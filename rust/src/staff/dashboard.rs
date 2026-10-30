//! dashboard.php: the staff landing page. A menu of the staff tools for the visitor's role, then report tabs over
//! the log, user and orderhd tables. `?tab=<title>` opens on a tab.

use std::{collections::HashMap, sync::Arc};

use axum::{
    extract::{RawQuery, State},
    response::Response,
    routing::get,
    Router,
};
use base64::{engine::general_purpose::STANDARD_NO_PAD, Engine};
use sqlx::SqlitePool;

use super::{
    csrf::Fields,
    html::{close, esc, hidden, href, number, open, url_query, MenuGroup, Notes, Opts, Tabs},
    shell, time, Staff,
};
use crate::{
    api::{Auth, Executive},
    db::unix_now,
    identity::Role,
};

const PATH: &str = "/dashboard.php";
const WEEK: i64 = 7 * 86400;

use Role::{Administrator as A, Executive as E, Internal as I, SuperUser as S};

/// The menu: groups of (minimum role, label, path). The last group is titled with the visitor's role.
type Link = (Role, &'static str, &'static str);

const MENU: &[(&str, &[Link])] = &[
    (
        "User & BOT Manager",
        &[
            (E, "Maintain Users", "/user.php"),
            (A, "User Utility", "/user_utility.php"),
            (E, "Email Export", "/user_email.php"),
            (A, "User Impersonate", "/user_impersonate.php"),
            (A, "Currency Codes", "/currency.php"),
            (A, "Maintain Industry", "/industry.php"),
            (A, "Maintain BOTS", "/bot.php"),
            (A, "BOT Impersonate", "/bot_impersonate.php"),
            (A, "Apache Log BOTS", "/bot_apache.php"),
        ],
    ),
    (
        "Remote Access",
        &[
            (A, "Company", "/remote_access.php"),
            (A, "Access Code", "/remote_code.php"),
            (A, "Tokens", "/remote_token.php"),
            (A, "Domain Blacklist", "/blacklist.php"),
            (A, "reCAPTCHA Bypass", "/captcha_bypass.php"),
            (A, "Remote Grants", "/staff/remote-grants"),
        ],
    ),
    (
        "Document Manager",
        &[(A, "Languages", "/language.php"), (E, "Documents", "/document.php"), (E, "Pages", "/pages.php"), (A, "Campaigns", "/campaign_manager.php")],
    ),
    (
        "Portal / Hero Pages",
        &[
            (E, "Maintain Customer Portal", "/portal.php"),
            (E, "Portal Content", "/portalcontent.php"),
            (E, "Portal Category", "/portalcategory.php"),
            (E, "Portal Items", "/portalitem.php"),
            (A, "Hero Headline", "/hero.php"),
            (A, "Hero Preview", "/hero_preview.php"),
        ],
    ),
    (
        "Inventory",
        &[
            (I, "Pricing & CAD", "/configure.php"),
            (I, "Price Book", "/inventory_pricebook.php"),
            (E, "Category Codes", "/category.php"),
            (E, "Subcategory Codes", "/subcategory.php"),
            (E, "Subcategory Utility", "/subcategory_utility.php"),
            (E, "Subcategory Order", "/subcategory_order.php"),
            (E, "Inventory Manager", "/inventory.php"),
            (A, "Price Increase", "/inventory_increase.php"),
            (A, "Inventory Import/Export", "/inventory_utility.php"),
            (A, "Inventory w/o Prices", "/inventory_no_price.php"),
            (A, "ERP Pricing Import", "/inventory_price.php"),
            (A, "Smart SKU Pricing Utility", "/pricing_utility.php"),
        ],
    ),
    (
        "Catalog",
        &[
            (A, "Search Utility", "/search_utility.php"),
            (A, "Image Filmstrip", "/image_filmstrip.php"),
            (A, "Design Partner Agreement", "/design_partner_agreement.php"),
            (A, "Metric Filter", "/catalog_metric_filter.php"),
        ],
    ),
    (
        "PARTsolutions Integration",
        &[
            (A, "Inventory Import PRJ", "/inventory_prj.php"),
            (A, "Inventory Missing PRJ", "/inventory_missing_prj.php"),
            (A, "Subcategory PRJ Review", "/subcategory_prj.php"),
            (A, "CAD Toolbox", "/cad_toolbox.php"),
            (A, "Configurator Toolbox", "/configurator_toolbox.php"),
        ],
    ),
    ("Orders", &[(E, "PO", "/orderhd.php"), (E, "Carrier Codes", "/carrier.php")]),
    (
        "",
        &[
            (A, "Registry", "/scs_registry.php"),
            (E, "Event Log", "/scs_log.php"),
            (A, "Tracking Log", "/scs_track_log.php"),
            (E, "Reset Cookie", "/cookie_reset.php"),
            (A, "Email Documents", "/mailer_maintain.php"),
            (A, "Email Output Tester", "/mailer_output.php"),
            (S, "File Manager", "/scs_filemanager.php"),
        ],
    ),
];

