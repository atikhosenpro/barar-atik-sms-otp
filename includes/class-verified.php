<?php
/**
 * Per-session memory of the phone numbers this plugin proved with a code.
 *
 * A code is verified in one request (the AJAX call from the form), while the
 * checkout that wants to act on it is a later, separate request. The
 * WooCommerce session is the one store present in both, so a verified number is
 * remembered there and the checkout can tell the difference between a number
 * that was proven and a number that was merely typed.
 *
 * Numbers are never stored, only an opaque stamp of each one.
 *
 * @package Barar_Atik
 */

defined( 'ABSPATH' ) || exit;

class Barar_Atik_Verified {

	/**
	 * WooCommerce session key holding the stamps.
	 */
	const SESSION_KEY = 'barar_atik_verified';

	/**
	 * How many numbers one session remembers (newest first).
	 */
	const MAX_ENTRIES = 8;

	/**
	 * Remember that a code was successfully verified for this number.
	 *
	 * @param string $number Phone number, in any written form.
	 * @return void
	 */
	public static function mark( $number ) {
		$number  = Barar_Atik_Phone::normalize( $number );
		$session = self::session();
		if ( '' === $number || ! $session ) {
			return;
		}

		$entries   = self::read( $session );
		$entries[] = self::stamp( $number );

		$session->set( self::SESSION_KEY, self::cap( $entries ) );
	}

	/**
	 * Was this number proven with a code in the current session?
	 *
	 * @param string $number Phone number, in any written form.
	 * @return bool
	 */
	public static function has( $number ) {
		$number  = Barar_Atik_Phone::normalize( $number );
		$session = self::session();
		if ( '' === $number || ! $session ) {
			return false;
		}

		return in_array( self::stamp( $number ), self::read( $session ), true );
	}

	/**
	 * The stored stamps, oldest entry dropped first, duplicates removed.
	 *
	 * @param array $entries Raw entries.
	 * @return array
	 */
	private static function cap( $entries ) {
		$out = array_values( array_unique( $entries ) );

		// array_reverse() so the newest stamp survives the cut.
		return array_reverse( array_slice( array_reverse( $out ), 0, self::MAX_ENTRIES ) );
	}

	/**
	 * Read the stamps of this session, keeping only well-formed ones.
	 *
	 * @param object $session WooCommerce session.
	 * @return array
	 */
	private static function read( $session ) {
		$stored = $session->get( self::SESSION_KEY );
		if ( ! is_array( $stored ) ) {
			return array();
		}

		$clean = array();
		foreach ( $stored as $entry ) {
			$entry = (string) $entry;
			if ( preg_match( '/^[a-f0-9]{32}$/', $entry ) ) {
				$clean[] = $entry;
			}
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * Opaque, non-reversible stamp of a number, safe to keep in a session.
	 *
	 * @param string $number Normalized number.
	 * @return string
	 */
	private static function stamp( $number ) {
		return substr( hash_hmac( 'sha256', $number, wp_salt( 'nonce' ) ), 0, 32 );
	}

	/**
	 * The WooCommerce session, when one is available.
	 *
	 * @return object|null
	 */
	private static function session() {
		if ( ! function_exists( 'WC' ) ) {
			return null;
		}

		$wc = WC();
		if ( ! is_object( $wc ) || ! isset( $wc->session ) || ! is_object( $wc->session ) ) {
			return null;
		}

		if ( ! method_exists( $wc->session, 'get' ) || ! method_exists( $wc->session, 'set' ) ) {
			return null;
		}

		return $wc->session;
	}
}