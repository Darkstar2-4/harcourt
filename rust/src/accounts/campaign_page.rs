//! campaign.php: a campaign's landing page. Everyone sees its Public content; a visitor who is not signed in gets
//! the short sign-up form; a signed-in company that is not Denied sees the Semi-Private content and, until the
//! browser has given an address, the address form; an Active company that has given one sees the Private content
//! and the campaign's product families.
//!
//! The address form only fills the `harcourt` cookie, as the PHP's did: the gate is the company's Active status.

use std::sync::Arc;

use axum::{
    extract::{Form, State},
    http::{header, HeaderMap, HeaderValue, StatusCode, Uri},
    response::{IntoResponse, Response},
};
use sqlx::SqlitePool;

use crate::{
    accounts::{
        admission::{self, Registration},
        bot::BotStatus,
        cookies,
        locale::Labels,
        log::{self, Kind, Subject},
        registry,
        visit,
    },
    api::Auth,
    db::unix_now,
    identity::{Email, Status},
    staff::{
        check,
        csrf::Fields,
        html::{esc, hidden, href, submit, text, url_query, Form as Widgets, Notes, Opts},
        phpser,
        shell::{self, Head, View},
    },
    visitor::{self, Visitor},
};

/// The `campaign` row as campaign.php used it. `kind` is the raw type: the page tests for "Invite" and "Remote"
/// only, so anything else behaves as Public.
struct Campaign {
    id: i64,
    code: String,
    name: String,
    description: String,
    kind: String,
    title_omit: bool,
    content_class: String,
    subcategories: Vec<i64>,
    css: Vec<String>,
}

/// classes/campaign.php read($code, "code"): MySQL compared the code without case or trailing spaces.
async fn read(pool: &SqlitePool, code: &str) -> sqlx::Result<Option<Campaign>> {
    let row = sqlx::query!(
        r#"SELECT id AS "id!: i64", code, name, description, "type" AS kind, title_omit, divs, subcategory, css
           FROM campaign WHERE rtrim(lower(code), ' ') = rtrim(lower(?1), ' ') ORDER BY id LIMIT 1"#,
        code
    )
    .fetch_optional(pool)
    .await?;
    Ok(row.map(|r| {
        let divs = r.divs.as_deref().and_then(phpser::parse);
        let content_class = divs.as_ref().and_then(|d| d.get("content")).map(|v| v.text()).unwrap_or_else(|| "campaign_content".into());
        let list = |s: Option<String>| -> Vec<String> { s.filter(|s| !s.is_empty()).map(|s| s.split('^').map(str::to_string).collect()).unwrap_or_default() };
        Campaign {
            id: r.id,
            code: r.code.unwrap_or_default(),
            name: r.name.unwrap_or_default(),
            description: r.description.unwrap_or_default(),
            kind: r.kind.unwrap_or_default(),
            title_omit: r.title_omit.unwrap_or(0) != 0,
            content_class,
            subcategories: list(r.subcategory).iter().filter_map(|s| s.trim().parse().ok()).collect(),
            css: list(r.css),
        }
    }))
}

/// Who campaign.php drew the page for: the session user's id and status, the company name it greets, and the
/// address the browser remembers.
struct Reader {
    signed_in: Option<(i64, Status, String)>,
    cookie: String,
}

impl Reader {
    /// A bot's session user had the bot's negated id, its name and its status, so a bot reads as signed in.
    fn of(visitor: &Visitor, headers: &HeaderMap) -> Reader {
        let signed_in = match (visitor.user(), visitor.bot()) {
            (Some(u), _) => Some((u.id.0, u.status, visitor.account().and_then(|a| a.user.company_name.clone()).unwrap_or_default())),
            (None, Some(b)) => Some((-b.id, if b.status == BotStatus::Active { Status::Active } else { Status::Denied }, b.name.clone())),
            (None, None) => None,
        };
        let cookie = cookies::get(headers, cookies::EMAIL).filter(|c| !matches!(c.as_str(), "" | "0")).unwrap_or_default();
        Reader { signed_in, cookie }
    }
}

enum Posted {
    Register { first_name: String, last_name: String, company_name: String, email: String },
    Email(String),
    Nothing,
}

impl Posted {
    fn of(fields: &Fields) -> Posted {
        match fields.get("action") {
            "register" => Posted::Register {
                first_name: check::posted_text(fields.get("first_name")),
                last_name: check::posted_text(fields.get("last_name")),
                company_name: check::posted_text(fields.get("company_name")),
                email: fields.get("email").to_string(),
            },
            "email" => Posted::Email(fields.get("email").to_string()),
            _ => Posted::Nothing,
        }
    }
}

