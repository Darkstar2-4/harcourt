//! blacklist.php and captcha_bypass.php: two lists of addresses staff keep, each one page with a search box and
//! AJAX add, remove and paging. The blacklist holds mail domains that may not sign up; the bypass list holds
//! addresses (or `@domain`s) whose sign-up skips reCAPTCHA. `List` is what differs between them.
//!
//! A change comes from the page's own script: it sends `X-Requested-With`, which a page on another site cannot
//! send without a preflight that is never granted, and an Origin, when the browser sends one, that is this site.

use std::{future::Future, sync::Arc};

use axum::{
    extract::{Form, State},
    http::{header, HeaderMap, StatusCode},
    response::{IntoResponse, Response},
    routing::MethodRouter,
};
use sqlx::SqlitePool;

use crate::{
    accounts::{
        html::json_encode,
        log::{self, Kind, Subject},
    },
    api::{Administrator, Auth},
    db::unix_now,
    staff::{
        csrf::{php_intval, Fields},
        html::{button, close, esc, open, page_items_of, Form as Widgets, Notes, Opts},
        shell, time, Staff,
    },
    visitor::{Visitor, PHP_CONTENT_TYPE},
};

/// `$database->query_limit` on these pages.
const ROWS: i64 = 10;

pub struct Row {
    pub id: i64,
    pub value: String,
    pub comment: String,
    pub created: i64,
}

pub trait List: Send + Sync + 'static {
    const PATH: &'static str;
    const TITLE: &'static str;
    /// The prefix of the page's element ids and script functions, and of its JSON keys.
    const PREFIX: &'static str;
    /// The posted field naming the entry to add (`domain`, `email`).
    const FIELD: &'static str;
    const INPUT_LABEL: &'static str;
    const INPUT_MAX: u32;
    const PLACEHOLDER: &'static str;
    const ADD_BUTTON: &'static str;
    const SEARCH_PLACEHOLDER: &'static str;
    const HEADER: &'static str;
    const EMPTY: &'static str;
    const SCRIPT: &'static str;
    const ADDED: Kind;
    const REMOVED: Kind;

    /// What is wrong with an entry as typed (lower-cased and trimmed), in the page's words.
    fn invalid(entry: &str) -> Option<&'static str>;
    /// The answers to an add or remove, given the escaped entry.
    fn already(entry: &str) -> String;
    fn added(entry: &str) -> String;
    fn removed(entry: &str) -> String;
    /// The log comments for an add or remove.
    fn add_comment(entry: &str) -> String;
    fn remove_comment(entry: &str) -> String;

    fn page(pool: &SqlitePool, search: &str, page: i64) -> impl Future<Output = sqlx::Result<(Vec<Row>, i64)>> + Send;
    fn exists(pool: &SqlitePool, entry: &str) -> impl Future<Output = sqlx::Result<bool>> + Send;
    fn insert(pool: &SqlitePool, entry: &str, comment: &str, now: i64) -> impl Future<Output = sqlx::Result<()>> + Send;
    /// Delete the entry with this id, giving back what it held.
    fn remove(pool: &SqlitePool, id: i64) -> impl Future<Output = sqlx::Result<Option<String>>> + Send;
}

