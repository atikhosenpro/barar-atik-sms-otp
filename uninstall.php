<?php
/**
 * Uninstall Barar Atik - TextBee SMS & OTP.
 *
 * @package Barar_Atik
 */

// Prevent direct access.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/*
 * This plugin stores data in two options and one custom table. When
 * deleting the plugin, this file removes those entries. If you wish to
 * preserve SMS logs or statistics, do NOT delete the plugin via the
 * WordPress dashboard in "delete" mode that runs uninstall.php.
 */

// Remove plugin options.
delete_option( 'barar_atik_settings' );
delete_option( 'barar_atik_stats' );
delete_option( 'barar_atik_db_version' );
delete_option( 'barar_atik_last_error' );

// Remove transients.
global $wpdb;

$barar_atik_transient_prefixes = array(
	'barar_atik_diag%',
	'barar_atik_last_sync%',
	'barar_atik_poll_lock%',
	'barar_atik_otp_',
	'barar_atik_cd_',
	'barar_atik_rt_',
	'barar_atik_wk_',
);

foreach ( $barar_atik_transient_prefixes as $barar_atik_transient_prefix ) {
	$barar_atik_transient_like = $barar_atik_transient_prefix . '%';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
			$barar_atik_transient_like
		)
	);
}

// Note: We do NOT drop the custom table {prefix}barar_atik_sms by default
// to preserve message history. If you need to remove it, you can do so
// via database management tools.
