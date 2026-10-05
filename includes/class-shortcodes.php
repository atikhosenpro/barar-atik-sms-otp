<?php
/**
 * Shortcodes: print the plugin's login / registration forms anywhere.
 *
 * @package Barar_Atik
 */

defined( 'ABSPATH' ) || exit;

class Barar_Atik_Shortcodes {

	/**
	 * Registered tags, in the order they are listed in the admin.
	 *
	 * @var string[]
	 */
	const TAGS = array( 'barar_otp_login', 'barar_otp_register', 'barar_otp_auth' );

	/**
	 * Hook the shortcodes.
	 */
	public function __construct() {
		add_shortcode( 'barar_otp_login', array( $this, 'render_login' ) );
		add_shortcode( 'barar_otp_register', array( $this, 'render_register' ) );
		add_shortcode( 'barar_otp_auth', array( $this, 'render_auth' ) );
	}

	/**
	 * All known tags (used for the frontend asset detection).
	 *
	 * @return string[]
	 */
	public static function tags() {
		return self::TAGS;
	}

	/**
	 * Shortcode → description pairs for the admin "copy this" list.
	 *
	 * @return array[]
	 */
	public static function catalogue() {
		return array(
			array(
				'code' => '[barar_otp_login]',
				'desc' => __( 'Phone OTP login form (password login when it is enabled).', 'barar-atik-sms-otp' ),
			),
			array(
				'code' => '[barar_otp_register]',
				'desc' => __( 'Phone OTP registration form.', 'barar-atik-sms-otp' ),
			),
			array(
				'code' => '[barar_otp_auth]',
				'desc' => __( 'Login form and registration form on the same page.', 'barar-atik-sms-otp' ),
			),
			array(
				'code' => '[barar_otp_login context="woocommerce"]',
				'desc' => __( 'WooCommerce-flavoured login form (same fields as My Account).', 'barar-atik-sms-otp' ),
			),
			array(
				'code' => '[barar_otp_register redirect="/checkout/"]',
				'desc' => __( 'Registration form that returns to a chosen page after signing in.', 'barar-atik-sms-otp' ),
			),
		);
	}

	/**
	 * [barar_otp_login]
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function render_login( $atts ) {
		return $this->render_kinds( array( 'login' ), $atts, 'barar_otp_login' );
	}

	/**
	 * [barar_otp_register]
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function render_register( $atts ) {
		return $this->render_kinds( array( 'register' ), $atts, 'barar_otp_register' );
	}

	/**
	 * [barar_otp_auth] — login + registration on one page.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function render_auth( $atts ) {
		return $this->render_kinds( array( 'login', 'register' ), $atts, 'barar_otp_auth' );
	}

	/**
	 * Build the markup for one or both forms.
	 *
	 * @param string[] $kinds  login|register.
	 * @param array    $atts   Shortcode attributes (context, redirect, title).
	 * @param string   $tag    Called shortcode (for shortcode_atts()).
	 * @return string
	 */
	private function render_kinds( $kinds, $atts, $tag ) {
		$atts = shortcode_atts(
			array(
				'context'  => 'wp',
				'redirect' => '',
				'title'    => '',
			),
			(array) $atts,
			$tag
		);

		$type = ( 0 === strcasecmp( 'woocommerce', (string) $atts['context'] ) ) ? 'wc' : 'wp';

		$plugin = Barar_Atik_Plugin::instance();
		if ( ! $plugin->frontend ) {
			return '';
		}

		$out = '';

		if ( '' !== trim( (string) $atts['title'] ) ) {
			$out .= '<h3 class="barar-form__title">' . esc_html( $atts['title'] ) . '</h3>';
		}

		foreach ( $kinds as $kind ) {
			$context = $type . '_' . $kind;

			if ( 'login' === $kind ) {
				if ( ! Barar_Atik_Settings::context_enabled( $context ) ) {
					continue;
				}
			} else {
				// Registration must respect the WordPress/WooCommerce switches.
				$allowed = ( 'wp' === $type )
					? (bool) get_option( 'users_can_register' )
					: ( 'yes' === get_option( 'woocommerce_enable_myaccount_registration', 'no' ) || 'yes' === get_option( 'woocommerce_enable_signup_and_login_from_checkout', 'no' ) );
				if ( ! $allowed || ! Barar_Atik_Settings::context_enabled( $context ) ) {
					continue;
				}
			}

			ob_start();
			$plugin->frontend->render_form(
				$context,
				array(
					'mode'     => 'panel',
					'target'   => '',
					'redirect' => (string) $atts['redirect'],
				)
			);
			$body = (string) ob_get_clean();

			if ( '' === $body ) {
				continue;
			}

			$out .= '<div class="barar-form barar-form--' . esc_attr( $kind ) . '">' . $body . '</div>';
		}

		return '' === $out ? '' : '<div class="barar-otp-forms">' . $out . '</div>';
	}
}
