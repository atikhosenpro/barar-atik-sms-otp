<?php
/**
 * Plugin Name:          Barar Atik - TextBee SMS & OTP
 * Plugin URI:           https://wordpress.org/plugins/barar-atik-sms-otp
 * Description:          Lightweight TextBee SMS gateway integration for WordPress and WooCommerce: secure API connection, WooCommerce order SMS automations, macros, message/status log, and phone-number OTP login/registration.
 * Version:              1.2.0
 * Requires at least:    6.0
 * Requires PHP:         7.4
 * Author:               Atik Hosen
 * License:              GPLv2 or later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          barar-atik-sms-otp
 * Domain Path:          /languages
 * WC requires at least: 7.0
 *
 * @package Barar_Atik
 */

defined( 'ABSPATH' ) || exit;

define( 'BARAR_ATIK_VERSION', '1.2.0' );
define( 'BARAR_ATIK_FILE', __FILE__ );
define( 'BARAR_ATIK_PATH', plugin_dir_path( __FILE__ ) );
define( 'BARAR_ATIK_URL', plugin_dir_url( __FILE__ ) );

/**
 * Simple PSR-0-ish autoloader for the Barar_Atik_* class prefix.
 *
 * Barar_Atik_Message_Log -> includes/class-message-log.php
 */
spl_autoload_register(
	function ( $class ) {
		$prefix = 'Barar_Atik_';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}
		$relative = strtolower( str_replace( '_', '-', substr( $class, strlen( $prefix ) ) ) );
		$file     = BARAR_ATIK_PATH . 'includes/class-' . $relative . '.php';
		if ( is_file( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( __FILE__, array( 'Barar_Atik_Message_Log', 'install' ) );

register_deactivation_hook(
	__FILE__,
	function () {
		// Cron retries scheduled by this plugin are harmless leftovers; clear ours only.
		wp_clear_scheduled_hook( 'barar_atik_retry' );
		wp_clear_scheduled_hook( 'barar_atik_prune' );
	}
);

/*
 * Declare HPOS (custom order tables) compatibility before WooCommerce initializes.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

add_action( 'plugins_loaded', array( 'Barar_Atik_Plugin', 'instance' ), 5 );
