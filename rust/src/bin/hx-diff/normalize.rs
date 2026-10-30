//! The differences hx-diff forgives, and nothing else. Each rule removes one kind of value that differs between two
//! correct answers to the same request (who served it, when, which session); they run in this order on both sides.
//! `hx-diff rules` prints this table, and ORACLE.md lists it as the allowed differences.

use std::sync::LazyLock;

use regex::{Captures, Regex};

pub struct Ctx {
    /// host[:port] of both stacks under test, besides www.harcourt.co itself.
    pub hosts: Vec<String>,
    /// Request time, unix seconds.
    pub now: i64,
}

pub struct Rule {
    pub name: &'static str,
    pub removes: &'static str,
    apply: fn(&str, &Ctx) -> String,
}

pub const NEAR_NOW_SECS: i64 = 2 * 86_400;

pub static RULES: [Rule; 9] = [
    Rule {
        name: "host",
        removes: "Absolute origins of the two stacks under test and of www.harcourt.co / harcourt.co, with or without \
                  scheme or port, plain or JSON-escaped (https:\\/\\/...), become https://HOST.",
        apply: host,
    },
    Rule {
        name: "session-id",
        removes: "Values of the PHPSESSID and __Host-hx_session cookies wherever they appear in a body or URL.",
        apply: session_id,
    },
    Rule {
        name: "nonce",
        removes: "Per-render tokens: WordPress nonces (_wpnonce/_ajax_nonce in URLs, any \"...nonce...\" JSON key, a \"token\" JSON value of 32+ hex digits (the per-render \
                  token in scscpq_wp.php's loader), \
                  data-*nonce and nonce attributes, hidden inputs named *nonce*) and Gravity Forms state \
                  (hidden inputs gform_unique_id, gform_currency and state_N), and Akismet's ak_js hidden input.",
        apply: nonce,
    },
    Rule {
        name: "csrf",
        removes: "Every <input> element whose name attribute is exactly csrf, hidden or not, in any attribute order. \
                  It is the Rust pages' per-session form token, which the PHP lacks on purpose. Other inputs stay.",
        apply: csrf,
    },
    Rule {
        name: "gform-honeypot",
        removes: "The label text of the Gravity Forms honeypot field (the element with class gform_validation_container), \
                  which Gravity Forms picks at random on every render. The field itself and its input stay.",
        apply: gform_honeypot,
    },
    Rule {
        name: "gform-ajax",
        removes: "The hash= value inside the gform_ajax hidden input, which Gravity Forms derives from the WordPress \
                  salts. The rest of that input and every other input stay.",
        apply: gform_ajax,
    },
    Rule {
        name: "time-near-now",
        removes: "Timestamps within two days of the request: 10-digit unix seconds, 13-digit unix milliseconds, \
                  YYYY-MM-DD dates, YYYY-MM-DD HH:MM[:SS] and ISO 8601 date-times, and HTTP dates. Times further \
                  from now are data and stay.",
        apply: time_near_now,
    },
    Rule {
        name: "asset-version",
        removes: "The ver= or v= query value on URLs of .js and .css files (cache busters).",
        apply: asset_version,
    },
    Rule {
        name: "whitespace",
        removes: "Whitespace-only differences: CRLF, runs of spaces/tabs/newlines, and blank lines. Output is one \
                  tag per line (a line break before every <) with lines trimmed, so diffs read tag by tag.",
        apply: whitespace,
    },
];

/// Headers compared after the rules; every other header is ignored.
pub const HEADERS: [&str; 4] = ["content-type", "location", "content-disposition", "x-robots-tag"];

/// Session cookies whose name differs by design between PHP and Rust; other Set-Cookie names are compared.
pub const SESSION_COOKIES: [&str; 2] = ["PHPSESSID", "__Host-hx_session"];

pub fn apply_all(text: &str, ctx: &Ctx) -> String {
    RULES.iter().fold(text.to_string(), |acc, r| (r.apply)(&acc, ctx))
}

