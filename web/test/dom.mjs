import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { JSDOM, VirtualConsole } from 'jsdom';

const require = createRequire(import.meta.url);

export const committed = (path) => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
export const jquerySource = () => readFileSync(require.resolve('jquery/dist/jquery.js'), 'utf8');

/** A page at the production origin. `errors` collects what jsdom reports, e.g. a navigation it cannot perform. */
export function page(html, url = 'https://www.harcourt.co/catalog/') {
  const errors = [];
  const virtualConsole = new VirtualConsole();
  virtualConsole.on('jsdomError', (e) => errors.push(e));
  const dom = new JSDOM(html, { url, runScripts: 'dangerously', pretendToBeVisual: true, virtualConsole });
  return { window: dom.window, errors };
}

/** Runs code the way a <script src> does: as a classic script, so top-level functions become globals. */
export function runScript(window, code) {
  const script = window.document.createElement('script');
  script.textContent = code;
  window.document.head.appendChild(script);
}

export const settle = (ms = 30) => new Promise((resolve) => setTimeout(resolve, ms));
