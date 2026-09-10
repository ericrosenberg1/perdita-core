<?php
/**
 * Perdita Core uninstaller.
 *
 * Runs ONLY when an admin deletes the plugin from the Plugins screen, not on
 * deactivation. That path is register_deactivation_hook() in perdita-core.php,
 * which clears scheduled events and the cached update manifest and nothing
 * else, so a deactivate/reactivate cycle is lossless.
 *
 * WHAT THIS REMOVES: settings, caches, schema markers, and derived state. The
 * rows that exist only to make the plugin work and that a reinstall rebuilds.
 *
 * WHAT THIS KEEPS, ON PURPOSE:
 *   - Form entries ({prefix}perdita_entries) and the perdita_form posts that
 *     define the forms. Visitors typed those in, and the form definitions are
 *     authored content.
 *   - Newsletter subscribers ({prefix}perdita_subscribers). Deleting a mailing
 *     list on plugin removal is unrecoverable and, in several jurisdictions,
 *     means re-collecting consent from everyone on it.
 *   - The SMTP log ({prefix}perdita_smtp_log). Deliverability evidence, which
 *     is exactly what an admin needs to read when a provider asks why a
 *     domain's complaint rate moved, and often needed AFTER removing whatever
 *     was sending the mail.
 *   - Orders and products (perdita_order, perdita_product posts and their
 *     meta). Commercial records. A shop owner who removes this plugin still
 *     needs last year's orders, and no plugin should destroy a financial
 *     trail without being asked.
 *   - SEO settings (option perdita_seo). This is not plugin bookkeeping, it is
 *     live site configuration: titles, meta templates, social defaults, and
 *     the noindex list. It also survives a reinstall intact, which is the
 *     behavior a site owner reinstalling after a support session expects.
 *   - The theme's own token document (option perdita_settings). It belongs to
 *     the Perdita theme, which this plugin only reads.
 *   - Backup archives in uploads/perdita-backups/. They are the last copy of
 *     something, by definition.
 *   - Page cache files in uploads/perdita-cache/. Nothing serves them once the
 *     plugin is gone, and an uninstaller that recursively deletes a computed
 *     uploads path is exactly the kind of code that eats a media library when
 *     wp_upload_dir() returns something unexpected. Delete the folder by hand
 *     if the empty directory bothers you.
 * Anything in that list has to be removed by the person who owns it,
 * deliberately, not as a side effect of clicking Delete on a plugin.
 *
 * @package Perdita_Core
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Options this plugin owns. Every one is a setting, a cache, a schema marker,
 * or a dismissed-notice flag that a reinstall rebuilds.
 *
 * The encrypted credentials some of these hold (SMTP password, Stripe keys,
 * the Search Console client secret, the PageSpeed API key) go with them: they
 * are unreadable without this plugin's code, and leaving a site's live
 * secrets in the options table after the owner deleted the thing that used
 * them is worse than making them paste the values again.
 *
 * @return string[]
 */
function perdita_core_uninstall_options() {
	return array(
		// This plugin's own state: module toggles, migration stamp, schema version.
		'perdita_core',

		// Module settings.
		'perdita_analytics_settings',
		'perdita_backups_settings',
		'perdita_caching_settings',
		// perdita_forms_settings is kept, like perdita_seo: notification recipients and
		// spam-provider configuration are site configuration, not plugin bookkeeping.
		'perdita_mcp_settings',
		'perdita_pagespeed_settings',
		'perdita_related_posts_settings',
		'perdita_sales_settings',
		'perdita_search_console_settings',
		'perdita_security_settings',
		'perdita_smtp_settings',
		'perdita_subscriptions_settings',
		'perdita_indexnow',       // Key, engines, submission log.
		'perdita_breadcrumbs',
		'perdita_image_seo',
		'perdita_seo_llms_rules', // Rewrite-flush stamp for llms.txt.

		// MCP OAuth authorization-server state: registered clients, issued
		// grants, and live connections. Every one is a credential for reaching
		// this site, so removing the endpoint has to remove them too, or a
		// reinstall would silently restore access nobody re-approved.
		'perdita_mcp_oauth_clients',
		'perdita_mcp_oauth_grants',
		'perdita_mcp_oauth_connections',

		// The pending new-post notification queue. A work list, not a record:
		// with the module gone there is nothing to send it.
		'perdita_subscriptions_queue',

		// Schema markers. Kept in step with the tables they describe, and the
		// tables that survive are recreated by dbDelta on a reinstall anyway.
		'perdita_forms_db_version',
		'perdita_subscriptions_db_version',

		// Dismissed-notice flags.
		'perdita_forms_proxy_ip_notice',
		'perdita_subscriptions_proxy_ip_notice',
	);
}

/**
 * Transient name prefixes this plugin owns.
 *
 * Several transients are keyed per user, per cart, per IP hash, or per OAuth
 * exchange, so they cannot be enumerated by name. Each prefix below is
 * unambiguously this plugin's, and each row is a cache or a short-lived token
 * with nothing behind it worth keeping.
 *
 * @return string[]
 */
function perdita_core_uninstall_transient_prefixes() {
	return array(
		'perdita_core_',   // Cached update manifest.
		'perdita_mcp_',    // API keys, OAuth pending/consent/claim records, rate limiters.
		'perdita_mcpat_',  // MCP OAuth access tokens.
		'perdita_mcprt_',  // MCP OAuth refresh tokens.
		'perdita_mcpc_',   // MCP OAuth client registrations.
		'perdita_sc_',     // Search Console cached report data and OAuth state.
		'perdita_psi_',    // PageSpeed Insights results.
		'perdita_sec_',    // Security module's login rate limiter.
		'perdita_sales_',  // Carts.
		'perdita_subs_rl_', // Subscription signup rate limiter.
		'perdita_fl_',     // Forms submission rate limiter.
		'perdita_backups_notice_',
		'perdita_indexnow_', // Sitemap ping throttle.
		'perdita_image_sitemap', // Cached image sitemap XML.
		'perdita_seo_llms_', // Cached llms.txt and llms-full.txt.
	);
}

/**
 * Remove this plugin's options, transients and scheduled events from the
 * CURRENT site.
 */
function perdita_core_uninstall_site() {
	global $wpdb;

	foreach ( perdita_core_uninstall_options() as $option ) {
		delete_option( $option );
	}

	// Scheduled events. The deactivation hook normally clears these first, but
	// deleting a plugin that was already inactive (or whose deactivation hook
	// never ran, e.g. removed by hand from the filesystem and then deleted)
	// skips that, so repeat it here.
	foreach (
		array(
			'perdita_backups_run',
			'perdita_subscriptions_send_batch',
			'perdita_indexnow_submit',
		) as $hook
	) {
		wp_clear_scheduled_hook( $hook );
	}

	// Transients, including the per-user and per-hash ones that cannot be
	// named individually. Deleting the rows directly (rather than calling
	// delete_transient() on names we would have to guess) is the only way to
	// reach them, and the timeout companion rows go with them.
	foreach ( perdita_core_uninstall_transient_prefixes() as $prefix ) {
		$like = $wpdb->esc_like( $prefix ) . '%';
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				'_transient_' . $like,
				'_transient_timeout_' . $like
			)
		);
	}

	wp_cache_flush();
}

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $perdita_core_site_id ) {
		switch_to_blog( $perdita_core_site_id );
		perdita_core_uninstall_site();
		restore_current_blog();
	}
} else {
	perdita_core_uninstall_site();
}
