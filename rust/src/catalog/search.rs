//! The catalog search box (jQuery UI autocomplete) answered from memory. PHP booted the whole SCS app and scanned the
//! `search` table on every keystroke (scs_ajax.php?table=search). Here one immutable `Index` is built from the same
//! tables search_export.php exported (search, subcategory, category, content names, inventory) and rebuilt when they
//! change. `search()` is a pure function of the index, the term and the visitor's `Ctx` (language, admin, blocked
//! categories), which comes from the catalog's `Viewer`. Contract: search/SPEC.md in the bench.

use std::{
    cmp::Ordering,
    collections::{BinaryHeap, HashMap, HashSet},
    ops::{Range, RangeInclusive},
    path::Path,
    sync::Arc,
    time::{Duration, Instant},
};

use axum::{
    extract::{Query, State},
    http::{header, HeaderMap, StatusCode},
    response::{IntoResponse, Response},
    routing::get,
    Extension, Json, Router,
};
use serde_json::{json, Value};
use sqlx::SqlitePool;

use super::viewer::{self, Viewer};
use crate::{api::Auth, identity::Role, visitor::Visitor};

/// What searchers opened (build_learned.py, from the search log). Read from HXWEB_SEARCH when present; without them
/// the ranking is the catalog's own.
const LEARNED_FILES: [&str; 2] = ["learned.tsv", "popular.tsv"];
/// How long a built index is used before the tables are checked for changes.
const RECHECK: Duration = Duration::from_secs(30);
/// Typed term -> (picks, entry) of what searchers opened after it.
type Learned = HashMap<String, Vec<(u32, u32)>>;
const DEFAULT_LIMIT: usize = 10;
const MAX_LIMIT: usize = 100;
const MAX_TERM_CHARS: usize = 100;
const TYPO_CODE_CHARS: RangeInclusive<usize> = 4..=25;
const TYPO_WORD_LETTERS: usize = 4;
const CODE_ALPHABET: &str = "ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789";
const WORD_ALPHABET: &str = "abcdefghijklmnopqrstuvwxyz0123456789";

#[derive(Clone, Copy, Debug, PartialEq, Eq, Hash)]
enum Lang {
    En,
    Fr,
}

impl Lang {
    fn parse(s: &str) -> Option<Lang> {
        match s.to_ascii_uppercase().as_str() {
            "EN" => Some(Lang::En),
            "FR" => Some(Lang::Fr),
            _ => None,
        }
    }
}

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
enum Kind {
    Part,
    Competitor,
    Family,
    SearchWord,
}

impl Kind {
    fn parse(table: &str) -> Option<Kind> {
        match table {
            "inventory" => Some(Kind::Part),
            "competitor_sku" => Some(Kind::Competitor),
            "subcategory" => Some(Kind::Family),
            "search_words" => Some(Kind::SearchWord),
            _ => None,
        }
    }

    /// The item's `category`: PHP's ucwords() of the table name, as live sends it.
    fn category(self) -> &'static str {
        match self {
            Kind::Part => "Inventory",
            Kind::Competitor => "Competitor_sku",
            Kind::Family => "Subcategory",
            Kind::SearchWord => "Search_words",
        }
    }

    /// A family name and a search word both open the family page, so an answer shows each family once.
    fn is_family(self) -> bool {
        matches!(self, Kind::Family | Kind::SearchWord)
    }
}

/// One row of the `search` table. Its normalized code lives in `Index::norms`.
struct Entry {
    row_id: u64,
    kind: Kind,
    /// None: every language ('' in the table).
    lang: Option<Lang>,
    active: bool,
    code: Box<str>,
    category_id: u32,
    subcategory_id: u32,
    inventory_id: u32,
    ranking: u64,
}

#[derive(Default)]
struct Family {
    /// English, French (families.tsv, which also lists inactive families: labels only, never searched).
    names: [Box<str>; 2],
    /// The family-name entry per language, where the index has one.
    rows: [Option<u32>; 2],
}

/// Every entry's normalized code, back to back in entry order. Binary searches and block scans then read one
/// allocation in order instead of chasing a pointer per entry; the typo tier does ~1,800 lookups per keystroke.
struct Norms {
    text: String,
    bounds: Vec<u32>,
}

impl Norms {
    fn get(&self, i: usize) -> &str {
        &self.text[self.bounds[i] as usize..self.bounds[i + 1] as usize]
    }
}

struct Index {
    /// Sorted by normalized code: exact and prefix lookups are binary searches, and an entry's position is its id.
    entries: Vec<Entry>,
    norms: Norms,
    /// Entry id -> place in the code tiers' order (shorter normalized code, ranking, code).
    by_code: Vec<u32>,
    /// Entry id -> place in the word tiers' order (ranking, code).
    by_rank: Vec<u32>,
    /// Byte trigram of a normalized code -> ids of the entries that have it, ascending.
    trigrams: HashMap<[u8; 3], Vec<u32>>,
    /// Stem -> family-name and search-word entries whose own text has it. Sorted by stem, for prefix blocks.
    words: Vec<(Box<str>, Vec<u32>)>,
    /// Stem -> categories whose English or French name has it, sorted by stem.
    category_words: Vec<(Box<str>, Vec<u32>)>,
    family_entries: Vec<u32>,
    families: HashMap<u32, Family>,
    /// inventory_id -> SKU, to label competitor part numbers.
    skus: HashMap<u32, Box<str>>,
    /// Typed term as `phrase()` keys it -> (picks, entry) of what searchers opened after it, most picks first. A
    /// family is held by one of its family-name entries; `Answer::shown` swaps in the visitor's language.
    learned: HashMap<String, Vec<(u32, u32)>>,
    /// The same by `stemmed()` term, for a term not learned as typed ("drill bushings" -> "drill bushing"). Only a
    /// fallback: "hpints" and "hpint" share a stem but are different parts.
    learned_stems: HashMap<String, Vec<(u32, u32)>>,
    /// subcategory_id -> how often the family is opened after a search.
    popular: HashMap<u32, u32>,
}

#[derive(Clone, Debug, PartialEq)]
struct Ctx {
    lang: Lang,
    admin: bool,
    blocked: Vec<u32>,
}

impl Default for Ctx {
    fn default() -> Self {
        Ctx { lang: Lang::En, admin: false, blocked: Vec::new() }
    }
}

/// Best first: an answer is filled tier by tier, and a later tier only gets the room the earlier ones left.
#[derive(Clone, Copy, Debug, PartialEq, Eq, PartialOrd, Ord)]
enum Tier {
    /// The normalized term is a part or competitor code, or a family name.
    Exact,
    /// The term is one searchers typed before: what they opened next, most picks first.
    Learned,
    /// A code, family name or search word starts with the normalized term.
    Prefix,
    /// Every query word is a word of the entry's own name or its category's; the last word may be partly typed.
    Words,
    /// A code contains the normalized term (3+ characters).
    Contains,
    /// One edit away: codes as a prefix, words as whole words.
    Typo,
    /// Some, not all, query words reach the entry. Not in SPEC.md: "threaded insert" has no family with both words.
    SomeWords,
    /// A query word starts a category's name: that category's families.
    Category,
}

const TIERS: [Tier; 8] = [Tier::Exact, Tier::Learned, Tier::Prefix, Tier::Words, Tier::Contains, Tier::Typo, Tier::SomeWords, Tier::Category];

impl Tier {
    fn name(self) -> &'static str {
        match self {
            Tier::Exact => "exact",
            Tier::Learned => "learned",
            Tier::Prefix => "prefix",
            Tier::Words => "words",
            Tier::Contains => "contains",
            Tier::Typo => "typo",
            Tier::SomeWords => "some_words",
            Tier::Category => "category",
        }
    }
}

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
enum Hit {
    Row { entry: u32, tier: Tier },
    /// "Lookup Part# <term>": the part-number lookup page, for a part the index does not have.
    Lookup,
}

impl Index {
    #[cfg(test)]
    fn load(dir: &Path) -> Result<Index, String> {
        let read = |f: &str| std::fs::read(dir.join(f)).map(|b| String::from_utf8_lossy(&b).into_owned()).map_err(|e| format!("{f}: {e}"));
        let optional = |f: &str| if dir.join(f).exists() { read(f) } else { Ok(String::new()) };
        let [learned, popular] = LEARNED_FILES.map(optional);
        Index::build(&read("rows.tsv")?, &read("families.tsv")?, &read("categories.tsv")?, &read("parts.tsv")?, &learned?, &popular?)
    }