/// The list with its pager, as blacklist_output() and captcha_bypass_output() drew it.
async fn content<L: List>(pool: &SqlitePool, search: &str, page: i64) -> sqlx::Result<String> {
    let (rows, found) = L::page(pool, search, page).await?;
    let mut out = format!(
        "<table class='standard border tablesorter'><thead><tr><th width='10%'>Action</th><th width='35%'>{}</th><th width='35%'>Reason</th><th width='20%'>Date Added</th></tr></thead><tbody>",
        L::HEADER
    );
    if rows.is_empty() {
        let none = if search.is_empty() { L::EMPTY.to_string() } else { format!("No results for &ldquo;{}&rdquo;", esc(search)) };
        out.push_str(&format!("<tr><td colspan=4>{none}</td></tr>"));
    }
    for r in rows {
        let remove = format!("{}_remove({},{});", L::PREFIX, r.id, esc(&json_encode(&r.value)));
        let created = if r.created != 0 { time::date("m/d/Y h:i A", r.created) } else { String::new() };
        out.push_str(&format!(
            "<tr><td class=center>{}</td><td>{}</td><td>{}</td><td class=center>{created}</td></tr>",
            button("Remove", Opts::new().onclick(remove).style("padding:5px 10px;cursor:pointer;")),
            esc(&r.value),
            esc(&r.comment)
        ));
    }
    out.push_str("</tbody></table>");
    out.push_str(&page_items_of(found, page, ROWS, &format!("{}_load(%page%);", L::PREFIX)));
    Ok(out)
}

async fn show<L: List>(State(auth): State<Arc<Auth>>, staff: Staff<Administrator>) -> Response {
    page::<L>(&auth.pool, &staff).await.unwrap_or_else(shell::internal)
}

async fn page<L: List>(pool: &SqlitePool, staff: &Staff<Administrator>) -> sqlx::Result<Response> {
    let notes = Notes::new(L::TITLE, "");
    let w = Widgets { notes: &notes };
    let input = |field: &str, max: u32, placeholder: &str| w.text(field, "", 40, max, Opts::new().class("scscpq_input").set("placeholder", placeholder));
    let p = L::PREFIX;
    let mut body = notes.render();
    body.push_str("<style>#scs_content { min-height: calc(100vh - 200px); }</style>");
    body.push_str(L::SCRIPT);
    body.push_str(&open(L::PATH, staff.csrf.as_str(), false));
    body.push_str(&format!(
        "<table class='standard border'><tr><td class='scscpq_label'>{}</td><td>{}</td><td class='scscpq_label'>Reason:</td><td>{}</td><td>{}</td></tr></table>",
        L::INPUT_LABEL,
        input(&format!("new_{}", L::FIELD), L::INPUT_MAX, L::PLACEHOLDER),
        input("new_comment", 200, "Optional"),
        button(L::ADD_BUTTON, Opts::new().onclick(format!("{p}_add();")).class("red-button").style("margin-top:0;padding:10px 20px;"))
    ));
    body.push_str(&format!(
        "<div style='padding:10px 0;'><label style='margin-right:8px;font-weight:bold;'>Search:</label><input type=text name='{p}_search' id='{p}_search' size='30' maxlength='200' class='scscpq_input' placeholder='{}' onkeyup='{p}_search_keyup();' style='width:auto;'></div>",
        L::SEARCH_PLACEHOLDER
    ));
    body.push_str(&format!("<div id={p}_message style='padding:10px;'></div><div id={p}_content>{}</div>", content::<L>(pool, "", 1).await?));
    body.push_str(close());
    Ok(shell::page(pool, staff.view(), L::TITLE, &body).await)
}

/// A call from the page's own script: `X-Requested-With` set, and no Origin but ours.
pub fn from_page_script(headers: &HeaderMap, origin: &str) -> bool {
    headers.contains_key("x-requested-with") && headers.get(header::ORIGIN).is_none_or(|o| o.as_bytes() == origin.as_bytes())
}

/// PHP's `die(json_encode($results))`: keys in the order set, under PHP's default content type.
pub fn json(pairs: &[(String, String)]) -> Response {
    let body = format!("{{{}}}", pairs.iter().map(|(k, v)| format!("{}:{}", json_encode(k), json_encode(v))).collect::<Vec<_>>().join(","));
    ([(header::CONTENT_TYPE, PHP_CONTENT_TYPE)], body).into_response()
}

