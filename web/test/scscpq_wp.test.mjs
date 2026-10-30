import assert from 'node:assert/strict';
import test from 'node:test';
import { committed, jquerySource, page, runScript, settle } from './dom.mjs';

const loader = committed('scs_scripts/scscpq_wp.js');
const ORIGIN = 'https://www.harcourt.co';

/** jQuery and the loader in a page whose hxweb answer is already inlined, so the loader makes no request. */
function setup(body, { before } = {}) {
  const { window } = page(`<!doctype html><html lang="en"><body>${body}</body></html>`);
  window.hxweb_loader = {};
  runScript(window, jquerySource());
  before?.(window);
  runScript(window, loader);
  return window;
}

const message = (window, data, origin) =>
  window.dispatchEvent(new window.MessageEvent('message', { data, origin }));

test('a message is acted on only from this site', () => {
  const window = setup('<div id="cart">old</div><iframe id="frame"></iframe>');
  const cart = window.document.getElementById('cart');
  const frame = window.document.getElementById('frame');

  message(window, { scscpq: 'cart_size', id: 'cart', data: '<b>x</b>' }, 'https://evil.example');
  message(window, { scscpq: 'resize', id: 'frame', data: 900 }, 'https://evil.example');
  message(window, { scscpq: 'cart_size', id: 'cart', data: '<b>x</b>' }, 'null');
  assert.equal(cart.innerHTML, 'old');
  assert.equal(frame.style.height, '');

  message(window, { scscpq: 'cart_size', id: 'cart', data: '<b>3</b>' }, ORIGIN);
  message(window, { scscpq: 'resize', id: 'frame', data: 300 }, ORIGIN);
  assert.equal(cart.innerHTML, '<b>3</b>');
  assert.equal(frame.style.height, '340px');
});

test('malformed messages from this site are ignored', () => {
  const window = setup('<div id="cart">old</div>');
  for (const data of ['text', null, 7, { scscpq: 'cart_size' }, { scscpq: 'cart_size', id: 5 }, { scscpq: 'nope', id: 'cart' }, { id: 'cart' }]) {
    message(window, data, ORIGIN);
  }
  assert.equal(window.document.getElementById('cart').innerHTML, 'old');
});

test('scscpq_ipc names this site as the target', () => {
  const window = setup('');
  const sent = [];
  window.postMessage = (data, target) => sent.push([data, target]);
  window.scscpq_ipc('frame', 'resize', 120);
  assert.equal(sent.length, 1);
  assert.equal(sent[0][1], ORIGIN);
  assert.equal(sent[0][0].scscpq, 'resize');
  assert.equal(sent[0][0].id, 'frame');
  assert.equal(sent[0][0].data, 120);
});

test('the loader answer shows, hides and fills blocks and keeps the token', () => {
  const window = setup('<div class="scscpq_cart">c</div><div class="scscpq_newuser" style="display:none">n</div><div class="scscpq_search">s</div>');
  window.scscpq_apply({ token: 't1', scscpq_cart: 0, scscpq_newuser: 1, scscpq_search: '<i>hi</i>', unrelated: 5 }, true);
  const $ = (selector) => window.document.querySelector(selector);
  assert.equal($('.scscpq_cart').style.display, 'none');
  assert.notEqual($('.scscpq_newuser').style.display, 'none');
  assert.equal($('.scscpq_newuser').textContent, 'n');
  assert.equal($('.scscpq_search').innerHTML, '<i>hi</i>');
  assert.equal(window.sessionStorage.getItem('scscpq_token'), 't1');
});

test('a link already loaded inline is not fetched again', () => {
  const window = setup('<div id="scscpq_page">kept</div>');
  const loads = [];
  window.jQuery.fn.load = function (url) {
    loads.push(url);
    return this;
  };
  window.scscpq_apply({ scscpq_link: '/catalog.php?x=1' }, true);
  assert.deepEqual(loads, []);
  window.scscpq_apply({ scscpq_link: '/catalog.php?x=1' }, false);
  assert.deepEqual(loads, ['/catalog.php?x=1']);
});

test('flag links carry the language they stand for', async () => {
  const window = setup(`<ul>
    <li class="wpml-ls-item wpml-ls-item-fr"><a id="fr" href="/catalogue/?lang=fr#x">FR</a></li>
    <li class="wpml-ls-item wpml-ls-item-en"><a id="en" href="/catalog/">EN</a></li>
    <li class="wpml-ls-item wpml-ls-item-en"><a id="done" href="/catalog/?hxlang=en">EN</a></li>
    <li class="wpml-ls-item wpml-ls-item-en"><a id="hash" href="#">EN</a></li></ul>`);
  await settle();
  const href = (id) => window.document.getElementById(id).getAttribute('href');
  assert.equal(href('fr'), '/catalogue/?lang=fr&hxlang=fr#x');
  assert.equal(href('en'), '/catalog/?hxlang=en');
  assert.equal(href('done'), '/catalog/?hxlang=en');
  assert.equal(href('hash'), '#');
});

test('a failed request shows the server text as text', async () => {
  const hostile = '<img src=x onerror=alert(1)>';
  const { window } = page('<!doctype html><html lang="en"><body><div id="scscpq_page">p</div><div id="scscpq_status"></div></body></html>');
  runScript(window, jquerySource());
  window.jQuery.ajax = (options) => options.error({ responseText: hostile });
  runScript(window, loader);
  await settle();
  const status = window.document.getElementById('scscpq_status');
  assert.equal(status.children.length, 0);
  assert.equal(status.textContent, hostile);
});

test('ajax_fill sets inputs, checkboxes and text by field type', () => {
  const window = setup('<input id="ajax__name" type="text"><input id="ajax__ok" type="checkbox"><textarea id="ajax__memo"></textarea><span id="ajax__note"></span>');
  window.ajax_fill('', { name: 'Pat', ok: 1, memo: 'm', note: '<b>x</b>', missing: 'ignored' });
  const $ = (id) => window.document.getElementById(id);
  assert.equal($('ajax__name').value, 'Pat');
  assert.equal($('ajax__ok').checked, true);
  assert.equal($('ajax__memo').value, 'm');
  assert.equal($('ajax__note').innerHTML, '<b>x</b>');
});
