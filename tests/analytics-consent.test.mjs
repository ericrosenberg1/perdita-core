// Run: node --test tests/analytics-consent.test.mjs
// (tests/smoke.php runs it too when node is on PATH.)
//
// The Analytics module's head bootstrap decides whether a page_view counts.
// Before 0.19.3-alpha it applied a stored Accept after gtag('config'), and
// config sends the page_view, so every page_view went out denied, even for
// visitors who had accepted, and GA4 dropped them. These tests execute the
// shipped scripts and pin the order plus the consent rules: opt-in and
// opt-out defaults, privacy signals, the Simple Consent Manager cookie
// migration, consent-only operation, and the banner's controls.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const ASSETS = new URL('../inc/modules/analytics/assets/', import.meta.url);
const BOOTSTRAP = readFileSync(new URL('consent-bootstrap.js', ASSETS), 'utf8');
const BANNER = readFileSync(new URL('banner.js', ASSETS), 'utf8');

function page({
  cookie = '',
  dnt = null,
  gpc = undefined,
  id = 'G-TEST123',
  respectDnt = true,
  model = 'opt_in',
  adSignals = false,
} = {}) {
  const attrs = {};
  const window = { perditaAnalytics: { id, model, adSignals, respectDnt }, location: { protocol: 'https:' } };
  window.navigator = { doNotTrack: dnt, globalPrivacyControl: gpc };
  // A cookie jar that behaves like document.cookie: reads return every
  // name=value pair, writes set one.
  const jar = new Map(
    cookie
      .split(';')
      .map((c) => c.trim())
      .filter(Boolean)
      .map((c) => [c.slice(0, c.indexOf('=')), c.slice(c.indexOf('=') + 1)]),
  );
  const writes = [];
  const document = {
    get cookie() {
      return [...jar].map(([k, v]) => `${k}=${v}`).join('; ');
    },
    set cookie(v) {
      writes.push(v);
      const pair = v.split(';')[0];
      jar.set(pair.slice(0, pair.indexOf('=')), pair.slice(pair.indexOf('=') + 1));
    },
    readyState: 'complete',
    documentElement: {
      setAttribute: (k, v) => (attrs[k] = v),
      getAttribute: (k) => (k in attrs ? attrs[k] : null),
    },
  };
  const context = { window, navigator: window.navigator, document, Date, RegExp };
  vm.createContext(context);
  vm.runInContext(BOOTSTRAP, context);
  // JSON round trip: objects from the vm realm fail deepStrictEqual otherwise.
  const calls = () => JSON.parse(JSON.stringify((window.dataLayer ?? []).map((args) => Array.from(args))));
  return { context, window, document, attrs, calls, jar, writes };
}

const consent = (calls, kind) => calls.filter((c) => c[0] === 'consent' && c[1] === kind).map((c) => c[2]);
const indexOf = (calls, pred) => calls.findIndex(pred);
const ALL_FOUR = ['analytics_storage', 'ad_storage', 'ad_user_data', 'ad_personalization'];

function grantsAds(calls) {
  return calls.some(
    (c) => c[0] === 'consent' && ['ad_storage', 'ad_user_data', 'ad_personalization'].some((k) => c[2][k] === 'granted'),
  );
}

function el(extra = {}) {
  const attrs = { hidden: 'hidden' };
  const listeners = {};
  return {
    nodeType: 1,
    focused: false,
    classList: { contains: () => false },
    parentNode: null,
    setAttribute: (k, v) => (attrs[k] = v),
    removeAttribute: (k) => delete attrs[k],
    hasAttribute: (k) => k in attrs,
    getAttribute: (k) => (k in attrs ? attrs[k] : null),
    addEventListener: (type, fn) => (listeners[type] = fn),
    focus() {
      this.focused = true;
    },
    listeners,
    attrs,
    ...extra,
  };
}