async fn post<L: List>(State(auth): State<Arc<Auth>>, staff: Staff<Administrator>, visitor: Visitor, headers: HeaderMap, Form(posted): Form<Vec<(String, String)>>) -> Response {
    if !headers.contains_key("x-requested-with") {
        return show::<L>(State(auth), staff).await;
    }
    if !from_page_script(&headers, &auth.public_origin) {
        return StatusCode::FORBIDDEN.into_response();
    }
    let fields = Fields::posted(posted);
    let ip = visitor.client.map(|ip| ip.to_string()).unwrap_or_default();
    act::<L>(&auth.pool, &staff, &fields, &ip).await.unwrap_or_else(shell::internal)
}

async fn act<L: List>(pool: &SqlitePool, staff: &Staff<Administrator>, fields: &Fields, ip: &str) -> sqlx::Result<Response> {
    let mut page = php_intval(fields.get("page")).max(1);
    let search = fields.get("search").trim().to_string();
    let message_key = format!("{}_message", L::PREFIX);
    let mut out: Vec<(String, String)> = Vec::new();
    let red = |text: String| format!("<span style='color:red;'>{text}</span>");
    let who = Subject { ip, user_id: staff.who.user.id.0, email: staff.who.email.as_deref(), ..Default::default() };
    match fields.get("action") {
        "add" => {
            let entry = fields.get(L::FIELD).trim().to_lowercase();
            let comment = fields.get("comment").trim().to_string();
            let message = if let Some(e) = L::invalid(&entry) {
                red(e.to_string())
            } else if L::exists(pool, &entry).await? {
                red(L::already(&esc(&entry)))
            } else {
                L::insert(pool, &entry, &comment, unix_now()).await?;
                log::record(pool, L::ADDED, &who, &L::add_comment(&entry)).await;
                page = 1;
                L::added(&esc(&entry))
            };
            out.push((message_key, message));
        }
        "remove" => {
            if let Some(entry) = L::remove(pool, php_intval(fields.get("id"))).await? {
                log::record(pool, L::REMOVED, &who, &L::remove_comment(&entry)).await;
                page = 1;
                out.push((message_key, L::removed(&esc(&entry))));
            }
        }
        _ => {}
    }
    out.push((format!("{}_content", L::PREFIX), content::<L>(pool, &search, page).await?));
    Ok(json(&out))
}

pub fn route<L: List>() -> MethodRouter<Arc<Auth>> {
    axum::routing::get(show::<L>).post(post::<L>)
}

const BLACKLIST_SCRIPT: &str = r##"<script>
var blacklist_search_timer;

function blacklist_load(page) {
    var search = jQuery("#blacklist_search").val().trim();
    jQuery.ajax({
        type: "post",
        dataType: "json",
        data: { action: "list", page: page, search: search },
        success: function(response) {
            jQuery("#blacklist_content").html(response['blacklist_content']);
        },
        error: function(xhr) {
            console.log(xhr);
        }
    });
}

function blacklist_search_keyup() {
    clearTimeout(blacklist_search_timer);
    blacklist_search_timer = setTimeout(function() { blacklist_load(1); }, 300);
}

function blacklist_add() {
    var domain = jQuery("#new_domain").val().trim();
    if (!domain) return;
    if (!confirm("Blacklist domain: " + domain + "?")) return;
    jQuery.ajax({
        type: "post",
        dataType: "json",
        data: { action: "add", domain: domain, comment: jQuery("#new_comment").val(), page: 1, search: jQuery("#blacklist_search").val().trim() },
        success: function(response) {
            jQuery("#blacklist_message").html(response['blacklist_message']);
            jQuery("#blacklist_content").html(response['blacklist_content']);
            jQuery("#new_domain").val("");
            jQuery("#new_comment").val("");
        },
        error: function(xhr) {
            console.log(xhr);
        }
    });
}

function blacklist_remove(id, domain) {
    if (!confirm("Remove " + domain + " from the blacklist?")) return;
    jQuery.ajax({
        type: "post",
        dataType: "json",
        data: { action: "remove", id: id, page: 1, search: jQuery("#blacklist_search").val().trim() },
        success: function(response) {
            jQuery("#blacklist_message").html(response['blacklist_message']);
            jQuery("#blacklist_content").html(response['blacklist_content']);
        },
        error: function(xhr) {
            console.log(xhr);
        }
    });
}
</script>
"##;

