=== Perdita Core ===
Contributors: eric1985
Tags: forms, seo, caching, security, newsletter
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.1-alpha
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

The free companion plugin for the Perdita theme. SEO, forms, caching, security, email, and more, as modules you turn on one at a time.

== Description ==

Perdita Core adds the site features the Perdita theme can't ship on its own. WordPress theme rules keep things like SEO output, contact forms, page caching, and custom post types out of a theme, so they live here instead. The theme handles design. This plugin handles what your site does.

It needs the Perdita theme. Activate Perdita, or a child theme of it, and this plugin wires itself in. Without it, the plugin registers nothing and tells you why.

Everything is a module, and a module that's off costs you one option read. Its code isn't loaded, its admin menu isn't registered, and its tables are never touched. Turn modules on and off under Perdita in the admin menu.

**What you get**

* **SEO.** Titles, meta descriptions, Open Graph and Twitter cards, schema, canonical URLs, and a one-click import from Yoast or All in One SEO.
* **Forms.** Build a contact form, store the entries in your own database, and keep the spam out with a honeypot and rate limiting.
* **Caching and performance.** Page caching with smart clearing, plus browser cache headers. It skips carts, logged-in users, and any page with a form on it.
* **Security hardening.** Login rate limiting, security headers, a locked-down file editor, and blocks for XML-RPC and author enumeration.
* **Email delivery (SMTP).** Send site mail through your own SMTP server so it reaches the inbox. Credentials are encrypted at rest.
* **Email subscriptions.** Let readers subscribe and get a note when you publish. Double opt-in, one-click unsubscribe.
* **Analytics and consent.** Google Analytics 4 behind a cookie banner that holds tracking until the visitor agrees.
* **Backups.** On-demand and scheduled backups of your database and files, with restore.
* **Related posts.** Matched by shared categories and tags. Nothing leaves your site.
* **Sales.** A small store for digital downloads and simple physical products, with Stripe checkout.
* **Search Console.** Clicks, impressions, and top queries in wp-admin, using your own Google Cloud OAuth app.
* **PageSpeed Insights.** Core Web Vitals for your homepage, straight from Google, so you can see what a setting actually did.
* **MCP server.** Let Claude, ChatGPT, or any MCP client manage posts and pages over a secure API instead of only through wp-admin.

**Defaults**

SEO and Forms are on when you activate. Every other module is off, including all of the ones that talk to an outside service. You turn on what you want, when you want it.

**Perdita Pro**

Perdita Pro is a separate paid plugin that adds more modules on top of these: redirects and a 404 monitor, a malware scanner and two-factor login, differential backups, multi-route SMTP with bounce handling, coupons and subscriptions for the store, and more. This free plugin is complete without it. Nothing here is a trial, a teaser, or crippled until you pay.

== Installation ==

1. Install and activate the Perdita theme first. You can run a child theme of Perdita instead, that works the same way.
2. Upload the `perdita-core` folder to `/wp-content/plugins/`, or install the zip from Plugins > Add New > Upload Plugin.
3. Activate Perdita Core from the Plugins screen.
4. Go to **Perdita** in the admin menu and switch on the modules you want.

If you already ran these features inside the Perdita theme, your settings come across on the first load. Every module you had on stays on.

== Frequently Asked Questions ==

= Do I need the Perdita theme? =

Yes. The plugin reads the theme's design token document, its encryption helper, and its AI provider connection, so it can't run without them. If Perdita isn't the active theme (or the parent of the active theme), the plugin registers nothing and shows an admin notice saying so. Your settings and your data stay where they are, and everything switches back on when you activate the theme again.

= Does it phone home? =

Only for update checks, and only from wp-admin, cron, or WP-CLI. The plugin asks perdita.ericrosenberg.com for a small JSON file listing the current version, and verifies the sha256 checksum of any package before WordPress installs it. That check is the only outbound request the plugin makes on its own.

Everything else that touches an outside service is a module you have to turn on and configure with your own credentials: Google Analytics, Google PageSpeed Insights, Google Search Console, your SMTP server, Stripe, and the AI provider you connected in the theme. None of them run until you set them up. See the Privacy section for the full list.

= Will it slow my site down? =

A module that's off never loads. A module that's on only loads on the requests it needs: an admin-only module like Search Console never touches a front-end page view, and a REST-only module like the MCP server never loads on the front end either. That's the whole design of the module registry.

= What happens to my data if I delete the plugin? =

Form entries, subscribers, orders, products, form definitions, the SMTP log, your SEO settings, and your backup archives all stay. Module settings, caches, rate limiters, MCP OAuth clients and tokens, and the module toggles are removed. The reasoning for each is written out at the top of `uninstall.php`.

= Can I use it with Perdita Pro? =

Yes, that's what Pro is built on. Pro registers its own modules into this plugin's registry, so they appear on the same Modules screen and behave the same way.

== Changelog ==

= 1.0.1-alpha =
* SEO: import site-wide Genesis Framework SEO settings (homepage title and description, separator, title shape, archive noindex rules) from Perdita SEO > Tools, next to the Yoast and All in One SEO importers.
* The Premium feature list no longer claims a Rank Math per-post importer that does not exist.

= 1.0.0-alpha =
* First release. Split out of the Perdita theme so the theme can go to the wordpress.org theme directory, which doesn't allow plugin features in a theme.
* Carries the module registry, SEO, Forms, the footer shortcodes, the AI section builder, and all eleven folder modules (analytics, backups, caching, MCP, PageSpeed, related posts, sales, Search Console, security, SMTP, subscriptions).
* Module enable state moves from the theme's `perdita_settings` option to this plugin's own `perdita_core` option. Existing choices are copied across on first load, including the older `features.seo` and `features.forms` flags.
* The top-level Perdita admin menu now belongs to this plugin.

== Privacy ==

This plugin makes no outbound request on its own except the update check described below. Every other service listed here belongs to a module that's off until you turn it on and enter your own credentials.

* **Update check (always on).** A request to perdita.ericrosenberg.com for a JSON manifest, from wp-admin, cron, or WP-CLI only. It sends no site data, no domain, and no identifier. Remove `inc/class-perdita-core-updater.php` to switch it off entirely.
* **Google Analytics (analytics module).** Loads Google's gtag script and sends visitor page views to Google, but only after a visitor accepts the consent banner. Off by default, and no tracking happens before consent.
* **Google PageSpeed Insights (pagespeed module).** Sends your own public page URLs to Google's API from wp-admin, using your API key. No visitor data.
* **Google Search Console (search-console module).** Connects to Google with an OAuth app you create in your own Google Cloud account, and reads your own site's search performance. No shared credentials, and nothing is sent to us.
* **Your SMTP provider (smtp module).** Site email is handed to the SMTP server you configure instead of PHP mail. Recipients and subjects are written to a log table on your own site.
* **Stripe (sales module).** Checkout sessions and webhook verification, using your own Stripe keys. Customer payment details go to Stripe, never to your database.
* **Your AI provider (the section builder).** The block-editor "describe a section" tool sends your description to whichever provider you connected in the Perdita theme (OpenRouter, Anthropic, or OpenAI). Nothing is sent until you use the tool.
* **MCP clients (mcp module).** Opens an authenticated API on your site that an AI client you authorize can call. It's off by default, needs a bearer token or an OAuth approval, and every connection is listed and revocable in wp-admin.

Every stored credential is encrypted at rest with the Perdita theme's encryption helper, which derives its key from your WordPress salts.
