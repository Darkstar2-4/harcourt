# Staff pages, round five (p5-staff): expected differences from the oracle

Run `p5-staff.jsonl` with `--rust-session --jobs 1`; its cases depend on their order. The earlier rounds' files
(`staff.expected.md`, `staff2.expected.md`, `p2-staff.expected.md`, `p3-staff.expected.md`) still hold, except
where this file changes them.

## Changed since earlier rounds

- **Sign-in times follow the oracle.** The request that begins a visit, under the account's session or at its
  company address, stamps `last_login` and logs a sign-in, as login_update() did at the start of every PHP session.
  A persona's "Last Login" and "reCAPTCHA Score" rows on user.php, and its row in the dashboard's 7 Day Access
  tab, now match the oracle when both sides saw that persona's latest visit begin in the same minute. This
  replaces the "Persona sign-in times" notes in the earlier files.
- **Form tokens.** On user.php an update that its checks turn back shows the errors without `csrf`; one that would
  save still answers 403 without it. On subcategory.php an update with no record being edited in the session does
  nothing and shows the list, as the PHP did; with a draft to save it needs the token. A delete needs it on both.

## How the rig shows through

- **A warm session's first request.** hx-diff primes the oracle with a request that has no PHP session, which
  begins a visit there and stamps the persona's sign-in. It primes Rust with the oracle's session id, which Rust
  takes as a visit already under way (Rust keeps a visit only once something is stored for it), so it stamps
  nothing. A persona whose last cold case came before its priming shows a later Last Login on the oracle.

## Deliberate changes

### subcategory.php (`/subcategory.php`)

- **Inch marks in the Del? box.** The delete confirmation of an Inactive subcategory whose name holds `"` writes it
  as `\x22` in the onclick script. The PHP wrote `\"`, which ended the onclick attribute at that point, so the
  list of a category with such a name differs on that row.

### user.php (`/user.php`)

- **Empty status or type.** An update without a status or a type is marked in error on those fields. The PHP
  stored whatever was posted, so a form with other errors lists more of them here.
