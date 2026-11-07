//! Crawlers we recognise (classes/bot.php). A bot is matched on the reverse DNS name of the client address, but only
//! a name that resolves forward to the same address counts: whoever owns an address writes its PTR record, so an
//! unconfirmed name proves nothing.

use std::{
    collections::HashMap,
    net::IpAddr,
    sync::{Arc, Mutex, MutexGuard, PoisonError},
    time::{Duration, Instant},
};

use regex::{Regex, RegexBuilder};
use sqlx::SqlitePool;
use tokio::sync::{watch, Semaphore};

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

/// How long a request waits for a confirmed name. A lookup still running then finishes in the background, and its
/// answer serves the next request from that address.
const WAIT: Duration = Duration::from_millis(300);
const CACHE_TTL: Duration = Duration::from_secs(3600);
const CACHE_MAX: usize = 10_000;
/// Lookups running at once. Each holds a thread of the blocking pool, which static files use too, until the
/// resolver answers; past this an address counts as not yet known.
const FLIGHTS_MAX: usize = 32;

/// A blocking lookup of the confirmed name of an address.
type Resolve = Arc<dyn Fn(IpAddr) -> Option<String> + Send + Sync>;

pub struct Dns(Source);

enum Source {
    System(Resolver),
    Fixed { reverse: HashMap<IpAddr, String>, forward: HashMap<String, Vec<IpAddr>> },
}

struct Resolver {
    resolve: Resolve,
    flights: Arc<Semaphore>,
    known: Arc<Mutex<HashMap<IpAddr, Known>>>,
}

enum Known {
    Answered(Instant, Option<String>),
    /// A lookup in flight, which sends its answer once to everyone waiting on this address.
    Asking(watch::Receiver<Option<Option<String>>>),
}

enum Lookup {
    Known(Option<String>),
    Waiting(watch::Receiver<Option<Option<String>>>),
}

impl Resolver {
    fn new(resolve: Resolve, flights: usize) -> Resolver {
        Resolver { resolve, flights: Arc::new(Semaphore::new(flights)), known: Arc::default() }
    }

    fn known(&self) -> MutexGuard<'_, HashMap<IpAddr, Known>> {
        self.known.lock().unwrap_or_else(PoisonError::into_inner)
    }

    /// The cached answer, the lookup already in flight, or a new one when a flight is free.
    fn lookup(&self, ip: IpAddr) -> Lookup {
        let mut known = self.known();
        match known.get(&ip) {
            Some(Known::Answered(at, name)) if at.elapsed() < CACHE_TTL => return Lookup::Known(name.clone()),
            Some(Known::Asking(flight)) if flight.has_changed().is_ok() => return Lookup::Waiting(flight.clone()),
            _ => {}
        }
        let Ok(permit) = self.flights.clone().try_acquire_owned() else { return Lookup::Known(None) };
        if known.len() >= CACHE_MAX {
            known.retain(|_, k| !matches!(k, Known::Answered(at, _) if at.elapsed() >= CACHE_TTL));
            if known.len() >= CACHE_MAX {
                known.retain(|_, k| matches!(k, Known::Asking(_)));
            }
        }
        let (answer, flight) = watch::channel(None);
        known.insert(ip, Known::Asking(flight.clone()));
        let (resolve, cache) = (self.resolve.clone(), self.known.clone());
        tokio::task::spawn_blocking(move || {
            let name = resolve(ip);
            drop(permit);
            cache.lock().unwrap_or_else(PoisonError::into_inner).insert(ip, Known::Answered(Instant::now(), name.clone()));
            answer.send_replace(Some(name));
        });
        Lookup::Waiting(flight)
    }

    async fn confirmed_name(&self, ip: IpAddr) -> Option<String> {
        let mut flight = match self.lookup(ip) {
            Lookup::Known(name) => return name,
            Lookup::Waiting(flight) => flight,
        };
        let answer = tokio::time::timeout(WAIT, flight.wait_for(Option::is_some)).await.ok().and_then(|sent| sent.ok().and_then(|a| a.clone()));
        answer.flatten()
    }
}

impl Dns {
    pub fn system() -> Dns {
        Dns(Source::System(Resolver::new(Arc::new(system_confirmed), FLIGHTS_MAX)))
    }