fn menu(role: Role) -> String {
    let mut m = MenuGroup::new("standard");
    for (title, items) in MENU {
        m.group(if title.is_empty() { role.as_str() } else { title });
        for (min, label, path) in *items {
            if role >= *min {
                m.item(label, path, Opts::new());
            }
        }
    }
    m.render()
}

fn date(unix: i64) -> String {
    time::date("m/d/Y h:i A", unix)
}

fn table(class: &str, headers: &[&str], rows: Vec<String>) -> String {
    let mut out = vec![format!("<table class='{class}'>"), "<thead>".into(), "<tr>".into()];
    out.extend(headers.iter().map(|h| format!("<th>{h}</th>")));
    out.extend(["</tr>".to_string(), "</thead>".into(), "<tbody>".into()]);
    out.extend(rows);
    out.extend(["</tbody>".to_string(), "</table>".into()]);
    out.join("\n")
}

/// `fn_url("/x.php", ["action" => "maintain", "record_id" => id, "return_url" => "/dashboard.php?tab=…"])`.
fn maintain_url(page: &str, id: i64, tab: &str) -> String {
    let back = format!("{PATH}{}", url_query(&[("tab", tab)]));
    format!("{page}{}", url_query(&[("action", "maintain"), ("record_id", &id.to_string()), ("return_url", &back)]))
}

struct Settings {
    leadscore_days: i64,
    scores: HashMap<String, i64>,
    thumbnail: String,
    score_ignore: f64,
    score_spam: f64,
}

async fn settings(pool: &SqlitePool) -> Settings {
    let mut s = Settings { leadscore_days: 0, scores: HashMap::new(), thumbnail: String::new(), score_ignore: 0.0, score_spam: 0.0 };
    for (field, item, data) in shell::registry(pool, "leadscore").await {
        match field.as_str() {
            "days" => s.leadscore_days = data.trim().parse().unwrap_or(0),
            "score" => {
                s.scores.insert(item.to_lowercase(), data.trim().parse().unwrap_or(0));
            }
            _ => {}
        }
    }
    for (field, _, data) in shell::registry(pool, "local").await {
        if field == "thumbnail" {
            s.thumbnail = data.trim().to_string();
        }
    }
    for (field, _, data) in shell::registry(pool, "recaptcha").await {
        match field.as_str() {
            "score_ignore" => s.score_ignore = data.trim().parse().unwrap_or(0.0),
            "score_spam" => s.score_spam = data.trim().parse().unwrap_or(0.0),
            _ => {}
        }
    }
    s
}

/// user_class::recaptcha_score().
fn recaptcha(score: f64, s: &Settings) -> String {
    let verdict = if score < s.score_ignore {
        "(Reject/Ignore)"
    } else if score < s.score_spam {
        "(Possible Spam)"
    } else {
        "(OK)"
    };
    format!("{score} {verdict}")
}