    fn build(rows: &str, families: &str, categories: &str, parts: &str, learned: &str, popular: &str) -> Result<Index, String> {
        let mut parsed: Vec<(String, Entry)> = rows.lines().filter_map(entry).collect();
        let skipped = rows.lines().filter(|l| !l.is_empty()).count() - parsed.len();
        if parsed.is_empty() {
            return Err("rows.tsv: no rows".into());
        }
        if skipped > 0 {
            tracing::warn!("search: {skipped} lines of rows.tsv not understood, left out");
        }
        parsed.sort_unstable_by(|a, b| a.0.cmp(&b.0).then(a.1.row_id.cmp(&b.1.row_id)));
        let mut norms = Norms { text: String::new(), bounds: vec![0] };
        let mut entries = Vec::with_capacity(parsed.len());
        for (norm, e) in parsed {
            norms.text.push_str(&norm);
            norms.bounds.push(norms.text.len() as u32);
            entries.push(e);
        }
        let order = |cmp: &dyn Fn(usize, usize) -> Ordering| {
            let mut ids: Vec<u32> = (0..entries.len() as u32).collect();
            ids.sort_unstable_by(|&a, &b| cmp(a as usize, b as usize));
            let mut place = vec![0; ids.len()];
            for (p, &i) in ids.iter().enumerate() {
                place[i as usize] = p as u32;
            }
            place
        };
        let tail = |a: usize, b: usize| entries[a].ranking.cmp(&entries[b].ranking).then_with(|| entries[a].code.cmp(&entries[b].code));
        let by_code = order(&|a, b| norms.get(a).len().cmp(&norms.get(b).len()).then_with(|| tail(a, b)));
        let by_rank = order(&tail);

        let mut fams: HashMap<u32, Family> = HashMap::new();
        for line in families.lines() {
            if let [id, _, _, en, fr] = line.split('\t').collect::<Vec<_>>()[..] {
                if let Ok(id) = id.parse() {
                    fams.insert(id, Family { names: [en.into(), fr.into()], rows: [None, None] });
                }
            }
        }
        let mut trigrams: HashMap<[u8; 3], Vec<u32>> = HashMap::new();
        let mut words: HashMap<String, Vec<u32>> = HashMap::new();
        let mut family_entries = Vec::new();
        for (i, e) in entries.iter().enumerate() {
            for t in norms.get(i).as_bytes().windows(3) {
                add(trigrams.entry([t[0], t[1], t[2]]).or_default(), i as u32);
            }
            if e.kind.is_family() {
                for s in tokens(&e.code) {
                    add(words.entry(stem(&s)).or_default(), i as u32);
                }
            }
            if e.kind == Kind::Family {
                family_entries.push(i as u32);
                if let Some(lang) = e.lang {
                    fams.entry(e.subcategory_id).or_default().rows[lang as usize].get_or_insert(i as u32);
                }
            }
        }
        let mut category_words: HashMap<String, Vec<u32>> = HashMap::new();
        for line in categories.lines() {
            if let [id, en, fr] = line.split('\t').collect::<Vec<_>>()[..] {
                if let Ok(id) = id.parse::<u32>() {
                    for s in tokens(en).into_iter().chain(tokens(fr)) {
                        let cats = category_words.entry(stem(&s)).or_default();
                        if !cats.contains(&id) {
                            cats.push(id);
                        }
                    }
                }
            }
        }
        let skus = parts
            .lines()
            .filter_map(|l| {
                let mut f = l.split('\t');
                Some((f.next()?.parse().ok()?, f.next()?.into()))
            })
            .collect();
        let (mut by_term, mut by_stem): (Learned, Learned) = Default::default();
        for line in learned.lines() {
            let [term, sub, sku, picks] = line.split('\t').collect::<Vec<_>>()[..] else { continue };
            let (Some(sub), Ok(picks)) = (number::<u32>(sub), picks.parse::<u32>()) else { continue };
            let target = if sku.is_empty() {
                fams.get(&sub).and_then(|f| f.rows[0].or(f.rows[1]))
            } else {
                let b = if norm(sku).is_empty() { 0..0 } else { block(&norms, 0..entries.len(), &norm(sku)) };
                b.into_iter().find(|&i| entries[i].kind == Kind::Part && entries[i].code.eq_ignore_ascii_case(sku)).map(|i| i as u32)
            };
            if let Some(e) = target {
                by_term.entry(phrase(term)).or_default().push((picks, e));
                by_stem.entry(stemmed(term)).or_default().push((picks, e));
            }
        }
        let popular = popular
            .lines()
            .filter_map(|l| {
                let [sub, picks] = l.split('\t').collect::<Vec<_>>()[..] else { return None };
                Some((sub.parse().ok()?, picks.parse().ok()?))
            })
            .collect();
        Ok(Index {
            entries,
            norms,
            by_code,
            by_rank,
            trigrams,
            words: sorted(words),
            category_words: sorted(category_words),
            family_entries,
            families: fams,
            skus,
            learned: tally(by_term),
            learned_stems: tally(by_stem),
            popular,
        })
    }

    fn norm(&self, i: usize) -> &str {
        self.norms.get(i)
    }

    /// Entries within `scope` whose normalized code starts with `p`.
    fn block(&self, scope: Range<usize>, p: &str) -> Range<usize> {
        block(&self.norms, scope, p)
    }
}

fn entry(line: &str) -> Option<(String, Entry)> {
    let [id, table, lang, active, code, category, subcategory, inventory, ranking] = line.split('\t').collect::<Vec<_>>()[..] else {
        return None;
    };
    let e = Entry {
        row_id: id.parse().ok()?,
        kind: Kind::parse(table)?,
        lang: if lang.is_empty() { None } else { Some(Lang::parse(lang)?) },
        active: active == "1",
        code: code.into(),
        category_id: number(category)?,
        subcategory_id: number(subcategory)?,
        inventory_id: number(inventory)?,
        ranking: number(ranking)?,
    };
    Some((norm(code), e))
}

/// Empty is 0 (a NULL in the export).
fn number<T: std::str::FromStr + Default>(s: &str) -> Option<T> {
    if s.is_empty() { Some(T::default()) } else { s.parse().ok() }
}

/// Entries are visited in ascending order, so pushing only a new last id keeps each list sorted and unique.
fn add(ids: &mut Vec<u32>, id: u32) {
    if ids.last() != Some(&id) {
        ids.push(id);
    }
}

/// Picks of one entry under a key added up (several learned terms can share a stem), most picks first.
fn tally(m: HashMap<String, Vec<(u32, u32)>>) -> HashMap<String, Vec<(u32, u32)>> {
    m.into_iter()
        .map(|(k, picks)| {
            let mut per: HashMap<u32, u32> = HashMap::new();
            for (n, e) in picks {
                *per.entry(e).or_default() += n;
            }
            let mut v: Vec<(u32, u32)> = per.into_iter().map(|(e, n)| (n, e)).collect();
            v.sort_unstable_by(|a, b| b.0.cmp(&a.0).then(a.1.cmp(&b.1)));
            (k, v)
        })
        .collect()
}

fn sorted(m: HashMap<String, Vec<u32>>) -> Vec<(Box<str>, Vec<u32>)> {
    let mut v: Vec<_> = m.into_iter().map(|(k, ids)| (k.into_boxed_str(), ids)).collect();
    v.sort_unstable_by(|a, b| a.0.cmp(&b.0));
    v
}

/// Keys in ascending order.
trait Sorted {
    fn key(&self, i: usize) -> &str;
}

impl Sorted for Norms {
    fn key(&self, i: usize) -> &str {
        self.get(i)
    }
}

impl Sorted for [(Box<str>, Vec<u32>)] {
    fn key(&self, i: usize) -> &str {
        &self[i].0
    }
}

/// Positions within `scope` whose key starts with `p`.
fn block<S: Sorted + ?Sized>(s: &S, scope: Range<usize>, p: &str) -> Range<usize> {
    let first = |mut lo: usize, mut hi: usize, before: &dyn Fn(&str) -> bool| {
        while lo < hi {
            let mid = lo + (hi - lo) / 2;
            if before(s.key(mid)) { lo = mid + 1 } else { hi = mid }
        }
        lo
    };
    let lo = first(scope.start, scope.end, &|k| k < p);
    // Most typo variants start nothing: one search settles them.
    if lo == scope.end || !s.key(lo).starts_with(p) {
        return lo..lo;
    }
    lo..first(lo + 1, scope.end, &|k| k.starts_with(p))
}

/// The ids under `k` exactly.
fn exactly<'a>(list: &'a [(Box<str>, Vec<u32>)], k: &str) -> &'a [u32] {
    let b = block(list, 0..list.len(), k);
    list[b].first().filter(|t| *t.0 == *k).map_or(&[], |t| &t.1)
}

/// Lowercase with Latin-1 accents folded, so "Perçage" and "percage" meet.
fn fold(s: &str) -> String {
    let mut out = String::with_capacity(s.len());
    for c in s.chars().flat_map(char::to_lowercase) {
        match c {
            'à'..='å' => out.push('a'),
            'æ' => out.push_str("ae"),
            'ç' => out.push('c'),
            'è'..='ë' => out.push('e'),
            'ì'..='ï' => out.push('i'),
            'ñ' => out.push('n'),
            'ò'..='ö' | 'ø' => out.push('o'),
            'ù'..='ü' => out.push('u'),
            'ý' | 'ÿ' => out.push('y'),
            'œ' => out.push_str("oe"),
            'ß' => out.push_str("ss"),
            c => out.push(c),
        }
    }
    out
}

