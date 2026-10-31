//! `hx-diff bench`: replay a corpus against one server with concurrency and print latency percentiles per route group.
//! Each worker browses inside its own PHP session (primed like a warm case) unless `--cold`; one unrecorded round
//! warms the server first. With `--sql-log FILE` (the server's log, run with `RUST_LOG=info,sqlx::query=debug`) a
//! first pass sends every case alone and counts the statements logged for it.

use std::{collections::BTreeMap, io::Read, path::PathBuf, process::ExitCode, sync::Arc, time::Instant};

use regex::Regex;
use reqwest::header::{ACCEPT_ENCODING, ACCEPT_LANGUAGE, COOKIE, HOST, USER_AGENT};

use crate::{corpus::Case, fail, persona::Personas, prime, warm, SITE, UA};

#[derive(Debug)]
pub struct Args {
    pub rust: String,
    pub jobs: usize,
    pub rounds: usize,
    pub cold: bool,
    pub filter: Option<Regex>,
    pub sql_log: Option<PathBuf>,
    pub corpora: Vec<PathBuf>,
}

/// The traffic groups by path: the catalog and its AJAX, search, product files, scripts and styles, other static
/// files, and the rest (content pages).
pub fn group(path: &str) -> &'static str {
    let p = path.split('?').next().unwrap_or("").to_ascii_lowercase();
    let ext = p.rsplit_once('.').map_or("", |(_, e)| e);
    let starts = |prefixes: &[&str]| prefixes.iter().any(|x| p.starts_with(x));
    match ext {
        _ if starts(&["/scs_file.php", "/document", "/image_viewer.php", "/images_subcategory/", "/overview_documents/"]) || ext == "pdf" => "files",
        "js" | "css" => "scripts",
        _ if starts(&["/scs_ajax.php", "/hx_search_ctx.php", "/search", "/recherche"]) => "search",
        _ if starts(&["/catalog", "/scscpq", "/scs_api.php", "/customer_portal"]) => "catalog",
        "" | "php" | "xml" | "txt" => "content",
        _ => "static",
    }
}

fn percentile(sorted: &[u64], q: f64) -> f64 {
    sorted[((q * sorted.len() as f64).ceil() as usize).clamp(1, sorted.len()) - 1] as f64 / 1000.0
}

fn load_average() -> String {
    std::fs::read_to_string("/proc/loadavg").map(|s| s.split_whitespace().take(3).collect::<Vec<_>>().join(" ")).unwrap_or_default()
}

struct Bench {
    client: reqwest::Client,
    base: String,
    cases: Vec<(String, Case)>,
    /// Per worker, the PHP session for each language.
    sessions: Vec<BTreeMap<&'static str, String>>,
}

impl Bench {
    /// Case `i` as worker `w`: microseconds to the last byte, bytes, and whether it failed (no answer or a 5xx).
    async fn send(&self, w: usize, i: usize) -> (u64, usize, bool) {
        let (ip, c) = &self.cases[i];
        let mut req = (self.client.request(c.method.clone(), format!("{}{}", self.base, c.path)))
            .header(HOST, SITE)
            .header(USER_AGENT, UA)
            .header(ACCEPT_ENCODING, "gzip, deflate, br")
            .header(ACCEPT_LANGUAGE, c.language.accept_language())
            .header("x-real-ip", ip);
        if let Some(cookie) = self.sessions[w].get(c.language.code()).and_then(|sid| warm::cookie_header(Some(sid), None)) {
            req = req.header(COOKIE, cookie);
        }
        let start = Instant::now();
        let answer = match req.send().await {
            Ok(resp) => {
                let status = resp.status().as_u16();
                resp.bytes().await.ok().map(|body| (status, body.len()))
            }
            Err(_) => None,
        };
        (start.elapsed().as_micros() as u64, answer.map_or(0, |a| a.1), answer.is_none_or(|a| a.0 >= 500))
    }
}