async fn pending(pool: &SqlitePool, s: &Settings, tabs: &mut Tabs) -> sqlx::Result<()> {
    let users = sqlx::query!(
        r#"SELECT id AS "id!: i64", ip, company_name, recaptcha_score, last_login FROM "user"
           WHERE status = 'Pending' ORDER BY recaptcha_score DESC, company_name"#
    )
    .fetch_all(pool)
    .await?;
    if users.is_empty() {
        return Ok(());
    }
    let signups = sqlx::query!(
        r#"SELECT l.user_id AS "user_id!: i64", l.comment FROM log l JOIN "user" u ON u.id = l.user_id
           WHERE u.status = 'Pending' AND lower(l.type) = 'user' AND lower(l.subtype) = 'signup' ORDER BY l.id DESC"#
    )
    .fetch_all(pool)
    .await?;
    let mut names: HashMap<i64, String> = HashMap::new();
    for row in signups {
        names.insert(row.user_id, row.comment.unwrap_or_default());
    }
    let tab = format!("Pending Access ({})", number(users.len() as i64));
    let rows = users
        .iter()
        .map(|u| {
            [
                "<tr>".to_string(),
                format!("<td>{}</td>", href(&esc(u.ip.as_deref().unwrap_or("")), &maintain_url("/user.php", u.id, &tab), Opts::new())),
                format!("<td>{}</td>", esc(u.company_name.as_deref().unwrap_or(""))),
                format!("<td>{}</td>", esc(names.get(&u.id).map_or("", String::as_str))),
                format!("<td>{}</td>", recaptcha(u.recaptcha_score, s)),
                format!("<td class=center>{}</td>", date(u.last_login)),
                "</tr>".into(),
            ]
            .join("\n")
        })
        .collect();
    tabs.land_on(&tab);
    tabs.item(&tab, table("standard border tablesorter", &["IP", "Company", "Name", "Score", "Date/Time"], rows));
    Ok(())
}

async fn pending_po(pool: &SqlitePool, tabs: &mut Tabs) -> sqlx::Result<()> {
    let orders = sqlx::query!(
        r#"SELECT id AS "id!: i64", company_name, name, email, order_date FROM orderhd WHERE status = 'Pending' ORDER BY id"#
    )
    .fetch_all(pool)
    .await?;
    if orders.is_empty() {
        return Ok(());
    }
    let tab = format!("Pending PO ({})", orders.len());
    let rows = orders
        .iter()
        .map(|o| {
            [
                "<tr>".to_string(),
                format!("<td class=center>{}</td>", href(&o.id.to_string(), &maintain_url("/orderhd.php", o.id, &tab), Opts::new())),
                format!("<td>{}</td>", esc(o.company_name.as_deref().unwrap_or(""))),
                format!("<td>{}</td>", esc(o.name.as_deref().unwrap_or(""))),
                format!("<td>{}</td>", esc(o.email.as_deref().unwrap_or(""))),
                format!("<td class=center>{}</td>", date(o.order_date.unwrap_or(0))),
                "</tr>".into(),
            ]
            .join("\n")
        })
        .collect();
    tabs.item(&tab, table("small border tablesorter", &["PO#", "Company", "Name", "Email", "Date/Time"], rows));
    tabs.land_on(&tab);
    Ok(())
}

/// dashboard_leadscore_class: what one address did, scored by the registry's `leadscore.score` weights.
#[derive(Default)]
struct Lead {
    company_name: String,
    score: i64,
    new: bool,
    subcategory: Vec<i64>,
    portal: Vec<i64>,
    campaign: Vec<String>,
    referer: Vec<String>,
}

impl Lead {
    fn process(&mut self, kind: &str, subtype: &str, subcategory_id: i64, comment: &str, scores: &HashMap<String, i64>) {
        if subcategory_id != 0 {
            let list = if kind == "Page" && subtype == "Portal" { &mut self.portal } else { &mut self.subcategory };
            if !list.contains(&subcategory_id) {
                list.push(subcategory_id);
            }
        }
        let field = format!("{kind}:{subtype}").to_lowercase();
        if field == "user:email" {
            self.new = true;
        }
        self.score += scores.get(&field).copied().unwrap_or(0);
        if comment.is_empty() {
            return;
        }
        if subtype.eq_ignore_ascii_case("campaign") && !self.campaign.iter().any(|c| c == comment) {
            self.campaign.push(comment.to_string());
        } else if subtype.eq_ignore_ascii_case("referer") && !self.referer.iter().any(|c| c == comment) {
            self.referer.push(comment.to_string());
        }
    }
}