/// A code as matched: folded, uppercase, without the separators customers drop or swap ("ls1", "LS-1", "ls 1").
fn norm(s: &str) -> String {
    fold(s).chars().filter(|&c| !matches!(c, '-' | '_' | '.' | '/') && !c.is_whitespace()).map(|c| c.to_ascii_uppercase()).collect()
}

/// A typed term as learned terms are matched: folded, whitespace collapsed.
fn phrase(s: &str) -> String {
    fold(s).split_whitespace().collect::<Vec<_>>().join(" ")
}

fn stemmed(s: &str) -> String {
    fold(s).split_whitespace().map(stem).collect::<Vec<_>>().join(" ")
}

fn tokens(s: &str) -> Vec<String> {
    fold(s).split(|c: char| !c.is_alphanumeric()).filter(|w| !w.is_empty()).map(str::to_string).collect()
}

/// English and French plurals onto a stem the singular shares (bushings/bushing, boxes/box, broches/broche,
/// séries/série, anneaux/anneau). It only has to agree with itself: query and index words go through it alike.
fn stem(w: &str) -> String {
    if w.chars().count() <= 3 {
        return w.to_string();
    }
    if let Some(b) = w.strip_suffix("ies").or_else(|| w.strip_suffix("ie")) {
        return format!("{b}y");
    }
    if ["aux", "eux", "oux"].iter().any(|s| w.ends_with(s)) {
        return w[..w.len() - 1].to_string();
    }
    let w = match w.strip_suffix('s') {
        Some(b) if !b.ends_with('s') => b,
        _ => w,
    };
    match w.strip_suffix('e') {
        Some(b) if ["s", "x", "z", "ch", "sh"].iter().any(|s| b.ends_with(s)) => b,
        _ => w,
    }
    .to_string()
}

/// Calls `f` with each one-edit variant of `q` edited at byte offset `at` (a char boundary): delete or substitute
/// the char there, swap it with the next, or insert before it (at the end when `at == q.len()`). `alphabet` is ASCII.
fn edits_at(q: &str, at: usize, alphabet: &str, buf: &mut String, f: &mut impl FnMut(&str)) {
    let (head, tail) = q.split_at(at);
    let mut emit = |parts: &[&str]| {
        buf.clear();
        buf.push_str(head);
        parts.iter().for_each(|p| buf.push_str(p));
        f(buf);
    };
    let letters = (0..alphabet.len()).map(|i| &alphabet[i..i + 1]);
    let mut chars = tail.chars();
    if let Some(c) = chars.next() {
        let rest = chars.as_str();
        emit(&[rest]);
        let mut after = rest.chars();
        if let Some(d) = after.next().filter(|&d| d != c) {
            emit(&[d.encode_utf8(&mut [0; 4]), c.encode_utf8(&mut [0; 4]), after.as_str()]);
        }
        for a in letters.clone().filter(|a| !a.starts_with(c)) {
            emit(&[a, rest]);
        }
    }
    for a in letters {
        emit(&[a, tail]);
    }
}

/// Calls `f(variant, block)` for each one-edit variant of `q` that starts keys of `s` (the first `len`), with the
/// block it starts. A variant edited at a position starts with the part of `q` before it, so it is looked up inside
/// that part's block, which narrows within a few characters. `end`: also insert after the last character.
fn one_edit<S: Sorted + ?Sized>(s: &S, len: usize, q: &str, alphabet: &str, end: bool, f: &mut impl FnMut(&str, Range<usize>)) {
    let mut scope = 0..len;
    let mut buf = String::new();
    for (at, c) in q.char_indices().map(|(at, c)| (at, Some(c))).chain(end.then_some((q.len(), None))) {
        edits_at(q, at, alphabet, &mut buf, &mut |v| {
            let b = block(s, scope.clone(), v);
            if !b.is_empty() {
                f(v, b);
            }
        });
        let Some(c) = c else { break };
        scope = block(s, scope, &q[..at + c.len_utf8()]);
        if scope.is_empty() {
            break;
        }
    }
}

/// Blocks of entries whose normalized code starts with a one-edit variant of `q`, merged. Inserting at the end is
/// left out: that is the prefix tier.
fn typo_blocks(ix: &Index, q: &str) -> Vec<Range<usize>> {
    let mut blocks = Vec::new();
    one_edit(&ix.norms, ix.entries.len(), q, CODE_ALPHABET, false, &mut |_, b| blocks.push(b));
    blocks.sort_unstable_by_key(|b| b.start);
    let mut merged: Vec<Range<usize>> = Vec::new();
    for b in blocks {
        match merged.last_mut() {
            Some(m) if b.start <= m.end => m.end = m.end.max(b.end),
            _ => merged.push(b),
        }
    }
    merged
}

/// Entries that have every trigram of `q` (3+ bytes): those that contain `q`, and the odd one that has its trigrams
/// elsewhere. Checking each is left to `Answer::take`, which only checks the few that make the cut: "box1" is in 49k codes.
fn containing(ix: &Index, q: &str) -> Vec<u32> {
    let Some(mut lists) = q.as_bytes().windows(3).map(|t| ix.trigrams.get(&[t[0], t[1], t[2]])).collect::<Option<Vec<_>>>() else {
        return Vec::new();
    };
    lists.sort_unstable_by_key(|l| l.len());
    let mut ids = lists[0].clone();
    for list in &lists[1..] {
        ids = intersect(&ids, list);
    }
    ids
}

/// Both ascending. Numbers like "0606" have two trigrams in ~10k codes each: a merge walk, not a search per id.
fn intersect(a: &[u32], b: &[u32]) -> Vec<u32> {
    if b.len() > 32 * a.len() {
        return a.iter().copied().filter(|x| b.binary_search(x).is_ok()).collect();
    }
    let (mut i, mut j, mut out) = (0, 0, Vec::new());
    while i < a.len() && j < b.len() {
        match a[i].cmp(&b[j]) {
            Ordering::Less => i += 1,
            Ordering::Greater => j += 1,
            Ordering::Equal => {
                out.push(a[i]);
                i += 1;
                j += 1;
            }
        }
    }
    out
}

struct Word {
    stem: String,
    letters: usize,
}

/// What one query word reaches: family-name and search-word entries through their own text, categories through
/// their names. Both sorted.
struct Reach {
    own: Vec<u32>,
    cats: Vec<u32>,
}

impl Reach {
    fn new(ix: &Index, w: &Word, prefix: bool, typos: bool) -> Reach {
        let reach = |list: &[(Box<str>, Vec<u32>)]| -> Vec<u32> {
            let mut ids: Vec<u32> = if prefix {
                list[block(list, 0..list.len(), &w.stem)].iter().flat_map(|t| t.1.iter().copied()).collect()
            } else {
                exactly(list, &w.stem).to_vec()
            };
            if typos && w.letters >= TYPO_WORD_LETTERS {
                one_edit(list, list.len(), &w.stem, WORD_ALPHABET, true, &mut |v, b| {
                    ids.extend(list[b].first().filter(|t| *t.0 == *v).into_iter().flat_map(|t| t.1.iter().copied()));
                });
            }
            ids.sort_unstable();
            ids.dedup();
            ids
        };
        Reach { own: reach(&ix.words), cats: reach(&ix.category_words) }
    }
}

/// Entries some query word reaches by their own text, each with the query words that reach it neither by its own
/// text nor by its category's name, as one order: 2 per word missed, 1 more when `head` is among them (a "threaded
/// insert" is an insert). 0: every word reached. A category name alone only feeds the category tier.
fn reached(ix: &Index, reach: &[Reach], head: usize) -> Vec<(u32, u32)> {
    let mut own: Vec<u32> = reach.iter().flat_map(|r| r.own.iter().copied()).collect();
    own.sort_unstable();
    own.dedup();
    own.into_iter()
        .map(|e| {
            let cat = ix.entries[e as usize].category_id;
            let missed = |r: &Reach| r.own.binary_search(&e).is_err() && r.cats.binary_search(&cat).is_err();
            let order = 2 * reach.iter().filter(|r| missed(r)).count() + usize::from(reach.get(head).is_some_and(missed));
            (order as u32, e)
        })
        .collect()
}

/// The table filters live applies: blocked categories, and administrators see active rows only (live's rule, kept).
fn visible(e: &Entry, ctx: &Ctx) -> bool {
    !(ctx.admin && !e.active) && !ctx.blocked.contains(&e.category_id)
}

/// The answer being filled tier by tier.
struct Answer<'a> {
    ix: &'a Index,
    ctx: &'a Ctx,
    cap: usize,
    hits: Vec<Hit>,
    entries: HashSet<u32>,
    families: HashSet<u32>,
}

