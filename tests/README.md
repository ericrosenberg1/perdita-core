# Perdita Core tests

Integration smoke tests that run the real plugin code against a live WordPress
with the Perdita theme active and this plugin activated. They cover the plugin
bootstrap and its dependency on the theme, the module registry, the migration
of module state out of the theme's option, the top-level admin menu slug, a
full form submission and storage cycle, the SEO store, the footer shortcodes,
and every module's offline-testable logic (SMTP, analytics, PageSpeed, Search
Console, security, caching, backups, related posts, subscriptions, sales, and
the MCP server with its OAuth authorization server).

Everything they create is cleaned up, and every option they touch is captured
and restored.

## Run

```
bash tests/run.sh /path/to/wordpress
```

Point it at a specific PHP if the one on your PATH is the wrong version:

```
PHP=/opt/homebrew/bin/php bash tests/run.sh /path/to/wordpress
```

The command exits non-zero if anything fails, so it drops straight into CI.

## Why it isn't run through wp-cli

The theme's suite uses `wp eval-file`. This one loads WordPress through a
generated wrapper that requires `wp-load.php`, because several assertions here
are about what is *not* built on an ordinary request: the self-hosted updater,
the Modules screen, and every module's admin class. wp-cli defines `WP_CLI`,
which is one of the three contexts that legitimately constructs the updater, so
running under it would quietly make those assertions vacuous.

## Layout

`tests/smoke.php` is the harness and the main suite. It defines `$ok()` and the
`$pass`/`$fail` counters, then requires every `tests/smoke-*.php` fragment in
the same scope right before printing the summary. A focused suite for one fix
can live in its own fragment without two people editing the main file at once.

- `smoke-security.php` covers security and correctness regressions: the MCP
  content-leak fix, the OAuth consent capability, the updater's checksum
  verification, SMTP STARTTLS, the subscription confirm token, and the
  author-enumeration block.
- `smoke-perf.php` covers performance regressions: lazy construction, page
  cache keying and purging, the related-posts memo, the SEO store's negative
  cache, and the sales stylesheet only loading where it's used.
- `smoke-seo-core.php` covers the free SEO layer end to end: template
  variables, per-post-type and term templates, robots directives, the
  extension filters, the linked JSON-LD graph, author E-E-A-T, webmaster
  tags, the robots.txt override, feed controls, and llms.txt.
- `smoke-indexnow.php`, `smoke-breadcrumbs.php`, and `smoke-image-seo.php`
  cover the three SEO modules: key file and submission scheduling with HTTP
  stubbed, the breadcrumb trail for every view type, and alt text generation
  plus the image sitemap.
- `smoke-mcp-seo.php` covers the MCP SEO fields: the update action fires with
  sanitized fields and `get_post` merges the filter result.

## What it does not cover

These need a browser, a live model, or a real network round-trip:

- Any AI feature against a real key, including describe-a-section
- The Search Console OAuth round-trip (needs an HTTPS host and a Google app)
- A live Stripe checkout or webhook delivery
- The MCP JSON-RPC endpoint over real HTTP, and its rate limiter
- The forms field-builder UI and the block editor's section sidebar
- The self-hosted updater performing a real cross-version install
