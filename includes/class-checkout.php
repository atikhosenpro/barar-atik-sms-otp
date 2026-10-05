<?php
/**
 * WooCommerce checkout: phone login/registration and phone-first contact data.
 *
 * Nothing here touches the order SMS automations (class-woocommerce.php); the
 * components are deliberately separate so the working automations stay as they
 * are.
 *
 * @package Barar_Atik
 */

defined( 'ABSPATH' ) || exit;

class Barar_Atik_Checkout {

	/**
	 * Hook everything.
	 */
	public function __construct() {
		// Loads the OTP assets on the checkout page only.
		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ), 19 );

		// Priority 20: after WooCommerce's own login form (priority 10) so the
		// existing checkout keeps looking exactly like it does today.
		add_action( 'woocommerce_before_checkout_form', array( $this, 'render_panels' ), 20 );

		add_filter( 'woocommerce_checkout_fields', array( $this, 'checkout_fields' ), 20 );
		add_filter( 'woocommerce_checkout_required_field_notice', array( $this, 'required_notice' ), 10, 2 );

		// Runs after WC builds the posted data (line ~858 of class-wc-checkout.php).
		add_filter( 'woocommerce_checkout_posted_data', array( $this, 'posted_data' ), 99 );

		// Removal has to happen after WooCommerce registers its own hook,
		// therefore it runs on "wp" instead of in the constructor.
		add_action( 'wp', array( $this, 'maybe_hide_native_login' ), 5 );
	}

	/* ---------------------------------------------------------------------
	 * Assets
	 * ------------------------------------------------------------------ */

	/**
	 * Enqueue the OTP assets on the checkout page when a checkout feature is on.
	 *
	 * @return void
	 */
	public function assets() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}
		$needed = '1' === Barar_Atik_Settings::get( 'checkout_otp_login', '1' )
			|| '1' === Barar_Atik_Settings::get( 'checkout_otp_register', '0' )
			|| '1' === Barar_Atik_Settings::get( 'checkout_popup', '0' )
			|| '1' === Barar_Atik_Settings::get( 'checkout_verify', '0' );
		if ( ! $needed ) {
			return;
		}
		Barar_Atik_Plugin::instance()->frontend->enqueue_assets();
	}

	/* ---------------------------------------------------------------------
	 * Panels
	 * ------------------------------------------------------------------ */

	/**
	 * Print the phone login / registration panels above the checkout form.
	 *
	 * Kept as the compatibility path for installations that switch the tabbed
	 * area off: the two phone forms are then simply stacked, exactly as before.
	 *
	 * @return void
	 */
	public function render_panels() {
		if ( '1' === Barar_Atik_Settings::get( 'checkout_auth_tabs', '1' ) ) {
			// The tabbed area draws them itself.
			return;
		}
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_user_logged_in() ) {
			return;
		}

		$plugin = Barar_Atik_Plugin::instance();
		if ( ! $plugin->frontend ) {
			return;
		}

		$redirect = function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : '';
		$out      = '';

		if ( '1' === Barar_Atik_Settings::get( 'checkout_otp_login', '1' ) && Barar_Atik_Settings::context_enabled( 'wc_login' ) ) {
			$note = Barar_Atik_Settings::label( 'txt_checkout_login' );
			$out .= '<div class="barar-checkout-otp barar-checkout-otp--login">';
			if ( '' !== $note ) {
				$out .= '<p class="barar-checkout-otp__note">' . esc_html( $note ) . '</p>';
			}
			$out .= $this->capture( 'wc_login', $redirect );
			$out .= '</div>';
		}

		if ( '1' === Barar_Atik_Settings::get( 'checkout_otp_register', '0' )
			&& Barar_Atik_Settings::context_enabled( 'wc_register' )
			&& $this->registration_allowed()
		) {
			$note = Barar_Atik_Settings::label( 'txt_checkout_register' );
			$out .= '<div class="barar-checkout-otp barar-checkout-otp--register">';
			if ( '' !== $note ) {
				$out .= '<p class="barar-checkout-otp__note">' . esc_html( $note ) . '</p>';
			}
			$out .= $this->capture( 'wc_register', $redirect );
			$out .= '</div>';
		}

		if ( '' === $out ) {
			return;
		}

		echo '<div class="barar-checkout-otp-wrap">' . $out . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above.
	}

	/**
	 * Render one panel and return its markup.
	 *
	 * The target stays "derive from the context", so opening the phone panel
	 * at checkout folds the native login form away again.
	 *
	 * @param string $context  OTP context.
	 * @param string $redirect Redirect target after success.
	 * @return string
	 */
	private function capture( $context, $redirect ) {
		ob_start();
		Barar_Atik_Plugin::instance()->frontend->render_form(
			$context,
			array(
				'mode'     => 'panel',
				'redirect' => $redirect,
			)
		);
		return (string) ob_get_clean();
	}

	/**
	 * Is signup allowed from checkout?
	 *
	 * @return bool
	 */
	private function registration_allowed() {
		return 'yes' === get_option( 'woocommerce_enable_myaccount_registration', 'no' )
			|| 'yes' === get_option( 'woocommerce_enable_signup_and_login_from_checkout', 'no' );
	}

	/**
	 * "Login without an email address": remove the native email/password login
	 * form so the phone panel is the only login at checkout.
	 *
	 * With the tabbed area on this is already handled by dropping the
	 * Username/Email tab, so it only applies to the stacked fallback above.
	 *
	 * @return void
	 */
	public function maybe_hide_native_login() {
		if ( '1' === Barar_Atik_Settings::get( 'checkout_auth_tabs', '1' ) ) {
			return;
		}
		if ( '1' !== Barar_Atik_Settings::get( 'checkout_login_no_email', '0' ) ) {
			return;
		}
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}
		remove_action( 'woocommerce_before_checkout_form', 'woocommerce_checkout_login_form', 10 );
	}

	/* ---------------------------------------------------------------------
	 * Fields
	 * ------------------------------------------------------------------ */

	/**
	 * Keep the "required" error readable for a field that has no label.
	 *
	 * @param string $notice Notice text.
	 * @param string $label  Field label.
	 * @return string
	 */
	public function required_notice( $notice, $label ) {
		if ( '' === trim( wp_strip_all_tags( (string) $label ) ) ) {
			return __( 'Please fill in all required fields.', 'barar-atik-sms-otp' );
		}
		return $notice;
	}

	/**
	 * Checkout labels/placeholders from the admin + the "order without an
	 * email address" switch (email optional, phone required).
	 *
	 * @param array $fields Checkout fields.
	 * @return array
	 */
	public function checkout_fields( $fields ) {
		$map = array(
			'billing_email'      => array( 'txt_wc_email_label', 'txt_wc_email_ph' ),
			'billing_phone'      => array( 'txt_wc_phone_label', 'txt_wc_phone_ph' ),
			'billing_first_name' => array( 'txt_wc_first_label', null ),
			'billing_last_name'  => array( 'txt_wc_last_label', null ),
		);

		foreach ( $map as $key => $pair ) {
			$label = (string) Barar_Atik_Settings::get( $pair[0], '' );
			$ph    = null === $pair[1] ? '' : (string) Barar_Atik_Settings::get( $pair[1], '' );

			foreach ( $fields as $set => $set_fields ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
				if ( ! isset( $fields[ $set ][ $key ] ) || ! is_array( $fields[ $set ][ $key ] ) ) {
					continue;
				}
				// No wording of its own: a label that was not typed in the
				// settings is not shown (WooCommerce prints none for '').
				$fields[ $set ][ $key ]['label'] = $label;
				if ( '' !== $ph ) {
					$fields[ $set ][ $key ]['placeholder'] = $ph;
				}
				break;
			}
		}

		if ( '1' === Barar_Atik_Settings::get( 'checkout_order_no_email', '0' ) ) {
			foreach ( $fields as $set => $set_fields ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
				if ( isset( $fields[ $set ]['billing_email'] ) && is_array( $fields[ $set ]['billing_email'] ) ) {
					$fields[ $set ]['billing_email']['required'] = false;
				}
				if ( isset( $fields[ $set ]['billing_phone'] ) && is_array( $fields[ $set ]['billing_phone'] ) ) {
					$fields[ $set ]['billing_phone']['required'] = true;
				}
			}
		}

		return $fields;
	}

	/**
	 * Never let WooCommerce create an account from a checkout that has no
	 * email address: wc_create_new_customer() rejects an empty address and
	 * would abort the order ("Invalid email address").
	 *
	 * When WooCommerce forces the account anyway (guest checkout disabled)
	 * the admin screen shows a warning instead.
	 *
	 * @param array $data Posted checkout data.
	 * @return array
	 */
	public function posted_data( $data ) {
		if ( '1' !== Barar_Atik_Settings::get( 'checkout_order_no_email', '0' ) ) {
			return $data;
		}
		if ( empty( $data['billing_email'] ) && ! empty( $data['createaccount'] ) ) {
			$data['createaccount'] = false;
		}
		return $data;
	}
}