/// The sign-up form's fields as typed, shown again after a failed post.
#[derive(Default)]
struct Typed {
    first_name: String,
    last_name: String,
    company_name: String,
    email: String,
}

pub async fn page(State(core): State<Arc<Auth>>, visitor: Visitor, headers: HeaderMap, uri: Uri) -> Response {
    respond(&core, &visitor, &headers, Fields::from_query(uri.query())).await
}

pub async fn post(State(core): State<Arc<Auth>>, visitor: Visitor, headers: HeaderMap, uri: Uri, Form(posted): Form<Vec<(String, String)>>) -> Response {
    if headers.get(header::ORIGIN).is_some_and(|o| o.as_bytes() != core.public_origin.as_bytes()) {
        return StatusCode::FORBIDDEN.into_response();
    }
    respond(&core, &visitor, &headers, Fields::posted(posted).with_query(&Fields::from_query(uri.query()))).await
}

async fn respond(core: &Auth, visitor: &Visitor, headers: &HeaderMap, fields: Fields) -> Response {
    match run(core, visitor, headers, &fields).await {
        Ok(r) => r,
        Err(e) => shell::internal(e),
    }
}

fn home(visitor: &Visitor) -> Response {
    let to = if visitor.framed { "/" } else { "/scs_index.php" };
    (StatusCode::FOUND, [(header::LOCATION, to), (header::CONTENT_TYPE, visitor::PHP_CONTENT_TYPE)]).into_response()
}

async fn run(core: &Auth, visitor: &Visitor, headers: &HeaderMap, fields: &Fields) -> sqlx::Result<Response> {
    let pool = &core.pool;
    let now = unix_now();
    let mut kept = match &visitor.visit {
        Some(id) => visit::load(pool, id, now).await?.campaign_code,
        None => None,
    };
    let asked = if fields.has("campaign") { fields.get("campaign").to_string() } else { kept.clone().unwrap_or_default() };
    let Some(campaign) = read(pool, &asked).await? else { return Ok(home(visitor)) };
    if campaign.kind == "Invite" && kept.is_none() {
        if let Some(id) = &visitor.visit {
            visit::set_campaign(pool, id, Some(&campaign.code), now).await?;
        }
        kept = Some(campaign.code.clone());
    }
    if campaign.kind == "Remote" || (campaign.kind == "Invite" && kept.as_deref() != Some(campaign.code.as_str())) {
        return Ok(home(visitor));
    }

    let mut reader = Reader::of(visitor, headers);
    let mut notes = Notes::new(&campaign.name, "");
    let mut typed = Typed::default();
    let mut set_cookies: Vec<HeaderValue> = Vec::new();
    let ip = visitor.client.map(|ip| ip.to_string()).unwrap_or_default();
    match Posted::of(fields) {
        Posted::Register { first_name, last_name, company_name, email } if reader.signed_in.is_none() => {
            let (address, error) = check::email(&email, true);
            typed = Typed { first_name, last_name, company_name, email: address };
            for (field, value) in [("first_name", &typed.first_name), ("last_name", &typed.last_name), ("company_name", &typed.company_name)] {
                if value.is_empty() {
                    notes.error(field, None);
                }
            }
            if error.is_some() {
                notes.error("email", None);
            }
            if let (false, Some(email)) = (notes.has_errors(), Email::parse(&typed.email)) {
                let user_agent = headers.get(header::USER_AGENT).and_then(|v| v.to_str().ok()).unwrap_or("");
                let ctx = admission::Context { ip: &ip, user_agent, dns_name: None, now };
                let registration = Registration { company_name: typed.company_name.clone(), ..Registration::default() };
                let (id, sign_in) = admission::register(pool, &core.accounts, &registration, &email, false, &ctx).await?;
                set_cookies.extend(cookies::session(&sign_in.token, false));
                set_cookies.extend(cookies::php_year(cookies::EMAIL, email.as_str(), now));
                let comment = format!("Company name: {}<br>Name: {} {}", typed.company_name, typed.first_name, typed.last_name);
                admission::log_signup(pool, id, &ctx, &comment).await;
                reader.signed_in = Some((id.0, Status::Pending, typed.company_name.clone()));
                reader.cookie = email.as_str().to_string();
            }
        }
        Posted::Email(email) if reader.signed_in.is_some() => {
            let (address, error) = check::email(&email, true);
            typed.email = address;
            if error.is_some() {
                notes.error("email", None);
            } else {
                set_cookies.extend(cookies::php_year(cookies::EMAIL, &typed.email, now));
                let who = Subject {
                    ip: &ip,
                    user_id: reader.signed_in.as_ref().map_or(0, |s| s.0),
                    bot_id: visitor.bot().map_or(0, |b| b.id),
                    email: Some(&typed.email),
                };
                log::record(pool, Kind::UserEmail, &who, "").await;
                reader.cookie = typed.email.clone();
            }
        }
        _ => {}
    }

    let body = render(pool, visitor, &campaign, &asked, &reader, &notes, &typed).await?;
    let dashboard = Labels::load(pool, &visitor.language).await?.get("menu_dashboard", "Dashboard");
    let head = Head { title: &campaign.name, description: Some(&campaign.description), css: &campaign.css, dashboard: Some(&dashboard) };
    let view = View { role: visitor.role(), wp: visitor.framed };
    let mut response = shell::document(pool, view, &head, &body).await;
    for c in set_cookies {
        response.headers_mut().append(header::SET_COOKIE, c);
    }
    Ok(response)
}