// A minimal banner DOM for banner.js: the banner, its two buttons, the
// preferences button, and document-level click and keydown listeners.
function withBanner(p, { prefs = true } = {}) {
  const docListeners = {};
  const buttons = { granted: el(), denied: el() };
  const banner = el({
    querySelector: (sel) => (sel === 'button' ? buttons.denied : sel.includes('granted') ? buttons.granted : buttons.denied),
  });
  const prefsBtn = prefs ? el() : null;
  p.document.getElementById = (id) =>
    id === 'perdita-consent-banner' ? banner : id === 'perdita-consent-prefs' ? prefsBtn : null;
  p.document.addEventListener = (type, fn) => (docListeners[type] = fn);
  vm.runInContext(BANNER, p.context);
  const shown = (x) => !x.hasAttribute('hidden');
  const press = (key, defaultPrevented = false) => docListeners.keydown({ key, defaultPrevented });
  const click = (target) => {
    let prevented = false;
    docListeners.click({ target, preventDefault: () => (prevented = true) });
    return prevented;
  };
  return {
    banner,
    buttons,
    prefs: prefsBtn,
    bannerShown: () => shown(banner),
    prefsShown: () => !!prefsBtn && shown(prefsBtn),
    accept: () => buttons.granted.listeners.click(),
    decline: () => buttons.denied.listeners.click(),
    press,
    click,
  };
}

/* ---------- opt-in (the default) ---------- */