/// `content_class::encode()`: the scs_file.php key for a content row, tied to the viewer's last sign-in.
fn content_key(content_id: i64, last_login: i64) -> String {
    STANDARD_NO_PAD.encode(format!("{content_id}\t{last_login:x}"))
}

async fn leadscore(pool: &SqlitePool, s: &Settings, viewer_last_login: i64, tabs: &mut Tabs) -> sqlx::Result<()> {
    let since = unix_now() - s.leadscore_days * 86400;
    let rows = sqlx::query!(
        r#"SELECT u.company_name AS "company_name?", l.email AS "email!", l.type AS "kind!", l.subtype, l.subcategory_id AS "subcategory_id!: i64", l.comment
           FROM log l LEFT JOIN "user" u ON u.id = l.user_id
           WHERE l.type IN ('User', 'Page', 'Catalog', 'Search', 'PARTSolutions', 'Initial', 'IPStack', 'Blacklist')
             AND l.date_time >= ?1 AND l.email <> '' AND l.user_id <> 0 AND u.leadscore = 1 AND lower(u.status) = 'active'
           ORDER BY l.email, l.id DESC"#,
        since
    )
    .fetch_all(pool)
    .await?;
    let mut order: Vec<String> = Vec::new();
    let mut leads: HashMap<String, Lead> = HashMap::new();
    for r in rows {
        let lead = leads.entry(r.email.clone()).or_insert_with(|| {
            order.push(r.email.clone());
            Lead { company_name: r.company_name.clone().unwrap_or_default(), ..Lead::default() }
        });
        lead.process(&r.kind, r.subtype.as_deref().unwrap_or(""), r.subcategory_id, r.comment.as_deref().unwrap_or(""), &s.scores);
    }
    let mut ranked: Vec<&String> = order.iter().filter(|e| leads[*e].score > 0).collect();
    if ranked.is_empty() {
        return Ok(());
    }
    ranked.sort_by_key(|e| std::cmp::Reverse(leads[*e].score));
    let mut out = Vec::new();
    for email in ranked {
        let lead = &leads[email];
        let mut viewed = Vec::new();
        if !lead.subcategory.is_empty() {
            let ids = serde_json::to_string(&lead.subcategory).unwrap_or_default();
            let subs = sqlx::query!(
                r#"SELECT s.name, s.thumbnail_url FROM subcategory s LEFT JOIN category c ON c.id = s.category_id
                   WHERE s.id IN (SELECT value FROM json_each(?1))
                   ORDER BY c.sort_order, c.name, s.sort_order, s.name"#,
                ids
            )
            .fetch_all(pool)
            .await?;
            for sub in subs {
                let t = esc(&s.thumbnail);
                let img = match sub.thumbnail_url.as_deref().filter(|u| !u.is_empty()) {
                    Some(src) => format!("<img src='{}' width='{t}' height='{t}' border='0'>", esc(src)),
                    None => String::new(),
                };
                viewed.push(format!("<li>{img}<br>{}</li>", esc(sub.name.as_deref().unwrap_or(""))));
            }
        }
        if !lead.portal.is_empty() {
            let ids = serde_json::to_string(&lead.portal).unwrap_or_default();
            let cats = sqlx::query!(
                r#"SELECT p.name AS portal_name, c.id AS "content_id?: i64" FROM portalcategory p
                   LEFT JOIN content c ON c.record_type = 'portalcontent' AND c.language_code = 'EN' AND c.record_id = p.image_portalcontent_id
                   WHERE p.id IN (SELECT value FROM json_each(?1)) ORDER BY c.name"#,
                ids
            )
            .fetch_all(pool)
            .await?;
            for cat in cats {
                let t = s.thumbnail.as_str();
                let src = format!(
                    "/scs_file.php{}",
                    url_query(&[("width", t), ("height", t), ("resize", "1"), ("content_id", &content_key(cat.content_id.unwrap_or(0), viewer_last_login))])
                );
                viewed.push(format!("<li><img src='{}' border='0'><br>{}</li>", esc(&src), esc(cat.portal_name.as_deref().unwrap_or(""))));
            }
        }
        let viewed = if viewed.is_empty() { "&nbsp;".to_string() } else { format!("<ul>{}</ul>", viewed.join("\n")) };
        let join = |v: &[String]| v.iter().map(|x| esc(x)).collect::<Vec<_>>().join("<br>");
        out.push(
            [
                "<tr>".to_string(),
                format!("<td>{}</td>", esc(email)),
                format!("<td class nobr>{}</td>", esc(&lead.company_name)),
                format!("<td class=center>{}</td>", number(lead.score)),
                format!("<td class=catalog_subcategory_list>{viewed}</td>"),
                format!("<td>{}</td>", if lead.new { "<font color='red'>NEW!</font>" } else { "&nbsp;" }),
                format!("<td>{}</td>", join(&lead.campaign)),
                format!("<td>{}</td>", join(&lead.referer)),
                "</tr>".into(),
            ]
            .join("\n"),
        );
    }
    let headers = ["Email", "Company Name", "Lead Score", "Viewed", "New?", "Campaign?", "Referer?"];
    let html = table("small border tablesorter", &headers, out).replacen("<th>Lead Score</th>", "<th class=nobr>Lead Score</th>", 1);
    tabs.item(&format!("Lead Score ({} Days)", s.leadscore_days), html);
    Ok(())
}