const BYPASS_SCRIPT: &str = r##"<script>
var captcha_bypass_search_timer;

function captcha_bypass_load(page) {
    var search = jQuery("#captcha_bypass_search").val().trim();
    jQuery.ajax({
        type: "post",
        dataType: "json",
        data: { action: "list", page: page, search: search },
        success: function(response) {
            jQuery("#captcha_bypass_content").html(response['captcha_bypass_content']);
        },
        error: function(xhr) {
            console.log(xhr);
        }
    });
}

function captcha_bypass_search_keyup() {
    clearTimeout(captcha_bypass_search_timer);
    captcha_bypass_search_timer = setTimeout(function() { captcha_bypass_load(1); }, 300);
}

function captcha_bypass_add() {
    var email = jQuery("#new_email").val().trim();
    if (!email) return;
    if (!confirm("Bypass reCAPTCHA for: " + email + "?")) return;
    jQuery.ajax({
        type: "post",
        dataType: "json",
        data: { action: "add", email: email, comment: jQuery("#new_comment").val(), page: 1, search: jQuery("#captcha_bypass_search").val().trim() },
        success: function(response) {
            jQuery("#captcha_bypass_message").html(response['captcha_bypass_message']);
            jQuery("#captcha_bypass_content").html(response['captcha_bypass_content']);
            jQuery("#new_email").val("");
            jQuery("#new_comment").val("");
        },
        error: function(xhr) {
            console.log(xhr);
        }
    });
}

function captcha_bypass_remove(id, email) {
    if (!confirm("Remove " + email + " from the reCAPTCHA bypass list?")) return;
    jQuery.ajax({
        type: "post",
        dataType: "json",
        data: { action: "remove", id: id, page: 1, search: jQuery("#captcha_bypass_search").val().trim() },
        success: function(response) {
            jQuery("#captcha_bypass_message").html(response['captcha_bypass_message']);
            jQuery("#captcha_bypass_content").html(response['captcha_bypass_content']);
        },
        error: function(xhr) {
            console.log(xhr);
        }
    });
}
</script>
"##;

/// A mail domain: dot-separated labels of letters, digits and inner hyphens, at least two of them.
fn is_domain(s: &str) -> bool {
    let labels: Vec<&str> = s.split('.').collect();
    labels.len() >= 2
        && labels.iter().all(|l| !l.is_empty() && l.len() <= 63 && l.bytes().all(|b| b.is_ascii_alphanumeric() || b == b'-') && !l.starts_with('-') && !l.ends_with('-'))
}

/// FILTER_VALIDATE_EMAIL, near enough: a dot-atom local part and a domain as above.
fn is_email(s: &str) -> bool {
    let Some((local, domain)) = s.rsplit_once('@') else { return false };
    let atext = |b: u8| b.is_ascii_alphanumeric() || b"!#$%&'*+/=?^_`{|}~-".contains(&b);
    !local.is_empty() && local.len() <= 64 && local.split('.').all(|w| !w.is_empty() && w.bytes().all(atext)) && is_domain(domain)
}

pub struct Blacklist;

impl List for Blacklist {
    const PATH: &'static str = "/blacklist.php";
    const TITLE: &'static str = "Domain Blacklist";
    const PREFIX: &'static str = "blacklist";
    const FIELD: &'static str = "domain";
    const INPUT_LABEL: &'static str = "Domain:";
    const INPUT_MAX: u32 = 80;
    const PLACEHOLDER: &'static str = "example.com";
    const ADD_BUTTON: &'static str = "Add to Blacklist";
    const SEARCH_PLACEHOLDER: &'static str = "Filter by domain or reason...";
    const HEADER: &'static str = "Domain";
    const EMPTY: &'static str = "No blacklisted domains";
    const SCRIPT: &'static str = BLACKLIST_SCRIPT;
    const ADDED: Kind = Kind::BlacklistAdd;
    const REMOVED: Kind = Kind::BlacklistRemove;

