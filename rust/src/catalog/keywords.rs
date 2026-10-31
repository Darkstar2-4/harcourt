//! The results page's keyword search, as prod's classes/search.php does it: every term in a code (part numbers
//! compared without separators, words singular or plural), or the start of a word in the name of the category a
//! row is filed under; a term found in no name is corrected to the commonest name word one typo away.

use std::collections::{BTreeMap, HashMap};

const TYPO_CHARACTERS: &str = "ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789";

/// One `search` row as the keyword search reads it.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Row {
    pub table: String,
    pub code: String,
    pub code_normalized: String,
    pub category_id: i64,
    pub subcategory_id: i64,
    pub inventory_id: Option<i64>,
    pub ranking: i64,
}

/// search_class::normalize_code().
pub fn normalize(s: &str) -> String {
    s.chars().filter(|c| !c.is_whitespace() && !"-_./".contains(*c)).collect::<String>().to_uppercase()
}

/// keyword_stem(): "hinges" and "hinge" both "hinge"; anything not four letters or more passes through.
pub fn stem(term: &str) -> String {
    if term.len() < 4 || !term.chars().all(|c| c.is_ascii_alphabetic()) {
        return term.to_string();
    }
    let t = term.to_lowercase();
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

/// Words of category names (English and the page language), entities dropped, by category id.
pub fn category_words(names: &[(i64, String)]) -> BTreeMap<i64, Vec<String>> {
    let entity = regex::Regex::new(r"(?i)&[a-z0-9#]+;").expect("static pattern");
    let mut out: BTreeMap<i64, Vec<String>> = BTreeMap::new();
    for (id, name) in names {
        let clean = entity.replace_all(name, "").to_lowercase();
        out.entry(*id).or_default().extend(clean.split(|c: char| !c.is_ascii_alphanumeric()).filter(|w| !w.is_empty()).map(str::to_string));
    }
    out
}

/// category_matches(): categories with a name word starting with the term's stem.
fn category_matches(categories: &BTreeMap<i64, Vec<String>>, term: &str, min_length: usize) -> Vec<i64> {
    let s = stem(term).to_lowercase();
    if s.len() < min_length || !s.chars().all(|c| c.is_ascii_alphanumeric()) {
        return Vec::new();
    }
    categories.iter().filter(|(_, words)| words.iter().any(|w| w.starts_with(&s))).map(|(id, _)| *id).collect()
}

/// keyword_like(): the term in the row's code, separator-free when it has letters or digits.
fn like(row: &Row, term: &str) -> bool {
    let n = normalize(&stem(term));
    if n.chars().any(|c| c.is_ascii_alphanumeric()) {
        row.code_normalized.to_uppercase().contains(&n)
    } else {
        row.code.to_lowercase().contains(&term.to_lowercase())
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
            if !term.chars().any(|c| c.is_ascii_alphanumeric()) {
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

/// keywords(): name words of four letters or more and how often each appears.
pub fn keywords<'a>(codes: impl Iterator<Item = &'a str>) -> HashMap<String, usize> {
    let mut out = HashMap::new();
    for code in codes {
        for w in code.to_lowercase().split(|c: char| !c.is_ascii_lowercase()).filter(|w| w.len() >= 4) {
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
/// away, when there is one.
pub fn correct(terms: &[String], keywords: &HashMap<String, usize>) -> Vec<String> {
    terms
        .iter()
        .map(|term| {
            if term.len() < 4 || !term.chars().all(|c| c.is_ascii_alphabetic()) {
                return term.clone();
            }
            let s = stem(term);
            if keywords.keys().any(|w| w.contains(&s)) {
                return term.clone();
            }
            let mut best = (0, term.clone());
            for v in typo_variants(&term.to_uppercase()) {
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
        Row { table: table.into(), code: code.into(), code_normalized: normalize(code), category_id, subcategory_id: 1, inventory_id: None, ranking: 0 }
    }

    #[test]
    fn stems_and_codes() {
        assert_eq!(stem("hinges"), "hinge");
        assert_eq!(stem("assemblies"), "assembl");
        assert_eq!(stem("bushes"), "bush");
        assert_eq!(stem("glass"), "glass");
        assert_eq!(stem("LS-10"), "LS-10");
        assert_eq!(normalize("hb-lt 1.5/x"), "HBLT15X");
    }

    #[test]
    fn terms_match_codes_or_the_category_they_are_filed_under() {
        let cats = category_words(&[(1, "Clamping &amp; Fixtures".into()), (2, "Shipping".into())]);
        let f = Filter::new(&["clamp".into()], &cats);
        assert!(f.matches(&row("subcategory", "Toggle Pads", 1)), "filed under Clamping");
        assert!(!f.matches(&row("subcategory", "Toggle Pads", 2)));
        assert!(f.matches(&row("category", "Clamping", 1)));
        let f = Filter::new(&["ls1".into()], &cats);
        assert!(f.matches(&row("inventory", "LS-1A", 9)));
        let f = Filter::new(&["pin".into()], &cats);
        assert!(!f.matches(&row("category", "Shipping", 2)), "pin does not start a word of Shipping");
    }

    #[test]
    fn unknown_words_are_corrected_one_typo_away() {
        let kw = keywords(["Drill Bushings", "Drill Bushing Liners", "Toggle Clamps"].into_iter());
        assert_eq!(correct(&["drill".into(), "bushng".into()], &kw), ["drill", "bushing"]);
        assert_eq!(correct(&["zzzzz".into(), "ls10".into()], &kw), ["zzzzz", "ls10"]);
    }
}
