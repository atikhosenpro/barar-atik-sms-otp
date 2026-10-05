<?php
/**
 * Frontend design: the admin-controlled stylesheet for the plugin's forms.
 *
 * Only values that differ from the defaults are printed, so a fresh install
 * outputs nothing and the plugin keeps looking exactly like it does today.
 *
 * @package Barar_Atik
 */

defined( 'ABSPATH' ) || exit;

class Barar_Atik_Design {

	/**
	 * Hook the printer.
	 */
	public function __construct() {
		// Frontend pages (also covers the shortcode/checkout forms).
		add_action( 'wp_head', array( $this, 'print_css' ), 30 );
		// wp-login.php does not run wp_head.
		add_action( 'login_enqueue_scripts', array( $this, 'print_css' ), 30 );
	}

	/**
	 * Print the generated stylesheet (nothing at all when the styles are
	 * disabled or every value is still the default).
	 *
	 * @return void
	 */
	public function print_css() {
		if ( Barar_Atik_Settings::styles_disabled() ) {
			return;
		}
		$css = $this->css();
		if ( '' === $css ) {
			return;
		}
		echo "\n<style id=\"barar-atik-design\" media=\"all\">" . $css . '</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from whitelisted colour/size values below.
	}

	/**
	 * Build the stylesheet.
	 *
	 * @return string
	 */
	public function css() {
		$defaults = Barar_Atik_Settings::defaults();
		$css      = '';

		/* Colours become custom properties consumed by assets/otp.css. */
		$vars = array();
		$map  = array(
			'design_primary' => '--barar-primary',
			'design_border'  => '--barar-border',
			'design_surface' => '--barar-surface',
			'design_label'   => '--barar-label',
		);
		foreach ( $map as $key => $var ) {
			$default = isset( $defaults[ $key ] ) ? (string) $defaults[ $key ] : '';
			$value   = strtoupper( (string) Barar_Atik_Settings::get( $key, $default ) );
			if ( '' !== $value && $value !== strtoupper( $default ) ) {
				$vars[ $var ] = $value;
			}
		}

		$sizes = array(
			'design_radius' => '--barar-radius',
			'design_font'   => '--barar-font',
			'design_width'  => '--barar-width',
		);
		foreach ( $sizes as $key => $var ) {
			$default = isset( $defaults[ $key ] ) ? (string) $defaults[ $key ] : '';
			$value   = (string) Barar_Atik_Settings::get( $key, $default );
			if ( '' !== $value && $value !== $default ) {
				$vars[ $var ] = absint( $value ) . 'px';
			}
		}

		if ( ! empty( $vars ) ) {
			$decl = '';
			foreach ( $vars as $var => $value ) {
				$decl .= $var . ':' . $value . ';';
			}
			$css .= '.barar-otp,.barar-form,.barar-pwd,.barar-checkout-otp,.barar-checkout-otp-wrap{' . $decl . '}';
		}

		/* Optional button colours (empty keeps the theme's buttons). */
		$button_bg   = (string) Barar_Atik_Settings::get( 'design_button_bg', '' );
		$button_text = (string) Barar_Atik_Settings::get( 'design_button_text', '' );
		if ( '' !== $button_bg ) {
			$rule = '.barar-otp .barar-otp__actions .button{background:' . $button_bg . ';border:1px solid ' . $button_bg . ';';
			if ( '' !== $button_text ) {
				$rule .= 'color:' . $button_text . ';';
			}
			$css .= $rule . '}';
		}

		return '' === $css ? '' : wp_strip_all_tags( $css );
	}
}
