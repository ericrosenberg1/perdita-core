=== Perdita Core ===
Contributors: eric1985
Tags: forms, seo, caching, security, newsletter
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 0.19.22-beta
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
* **Caching and performance.** Page caching plus browser cache headers. You can serve cached pages before WordPress loads, through a drop-in like WP Super Cache's, and choose whether a post save clears the whole cache or only the pages that show that post. You can also preload the cache in the background, store gzip copies, serve the old copy while a page rebuilds, keep a separate cache for phones, cache your feeds, cap the cache's size on disk, and point static files at a CDN. You get a searchable list of cached pages, a scheduled cleanup, a debug log, and `wp perdita cache` commands. Carts, logged-in visitors, pages that set a cookie, and pages a plugin marks as private never get a cached copy.
* **Security hardening.** Login rate limiting, security headers, a locked-down file editor, and blocks for XML-RPC and author enumeration.
* **Email delivery (SMTP).** Send site mail through your own SMTP server so it reaches the inbox. Credentials are encrypted at rest.
* **Email subscriptions.** Let readers subscribe and get a note when you publish. Double opt-in, one-click unsubscribe.
* **Analytics and consent.** Google Analytics 4 and a cookie banner that sets Google Consent Mode v2, opt-in or opt-out. It can manage consent for Google tags other plugins add, with no measurement ID of its own, and replaces the Simple Consent Manager plugin.
* **Backups.** On-demand and scheduled backups of your database and files, with restore.
* **Related posts.** Matched by shared categories and tags. Nothing leaves your site.
* **Sales.** A small store for digital downloads and simple physical products, with Stripe checkout.
* **Search Console.** Clicks, impressions, and top queries in wp-admin, using your own Google Cloud OAuth app.
* **PageSpeed Insights.** Core Web Vitals for your homepage, straight from Google, so you can see what a setting actually did.
* **MCP server.** Let Claude, ChatGPT, or any MCP client manage posts and pages over a secure API instead of only through wp-admin.
* **Switch to Perdita.** Bring your settings over from WP Super Cache, W3 Total Cache, WP Fastest Cache, LiteSpeed Cache, Cache Enabler, Yoast SEO, All in One SEO, Rank Math, SEOPress, WP Mail SMTP, FluentSMTP, Post SMTP, Site Kit, MonsterInsights, GA Google Analytics, Simple Consent Manager, Limit Login Attempts Reloaded, or Loginizer. You can preview every setting first, undo an import, and deactivate the old plugin when you're ready. For a caching plugin, you can also clear out what it leaves behind, its drop-in and its .htaccess rules, and you get a backup copy of each file in a protected folder first. `wp perdita switch` gives you the same tools on the command line.

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

= 0.19.22-beta =
* Fix: you get your page cache's daily cleanup on a site that updates itself, with no wp-admin visit needed.
* Fix: after an automatic update, you get the new release's early-serve file in `wp-content` without opening wp-admin.

= 0.19.21-beta =
* New: in Caching, you get preload, gzip copies, serve stale while rebuilding, a separate copy for phones, rejected user agents and cookies, scheduled cleanup with a size cap, a list of cached pages, CDN URL rewriting, feed caching, and `wp perdita cache`.
* New: Switch to Perdita, under Perdita in the admin menu. You can preview and import your settings from 23 caching, SEO, email, analytics, and login-security plugins, undo an import, and then deactivate the old plugin. You can do the same from the command line with `wp perdita switch`.
* New: you get Perdita's own colors on every Perdita admin screen.
* Fix: visitors never get a cached copy of a page that sets a cookie, defines `DONOTCACHEPAGE`, or changes mid-load.
* Fix: you get a fresh page cache after you change a plugin, widget, menu, category, tag, the site title, or permalinks.
* Fix: a CDN or proxy in front of your site can no longer share a page that Perdita chose not to cache.
* Fix: visitors on tablets and phones get the right cached copy, matched to WordPress's own mobile test.
* Fix: you keep a long-dash title separator when you import from Yoast.
* Fix: you get a label on every field of the form builder, form entries, and Image SEO screens, and you can use the form builder, sales orders, IndexNow, Email, and PageSpeed screens on a phone.
* New: when your Perdita versions are out of step, you get an update button for each older piece.
* Fix: you get a fresh Perdita Core offer when you click Check again on the Updates screen, not one up to 12 hours old.

