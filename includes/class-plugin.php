<?php
/**
 * Plugin bootstrap: wires every component together.
 *
 * @package Barar_Atik
 */

defined( 'ABSPATH' ) || exit;

final class Barar_Atik_Plugin {

	/**
	 * Singleton.
	 *
	 * @var Barar_Atik_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Settings registrar.
	 *
	 * @var Barar_Atik_Settings
	 */
	public $settings;

	/**
	 * TextBee API client.
	 *
	 * @var Barar_Atik_TextBee
	 */
	public $textbee;

	/**
	 * Message log.
	 *
	 * @var Barar_Atik_Message_Log
	 */
	public $log;

	/**
	 * SMS pipeline.
	 *
	 * @var Barar_Atik_SMS
	 */
	public $sms;

	/**
	 * OTP service.
	 *
	 * @var Barar_Atik_OTP
	 */
	public $otp;

	/**
	 * WooCommerce integration.
	 *
	 * @var Barar_Atik_WooCommerce
	 */
	public $wc;

	/**
	 * Webhook endpoint.
	 *
	 * @var Barar_Atik_Webhook
	 */
	public $webhook;

	/**
	 * Frontend forms.
	 *
	 * @var Barar_Atik_Frontend
	 */
	public $frontend;

	/**
	 * Login/registration shortcodes.
	 *
	 * @var Barar_Atik_Shortcodes
	 */
	public $shortcodes;

	/**
	 * Checkout integration.
	 *
	 * @var Barar_Atik_Checkout
	 */
	public $checkout;

	/**
	 * Checkout customer account created from a verified phone number.
	 *
	 * @var Barar_Atik_Checkout_Customer
	 */
	public $checkout_customer;

	/**
	 * Tabbed authentication area at checkout, plus the popup and content slots.
	 *
	 * @var Barar_Atik_Checkout_Auth
	 */
	public $checkout_auth;

	/**
	 * Guest verification: phone and email proven before Place Order works.
	 *
	 * @var Barar_Atik_Checkout_Verify
	 */
	public $checkout_verify;

	/**
	 * Frontend design (admin controlled styles).
	 *
	 * @var Barar_Atik_Design
	 */
	public $design;

	/**
	 * WooCommerce email → SMS mirror.
	 *
	 * @var Barar_Atik_Email_Mirror
	 */
	public $email_mirror;

	/**
	 * Admin.
	 *
	 * @var Barar_Atik_Admin|null
	 */
	public $admin = null;

	/**
	 * Get the singleton.
	 *
	 * @return Barar_Atik_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Version string for one of the plugin's own assets.
	 *
	 * BARAR_ATIK_VERSION only changes when a release is cut, so while a file
	 * is being worked on the URL keeps pointing at ?ver=1.1.0 and the browser
	 * - and every proxy in front of it - goes on serving the copy it cached
	 * under that URL. The checkout verification popup lives in otp.js, so a
	 * stale copy of that one file silently removes the entire feature while
	 * everything on the server still works, which is a very confusing failure
	 * to debug from the storefront.
	 *
	 * The file's own modification time gives a fresh URL whenever the asset
	 * really changes, and the release constant remains as the fallback for
	 * the case where the file cannot be read (hardened hosts, missing perms).
	 *
	 * @param string $relative Path relative to the plugin root.
	 * @return string
	 */
	public static function asset_version( $relative ) {
		$path = BARAR_ATIK_PATH . $relative;
		if ( is_file( $path ) ) {
			$mtime = filemtime( $path );
			if ( $mtime ) {
				return (string) $mtime;
			}
		}
		return BARAR_ATIK_VERSION;
	}

	/**
	 * Boot the components.
	 */
	private function __construct() {
		add_action( 'init', array( 'Barar_Atik_Message_Log', 'maybe_install' ), 1 );

		foreach ( array( 'add_option_', 'update_option_', 'delete_option_' ) as $prefix ) {
			add_action( $prefix . Barar_Atik_Settings::OPTION, array( 'Barar_Atik_Settings', 'flush' ) );
		}

		$this->settings = new Barar_Atik_Settings();
		add_action( 'admin_init', array( $this->settings, 'register' ) );

		$this->log     = new Barar_Atik_Message_Log();
		$this->textbee = new Barar_Atik_TextBee();
		$this->sms     = new Barar_Atik_SMS( $this->textbee, $this->log );
		$this->otp     = new Barar_Atik_OTP( $this->sms );
		$this->wc      = new Barar_Atik_WooCommerce( $this->sms, $this->log );
		$this->webhook = new Barar_Atik_Webhook( $this->log );
		$this->frontend = new Barar_Atik_Frontend();

		// Additive integrations: shortcodes, checkout panels, design styles and
		// the optional email→SMS mirror. They hook new actions only.
		$this->shortcodes   = new Barar_Atik_Shortcodes();
		$this->checkout     = new Barar_Atik_Checkout();
		$this->design       = new Barar_Atik_Design();
		$this->email_mirror = new Barar_Atik_Email_Mirror();

		// Phone-first checkout: opt-in, hooks the classic checkout only.
		$this->checkout_customer = new Barar_Atik_Checkout_Customer();

		// The "enable login during checkout" area as switchable tabs + popup.
		$this->checkout_auth = new Barar_Atik_Checkout_Auth();

		// Guest contact verification, enforced server side on the order itself.
		$this->checkout_verify = new Barar_Atik_Checkout_Verify( $this->otp, $this->sms );

		// Bounded retries scheduled by the SMS pipeline (AS or WP-Cron).
		add_action( 'barar_atik_retry', array( $this->sms, 'run_retry' ) );

		// Daily housekeeping of the message log.
		add_action( 'barar_atik_prune', array( 'Barar_Atik_Message_Log', 'prune' ) );
		add_action(
			'init',
			static function () {
				if ( ! wp_next_scheduled( 'barar_atik_prune' ) ) {
					wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'barar_atik_prune' );
				}
			}
		);

		if ( is_admin() ) {
			$this->admin = new Barar_Atik_Admin( $this );
		}
	}
}