async fn access(pool: &SqlitePool, tabs: &mut Tabs) -> sqlx::Result<()> {
    let since = unix_now() - WEEK;
    let users = sqlx::query!(
        r#"SELECT id AS "id!: i64", ip, company_name, city, state, zip, country_code, last_login FROM "user"
           WHERE last_login >= ?1 ORDER BY last_login DESC"#,
        since
    )
    .fetch_all(pool)
    .await?;
    if users.is_empty() {
        return Ok(());
    }
    let rows = users
        .iter()
        .map(|u| {
            let link = format!("/scs_log.php{}", url_query(&[("action", "login"), ("user_id", &u.id.to_string())]));
            let when = if u.last_login != 0 { date(u.last_login) } else { "&nbsp;".into() };
            let cell = |v: &Option<String>| format!("<td>{}</td>", esc(v.as_deref().unwrap_or("")));
            [
                "<tr>".to_string(),
                format!("<td>{}</td>", href(&esc(u.ip.as_deref().unwrap_or("")), &link, Opts::new())),
                cell(&u.company_name),
                cell(&u.city),
                cell(&u.state),
                cell(&u.zip),
                cell(&u.country_code),
                "<td class=center>".into(),
                when,
                "</td>".into(),
                "</tr>".into(),
            ]
            .join("\n")
        })
        .collect();
    let tab = format!("7 Day Access ({})", number(users.len() as i64));
    tabs.item(&tab, table("small border tablesorter", &["IP", "Company", "City", "State", "Zip", "Country", "Date/Time"], rows));
    Ok(())
}

async fn campaign(pool: &SqlitePool, tabs: &mut Tabs) -> sqlx::Result<()> {
    let since = unix_now() - WEEK;
    let rows = sqlx::query!(
        r#"SELECT comment, count(*) AS "count!: i64", max(date_time) AS "date!: i64" FROM log
           WHERE date_time >= ?1 AND lower(type) = 'initial' AND lower(subtype) = 'campaign'
           GROUP BY comment ORDER BY comment"#,
        since
    )
    .fetch_all(pool)
    .await?;
    if rows.is_empty() {
        return Ok(());
    }
    let mut out = vec![
        "<table class='large border tablesorter'>".to_string(),
        "<thead>".into(),
        "<tr>".into(),
        "<th>Campaign</th>".into(),
        "<th>Last Date/Time</th>".into(),
        "<th>Count</th>".into(),
        "</tr>".into(),
        "</thead>".into(),
        "<tbody>".into(),
    ];
    for r in &rows {
        out.push("<tr>".into());
        out.push(format!("<td>{}</td>", esc(r.comment.as_deref().unwrap_or(""))));
        out.push(format!("<td class=center>{}</td>", date(r.date)));
        out.push(format!("<td class=right>{}</td>", number(r.count)));
        out.push("</td>".into());
        out.push("</tr>".into());
    }
    out.push("</tbody>".into());
    if rows.len() > 1 {
        let total: i64 = rows.iter().map(|r| r.count).sum();
        out.extend(["<tfooter>".to_string(), "<tr>".into(), "<td colspan=2><b>7 day totals</b></td>".into()]);
        out.push(format!("<td class=right><b>{}</b></td>", number(total)));
        out.extend(["</tr>".to_string(), "</tfooter>".into()]);
    }
    out.push("</table>".into());
    tabs.item("Campaign", out.join("\n"));
    Ok(())
}