    fn invalid(entry: &str) -> Option<&'static str> {
        match () {
            _ if entry.is_empty() => Some("Domain is required"),
            _ if !is_domain(entry) => Some("Invalid domain format"),
            _ => None,
        }
    }

    fn already(entry: &str) -> String {
        format!("{entry} is already blacklisted")
    }

    fn added(entry: &str) -> String {
        format!("{entry} has been blacklisted")
    }

    fn removed(entry: &str) -> String {
        format!("{entry} has been removed from the blacklist")
    }

    fn add_comment(entry: &str) -> String {
        format!("Domain blacklisted: {entry}")
    }

    fn remove_comment(entry: &str) -> String {
        format!("Domain removed from blacklist: {entry}")
    }

    async fn page(pool: &SqlitePool, search: &str, page: i64) -> sqlx::Result<(Vec<Row>, i64)> {
        let offset = (page - 1) * ROWS;
        let found = sqlx::query_scalar!(r#"SELECT count(*) AS "n!: i64" FROM blacklist WHERE ?1 = '' OR domain LIKE '%' || ?1 || '%' OR comment LIKE '%' || ?1 || '%'"#, search)
            .fetch_one(pool)
            .await?;
        let rows = sqlx::query!(
            r#"SELECT id AS "id!: i64", domain, comment, created FROM blacklist
               WHERE ?1 = '' OR domain LIKE '%' || ?1 || '%' OR comment LIKE '%' || ?1 || '%'
               ORDER BY domain COLLATE NOCASE LIMIT ?2 OFFSET ?3"#,
            search,
            ROWS,
            offset
        )
        .fetch_all(pool)
        .await?;
        let rows = rows.into_iter().map(|r| Row { id: r.id, value: r.domain, comment: r.comment.unwrap_or_default(), created: r.created.unwrap_or(0) }).collect();
        Ok((rows, found))
    }

    async fn exists(pool: &SqlitePool, entry: &str) -> sqlx::Result<bool> {
        Ok(sqlx::query_scalar!(r#"SELECT 1 AS "x!: i64" FROM blacklist WHERE domain = ?1 COLLATE NOCASE LIMIT 1"#, entry).fetch_optional(pool).await?.is_some())
    }

    async fn insert(pool: &SqlitePool, entry: &str, comment: &str, now: i64) -> sqlx::Result<()> {
        sqlx::query!("INSERT INTO blacklist (domain, comment, created) VALUES (?1, ?2, ?3)", entry, comment, now).execute(pool).await?;
        Ok(())
    }

    async fn remove(pool: &SqlitePool, id: i64) -> sqlx::Result<Option<String>> {
        sqlx::query_scalar!(r#"DELETE FROM blacklist WHERE id = ?1 RETURNING domain AS "domain!""#, id).fetch_optional(pool).await
    }
}

pub struct CaptchaBypass;

impl List for CaptchaBypass {
    const PATH: &'static str = "/captcha_bypass.php";
    const TITLE: &'static str = "reCAPTCHA Bypass";
    const PREFIX: &'static str = "captcha_bypass";
    const FIELD: &'static str = "email";
    const INPUT_LABEL: &'static str = "Email or @domain:";
    const INPUT_MAX: u32 = 190;
    const PLACEHOLDER: &'static str = "user@example.com or @example.com";
    const ADD_BUTTON: &'static str = "Add Bypass";
    const SEARCH_PLACEHOLDER: &'static str = "Filter by email or reason...";
    const HEADER: &'static str = "Email / @domain";
    const EMPTY: &'static str = "No reCAPTCHA bypass entries";
    const SCRIPT: &'static str = BYPASS_SCRIPT;
    const ADDED: Kind = Kind::CaptchaBypassAdd;
    const REMOVED: Kind = Kind::CaptchaBypassRemove;

    /// `@example.com` lets every address at that domain through.
    fn invalid(entry: &str) -> Option<&'static str> {
        match entry.strip_prefix('@') {
            _ if entry.is_empty() => Some("Email or @domain is required"),
            Some(domain) if !is_domain(domain) => Some("Invalid domain format"),
            Some(_) => None,
            None if !is_email(entry) => Some("Invalid email format"),
            None => None,
        }
    }

    fn already(entry: &str) -> String {
        format!("{entry} already bypasses reCAPTCHA")
    }

    fn added(entry: &str) -> String {
        format!("{entry} will now bypass reCAPTCHA")
    }

    fn removed(entry: &str) -> String {
        format!("{entry} has been removed from the bypass list")
    }

    fn add_comment(entry: &str) -> String {
        format!("reCAPTCHA bypass added: {entry}")
    }

    fn remove_comment(entry: &str) -> String {
        format!("reCAPTCHA bypass removed: {entry}")
    }

    async fn page(pool: &SqlitePool, search: &str, page: i64) -> sqlx::Result<(Vec<Row>, i64)> {
        let offset = (page - 1) * ROWS;
        let found = sqlx::query_scalar!(r#"SELECT count(*) AS "n!: i64" FROM captcha_bypass WHERE ?1 = '' OR email LIKE '%' || ?1 || '%' OR comment LIKE '%' || ?1 || '%'"#, search)
            .fetch_one(pool)
            .await?;
        let rows = sqlx::query!(
            r#"SELECT id AS "id!: i64", email, comment, created FROM captcha_bypass
               WHERE ?1 = '' OR email LIKE '%' || ?1 || '%' OR comment LIKE '%' || ?1 || '%'
               ORDER BY email COLLATE NOCASE LIMIT ?2 OFFSET ?3"#,
            search,
            ROWS,
            offset
        )
        .fetch_all(pool)
        .await?;
        let rows = rows.into_iter().map(|r| Row { id: r.id, value: r.email, comment: r.comment.unwrap_or_default(), created: r.created.unwrap_or(0) }).collect();
        Ok((rows, found))
    }

    async fn exists(pool: &SqlitePool, entry: &str) -> sqlx::Result<bool> {
        Ok(sqlx::query_scalar!(r#"SELECT 1 AS "x!: i64" FROM captcha_bypass WHERE email = ?1 COLLATE NOCASE LIMIT 1"#, entry).fetch_optional(pool).await?.is_some())
    }

    async fn insert(pool: &SqlitePool, entry: &str, comment: &str, now: i64) -> sqlx::Result<()> {
        sqlx::query!("INSERT INTO captcha_bypass (email, comment, created) VALUES (?1, ?2, ?3)", entry, comment, now).execute(pool).await?;
        Ok(())
    }

    async fn remove(pool: &SqlitePool, id: i64) -> sqlx::Result<Option<String>> {
        sqlx::query_scalar!(r#"DELETE FROM captcha_bypass WHERE id = ?1 RETURNING email AS "email!""#, id).fetch_optional(pool).await
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn entries_are_checked_as_the_pages_checked_them() {
        assert_eq!(Blacklist::invalid(""), Some("Domain is required"));
        assert_eq!(Blacklist::invalid("example"), Some("Invalid domain format"));
        assert_eq!(Blacklist::invalid("-x.example.com"), Some("Invalid domain format"));
        assert_eq!(Blacklist::invalid("mail.example.com"), None);
        assert_eq!(CaptchaBypass::invalid("@example"), Some("Invalid domain format"));
        assert_eq!(CaptchaBypass::invalid("@example.com"), None);
        assert_eq!(CaptchaBypass::invalid("pat.lee@example.com"), None);
        assert_eq!(CaptchaBypass::invalid("pat lee@example.com"), Some("Invalid email format"));
    }
}
