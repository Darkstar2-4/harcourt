//! The cms importer and its check.
//!
//!     hxweb-cms check  --dump wp.sql --export /home/harcou6/static_site [--all]
//!     hxweb-cms import --dump wp.sql --export /home/harcou6/static_site --db /var/lib/hxweb/hx.db
//!
//! `wp.sql` is a `mysqldump --complete-insert` of the WordPress content tables (see docs/cms.md). `check` writes
//! nothing: it renders every page whose template Rust has ported and compares it with the export, one line per
//! page. `import` writes the cms tables, then runs the same check.

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
}

fn args() -> Result<Args, String> {
    let mut it = std::env::args().skip(1);
    let command = it.next().ok_or("usage: hxweb-cms check|import --dump FILE --export DIR [--db FILE] [--all]")?;
    let (mut dump, mut export, mut db, mut all) = (None, None, None, false);
    while let Some(a) = it.next() {
        match a.as_str() {
            "--dump" => dump = it.next().map(PathBuf::from),
            "--export" => export = it.next().map(PathBuf::from),
            "--db" => db = it.next().map(PathBuf::from),
            "--all" => all = true,
            other => return Err(format!("unknown argument {other}")),
        }
    }
    Ok(Args { command, dump: dump.ok_or("--dump is required")?, export: export.ok_or("--export is required")?, db, all })
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
    let exported = export::read(&a.export)?;
    let report = export::apply(&mut content, &exported);
    for p in &report.problems {
        println!("problem: {p}");
    }
    println!(
        "pages {}, menu items {}, media {}, forms {}, extracted {}",
        content.pages.len(),
        content.menus.len(),
        content.media.len(),
        content.forms.len(),
        report.extracted.len()
    );
    let site = match a.command.as_str() {
        "check" => content.site(),
        "import" => {
            let db = a.db.ok_or("import needs --db")?;
            let pool = hxweb::db::open(&db).await.map_err(|e| format!("{}: {e}", db.display()))?;
            import::write(&pool, &content, hxweb::db::unix_now()).await.map_err(|e| format!("write: {e}"))?;
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
