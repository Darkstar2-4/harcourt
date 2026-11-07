//! The search box's learned ranking (search/SPEC.md "learned"), from the search log: for each search term the
//! product families searchers opened right after it, and how often each family is opened after any search. It
//! was search_learn.sh's nightly job (search_export.php --log, build_queryset.py, build_learned.py); the program now
//! does it itself and writes the same two tables (learned.tsv, popular.tsv) for `search::Index`.

use std::collections::HashMap;

use sqlx::SqlitePool;

/// A pick counts when it comes within ten minutes of the search.
const WINDOW: i64 = 600;
/// What hxweb opened for an Enter PHP found nothing for was logged as a pick within two seconds; no visitor picks
/// from an empty page that fast.
const FALLBACK: i64 = 2;
const PICKS: [&str; 4] = ["Subcategory", "SKU", "Lookup", "Competitor SKU"];
/// A family is learned for a term when it was opened after it this often, and as at least this share of
/// everything opened after it.
const MIN_PICKS: u32 = 3;
const MIN_SHARE: f64 = 0.1;
/// The log the job read: two years.
const HISTORY: i64 = 2 * 365 * 86_400;

/// One row of the search log (`log` rows of type Search).
#[derive(Debug, Clone)]
pub struct Event {
    pub id: i64,
    pub date_time: i64,
    /// The signed-in user, else the address.
    pub visitor: String,
    pub subtype: String,
    pub subcategory_id: i64,
    pub comment: String,
}

/// The search a General row records ("Search: <term> | <what live found>"), as the queryset normalized it.
fn term(comment: &str) -> Option<String> {
    let line = comment.replace('\n', " | ").replace(['\t', '\r'], " ");
    let rest = line.strip_prefix("Search: ")?;
    let first = rest.split(" | ").next().unwrap_or("");
    let t = first.split_whitespace().collect::<Vec<_>>().join(" ").to_lowercase();
    (!t.is_empty()).then_some(t)
}

/// The learned and popular tables, as build_learned.py wrote them.
pub fn learn(mut events: Vec<Event>) -> (String, String) {
    events.sort_by_key(|e| (e.visitor.clone(), e.date_time, e.id));
    // term -> family -> picks, in the order terms were first searched
    let mut order: Vec<String> = Vec::new();
    let mut picked: HashMap<String, (usize, HashMap<i64, u32>)> = HashMap::new();
    for (i, e) in events.iter().enumerate() {
        if e.subtype != "General" {
            continue;
        }
        let Some(t) = term(&e.comment) else { continue };
        let none = e.comment.contains("No records found");
        let entry = picked.entry(t.clone()).or_insert_with(|| {
            order.push(t.clone());
            (0, HashMap::new())
        });
        entry.0 += 1;
        for next in events[i + 1..].iter().take_while(|n| n.visitor == e.visitor) {
            if next.date_time - e.date_time > WINDOW || next.subtype == "General" {
                break;
            }
            if none && next.date_time - e.date_time <= FALLBACK {
                continue;
            }
            if PICKS.contains(&next.subtype.as_str()) {
                *entry.1.entry(next.subcategory_id).or_default() += 1;
                break;
            }
        }
    }
    let mut learned = String::new();
    let mut popular: HashMap<i64, u32> = HashMap::new();
    order.sort_by_key(|t| std::cmp::Reverse(picked[t].0));
    for t in &order {
        if t.chars().count() > 60 || t.contains('@') {
            continue;
        }
        let labels = &picked[t].1;
        let total: u32 = labels.values().sum();
        let mut families: Vec<(i64, u32)> = labels.iter().filter(|(f, _)| **f != 0).map(|(f, n)| (*f, *n)).collect();
        families.sort_by(|a, b| b.1.cmp(&a.1).then(a.0.cmp(&b.0)));
        for (family, picks) in families {
            *popular.entry(family).or_default() += picks;
            if picks >= MIN_PICKS && f64::from(picks) >= MIN_SHARE * f64::from(total) {
                learned.push_str(&format!("{t}\t{family}\t\t{picks}\n"));
            }
        }
    }
    let mut popular: Vec<(i64, u32)> = popular.into_iter().collect();
    popular.sort_by(|a, b| b.1.cmp(&a.1).then(a.0.cmp(&b.0)));
    (learned, popular.iter().map(|(f, n)| format!("{f}\t{n}\n")).collect())
}

/// The last two years of the search log, without bots and without the bench's scripted customers.
pub async fn from_log(pool: &SqlitePool, now: i64) -> sqlx::Result<(String, String)> {
    let since = now - HISTORY;
    let events = sqlx::query!(
        r#"SELECT id AS "id!: i64", date_time, user_id, ip, COALESCE(subtype, '') AS "subtype!: String", subcategory_id, COALESCE(comment, '') AS "comment!: String"
           FROM log WHERE type = 'Search' AND bot_id = 0 AND date_time > ?1
             AND user_id NOT IN (SELECT id FROM "user" WHERE comment = 'bench-test')"#,
        since
    )
    .fetch_all(pool)
    .await?
    .into_iter()
    .map(|r| Event {
        id: r.id,
        date_time: r.date_time,
        visitor: if r.user_id != 0 { format!("u{}", r.user_id) } else { format!("ip{}", r.ip) },
        subtype: r.subtype,
        subcategory_id: r.subcategory_id,
        comment: r.comment,
    })
    .collect();
    Ok(tokio::task::spawn_blocking(move || learn(events)).await.unwrap_or_default())
}

#[cfg(test)]
mod tests {
    use super::*;

    fn ev(id: i64, t: i64, who: &str, subtype: &str, family: i64, comment: &str) -> Event {
        Event { id, date_time: t, visitor: who.into(), subtype: subtype.into(), subcategory_id: family, comment: comment.into() }
    }

    #[test]
    fn a_family_opened_after_a_term_often_enough_is_learned() {
        let mut events = Vec::new();
        for v in 0..4 {
            let who = format!("ip{v}");
            events.push(ev(v * 10, 1000, &who, "General", 0, "Search: Drill  Bushing\nFound 3"));
            events.push(ev(v * 10 + 1, 1030, &who, "Subcategory", 448, ""));
        }
        events.push(ev(100, 1000, "u9", "General", 0, "Search: drill bushing"));
        events.push(ev(101, 1000 + WINDOW + 1, "u9", "Subcategory", 7, ""));
        events.push(ev(200, 5000, "u8", "General", 0, "Search: knob No records found"));
        events.push(ev(201, 5001, "u8", "SKU", 12, ""));
        let (learned, popular) = learn(events);
        assert_eq!(learned, "drill bushing\t448\t\t4\n");
        assert_eq!(popular, "448\t4\n");
    }

    /// Prints what a database's search log teaches, to compare with search_learn.sh's output for the same log:
    /// HX_LEARN_DB=/path/to.db HX_LEARN_NOW=<unix> cargo test learns_from_a_database -- --ignored --nocapture
    #[tokio::test]
    #[ignore]
    async fn learns_from_a_database() {
        let (Ok(db), Ok(now)) = (std::env::var("HX_LEARN_DB"), std::env::var("HX_LEARN_NOW")) else { return };
        let pool = SqlitePool::connect(&format!("sqlite://{db}?mode=ro")).await.unwrap();
        let (learned, popular) = from_log(&pool, now.parse().unwrap()).await.unwrap();
        println!("LEARNED\n{learned}POPULAR\n{popular}");
    }
}
