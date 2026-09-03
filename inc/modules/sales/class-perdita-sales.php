<?php
/**
 * Sales module: a slim store for digital downloads and simple physical goods.
 *
 * This is deliberately small, but the parts that touch money and files are
 * built to be hard to abuse. Three rules drive the whole design:
 *
 *   1. Never trust a client-supplied price. The cart stores only product ids
 *      and quantities, server-side, keyed by a random id in a cookie. Every
 *      total (line price, shipping, tax) is recomputed from product meta at
 *      checkout time. There is no code path where a price comes off the wire.
 *
 *   2. Verify the payment webhook signature. The Stripe webhook route is public
 *      (Stripe has no login), so it verifies the Stripe-Signature header with
 *      HMAC-SHA256 over "timestamp.payload" against the encrypted signing
 *      secret, with a timestamp tolerance to stop replay. An unverified request
 *      never changes an order. This mirrors the relay's verifyStripe().
 *
 *   3. Download links are signed, expiring, and immune to IDOR. A link carries
 *      an HMAC (keyed by a per-site secret, not a guessable id) over
 *      order + product + file index + expiry. The endpoint recomputes the HMAC
 *      with hash_equals, checks the expiry, confirms the order is paid and
 *      actually contains that product, then streams the file only after
 *      realpath-confirming it sits inside uploads. A raw file id in a URL grants
 *      nothing on its own.
 *
 * Secrets (the Stripe secret key and webhook signing secret) are stored
 * encrypted with Perdita_Crypto, whose key derives from wp-config salts, so a
 * database dump alone does not reveal them.
 *
 * Scope: one gateway (Stripe hosted Checkout, which keeps card data off-site so
 * there is no PCI card handling here), no subscriptions, coupons, or variable
 * products. Those are Pro.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

class Perdita_Sales {

	/**
	 * Product custom post type.
	 */
	const CPT_PRODUCT = 'perdita_product';

	/**
	 * Order custom post type.
	 */
	const CPT_ORDER = 'perdita_order';

	/**
	 * Settings option name.
	 */
	const OPTION = 'perdita_sales_settings';

	/**
	 * Cart cookie name. Holds only a random cart id, never prices or items.
	 */
	const COOKIE = 'perdita_cart';

	/**
	 * Transient key prefix for a server-side cart.
	 */
	const CART_PREFIX = 'perdita_sales_cart_';

	/**
	 * How long a cart transient lives without activity.
	 */
	const CART_TTL = DAY_IN_SECONDS;

	/**
	 * REST namespace.
	 */
	const NS = 'perdita/v1';

	/**
	 * How long a signed download link is valid, in seconds.
	 */
	const DOWNLOAD_TTL = 86400; // 24 hours.

	/**
	 * Webhook timestamp tolerance, in seconds. Rejects old/replayed events.
	 */
	const WEBHOOK_TOLERANCE = 300;

	/**
	 * The Perdita core singleton.
	 *
	 * @var Perdita
	 */
	private $core;

	/**
	 * Cached decoded cart for this request, so repeated reads don't re-hit the
	 * transient store. Null until first loaded.
	 *
	 * @var array|null
	 */
	private $cart_cache = null;

	/**
	 * Constructor. Register the CPTs, shortcodes, cart handling, and REST routes.
	 *
	 * @param Perdita $core Core.
	 */
	public function __construct( $core ) {
		$this->core = $core;

		add_action( 'init', array( $this, 'register_cpts' ) );

		// Shortcodes.
		add_shortcode( 'perdita_product', array( $this, 'shortcode_product' ) );
		add_shortcode( 'perdita_products', array( $this, 'shortcode_products' ) );
		add_shortcode( 'perdita_cart', array( $this, 'shortcode_cart' ) );
		add_shortcode( 'perdita_checkout', array( $this, 'shortcode_checkout' ) );

		// Cart + checkout form posts (front end, nonce protected).
		add_action( 'admin_post_nopriv_perdita_sales_cart', array( $this, 'handle_cart_post' ) );
		add_action( 'admin_post_perdita_sales_cart', array( $this, 'handle_cart_post' ) );
		add_action( 'admin_post_nopriv_perdita_sales_checkout', array( $this, 'handle_checkout_post' ) );
		add_action( 'admin_post_perdita_sales_checkout', array( $this, 'handle_checkout_post' ) );

		// Secure download handler. A plain template_redirect gate keyed by a
		// query var, so the URL is friendly and there is no admin dependency.
		add_action( 'template_redirect', array( $this, 'maybe_stream_download' ) );

		// Front-end store styles.
		if ( ! is_admin() ) {
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		}

		// REST: the public Stripe webhook and a signed download endpoint.
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Settings                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'currency'         => 'USD',
			'publishable_key'  => '',      // Stripe publishable key (plain, it is public).
			'secret_key'       => '',      // Encrypted blob at rest.
			'webhook_secret'   => '',      // Encrypted blob at rest.
			'tax_rate'         => 0.0,     // Flat percent, e.g. 8.5 means 8.5%.
			'download_secret'  => '',      // Per-site HMAC key for download links.
			'max_downloads'    => 5,       // Per (order,product,file); 0 means unlimited.
			'success_page'     => 0,       // Optional page id for the success screen.
		);
	}

	/**
	 * Read stored settings merged over defaults.
	 *
	 * @return array
	 */
	public static function settings() {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return array_merge( self::defaults(), $stored );
	}

	/**
	 * The store currency, a validated 3-letter uppercase code.
	 *
	 * @return string
	 */
	public static function currency() {
		$c = strtoupper( (string) self::settings()['currency'] );
		return preg_match( '/^[A-Z]{3}$/', $c ) ? $c : 'USD';
	}

	/**
	 * The decrypted Stripe secret key, or '' when unset.
	 *
	 * @return string
	 */
	public static function secret_key() {
		return Perdita_Crypto::decrypt( (string) self::settings()['secret_key'] );
	}

	/**
	 * The decrypted webhook signing secret, or '' when unset.
	 *
	 * @return string
	 */
	public static function webhook_secret() {
		return Perdita_Crypto::decrypt( (string) self::settings()['webhook_secret'] );
	}

	/**
	 * The per-site download-signing secret. Generated on install; regenerated
	 * lazily here if somehow empty so links can never be signed with a blank key.
	 *
	 * @return string
	 */
	public static function download_secret() {
		$s = self::settings();
		$secret = (string) $s['download_secret'];
		if ( '' === $secret ) {
			$secret = self::generate_download_secret();
			$s['download_secret'] = $secret;
			update_option( self::OPTION, $s );
		}
		return $secret;
	}

	/**
	 * Make a fresh random download-signing secret.
	 *
	 * @return string
	 */
	private static function generate_download_secret() {
		return bin2hex( random_bytes( 32 ) );
	}

	/**
	 * Enqueue the front-end store stylesheet, but only on a page that actually
	 * renders store markup. Registered handle keeps it slim and cache-busted by
	 * the theme version.
	 *
	 * Enabling the module used to add store.css to every front-end page on the
	 * site, blog posts and contact pages included, for markup that appears on a
	 * handful of them. This mirrors how the related-posts module gates its own
	 * stylesheet.
	 */
	public function enqueue_assets() {
		if ( ! $this->needs_store_css() ) {
			return;
		}
		wp_enqueue_style(
			'perdita-sales',
			PERDITA_CORE_URL . 'inc/modules/sales/assets/store.css',
			array(),
			PERDITA_CORE_VERSION
		);
	}

	/**
	 * Whether this request renders store markup: a single product, the product
	 * archive, or a singular post/page whose content carries one of the
	 * module's shortcodes.
	 *
	 * @return bool
	 */
	private function needs_store_css() {
		if ( is_singular( self::CPT_PRODUCT ) || is_post_type_archive( self::CPT_PRODUCT ) ) {
			return true;
		}
		if ( ! is_singular() ) {
			return false;
		}
		$post = get_post();
		if ( ! $post instanceof WP_Post || '' === (string) $post->post_content ) {
			return false;
		}
		foreach ( self::shortcode_tags() as $tag ) {
			if ( has_shortcode( $post->post_content, $tag ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The shortcode tags this module registers. Mirrors the add_shortcode()
	 * calls in the constructor: add one there, add it here, or the stylesheet
	 * stops loading on the pages that use it.
	 *
	 * @return string[]
	 */
	private static function shortcode_tags() {
		return array( 'perdita_product', 'perdita_products', 'perdita_cart', 'perdita_checkout' );
	}

	/* ------------------------------------------------------------------ */
	/* Custom post types                                                  */
	/* ------------------------------------------------------------------ */

	/**
	 * Register the product and order post types. Products are editable in the
	 * admin under the normal edit_posts capability the CPT enforces. Orders are
	 * created by the checkout flow and only ever viewed in the admin, so they are
	 * not publicly queryable and cannot be created from the post editor.
	 */
	public function register_cpts() {
		register_post_type(
			self::CPT_PRODUCT,
			array(
				'labels'          => array(
					'name'          => __( 'Products', 'perdita-core' ),
					'singular_name' => __( 'Product', 'perdita-core' ),
					'add_new'       => __( 'Add Product', 'perdita-core' ),
					'add_new_item'  => __( 'Add Product', 'perdita-core' ),
					'edit_item'     => __( 'Edit Product', 'perdita-core' ),
					'new_item'      => __( 'New Product', 'perdita-core' ),
					'view_item'     => __( 'View Product', 'perdita-core' ),
					'search_items'  => __( 'Search Products', 'perdita-core' ),
					'menu_name'     => __( 'Store', 'perdita-core' ),
				),
				'public'          => true,
				'has_archive'     => true,
				'show_in_menu'    => true,
				'menu_icon'       => 'dashicons-cart',
				'menu_position'   => 26,
				'supports'        => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
				'rewrite'         => array( 'slug' => 'products' ),
				'capability_type' => 'post',
			)
		);

		register_post_type(
			self::CPT_ORDER,
			array(
				'labels'              => array(
					'name'          => __( 'Orders', 'perdita-core' ),
					'singular_name' => __( 'Order', 'perdita-core' ),
				),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => false, // Admin renders its own orders screen.
				'show_in_menu'        => false,
				'supports'            => array( 'title' ),
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Product meta helpers                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * Read a product's normalized meta. Prices are always read from here at
	 * checkout time, never from client input.
	 *
	 * @param int $product_id Product post id.
	 * @return array|null Null when the id is not a published product.
	 */
	public static function product( $product_id ) {
		$product_id = (int) $product_id;
		$post = get_post( $product_id );
		if ( ! $post || self::CPT_PRODUCT !== $post->post_type || 'publish' !== $post->post_status ) {
			return null;
		}

		$type = get_post_meta( $product_id, '_perdita_sales_type', true );
		$type = ( 'physical' === $type ) ? 'physical' : 'digital';

		return array(
			'id'         => $product_id,
			'title'      => get_the_title( $product_id ),
			'price'      => self::normalize_amount( get_post_meta( $product_id, '_perdita_sales_price', true ) ),
			'type'       => $type,
			'file_id'    => (int) get_post_meta( $product_id, '_perdita_sales_file_id', true ),
			'file_path'  => (string) get_post_meta( $product_id, '_perdita_sales_file_path', true ),
			'stock'      => (int) get_post_meta( $product_id, '_perdita_sales_stock', true ),
			'shipping'   => self::normalize_amount( get_post_meta( $product_id, '_perdita_sales_shipping', true ) ),
		);
	}

	/**
	 * Clamp any amount-ish input to a non-negative decimal with 2 places. This is
	 * the single choke point for money coming out of meta, so a corrupt or
	 * hostile stored value can't produce a negative or absurd charge.
	 *
	 * @param mixed $raw Raw value.
	 * @return float
	 */
	public static function normalize_amount( $raw ) {
		$n = is_scalar( $raw ) ? (float) $raw : 0.0;
		if ( ! is_finite( $n ) || $n < 0 ) {
			$n = 0.0;
		}
		return round( $n, 2 );
	}

	/**
	 * The absolute file path for a digital product's file, or '' when there is
	 * none. Resolves an attachment id first, then a stored relative/absolute
	 * path, and always confirms the result is inside the uploads directory. This
	 * is the security gate that download streaming relies on.
	 *
	 * @param array $product Product array from self::product().
	 * @return string Absolute path inside uploads, or '' if invalid.
	 */
	public static function product_file_path( $product ) {
		$uploads = wp_get_upload_dir();
		$base    = isset( $uploads['basedir'] ) ? realpath( $uploads['basedir'] ) : false;
		if ( false === $base ) {
			return '';
		}

		$candidate = '';
		if ( ! empty( $product['file_id'] ) ) {
			$attached = get_attached_file( (int) $product['file_id'] );
			if ( $attached ) {
				$candidate = $attached;
			}
		}
		if ( '' === $candidate && ! empty( $product['file_path'] ) ) {
			$stored = (string) $product['file_path'];
			// A stored path may be relative to uploads or already absolute.
			$candidate = ( '/' === substr( $stored, 0, 1 ) )
				? $stored
				: trailingslashit( $uploads['basedir'] ) . ltrim( $stored, '/' );
		}
		if ( '' === $candidate ) {
			return '';
		}

		$real = realpath( $candidate );
		if ( false === $real || ! is_file( $real ) ) {
			return '';
		}

		// Containment check: the resolved real path must sit under the uploads
		// real base. realpath() has collapsed any ../ traversal, so a path that
		// escaped uploads fails this and is rejected.
		$base_slash = trailingslashit( $base );
		if ( 0 !== strpos( $real, $base_slash ) ) {
			return '';
		}
		return $real;
	}

	/* ------------------------------------------------------------------ */
	/* Cart (server-side, keyed by a random cookie id)                    */
	/* ------------------------------------------------------------------ */

	/**
	 * The current cart id from the cookie, or '' if none. Validated to the exact
	 * shape we mint so a hostile cookie can't steer the transient key.
	 *
	 * @return string
	 */
	private function cart_id() {
		if ( empty( $_COOKIE[ self::COOKIE ] ) ) {
			return '';
		}
		$id = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) );
		return preg_match( '/^[a-f0-9]{32}$/', $id ) ? $id : '';
	}

	/**
	 * Get the current cart id, minting one (and setting the cookie) if needed.
	 *
	 * @return string
	 */
	private function ensure_cart_id() {
		$id = $this->cart_id();
		if ( '' !== $id ) {
			return $id;
		}
		$id = bin2hex( random_bytes( 16 ) );
		// HttpOnly so page JS can't read the id, SameSite=Lax to blunt CSRF, and
		// Secure whenever the request is over TLS.
		$secure = is_ssl();
		if ( ! headers_sent() ) {
			setcookie(
				self::COOKIE,
				$id,
				array(
					'expires'  => time() + self::CART_TTL,
					'path'     => COOKIEPATH ? COOKIEPATH : '/',
					'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
					'secure'   => $secure,
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
		}
		$_COOKIE[ self::COOKIE ] = $id; // Available within this request too.
		return $id;
	}

	/**
	 * The transient key for a cart id.
	 *
	 * @param string $cart_id Cart id.
	 * @return string
	 */
	private function cart_transient( $cart_id ) {
		return self::CART_PREFIX . $cart_id;
	}

	/**
	 * Read the current cart as an id => qty map. Only product ids and quantities
	 * are ever stored, never prices.
	 *
	 * @return array Map of product_id => qty.
	 */
	public function get_cart() {
		if ( null !== $this->cart_cache ) {
			return $this->cart_cache;
		}
		$id = $this->cart_id();
		if ( '' === $id ) {
			$this->cart_cache = array();
			return $this->cart_cache;
		}
		$stored = get_transient( $this->cart_transient( $id ) );
		$cart   = array();
		if ( is_array( $stored ) ) {
			foreach ( $stored as $pid => $qty ) {
				$pid = (int) $pid;
				$qty = (int) $qty;
				if ( $pid > 0 && $qty > 0 ) {
					$cart[ $pid ] = $qty;
				}
			}
		}
		$this->cart_cache = $cart;
		return $cart;
	}

	/**
	 * Persist a cart map.
	 *
	 * @param array $cart Map of product_id => qty.
	 */
	private function save_cart( array $cart ) {
		$id = $this->ensure_cart_id();
		$clean = array();
		foreach ( $cart as $pid => $qty ) {
			$pid = (int) $pid;
			$qty = (int) $qty;
			if ( $pid > 0 && $qty > 0 ) {
				$clean[ $pid ] = min( $qty, 999 );
			}
		}
		set_transient( $this->cart_transient( $id ), $clean, self::CART_TTL );
		$this->cart_cache = $clean;
	}

	/**
	 * Compute cart totals server-side from product meta. This is the only place
	 * cart money is derived, and it never reads a client price.
	 *
	 * @param array|null $cart Optional explicit cart; defaults to the live cart.
	 * @return array {
	 *     @type array $lines    Per-line details.
	 *     @type float $subtotal Sum of line totals.
	 *     @type float $shipping Total shipping.
	 *     @type float $tax      Tax on subtotal + shipping.
	 *     @type float $total    Grand total.
	 *     @type bool  $physical Whether any physical item is present.
	 * }
	 */
	public function compute_totals( $cart = null ) {
		$cart = ( null === $cart ) ? $this->get_cart() : $cart;

		$lines    = array();
		$subtotal = 0.0;
		$shipping = 0.0;
		$physical = false;

		foreach ( $cart as $pid => $qty ) {
			$product = self::product( $pid );
			if ( null === $product ) {
				continue; // Skip products that vanished or unpublished.
			}
			$qty        = max( 1, (int) $qty );
			$unit       = $product['price'];
			$line_total = round( $unit * $qty, 2 );
			$subtotal  += $line_total;

			if ( 'physical' === $product['type'] ) {
				$physical  = true;
				$shipping += round( $product['shipping'] * $qty, 2 );
			}

			$lines[] = array(
				'id'         => $product['id'],
				'title'      => $product['title'],
				'type'       => $product['type'],
				'qty'        => $qty,
				'unit_price' => $unit,
				'line_total' => $line_total,
			);
		}

		$tax_rate = self::normalize_amount( self::settings()['tax_rate'] );
		$tax      = round( ( $subtotal + $shipping ) * ( $tax_rate / 100 ), 2 );
		$total    = round( $subtotal + $shipping + $tax, 2 );

		return array(
			'lines'    => $lines,
			'subtotal' => round( $subtotal, 2 ),
			'shipping' => round( $shipping, 2 ),
			'tax'      => $tax,
			'total'    => $total,
			'physical' => $physical,
		);
	}

	/* ------------------------------------------------------------------ */
	/* Cart form handling                                                 */
	/* ------------------------------------------------------------------ */

	/**
	 * Handle add/update/remove cart posts. Nonce protected. Quantities and ids
	 * are the only inputs, and both are cast to ints.
	 */
	public function handle_cart_post() {
		check_admin_referer( 'perdita_sales_cart' );

		$op   = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
		$cart = $this->get_cart();

		if ( 'add' === $op ) {
			$pid = isset( $_POST['product_id'] ) ? (int) $_POST['product_id'] : 0;
			$qty = isset( $_POST['qty'] ) ? max( 1, (int) $_POST['qty'] ) : 1;
			$product = self::product( $pid );
			if ( null !== $product ) {
				// For a physical product, never let the cart exceed available stock.
				$current = isset( $cart[ $pid ] ) ? (int) $cart[ $pid ] : 0;
				$want    = $current + $qty;
				if ( 'physical' === $product['type'] ) {
					$want = min( $want, max( 0, (int) $product['stock'] ) );
				}
				if ( $want > 0 ) {
					$cart[ $pid ] = $want;
				}
			}
		} elseif ( 'update' === $op ) {
			$items = isset( $_POST['qty'] ) && is_array( $_POST['qty'] ) ? wp_unslash( $_POST['qty'] ) : array();
			foreach ( $items as $pid => $qty ) {
				$pid = (int) $pid;
				$qty = (int) $qty;
				if ( $pid <= 0 ) {
					continue;
				}
				if ( $qty <= 0 ) {
					unset( $cart[ $pid ] );
					continue;
				}
				$product = self::product( $pid );
				if ( null === $product ) {
					unset( $cart[ $pid ] );
					continue;
				}
				if ( 'physical' === $product['type'] ) {
					$qty = min( $qty, max( 0, (int) $product['stock'] ) );
				}
				if ( $qty > 0 ) {
					$cart[ $pid ] = $qty;
				} else {
					unset( $cart[ $pid ] );
				}
			}
		} elseif ( 'remove' === $op ) {
			$pid = isset( $_POST['product_id'] ) ? (int) $_POST['product_id'] : 0;
			unset( $cart[ $pid ] );
		}

		$this->save_cart( $cart );

		$redirect = wp_get_referer();
		if ( ! $redirect ) {
			$redirect = home_url( '/' );
		}
		wp_safe_redirect( $redirect );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Checkout                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Handle the checkout post: recompute totals server-side, create a pending
	 * order, create a Stripe Checkout Session, and redirect to it. Nonce
	 * protected. No price is ever read from the request.
	 */
	public function handle_checkout_post() {
		check_admin_referer( 'perdita_sales_checkout' );

		$cart = $this->get_cart();
		if ( empty( $cart ) ) {
			$this->checkout_error( __( 'Your cart is empty.', 'perdita-core' ) );
		}

		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		if ( ! is_email( $email ) ) {
			$this->checkout_error( __( 'Please enter a valid email address.', 'perdita-core' ) );
		}

		// Recompute EVERYTHING from product meta, server-side.
		$totals = $this->compute_totals( $cart );
		if ( empty( $totals['lines'] ) || $totals['total'] <= 0 ) {
			$this->checkout_error( __( 'There is nothing to pay for. Please check your cart.', 'perdita-core' ) );
		}

		// Shipping address is required only when a physical item is present.
		$shipping_address = array();
		$reserved_stock   = array(); // product_id => qty, for release on a later failure.
		if ( $totals['physical'] ) {
			$shipping_address = $this->read_shipping_address();
			if ( '' === $shipping_address['line1'] || '' === $shipping_address['city'] || '' === $shipping_address['postcode'] ) {
				$this->checkout_error( __( 'Please enter a shipping address for your physical items.', 'perdita-core' ) );
			}
			// Claim stock NOW, atomically, rather than just checking it: a
			// plain read-then-write check here would let two shoppers
			// checking out for the last unit at the same moment both pass
			// before either one's Stripe webhook ever reaches
			// decrement_stock(), overselling the product. reserve_stock()
			// closes that race with a single conditional UPDATE. Any slot
			// already reserved by an earlier line in this same cart is
			// released again if a later line can't be reserved, or if
			// checkout fails afterward for an unrelated reason below.
			foreach ( $totals['lines'] as $line ) {
				if ( 'physical' !== $line['type'] ) {
					continue;
				}
				if ( ! $this->reserve_stock( $line['id'], $line['qty'] ) ) {
					foreach ( $reserved_stock as $reserved_id => $reserved_qty ) {
						$this->release_stock( $reserved_id, $reserved_qty );
					}
					/* translators: %s: product name */
					$this->checkout_error( sprintf( __( 'Sorry, "%s" is out of stock.', 'perdita-core' ), $line['title'] ) );
				}
				$reserved_stock[ $line['id'] ] = ( $reserved_stock[ $line['id'] ] ?? 0 ) + $line['qty'];
			}
		}

		$order_id = $this->create_order( $totals, $email, $shipping_address );
		if ( ! $order_id ) {
			foreach ( $reserved_stock as $reserved_id => $reserved_qty ) {
				$this->release_stock( $reserved_id, $reserved_qty );
			}
			$this->checkout_error( __( 'We could not start your order. Please try again.', 'perdita-core' ) );
		}

		$session = $this->create_stripe_session( $order_id, $totals, $email );
		if ( is_wp_error( $session ) ) {
			foreach ( $reserved_stock as $reserved_id => $reserved_qty ) {
				$this->release_stock( $reserved_id, $reserved_qty );
			}
			$this->checkout_error( $session->get_error_message() );
		}

		update_post_meta( $order_id, '_perdita_sales_session_id', $session['id'] );
		update_post_meta( $order_id, '_perdita_sales_gateway_ref', $session['id'] );

		// Off to Stripe's hosted page. wp_redirect (not wp_safe_redirect) because
		// the destination is an external Stripe URL by design.
		wp_redirect( esc_url_raw( $session['url'] ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- external gateway URL from Stripe API.
		exit;
	}

	/**
	 * Read and sanitize the shipping address fields.
	 *
	 * @return array
	 */
	private function read_shipping_address() {
		$field = function ( $key ) {
			return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked by caller.
		};
		return array(
			'name'     => $field( 'ship_name' ),
			'line1'    => $field( 'ship_line1' ),
			'line2'    => $field( 'ship_line2' ),
			'city'     => $field( 'ship_city' ),
			'state'    => $field( 'ship_state' ),
			'postcode' => $field( 'ship_postcode' ),
			'country'  => $field( 'ship_country' ),
		);
	}

	/**
	 * Create a pending order post capturing server-side prices.
	 *
	 * @param array  $totals           Totals from compute_totals().
	 * @param string $email            Customer email.
	 * @param array  $shipping_address Sanitized shipping address.
	 * @return int Order post id, or 0 on failure.
	 */
	private function create_order( array $totals, $email, array $shipping_address ) {
		$order_id = wp_insert_post(
			array(
				'post_type'   => self::CPT_ORDER,
				'post_status' => 'publish', // Internal CPT; status lives in meta.
				/* translators: %s: current datetime */
				'post_title'  => sprintf( __( 'Order %s', 'perdita-core' ), current_time( 'mysql' ) ),
			),
			true
		);
		if ( is_wp_error( $order_id ) || ! $order_id ) {
			return 0;
		}

		// Store each line with the unit price CAPTURED server-side at this moment,
		// so a later price change on the product does not alter a placed order.
		$items = array();
		foreach ( $totals['lines'] as $line ) {
			$items[] = array(
				'id'         => (int) $line['id'],
				'title'      => (string) $line['title'],
				'type'       => (string) $line['type'],
				'qty'        => (int) $line['qty'],
				'unit_price' => self::normalize_amount( $line['unit_price'] ),
			);
		}

		update_post_meta( $order_id, '_perdita_sales_status', 'pending' );
		update_post_meta( $order_id, '_perdita_sales_items', $items );
		update_post_meta( $order_id, '_perdita_sales_subtotal', $totals['subtotal'] );
		update_post_meta( $order_id, '_perdita_sales_shipping', $totals['shipping'] );
		update_post_meta( $order_id, '_perdita_sales_tax', $totals['tax'] );
		update_post_meta( $order_id, '_perdita_sales_total', $totals['total'] );
		update_post_meta( $order_id, '_perdita_sales_currency', self::currency() );
		update_post_meta( $order_id, '_perdita_sales_email', $email );
		update_post_meta( $order_id, '_perdita_sales_shipping_address', $shipping_address );
		update_post_meta( $order_id, '_perdita_sales_gateway_ref', '' );
		update_post_meta( $order_id, '_perdita_sales_created', current_time( 'mysql' ) );

		return (int) $order_id;
	}

	/**
	 * Create a Stripe hosted Checkout Session for an order. Line items are priced
	 * from the SERVER-SIDE order totals, in the smallest currency unit. Shipping
	 * and tax are added as their own line items so the amount Stripe charges
	 * equals the order total exactly.
	 *
	 * @param int    $order_id Order id.
	 * @param array  $totals   Server-side totals.
	 * @param string $email    Customer email.
	 * @return array|WP_Error {id, url} on success.
	 */
	private function create_stripe_session( $order_id, array $totals, $email ) {
		$secret = self::secret_key();
		if ( '' === $secret ) {
			return new WP_Error( 'no_key', __( 'The store is not configured for payments yet. Please contact the site owner.', 'perdita-core' ) );
		}

		$currency = strtolower( self::currency() );
		$body     = array(
			'mode'                => 'payment',
			'success_url'         => add_query_arg(
				array(
					'perdita_sales'    => 'success',
					'order'            => $order_id,
				),
				$this->success_url()
			) . '&session_id={CHECKOUT_SESSION_ID}',
			'cancel_url'          => add_query_arg(
				array(
					'perdita_sales' => 'cancel',
					'order'         => $order_id,
				),
				$this->cancel_url()
			),
			'client_reference_id' => (string) $order_id,
			'customer_email'      => $email,
		);
		$body['metadata']['order_id'] = (string) $order_id;

		// Build line items from the SERVER-SIDE totals only.
		$i = 0;
		foreach ( $totals['lines'] as $line ) {
			$body[ "line_items[$i][price_data][currency]" ]              = $currency;
			$body[ "line_items[$i][price_data][product_data][name]" ]    = $line['title'];
			$body[ "line_items[$i][price_data][unit_amount]" ]           = (string) self::to_minor_units( $line['unit_price'] );
			$body[ "line_items[$i][quantity]" ]                          = (string) (int) $line['qty'];
			$i++;
		}
		if ( $totals['shipping'] > 0 ) {
			$body[ "line_items[$i][price_data][currency]" ]           = $currency;
			$body[ "line_items[$i][price_data][product_data][name]" ] = __( 'Shipping', 'perdita-core' );
			$body[ "line_items[$i][price_data][unit_amount]" ]        = (string) self::to_minor_units( $totals['shipping'] );
			$body[ "line_items[$i][quantity]" ]                       = '1';
			$i++;
		}
		if ( $totals['tax'] > 0 ) {
			$body[ "line_items[$i][price_data][currency]" ]           = $currency;
			$body[ "line_items[$i][price_data][product_data][name]" ] = __( 'Tax', 'perdita-core' );
			$body[ "line_items[$i][price_data][unit_amount]" ]        = (string) self::to_minor_units( $totals['tax'] );
			$body[ "line_items[$i][quantity]" ]                       = '1';
			$i++;
		}

		$response = wp_remote_post(
			'https://api.stripe.com/v1/checkout/sessions',
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Bearer ' . $secret,
					'Content-Type'  => 'application/x-www-form-urlencoded',
				),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'stripe_unreachable', __( 'Could not reach the payment provider. Please try again.', 'perdita-core' ) );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== (int) $code || ! is_array( $data ) || empty( $data['id'] ) || empty( $data['url'] ) ) {
			return new WP_Error( 'stripe_error', __( 'The payment provider rejected the request. Please try again.', 'perdita-core' ) );
		}

		return array(
			'id'  => (string) $data['id'],
			'url' => (string) $data['url'],
		);
	}

	/**
	 * Convert a major-unit decimal amount (e.g. 12.34) to integer minor units
	 * (1234). Zero-decimal currencies are not special-cased in this slim build;
	 * that is a Pro concern.
	 *
	 * @param float $amount Amount in major units.
	 * @return int
	 */
	public static function to_minor_units( $amount ) {
		return (int) round( self::normalize_amount( $amount ) * 100 );
	}

	/**
	 * The base URL for a checkout success return.
	 *
	 * @return string
	 */
	private function success_url() {
		$page = (int) self::settings()['success_page'];
		if ( $page > 0 ) {
			$link = get_permalink( $page );
			if ( $link ) {
				return $link;
			}
		}
		return home_url( '/' );
	}

	/**
	 * The base URL for a cancelled checkout return.
	 *
	 * @return string
	 */
	private function cancel_url() {
		$ref = wp_get_referer();
		return $ref ? $ref : home_url( '/' );
	}

	/**
	 * Stop the checkout with a friendly error page.
	 *
	 * @param string $message Message to show.
	 */
	private function checkout_error( $message ) {
		wp_die(
			esc_html( $message ),
			esc_html__( 'Checkout', 'perdita-core' ),
			array(
				'response'  => 400,
				'back_link' => true,
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* REST: webhook + signed download                                    */
	/* ------------------------------------------------------------------ */

	/**
	 * Register the public webhook and the signed download REST routes.
	 */
	public function register_routes() {
		register_rest_route(
			self::NS,
			'/sales/webhook',
			array(
				'methods'             => 'POST',
				// Public by necessity: Stripe has no login. The signature check
				// inside is the real gate, not this callback.
				'permission_callback' => '__return_true',
				'callback'            => array( $this, 'handle_webhook' ),
			)
		);

		register_rest_route(
			self::NS,
			'/sales/download',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true', // Auth is the signed token, checked in the callback.
				'args'                => array(
					'order'   => array( 'required' => true ),
					'product' => array( 'required' => true ),
					'file'    => array( 'required' => false ),
					'expires' => array( 'required' => true ),
					'token'   => array( 'required' => true ),
				),
				'callback'            => array( $this, 'handle_rest_download' ),
			)
		);
	}

	/**
	 * Verify a Stripe webhook signature. Mirrors the relay's verifyStripe():
	 * parse t= and v1= from the Stripe-Signature header, reject timestamps
	 * outside the tolerance window (replay protection), then HMAC-SHA256 over
	 * "t.payload" and compare in constant time against every v1 (there can be
	 * several during a secret rotation).
	 *
	 * @param string $payload   Raw request body, exactly as received.
	 * @param string $sig_header The Stripe-Signature header value.
	 * @param string $secret    The webhook signing secret (decrypted).
	 * @return bool
	 */
	public static function verify_stripe_signature( $payload, $sig_header, $secret ) {
		if ( '' === (string) $sig_header || '' === (string) $secret ) {
			return false;
		}

		$t  = '';
		$v1 = array();
		foreach ( explode( ',', $sig_header ) as $part ) {
			$idx = strpos( $part, '=' );
			if ( false === $idx ) {
				continue;
			}
			$k   = substr( $part, 0, $idx );
			$val = substr( $part, $idx + 1 );
			if ( 't' === $k ) {
				$t = $val;
			} elseif ( 'v1' === $k ) {
				$v1[] = $val;
			}
		}
		if ( '' === $t || empty( $v1 ) || ! ctype_digit( $t ) ) {
			return false;
		}

		// Replay protection: reject events outside the tolerance window.
		$age = time() - (int) $t;
		if ( abs( $age ) > self::WEBHOOK_TOLERANCE ) {
			return false;
		}

		$signed   = $t . '.' . $payload;
		$expected = hash_hmac( 'sha256', $signed, $secret );

		foreach ( $v1 as $candidate ) {
			// hash_equals needs equal-length strings; both are 64-char lowercase
			// hex from sha256, so this is a like-for-like constant-time compare.
			if ( is_string( $candidate ) && hash_equals( $expected, strtolower( trim( $candidate ) ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Stripe webhook handler. Verifies the signature FIRST, then acts only on a
	 * checkout.session.completed whose order matches. Always returns 200 for a
	 * verified, handled event so Stripe stops retrying; never mutates an order on
	 * an unverified request.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public function handle_webhook( WP_REST_Request $req ) {
		$payload = $req->get_body();
		$sig     = $req->get_header( 'stripe_signature' ); // WP maps Stripe-Signature to this.
		$secret  = self::webhook_secret();

		if ( ! self::verify_stripe_signature( $payload, (string) $sig, $secret ) ) {
			// Unverified: refuse. 400, no state change.
			return new WP_REST_Response( array( 'error' => 'bad_signature' ), 400 );
		}

		$event = json_decode( $payload, true );
		if ( ! is_array( $event ) || empty( $event['type'] ) ) {
			return new WP_REST_Response( array( 'error' => 'bad_payload' ), 400 );
		}

		if ( 'checkout.session.completed' === $event['type'] ) {
			$obj      = isset( $event['data']['object'] ) && is_array( $event['data']['object'] ) ? $event['data']['object'] : array();
			$order_id = isset( $obj['client_reference_id'] ) ? (int) $obj['client_reference_id'] : 0;
			if ( ! $order_id && isset( $obj['metadata']['order_id'] ) ) {
				$order_id = (int) $obj['metadata']['order_id'];
			}
			$session_id = isset( $obj['id'] ) ? (string) $obj['id'] : '';

			$this->mark_order_paid( $order_id, $session_id );
		}

		// Ack every verified, handled event so Stripe does not retry-storm.
		return new WP_REST_Response( array( 'received' => true ), 200 );
	}

	/**
	 * Mark an order paid: idempotent, decrements stock once, sends the receipt.
	 * Cross-checks the session id on the order so a webhook can't flip an
	 * unrelated order to paid.
	 *
	 * @param int    $order_id   Order id.
	 * @param string $session_id Stripe session id from the event.
	 */
	private function mark_order_paid( $order_id, $session_id ) {
		$order_id = (int) $order_id;
		$order    = $order_id ? get_post( $order_id ) : null;
		if ( ! $order || self::CPT_ORDER !== $order->post_type ) {
			return;
		}

		// The event's session id must match the one we stored when creating the
		// order. A mismatch means the reference was tampered with; do nothing.
		$stored_session = (string) get_post_meta( $order_id, '_perdita_sales_session_id', true );
		if ( '' !== $stored_session && '' !== $session_id && ! hash_equals( $stored_session, $session_id ) ) {
			return;
		}

		// Idempotency: a second delivery of the same event must not double-charge
		// stock or re-send the receipt.
		if ( 'paid' === get_post_meta( $order_id, '_perdita_sales_status', true ) ) {
			return;
		}

		update_post_meta( $order_id, '_perdita_sales_status', 'paid' );
		update_post_meta( $order_id, '_perdita_sales_paid_at', current_time( 'mysql' ) );
		if ( '' !== $session_id ) {
			update_post_meta( $order_id, '_perdita_sales_gateway_ref', $session_id );
		}

		// No stock decrement here: reserve_stock() already claimed it
		// atomically at checkout time (see handle_checkout_post()), before
		// this order's Stripe session was even created.
		$this->send_receipt( $order_id );

		/**
		 * Fires after an order is confirmed paid.
		 *
		 * @param int $order_id Order id.
		 */
		do_action( 'perdita_sales_order_paid', $order_id );
	}

	/**
	 * Atomically reserve stock for one line item at checkout time, before
	 * creating the order or the Stripe Checkout Session -- see
	 * handle_checkout_post() for why a plain read-then-write check isn't
	 * enough. A single conditional UPDATE against the stock meta value: it
	 * only succeeds if enough stock is still recorded at the moment it
	 * runs, so at most as many reservations as actual stock can ever
	 * succeed, no matter how many checkouts race for the same product.
	 *
	 * @param int $product_id Product post id.
	 * @param int $qty        Quantity to reserve.
	 * @return bool True if reserved, false if not enough stock remained.
	 */
	private function reserve_stock( $product_id, $qty ) {
		global $wpdb;
		$product_id = (int) $product_id;
		$qty        = (int) $qty;
		if ( $product_id <= 0 || $qty <= 0 ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- prepared below; the Meta API has no atomic conditional-update primitive.
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->postmeta} SET meta_value = CAST(meta_value AS SIGNED) - %d WHERE post_id = %d AND meta_key = '_perdita_sales_stock' AND CAST(meta_value AS SIGNED) >= %d",
				$qty,
				$product_id,
				$qty
			)
		);
		if ( $updated > 0 ) {
			wp_cache_delete( $product_id, 'post_meta' );
		}
		return $updated > 0;
	}

	/**
	 * Release stock reserve_stock() claimed, when checkout fails afterward
	 * for a reason unrelated to the item itself (a later cart line was out
	 * of stock, order creation failed, Stripe session creation failed), so
	 * an aborted checkout never permanently shrinks stock.
	 *
	 * @param int $product_id Product post id.
	 * @param int $qty        Quantity to release.
	 */
	private function release_stock( $product_id, $qty ) {
		global $wpdb;
		$product_id = (int) $product_id;
		$qty        = (int) $qty;
		if ( $product_id <= 0 || $qty <= 0 ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- prepared below.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->postmeta} SET meta_value = CAST(meta_value AS SIGNED) + %d WHERE post_id = %d AND meta_key = '_perdita_sales_stock'",
				$qty,
				$product_id
			)
		);
		wp_cache_delete( $product_id, 'post_meta' );
	}

	/* ------------------------------------------------------------------ */
	/* Signed, expiring, non-IDOR downloads                               */
	/* ------------------------------------------------------------------ */

	/**
	 * Build the signed token for a download. The HMAC is keyed by the per-site
	 * download secret (not any guessable id) over order + product + file index +
	 * expiry, so the URL cannot be forged and cannot be pointed at another
	 * order's file by editing ids.
	 *
	 * @param int $order_id   Order id.
	 * @param int $product_id Product id.
	 * @param int $file_index File index within the product (0 for the single file).
	 * @param int $expires    Unix expiry timestamp.
	 * @return string Hex HMAC.
	 */
	public static function download_token( $order_id, $product_id, $file_index, $expires ) {
		$data = implode(
			'|',
			array(
				'perdita_sales_dl_v1',
				(int) $order_id,
				(int) $product_id,
				(int) $file_index,
				(int) $expires,
			)
		);
		return hash_hmac( 'sha256', $data, self::download_secret() );
	}

	/**
	 * Build a full signed download URL for a paid order's digital product.
	 *
	 * @param int $order_id   Order id.
	 * @param int $product_id Product id.
	 * @param int $file_index File index.
	 * @return string
	 */
	public static function download_url( $order_id, $product_id, $file_index = 0 ) {
		$expires = time() + self::DOWNLOAD_TTL;
		$token   = self::download_token( $order_id, $product_id, $file_index, $expires );
		return add_query_arg(
			array(
				'perdita_sales_dl' => 1,
				'order'            => (int) $order_id,
				'product'          => (int) $product_id,
				'file'             => (int) $file_index,
				'expires'          => (int) $expires,
				'token'            => $token,
			),
			home_url( '/' )
		);
	}

	/**
	 * Front-end download gate. Fires on template_redirect when the friendly
	 * ?perdita_sales_dl=1 URL is hit. Delegates to the same validator the REST
	 * route uses.
	 */
	public function maybe_stream_download() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- signed token is the authenticator, not a nonce.
		if ( empty( $_GET['perdita_sales_dl'] ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- signed token authenticates this request.
		$args = array(
			'order'   => isset( $_GET['order'] ) ? (int) $_GET['order'] : 0,
			'product' => isset( $_GET['product'] ) ? (int) $_GET['product'] : 0,
			'file'    => isset( $_GET['file'] ) ? (int) $_GET['file'] : 0,
			'expires' => isset( $_GET['expires'] ) ? (int) $_GET['expires'] : 0,
			'token'   => isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '',
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$error = $this->stream_download( $args['order'], $args['product'], $args['file'], $args['expires'], $args['token'] );
		if ( is_wp_error( $error ) ) {
			wp_die( esc_html( $error->get_error_message() ), esc_html__( 'Download', 'perdita-core' ), array( 'response' => 403 ) );
		}
		// stream_download exits on success.
	}

	/**
	 * REST download endpoint. Same validator as the friendly URL.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response Only returned on error; success streams and exits.
	 */
	public function handle_rest_download( WP_REST_Request $req ) {
		$error = $this->stream_download(
			(int) $req->get_param( 'order' ),
			(int) $req->get_param( 'product' ),
			(int) $req->get_param( 'file' ),
			(int) $req->get_param( 'expires' ),
			(string) $req->get_param( 'token' )
		);
		if ( is_wp_error( $error ) ) {
			return new WP_REST_Response( array( 'error' => $error->get_error_message() ), 403 );
		}
		// Unreachable on success (stream_download exits), but satisfies the return type.
		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * Validate a signed download request and stream the file. Every check must
	 * pass. On success this sends the file and exits; on any failure it returns a
	 * WP_Error and streams nothing.
	 *
	 * The checks, in order:
	 *   - expiry is in the future,
	 *   - the HMAC recomputes and matches in constant time (hash_equals),
	 *   - the order exists, is an order, and is marked paid,
	 *   - the order actually contains that product (no IDOR to another buyer's file),
	 *   - the product is digital and its file resolves INSIDE uploads (realpath),
	 *   - the per-file download count is under the cap.
	 *
	 * @param int    $order_id   Order id.
	 * @param int    $product_id Product id.
	 * @param int    $file_index File index.
	 * @param int    $expires    Expiry timestamp.
	 * @param string $token      Provided HMAC.
	 * @return WP_Error Always a WP_Error on failure; exits on success.
	 */
	private function stream_download( $order_id, $product_id, $file_index, $expires, $token ) {
		$deny = new WP_Error( 'denied', __( 'This download link is invalid or has expired.', 'perdita-core' ) );

		$order_id   = (int) $order_id;
		$product_id = (int) $product_id;
		$file_index = (int) $file_index;
		$expires    = (int) $expires;

		// 1. Expiry.
		if ( $expires <= time() ) {
			return $deny;
		}

		// 2. Recompute the HMAC and compare in constant time. A raw id in the URL
		// is worthless without a token that matches this exact tuple.
		$expected = self::download_token( $order_id, $product_id, $file_index, $expires );
		if ( ! is_string( $token ) || ! hash_equals( $expected, $token ) ) {
			return $deny;
		}

		// 3. Order must exist and be paid.
		$order = get_post( $order_id );
		if ( ! $order || self::CPT_ORDER !== $order->post_type ) {
			return $deny;
		}
		if ( 'paid' !== get_post_meta( $order_id, '_perdita_sales_status', true ) ) {
			return $deny;
		}

		// 4. The order must actually contain this product. This is the anti-IDOR
		// check: even a correctly signed token for product X on order A cannot
		// pull product Y's file.
		$items    = get_post_meta( $order_id, '_perdita_sales_items', true );
		$in_order = false;
		if ( is_array( $items ) ) {
			foreach ( $items as $item ) {
				if ( is_array( $item ) && (int) ( $item['id'] ?? 0 ) === $product_id ) {
					$in_order = true;
					break;
				}
			}
		}
		if ( ! $in_order ) {
			return $deny;
		}

		// 5. Product must be digital with a file that resolves inside uploads.
		$product = self::product( $product_id );
		if ( null === $product || 'digital' !== $product['type'] ) {
			return $deny;
		}
		if ( 0 !== $file_index ) {
			// This slim build stores exactly one file per product (index 0).
			return $deny;
		}
		$path = self::product_file_path( $product );
		if ( '' === $path ) {
			return $deny;
		}

		// 6. Per-file download cap.
		$max = (int) self::settings()['max_downloads'];
		if ( $max > 0 ) {
			$key   = '_perdita_sales_dl_count_' . $product_id . '_' . $file_index;
			$count = (int) get_post_meta( $order_id, $key, true );
			if ( $count >= $max ) {
				return new WP_Error( 'limit', __( 'This download has reached its limit. Please contact the site owner.', 'perdita-core' ) );
			}
			update_post_meta( $order_id, $key, $count + 1 );
		}

		$this->send_file( $path );
		exit; // send_file already exited, but be explicit.
	}

	/**
	 * Stream a validated file to the browser as a download. The path is already
	 * confirmed to be inside uploads by the caller.
	 *
	 * @param string $path Absolute file path inside uploads.
	 */
	private function send_file( $path ) {
		// Discard any buffered output so it doesn't corrupt the binary stream.
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		$filename = basename( $path );
		$size     = filesize( $path );

		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . str_replace( '"', '', $filename ) . '"' );
		header( 'Content-Transfer-Encoding: binary' );
		if ( false !== $size ) {
			header( 'Content-Length: ' . $size );
		}
		header( 'X-Content-Type-Options: nosniff' );

		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming a large binary, WP_Filesystem would buffer it all in memory.
		if ( false !== $handle ) {
			while ( ! feof( $handle ) ) {
				echo fread( $handle, 8192 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.WP.AlternativeFunctions.file_system_operations_fread -- raw binary file body.
				flush();
			}
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- pairs with fopen above.
		}
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Receipt email + confirmation                                       */
	/* ------------------------------------------------------------------ */

	/**
	 * Email the customer a receipt, with signed download links for any digital
	 * items.
	 *
	 * @param int $order_id Order id.
	 */
	private function send_receipt( $order_id ) {
		$email = (string) get_post_meta( $order_id, '_perdita_sales_email', true );
		if ( ! is_email( $email ) ) {
			return;
		}
		$items    = get_post_meta( $order_id, '_perdita_sales_items', true );
		$currency = (string) get_post_meta( $order_id, '_perdita_sales_currency', true );
		$total    = (float) get_post_meta( $order_id, '_perdita_sales_total', true );

		$lines = array();
		$lines[] = sprintf( /* translators: %s: site name */ __( 'Thank you for your order from %s.', 'perdita-core' ), wp_specialchars_decode( get_bloginfo( 'name' ) ) );
		$lines[] = '';
		$lines[] = sprintf( /* translators: %d: order id */ __( 'Order #%d', 'perdita-core' ), $order_id );
		$lines[] = '';

		if ( is_array( $items ) ) {
			foreach ( $items as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$lines[] = sprintf(
					'%1$s x%2$d - %3$s',
					(string) ( $item['title'] ?? '' ),
					(int) ( $item['qty'] ?? 1 ),
					$this->format_money( (float) ( $item['unit_price'] ?? 0 ) * (int) ( $item['qty'] ?? 1 ), $currency )
				);
			}
		}
		$lines[] = '';
		$lines[] = sprintf( /* translators: %s: formatted total */ __( 'Total: %s', 'perdita-core' ), $this->format_money( $total, $currency ) );

		// Digital download links.
		$digital_links = $this->digital_links_for_order( $order_id );
		if ( ! empty( $digital_links ) ) {
			$lines[] = '';
			$lines[] = __( 'Your downloads (links expire in 24 hours):', 'perdita-core' );
			foreach ( $digital_links as $dl ) {
				$lines[] = $dl['title'] . ': ' . $dl['url'];
			}
		}

		$subject = sprintf( /* translators: %d: order id */ __( 'Your order #%d', 'perdita-core' ), $order_id );
		wp_mail( $email, $subject, implode( "\n", $lines ) );
	}

	/**
	 * Build the signed download links for the digital items on a paid order.
	 *
	 * @param int $order_id Order id.
	 * @return array List of {title, url}.
	 */
	public function digital_links_for_order( $order_id ) {
		$out   = array();
		$items = get_post_meta( $order_id, '_perdita_sales_items', true );
		if ( ! is_array( $items ) ) {
			return $out;
		}
		$seen = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || 'digital' !== ( $item['type'] ?? '' ) ) {
				continue;
			}
			$pid = (int) ( $item['id'] ?? 0 );
			if ( $pid <= 0 || isset( $seen[ $pid ] ) ) {
				continue;
			}
			$seen[ $pid ] = true;
			$product      = self::product( $pid );
			if ( null === $product || '' === self::product_file_path( $product ) ) {
				continue;
			}
			$out[] = array(
				'title' => (string) ( $item['title'] ?? $product['title'] ),
				'url'   => self::download_url( $order_id, $pid, 0 ),
			);
		}
		return $out;
	}

	/**
	 * Format an amount with its currency for display.
	 *
	 * @param float  $amount   Amount.
	 * @param string $currency 3-letter code.
	 * @return string
	 */
	public function format_money( $amount, $currency = '' ) {
		$currency = '' !== $currency ? strtoupper( $currency ) : self::currency();
		return $currency . ' ' . number_format_i18n( self::normalize_amount( $amount ), 2 );
	}

	/* ------------------------------------------------------------------ */
	/* Shortcodes                                                         */
	/* ------------------------------------------------------------------ */

	/**
	 * [perdita_product id="N"] renders a single product with an Add to cart form.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function shortcode_product( $atts ) {
		$atts    = shortcode_atts( array( 'id' => 0 ), $atts, 'perdita_product' );
		$product = self::product( (int) $atts['id'] );
		if ( null === $product ) {
			return '';
		}
		return $this->render_product_card( $product );
	}

	/**
	 * [perdita_products] renders a grid of published products.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function shortcode_products( $atts ) {
		$atts = shortcode_atts( array( 'limit' => 12 ), $atts, 'perdita_products' );

		$query = new WP_Query(
			array(
				'post_type'      => self::CPT_PRODUCT,
				'post_status'    => 'publish',
				'posts_per_page' => max( 1, min( 48, (int) $atts['limit'] ) ),
				'no_found_rows'  => true,
			)
		);
		if ( ! $query->have_posts() ) {
			return '<p>' . esc_html__( 'No products yet.', 'perdita-core' ) . '</p>';
		}

		$out = '<div class="perdita-sales-grid">';
		while ( $query->have_posts() ) {
			$query->the_post();
			$product = self::product( get_the_ID() );
			if ( null !== $product ) {
				$out .= $this->render_product_card( $product, true );
			}
		}
		wp_reset_postdata();
		$out .= '</div>';
		return $out;
	}

	/**
	 * Render one product card. All dynamic values escaped.
	 *
	 * @param array $product Product array.
	 * @param bool  $compact Whether this is a grid tile (links to the product).
	 * @return string
	 */
	private function render_product_card( $product, $compact = false ) {
		$out  = '<div class="perdita-sales-product">';
		$out .= '<h3 class="perdita-sales-product__title">' . esc_html( $product['title'] ) . '</h3>';
		$out .= '<p class="perdita-sales-product__price">' . esc_html( $this->format_money( $product['price'] ) ) . '</p>';

		if ( 'physical' === $product['type'] ) {
			$in_stock = (int) $product['stock'] > 0;
			$out     .= '<p class="perdita-sales-product__stock">' . (
				$in_stock
					? esc_html__( 'In stock', 'perdita-core' )
					: esc_html__( 'Out of stock', 'perdita-core' )
			) . '</p>';
			if ( ! $in_stock ) {
				$out .= '</div>';
				return $out;
			}
		}

		$out .= '<form class="perdita-sales-add" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		$out .= '<input type="hidden" name="action" value="perdita_sales_cart" />';
		$out .= '<input type="hidden" name="op" value="add" />';
		$out .= '<input type="hidden" name="product_id" value="' . esc_attr( (string) $product['id'] ) . '" />';
		$out .= wp_nonce_field( 'perdita_sales_cart', '_wpnonce', true, false );
		$out .= '<input type="number" name="qty" value="1" min="1" class="perdita-sales-qty" />';
		$out .= '<button type="submit" class="perdita-sales-btn">' . esc_html__( 'Add to cart', 'perdita-core' ) . '</button>';
		$out .= '</form>';
		$out .= '</div>';
		return $out;
	}

	/**
	 * [perdita_cart] renders the current cart with quantity update and remove.
	 *
	 * @return string
	 */
	public function shortcode_cart() {
		$totals = $this->compute_totals();
		if ( empty( $totals['lines'] ) ) {
			return '<p class="perdita-sales-empty">' . esc_html__( 'Your cart is empty.', 'perdita-core' ) . '</p>';
		}

		$out  = '<form class="perdita-sales-cart" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		$out .= '<input type="hidden" name="action" value="perdita_sales_cart" />';
		$out .= '<input type="hidden" name="op" value="update" />';
		$out .= wp_nonce_field( 'perdita_sales_cart', '_wpnonce', true, false );
		$out .= '<table class="perdita-sales-cart-table"><thead><tr>';
		$out .= '<th>' . esc_html__( 'Product', 'perdita-core' ) . '</th>';
		$out .= '<th>' . esc_html__( 'Price', 'perdita-core' ) . '</th>';
		$out .= '<th>' . esc_html__( 'Qty', 'perdita-core' ) . '</th>';
		$out .= '<th>' . esc_html__( 'Total', 'perdita-core' ) . '</th>';
		$out .= '</tr></thead><tbody>';

		foreach ( $totals['lines'] as $line ) {
			$out .= '<tr>';
			$out .= '<td>' . esc_html( $line['title'] ) . '</td>';
			$out .= '<td>' . esc_html( $this->format_money( $line['unit_price'] ) ) . '</td>';
			$out .= '<td><input type="number" name="qty[' . esc_attr( (string) $line['id'] ) . ']" value="' . esc_attr( (string) $line['qty'] ) . '" min="0" class="perdita-sales-qty" /></td>';
			$out .= '<td>' . esc_html( $this->format_money( $line['line_total'] ) ) . '</td>';
			$out .= '</tr>';
		}
		$out .= '</tbody></table>';

		$out .= '<ul class="perdita-sales-totals">';
		$out .= '<li>' . esc_html__( 'Subtotal', 'perdita-core' ) . ': ' . esc_html( $this->format_money( $totals['subtotal'] ) ) . '</li>';
		if ( $totals['shipping'] > 0 ) {
			$out .= '<li>' . esc_html__( 'Shipping', 'perdita-core' ) . ': ' . esc_html( $this->format_money( $totals['shipping'] ) ) . '</li>';
		}
		if ( $totals['tax'] > 0 ) {
			$out .= '<li>' . esc_html__( 'Tax', 'perdita-core' ) . ': ' . esc_html( $this->format_money( $totals['tax'] ) ) . '</li>';
		}
		$out .= '<li class="perdita-sales-grand">' . esc_html__( 'Total', 'perdita-core' ) . ': ' . esc_html( $this->format_money( $totals['total'] ) ) . '</li>';
		$out .= '</ul>';

		$out .= '<button type="submit" class="perdita-sales-btn">' . esc_html__( 'Update cart', 'perdita-core' ) . '</button>';
		$out .= '</form>';
		return $out;
	}

	/**
	 * [perdita_checkout] renders the checkout form. Shows a success or cancel
	 * message when returning from Stripe.
	 *
	 * @return string
	 */
	public function shortcode_checkout() {
		// Returning from Stripe?
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only status flag; the order's real status comes from the verified webhook, not this param.
		$flag = isset( $_GET['perdita_sales'] ) ? sanitize_key( wp_unslash( $_GET['perdita_sales'] ) ) : '';
		if ( 'success' === $flag ) {
			return $this->render_success();
		}
		if ( 'cancel' === $flag ) {
			return '<p class="perdita-sales-notice">' . esc_html__( 'Your payment was cancelled. Your cart is still here whenever you are ready.', 'perdita-core' ) . '</p>';
		}

		$totals = $this->compute_totals();
		if ( empty( $totals['lines'] ) ) {
			return '<p class="perdita-sales-empty">' . esc_html__( 'Your cart is empty.', 'perdita-core' ) . '</p>';
		}

		$out  = '<form class="perdita-sales-checkout" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		$out .= '<input type="hidden" name="action" value="perdita_sales_checkout" />';
		$out .= wp_nonce_field( 'perdita_sales_checkout', '_wpnonce', true, false );

		$out .= '<p><label>' . esc_html__( 'Email address', 'perdita-core' ) . '<br />';
		$out .= '<input type="email" name="email" required class="perdita-sales-input" /></label></p>';

		if ( $totals['physical'] ) {
			$out .= '<fieldset class="perdita-sales-ship"><legend>' . esc_html__( 'Shipping address', 'perdita-core' ) . '</legend>';
			$out .= $this->text_field( 'ship_name', __( 'Full name', 'perdita-core' ), true );
			$out .= $this->text_field( 'ship_line1', __( 'Address line 1', 'perdita-core' ), true );
			$out .= $this->text_field( 'ship_line2', __( 'Address line 2', 'perdita-core' ), false );
			$out .= $this->text_field( 'ship_city', __( 'City', 'perdita-core' ), true );
			$out .= $this->text_field( 'ship_state', __( 'State / region', 'perdita-core' ), false );
			$out .= $this->text_field( 'ship_postcode', __( 'Postal code', 'perdita-core' ), true );
			$out .= $this->text_field( 'ship_country', __( 'Country', 'perdita-core' ), false );
			$out .= '</fieldset>';
		}

		$out .= '<ul class="perdita-sales-totals">';
		$out .= '<li>' . esc_html__( 'Subtotal', 'perdita-core' ) . ': ' . esc_html( $this->format_money( $totals['subtotal'] ) ) . '</li>';
		if ( $totals['shipping'] > 0 ) {
			$out .= '<li>' . esc_html__( 'Shipping', 'perdita-core' ) . ': ' . esc_html( $this->format_money( $totals['shipping'] ) ) . '</li>';
		}
		if ( $totals['tax'] > 0 ) {
			$out .= '<li>' . esc_html__( 'Tax', 'perdita-core' ) . ': ' . esc_html( $this->format_money( $totals['tax'] ) ) . '</li>';
		}
		$out .= '<li class="perdita-sales-grand">' . esc_html__( 'Total', 'perdita-core' ) . ': ' . esc_html( $this->format_money( $totals['total'] ) ) . '</li>';
		$out .= '</ul>';

		$out .= '<button type="submit" class="perdita-sales-btn perdita-sales-pay">' . esc_html__( 'Pay now', 'perdita-core' ) . '</button>';
		$out .= '<p class="perdita-sales-secure">' . esc_html__( 'Payment is handled securely by Stripe. Your card details never touch this site.', 'perdita-core' ) . '</p>';
		$out .= '</form>';
		return $out;
	}

	/**
	 * One labeled text input for the checkout form.
	 *
	 * @param string $name     Field name.
	 * @param string $label    Label.
	 * @param bool   $required Whether required.
	 * @return string
	 */
	private function text_field( $name, $label, $required ) {
		return '<p><label>' . esc_html( $label ) . '<br /><input type="text" name="' . esc_attr( $name ) . '"'
			. ( $required ? ' required' : '' ) . ' class="perdita-sales-input" /></label></p>';
	}

	/**
	 * The success screen after returning from Stripe. It reads the order status,
	 * which only becomes "paid" via the verified webhook, so this page cannot be
	 * spoofed into showing a paid state by URL alone.
	 *
	 * @return string
	 */
	private function render_success() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- order id is validated against paid status set by the verified webhook.
		$order_id = isset( $_GET['order'] ) ? (int) $_GET['order'] : 0;
		$order    = $order_id ? get_post( $order_id ) : null;
		if ( ! $order || self::CPT_ORDER !== $order->post_type ) {
			return '<p class="perdita-sales-notice">' . esc_html__( 'Thank you. We could not find that order.', 'perdita-core' ) . '</p>';
		}

		$status = get_post_meta( $order_id, '_perdita_sales_status', true );
		if ( 'paid' !== $status ) {
			// Payment confirmation arrives via the webhook, which may lag the
			// redirect by a moment.
			return '<p class="perdita-sales-notice">' . esc_html__( 'Thanks. Your payment is being confirmed. You will get an email receipt shortly.', 'perdita-core' ) . '</p>';
		}

		// Paid: clear the cart and show downloads.
		$this->clear_cart();

		$out  = '<div class="perdita-sales-success">';
		$out .= '<p>' . esc_html__( 'Thank you. Your payment was received and your order is confirmed.', 'perdita-core' ) . '</p>';

		$links = $this->digital_links_for_order( $order_id );
		if ( ! empty( $links ) ) {
			$out .= '<h3>' . esc_html__( 'Your downloads', 'perdita-core' ) . '</h3>';
			$out .= '<ul class="perdita-sales-downloads">';
			foreach ( $links as $dl ) {
				$out .= '<li><a href="' . esc_url( $dl['url'] ) . '">' . esc_html( $dl['title'] ) . '</a></li>';
			}
			$out .= '</ul>';
			$out .= '<p class="perdita-sales-note">' . esc_html__( 'These links expire in 24 hours. We also emailed them to you.', 'perdita-core' ) . '</p>';
		}
		$out .= '</div>';
		return $out;
	}

	/**
	 * Empty the current cart.
	 */
	private function clear_cart() {
		$id = $this->cart_id();
		if ( '' !== $id ) {
			delete_transient( $this->cart_transient( $id ) );
		}
		$this->cart_cache = array();
	}

	/* ------------------------------------------------------------------ */
	/* Reporting helpers (used by the admin screen)                       */
	/* ------------------------------------------------------------------ */

	/**
	 * Paid-order count and revenue over the last N days (0 = all time).
	 *
	 * @param int $days Window in days; 0 for all time.
	 * @return array {count, revenue}
	 */
	public static function revenue_report( $days = 30 ) {
		global $wpdb;

		$meta_status = '_perdita_sales_status';
		$meta_total  = '_perdita_sales_total';
		$meta_paid   = '_perdita_sales_paid_at';

		// Join the orders to their status and total meta. Bounded by an optional
		// paid-at cutoff. Every value bound via prepare.
		$where_time = '';
		$params     = array( self::CPT_ORDER, $meta_status, $meta_total );

		if ( $days > 0 ) {
			$cutoff       = gmdate( 'Y-m-d H:i:s', time() - ( (int) $days * DAY_IN_SECONDS ) );
			$where_time   = ' AND mp.meta_value >= %s ';
			$params[]     = $meta_paid;
			$params[]     = $cutoff;
			$join_paid    = " LEFT JOIN {$wpdb->postmeta} mp ON mp.post_id = p.ID AND mp.meta_key = %s ";
		} else {
			$join_paid = '';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names are core WP tables via $wpdb->, all values bound below.
		$sql = "SELECT COUNT(*) AS cnt, COALESCE(SUM(CAST(mt.meta_value AS DECIMAL(12,2))),0) AS rev
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} ms ON ms.post_id = p.ID AND ms.meta_key = %s AND ms.meta_value = 'paid'
			INNER JOIN {$wpdb->postmeta} mt ON mt.post_id = p.ID AND mt.meta_key = %s
			{$join_paid}
			WHERE p.post_type = %s {$where_time}";

		// Reorder params to match the SQL placeholder order:
		// ms.meta_key(status), mt.meta_key(total), [mp.meta_key(paid)], post_type, [cutoff].
		$ordered = array( $meta_status, $meta_total );
		if ( $days > 0 ) {
			$ordered[] = $meta_paid;      // For the LEFT JOIN mp.meta_key.
		}
		$ordered[] = self::CPT_ORDER;     // post_type.
		if ( $days > 0 ) {
			$ordered[] = $cutoff;         // where_time.
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- prepared immediately below with $ordered.
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $ordered ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array(
			'count'   => $row ? (int) $row->cnt : 0,
			'revenue' => $row ? (float) $row->rev : 0.0,
		);
	}

	/* ------------------------------------------------------------------ */
	/* Install                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * On activation: register the CPTs (so rewrite rules exist), seed the option
	 * defaults, and generate the per-site download-signing secret if absent.
	 */
	public static function install() {
		$existing = get_option( self::OPTION, null );
		$settings = is_array( $existing ) ? array_merge( self::defaults(), $existing ) : self::defaults();

		if ( '' === (string) $settings['download_secret'] ) {
			$settings['download_secret'] = self::generate_download_secret();
		}

		update_option( self::OPTION, $settings );
	}
}