= 0.19.20-beta =
* Fix: Sales rounds a product's price and shipping to the currency's own decimals. A fractional price in a whole-unit currency like JPY no longer makes the recorded order total differ from the Stripe charge.

= 0.19.19-beta =
* Maintenance release, in lock step with the Perdita theme and Perdita Pro. Nothing changes in the plugin.

= 0.19.18-beta =
* Maintenance release, in lock step with the Perdita theme and Perdita Pro. Nothing changes in the plugin.

= 0.19.17-beta =
* Released in step with Perdita 0.19.17-beta. The newest Core zip sits at one fixed address now, linked from the Perdita screen and the README.

= 0.19.16-beta =
* Maintenance release, in lock step with the Perdita theme and Perdita Pro. Nothing changes in the plugin.

= 0.19.15-beta =
* The footer credit shortcodes ([perdita_year], [perdita_credit] and friends) expand through the theme's perdita_footer_credit_text filter, since the theme no longer runs shortcodes itself.

= 0.19.14-beta =
* Forms, related posts, subscriptions and admin cards fall back to Perdita's default palette instead of blue.
* The cookie consent banner uses the theme's primary color when no accent is set, and Perdita's fonts and colors.

= 0.19.13-beta =
* Fix: restoring a backup brings back every % in the database. Backups made earlier held a placeholder code in place of each %, which broke permalinks and saved settings. Those older backups now restore correctly too.
* Fix: a restore clears the object cache, so a site with Redis or Memcached shows the restored content right away.

= 0.19.12-beta =
* Fix: a cached page keeps WordPress's block styles. The cached copy had lost the button, group and spacer styles the first visitor saw.

= 0.19.11-beta =
* Fix: three wordpress.org Plugin Check errors in the page cache and llms.txt code. Nothing changes on your site.

= 0.19.10-beta =
* Fix: when Cloudflare rejects your Turnstile secret key, your forms keep taking messages and you see an admin notice asking you to fix the key. Visitors were turned away with "did not pass" before.

= 0.19.9-beta =
* Moving off All in One SEO keeps your titles. The settings import now carries the home page, post type and taxonomy templates, noindex rules, logo, default share image, verification codes and RSS footers, and turns AIOSEO's tags into Perdita's.
* New: "Serve before WordPress loads" on the Caching screen answers cached pages from a drop-in, the way WP Super Cache does. Perdita's cache waits while another page cache plugin is active.
* Fix: titles print as written, so a "-" separator stays a hyphen.
* Fix: archive canonicals drop tracking and cache-buster query strings.
* Fix: a hand-written excerpt is no longer cut off at 155 characters in the meta description.
* Fix: login lockouts and form throttles use the visitor's own address behind Cloudflare.
* Fix: an llms.txt left behind by AIOSEO or Yoast no longer hides Perdita's.
* Fix: the cookie consent banner no longer covers half a phone screen.
* Fix: the page cache clears after a theme, plugin or WordPress update.
* Fix: PageSpeed Insights tests the right page when the home URL carries its own query string, such as a language plugin's ?lang=fr.

= 0.19.8-beta =
* Fix: Stripe charges in JPY, KRW and other zero-decimal currencies use whole units. They were 100 times the price.
* Fix: a Turnstile outage no longer turns every visitor away from a form.
* Fix: the section sidebar loads on the post editor only.

= 0.19.7-beta =
* Automatic updates are on by default. Turn them off on the Plugins screen and they stay off.
* The one-click connect section for claude.ai and ChatGPT drops its "Pro" label. It stays free.
* Fix: IndexNow submits the category and tag archives of posts published from the block editor or the REST API.
* Fix: IndexNow submits a trashed post's original URL instead of its `__trashed` slug.

= 0.19.6-beta =
* Fix: GA4 consent is set before the first page view.
* Fix: the Search Console settings screen no longer crashes.
* Fix: IndexNow submits posts saved with WP-CLI.
* Smaller consent script on every page.
* Now in beta.

