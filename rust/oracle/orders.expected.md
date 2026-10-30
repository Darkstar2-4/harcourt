# Orders: differences from the oracle on purpose

Cases that would only show these are left out of `orders.jsonl` and `orders-forms.jsonl`.

- **Values in text are escaped.** `<` and `>` in an order, carrier or cart value are written as `&lt;` and `&gt;`. The PHP printed them raw, so a stored value could add markup to a staff page.
- **The cart is stored per visit, in Rust's database.** PHP kept it in its own session. A session sign-in keys it by the session; a company-address sign-in by the PHP session id with the account. A cart filled on one side only is not on the other, so cases that fill a cart are left out.
- **Staff pages need an Active account.** PHP's `access()` read the role alone, so a Pending or Denied Executive could open the PO Manager. Rust sends them home.
- **Checkout prefill.** No `harcourt_ecommerce` cookie is written or read. The form is prefilled from the account's last order under the same signed-in address; without one, as the PHP did without that cookie, from the `harcourt` cookie and the account's company.
- **PO Manager `return_url`.** It is followed only when it is a path on this site.
- **Carrier delete.** Only an inactive carrier can be deleted, which is all the list offers.
- **Form islands, honeypot label.** The label is always `Comments`. Gravity Forms picks one at random per render, so two oracle renders also differ there.
- **Form islands, `gform_ajax` hash.** The `hash=` part of form 1's hidden `gform_ajax` value is a digest of the fixed attributes, not WordPress's salted signature. Nothing in Rust reads it back; hx-diff's gform-ajax rule forgives the value.
- **Form posts.** The anti-spam token is the signed `state_N` value. A post without a fresh one is stored as spam. Posts are not in the corpus: a real Gravity Forms post needs the per-render state and hash from the same render.
- **reCAPTCHA.** When `HXWEB_RECAPTCHA_SECRET` is unset the check is skipped. The PHP blocked every contact form without its key.

How the forms corpus runs: the oracle's island endpoint is `/?hx_form=N&hx_lang=..&hx_page=..` on Apache (`ORACLE_LEGACY`; nginx refuses it from outside). The Rust one is `/hx/forms/island/`. hx-diff takes origins only, so a local proxy that maps `/` to `/hx/forms/island/` stands in for the Rust side.
