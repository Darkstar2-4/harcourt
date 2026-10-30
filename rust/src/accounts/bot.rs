//! Crawlers we recognise (classes/bot.php). A bot is matched on the reverse DNS name of the client address, but only
//! a name that resolves forward to the same address counts: whoever owns an address writes its PTR record, so an
//! unconfirmed name proves nothing.

use std::{
    collections::HashMap,
    net::IpAddr,
    sync::Mutex,
    time::{Duration, Instant},
};

use regex::{Regex, RegexBuilder};
use sqlx::SqlitePool;

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum BotStatus {
    Active,
    Denied,
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Bot {
    pub id: i64,
    pub code: String,
    pub name: String,
    pub portal_id: i64,
    pub catalog: bool,
    pub status: BotStatus,
}

const LOOKUP_TIMEOUT: Duration = Duration::from_secs(2);
const CACHE_TTL: Duration = Duration::from_secs(3600);
const CACHE_MAX: usize = 10_000;

pub struct Dns(Source);

enum Source {
    System(Mutex<HashMap<IpAddr, (Instant, Option<String>)>>),
    Fixed { reverse: HashMap<IpAddr, String>, forward: HashMap<String, Vec<IpAddr>> },
}

impl Dns {
    pub fn system() -> Dns {
        Dns(Source::System(Mutex::new(HashMap::new())))
    }

    /// `reverse` answers PTR lookups, `forward` answers A/AAAA lookups. Anything not listed resolves to nothing.
    pub fn fixed(reverse: &[(IpAddr, &str)], forward: &[(&str, IpAddr)]) -> Dns {
        let mut fwd: HashMap<String, Vec<IpAddr>> = HashMap::new();
        for (name, ip) in forward {
            fwd.entry(name.to_ascii_lowercase()).or_default().push(*ip);
        }
        Dns(Source::Fixed { reverse: reverse.iter().map(|(ip, n)| (*ip, n.to_string())).collect(), forward: fwd })
    }

    /// The PTR name of `ip`, lower case, only when that name resolves forward to `ip` again.
    pub async fn confirmed_name(&self, ip: IpAddr) -> Option<String> {
        let ip = ip.to_canonical();
        match &self.0 {
            Source::Fixed { reverse, forward } => {
                let name = normalise(reverse.get(&ip)?)?;
                forward.get(&name)?.iter().any(|a| a.to_canonical() == ip).then_some(name)
            }
            Source::System(cache) => {
                if let Some(hit) = cache.lock().ok().and_then(|c| c.get(&ip).filter(|(at, _)| at.elapsed() < CACHE_TTL).map(|(_, n)| n.clone())) {
                    return hit;
                }
                let found = system_confirmed(ip).await;
                if let Ok(mut c) = cache.lock() {
                    if c.len() >= CACHE_MAX {
                        c.retain(|_, (at, _)| at.elapsed() < CACHE_TTL);
                        if c.len() >= CACHE_MAX {
                            c.clear();
                        }
                    }
                    c.insert(ip, (Instant::now(), found.clone()));
                }
                found
            }
        }
    }
}

fn normalise(name: &str) -> Option<String> {
    let name = name.trim().trim_end_matches('.').to_ascii_lowercase();
    (!name.is_empty() && name.parse::<IpAddr>().is_err()).then_some(name)
}

async fn system_confirmed(ip: IpAddr) -> Option<String> {
    let reverse = tokio::task::spawn_blocking(move || dns_lookup::lookup_addr(&ip).ok());
    let name = normalise(&tokio::time::timeout(LOOKUP_TIMEOUT, reverse).await.ok()?.ok()??)?;
    let addrs = tokio::time::timeout(LOOKUP_TIMEOUT, tokio::net::lookup_host((name.clone(), 0))).await.ok()?.ok()?;
    let confirmed = addrs.map(|a| a.ip().to_canonical()).any(|a| a == ip);
    confirmed.then_some(name)
}

/// A PCRE literal as preg_match takes it (`/\.googlebot\.com$/i`) as a Rust regex. None for anything this cannot
/// read faithfully, so such a row matches nothing.
fn compile(pattern: &str) -> Option<Regex> {
    let pattern = pattern.trim_start();
    let open = pattern.chars().next()?;
    if open.is_alphanumeric() || open == '\\' || open.is_whitespace() {
        return None;
    }
    let close = match open {
        '(' => ')',
        '{' => '}',
        '[' => ']',
        '<' => '>',
        c => c,
    };
    let rest = &pattern[open.len_utf8()..];
    let end = rest.rfind(close)?;
    let (body, flags) = (&rest[..end], &rest[end + close.len_utf8()..]);
    let body = body.replace(&format!("\\{close}"), &close.to_string());
    let mut builder = RegexBuilder::new(&body);
    for flag in flags.trim_end().chars() {
        match flag {
            'i' => builder.case_insensitive(true),
            'm' => builder.multi_line(true),
            's' => builder.dot_matches_new_line(true),
            'x' => builder.ignore_whitespace(true),
            _ => return None,
        };
    }
    builder.build().ok()
}

fn parse_status(s: &str) -> Option<BotStatus> {
    match s {
        "Active" => Some(BotStatus::Active),
        "Denied" => Some(BotStatus::Denied),
        _ => None,
    }
}

/// The first bot, in id order, whose pattern matches the forward-confirmed name of `ip`. Denied bots match too;
/// what Denied means is the caller's decision.
pub async fn identify(pool: &SqlitePool, dns: &Dns, ip: IpAddr) -> sqlx::Result<Option<Bot>> {
    let Some(name) = dns.confirmed_name(ip).await else { return Ok(None) };
    let rows = sqlx::query!(
        r#"SELECT id AS "id!: i64", code AS "code!", pattern AS "pattern!", name AS "name!", portal_id AS "portal_id!: i64",
                  catalog AS "catalog!: i64", status AS "status!" FROM bot ORDER BY id"#
    )
    .fetch_all(pool)
    .await?;
    for r in rows {
        let Some(status) = parse_status(&r.status) else { continue };
        let Some(re) = compile(&r.pattern) else {
            tracing::debug!(bot = r.id, "bot pattern is not a readable PCRE literal");
            continue;
        };
        if re.is_match(&name) {
            return Ok(Some(Bot { id: r.id, code: r.code, name: r.name, portal_id: r.portal_id, catalog: r.catalog != 0, status }));
        }
    }
    Ok(None)
}

#[cfg(test)]
mod tests {
    use super::*;

    fn ip(s: &str) -> IpAddr {
        s.parse().unwrap()
    }

    #[test]
    fn pcre_literals_compile_with_their_flags() {
        let re = compile(r"/\.crawler\.example$/i").unwrap();
        assert!(re.is_match("bot-1.CRAWLER.example"));
        assert!(!re.is_match("crawler.example.evil"));
        assert!(compile(r"#^spider\d+\.example\.net$#").unwrap().is_match("spider7.example.net"));
        assert!(compile(r"{a/b}").unwrap().is_match("xa/by"));
        assert!(compile(r"/a\/b/").unwrap().is_match("a/b"));
        for bad in ["", "abc", r"\abc\", "/unclosed", "/x/e", "/(/"] {
            assert!(compile(bad).is_none(), "{bad:?}");
        }
    }

    #[tokio::test]
    async fn only_forward_confirmed_names_count() {
        let dns = Dns::fixed(
            &[(ip("192.0.2.1"), "Crawl-1.Example.NET."), (ip("192.0.2.2"), "crawl-2.example.net"), (ip("192.0.2.3"), "192.0.2.3")],
            &[("crawl-1.example.net", ip("192.0.2.1")), ("crawl-2.example.net", ip("198.51.100.9"))],
        );
        assert_eq!(dns.confirmed_name(ip("192.0.2.1")).await.as_deref(), Some("crawl-1.example.net"));
        assert_eq!(dns.confirmed_name(ip("::ffff:192.0.2.1")).await.as_deref(), Some("crawl-1.example.net"));
        assert_eq!(dns.confirmed_name(ip("192.0.2.2")).await, None, "the name points elsewhere");
        assert_eq!(dns.confirmed_name(ip("192.0.2.3")).await, None, "an address is not a name");
        assert_eq!(dns.confirmed_name(ip("192.0.2.4")).await, None);
    }

    #[tokio::test]
    async fn the_first_matching_row_wins_and_bad_rows_are_skipped() {
        let pool = crate::db::open_memory().await.unwrap();
        sqlx::query(
            r#"INSERT INTO bot (id, code, pattern, name, portal_id, catalog, status) VALUES
               (1, 'broken', '/(/', 'Broken', 0, 1, 'Active'),
               (2, 'odd', '/example\.net$/', 'Odd status', 0, 1, 'Maybe'),
               (3, 'crawl', '/\.example\.net$/i', 'Crawler', 4, 1, 'Active'),
               (4, 'later', '/example/', 'Later', 0, 0, 'Denied')"#,
        )
        .execute(&pool)
        .await
        .unwrap();
        let dns = Dns::fixed(&[(ip("192.0.2.1"), "crawl-1.example.net")], &[("crawl-1.example.net", ip("192.0.2.1"))]);
        let bot = identify(&pool, &dns, ip("192.0.2.1")).await.unwrap().unwrap();
        assert_eq!((bot.id, bot.portal_id, bot.catalog, bot.status), (3, 4, true, BotStatus::Active));
        assert!(identify(&pool, &dns, ip("192.0.2.9")).await.unwrap().is_none());
    }
}