= 0.19.5-alpha =
* Works with the Perdita theme's wordpress.org build.
* Fix: MCP rate limits count each IPv4 visitor separately on hosts that report IPv4-mapped addresses.
* Fix: the Image SEO variables help text translates cleanly.

= 0.19.4-alpha =
* Analytics replaces the Simple Consent Manager plugin. Visitors who already chose there keep their choice.
* Analytics offers opt-in or opt-out consent. Global Privacy Control turns measurement off in both.
* Analytics can manage consent for Google tags other plugins add, with no measurement ID of its own.
* Consent banner: Privacy Policy link, accent color, Escape to close, and a Cookie Preferences button, shortcode and menu link.
* Optional ad signals setting. Every consent update sets all four Consent Mode signals.
* Fix: the Perdita theme's button styles no longer repaint the consent banner buttons.

= 0.19.3-alpha =
* Fix: Analytics counts page views from visitors who accepted.
* Fix: Analytics never enables ad storage.
* Analytics honors Global Privacy Control.
* Simpler default consent banner text.

= 0.19.2-alpha =
* Security: admin notices show only messages Perdita itself sent, so a crafted link cannot put fake text on a Perdita screen.
* Security: switching the Security, Backups, MCP and SMTP modules needs an administrator.
* Security: MCP app registration is bounded, and a connecting app's state value is returned unchanged.
* Fix: signing in during an MCP connection works on sites installed in a subdirectory, and http://[::1] loopback apps can register.
* Removed: sitemap pings to Google and Bing. Both retired those endpoints. IndexNow and the Sitemap line in robots.txt cover it.

= 0.19.1-alpha =
* Security: self-hosted updates install only when signed with the Perdita release key and when the download matches its checksum.
* Security: password-protected posts stay out of the page cache, the meta description, social tags, structured data and the image sitemap.
* Security: the store's order confirmation page needs the buyer's own checkout session, so order numbers cannot be guessed for download links.
* Security: form and newsletter signup limits can no longer be switched off by a request header.
* Security: MCP connections cannot read password-protected posts, and a user who loses the right to connect loses access right away.
* Fix: store orders paid by bank debit wait for the money to arrive, and abandoned checkouts give their reserved stock back.
* Fix: a post with no author no longer prints article:author as a bare /author/ URL or an empty %%AUTHORLINK%% link in feed footers, and an author with a blank display name no longer gets a nameless Person in the schema.
* Fix: a robots.txt override no longer opens a site that is set to discourage search engines.
* Fix: IndexNow submissions no longer empty the page cache after every publish.
* Fix: MCP post writes keep backslashes, and the profile screen's Revoke button works.

= 0.19.0-alpha =
* SEO: title and description templates per post type and per taxonomy, with 18 variables (%title%, %sitename%, %sep%, %tagline%, %excerpt%, %category%, %tag%, %author%, %currentyear%, %currentmonth%, %currentdate%, %archive_title%, %term_title%, %term_description%, %post_type_singular%, %post_type_plural%, %page%, %search_term%) and a perdita_seo_variables filter. Category, tag, and custom taxonomy terms get their own SEO title and description fields.
* SEO: robots directives now go through WordPress core's wp_robots, with controls for paginated archives, attachment pages (noindex or redirect to the parent), per-post-type and per-taxonomy noindex defaults, and max-snippet, max-image-preview, and max-video-preview.
* SEO: the head output is filterable (perdita_seo_description, perdita_seo_canonical, perdita_seo_og_tags, perdita_seo_json_ld, perdita_seo_article_type). Perdita Pro uses these instead of rewriting the page buffer.
* SEO: structured data is one linked graph. Every page gets a WebPage node, every public post type gets an Article node, and the author is a full Person with job title, credentials, knows-about topics, and profile links from new fields on the user profile screen. Author archives are ProfilePage.
* SEO: article Open Graph tags (published and modified time, author, section, tags), og:locale, image dimensions and alt, Twitter title, description, image, and creator, plus a Facebook app id field.
* SEO: webmaster verification tags for Google, Bing, Pinterest, Yandex, and Baidu. A robots.txt editor that keeps every Sitemap line other features add. RSS feed prefix and suffix templates, and switches to disable the comments feed or all feeds.
* SEO: llms.txt is served at /llms.txt as an index of the site's content by post type, with an optional llms-full.txt.
* New IndexNow module (on by default): serves the key file, submits new and updated URLs to IndexNow shortly after publish, pings the sitemap to Google and Bing, keeps a log, and adds wp perdita indexnow commands.
* New Breadcrumbs module (on by default): perdita_breadcrumbs() template tag, [perdita_breadcrumbs] shortcode, a Breadcrumbs block, optional automatic placement above content, and a trail that Perdita Pro reuses for BreadcrumbList schema.
* New Image SEO module (off by default): fills missing alt text from the filename on upload, a bulk fill for the existing library, and an image sitemap at /image-sitemap.xml.
* MCP server: create_post, update_post, and get_post accept and return SEO title, description, focus keyphrase, canonical, noindex, Open Graph title and description, and schema type when Perdita Pro's SEO module is active.
* Fix: five translatable SEO strings carried template tokens that read as printf placeholders, and two robots settings were saved without unslashing.