async fn render(pool: &SqlitePool, visitor: &Visitor, campaign: &Campaign, asked: &str, reader: &Reader, notes: &Notes, typed: &Typed) -> sqlx::Result<String> {
    let w = Widgets { notes };
    let input = |field: &str, value: &str, size: u32| w.text(field, value, size, size, Opts::new().class("campaign_content_input"));
    let errors = ["first_name", "last_name", "company_name", "email"].iter().filter(|f| notes.is_error(f)).count();
    let mut out = format!("<form name='scs_form' id='scs_form' method=post action='{}' accept-charset='utf-8' autocomplete=on>\n", esc(&visitor.php_self));
    out.push_str(&hidden("campaign", asked));
    out.push_str(&content(pool, campaign, "Public").await?);

    let Some((_, status, company_name)) = &reader.signed_in else {
        out.push_str("<div class='campaign_form_div'><div class='campaign_form_content'>\n");
        out.push_str(&hidden("action", "register"));
        out.push_str("<div class=campaign_form_title_div><div style=\"font-size: 40px;font-weight: 600;\">New User?</div>");
        if errors > 0 {
            out.push_str(&format!("<div style=\"font-size: 20px; font-weight: normal;font-style: italic\">Correct {errors} error(s)</div>"));
        }
        out.push_str("</div>");
        for (label, field, value, size) in [
            ("First Name", "first_name", &typed.first_name, 50),
            ("Last Name", "last_name", &typed.last_name, 50),
            ("Company", "company_name", &typed.company_name, 60),
            ("Email Address", "email", &typed.email, 60),
        ] {
            out.push_str(&format!("<div>{label}</div><div>{}</div>", input(field, value, size)));
        }
        out.push_str(&format!("<div>{}</div>", submit("Submit", Opts::new().class("red-button campaign_form_button"))));
        out.push_str("<div class=campaign_form_disclaimer>Please complete this form to become an approved Harcourt &reg; user. Once you have completed the form you will be contacted further.<br><br>Thank you!</div>");
        out.push_str("</div> <!-- .campaign_form_content --></div> <!-- .campaign_form_div -->");
        out.push_str("</form>");
        return Ok(out);
    };
    if *status == Status::Denied {
        out.push_str("</form>");
        return Ok(out);
    }
    out.push_str(&content(pool, campaign, "Semi-Private").await?);
    if reader.cookie.is_empty() {
        out.push_str("<div class='campaign_form_div'><div class='campaign_form_content'>\n");
        out.push_str(&hidden("action", "email"));
        out.push_str("<div><div style=\"font-family: 'Rajdhani',sans-serif !important; font-weight: 600 !important; font-size: 40px; padding-top: 80px;\">Login</div>");
        if errors > 0 {
            out.push_str(&format!("<div style='font-size: 66%'>Correct {errors} error(s)</div>"));
        }
        out.push_str("</div>");
        out.push_str(&format!("<div class=campaign_email_welcome>Welcome {}</div>", text(company_name)));
        out.push_str("<div style='margin-top: 15px; margin-bottom; 15px; font-size: 15px;'>To access your products portal, please enter your email address<br>for verification.</div>");
        out.push_str("<div><br>Email Address</div>");
        let email = w.text("email", &typed.email, 50, 50, Opts::new().class("campaign_content_input").style("width: 220px;"));
        out.push_str(&format!("<div>{email} {}</div>", submit("Submit", Opts::new().class("red-button campaign_email_button"))));
        out.push_str("<div class='campaign_email_disclaimer'>Disclaimer: This should be a one-time verification only. Your email address will be kept strictly confidential and will not be disclosed to a third party or used outside the terms of our Privacy Policy.</div>");
        out.push_str("</div> <!-- .campaign_form_content --></div> <!-- .campaign_form_div -->");
    }
    if *status == Status::Active && !reader.cookie.is_empty() {
        out.push_str(&content(pool, campaign, "Private").await?);
        out.push_str(&products(pool, visitor, campaign).await?);
    }
    out.push_str("</form>");
    Ok(out)
}

