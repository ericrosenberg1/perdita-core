<?php
/**
 * Subscriptions admin: a settings screen (from name, email templates) plus a
 * paginated subscriber list under the Perdita menu.
 *
 * Saving goes through admin-post.php and is guarded by a capability check and
 * a nonce. Every value is sanitized on write and escaped on output.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

class Perdita_Subscriptions_Admin {

	/**
	 * The admin-post action for saving settings.
	 */
	const SAVE_ACTION = 'perdita_subscriptions_save';

	/**
	 * The admin-post action for removing a subscriber.
	 */
	const REMOVE_ACTION = 'perdita_subscriptions_remove';

	/**
	 * The settings-page slug.
	 */
	const PAGE = 'perdita-subscriptions';

	/**
	 * Subscribers shown per page on the list.
	 */
	const PER_PAGE = 50;

	/**
	 * The subscriptions module core.
	 *
	 * @var Perdita_Subscriptions
	 */
	private $main;

	/**
	 * The Perdita core.
	 *
	 * @var Perdita
	 */
	private $core;

	/**
	 * Constructor.
	 *
	 * @param Perdita_Subscriptions $main The module core.
	 * @param Perdita                $core The Perdita core.
	 */
	public function __construct( $main, $core ) {
		$this->main = $main;
		$this->core = $core;
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_' . self::SAVE_ACTION, array( $this, 'save' ) );
		add_action( 'admin_post_' . self::REMOVE_ACTION, array( $this, 'remove_subscriber' ) );
	}

	/**
	 * Register the Subscriptions submenu under the Perdita menu.
	 */
	public function menu() {
		add_submenu_page(
			'perdita',
			__( 'Subscriptions', 'perdita-core' ),
			__( 'Subscriptions', 'perdita-core' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render' )
		);
	}

	/* ---------- render ---------- */

	/**
	 * Render the settings + subscriber list screen.
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$s = $this->main->get_settings();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Subscriptions', 'perdita-core' ) . '</h1>';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['perdita_msg'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['perdita_msg'] ) ) ) . '</p></div>';
		}

		$this->box_shortcode();
		$this->box_counts();
		$this->box_settings( $s );
		$this->box_subscriber_list();
		$this->premium();

		echo '</div>';
	}

	/**
	 * Shortcode helper box, matching the Forms embed box.
	 */
	private function box_shortcode() {
		echo '<h2>' . esc_html__( 'Embed', 'perdita-core' ) . '</h2>';
		echo '<p>' . esc_html__( 'Paste this shortcode where you want the signup form:', 'perdita-core' ) . '</p>';
		echo '<input type="text" readonly class="widefat" style="max-width:320px;" onclick="this.select()" aria-label="' . esc_attr__( 'Shortcode to embed the signup form', 'perdita-core' ) . '" value="[perdita_subscribe]" />';
	}

	/**
	 * Live subscriber counts.
	 */
	private function box_counts() {
		$counts = Perdita_Subscriptions::counts();
		echo '<h2>' . esc_html__( 'Subscribers', 'perdita-core' ) . '</h2>';
		echo '<ul style="list-style:disc;padding-left:20px;">';
		printf( '<li>%s</li>', esc_html( sprintf(
			/* translators: %d: number of confirmed subscribers. */
			_n( '%d confirmed', '%d confirmed', $counts['confirmed'], 'perdita-core' ),
			$counts['confirmed']
		) ) );
		printf( '<li>%s</li>', esc_html( sprintf(
			/* translators: %d: number of pending subscribers. */
			_n( '%d pending confirmation', '%d pending confirmation', $counts['pending'], 'perdita-core' ),
			$counts['pending']
		) ) );
		printf( '<li>%s</li>', esc_html( sprintf(
			/* translators: %d: number of unsubscribed addresses. */
			_n( '%d unsubscribed', '%d unsubscribed', $counts['unsubscribed'], 'perdita-core' ),
			$counts['unsubscribed']
		) ) );
		echo '</ul>';
	}

	/**
	 * Settings form: from name + confirmation and notification templates.
	 *
	 * @param array $s Current settings.
	 */
	private function box_settings( $s ) {
		echo '<h2>' . esc_html__( 'Settings', 'perdita-core' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::SAVE_ACTION ) . '" />';
		wp_nonce_field( self::SAVE_ACTION );
		echo '<table class="form-table" role="presentation">';

		echo '<tr><th scope="row"><label for="ps-from-name">' . esc_html__( 'From name', 'perdita-core' ) . '</label></th><td>';
		echo '<input type="text" id="ps-from-name" class="regular-text" name="from_name" value="' . esc_attr( $s['from_name'] ) . '" />';
		echo '<p class="description">' . esc_html__( 'Shown as the sender name on confirmation and notification emails.', 'perdita-core' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="ps-resend">' . esc_html__( 'Resend cooldown', 'perdita-core' ) . '</label></th><td>';
		echo '<input type="number" id="ps-resend" min="1" step="1" name="resend_cooldown_mins" value="' . esc_attr( $s['resend_cooldown_mins'] ) . '" style="width:80px;" /> ' . esc_html__( 'minutes', 'perdita-core' );
		echo '<p class="description">' . esc_html__( 'Minimum time between confirmation emails to the same address, so repeated signup attempts for one address cannot flood its inbox.', 'perdita-core' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th colspan="2"><h3>' . esc_html__( 'Confirmation email', 'perdita-core' ) . '</h3></th></tr>';

		echo '<tr><th scope="row"><label for="ps-confirm-subject">' . esc_html__( 'Subject', 'perdita-core' ) . '</label></th><td>';
		echo '<input type="text" id="ps-confirm-subject" class="large-text" name="confirm_subject" value="' . esc_attr( $s['confirm_subject'] ) . '" />';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="ps-confirm-body">' . esc_html__( 'Body', 'perdita-core' ) . '</label></th><td>';
		echo '<textarea id="ps-confirm-body" class="large-text" rows="6" name="confirm_body">' . esc_textarea( $s['confirm_body'] ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'Placeholders: {site_name}, {confirm_url}', 'perdita-core' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th colspan="2"><h3>' . esc_html__( 'New post notification', 'perdita-core' ) . '</h3></th></tr>';

		echo '<tr><th scope="row"><label for="ps-notify-subject">' . esc_html__( 'Subject', 'perdita-core' ) . '</label></th><td>';
		echo '<input type="text" id="ps-notify-subject" class="large-text" name="notify_subject" value="' . esc_attr( $s['notify_subject'] ) . '" />';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="ps-notify-body">' . esc_html__( 'Body', 'perdita-core' ) . '</label></th><td>';
		echo '<textarea id="ps-notify-body" class="large-text" rows="8" name="notify_body">' . esc_textarea( $s['notify_body'] ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'Placeholders: {post_title}, {post_excerpt}, {post_url}, {unsubscribe_url}, {site_name}. The unsubscribe link is always included even if you remove the placeholder.', 'perdita-core' ) . '</p>';
		echo '</td></tr>';

		echo '</table>';
		submit_button( __( 'Save settings', 'perdita-core' ) );
		echo '</form>';
	}

	/**
	 * Save settings.
	 */
	public function save() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( self::SAVE_ACTION ) ) {
			wp_die( esc_html__( 'Not allowed.', 'perdita-core' ) );
		}

		$s = array(
			'from_name'            => isset( $_POST['from_name'] ) ? sanitize_text_field( wp_unslash( $_POST['from_name'] ) ) : '',
			'confirm_subject'      => isset( $_POST['confirm_subject'] ) ? sanitize_text_field( wp_unslash( $_POST['confirm_subject'] ) ) : '',
			'confirm_body'         => isset( $_POST['confirm_body'] ) ? sanitize_textarea_field( wp_unslash( $_POST['confirm_body'] ) ) : '',
			'notify_subject'       => isset( $_POST['notify_subject'] ) ? sanitize_text_field( wp_unslash( $_POST['notify_subject'] ) ) : '',
			'notify_body'          => isset( $_POST['notify_body'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notify_body'] ) ) : '',
			'resend_cooldown_mins' => isset( $_POST['resend_cooldown_mins'] ) ? max( 1, (int) $_POST['resend_cooldown_mins'] ) : 10,
		);

		// Never let a saved template lose the unsubscribe link entirely, fall
		// back to the default body rather than store a template that can't
		// meet the "always in every email" promise.
		if ( '' === $s['notify_body'] ) {
			$s['notify_body'] = Perdita_Subscriptions::defaults()['notify_body'];
		}
		if ( '' === $s['confirm_body'] ) {
			$s['confirm_body'] = Perdita_Subscriptions::defaults()['confirm_body'];
		}

		update_option( Perdita_Subscriptions::OPTION, $s );

		wp_safe_redirect(
			add_query_arg(
				'perdita_msg',
				rawurlencode( __( 'Settings saved.', 'perdita-core' ) ),
				admin_url( 'admin.php?page=' . self::PAGE )
			)
		);
		exit;
	}

	/* ---------- subscriber list ---------- */

	/**
	 * Paginated subscriber table, same pagination shape as
	 * Perdita_Forms_Admin::entries_page().
	 */
	private function box_subscriber_list() {
		global $wpdb;
		$table = Perdita_Subscriptions::table();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged  = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		$total  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" ); // phpcs:ignore WordPress.DB
		$offset = ( $paged - 1 ) * self::PER_PAGE;
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table ORDER BY created DESC LIMIT %d OFFSET %d", self::PER_PAGE, $offset ) ); // phpcs:ignore WordPress.DB

		echo '<h2>' . esc_html__( 'Subscriber list', 'perdita-core' ) . '</h2>';

		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'No subscribers yet.', 'perdita-core' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Email', 'perdita-core' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'perdita-core' ) . '</th>';
		echo '<th>' . esc_html__( 'Subscribed', 'perdita-core' ) . '</th>';
		echo '<th></th>';
		echo '</tr></thead><tbody>';

		$labels = array(
			'confirmed'    => __( 'Confirmed', 'perdita-core' ),
			'pending'      => __( 'Pending', 'perdita-core' ),
			'unsubscribed' => __( 'Unsubscribed', 'perdita-core' ),
		);

		foreach ( $rows as $row ) {
			$remove_url = wp_nonce_url(
				add_query_arg(
					array(
						'action' => self::REMOVE_ACTION,
						'id'     => $row->id,
					),
					admin_url( 'admin-post.php' )
				),
				self::REMOVE_ACTION . '_' . $row->id
			);

			echo '<tr>';
			echo '<td>' . esc_html( $row->email ) . '</td>';
			echo '<td>' . esc_html( isset( $labels[ $row->status ] ) ? $labels[ $row->status ] : $row->status ) . '</td>';
			echo '<td>' . esc_html( $row->created ) . '</td>';
			echo '<td><a href="' . esc_url( $remove_url ) . '" onclick="return confirm(\'' . esc_js( __( 'Remove this subscriber? This cannot be undone.', 'perdita-core' ) ) . '\');">' . esc_html__( 'Remove', 'perdita-core' ) . '</a></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		$total_pages = (int) ceil( $total / self::PER_PAGE );
		if ( $total_pages > 1 ) {
			$links = paginate_links(
				array(
					'base'      => add_query_arg( 'paged', '%#%' ),
					'format'    => '',
					'current'   => $paged,
					'total'     => $total_pages,
					'prev_text' => __( '&laquo; Newer', 'perdita-core' ),
					'next_text' => __( 'Older &raquo;', 'perdita-core' ),
				)
			);
			if ( $links ) {
				echo '<p class="perdita-subscriptions__pagination" style="margin-top:12px;">' . wp_kses_post( $links ) . '</p>';
			}
		}
	}

	/**
	 * Remove a subscriber (manual GDPR-style removal request).
	 */
	public function remove_subscriber() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		if ( ! current_user_can( 'manage_options' ) || ! $id || ! check_admin_referer( self::REMOVE_ACTION . '_' . $id ) ) {
			wp_die( esc_html__( 'Not allowed.', 'perdita-core' ) );
		}

		global $wpdb;
		$table = Perdita_Subscriptions::table();
		$wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		wp_safe_redirect(
			add_query_arg(
				'perdita_msg',
				rawurlencode( __( 'Subscriber removed.', 'perdita-core' ) ),
				admin_url( 'admin.php?page=' . self::PAGE )
			)
		);
		exit;
	}

	/* ---------- premium ---------- */

	/**
	 * "Coming in Perdita Premium" card, matching the pattern in
	 * Perdita_SEO_Admin::premium(). Inert UI only, no gating mechanism.
	 */
	private function premium() {
		?>
		<div class="card" style="max-width:760px;border-left:4px solid #2563eb;">
			<h2 style="margin-top:0;"><?php esc_html_e( 'Coming in Perdita Premium', 'perdita-core' ); ?></h2>
			<ul style="list-style:disc;padding-left:20px;color:#3c434a;">
				<li><?php esc_html_e( 'Weekly and monthly roundup digest emails, instead of (or alongside) a notification for every single post.', 'perdita-core' ); ?></li>
			</ul>
			<p class="description"><?php esc_html_e( 'Everything on this page is free, forever. Premium adds depth for bigger sites.', 'perdita-core' ); ?></p>
		</div>
		<?php
	}
}