= 0.18.2-alpha =
* MCP server: writes to the OAuth storage (registered clients, tokens, connected apps) now run under a short database lock and re-read the stored row inside it, so two requests landing at once can no longer overwrite each other's tokens. A write that cannot take the lock returns a retryable 503 instead of guessing.
* MCP server: get_post caps content_raw and content_rendered at 60,000 characters each, and says truncated: true with both full lengths when it cuts, so one huge post cannot swallow an AI client's context unannounced. Change the ceiling with the perdita_mcp_get_post_max_chars filter, or pass max_chars on a call to ask for less.
* Caching: the MCP server's OAuth records changing (every token issue and refresh) no longer purges the page cache. Nothing on a rendered page depends on them.

= 0.18.1-alpha =
* Fix: the page cache now purges when ANY module's settings change (Analytics, Search Console, security, PageSpeed, related posts, sales, SMTP, subscriptions, backups), when the SEO store changes or resets, and when a module is switched on or off. Previously only the design tokens and the caching module's own settings purged the cache, so saving another module's settings (for example turning on Analytics and setting a GA4 measurement ID) could leave the previous, stale page serving until the cache TTL expired.

= 0.18.0-alpha =
* Version numbers now match the Perdita theme and Perdita Pro release for release, so 1.0.1-alpha is followed by 0.18.0-alpha on purpose. Warns in wp-admin when the three installed pieces are not on the same version.

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
* **Google Analytics (analytics module).** Loads Google's gtag script with Google Consent Mode, only when you enter a measurement ID. In opt-in mode (the default), analytics cookies stay off until a visitor accepts the consent banner, and before that Google receives only cookieless pings. In opt-out mode they're on until a visitor opts out. Global Privacy Control, and Do Not Track when the site respects it, keep them off in both modes. Ad signals stay off unless you turn them on. The visitor's choice is stored in a first-party `perdita_consent` cookie for a year. Off by default.
* **Google PageSpeed Insights (pagespeed module).** Sends your own public page URLs to Google's API from wp-admin, using your API key. No visitor data.
* **Google Search Console (search-console module).** Connects to Google with an OAuth app you create in your own Google Cloud account, and reads your own site's search performance. No shared credentials, and nothing is sent to us.
* **Your SMTP provider (smtp module).** Site email is handed to the SMTP server you configure instead of PHP mail. Recipients and subjects are written to a log table on your own site.
* **Stripe (sales module).** Checkout sessions and webhook verification, using your own Stripe keys. Customer payment details go to Stripe, never to your database.
* **Your AI provider (the section builder).** The block-editor "describe a section" tool sends your description to whichever provider you connected in the Perdita theme (OpenRouter, Anthropic, or OpenAI). Nothing is sent until you use the tool.
* **MCP clients (mcp module).** Opens an authenticated API on your site that an AI client you authorize can call. It's off by default, needs a bearer token or an OAuth approval, and every connection is listed and revocable in wp-admin.

Every stored credential is encrypted at rest with the Perdita theme's encryption helper, which derives its key from your WordPress salts.
