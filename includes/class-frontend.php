<?php
/**
 * Frontend integration: renders the phone OTP controls for the WordPress
 * login/registration screens and the WooCommerce My Account forms.
 *
 * Two integration styles are supported:
 *  - a separate panel rendered outside the native forms (default), and
 *  - inline injection: the OTP controls rendered INSIDE the native
 *    WooCommerce forms with a Password / Phone OTP switch (optional).
 *
 * @package Barar_Atik
 */

defined( 'ABSPATH' ) || exit;

class Barar_Atik_Frontend {

	/**
	 * Set while the checkout authentication tabs capture WooCommerce's own
	 * login markup, so no OTP controls leak into the captured copy.
	 *
	 * WooCommerce's login template fires `woocommerce_login_form` inside the
	 * form. That template is also what the tabs reprint inside the "Username /
	 * Email Login" tab, and an inline OTP panel injected there would hide that
	 * tab's own username and password rows - which is exactly the tab that has
	 * to keep working.
	 *
	 * @var bool
	 */
	private $suspend_inline = false;

	/**
	 * Hook everything.
	 */
	public function __construct() {
		add_action( 'login_enqueue_scripts', array( $this, 'login_assets' ), 20 );
		add_action( 'login_footer', array( $this, 'render_login_page' ), 5 );
		add_action( 'wp_enqueue_scripts', array( $this, 'frontend_assets' ), 20 );

		/*
		 * Panel mode: fires once, after BOTH My Account forms and outside of
		 * them, so the inputs never end up inside the native <form>.
		 * Verified in WooCommerce templates/myaccount/form-login.php (2.6+ → trunk).
		 */
		add_action( 'woocommerce_after_customer_login_form', array( $this, 'render_wc_forms' ) );

		/*
		 * Inline mode: both hooks fire INSIDE their native forms
		 * (login: after the password row; register: before the nonce/submit),
		 * so the injected inputs submit together with the form. Inputs in this
		 * mode never carry HTML "required" attributes — a required OTP phone
		 * field would otherwise block a normal password submission.
		 */
		add_action( 'woocommerce_login_form', array( $this, 'render_wc_inline_login' ) );
		add_action( 'woocommerce_register_form', array( $this, 'render_wc_inline_register' ) );

		/*
		 * "Reset my password with a code": fires BEFORE the WooCommerce lost
		 * password <form>, so the panel sits outside the native form.
		 */
		add_action( 'woocommerce_before_lost_password_form', array( $this, 'render_wc_reset' ) );
	}

	/* ---------------------------------------------------------------------
	 * Conditions
	 * ------------------------------------------------------------------ */

	/**
	 * Is inline injection into the WooCommerce forms enabled?
	 *
	 * @return bool
	 */
	private function inject_inline() {
		if ( $this->suspend_inline ) {
			return false;
		}
		return '1' === Barar_Atik_Settings::get( 'wc_inject', '0' );
	}

	/**
	 * Run a callback with inline injection temporarily disabled.
	 *
	 * Used by the checkout authentication tabs while they capture WooCommerce's
	 * login markup, so the captured copy stays pure WooCommerce.
	 *
	 * @param callable $callback Callback receiving no arguments.
	 * @return void
	 */
	public function without_inline( $callback ) {
		$was             = $this->suspend_inline;
		$this->suspend_inline = true;

		try {
			call_user_func( $callback );
		} finally {
			$this->suspend_inline = $was;
		}
	}

