//! Property tests over the code of hxweb that reads untrusted input: query, form, cookie and multipart parsing,
//! the path clean-up, WordPress routing, PHP's serialize format, import files, the escapers and markup builders,
//! the PHP number and date formats, mail building, and the whole router. Every property states what holds for any
//! input: no panic, an answer within a time bound, and the function's documented invariant.
//!
//! The tests live in `tests/fuzz/`; `cargo test --release` from this directory runs them. Runs are deterministic:
//! HX_FUZZ_SEED picks another seed, and HX_FUZZ_CASES sets the cases per property for a long run (the defaults
//! keep the whole crate well under a minute and a half).

use std::time::{Duration, Instant};

use proptest::test_runner::{Config, RngSeed};

pub mod alloc;
pub mod gen;

fn env_number(name: &str) -> Option<u64> {
    std::env::var(name).ok().and_then(|v| v.trim().parse().ok())
}

/// A property's configuration: `ci` cases by default, HX_FUZZ_CASES on a long run.
pub fn config(ci: u32) -> Config {
    heavy(ci, 1)
}

/// The same for a property whose case costs about `cost` cheap ones: a long run gives it HX_FUZZ_CASES / cost.
pub fn heavy(ci: u32, cost: u32) -> Config {
    shard(ci, cost, 0)
}

/// One of several runs of the same property, each from its own seed, so they can share the work across threads.
pub fn shard(ci: u32, cost: u32, n: u64) -> Config {
    let cases = env_number("HX_FUZZ_CASES").map_or(ci, |c| (c / u64::from(cost.max(1))).clamp(1, u64::from(u32::MAX)) as u32);
    Config {
        cases,
        rng_seed: RngSeed::Fixed(env_number("HX_FUZZ_SEED").unwrap_or(0x4858_6675_7a7a) ^ n.wrapping_mul(0x9e37_79b9_7f4a_7c15)),
        failure_persistence: None,
        max_shrink_iters: 4096,
        ..Config::default()
    }
}

/// Runs `f` and fails the case when it takes longer than `limit`. The limits are generous (a slow CI box, a
/// loaded build machine); they catch the quadratic and the endless, not the merely slow.
pub fn within<T>(limit: Duration, what: &str, f: impl FnOnce() -> T) -> T {
    let start = Instant::now();
    let out = f();
    let took = start.elapsed();
    assert!(took <= limit, "{what} took {took:?}, over its bound of {limit:?}");
    out
}

/// The bound for one call of a pure function on input of a size a request can carry.
pub const PURE: Duration = Duration::from_millis(250);

/// The start of a long input or output, for a failure message.
pub fn cut(s: &str) -> String {
    match s.char_indices().nth(300) {
        Some((i, _)) => format!("{:?}… ({} bytes)", &s[..i], s.len()),
        None => format!("{s:?}"),
    }
}