async fn sku_error(pool: &SqlitePool, tabs: &mut Tabs) -> sqlx::Result<()> {
    let since = unix_now() - WEEK;
    let rows = sqlx::query!(
        r#"SELECT l.sku AS "sku!", c.name AS "category_name?", s.name AS "subcategory_name?", s.prj AS "prj?",
                  min(l.date_time) AS "first!: i64", max(l.date_time) AS "last!: i64", count(*) AS "count!: i64"
           FROM log l
           LEFT JOIN inventory i ON i.sku = l.sku
           LEFT JOIN category c ON c.id = i.category_id
           LEFT JOIN subcategory s ON s.id = i.subcategory_id
           WHERE l.type = 'PARTSolutions' AND l.subtype = 'Reverse Lookup Error' AND l.date_time >= ?1 AND s.prj <> ''
           GROUP BY i.sku ORDER BY min(l.date_time)"#,
        since
    )
    .fetch_all(pool)
    .await?;
    if rows.is_empty() {
        return Ok(());
    }
    let body = rows
        .iter()
        .map(|r| {
            let link = format!("/cad_lookup.php{}", url_query(&[("action", "ipc"), ("source", "prj"), ("prj", r.prj.as_deref().unwrap_or("")), ("sku", &r.sku)]));
            [
                "<tr>".to_string(),
                format!("<td>{}</td>", href(&esc(&r.sku), &esc(&link), Opts::new())),
                format!("<td>{}</td>", esc(r.category_name.as_deref().unwrap_or(""))),
                format!("<td>{}</td>", esc(r.subcategory_name.as_deref().unwrap_or(""))),
                format!("<td class=center>{}</td>", date(r.first)),
                format!("<td class=center>{}</td>", date(r.last)),
                format!("<td class=right>{}</td>", number(r.count)),
                "</tr>".into(),
            ]
            .join("\n")
        })
        .collect();
    let mut html = table("standard border tablesorter", &["SKU", "Category", "Subcategory", "First Date", "Last Date", "Count"], body);
    html.push_str(&format!("\n<p class=center>{} errors detected.</p>", number(rows.len() as i64)));
    tabs.item("Reverse Part# Missing (7 Days)", html);
    Ok(())
}

async fn related_error(pool: &SqlitePool, tabs: &mut Tabs) -> sqlx::Result<()> {
    let since = unix_now() - WEEK;
    let rows = sqlx::query!(
        r#"SELECT sku AS "sku!", comment, date_time AS "date_time!: i64" FROM log WHERE id IN (
             SELECT max(id) FROM log WHERE lower(type) = 'error' AND lower(subtype) = 'related sku' AND date_time >= ?1 GROUP BY sku)
           ORDER BY sku"#,
        since
    )
    .fetch_all(pool)
    .await?;
    if rows.is_empty() {
        return Ok(());
    }
    let mut out = vec!["<table class='border'>".to_string(), "<tr>".into(), "<th>Part#</th>".into(), "<th>Comment</th>".into(), "<th>Last Date</th>".into(), "</tr>".into()];
    for r in rows {
        out.push("<tr>".into());
        out.push(format!("<td>{}</td>", esc(&r.sku)));
        out.push(format!("<td>{}</td>", esc(r.comment.as_deref().unwrap_or("")).replace('\n', "<br>")));
        out.push(format!("<td class=center>{}</td>", time::date("m/d/Y h:i", r.date_time)));
        out.push("</tr>".into());
    }
    out.push("</table>".into());
    tabs.item("Related SKU", out.join("\n"));
    Ok(())
}

