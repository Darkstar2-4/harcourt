//! The cms importer and its check.
//!
//!     hxweb-cms check  --dump wp.sql --export /home/harcou6/static_site [--all]
//!     hxweb-cms import --dump wp.sql --export /home/harcou6/static_site --db /var/lib/hxweb/hx.db [--capture DIR]
//!     hxweb-cms capture --dump wp.sql --export /home/harcou6/static_site --origin https://HOST --out DIR [--insecure]
//!
//! `wp.sql` is a `mysqldump --complete-insert` of the WordPress content tables (see docs/cms.md). `check` writes
//! nothing: it renders every page whose template Rust has ported and compares it with the export, one line per
//! page. `import` writes the cms tables and, when the dump has `wp_gf_form` and `wp_gf_form_meta`, the Gravity
//! Forms definitions, then runs the same check. `capture` fetches the singles of the other post types from a running
//! WordPress (`--origin`, asked for as www.harcourt.co) into DIR in the export's form; `--capture DIR` adds them.

use std::{path::PathBuf, process::ExitCode};

use hxweb::cms::{
    import::{self, export},
    Site,
};

struct Args {
    command: String,
    dump: PathBuf,
    export: PathBuf,
    db: Option<PathBuf>,
    all: bool,
    capture: Option<PathBuf>,
    origin: Option<String>,
    out: Option<PathBuf>,
    insecure: bool,
}

fn args() -> Result<Args, String> {
    let mut it = std::env::args().skip(1);
    let command = it.next().ok_or("usage: hxweb-cms check|import|capture --dump FILE --export DIR [--db FILE] [--all] [--capture DIR] [--origin URL --out DIR [--insecure]]")?;
    let (mut dump, mut export, mut db, mut all) = (None, None, None, false);
    let (mut capture, mut origin, mut out, mut insecure) = (None, None, None, false);
    while let Some(a) = it.next() {
        match a.as_str() {
            "--dump" => dump = it.next().map(PathBuf::from),
            "--export" => export = it.next().map(PathBuf::from),
            "--db" => db = it.next().map(PathBuf::from),
            "--all" => all = true,
            "--capture" => capture = it.next().map(PathBuf::from),
            "--origin" => origin = it.next(),
            "--out" => out = it.next().map(PathBuf::from),
            "--insecure" => insecure = true,
            other => return Err(format!("unknown argument {other}")),
        }
    }
    Ok(Args { command, dump: dump.ok_or("--dump is required")?, export: export.ok_or("--export is required")?, db, all, capture, origin, out, insecure })
}

#[tokio::main(flavor = "current_thread")]
async fn main() -> ExitCode {
    match run().await {
        Ok(true) => ExitCode::SUCCESS,
        Ok(false) => ExitCode::from(1),
        Err(e) => {
            eprintln!("hxweb-cms: {e}");
            ExitCode::from(2)
        }
    }
}

async fn run() -> Result<bool, String> {
    let a = args()?;
    let text = std::fs::read_to_string(&a.dump).map_err(|e| format!("{}: {e}", a.dump.display()))?;
    let tables = import::dump::parse(&text)?;
    let mut content = import::wp::read(&tables)?;
    let mut exported = export::read(&a.export)?;
    if a.command == "capture" {
        let origin = a.origin.ok_or("capture needs --origin")?;
        let out = a.out.ok_or("capture needs --out")?;
        let problems = import::capture::capture(&content, &exported, &origin, "www.harcourt.co", a.insecure, &out).await?;
        for p in &problems {
            println!("problem: {p}");
        }
        println!("captured into {}", out.display());
        return Ok(problems.is_empty());
    }
    if let Some(dir) = &a.capture {
        exported.add_capture(dir)?;
    }
    let report = export::apply(&mut content, &exported);
    for p in &report.problems {
        println!("problem: {p}");
    }
    println!(
        "pages {}, menu items {}, media {}, extracted {}",
        content.pages.len(),
        content.menus.len(),
        content.media.len(),
        report.extracted.len()
    );
    let site = match a.command.as_str() {
        "check" => content.site(),
        "import" => {
            let db = a.db.ok_or("import needs --db")?;
            let pool = hxweb::db::open(&db).await.map_err(|e| format!("{}: {e}", db.display()))?;
            import::write(&pool, &content, hxweb::db::unix_now()).await.map_err(|e| format!("write: {e}"))?;
            match hxweb::orders::forms::import::write(&pool, &tables).await.map_err(|e| format!("forms: {e}"))? {
                Some(n) => println!("gravity forms {n}"),
                None => println!("gravity forms: none in the dump, left as they were"),
            }
            println!("written to {}; checking what it loads back", db.display());
            Site::load(&pool).await.map_err(|e| format!("load: {e}"))?
        }
        other => return Err(format!("unknown command {other}")),
    };
    let lines = export::check(&site, &exported, !a.all);
    let mut ok = report.problems.is_empty();
    for (pass, line) in &lines {
        ok &= *pass;
        println!("{} {line}", if *pass { "PASS" } else { "FAIL" });
    }
    println!("{} of {} pages match", lines.iter().filter(|(p, _)| *p).count(), lines.len());
    Ok(ok)
}