    /// `reverse` answers PTR lookups, `forward` answers A/AAAA lookups. Anything not listed resolves to nothing.
    pub fn fixed(reverse: &[(IpAddr, &str)], forward: &[(&str, IpAddr)]) -> Dns {
        let mut fwd: HashMap<String, Vec<IpAddr>> = HashMap::new();
        for (name, ip) in forward {
            fwd.entry(name.to_ascii_lowercase()).or_default().push(*ip);
        }
        Dns(Source::Fixed { reverse: reverse.iter().map(|(ip, n)| (*ip, n.to_string())).collect(), forward: fwd })
    }

    /// The PTR name of `ip`, lower case, only when that name resolves forward to `ip` again. None too when the
    /// answer is not known within WAIT.
    pub async fn confirmed_name(&self, ip: IpAddr) -> Option<String> {
        let ip = ip.to_canonical();
        match &self.0 {
            Source::Fixed { reverse, forward } => {
                let name = normalise(reverse.get(&ip)?)?;
                forward.get(&name)?.iter().any(|a| a.to_canonical() == ip).then_some(name)
            }
            Source::System(resolver) => resolver.confirmed_name(ip).await,
        }
    }
}

fn normalise(name: &str) -> Option<String> {
    let name = name.trim().trim_end_matches('.').to_ascii_lowercase();
    (!name.is_empty() && name.parse::<IpAddr>().is_err()).then_some(name)
}

fn system_confirmed(ip: IpAddr) -> Option<String> {
    let name = normalise(&dns_lookup::lookup_addr(&ip).ok()?)?;
    let confirmed = dns_lookup::lookup_host(&name).ok()?.into_iter().any(|a| a.to_canonical() == ip);
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

struct Rule {
    bot: Bot,
    pattern: Regex,
}

/// The bot table with its patterns compiled: read on first use and again whenever staff change it.
#[derive(Default)]
pub struct Bots(Mutex<Loaded>);

#[derive(Default)]
struct Loaded {
    generation: u64,
    rules: Option<Arc<[Rule]>>,
}

impl Bots {
    fn loaded(&self) -> MutexGuard<'_, Loaded> {
        self.0.lock().unwrap_or_else(PoisonError::into_inner)
    }

    /// Reads the table again. A read that began before this call is not kept.
    pub async fn reload(&self, pool: &SqlitePool) -> sqlx::Result<()> {
        let generation = {
            let mut loaded = self.loaded();
            loaded.generation += 1;
            loaded.rules = None;
            loaded.generation
        };
        self.load(pool, generation).await.map(drop)
    }

    async fn rules(&self, pool: &SqlitePool) -> sqlx::Result<Arc<[Rule]>> {
        let generation = {
            let loaded = self.loaded();
            if let Some(rules) = &loaded.rules {
                return Ok(rules.clone());
            }
            loaded.generation
        };
        self.load(pool, generation).await
    }

    async fn load(&self, pool: &SqlitePool, generation: u64) -> sqlx::Result<Arc<[Rule]>> {
        let rows = sqlx::query!(
            r#"SELECT id AS "id!: i64", code AS "code!", pattern AS "pattern!", name AS "name!", portal_id AS "portal_id!: i64",
                      catalog AS "catalog!: i64", status AS "status!" FROM bot ORDER BY id"#
        )
        .fetch_all(pool)
        .await?;
        let rules: Arc<[Rule]> = rows
            .into_iter()
            .filter_map(|r| {
                let status = parse_status(&r.status)?;
                let Some(pattern) = compile(&r.pattern) else {
                    tracing::debug!(bot = r.id, "bot pattern is not a readable PCRE literal");
                    return None;
                };
                Some(Rule { bot: Bot { id: r.id, code: r.code, name: r.name, portal_id: r.portal_id, catalog: r.catalog != 0, status }, pattern })
            })
            .collect();
        let mut loaded = self.loaded();
        if loaded.generation == generation {
            loaded.rules = Some(rules.clone());
        }
        Ok(rules)
    }
}

