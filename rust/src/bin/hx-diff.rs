//! hx-diff: send the same requests to the oracle (the PHP site on its private box) and to a Rust server, and show
//! where the answers differ after the allowed normalizations (`hx-diff rules`).
//!
//!     hx-diff run --oracle https://172.31.4.145 --rust http://127.0.0.1:8601 [--personas all|a,b] [--rust-session]
//!                 [--warm] [--filter REGEX] [--jobs N] [--context N] [--max-lines N] [--timeout SECS] [--out DIR]
//!                 CORPUS.jsonl...
//!     hx-diff personas [--sql]       the synthetic personas, or the MySQL that loads them into the oracle
//!     hx-diff seed --db PATH         sessions for the personas in a migrated copy of the imported SQLite file
//!     hx-diff rules                  the normalization rules and compared headers
//!     hx-diff bench --rust URL [--jobs N] [--rounds N] [--cold] [--filter REGEX] [--sql-log FILE] CORPUS.jsonl...
//!                                    latency percentiles and SQL statements per request, by route group (bench.rs)
//!
//! Exit 0 when every case passes, 1 when any fails, 2 on usage, input or connection errors.

#[path = "hx-diff/bench.rs"]
mod bench;
#[path = "hx-diff/corpus.rs"]
mod corpus;
#[path = "hx-diff/normalize.rs"]
mod normalize;
#[path = "hx-diff/persona.rs"]
mod persona;
#[path = "hx-diff/warm.rs"]
mod warm;

use std::{
    collections::BTreeSet,
    path::{Path, PathBuf},
    process::ExitCode,
    sync::Arc,
    time::Duration,
};

use regex::Regex;
use reqwest::header::{HeaderMap, ACCEPT_ENCODING, ACCEPT_LANGUAGE, CONTENT_TYPE, COOKIE, HOST, SET_COOKIE, USER_AGENT};
use sha2::{Digest, Sha256};
use similar::TextDiff;

use corpus::{Case, Session};
use normalize::Ctx;
use persona::{Lang, Persona, Personas};

const SITE: &str = "www.harcourt.co";
const UA: &str = "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36 hx-diff";

#[derive(Debug)]
struct RunArgs {
    oracle: String,
    rust: String,
    personas: Option<Vec<String>>,
    rust_session: bool,
    warm: bool,
    filter: Option<Regex>,
    jobs: usize,
    context: usize,
    max_lines: usize,
    timeout: Duration,
    out: Option<PathBuf>,
    corpora: Vec<PathBuf>,
}

#[derive(Debug)]
enum Command {
    Run(RunArgs),
    Personas { sql: bool },
    Seed { db: PathBuf },
    Rules,
    Bench(bench::Args),
}

const USAGE: &str = "usage: hx-diff run --oracle URL --rust URL [--personas all|a,b] [--rust-session] [--warm] \
[--filter REGEX] [--jobs N] [--context N] [--max-lines N] [--timeout SECS] [--out DIR] CORPUS.jsonl...\n       hx-diff personas [--sql]\n       \
hx-diff seed --db PATH\n       hx-diff rules\n       \
hx-diff bench --rust URL [--jobs N] [--rounds N] [--cold] [--filter REGEX] [--sql-log FILE] CORPUS.jsonl...";

fn base_url(s: &str) -> Result<String, String> {
    let u = reqwest::Url::parse(s).map_err(|e| format!("{s}: {e}"))?;
    if !matches!(u.scheme(), "http" | "https") || u.host_str().is_none() || u.path() != "/" || u.query().is_some() {
        return Err(format!("{s}: give a bare origin such as https://172.31.4.145 or http://127.0.0.1:8601"));
    }
    Ok(s.trim_end_matches('/').to_string())
}