pub async fn run(a: Args, personas: Personas) -> ExitCode {
    let mut cases = Vec::new();
    for path in &a.corpora {
        match crate::corpus::load(path, &personas) {
            Ok(c) => cases.extend(c.into_iter().filter(|c| a.filter.as_ref().is_none_or(|f| f.is_match(&c.name))).map(|c| {
                (personas.get(&c.persona).map(|p| p.ip.to_string()).unwrap_or_default(), c)
            })),
            Err(e) => return fail(&e),
        }
    }
    let (Some(anonymous), Ok(client)) = (personas.get("anonymous"), reqwest::Client::builder().redirect(reqwest::redirect::Policy::none()).no_proxy().build()) else {
        return fail("no anonymous persona or no HTTP client");
    };
    let mut sessions = vec![BTreeMap::new(); a.jobs];
    for (w, (_, c)) in (0..a.jobs).flat_map(|w| cases.iter().map(move |c| (w, c))).filter(|_| !a.cold) {
        if !sessions[w].contains_key(c.language.code()) {
            match prime(&client, &a.rust, anonymous, c.language).await {
                Ok(sid) => sessions[w].insert(c.language.code(), sid),
                Err(e) => return fail(&e),
            };
        }
    }
    let b = Arc::new(Bench { client, base: a.rust.clone(), cases, sessions });

    let mut sql: BTreeMap<&str, (usize, usize)> = BTreeMap::new();
    let mut heaviest = Vec::new();
    if let Some(path) = &a.sql_log {
        let Ok(mut log) = std::fs::File::open(path) else { return fail(&format!("cannot read {}", path.display())) };
        let mut text = String::new();
        let _ = log.read_to_string(&mut text);
        for i in 0..b.cases.len() {
            b.send(0, i).await;
            text.clear();
            let _ = log.read_to_string(&mut text);
            let n = text.matches("rows_returned").count();
            for g in [group(&b.cases[i].1.path), "ALL"] {
                let e = sql.entry(g).or_default();
                *e = (e.0 + n, e.1 + 1);
            }
            heaviest.push((n, i));
        }
        heaviest.sort_by(|x, y| y.cmp(x));
    }

    let start_load = load_average();
    let mut samples: BTreeMap<&str, Vec<(u64, usize, bool)>> = BTreeMap::new();
    let mut start = Instant::now();
    for round in 0..=a.rounds {
        if round == 1 {
            start = Instant::now();
        }
        let next = Arc::new(std::sync::atomic::AtomicUsize::new(0));
        let mut set = tokio::task::JoinSet::new();
        for w in 0..a.jobs {
            let (b, next) = (b.clone(), next.clone());
            set.spawn(async move {
                let mut mine = Vec::new();
                loop {
                    let i = next.fetch_add(1, std::sync::atomic::Ordering::Relaxed);
                    if i >= b.cases.len() {
                        return mine;
                    }
                    mine.push((i, b.send(w, i).await));
                }
            });
        }
        while let Some(done) = set.join_next().await {
            for (i, s) in done.unwrap_or_default().into_iter().filter(|_| round > 0) {
                samples.entry(group(&b.cases[i].1.path)).or_default().push(s);
                samples.entry("ALL").or_default().push(s);
            }
        }
    }
    let rate = samples.get("ALL").map_or(0, Vec::len) as f64 / start.elapsed().as_secs_f64();
    println!("{} cases x {} rounds, {} workers, {} sessions; load average {start_load} at start, {} at end", b.cases.len(), a.rounds, a.jobs, if a.cold { "cold" } else { "warm" }, load_average());
    println!("{:<8} {:>7} {:>9} {:>9} {:>9} {:>9} {:>10} {:>7} {:>7}", "group", "reqs", "p50 ms", "p95 ms", "p99 ms", "max ms", "mean B", "sql/req", "errors");
    for (name, list) in &mut samples {
        list.sort_unstable();
        let micros: Vec<u64> = list.iter().map(|s| s.0).collect();
        let per = sql.get(name).map_or("-".into(), |(n, reqs)| format!("{:.1}", *n as f64 / *reqs as f64));
        let (p50, p95, p99, max) = (percentile(&micros, 0.5), percentile(&micros, 0.95), percentile(&micros, 0.99), percentile(&micros, 1.0));
        let bytes = list.iter().map(|s| s.1).sum::<usize>() / list.len();
        println!("{name:<8} {:>7} {p50:>9.2} {p95:>9.2} {p99:>9.2} {max:>9.2} {bytes:>10} {per:>7} {:>7}", list.len(), list.iter().filter(|s| s.2).count());
    }
    println!("throughput {rate:.0} requests/s");
    for (n, i) in heaviest.iter().take(12) {
        println!("{n:>5} statements  {}", b.cases[*i].1.path);
    }
    ExitCode::SUCCESS
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn paths_fall_into_the_traffic_groups_and_percentiles_are_nearest_rank() {
        let groups = ["/catalogue/?lang=fr", "/scs_ajax.php?term=hs", "/scs_file.php?content_id=x", "/scs_scripts/a.js?v=3", "/wp-content/uploads/a.png", "/sitemap_index.xml", "/company/"];
        assert_eq!(groups.map(group), ["catalog", "search", "files", "scripts", "static", "content", "content"]);
        let v: Vec<u64> = (1..=100).map(|x| x * 1000).collect();
        assert_eq!([0.5, 0.99, 1.0].map(|q| percentile(&v, q)), [50.0, 99.0, 100.0]);
    }
}
