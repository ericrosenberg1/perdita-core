# Perdita Core: notes for agents

This is the free plugin half of Perdita. It ships in lock step with the Perdita theme, Perdita Pro and the license server. This is the one public repo of the four, so keep host names, server paths and anything else about private infrastructure out of it. A pre-push hook (infra-leak-guard) blocks the common patterns. The release recipe, live-site notes and the full landmine list are in `~/Code/perdita/CLAUDE.md`, which lives in a private repo.

## Rules that bite

- **One version number across theme, Core and Pro.** Bump with `../perdita/bin/bump-version.sh <version>` (or this repo's copy), never by hand. Labels are -alpha, -beta or -rc only, and the script refuses a downgrade. `bin/build-release.sh` refuses a version the siblings don't share.
- **The `--wporg` build declares plain X.Y.Z** while `PERDITA_CORE_VERSION` keeps the label. The plugin uploader rejects any version with letters, and the 0.19.5 zip declared `0.19.5-alpha`, so that first submission may never have gone in.
- **Keep the `Update URI` header and the updater's foreign-offer guard.** Without them, a directory listing would let WordPress replace self-hosted installs with the stripped directory build.
- **The updater's auto-update default is off** (`perdita_core_auto_update` false) while the theme's is on. Changing that is an open Eric decision.
- **`Perdita_Crypto` here is a copy of the theme's.** Core loads it only when the theme didn't, and a test fails on drift.

## Modules

14 free module ids live in `inc/modules/` (the Pro plugin adds 13). Module state is the autoloaded option `perdita_core` (`modules.<id>`), and the theme's legacy `perdita_settings['modules']` still matters to the theme suite, so test toggles write both.

Decisions that look like bugs but aren't:
- The module-state migration copies the old `features.seo` and `features.forms` flags even when they equal the defaults. It's behavior-preserving, leave it.
- MCP answers an unknown tool name with `-32602`, not `-32601`. The MCP spec's own example and the reference SDK do the same, and an end-to-end test pins it beside `-32601` for an unknown method.
- OAuth option blobs (grants, clients, connections) only change through `mutate_option()`, a per-option database lock. Don't add a plain `update_option()` on them.

Open, waiting on Eric (don't change without his answer):
- `inc/modules/mcp/class-perdita-mcp-admin.php` titles the OAuth connected-apps section "Pro: one-click connect from claude.ai or ChatGPT", but nothing checks a license before registering those routes. Either the label goes or a license check gates the routes.
- Forms Turnstile (`check_turnstile()` in `inc/class-perdita-forms.php`): a secret Cloudflare rejects turns every visitor away with "did not pass" and the owner is never told. Pro's Spam Pro treats a rejected secret like an outage and raises an admin notice. Whether Core fails open or closed is his call, and the admin notice is wanted either way.

## Behavior other code relies on

- Analytics: the consent default carries the visitor's stored choice and runs before every `gtag('config')`, with no consent update on page load. `tests/smoke-analytics-order.php` renders the actual head and footer for six settings paths and runs the inline scripts in node. A blank measurement ID with "manage consent for other Google tags" off prints nothing, and a live site's mu-plugin depends on that.
- Analytics adopts Simple Consent Manager's settings once on `admin_init` for a site that never chose a consent model, and unhooks SCM's head script while Perdita's consent layer is on.
- IndexNow: changes queue during the request and go out at shutdown as one cron event. Under WP-CLI they submit inline at shutdown (filter `perdita_indexnow_submit_inline`), and `save_post` at priority 20 is the catch-all for every save path. A published post queues by id and its URLs are built at flush, because the REST controller (and so the block editor) sets categories, tags and meta after `wp_insert_post()` fires the post hooks. Old URLs (unpublish, trash, delete, slug or term change) are still captured before the change.

## Pending changelog

Fixes merged to main since the last release go here, one `readme.txt` line each. At the next lock-step bump, move them into that version's changelog entry and empty this list.

- Fix: IndexNow submits the category and tag archives of posts published from the block editor or the REST API.

## Tests

- `bash tests/run.sh /path/to/wordpress` (see `tests/README.md`). The node tests (`*.test.mjs`) run from `smoke.php` when node is on PATH.
- 818 passing at 0.19.6-beta on WordPress 7.1.3 with PHP 8.5 and 8.4, zero notices. 832 on main since the IndexNow REST fix.
- Plugin Check 2.1.0 on the `--wporg` zip: 0 errors, 81 warnings. Those are the baseline. WPCS reports 278 errors and 217 warnings on the directory build, all pre-existing, so compare against those numbers rather than zero.
- Rig recipes are in the Mac's memory files `reference_perdita_review_tooling` and `reference_headless_wp_test_rig`.
