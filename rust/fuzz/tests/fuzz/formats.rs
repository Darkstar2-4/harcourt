use hxweb::{accounts, catalog, pricing, staff};
use hxweb_fuzz::{config, gen, within, PURE};
use proptest::prelude::*;
use rust_decimal::{prelude::FromPrimitive, Decimal};

/// Every sign of a number: ordinary money, the edges of f64, and the values a database column can hold.
fn number() -> impl Strategy<Value = f64> {
    prop_oneof![
        4 => (-1_000_000_000i64..1_000_000_000, 0u32..4).prop_map(|(n, dp)| n as f64 / 10f64.powi(dp as i32)),
        2 => any::<f64>(),
        1 => prop_oneof![Just(f64::NAN), Just(f64::INFINITY), Just(f64::NEG_INFINITY), Just(f64::MAX), Just(f64::MIN), Just(-0.0), Just(1e300)],
    ]
}

/// A decimal that has an exact short spelling, `k / 1000`: PHP rounds it half away from zero.
fn thousandths() -> impl Strategy<Value = (i64, f64)> {
    (-100_000_000i64..100_000_000).prop_map(|k| (k, k as f64 / 1000.0))
}

/// `k / 1000` rounded half away from zero to `dp` (0..=2) places, spelled without grouping.
fn rounded_thousandths(k: i64, dp: u32) -> String {
    let scale = 10i64.pow(3 - dp);
    let q = k.abs() / scale;
    let r = k.abs() % scale;
    let q = if r * 2 >= scale { q + 1 } else { q };
    let unit = 10i64.pow(dp);
    let (int, frac) = (q / unit, q % unit);
    let sign = if k < 0 && q != 0 { "-" } else { "" };
    if dp == 0 { format!("{sign}{int}") } else { format!("{sign}{int}.{frac:0width$}", width = dp as usize) }
}

fn grouped_well(s: &str) -> bool {
    let s = s.trim_start_matches('-');
    let int = s.split('.').next().unwrap_or("");
    let groups: Vec<&str> = int.split(',').collect();
    groups[0].len() <= 3 && !groups[0].is_empty() && groups[1..].iter().all(|g| g.len() == 3) && int.bytes().all(|b| b.is_ascii_digit() || b == b',')
}

proptest! {
    #![proptest_config(config(4096))]

    #[test]
    fn number_formats_are_total(n in number(), dp in 0usize..6) {
        within(PURE, "pricing::number_format", || pricing::number_format(n, dp));
        within(PURE, "number_dp", || staff::html::number_dp(n, dp));
        within(PURE, "map::number_format", || staff::map::number_format(n, dp as u32));
        within(PURE, "php_round", || pricing::php_round(n, dp as i32));
        staff::map::float_text(n);
        if let Some(d) = Decimal::from_f64(n) {
            hxweb::orders::shop::number_format(d, dp as u32);
        }
    }

    /// PHP's number_format() of an ordinary amount, in each of the port's copies of it.
    #[test]
    fn number_format_copies_round_half_away_from_zero((k, n) in thousandths(), dp in 0u32..3) {
        let expected = rounded_thousandths(k, dp);
        let p = pricing::number_format(n, dp as usize);
        prop_assert!(grouped_well(&p), "{:?}", p);
        prop_assert_eq!(p.replace(',', ""), expected.clone(), "pricing::number_format({}, {})", n, dp);
        let s = staff::html::number_dp(n, dp as usize);
        prop_assert!(grouped_well(&s), "{:?}", s);
        prop_assert_eq!(s.replace(',', ""), expected.clone(), "staff::html::number_dp({}, {})", n, dp);
        prop_assert_eq!(staff::map::number_format(n, dp), expected.clone(), "staff::map::number_format({}, {})", n, dp);
        let o = hxweb::orders::shop::number_format(Decimal::new(k, 3), dp);
        prop_assert!(grouped_well(&o), "{:?}", o);
        prop_assert_eq!(o.replace(',', ""), expected, "orders::shop::number_format({}, {})", n, dp);
    }

    #[test]
    fn whole_numbers_group_by_thousands(n in any::<i64>()) {
        let s = staff::html::number(n);
        prop_assert!(grouped_well(&s), "{:?}", s);
        prop_assert_eq!(s.replace(',', ""), n.to_string());
    }

    /// PHP's is_numeric() and floatval() agree: a numeric string reads as itself.
    #[test]
    fn is_numeric_strings_read_back(s in prop_oneof![gen::text_of("0123456789.eE+- \t", 0..14), gen::text(30)]) {
        let numeric = within(PURE, "is_numeric", || staff::map::is_numeric(&s));
        let f = within(PURE, "floatval", || staff::map::floatval(&s));
        if numeric {
            let t = s.trim_matches([' ', '\t', '\n', '\r', '\x0b', '\x0c']);
            if let Ok(exact) = t.parse::<f64>() {
                prop_assert!(f == exact || (f.is_nan() && exact.is_nan()), "{:?}: {} vs {}", s, f, exact);
            }
        }
        within(PURE, "posted_number", || staff::check::posted_number(&s, 2));
    }

    #[test]
    fn floatval_reads_a_long_prefix_in_linear_time(piece in gen::text_of("0123456789.eE+-", 1..4), n in 0usize..20_000) {
        let s = piece.repeat(n);
        within(PURE, "floatval", || staff::map::floatval(&s));
        let v = catalog::php::Value::Str(s);
        within(PURE, "Value::float", || v.float());
        within(PURE, "Value::int", || v.int());
    }

    /// The two copies of the site's clock agree, and date() writes every letter it knows for any instant.
    #[test]
    fn detroit_clocks_agree(unix in -6_000_000_000i64..10_000_000_000, fmt in gen::text_of("djmnYyHGhgiasADlMFNw\\-/: x", 0..20)) {
        let a = staff::time::detroit(unix);
        let b = accounts::clock::detroit(unix);
        prop_assert_eq!((a.year, a.month, a.day, a.hour, a.minute), (i64::from(b.date.year), b.date.month, b.date.day, b.hour, b.minute), "{}", unix);
        within(PURE, "date", || staff::time::date(&fmt, unix));
        let back = staff::time::from_local(a.year, a.month, a.day, a.hour, a.minute, a.second);
        prop_assert!((back - unix).abs() <= 3600, "{} came back as {}", unix, back);
    }

    #[test]
    fn date_is_total(unix in any::<i64>(), fmt in gen::text_of("djmnYyHGhgiasADlMFNw\\", 0..12)) {
        within(PURE, "date", || staff::time::date(&fmt, unix));
        accounts::clock::detroit(unix);
    }

    #[test]
    fn typed_dates_read_back(y in 1900i64..2100, m in 1u32..13, d in 1u32..32, form in 0..3) {
        let typed = match form {
            0 => format!("{y}/{m}/{d}"),
            1 => format!("{y}-{m:02}-{d:02}"),
            _ => format!("{m}/{d}/{y}"),
        };
        let parsed = staff::time::parse_date(&typed);
        let real = chrono::NaiveDate::from_ymd_opt(y as i32, m, d).is_some();
        prop_assert_eq!(parsed, real.then_some((y, m, d)), "{}", typed);
        if let Some(date) = parsed {
            prop_assert_eq!(staff::time::add_days(staff::time::add_days(date, 400), -400), date);
        }
    }

    #[test]
    fn date_readers_are_total(s in prop_oneof![gen::text_of("0123456789/-. ", 0..30), gen::text(40)]) {
        within(PURE, "parse_date", || staff::time::parse_date(&s));
        within(PURE, "php_date", || catalog::model::php_date(&s));
    }
}
