//! The two endpoints other sites call: scs_api.php answers a remote campaign page, stock.php is PARTsolutions
//! reporting a CAD download. Neither ran scs_header.php's page handling: scs_api.php started no session at all,
//! stock.php stopped after starting one.

use std::{collections::HashMap, sync::Arc};

use axum::{
    body::Bytes,
    extract::{Query, State},
    http::{header, StatusCode},
    response::{IntoResponse, Response},
    routing::get,
    Router,
};
use sqlx::SqlitePool;

use crate::{
    accounts::{campaign, clock, profile::check_email},
    api::Auth,
    db::unix_now,
    visitor::{Visitor, PHP_CONTENT_TYPE},
};

pub fn router() -> Router<Arc<Auth>> {
    Router::new().route("/scs_api.php", get(scs_api).post(scs_api)).route("/stock.php", get(stock).post(stock))
}

/// $_REQUEST: the query, then the posted form over it.
fn request(query: HashMap<String, String>, body: &Bytes) -> HashMap<String, String> {
    let mut params = query;
    params.extend(form_urlencoded::parse(body).into_owned());
    params
}

fn text(status: StatusCode, body: String) -> Response {
    (status, [(header::CONTENT_TYPE, PHP_CONTENT_TYPE)], body).into_response()
}

fn failed(e: sqlx::Error) -> Response {
    tracing::error!("catalog callback: {e}");
    text(StatusCode::INTERNAL_SERVER_ERROR, "Internal error".into())
}

/// scs_api.php. The PHP meant to grade the caller's company (public, semiprivate, private) and name it, but it looked
/// the address up as an account id and so never found one: every caller got "public" and no company. Since it
/// answered pages on any site, finding the company would have told any site who its visitor works for, so that
/// answer stays. A visit from a running Remote campaign is logged once a day per address and person.
async fn scs_api(State(core): State<Arc<Auth>>, visitor: Visitor, Query(query): Query<HashMap<String, String>>, body: Bytes) -> Response {
    let p = request(query, &body);
    let get = |k: &str| p.get(k).map(String::as_str).unwrap_or("");
    let result = async {
        let email = check_email(get("email"), true).unwrap_or_default();
        let today = clock::detroit(unix_now()).date;
        if let Some(c) = campaign::active(&core.pool, get("campaign"), today).await?.filter(|c| c.kind == campaign::Kind::Remote) {
            let ip = visitor.client.map(|ip| ip.to_string()).unwrap_or_default();
            log_campaign(&core.pool, &ip, &email, &c.code).await?;
        }
        Ok::<_, sqlx::Error>("{\"accesslevel\":\"public\",\"company\":null}".to_string())
    }
    .await;
    match result {
        Ok(body) => text(StatusCode::OK, body),
        Err(e) => failed(e),
    }
}

/// The campaign's arrival, unless this address and person already arrived today.
async fn log_campaign(pool: &SqlitePool, ip: &str, email: &str, code: &str) -> sqlx::Result<()> {
    let now = unix_now();
    let local = clock::detroit(now);
    let day_start = now - i64::from(local.hour) * 3600 - i64::from(local.minute) * 60 - now.rem_euclid(60);
    let seen = sqlx::query_scalar!(
        r#"SELECT EXISTS (SELECT 1 FROM log WHERE ip = ?1 AND type = 'Initial' AND subtype = 'Campaign' AND date_time >= ?2 AND email = ?3) AS "seen!: bool""#,
        ip,
        day_start,
        email
    )
    .fetch_one(pool)
    .await?;
    if !seen {
        sqlx::query!(
            "INSERT INTO log (ip, date_time, user_id, bot_id, email, type, subtype, comment) VALUES (?1, ?2, 0, 0, ?3, 'Initial', 'Campaign', ?4)",
            ip,
            now,
            email,
            code
        )
        .execute(pool)
        .await?;
    }
    Ok(())
}

/// stock.php: a CAD download PARTsolutions reports with `name=<user id>|<name>`, the person's email, the part's id
/// as `sku` and the CAD format. Anything missing or unknown is refused with the PHP's message; a complete report is
/// logged and answered with nothing.
async fn stock(State(core): State<Arc<Auth>>, visitor: Visitor, Query(query): Query<HashMap<String, String>>, body: Bytes) -> Response {
    let p = request(query, &body);
    let get = |k: &str| p.get(k).map(String::as_str).unwrap_or("");
    let result = async {
        let (user_id, name) = get("name").split_once('|').unwrap_or((get("name"), ""));
        let email = check_email(get("email"), true);
        let (part, cad_code) = (get("sku").trim(), get("cad").trim());
        let user = sqlx::query_scalar!(r#"SELECT id AS "id!: i64" FROM "user" WHERE id = ?1"#, user_id).fetch_optional(&core.pool).await?;
        let part = sqlx::query!(r#"SELECT id AS "id!: i64", sku, subcategory_id FROM inventory WHERE id = ?1"#, part).fetch_optional(&core.pool).await?;
        let (Some(user), Some(part), Ok(email), false, false) = (user, part, email, name.is_empty(), cad_code.is_empty()) else {
            return Ok::<_, sqlx::Error>("Missing parameters".to_string());
        };
        let ip = visitor.client.map(|ip| ip.to_string()).unwrap_or_default();
        let now = unix_now();
        let sku = part.sku;
        let subcategory_id = part.subcategory_id.unwrap_or(0);
        sqlx::query!(
            "INSERT INTO log (ip, date_time, user_id, bot_id, email, type, subtype, sku, subcategory_id, comment) VALUES (?1, ?2, ?3, 0, ?4, 'PARTSolutions', 'CAD', ?5, ?6, ?7)",
            ip,
            now,
            user,
            email,
            sku,
            subcategory_id,
            cad_code
        )
        .execute(&core.pool)
        .await?;
        Ok(String::new())
    }
    .await;
    match result {
        Ok(body) => text(StatusCode::OK, body),
        Err(e) => failed(e),
    }
}
