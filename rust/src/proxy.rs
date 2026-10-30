//! Everything hxweb does not build itself goes to the legacy stack (Apache + PHP: WordPress form posts, SCS pages,
//! AJAX, downloads) unchanged, streamed both ways. On a staging host (HXWEB_PUBLIC_HOST set), text responses are
//! buffered so the site's absolute www.harcourt.co links point back at the staging host.

use axum::{
    body::Body,
    extract::Request,
    http::{header, HeaderName, HeaderValue, Response, StatusCode},
};

use crate::App;

const HOP_BY_HOP: [HeaderName; 4] = [header::CONNECTION, header::TRANSFER_ENCODING, header::UPGRADE, header::TE];

pub async fn pass(app: &App, req: Request) -> Response<Body> {
    let (parts, body) = req.into_parts();
    let uri = parts.uri.path_and_query().map(|p| p.as_str()).unwrap_or("/").to_string();
    let staging = app.public_host != app.site;
    let mut out = app
        .legacy_http
        .request(parts.method.clone(), format!("{}{}", app.legacy, uri))
        .body(reqwest::Body::wrap_stream(body.into_data_stream()));
    for (k, v) in &parts.headers {
        if !HOP_BY_HOP.contains(k) && k != header::HOST && !(staging && k == header::ACCEPT_ENCODING) {
            out = out.header(k, v);
        }
    }
    out = out.header(header::HOST, &app.site);
    let r = match out.send().await {
        Ok(r) => r,
        Err(e) => {
            tracing::error!("legacy {} {uri}: {e}", parts.method);
            return Response::builder().status(StatusCode::BAD_GATEWAY).body(Body::from("Bad gateway")).unwrap();
        }
    };
    app.search.forget(&parts.headers, r.headers().get_all(header::SET_COOKIE));
    let text = staging
        && r.headers().get(header::CONTENT_TYPE).and_then(|v| v.to_str().ok()).is_some_and(|ct| {
            ["text/", "application/json", "application/javascript", "application/xml"].iter().any(|t| ct.starts_with(t))
        });
    let mut b = Response::builder().status(r.status());
    for (k, v) in r.headers() {
        if HOP_BY_HOP.contains(k) || (text && k == header::CONTENT_LENGTH) {
            continue;
        }
        if staging && k == header::LOCATION {
            if let Ok(loc) = HeaderValue::from_str(&app.public(v.to_str().unwrap_or(""))) {
                b = b.header(k, loc);
                continue;
            }
        }
        b = b.header(k, v);
    }
    if text {
        let bytes = r.bytes().await.unwrap_or_default();
        return b.body(Body::from(app.public_bytes(&bytes))).unwrap();
    }
    b.body(Body::from_stream(r.bytes_stream())).unwrap()
}
