<?php
/**
 * The plugins only Perdita Pro imports from, so the free Switch to Perdita
 * screen can list every plugin you can switch from, whether or not Pro is
 * here to run the import.
 *
 * Each id, label, module, and plugin file list matches the Pro source of the
 * same id (Perdita_Pro_Switch::classes()), and Pro's suite fails when the two
 * drift. Once Pro registers a source, the screen shows Pro's card and this
 * entry steps aside.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

class Perdita_Switch_Catalog {

	/**
	 * Where someone without Pro reads about it.
	 */
	const PRO_URL = 'https://perdita.app/';

	/**
	 * The Pro module groups, for the screen's headings.
	 *
	 * @return array module id => label.
	 */
	public static function module_labels() {
		return array(
			'caching-pro'  => __( 'Speed (Pro)', 'perdita-core' ),
			'media-pro'    => __( 'Image Compression (Pro)', 'perdita-core' ),
			'spam-pro'     => __( 'Spam Protection (Pro)', 'perdita-core' ),
			'seo-pro'      => __( 'SEO Pro', 'perdita-core' ),
			'smtp-pro'     => __( 'SMTP Failover (Pro)', 'perdita-core' ),
			'security-pro' => __( 'Security (Pro)', 'perdita-core' ),
			'backups-pro'  => __( 'Backups (Pro)', 'perdita-core' ),
			'forms-pro'    => __( 'Forms Pro', 'perdita-core' ),
		);
	}

	/**
	 * Add-ons that need a source plugin and stop working without it. When
	 * the screen deactivates the plugin, it deactivates the add-on too, and
	 * Reactivate turns both back on.
	 *
	 * @param string $basename The source plugin's basename.
	 * @return array add-on basename => name.
	 */
	public static function addons( $basename ) {
		$all = array(
			'contact-form-7/wp-contact-form-7.php' => array( 'flamingo/flamingo.php' => 'Flamingo' ),
			'wordpress-seo/wp-seo.php'             => array( 'wordpress-seo-premium/wp-seo-premium.php' => 'Yoast SEO Premium' ),
			'seo-by-rank-math/rank-math.php'       => array( 'seo-by-rank-math-pro/rank-math-pro.php' => 'Rank Math SEO PRO' ),
			'wp-seopress/seopress.php'             => array( 'wp-seopress-pro/seopress-pro.php' => 'SEOPress PRO' ),
		);
		$list = isset( $all[ (string) $basename ] ) ? $all[ (string) $basename ] : array();
		/**
		 * Filters the add-ons the Switch screen deactivates with a plugin.
		 *
		 * @param array  $list     add-on basename => name.
		 * @param string $basename The source plugin's basename.
		 */
		return (array) apply_filters( 'perdita_switch_addons', $list, (string) $basename );
	}

	/**
	 * The add-ons of a plugin that are active now.
	 *
	 * @param string $basename The source plugin's basename.
	 * @return array add-on basename => name.
	 */
	public static function active_addons( $basename ) {
		$out = array();
		foreach ( self::addons( $basename ) as $addon => $name ) {
			if ( $addon !== $basename && Perdita_Switch_Source::plugin_active( $addon ) ) {
				$out[ $addon ] = (string) $name;
			}
		}
		return $out;
	}

	/**
	 * Every Pro-only source.
	 *
	 * @return array id => label, module, plugins (basenames), covers (what you can bring over with Pro),
	 *               plugin (the name on the screen's list, or '' for a card that covers several plugins).
	 */
	public static function pro_sources() {
		$speed  = __( 'With Perdita Pro, you can bring over its minify, lazy load, preload, and other page speed settings.', 'perdita-core' );
		$images = __( 'With Perdita Pro, you can bring over its compression settings and every image you already compressed with that plugin, so you never compress an image twice.', 'perdita-core' );
		$spam   = __( 'With Perdita Pro, you can bring over its keys and spam settings.', 'perdita-core' );
		$secure = __( 'With Perdita Pro, you can bring over its IP block list, country rules, rate limits, user agent rules, blocked usernames, two-factor role requirement, and scan and alert settings.', 'perdita-core' );
		$backup = __( 'With Perdita Pro, you can bring over its S3-compatible bucket, schedule, and how many backups to keep.', 'perdita-core' );
		$seo    = array( 'wordpress-seo/wp-seo.php', 'wordpress-seo-premium/wp-seo-premium.php', 'all-in-one-seo-pack/all_in_one_seo_pack.php', 'all-in-one-seo-pack-pro/all_in_one_seo_pack.php', 'seo-by-rank-math/rank-math.php', 'wp-seopress/seopress.php' );
		$list   = array(
			'wp-rocket'                 => array( 'WP Rocket', 'caching-pro', array( 'wp-rocket/wp-rocket.php' ), $speed ),
			'autoptimize'               => array( 'Autoptimize', 'caching-pro', array( 'autoptimize/autoptimize.php' ), $speed ),
			'perfmatters'               => array( 'Perfmatters', 'caching-pro', array( 'perfmatters/perfmatters.php' ), $speed ),
			'w3-total-cache-speed'      => array( 'W3 Total Cache, page optimization', 'caching-pro', array( 'w3-total-cache/w3-total-cache.php' ), $speed, 'W3 Total Cache' ),
			'wp-fastest-cache-speed'    => array( 'WP Fastest Cache, page optimization', 'caching-pro', array( 'wp-fastest-cache/wpFastestCache.php' ), $speed, 'WP Fastest Cache' ),
			'litespeed-cache-speed'     => array( 'LiteSpeed Cache, page optimization', 'caching-pro', array( 'litespeed-cache/litespeed-cache.php' ), $speed, 'LiteSpeed Cache' ),
			'cache-enabler-speed'       => array( 'Cache Enabler, page optimization', 'caching-pro', array( 'cache-enabler/cache-enabler.php' ), $speed, 'Cache Enabler' ),
			'ewww'                      => array( 'EWWW Image Optimizer', 'media-pro', array( 'ewww-image-optimizer/ewww-image-optimizer.php', 'ewww-image-optimizer-cloud/ewww-image-optimizer-cloud.php' ), $images ),
			'shortpixel'                => array( 'ShortPixel', 'media-pro', array( 'shortpixel-image-optimiser/wp-shortpixel.php' ), $images ),
			'imagify'                   => array( 'Imagify', 'media-pro', array( 'imagify/imagify.php' ), $images ),
			'smush'                     => array( 'Smush', 'media-pro', array( 'wp-smushit/wp-smush.php', 'wp-smush-pro/wp-smush.php' ), $images ),
			'akismet'                   => array( 'Akismet', 'spam-pro', array( 'akismet/akismet.php' ), $spam ),
			'antispam-bee'              => array( 'Antispam Bee', 'spam-pro', array( 'antispam-bee/antispam_bee.php' ), $spam ),
			'advanced-google-recaptcha' => array( 'Advanced Google reCAPTCHA', 'spam-pro', array( 'advanced-google-recaptcha/advanced-google-recaptcha.php' ), $spam ),
			'simple-google-recaptcha'   => array( 'Simple Google reCAPTCHA', 'spam-pro', array( 'simple-google-recaptcha/simple-google-recaptcha.php' ), $spam ),
			'seo-pro-posts'             => array( 'Each post\'s own SEO fields', 'seo-pro', $seo, __( 'With Perdita Pro, you can bring over each post\'s SEO title, description, and social fields.', 'perdita-core' ), '' ),
			'redirection'               => array( 'Redirection', 'seo-pro', array( 'redirection/redirection.php' ), __( 'With Perdita Pro, you can bring over your redirects.', 'perdita-core' ) ),
			'seo-pro-redirects'         => array( 'Redirects from SEO plugins', 'seo-pro', array( 'wordpress-seo-premium/wp-seo-premium.php', 'seo-by-rank-math/rank-math.php', 'seo-by-rank-math-pro/rank-math-pro.php', 'wp-seopress-pro/seopress-pro.php', 'wp-seopress/seopress.php', 'all-in-one-seo-pack-pro/all_in_one_seo_pack.php' ), __( 'With Perdita Pro, you can bring over the redirects you made in your SEO plugin.', 'perdita-core' ), '' ),
			'fluent-smtp-failover'      => array( 'FluentSMTP fallback connection', 'smtp-pro', array( 'fluent-smtp/fluent-smtp.php' ), __( 'With Perdita Pro, you can bring over the fallback connection, so you can still send mail while the first connection is down.', 'perdita-core' ), 'FluentSMTP' ),
			'wordfence'                 => array( 'Wordfence, Pro settings', 'security-pro', array( 'wordfence/wordfence.php' ), $secure, 'Wordfence' ),
			'solid-security'            => array( 'Solid Security, Pro settings', 'security-pro', array( 'better-wp-security/better-wp-security.php', 'ithemes-security-pro/ithemes-security-pro.php' ), $secure, 'Solid Security' ),
			'aios'                      => array( 'All In One WP Security, Pro settings', 'security-pro', array( 'all-in-one-wp-security-and-firewall/wp-security.php' ), $secure, 'All In One WP Security' ),
			'sucuri-pro'                => array( 'Sucuri Security, Pro settings', 'security-pro', array( 'sucuri-scanner/sucuri.php' ), $secure, 'Sucuri Security' ),
			'shield-pro'                => array( 'Shield Security, Pro settings', 'security-pro', array( 'wp-simple-firewall/icwp-wpsf.php' ), $secure, 'Shield Security' ),
			'defender-pro'              => array( 'Defender, Pro settings', 'security-pro', array( 'defender-security/wp-defender.php', 'wp-defender/wp-defender.php' ), $secure, 'Defender' ),
			'patchstack-pro'            => array( 'Patchstack, Pro settings', 'security-pro', array( 'patchstack/patchstack.php' ), $secure, 'Patchstack' ),
			'loginizer-pro'             => array( 'Loginizer, Pro settings', 'security-pro', array( 'loginizer/loginizer.php' ), $secure, 'Loginizer' ),
			'llar-pro'                  => array( 'Limit Login Attempts Reloaded, Pro settings', 'security-pro', array( 'limit-login-attempts-reloaded/limit-login-attempts-reloaded.php' ), $secure, 'Limit Login Attempts Reloaded' ),
			'updraftplus'               => array( 'UpdraftPlus', 'backups-pro', array( 'updraftplus/updraftplus.php' ), $backup ),
			'backwpup'                  => array( 'BackWPup', 'backups-pro', array( 'backwpup/backwpup.php', 'backwpup-pro/backwpup.php' ), $backup ),
			'contact-form-7-pro'        => array( 'Contact Form 7, uploads and autoresponder', 'forms-pro', array( 'contact-form-7/wp-contact-form-7.php' ), __( 'With Perdita Pro, you can bring over file upload fields and the second mail, Contact Form 7\'s autoresponder.', 'perdita-core' ), 'Contact Form 7' ),
		);
		$out = array();
		foreach ( $list as $id => $row ) {
			$out[ $id ] = array(
				'label'   => $row[0],
				'module'  => $row[1],
				'plugins' => $row[2],
				'covers'  => $row[3],
				'plugin'  => isset( $row[4] ) ? $row[4] : $row[0],
			);
		}
		return $out;
	}

	/**
	 * The Pro-only sources no registered source answers yet.
	 *
	 * @param array $registered Perdita_Switch::sources().
	 * @return array id => entry plus installed and active (bool).
	 */
	public static function missing( array $registered ) {
		$plugins = Perdita_Switch_Source::installed_plugins();
		$out     = array();
		foreach ( self::pro_sources() as $id => $row ) {
			if ( isset( $registered[ $id ] ) ) {
				continue;
			}
			$row['installed'] = false;
			$row['active']    = false;
			foreach ( $row['plugins'] as $basename ) {
				if ( isset( $plugins[ $basename ] ) ) {
					$row['installed'] = true;
					if ( Perdita_Switch_Source::plugin_active( $basename ) ) {
						$row['active'] = true;
					}
				}
			}
			$out[ $id ] = $row;
		}
		return $out;
	}

	/**
	 * Where Perdita Pro stands on this site, for the locked cards.
	 *
	 * @return string missing, inactive, unlicensed, or outdated (licensed,
	 *                but this Pro has no such source).
	 */
	public static function pro_state() {
		$plugins = Perdita_Switch_Source::installed_plugins();
		if ( ! isset( $plugins['perdita-pro/perdita-pro.php'] ) ) {
			return 'missing';
		}
		if ( ! Perdita_Switch_Source::plugin_active( 'perdita-pro/perdita-pro.php' ) ) {
			return 'inactive';
		}
		if ( ! class_exists( 'Perdita_Pro_License' ) || ! Perdita_Pro_License::is_active() ) {
			return 'unlicensed';
		}
		return 'outdated';
	}

	/**
	 * The sentence and link a locked card ends with.
	 *
	 * @param string $state pro_state().
	 * @return array text, link, url.
	 */
	public static function pro_action( $state ) {
		switch ( $state ) {
			case 'inactive':
				return array(
					'text' => __( 'You have Perdita Pro installed. Activate it to run this import.', 'perdita-core' ),
					'link' => __( 'Go to Plugins', 'perdita-core' ),
					'url'  => admin_url( 'plugins.php' ),
				);
			case 'unlicensed':
				return array(
					'text' => __( 'Add your Perdita Pro license key to run this import.', 'perdita-core' ),
					'link' => __( 'Add your key', 'perdita-core' ),
					'url'  => admin_url( 'admin.php?page=perdita-pro' ),
				);
			case 'outdated':
				return array(
					'text' => __( 'Update Perdita Pro to run this import.', 'perdita-core' ),
					'link' => __( 'Go to Updates', 'perdita-core' ),
					'url'  => admin_url( 'update-core.php' ),
				);
		}
		return array(
			'text' => __( 'This import runs in Perdita Pro.', 'perdita-core' ),
			'link' => __( 'About Perdita Pro', 'perdita-core' ),
			/**
			 * Filters the link a locked Switch to Perdita card gives someone
			 * without Perdita Pro.
			 *
			 * @param string $url URL.
			 */
			'url'  => (string) apply_filters( 'perdita_switch_pro_url', self::PRO_URL ),
		);
	}
}
