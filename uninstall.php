<?php
/**
 * Uninstall Barar Atik - TextBee SMS & OTP.
 *
 * Removes the plugin options and every transient it created. The message log
 * table ({prefix}barar_atik_sms) is kept on purpose so SMS history survives a
 * reinstall; drop it from your database tool if you do not need it.
 *
 * @package Barar_Atik
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

delete_option( 'barar_atik_settings' );
delete_option( 'barar_atik_stats' );
delete_option( 'barar_atik_db_version' );
delete_option( 'barar_atik_last_error' );

wp_clear_scheduled_hook( 'barar_atik_retry' );
wp_clear_scheduled_hook( 'barar_atik_prune' );

// Transients are stored as _transient_{name} and _transient_timeout_{name}.
foreach ( array( '_transient_barar_atik_', '_transient_timeout_barar_atik_' ) as $barar_atik_prefix ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like( $barar_atik_prefix ) . '%'
		)
	);
}