fn parse_args(args: &[String]) -> Result<Command, String> {
    let mut it = args.iter();
    let sub = it.next().ok_or(USAGE)?;
    let value = |name: &str, it: &mut std::slice::Iter<String>| it.next().cloned().ok_or(format!("{name} needs a value"));
    match sub.as_str() {
        "rules" => Ok(Command::Rules),
        "personas" => match it.next().map(String::as_str) {
            None => Ok(Command::Personas { sql: false }),
            Some("--sql") => Ok(Command::Personas { sql: true }),
            Some(other) => Err(format!("personas: unknown option {other}")),
        },
        "seed" => match (it.next().map(String::as_str), it.next(), it.next()) {
            (Some("--db"), Some(db), None) => Ok(Command::Seed { db: PathBuf::from(db) }),
            _ => Err("usage: hx-diff seed --db PATH".into()),
        },
        "run" => {
            let (mut oracle, mut rust) = (None, None);
            let mut a = RunArgs {
                oracle: String::new(),
                rust: String::new(),
                personas: None,
                rust_session: false,
                warm: false,
                filter: None,
                jobs: 4,
                context: 3,
                max_lines: 60,
                timeout: Duration::from_secs(120),
                out: None,
                corpora: Vec::new(),
            };
            let num = |name: &str, s: String| s.parse::<usize>().map_err(|_| format!("{name} wants a number, got {s:?}"));
            while let Some(arg) = it.next() {
                match arg.as_str() {
                    "--oracle" => oracle = Some(base_url(&value("--oracle", &mut it)?)?),
                    "--rust" => rust = Some(base_url(&value("--rust", &mut it)?)?),
                    "--personas" => a.personas = Some(value("--personas", &mut it)?.split(',').map(|s| s.trim().to_string()).collect()),
                    "--rust-session" => a.rust_session = true,
                    "--warm" => a.warm = true,
                    "--filter" => {
                        let f = value("--filter", &mut it)?;
                        a.filter = Some(Regex::new(&f).map_err(|e| format!("--filter: {e}"))?);
                    }
                    "--jobs" => a.jobs = num("--jobs", value("--jobs", &mut it)?)?.max(1),
                    "--context" => a.context = num("--context", value("--context", &mut it)?)?,
                    "--max-lines" => a.max_lines = num("--max-lines", value("--max-lines", &mut it)?)?,
                    "--timeout" => a.timeout = Duration::from_secs(num("--timeout", value("--timeout", &mut it)?)? as u64),
                    "--out" => a.out = Some(PathBuf::from(value("--out", &mut it)?)),
                    s if s.starts_with("--") => return Err(format!("unknown option {s}\n{USAGE}")),
                    s => a.corpora.push(PathBuf::from(s)),
                }
            }
            a.oracle = oracle.ok_or("run needs --oracle")?;
            a.rust = rust.ok_or("run needs --rust")?;
            if a.corpora.is_empty() {
                return Err("run needs at least one corpus file".into());
            }
            Ok(Command::Run(a))
        }
        "bench" => {
            let mut a = bench::Args { rust: String::new(), jobs: 8, rounds: 3, cold: false, filter: None, sql_log: None, corpora: Vec::new() };
            let num = |name: &str, s: String| s.parse::<usize>().map_err(|_| format!("{name} wants a number, got {s:?}"));
            while let Some(arg) = it.next() {
                match arg.as_str() {
                    "--rust" => a.rust = base_url(&value("--rust", &mut it)?)?,
                    "--jobs" => a.jobs = num("--jobs", value("--jobs", &mut it)?)?.max(1),
                    "--rounds" => a.rounds = num("--rounds", value("--rounds", &mut it)?)?.max(1),
                    "--cold" => a.cold = true,
                    "--filter" => a.filter = Some(Regex::new(&value("--filter", &mut it)?).map_err(|e| format!("--filter: {e}"))?),
                    "--sql-log" => a.sql_log = Some(PathBuf::from(value("--sql-log", &mut it)?)),
                    s if s.starts_with("--") => return Err(format!("unknown option {s}\n{USAGE}")),
                    s => a.corpora.push(PathBuf::from(s)),
                }
            }
            if a.rust.is_empty() || a.corpora.is_empty() {
                return Err("bench needs --rust and at least one corpus file".into());
            }
            Ok(Command::Bench(a))
        }
        _ => Err(USAGE.into()),
    }
}

#[tokio::main]
async fn main() -> ExitCode {
    let args: Vec<String> = std::env::args().skip(1).collect();
    let personas = match Personas::embedded() {
        Ok(p) => p,
        Err(e) => return fail(&e),
    };
    let command = match parse_args(&args) {
        Ok(c) => c,
        Err(e) => return fail(&e),
    };
    match command {
        Command::Rules => {
            for r in &normalize::RULES {
                println!("{:<14} {}", r.name, r.removes);
            }
            println!("{:<14} status, {}, and Set-Cookie names except {}. All other headers are ignored.", "compared", normalize::HEADERS.join(", "), normalize::SESSION_COOKIES.join(" and "));
            println!("{:<14} text bodies (text/*, json, javascript, xml) after the rules above; other bodies by length and SHA-256.", "bodies");
            ExitCode::SUCCESS
        }
        Command::Personas { sql: true } => {
            print!("{}", personas.sql());
            ExitCode::SUCCESS
        }
        Command::Personas { sql: false } => {
            println!("{:<26} {:<16} {:<14} {:<8} lang", "persona", "X-Real-IP", "role", "status");
            for p in personas.all() {
                match &p.account {
                    Some(a) => println!("{:<26} {:<16} {:<14} {:<8} {}", p.name, p.ip, a.role.as_str(), persona::status_str(a.status), a.language.code()),
                    None => println!("{:<26} {:<16} {:<14} {:<8} by Accept-Language", p.name, p.ip, "(none)", "-"),
                }
            }
            ExitCode::SUCCESS
        }
        Command::Seed { db } => match seed(&db, &personas).await {
            Ok(n) => {
                println!("seeded {n} persona sessions in {}", db.display());
                ExitCode::SUCCESS
            }
            Err(e) => fail(&e),
        },
        Command::Run(a) => run(a, personas).await,
        Command::Bench(a) => bench::run(a, personas).await,
    }
}

fn fail(e: &str) -> ExitCode {
    eprintln!("hx-diff: {e}");
    ExitCode::from(2)
}