test('opt-in: a stored Accept is applied before config sends the page_view', () => {
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

test('opt-in: a fresh visitor stays denied, and config still runs once', () => {
  const { calls, attrs } = page();
  const c = calls();
  assert.deepEqual(consent(c, 'default')[0], {
    analytics_storage: 'denied',
    ad_storage: 'denied',
    ad_user_data: 'denied',
    ad_personalization: 'denied',
  });
  assert.equal(consent(c, 'update').length, 0);
  assert.deepEqual(c.filter((x) => x[0] === 'config'), [['config', 'G-TEST123']]);
  assert.equal(attrs['data-perdita-consent'], 'unset');
});

test('opt-in: a stored Decline stays denied', () => {
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

test('ad signals stay denied by default, by a stored Accept or by the banner, in both models', () => {
  for (const model of ['opt_in', 'opt_out']) {
    const stored = page({ cookie: 'perdita_consent=granted', model });
    assert.ok(!grantsAds(stored.calls()), model);
    const fresh = page({ model });
    withBanner(fresh).accept();
    assert.ok(!grantsAds(fresh.calls()), model);
  }
});

test('every consent command sets all four signals', () => {
  for (const opts of [{}, { cookie: 'perdita_consent=granted' }, { model: 'opt_out' }, { adSignals: true, cookie: 'perdita_consent=granted' }]) {
    const p = page(opts);
    const b = withBanner(p);
    b.accept();
    for (const c of p.calls().filter((x) => x[0] === 'consent')) {
      for (const k of ALL_FOUR) assert.ok(k in c[2], `${JSON.stringify(opts)} ${c[1]} is missing ${k}`);
    }
  }
});

test('ad signals follow analytics when the site turns them on', () => {
  const stored = page({ cookie: 'perdita_consent=granted', adSignals: true });
  const u = consent(stored.calls(), 'update')[0];
  for (const k of ALL_FOUR) assert.equal(u[k], 'granted', k);

  const gpc = page({ cookie: 'perdita_consent=granted', adSignals: true, gpc: true });
  assert.ok(!grantsAds(gpc.calls()), 'GPC keeps ad signals off too');

  const out = page({ model: 'opt_out', adSignals: true });
  for (const k of ALL_FOUR) assert.equal(consent(out.calls(), 'default')[0][k], 'granted', k);
});

test('opt-in: clicking Accept grants analytics and resends this page_view', () => {
  const p = page();
  const b = withBanner(p);
  assert.equal(b.bannerShown(), true, 'banner shows for a fresh visitor');
  assert.equal(b.prefsShown(), false);
  const before = p.calls().length;
  b.accept();
  assert.deepEqual(p.calls().slice(before), [
    ['consent', 'update', { analytics_storage: 'granted', ad_storage: 'denied', ad_user_data: 'denied', ad_personalization: 'denied' }],
    ['event', 'page_view'],
  ]);
  assert.match(p.document.cookie, /perdita_consent=granted/);
  assert.equal(b.bannerShown(), false);
  assert.equal(b.prefsShown(), true, 'the Cookie Preferences button appears once a choice is stored');
});

test('opt-in: clicking Decline keeps analytics off and sends no page_view', () => {
  const p = page();
  const b = withBanner(p);
  const before = p.calls().length;
  b.decline();
  assert.deepEqual(p.calls().slice(before), [
    ['consent', 'update', { analytics_storage: 'denied', ad_storage: 'denied', ad_user_data: 'denied', ad_personalization: 'denied' }],
  ]);
  assert.match(p.document.cookie, /perdita_consent=denied/);
});

test('the banner stays hidden under a privacy signal or a stored choice', () => {
  const gpc = withBanner(page({ gpc: true }));
  assert.equal(gpc.bannerShown(), false);
  assert.equal(gpc.prefsShown(), false, 'no preferences button under GPC either');
  const stored = withBanner(page({ cookie: 'perdita_consent=granted' }));
  assert.equal(stored.bannerShown(), false);
  assert.equal(stored.prefsShown(), true);
});

test('a malformed consent cookie reads as no choice', () => {
  const { calls, attrs } = page({ cookie: 'perdita_consent=%E0%A4%A' });
  assert.equal(consent(calls(), 'update').length, 0);
  assert.equal(attrs['data-perdita-consent'], 'unset');
});

/* ---------- opt-out ---------- */

test('opt-out: a fresh visitor starts granted, before config, with no update needed', () => {
  const p = page({ model: 'opt_out' });
  const c = p.calls();
  const d = consent(c, 'default')[0];
  assert.equal(d.analytics_storage, 'granted');
  assert.equal(d.ad_storage, 'denied');
  assert.ok(indexOf(c, (x) => x[0] === 'consent' && x[1] === 'default') < indexOf(c, (x) => x[0] === 'config'));
  assert.equal(consent(c, 'update').length, 0);
  assert.equal(p.attrs['data-perdita-consent'], 'unset');
});

test('opt-out: a stored opt out starts denied', () => {
  const p = page({ model: 'opt_out', cookie: 'perdita_consent=denied' });
  assert.equal(consent(p.calls(), 'default')[0].analytics_storage, 'denied');
  assert.equal(consent(p.calls(), 'update').length, 0);
});

test('opt-out: Global Privacy Control forces denied and hides the banner', () => {
  const p = page({ model: 'opt_out', gpc: true, respectDnt: false });
  assert.equal(consent(p.calls(), 'default')[0].analytics_storage, 'denied');
  assert.equal(consent(p.calls(), 'update').length, 0);
  assert.equal(p.attrs['data-perdita-consent'], 'dnt');
  assert.equal(withBanner(p).bannerShown(), false);

  const accepted = page({ model: 'opt_out', gpc: true, cookie: 'perdita_consent=granted' });
  assert.equal(consent(accepted.calls(), 'default')[0].analytics_storage, 'denied', 'even over a stored Accept');
});

test('opt-out: a respected Do Not Track forces denied, an ignored one does not', () => {
  assert.equal(consent(page({ model: 'opt_out', dnt: '1' }).calls(), 'default')[0].analytics_storage, 'denied');
  assert.equal(consent(page({ model: 'opt_out', dnt: '1', respectDnt: false }).calls(), 'default')[0].analytics_storage, 'granted');
});

test('opt-out: Opt out stores denied, Accept stores granted without a second page_view', () => {
  const p = page({ model: 'opt_out' });
  const b = withBanner(p);
  assert.equal(b.bannerShown(), true);
  const before = p.calls().length;
  b.accept();
  assert.deepEqual(
    p.calls().slice(before).map((c) => c[0] + ':' + (c[2]?.analytics_storage ?? c[1])),
    ['consent:granted'],
    'already granted, so the page_view went out counted and is not resent',
  );

  const q = page({ model: 'opt_out' });
  const bq = withBanner(q);
  bq.decline();
  assert.match(q.document.cookie, /perdita_consent=denied/);
  assert.equal(consent(q.calls(), 'update').at(-1).analytics_storage, 'denied');
});

test('opt-out: Escape closes without recording a choice', () => {
  const p = page({ model: 'opt_out' });
  const b = withBanner(p);
  b.press('Escape');
  assert.equal(b.bannerShown(), false);
  assert.equal(p.writes.length, 0, 'no cookie written');
  assert.equal(consent(p.calls(), 'update').length, 0);
});

/* ---------- Simple Consent Manager migration ---------- */

test('scm_consent=declined is honored and copied into perdita_consent', () => {
  for (const model of ['opt_in', 'opt_out']) {
    const p = page({ model, cookie: 'scm_consent=declined' });
    assert.equal(consent(p.calls(), 'default')[0].analytics_storage, 'denied', model);
    assert.equal(consent(p.calls(), 'update').length, 0, model);
    assert.equal(p.jar.get('perdita_consent'), 'denied', model);
    assert.match(p.writes[0], /Max-Age=31536000; Path=\/; SameSite=Lax; Secure/);
    assert.equal(p.attrs['data-perdita-consent'], 'denied');
    assert.equal(withBanner(p).bannerShown(), false, 'not asked again');
  }
});

test('scm_consent=accepted counts as an Accept, before config', () => {
  const p = page({ cookie: 'scm_consent=accepted' });
  const c = p.calls();
  assert.equal(consent(c, 'update')[0].analytics_storage, 'granted');
  assert.ok(indexOf(c, (x) => x[1] === 'update') < indexOf(c, (x) => x[0] === 'config'));
  assert.equal(p.jar.get('perdita_consent'), 'granted');
});

test('perdita_consent wins over scm_consent, and nothing is rewritten', () => {
  const p = page({ cookie: 'scm_consent=accepted; perdita_consent=denied' });
  assert.equal(consent(p.calls(), 'update').length, 0);
  assert.equal(p.writes.length, 0);
});

test('an unknown scm_consent value is ignored', () => {
  const p = page({ cookie: 'scm_consent=maybe' });
  assert.equal(p.writes.length, 0);
  assert.equal(p.attrs['data-perdita-consent'], 'unset');
});

test('GPC still wins over a migrated SCM Accept', () => {
  const p = page({ cookie: 'scm_consent=accepted', gpc: true });
  assert.equal(consent(p.calls(), 'update').length, 0);
  assert.equal(p.attrs['data-perdita-consent'], 'dnt');
});

/* ---------- consent-only operation (no measurement ID) ---------- */

test('no measurement ID: consent defaults still apply, and nothing loads or configures', () => {
  const p = page({ id: '', model: 'opt_out' });
  const c = p.calls();
  const d = consent(c, 'default')[0];
  assert.equal(d.analytics_storage, 'granted');
  assert.equal(d.wait_for_update, 500, 'other plugins\' tags may be waiting');
  assert.equal(c.filter((x) => x[0] === 'config' || x[0] === 'js').length, 0);
  assert.equal(typeof p.window.gtag, 'function', 'gtag is defined for the other tags');
});

test('no measurement ID, opt-in: a stored Accept still updates before any tag', () => {
  const p = page({ id: '', cookie: 'perdita_consent=granted' });
  assert.equal(consent(p.calls(), 'update')[0].analytics_storage, 'granted');
});

test('no measurement ID: Accept on the banner does not send a page_view of its own', () => {
  const p = page({ id: '' });
  const b = withBanner(p);
  b.accept();
  assert.equal(p.calls().filter((x) => x[0] === 'event').length, 0);
});

test('an existing gtag function is kept, not replaced', () => {
  const attrs = {};
  const seen = [];
  const window = { perditaAnalytics: { id: '', model: 'opt_out' }, location: { protocol: 'https:' }, navigator: {} };
  window.gtag = (...a) => seen.push(a);
  const document = { cookie: '', documentElement: { setAttribute: (k, v) => (attrs[k] = v) } };
  const ctx = { window, navigator: window.navigator, document, Date, RegExp };
  vm.createContext(ctx);
  vm.runInContext(BOOTSTRAP, ctx);
  assert.equal(seen[0][0], 'consent');
});

/* ---------- banner controls ---------- */

test('Cookie Preferences reopens the banner, focuses it, and returns focus on close', () => {
  const p = page({ cookie: 'perdita_consent=denied' });
  const b = withBanner(p);
  assert.equal(b.bannerShown(), false);
  assert.ok(b.click(b.prefs), 'the click is handled');
  assert.equal(b.bannerShown(), true);
  assert.equal(b.prefsShown(), false);
  assert.equal(b.buttons.denied.focused, true, 'focus moves into the banner');
  b.press('Escape');
  assert.equal(b.bannerShown(), false);
  assert.equal(p.jar.get('perdita_consent'), 'denied', 'Escape on a reopened banner changes nothing');
  assert.equal(b.prefs.focused, true, 'focus goes back to the button that opened it');
});

test('a reopened opt-in banner can switch Decline to Accept, which resends the page_view', () => {
  const p = page({ cookie: 'perdita_consent=denied' });
  const b = withBanner(p);
  b.click(b.prefs);
  const before = p.calls().length;
  b.accept();
  assert.deepEqual(p.calls().slice(before).map((c) => c[0]), ['consent', 'event']);
  assert.equal(p.jar.get('perdita_consent'), 'granted');
});

test('any link to #perdita-cookie-preferences reopens the banner, from a child element too', () => {
  const p = page({ cookie: 'perdita_consent=granted' });
  const b = withBanner(p);
  const link = el({ getAttribute: (k) => (k === 'href' ? 'https://example.test/#perdita-cookie-preferences' : null) });
  const span = el({ getAttribute: () => null, parentNode: link });
  link.parentNode = p.document;
  assert.ok(b.click(span));
  assert.equal(b.bannerShown(), true);

  const other = el({ getAttribute: (k) => (k === 'href' ? '#top' : null) });
  other.parentNode = p.document;
  b.press('Escape');
  assert.equal(b.click(other), false, 'other links are left alone');
});

test('the preferences class works as a trigger', () => {
  const p = page({ cookie: 'perdita_consent=granted' });
  const b = withBanner(p);
  const btn = el({ classList: { contains: (c) => c === 'perdita-cookie-preferences' }, getAttribute: () => null });
  btn.parentNode = p.document;
  b.click(btn);
  assert.equal(b.bannerShown(), true);
});

test('under GPC a preferences link does nothing but is still kept from jumping', () => {
  const p = page({ gpc: true });
  const b = withBanner(p);
  const link = el({ getAttribute: (k) => (k === 'href' ? '#perdita-cookie-preferences' : null) });
  link.parentNode = p.document;
  assert.ok(b.click(link));
  assert.equal(b.bannerShown(), false);
});

test('opt-in: Escape on a first visit records a decline', () => {
  const p = page();
  const b = withBanner(p);
  b.press('Escape');
  assert.equal(b.bannerShown(), false);
  assert.equal(p.jar.get('perdita_consent'), 'denied');
});

test('Escape does nothing when the banner is hidden or another handler took it', () => {
  const p = page();
  const b = withBanner(p);
  b.press('Escape', true);
  assert.equal(b.bannerShown(), true, 'defaultPrevented is respected');
  const q = page({ cookie: 'perdita_consent=granted' });
  const bq = withBanner(q);
  bq.press('Escape');
  assert.equal(q.writes.length, 0);
});

test('the banner works without the preferences button', () => {
  const p = page();
  const b = withBanner(p, { prefs: false });
  b.accept();
  assert.equal(b.bannerShown(), false);
});
