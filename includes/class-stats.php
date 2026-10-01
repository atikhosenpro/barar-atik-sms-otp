<?php
/**
 * Lightweight local counters used by the admin summary cards.
 *
 * @package Barar_Atik
 */

defined( 'ABSPATH' ) || exit;

class Barar_Atik_Stats {

	const OPTION = 'barar_atik_stats';

	/**
	 * Base counters.
	 *
	 * @return array
	 */
	private static function base() {
		return array(
			'sms_queued'     => 0,
			'sms_failed'     => 0,
			'otp_sent'       => 0,
			'otp_send_fail'  => 0,
			'otp_verify_fail'=> 0,
			'otp_login'      => 0,
			'otp_register'   => 0,
		);
	}

	/**
	 * All counters.
	 *
	 * @return array
	 */
	public static function all() {
		$stored = get_option( self::OPTION, array() );
		return array_merge( self::base(), is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Read one counter.
	 *
	 * @param string $key Counter key.
	 * @return int
	 */
	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? (int) $all[ $key ] : 0;
	}

	/**
	 * Increment a counter by one.
	 *
	 * @param string $key Counter key.
	 * @return void
	 */
	public static function inc( $key ) {
		$all      = self::all();
		$all[ $key ] = isset( $all[ $key ] ) ? (int) $all[ $key ] + 1 : 1;
		update_option( self::OPTION, $all, false );
	}

	/**
	 * Reset all counters.
	 *
	 * @return bool
	 */
	public static function reset() {
		return update_option( self::OPTION, self::base(), false );
	}
}
