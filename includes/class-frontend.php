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
		return '1' === Barar_Atik_Settings::get( 'wc_inject', '0' );
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
		$login    = Barar_Atik_Settings::context_enabled( 'wc_login' );
		$register = Barar_Atik_Settings::context_enabled( 'wc_register' )
			&& 'yes' === get_option( 'woocommerce_enable_myaccount_registration', 'no' );
		return $login || $register;
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
		if ( ! in_array( $action, array( '', 'login', 'register' ), true ) || is_user_logged_in() ) {
			return;
		}

		$is_register = ( 'register' === $action );
		$context     = $is_register ? 'wp_register' : 'wp_login';
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
		if ( ! $this->account_will_render() ) {
			return;
		}
		$this->enqueue();
	}

	/**
	 * Register, localize and enqueue the OTP assets.
	 *
	 * @return void
	 */
	private function enqueue() {
		wp_register_style( 'barar-atik-otp', BARAR_ATIK_URL . 'assets/otp.css', array(), BARAR_ATIK_VERSION );
		wp_register_script( 'barar-atik-otp', BARAR_ATIK_URL . 'assets/otp.js', array(), BARAR_ATIK_VERSION, true );

		wp_localize_script(
			'barar-atik-otp',
			'bararAtikOtp',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( Barar_Atik_OTP::NONCE ),
				'actions' => array(
					'send'   => 'barar_atik_send_otp',
					'verify' => 'barar_atik_verify_otp',
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
					/* translators: %s: countdown time until a new code can be requested, e.g. 00:45. */
					'resendIn'       => __( 'Resend in %s', 'barar-atik-sms-otp' ),
					'resendNow'      => __( 'Resend code', 'barar-atik-sms-otp' ),
					/* translators: %s: masked phone number the code was sent to, e.g. +88017*****678. */
					'sentTo'         => __( 'Code sent to %s', 'barar-atik-sms-otp' ),
				),
			)
		);

		wp_enqueue_style( 'barar-atik-otp' );
		wp_enqueue_script( 'barar-atik-otp' );
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
		if ( ! in_array( $action, array( '', 'login', 'register' ), true ) || is_user_logged_in() ) {
			return;
		}

		if ( 'register' === $action ) {
			if ( Barar_Atik_Settings::context_enabled( 'wp_register' ) && get_option( 'users_can_register' ) ) {
				$this->render( 'wp_register' );
			}
			return;
		}

		if ( Barar_Atik_Settings::context_enabled( 'wp_login' ) ) {
			$this->render( 'wp_login' );
		}
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
	 * Render the OTP controls for one context.
	 *
	 * @param string $context wp_login|wp_register|wc_login|wc_register.
	 * @param string $mode    panel (outside the form) | inline (inside the form).
	 * @return void
	 */
	private function render( $context, $mode = 'panel' ) {
		// Guard against duplicate output (a theme template firing the hook twice
		// would otherwise produce duplicate element IDs).
		static $rendered = array();
		if ( isset( $rendered[ $context ] ) ) {
			return;
		}
		$rendered[ $context ] = true;

		$is_register  = in_array( $context, array( 'wp_register', 'wc_register' ), true );
		$is_wp        = ( 0 === strpos( $context, 'wp_' ) );
		$inline       = ( 'inline' === $mode );

		$hide_password = '1' !== Barar_Atik_Settings::get( 'password_login', '1' );
		$passwordless  = '1' === Barar_Atik_Settings::get( 'passwordless_register', '0' );
		$phone_as_user = '1' === Barar_Atik_Settings::get( 'phone_as_username', '0' );

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
		}

		$switch_label = $is_register ? __( 'Register with Phone OTP', 'barar-atik-sms-otp' ) : __( 'Login with Phone OTP', 'barar-atik-sms-otp' );
		$back_label   = $is_register ? __( 'Use email registration instead', 'barar-atik-sms-otp' ) : __( 'Use password instead', 'barar-atik-sms-otp' );
		$verify_label = $is_register ? __( 'Verify & Create Account', 'barar-atik-sms-otp' ) : __( 'Verify & Sign In', 'barar-atik-sms-otp' );
		$pwd_tab      = __( 'Password', 'barar-atik-sms-otp' );
		$otp_tab      = __( 'Phone OTP', 'barar-atik-sms-otp' );

		// Fields that live inside the native form must never be HTML-required:
		// the browser would block a plain password submission while they are
		// empty. Validation happens in otp.js via the data-* attributes.
		$field = function ( $name, $label, $type, $extra = array() ) use ( $context, $inline ) {
			$id       = 'barar-otp-' . $context . '-' . $name;
			$type     = esc_attr( $type );
			$autoc    = isset( $extra['autocomplete'] ) ? ' autocomplete="' . esc_attr( $extra['autocomplete'] ) . '"' : '';
			$optional = ! empty( $extra['optional'] );
			$req      = ( $optional || $inline ) ? '' : ' required aria-required="true"';
			?>
			<p class="barar-otp__field">
				<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?><?php echo $optional ? ' <span class="barar-otp__optional">' . esc_html__( '(optional)', 'barar-atik-sms-otp' ) . '</span>' : ''; ?></label>
				<input type="<?php echo $type; ?>" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( 'barar_' . $name ); ?>" class="barar-otp__input barar-otp__<?php echo esc_attr( $name ); ?>"<?php echo $req; // phpcs:ignore ?> <?php echo $autoc; // phpcs:ignore ?> />
			</p>
			<?php
		};
		?>
		<div class="barar-otp barar-otp--<?php echo esc_attr( $context ); ?><?php echo $inline ? ' barar-otp--inline' : ''; ?>"
			data-context="<?php echo esc_attr( $context ); ?>"
			data-target="<?php echo esc_attr( $target ); ?>"
			data-inline="<?php echo $inline ? '1' : '0'; ?>"
			data-hide-password="<?php echo $hide_password ? '1' : '0'; ?>"
			data-passwordless="<?php echo $passwordless ? '1' : '0'; ?>"
			data-email-required="<?php echo '2' === $email_state ? '1' : '0'; ?>"
			data-first-name-required="<?php echo '2' === $first_state ? '1' : '0'; ?>"
			data-last-name-required="<?php echo '2' === $last_state ? '1' : '0'; ?>"
			data-resend="<?php echo esc_attr( (string) max( 10, (int) Barar_Atik_Settings::get( 'otp_resend', '60' ) ) ); ?>"
			data-register="<?php echo $is_register ? '1' : '0'; ?>">
			<noscript><style>.barar-otp{display:none !important;}</style></noscript>

			<?php if ( $inline ) : ?>
				<?php if ( ! $hide_password ) : ?>
					<div class="barar-otp__tabs" role="tablist">
						<button type="button" class="barar-otp__tab is-active" role="tab" aria-selected="true" data-mode="password"><?php echo esc_html( $pwd_tab ); ?></button>
						<button type="button" class="barar-otp__tab" role="tab" aria-selected="false" data-mode="otp"><?php echo esc_html( $otp_tab ); ?></button>
					</div>
				<?php endif; ?>
			<?php else : ?>
				<button type="button" class="barar-otp__switch" aria-expanded="false"><?php echo esc_html( $switch_label ); ?></button>
			<?php endif; ?>

			<div class="barar-otp__body"<?php echo ( $inline && ! $hide_password ) ? ' hidden' : ''; ?>>
				<p class="barar-otp__status" role="status" aria-live="polite"></p>

				<div class="barar-otp__step barar-otp__step--phone">
					<?php if ( $is_register ) : ?>
						<?php
						if ( '0' !== $email_state ) {
							$field(
								'email',
								__( 'Email address', 'barar-atik-sms-otp' ),
								'email',
								array(
									'autocomplete' => 'email',
									'optional'     => '2' !== $email_state,
								)
							);
						}
						if ( $is_wp && ! $phone_as_user ) {
							$field( 'username', __( 'Username', 'barar-atik-sms-otp' ), 'text', array( 'autocomplete' => 'username' ) );
						}
						if ( '0' !== $first_state ) {
							$field( 'first_name', __( 'First name', 'barar-atik-sms-otp' ), 'text', array( 'optional' => '2' !== $first_state ) );
						}
						if ( '0' !== $last_state ) {
							$field( 'last_name', __( 'Last name', 'barar-atik-sms-otp' ), 'text', array( 'optional' => '2' !== $last_state ) );
						}
						if ( ! $passwordless ) {
							$field( 'password', __( 'Password', 'barar-atik-sms-otp' ), 'password', array( 'autocomplete' => 'new-password' ) );
						}
						?>
					<?php endif; ?>

					<p class="barar-otp__field">
						<label for="barar-otp-<?php echo esc_attr( $context ); ?>-phone"><?php echo esc_html( Barar_Atik_Settings::phone_label() ); ?></label>
						<input type="tel" id="barar-otp-<?php echo esc_attr( $context ); ?>-phone" name="barar_phone" class="barar-otp__input barar-otp__phone" autocomplete="tel" inputmode="tel" placeholder="+8801XXXXXXXXX"<?php echo $inline ? '' : ' required aria-required="true"'; // phpcs:ignore ?> />
					</p>

					<p class="barar-otp__actions">
						<button type="button" class="barar-otp__send button"><?php esc_html_e( 'Send OTP', 'barar-atik-sms-otp' ); ?></button>
						<span class="barar-otp__spinner" hidden aria-hidden="true"></span>
					</p>
				</div>

				<div class="barar-otp__step barar-otp__step--code" hidden>
					<p class="barar-otp__hint"></p>
					<p class="barar-otp__field">
						<label for="barar-otp-<?php echo esc_attr( $context ); ?>-code"><?php esc_html_e( 'Verification code', 'barar-atik-sms-otp' ); ?></label>
						<input type="text" id="barar-otp-<?php echo esc_attr( $context ); ?>-code" name="barar_otp" class="barar-otp__input barar-otp__code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]*"<?php echo $inline ? '' : ' required aria-required="true"'; // phpcs:ignore ?> />
					</p>
					<p class="barar-otp__actions">
						<button type="button" class="barar-otp__verify button"><?php echo esc_html( $verify_label ); ?></button>
						<button type="button" class="barar-otp__resend button" disabled><?php esc_html_e( 'Resend code', 'barar-atik-sms-otp' ); ?></button>
						<span class="barar-otp__spinner" hidden aria-hidden="true"></span>
					</p>
					<p class="barar-otp__actions">
						<button type="button" class="barar-otp__change button"><?php esc_html_e( 'Change phone number', 'barar-atik-sms-otp' ); ?></button>
					</p>
				</div>

				<?php if ( ! $hide_password ) : ?>
					<p class="barar-otp__actions">
						<button type="button" class="barar-otp__back link"><?php echo esc_html( $back_label ); ?></button>
					</p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