impl Answer<'_> {
    fn room(&self) -> usize {
        self.cap - self.hits.len()
    }

    /// The entry shown for a matched one: itself, or for a family name or search word in the other language, the
    /// family's own entry in the visitor's language (none when it has no row in that language).
    fn shown(&self, e: u32) -> Option<u32> {
        let m = &self.ix.entries[e as usize];
        let s = match m.lang {
            Some(l) if l != self.ctx.lang => self.ix.families.get(&m.subcategory_id)?.rows[self.ctx.lang as usize]?,
            _ => e,
        };
        visible(&self.ix.entries[s as usize], self.ctx).then_some(s)
    }

    fn seen(&self, s: u32) -> bool {
        let e = &self.ix.entries[s as usize];
        self.entries.contains(&s) || (e.kind.is_family() && self.families.contains(&e.subcategory_id))
    }

    fn push(&mut self, s: u32, tier: Tier) {
        if self.room() == 0 || self.seen(s) {
            return;
        }
        let e = &self.ix.entries[s as usize];
        self.entries.insert(s);
        if e.kind.is_family() {
            self.families.insert(e.subcategory_id);
        }
        self.hits.push(Hit::Row { entry: s, tier });
    }

    fn take(&mut self, tier: Tier, cands: impl Iterator<Item = (u64, u32)> + Clone) {
        self.take_if(tier, cands, |_| true);
    }

    /// Adds candidates (order within the tier, matched entry) that pass `keep`, best first until full. A prefix block
    /// can hold 100k part numbers ("h"), so the scan keeps only the best few and reads nothing but the key for the
    /// rest; when dedupe leaves room, a wider rescan follows.
    fn take_if(&mut self, tier: Tier, cands: impl Iterator<Item = (u64, u32)> + Clone, keep: impl Fn(u32) -> bool) {
        let mut n = self.cap * 2 + 8;
        while self.room() > 0 {
            let mut best = BinaryHeap::with_capacity(n + 1);
            let mut more = false;
            for (key, e) in cands.clone() {
                if best.len() == n {
                    more = true;
                    if best.peek().is_some_and(|&(top, _)| key >= top) {
                        continue;
                    }
                }
                let Some(s) = self.shown(e).filter(|&s| !self.seen(s) && keep(e)) else { continue };
                best.push((key, s));
                if best.len() > n {
                    best.pop();
                }
            }
            for (_, s) in best.into_sorted_vec() {
                self.push(s, tier);
            }
            if !more {
                return;
            }
            n *= 4;
        }
    }
}

fn search(ix: &Index, term: &str, ctx: &Ctx, limit: usize) -> Vec<Hit> {
    let term = term.trim();
    // The log has pasted XML and JSON blobs of 2 kB in it: past any part number or family name, cost without use.
    let term = term.char_indices().nth(MAX_TERM_CHARS).map_or(term, |(at, _)| &term[..at]);
    let q = norm(term);
    if q.is_empty() {
        return Vec::new();
    }
    let words: Vec<Word> =
        tokens(term).iter().map(|w| Word { stem: stem(w), letters: w.chars().filter(|c| c.is_alphabetic()).count() }).collect();
    let prefixed = ix.block(0..ix.entries.len(), &q);
    let exact = prefixed.start..prefixed.start + prefixed.clone().take_while(|&i| ix.norm(i) == q).count();
    let lookup = !term.contains(char::is_whitespace)
        && !ix.entries[exact.clone()].iter().any(|e| matches!(e.kind, Kind::Part | Kind::Competitor) && visible(e, ctx));
    let mut out = Answer {
        ix,
        ctx,
        cap: limit.saturating_sub(usize::from(lookup)),
        hits: Vec::new(),
        entries: HashSet::new(),
        families: HashSet::new(),
    };
    let code_key = |e: usize| (u64::from(ix.by_code[e]), e as u32);
    // Word tiers: the family's popularity, then `reached`'s order, the visitor's language before the other, ranking
    // and code. Bits: 1 spare (SomeWords: head noun missed), 15 popularity (inverted), 15 order, 1 language, 32 ranking.
    let word_key = |order: u32, e: u32| {
        let m = &ix.entries[e as usize];
        let popular = ix.popular.get(&m.subcategory_id).map_or(0, |&n| n.min(0x7FFF));
        let other = m.lang.is_some_and(|l| l != ctx.lang);
        let key = (u64::from(0x7FFF - popular) << 48) | (u64::from(order.min(0x7FFF)) << 33) | (u64::from(other) << 32);
        (key | u64::from(ix.by_rank[e as usize]), e)
    };
    let last = words.len().saturating_sub(1);
    // The noun a name is about comes last in English ("threaded insert") and first in French ("insert fileté").
    let head = if ctx.lang == Lang::Fr { 0 } else { last };
    let reach = |typos: bool| -> Vec<Reach> { words.iter().enumerate().map(|(i, w)| Reach::new(ix, w, i == last, typos)).collect() };
    let mut by_words: Option<Vec<(u32, u32)>> = None;
    for tier in TIERS {
        if out.room() == 0 {
            break;
        }
        match tier {
            Tier::Exact => out.take(tier, exact.clone().filter(|&e| ix.entries[e].kind != Kind::SearchWord).map(code_key)),
            Tier::Learned => {
                if let Some(picks) = ix.learned.get(&phrase(term)).or_else(|| ix.learned_stems.get(&stemmed(term))) {
                    out.take(tier, picks.iter().map(|&(n, e)| ((u64::from(u32::MAX - n) << 32) | u64::from(ix.by_rank[e as usize]), e)));
                }
            }
            Tier::Prefix => out.take(tier, prefixed.clone().map(code_key)),
            Tier::Words => {
                let found = by_words.get_or_insert_with(|| reached(ix, &reach(false), head));
                out.take(tier, found.iter().filter(|&&(order, _)| order == 0).map(|&(o, e)| word_key(o, e)));
            }
            Tier::Contains if q.len() >= 3 => {
                let found = containing(ix, &q);
                out.take_if(tier, found.iter().map(|&e| code_key(e as usize)), |e| ix.norm(e as usize).contains(q.as_str()));
            }
            Tier::Contains => {}
            Tier::Typo => {
                let blocks = if TYPO_CODE_CHARS.contains(&q.chars().count()) { typo_blocks(ix, &q) } else { Vec::new() };
                let by_code = blocks.iter().flat_map(|b| b.clone().map(code_key));
                let by_word: Vec<(u64, u32)> = if words.iter().any(|w| w.letters >= TYPO_WORD_LETTERS) {
                    reached(ix, &reach(true), head).into_iter().filter(|&(order, _)| order == 0).map(|(o, e)| word_key(o, e)).collect()
                } else {
                    Vec::new()
                };
                // Part numbers carry digits and words do not: the likelier reading of the typo goes first.
                if term.chars().any(|c| c.is_ascii_digit()) {
                    out.take(tier, by_code);
                    out.take(tier, by_word.into_iter());
                } else {
                    out.take(tier, by_word.into_iter());
                    out.take(tier, by_code);
                }
            }
            // Only some words matched: an entry with the noun the search is about ("insert" in "threaded insert")
            // goes before a more popular one without it. Not for part numbers split into pieces (hb-8-174-ss),
            // whose last piece is no noun.
            Tier::SomeWords if words.len() > 1 => {
                let noun_first = !term.chars().any(|c| c.is_ascii_digit());
                let found = by_words.get_or_insert_with(|| reached(ix, &reach(false), head));
                out.take(tier, found.iter().filter(|&&(order, _)| order > 0).map(|&(o, e)| {
                    let (key, e) = word_key(o, e);
                    (key | (u64::from(noun_first && o & 1 == 1) << 63), e)
                }));
            }
            Tier::SomeWords => {}
            Tier::Category => {
                let cats: Vec<u32> = words
                    .iter()
                    .filter(|w| w.stem.chars().count() >= 3)
                    .flat_map(|w| ix.category_words[block(&ix.category_words[..], 0..ix.category_words.len(), &w.stem)].iter())
                    .flat_map(|t| t.1.iter().copied())
                    .collect();
                let families = ix.family_entries.iter().filter(|&&e| cats.contains(&ix.entries[e as usize].category_id));
                out.take(tier, families.map(|&e| word_key(0, e)));
            }
        }
    }
    let mut hits = out.hits;
    if lookup {
        hits.push(Hit::Lookup);
    }
    hits
}

fn label(ix: &Index, e: &Entry, lang: Lang) -> String {
    let family = || {
        let names = &ix.families.get(&e.subcategory_id)?.names;
        [&names[lang as usize], &names[1 - lang as usize]].into_iter().find(|n| !n.is_empty())
    };
    match e.kind {
        Kind::Part => e.code.to_string(),
        Kind::Competitor => match ix.skus.get(&e.inventory_id) {
            Some(sku) => format!("{} (Harcourt {sku})", e.code),
            None => e.code.to_string(),
        },
        Kind::SearchWord => match family() {
            Some(f) => format!("{} - {f}", e.code),
            None => e.code.to_string(),
        },
        Kind::Family => family().unwrap_or(&e.code).to_string(),
    }
}

