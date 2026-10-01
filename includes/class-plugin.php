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
	 * Boot the components.
	 */
	private function __construct() {
		add_action( 'init', array( 'Barar_Atik_Message_Log', 'maybe_install' ), 1 );

		$this->settings = new Barar_Atik_Settings();
		add_action( 'admin_init', array( $this->settings, 'register' ) );

		$this->log     = new Barar_Atik_Message_Log();
		$this->textbee = new Barar_Atik_TextBee();
		$this->sms     = new Barar_Atik_SMS( $this->textbee, $this->log );
		$this->otp     = new Barar_Atik_OTP( $this->sms );
		$this->wc      = new Barar_Atik_WooCommerce( $this->sms, $this->log );
		$this->webhook = new Barar_Atik_Webhook( $this->log );
		$this->frontend = new Barar_Atik_Frontend();

		// Bounded retries scheduled by the SMS pipeline (AS or WP-Cron).
		add_action( 'barar_atik_retry', array( $this->sms, 'run_retry' ) );

		if ( is_admin() ) {
			$this->admin = new Barar_Atik_Admin( $this );
		}
	}
}