async fn seed(db: &Path, personas: &Personas) -> Result<usize, String> {
    if !db.exists() {
        return Err(format!("{} does not exist; copy the imported database first", db.display()));
    }
    let options = sqlx::sqlite::SqliteConnectOptions::new().filename(db).busy_timeout(Duration::from_secs(5));
    let pool = sqlx::sqlite::SqlitePoolOptions::new()
        .max_connections(1)
        .connect_with(options)
        .await
        .map_err(|e| format!("{}: {e}", db.display()))?;
    let accounts: Vec<(&Persona, &persona::Account)> = personas.all().iter().filter_map(|p| p.account.as_ref().map(|a| (p, a))).collect();
    let (lo, hi) = (*persona::ID_RANGE.start(), *persona::ID_RANGE.end());
    let present = sqlx::query_scalar!(r#"SELECT COUNT(*) AS "n!: i64" FROM "user" WHERE id BETWEEN ?1 AND ?2"#, lo, hi)
        .fetch_one(&pool)
        .await
        .map_err(|e| e.to_string())?;
    if present != accounts.len() as i64 {
        return Err(format!(
            "{} holds {present} of the {} persona users; seed only a copy imported from the oracle",
            db.display(),
            accounts.len()
        ));
    }
    let now = hxweb::db::unix_now();
    let expires = now + 30 * 86_400;
    let mut tx = pool.begin().await.map_err(|e| e.to_string())?;
    for (p, a) in &accounts {
        let token = hxweb::token::Token::parse(&p.session_token()).ok_or("persona token does not parse")?;
        let hash = token.hash();
        let hash = hash.as_slice();
        let email = format!("persona@{}", a.domain);
        sqlx::query!("DELETE FROM session WHERE user_id = ?1", a.id).execute(&mut *tx).await.map_err(|e| e.to_string())?;
        sqlx::query!(
            "INSERT INTO session (token_hash, user_id, email, created_at, expires_at) VALUES (?1, ?2, ?3, ?4, ?5)",
            hash,
            a.id,
            email,
            now,
            expires
        )
        .execute(&mut *tx)
        .await
        .map_err(|e| e.to_string())?;
    }
    tx.commit().await.map_err(|e| e.to_string())?;
    pool.close().await;
    Ok(accounts.len())
}

/// One side's answer, reduced to what is compared.
struct Answer {
    raw: Vec<u8>,
    /// The comparable rendering: status, the headers that matter, then the normalized body.
    doc: String,
    status: u16,
}

enum Outcome {
    Same,
    Differs { summary: String, diff: String },
    Broken(String),
}

struct Job {
    case: Case,
    persona: Persona,
}

fn is_text(content_type: &str) -> bool {
    let ct = content_type.to_ascii_lowercase();
    ct.starts_with("text/") || ["json", "javascript", "xml", "x-www-form-urlencoded"].iter().any(|t| ct.contains(t))
}

fn render(status: u16, headers: &HeaderMap, body: &[u8], ctx: &Ctx) -> String {
    let mut doc = vec![format!("status: {status}")];
    for name in normalize::HEADERS {
        let joined = headers.get_all(name).iter().map(|v| String::from_utf8_lossy(v.as_bytes()).into_owned()).collect::<Vec<_>>().join(", ");
        if joined.is_empty() {
            continue;
        }
        let v = match name {
            "content-type" => joined.to_ascii_lowercase().replace(' ', ""),
            "location" => normalize::apply_all(&normalize::host_only(&joined, ctx), ctx).replace('\n', ""),
            _ => joined,
        };
        doc.push(format!("{name}: {v}"));
    }
    let cookies: BTreeSet<String> = headers
        .get_all(SET_COOKIE)
        .iter()
        .filter_map(|v| v.to_str().ok()?.split('=').next().map(|n| n.trim().to_string()))
        .filter(|n| !normalize::SESSION_COOKIES.contains(&n.as_str()))
        .collect();
    if !cookies.is_empty() {
        doc.push(format!("set-cookie names: {}", cookies.into_iter().collect::<Vec<_>>().join(", ")));
    }
    doc.push("----- body -----".into());
    let ct = headers.get(CONTENT_TYPE).and_then(|v| v.to_str().ok()).unwrap_or("");
    let text = if ct.is_empty() { std::str::from_utf8(body).ok() } else if is_text(ct) { Some(std::str::from_utf8(body).unwrap_or("")) } else { None };
    match text {
        Some(_) if body.is_empty() => doc.push("(empty)".into()),
        Some(t) if std::str::from_utf8(body).is_ok() => doc.push(normalize::apply_all(t, ctx)),
        _ => doc.push(format!("binary {} bytes, sha256 {}", body.len(), hex::encode(Sha256::digest(body)))),
    }
    doc.join("\n")
}

/// The headers every request carries: the site's Host, a browser, no compression, and who the persona is.
fn request(client: &reqwest::Client, method: reqwest::Method, url: String, persona: &Persona, lang: Lang) -> reqwest::RequestBuilder {
    client
        .request(method, url)
        .header(HOST, SITE)
        .header(USER_AGENT, UA)
        .header(ACCEPT_ENCODING, "identity")
        .header(ACCEPT_LANGUAGE, lang.accept_language())
        .header("x-real-ip", persona.ip.to_string())
}

async fn fetch(client: &reqwest::Client, base: &str, job: &Job, cookie: Option<&str>, ctx: &Ctx) -> Result<Answer, String> {
    let c = &job.case;
    let mut req = request(client, c.method.clone(), format!("{base}{}", c.path), &job.persona, c.language);
    if let Some(cookie) = cookie {
        req = req.header(COOKIE, cookie);
    }
    for (h, v) in &c.headers {
        req = req.header(h.name(), v);
    }
    if let Some(body) = &c.body {
        let ct = c.content_type.as_deref().unwrap_or("application/x-www-form-urlencoded");
        req = req.header(CONTENT_TYPE, ct).body(body.clone());
    }
    let resp = req.send().await.map_err(|e| format!("{base}: {e}"))?;
    let status = resp.status().as_u16();
    let headers = resp.headers().clone();
    let raw = resp.bytes().await.map_err(|e| format!("{base}: reading body: {e}"))?.to_vec();
    let doc = render(status, &headers, &raw, ctx);
    Ok(Answer { raw, doc, status })
}

/// One cookie-less GET of `warm::PRIME_PATH` on the oracle as this persona, returning the PHP session it started.
async fn prime(client: &reqwest::Client, oracle: &str, persona: &Persona, lang: Lang) -> Result<String, String> {
    let url = format!("{oracle}{}", warm::PRIME_PATH);
    let resp = request(client, reqwest::Method::GET, url.clone(), persona, lang)
        .send()
        .await
        .map_err(|e| format!("priming {url}: {e}"))?;
    let sid = warm::phpsessid(resp.headers().get_all(SET_COOKIE).iter().filter_map(|v| v.to_str().ok()))
        .ok_or_else(|| format!("priming {url} as {} did not start a PHP session", persona.name))?;
    resp.bytes().await.map_err(|e| format!("priming {url}: {e}"))?;
    Ok(sid)
}

/// The same priming GET on the Rust side, inside the oracle's session id, so the Rust visit for that id exists and
/// remembers what the first request set (the WordPress frame). Its answer and Set-Cookie are not used.
async fn prime_rust(client: &reqwest::Client, rust: &str, persona: &Persona, lang: Lang, sid: &str) -> Result<(), String> {
    let url = format!("{rust}{}", warm::PRIME_PATH);
    let cookie = warm::cookie_header(Some(sid), None).unwrap_or_default();
    let resp = request(client, reqwest::Method::GET, url.clone(), persona, lang)
        .header(COOKIE, cookie)
        .send()
        .await
        .map_err(|e| format!("priming {url}: {e}"))?;
    resp.bytes().await.map_err(|e| format!("priming {url}: {e}"))?;
    Ok(())
}

/// What every worker of one run shares.
struct Runner {
    client: reqwest::Client,
    oracle: String,
    rust: String,
    hosts: Vec<String>,
    rust_session: bool,
    warm_by_default: bool,
    sessions: warm::Sessions,
}

impl Runner {
    fn is_warm(&self, job: &Job) -> bool {
        job.case.session.unwrap_or(if self.warm_by_default { Session::Warm } else { Session::Cold }) == Session::Warm
    }

    /// The warm session for this job's persona and language, primed on the oracle and then, under the oracle's id,
    /// on the Rust side, once per run.
    async fn warm_sid(&self, job: &Job) -> Result<String, String> {
        let (persona, lang) = (&job.persona, job.case.language);
        self.sessions
            .sid(&persona.name, lang, || async {
                let sid = prime(&self.client, &self.oracle, persona, lang).await?;
                prime_rust(&self.client, &self.rust, persona, lang, &sid).await?;
                Ok(sid)
            })
            .await
    }

    /// Both answers for one job. A warm job first gets its persona's primed session and sends it to both sides.
    async fn answers(&self, job: &Job) -> Result<(Answer, Answer), String> {
        let ctx = Ctx { hosts: self.hosts.clone(), now: hxweb::db::unix_now() };
        let sid = match self.is_warm(job) {
            true => Some(self.warm_sid(job).await?),
            false => None,
        };
        let token = (self.rust_session && job.persona.account.is_some()).then(|| job.persona.session_token());
        let oracle_cookie = warm::cookie_header(sid.as_deref(), None);
        let rust_cookie = warm::cookie_header(sid.as_deref(), token.as_deref());
        let (o, r) = tokio::join!(
            fetch(&self.client, &self.oracle, job, oracle_cookie.as_deref(), &ctx),
            fetch(&self.client, &self.rust, job, rust_cookie.as_deref(), &ctx)
        );
        Ok((o?, r?))
    }
}

fn compare(oracle: &Answer, rust: &Answer, context: usize, max_lines: usize) -> Outcome {
    if oracle.doc == rust.doc {
        return Outcome::Same;
    }
    let (o_head, o_body) = oracle.doc.split_once("\n----- body -----\n").unwrap_or((&oracle.doc, ""));
    let (r_head, r_body) = rust.doc.split_once("\n----- body -----\n").unwrap_or((&rust.doc, ""));
    let mut parts = Vec::new();
    if oracle.status != rust.status {
        parts.push(format!("status {} vs {}", oracle.status, rust.status));
    } else if o_head != r_head {
        parts.push("headers".to_string());
    }
    if o_body != r_body {
        parts.push("body".to_string());
    }
    let udiff = TextDiff::from_lines(&oracle.doc, &rust.doc).unified_diff().context_radius(context).header("oracle", "rust").to_string();
    let lines: Vec<&str> = udiff.lines().collect();
    let mut diff = lines.iter().take(max_lines).copied().collect::<Vec<_>>().join("\n");
    if lines.len() > max_lines {
        diff.push_str(&format!("\n... {} more diff lines (raise --max-lines or use --out)", lines.len() - max_lines));
    }
    Outcome::Differs { summary: parts.join("; "), diff }
}

fn file_stem(job: &Job) -> String {
    let safe: String = job.case.name.chars().map(|c| if c.is_ascii_alphanumeric() || c == '-' || c == '_' { c } else { '_' }).collect();
    format!("{safe}.{}", job.persona.name)
}

fn save(dir: &Path, job: &Job, side: &str, a: &Answer) -> std::io::Result<()> {
    std::fs::create_dir_all(dir)?;
    let stem = file_stem(job);
    std::fs::write(dir.join(format!("{stem}.{side}.raw")), &a.raw)?;
    std::fs::write(dir.join(format!("{stem}.{side}.norm")), &a.doc)
}

async fn run(a: RunArgs, personas: Personas) -> ExitCode {
    let mut cases = Vec::new();
    for path in &a.corpora {
        match corpus::load(path, &personas) {
            Ok(c) => cases.extend(c),
            Err(e) => return fail(&e),
        }
    }
    let wanted: Option<Vec<&Persona>> = match &a.personas {
        None => None,
        Some(list) if list.len() == 1 && list[0] == "all" => Some(personas.all().iter().collect()),
        Some(list) => {
            let mut v = Vec::new();
            for name in list {
                match personas.get(name) {
                    Some(p) => v.push(p),
                    None => return fail(&format!("unknown persona {name:?} (see `hx-diff personas`)")),
                }
            }
            Some(v)
        }
    };
    let mut jobs = Vec::new();
    for case in cases.into_iter().filter(|c| a.filter.as_ref().is_none_or(|f| f.is_match(&c.name))) {
        let who: Vec<&Persona> = match &wanted {
            Some(v) => v.clone(),
            None => personas.get(&case.persona).into_iter().collect(),
        };
        for p in who {
            jobs.push(Job { case: case.clone(), persona: p.clone() });
        }
    }
    if jobs.is_empty() {
        return fail("no cases selected");
    }
    let client = match reqwest::Client::builder()
        .danger_accept_invalid_certs(true)
        .redirect(reqwest::redirect::Policy::none())
        .no_proxy()
        .connect_timeout(Duration::from_secs(10))
        .timeout(a.timeout)
        .build()
    {
        Ok(c) => c,
        Err(e) => return fail(&e.to_string()),
    };
    let host_of = |u: &str| reqwest::Url::parse(u).ok().map(|u| match u.port() {
        Some(p) => format!("{}:{p}", u.host_str().unwrap_or("")),
        None => u.host_str().unwrap_or("").to_string(),
    });
    let hosts: Vec<String> = [host_of(&a.oracle), host_of(&a.rust)].into_iter().flatten().collect();
    let runner = Arc::new(Runner {
        client,
        oracle: a.oracle.clone(),
        rust: a.rust.clone(),
        hosts,
        rust_session: a.rust_session,
        warm_by_default: a.warm,
        sessions: warm::Sessions::default(),
    });
    let gate = Arc::new(tokio::sync::Semaphore::new(a.jobs));
    let mut set = tokio::task::JoinSet::new();
    for (i, job) in jobs.into_iter().enumerate() {
        let (runner, gate) = (runner.clone(), gate.clone());
        let (context, max_lines, out) = (a.context, a.max_lines, a.out.clone());
        set.spawn(async move {
            let _permit = gate.acquire_owned().await;
            let warm = runner.is_warm(&job);
            let outcome = match runner.answers(&job).await {
                Ok((o, r)) => {
                    if let Some(dir) = &out {
                        if let Err(e) = save(dir, &job, "oracle", &o).and_then(|_| save(dir, &job, "rust", &r)) {
                            return (i, job, Outcome::Broken(format!("--out: {e}")), warm);
                        }
                    }
                    compare(&o, &r, context, max_lines)
                }
                Err(e) => Outcome::Broken(e),
            };
            (i, job, outcome, warm)
        });
    }
    let mut results = Vec::new();
    while let Some(done) = set.join_next().await {
        match done {
            Ok(r) => results.push(r),
            Err(e) => return fail(&format!("worker: {e}")),
        }
    }
    results.sort_by_key(|(i, _, _, _)| *i);
    let (mut passed, mut failed, mut broken) = (0, 0, 0);
    for (_, job, outcome, warm) in &results {
        let who = format!("{} {}{}", job.case.name, job.persona.name, if *warm { " (warm)" } else { "" });
        match outcome {
            Outcome::Same => {
                passed += 1;
                println!("PASS {who}");
            }
            Outcome::Differs { summary, diff } => {
                failed += 1;
                println!("FAIL {who}: {summary}\n{diff}");
            }
            Outcome::Broken(e) => {
                broken += 1;
                println!("ERROR {who}: {e}");
            }
        }
    }
    println!("{passed} passed, {failed} failed{}", if broken > 0 { format!(", {broken} errors") } else { String::new() });
    match (broken, failed) {
        (0, 0) => ExitCode::SUCCESS,
        (0, _) => ExitCode::from(1),
        _ => ExitCode::from(2),
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn s(v: &[&str]) -> Vec<String> {
        v.iter().map(|x| x.to_string()).collect()
    }

    fn ctx() -> Ctx {
        Ctx { hosts: vec!["172.31.4.145".into()], now: 1_793_318_400 }
    }

    #[test]
    fn run_args_parse_and_validate() {
        let c = parse_args(&s(&["run", "--oracle", "https://172.31.4.145", "--rust", "http://127.0.0.1:8601/", "--personas", "all", "seed.jsonl"])).unwrap();
        let Command::Run(a) = c else { panic!("not run") };
        assert_eq!(a.rust, "http://127.0.0.1:8601");
        assert_eq!(a.personas, Some(vec!["all".to_string()]));
        assert!(parse_args(&s(&["run", "--oracle", "https://172.31.4.145", "seed.jsonl"])).is_err());
        assert!(parse_args(&s(&["run", "--oracle", "https://172.31.4.145/catalog", "--rust", "http://x", "c"])).is_err());
        assert!(parse_args(&s(&["run", "--oracle", "https://a", "--rust", "http://b"])).is_err());
        assert!(parse_args(&s(&["run", "--oracle", "https://a", "--rust", "http://b", "--bogus", "c"])).is_err());
        assert!(matches!(parse_args(&s(&["personas", "--sql"])), Ok(Command::Personas { sql: true })));
        assert!(parse_args(&s(&["seed"])).is_err());
    }

    fn headers(pairs: &[(&'static str, &str)]) -> HeaderMap {
        let mut h = HeaderMap::new();
        for (k, v) in pairs {
            h.append(*k, v.parse().unwrap());
        }
        h
    }

    fn answer(status: u16, h: &HeaderMap, body: &str) -> Answer {
        Answer { raw: body.as_bytes().to_vec(), doc: render(status, h, body.as_bytes(), &ctx()), status }
    }

    #[test]
    fn equal_after_normalization_passes() {
        let a = headers(&[("content-type", "text/html; charset=UTF-8"), ("set-cookie", "PHPSESSID=abc; path=/"), ("date", "x")]);
        let b = headers(&[("content-type", "text/html;charset=utf-8"), ("set-cookie", "__Host-hx_session=zz; Path=/")]);
        let o = answer(200, &a, "<p>hi</p>\n<a href=\"https://www.harcourt.co/x\">");
        let r = answer(200, &b, "<p>hi</p><a href=\"https://172.31.4.145/x\">");
        assert!(matches!(compare(&o, &r, 3, 60), Outcome::Same));
    }

    #[test]
    fn differences_are_named_and_diffed() {
        let h = headers(&[("content-type", "text/html")]);
        let o = answer(200, &h, "<p>one</p>");
        let r = answer(404, &h, "<p>two</p>");
        let Outcome::Differs { summary, diff } = compare(&o, &r, 3, 60) else { panic!("should differ") };
        assert_eq!(summary, "status 200 vs 404; body");
        assert!(diff.contains("--- oracle") && diff.contains("+++ rust"), "{diff}");
        assert!(diff.contains("-<p>one") && diff.contains("+<p>two"), "{diff}");
        let cookie = headers(&[("content-type", "text/html"), ("set-cookie", "harcourt=1")]);
        let Outcome::Differs { summary, .. } = compare(&o, &answer(200, &cookie, "<p>one</p>"), 3, 60) else { panic!() };
        assert_eq!(summary, "headers");
    }

    #[test]
    fn long_diffs_are_capped() {
        let h = headers(&[("content-type", "text/plain")]);
        let o = answer(200, &h, &(0..200).map(|i| format!("<a>{i}")).collect::<String>());
        let r = answer(200, &h, &(0..200).map(|i| format!("<b>{i}")).collect::<String>());
        let Outcome::Differs { diff, .. } = compare(&o, &r, 3, 20) else { panic!() };
        assert!(diff.lines().count() <= 21);
        assert!(diff.ends_with("(raise --max-lines or use --out)"));
    }

    #[test]
    fn binary_bodies_compare_by_hash() {
        let h = headers(&[("content-type", "image/png")]);
        let doc = render(200, &h, b"\x89PNG", &ctx());
        assert!(doc.contains("binary 4 bytes, sha256 "), "{doc}");
        assert_ne!(doc, render(200, &h, b"\x89PNH", &ctx()));
    }

    /// A local listener that records every request head (lowercased) and, when `sessions` is set, answers the
    /// priming page with a new PHPSESSID per request.
    struct Fake {
        base: String,
        heads: Arc<std::sync::Mutex<Vec<String>>>,
    }

    impl Fake {
        fn start(sessions: bool) -> Fake {
            use std::io::{Read, Write};
            let listener = std::net::TcpListener::bind("127.0.0.1:0").unwrap();
            let base = format!("http://127.0.0.1:{}", listener.local_addr().unwrap().port());
            let heads = Arc::new(std::sync::Mutex::new(Vec::new()));
            let seen = heads.clone();
            std::thread::spawn(move || {
                for (n, sock) in listener.incoming().enumerate() {
                    let Ok(mut sock) = sock else { return };
                    let mut head = Vec::new();
                    let mut buf = [0u8; 1024];
                    while !head.windows(4).any(|w| w == b"\r\n\r\n") {
                        let Ok(k) = sock.read(&mut buf) else { break };
                        if k == 0 {
                            break;
                        }
                        head.extend_from_slice(&buf[..k]);
                    }
                    let head = String::from_utf8_lossy(&head).to_ascii_lowercase();
                    let priming = head.starts_with(&format!("get {} ", warm::PRIME_PATH));
                    let cookie = if sessions && priming { format!("set-cookie: PHPSESSID=sid{n}; path=/\r\n") } else { String::new() };
                    seen.lock().unwrap().push(head);
                    let reply = format!("HTTP/1.1 200 OK\r\nconnection: close\r\ncontent-type: text/plain\r\n{cookie}content-length: 2\r\n\r\nok");
                    let _ = sock.write_all(reply.as_bytes());
                }
            });
            Fake { base, heads }
        }

        fn heads(&self) -> Vec<String> {
            self.heads.lock().unwrap().clone()
        }
    }

    fn cookie_of(head: &str) -> Option<&str> {
        head.split("\r\n").find_map(|l| l.strip_prefix("cookie: "))
    }

    fn job(case: &str) -> Job {
        let personas = Personas::embedded().unwrap();
        let case = corpus::parse(case, "t", &personas).unwrap().remove(0);
        Job { persona: personas.get(&case.persona).unwrap().clone(), case }
    }

    fn runner(oracle: &Fake, rust: &Fake, warm_by_default: bool, rust_session: bool) -> Runner {
        Runner {
            client: reqwest::Client::builder().redirect(reqwest::redirect::Policy::none()).no_proxy().build().unwrap(),
            oracle: oracle.base.clone(),
            rust: rust.base.clone(),
            hosts: vec![],
            rust_session,
            warm_by_default,
            sessions: warm::Sessions::default(),
        }
    }

    /// One request against a throwaway local listener, returning the request head it received.
    fn received(case: &str) -> String {
        let fake = Fake::start(false);
        let client = reqwest::Client::builder().redirect(reqwest::redirect::Policy::none()).no_proxy().build().unwrap();
        let rt = tokio::runtime::Builder::new_current_thread().enable_all().build().unwrap();
        let answer = rt.block_on(fetch(&client, &fake.base, &job(case), None, &ctx())).unwrap();
        assert_eq!(answer.status, 200);
        fake.heads().remove(0)
    }

    #[tokio::test]
    async fn warm_cases_share_one_primed_session_per_persona_and_language() {
        let (oracle, rust) = (Fake::start(true), Fake::start(true));
        let r = runner(&oracle, &rust, false, false);
        let warm = |path: &str, lang: &str| job(&format!(r#"{{"name":"w","path":"{path}","persona":"customer-active","language":"{lang}","session":"warm"}}"#));
        for j in [warm("/cart.php", "en"), warm("/checkout.php", "en"), job(r#"{"name":"c","path":"/cold.php","persona":"customer-active"}"#), warm("/cart.php", "fr")] {
            r.answers(&j).await.unwrap();
        }
        let o = oracle.heads();
        let paths: Vec<&str> = o.iter().map(|h| h.split(' ').nth(1).unwrap()).collect();
        assert_eq!(paths, [warm::PRIME_PATH, "/cart.php", "/checkout.php", "/cold.php", warm::PRIME_PATH, "/cart.php"]);
        assert!(cookie_of(&o[0]).is_none() && o[0].contains("\r\nx-real-ip: 198.51.100.11\r\n"), "{}", o[0]);
        assert!(o[4].contains("\r\naccept-language: fr-ca,fr;q=0.9\r\n"), "{}", o[4]);
        let en = cookie_of(&o[1]).unwrap();
        assert!(en.starts_with("phpsessid=sid"), "{en}");
        assert_eq!(cookie_of(&o[2]), Some(en));
        assert_eq!(cookie_of(&o[3]), None);
        let fr = cookie_of(&o[5]).unwrap();
        assert_ne!(fr, en);
        let rs = rust.heads();
        let rust_paths: Vec<&str> = rs.iter().map(|h| h.split(' ').nth(1).unwrap()).collect();
        assert_eq!(rust_paths, [warm::PRIME_PATH, "/cart.php", "/checkout.php", "/cold.php", warm::PRIME_PATH, "/cart.php"]);
        assert!(rs[0].contains("\r\nx-real-ip: 198.51.100.11\r\n") && rs[4].contains("\r\naccept-language: fr-ca,fr;q=0.9\r\n"));
        assert_eq!(
            rs.iter().map(|h| cookie_of(h)).collect::<Vec<_>>(),
            [Some(en), Some(en), Some(en), None, Some(fr), Some(fr)],
            "the Rust priming runs inside the oracle's id, and its own Set-Cookie is not used"
        );
    }

    #[tokio::test]
    async fn the_rust_side_is_primed_once_per_pair_before_any_case() {
        let (oracle, rust) = (Fake::start(true), Fake::start(true));
        let r = Arc::new(runner(&oracle, &rust, true, false));
        let mut set = tokio::task::JoinSet::new();
        for path in ["/a.php", "/b.php", "/c.php", "/d.php"] {
            let (r, j) = (r.clone(), job(&format!(r#"{{"name":"x","path":"{path}","persona":"anonymous"}}"#)));
            set.spawn(async move { r.answers(&j).await.map(|_| ()) });
        }
        while let Some(done) = set.join_next().await {
            done.unwrap().unwrap();
        }
        let rs = rust.heads();
        assert_eq!(rs.iter().filter(|h| h.starts_with(&format!("get {} ", warm::PRIME_PATH))).count(), 1);
        assert!(rs[0].starts_with(&format!("get {} ", warm::PRIME_PATH)), "priming comes first: {}", rs[0]);
        assert_eq!(oracle.heads().iter().filter(|h| h.starts_with(&format!("get {} ", warm::PRIME_PATH))).count(), 1);
    }

    #[tokio::test]
    async fn the_warm_flag_sets_the_default_and_rust_session_rides_along() {
        let (oracle, rust) = (Fake::start(true), Fake::start(true));
        let r = runner(&oracle, &rust, true, true);
        r.answers(&job(r#"{"name":"d","path":"/a.php","persona":"customer-active"}"#)).await.unwrap();
        r.answers(&job(r#"{"name":"d","path":"/b.php","persona":"customer-active","session":"cold"}"#)).await.unwrap();
        let (o, rs) = (oracle.heads(), rust.heads());
        let sid = cookie_of(&o[1]).unwrap();
        let token = Personas::embedded().unwrap().get("customer-active").unwrap().session_token().to_ascii_lowercase();
        assert_eq!(cookie_of(&rs[0]), Some(sid), "the Rust priming carries only the PHP session");
        assert_eq!(cookie_of(&rs[1]).unwrap(), format!("{sid}; __host-hx_session={token}"));
        assert_eq!(cookie_of(&o[2]), None, "an explicit cold case stays cold under --warm");
        assert_eq!(cookie_of(&rs[2]).unwrap(), format!("__host-hx_session={token}"));
    }

    #[tokio::test]
    async fn a_priming_that_starts_no_session_is_an_error() {
        let (oracle, rust) = (Fake::start(false), Fake::start(false));
        let r = runner(&oracle, &rust, true, false);
        let e = r.answers(&job(r#"{"name":"d","path":"/a.php"}"#)).await.err().unwrap();
        assert!(e.contains("did not start a PHP session"), "{e}");
        assert_eq!(oracle.heads().len(), 1);
        assert!(rust.heads().is_empty());
    }

    #[test]
    fn case_headers_are_sent_with_the_persona() {
        let head = received(
            r#"{"name":"n","path":"/newuser.php","persona":"customer-active","headers":{"Referer":"https://www.harcourt.co/request-access/","X-Requested-With":"XMLHttpRequest"}}"#,
        );
        assert!(head.starts_with("get /newuser.php http/1.1\r\n"), "{head}");
        for line in [
            "referer: https://www.harcourt.co/request-access/",
            "x-requested-with: xmlhttprequest",
            "x-real-ip: 198.51.100.11",
            "host: www.harcourt.co",
        ] {
            assert!(head.contains(&format!("\r\n{line}\r\n")), "missing {line:?} in {head}");
        }
        let plain = received(r#"{"name":"n","path":"/newuser.php"}"#);
        assert!(!plain.contains("\r\nreferer:") && !plain.contains("\r\nx-requested-with:"), "{plain}");
    }

    #[test]
    fn locations_lose_their_host() {
        let h = headers(&[("location", "https://www.harcourt.co/newuser.php?x=1")]);
        assert!(render(302, &h, b"", &ctx()).contains("location: https://HOST/newuser.php?x=1"));
    }
}