	/**
	 * Will OTP controls render on the WooCommerce My Account page?
	 *
	 * @return bool
	 */
	private function account_will_render() {
		if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
			return false;
		}
		if ( $this->is_wc_reset_screen() ) {
			return Barar_Atik_Settings::context_enabled( 'wc_reset' );
		}
		$login    = Barar_Atik_Settings::context_enabled( 'wc_login' );
		$register = Barar_Atik_Settings::context_enabled( 'wc_register' )
			&& 'yes' === get_option( 'woocommerce_enable_myaccount_registration', 'no' );
		return $login || $register;
	}

	/**
	 * Is this the WooCommerce "lost password" screen?
	 *
	 * @return bool
	 */
	private function is_wc_reset_screen() {
		if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
			return false;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only screen detection, no value is used in output.
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		return false !== strpos( $uri, 'lost-password' );
	}

	/**
	 * Does the current post render one of our shortcode forms?
	 *
	 * @return bool
	 */
	private function post_has_form_shortcode() {
		$post = get_post();
		if ( ! $post ) {
			return false;
		}
		foreach ( Barar_Atik_Shortcodes::tags() as $tag ) {
			if ( has_shortcode( $post->post_content, $tag ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Public entry point so other components (checkout, shortcodes) can make
	 * sure the OTP assets are loaded on the current request.
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		$this->enqueue();
	}

	/* ---------------------------------------------------------------------
	 * Assets
	 * ------------------------------------------------------------------ */

	/**
	 * Register + enqueue assets on wp-login.php.
	 *
	 * @return void
	 */
	public function login_assets() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen routing; the OTP form is nonce checked when submitted.
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
		if ( ! in_array( $action, array( '', 'login', 'register', 'lostpassword' ), true ) || is_user_logged_in() ) {
			return;
		}

		$is_reset   = ( 'lostpassword' === $action );
		$is_register = ( 'register' === $action );
		$context     = $is_reset ? 'wp_reset' : ( $is_register ? 'wp_register' : 'wp_login' );
		if ( ! Barar_Atik_Settings::context_enabled( $context ) ) {
			return;
		}
		if ( $is_register && ! get_option( 'users_can_register' ) ) {
			return;
		}

		$this->enqueue();
	}

	/**
	 * Register + enqueue assets on the My Account page.
	 *
	 * @return void
	 */
	public function frontend_assets() {
		if ( $this->account_will_render() || $this->post_has_form_shortcode() ) {
			$this->enqueue();
		}
	}

	/**
	 * Register, localize and enqueue the OTP assets.
	 *
	 * @return void
	 */
	private function enqueue() {
		// Registering/localising twice would print the bararAtikOtp payload
		// twice, so the whole block runs once per request.
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;

		/*
		 * Versioned by each file's own mtime, never by BARAR_ATIK_VERSION.
		 * The popup, its click gate and this very config all arrive inside
		 * these two files; a fixed version string would pin the browser to
		 * whatever it first cached and the storefront would keep running the
		 * old script while the server was already serving the new markup.
		 */
		wp_register_style( 'barar-atik-otp', BARAR_ATIK_URL . 'assets/otp.css', array(), Barar_Atik_Plugin::asset_version( 'assets/otp.css' ) );
		wp_register_script( 'barar-atik-otp', BARAR_ATIK_URL . 'assets/otp.js', array(), Barar_Atik_Plugin::asset_version( 'assets/otp.js' ), true );

		wp_localize_script(
			'barar-atik-otp',
			'bararAtikOtp',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( Barar_Atik_OTP::NONCE ),
				'actions' => array(
					'send'   => 'barar_atik_send_otp',
					'verify' => 'barar_atik_verify_otp',
					'vsend'  => 'barar_atik_verify_send',
					'vcheck' => 'barar_atik_verify_check',
				),
				'flags'   => array(
					'eyeToggle'   => '1' === Barar_Atik_Settings::get( 'show_password_toggle', '1' ),
					'autocomplete' => '1' === Barar_Atik_Settings::get( 'enhance_native', '1' ),
				),
				'i18n'    => array(
					'sending'        => __( 'Sending code…', 'barar-atik-sms-otp' ),
					'verifying'      => __( 'Verifying…', 'barar-atik-sms-otp' ),
					'phoneRequired'  => __( 'Please enter your phone number.', 'barar-atik-sms-otp' ),
					'invalidPhone'   => __( 'Please enter a valid phone number.', 'barar-atik-sms-otp' ),
					'codeRequired'   => __( 'Please enter the verification code.', 'barar-atik-sms-otp' ),
					'emailRequired'  => __( 'Please enter your email address.', 'barar-atik-sms-otp' ),
					'passwordShort'  => __( 'The password must be at least 6 characters long.', 'barar-atik-sms-otp' ),
					'firstRequired'  => __( 'Please enter your first name.', 'barar-atik-sms-otp' ),
					'lastRequired'   => __( 'Please enter your last name.', 'barar-atik-sms-otp' ),
					'network'        => __( 'Something went wrong. Please try again.', 'barar-atik-sms-otp' ),
					'session'        => __( 'Your session has expired. Please refresh the page and try again.', 'barar-atik-sms-otp' ),
					/* translators: %s: masked phone number the code was sent to, e.g. +88017*****678. */
					'sentTo'         => __( 'Code sent to %s', 'barar-atik-sms-otp' ),
					'verifySuccess'  => __( 'Verified', 'barar-atik-sms-otp' ),
					'verifyNeeded'   => __( 'Verify to place order', 'barar-atik-sms-otp' ),
					'phoneOrEmail'   => __( 'Enter your phone number and email to verify', 'barar-atik-sms-otp' ),
					/*
					 * Shown when Place Order is clicked with a required
					 * billing field still empty, so the shopper fixes the
					 * form instead of being sent through a code that the
					 * order would then be rejected for anyway.
					 */
					'fillFields'     => __( 'Please complete the highlighted fields before verifying.', 'barar-atik-sms-otp' ),
					'verifyTitle'    => __( 'Verify your contact details', 'barar-atik-sms-otp' ),
					'show'           => __( 'Show', 'barar-atik-sms-otp' ),
					'hide'           => __( 'Hide', 'barar-atik-sms-otp' ),
				),
			)
		);

		if ( ! Barar_Atik_Settings::styles_disabled() ) {
			wp_enqueue_style( 'barar-atik-otp' );
			$this->late_style_fallback();
		}
		wp_enqueue_script( 'barar-atik-otp' );
	}

	/**
	 * Print the stylesheet when the OTP controls are rendered after wp_head
	 * has already run (shortcodes inside a page builder, widgets…).
	 *
	 * wp_enqueue_scripts runs as a callback OF wp_head (prio 1), so
	 * did_action('wp_head') is already 1 there — doing_action() tells the two
	 * apart and stops us printing a second copy next to wp_print_styles().
	 *
	 * @return void
	 */
	private function late_style_fallback() {
		static $printed = false;
		if ( $printed || ! did_action( 'wp_head' ) || doing_action( 'wp_head' ) ) {
			return;
		}
		$printed = true;
		// Handled by core's style printer (WP_Styles::do_item()) instead of a
		// hand-written <link>: it honours style_loader_src, the version query
		// and any data attached to the handle.
		wp_print_styles( array( 'barar-atik-otp' ) );
	}

	/* ---------------------------------------------------------------------
	 * Render hooks
	 * ------------------------------------------------------------------ */

	/**
	 * Render on wp-login.php for the login and registration screens.
	 *
	 * @return void
	 */
	public function render_login_page() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen routing; the OTP form is nonce checked when submitted.
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
		if ( ! in_array( $action, array( '', 'login', 'register', 'lostpassword' ), true ) || is_user_logged_in() ) {
			return;
		}

		if ( 'register' === $action ) {
			if ( Barar_Atik_Settings::context_enabled( 'wp_register' ) && get_option( 'users_can_register' ) ) {
				$this->render( 'wp_register' );
			}
			return;
		}

		if ( 'lostpassword' === $action ) {
			if ( Barar_Atik_Settings::context_enabled( 'wp_reset' ) ) {
				$this->render( 'wp_reset' );
			}
			return;
		}

		if ( Barar_Atik_Settings::context_enabled( 'wp_login' ) ) {
			$this->render( 'wp_login' );
		}
	}

	/**
	 * Render the "reset with a code" panel above the WooCommerce lost password
	 * form (panel mode, outside the native <form>).
	 *
	 * @return void
	 */
	public function render_wc_reset() {
		if ( is_user_logged_in() || ! Barar_Atik_Settings::context_enabled( 'wc_reset' ) ) {
			return;
		}
		$this->render( 'wc_reset' );
	}

	/**
	 * Render the WooCommerce panels below the native login + register forms
	 * (panel mode; inactive when inline injection is enabled).
	 *
	 * @return void
	 */
	public function render_wc_forms() {
		if ( is_user_logged_in() || $this->inject_inline() ) {
			return;
		}

		if ( Barar_Atik_Settings::context_enabled( 'wc_login' ) ) {
			$this->render( 'wc_login' );
		}
		if ( Barar_Atik_Settings::context_enabled( 'wc_register' ) && 'yes' === get_option( 'woocommerce_enable_myaccount_registration', 'no' ) ) {
			$this->render( 'wc_register' );
		}
	}

	/**
	 * Inline OTP controls inside the native WooCommerce login form.
	 *
	 * @return void
	 */
	public function render_wc_inline_login() {
		if ( is_user_logged_in() || ! $this->inject_inline() ) {
			return;
		}
		if ( ! Barar_Atik_Settings::context_enabled( 'wc_login' ) ) {
			return;
		}
		$this->render( 'wc_login', 'inline' );
	}

	/**
	 * Inline OTP controls inside the native WooCommerce registration form.
	 *
	 * @return void
	 */
	public function render_wc_inline_register() {
		if ( is_user_logged_in() || ! $this->inject_inline() ) {
			return;
		}
		if ( ! Barar_Atik_Settings::context_enabled( 'wc_register' ) ) {
			return;
		}
		if ( 'yes' !== get_option( 'woocommerce_enable_myaccount_registration', 'no' ) ) {
			return;
		}
		$this->render( 'wc_register', 'inline' );
	}

	/* ---------------------------------------------------------------------
	 * Markup
	 * ------------------------------------------------------------------ */

	/**
	 * Public entry point so other components (checkout tabs, popup) can print
	 * an extra copy of a context's form.
	 *
	 * The same context can legitimately appear more than once on one page - the
	 * checkout prints it inline and again inside the authentication popup - so
	 * callers pass an instance key and every element id is scoped to it.
	 *
	 * @param string $context Context key.
	 * @param array  $args {
	 *     @type string      $mode       panel (outside the form) | inline (inside the form).
	 *     @type string|null $target     CSS selector of the native form.
	 *     @type string      $redirect   Fixed redirect target.
	 *     @type string      $instance   Unique key so ids stay unique.
	 *     @type bool        $force      Print even if the context was already printed.
	 *     @type bool        $standalone Draw only the OTP controls, with no inner
	 *                                 "password / phone OTP" tab bar. Used by the
	 *                                 checkout authentication tabs, where the
	 *                                 username + password form is a tab of its own
	 *                                 and must not be claimed by this panel.
	 * }
	 * @return void
	 */
	public function render_form( $context, $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'mode'       => 'panel',
				'target'     => null,
				'redirect'   => '',
				'instance'   => '',
				'force'      => false,
				'standalone' => false,
			)
		);

		$this->render(
			$context,
			$args['mode'],
			$args['target'],
			(string) $args['redirect'],
			(string) $args['instance'],
			(bool) $args['force'],
			(bool) $args['standalone']
		);
	}

	/**
	 * Render the OTP controls for one context.
	 *
	 * @param string      $context         wp_login|wp_register|wc_login|wc_register|wp_reset|wc_reset.
	 * @param string      $mode            panel (outside the form) | inline (inside the form).
	 * @param string|null $target_override CSS selector of the native form (null = derive from the context).
	 * @param string      $redirect        Fixed redirect target for this instance.
	 * @param string      $instance        Unique key scoping this copy's element ids.
	 * @param bool        $force           Print even when the context was already printed.
	 * @param bool        $standalone       OTP controls only, no inner tab bar.
	 * @return void
	 */
	private function render( $context, $mode = 'panel', $target_override = null, $redirect = '', $instance = '', $force = false, $standalone = false ) {
		// Guard against duplicate output (a theme template firing the hook twice
		// would otherwise produce duplicate element IDs). Scoped per instance,
		// so the checkout can print the same context inline and in its popup.
		static $rendered = array();
		$slot = $context . ( '' !== $instance ? ':' . $instance : '' );
		if ( ! $force && isset( $rendered[ $slot ] ) ) {
			return;
		}
		$rendered[ $slot ] = true;

		// Every element id and the root class carry the instance key.
		$uid = ( '' !== $instance ) ? $context . '-' . preg_replace( '/[^a-zA-Z0-9_-]/', '', $instance ) : $context;
		if ( '' === $uid ) {
			$uid = $context;
		}

		$is_register  = in_array( $context, array( 'wp_register', 'wc_register' ), true );
		$is_reset     = in_array( $context, array( 'wp_reset', 'wc_reset' ), true );
		$is_wp        = ( 0 === strpos( $context, 'wp_' ) );
		$inline       = ( 'inline' === $mode );

		// A password reset always needs the new password field, so the
		// "hide the password option" switch never applies to those contexts.
		// $standalone (the checkout auth tabs) has no native form to toggle
		// against, so the inner tab bar is dropped entirely.
		$hide_password = $standalone || ( ! $is_reset && '1' !== Barar_Atik_Settings::get( 'password_login', '1' ) );
		$passwordless  = '1' === Barar_Atik_Settings::get( 'passwordless_register', '0' );
		$phone_as_user = '1' === Barar_Atik_Settings::get( 'phone_as_username', '0' );
		$show_pwd_toggle = '1' === Barar_Atik_Settings::get( 'show_password_toggle', '1' );

		$email_state  = $is_register ? Barar_Atik_Settings::field_mode( 'reg_email', '2' ) : '2';
		$first_state  = $is_register ? Barar_Atik_Settings::field_mode( 'reg_first_name', '1' ) : '1';
		$last_state   = $is_register ? Barar_Atik_Settings::field_mode( 'reg_last_name', '1' ) : '1';

		$target = '#loginform';
		if ( 'wc_login' === $context ) {
			$target = '.woocommerce-form-login';
		} elseif ( 'wc_register' === $context ) {
			$target = '.woocommerce-form-register';
		} elseif ( 'wp_register' === $context ) {
			$target = '#registerform';
		} elseif ( 'wp_reset' === $context ) {
			$target = '#lostpasswordform';
		} elseif ( 'wc_reset' === $context ) {
			$target = '.lost_reset_password';
		}
		if ( null !== $target_override ) {
			$target = $target_override;
		}
		if ( $standalone ) {
			// No native form is being toggled, so assets/otp.js must not go
			// looking for one: on the checkout a stray match would hide the
			// real username + password form that lives in the next tab.
			$target = '';
		}

		$switch_label = $is_reset
			? Barar_Atik_Settings::label( 'switch_reset' )
			: ( $is_register
				? Barar_Atik_Settings::label( 'switch_register' )
				: Barar_Atik_Settings::label( 'switch_login' ) );
		$back_label   = $is_register
			? Barar_Atik_Settings::label( 'btn_back_register' )
			: ( $is_reset
				? Barar_Atik_Settings::label( 'btn_back_reset' )
				: Barar_Atik_Settings::label( 'btn_back_login' ) );
		$verify_label = $is_reset
			? Barar_Atik_Settings::label( 'btn_verify_reset' )
			: ( $is_register
				? Barar_Atik_Settings::label( 'btn_verify_register' )
				: Barar_Atik_Settings::label( 'btn_verify_login' ) );
		$pwd_tab      = Barar_Atik_Settings::label( 'tab_password' );
		$otp_tab      = Barar_Atik_Settings::label( 'tab_otp' );
		// On a reset screen the native form is an email link, not a password.
		$email_tab    = Barar_Atik_Settings::label( 'tab_email' );

		// Fields that live inside the native form must never be HTML-required:
		// the browser would block a plain password submission while they are
		// empty. Validation happens in otp.js via the data-* attributes.
		$field = function ( $name, $label, $type, $extra = array() ) use ( $uid, $inline ) {
			$id       = 'barar-otp-' . $uid . '-' . $name;
			$type     = esc_attr( $type );
			$autoc    = isset( $extra['autocomplete'] ) ? ' autocomplete="' . esc_attr( $extra['autocomplete'] ) . '"' : '';
			$ph       = ! empty( $extra['placeholder'] ) ? ' placeholder="' . esc_attr( $extra['placeholder'] ) . '"' : '';
			$mode     = ! empty( $extra['inputmode'] ) ? ' inputmode="' . esc_attr( $extra['inputmode'] ) . '"' : '';
			$optional = ! empty( $extra['optional'] );
			$req      = ( $optional || $inline ) ? '' : ' required aria-required="true"';
			?>
			<p class="barar-otp__field">
				<?php if ( '' !== $label ) : ?>
				<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?><?php echo $optional ? ' <span class="barar-otp__optional">' . esc_html__( '(optional)', 'barar-atik-sms-otp' ) . '</span>' : ''; ?></label>
				<?php endif; ?>
				<input type="<?php echo $type; ?>" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( 'barar_' . $name ); ?>" class="barar-otp__input barar-otp__<?php echo esc_attr( $name ); ?>"<?php echo $req; // phpcs:ignore ?> <?php echo $autoc; // phpcs:ignore ?><?php echo $ph; // phpcs:ignore ?><?php echo $mode; // phpcs:ignore ?> />
			</p>
			<?php
		};
		?>
		<div class="barar-otp barar-otp--<?php echo esc_attr( $uid ); ?><?php echo $inline ? ' barar-otp--inline' : ''; ?>"
			data-context="<?php echo esc_attr( $context ); ?>"
			data-instance="<?php echo esc_attr( $instance ); ?>"
			data-target="<?php echo esc_attr( $target ); ?>"
			data-inline="<?php echo $inline ? '1' : '0'; ?>"
			data-hide-password="<?php echo $hide_password ? '1' : '0'; ?>"
			data-passwordless="<?php echo $passwordless ? '1' : '0'; ?>"
			data-email-required="<?php echo '2' === $email_state ? '1' : '0'; ?>"
			data-first-name-required="<?php echo '2' === $first_state ? '1' : '0'; ?>"
			data-last-name-required="<?php echo '2' === $last_state ? '1' : '0'; ?>"
			data-resend="<?php echo esc_attr( (string) max( 10, (int) Barar_Atik_Settings::get( 'otp_resend', '60' ) ) ); ?>"
			data-register="<?php echo $is_register ? '1' : '0'; ?>"
			data-reset="<?php echo $is_reset ? '1' : '0'; ?>"
			data-redirect="<?php echo esc_url( $redirect ); ?>"
			data-show-password-toggle="<?php echo $show_pwd_toggle ? '1' : '0'; ?>">
			<noscript><style>.barar-otp{display:none !important;}</style></noscript>

			<?php
			/*
			 * One tab bar for both modes, with Phone OTP active on load. The
			 * phone number is the primary way in everywhere this plugin offers
			 * authentication, so it is what the visitor sees first; the native
			 * password (or email link) form is one click away.
			 */
			?>
			<?php if ( ! $hide_password ) : ?>
				<div class="barar-otp__tabs" role="tablist" aria-label="<?php echo esc_attr( $is_reset ? __( 'Password reset method', 'barar-atik-sms-otp' ) : __( 'Sign in method', 'barar-atik-sms-otp' ) ); ?>">
					<button type="button" class="barar-otp__tab is-active" role="tab" aria-selected="true" data-mode="otp" id="barar-otp-tab-otp-<?php echo esc_attr( $uid ); ?>"<?php echo '' === $otp_tab ? ' style="display:none" data-barar-blank="1"' : ''; // phpcs:ignore ?>><?php echo esc_html( $otp_tab ); ?></button>
					<button type="button" class="barar-otp__tab" role="tab" aria-selected="false" data-mode="password" id="barar-otp-tab-pwd-<?php echo esc_attr( $uid ); ?>"<?php echo '' === ( $is_reset ? $email_tab : $pwd_tab ) ? ' style="display:none" data-barar-blank="1"' : ''; // phpcs:ignore ?>><?php echo esc_html( $is_reset ? $email_tab : $pwd_tab ); ?></button>
				</div>
				<?php if ( '' !== $switch_label ) : ?>
				<p class="barar-otp__caption"><?php echo esc_html( $switch_label ); ?></p>
				<?php endif; ?>
			<?php endif; ?>

			<div class="barar-otp__body">
				<p class="barar-otp__status" role="status" aria-live="polite"></p>

				<div class="barar-otp__step barar-otp__step--phone">
					<?php if ( $is_register ) : ?>
						<?php
						if ( '0' !== $email_state ) {
							$field(
								'email',
								Barar_Atik_Settings::label( 'lbl_email' ),
								'email',
								array(
									'autocomplete' => 'email',
									'inputmode'    => 'email',
									'optional'     => '2' !== $email_state,
									'placeholder'  => Barar_Atik_Settings::get( 'ph_email', '' ),
								)
							);
						}
						if ( $is_wp && ! $phone_as_user ) {
							$field( 'username', Barar_Atik_Settings::label( 'lbl_username' ), 'text', array( 'autocomplete' => 'username', 'placeholder' => Barar_Atik_Settings::get( 'ph_username', '' ) ) );
						}
						if ( '0' !== $first_state ) {
							$field( 'first_name', Barar_Atik_Settings::label( 'lbl_first_name' ), 'text', array( 'optional' => '2' !== $first_state ) );
						}
						if ( '0' !== $last_state ) {
							$field( 'last_name', Barar_Atik_Settings::label( 'lbl_last_name' ), 'text', array( 'optional' => '2' !== $last_state ) );
						}
						if ( ! $passwordless ) {
						$pwd_id = 'barar-otp-' . $uid . '-password';
						?>
						<p class="barar-otp__field">
							<?php if ( '' !== Barar_Atik_Settings::label( 'lbl_password' ) ) : ?>
							<label for="<?php echo esc_attr( $pwd_id ); ?>"><?php echo esc_html( Barar_Atik_Settings::label( 'lbl_password' ) ); ?></label>
							<?php endif; ?>
							<span class="barar-otp__password-wrap">
								<input type="password" id="<?php echo esc_attr( $pwd_id ); ?>" name="barar_password" class="barar-otp__input barar-otp__password" autocomplete="new-password" />
								<?php if ( $show_pwd_toggle ) : ?>
									<button type="button" class="barar-otp__password-toggle" aria-label="<?php esc_attr_e( 'Show password', 'barar-atik-sms-otp' ); ?>" aria-pressed="false">
										<svg class="barar-otp__eye-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
										<svg class="barar-otp__eye-off-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="display:none;"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
									</button>
								<?php endif; ?>
							</span>
						</p>
						<?php
					}
						?>
					<?php endif; ?>

					<p class="barar-otp__field">
						<?php if ( '' !== Barar_Atik_Settings::phone_label() ) : ?>
						<label for="barar-otp-<?php echo esc_attr( $uid ); ?>-phone"><?php echo esc_html( Barar_Atik_Settings::phone_label() ); ?></label>
						<?php endif; ?>
						<input type="tel" id="barar-otp-<?php echo esc_attr( $uid ); ?>-phone" name="barar_phone" class="barar-otp__input barar-otp__phone" autocomplete="tel" inputmode="tel" placeholder="<?php echo esc_attr( Barar_Atik_Settings::label( 'ph_phone' ) ); ?>"<?php echo $inline ? '' : ' required aria-required="true"'; // phpcs:ignore ?> />
					</p>

					<p class="barar-otp__actions">
						<button type="button" class="barar-otp__send button"><?php echo esc_html( Barar_Atik_Settings::label( 'btn_send' ) ); ?></button>
						<span class="barar-otp__spinner" hidden aria-hidden="true"></span>
					</p>
				</div>

				<div class="barar-otp__step barar-otp__step--code" hidden>
					<p class="barar-otp__hint"></p>
					<p class="barar-otp__field">
						<?php if ( '' !== Barar_Atik_Settings::label( 'lbl_code' ) ) : ?>
						<label for="barar-otp-<?php echo esc_attr( $uid ); ?>-code"><?php echo esc_html( Barar_Atik_Settings::label( 'lbl_code' ) ); ?></label>
						<?php endif; ?>
						<input type="text" id="barar-otp-<?php echo esc_attr( $uid ); ?>-code" name="barar_otp" class="barar-otp__input barar-otp__code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]*"<?php echo $inline ? '' : ' required aria-required="true"'; // phpcs:ignore ?> />
					</p>
					<?php if ( $is_reset ) : ?>
						<?php $reset_pwd_id = 'barar-otp-' . $uid . '-password'; ?>
						<p class="barar-otp__field">
							<?php if ( '' !== Barar_Atik_Settings::label( 'lbl_new_password' ) ) : ?>
							<label for="<?php echo esc_attr( $reset_pwd_id ); ?>"><?php echo esc_html( Barar_Atik_Settings::label( 'lbl_new_password' ) ); ?></label>
							<?php endif; ?>
							<span class="barar-otp__password-wrap">
								<input type="password" id="<?php echo esc_attr( $reset_pwd_id ); ?>" name="barar_password" class="barar-otp__input barar-otp__password" autocomplete="new-password" />
								<?php if ( $show_pwd_toggle ) : ?>
									<button type="button" class="barar-otp__password-toggle" aria-label="<?php esc_attr_e( 'Show password', 'barar-atik-sms-otp' ); ?>" aria-pressed="false">
										<svg class="barar-otp__eye-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
										<svg class="barar-otp__eye-off-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="display:none;"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
									</button>
								<?php endif; ?>
							</span>
						</p>
					<?php endif; ?>
					<p class="barar-otp__actions">
						<button type="button" class="barar-otp__verify button"><?php echo esc_html( $verify_label ); ?></button>
						<button type="button" class="barar-otp__resend button" disabled data-label="<?php echo esc_attr( Barar_Atik_Settings::label( 'btn_resend' ) ); ?>"><?php echo esc_html( Barar_Atik_Settings::label( 'btn_resend' ) ); ?></button>
						<span class="barar-otp__spinner" hidden aria-hidden="true"></span>
					</p>
					<p class="barar-otp__actions">
						<button type="button" class="barar-otp__change button"<?php echo '' === Barar_Atik_Settings::label( 'btn_change' ) ? ' style="display:none" data-barar-blank="1"' : ''; // phpcs:ignore ?>><?php echo esc_html( Barar_Atik_Settings::label( 'btn_change' ) ); ?></button>
					</p>
				</div>

				<?php if ( ! $hide_password ) : ?>
					<p class="barar-otp__actions">
						<button type="button" class="barar-otp__back link"<?php echo '' === $back_label ? ' style="display:none" data-barar-blank="1"' : ''; // phpcs:ignore ?>><?php echo esc_html( $back_label ); ?></button>
					</p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
