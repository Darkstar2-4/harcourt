//! The input checks of includes/functions.php that staff forms share, with the PHP's messages.

/// email_class: the address lower-cased and trimmed, and what is wrong with it. Blank is fine unless `required`.
pub fn email(input: &str, required: bool) -> (String, Option<&'static str>) {
    let address = input.trim().to_lowercase();
    let error = email_error(&address, required);
    (address, error)
}

fn email_error(address: &str, required: bool) -> Option<&'static str> {
    if address.is_empty() {
        return required.then_some("Email address cannot be blank");
    }
    if address.contains([' ', '/', '\\', ',', '?', '!', ':', ';']) {
        return Some("Address contains invalid characters");
    }
    let parts: Vec<&str> = address.split('@').collect();
    if parts.len() != 2 {
        return Some("Invalid email format");
    }
    let (account, domain) = (parts[0], parts[1]);
    if account.is_empty() {
        return Some("Account missing");
    }
    let labels: Vec<&str> = domain.split('.').collect();
    if domain.is_empty() || labels[0].is_empty() || labels.last().map_or(0, |l| l.len()) < 2 {
        return Some("Invalid domain");
    }
    let word = |s: &str, extra: &[u8]| !s.is_empty() && s.bytes().all(|b| b.is_ascii_alphanumeric() || b == b'-' || extra.contains(&b));
    let tld = labels.last().copied().unwrap_or("");
    let ok = account.split('.').all(|w| word(w, b"_"))
        && labels.len() >= 2
        && labels.iter().all(|l| word(l, b""))
        && tld.len() >= 2
        && tld.bytes().all(|b| b.is_ascii_alphabetic());
    (!ok).then_some("Email format error")
}

/// fn_text(): posted text trimmed, with each line's `<script` … `>` run removed as the PHP's
/// `/(<script)(.*)(>)/i` removed it.
pub fn posted_text(input: &str) -> String {
    let mut out = String::with_capacity(input.len());
    for line in input.split_inclusive('\n') {
        let start = line.to_ascii_lowercase().find("<script");
        let end = start.and_then(|s| line[s..].rfind('>').map(|e| s + e + 1));
        match (start, end) {
            (Some(s), Some(e)) => {
                out.push_str(&line[..s]);
                out.push_str(&line[e..]);
            }
            _ => out.push_str(line),
        }
    }
    out.trim().to_string()
}

/// verify_file(): a site path of the given type. Blank is fine; anything else must be `/….type`.
pub fn site_file(path: &str, kind: &str) -> Option<String> {
    if path.is_empty() {
        return None;
    }
    if !path.to_ascii_lowercase().ends_with(&format!(".{kind}")) {
        return Some(format!("{path} not type {kind}"));
    }
    if !path.starts_with('/') {
        return Some(format!("{path} must begin with /"));
    }
    None
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn email_messages_match_the_php() {
        assert_eq!(email(" Jo@Example.com ", false), ("jo@example.com".into(), None));
        assert_eq!(email("", false).1, None);
        assert_eq!(email("", true).1, Some("Email address cannot be blank"));
        assert_eq!(email("a b@x.com", false).1, Some("Address contains invalid characters"));
        assert_eq!(email("ab.x.com", false).1, Some("Invalid email format"));
        assert_eq!(email("@x.com", false).1, Some("Account missing"));
        assert_eq!(email("a@x.c", false).1, Some("Invalid domain"));
        assert_eq!(email("a@x.c0m", false).1, Some("Email format error"));
        assert_eq!(email("first.last@sub.example.org", false).1, None);
    }

    #[test]
    fn site_files_must_be_rooted_and_typed() {
        assert_eq!(site_file("", "css"), None);
        assert_eq!(site_file("/a/b.css", "css"), None);
        assert_eq!(site_file("a.css", "css").as_deref(), Some("a.css must begin with /"));
        assert_eq!(site_file("/a.js", "css").as_deref(), Some("/a.js not type css"));
    }
}
