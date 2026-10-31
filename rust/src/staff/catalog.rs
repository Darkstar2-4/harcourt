//! PHP's reading of a number from text, which the staff forms, the import files and the catalog share.

/// floatval(): the leading number of the text, 0 when there is none.
pub fn php_floatval(s: &str) -> f64 {
    let s = s.trim_start();
    let b = s.as_bytes();
    let mut end = usize::from(matches!(b.first(), Some(b'+' | b'-')));
    let digits = |from: usize| b[from..].iter().take_while(|c| c.is_ascii_digit()).count();
    end += digits(end);
    if b.get(end) == Some(&b'.') {
        end += 1 + digits(end + 1);
    }
    if matches!(b.get(end), Some(b'e' | b'E')) {
        let sign = usize::from(matches!(b.get(end + 1), Some(b'+' | b'-')));
        let exp = digits(end + 1 + sign);
        if exp > 0 {
            end += 1 + sign + exp;
        }
    }
    s[..end].parse().unwrap_or(0.0)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn floatval_reads_the_leading_number() {
        assert_eq!(php_floatval(" 12.5abc"), 12.5);
        assert_eq!(php_floatval("1e3"), 1000.0);
        assert_eq!(php_floatval("abc"), 0.0);
        assert_eq!(php_floatval("-.5"), -0.5);
    }
}
