//! The results page's keyword search, as prod's classes/search.php does it: every term in a code (part numbers
//! compared without separators, words singular or plural), or the start of a word in the name of the category a
//! row is filed under; a term found in no name is corrected to the commonest name word one typo away. Names and
//! terms are folded and split into words as the search box does it (`search::fold`, `search::tokens`).

use std::collections::{BTreeMap, HashMap};

use super::search::{category_name_words, code_contains, fold, normalize_code, tokens, TYPO_CODE_CHARS};

const TYPO_CHARACTERS: &str = "ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789";

/// One `search` row as the keyword search reads it.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Row {
    pub table: String,
    pub code: String,
    /// `search::normalize_code` of the code.
    pub code_normalized: String,
    pub category_id: i64,
    pub subcategory_id: i64,
    pub inventory_id: Option<i64>,
    pub ranking: i64,
}

/// keyword_stem(), of the folded term: "hinges" and "hinge" both "hinge", "perçages" "percage"; anything not four
/// letters or more passes through folded. It cuts deeper than the box's stem because it is matched against names as
/// they are, not against stemmed ones: "assembl" is in both "assembly" and "assemblies".
pub fn stem(term: &str) -> String {
    let t = fold(term);
    if t.len() < 4 || !t.chars().all(|c| c.is_ascii_alphabetic()) {
        return t;
    }
    let cut = if t.ends_with("ies") {
        3
    } else if ["ses", "xes", "zes", "ches", "shes"].iter().any(|e| t.ends_with(e)) {
        2
    } else if (t.ends_with('s') && !t.ends_with("ss")) || (t.len() >= 5 && t.ends_with('y')) {
        1
    } else {
        0
    };
    let s = &t[..t.len() - cut];
    if s.len() >= 3 { s.to_string() } else { t }
}

/// Words of category names (English and the page language) as the box reads them (`search::category_name_words`),
/// by category id.
pub fn category_words(names: &[(i64, String)]) -> BTreeMap<i64, Vec<String>> {
    let mut out: BTreeMap<i64, Vec<String>> = BTreeMap::new();
    for (id, name) in names {
        out.entry(*id).or_default().extend(category_name_words(name));
    }
    out
}

/// category_matches(): for a term that is one word (`search::tokens`), categories with a name word starting with
/// its stem.
fn category_matches(categories: &BTreeMap<i64, Vec<String>>, term: &str, min_length: usize) -> Vec<i64> {
    let s = stem(term);
    if tokens(&s) != [s.as_str()] || s.chars().count() < min_length {
        return Vec::new();
    }
    categories.iter().filter(|(_, words)| words.iter().any(|w| w.starts_with(&s))).map(|(id, _)| *id).collect()
}

/// keyword_like(): the term in the row's code, separator-free when it has letters or digits.
fn like(row: &Row, term: &str) -> bool {
    let n = normalize_code(&stem(term));
    if n.chars().any(|c| c.is_ascii_alphanumeric()) {
        row.code_normalized.contains(&n)
    } else {
        code_contains(&row.code, term)
    }
}

/// keyword_where() as a test of one row.
pub struct Filter {
    terms: Vec<(String, Vec<i64>)>,
    category_ids: Vec<i64>,
}

impl Filter {
    pub fn new(terms: &[String], categories: &BTreeMap<i64, Vec<String>>) -> Filter {
        let mut out = Vec::new();
        let mut ids: Option<Vec<i64>> = None;
        for term in terms.iter().filter(|t| !t.is_empty()) {
            out.push((term.clone(), category_matches(categories, term, 3)));
            if tokens(term).is_empty() {
                continue;
            }
            let m = category_matches(categories, term, 1);
            ids = Some(match ids {
                Some(prev) => prev.into_iter().filter(|i| m.contains(i)).collect(),
                None => m,
            });
        }
        Filter { terms: out, category_ids: ids.unwrap_or_default() }
    }

    pub fn matches(&self, row: &Row) -> bool {
        if row.table == "category" {
            return self.category_ids.contains(&row.category_id);
        }
        self.terms.iter().all(|(term, cats)| like(row, term) || cats.contains(&row.category_id))
    }
}

/// keywords(): name words of four letters or more, folded, and how often each appears. A word here is letters only,
/// as prod split it ("m8x20" has none), where the box's words keep their digits.
pub fn keywords<'a>(codes: impl Iterator<Item = &'a str>) -> HashMap<String, usize> {
    let mut out = HashMap::new();
    for code in codes {
        for w in fold(code).split(|c: char| !c.is_ascii_lowercase()).filter(|w| w.len() >= 4) {
            *out.entry(w.to_string()).or_insert(0) += 1;
        }
    }
    out
}

fn typo_variants(code: &str) -> Vec<String> {
    let chars: Vec<char> = code.chars().collect();
    let mut out: Vec<String> = Vec::new();
    let s = |v: &[char]| v.iter().collect::<String>();
    for i in 0..chars.len() {
        out.push(s(&[&chars[..i], &chars[i + 1..]].concat()));
        if i + 1 < chars.len() {
            let mut t = chars.clone();
            t.swap(i, i + 1);
            out.push(s(&t));
        }
        for c in TYPO_CHARACTERS.chars() {
            let mut t = chars.clone();
            t[i] = c;
            out.push(s(&t));
        }
    }
    for i in 0..=chars.len() {
        for c in TYPO_CHARACTERS.chars() {
            let mut t = chars.clone();
            t.insert(i, c);
            out.push(s(&t));
        }
    }
    let mut seen = std::collections::HashSet::new();
    out.retain(|v| !v.is_empty() && v != code && seen.insert(v.clone()));
    out
}

