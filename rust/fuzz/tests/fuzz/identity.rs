use hxweb::{access_code::normalise, identity::Email, retired::is_retired, token::Token};
use proptest::prelude::*;

const CODE_ALPHABET: &str = "23456789ABCDEFGHJKMNPQRSTUVWXYZ";
const TOKEN_ALPHABET: &str = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_";

fn code() -> impl Strategy<Value = String> {
    proptest::collection::vec(proptest::sample::select(CODE_ALPHABET.chars().collect::<Vec<_>>()), 8)
        .prop_map(|v| v.into_iter().collect())
}

fn token_text() -> impl Strategy<Value = String> {
    proptest::collection::vec(proptest::sample::select(TOKEN_ALPHABET.chars().collect::<Vec<_>>()), 43)
        .prop_map(|v| v.into_iter().collect())
}

proptest! {
    #![proptest_config(ProptestConfig::with_cases(4096))]

    #[test]
    fn email_parse_is_total_and_its_output_is_canonical(s in any::<String>()) {
        if let Some(e) = Email::parse(&s) {
            let canonical = s.trim().to_ascii_lowercase();
            prop_assert_eq!(e.as_str(), canonical.as_str());
            prop_assert_eq!(e.as_str().matches('@').count(), 1);
            let (local, domain) = e.as_str().split_once('@').unwrap();
            prop_assert!(!local.is_empty() && local.len() <= 64);
            prop_assert!(domain.contains('.') && !domain.starts_with('.') && !domain.ends_with('.') && !domain.contains(".."));
            prop_assert!(domain.len() <= 80);
            prop_assert!(e.as_str().bytes().all(|b| b.is_ascii_graphic()));
            prop_assert_eq!(e.domain(), domain);
            prop_assert_eq!(Email::parse(e.as_str()), Some(e.clone()));
        }
    }

    #[test]
    fn well_formed_addresses_parse_whatever_their_case_and_padding(
        local in "[a-z0-9._%+-]{1,64}",
        labels in proptest::collection::vec("[a-z0-9-]{1,15}", 2..5),
        pad in "[ \t]{0,3}",
    ) {
        let domain = labels.join(".");
        prop_assume!(domain.len() <= 80);
        let address = format!("{local}@{domain}");
        let shouted = format!("{pad}{}{pad}", address.to_ascii_uppercase());
        prop_assert_eq!(Email::parse(&shouted).map(|e| e.as_str().to_string()), Some(address));
    }

    #[test]
    fn token_parse_accepts_exactly_the_generated_shape(s in any::<String>()) {
        let shape = s.len() == 43 && s.bytes().all(|b| b.is_ascii_alphanumeric() || b == b'-' || b == b'_');
        prop_assert_eq!(Token::parse(&s).is_some(), shape);
    }

    #[test]
    fn tokens_round_trip_and_one_bad_byte_breaks_them(t in token_text(), at in 0usize..43, bad in "[^A-Za-z0-9_-]") {
        let parsed = Token::parse(&t).expect("43 alphabet characters");
        prop_assert_eq!(parsed.as_str(), t.as_str());
        let mut broken: Vec<char> = t.chars().collect();
        broken[at] = bad.chars().next().unwrap();
        prop_assert!(Token::parse(&broken.into_iter().collect::<String>()).is_none());
    }

    #[test]
    fn normalise_is_total_and_yields_only_codes(s in any::<String>()) {
        if let Some(c) = normalise(&s) {
            prop_assert_eq!(c.len(), 8);
            prop_assert!(c.chars().all(|ch| CODE_ALPHABET.contains(ch)));
            prop_assert_eq!(normalise(&c), Some(c.clone()));
        }
    }

    #[test]
    fn typed_codes_survive_case_spaces_and_hyphens(c in code(), seps in proptest::collection::vec("[ \t-]{0,2}", 9), lower in any::<bool>()) {
        let mut typed = String::new();
        for (i, ch) in c.chars().enumerate() {
            typed.push_str(&seps[i]);
            typed.push(if lower { ch.to_ascii_lowercase() } else { ch });
        }
        typed.push_str(&seps[8]);
        prop_assert_eq!(normalise(&typed), Some(c));
    }

    #[test]
    fn is_retired_is_total_and_never_fires_without_the_name(s in any::<String>()) {
        let hit = is_retired(&s);
        let decoded = percent_encoding::percent_decode_str(&s).decode_utf8_lossy().to_ascii_lowercase();
        if !decoded.contains("scs_remote.php") {
            prop_assert!(!hit);
        }
    }

    #[test]
    fn the_retired_name_is_caught_in_every_spelling(
        before in proptest::collection::vec("[a-z0-9_]{1,8}|\\.|", 0..4),
        after in proptest::collection::vec("[a-z0-9_]{1,8}", 0..3),
        upper in proptest::collection::vec(any::<bool>(), 14),
        encode in proptest::collection::vec(any::<bool>(), 14),
    ) {
        let name: String = "scs_remote.php"
            .chars()
            .zip(upper.iter().zip(&encode))
            .map(|(ch, (&up, &enc))| {
                let ch = if up { ch.to_ascii_uppercase() } else { ch };
                if enc { format!("%{:02X}", ch as u32) } else { ch.to_string() }
            })
            .collect();
        let mut path = String::from("/");
        for seg in &before {
            path.push_str(seg);
            path.push('/');
        }
        path.push_str(&name);
        for seg in &after {
            path.push('/');
            path.push_str(seg);
        }
        prop_assert!(is_retired(&path), "{}", path);
    }
}
