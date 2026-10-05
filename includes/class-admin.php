<?php
/**
 * Admin UI: menu, settings pages, tools and AJAX handlers.
 *
 * Pages:
 *  - Connection   (API key, device, country code, webhook, test tools)
 *  - Automations  (WooCommerce events, templates, macros, preview)
 *  - OTP          (authentication settings and limits)
 *  - Messages     (log, filters, manual sync with TextBee)
 *  - Diagnostics  (health checks, duplicate phones, counters)
 *
 * @package Barar_Atik
 */

defined( 'ABSPATH' ) || exit;

class Barar_Atik_Admin {

	const NONCE = 'barar_atik_admin';

	/**
	 * Plugin container.
	 *
	 * @var Barar_Atik_Plugin
	 */
	private $plugin;

	/**
	 * Hook up menu, assets and AJAX.
	 *
	 * @param Barar_Atik_Plugin $plugin Plugin container.
	 */
	public function __construct( $plugin ) {
		$this->plugin = $plugin;

		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_notices', array( $this, 'notices' ) );

		add_action( 'wp_ajax_barar_atik_test_connection', array( $this, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_barar_atik_test_sms', array( $this, 'ajax_test_sms' ) );
		add_action( 'wp_ajax_barar_atik_preview', array( $this, 'ajax_preview' ) );
		add_action( 'wp_ajax_barar_atik_sync', array( $this, 'ajax_sync' ) );
		add_action( 'wp_ajax_barar_atik_create_webhook', array( $this, 'ajax_create_webhook' ) );
		add_action( 'wp_ajax_barar_atik_search_messages', array( $this, 'ajax_search_messages' ) );

		add_action( 'admin_post_barar_atik_resolve_phone', array( $this, 'resolve_phone' ) );
		add_action( 'admin_post_barar_atik_reset_stats', array( $this, 'reset_stats' ) );
		add_action( 'admin_post_barar_atik_export', array( $this, 'export_csv' ) );
	}

	/* ---------------------------------------------------------------------
	 * Menu and assets
	 * ------------------------------------------------------------------ */

	/**
	 * Every screen this plugin owns: slug => array( label, callback ).
	 *
	 * The menu, the sub-navigation and the asset loader all read this single
	 * list. Keeping three copies of it is how a page ended up reachable from
	 * the menu while the script loader was still matching an allow-list that
	 * never contained it - the buttons on seven of the eight screens were
	 * dead because of that drift.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	private static function pages() {
		return array(
			'barar-atik-sms-otp'     => array( __( 'Dashboard', 'barar-atik-sms-otp' ), 'page_dashboard' ),
			'barar-atik-connection'  => array( __( 'Connection', 'barar-atik-sms-otp' ), 'page_connection' ),
			'barar-atik-automations' => array( __( 'Automations', 'barar-atik-sms-otp' ), 'page_automations' ),
			'barar-atik-otp'         => array( __( 'Authentication', 'barar-atik-sms-otp' ), 'page_otp' ),
			'barar-atik-forms'       => array( __( 'Forms', 'barar-atik-sms-otp' ), 'page_forms' ),
			'barar-atik-design'      => array( __( 'Design', 'barar-atik-sms-otp' ), 'page_design' ),
			'barar-atik-messages'    => array( __( 'Messages', 'barar-atik-sms-otp' ), 'page_messages' ),
			'barar-atik-diagnostics' => array( __( 'Diagnostics', 'barar-atik-sms-otp' ), 'page_diagnostics' ),
		);
	}

	/**
	 * Register the menu structure.
	 *
	 * @return void
	 */
	public function menu() {
		$cap  = 'manage_options';
		$subs = self::pages();

		add_menu_page(
			__( 'TextBee SMS', 'barar-atik-sms-otp' ),
			__( 'TextBee SMS', 'barar-atik-sms-otp' ),
			$cap,
			'barar-atik-sms-otp',
			array( $this, 'page_dashboard' ),
			'dashicons-email-alt',
			58
		);

		foreach ( $subs as $slug => $sub ) {
			add_submenu_page(
				'barar-atik-sms-otp',
				$sub[0],
				$sub[0],
				$cap,
				$slug,
				array( $this, $sub[1] )
			);
		}
	}

	/**
	 * Shared sub-navigation shown on every plugin page.
	 *
	 * @param string $current Slug of the active page.
	 * @return void
	 */
	private function nav( $current ) {
		$items = array();
		foreach ( self::pages() as $slug => $sub ) {
			$items[ $slug ] = $sub[0];
		}
		?>
		<nav class="barar-nav" aria-label="<?php esc_attr_e( 'Plugin sections', 'barar-atik-sms-otp' ); ?>">
			<?php foreach ( $items as $slug => $label ) : ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $slug ) ); ?>"
					class="barar-nav__item<?php echo $slug === $current ? ' is-active' : ''; ?>"
					<?php echo $slug === $current ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	/**
	 * Enqueue admin assets only on our pages.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function assets( $hook ) {
		/*
		 * Decide on the page slug, never on $hook.
		 *
		 * WordPress builds a submenu's hook suffix from $admin_page_hooks,
		 * which holds the SLUGIFIED MENU TITLE rather than the menu slug. This
		 * plugin's title is "TextBee SMS", so the suffix is
		 * `textbee-sms_page_barar-atik-forms` - an allow-list of
		 * `barar-atik-sms-otp_page_...` matches only the dashboard, and the
		 * other seven screens loaded without admin.css or admin.js. Every
		 * button on them (test connection, test SMS, preview, webhook, sync,
		 * message search) was inert, and the list also rewrites itself when
		 * the title is translated.
		 *
		 * $_GET['page'] is the slug this class registered, so it is the one
		 * identifier that cannot drift.
		 */
		unset( $hook );

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( ! array_key_exists( $page, self::pages() ) ) {
			return;
		}

		// Version each asset by its own mtime: BARAR_ATIK_VERSION only changes on
		// release, so reusing it for files that are edited in place leaves browsers
		// serving a stale admin.css from the previous ?ver= value.
		wp_enqueue_style( 'barar-atik-admin', BARAR_ATIK_URL . 'assets/admin.css', array(), Barar_Atik_Plugin::asset_version( 'assets/admin.css' ) );
		wp_enqueue_script( 'barar-atik-admin', BARAR_ATIK_URL . 'assets/admin.js', array(), Barar_Atik_Plugin::asset_version( 'assets/admin.js' ), true );

		wp_localize_script(
			'barar-atik-admin',
			'bararAtikAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
				'actions' => array(
					'connection' => 'barar_atik_test_connection',
					'testSms'    => 'barar_atik_test_sms',
					'preview'    => 'barar_atik_preview',
					'sync'       => 'barar_atik_sync',
					'webhook'    => 'barar_atik_create_webhook',
					'searchMessages' => 'barar_atik_search_messages',
				),
				'i18n'     => array(
					'network'      => __( 'Network error. Please try again.', 'barar-atik-sms-otp' ),
					'working'      => __( 'Working…', 'barar-atik-sms-otp' ),
					'synced'       => __( 'Sync complete. Refreshing the list…', 'barar-atik-sms-otp' ),
					'inserted'     => __( 'Macro inserted.', 'barar-atik-sms-otp' ),
					'copied'       => __( 'Copied!', 'barar-atik-sms-otp' ),
					'noTemplate'   => __( 'Click a message box first, then click a macro.', 'barar-atik-sms-otp' ),
					/* translators: 1: number of characters, 2: number of SMS segments, 3: encoding name (GSM-7 or UCS-2). Placeholder order must stay %1$d, %2$d, %3$s. */
					'chars'        => __( '%1$d characters · %2$d SMS (%3$s)', 'barar-atik-sms-otp' ),
					'show'         => __( 'Show', 'barar-atik-sms-otp' ),
					'hide'         => __( 'Hide', 'barar-atik-sms-otp' ),
					'required'     => __( 'Please fill in the required fields.', 'barar-atik-sms-otp' ),
				),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Small admin notices (saved settings, resolver results).
	 * ------------------------------------------------------------------ */
	public function notices() {
		$screen = get_current_screen();
		if ( ! $screen || false === strpos( (string) $screen->id, 'barar-atik' ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only redirect flags used to render notices; the state-changing requests themselves are nonce checked.
		if ( isset( $_GET['settings-updated'] ) ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html__( 'Settings saved.', 'barar-atik-sms-otp' )
			);
		}

		$notice = isset( $_GET['barar_notice'] ) ? sanitize_key( wp_unslash( $_GET['barar_notice'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$map    = array(
			'phone_removed' => array( 'success', __( 'The duplicate phone number was removed from that account.', 'barar-atik-sms-otp' ) ),
			'phone_error'   => array( 'error', __( 'The phone number could not be removed. Please reload and try again.', 'barar-atik-sms-otp' ) ),
			'stats_reset'   => array( 'success', __( 'The counters were reset.', 'barar-atik-sms-otp' ) ),
		);
		if ( isset( $map[ $notice ] ) ) {
			printf(
				'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
				esc_attr( $map[ $notice ][0] ),
				esc_html( $map[ $notice ][1] )
			);
		}
	}

	/* ---------------------------------------------------------------------
	 * Shared markup helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Capability guard for every page.
	 *
	 * @return void
	 */
	private function guard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'barar-atik-sms-otp' ) );
		}
	}

	/**
	 * Open a Settings API form bound to our shared option.
	 *
	 * @return void
	 */
	private function form_open() {
		?>
		<form method="post" action="options.php">
		<?php settings_fields( Barar_Atik_Settings::GROUP ); ?>
		<?php
	}

	/**
	 * Close the settings form.
	 *
	 * @return void
	 */
	private function form_close() {
		submit_button( __( 'Save settings', 'barar-atik-sms-otp' ), 'primary', 'submit', true );
		?>
		</form>
		<?php
	}

	/**
	 * Current value for a settings key.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	private function val( $key ) {
		return (string) Barar_Atik_Settings::get( $key, '' );
	}

	/**
	 * Input id for a key.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	private function field_id( $key ) {
		return 'barar-f-' . sanitize_key( str_replace( array( '.', '[', ']' ), '-', $key ) );
	}

	/**
	 * Text / number / url input row.
	 *
	 * @param string $key   Settings key.
	 * @param string $label Label.
	 * @param array  $args  placeholder, hint, type, min, max, class.
	 * @return void
	 */
	private function field_text( $key, $label, $args = array() ) {
		$args  = wp_parse_args(
			$args,
			array(
				'type'        => 'text',
				'placeholder' => '',
				'hint'        => '',
				'class'       => '',
				'min'         => null,
				'max'         => null,
			)
		);
		$id    = $this->field_id( $key );
		$name  = 'barar_atik_settings[' . $key . ']';
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<input
					type="<?php echo esc_attr( $args['type'] ); ?>"
					class="regular-text <?php echo esc_attr( $args['class'] ); ?>"
					id="<?php echo esc_attr( $id ); ?>"
					name="<?php echo esc_attr( $name ); ?>"
					value="<?php echo esc_attr( $this->val( $key ) ); ?>"
					placeholder="<?php echo esc_attr( $args['placeholder'] ); ?>"
					<?php if ( null !== $args['min'] ) : ?>min="<?php echo esc_attr( (string) $args['min'] ); ?>"<?php endif; ?>
					<?php if ( null !== $args['max'] ) : ?>max="<?php echo esc_attr( (string) $args['max'] ); ?>"<?php endif; ?>
				/>
				<?php if ( '' !== $args['hint'] ) : ?>
					<p class="description"><?php echo esc_html( $args['hint'] ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Password-style row: the stored value is never printed.
	 *
	 * @param string $key   Settings key.
	 * @param string $label Label.
	 * @param array  $args  hint, placeholder.
	 * @return void
	 */
	private function field_secret( $key, $label, $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'hint'        => '',
				'placeholder' => '',
			)
		);
		$id    = $this->field_id( $key );
		$name  = 'barar_atik_settings[' . $key . ']';
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<div class="barar-secret">
					<input
						type="password"
						class="regular-text"
						id="<?php echo esc_attr( $id ); ?>"
						name="<?php echo esc_attr( $name ); ?>"
						value=""
						autocomplete="new-password"
						placeholder="<?php echo esc_attr( $args['placeholder'] ); ?>"
					/>
					<button type="button" class="button barar-toggle-secret" aria-pressed="false"><?php esc_html_e( 'Show', 'barar-atik-sms-otp' ); ?></button>
				</div>
				<?php if ( '' !== $args['hint'] ) : ?>
					<p class="description"><?php echo esc_html( $args['hint'] ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Checkbox row (hidden 0 + checkbox 1 pattern).
	 *
	 * @param string $key   Settings key.
	 * @param string $label Label.
	 * @param string $desc  Description.
	 * @return void
	 */
	private function field_checkbox( $key, $label, $desc = '' ) {
		$name = 'barar_atik_settings[' . $key . ']';
		?>
		<tr>
			<th scope="row"><?php echo esc_html( $label ); ?></th>
			<td>
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="0" />
				<label>
					<input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( '1', $this->val( $key ) ); ?> />
					<?php if ( '' !== $desc ) : ?>
						<?php echo esc_html( $desc ); ?>
					<?php else : ?>
						<?php esc_html_e( 'Enabled', 'barar-atik-sms-otp' ); ?>
					<?php endif; ?>
				</label>
			</td>
		</tr>
		<?php
	}

	/**
	 * Select row.
	 *
	 * @param string $key     Settings key.
	 * @param string $label   Label.
	 * @param array  $options value => label.
	 * @param array  $args    hint.
	 * @return void
	 */
	private function field_select( $key, $label, $options, $args = array() ) {
		$args = wp_parse_args( $args, array( 'hint' => '' ) );
		$id   = $this->field_id( $key );
		$name = 'barar_atik_settings[' . $key . ']';
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<select id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>">
					<?php foreach ( $options as $value => $opt_label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $this->val( $key ), (string) $value ); ?>><?php echo esc_html( $opt_label ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php if ( '' !== $args['hint'] ) : ?>
					<p class="description"><?php echo esc_html( $args['hint'] ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Textarea row.
	 *
	 * @param string $key   Settings key.
	 * @param string $label Label.
	 * @param array  $args  rows, placeholder, hint, template class.
	 * @return void
	 */
	private function field_textarea( $key, $label, $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'rows'        => 4,
				'placeholder' => '',
				'hint'        => '',
				'template'    => false,
			)
		);
		$id    = $this->field_id( $key );
		$name  = 'barar_atik_settings[' . $key . ']';
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<textarea
					id="<?php echo esc_attr( $id ); ?>"
					name="<?php echo esc_attr( $name ); ?>"
					rows="<?php echo (int) $args['rows']; ?>"
					class="large-text code<?php echo $args['template'] ? ' barar-tpl' : ''; ?>"
					placeholder="<?php echo esc_attr( $args['placeholder'] ); ?>"
				><?php echo esc_textarea( $this->val( $key ) ); ?></textarea>
				<?php if ( $args['template'] ) : ?>
					<div class="barar-counter" aria-live="polite"></div>
				<?php endif; ?>
				<?php if ( '' !== $args['hint'] ) : ?>
					<p class="description"><?php echo esc_html( $args['hint'] ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Simple info card.
	 *
	 * @param string $title Card title.
	 * @param string $body  Already-escaped HTML body.
	 * @param string $class Extra class.
	 * @return void
	 */
	private function card( $title, $body, $class = '' ) {
		?>
		<div class="barar-card <?php echo esc_attr( $class ); ?>">
			<h3><?php echo esc_html( $title ); ?></h3>
			<div class="barar-card__body"><?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
		</div>
		<?php
	}

	/**
	 * Macro reference panel (click to insert).
	 *
	 * @param array $only Optional subset of macro codes.
	 * @return void
	 */
	private function macros_panel( $only = array() ) {
		$groups = Barar_Atik_Macros::definitions();
		?>
		<div class="barar-card barar-macros">
			<h3><?php esc_html_e( 'Available macros', 'barar-atik-sms-otp' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Click into a message box, then click a macro to insert it.', 'barar-atik-sms-otp' ); ?></p>
			<?php foreach ( $groups as $group => $macros ) : ?>
				<?php
				if ( $only ) {
					$macros = array_intersect_key( $macros, array_flip( $only ) );
					if ( ! $macros ) {
						continue;
					}
				}
				?>
				<div class="barar-macro-group">
					<h4><?php echo esc_html( $group ); ?></h4>
					<?php foreach ( $macros as $code => $desc ) : ?>
						<button type="button" class="barar-macro" data-macro="<?php echo esc_attr( $code ); ?>">
							<code><?php echo esc_html( $code ); ?></code>
							<span><?php echo esc_html( $desc ); ?></span>
						</button>
					<?php endforeach; ?>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * MySQL timestamp to site time (plain text; escape at the call site).
	 *
	 * @param string $mysql Timestamp.
	 * @return string
	 */
	private function fmt_time( $mysql ) {
		$mysql = (string) $mysql;
		if ( '' === $mysql || '0000-00-00 00:00:00' === $mysql ) {
			return '—';
		}
		$ts = strtotime( $mysql );
		if ( ! $ts ) {
			return '—';
		}
		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts );
	}

	/**
	 * Unix timestamp to site time (plain text; escape at the call site).
	 *
	 * @param int $ts Timestamp.
	 * @return string
	 */
	private function fmt_ts( $ts ) {
		$ts = (int) $ts;
		return $ts > 0 ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts ) : '—';
	}

	/**
	 * Status badge markup.
	 *
	 * @param string $status Status key.
	 * @return string
	 */
	private function badge( $status ) {
		$status = (string) $status;
		$labels = Barar_Atik_Message_Log::status_labels();
		$label  = isset( $labels[ $status ] ) ? $labels[ $status ] : ucfirst( $status );
		return '<span class="barar-badge barar-badge--' . esc_attr( $status ) . '">' . esc_html( $label ) . '</span>';
	}

	/* ---------------------------------------------------------------------
	 * Page: Dashboard
	 * ------------------------------------------------------------------ */

	/**
	 * Overview: counters, setup checklist and the latest traffic.
	 *
	 * @return void
	 */
	public function page_dashboard() {
		$this->guard();

		$stats  = Barar_Atik_Stats::all();
		$counts = $this->plugin->log->counts();
		$recent = $this->plugin->log->recent( 5 );
		$diag   = Barar_Atik_TextBee::diagnostics();
		$last   = isset( $diag['last_success'] ) ? $diag['last_success'] : null;

		$api_key    = (string) Barar_Atik_Settings::get( 'api_key', '' );
		$device     = (string) Barar_Atik_Settings::get( 'device_id', '' );
		$secret     = (string) Barar_Atik_Settings::get( 'webhook_secret', '' );
		$webhook_on = '1' === $this->val( 'webhook_enabled' );

		$contexts = array();
		foreach ( array( 'otp_wp_login', 'otp_wp_register', 'otp_wc_login', 'otp_wc_register' ) as $ctx_key ) {
			if ( '1' === $this->val( $ctx_key ) ) {
				$contexts[] = $ctx_key;
			}
		}

		$steps = array(
			array(
				'ok'     => '' !== $api_key,
				'label'  => __( 'TextBee API key', 'barar-atik-sms-otp' ),
				'detail' => '' !== $api_key ? Barar_Atik_Settings::api_key_hint() : __( 'Paste your x-api-key to start sending.', 'barar-atik-sms-otp' ),
				'url'    => $this->page_url( 'barar-atik-connection' ),
			),
			array(
				'ok'     => '' !== $device,
				'label'  => __( 'Sending device', 'barar-atik-sms-otp' ),
				'detail' => '' !== $device ? $device : __( 'Optional — the account default device is used.', 'barar-atik-sms-otp' ),
				'url'    => $this->page_url( 'barar-atik-connection' ),
			),
			array(
				'ok'     => $webhook_on && strlen( $secret ) >= 20,
				'label'  => __( 'Delivery webhook', 'barar-atik-sms-otp' ),
				'detail' => $webhook_on && strlen( $secret ) >= 20
					? __( 'Enabled — statuses update automatically.', 'barar-atik-sms-otp' )
					: __( 'Optional — enables live delivered/failed statuses.', 'barar-atik-sms-otp' ),
				'url'    => $this->page_url( 'barar-atik-connection' ),
			),
			array(
				'ok'     => ! empty( $contexts ),
				'label'  => __( 'OTP forms enabled', 'barar-atik-sms-otp' ),
				'detail' => ! empty( $contexts )
					? sprintf( /* translators: %d: number of enabled forms. */ __( '%d of 4 forms use phone OTP.', 'barar-atik-sms-otp' ), count( $contexts ) )
					: __( 'No form shows the phone OTP option yet.', 'barar-atik-sms-otp' ),
				'url'    => $this->page_url( 'barar-atik-otp' ),
			),
			array(
				'ok'     => (bool) $last,
				'label'  => __( 'Connection verified', 'barar-atik-sms-otp' ),
				'detail' => $last
					? sprintf( '%1$s · %2$s', $last['action'], $this->fmt_ts( $last['at'] ) )
					: __( 'Run “Test connection” once to verify the key.', 'barar-atik-sms-otp' ),
				'url'    => $this->page_url( 'barar-atik-connection' ),
			),
		);

		/* ---- Dashboard tabs ------------------------------------------- */

		$tabs      = array(
			'overview'       => __( 'Overview', 'barar-atik-sms-otp' ),
			'connection'     => __( 'Connection', 'barar-atik-sms-otp' ),
			'automations'    => __( 'Automation', 'barar-atik-sms-otp' ),
			'authentication' => __( 'Authentication', 'barar-atik-sms-otp' ),
			'messages'       => __( 'Messages', 'barar-atik-sms-otp' ),
			'diagnostics'    => __( 'Diagnostics', 'barar-atik-sms-otp' ),
		);
		$active_tab = 'overview';

		$events        = (array) Barar_Atik_Settings::get( 'events', array() );
		$active_events = array();
		foreach ( $events as $event_key => $event ) {
			if ( is_array( $event ) && ! empty( $event['enabled'] ) ) {
				$active_events[ (string) $event_key ] = ! empty( $event['label'] ) ? (string) $event['label'] : (string) $event_key;
			}
		}

		$otp_forms = array(
			'otp_wp_login'    => __( 'WordPress — login form', 'barar-atik-sms-otp' ),
			'otp_wp_register' => __( 'WordPress — registration form', 'barar-atik-sms-otp' ),
			'otp_wc_login'    => __( 'WooCommerce — My Account login', 'barar-atik-sms-otp' ),
			'otp_wc_register' => __( 'WooCommerce — My Account registration', 'barar-atik-sms-otp' ),
			'otp_wp_reset'    => __( 'WordPress — password reset', 'barar-atik-sms-otp' ),
			'otp_wc_reset'    => __( 'WooCommerce — password reset', 'barar-atik-sms-otp' ),
		);
		$active_forms = array();
		foreach ( $otp_forms as $ctx => $ctx_label ) {
			if ( '1' === $this->val( $ctx ) ) {
				$active_forms[ $ctx ] = $ctx_label;
			}
		}

		$checkout_login  = '1' === $this->val( 'checkout_otp_login' );
		$checkout_signup = '1' === $this->val( 'checkout_otp_register' );
		$no_email_order  = '1' === $this->val( 'checkout_order_no_email' );
		$webhook_url     = Barar_Atik_Webhook::url();
		$retry_next      = wp_next_scheduled( 'barar_atik_retry' );
		?>
		<div class="wrap barar-admin">
			<h1><?php esc_html_e( 'TextBee SMS & OTP', 'barar-atik-sms-otp' ); ?></h1>
			<?php $this->nav( 'barar-atik-sms-otp' ); ?>

			<div class="barar-tabset">
				<div class="barar-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Dashboard sections', 'barar-atik-sms-otp' ); ?>">
				<?php foreach ( $tabs as $slug => $tab_label ) : ?>
					<button
						type="button"
						class="barar-tab<?php echo $active_tab === $slug ? ' is-active' : ''; ?>"
						role="tab"
						id="barar-tab-<?php echo esc_attr( $slug ); ?>"
						aria-controls="barar-panel-<?php echo esc_attr( $slug ); ?>"
						aria-selected="<?php echo $active_tab === $slug ? 'true' : 'false'; ?>"
						data-tab="<?php echo esc_attr( $slug ); ?>"
					><?php echo esc_html( $tab_label ); ?></button>
				<?php endforeach; ?>
			</div>

			<section class="barar-tabpanel is-active" id="barar-panel-overview" role="tabpanel" aria-labelledby="barar-tab-overview" data-tab-panel="overview">

			<div class="barar-cards barar-cards--stats">
				<?php
				$this->card( __( 'SMS queued', 'barar-atik-sms-otp' ), '<p class="barar-stat">' . esc_html( (string) (int) $stats['sms_queued'] ) . '</p><p class="barar-stat-sub">' . esc_html( sprintf( /* translators: %d: failed count. */ __( '%d failed', 'barar-atik-sms-otp' ), (int) $stats['sms_failed'] ) ) . '</p>', 'barar-card--stat' );
				$this->card( __( 'Delivered', 'barar-atik-sms-otp' ), '<p class="barar-stat">' . esc_html( (string) (int) $counts['delivered'] ) . '</p><p class="barar-stat-sub">' . esc_html( sprintf( /* translators: %d: sent count. */ __( '%d sent', 'barar-atik-sms-otp' ), (int) $counts['sent'] ) ) . '</p>', 'barar-card--stat' );
				$this->card( __( 'Codes sent', 'barar-atik-sms-otp' ), '<p class="barar-stat">' . esc_html( (string) (int) $stats['otp_sent'] ) . '</p><p class="barar-stat-sub">' . esc_html( sprintf( /* translators: %d: send failures. */ __( '%d send failures', 'barar-atik-sms-otp' ), (int) $stats['otp_send_fail'] ) ) . '</p>', 'barar-card--stat' );
				$this->card( __( 'OTP logins', 'barar-atik-sms-otp' ), '<p class="barar-stat">' . esc_html( (string) (int) $stats['otp_login'] ) . '</p>', 'barar-card--stat' );
				$this->card( __( 'OTP registrations', 'barar-atik-sms-otp' ), '<p class="barar-stat">' . esc_html( (string) (int) $stats['otp_register'] ) . '</p>', 'barar-card--stat' );
				$this->card( __( 'Verify failures', 'barar-atik-sms-otp' ), '<p class="barar-stat">' . esc_html( (string) (int) $stats['otp_verify_fail'] ) . '</p>', 'barar-card--stat' );
				?>
			</div>

			<div class="barar-cards barar-cards--split">
				<div class="barar-card barar-card--half">
					<h3><?php esc_html_e( 'Setup checklist', 'barar-atik-sms-otp' ); ?></h3>
					<div class="barar-card__body">
						<ul class="barar-checklist">
							<?php foreach ( $steps as $step ) : ?>
								<li>
									<span class="barar-dot <?php echo $step['ok'] ? 'barar-dot--ok' : 'barar-dot--warn'; ?>"></span>
									<span class="barar-checklist__text">
										<strong><?php echo esc_html( $step['label'] ); ?></strong>
										<span><?php echo esc_html( $step['detail'] ); ?></span>
									</span>
									<a class="button button-small" href="<?php echo esc_url( $step['url'] ); ?>"><?php esc_html_e( 'Open', 'barar-atik-sms-otp' ); ?></a>
								</li>
							<?php endforeach; ?>
						</ul>
					</div>
				</div>

				<div class="barar-card barar-card--half">
					<h3><?php esc_html_e( 'Latest messages', 'barar-atik-sms-otp' ); ?></h3>
					<div class="barar-card__body">
						<?php if ( empty( $recent ) ) : ?>
							<p class="description"><?php esc_html_e( 'No SMS traffic yet. Send a test message to see it appear here.', 'barar-atik-sms-otp' ); ?></p>
						<?php else : ?>
							<ul class="barar-feed">
								<?php foreach ( $recent as $row ) : ?>
									<li>
										<?php echo $this->badge( $row['status'] ); // phpcs:ignore ?>
										<code><?php echo '' !== (string) $row['recipient'] ? esc_html( Barar_Atik_Phone::mask( $row['recipient'] ) ) : '—'; ?></code>
										<span class="barar-feed__text"><?php echo esc_html( mb_substr( (string) $row['message'], 0, 60 ) . ( mb_strlen( (string) $row['message'] ) > 60 ? '…' : '' ) ); ?></span>
										<span class="barar-feed__time"><?php echo esc_html( $this->fmt_time( $row['requested_at'] ? $row['requested_at'] : $row['created_at'] ) ); ?></span>
									</li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
						<p>
							<a class="button button-secondary" href="<?php echo esc_url( $this->page_url( 'barar-atik-messages' ) ); ?>"><?php esc_html_e( 'Open Message Center', 'barar-atik-sms-otp' ); ?></a>
							<a class="button button-secondary" href="<?php echo esc_url( $this->page_url( 'barar-atik-connection' ) ); ?>"><?php esc_html_e( 'Send test SMS', 'barar-atik-sms-otp' ); ?></a>
							<a class="button" href="<?php echo esc_url( $this->page_url( 'barar-atik-otp' ) ); ?>"><?php esc_html_e( 'OTP settings', 'barar-atik-sms-otp' ); ?></a>
						</p>
					</div>
				</div>
			</div>
			</section>

			<?php /* ---------------- Connection ---------------- */ ?>
			<section class="barar-tabpanel" id="barar-panel-connection" role="tabpanel" aria-labelledby="barar-tab-connection" data-tab-panel="connection" hidden>
				<div class="barar-cards">
					<?php
					$this->card(
						__( 'API key', 'barar-atik-sms-otp' ),
						'' !== $api_key
							? '<p><span class="barar-dot barar-dot--ok"></span> ' . esc_html( sprintf( /* translators: %s: masked API key hint, e.g. abc••••••xyz. */ __( 'Configured (%s)', 'barar-atik-sms-otp' ), Barar_Atik_Settings::api_key_hint() ) ) . '</p>'
							: '<p><span class="barar-dot barar-dot--warn"></span> ' . esc_html__( 'Not configured yet.', 'barar-atik-sms-otp' ) . '</p>',
						'barar-card--half'
					);
					$this->card(
						__( 'Device', 'barar-atik-sms-otp' ),
						'' !== $device
							? '<p><span class="barar-dot barar-dot--ok"></span> <code>' . esc_html( $device ) . '</code></p>'
							: '<p><span class="barar-dot barar-dot--warn"></span> ' . esc_html__( 'No Device ID set.', 'barar-atik-sms-otp' ) . '</p>',
						'barar-card--half'
					);
					$this->card(
						__( 'Delivery webhook', 'barar-atik-sms-otp' ),
						$webhook_on && strlen( $secret ) >= 20
							? '<p><span class="barar-dot barar-dot--ok"></span> ' . esc_html__( 'Enabled — statuses update automatically.', 'barar-atik-sms-otp' ) . '</p><p class="barar-copy-row"><input type="text" class="large-text barar-copy-input" readonly value="' . esc_attr( $webhook_url ) . '" /><button type="button" class="button barar-copy">' . esc_html__( 'Copy', 'barar-atik-sms-otp' ) . '</button></p>'
							: '<p><span class="barar-dot barar-dot--warn"></span> ' . esc_html__( 'Optional — enables live delivered/failed statuses.', 'barar-atik-sms-otp' ) . '</p>',
						'barar-card--half'
					);
					$this->card(
						__( 'Last API result', 'barar-atik-sms-otp' ),
						$last
							? '<p><span class="barar-dot barar-dot--ok"></span> <code>' . esc_html( $last['action'] ) . '</code> · ' . esc_html( $this->fmt_ts( $last['at'] ) ) . '</p>'
							: '<p><span class="barar-dot barar-dot--warn"></span> ' . esc_html__( 'Run “Test connection” once to verify the key.', 'barar-atik-sms-otp' ) . '</p>',
						'barar-card--half'
					);
					?>
				</div>
				<p>
					<a class="button" href="<?php echo esc_url( $this->page_url( 'barar-atik-connection' ) ); ?>"><?php esc_html_e( 'Open Connection settings', 'barar-atik-sms-otp' ); ?></a>
				</p>
			</section>

			<?php /* ---------------- Automation ---------------- */ ?>
			<section class="barar-tabpanel" id="barar-panel-automations" role="tabpanel" aria-labelledby="barar-tab-automations" data-tab-panel="automations" hidden>
				<?php
				$event_html = '';
				foreach ( $active_events as $event_label ) {
					$event_html .= '<li>' . esc_html( $event_label ) . '</li>';
				}
				?>
				<div class="barar-cards">
					<?php
					$this->card(
						__( 'Order SMS automations', 'barar-atik-sms-otp' ),
						! empty( $active_events )
							? '<p><span class="barar-dot barar-dot--ok"></span> ' . esc_html( sprintf( /* translators: 1: enabled count, 2: total count. */ __( '%1$d of %2$d order events are enabled.', 'barar-atik-sms-otp' ), count( $active_events ), count( $events ) ) ) . '</p>'
							: '<p><span class="barar-dot barar-dot--warn"></span> ' . esc_html__( 'No order event sends an SMS yet.', 'barar-atik-sms-otp' ) . '</p>',
						'barar-card--half'
					);
					$this->card(
						__( 'Enabled events', 'barar-atik-sms-otp' ),
						'' !== $event_html
							? '<ul class="barar-feed">' . $event_html . '</ul>'
							: '<p class="description">' . esc_html__( 'Pick the events you need on the Automations page.', 'barar-atik-sms-otp' ) . '</p>',
						'barar-card--half'
					);
					?>
				</div>
				<p>
					<a class="button" href="<?php echo esc_url( $this->page_url( 'barar-atik-automations' ) ); ?>"><?php esc_html_e( 'Open Automations', 'barar-atik-sms-otp' ); ?></a>
					<a class="button button-secondary" href="<?php echo esc_url( $this->page_url( 'barar-atik-messages' ) ); ?>"><?php esc_html_e( 'Message Center', 'barar-atik-sms-otp' ); ?></a>
				</p>
			</section>

			<?php /* ---------------- Authentication ---------------- */ ?>
			<section class="barar-tabpanel" id="barar-panel-authentication" role="tabpanel" aria-labelledby="barar-tab-authentication" data-tab-panel="authentication" hidden>
				<?php
				$form_html = '';
				foreach ( $active_forms as $form_label ) {
					$form_html .= '<li>' . esc_html( $form_label ) . '</li>';
				}
				?>
				<div class="barar-cards">
					<?php
					$this->card(
						__( 'Forms using phone OTP', 'barar-atik-sms-otp' ),
						'' !== $form_html
							? '<ul class="barar-feed">' . $form_html . '</ul>'
							: '<p class="description">' . esc_html__( 'No form shows the phone OTP option yet.', 'barar-atik-sms-otp' ) . '</p>',
						'barar-card--half'
					);
					$this->card(
						__( 'Checkout & password reset', 'barar-atik-sms-otp' ),
						'<ul class="barar-feed">'
							. '<li>' . esc_html( $checkout_login ? __( 'Phone login at checkout: on', 'barar-atik-sms-otp' ) : __( 'Phone login at checkout: off', 'barar-atik-sms-otp' ) ) . '</li>'
							. '<li>' . esc_html( $checkout_signup ? __( 'Phone registration at checkout: on', 'barar-atik-sms-otp' ) : __( 'Phone registration at checkout: off', 'barar-atik-sms-otp' ) ) . '</li>'
							. '<li>' . esc_html( $no_email_order ? __( 'Orders can be placed without an email', 'barar-atik-sms-otp' ) : __( 'Email required at checkout', 'barar-atik-sms-otp' ) ) . '</li>'
							. '</ul>',
						'barar-card--half'
					);
					?>
				</div>

				<div class="barar-cards barar-cards--stats">
					<?php
					$this->card( __( 'Codes sent', 'barar-atik-sms-otp' ), '<p class="barar-stat">' . esc_html( (string) (int) $stats['otp_sent'] ) . '</p>', 'barar-card--stat' );
					$this->card( __( 'OTP logins', 'barar-atik-sms-otp' ), '<p class="barar-stat">' . esc_html( (string) (int) $stats['otp_login'] ) . '</p>', 'barar-card--stat' );
					$this->card( __( 'OTP registrations', 'barar-atik-sms-otp' ), '<p class="barar-stat">' . esc_html( (string) (int) $stats['otp_register'] ) . '</p>', 'barar-card--stat' );
					$this->card( __( 'Password resets', 'barar-atik-sms-otp' ), '<p class="barar-stat">' . esc_html( (string) (int) ( isset( $stats['otp_reset'] ) ? $stats['otp_reset'] : 0 ) ) . '</p>', 'barar-card--stat' );
					$this->card( __( 'Verify failures', 'barar-atik-sms-otp' ), '<p class="barar-stat">' . esc_html( (string) (int) $stats['otp_verify_fail'] ) . '</p>', 'barar-card--stat' );
					?>
				</div>

				<p>
					<a class="button" href="<?php echo esc_url( $this->page_url( 'barar-atik-otp' ) ); ?>"><?php esc_html_e( 'Open Authentication', 'barar-atik-sms-otp' ); ?></a>
					<a class="button button-secondary" href="<?php echo esc_url( $this->page_url( 'barar-atik-forms' ) ); ?>"><?php esc_html_e( 'Forms & shortcodes', 'barar-atik-sms-otp' ); ?></a>
					<a class="button button-secondary" href="<?php echo esc_url( $this->page_url( 'barar-atik-design' ) ); ?>"><?php esc_html_e( 'Design', 'barar-atik-sms-otp' ); ?></a>
				</p>
			</section>

			<?php /* ---------------- Messages ---------------- */ ?>
			<section class="barar-tabpanel" id="barar-panel-messages" role="tabpanel" aria-labelledby="barar-tab-messages" data-tab-panel="messages" hidden>
				<div class="barar-cards barar-cards--stats">
					<?php
					$this->card( __( 'SMS queued', 'barar-atik-sms-otp' ), '<p class="barar-stat">' . esc_html( (string) (int) $stats['sms_queued'] ) . '</p><p class="barar-stat-sub">' . esc_html( sprintf( /* translators: %d: failed count. */ __( '%d failed', 'barar-atik-sms-otp' ), (int) $stats['sms_failed'] ) ) . '</p>', 'barar-card--stat' );
					$this->card( __( 'Delivered', 'barar-atik-sms-otp' ), '<p class="barar-stat">' . esc_html( (string) (int) $counts['delivered'] ) . '</p><p class="barar-stat-sub">' . esc_html( sprintf( /* translators: %d: sent count. */ __( '%d sent', 'barar-atik-sms-otp' ), (int) $counts['sent'] ) ) . '</p>', 'barar-card--stat' );
					$this->card( __( 'In flight', 'barar-atik-sms-otp' ), '<p class="barar-stat">' . esc_html( (string) (int) $counts['pending'] ) . '</p>', 'barar-card--stat' );
					$this->card( __( 'Failed', 'barar-atik-sms-otp' ), '<p class="barar-stat">' . esc_html( (string) (int) $counts['failed'] ) . '</p>', 'barar-card--stat' );
					?>
				</div>

				<div class="barar-card">
					<h3><?php esc_html_e( 'Latest messages', 'barar-atik-sms-otp' ); ?></h3>
					<div class="barar-card__body">
						<?php if ( empty( $recent ) ) : ?>
							<p class="description"><?php esc_html_e( 'No SMS traffic yet. Send a test message to see it appear here.', 'barar-atik-sms-otp' ); ?></p>
						<?php else : ?>
							<ul class="barar-feed">
								<?php foreach ( $recent as $row ) : ?>
									<li>
										<?php echo $this->badge( $row['status'] ); // phpcs:ignore ?>
										<code><?php echo '' !== (string) $row['recipient'] ? esc_html( Barar_Atik_Phone::mask( $row['recipient'] ) ) : '—'; ?></code>
										<span class="barar-feed__text"><?php echo esc_html( mb_substr( (string) $row['message'], 0, 60 ) . ( mb_strlen( (string) $row['message'] ) > 60 ? '…' : '' ) ); ?></span>
										<span class="barar-feed__time"><?php echo esc_html( $this->fmt_time( $row['requested_at'] ? $row['requested_at'] : $row['created_at'] ) ); ?></span>
									</li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
					</div>
				</div>

				<p>
					<a class="button" href="<?php echo esc_url( $this->page_url( 'barar-atik-messages' ) ); ?>"><?php esc_html_e( 'Open Message Center', 'barar-atik-sms-otp' ); ?></a>
					<a class="button button-secondary" href="<?php echo esc_url( $this->page_url( 'barar-atik-connection' ) ); ?>"><?php esc_html_e( 'Send test SMS', 'barar-atik-sms-otp' ); ?></a>
				</p>
			</section>

			<?php /* ---------------- Diagnostics ---------------- */ ?>
			<section class="barar-tabpanel" id="barar-panel-diagnostics" role="tabpanel" aria-labelledby="barar-tab-diagnostics" data-tab-panel="diagnostics" hidden>
				<div class="barar-cards">
					<?php
					$this->card(
						__( 'Last API result', 'barar-atik-sms-otp' ),
						$last
							? '<p><span class="barar-dot barar-dot--ok"></span> <code>' . esc_html( $last['action'] ) . '</code> · ' . esc_html( $this->fmt_ts( $last['at'] ) ) . '</p>'
							: '<p><span class="barar-dot barar-dot--warn"></span> ' . esc_html__( 'No API call recorded yet.', 'barar-atik-sms-otp' ) . '</p>',
						'barar-card--half'
					);
					$this->card(
						__( 'Retry queue', 'barar-atik-sms-otp' ),
						$retry_next
							? '<p><span class="barar-dot barar-dot--ok"></span> ' . esc_html( sprintf( /* translators: %s: human time of the next retry. */ __( 'Next retry: %s', 'barar-atik-sms-otp' ), human_time_diff( (int) $retry_next, time() ) ) ) . '</p>'
							: '<p><span class="barar-dot barar-dot--ok"></span> ' . esc_html__( 'Nothing waiting for a retry.', 'barar-atik-sms-otp' ) . '</p>',
						'barar-card--half'
					);
					$this->card(
						__( 'Failed messages', 'barar-atik-sms-otp' ),
						(int) $counts['failed'] > 0
							? '<p><span class="barar-dot barar-dot--warn"></span> ' . esc_html( sprintf( /* translators: %d: failed count. */ __( '%d messages failed.', 'barar-atik-sms-otp' ), (int) $counts['failed'] ) ) . '</p>'
							: '<p><span class="barar-dot barar-dot--ok"></span> ' . esc_html__( 'No failed messages.', 'barar-atik-sms-otp' ) . '</p>',
						'barar-card--half'
					);
					$this->card(
						__( 'Plugin', 'barar-atik-sms-otp' ),
						'<p><span class="barar-dot barar-dot--ok"></span> <code>' . esc_html( BARAR_ATIK_VERSION ) . '</code> · PHP ' . esc_html( PHP_VERSION ) . '</p>',
						'barar-card--half'
					);
					?>
				</div>
				<p>
					<a class="button" href="<?php echo esc_url( $this->page_url( 'barar-atik-diagnostics' ) ); ?>"><?php esc_html_e( 'Open Diagnostics', 'barar-atik-sms-otp' ); ?></a>
				</p>
			</section>

			</div>
		</div>
		<?php
	}

	/**
	 * Admin URL for a plugin page slug.
	 *
	 * @param string $slug Page slug.
	 * @return string
	 */
	private function page_url( $slug ) {
		return admin_url( 'admin.php?page=' . $slug );
	}

	/* ---------------------------------------------------------------------
	 * Page: Connection
	 * ------------------------------------------------------------------ */

	/**
	 * Connection, webhook and test tools.
	 *
	 * @return void
	 */
	public function page_connection() {
		$this->guard();

		$diag         = Barar_Atik_TextBee::diagnostics();
		$api_hint     = Barar_Atik_Settings::api_key_hint();
		$device       = $this->val( 'device_id' );
		$webhook_on   = '1' === $this->val( 'webhook_enabled' );
		$webhook_url  = Barar_Atik_Webhook::url();
		$secret       = (string) Barar_Atik_Settings::get( 'webhook_secret', '' );
		$secret_hint  = '' !== $secret ? str_repeat( '•', 8 ) . ( strlen( $secret ) > 4 ? substr( $secret, -4 ) : '' ) : '';

		$last_ok = isset( $diag['last_success'] ) ? $diag['last_success'] : null;
		$last_er = isset( $diag['last_failure'] ) ? $diag['last_failure'] : null;
		?>
		<div class="wrap barar-admin">
			<h1><?php esc_html_e( 'TextBee SMS & OTP — Connection', 'barar-atik-sms-otp' ); ?></h1>
			<?php $this->nav( 'barar-atik-connection' ); ?>

			<div class="barar-cards">
				<?php
				$this->card(
					__( 'API key', 'barar-atik-sms-otp' ),
					$api_hint
						? '<p><span class="barar-dot barar-dot--ok"></span> ' . esc_html( sprintf( /* translators: %s: masked API key hint, e.g. abc••••••xyz. */ __( 'Configured (%s)', 'barar-atik-sms-otp' ), $api_hint ) ) . '</p>'
						: '<p><span class="barar-dot barar-dot--warn"></span> ' . esc_html__( 'Not configured yet.', 'barar-atik-sms-otp' ) . '</p>',
					'barar-card--half'
				);
				$this->card(
					__( 'Device', 'barar-atik-sms-otp' ),
					'' !== $device
						? '<p><span class="barar-dot barar-dot--ok"></span> <code>' . esc_html( $device ) . '</code></p>'
						: '<p><span class="barar-dot barar-dot--warn"></span> ' . esc_html__( 'No Device ID set.', 'barar-atik-sms-otp' ) . '</p>',
					'barar-card--half'
				);
				$this->card(
					__( 'Webhook', 'barar-atik-sms-otp' ),
					( $webhook_on && '' !== $secret )
						? '<p><span class="barar-dot barar-dot--ok"></span> ' . esc_html__( 'Enabled (delivery updates pushed by TextBee).', 'barar-atik-sms-otp' ) . '</p>'
						: '<p><span class="barar-dot barar-dot--off"></span> ' . esc_html__( 'Disabled — statuses refresh manually.', 'barar-atik-sms-otp' ) . '</p>',
					'barar-card--half'
				);
				$this->card(
					__( 'Last API result', 'barar-atik-sms-otp' ),
					'<p>' .
						( $last_ok ? '<span class="barar-dot barar-dot--ok"></span> ' . esc_html( sprintf( '%1$s · HTTP %2$s · %3$s', $last_ok['action'], $last_ok['status'], $this->fmt_ts( $last_ok['at'] ) ) ) . '<br />' : '' ) .
						( $last_er ? '<span class="barar-dot barar-dot--err"></span> ' . esc_html( sprintf( '%1$s · HTTP %2$s · %3$s — %4$s', $last_er['action'], $last_er['status'], $this->fmt_ts( $last_er['at'] ), $last_er['message'] ) ) : '' ) .
						( ! $last_ok && ! $last_er ? esc_html__( 'No API calls made yet.', 'barar-atik-sms-otp' ) : '' ) .
					'</p>',
					'barar-card--full'
				);
				?>
			</div>

			<?php $this->form_open(); ?>
				<h2><?php esc_html_e( 'TextBee API', 'barar-atik-sms-otp' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Find both values in the TextBee dashboard (API keys and Connected devices). The key is stored encrypted-safe in the database and is never printed in full anywhere.', 'barar-atik-sms-otp' ); ?></p>
				<table class="form-table" role="presentation">
					<?php
					$this->field_secret(
						'api_key',
						__( 'API key', 'barar-atik-sms-otp' ),
						array(
							'hint'        => '' !== $api_hint ? sprintf( /* translators: %s: masked API key hint, e.g. abc••••••xyz. */ __( 'Saved key: %s — leave blank to keep it.', 'barar-atik-sms-otp' ), $api_hint ) : __( 'Paste the x-api-key value from TextBee.', 'barar-atik-sms-otp' ),
							'placeholder' => '' !== $api_hint ? '••••••••' : 'textbee_api_...',
						)
					);
					$this->field_text(
						'device_id',
						__( 'Device ID', 'barar-atik-sms-otp' ),
						array(
							'hint'        => __( 'The connected phone device. Leave blank to use the account default device.', 'barar-atik-sms-otp' ),
							'placeholder' => '65f1a2b3c4d5e6f7a8b9c0d1',
						)
					);
					$this->field_text(
						'country_code',
						__( 'Default country code', 'barar-atik-sms-otp' ),
						array(
							'hint'        => __( 'Digits only (no +). Used when a number is written like 01712345678. Example: 880.', 'barar-atik-sms-otp' ),
							'placeholder' => '880',
						)
					);
					$this->field_text(
						'admin_phone',
						__( 'Administrator phone', 'barar-atik-sms-otp' ),
						array(
							'hint'        => __( 'Manual override for the Admin recipient. Falls back to the first administrator account with a phone number.', 'barar-atik-sms-otp' ),
							'placeholder' => '+8801XXXXXXXXX',
						)
					);
					?>
				</table>

				<h2><?php esc_html_e( 'Delivery webhook (optional)', 'barar-atik-sms-otp' ); ?></h2>
				<p class="description"><?php esc_html_e( 'TextBee pushes sent/delivered/failed/received events to this site. Requests are verified with an HMAC-SHA256 signature.', 'barar-atik-sms-otp' ); ?></p>
				<table class="form-table" role="presentation">
					<?php
					$this->field_checkbox( 'webhook_enabled', __( 'Enable webhook', 'barar-atik-sms-otp' ), __( 'Accept signed status updates from TextBee', 'barar-atik-sms-otp' ) );
					$this->field_secret(
						'webhook_secret',
						__( 'Signing secret', 'barar-atik-sms-otp' ),
						array(
							'hint'        => '' !== $secret_hint ? sprintf( /* translators: %s: masked signing-secret hint, e.g. sec••••••xyz. */ __( 'Saved secret: %s — leave blank to keep it. Must be at least 20 characters.', 'barar-atik-sms-otp' ), $secret_hint ) : __( 'At least 20 characters. Use the button below to generate and register everything automatically.', 'barar-atik-sms-otp' ),
							'placeholder' => '••••••••',
						)
					);
					?>
				</table>

				<div class="barar-inline-card">
					<p><strong><?php esc_html_e( 'Endpoint URL:', 'barar-atik-sms-otp' ); ?></strong></p>
					<p class="barar-copy-row">
						<input type="text" class="large-text barar-copy-input" readonly value="<?php echo esc_attr( $webhook_url ); ?>" />
						<button type="button" class="button barar-copy"><?php esc_html_e( 'Copy', 'barar-atik-sms-otp' ); ?></button>
					</p>
					<p>
						<button type="button" class="button button-secondary barar-create-webhook"><?php esc_html_e( 'Generate secret &amp; create subscription', 'barar-atik-sms-otp' ); ?></button>
						<span class="barar-tool-result barar-webhook-result" role="status" aria-live="polite"></span>
					</p>
					<p class="description"><?php esc_html_e( 'Creates the subscription through the TextBee API, saves the secret and enables the webhook here. Requires a saved API key.', 'barar-atik-sms-otp' ); ?></p>
				</div>

				<?php $this->form_close(); ?>

			<div class="barar-cards">
				<div class="barar-card barar-card--half">
					<h3><?php esc_html_e( 'Test connection', 'barar-atik-sms-otp' ); ?></h3>
					<div class="barar-card__body">
						<p class="description"><?php esc_html_e( 'Checks the API key against the TextBee stats endpoint.', 'barar-atik-sms-otp' ); ?></p>
						<button type="button" class="button button-secondary barar-test-connection"><?php esc_html_e( 'Test connection', 'barar-atik-sms-otp' ); ?></button>
						<div class="barar-tool-result barar-test-connection-result" role="status" aria-live="polite"></div>
					</div>
				</div>

				<div class="barar-card barar-card--half">
					<h3><?php esc_html_e( 'Send a test SMS', 'barar-atik-sms-otp' ); ?></h3>
					<div class="barar-card__body">
						<table class="form-table barar-tool-table" role="presentation">
							<tr>
								<th scope="row"><label for="barar-test-phone-conn"><?php esc_html_e( 'Phone number', 'barar-atik-sms-otp' ); ?></label></th>
								<td><input type="text" id="barar-test-phone-conn" class="regular-text barar-test-phone" placeholder="+8801XXXXXXXXX" /></td>
							</tr>
							<tr>
								<th scope="row"><label for="barar-test-msg-conn"><?php esc_html_e( 'Message', 'barar-atik-sms-otp' ); ?></label></th>
								<td>
									<textarea id="barar-test-msg-conn" rows="3" class="large-text barar-test-message barar-tpl"><?php echo esc_textarea( sprintf( /* translators: %s: site title. */ __( 'Hello from %s! This is a test SMS from WordPress.', 'barar-atik-sms-otp' ), get_bloginfo( 'name' ) ) ); ?></textarea>
									<div class="barar-counter" aria-live="polite"></div>
								</td>
							</tr>
						</table>
						<button type="button" class="button button-primary barar-test-send"><?php esc_html_e( 'Send test SMS', 'barar-atik-sms-otp' ); ?></button>
						<div class="barar-tool-result barar-test-result" role="status" aria-live="polite"></div>
						<p class="description"><?php esc_html_e( 'A queued response means TextBee accepted the message for delivery — it is not yet delivered to the handset.', 'barar-atik-sms-otp' ); ?></p>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Page: Automations
	 * ------------------------------------------------------------------ */

	/**
	 * WooCommerce event automation settings, macros and tools.
	 *
	 * @return void
	 */
	public function page_automations() {
		$this->guard();

		$events          = Barar_Atik_Settings::get( 'events', array() );
		$recipient_types = array(
			'customer' => __( 'Customer (billing phone)', 'barar-atik-sms-otp' ),
			'vendor'   => __( 'Vendor (product author phone)', 'barar-atik-sms-otp' ),
			'admin'    => __( 'Administrator', 'barar-atik-sms-otp' ),
		);

		$order_options = array();
		if ( function_exists( 'wc_get_orders' ) ) {
			$ids = wc_get_orders(
				array(
					'limit'  => 20,
					'orderby' => 'date',
					'order'  => 'DESC',
					'return' => 'ids',
				)
			);
			foreach ( (array) $ids as $order_id ) {
				$order = wc_get_order( $order_id );
				if ( ! $order ) {
					continue;
				}
				$name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
				$order_options[ (int) $order_id ] = sprintf(
					'#%1$s — %2$s (%3$s)',
					$order->get_order_number(),
					$name ? $name : __( 'Guest', 'barar-atik-sms-otp' ),
					$order->get_status()
				);
			}
		}
		?>
		<div class="wrap barar-admin barar-automations">
			<h1><?php esc_html_e( 'WooCommerce Automations', 'barar-atik-sms-otp' ); ?></h1>
			<?php $this->nav( 'barar-atik-automations' ); ?>

			<?php if ( ! class_exists( 'WooCommerce' ) ) : ?>
				<div class="notice notice-warning"><p><?php esc_html_e( 'WooCommerce is not active, so order SMS notifications are paused. Everything you configure here is kept.', 'barar-atik-sms-otp' ); ?></p></div>
			<?php endif; ?>

			<div class="barar-layout">
				<div class="barar-main">
					<?php $this->form_open(); ?>
						<h2><?php esc_html_e( 'Delivery &amp; retries', 'barar-atik-sms-otp' ); ?></h2>
						<table class="form-table" role="presentation">
							<?php
							$this->field_checkbox( 'retry_enabled', __( 'Automatic retries', 'barar-atik-sms-otp' ), __( 'Retry failed order SMS automatically (never on OTP or test messages)', 'barar-atik-sms-otp' ) );
							$this->field_text(
								'retry_count',
								__( 'Maximum retries', 'barar-atik-sms-otp' ),
								array(
									'type'  => 'number',
									'min'   => 0,
									'max'   => 5,
									'hint'  => __( 'Bounded retries with a delay. Action Scheduler is used when available, otherwise WP-Cron.', 'barar-atik-sms-otp' ),
									'class' => 'small-text',
								)
							);
							?>
						</table>

						<h2><?php esc_html_e( 'Order events', 'barar-atik-sms-otp' ); ?></h2>
						<p class="description"><?php esc_html_e( 'Each event sends one message per selected recipient. Vendor and administrator messages fall back to the main message when left empty.', 'barar-atik-sms-otp' ); ?></p>

						<?php foreach ( $events as $event_key => $event ) : ?>
							<?php
							$label       = ! empty( $event['label'] ) ? (string) $event['label'] : $event_key;
							$recipients  = isset( $event['recipients'] ) && is_array( $event['recipients'] ) ? $event['recipients'] : array();
							$name_prefix = 'barar_atik_settings[events][' . $event_key . ']';
							?>
							<div class="barar-card barar-event" id="barar-event-<?php echo esc_attr( $event_key ); ?>">
								<h3><?php echo esc_html( $label ); ?></h3>

								<label class="barar-inline">
									<input type="hidden" name="<?php echo esc_attr( $name_prefix ); ?>[enabled]" value="0" />
									<input type="checkbox" name="<?php echo esc_attr( $name_prefix ); ?>[enabled]" value="1" <?php checked( '1', (string) $event['enabled'] ); ?> />
									<?php esc_html_e( 'Send SMS for this event', 'barar-atik-sms-otp' ); ?>
								</label>

								<fieldset class="barar-recipients">
									<legend><?php esc_html_e( 'Recipients', 'barar-atik-sms-otp' ); ?></legend>
									<input type="hidden" name="<?php echo esc_attr( $name_prefix ); ?>[recipients][]" value="" />
									<?php foreach ( $recipient_types as $rtype => $rlabel ) : ?>
										<label>
											<input type="checkbox" name="<?php echo esc_attr( $name_prefix ); ?>[recipients][]" value="<?php echo esc_attr( $rtype ); ?>" <?php checked( in_array( $rtype, $recipients, true ) ); ?> />
											<?php echo esc_html( $rlabel ); ?>
										</label>
									<?php endforeach; ?>
								</fieldset>

								<p>
									<label for="barar-tpl-<?php echo esc_attr( $event_key ); ?>"><strong><?php esc_html_e( 'Message (customer / main)', 'barar-atik-sms-otp' ); ?></strong></label>
									<textarea id="barar-tpl-<?php echo esc_attr( $event_key ); ?>" rows="3" class="large-text code barar-tpl" name="<?php echo esc_attr( $name_prefix ); ?>[tpl]"><?php echo esc_textarea( $event['tpl'] ); ?></textarea>
									<span class="barar-counter" aria-live="polite"></span>
								</p>

								<details class="barar-details">
									<summary><?php esc_html_e( 'Vendor message (optional)', 'barar-atik-sms-otp' ); ?></summary>
									<textarea rows="3" class="large-text code barar-tpl" name="<?php echo esc_attr( $name_prefix ); ?>[tpl_vendor]" placeholder="<?php esc_attr_e( 'Leave empty to use the main message.', 'barar-atik-sms-otp' ); ?>"><?php echo esc_textarea( $event['tpl_vendor'] ); ?></textarea>
									<span class="barar-counter" aria-live="polite"></span>
								</details>

								<details class="barar-details">
									<summary><?php esc_html_e( 'Administrator message (optional)', 'barar-atik-sms-otp' ); ?></summary>
									<textarea rows="3" class="large-text code barar-tpl" name="<?php echo esc_attr( $name_prefix ); ?>[tpl_admin]" placeholder="<?php esc_attr_e( 'Leave empty to use the main message.', 'barar-atik-sms-otp' ); ?>"><?php echo esc_textarea( $event['tpl_admin'] ); ?></textarea>
									<span class="barar-counter" aria-live="polite"></span>
								</details>
							</div>
						<?php endforeach; ?>

						<?php $this->form_close(); ?>

					<h2><?php esc_html_e( 'Preview a template', 'barar-atik-sms-otp' ); ?></h2>
					<div class="barar-card">
						<div class="barar-card__body">
							<p class="description"><?php esc_html_e( 'Pick an event to load its message, choose a real order and see exactly what each recipient would receive.', 'barar-atik-sms-otp' ); ?></p>
							<table class="form-table barar-tool-table" role="presentation">
								<tr>
									<th scope="row"><label for="barar-preview-event"><?php esc_html_e( 'Event', 'barar-atik-sms-otp' ); ?></label></th>
									<td>
										<select id="barar-preview-event" class="barar-preview-event">
											<option value=""><?php esc_html_e( '— custom message —', 'barar-atik-sms-otp' ); ?></option>
											<?php foreach ( $events as $event_key => $event ) : ?>
												<option
													value="<?php echo esc_attr( $event_key ); ?>"
													data-template="<?php echo esc_attr( $event['tpl'] ); ?>"
													data-template-vendor="<?php echo esc_attr( $event['tpl_vendor'] ); ?>"
													data-template-admin="<?php echo esc_attr( $event['tpl_admin'] ); ?>"
												><?php echo esc_html( ! empty( $event['label'] ) ? $event['label'] : $event_key ); ?></option>
											<?php endforeach; ?>
										</select>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="barar-preview-order"><?php esc_html_e( 'Order', 'barar-atik-sms-otp' ); ?></label></th>
									<td>
										<select id="barar-preview-order" class="barar-preview-order">
											<?php if ( empty( $order_options ) ) : ?>
												<option value="0"><?php esc_html_e( 'No orders found yet', 'barar-atik-sms-otp' ); ?></option>
											<?php else : ?>
												<?php foreach ( $order_options as $oid => $olabel ) : ?>
													<option value="<?php echo esc_attr( (string) $oid ); ?>"><?php echo esc_html( $olabel ); ?></option>
												<?php endforeach; ?>
											<?php endif; ?>
										</select>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="barar-preview-recipient"><?php esc_html_e( 'Recipient', 'barar-atik-sms-otp' ); ?></label></th>
									<td>
										<select id="barar-preview-recipient" class="barar-preview-recipient">
											<?php foreach ( $recipient_types as $rtype => $rlabel ) : ?>
												<option value="<?php echo esc_attr( $rtype ); ?>"><?php echo esc_html( $rlabel ); ?></option>
											<?php endforeach; ?>
										</select>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="barar-preview-template"><?php esc_html_e( 'Message', 'barar-atik-sms-otp' ); ?></label></th>
									<td>
										<textarea id="barar-preview-template" rows="4" class="large-text code barar-preview-template barar-tpl"></textarea>
										<span class="barar-counter" aria-live="polite"></span>
									</td>
								</tr>
							</table>
							<button type="button" class="button button-primary barar-preview-send"><?php esc_html_e( 'Preview message', 'barar-atik-sms-otp' ); ?></button>
							<div class="barar-tool-result barar-preview-result" role="status" aria-live="polite"></div>
						</div>
					</div>

					<h2><?php esc_html_e( 'Send a test SMS', 'barar-atik-sms-otp' ); ?></h2>
					<div class="barar-card">
						<div class="barar-card__body">
							<table class="form-table barar-tool-table" role="presentation">
								<tr>
									<th scope="row"><label for="barar-test-phone-auto"><?php esc_html_e( 'Phone number', 'barar-atik-sms-otp' ); ?></label></th>
									<td><input type="text" id="barar-test-phone-auto" class="regular-text barar-test-phone" placeholder="+8801XXXXXXXXX" /></td>
								</tr>
								<tr>
									<th scope="row"><label for="barar-test-msg-auto"><?php esc_html_e( 'Message', 'barar-atik-sms-otp' ); ?></label></th>
									<td>
										<textarea id="barar-test-msg-auto" rows="3" class="large-text barar-test-message barar-tpl"><?php echo esc_textarea( sprintf( /* translators: %s: site title. */ __( 'Hello from %s! This is a test SMS from WordPress.', 'barar-atik-sms-otp' ), get_bloginfo( 'name' ) ) ); ?></textarea>
										<span class="barar-counter" aria-live="polite"></span>
									</td>
								</tr>
							</table>
							<button type="button" class="button button-primary barar-test-send"><?php esc_html_e( 'Send test SMS', 'barar-atik-sms-otp' ); ?></button>
							<div class="barar-tool-result barar-test-result" role="status" aria-live="polite"></div>
						</div>
					</div>
				</div>

				<aside class="barar-side">
					<?php $this->macros_panel(); ?>
				</aside>
			</div>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Page: OTP
	 * ------------------------------------------------------------------ */

	/**
	 * OTP authentication settings.
	 *
	 * @return void
	 */
	public function page_otp() {
		$this->guard();

		$stats    = Barar_Atik_Stats::all();
		$woo_on   = function_exists( 'is_account_page' );
		$wp_reg   = (bool) get_option( 'users_can_register' );
		$woo_reg  = 'yes' === get_option( 'woocommerce_enable_myaccount_registration', 'no' );

		$role_options = array();
		foreach ( array( 'subscriber', 'customer' ) as $role ) {
			if ( get_role( $role ) ) {
				$role_options[ $role ] = translate_user_role( $role );
			}
		}
		if ( empty( $role_options ) ) {
			$role_options['subscriber'] = __( 'Subscriber', 'barar-atik-sms-otp' );
		}
		?>
		<div class="wrap barar-admin">
			<h1><?php esc_html_e( 'OTP Authentication', 'barar-atik-sms-otp' ); ?></h1>
			<?php $this->nav( 'barar-atik-otp' ); ?>

			<div class="barar-cards">
				<?php
				$this->card(
					__( 'Codes sent', 'barar-atik-sms-otp' ),
					'<p class="barar-stat">' . esc_html( (string) (int) $stats['otp_sent'] ) . '</p>',
					'barar-card--quarter'
				);
				$this->card(
					__( 'Verification failures', 'barar-atik-sms-otp' ),
					'<p class="barar-stat">' . esc_html( (string) (int) $stats['otp_verify_fail'] ) . '</p>',
					'barar-card--quarter'
				);
				$this->card(
					__( 'OTP logins', 'barar-atik-sms-otp' ),
					'<p class="barar-stat">' . esc_html( (string) (int) $stats['otp_login'] ) . '</p>',
					'barar-card--quarter'
				);
				$this->card(
					__( 'OTP registrations', 'barar-atik-sms-otp' ),
					'<p class="barar-stat">' . esc_html( (string) (int) $stats['otp_register'] ) . '</p>',
					'barar-card--quarter'
				);
				?>
			</div>

			<?php if ( '1' === $this->val( 'otp_wp_register' ) && ! $wp_reg ) : ?>
				<div class="notice notice-warning"><p><?php esc_html_e( 'WordPress registration is switched off (Settings → General → Anyone can register), so the phone OTP registration form is hidden.', 'barar-atik-sms-otp' ); ?></p></div>
			<?php endif; ?>
			<?php if ( '1' === $this->val( 'otp_wc_register' ) && ! $woo_on ) : ?>
				<div class="notice notice-warning"><p><?php esc_html_e( 'WooCommerce is not active, so the My Account phone OTP forms are hidden.', 'barar-atik-sms-otp' ); ?></p></div>
			<?php endif; ?>
			<?php if ( '1' === $this->val( 'otp_wc_register' ) && $woo_on && ! $woo_reg ) : ?>
				<div class="notice notice-warning"><p><?php esc_html_e( 'WooCommerce “Allow customers to create an account” is off, so the phone registration form is hidden on My Account.', 'barar-atik-sms-otp' ); ?></p></div>
			<?php endif; ?>

			<?php $this->form_open(); ?>
				<h2><?php esc_html_e( 'Where the phone OTP appears', 'barar-atik-sms-otp' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					$this->field_checkbox( 'otp_wp_login', __( 'wp-login.php — login', 'barar-atik-sms-otp' ), __( 'Show “Login with Phone OTP” on the WordPress login screen', 'barar-atik-sms-otp' ) );
					$this->field_checkbox( 'otp_wp_register', __( 'wp-login.php — registration', 'barar-atik-sms-otp' ), __( 'Show phone registration on wp-login.php?action=register', 'barar-atik-sms-otp' ) );
					$this->field_checkbox( 'otp_wc_login', __( 'WooCommerce My Account — login', 'barar-atik-sms-otp' ), __( 'Show phone OTP below the My Account login form', 'barar-atik-sms-otp' ) );
					$this->field_checkbox( 'otp_wc_register', __( 'WooCommerce My Account — registration', 'barar-atik-sms-otp' ), __( 'Show phone OTP below the My Account registration form', 'barar-atik-sms-otp' ) );
					$this->field_checkbox( 'otp_wp_reset', __( 'wp-login.php — password reset', 'barar-atik-sms-otp' ), __( 'Offer “reset with a phone code” on the lostpassword and resetpass screens', 'barar-atik-sms-otp' ) );
					$this->field_checkbox( 'otp_wc_reset', __( 'WooCommerce — password reset', 'barar-atik-sms-otp' ), __( 'Offer “reset with a phone code” on the My Account lost password form', 'barar-atik-sms-otp' ) );
					?>
				</table>

				<h2><?php esc_html_e( 'Behaviour', 'barar-atik-sms-otp' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					$this->field_checkbox( 'password_login', __( 'Keep password login available', 'barar-atik-sms-otp' ), __( 'The normal username/email + password form stays visible', 'barar-atik-sms-otp' ) );
					$this->field_checkbox( 'passwordless_register', __( 'Passwordless registration', 'barar-atik-sms-otp' ), __( 'Do not ask for a password during phone registration (a secret password is generated automatically)', 'barar-atik-sms-otp' ) );
					$this->field_checkbox( 'auto_login_register', __( 'Sign in after registration', 'barar-atik-sms-otp' ), __( 'Automatically log the customer in once the code is verified', 'barar-atik-sms-otp' ) );
					$this->field_checkbox( 'show_password_toggle', __( 'Show password toggle (eye icon)', 'barar-atik-sms-otp' ), __( 'Display an eye icon in the password field to allow users to show/hide their password', 'barar-atik-sms-otp' ) );
					$this->field_checkbox( 'reset_auto_login', __( 'Sign in after a phone password reset', 'barar-atik-sms-otp' ), __( 'Log the user straight in once the new password has been saved', 'barar-atik-sms-otp' ) );
					?>
				</table>

				<h2><?php esc_html_e( 'WooCommerce form integration', 'barar-atik-sms-otp' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					$this->field_checkbox(
						'wc_inject',
						__( 'Inject OTP into the WooCommerce forms', 'barar-atik-sms-otp' ),
						__( 'Render phone/OTP fields directly INSIDE the My Account login and register forms, with a Password / Phone OTP switch. When off, a separate panel appears below the forms.', 'barar-atik-sms-otp' )
					);
					?>
				</table>

				<h2><?php esc_html_e( 'Account creation', 'barar-atik-sms-otp' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Control how new accounts are created during phone registration.', 'barar-atik-sms-otp' ); ?></p>
				<table class="form-table" role="presentation">
					<?php
					$this->field_checkbox(
						'phone_as_username',
						__( 'Use the phone number as the username', 'barar-atik-sms-otp' ),
						__( 'New accounts get the digits of their phone number as their WordPress username (e.g. 8801712345678)', 'barar-atik-sms-otp' )
					);
					$this->field_checkbox(
						'username_local_format',
						__( 'Store the username without the country code', 'barar-atik-sms-otp' ),
						__( 'Saves 01722032083 instead of +8801722032083 or 8801722032083, so the account can also be matched by the local number', 'barar-atik-sms-otp' )
					);
					$this->field_select(
						'register_system',
						__( 'Registration system', 'barar-atik-sms-otp' ),
						array(
							'auto'       => __( 'Automatic — WooCommerce on My Account, WordPress on wp-login', 'barar-atik-sms-otp' ),
							'woocommerce'=> __( 'Always WooCommerce (wc_create_new_customer)', 'barar-atik-sms-otp' ),
							'wordpress'  => __( 'Always WordPress (wp_insert_user)', 'barar-atik-sms-otp' ),
						),
						array( 'hint' => __( 'WooCommerce accounts receive the standard “new customer” handling (customer role, emails). Falls back to WordPress when WooCommerce is inactive.', 'barar-atik-sms-otp' ) )
					);
					$this->field_select(
						'register_role',
						__( 'Registration role', 'barar-atik-sms-otp' ),
						$role_options,
						array( 'hint' => __( 'Used by the WordPress registration path. Administrators can never be assigned — extend the list safely with the barar_atik_register_role filter.', 'barar-atik-sms-otp' ) )
					);
					?>
				</table>

				<h2><?php esc_html_e( 'Registration fields', 'barar-atik-sms-otp' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Choose which fields the phone registration form asks for, and whether they are required.', 'barar-atik-sms-otp' ); ?></p>
				<table class="form-table" role="presentation">
					<?php
					$this->field_select(
						'reg_email',
						__( 'Email address', 'barar-atik-sms-otp' ),
						array(
							'2' => __( 'Shown — required', 'barar-atik-sms-otp' ),
							'1' => __( 'Shown — optional', 'barar-atik-sms-otp' ),
							'0' => __( 'Hidden (account created without an email)', 'barar-atik-sms-otp' ),
						),
						array( 'hint' => __( 'Hiding or omitting the email skips the WordPress “new account” email, since there is nowhere to send it.', 'barar-atik-sms-otp' ) )
					);
					$this->field_select(
						'reg_first_name',
						__( 'First name', 'barar-atik-sms-otp' ),
						array(
							'2' => __( 'Shown — required', 'barar-atik-sms-otp' ),
							'1' => __( 'Shown — optional', 'barar-atik-sms-otp' ),
							'0' => __( 'Hidden', 'barar-atik-sms-otp' ),
						)
					);
					$this->field_select(
						'reg_last_name',
						__( 'Last name', 'barar-atik-sms-otp' ),
						array(
							'2' => __( 'Shown — required', 'barar-atik-sms-otp' ),
							'1' => __( 'Shown — optional', 'barar-atik-sms-otp' ),
							'0' => __( 'Hidden', 'barar-atik-sms-otp' ),
						)
					);
					$this->field_text(
						'phone_label',
						__( 'Phone field label', 'barar-atik-sms-otp' ),
						array(
							'hint' => __( 'The label shown above the phone input on all OTP forms. Empty = no label.', 'barar-atik-sms-otp' ),
						)
					);
					?>
				</table>

				<h2><?php esc_html_e( 'After sign-in / registration', 'barar-atik-sms-otp' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					$this->field_select(
						'redirect_mode',
						__( 'Redirect visitors to', 'barar-atik-sms-otp' ),
						array(
							'default'  => __( 'Default — the page they came from (WordPress redirect_to / My Account)', 'barar-atik-sms-otp' ),
							'previous' => __( 'The previous page (HTTP referer)', 'barar-atik-sms-otp' ),
							'custom'   => __( 'A custom URL', 'barar-atik-sms-otp' ),
						),
						array( 'hint' => __( 'Applied after a successful OTP login and after registration. Every URL is validated server side and must stay on this site.', 'barar-atik-sms-otp' ) )
					);
					$this->field_text(
						'redirect_url',
						__( 'Custom redirect URL', 'barar-atik-sms-otp' ),
						array(
							'type'        => 'url',
							'hint'        => __( 'Only used when the mode above is “A custom URL”. Leave empty to fall back to My Account / home.', 'barar-atik-sms-otp' ),
							'placeholder' => 'https://example.com/welcome/',
						)
					);
					?>
				</table>

				<h2><?php esc_html_e( 'Verification message', 'barar-atik-sms-otp' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					$this->field_textarea(
						'otp_template',
						__( 'OTP message', 'barar-atik-sms-otp' ),
						array(
							'rows'     => 3,
							'template' => true,
							'hint'     => __( 'The code itself is never stored in the message log.', 'barar-atik-sms-otp' ),
						)
					);
					?>
				</table>

				<h2><?php esc_html_e( 'Security limits', 'barar-atik-sms-otp' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					$this->field_text( 'otp_length', __( 'Code length', 'barar-atik-sms-otp' ), array( 'type' => 'number', 'min' => 4, 'max' => 8, 'class' => 'small-text' ) );
					$this->field_text( 'otp_expiry', __( 'Expiry (minutes)', 'barar-atik-sms-otp' ), array( 'type' => 'number', 'min' => 1, 'max' => 60, 'class' => 'small-text' ) );
					$this->field_text( 'otp_resend', __( 'Resend cooldown (seconds)', 'barar-atik-sms-otp' ), array( 'type' => 'number', 'min' => 10, 'max' => 3600, 'class' => 'small-text' ) );
					$this->field_text( 'otp_max_attempts', __( 'Wrong attempts per code', 'barar-atik-sms-otp' ), array( 'type' => 'number', 'min' => 1, 'max' => 10, 'class' => 'small-text' ) );
					$this->field_text( 'otp_limit_phone', __( 'Sends per phone (per hour)', 'barar-atik-sms-otp' ), array( 'type' => 'number', 'min' => 1, 'max' => 100, 'class' => 'small-text' ) );
					$this->field_text( 'otp_limit_ip', __( 'Sends per IP (per hour)', 'barar-atik-sms-otp' ), array( 'type' => 'number', 'min' => 1, 'max' => 200, 'class' => 'small-text' ) );
					?>
				</table>

				<?php $this->form_close(); ?>

			<div class="barar-cards barar-cards--with-side">
				<div class="barar-card barar-card--full">
					<h3><?php esc_html_e( 'OTP macros', 'barar-atik-sms-otp' ); ?></h3>
					<div class="barar-card__body">
						<p class="barar-macro-inline">
							<?php foreach ( array( '{otp}', '{otp_expiry}', '{site_name}', '{site_url}' ) as $code ) : ?>
								<button type="button" class="barar-macro" data-macro="<?php echo esc_attr( $code ); ?>"><code><?php echo esc_html( $code ); ?></code></button>
							<?php endforeach; ?>
						</p>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Page: Forms
	 * ------------------------------------------------------------------ */

	/**
	 * Forms: shortcodes, checkout behaviour, native enhancements, all visible
	 * text and the optional email → SMS mirror.
	 *
	 * @return void
	 */
	public function page_forms() {
		$this->guard();

		$woo_on = class_exists( 'WooCommerce' );
		?>
		<div class="wrap barar-admin">
			<h1><?php esc_html_e( 'TextBee SMS & OTP — Forms', 'barar-atik-sms-otp' ); ?></h1>
			<?php $this->nav( 'barar-atik-forms' ); ?>

			<?php if ( '1' === $this->val( 'checkout_order_no_email' ) && $woo_on && 'no' === get_option( 'woocommerce_enable_guest_checkout', 'no' ) ) : ?>
				<div class="notice notice-warning"><p><?php esc_html_e( 'Guest checkout is disabled, so WooCommerce still asks for an email address to create the account.', 'barar-atik-sms-otp' ); ?></p></div>
			<?php endif; ?>
			<?php if ( '1' === $this->val( 'email_to_sms' ) && '' === trim( (string) Barar_Atik_Settings::get( 'api_key', '' ) ) ) : ?>
				<div class="notice notice-warning"><p><?php esc_html_e( 'The email → SMS mirror is on, but no API key is configured yet.', 'barar-atik-sms-otp' ); ?></p></div>
			<?php endif; ?>

			<div class="barar-cards">
				<div class="barar-card barar-card--full">
					<h3><?php esc_html_e( 'Shortcodes — paste these into any page', 'barar-atik-sms-otp' ); ?></h3>
					<div class="barar-card__body">
						<table class="form-table" role="presentation">
							<?php foreach ( Barar_Atik_Shortcodes::catalogue() as $item ) : ?>
								<tr>
									<th scope="row"><code><?php echo esc_html( $item['code'] ); ?></code></th>
									<td>
										<p class="barar-copy-row">
											<input type="text" class="large-text barar-copy-input" readonly value="<?php echo esc_attr( $item['code'] ); ?>" />
											<button type="button" class="button barar-copy"><?php esc_html_e( 'Copy', 'barar-atik-sms-otp' ); ?></button>
										</p>
										<p class="description"><?php echo esc_html( $item['desc'] ); ?></p>
									</td>
								</tr>
							<?php endforeach; ?>
						</table>
						<p class="description"><?php esc_html_e( 'The shortcodes follow the settings on the Authentication page (Where the phone OTP appears) and are styled by the Design page.', 'barar-atik-sms-otp' ); ?></p>
					</div>
				</div>
			</div>

			<?php $this->form_open(); ?>

				<h2><?php esc_html_e( 'Login & registration at checkout', 'barar-atik-sms-otp' ); ?></h2>
				<p class="description"><?php esc_html_e( 'WooCommerce only — the panels appear above the checkout form for guests.', 'barar-atik-sms-otp' ); ?></p>
				<table class="form-table" role="presentation">
					<?php
					$this->field_checkbox( 'checkout_auth_tabs', __( 'Switchable tabs instead of stacked panels', 'barar-atik-sms-otp' ), __( 'Replaces the "Enable login during checkout" area with three tabs: Phone OTP Login, Phone OTP Registration and the standard Username / Email Login. Phone OTP Login is the tab that opens first. With this off, the old stacked layout is used.', 'barar-atik-sms-otp' ) );
					$this->field_checkbox( 'checkout_otp_login', __( 'Phone OTP login at checkout', 'barar-atik-sms-otp' ), __( 'Guests can sign in with their phone number before they pay', 'barar-atik-sms-otp' ) );
					$this->field_checkbox( 'checkout_otp_register', __( 'Phone registration at checkout', 'barar-atik-sms-otp' ), __( 'Guests can create an account with their phone number before they pay', 'barar-atik-sms-otp' ) );
					$this->field_checkbox( 'checkout_login_no_email', __( 'Login without an email address', 'barar-atik-sms-otp' ), __( 'Hide the standard email + password login form, so the phone number is the only way in at checkout', 'barar-atik-sms-otp' ) );
					$this->field_checkbox( 'checkout_order_no_email', __( 'Allow orders without an email address', 'barar-atik-sms-otp' ), __( 'The billing email becomes optional and the phone number stays required. WooCommerce cannot mail a receipt without an address, so the SMS is the order notification.', 'barar-atik-sms-otp' ) );
					?>
				</table>

				<h2><?php esc_html_e( 'Checkout sign-in popup', 'barar-atik-sms-otp' ); ?></h2>
				<p class="description"><?php esc_html_e( 'The same sign-in tabs in a popup, so the checkout form stays visible underneath. Closing the popup returns to the checkout exactly as it was.', 'barar-atik-sms-otp' ); ?></p>
				<table class="form-table" role="presentation">
					<?php
					$this->field_checkbox( 'checkout_popup', __( 'Offer a popup for signing in', 'barar-atik-sms-otp' ), __( 'Adds a button above the checkout form that opens the login tabs in a popup', 'barar-atik-sms-otp' ) );
					$this->field_text( 'checkout_popup_label', __( 'Popup button label', 'barar-atik-sms-otp' ) );
					$this->field_text( 'checkout_popup_title', __( 'Popup heading', 'barar-atik-sms-otp' ) );
					?>
				</table>

				<h2><?php esc_html_e( 'Verify the shopper before they pay', 'barar-atik-sms-otp' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'The shopper fills in the billing phone number and email address as usual, then has to prove them with a code before Place Order works. Clicking Place Order opens the code dialog and sends the code straight away; the order is submitted the moment it is accepted. The check runs on the server: the order is refused unless the code was accepted for the very phone number and email address being ordered.', 'barar-atik-sms-otp' ); ?>
				</p>
				<?php foreach ( $this->plugin->checkout_verify->diagnose() as $barar_problem ) : ?>
					<div class="notice notice-error inline"><p><?php echo esc_html( $barar_problem ); ?></p></div>
				<?php endforeach; ?>
				<p class="description"><?php esc_html_e( 'Testing tip: use a private/incognito window while signed out. A browser where you are signed in as an administrator is a signed-in shopper and, with "Ask signed-in customers" off, is never asked for a code.', 'barar-atik-sms-otp' ); ?></p>
				<?php if ( '1' === $this->val( 'checkout_verify' ) && '1' !== $this->val( 'checkout_verify_phone' ) && '1' !== $this->val( 'checkout_verify_email' ) ) : ?>
					<div class="notice notice-warning inline"><p><?php esc_html_e( 'Both channels are off, so there is nothing to verify and the step does nothing.', 'barar-atik-sms-otp' ); ?></p></div>
				<?php endif; ?>
				<?php if ( '1' === $this->val( 'checkout_verify' ) && '1' !== $this->val( 'checkout_verify_guests' ) && '1' !== $this->val( 'checkout_verify_users' ) ) : ?>
					<div class="notice notice-warning inline"><p><?php esc_html_e( 'Nobody is being asked to verify: both audiences below are off, so no shopper ever reaches the step.', 'barar-atik-sms-otp' ); ?></p></div>
				<?php endif; ?>
				<table class="form-table" role="presentation">
					<?php
					$this->field_checkbox( 'checkout_verify', __( 'Require verification before Place Order', 'barar-atik-sms-otp' ), __( 'Master switch. Until the code is accepted the order will not go through.', 'barar-atik-sms-otp' ) );
					$this->field_checkbox( 'checkout_verify_guests', __( 'Ask signed-out shoppers (guests)', 'barar-atik-sms-otp' ), __( 'ON: a visitor who is not logged in gets the code popup when pressing Place Order. After the correct code the order is placed and their account is created or signed in in the background. OFF: guests order without a code.', 'barar-atik-sms-otp' ) );
					$this->field_checkbox( 'checkout_verify_users', __( 'Ask signed-in customers', 'barar-atik-sms-otp' ), __( 'OFF (recommended): a customer who is already logged in never sees the popup. ON: they must also prove the phone number written on the order.', 'barar-atik-sms-otp' ) );
					$this->field_text( 'checkout_verify_resend', __( 'Resend countdown (seconds)', 'barar-atik-sms-otp' ), array( 'type' => 'number', 'min' => 10, 'max' => 3600, 'class' => 'small-text', 'hint' => __( 'How long the shopper waits before a new code can be requested. Default 60 (one minute).', 'barar-atik-sms-otp' ) ) );
					$this->field_text( 'checkout_verify_expiry', __( 'Code validity (minutes)', 'barar-atik-sms-otp' ), array( 'type' => 'number', 'min' => 1, 'max' => 60, 'class' => 'small-text', 'hint' => __( 'How long a code stays valid; shown as the countdown bar in the popup. Default 1 minute.', 'barar-atik-sms-otp' ) ) );
					$this->field_text( 'verify_popup_title', __( 'Popup heading', 'barar-atik-sms-otp' ) );
					$this->field_checkbox( 'checkout_verify_phone', __( 'Verify the phone number', 'barar-atik-sms-otp' ), __( 'A text message with the code goes to the billing phone number', 'barar-atik-sms-otp' ) );
					$this->field_checkbox( 'checkout_verify_email', __( 'Verify the email address', 'barar-atik-sms-otp' ), __( 'The same code is also emailed to the billing email address, where the WooCommerce receipt goes. The address may be left blank — the phone number then carries the verification on its own.', 'barar-atik-sms-otp' ) );
					$this->field_text( 'checkout_verify_note', __( 'Note inside the verification popup', 'barar-atik-sms-otp' ) );
					$this->field_text( 'verify_error', __( 'Message when an order is refused', 'barar-atik-sms-otp' ), array( 'placeholder' => __( 'Please verify your phone number and email address before placing the order.', 'barar-atik-sms-otp' ) ) );
					$this->field_text( 'verify_done', __( 'Text once verified', 'barar-atik-sms-otp' ) );
					$this->field_text( 'verify_change', __( 'Button that starts over', 'barar-atik-sms-otp' ) );
					$this->field_text( 'verify_email_subject', __( 'Subject of the verification email', 'barar-atik-sms-otp' ), array( 'placeholder' => __( 'Your store verification code', 'barar-atik-sms-otp' ) ) );
					?>
				</table>

				<h2><?php esc_html_e( 'Extra content on the checkout page', 'barar-atik-sms-otp' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Both boxes take normal content and shortcodes, so a delivery notice, a trust badge or a shortcode from another plugin can sit above or below the checkout form.', 'barar-atik-sms-otp' ); ?></p>
				<table class="form-table" role="presentation">
					<?php
					$this->field_textarea( 'checkout_top_content', __( 'Above the checkout form', 'barar-atik-sms-otp' ), array( 'rows' => 5 ) );
					$this->field_textarea( 'checkout_bottom_content', __( 'Below the checkout form', 'barar-atik-sms-otp' ), array( 'rows' => 5 ) );
					?>
				</table>

				<h2><?php esc_html_e( 'The phone number is the customer account', 'barar-atik-sms-otp' ); ?></h2>
				<p class="description"><?php esc_html_e( 'For a shop that takes orders by phone number only. When this is on, the number the shopper proved with a verification code becomes their WooCommerce customer: a known number signs in, an unknown number gets a new account, and no email address is needed anywhere.', 'barar-atik-sms-otp' ); ?></p>
				<?php if ( '1' === $this->val( 'checkout_auto_customer' ) && ! $woo_on ) : ?>
					<div class="notice notice-warning inline"><p><?php esc_html_e( 'WooCommerce is not active, so there is no checkout to apply this to.', 'barar-atik-sms-otp' ); ?></p></div>
				<?php endif; ?>
				<table class="form-table" role="presentation">
					<?php
					$this->field_checkbox( 'checkout_auto_customer', __( 'Create or sign in the customer at checkout', 'barar-atik-sms-otp' ), __( 'Turns the verified phone number into the WooCommerce customer account before the order is placed', 'barar-atik-sms-otp' ) );
					$this->field_checkbox( 'checkout_auto_customer_login', __( 'Sign in a known number', 'barar-atik-sms-otp' ), __( 'A number that already belongs to an account signs that customer in, so their order history stays in one place', 'barar-atik-sms-otp' ) );
					$this->field_checkbox( 'checkout_auto_customer_create', __( 'Create an account for a new number', 'barar-atik-sms-otp' ), __( 'An unknown number gets a fresh customer account with a random password nobody knows, reached only through a phone code', 'barar-atik-sms-otp' ) );

					$checkout_role_options = array();
					foreach ( array( 'subscriber', 'customer' ) as $checkout_role ) {
						if ( get_role( $checkout_role ) ) {
							$checkout_role_options[ $checkout_role ] = translate_user_role( $checkout_role );
						}
					}
					if ( empty( $checkout_role_options ) ) {
						$checkout_role_options['subscriber'] = __( 'Subscriber', 'barar-atik-sms-otp' );
					}
					$this->field_select(
						'checkout_auto_customer_role',
						__( 'Role for a new checkout account', 'barar-atik-sms-otp' ),
						$checkout_role_options,
						array( 'hint' => __( 'Administrators can never be assigned, and an account holding a blocked role is never signed in at checkout. Subscriber is raised to Customer automatically while WooCommerce is active.', 'barar-atik-sms-otp' ) )
					);
					?>
				</table>

				<h2><?php esc_html_e( 'Password reset with a phone code', 'barar-atik-sms-otp' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Enable the reset forms on the Authentication page. WordPress and WooCommerce already offer the classic email reset link — the phone code is an extra option beside it.', 'barar-atik-sms-otp' ); ?>
					<a href="<?php echo esc_url( $this->page_url( 'barar-atik-otp' ) ); ?>"><?php esc_html_e( 'Open Authentication', 'barar-atik-sms-otp' ); ?></a>
				</p>

				<h2><?php esc_html_e( 'The standard WordPress / WooCommerce forms', 'barar-atik-sms-otp' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					$this->field_checkbox(
						'enhance_native',
						__( 'Improve the native forms', 'barar-atik-sms-otp' ),
						__( 'Add a show/hide eye icon to the standard password fields and let browsers suggest the email address (autocomplete) on the WordPress and WooCommerce forms', 'barar-atik-sms-otp' )
					);
					?>
				</table>

				<h2><?php esc_html_e( 'Field labels & placeholders — plugin forms', 'barar-atik-sms-otp' ); ?></h2>
				<p class="description"><?php esc_html_e( 'There is no built-in wording: only what you type here is shown. A field left empty is hidden. Keep at least the main buttons (Send code, Verify) filled in, and a tab or link without text is not shown at all.', 'barar-atik-sms-otp' ); ?></p>
				<table class="form-table" role="presentation">
					<?php
					$this->field_text( 'lbl_email', __( 'Email address label', 'barar-atik-sms-otp' ) );
					$this->field_text( 'ph_email', __( 'Email placeholder', 'barar-atik-sms-otp' ), array( 'placeholder' => __( 'you@example.com', 'barar-atik-sms-otp' ) ) );
					$this->field_text( 'lbl_username', __( 'Username label', 'barar-atik-sms-otp' ) );
					$this->field_text( 'ph_username', __( 'Username placeholder', 'barar-atik-sms-otp' ), array( 'placeholder' => __( 'username', 'barar-atik-sms-otp' ) ) );
					$this->field_text( 'lbl_first_name', __( 'First name label', 'barar-atik-sms-otp' ) );
					$this->field_text( 'lbl_last_name', __( 'Last name label', 'barar-atik-sms-otp' ) );
					$this->field_text( 'lbl_password', __( 'Password label', 'barar-atik-sms-otp' ) );
					$this->field_text( 'lbl_new_password', __( 'New password label (reset form)', 'barar-atik-sms-otp' ) );
					$this->field_text( 'lbl_code', __( 'Verification code label', 'barar-atik-sms-otp' ) );
					$this->field_text( 'ph_phone', __( 'Phone placeholder', 'barar-atik-sms-otp' ), array( 'placeholder' => '+8801XXXXXXXXX' ) );
					?>
				</table>

				<h2><?php esc_html_e( 'Buttons, tabs and switches', 'barar-atik-sms-otp' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					$this->field_text( 'switch_login', __( 'Switch — login', 'barar-atik-sms-otp' ) );
					$this->field_text( 'switch_register', __( 'Switch — registration', 'barar-atik-sms-otp' ) );
					$this->field_text( 'switch_reset', __( 'Switch — password reset', 'barar-atik-sms-otp' ) );
					$this->field_text( 'tab_password', __( 'Tab — password', 'barar-atik-sms-otp' ) );
					$this->field_text( 'tab_otp', __( 'Tab — phone OTP', 'barar-atik-sms-otp' ) );
					$this->field_text( 'btn_send', __( 'Button — send code', 'barar-atik-sms-otp' ) );
					$this->field_text( 'btn_resend', __( 'Button — resend', 'barar-atik-sms-otp' ) );
					$this->field_text( 'btn_change', __( 'Button — change phone', 'barar-atik-sms-otp' ) );
					$this->field_text( 'btn_verify_login', __( 'Button — verify & sign in', 'barar-atik-sms-otp' ) );
					$this->field_text( 'btn_verify_register', __( 'Button — verify & create account', 'barar-atik-sms-otp' ) );
					$this->field_text( 'btn_verify_reset', __( 'Button — reset password', 'barar-atik-sms-otp' ) );
					$this->field_text( 'btn_verify', __( 'Button — verify the code (checkout)', 'barar-atik-sms-otp' ) );
					$this->field_text( 'tab_email', __( 'Tab — email (password reset)', 'barar-atik-sms-otp' ) );
					$this->field_text( 'btn_edit', __( 'Button — edit a detail (verification)', 'barar-atik-sms-otp' ) );
					$this->field_text( 'tab_auth_login', __( 'Checkout tab — phone login', 'barar-atik-sms-otp' ) );
					$this->field_text( 'tab_auth_register', __( 'Checkout tab — phone registration', 'barar-atik-sms-otp' ) );
					$this->field_text( 'tab_auth_account', __( 'Checkout tab — username / email', 'barar-atik-sms-otp' ) );
					$this->field_text( 'btn_back_login', __( 'Back link — login', 'barar-atik-sms-otp' ) );
					$this->field_text( 'btn_back_register', __( 'Back link — registration', 'barar-atik-sms-otp' ) );
					$this->field_text( 'btn_back_reset', __( 'Back link — password reset', 'barar-atik-sms-otp' ) );
					?>
				</table>

				<h2><?php esc_html_e( 'WooCommerce checkout text', 'barar-atik-sms-otp' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Labels of the billing fields on the checkout form. Left empty, the label is hidden (the placeholder, if any, still shows).', 'barar-atik-sms-otp' ); ?></p>
				<table class="form-table" role="presentation">
					<?php
					$this->field_text( 'txt_wc_phone_label', __( 'Phone label', 'barar-atik-sms-otp' ) );
					$this->field_text( 'txt_wc_phone_ph', __( 'Phone placeholder', 'barar-atik-sms-otp' ), array( 'placeholder' => '+8801XXXXXXXXX' ) );
					$this->field_text( 'txt_wc_email_label', __( 'Email label', 'barar-atik-sms-otp' ) );
					$this->field_text( 'txt_wc_email_ph', __( 'Email placeholder', 'barar-atik-sms-otp' ), array( 'placeholder' => __( 'you@example.com', 'barar-atik-sms-otp' ) ) );
					$this->field_text( 'txt_wc_first_label', __( 'First name label', 'barar-atik-sms-otp' ) );
					$this->field_text( 'txt_wc_last_label', __( 'Last name label', 'barar-atik-sms-otp' ) );
					$this->field_text( 'txt_checkout_login', __( 'Note above the checkout login panel', 'barar-atik-sms-otp' ) );
					$this->field_text( 'txt_checkout_register', __( 'Note above the checkout registration panel', 'barar-atik-sms-otp' ) );
					?>
				</table>

				<h2><?php esc_html_e( 'Email → SMS mirror', 'barar-atik-sms-otp' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Send a copy of the selected WooCommerce emails as a text message to the customer. The order automations on the Automations page stay as they are.', 'barar-atik-sms-otp' ); ?></p>
				<table class="form-table" role="presentation">
					<?php
					$this->field_checkbox( 'email_to_sms', __( 'Mirror WooCommerce emails as SMS', 'barar-atik-sms-otp' ), __( 'Every enabled email below also reaches the customer as a text message', 'barar-atik-sms-otp' ) );
					$types  = Barar_Atik_Settings::known_email_types();
					$chosen = array_map( 'strval', (array) Barar_Atik_Settings::get( 'email_to_sms_types', array() ) );
					$chosen = array_filter( $chosen );

					// Titles straight from WooCommerce when it is loaded.
					$titles = array();
					if ( function_exists( 'WC_Emails' ) ) {
						foreach ( (array) WC_Emails::instance()->get_emails() as $wc_email ) {
							if ( is_object( $wc_email ) && isset( $wc_email->id ) ) {
								$titles[ (string) $wc_email->id ] = (string) $wc_email->title;
							}
						}
					}

					$chosen_html = '';
					foreach ( $types as $type ) {
						$type   = (string) $type;
						$label  = ( '' !== ( $titles[ $type ] ?? '' ) ) ? $titles[ $type ] : ucwords( str_replace( '_', ' ', $type ) );
						$chosen_html .= '<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="barar_atik_settings[email_to_sms_types][]" value="' . esc_attr( $type ) . '" ' . checked( in_array( $type, $chosen, true ), true, false ) . ' /> <code>' . esc_html( $type ) . '</code> — ' . esc_html( $label ) . '</label>';
					}
					?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Emails to mirror', 'barar-atik-sms-otp' ); ?></th>
						<td>
							<input type="hidden" name="barar_atik_settings[email_to_sms_types][]" value="" />
							<?php echo $chosen_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above. ?>
							<p class="description"><?php esc_html_e( 'Tick none to mirror no email at all.', 'barar-atik-sms-otp' ); ?></p>
						</td>
					</tr>
					<?php
					$this->field_textarea(
						'email_to_sms_tpl',
						__( 'SMS text', 'barar-atik-sms-otp' ),
						array(
							'rows'     => 3,
							'template' => true,
							'hint'     => __( 'Available macros: {email_subject} plus every order macro.', 'barar-atik-sms-otp' ),
						)
					);
					?>
				</table>

			<?php $this->form_close(); ?>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Page: Design
	 * ------------------------------------------------------------------ */

	/**
	 * Design: the stylesheet controls for the plugin's own forms.
	 *
	 * @return void
	 */
	public function page_design() {
		$this->guard();
		?>
		<div class="wrap barar-admin">
			<h1><?php esc_html_e( 'TextBee SMS & OTP — Design', 'barar-atik-sms-otp' ); ?></h1>
			<?php $this->nav( 'barar-atik-design' ); ?>

			<div class="barar-cards">
				<div class="barar-card barar-card--full">
					<h3><?php esc_html_e( 'How the styles work', 'barar-atik-sms-otp' ); ?></h3>
					<div class="barar-card__body">
						<p class="description"><?php esc_html_e( 'These controls only touch the plugin’s own forms (login, registration, password reset, checkout panels and shortcodes). Values left at their default are not printed at all, so a fresh install looks exactly like before.', 'barar-atik-sms-otp' ); ?></p>
						<p class="description"><?php esc_html_e( '“Disable Plugin Styles” switches every piece of plugin CSS off — the controls below are then ignored and your theme decides how the fields look.', 'barar-atik-sms-otp' ); ?></p>
					</div>
				</div>
			</div>

			<?php $this->form_open(); ?>

				<h2><?php esc_html_e( 'Plugin styles', 'barar-atik-sms-otp' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					$this->field_checkbox( 'disable_styles', __( 'Disable Plugin Styles', 'barar-atik-sms-otp' ), __( 'Load no plugin CSS at all and let the theme style every form', 'barar-atik-sms-otp' ) );
					?>
				</table>

				<h2><?php esc_html_e( 'Colours', 'barar-atik-sms-otp' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					$this->field_text( 'design_primary', __( 'Accent colour', 'barar-atik-sms-otp' ), array( 'type' => 'color' ) );
					$this->field_text( 'design_border', __( 'Field border colour', 'barar-atik-sms-otp' ), array( 'type' => 'color' ) );
					$this->field_text( 'design_surface', __( 'Field background', 'barar-atik-sms-otp' ), array( 'type' => 'color' ) );
					$this->field_text( 'design_label', __( 'Label colour', 'barar-atik-sms-otp' ), array( 'type' => 'color' ) );
					$this->field_text( 'design_button_bg', __( 'Button background', 'barar-atik-sms-otp' ), array( 'placeholder' => '#1e6dd8', 'hint' => __( 'Leave empty to keep your theme’s button style.', 'barar-atik-sms-otp' ) ) );
					$this->field_text( 'design_button_text', __( 'Button text colour', 'barar-atik-sms-otp' ), array( 'placeholder' => '#ffffff', 'hint' => __( 'Leave empty to keep the theme’s button text colour.', 'barar-atik-sms-otp' ) ) );
					?>
				</table>

				<h2><?php esc_html_e( 'Shape & size', 'barar-atik-sms-otp' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					$this->field_text( 'design_radius', __( 'Corner radius (px)', 'barar-atik-sms-otp' ), array( 'type' => 'number', 'min' => 0, 'max' => 40, 'class' => 'small-text' ) );
					$this->field_text( 'design_font', __( 'Font size (px)', 'barar-atik-sms-otp' ), array( 'type' => 'number', 'min' => 12, 'max' => 24, 'class' => 'small-text' ) );
					$this->field_text( 'design_width', __( 'Form width (px)', 'barar-atik-sms-otp' ), array( 'type' => 'number', 'min' => 280, 'max' => 900, 'class' => 'small-text' ) );
					?>
				</table>

			<?php $this->form_close(); ?>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Page: Messages
	 * ------------------------------------------------------------------ */

	/**
	 * Message center: log table, filters and manual refresh.
	 *
	 * @return void
	 */
	public function page_messages() {
		$this->guard();

		$labels = Barar_Atik_Message_Log::status_labels();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only list filters from the URL; no state is changed here.
		$direction = isset( $_GET['direction'] ) ? sanitize_key( wp_unslash( $_GET['direction'] ) ) : '';
		$status    = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$search    = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$paged     = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$direction = in_array( $direction, array( 'sent', 'received' ), true ) ? $direction : '';
		$status    = isset( $labels[ $status ] ) ? $status : '';
		$per_page  = 20;

		$counts    = $this->plugin->log->counts();
		$result    = $this->plugin->log->query(
			array(
				'direction' => $direction,
				'status'    => $status,
				'search'    => $search,
				'paged'     => $paged,
				'per_page'  => $per_page,
			)
		);
		$last_sync = get_transient( 'barar_atik_last_sync' );
		$last_sync = is_array( $last_sync ) ? $last_sync : null;
		$total     = (int) $result['total'];
		$pages     = max( 1, (int) ceil( $total / $per_page ) );
		?>
		<div class="wrap barar-admin">
			<h1><?php esc_html_e( 'Message Center', 'barar-atik-sms-otp' ); ?></h1>
			<?php $this->nav( 'barar-atik-messages' ); ?>

			<div class="barar-cards">
				<?php
				$this->card( __( 'In queue', 'barar-atik-sms-otp' ), '<p class="barar-stat">' . esc_html( (string) $counts['pending'] ) . '</p>', 'barar-card--quarter' );
				$this->card( __( 'Sent', 'barar-atik-sms-otp' ), '<p class="barar-stat">' . esc_html( (string) $counts['sent'] ) . '</p>', 'barar-card--quarter' );
				$this->card( __( 'Delivered', 'barar-atik-sms-otp' ), '<p class="barar-stat">' . esc_html( (string) $counts['delivered'] ) . '</p>', 'barar-card--quarter' );
				$this->card( __( 'Failed', 'barar-atik-sms-otp' ), '<p class="barar-stat">' . esc_html( (string) $counts['failed'] ) . '</p>', 'barar-card--quarter' );
				?>
			</div>

			<form method="get" class="barar-filters">
				<input type="hidden" name="page" value="barar-atik-messages" />
				<label>
					<span><?php esc_html_e( 'Direction', 'barar-atik-sms-otp' ); ?></span>
					<select name="direction">
						<option value=""><?php esc_html_e( 'All', 'barar-atik-sms-otp' ); ?></option>
						<option value="sent" <?php selected( $direction, 'sent' ); ?>><?php esc_html_e( 'Sent', 'barar-atik-sms-otp' ); ?></option>
						<option value="received" <?php selected( $direction, 'received' ); ?>><?php esc_html_e( 'Received', 'barar-atik-sms-otp' ); ?></option>
					</select>
				</label>
				<label>
					<span><?php esc_html_e( 'Status', 'barar-atik-sms-otp' ); ?></span>
					<select name="status">
						<option value=""><?php esc_html_e( 'All', 'barar-atik-sms-otp' ); ?></option>
						<?php foreach ( $labels as $skey => $slabel ) : ?>
							<option value="<?php echo esc_attr( $skey ); ?>" <?php selected( $status, $skey ); ?>><?php echo esc_html( $slabel ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label>
					<span><?php esc_html_e( 'Search', 'barar-atik-sms-otp' ); ?></span>
					<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'phone, order id, batch id…', 'barar-atik-sms-otp' ); ?>" />
				</label>
				<button type="submit" class="button"><?php esc_html_e( 'Filter', 'barar-atik-sms-otp' ); ?></button>
				<button
					type="button"
					class="button button-secondary barar-sync"
					data-direction="<?php echo esc_attr( $direction ); ?>"
					data-status="<?php echo esc_attr( $status ); ?>"
					data-search="<?php echo esc_attr( $search ); ?>"
				><?php esc_html_e( 'Refresh from TextBee', 'barar-atik-sms-otp' ); ?></button>
				<span class="barar-tool-result barar-sync-result" role="status" aria-live="polite"></span>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=barar_atik_export' ), 'barar_atik_export' ) ); ?>"><?php esc_html_e( 'Export CSV', 'barar-atik-sms-otp' ); ?></a>
			</form>

			<p class="barar-last-sync">
				<?php if ( $last_sync ) : ?>
					<?php
					printf(
						/* translators: 1: time, 2: new rows, 3: updated rows. */
						esc_html__( 'Last refresh: %1$s — %2$d new, %3$d updated.', 'barar-atik-sms-otp' ),
						esc_html( $this->fmt_ts( isset( $last_sync['at'] ) ? $last_sync['at'] : 0 ) ),
						(int) ( isset( $last_sync['created'] ) ? $last_sync['created'] : 0 ),
						(int) ( isset( $last_sync['updated'] ) ? $last_sync['updated'] : 0 )
					);
					?>
				<?php else : ?>
					<?php esc_html_e( 'Not refreshed from TextBee yet in this hour window.', 'barar-atik-sms-otp' ); ?>
				<?php endif; ?>
			</p>

			<table class="wp-list-table widefat fixed striped barar-messages">
				<thead>
					<tr>
						<th class="barar-col-id" scope="col"><?php esc_html_e( 'ID', 'barar-atik-sms-otp' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Recipient', 'barar-atik-sms-otp' ); ?></th>
						<th class="barar-col-dir" scope="col"><?php esc_html_e( 'Direction', 'barar-atik-sms-otp' ); ?></th>
						<th class="barar-col-status" scope="col"><?php esc_html_e( 'Status', 'barar-atik-sms-otp' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Message', 'barar-atik-sms-otp' ); ?></th>
						<th class="barar-col-context" scope="col"><?php esc_html_e( 'Context', 'barar-atik-sms-otp' ); ?></th>
						<th class="barar-col-times" scope="col"><?php esc_html_e( 'Times', 'barar-atik-sms-otp' ); ?></th>
						<th class="barar-col-tb" scope="col"><?php esc_html_e( 'TextBee', 'barar-atik-sms-otp' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $result['rows'] ) ) : ?>
						<tr class="barar-empty"><td colspan="8"><?php esc_html_e( 'No messages match this filter yet. Use “Refresh from TextBee” or send a test SMS.', 'barar-atik-sms-otp' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $result['rows'] as $row ) : ?>
							<?php
							$message = (string) $row['message'];
							$excerpt = $message ? mb_substr( $message, 0, 90 ) : ( '' !== (string) $row['error'] ? '—' : '' );
								$excerpt = mb_strlen( $message ) > 90 ? $excerpt . '…' : $excerpt;

								$times = array();
								foreach ( array(
									'requested_at' => __( 'Requested', 'barar-atik-sms-otp' ),
									'sent_at'      => __( 'Sent', 'barar-atik-sms-otp' ),
									'delivered_at' => __( 'Delivered', 'barar-atik-sms-otp' ),
									'failed_at'    => __( 'Failed', 'barar-atik-sms-otp' ),
									'received_at'  => __( 'Received', 'barar-atik-sms-otp' ),
								) as $tkey => $tlabel ) {
									if ( ! empty( $row[ $tkey ] ) ) {
										$times[] = $tlabel . ': ' . $this->fmt_time( $row[ $tkey ] );
									}
								}
								?>
							<tr>
								<td class="barar-col-id"><?php echo (int) $row['id']; ?></td>
								<td><code><?php echo '' !== (string) $row['recipient'] ? esc_html( Barar_Atik_Phone::mask( $row['recipient'] ) ) : '—'; ?></code></td>
								<td class="barar-col-dir"><?php echo 'received' === $row['direction'] ? esc_html__( '← Received', 'barar-atik-sms-otp' ) : esc_html__( '→ Sent', 'barar-atik-sms-otp' ); ?></td>
								<td class="barar-col-status"><?php echo $this->badge( $row['status'] ); // phpcs:ignore ?></td>
								<td>
									<?php if ( '' !== $message ) : ?>
										<span title="<?php echo esc_attr( $message ); ?>"><?php echo esc_html( $excerpt ); ?></span>
									<?php endif; ?>
									<?php if ( '' !== (string) $row['error'] ) : ?>
										<div class="barar-row-error"><?php echo esc_html( (string) $row['error'] ); ?></div>
									<?php endif; ?>
								</td>
								<td class="barar-col-context">
									<?php
									$context_html = '';
									if ( 'order' === $row['ref_type'] && (int) $row['ref_id'] > 0 ) {
										$url    = $this->order_link( (int) $row['ref_id'] );
										$context_html = '<a href="' . esc_url( $url ) . '">' . esc_html( sprintf( /* translators: %d: order ID. */ __( 'Order #%d', 'barar-atik-sms-otp' ), (int) $row['ref_id'] ) ) . '</a>';
									} elseif ( 'user' === $row['ref_type'] && (int) $row['ref_id'] > 0 ) {
										$edit = get_edit_user_link( (int) $row['ref_id'] );
										$context_html = $edit
											? '<a href="' . esc_url( $edit ) . '">' . esc_html( sprintf( /* translators: %d: user ID. */ __( 'User #%d', 'barar-atik-sms-otp' ), (int) $row['ref_id'] ) ) . '</a>'
											: esc_html( sprintf( /* translators: %d: user ID. */ __( 'User #%d', 'barar-atik-sms-otp' ), (int) $row['ref_id'] ) );
									}
									echo $context_html; // phpcs:ignore WordPress.Security.EscapeOutput ?>
									<?php if ( '' !== (string) $row['event'] || '' !== (string) $row['source'] ) : ?>
										<div class="barar-row-sub"><?php echo esc_html( trim( $row['source'] . ' / ' . $row['event'], ' /' ) ); ?></div>
									<?php endif; ?>
								</td>
								<td class="barar-col-times">
									<?php if ( $times ) : ?>
										<?php echo esc_html( implode( ' · ', $times ) ); ?>
									<?php else : ?>
										—
									<?php endif; ?>
								</td>
								<td class="barar-col-tb">
									<?php if ( '' !== (string) $row['sms_batch_id'] ) : ?>
										<code title="<?php esc_attr_e( 'SMS batch ID', 'barar-atik-sms-otp' ); ?>"><?php echo esc_html( $row['sms_batch_id'] ); ?></code>
									<?php endif; ?>
									<?php if ( '' !== (string) $row['textbee_id'] ) : ?>
										<code title="<?php esc_attr_e( 'Message ID', 'barar-atik-sms-otp' ); ?>"><?php echo esc_html( $row['textbee_id'] ); ?></code>
									<?php endif; ?>
									<?php if ( '' === (string) $row['sms_batch_id'] && '' === (string) $row['textbee_id'] ) : ?>
										—
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<?php if ( $pages > 1 ) : ?>
				<p class="barar-pagination">
					<?php
					echo $this->page_links( $paged, $pages, $direction, $status, $search ); // phpcs:ignore WordPress.Security.EscapeOutput
					?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Pagination for the message center.
	 *
	 * @param int    $current   Current page.
	 * @param int    $pages     Total pages.
	 * @param string $direction Direction filter.
	 * @param string $status    Status filter.
	 * @param string $search    Search filter.
	 * @return string HTML.
	 */
	private function page_links( $current, $pages, $direction, $status, $search ) {
		$parts = array( 'page' => 'barar-atik-messages' );
		if ( $direction ) {
			$parts['direction'] = $direction;
		}
		if ( $status ) {
			$parts['status'] = $status;
		}
		if ( '' !== $search ) {
			$parts['s'] = $search;
		}

		/* translators: 1: current page number, 2: total pages. */
		$label = __( 'Page %1$d of %2$d', 'barar-atik-sms-otp' );
		$out   = '<span>' . esc_html( sprintf( $label, $current, $pages ) ) . '</span> ';

		if ( $current > 1 ) {
			$url = add_query_arg( array_merge( $parts, array( 'paged' => $current - 1 ) ), admin_url( 'admin.php' ) );
			$out .= '<a class="button button-small" href="' . esc_url( $url ) . '">' . esc_html__( 'Previous', 'barar-atik-sms-otp' ) . '</a> ';
		}
		if ( $current < $pages ) {
			$url = add_query_arg( array_merge( $parts, array( 'paged' => $current + 1 ) ), admin_url( 'admin.php' ) );
			$out .= '<a class="button button-small" href="' . esc_url( $url ) . '">' . esc_html__( 'Next', 'barar-atik-sms-otp' ) . '</a>';
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Page: Diagnostics
	 * ------------------------------------------------------------------ */

	/**
	 * Health checks, duplicate phone resolver, failures and counters.
	 *
	 * @return void
	 */
	public function page_diagnostics() {
		$this->guard();

		global $wpdb;

		$api_key = (string) Barar_Atik_Settings::get( 'api_key', '' );
		$device  = (string) Barar_Atik_Settings::get( 'device_id', '' );
		$secret  = (string) Barar_Atik_Settings::get( 'webhook_secret', '' );
		$diag    = Barar_Atik_TextBee::diagnostics();
		$stats   = Barar_Atik_Stats::all();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off schema check on the diagnostics screen; the result is shown once and never reused.
		$table_ok = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Barar_Atik_Message_Log::table() ) );

		$checks   = array();
		$checks[] = array(
			'ok'     => '' !== $api_key,
			'label'  => __( 'TextBee API key', 'barar-atik-sms-otp' ),
			'detail' => '' !== $api_key ? Barar_Atik_Settings::api_key_hint() : __( 'Not configured — add it on the Connection page.', 'barar-atik-sms-otp' ),
		);
		$checks[] = array(
			'ok'     => '' !== $device,
			'label'  => __( 'Device ID', 'barar-atik-sms-otp' ),
			'detail' => '' !== $device ? $device : __( 'Not set — the account default device will be used.', 'barar-atik-sms-otp' ),
		);
		$checks[] = array(
			'ok'     => '' !== $secret && strlen( $secret ) >= 20,
			'label'  => __( 'Webhook signing secret', 'barar-atik-sms-otp' ),
			'detail' => '' === $secret
				? __( 'No webhook configured (manual refresh still works).', 'barar-atik-sms-otp' )
				: ( strlen( $secret ) >= 20 ? __( 'Configured (at least 20 characters).', 'barar-atik-sms-otp' ) : __( 'Too short — regenerate it on the Connection page.', 'barar-atik-sms-otp' ) ),
		);
		$checks[] = array(
			'ok'     => $table_ok,
			'label'  => __( 'Message log table', 'barar-atik-sms-otp' ),
			'detail' => $table_ok ? Barar_Atik_Message_Log::table() : __( 'Missing — deactivate and reactivate the plugin.', 'barar-atik-sms-otp' ),
		);

		if ( class_exists( 'WooCommerce' ) ) {
			$hpos = false;
			if ( class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\OrderUtil' ) ) {
				$hpos = \Automattic\WooCommerce\Internal\DataStores\Orders\OrderUtil::custom_orders_table_usage_is_enabled();
			}
			$checks[] = array(
				'ok'     => true,
				'label'  => __( 'Order storage', 'barar-atik-sms-otp' ),
				'detail' => $hpos ? __( 'HPOS (custom order tables) active — plugin uses CRUD APIs only.', 'barar-atik-sms-otp' ) : __( 'WooCommerce order tables (legacy/CRUD compatible).', 'barar-atik-sms-otp' ),
			);
		}

		$checks[] = array(
			'ok'     => (bool) get_option( 'users_can_register' ),
			'label'  => __( 'WordPress registration', 'barar-atik-sms-otp' ),
			'detail' => get_option( 'users_can_register' ) ? __( 'Open (Settings → General).', 'barar-atik-sms-otp' ) : __( 'Closed — phone registration on wp-login.php is hidden.', 'barar-atik-sms-otp' ),
		);

		$last_ok = isset( $diag['last_success'] ) ? $diag['last_success'] : null;
		$checks[] = array(
			'ok'     => (bool) $last_ok,
			'label'  => __( 'Last successful API call', 'barar-atik-sms-otp' ),
			'detail' => $last_ok
				? sprintf( '%1$s · HTTP %2$s · %3$s', $last_ok['action'], $last_ok['status'], $this->fmt_ts( $last_ok['at'] ) )
				: __( 'None recorded yet.', 'barar-atik-sms-otp' ),
		);

		$duplicates = $this->duplicate_phones();
		$failed     = $this->plugin->log->recent( 10, 'failed' );
		?>
		<div class="wrap barar-admin">
			<h1><?php esc_html_e( 'Diagnostics', 'barar-atik-sms-otp' ); ?></h1>
			<?php $this->nav( 'barar-atik-diagnostics' ); ?>

			<div class="barar-card">
				<h3><?php esc_html_e( 'Health checks', 'barar-atik-sms-otp' ); ?></h3>
				<div class="barar-card__body">
					<table class="widefat striped barar-checks">
						<tbody>
							<?php foreach ( $checks as $check ) : ?>
								<tr>
									<td class="barar-check-icon">
										<span class="barar-dot <?php echo $check['ok'] ? 'barar-dot--ok' : 'barar-dot--warn'; ?>"></span>
									</td>
									<td><strong><?php echo esc_html( $check['label'] ); ?></strong></td>
									<td><?php echo esc_html( $check['detail'] ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>

			<?php
			$last_error = get_option( 'barar_atik_last_error', null );
			if ( is_array( $last_error ) && ! empty( $last_error['at'] ) ) :
				?>
				<div class="barar-card barar-card--alert">
					<h3><?php esc_html_e( 'Last authentication error', 'barar-atik-sms-otp' ); ?></h3>
					<div class="barar-card__body">
						<p class="description"><?php esc_html_e( 'An unexpected error was caught while sending or verifying a code. Visitors see a generic message; the details below are kept here for debugging.', 'barar-atik-sms-otp' ); ?></p>
						<ul class="barar-stat-list">
							<li><strong><?php esc_html_e( 'When:', 'barar-atik-sms-otp' ); ?></strong> <?php echo esc_html( $this->fmt_ts( isset( $last_error['at'] ) ? (int) $last_error['at'] : 0 ) ); ?></li>
							<li><strong><?php esc_html_e( 'Operation:', 'barar-atik-sms-otp' ); ?></strong> <?php echo esc_html( isset( $last_error['where'] ) ? (string) $last_error['where'] : '' ); ?></li>
							<li><strong><?php esc_html_e( 'Message:', 'barar-atik-sms-otp' ); ?></strong> <?php echo esc_html( isset( $last_error['message'] ) ? (string) $last_error['message'] : '' ); ?></li>
							<li><strong><?php esc_html_e( 'Location:', 'barar-atik-sms-otp' ); ?></strong> <?php echo esc_html( ( isset( $last_error['file'] ) ? (string) $last_error['file'] : '' ) . ':' . ( isset( $last_error['line'] ) ? (int) $last_error['line'] : 0 ) ); ?></li>
						</ul>
					</div>
				</div>
			<?php endif; ?>

			<div class="barar-card">
				<h3><?php esc_html_e( 'Duplicate phone numbers', 'barar-atik-sms-otp' ); ?></h3>
				<div class="barar-card__body">
					<p class="description"><?php esc_html_e( 'Phone OTP login refuses to choose between accounts that share a number. Remove the number from the account that should not use it.', 'barar-atik-sms-otp' ); ?></p>
					<?php if ( empty( $duplicates ) ) : ?>
						<p><?php esc_html_e( 'No duplicate phone numbers found.', 'barar-atik-sms-otp' ); ?></p>
					<?php else : ?>
						<?php foreach ( $duplicates as $number => $entries ) : ?>
							<div class="barar-dupe">
								<h4><code><?php echo esc_html( Barar_Atik_Phone::mask( $number ) ); ?></code></h4>
								<ul>
									<?php foreach ( $entries as $entry ) : ?>
										<?php
										$user = get_userdata( (int) $entry['user_id'] );
										?>
										<li>
											<strong><?php echo $user ? esc_html( $user->display_name ) : esc_html( sprintf( /* translators: %d: user ID. */ __( 'User #%d', 'barar-atik-sms-otp' ), (int) $entry['user_id'] ) ); ?></strong>
											<span class="barar-row-sub"><?php echo esc_html( $entry['key'] . ' = ' . $entry['value'] ); ?></span>
											<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="barar-inline-form">
												<?php wp_nonce_field( 'barar_atik_resolve' ); ?>
												<input type="hidden" name="action" value="barar_atik_resolve" />
												<input type="hidden" name="user_id" value="<?php echo (int) $entry['user_id']; ?>" />
												<input type="hidden" name="meta_key" value="<?php echo esc_attr( $entry['key'] ); ?>" />
												<input type="hidden" name="meta_value" value="<?php echo esc_attr( $entry['value'] ); ?>" />
												<button type="submit" class="button button-small"><?php esc_html_e( 'Remove this number', 'barar-atik-sms-otp' ); ?></button>
											</form>
										</li>
									<?php endforeach; ?>
								</ul>
							</div>
						<?php endforeach; ?>
					<?php endif; ?>
				</div>
			</div>

			<div class="barar-card">
				<h3><?php esc_html_e( 'Recent failures', 'barar-atik-sms-otp' ); ?></h3>
				<div class="barar-card__body">
					<?php if ( empty( $failed ) ) : ?>
						<p><?php esc_html_e( 'No failed messages recorded.', 'barar-atik-sms-otp' ); ?></p>
					<?php else : ?>
						<table class="widefat striped">
							<thead>
								<tr>
									<th><?php esc_html_e( 'ID', 'barar-atik-sms-otp' ); ?></th>
									<th><?php esc_html_e( 'Recipient', 'barar-atik-sms-otp' ); ?></th>
									<th><?php esc_html_e( 'Error', 'barar-atik-sms-otp' ); ?></th>
									<th><?php esc_html_e( 'When', 'barar-atik-sms-otp' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $failed as $row ) : ?>
									<tr>
										<td><?php echo (int) $row['id']; ?></td>
										<td><code><?php echo '' !== (string) $row['recipient'] ? esc_html( Barar_Atik_Phone::mask( $row['recipient'] ) ) : '—'; ?></code></td>
										<td><?php echo esc_html( (string) ( $row['error'] ? $row['error'] : __( 'No error text stored.', 'barar-atik-sms-otp' ) ) ); ?></td>
										<td><?php echo esc_html( $this->fmt_time( $row['failed_at'] ? $row['failed_at'] : $row['updated_at'] ) ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>
			</div>

			<div class="barar-card">
				<h3><?php esc_html_e( 'Counters', 'barar-atik-sms-otp' ); ?></h3>
				<div class="barar-card__body">
					<ul class="barar-stat-list">
						<?php foreach ( $stats as $key => $value ) : ?>
							<li><strong><?php echo esc_html( str_replace( '_', ' ', $key ) ); ?>:</strong> <?php echo (int) $value; ?></li>
						<?php endforeach; ?>
					</ul>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'barar_atik_reset_stats' ); ?>
						<input type="hidden" name="action" value="barar_atik_reset_stats" />
						<button type="submit" class="button"><?php esc_html_e( 'Reset counters', 'barar-atik-sms-otp' ); ?></button>
					</form>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Group user phone meta by normalized number and keep only collisions
	 * between different accounts.
	 *
	 * @return array number => list of entries( user_id, key, value ).
	 */
	private function duplicate_phones() {
		global $wpdb;

		$keys = Barar_Atik_Phone::meta_keys();
		if ( empty( $keys ) ) {
			return array();
		}

		/*
		 * $in is a generated list of %s placeholders, one per key, so the
		 * placeholder count always matches the $keys argument below.
		 *
		 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		 * phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		 */
		$in   = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id, meta_key, meta_value FROM {$wpdb->usermeta} WHERE meta_key IN ({$in}) ORDER BY user_id ASC",
				$keys
			),
			ARRAY_A
		);
		/*
		 * phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		 * phpcs:enable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		 * phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery
		 * phpcs:enable WordPress.DB.DirectDatabaseQuery.NoCaching
		 */
		$by_num = array();

		foreach ( (array) $rows as $row ) {
			$norm = Barar_Atik_Phone::normalize( isset( $row['meta_value'] ) ? (string) $row['meta_value'] : '' );
			if ( '' === $norm ) {
				continue;
			}
			$by_num[ $norm ][] = array(
				'user_id' => (int) $row['user_id'],
				'key'     => (string) $row['meta_key'],
				'value'   => (string) $row['meta_value'],
			);
		}

		$duplicates = array();
		foreach ( $by_num as $number => $entries ) {
			$user_ids = array();
			foreach ( $entries as $entry ) {
				$user_ids[ $entry['user_id'] ] = $entry['user_id'];
			}
			if ( count( $user_ids ) < 2 ) {
				continue;
			}
			$duplicates[ $number ] = $entries;
		}
		return $duplicates;
	}

	/**
	 * Edit URL for an order (HPOS safe).
	 *
	 * @param int $order_id Order ID.
	 * @return string
	 */
	private function order_link( $order_id ) {
		$order_id = (int) $order_id;

		if ( $order_id && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				if ( method_exists( $order, 'get_edit_order_url' ) ) {
					$url = $order->get_edit_order_url();
					if ( $url ) {
						return $url;
					}
				}

				$hpos = false;
				if ( class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\OrderUtil' ) ) {
					$hpos = \Automattic\WooCommerce\Internal\DataStores\Orders\OrderUtil::custom_orders_table_usage_is_enabled();
				}

				if ( $hpos ) {
					return admin_url( 'admin.php?page=wc-orders&id=' . $order_id . '&action=edit' );
				}
				return admin_url( 'post.php?post=' . $order_id . '&action=edit' );
			}
		}

		return admin_url( 'edit.php?post_type=shop_order' );
	}

	/* ---------------------------------------------------------------------
	 * AJAX: admin tools
	 * ------------------------------------------------------------------ */

	/**
	 * Nonce + capability guard for every AJAX action.
	 *
	 * @return void
	 */
	private function ajax_guard() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'code'    => 'forbidden',
					'message' => __( 'You do not have permission to do that.', 'barar-atik-sms-otp' ),
				)
			);
		}
	}

	/**
	 * Test the API connection through GET /gateway/stats.
	 *
	 * @return void
	 */
	public function ajax_test_connection() {
		$this->ajax_guard();

		$res = $this->plugin->textbee->stats();
		if ( ! $res['ok'] ) {
			wp_send_json_error(
				array(
					'code'    => 'api_error',
					'message' => $res['error'],
					'status'  => (int) $res['status'],
				)
			);
		}

		$totals = array();
		foreach ( (array) $res['totals'] as $label => $value ) {
			if ( is_scalar( $value ) ) {
				$totals[ sanitize_key( (string) $label ) ] = is_bool( $value ) ? (string) (int) $value : (string) $value;
			}
		}

		wp_send_json_success(
			array(
				'message'    => __( 'Connected to TextBee. The API key is valid.', 'barar-atik-sms-otp' ),
				'status'     => (int) $res['status'],
				'elapsed_ms' => isset( $res['elapsed_ms'] ) ? (int) $res['elapsed_ms'] : 0,
				'totals'     => $totals,
			)
		);
	}

	/**
	 * Send a test SMS.
	 *
	 * @return void
	 */
	public function ajax_test_sms() {
		$this->ajax_guard();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce and capability are verified in ajax_guard() above.
		$phone_raw = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
		$phone     = Barar_Atik_Phone::normalize( $phone_raw );
		if ( '' === $phone ) {
			wp_send_json_error(
				array(
					'code'    => 'invalid_phone',
					'message' => __( 'Please enter a valid phone number.', 'barar-atik-sms-otp' ),
				)
			);
		}

		$message = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( '' === trim( $message ) ) {
			wp_send_json_error(
				array(
					'code'    => 'empty_message',
					'message' => __( 'Please write a message first.', 'barar-atik-sms-otp' ),
				)
			);
		}

		$res = $this->plugin->sms->send_test( $phone, $message );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error(
				array(
					'code'    => $res->get_error_code(),
					'message' => $res->get_error_message(),
				)
			);
		}

		$payload = array(
			'queued'    => ! empty( $res['queued'] ),
			'duplicate' => ! empty( $res['duplicate'] ),
			'log_id'    => (int) $res['log_id'],
			'batch_id'  => (string) $res['batch_id'],
			'http'      => (int) $res['status'],
			'status'    => (int) $res['status'],
			'masked'    => Barar_Atik_Phone::mask( $phone ),
		);

		if ( ! empty( $res['queued'] ) ) {
			$payload['message'] = sprintf(
				/* translators: %s: TextBee batch id. */
				__( 'Queued by TextBee (batch %s). Delivery status appears on the Messages page.', 'barar-atik-sms-otp' ),
				'' !== $payload['batch_id'] ? $payload['batch_id'] : '—'
			);
			wp_send_json_success( $payload );
		}

		if ( ! empty( $res['duplicate'] ) ) {
			$payload['message'] = __( 'This exact message was already sent for this context (duplicate protection).', 'barar-atik-sms-otp' );
			wp_send_json_success( $payload );
		}

		$payload['code']    = 'send_failed';
		$payload['message'] = (string) $res['error'];
		wp_send_json_error( $payload );
	}

	/**
	 * Render a template against a real order.
	 *
	 * @return void
	 */
	public function ajax_preview() {
		$this->ajax_guard();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce and capability are verified in ajax_guard() above.
		$order_id  = isset( $_POST['order_id'] ) ? (int) $_POST['order_id'] : 0;
		$recipient = isset( $_POST['recipient'] ) ? sanitize_key( wp_unslash( $_POST['recipient'] ) ) : 'customer';
		if ( ! in_array( $recipient, array( 'customer', 'vendor', 'admin' ), true ) ) {
			$recipient = 'customer';
		}
		$template = isset( $_POST['template'] ) ? sanitize_textarea_field( wp_unslash( $_POST['template'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( '' === trim( $template ) ) {
			wp_send_json_error(
				array(
					'code'    => 'empty',
					'message' => __( 'Choose an event or write a message first.', 'barar-atik-sms-otp' ),
				)
			);
		}
		if ( $order_id <= 0 ) {
			wp_send_json_error(
				array(
					'code'    => 'no_order',
					'message' => __( 'Choose an order to preview against.', 'barar-atik-sms-otp' ),
				)
			);
		}

		$rendered = Barar_Atik_Macros::preview_order( $template, $order_id, $recipient );
		if ( is_wp_error( $rendered ) ) {
			wp_send_json_error(
				array(
					'code'    => $rendered->get_error_code(),
					'message' => $rendered->get_error_message(),
				)
			);
		}

		// Resolved recipient (masked) so missing numbers are visible early.
		$order   = wc_get_order( $order_id );
		$phone   = '';
		if ( 'customer' === $recipient ) {
			$phone = $order ? (string) $order->get_billing_phone() : '';
		} elseif ( 'admin' === $recipient ) {
			$phone = Barar_Atik_WooCommerce::admin_phone( false );
		} else {
			$vendor = Barar_Atik_WooCommerce::first_vendor( $order );
			$phone  = $vendor['phone'];
		}
		$normalized = Barar_Atik_Phone::normalize( $phone );

		wp_send_json_success(
			array(
				'preview'  => $rendered,
				'segments' => Barar_Atik_Macros::segments( $rendered ),
				'phone'    => $normalized ? Barar_Atik_Phone::mask( $normalized ) : '',
			)
		);
	}

	/**
	 * Create the TextBee webhook subscription (and store its secret).
	 *
	 * @return void
	 */
	public function ajax_create_webhook() {
		$this->ajax_guard();

		if ( '' === (string) Barar_Atik_Settings::get( 'api_key', '' ) ) {
			wp_send_json_error(
				array(
					'code'    => 'no_key',
					'message' => __( 'Save the TextBee API key first.', 'barar-atik-sms-otp' ),
				)
			);
		}

		$secret = (string) Barar_Atik_Settings::get( 'webhook_secret', '' );
		if ( strlen( $secret ) < 20 ) {
			$secret = bin2hex( random_bytes( 20 ) ); // 40 characters, well above the 20 minimum.
		}

		$url    = Barar_Atik_Webhook::url();
		$events = array(
			'MESSAGE_RECEIVED',
			'MESSAGE_SENT',
			'MESSAGE_DELIVERED',
			'MESSAGE_FAILED',
			'UNKNOWN_STATE',
		);

		$name = preg_replace( '/[^a-z0-9\- ]+/i', '', get_bloginfo( 'name' ) );
		$name = trim( 'WordPress - ' . ( $name ? $name : 'Site' ) );

		$res = $this->plugin->textbee->create_webhook( $name, $url, $secret, $events );
		if ( ! $res['ok'] ) {
			wp_send_json_error(
				array(
					'code'    => 'api_error',
					'message' => $res['error'],
					'status'  => (int) $res['status'],
				)
			);
		}

		$data       = is_array( $res['data'] ) ? $res['data'] : array();
		$webhook_id = '';
		foreach ( array( '_id', 'id', 'webhookId' ) as $id_key ) {
			if ( ! empty( $data[ $id_key ] ) && is_scalar( $data[ $id_key ] ) ) {
				$webhook_id = (string) $data[ $id_key ];
				break;
			}
		}

		Barar_Atik_Settings::set( 'webhook_secret', $secret );
		Barar_Atik_Settings::set( 'webhook_enabled', '1' );
		Barar_Atik_Settings::set( 'webhook_id', $webhook_id );

		wp_send_json_success(
			array(
				'url'     => $url,
				'message' => __( 'Subscription created in TextBee, the signing secret was saved and the webhook is now enabled.', 'barar-atik-sms-otp' ),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * AJAX: message sync
	 * ------------------------------------------------------------------ */

	/**
	 * Normalize a TextBee history response into a list of message arrays.
	 *
	 * @param array $res Response from the client.
	 * @return array
	 */
	private function remote_items( $res ) {
		$data = isset( $res['data'] ) && is_array( $res['data'] ) ? $res['data'] : array();

		foreach ( array( 'messages', 'items', 'data' ) as $wrap ) {
			if ( isset( $data[ $wrap ] ) && is_array( $data[ $wrap ] ) ) {
				$data = $data[ $wrap ];
				break;
			}
		}
		if ( isset( $data['_id'] ) || isset( $data['smsId'] ) ) {
			$data = array( $data );
		}
		if ( ! is_array( $data ) ) {
			return array();
		}
		return array_values( array_filter( $data, 'is_array' ) );
	}

	/**
	 * Pull history from TextBee and merge it into the local log, then poll
	 * still-pending batches (throttled to once per minute).
	 *
	 * @param array $filters direction, status, search.
	 * @return array|WP_Error Summary.
	 */
	private function run_sync( $filters ) {
		$api_query = array(
			'limit' => 25,
			'page'  => 1,
		);
		if ( ! empty( $filters['direction'] ) ) {
			$api_query['direction'] = $filters['direction'];
		}
		if ( ! empty( $filters['status'] ) ) {
			$api_query['status'] = $filters['status'];
		}
		if ( ! empty( $filters['search'] ) ) {
			$api_query['search'] = $filters['search'];
		}

		$res = $this->plugin->textbee->get_messages( $api_query );
		if ( ! $res['ok'] ) {
			return new WP_Error(
				'api',
				$res['error'] ? $res['error'] : __( 'The message history request failed.', 'barar-atik-sms-otp' ),
				array( 'status' => (int) $res['status'] )
			);
		}

		$created = 0;
		$updated = 0;
		$fetched = 0;

		foreach ( $this->remote_items( $res ) as $item ) {
			$fetched++;
			$outcome = $this->plugin->log->merge_remote( $item, array( 'source' => 'sync' ) );
			if ( 'created' === $outcome ) {
				$created++;
			} elseif ( 'updated' === $outcome ) {
				$updated++;
			}
		}

		// Refresh delivery status of recent pending batches (manual tool, throttled).
		$polled = 0;
		if ( ! get_transient( 'barar_atik_poll_lock' ) ) {
			set_transient( 'barar_atik_poll_lock', 1, MINUTE_IN_SECONDS );
			foreach ( $this->plugin->log->pending_batches( 3 ) as $batch ) {
				$pr = $this->plugin->textbee->get_messages(
					array(
						'smsBatchId' => $batch,
						'limit'      => 20,
					)
				);
				if ( ! $pr['ok'] ) {
					continue;
				}
				foreach ( $this->remote_items( $pr ) as $item ) {
					$this->plugin->log->merge_remote( $item, array( 'source' => 'sync' ) );
					$polled++;
				}
			}
		}

		$summary = array(
			'at'      => time(),
			'fetched' => $fetched,
			'created' => $created,
			'updated' => $updated,
			'polled'  => $polled,
		);
		set_transient( 'barar_atik_last_sync', $summary, HOUR_IN_SECONDS );

		return $summary;
	}

	/**
	 * AJAX: refresh the Message Center from TextBee.
	 *
	 * @return void
	 */
	public function ajax_sync() {
		$this->ajax_guard();

		$statuses = Barar_Atik_Message_Log::status_labels();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce and capability are verified in ajax_guard() above.
		$direction = isset( $_POST['direction'] ) ? sanitize_key( wp_unslash( $_POST['direction'] ) ) : '';
		$direction = in_array( $direction, array( 'sent', 'received' ), true ) ? $direction : '';

		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';
		$status = isset( $statuses[ $status ] ) ? $status : '';

		$search = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$summary = $this->run_sync(
			array(
				'direction' => $direction,
				'status'    => $status,
				'search'    => $search,
			)
		);

		if ( is_wp_error( $summary ) ) {
			wp_send_json_error(
				array(
					'code'    => $summary->get_error_code(),
					'message' => $summary->get_error_message(),
					'status'  => isset( $summary->error_data['status'] ) ? (int) $summary->error_data['status'] : 0,
				)
			);
		}

		$summary['message'] = sprintf(
			/* translators: 1: rows fetched, 2: new rows, 3: updated rows. */
			__( 'Fetched %1$d messages from TextBee — %2$d new, %3$d updated.', 'barar-atik-sms-otp' ),
			$summary['fetched'],
			$summary['created'],
			$summary['updated']
		);

		wp_send_json_success( $summary );
	}

	/**
	 * AJAX: search messages with highlighting.
	 *
	 * @return void
	 */
	public function ajax_search_messages() {
		$this->ajax_guard();

		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$search = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
		$direction = isset( $_POST['direction'] ) ? sanitize_key( wp_unslash( $_POST['direction'] ) ) : '';
		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';
		$paged = isset( $_POST['paged'] ) ? max( 1, (int) $_POST['paged'] ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$direction = in_array( $direction, array( 'sent', 'received' ), true ) ? $direction : '';
		$labels = Barar_Atik_Message_Log::status_labels();
		$status = isset( $labels[ $status ] ) ? $status : '';

		$result = $this->plugin->log->query(
			array(
				'direction' => $direction,
				'status'    => $status,
				'search'    => $search,
				'paged'     => $paged,
				'per_page'  => 20,
			)
		);

		$rows_html = '';
		if ( empty( $result['rows'] ) ) {
			$rows_html = '<tr class="barar-empty"><td colspan="8">' . esc_html__( 'No messages match this filter yet. Use "Refresh from TextBee" or send a test SMS.', 'barar-atik-sms-otp' ) . '</td></tr>';
		} else {
			foreach ( $result['rows'] as $row ) {
				$message = (string) $row['message'];
				$excerpt = $message ? mb_substr( $message, 0, 90 ) : ( '' !== (string) $row['error'] ? '—' : '' );
				$excerpt = mb_strlen( $message ) > 90 ? $excerpt . '…' : $excerpt;

				// Highlight search term in excerpt
				if ( $search && $excerpt !== '—' ) {
					$excerpt = preg_replace( '/(' . preg_quote( $search, '/' ) . ')/i', '<mark>$1</mark>', $excerpt );
				}

				$times = array();
				foreach ( array(
					'requested_at' => __( 'Requested', 'barar-atik-sms-otp' ),
					'sent_at'      => __( 'Sent', 'barar-atik-sms-otp' ),
					'delivered_at' => __( 'Delivered', 'barar-atik-sms-otp' ),
					'failed_at'    => __( 'Failed', 'barar-atik-sms-otp' ),
					'received_at'  => __( 'Received', 'barar-atik-sms-otp' ),
				) as $tkey => $tlabel ) {
					if ( ! empty( $row[ $tkey ] ) ) {
						$times[] = $tlabel . ': ' . $this->fmt_time( $row[ $tkey ] );
					}
				}

				$context_html = '';
				if ( 'order' === $row['ref_type'] && (int) $row['ref_id'] > 0 ) {
					$url = $this->order_link( (int) $row['ref_id'] );
					$context_html = '<a href="' . esc_url( $url ) . '">' . esc_html( sprintf( /* translators: %d: order ID. */ __( 'Order #%d', 'barar-atik-sms-otp' ), (int) $row['ref_id'] ) ) . '</a>';
				} elseif ( 'user' === $row['ref_type'] && (int) $row['ref_id'] > 0 ) {
					$edit = get_edit_user_link( (int) $row['ref_id'] );
					$context_html = $edit
						? '<a href="' . esc_url( $edit ) . '">' . esc_html( sprintf( /* translators: %d: user ID. */ __( 'User #%d', 'barar-atik-sms-otp' ), (int) $row['ref_id'] ) ) . '</a>'
						: esc_html( sprintf( /* translators: %d: user ID. */ __( 'User #%d', 'barar-atik-sms-otp' ), (int) $row['ref_id'] ) );
				}

				$tb_html = '';
				if ( '' !== (string) $row['sms_batch_id'] ) {
					$tb_html .= '<code title="' . esc_attr__( 'SMS batch ID', 'barar-atik-sms-otp' ) . '">' . esc_html( $row['sms_batch_id'] ) . '</code> ';
				}
				if ( '' !== (string) $row['textbee_id'] ) {
					$tb_html .= '<code title="' . esc_attr__( 'Message ID', 'barar-atik-sms-otp' ) . '">' . esc_html( $row['textbee_id'] ) . '</code>';
				}
				if ( '' === $tb_html ) {
					$tb_html = '—';
				}

				$rows_html .= '
				<tr>
					<td class="barar-col-id">' . (int) $row['id'] . '</td>
					<td><code>' . ( '' !== (string) $row['recipient'] ? esc_html( Barar_Atik_Phone::mask( $row['recipient'] ) ) : '—' ) . '</code></td>
					<td class="barar-col-dir">' . ( 'received' === $row['direction'] ? esc_html__( '← Received', 'barar-atik-sms-otp' ) : esc_html__( '→ Sent', 'barar-atik-sms-otp' ) ) . '</td>
					<td class="barar-col-status">' . $this->badge( $row['status'] ) . '</td>
					<td>' . ( '' !== $message ? '<span title="' . esc_attr( $message ) . '">' . $excerpt . '</span>' : '' ) . ( '' !== (string) $row['error'] ? '<div class="barar-row-error">' . esc_html( (string) $row['error'] ) . '</div>' : '' ) . '</td>
					<td class="barar-col-context">' . $context_html . ( '' !== (string) $row['event'] || '' !== (string) $row['source'] ? '<div class="barar-row-sub">' . esc_html( trim( $row['source'] . ' / ' . $row['event'], ' /' ) ) . '</div>' : '' ) . '</td>
					<td class="barar-col-times">' . ( $times ? esc_html( implode( ' · ', $times ) ) : '—' ) . '</td>
					<td class="barar-col-tb">' . $tb_html . '</td>
				</tr>';
			}
		}

		$total = (int) $result['total'];
		$per_page = 20;
		$pages = max( 1, (int) ceil( $total / $per_page ) );

		$pagination_html = '';
		if ( $pages > 1 ) {
			$parts = array( 'page' => 'barar-atik-messages' );
			if ( $direction ) { $parts['direction'] = $direction; }
			if ( $status ) { $parts['status'] = $status; }
			if ( $search ) { $parts['s'] = $search; }

			/* translators: 1: current page number, 2: total number of pages, e.g. Page 2 of 5. */
			$pagination_html = '<span>' . esc_html( sprintf( __( 'Page %1$d of %2$d', 'barar-atik-sms-otp' ), $paged, $pages ) ) . '</span> ';
			if ( $paged > 1 ) {
				$url = add_query_arg( array_merge( $parts, array( 'paged' => $paged - 1 ) ), admin_url( 'admin.php' ) );
				$pagination_html .= '<a class="button button-small" href="' . esc_url( $url ) . '">' . esc_html__( 'Previous', 'barar-atik-sms-otp' ) . '</a> ';
			}
			if ( $paged < $pages ) {
				$url = add_query_arg( array_merge( $parts, array( 'paged' => $paged + 1 ) ), admin_url( 'admin.php' ) );
				$pagination_html .= '<a class="button button-small" href="' . esc_url( $url ) . '">' . esc_html__( 'Next', 'barar-atik-sms-otp' ) . '</a>';
			}
		}

		wp_send_json_success( array(
			'rows_html'      => $rows_html,
			'pagination_html' => $pagination_html,
			'total'          => $total,
			'current_page'   => $paged,
			'total_pages'    => $pages,
		) );
	}

	/* ---------------------------------------------------------------------
	 * Admin-post handlers
	 * ------------------------------------------------------------------ */

	/**
	 * Remove one duplicated phone number from one account.
	 *
	 * @return void
	 */
	public function resolve_phone() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'barar-atik-sms-otp' ), 403 );
		}
		check_admin_referer( 'barar_atik_resolve' );

		$user_id  = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;
		$meta_key = isset( $_POST['meta_key'] ) ? sanitize_key( wp_unslash( $_POST['meta_key'] ) ) : '';
		$expected = isset( $_POST['meta_value'] ) ? sanitize_text_field( wp_unslash( $_POST['meta_value'] ) ) : '';

		$back   = admin_url( 'admin.php?page=barar-atik-diagnostics' );
		$notice = 'phone_error';

		if ( $user_id > 0 && in_array( $meta_key, Barar_Atik_Phone::meta_keys(), true ) && '' !== $expected ) {
			$stored = get_user_meta( $user_id, $meta_key, true );
			// Only delete the exact value shown, never a changed one.
			if ( is_string( $stored ) && hash_equals( $stored, $expected ) ) {
				delete_user_meta( $user_id, $meta_key );
				$notice = 'phone_removed';
			}
		}

		wp_safe_redirect( add_query_arg( 'barar_notice', $notice, $back ) );
		exit;
	}

	/**
	 * Reset the local counters.
	 *
	 * @return void
	 */
	public function reset_stats() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'barar-atik-sms-otp' ), 403 );
		}
		check_admin_referer( 'barar_atik_reset_stats' );

		Barar_Atik_Stats::reset();

		wp_safe_redirect( add_query_arg( 'barar_notice', 'stats_reset', admin_url( 'admin.php?page=barar-atik-diagnostics' ) ) );
		exit;
	}

	/**
	 * Export the message log as CSV (paginated internally, bounded at 5000 rows).
	 *
	 * @return void
	 */
	public function export_csv() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to export messages.', 'barar-atik-sms-otp' ), 403 );
		}
		check_admin_referer( 'barar_atik_export' );

		$columns = array(
			'id'           => __( 'ID', 'barar-atik-sms-otp' ),
			'created_at'   => __( 'Created', 'barar-atik-sms-otp' ),
			'recipient'    => __( 'Recipient (masked)', 'barar-atik-sms-otp' ),
			'direction'    => __( 'Direction', 'barar-atik-sms-otp' ),
			'status'       => __( 'Status', 'barar-atik-sms-otp' ),
			'event'        => __( 'Event', 'barar-atik-sms-otp' ),
			'source'       => __( 'Source', 'barar-atik-sms-otp' ),
			'ref_type'     => __( 'Reference type', 'barar-atik-sms-otp' ),
			'ref_id'       => __( 'Reference ID', 'barar-atik-sms-otp' ),
			'message'      => __( 'Message', 'barar-atik-sms-otp' ),
			'error'        => __( 'Error', 'barar-atik-sms-otp' ),
			'sms_batch_id' => __( 'SMS batch ID', 'barar-atik-sms-otp' ),
			'textbee_id'   => __( 'TextBee message ID', 'barar-atik-sms-otp' ),
			'requested_at' => __( 'Requested', 'barar-atik-sms-otp' ),
			'sent_at'      => __( 'Sent', 'barar-atik-sms-otp' ),
			'delivered_at' => __( 'Delivered', 'barar-atik-sms-otp' ),
			'failed_at'    => __( 'Failed', 'barar-atik-sms-otp' ),
			'received_at'  => __( 'Received', 'barar-atik-sms-otp' ),
		);

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="barar-atik-messages-' . gmdate( 'Ymd-His' ) . '.csv"' );

		$out = fopen( 'php://output', 'w' );
		if ( ! $out ) {
			wp_die( esc_html__( 'The CSV file could not be created.', 'barar-atik-sms-otp' ), 500 );
		}

		fputcsv( $out, array_values( $columns ), ',', '"', '\\' );

		$page     = 1;
		$exported = 0;
		do {
			$result = $this->plugin->log->query(
				array(
					'paged'    => $page,
					'per_page' => 100,
				)
			);
			foreach ( $result['rows'] as $row ) {
				$line = array();
				foreach ( array_keys( $columns ) as $key ) {
					$value = isset( $row[ $key ] ) ? (string) $row[ $key ] : '';
					if ( 'recipient' === $key && '' !== $value ) {
						$value = Barar_Atik_Phone::mask( $value );
					}
					$line[] = $value;
				}
				fputcsv( $out, $line, ',', '"', '\\' );
			}
			$exported += count( $result['rows'] );
			$page++;
		} while ( ! empty( $result['rows'] ) && $exported < (int) $result['total'] && $page <= 50 );

		// The php://output stream is flushed and closed by PHP on shutdown.
		exit;
	}
}