/// The first bot, in id order, whose pattern matches the forward-confirmed name of `ip`. Denied bots match too;
/// what Denied means is the caller's decision.
pub async fn identify(pool: &SqlitePool, dns: &Dns, bots: &Bots, ip: IpAddr) -> sqlx::Result<Option<Bot>> {
    let Some(name) = dns.confirmed_name(ip).await else { return Ok(None) };
    Ok(bots.rules(pool).await?.iter().find(|r| r.pattern.is_match(&name)).map(|r| r.bot.clone()))
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
        let bots = Bots::default();
        let bot = identify(&pool, &dns, &bots, ip("192.0.2.1")).await.unwrap().unwrap();
        assert_eq!((bot.id, bot.portal_id, bot.catalog, bot.status), (3, 4, true, BotStatus::Active));
        assert!(identify(&pool, &dns, &bots, ip("192.0.2.9")).await.unwrap().is_none());
    }

    #[tokio::test]
    async fn the_compiled_table_changes_when_it_is_reloaded() {
        let pool = crate::db::open_memory().await.unwrap();
        sqlx::query(r#"INSERT INTO bot (id, code, pattern, name, status) VALUES (1, 'crawl', '/\.example\.net$/', 'Crawler', 'Active')"#).execute(&pool).await.unwrap();
        let dns = Dns::fixed(&[(ip("192.0.2.1"), "crawl-1.example.net")], &[("crawl-1.example.net", ip("192.0.2.1"))]);
        let bots = Bots::default();
        async fn named(pool: &SqlitePool, dns: &Dns, bots: &Bots) -> Option<String> {
            identify(pool, dns, bots, ip("192.0.2.1")).await.unwrap().map(|b| b.name)
        }
        assert_eq!(named(&pool, &dns, &bots).await.as_deref(), Some("Crawler"));
        sqlx::query("UPDATE bot SET name = 'Renamed'").execute(&pool).await.unwrap();
        assert_eq!(named(&pool, &dns, &bots).await.as_deref(), Some("Crawler"), "compiled once");
        bots.reload(&pool).await.unwrap();
        assert_eq!(named(&pool, &dns, &bots).await.as_deref(), Some("Renamed"));
    }

    fn slow(calls: Arc<std::sync::atomic::AtomicUsize>, delay: Duration) -> Resolve {
        Arc::new(move |ip: IpAddr| {
            calls.fetch_add(1, std::sync::atomic::Ordering::SeqCst);
            std::thread::sleep(delay);
            Some(format!("host-{ip}.example.net"))
        })
    }

    #[tokio::test]
    async fn a_quick_lookup_answers_the_request_that_asked() {
        let calls = Arc::default();
        let resolver = Resolver::new(slow(Arc::clone(&calls), Duration::ZERO), 4);
        assert_eq!(resolver.confirmed_name(ip("192.0.2.1")).await.as_deref(), Some("host-192.0.2.1.example.net"));
        assert_eq!(resolver.confirmed_name(ip("192.0.2.1")).await.as_deref(), Some("host-192.0.2.1.example.net"));
        assert_eq!(calls.load(std::sync::atomic::Ordering::SeqCst), 1, "cached");
    }

    #[tokio::test]
    async fn a_slow_lookup_finishes_for_the_next_request_and_is_shared() {
        let calls = Arc::default();
        let resolver = Resolver::new(slow(Arc::clone(&calls), WAIT * 2), 4);
        let started = Instant::now();
        let (a, b) = tokio::join!(resolver.confirmed_name(ip("192.0.2.1")), resolver.confirmed_name(ip("192.0.2.1")));
        assert_eq!((a, b), (None, None));
        assert!(started.elapsed() < WAIT * 2, "the request did not wait for the resolver");
        tokio::time::sleep(WAIT * 2).await;
        assert_eq!(resolver.confirmed_name(ip("192.0.2.1")).await.as_deref(), Some("host-192.0.2.1.example.net"));
        assert_eq!(calls.load(std::sync::atomic::Ordering::SeqCst), 1, "one lookup for the address");
    }

    #[tokio::test]
    async fn lookups_past_the_limit_wait_for_a_free_flight() {
        let calls = Arc::default();
        let resolver = Resolver::new(slow(Arc::clone(&calls), WAIT * 2), 1);
        assert_eq!(resolver.confirmed_name(ip("192.0.2.1")).await, None);
        assert_eq!(resolver.confirmed_name(ip("192.0.2.2")).await, None, "not yet known");
        tokio::time::sleep(WAIT * 2).await;
        assert_eq!(calls.load(std::sync::atomic::Ordering::SeqCst), 1);
        assert_eq!(resolver.confirmed_name(ip("192.0.2.2")).await, None, "asked now");
        tokio::time::sleep(WAIT * 2).await;
        assert_eq!(resolver.confirmed_name(ip("192.0.2.2")).await.as_deref(), Some("host-192.0.2.2.example.net"));
    }
}