/// keyword_correct(): each word of four letters or more found in no name becomes the commonest name word one typo
/// away, when there is one; accents are folded first. Words longer than the search box corrects are left as typed.
pub fn correct(terms: &[String], keywords: &HashMap<String, usize>) -> Vec<String> {
    terms
        .iter()
        .map(|term| {
            let folded = fold(term);
            if !TYPO_CODE_CHARS.contains(&folded.len()) || !folded.chars().all(|c| c.is_ascii_alphabetic()) {
                return term.clone();
            }
            let s = stem(&folded);
            if keywords.keys().any(|w| w.contains(&s)) {
                return term.clone();
            }
            let mut best = (0, term.clone());
            for v in typo_variants(&folded.to_uppercase()) {
                let v = v.to_lowercase();
                if let Some(&n) = keywords.get(&v) {
                    if n > best.0 {
                        best = (n, v);
                    }
                }
            }
            best.1
        })
        .collect()
}

#[cfg(test)]
mod tests {
    use super::*;

    fn row(table: &str, code: &str, category_id: i64) -> Row {
        Row { table: table.into(), code: code.into(), code_normalized: normalize_code(code), category_id, subcategory_id: 1, inventory_id: None, ranking: 0 }
    }

    #[test]
    fn stems_and_codes() {
        assert_eq!(stem("hinges"), "hinge");
        assert_eq!(stem("assemblies"), "assembl");
        assert_eq!(stem("bushes"), "bush");
        assert_eq!(stem("glass"), "glass");
        assert_eq!(stem("LS-10"), "ls-10");
        assert_eq!(stem("Perçages"), "percage");
        assert_eq!(normalize_code("hb-lt 1.5/x"), "HBLT15X");
    }

    #[test]
    fn terms_match_codes_or_the_category_they_are_filed_under() {
        let cats = category_words(&[(1, "Clamping &amp; Fixtures".into()), (2, "Shipping".into())]);
        assert_eq!(cats[&1], ["clamping", "fixtures"]);
        let f = Filter::new(&["clamp".into()], &cats);
        assert!(f.matches(&row("subcategory", "Toggle Pads", 1)), "filed under Clamping");
        assert!(!f.matches(&row("subcategory", "Toggle Pads", 2)));
        assert!(f.matches(&row("category", "Clamping", 1)));
        let f = Filter::new(&["ls1".into()], &cats);
        assert!(f.matches(&row("inventory", "LS-1A", 9)));
        let f = Filter::new(&["pin".into()], &cats);
        assert!(!f.matches(&row("category", "Shipping", 2)), "pin does not start a word of Shipping");
        let f = Filter::new(&["e7".into()], &cats);
        assert!(f.matches(&row("inventory", "É-7", 9)), "codes compare as the search box compares them");
        let f = Filter::new(&["é-7".into()], &cats);
        assert!(f.matches(&row("inventory", "E-7", 9)));
        let f = Filter::new(&["ø".into()], &cats);
        assert!(f.matches(&row("subcategory", "Ring O", 9)));
    }

    #[test]
    fn accented_category_names_match_with_or_without_the_accent() {
        let cats = category_words(&[(1, "Gabarits de Perçage".into()), (2, "Pièces &amp; Écrous".into())]);
        assert_eq!(cats[&1], ["gabarits", "de", "percage"]);
        assert_eq!(cats[&2], ["pieces", "ecrous"]);
        for term in ["perçage", "percage", "Perçages", "perç", "écrou", "ecrous"] {
            let f = Filter::new(&[term.into()], &cats);
            let id = if term.contains("crou") { 2 } else { 1 };
            assert!(f.matches(&row("subcategory", "Toggle Pads", id)), "{term}");
            assert!(f.matches(&row("category", "", id)), "{term}");
        }
        assert!(!Filter::new(&["per-age".into()], &cats).matches(&row("subcategory", "Toggle Pads", 1)), "a term of two words names no category");
    }

    #[test]
    fn accented_words_are_corrected_as_their_folded_forms() {
        let kw = keywords(["Gabarits de Perçage"].into_iter());
        assert_eq!(kw.get("percage"), Some(&1));
        assert_eq!(correct(&["perçgae".into(), "percage".into(), "perçage".into()], &kw), ["percage", "percage", "perçage"]);
    }

    #[test]
    fn unknown_words_are_corrected_one_typo_away() {
        let kw = keywords(["Drill Bushings", "Drill Bushing Liners", "Toggle Clamps"].into_iter());
        assert_eq!(correct(&["drill".into(), "bushng".into()], &kw), ["drill", "bushing"]);
        assert_eq!(correct(&["zzzzz".into(), "ls10".into()], &kw), ["zzzzz", "ls10"]);
        let long = "bushingx".repeat(4);
        assert_eq!(correct(&[long.clone()], &keywords([long[1..].to_string().as_str()].into_iter())), [long], "past the box's typo length");
    }
}
