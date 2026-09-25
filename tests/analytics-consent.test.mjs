// Run: node --test tests/analytics-consent.test.mjs
// (tests/smoke.php runs it too when node is on PATH.)
//
// The Analytics module's head bootstrap decides whether a page_view counts.
// Before 0.19.3-alpha it applied a stored Accept after gtag('config'), and
// config sends the page_view, so every page_view went out denied, even for
// visitors who had accepted, and GA4 dropped them. These tests execute the
// shipped scripts and pin the order plus the opt-in rules.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const ASSETS = new URL('../inc/modules/analytics/assets/', import.meta.url);
const BOOTSTRAP = readFileSync(new URL('consent-bootstrap.js', ASSETS), 'utf8');
const BANNER = readFileSync(new URL('banner.js', ASSETS), 'utf8');

function page({ cookie = '', dnt = null, gpc = undefined, id = 'G-TEST123', respectDnt = true } = {}) {
  const attrs = {};
  const window = { perditaAnalytics: { id, respectDnt }, location: { protocol: 'https:' } };
  window.navigator = { doNotTrack: dnt, globalPrivacyControl: gpc };
  const document = {
    cookie,
    readyState: 'complete',
    documentElement: {
      setAttribute: (k, v) => (attrs[k] = v),
      getAttribute: (k) => (k in attrs ? attrs[k] : null),
    },
  };
  const context = { window, navigator: window.navigator, document, Date };
  vm.createContext(context);
  vm.runInContext(BOOTSTRAP, context);
  // JSON round trip: objects from the vm realm fail deepStrictEqual otherwise.
  const calls = () => JSON.parse(JSON.stringify((window.dataLayer ?? []).map((args) => Array.from(args))));
  return { context, window, document, attrs, calls };
}

const consent = (calls, kind) => calls.filter((c) => c[0] === 'consent' && c[1] === kind).map((c) => c[2]);
const indexOf = (calls, pred) => calls.findIndex(pred);

function grantsAds(calls) {
  return calls.some(
    (c) => c[0] === 'consent' && ['ad_storage', 'ad_user_data', 'ad_personalization'].some((k) => c[2][k] === 'granted'),
  );
}

// A minimal banner DOM for banner.js: two buttons and a keydown listener.
function withBanner(p) {
  const handlers = {};
  const hidden = { value: true };
  const button = (choice) => ({
    addEventListener: (type, fn) => (handlers[choice] = fn),
  });
  const buttons = { granted: button('granted'), denied: button('denied') };
  const banner = {
    setAttribute: (k) => k === 'hidden' && (hidden.value = true),
    removeAttribute: (k) => k === 'hidden' && (hidden.value = false),
    querySelector: (sel) => (sel.includes('granted') ? buttons.granted : buttons.denied),
    addEventListener: (type, fn) => (handlers[type] = fn),
  };
  p.document.getElementById = (id) => (id === 'perdita-consent-banner' ? banner : null);
  p.document.addEventListener = () => {};
  vm.runInContext(BANNER, p.context);
  return { handlers, hidden };
}

test('a stored Accept is applied before config sends the page_view', () => {
  const { calls, attrs } = page({ cookie: 'foo=1; perdita_consent=granted' });
  const c = calls();
  const defaultAt = indexOf(c, (x) => x[0] === 'consent' && x[1] === 'default');
  const updateAt = indexOf(c, (x) => x[0] === 'consent' && x[1] === 'update');
  const configAt = indexOf(c, (x) => x[0] === 'config');
  assert.ok(defaultAt >= 0 && updateAt > defaultAt, 'default, then the stored choice');
  assert.ok(configAt > updateAt, 'the stored choice must be in place before config');
  assert.equal(consent(c, 'update')[0].analytics_storage, 'granted');
  assert.equal(attrs['data-perdita-consent'], 'granted');
});

test('a fresh visitor stays denied (opt-in), and config still runs once', () => {
  const { calls, attrs } = page();
  const c = calls();
  assert.equal(consent(c, 'default')[0].analytics_storage, 'denied');
  assert.equal(consent(c, 'update').length, 0);
  assert.deepEqual(c.filter((x) => x[0] === 'config'), [['config', 'G-TEST123']]);
  assert.equal(attrs['data-perdita-consent'], 'unset');
});

test('a stored Decline stays denied', () => {
  const { calls, attrs } = page({ cookie: 'perdita_consent=denied' });
  assert.equal(consent(calls(), 'update').length, 0);
  assert.equal(attrs['data-perdita-consent'], 'denied');
});

test('Global Privacy Control outranks a stored Accept, even with DNT respect off', () => {
  const { calls, attrs } = page({ cookie: 'perdita_consent=granted', gpc: true, respectDnt: false });
  assert.equal(consent(calls(), 'update').length, 0);
  assert.equal(attrs['data-perdita-consent'], 'dnt');
});

test('Do Not Track outranks a stored Accept when the site respects it', () => {
  const on = page({ cookie: 'perdita_consent=granted', dnt: '1' });
  assert.equal(consent(on.calls(), 'update').length, 0);
  assert.equal(on.attrs['data-perdita-consent'], 'dnt');

  const off = page({ cookie: 'perdita_consent=granted', dnt: '1', respectDnt: false });
  assert.equal(consent(off.calls(), 'update')[0].analytics_storage, 'granted');
});

test('ad storage is never granted, by a stored Accept or by the banner', () => {
  const stored = page({ cookie: 'perdita_consent=granted' });
  assert.ok(!grantsAds(stored.calls()));
  const d = consent(stored.calls(), 'default')[0];
  assert.equal(d.ad_storage, 'denied');
  assert.equal(d.ad_user_data, 'denied');
  assert.equal(d.ad_personalization, 'denied');

  const fresh = page();
  withBanner(fresh).handlers.granted();
  assert.ok(!grantsAds(fresh.calls()));
});

test('clicking Accept grants analytics and resends this page_view', () => {
  const p = page();
  const { handlers, hidden } = withBanner(p);
  assert.equal(hidden.value, false, 'banner shows for a fresh visitor');
  const before = p.calls().length;
  handlers.granted();
  const after = p.calls().slice(before);
  assert.deepEqual(after, [
    ['consent', 'update', { analytics_storage: 'granted' }],
    ['event', 'page_view'],
  ]);
  assert.match(p.document.cookie, /perdita_consent=granted/);
  assert.equal(hidden.value, true);
});

test('clicking Decline keeps analytics off and sends no page_view', () => {
  const p = page();
  const { handlers } = withBanner(p);
  const before = p.calls().length;
  handlers.denied();
  const after = p.calls().slice(before);
  assert.deepEqual(after, [['consent', 'update', { analytics_storage: 'denied' }]]);
  assert.match(p.document.cookie, /perdita_consent=denied/);
});

test('the banner stays hidden under a privacy signal or a stored choice', () => {
  assert.equal(withBanner(page({ gpc: true })).hidden.value, true);
  assert.equal(withBanner(page({ cookie: 'perdita_consent=granted' })).hidden.value, true);
});

test('no measurement ID means no tracking at all', () => {
  assert.equal(page({ id: '' }).calls().length, 0);
});

test('a malformed consent cookie reads as no choice', () => {
  const { calls, attrs } = page({ cookie: 'perdita_consent=%E0%A4%A' });
  assert.equal(consent(calls(), 'update').length, 0);
  assert.equal(attrs['data-perdita-consent'], 'unset');
});