/// Live's response shape, plus `subcategory_id`, `sku` and `tier` per item.
fn answer_json(ix: &Index, hits: &[Hit], term: &str, ctx: &Ctx) -> Value {
    let items: Vec<Value> = hits
        .iter()
        .map(|h| match *h {
            Hit::Row { entry, tier } => {
                let e = &ix.entries[entry as usize];
                let sku = match e.kind {
                    Kind::Part => &*e.code,
                    Kind::Competitor => ix.skus.get(&e.inventory_id).map_or("", |s| &**s),
                    Kind::Family | Kind::SearchWord => "",
                };
                json!({"value": term, "label": label(ix, e, ctx.lang), "category": e.kind.category(), "id": e.row_id,
                       "subcategory_id": e.subcategory_id, "sku": sku, "tier": tier.name()})
            }
            Hit::Lookup => json!({"value": term, "label": format!("Lookup Part# {term}"), "category": "Lookup", "id": 0,
                                  "subcategory_id": 0, "sku": "", "tier": "lookup"}),
        })
        .collect();
    let rows = hits.iter().filter(|h| matches!(h, Hit::Row { .. })).count();
    json!({"items": items, "rows": rows, "found_rows": rows.to_string()})
}

/// The search scope for this viewer on a page in `lang`: what hx_search_ctx.php reported.
fn scope(viewer: &Viewer, lang: Lang) -> Ctx {
    Ctx {
        lang,
        admin: viewer.at_least(Role::Administrator),
        blocked: viewer.blocked.ids().into_iter().filter_map(|id| u32::try_from(id).ok()).collect(),
    }
}

/// The catalog language the search box is on, from its page's address: /catalogue/ or lang=fr is French.
fn page_lang(headers: &HeaderMap) -> Lang {
    let referer = headers.get(header::REFERER).and_then(|v| v.to_str().ok()).unwrap_or("");
    let path = referer.split_once("://").map_or(referer, |(_, rest)| rest.find('/').map_or("", |i| &rest[i..]));
    let (path, query) = path.split_once('?').unwrap_or((path, ""));
    if path.starts_with("/catalogue/") || query.split('&').any(|kv| kv.eq_ignore_ascii_case("lang=fr")) { Lang::Fr } else { Lang::En }
}

/// Row counts and highest ids of the tables the index reads: any edit, import or rebuild changes one of them.
#[derive(Debug, Clone, PartialEq, Eq)]
struct Stamp([i64; 9]);

async fn stamp(pool: &SqlitePool) -> sqlx::Result<Stamp> {
    let r = sqlx::query!(
        r#"SELECT (SELECT count(*) FROM search) AS "a!: i64", (SELECT coalesce(max(id), 0) FROM search) AS "b!: i64",
                  (SELECT count(*) FROM subcategory) AS "c!: i64", (SELECT coalesce(max(id), 0) FROM subcategory) AS "d!: i64",
                  (SELECT count(*) FROM category) AS "e!: i64", (SELECT count(*) FROM inventory) AS "f!: i64",
                  (SELECT coalesce(max(id), 0) FROM inventory) AS "g!: i64",
                  (SELECT count(*) FROM content WHERE record_type IN ('category', 'subcategory') AND record_item = '') AS "h!: i64",
                  (SELECT coalesce(max(revised), 0) FROM content WHERE record_type IN ('category', 'subcategory') AND record_item = '') AS "i!: i64""#
    )
    .fetch_one(pool)
    .await?;
    Ok(Stamp([r.a, r.b, r.c, r.d, r.e, r.f, r.g, r.h, r.i]))
}

fn cell(s: &str) -> String {
    s.replace(['\t', '\r', '\n'], " ")
}

fn opt<T: ToString>(v: Option<T>) -> String {
    v.map(|v| v.to_string()).unwrap_or_default()
}

/// The four inputs search_export.php wrote, as tab-separated text.
async fn export(pool: &SqlitePool) -> sqlx::Result<[String; 4]> {
    let mut rows = String::new();
    for r in sqlx::query!(
        r#"SELECT id AS "id!: i64", table_name, coalesce(language_code, '') AS "lang!: String", active, code, category_id, subcategory_id, inventory_id, ranking FROM search"#
    )
    .fetch_all(pool)
    .await?
    {
        rows.push_str(&format!(
            "{}\t{}\t{}\t{}\t{}\t{}\t{}\t{}\t{}\n",
            r.id,
            cell(&opt(r.table_name)),
            cell(&r.lang),
            opt(r.active),
            cell(&opt(r.code)),
            opt(r.category_id),
            opt(r.subcategory_id),
            opt(r.inventory_id),
            opt(r.ranking)
        ));
    }
    let name = |list: &[(String, i64, String, String)], id: i64, lang: &str| {
        list.iter().find(|(_, rid, l, _)| *rid == id && l == lang).map(|(_, _, _, n)| n.clone()).unwrap_or_default()
    };
    let names: Vec<(String, i64, String, String)> = sqlx::query!(
        r#"SELECT record_type AS "t!", record_id AS "id!: i64", language_code AS "l!", name AS "n!" FROM content
           WHERE record_type IN ('category', 'subcategory') AND record_item = '' AND language_code IN ('EN', 'FR') ORDER BY id"#
    )
    .fetch_all(pool)
    .await?
    .into_iter()
    .map(|r| (r.t, r.id, r.l, r.n))
    .collect();
    let of = |t: &str| names.iter().filter(|(rt, _, _, _)| rt == t).cloned().collect::<Vec<_>>();
    let (family_names, category_names) = (of("subcategory"), of("category"));
    let mut families = String::new();
    for r in sqlx::query!(r#"SELECT id AS "id!: i64", category_id, status AS "status!" FROM subcategory"#).fetch_all(pool).await? {
        families.push_str(&format!(
            "{}\t{}\t{}\t{}\t{}\n",
            r.id,
            opt(r.category_id),
            cell(&r.status),
            cell(&name(&family_names, r.id, "EN")),
            cell(&name(&family_names, r.id, "FR"))
        ));
    }
    let mut categories = String::new();
    for r in sqlx::query!(r#"SELECT id AS "id!: i64" FROM category"#).fetch_all(pool).await? {
        categories.push_str(&format!("{}\t{}\t{}\n", r.id, cell(&name(&category_names, r.id, "EN")), cell(&name(&category_names, r.id, "FR"))));
    }
    let mut parts = String::new();
    for r in sqlx::query!(r#"SELECT id AS "id!: i64", sku, subcategory_id FROM inventory"#).fetch_all(pool).await? {
        parts.push_str(&format!("{}\t{}\t{}\n", r.id, cell(&r.sku), opt(r.subcategory_id)));
    }
    Ok([rows, families, categories, parts])
}

struct Built {
    stamp: Stamp,
    checked: Instant,
    index: Option<Arc<Index>>,
}

/// The index for one database, built on first use and rebuilt when its tables change. A build that fails keeps
/// the previous index.
#[derive(Default)]
pub struct Cache {
    built: tokio::sync::Mutex<Option<Built>>,
}

impl Cache {
    pub fn new() -> Arc<Cache> {
        Arc::new(Cache::default())
    }

    async fn index(&self, pool: &SqlitePool) -> sqlx::Result<Option<Arc<Index>>> {
        let mut built = self.built.lock().await;
        if let Some(b) = built.as_ref().filter(|b| b.checked.elapsed() < RECHECK) {
            return Ok(b.index.clone());
        }
        let now = stamp(pool).await?;
        if let Some(b) = built.as_mut().filter(|b| b.stamp == now) {
            b.checked = Instant::now();
            return Ok(b.index.clone());
        }
        let [rows, families, categories, parts] = export(pool).await?;
        let learned = std::env::var_os("HXWEB_SEARCH").map(|d| {
            LEARNED_FILES.map(|f| std::fs::read(Path::new(&d).join(f)).map(|b| String::from_utf8_lossy(&b).into_owned()).unwrap_or_default())
        });
        let [learned, popular] = learned.unwrap_or_default();
        let start = Instant::now();
        let result = tokio::task::spawn_blocking(move || Index::build(&rows, &families, &categories, &parts, &learned, &popular)).await;
        let previous = built.as_ref().and_then(|b| b.index.clone());
        let index = match result {
            Ok(Ok(ix)) => {
                tracing::info!("search index built: {} entries in {:?}", ix.entries.len(), start.elapsed());
                Some(Arc::new(ix))
            }
            Ok(Err(e)) => {
                tracing::warn!("search index not built: {e}");
                previous
            }
            Err(e) => {
                tracing::error!("search index build failed: {e}");
                previous
            }
        };
        *built = Some(Built { stamp: now, checked: Instant::now(), index: index.clone() });
        Ok(index)
    }
}

