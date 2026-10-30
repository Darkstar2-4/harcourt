//! Opaque bearer secrets (session cookies, remote grants). 256 random bits from the operating system; the database
//! only ever sees the SHA-256, so reading the table does not yield a usable credential.

use base64::{engine::general_purpose::URL_SAFE_NO_PAD, Engine};
use rand::{rngs::OsRng, RngCore};
use sha2::{Digest, Sha256};

const ENCODED_LEN: usize = 43;

pub struct Token(String);

pub struct TokenHash([u8; 32]);

impl Token {
    pub fn generate() -> Self {
        let mut bytes = [0u8; 32];
        OsRng.fill_bytes(&mut bytes);
        Token(URL_SAFE_NO_PAD.encode(bytes))
    }

    /// Anything that is not exactly what `generate` produces is rejected before it can reach a query.
    pub fn parse(s: &str) -> Option<Self> {
        let ok = s.len() == ENCODED_LEN && s.bytes().all(|c| c.is_ascii_alphanumeric() || c == b'-' || c == b'_');
        ok.then(|| Token(s.to_string()))
    }

    pub fn as_str(&self) -> &str {
        &self.0
    }

    pub fn hash(&self) -> TokenHash {
        TokenHash(Sha256::digest(self.0.as_bytes()).into())
    }
}

impl TokenHash {
    pub fn as_slice(&self) -> &[u8] {
        &self.0
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn generated_tokens_parse_and_differ() {
        let a = Token::generate();
        let b = Token::generate();
        assert!(Token::parse(a.as_str()).is_some());
        assert_ne!(a.as_str(), b.as_str());
        assert_ne!(a.hash().as_slice(), b.hash().as_slice());
    }

    #[test]
    fn malformed_tokens_do_not_parse() {
        for bad in ["", "short", &"a".repeat(44), &"a".repeat(42), &format!("{}'", "a".repeat(42)), &format!("{} ", "a".repeat(42))] {
            assert!(Token::parse(bad).is_none(), "{bad:?}");
        }
    }
}
