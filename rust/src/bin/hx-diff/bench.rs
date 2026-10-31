//! `hx-diff bench`: replay a corpus against one server with concurrency and print latency percentiles per route group.
//! Each worker browses inside its own PHP session (primed like a warm case) unless `--cold`. One unrecorded round
//! warms the server's caches first. With `--sql-log FILE` (the server's log, started with
//! `RUST_LOG=info,sqlx::query=debug`) a first pass sends every case alone and counts the statements logged for it.

use std::{
    collections::BTreeMap,
    io::{Read, Seek, SeekFrom},
    path::PathBuf,
    process::ExitCode,
    sync::{
        atomic::{AtomicUsize, Ordering},
        Arc,
    },
    time::{Duration, Instant},
};

use regex::Regex;
use reqwest::header::{ACCEPT_ENCODING, ACCEPT_LANGUAGE, COOKIE, HOST, USER_AGENT};

use crate::{corpus, persona::Personas, prime, warm, SITE, UA};

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

/// The traffic groups the site is tuned for, by path: the catalog and its AJAX, search, product files, the catalog's
/// scripts and styles, other static files, and everything else (content pages).
pub fn group(path: &str) -> &'static str {
    let p = path.split('?').next().unwrap_or("").to_ascii_lowercase();
    let ext = p.rsplit_once('.').map_or("", |(_, e)| e);
    let starts = |prefixes: &[&str]| prefixes.iter().any(|x| p.starts_with(x));
    if starts(&["/scs_file.php", "/document", "/image_viewer.php", "/images_subcategory/", "/overview_documents/"]) || ext == "pdf" {
        "files"
    } else if ext == "js" || ext == "css" {
        "scripts"
    } else if starts(&["/scs_ajax.php", "/hx_search_ctx.php", "/search", "/recherche"]) {
        "search"
    } else if starts(&["/catalog", "/scscpq", "/scs_api.php", "/customer_portal"]) {
        "catalog"
    } else if p.contains('.') && !p.ends_with(".php") && !p.ends_with(".xml") && !p.ends_with(".txt") {
        "static"
    } else {
        "content"
    }
}

struct Sample {
    group: &'static str,
    micros: u64,
    bytes: usize,
    failed: bool,
}

fn percentile(sorted: &[u64], q: f64) -> f64 {
    let i = ((q * sorted.len() as f64).ceil() as usize).clamp(1, sorted.len()) - 1;
    sorted[i] as f64 / 1000.0
}

fn load_average() -> String {
    std::fs::read_to_string("/proc/loadavg").map(|s| s.split_whitespace().take(3).collect::<Vec<_>>().join(" ")).unwrap_or_default()
}

/// Statements the server logged since `from`, and where the log ends now.
fn statements_since(log: &mut std::fs::File, from: u64) -> std::io::Result<(usize, u64)> {
    let mut text = String::new();
    log.seek(SeekFrom::Start(from))?;
    let read = log.read_to_string(&mut text)?;
    Ok((text.matches("rows_returned").count(), from + read as u64))
}