/// The search row the search box would put first for `term`: what the results page opens when its own keyword
/// search finds nothing.
pub async fn best(cache: &Cache, pool: &SqlitePool, viewer: &Viewer, term: &str) -> sqlx::Result<Option<u64>> {
    let lang = match viewer.lang {
        viewer::Lang::En => Lang::En,
        viewer::Lang::Fr => Lang::Fr,
    };
    Ok(cache.index(pool).await?.and_then(|ix| first_row(&ix, term, &scope(viewer, lang))))
}

/// Limit 2, not 1: a Lookup item takes one place, and the first row does not depend on the limit.
fn first_row(ix: &Index, term: &str, ctx: &Ctx) -> Option<u64> {
    search(ix, term, ctx, 2).into_iter().find_map(|h| match h {
        Hit::Row { entry, .. } => Some(ix.entries[entry as usize].row_id),
        Hit::Lookup => None,
    })
}

pub fn router() -> Router<Arc<Auth>> {
    Router::new().route("/catalog-api/search", get(suggest))
}

#[derive(serde::Deserialize)]
struct SuggestQuery {
    #[serde(default)]
    term: String,
    limit: Option<String>,
}

/// The search box's suggestions, for visitors who may browse the catalog (the box is not shown to anyone else).
async fn suggest(State(core): State<Arc<Auth>>, Extension(cache): Extension<Arc<Cache>>, visitor: Visitor, headers: HeaderMap, Query(q): Query<SuggestQuery>) -> Response {
    suggestions(&core, &cache, &visitor, &headers, &q.term, q.limit.as_deref()).await
}

/// The suggestions for `term`, for /catalog-api/search and scs_ajax.php?table=search.
pub async fn suggestions(core: &Auth, cache: &Cache, visitor: &Visitor, headers: &HeaderMap, term: &str, limit: Option<&str>) -> Response {
    let lang = page_lang(headers);
    let viewer_lang = if lang == Lang::Fr { viewer::Lang::Fr } else { viewer::Lang::En };
    let result = async {
        let viewer = viewer::load(&core.pool, visitor, viewer_lang).await?;
        if !viewer.may_browse() || viewer.is_bot() {
            return Ok(None);
        }
        Ok::<_, sqlx::Error>(cache.index(&core.pool).await?.map(|ix| (ix, scope(&viewer, lang))))
    }
    .await;
    let term = term.trim();
    let limit = limit.and_then(|l| l.parse::<usize>().ok()).filter(|&l| l > 0).map_or(DEFAULT_LIMIT, |l| l.min(MAX_LIMIT));
    let headers = [(header::CACHE_CONTROL, "no-store")];
    match result {
        Ok(Some((ix, ctx))) => (StatusCode::OK, headers, Json(answer_json(&ix, &search(&ix, term, &ctx, limit), term, &ctx))).into_response(),
        Ok(None) => (StatusCode::FORBIDDEN, headers, Json(json!({"items": [], "error": "Access denied"}))).into_response(),
        Err(e) => {
            tracing::error!("search: {e}");
            (StatusCode::INTERNAL_SERVER_ERROR, headers, Json(json!({"items": [], "error": "Internal"}))).into_response()
        }
    }
}

#[cfg(test)]
mod tests {
    use std::path::PathBuf;

    use super::*;

    const FILES: [&str; 4] = ["rows.tsv", "families.tsv", "categories.tsv", "parts.tsv"];

    const ROWS: &str = "\
1\tsubcategory\tEN\t1\tDrill Bushings\t1\t10\t0\t110
2\tsubcategory\tFR\t1\tCanons de Perçage\t1\t10\t0\t110
3\tsubcategory\tEN\t1\tSwing Clamps\t14\t20\t0\t120
4\tsubcategory\tFR\t1\tBrides pivotantes\t14\t20\t0\t120
5\tsubcategory\tEN\t1\tToggle Pads\t14\t21\t0\t130
6\tsubcategory\tFR\t1\tInsert fileté plein\t10\t30\t0\t285
7\tsubcategory\tEN\t1\tSolid Key Inserts\t10\t30\t0\t285
8\tsubcategory\tFR\t1\tBroches lisses\t6\t40\t0\t100
9\tsearch_words\tEN\t1\tjig bushing\t1\t10\t0\t1000010
10\tsearch_words\tEN\t1\tclamp\t14\t20\t0\t1000020
11\tsearch_words\tEN\t1\tthreaded pins\t6\t41\t0\t1000030
12\tsubcategory\tEN\t1\tDowel Pins\t6\t41\t0\t105
13\tsubcategory\tEN\t1\tJig Percage Plates\t1\t11\t0\t200
100\tinventory\t\t1\tLS-1\t1\t10\t1000\t2000003
101\tinventory\t\t1\tLS-1A\t1\t10\t1001\t2000004
102\tinventory\t\t1\tLS-10\t1\t10\t1002\t2000005
103\tinventory\t\t1\tHBM-10\t2\t50\t1003\t2000003
104\tinventory\t\t1\tHBM-10-L\t2\t50\t1004\t2000004
105\tinventory\t\t1\tXLS1-9\t1\t10\t1005\t2000006
106\tinventory\t\t0\tOLD-1\t1\t10\t1006\t2000007
107\tinventory\t\t1\tSC-5\t14\t20\t1007\t2000001
108\tinventory\t\t1\tÉ-7\t1\t10\t1008\t2000008
200\tcompetitor_sku\t\t1\t#41102\t2\t50\t1003\t3000001
";
    const FAMILIES: &str = "\
10\t1\tActive\tDrill Bushings\tCanons de Perçage
11\t1\tActive\tJig Percage Plates\t
20\t14\tActive\tSwing Clamps\tBrides pivotantes
21\t14\tActive\tToggle Pads\tPatins
30\t10\tActive\tSolid Key Inserts\tInsert fileté plein
40\t6\tActive\tPlain Pins\tBroches lisses
41\t6\tActive\tDowel Pins\t
99\t1\tInactive\tOld Family\tAncienne famille
";
    const CATEGORIES: &str = "1\tDrilling\tCanons de Perçage\n6\tAlignment\tBroches et Goupilles de centrage\n14\tClamping\tBridage\n10\tTooling Essentials\tLes Essentiels\n";
    const PARTS: &str = "1000\tLS-1\t10\n1003\tHBM-10\t50\n";

    fn ix() -> Index {
        Index::build(ROWS, FAMILIES, CATEGORIES, PARTS, "", "").unwrap()
    }

    fn fr() -> Ctx {
        Ctx { lang: Lang::Fr, ..Ctx::default() }
    }

    fn run(ix: &Index, term: &str, ctx: &Ctx) -> Vec<(String, &'static str)> {
        hits(ix, term, ctx, 20)
    }

    fn hits(ix: &Index, term: &str, ctx: &Ctx, limit: usize) -> Vec<(String, &'static str)> {
        search(ix, term, ctx, limit)
            .iter()
            .map(|h| match *h {
                Hit::Row { entry, tier } => (label(ix, &ix.entries[entry as usize], ctx.lang), tier.name()),
                Hit::Lookup => (format!("Lookup Part# {}", term.trim()), "lookup"),
            })
            .collect()
    }

    fn row(label: &str, tier: &'static str) -> (String, &'static str) {
        (label.to_string(), tier)
    }

    fn lookup(term: &str) -> (String, &'static str) {
        row(&format!("Lookup Part# {term}"), "lookup")
    }

