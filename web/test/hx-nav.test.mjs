import assert from 'node:assert/strict';
import test from 'node:test';
import { committed, page, runScript, settle } from './dom.mjs';

const code = committed('hx/hx-nav.js');
const SUB = '/catalog/?action=subcategory&subcategory_id=5';

const SHELL = `<!doctype html><html lang="en"><head></head><body>
<ul><li class="wpml-ls-item wpml-ls-item-en"><a href="/catalog/">EN</a></li><li class="wpml-ls-item wpml-ls-item-fr"><a href="/catalogue/">FR</a></li></ul>
<div id="scscpq_page"><a id="sub" href="${SUB.replace('&', '&amp;')}">Sub</a>
<a id="home" href="/catalog/">Home</a><a id="ext" href="https://other.example/catalog/?action=sku">Ext</a>
<a id="fr" href="/catalogue/?action=subcategory&amp;subcategory_id=5">Fr</a><a id="hash" href="#top">Top</a></div></body></html>`;

function setup(replies) {
  const { window, errors } = page(SHELL);
  const fetches = [];
  const scrolls = [];
  window.fetch = async (url, init) => {
    fetches.push({ url, init });
    const reply = replies.shift() ?? { body: { reload: true } };
    return { ok: reply.ok ?? true, status: reply.status ?? 200, json: async () => reply.body };
  };
  window.scrollTo = (x, y) => scrolls.push([x, y]);
  runScript(window, code);
  return { window, errors, fetches, scrolls };
}

function click(window, id, init = {}) {
  const event = new window.MouseEvent('click', { bubbles: true, cancelable: true, button: 0, ...init });
  window.document.getElementById(id).dispatchEvent(event);
  return event;
}

const view = { body: { html: '<p id="new">NEW</p>', flags: { en: `${SUB}&hxlang=en`, fr: '/catalogue/?x&hxlang=fr' } } };

test('a catalog link swaps the catalog in place and keeps the address in step', async () => {
  const { window, fetches } = setup([view]);
  const event = click(window, 'sub');
  assert.equal(event.defaultPrevented, true);
  await settle();
  assert.equal(fetches.length, 1);
  assert.equal(fetches[0].url, SUB);
  assert.equal(fetches[0].init.headers['X-HX-Nav'], '1');
  assert.ok(window.document.getElementById('new'), 'new markup is in the page');
  assert.equal(window.document.getElementById('sub'), null, 'old view was taken out');
  assert.equal(window.location.pathname + window.location.search, SUB);
  assert.equal(window.document.documentElement.getAttribute('data-hx-url'), SUB);
  assert.equal(window.document.querySelector('li.wpml-ls-item-fr > a').getAttribute('href'), '/catalogue/?x&hxlang=fr');
});

test('other links, other sites, the other language and modified clicks are left to the browser', async () => {
  const { window, fetches } = setup([view]);
  for (const id of ['home', 'ext', 'fr', 'hash']) assert.equal(click(window, id).defaultPrevented, false, id);
  for (const init of [{ ctrlKey: true }, { metaKey: true }, { shiftKey: true }, { altKey: true }, { button: 1 }]) {
    assert.equal(click(window, 'sub', init).defaultPrevented, false, JSON.stringify(init));
  }
  await settle();
  assert.equal(fetches.length, 0);
});

test('Back puts the earlier view back, scroll position included', async () => {
  const { window, scrolls } = setup([view]);
  const original = window.document.getElementById('sub');
  click(window, 'sub');
  await settle();
  window.history.replaceState({ hx: '/catalog/', y: 7 }, '', '/catalog/');
  window.hxPopstate({ state: { hx: '/catalog/', y: 7 } });
  assert.equal(window.document.getElementById('sub'), original, 'the same node, not a rebuild');
  assert.equal(window.document.getElementById('new'), null);
  assert.deepEqual(scrolls.at(-1), [0, 7]);
  assert.equal(window.document.querySelector('li.wpml-ls-item-en > a').getAttribute('href'), '/catalog/');
});

test('an answer that arrives after the visitor moved on is dropped', async () => {
  const { window } = setup([]);
  let release;
  window.fetch = () => new Promise((resolve) => (release = () => resolve({ ok: true, json: async () => view.body })));
  click(window, 'sub');
  window.hxPopstate({ state: { y: 0 } });
  release();
  await settle();
  assert.equal(window.document.getElementById('new'), null);
  assert.ok(window.document.getElementById('sub'));
});

test('a failed request falls back to a normal page load', async () => {
  const { window, errors } = setup([{ ok: false, status: 500, body: {} }]);
  click(window, 'sub');
  await settle();
  assert.equal(window.document.getElementById('new'), null);
  assert.ok(window.document.getElementById('sub'), 'the old view is still there');
  assert.ok(errors.some((e) => /navigation/i.test(e.message)), 'a page load was started');
});

test('a reload reply or a malformed one swaps nothing and leaves the address on the target, which then loads', async () => {
  for (const reply of [{ body: { reload: true } }, { body: { html: 42 } }]) {
    const { window } = setup([reply]);
    click(window, 'sub');
    await settle();
    assert.equal(window.document.getElementById('new'), null, JSON.stringify(reply));
    assert.ok(window.document.getElementById('sub'), JSON.stringify(reply));
    assert.equal(window.location.pathname + window.location.search, SUB, JSON.stringify(reply));
  }
});

test('hxGo takes swappable addresses only', async () => {
  const { window, fetches } = setup([view]);
  assert.equal(window.hxGo('/catalog/'), false);
  assert.equal(window.hxGo('https://other.example/catalog/?action=sku'), false);
  assert.equal(window.hxGo(SUB), true);
  await settle();
  assert.equal(fetches.length, 1);
});