pub async fn run(a: Args, personas: Personas) -> ExitCode {
    let mut cases = Vec::new();
    for path in &a.corpora {
        match corpus::load(path, &personas) {
            Ok(c) => cases.extend(
                c.into_iter()
                    .filter(|c| a.filter.as_ref().is_none_or(|f| f.is_match(&c.name)))
                    .map(|c| (personas.get(&c.persona).map(|p| p.ip.to_string()).unwrap_or_default(), c)),
            ),
            Err(e) => return crate::fail(&e),
        }
    }
    let Some(anonymous) = personas.get("anonymous") else { return crate::fail("no anonymous persona") };
    let client = match reqwest::Client::builder().redirect(reqwest::redirect::Policy::none()).no_proxy().timeout(Duration::from_secs(60)).build() {
        Ok(c) => c,
        Err(e) => return crate::fail(&e.to_string()),
    };
    // One session per worker and language, as many visitors browsing at once.
    let mut sessions = Vec::new();
    for _ in 0..a.jobs {
        let mut by_lang = BTreeMap::new();
        for (_, c) in &cases {
            if a.cold || by_lang.contains_key(c.language.code()) {
                continue;
            }
            match prime(&client, &a.rust, anonymous, c.language).await {
                Ok(sid) => by_lang.insert(c.language.code(), sid),
                Err(e) => return crate::fail(&e),
            };
        }
        sessions.push(by_lang);
    }
    let (client, cases, sessions) = (Arc::new(client), Arc::new(cases), Arc::new(sessions));
    let send = {
        let (client, cases, sessions, base) = (client.clone(), cases.clone(), sessions.clone(), a.rust.clone());
        move |worker: usize, i: usize| {
            let (client, cases, sessions, base) = (client.clone(), cases.clone(), sessions.clone(), base.clone());
            async move {
                let (ip, c) = &cases[i];
                let mut req = client
                    .request(c.method.clone(), format!("{base}{}", c.path))
                    .header(HOST, SITE)
                    .header(USER_AGENT, UA)
                    .header(ACCEPT_ENCODING, "gzip, deflate, br")
                    .header(ACCEPT_LANGUAGE, c.language.accept_language())
                    .header("x-real-ip", ip);
                if let Some(cookie) = sessions[worker].get(c.language.code()).and_then(|sid| warm::cookie_header(Some(sid), None)) {
                    req = req.header(COOKIE, cookie);
                }
                let start = Instant::now();
                let answer = match req.send().await {
                    Ok(resp) => {
                        let status = resp.status().as_u16();
                        resp.bytes().await.map(|b| (status, b.len())).ok()
                    }
                    Err(_) => None,
                };
                let micros = start.elapsed().as_micros() as u64;
                Sample { group: group(&c.path), micros, bytes: answer.map_or(0, |a| a.1), failed: answer.is_none_or(|a| a.0 >= 500) }
            }
        }
    };

    let mut sql: BTreeMap<&'static str, (usize, usize)> = BTreeMap::new();
    let mut heaviest = Vec::new();
    if let Some(path) = &a.sql_log {
        let mut log = match std::fs::File::open(path) {
            Ok(f) => f,
            Err(e) => return crate::fail(&format!("{}: {e}", path.display())),
        };
        let mut at = log.seek(SeekFrom::End(0)).unwrap_or(0);
        for i in 0..cases.len() {
            send(0, i).await;
            let Ok((n, end)) = statements_since(&mut log, at) else { return crate::fail("reading the server log") };
            at = end;
            let e = sql.entry(group(&cases[i].1.path)).or_default();
            *e = (e.0 + n, e.1 + 1);
            heaviest.push((n, i));
        }
        heaviest.sort_by(|x, y| y.cmp(x));
    }

    let started_load = load_average();
    let mut samples = Vec::new();
    let mut wall = Duration::ZERO;
    for round in 0..=a.rounds {
        let next = Arc::new(AtomicUsize::new(0));
        let mut set = tokio::task::JoinSet::new();
        let start = Instant::now();
        for worker in 0..a.jobs {
            let (next, send, total) = (next.clone(), send.clone(), cases.len());
            set.spawn(async move {
                let mut mine = Vec::new();
                loop {
                    let i = next.fetch_add(1, Ordering::Relaxed);
                    if i >= total {
                        return mine;
                    }
                    mine.push(send(worker, i).await);
                }
            });
        }
        while let Some(done) = set.join_next().await {
            if round > 0 {
                samples.extend(done.unwrap_or_default());
            }
        }
        if round > 0 {
            wall += start.elapsed();
        }
    }

    let mut groups: BTreeMap<&'static str, Vec<&Sample>> = BTreeMap::new();
    for s in &samples {
        groups.entry(s.group).or_default().push(s);
        groups.entry("ALL").or_default().push(s);
    }
    println!("{} cases x {} rounds, {} workers, {} sessions; load average {} at start, {} at end", cases.len(), a.rounds, a.jobs, if a.cold { "cold" } else { "warm" }, started_load, load_average());
    println!("{:<8} {:>7} {:>9} {:>9} {:>9} {:>9} {:>10} {:>7} {:>7}", "group", "reqs", "p50 ms", "p95 ms", "p99 ms", "max ms", "mean B", "sql/req", "errors");
    for (name, list) in &groups {
        let mut micros: Vec<u64> = list.iter().map(|s| s.micros).collect();
        micros.sort_unstable();
        let bytes = list.iter().map(|s| s.bytes).sum::<usize>() / list.len();
        let failed = list.iter().filter(|s| s.failed).count();
        let per = if *name == "ALL" { sql.values().fold((0, 0), |t, v| (t.0 + v.0, t.1 + v.1)) } else { sql.get(name).copied().unwrap_or((0, 0)) };
        let per = if per.1 == 0 { "-".to_string() } else { format!("{:.1}", per.0 as f64 / per.1 as f64) };
        println!(
            "{name:<8} {:>7} {:>9.2} {:>9.2} {:>9.2} {:>9.2} {bytes:>10} {per:>7} {failed:>7}",
            list.len(),
            percentile(&micros, 0.50),
            percentile(&micros, 0.95),
            percentile(&micros, 0.99),
            percentile(&micros, 1.0)
        );
    }
    println!("throughput {:.0} requests/s", samples.len() as f64 / wall.as_secs_f64().max(1e-9));
    if !heaviest.is_empty() {
        println!("most statements per request:");
        for (n, i) in heaviest.iter().take(12) {
            println!("{n:>5}  {}", cases[*i].1.path);
        }
    }
    ExitCode::SUCCESS
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn paths_fall_into_the_traffic_groups() {
        assert_eq!(group("/catalog.php?action=subcategory&subcategory_id=1"), "catalog");
        assert_eq!(group("/catalogue/?lang=fr"), "catalog");
        assert_eq!(group("/scs_ajax.php?table=search&term=hs"), "search");
        assert_eq!(group("/scs_file.php?content_id=x"), "files");
        assert_eq!(group("/scs_scripts/scscpq_wp.js?v=3"), "scripts");
        assert_eq!(group("/wp-content/uploads/2024/02/logo.png"), "static");
        assert_eq!(group("/sitemap_index.xml"), "content");
        assert_eq!(group("/company/about-harcourt/"), "content");
    }

    #[test]
    fn percentiles_are_nearest_rank() {
        let v: Vec<u64> = (1..=100).map(|x| x * 1000).collect();
        assert_eq!(percentile(&v, 0.50), 50.0);
        assert_eq!(percentile(&v, 0.99), 99.0);
        assert_eq!(percentile(&v, 1.0), 100.0);
        assert_eq!(percentile(&[7000], 0.95), 7.0);
    }
}