    fn by_code<'a>(ix: &'a Index, code: &str) -> &'a Entry {
        ix.entries.iter().find(|e| &*e.code == code).unwrap()
    }

    #[test]
    fn tier_order() {
        let ix = ix();
        let en = Ctx::default();
        assert_eq!(run(&ix, "ls1", &en), [row("LS-1", "exact"), row("LS-1A", "prefix"), row("LS-10", "prefix"), row("XLS1-9", "contains")]);
        for term in ["ls1", "hbm1", "hbn10", "bushing", "clamp", "threaded insert", "percage", "e"] {
            let tiers: Vec<Tier> = search(&ix, term, &en, 20)
                .iter()
                .filter_map(|h| match h {
                    Hit::Row { tier, .. } => Some(*tier),
                    Hit::Lookup => None,
                })
                .collect();
            assert!(tiers.is_sorted(), "{term}: {tiers:?}");
        }
    }

    #[test]
    fn separators() {
        let ix = ix();
        for term in ["LS-1", "ls1", "ls 1", "l.s/1", " ls_1 "] {
            assert_eq!(run(&ix, term, &Ctx::default())[0], row("LS-1", "exact"), "{term}");
        }
        assert_eq!(run(&ix, "hbm10", &Ctx::default())[0], row("HBM-10", "exact"));
    }

    #[test]
    fn plural_stems() {
        for (plural, singular) in [
            ("bushings", "bushing"),
            ("pins", "pin"),
            ("boxes", "box"),
            ("glasses", "glass"),
            ("assemblies", "assembly"),
            ("series", "serie"),
            ("broches", "broche"),
            ("anneaux", "anneau"),
            ("inserts", "insert"),
        ] {
            assert_eq!(stem(plural), stem(singular), "{plural}");
        }
        assert_eq!(stem("glass"), "glass");
        let ix = ix();
        let en = Ctx::default();
        assert_eq!(run(&ix, "bushings", &en), [row("Drill Bushings", "words"), lookup("bushings")]);
        assert_eq!(run(&ix, "bushing", &en), [row("Drill Bushings", "words"), lookup("bushing")]);
    }

    #[test]
    fn accents() {
        let ix = ix();
        assert_eq!(run(&ix, "e7", &Ctx::default())[0], row("É-7", "exact"));
        for term in ["percage", "PERÇAGE", "perçage"] {
            assert_eq!(run(&ix, term, &fr()), [row("Canons de Perçage", "words"), lookup(term)], "{term}");
        }
    }

    #[test]
    fn language() {
        let ix = ix();
        let en = Ctx::default();
        // A French family name reaches an English visitor as the family's English entry, after English matches.
        assert_eq!(run(&ix, "percage", &en), [row("Jig Percage Plates", "words"), row("Drill Bushings", "words"), lookup("percage")]);
        assert_eq!(run(&ix, "brides", &en)[0], row("Swing Clamps", "prefix"));
        assert_eq!(run(&ix, "brides", &fr())[0], row("Brides pivotantes", "prefix"));
        // Family 40 has no English row, family 41 no French one.
        assert_eq!(run(&ix, "broches", &en), [row("Dowel Pins", "category"), lookup("broches")]);
        assert_eq!(run(&ix, "broches", &fr()), [row("Broches lisses", "prefix"), lookup("broches")]);
        assert!(run(&ix, "dowel", &fr()).iter().all(|(l, _)| !l.contains("Dowel")));
    }

    #[test]
    fn code_typos() {
        let ix = ix();
        let en = Ctx::default();
        for term in ["hbn10", "hmb10", "hbmm10", "hb10", "hbm1o"] {
            assert_eq!(run(&ix, term, &en)[0], row("HBM-10", "typo"), "{term}");
        }
        assert_eq!(run(&ix, "hbn1", &en), [row("HBM-10", "typo"), row("HBM-10-L", "typo"), lookup("hbn1")]);
        assert_eq!(run(&ix, "lz1", &en), [lookup("lz1")], "codes under 4 characters get no typo variants");
    }

    #[test]
    fn word_typos() {
        let ix = ix();
        let en = Ctx::default();
        assert_eq!(run(&ix, "bushngs", &en), [row("Drill Bushings", "typo"), lookup("bushngs")]);
        assert_eq!(run(&ix, "swing clammps", &en), [row("Swing Clamps", "typo")]);
    }

    #[test]
    fn lookup_item() {
        let ix = ix();
        let en = Ctx::default();
        assert_eq!(run(&ix, "zzz9", &en), [lookup("zzz9")]);
        assert!(!run(&ix, "ls1", &en).contains(&lookup("ls1")), "an exact part number needs no lookup");
        assert_eq!(run(&ix, "zz top", &en), [], "only a one-word term can be a part number");
        assert_eq!(hits(&ix, "ls", &en, 2), [row("LS-1", "prefix"), lookup("ls")], "the lookup counts toward the limit");
        assert_eq!(run(&ix, "  ", &en), []);
    }

    #[test]
    fn blocked_and_admin() {
        let ix = ix();
        let blocked = Ctx { blocked: vec![14], ..Ctx::default() };
        assert_eq!(run(&ix, "clamp", &blocked), [lookup("clamp")]);
        assert_eq!(run(&ix, "sc-5", &blocked), [lookup("sc-5")]);
        assert_eq!(run(&ix, "sc-5", &Ctx::default())[0], row("SC-5", "exact"));
        assert_eq!(run(&ix, "old-1", &Ctx::default()), [row("OLD-1", "exact")]);
        assert_eq!(run(&ix, "old-1", &Ctx { admin: true, ..Ctx::default() }), [lookup("old-1")]);
    }

    #[test]
    fn families_once_parts_each() {
        let ix = ix();
        let en = Ctx::default();
        // "clamp" is a search word and part of a family name of family 20: one item. The category adds family 21.
        assert_eq!(run(&ix, "clamp", &en), [row("clamp - Swing Clamps", "prefix"), row("Toggle Pads", "category"), lookup("clamp")]);
        assert_eq!(run(&ix, "ls", &en), [row("LS-1", "prefix"), row("LS-1A", "prefix"), row("LS-10", "prefix"), lookup("ls")]);
    }

    #[test]
    fn some_words() {
        let ix = ix();
        assert_eq!(
            run(&ix, "threaded insert", &Ctx::default()),
            [row("Solid Key Inserts", "some_words"), row("threaded pins - Dowel Pins", "some_words")]
        );
        assert_eq!(
            run(&ix, "insert threaded", &Ctx::default()),
            [row("threaded pins - Dowel Pins", "some_words"), row("Solid Key Inserts", "some_words")],
            "in English the last word names the thing"
        );
    }

    const LEARNED: &str = "\
drill bushing\t30\t\t5
drill bushing\t10\tLS-10\t3
drill bushing\t10\t\t1
hpints\t41\t\t2
hpint\t20\t\t9
clamp\t20\t\t3
ghost\t999\t\t4
ghost\t10\tNOPE-1\t4
";
    const POPULAR: &str = "10\t50\n11\t1\n21\t40\n";

    fn learned_ix() -> Index {
        Index::build(ROWS, FAMILIES, CATEGORIES, PARTS, LEARNED, POPULAR).unwrap()
    }

    #[test]
    fn learned_tier() {
        let ix = learned_ix();
        let en = Ctx::default();
        let learned = [row("Solid Key Inserts", "learned"), row("LS-10", "learned"), row("Drill Bushings", "learned")];
        assert_eq!(run(&ix, "drill bushing", &en)[..3], learned);
        assert_eq!(
            run(&ix, "DRILL  BUSHINGS", &en)[..3],
            [row("Drill Bushings", "exact"), row("Solid Key Inserts", "learned"), row("LS-10", "learned")],
            "not learned as typed: the stemmed term's picks, right after the exact family name"
        );
        assert_eq!(
            run(&ix, "drill bushing", &fr())[..3],
            [row("Insert fileté plein", "learned"), row("LS-10", "learned"), row("Canons de Perçage", "learned")]
        );
        assert_eq!(run(&ix, "hpints", &en), [row("Dowel Pins", "learned"), lookup("hpints")], "learned as typed: no stem mixing");
        assert_eq!(run(&ix, "hpint", &en), [row("Swing Clamps", "learned"), lookup("hpint")]);
        assert!(!run(&ix, "clamp", &Ctx { blocked: vec![14], ..Ctx::default() }).iter().any(|(_, t)| *t == "learned"));
        assert_eq!(run(&ix, "ghost", &en), [lookup("ghost")], "picks of unknown families and SKUs are left out");
        assert_eq!(first_row(&ix, "drill bushing", &en), Some(7), "the Enter key's page opens the box's first item");
        assert_eq!(first_row(&ix, "drill bushing", &fr()), Some(6));
        assert_eq!(first_row(&ix, "ghost", &en), None, "the Lookup item is not a row");
        assert!(!run(&super::tests::ix(), "drill bushing", &en).iter().any(|(_, t)| *t == "learned"), "no learned.tsv: no learned tier");
    }

    #[test]
    fn popularity() {
        let (plain, learned) = (ix(), learned_ix());
        let en = Ctx::default();
        assert_eq!(run(&plain, "percage", &en), [row("Jig Percage Plates", "words"), row("Drill Bushings", "words"), lookup("percage")]);
        assert_eq!(run(&learned, "percage", &en), [row("Drill Bushings", "words"), row("Jig Percage Plates", "words"), lookup("percage")]);
        assert_eq!(run(&plain, "clamping", &en), [row("Swing Clamps", "category"), row("Toggle Pads", "category"), lookup("clamping")]);
        assert_eq!(run(&learned, "clamping", &en), [row("Toggle Pads", "category"), row("Swing Clamps", "category"), lookup("clamping")]);
        assert_eq!(run(&learned, "ls", &en), run(&plain, "ls", &en), "code tiers keep their order");
        let threaded_popular = Index::build(ROWS, FAMILIES, CATEGORIES, PARTS, "", "41\t99\n30\t1\n").unwrap();
        assert_eq!(
            run(&threaded_popular, "threaded insert", &en)[0],
            row("Solid Key Inserts", "some_words"),
            "some words matched: the head noun before a more popular family without it"
        );
    }

    #[test]
    fn labels() {
        let ix = ix();
        assert_eq!(label(&ix, by_code(&ix, "LS-1"), Lang::En), "LS-1");
        assert_eq!(label(&ix, by_code(&ix, "#41102"), Lang::En), "#41102 (Harcourt HBM-10)");
        assert_eq!(label(&ix, by_code(&ix, "jig bushing"), Lang::En), "jig bushing - Drill Bushings");
        assert_eq!(label(&ix, by_code(&ix, "jig bushing"), Lang::Fr), "jig bushing - Canons de Perçage");
        assert_eq!(label(&ix, by_code(&ix, "Drill Bushings"), Lang::Fr), "Canons de Perçage");
        assert_eq!(label(&ix, by_code(&ix, "Dowel Pins"), Lang::Fr), "Dowel Pins", "no French name: the English one");
        let v = answer_json(&ix, &search(&ix, "ls", &Ctx::default(), 2), "ls", &Ctx::default());
        assert_eq!(
            v,
            json!({"items": [
                {"value": "ls", "label": "LS-1", "category": "Inventory", "id": 100, "subcategory_id": 10, "sku": "LS-1", "tier": "prefix"},
                {"value": "ls", "label": "Lookup Part# ls", "category": "Lookup", "id": 0, "subcategory_id": 0, "sku": "", "tier": "lookup"}],
                "rows": 1, "found_rows": "1"})
        );
    }

    fn referer(page: &str) -> HeaderMap {
        let mut h = HeaderMap::new();
        h.insert("referer", format!("https://www.harcourt.co{page}").parse().unwrap());
        h
    }

    #[test]
    fn the_page_decides_the_language() {
        assert_eq!(page_lang(&referer("/catalog/?search_source=search")), Lang::En);
        assert_eq!(page_lang(&referer("/catalogue/")), Lang::Fr);
        assert_eq!(page_lang(&referer("/catalog/?s=1&lang=fr")), Lang::Fr);
        assert_eq!(page_lang(&HeaderMap::new()), Lang::En);
    }

    /// The export on the dev box (~/searchdata) or next to the bench (search/data). Absent: the test is skipped.
    fn data_dir() -> Option<PathBuf> {
        [Path::new(&std::env::var("HOME").unwrap_or_default()).join("searchdata"), Path::new(env!("CARGO_MANIFEST_DIR")).join("../search/data")]
            .into_iter()
            .find(|d| FILES.iter().all(|f| d.join(f).is_file()))
    }

    fn rss_kb() -> usize {
        let status = std::fs::read_to_string("/proc/self/status").unwrap_or_default();
        status.lines().find_map(|l| l.strip_prefix("VmRSS:")).and_then(|v| v.trim().trim_end_matches(" kB").parse().ok()).unwrap_or(0)
    }

    fn heap_bytes(ix: &Index) -> usize {
        use std::mem::size_of;
        let ids = |m: &[(Box<str>, Vec<u32>)]| m.iter().map(|(k, v)| size_of::<(Box<str>, Vec<u32>)>() + k.len() + v.capacity() * 4).sum::<usize>();
        ix.entries.capacity() * size_of::<Entry>()
            + ix.entries.iter().map(|e| e.code.len()).sum::<usize>()
            + ix.norms.text.capacity()
            + (ix.norms.bounds.capacity() + ix.by_code.capacity() + ix.by_rank.capacity()) * 4
            + ix.trigrams.capacity() * (size_of::<([u8; 3], Vec<u32>)>() + 1)
            + ix.trigrams.values().map(|v| v.capacity() * 4).sum::<usize>()
            + ids(&ix.words)
            + ids(&ix.category_words)
            + ix.skus.capacity() * (size_of::<(u32, Box<str>)>() + 1)
            + ix.skus.values().map(|s| s.len()).sum::<usize>()
    }

    #[test]
    fn real_data() {
        let Some(dir) = data_dir() else {
            eprintln!("real_data: no search export found, skipped");
            return;
        };
        let rss = rss_kb();
        let start = Instant::now();
        let ix = Index::load(&dir).unwrap();
        let built = start.elapsed();
        eprintln!(
            "real_data: {} entries from {} built in {built:?}; index ~{:.1} MB by size, RSS +{:.1} MB",
            ix.entries.len(),
            dir.display(),
            heap_bytes(&ix) as f64 / 1e6,
            rss_kb().saturating_sub(rss) as f64 / 1e3
        );
        let en = Ctx::default();
        let rows = |term: &str, limit: usize| -> Vec<(usize, Tier)> {
            search(&ix, term, &en, limit)
                .into_iter()
                .filter_map(|h| match h {
                    Hit::Row { entry, tier } => Some((entry as usize, tier)),
                    Hit::Lookup => None,
                })
                .collect()
        };
        let code = |i: usize| ix.entries[i].code.to_string();
        let first = |term: &str| rows(term, 20).first().map(|&(i, _)| code(i)).unwrap_or_default();

        assert_eq!(first("ls1"), "LS-1");
        assert_eq!(first("hbm10"), "HBM-10");
        let hpin = rows("HPIN", 20);
        assert!(
            hpin.iter().all(|&(i, _)| ix.norm(i).starts_with("HPIN")) && hpin.iter().filter(|&&(i, _)| ix.entries[i].kind == Kind::Part).count() >= 10,
            "HPIN: {:?}",
            hpin.iter().map(|&(i, _)| code(i)).collect::<Vec<_>>()
        );
        let families = |term: &str| {
            let mut f: Vec<u32> = rows(term, 100)
                .iter()
                .filter(|&&(i, t)| ix.entries[i].kind.is_family() && t <= Tier::Words)
                .map(|&(i, _)| ix.entries[i].subcategory_id)
                .collect();
            f.sort_unstable();
            f
        };
        let bushing = families("bushing");
        assert!(bushing.len() >= 5, "bushing: {bushing:?}");
        assert_eq!(families("bushings"), bushing);
        assert!(rows("threaded insert", 10).iter().any(|&(i, _)| ix.entries[i].subcategory_id == 231), "threaded insert: no family 231");
        let clamp: HashSet<u32> = rows("clamp", 500).iter().filter(|&&(i, _)| ix.entries[i].kind.is_family()).map(|&(i, _)| ix.entries[i].subcategory_id).collect();
        let clamping: Vec<u32> = ix
            .family_entries
            .iter()
            .map(|&e| &ix.entries[e as usize])
            .filter(|e| e.category_id == 14 && e.lang == Some(Lang::En))
            .map(|e| e.subcategory_id)
            .collect();
        let missing: Vec<&u32> = clamping.iter().filter(|s| !clamp.contains(s)).collect();
        assert!(missing.is_empty(), "clamp misses Clamping families {missing:?}");
        for (typo, part) in [("hbm1o", "HBM-10"), ("hchak-6-28", "HCHKA-6-28"), ("hsslb-4", "HSLB-4"), ("hpim-.1875-2.00", "HPIN-.1875-2.00"), ("hpim-.18", "HPIN-.1875-2.00")] {
            let found: Vec<String> = rows(typo, 20).iter().take(5).map(|&(i, _)| code(i)).collect();
            assert!(found.iter().any(|c| c == part), "{typo}: {found:?}");
        }

        let mut seed = 0x2545_f491_4f6c_dd1du64;
        let mut pick = |n: usize| {
            seed ^= seed << 13;
            seed ^= seed >> 7;
            seed ^= seed << 17;
            (seed % n as u64) as usize
        };
        let mut queries = Vec::with_capacity(2000);
        while queries.len() < 2000 {
            let e = if queries.len() % 4 == 3 {
                &ix.entries[ix.family_entries[pick(ix.family_entries.len())] as usize]
            } else {
                &ix.entries[pick(ix.entries.len())]
            };
            let c: Vec<char> = e.code.chars().collect();
            queries.push(match queries.len() % 4 {
                0 => e.code.to_string(),
                1 => c[..1 + pick(c.len())].iter().collect(),
                2 => {
                    let mut t = c.clone();
                    let i = pick(t.len());
                    t[i] = if t[i] == 'X' { 'Y' } else { 'X' };
                    t.into_iter().collect()
                }
                _ => c[..1 + pick(c.len())].iter().collect::<String>().to_lowercase(),
            });
        }
        let mut times: Vec<Duration> = Vec::with_capacity(queries.len());
        let mut found = 0;
        for q in &queries {
            let t = Instant::now();
            let h = search(&ix, q, &en, 20);
            times.push(t.elapsed());
            found += usize::from(h.iter().any(|h| matches!(h, Hit::Row { .. })));
        }
        let mut sorted = times.clone();
        sorted.sort_unstable();
        let slowest = times.iter().enumerate().max_by_key(|(_, t)| **t).map(|(i, _)| queries[i].as_str()).unwrap_or("");
        eprintln!(
            "real_data: {} queries (code, typed prefix, one typo, family name prefix): median {:?}, p99 {:?}, max {:?} ({slowest:?}); {} with results",
            queries.len(),
            sorted[sorted.len() / 2],
            sorted[sorted.len() * 99 / 100],
            sorted[sorted.len() - 1],
            found
        );
    }
}