/// campaign_output(): the campaign's content rows for one access level, in the order they were stored. Content
/// is page HTML the staff wrote, printed as stored.
async fn content(pool: &SqlitePool, campaign: &Campaign, access: &str) -> sqlx::Result<String> {
    let like = format!("{access}%");
    let rows = sqlx::query!(
        r#"SELECT name, description, filetype, html_content, CAST(content AS TEXT) AS "body: String"
           FROM content WHERE record_type = 'campaign' AND record_id = ?1 AND record_item LIKE ?2 ORDER BY id"#,
        campaign.id,
        like
    )
    .fetch_all(pool)
    .await?;
    let mut out = String::new();
    for r in rows {
        let mut parts = Vec::new();
        if !campaign.title_omit {
            parts.push(format!("<h1>{}</h1>", text(&r.name)));
        }
        let description = r.description.unwrap_or_default();
        if !description.is_empty() {
            parts.push(format!("<div>{description}</div>"));
        }
        let body = if matches!(r.filetype.as_str(), "html" | "hyperlink") { r.html_content } else { r.body };
        let body = body.unwrap_or_default();
        if !body.is_empty() {
            parts.push(format!("<div>{body}</div>"));
        }
        out.push_str(&format!("<div class={}>{}</div>", campaign.content_class, parts.join("<br>")));
    }
    Ok(out)
}

/// The campaign's product families, each linking to its page in the catalog.
async fn products(pool: &SqlitePool, visitor: &Visitor, campaign: &Campaign) -> sqlx::Result<String> {
    if campaign.subcategories.is_empty() {
        return Ok(String::new());
    }
    let ids = serde_json::to_string(&campaign.subcategories).unwrap_or_else(|_| "[]".into());
    let rows = sqlx::query!(
        r#"SELECT category.id AS "category_id!: i64", subcategory.id AS "id!: i64", subcategory.name, subcategory.thumbnail_url
           FROM inventory
           JOIN subcategory ON subcategory.id = inventory.subcategory_id
           JOIN category ON category.id = subcategory.category_id
           WHERE category.active = 1 AND subcategory.status = 'Active' AND inventory.active = 1
             AND inventory.subcategory_id IN (SELECT value FROM json_each(?1))
           GROUP BY subcategory.id
           ORDER BY category.sort_order, category.name, subcategory.sort_order, subcategory.name"#,
        ids
    )
    .fetch_all(pool)
    .await?;
    if rows.is_empty() {
        return Ok(String::new());
    }
    let size = registry::number(pool, "local", "thumbnail").await?.map(|n| (n as i64).to_string()).unwrap_or_default();
    let catalog = match (visitor.framed, visitor.account().is_some_and(|a| a.language_code == "FR")) {
        (false, _) => "/catalog.php",
        (true, true) => "/catalogue/",
        (true, false) => "/catalog/",
    };
    let class = &campaign.content_class;
    let mut out = format!("<div class={class}><div class=catalog_subcategory_list><ul>");
    for r in rows {
        let thumbnail = r.thumbnail_url.unwrap_or_default();
        let img = crate::catalog::forms::img(&thumbnail, &[("width", &size), ("height", &size)]).unwrap_or_default();
        let url = format!("{catalog}{}", url_query(&[("action", "subcategory"), ("category_id", &r.category_id.to_string()), ("subcategory_id", &r.id.to_string())]));
        out.push_str(&format!("<li>{}</li>", href(&format!("{img}<br>{}", esc(&r.name.unwrap_or_default())), &url, Opts::new())));
    }
    out.push_str(&format!("</ul></div> <!-- .catalog_subcategory_list --></div> <!-- .{class} -->"));
    Ok(out)
}
