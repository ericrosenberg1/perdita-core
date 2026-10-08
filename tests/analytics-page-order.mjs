// Run by tests/smoke-analytics-order.php: node tests/analytics-page-order.mjs <page.html> [cookie]
//
// Executes every inline script of a rendered page, in document order, the way
// a browser would before any remote script arrives, and prints the dataLayer
// as JSON. Remote scripts (gtag.js) are skipped: they only drain the queue in
// the order these scripts filled it, so the queue order is what GA4 sees.
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const html = readFileSync(process.argv[2], 'utf8');
const cookie = process.argv[3] ?? '';

const jar = new Map(
  cookie
    .split(';')
    .map((c) => c.trim())
    .filter(Boolean)
    .map((c) => [c.slice(0, c.indexOf('=')), c.slice(c.indexOf('=') + 1)]),
);
const context = {
  navigator: { doNotTrack: null, globalPrivacyControl: false },
  location: { protocol: 'https:', pathname: '/', hostname: 'example.test' },
  document: {
    get cookie() {
      return [...jar].map(([k, v]) => `${k}=${v}`).join('; ');
    },
    set cookie(v) {
      const pair = v.split(';')[0];
      jar.set(pair.slice(0, pair.indexOf('=')), pair.slice(pair.indexOf('=') + 1));
    },
    readyState: 'loading',
    documentElement: { setAttribute() {}, getAttribute: () => null },
    addEventListener() {},
    getElementById: () => null,
    querySelectorAll: () => [],
  },
  Date,
  RegExp,
  JSON,
};
context.window = context;
vm.createContext(context);

const scripts = [...html.matchAll(/<script\b([^>]*)>([\s\S]*?)<\/script>/gi)];
let ran = 0;
for (const [, attrs, body] of scripts) {
  if (/\bsrc\s*=/.test(attrs)) continue;
  const type = (attrs.match(/\btype\s*=\s*["']?([^"'\s>]+)/i) || [])[1];
  if (type && !/^(text\/javascript|module)$/i.test(type)) continue;
  if (!body.trim()) continue;
  vm.runInContext(body, context);
  ran++;
}
const dl = (context.dataLayer ?? []).map((a) => Array.from(a));
process.stdout.write(JSON.stringify({ ran, calls: JSON.parse(JSON.stringify(dl)) }));
