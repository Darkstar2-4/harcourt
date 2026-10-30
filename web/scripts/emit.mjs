// Copies the compiled scripts to the paths the pages already load them from. `--check` fails when the committed
// copy is not what the current TypeScript compiles to.
import { readFileSync, writeFileSync } from 'node:fs';

const targets = [
  { built: 'build/hx-nav.js', committed: '../hx/hx-nav.js', source: 'src/hx-nav.ts' },
  { built: 'build/scscpq_wp.js', committed: '../scs_scripts/scscpq_wp.js', source: 'src/scscpq_wp.ts' },
];
const check = process.argv.includes('--check');
let drift = false;

for (const { built, committed, source } of targets) {
  const output = `/* Generated from web/${source} by "npm run build" in web/. Edit the TypeScript, not this file. */\n${readFileSync(built, 'utf8')}`;
  if (!check) {
    writeFileSync(committed, output);
  } else if (readFileSync(committed, 'utf8') !== output) {
    console.error(`${committed} is not what web/${source} compiles to; run "npm run build" in web/`);
    drift = true;
  }
}
process.exit(drift ? 1 : 0);