/// Only the host rule, for headers such as Location.
pub fn host_only(text: &str, ctx: &Ctx) -> String {
    host(text, ctx)
}

fn re(s: &str) -> Regex {
    Regex::new(s).unwrap_or_else(|e| panic!("normalization regex {s:?}: {e}"))
}

fn host(text: &str, ctx: &Ctx) -> String {
    let mut names: Vec<String> = ctx.hosts.iter().map(|h| regex::escape(h)).collect();
    names.push(r"(?:www\.)?harcourt\.co".into());
    let pattern = format!(r"(?i)(?:https?:)?(//|\\/\\/)(?:{})(?::\d+)?\b", names.join("|"));
    re(&pattern)
        .replace_all(text, |c: &Captures| if &c[1] == "//" { "https://HOST" } else { r"https:\/\/HOST" })
        .into_owned()
}

fn session_id(text: &str, _: &Ctx) -> String {
    static RE: LazyLock<Regex> = LazyLock::new(|| re(r"\b(PHPSESSID|__Host-hx_session)=[A-Za-z0-9_\-,%]+"));
    RE.replace_all(text, "$1={SID}").into_owned()
}

fn nonce(text: &str, _: &Ctx) -> String {
    static RES: LazyLock<[Regex; 5]> = LazyLock::new(|| {
        [
            re(r"\b(_wpnonce|_ajax_nonce)=[0-9a-f]{6,}"),
            re(r#"(?i)("[a-z_]*nonce[a-z_]*"\s*:\s*")[^"]*"|("token"\s*:\s*")[0-9a-f]{32,}""#),
            re(r#"(?i)(\sdata-[a-z_-]*nonce=["'])[^"']*"#),
            re(r#"(?i)(\snonce=["'])[^"']*"#),
            re(r#"(?i)(<input\b[^>]*\bname=["'](?:[a-z_-]*nonce[a-z_-]*|gform_unique_id|gform_currency|state_\d+|ak_js)["'][^>]*\bvalue=["'])[^"']*"#),
        ]
    });
    let mut out = text.to_string();
    for (i, r) in RES.iter().enumerate() {
        let to = if i == 1 { "$1$2{NONCE}\"" } else if i == 0 { "$1={NONCE}" } else { "${1}{NONCE}" };
        out = r.replace_all(&out, to).into_owned();
    }
    out
}

static INPUT: LazyLock<Regex> = LazyLock::new(|| re(r#"(?i)<input\b(?:[^>"']|"[^"]*"|'[^']*')*>"#));

/// The name attribute of an `<input ...>` tag, read attribute by attribute so a value that merely contains
/// `name=` does not count.
fn input_name(tag: &str) -> Option<&str> {
    static ATTR: LazyLock<Regex> =
        LazyLock::new(|| re(r#"\s([^\s"'=<>/]+)(?:\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s"'=<>`]+)))?"#));
    ATTR.captures_iter(tag.get("<input".len()..)?).find_map(|a| {
        let value = a.get(2).or(a.get(3)).or(a.get(4)).map(|m| m.as_str()).unwrap_or("");
        a[1].eq_ignore_ascii_case("name").then_some(value)
    })
}

fn csrf(text: &str, _: &Ctx) -> String {
    INPUT
        .replace_all(text, |c: &Captures| if input_name(&c[0]) == Some("csrf") { String::new() } else { c[0].to_string() })
        .into_owned()
}

fn gform_honeypot(text: &str, _: &Ctx) -> String {
    static RE: LazyLock<Regex> = LazyLock::new(|| {
        re(r#"(class=["'][^"']*\bgform_validation_container\b[^"']*["'][^>]*>\s*<label\b[^>]*>(?:\s*<span\b[^>]*>)?)[^<]*"#)
    });
    RE.replace_all(text, "${1}{HONEYPOT}").into_owned()
}

fn gform_ajax(text: &str, _: &Ctx) -> String {
    static HASH: LazyLock<Regex> = LazyLock::new(|| re(r"\bhash=[0-9A-Za-z]+"));
    INPUT
        .replace_all(text, |c: &Captures| {
            if input_name(&c[0]) == Some("gform_ajax") {
                HASH.replace_all(&c[0], "hash={HASH}").into_owned()
            } else {
                c[0].to_string()
            }
        })
        .into_owned()
}

/// Days from 1970-01-01 to a proleptic Gregorian date (Howard Hinnant's days_from_civil).
fn days_from_civil(y: i64, m: i64, d: i64) -> i64 {
    let y = if m <= 2 { y - 1 } else { y };
    let era = y.div_euclid(400);
    let yoe = y - era * 400;
    let doy = (153 * (if m > 2 { m - 3 } else { m + 9 }) + 2) / 5 + d - 1;
    let doe = yoe * 365 + yoe / 4 - yoe / 100 + doy;
    era * 146_097 + doe - 719_468
}

fn epoch(y: i64, mo: i64, d: i64, h: i64, mi: i64, s: i64) -> Option<i64> {
    let valid = (1..=12).contains(&mo) && (1..=31).contains(&d) && h < 24 && mi < 60 && s < 61;
    valid.then(|| days_from_civil(y, mo, d) * 86_400 + h * 3600 + mi * 60 + s)
}

const MONTHS: [&str; 12] = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];

fn time_near_now(text: &str, ctx: &Ctx) -> String {
    static ISO: LazyLock<Regex> = LazyLock::new(|| {
        re(r"\b(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2})(?:\.\d+)?)?(?:Z|[+-]\d{2}:?\d{2})?)?\b")
    });
    static HTTP: LazyLock<Regex> = LazyLock::new(|| {
        re(r"\b(?:Mon|Tue|Wed|Thu|Fri|Sat|Sun), (\d{2}) (Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec) (\d{4}) (\d{2}):(\d{2}):(\d{2}) GMT\b")
    });
    static UNIX: LazyLock<Regex> = LazyLock::new(|| re(r"\b(\d{13}|\d{10})\b"));
    let near = |t: Option<i64>| t.is_some_and(|t| (t - ctx.now).abs() <= NEAR_NOW_SECS);
    let num = |c: &Captures, i: usize| c.get(i).map_or(Some(0), |m| m.as_str().parse::<i64>().ok());
    let out = ISO.replace_all(text, |c: &Captures| {
        let t = (|| epoch(num(c, 1)?, num(c, 2)?, num(c, 3)?, num(c, 4)?, num(c, 5)?, num(c, 6)?))();
        if near(t) { "{NOW}".to_string() } else { c[0].to_string() }
    });
    let out = HTTP.replace_all(&out, |c: &Captures| {
        let mo = MONTHS.iter().position(|m| *m == &c[2]).map(|i| i as i64 + 1);
        let t = (|| epoch(num(c, 3)?, mo?, num(c, 1)?, num(c, 4)?, num(c, 5)?, num(c, 6)?))();
        if near(t) { "{NOW}".to_string() } else { c[0].to_string() }
    });
    UNIX.replace_all(&out, |c: &Captures| {
        let v = c[1].parse::<i64>().ok();
        let t = if c[1].len() == 13 { v.map(|ms| ms / 1000) } else { v };
        if near(t) { "{NOW}".to_string() } else { c[0].to_string() }
    })
    .into_owned()
}

fn asset_version(text: &str, _: &Ctx) -> String {
    static RE: LazyLock<Regex> =
        LazyLock::new(|| re(r#"(\.(?:js|css)\?(?:[^"'\s<>#]*?(?:&amp;|&))?)(ver|v)=[^&"'\s<>#]*"#));
    RE.replace_all(text, "${1}${2}={V}").into_owned()
}

fn whitespace(text: &str, _: &Ctx) -> String {
    static RUNS: LazyLock<Regex> = LazyLock::new(|| re(r"\s+"));
    let one = RUNS.replace_all(text, " ");
    one.replace('<', "\n<").lines().map(str::trim).filter(|l| !l.is_empty()).collect::<Vec<_>>().join("\n")
}

#[cfg(test)]
mod tests {
    use super::*;

    const NOW: i64 = 1_793_318_400; // 2026-10-30 00:00:00 UTC

    fn ctx() -> Ctx {
        Ctx { hosts: vec!["172.31.4.145".into(), "127.0.0.1:8601".into()], now: NOW }
    }

    #[test]
    fn host_forms_collapse_and_other_sites_stay() {
        let c = ctx();
        assert_eq!(host("<a href=\"https://www.harcourt.co/catalog/\">", &c), "<a href=\"https://HOST/catalog/\">");
        assert_eq!(host("http://127.0.0.1:8601/x", &c), "https://HOST/x");
        assert_eq!(host("https://172.31.4.145:8443/x", &c), "https://HOST/x");
        assert_eq!(host(r#""url":"https:\/\/www.harcourt.co\/fr\/""#, &c), r#""url":"https:\/\/HOST\/fr\/""#);
        assert_eq!(host("//harcourt.co/a", &c), "https://HOST/a");
        assert_eq!(host("https://www.harcourt.com/a", &c), "https://www.harcourt.com/a");
        assert_eq!(host("https://fonts.googleapis.com/css", &c), "https://fonts.googleapis.com/css");
    }

    #[test]
    fn session_ids_go() {
        assert_eq!(session_id("a.php?PHPSESSID=abc123def&x=1", &ctx()), "a.php?PHPSESSID={SID}&x=1");
        assert_eq!(session_id("__Host-hx_session=Zm9v_-x", &ctx()), "__Host-hx_session={SID}");
        assert_eq!(session_id("SESSIONID=abc", &ctx()), "SESSIONID=abc");
    }

    #[test]
    fn nonces_go_and_ordinary_values_stay() {
        let c = ctx();
        assert_eq!(nonce("/wp-admin/admin-ajax.php?_wpnonce=1a2b3c4d5e", &c), "/wp-admin/admin-ajax.php?_wpnonce={NONCE}");
        assert_eq!(nonce(r#"{"restNonce":"ab12cd34ef","id":"7"}"#, &c), r#"{"restNonce":"{NONCE}","id":"7"}"#);
        assert_eq!(nonce(r#"<div data-ajax-nonce="f00ba4">"#, &c), r#"<div data-ajax-nonce="{NONCE}">"#);
        let loader = r#"{"scscpq_search":null,"token":"4df41c71831abc3c33424f883ebe8e0af28d0d94","trace":["token"]}"#;
        assert_eq!(nonce(loader, &c), r#"{"scscpq_search":null,"token":"{NONCE}","trace":["token"]}"#);
        assert_eq!(nonce(r#"{"token":"short"}"#, &c), r#"{"token":"short"}"#);
        assert_eq!(nonce(r#"<script nonce="r4nd0m">"#, &c), r#"<script nonce="{NONCE}">"#);
        assert_eq!(
            nonce(r#"<input type="hidden" id="_wpnonce" name="_wpnonce" value="0a1b2c3d4e" />"#, &c),
            r#"<input type="hidden" id="_wpnonce" name="_wpnonce" value="{NONCE}" />"#
        );
        assert_eq!(
            nonce("<input type='hidden' class='gform_hidden' name='state_1' value='WyJbXSIsIjZk' />", &c),
            "<input type='hidden' class='gform_hidden' name='state_1' value='{NONCE}' />"
        );
        assert_eq!(nonce(r#"<input name="gform_unique_id" value="abc">"#, &c), r#"<input name="gform_unique_id" value="{NONCE}">"#);
        assert_eq!(
            nonce("<input type='hidden' name='gform_currency' data-currency='USD' value='A19n+zm/Q=' />", &c),
            "<input type='hidden' name='gform_currency' data-currency='USD' value='{NONCE}' />"
        );
        assert_eq!(nonce(r#"<input type="hidden" id="ak_js_1" name="ak_js" value="22" />"#, &c), r#"<input type="hidden" id="ak_js_1" name="ak_js" value="{NONCE}" />"#);
        let stays = r#"<input type="hidden" name="subcategory_id" value="658">"#;
        assert_eq!(nonce(stays, &c), stays);
    }

    #[test]
    fn csrf_inputs_go_in_every_spelling() {
        let c = ctx();
        for tag in [
            r#"<input type="hidden" name="csrf" value="Zm9v_-x">"#,
            r#"<input value="Zm9v" name="csrf" type="hidden" />"#,
            "<input name='csrf' value='x'>",
            "<input type=hidden name=csrf value=abc>",
            "<input name=csrf>",
            r#"<INPUT Name = "csrf" value="x"/>"#,
            r#"<input type="text" data-x="a>b" name="csrf">"#,
        ] {
            assert_eq!(csrf(&format!("<form>{tag}<button>"), &c), "<form><button>", "{tag}");
        }
    }

    #[test]
    fn the_honeypot_label_goes_and_the_field_stays() {
        let c = ctx();
        let field = |label: &str| {
            format!(
                "<li id=\"field_3_14\" class=\"gfield gfield--type-honeypot gform_validation_container field_sublabel_below\"  >\
                 <label class='gfield_label gform-field-label' for='input_3_14'><span class='gform-field-label__text'>{label}</span></label>\
                 <div class='ginput_container'><input name='input_14' id='input_3_14' type='text' value='' /></div></li>"
            )
        };
        assert_eq!(gform_honeypot(&field("X/Twitter"), &c), gform_honeypot(&field("Phone"), &c));
        assert!(gform_honeypot(&field("Phone"), &c).contains(">{HONEYPOT}</span>"));
        assert!(gform_honeypot(&field("Phone"), &c).contains("<input name='input_14' id='input_3_14'"));
        let bare = "<div class='gfield gform_validation_container'><label class='gfield_label' for='input_1_5'>Email</label>";
        assert_eq!(gform_honeypot(bare, &c), "<div class='gfield gform_validation_container'><label class='gfield_label' for='input_1_5'>{HONEYPOT}</label>");
        let ordinary = "<li class=\"gfield gfield--type-text\"><label class='gfield_label' for='input_3_1'><span>Name</span></label>";
        assert_eq!(gform_honeypot(ordinary, &c), ordinary);
        let lookalike = "<li class=\"gform_validation_container_x\"><label>Phone</label>";
        assert_eq!(gform_honeypot(lookalike, &c), lookalike);
    }

    #[test]
    fn only_the_gform_ajax_hash_goes() {
        let c = ctx();
        let input = "<input type='hidden' name='gform_ajax' value='form_id=1&amp;title=&amp;theme=legacy&amp;hash=d2234b66e616f050984016021d094b0b' />";
        assert_eq!(
            gform_ajax(input, &c),
            "<input type='hidden' name='gform_ajax' value='form_id=1&amp;title=&amp;theme=legacy&amp;hash={HASH}' />"
        );
        let reordered = "<input value='form_id=2&amp;hash=abc123' type='hidden' name='gform_ajax'>";
        assert_eq!(gform_ajax(reordered, &c), "<input value='form_id=2&amp;hash={HASH}' type='hidden' name='gform_ajax'>");
        for stays in [
            "<input type='hidden' name='gform_field_values' value='hash=abc'>",
            "<input type='hidden' name='gform_ajax_x' value='hash=abc'>",
            "<a href='/x?hash=abc'>",
            "<iframe name='gform_ajax_frame_1' src='about:blank'>",
        ] {
            assert_eq!(gform_ajax(stays, &c), stays);
        }
    }

    #[test]
    fn csrf_leaves_every_other_input_alone() {
        let c = ctx();
        for tag in [
            r#"<input type="hidden" name="csrf_token" value="x">"#,
            r#"<input name="mycsrf" value="x">"#,
            r#"<input name="CSRF" value="x">"#,
            r#"<input name="email" value="csrf">"#,
            r#"<input data-name="csrf" name="email">"#,
            r#"<input title="x name=csrf" name="email">"#,
            r#"<input id="csrf" name="email">"#,
            r#"<input type="hidden" name="_wpnonce" value="abc">"#,
            r#"<meta name="csrf" content="x">"#,
            r#"<inputs name="csrf">"#,
        ] {
            assert_eq!(csrf(tag, &c), tag, "{tag}");
        }
    }

    #[test]
    fn times_near_now_go_and_data_stays() {
        let c = ctx();
        assert_eq!(time_near_now("t=1793318400&x=1", &c), "t={NOW}&x=1");
        assert_eq!(time_near_now("ms 1793318400123", &c), "ms {NOW}");
        assert_eq!(time_near_now("sku 1234567890", &c), "sku 1234567890");
        assert_eq!(time_near_now("part 1793318400999999", &c), "part 1793318400999999");
        assert_eq!(time_near_now("at 2026-10-29 23:59:01 UTC", &c), "at {NOW} UTC");
        assert_eq!(time_near_now("at 2026-10-30T01:02:03+00:00.", &c), "at {NOW}.");
        assert_eq!(time_near_now("on 2026-10-31", &c), "on {NOW}");
        assert_eq!(time_near_now("order 2024-03-01 10:00:00", &c), "order 2024-03-01 10:00:00");
        assert_eq!(time_near_now("Date: Thu, 29 Oct 2026 22:00:00 GMT", &c), "Date: {NOW}");
        assert_eq!(time_near_now("Sun, 06 Nov 1994 08:49:37 GMT", &c), "Sun, 06 Nov 1994 08:49:37 GMT");
        assert_eq!(time_near_now("2026-13-45", &c), "2026-13-45");
    }

    #[test]
    fn asset_versions_go_on_js_and_css_only() {
        let c = ctx();
        assert_eq!(asset_version(r#"src="/a/b.js?ver=6.8.1""#, &c), r#"src="/a/b.js?ver={V}""#);
        assert_eq!(asset_version(r#"href="/s.css?x=1&amp;ver=20261021b""#, &c), r#"href="/s.css?x=1&amp;ver={V}""#);
        assert_eq!(asset_version(r#"src="/hx/hx-nav.js?v=7""#, &c), r#"src="/hx/hx-nav.js?v={V}""#);
        assert_eq!(asset_version(r#"href="/catalog.php?v=7""#, &c), r#"href="/catalog.php?v=7""#);
    }

    #[test]
    fn whitespace_only_differences_vanish() {
        let c = ctx();
        assert_eq!(whitespace("<p>a\r\n  b</p>\n\n<br>", &c), whitespace("<p>a b</p><br>", &c));
        assert_eq!(whitespace("<div>\t<span>x</span></div>", &c), "<div>\n<span>x\n</span>\n</div>");
        assert_ne!(whitespace("<p>ab</p>", &c), whitespace("<p>a b</p>", &c));
    }

    #[test]
    fn rules_run_in_order_on_whole_pages() {
        let page = "<a href='https://www.harcourt.co/x.js?ver=1'>\r\n<input name='_wpnonce' value='abcdef1234'> 1793318400";
        assert_eq!(apply_all(page, &ctx()), "<a href='https://HOST/x.js?ver={V}'>\n<input name='_wpnonce' value='{NONCE}'> {NOW}");
        assert_eq!(
            RULES.iter().map(|r| r.name).collect::<Vec<_>>(),
            [
                "host",
                "session-id",
                "nonce",
                "csrf",
                "gform-honeypot",
                "gform-ajax",
                "time-near-now",
                "asset-version",
                "whitespace"
            ]
        );
    }

    #[test]
    fn civil_dates_match_known_epochs() {
        assert_eq!(epoch(1970, 1, 1, 0, 0, 0), Some(0));
        assert_eq!(epoch(2026, 10, 30, 0, 0, 0), Some(NOW));
        assert_eq!(epoch(2000, 2, 29, 12, 0, 0), Some(951_825_600));
        assert_eq!(epoch(2026, 0, 1, 0, 0, 0), None);
    }
}