async fn render(pool: &SqlitePool, staff: &Staff<Executive>, query: &Fields) -> sqlx::Result<Response> {
    let role = staff.who.user.role;
    let title = format!("{} Dashboard", role.as_str());
    let notes = Notes::new(&title, "");
    let s = settings(pool).await;
    let viewer_last_login = sqlx::query_scalar!(r#"SELECT last_login FROM "user" WHERE id = ?1"#, staff.who.user.id.0)
        .fetch_optional(pool)
        .await?
        .unwrap_or(0);
    let mut tabs = Tabs::new();
    if query.has("tab") {
        tabs.land_on(query.get("tab"));
    }
    tabs.item(role.as_str(), menu(role));
    if role >= Role::Administrator {
        pending(pool, &s, &mut tabs).await?;
    }
    pending_po(pool, &mut tabs).await?;
    if role >= Role::Administrator {
        leadscore(pool, &s, viewer_last_login, &mut tabs).await?;
    }
    access(pool, &mut tabs).await?;
    campaign(pool, &mut tabs).await?;
    if role >= Role::Administrator {
        sku_error(pool, &mut tabs).await?;
    }
    related_error(pool, &mut tabs).await?;
    let body = [
        notes.render(),
        open("/user.php", staff.csrf.as_str(), false),
        hidden("action", "maintain"),
        hidden("return_url", PATH),
        hidden("record_id", ""),
        close().to_string(),
        tabs.render(),
    ]
    .concat();
    Ok(shell::page(pool, role, &title, &body).await)
}

async fn show(State(auth): State<Arc<Auth>>, staff: Staff<Executive>, RawQuery(q): RawQuery) -> Response {
    render(&auth.pool, &staff, &Fields::from_query(q.as_deref())).await.unwrap_or_else(shell::internal)
}

pub fn routes() -> Router<Arc<Auth>> {
    Router::new().route(PATH, get(show))
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn menu_shows_each_link_from_its_role_up() {
        let exec = menu(Role::Executive);
        assert!(exec.contains("<a href=\"/user.php\" class='nobr'>Maintain Users</a>"));
        assert!(!exec.contains("/user_utility.php"));
        assert!(!exec.contains("Remote Access"));
        assert!(exec.contains("<span style='font-size: 140%;'>Executive</span><br>"));
        let admin = menu(Role::Administrator);
        assert!(admin.contains("/staff/remote-grants") && !admin.contains("/scs_filemanager.php"));
        assert!(menu(Role::SuperUser).contains("/scs_filemanager.php"));
        for gone in ["/scs_mysql.php", "/scs_toolbox.php", "/jquery_toolbox.php", "/php_toolbox.php", "/cookie.php"] {
            assert!(!menu(Role::SuperUser).contains(gone), "{gone}");
        }
    }

    #[test]
    fn leads_add_up_their_weights_and_remember_what_they_saw() {
        let scores = HashMap::from([("search:sku".to_string(), 2), ("user:email".to_string(), 5)]);
        let mut lead = Lead::default();
        lead.process("Search", "SKU", 12, "", &scores);
        lead.process("Search", "SKU", 12, "", &scores);
        lead.process("Page", "Portal", 3, "", &scores);
        lead.process("User", "Email", 0, "", &scores);
        lead.process("Initial", "Campaign", 0, "spring", &scores);
        lead.process("Initial", "campaign", 0, "spring", &scores);
        assert_eq!((lead.score, lead.new), (9, true));
        assert_eq!((lead.subcategory, lead.portal, lead.campaign), (vec![12], vec![3], vec!["spring".to_string()]));
    }

    #[test]
    fn return_urls_come_back_to_the_tab() {
        assert_eq!(maintain_url("/user.php", 5, "Pending Access (2)"), "/user.php?action=maintain&record_id=5&return_url=%2Fdashboard.php%3Ftab%3DPending%2BAccess%2B%25282%2529");
        assert_eq!(content_key(17, 255), STANDARD_NO_PAD.encode("17\tff"));
    }
}
