<?php
/**
 * Sales admin: product meta box, an orders list + detail view, a store settings
 * screen (with encrypted secrets), and a small revenue report.
 *
 * Every state-changing action checks a capability and a nonce. Every stored
 * value is sanitized on write and escaped on output. Secrets are shown only as
 * a "saved" placeholder, never their value.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

class Perdita_Sales_Admin {

	/**
	 * The sales module core.
	 *
	 * @var Perdita_Sales
	 */
	private $main;

	/**
	 * The Perdita core singleton.
	 *
	 * @var Perdita
	 */
	private $core;

	/**
	 * Orders + reporting page slug.
	 */
	const PAGE_ORDERS = 'perdita-sales-orders';

	/**
	 * Settings page slug.
	 */
	const PAGE_SETTINGS = 'perdita-sales-settings';

	/**
	 * Constructor. Wire meta boxes, menu, and admin-post handlers.
	 *
	 * @param Perdita_Sales $main Sales module core.
	 * @param Perdita       $core Core.
	 */
	public function __construct( $main, $core ) {
		$this->main = $main;
		$this->core = $core;

		add_action( 'add_meta_boxes', array( $this, 'add_product_meta_box' ) );
		add_action( 'save_post_' . Perdita_Sales::CPT_PRODUCT, array( $this, 'save_product_meta' ), 10, 2 );

		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_perdita_sales_save_settings', array( $this, 'handle_save_settings' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Product meta box                                                   */
	/* ------------------------------------------------------------------ */

	/**
	 * Register the product details meta box.
	 */
	public function add_product_meta_box() {
		add_meta_box(
			'perdita_sales_product',
			__( 'Product details', 'perdita-core' ),
			array( $this, 'render_product_meta_box' ),
			Perdita_Sales::CPT_PRODUCT,
			'normal',
			'high'
		);
	}

	/**
	 * Render the product meta box.
	 *
	 * @param WP_Post $post Product post.
	 */
	public function render_product_meta_box( $post ) {
		wp_nonce_field( 'perdita_sales_product_meta', 'perdita_sales_product_nonce' );

		$price     = Perdita_Sales::normalize_amount( get_post_meta( $post->ID, '_perdita_sales_price', true ) );
		$type      = get_post_meta( $post->ID, '_perdita_sales_type', true );
		$type      = ( 'physical' === $type ) ? 'physical' : 'digital';
		$file_id   = (int) get_post_meta( $post->ID, '_perdita_sales_file_id', true );
		$file_path = (string) get_post_meta( $post->ID, '_perdita_sales_file_path', true );
		$stock     = (int) get_post_meta( $post->ID, '_perdita_sales_stock', true );
		$shipping  = Perdita_Sales::normalize_amount( get_post_meta( $post->ID, '_perdita_sales_shipping', true ) );

		echo '<table class="form-table" role="presentation">';

		// Price.
		echo '<tr><th scope="row"><label for="perdita-sales-price">' . esc_html__( 'Price', 'perdita-core' ) . '</label></th><td>';
		echo '<input type="number" step="0.01" min="0" id="perdita-sales-price" name="perdita_sales_price" value="' . esc_attr( (string) $price ) . '" class="regular-text" />';
		echo ' <span class="description">' . esc_html( Perdita_Sales::currency() ) . '</span>';
		echo '</td></tr>';

		// Type.
		echo '<tr><th scope="row">' . esc_html__( 'Type', 'perdita-core' ) . '</th><td>';
		echo '<label style="margin-right:14px;"><input type="radio" name="perdita_sales_type" value="digital" ' . checked( 'digital', $type, false ) . ' /> ' . esc_html__( 'Digital download', 'perdita-core' ) . '</label>';
		echo '<label><input type="radio" name="perdita_sales_type" value="physical" ' . checked( 'physical', $type, false ) . ' /> ' . esc_html__( 'Physical product', 'perdita-core' ) . '</label>';
		echo '</td></tr>';

		// Digital file.
		echo '<tr class="perdita-sales-digital"><th scope="row"><label for="perdita-sales-file-id">' . esc_html__( 'Downloadable file', 'perdita-core' ) . '</label></th><td>';
		echo '<input type="number" min="0" id="perdita-sales-file-id" name="perdita_sales_file_id" value="' . esc_attr( (string) $file_id ) . '" class="small-text" /> ';
		echo '<span class="description">' . esc_html__( 'Media library attachment ID of the file to deliver.', 'perdita-core' ) . '</span>';
		echo '<p><label for="perdita-sales-file-path">' . esc_html__( 'Or a file path inside your uploads folder', 'perdita-core' ) . '</label><br />';
		echo '<input type="text" id="perdita-sales-file-path" name="perdita_sales_file_path" value="' . esc_attr( $file_path ) . '" class="large-text" placeholder="e.g. downloads/my-book.pdf" />';
		echo '<span class="description">' . esc_html__( 'Only used when no attachment ID is set. The file must live inside the uploads folder.', 'perdita-core' ) . '</span></p>';
		echo '</td></tr>';

		// Stock (physical).
		echo '<tr class="perdita-sales-physical"><th scope="row"><label for="perdita-sales-stock">' . esc_html__( 'Stock quantity', 'perdita-core' ) . '</label></th><td>';
		echo '<input type="number" min="0" id="perdita-sales-stock" name="perdita_sales_stock" value="' . esc_attr( (string) $stock ) . '" class="small-text" />';
		echo '</td></tr>';

		// Shipping (physical).
		echo '<tr class="perdita-sales-physical"><th scope="row"><label for="perdita-sales-shipping">' . esc_html__( 'Flat shipping per item', 'perdita-core' ) . '</label></th><td>';
		echo '<input type="number" step="0.01" min="0" id="perdita-sales-shipping" name="perdita_sales_shipping" value="' . esc_attr( (string) $shipping ) . '" class="regular-text" />';
		echo ' <span class="description">' . esc_html( Perdita_Sales::currency() ) . '</span>';
		echo '</td></tr>';

		echo '</table>';

		// Tiny script to show only the fields for the chosen type. No external
		// asset needed for this one toggle.
		?>
<script>
( function () {
	var box = document.getElementById( 'perdita_sales_product' );
	if ( ! box ) { return; }
	function sync() {
		var t = box.querySelector( 'input[name="perdita_sales_type"]:checked' );
		var isPhysical = t && t.value === 'physical';
		box.querySelectorAll( '.perdita-sales-physical' ).forEach( function ( el ) { el.style.display = isPhysical ? '' : 'none'; } );
		box.querySelectorAll( '.perdita-sales-digital' ).forEach( function ( el ) { el.style.display = isPhysical ? 'none' : ''; } );
	}
	box.querySelectorAll( 'input[name="perdita_sales_type"]' ).forEach( function ( el ) { el.addEventListener( 'change', sync ); } );
	sync();
} )();
</script>
		<?php
	}

	/**
	 * Save the product meta. Capability enforced by the CPT (edit_post), plus an
	 * explicit nonce check. Every value sanitized.
	 *
	 * @param int     $post_id Product id.
	 * @param WP_Post $post    Post object.
	 */
	public function save_product_meta( $post_id, $post ) {
		// Skip autosaves and revisions.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! isset( $_POST['perdita_sales_product_nonce'] ) ) {
			return;
		}
		if ( ! check_admin_referer( 'perdita_sales_product_meta', 'perdita_sales_product_nonce' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$price = isset( $_POST['perdita_sales_price'] ) ? Perdita_Sales::normalize_amount( wp_unslash( $_POST['perdita_sales_price'] ) ) : 0.0;
		update_post_meta( $post_id, '_perdita_sales_price', $price );

		$type = isset( $_POST['perdita_sales_type'] ) ? sanitize_key( wp_unslash( $_POST['perdita_sales_type'] ) ) : 'digital';
		$type = ( 'physical' === $type ) ? 'physical' : 'digital';
		update_post_meta( $post_id, '_perdita_sales_type', $type );

		$file_id = isset( $_POST['perdita_sales_file_id'] ) ? absint( wp_unslash( $_POST['perdita_sales_file_id'] ) ) : 0;
		update_post_meta( $post_id, '_perdita_sales_file_id', $file_id );

		// Store the file path as a relative-ish string. Strip any traversal now;
		// the download gate also realpath-confirms containment at delivery time.
		$file_path_raw = isset( $_POST['perdita_sales_file_path'] ) ? (string) wp_unslash( $_POST['perdita_sales_file_path'] ) : '';
		$file_path     = $this->sanitize_upload_relative_path( $file_path_raw );
		update_post_meta( $post_id, '_perdita_sales_file_path', $file_path );

		$stock = isset( $_POST['perdita_sales_stock'] ) ? absint( wp_unslash( $_POST['perdita_sales_stock'] ) ) : 0;
		update_post_meta( $post_id, '_perdita_sales_stock', $stock );

		$shipping = isset( $_POST['perdita_sales_shipping'] ) ? Perdita_Sales::normalize_amount( wp_unslash( $_POST['perdita_sales_shipping'] ) ) : 0.0;
		update_post_meta( $post_id, '_perdita_sales_shipping', $shipping );
	}

	/**
	 * Reduce an admin-entered file path to a safe relative path under uploads.
	 * Strips leading slashes, drive letters, and any ".." segment so the stored
	 * value can never point outside uploads even before the delivery-time
	 * realpath check.
	 *
	 * @param string $raw Raw path.
	 * @return string
	 */
	private function sanitize_upload_relative_path( $raw ) {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return '';
		}
		// Normalize slashes and drop any scheme or drive prefix.
		$raw = str_replace( '\\', '/', $raw );
		$raw = preg_replace( '#^[a-zA-Z]:/#', '', $raw ); // Windows drive.
		$raw = ltrim( $raw, '/' );

		$parts = array();
		foreach ( explode( '/', $raw ) as $seg ) {
			$seg = sanitize_file_name( $seg );
			if ( '' === $seg || '.' === $seg || '..' === $seg ) {
				continue; // Drop empties and traversal.
			}
			$parts[] = $seg;
		}
		return implode( '/', $parts );
	}

	/* ------------------------------------------------------------------ */
	/* Menu                                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * Add the Orders and Store settings submenus under the product CPT menu.
	 */
	public function menu() {
		$parent = 'edit.php?post_type=' . Perdita_Sales::CPT_PRODUCT;

		add_submenu_page(
			$parent,
			__( 'Orders', 'perdita-core' ),
			__( 'Orders', 'perdita-core' ),
			'manage_options',
			self::PAGE_ORDERS,
			array( $this, 'render_orders' )
		);

		add_submenu_page(
			$parent,
			__( 'Store settings', 'perdita-core' ),
			__( 'Store', 'perdita-core' ),
			'manage_options',
			self::PAGE_SETTINGS,
			array( $this, 'render_settings' )
		);
	}

	/* ------------------------------------------------------------------ */
	/* Orders list + detail + reporting                                   */
	/* ------------------------------------------------------------------ */

	/**
	 * Render the orders screen: a revenue summary, then either a single order
	 * detail (when ?order=N) or the list.
	 */
	public function render_orders() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'perdita-core' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view selector, no state change.
		$order_id = isset( $_GET['order'] ) ? (int) $_GET['order'] : 0;

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Orders', 'perdita-core' ) . '</h1>';

		$this->render_report();

		if ( $order_id > 0 ) {
			$this->render_order_detail( $order_id );
		} else {
			$this->render_orders_list();
		}

		echo '</div>';
	}

	/**
	 * Render the revenue report cards.
	 */
	private function render_report() {
		$last30 = Perdita_Sales::revenue_report( 30 );
		$all    = Perdita_Sales::revenue_report( 0 );

		echo '<div class="perdita-sales-report" style="display:flex;gap:16px;margin:16px 0;">';
		echo '<div class="card" style="padding:12px 16px;border:1px solid #ccd0d4;background:#fff;">';
		echo '<strong>' . esc_html__( 'Paid orders (30 days)', 'perdita-core' ) . '</strong><br />';
		echo '<span style="font-size:22px;">' . esc_html( (string) $last30['count'] ) . '</span>';
		echo '</div>';
		echo '<div class="card" style="padding:12px 16px;border:1px solid #ccd0d4;background:#fff;">';
		echo '<strong>' . esc_html__( 'Revenue (30 days)', 'perdita-core' ) . '</strong><br />';
		echo '<span style="font-size:22px;">' . esc_html( $this->main->format_money( $last30['revenue'] ) ) . '</span>';
		echo '</div>';
		echo '<div class="card" style="padding:12px 16px;border:1px solid #ccd0d4;background:#fff;">';
		echo '<strong>' . esc_html__( 'Revenue (all time)', 'perdita-core' ) . '</strong><br />';
		echo '<span style="font-size:22px;">' . esc_html( $this->main->format_money( $all['revenue'] ) ) . '</span>';
		echo '</div>';
		echo '</div>';
	}

	/**
	 * Render the orders list, newest first.
	 */
	private function render_orders_list() {
		$query = new WP_Query(
			array(
				'post_type'      => Perdita_Sales::CPT_ORDER,
				'post_status'    => 'any',
				'posts_per_page' => 50,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
		);

		if ( ! $query->have_posts() ) {
			echo '<p>' . esc_html__( 'No orders yet.', 'perdita-core' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Order', 'perdita-core' ) . '</th>';
		echo '<th>' . esc_html__( 'Date', 'perdita-core' ) . '</th>';
		echo '<th>' . esc_html__( 'Email', 'perdita-core' ) . '</th>';
		echo '<th>' . esc_html__( 'Total', 'perdita-core' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'perdita-core' ) . '</th>';
		echo '</tr></thead><tbody>';

		while ( $query->have_posts() ) {
			$query->the_post();
			$id       = get_the_ID();
			$email    = (string) get_post_meta( $id, '_perdita_sales_email', true );
			$total    = (float) get_post_meta( $id, '_perdita_sales_total', true );
			$currency = (string) get_post_meta( $id, '_perdita_sales_currency', true );
			$status   = (string) get_post_meta( $id, '_perdita_sales_status', true );
			$created  = (string) get_post_meta( $id, '_perdita_sales_created', true );
			$view_url = add_query_arg(
				array(
					'post_type' => Perdita_Sales::CPT_PRODUCT,
					'page'      => self::PAGE_ORDERS,
					'order'     => $id,
				),
				admin_url( 'edit.php' )
			);

			echo '<tr>';
			echo '<td><a href="' . esc_url( $view_url ) . '">#' . esc_html( (string) $id ) . '</a></td>';
			echo '<td style="white-space:nowrap;">' . esc_html( $created ) . '</td>';
			echo '<td>' . esc_html( $email ) . '</td>';
			echo '<td>' . esc_html( $this->main->format_money( $total, $currency ) ) . '</td>';
			echo '<td>' . $this->status_badge( $status ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- status_badge returns pre-escaped, kses-safe markup.
			echo '</tr>';
		}
		wp_reset_postdata();

		echo '</tbody></table>';
	}

	/**
	 * A colored status label. Returns kses-safe markup.
	 *
	 * @param string $status Order status.
	 * @return string
	 */
	private function status_badge( $status ) {
		$map = array(
			'paid'    => array( __( 'Paid', 'perdita-core' ), '#008a20' ),
			'pending' => array( __( 'Pending', 'perdita-core' ), '#996800' ),
		);
		$def   = isset( $map[ $status ] ) ? $map[ $status ] : array( ucfirst( $status ), '#50575e' );
		$badge = '<span style="color:' . esc_attr( $def[1] ) . ';">' . esc_html( $def[0] ) . '</span>';
		return wp_kses( $badge, array( 'span' => array( 'style' => array() ) ) );
	}

	/**
	 * Render a single order's detail.
	 *
	 * @param int $order_id Order id.
	 */
	private function render_order_detail( $order_id ) {
		$order = get_post( $order_id );
		if ( ! $order || Perdita_Sales::CPT_ORDER !== $order->post_type ) {
			echo '<p>' . esc_html__( 'Order not found.', 'perdita-core' ) . '</p>';
			return;
		}

		$email    = (string) get_post_meta( $order_id, '_perdita_sales_email', true );
		$currency = (string) get_post_meta( $order_id, '_perdita_sales_currency', true );
		$status   = (string) get_post_meta( $order_id, '_perdita_sales_status', true );
		$items    = get_post_meta( $order_id, '_perdita_sales_items', true );
		$subtotal = (float) get_post_meta( $order_id, '_perdita_sales_subtotal', true );
		$shipping = (float) get_post_meta( $order_id, '_perdita_sales_shipping', true );
		$tax      = (float) get_post_meta( $order_id, '_perdita_sales_tax', true );
		$total    = (float) get_post_meta( $order_id, '_perdita_sales_total', true );
		$ref      = (string) get_post_meta( $order_id, '_perdita_sales_gateway_ref', true );
		$ship     = get_post_meta( $order_id, '_perdita_sales_shipping_address', true );
		$created  = (string) get_post_meta( $order_id, '_perdita_sales_created', true );

		$back = add_query_arg(
			array(
				'post_type' => Perdita_Sales::CPT_PRODUCT,
				'page'      => self::PAGE_ORDERS,
			),
			admin_url( 'edit.php' )
		);

		echo '<p><a href="' . esc_url( $back ) . '">&larr; ' . esc_html__( 'Back to orders', 'perdita-core' ) . '</a></p>';
		echo '<h2>' . esc_html( sprintf( /* translators: %d: order id */ __( 'Order #%d', 'perdita-core' ), $order_id ) ) . '</h2>';

		echo '<table class="form-table" role="presentation">';
		echo '<tr><th>' . esc_html__( 'Date', 'perdita-core' ) . '</th><td>' . esc_html( $created ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Status', 'perdita-core' ) . '</th><td>' . $this->status_badge( $status ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- kses-safe.
		echo '<tr><th>' . esc_html__( 'Email', 'perdita-core' ) . '</th><td>' . esc_html( $email ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Gateway reference', 'perdita-core' ) . '</th><td>' . esc_html( $ref ) . '</td></tr>';
		echo '</table>';

		// Line items.
		echo '<h3>' . esc_html__( 'Items', 'perdita-core' ) . '</h3>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Product', 'perdita-core' ) . '</th>';
		echo '<th>' . esc_html__( 'Type', 'perdita-core' ) . '</th>';
		echo '<th>' . esc_html__( 'Unit price', 'perdita-core' ) . '</th>';
		echo '<th>' . esc_html__( 'Qty', 'perdita-core' ) . '</th>';
		echo '<th>' . esc_html__( 'Line total', 'perdita-core' ) . '</th>';
		echo '</tr></thead><tbody>';
		if ( is_array( $items ) ) {
			foreach ( $items as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$unit = (float) ( $item['unit_price'] ?? 0 );
				$qty  = (int) ( $item['qty'] ?? 0 );
				echo '<tr>';
				echo '<td>' . esc_html( (string) ( $item['title'] ?? '' ) ) . '</td>';
				echo '<td>' . esc_html( (string) ( $item['type'] ?? '' ) ) . '</td>';
				echo '<td>' . esc_html( $this->main->format_money( $unit, $currency ) ) . '</td>';
				echo '<td>' . esc_html( (string) $qty ) . '</td>';
				echo '<td>' . esc_html( $this->main->format_money( $unit * $qty, $currency ) ) . '</td>';
				echo '</tr>';
			}
		}
		echo '</tbody></table>';

		echo '<table class="form-table" role="presentation">';
		echo '<tr><th>' . esc_html__( 'Subtotal', 'perdita-core' ) . '</th><td>' . esc_html( $this->main->format_money( $subtotal, $currency ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Shipping', 'perdita-core' ) . '</th><td>' . esc_html( $this->main->format_money( $shipping, $currency ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Tax', 'perdita-core' ) . '</th><td>' . esc_html( $this->main->format_money( $tax, $currency ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Total', 'perdita-core' ) . '</th><td><strong>' . esc_html( $this->main->format_money( $total, $currency ) ) . '</strong></td></tr>';
		echo '</table>';

		// Shipping address, if any.
		if ( is_array( $ship ) && '' !== trim( (string) ( $ship['line1'] ?? '' ) ) ) {
			echo '<h3>' . esc_html__( 'Shipping address', 'perdita-core' ) . '</h3>';
			echo '<p>';
			foreach ( array( 'name', 'line1', 'line2', 'city', 'state', 'postcode', 'country' ) as $k ) {
				$v = trim( (string) ( $ship[ $k ] ?? '' ) );
				if ( '' !== $v ) {
					echo esc_html( $v ) . '<br />';
				}
			}
			echo '</p>';
		}
	}

	/* ------------------------------------------------------------------ */
	/* Settings screen                                                    */
	/* ------------------------------------------------------------------ */

	/**
	 * The settings page URL.
	 *
	 * @return string
	 */
	private function settings_url() {
		return add_query_arg(
			array(
				'post_type' => Perdita_Sales::CPT_PRODUCT,
				'page'      => self::PAGE_SETTINGS,
			),
			admin_url( 'edit.php' )
		);
	}

	/**
	 * Render the store settings screen.
	 */
	public function render_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'perdita-core' ) );
		}

		$s              = Perdita_Sales::settings();
		$has_secret     = '' !== (string) $s['secret_key'];
		$has_webhook    = '' !== (string) $s['webhook_secret'];
		$webhook_url    = rest_url( Perdita_Sales::NS . '/sales/webhook' );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Store settings', 'perdita-core' ) . '</h1>';
		echo '<p>' . esc_html__( 'Set your currency, tax, and Stripe keys. Card payments are handled on Stripe, so card data never touches this site.', 'perdita-core' ) . '</p>';

		$this->render_settings_notice();

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="perdita_sales_save_settings" />';
		wp_nonce_field( 'perdita_sales_save_settings' );
		echo '<table class="form-table" role="presentation">';

		// Currency.
		echo '<tr><th scope="row"><label for="perdita-sales-currency">' . esc_html__( 'Currency', 'perdita-core' ) . '</label></th><td>';
		echo '<input type="text" id="perdita-sales-currency" name="currency" value="' . esc_attr( $s['currency'] ) . '" class="small-text" maxlength="3" />';
		echo '<p class="description">' . esc_html__( 'Three-letter code, for example USD, EUR, or GBP.', 'perdita-core' ) . '</p>';
		echo '</td></tr>';

		// Tax rate.
		echo '<tr><th scope="row"><label for="perdita-sales-tax">' . esc_html__( 'Tax rate', 'perdita-core' ) . '</label></th><td>';
		echo '<input type="number" step="0.01" min="0" max="100" id="perdita-sales-tax" name="tax_rate" value="' . esc_attr( (string) $s['tax_rate'] ) . '" class="small-text" /> %';
		echo '<p class="description">' . esc_html__( 'A single flat rate applied to the order subtotal plus shipping. Leave at 0 for none.', 'perdita-core' ) . '</p>';
		echo '</td></tr>';

		// Publishable key (plain).
		echo '<tr><th scope="row"><label for="perdita-sales-pub">' . esc_html__( 'Stripe publishable key', 'perdita-core' ) . '</label></th><td>';
		echo '<input type="text" id="perdita-sales-pub" name="publishable_key" value="' . esc_attr( $s['publishable_key'] ) . '" class="regular-text" placeholder="pk_live_..." autocomplete="off" />';
		echo '<p class="description">' . esc_html__( 'This key is public and safe to store as-is.', 'perdita-core' ) . '</p>';
		echo '</td></tr>';

		// Secret key (encrypted).
		echo '<tr><th scope="row"><label for="perdita-sales-secret">' . esc_html__( 'Stripe secret key', 'perdita-core' ) . '</label></th><td>';
		$secret_placeholder = $has_secret ? esc_attr__( 'saved (leave blank to keep)', 'perdita-core' ) : '';
		echo '<input type="password" id="perdita-sales-secret" name="secret_key" value="" class="regular-text" autocomplete="new-password" placeholder="' . esc_attr( $secret_placeholder ) . '" />';
		if ( $has_secret ) {
			echo '<label style="margin-left:8px;"><input type="checkbox" name="remove_secret_key" value="1" /> ' . esc_html__( 'Remove', 'perdita-core' ) . '</label>';
		}
		echo '<p class="description">' . esc_html__( 'Encrypted before it is saved. Type a new value only when you want to change it.', 'perdita-core' ) . '</p>';
		echo '</td></tr>';

		// Webhook signing secret (encrypted).
		echo '<tr><th scope="row"><label for="perdita-sales-webhook">' . esc_html__( 'Webhook signing secret', 'perdita-core' ) . '</label></th><td>';
		$webhook_placeholder = $has_webhook ? esc_attr__( 'saved (leave blank to keep)', 'perdita-core' ) : '';
		echo '<input type="password" id="perdita-sales-webhook" name="webhook_secret" value="" class="regular-text" autocomplete="new-password" placeholder="' . esc_attr( $webhook_placeholder ) . '" />';
		if ( $has_webhook ) {
			echo '<label style="margin-left:8px;"><input type="checkbox" name="remove_webhook_secret" value="1" /> ' . esc_html__( 'Remove', 'perdita-core' ) . '</label>';
		}
		echo '<p class="description">' . esc_html__( 'From your Stripe webhook endpoint. Encrypted before it is saved.', 'perdita-core' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'Point your Stripe webhook at this URL and subscribe to checkout.session.completed:', 'perdita-core' ) . '<br /><code>' . esc_html( $webhook_url ) . '</code></p>';
		echo '</td></tr>';

		// Max downloads.
		echo '<tr><th scope="row"><label for="perdita-sales-maxdl">' . esc_html__( 'Download limit per file', 'perdita-core' ) . '</label></th><td>';
		echo '<input type="number" min="0" id="perdita-sales-maxdl" name="max_downloads" value="' . esc_attr( (string) $s['max_downloads'] ) . '" class="small-text" />';
		echo '<p class="description">' . esc_html__( 'How many times a buyer can download each file. Set 0 for unlimited.', 'perdita-core' ) . '</p>';
		echo '</td></tr>';

		// Success page.
		echo '<tr><th scope="row"><label for="perdita-sales-success">' . esc_html__( 'Success page', 'perdita-core' ) . '</label></th><td>';
		wp_dropdown_pages(
			array(
				'name'              => 'success_page',
				'id'               => 'perdita-sales-success',
				'show_option_none'  => esc_html__( 'Home page', 'perdita-core' ),
				'option_none_value' => 0,
				'selected'          => (int) $s['success_page'],
			)
		);
		echo '<p class="description">' . esc_html__( 'The page buyers return to after paying. Add the [perdita_checkout] shortcode there to show the confirmation and downloads.', 'perdita-core' ) . '</p>';
		echo '</td></tr>';

		echo '</table>';
		submit_button( __( 'Save settings', 'perdita-core' ) );
		echo '</form>';

		// Shortcode reference.
		echo '<hr />';
		echo '<h2>' . esc_html__( 'Shortcodes', 'perdita-core' ) . '</h2>';
		echo '<ul style="list-style:disc;margin-left:20px;">';
		echo '<li><code>[perdita_product id="123"]</code> ' . esc_html__( 'one product with an Add to cart button', 'perdita-core' ) . '</li>';
		echo '<li><code>[perdita_products]</code> ' . esc_html__( 'a grid of your products', 'perdita-core' ) . '</li>';
		echo '<li><code>[perdita_cart]</code> ' . esc_html__( 'the cart', 'perdita-core' ) . '</li>';
		echo '<li><code>[perdita_checkout]</code> ' . esc_html__( 'the checkout and, on return from Stripe, the confirmation', 'perdita-core' ) . '</li>';
		echo '</ul>';

		echo '</div>';
	}

	/**
	 * Render a saved notice after a settings save redirect.
	 */
	private function render_settings_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only status flag on a redirect.
		$status = isset( $_GET['perdita_sales_msg'] ) ? sanitize_key( wp_unslash( $_GET['perdita_sales_msg'] ) ) : '';
		if ( 'saved' === $status ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'perdita-core' ) . '</p></div>';
		}
	}

	/**
	 * Save settings. Capability + nonce enforced. Secrets encrypted, everything
	 * else sanitized. Never echoes a secret.
	 */
	public function handle_save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'perdita-core' ) );
		}
		check_admin_referer( 'perdita_sales_save_settings' );

		$current = Perdita_Sales::settings();

		// Currency: 3 letters, upper-cased, else keep current.
		$currency = isset( $_POST['currency'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['currency'] ) ) ) : $current['currency'];
		if ( ! preg_match( '/^[A-Z]{3}$/', $currency ) ) {
			$currency = $current['currency'];
		}

		// Tax rate: clamp 0..100.
		$tax = isset( $_POST['tax_rate'] ) ? (float) wp_unslash( $_POST['tax_rate'] ) : 0.0;
		if ( ! is_finite( $tax ) || $tax < 0 ) {
			$tax = 0.0;
		}
		if ( $tax > 100 ) {
			$tax = 100.0;
		}

		$max_dl = isset( $_POST['max_downloads'] ) ? absint( wp_unslash( $_POST['max_downloads'] ) ) : 0;

		$new = array(
			'currency'        => $currency,
			'publishable_key' => isset( $_POST['publishable_key'] ) ? sanitize_text_field( wp_unslash( $_POST['publishable_key'] ) ) : '',
			'secret_key'      => $current['secret_key'],     // Keep unless changed below.
			'webhook_secret'  => $current['webhook_secret'], // Keep unless changed below.
			'tax_rate'        => round( $tax, 2 ),
			'download_secret' => $current['download_secret'], // Never editable from the form.
			'max_downloads'   => $max_dl,
			'success_page'    => isset( $_POST['success_page'] ) ? absint( wp_unslash( $_POST['success_page'] ) ) : 0,
		);

		// The download-signing secret must always exist.
		if ( '' === (string) $new['download_secret'] ) {
			$new['download_secret'] = bin2hex( random_bytes( 32 ) );
		}

		// Secret key: remove wins, then a newly typed value, else keep.
		if ( ! empty( $_POST['remove_secret_key'] ) ) {
			$new['secret_key'] = '';
		} else {
			// Opaque secret: do not run it through text sanitizers. Encrypt immediately.
			$typed = isset( $_POST['secret_key'] ) ? trim( (string) wp_unslash( $_POST['secret_key'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- opaque secret, encrypted below, never echoed.
			if ( '' !== $typed ) {
				$new['secret_key'] = Perdita_Crypto::encrypt( $typed );
			}
		}

		// Webhook signing secret: same pattern.
		if ( ! empty( $_POST['remove_webhook_secret'] ) ) {
			$new['webhook_secret'] = '';
		} else {
			$typed = isset( $_POST['webhook_secret'] ) ? trim( (string) wp_unslash( $_POST['webhook_secret'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- opaque secret, encrypted below, never echoed.
			if ( '' !== $typed ) {
				$new['webhook_secret'] = Perdita_Crypto::encrypt( $typed );
			}
		}

		update_option( Perdita_Sales::OPTION, $new );

		wp_safe_redirect( add_query_arg( array( 'perdita_sales_msg' => 'saved' ), $this->settings_url() ) );
		exit;
	}
}
