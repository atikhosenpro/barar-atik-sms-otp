<?php
/**
 * Single phone-number layer: normalization, validation, masking and user lookup.
 *
 * Every part of the plugin (WooCommerce SMS, OTP login, OTP registration,
 * test sends) goes through this class so numbers are treated identically.
 *
 * @package Barar_Atik
 */

defined( 'ABSPATH' ) || exit;

class Barar_Atik_Phone {

	/**
	 * Configured default country code (digits only, no "+").
	 *
	 * @return string
	 */
	public static function default_cc() {
		$cc = preg_replace( '/\D+/', '', (string) Barar_Atik_Settings::get( 'country_code', '880' ) );
		return '' !== $cc ? substr( $cc, 0, 4 ) : '880';
	}

	/**
	 * Normalize a phone number to international E.164 style ("+8801712345678").
	 *
	 * Handles 01XXXXXXXXX, 8801XXXXXXXXX, +8801XXXXXXXXX, 00880... and keeps
	 * already-correct international numbers intact.
	 *
	 * @param string $raw       Raw number.
	 * @param string $countrycc Optional country code override.
	 * @return string Normalized number or empty string when invalid.
	 */
	public static function normalize( $raw, $countrycc = '' ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return '';
		}

		$cc = preg_replace( '/\D+/', '', (string) $countrycc );
		$cc = '' !== $cc ? substr( $cc, 0, 4 ) : self::default_cc();

		$has_plus = ( 0 === strpos( $raw, '+' ) );
		$digits   = preg_replace( '/\D+/', '', $raw );
		if ( '' === $digits ) {
			return '';
		}

		if ( $has_plus ) {
			$number = $digits;
		} elseif ( 0 === strpos( $digits, '00' ) && strlen( $digits ) > 6 ) {
			$number = substr( $digits, 2 );
		} elseif ( '' !== $cc && 0 === strpos( $digits, $cc ) && strlen( $digits ) > strlen( $cc ) + 5 ) {
			$number = $digits;
		} elseif ( 0 === strpos( $digits, '0' ) ) {
			$number = $cc . ltrim( $digits, '0' );
		} elseif ( strlen( $digits ) >= 11 ) {
			$number = $digits;
		} else {
			$number = $cc . $digits;
		}

		return self::is_e164( '+' . $number ) ? '+' . $number : '';
	}

	/**
	 * Is this a plausible E.164 number?
	 *
	 * @param string $number Candidate.
	 * @return bool
	 */
	public static function is_e164( $number ) {
		return (bool) preg_match( '/^\+[1-9]\d{7,14}$/', (string) $number );
	}

	/**
	 * Validate an already-normalized number.
	 *
	 * @param string $number Candidate.
	 * @return bool
	 */
	public static function is_valid( $number ) {
		return self::is_e164( $number );
	}

	/**
	 * Mask a number for display in logs and diagnostics: +88017*****678.
	 *
	 * @param string $number Phone number.
	 * @return string
	 */
	public static function mask( $number ) {
		$number = (string) $number;
		$len    = strlen( $number );
		if ( $len <= 7 ) {
			return $len <= 3 ? str_repeat( '*', $len ) : $number;
		}
		$keep_start = 4;
		$keep_end   = 3;
		return substr( $number, 0, $keep_start )
			. str_repeat( '*', max( 3, $len - $keep_start - $keep_end ) )
			. substr( $number, -$keep_end );
	}

	/**
	 * Common written variants of a normalized number, used when matching
	 * existing stored values (017..., 88017..., +88017...).
	 *
	 * @param string $number Normalized number.
	 * @return string[]
	 */
	public static function variants( $number ) {
		$number = (string) $number;
		if ( '' === $number ) {
			return array();
		}
		$out    = array( $number );
		$digits = ltrim( $number, '+' );
		$out[]  = $digits;

		$cc = self::default_cc();
		if ( '' !== $cc && 0 === strpos( $digits, $cc ) ) {
			$national = substr( $digits, strlen( $cc ) );
			if ( '' !== $national ) {
				$out[] = '0' . $national;
				$out[] = '00' . $digits;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * User meta keys that may hold a phone number.
	 *
	 * @return string[]
	 */
	public static function meta_keys() {
		$keys = array(
			'barar_atik_phone', // Written by this plugin.
			'billing_phone',    // WooCommerce billing phone.
			'phone_number',     // Common third-party phone-login plugins.
			'phone',
		);
		return array_values( array_unique( array_map( 'strval', (array) apply_filters( 'barar_atik_phone_meta_keys', $keys ) ) ) );
	}

	/**
	 * Find user IDs whose stored phone number normalizes to the given number.
	 *
	 * Returns every match so callers can detect duplicates instead of
	 * silently picking one.
	 *
	 * @param string $number Normalized number.
	 * @return int[] User IDs.
	 */
	public static function find_user_ids( $number ) {
		$number = self::normalize( $number );
		if ( '' === $number ) {
			return array();
		}

		$keys     = self::meta_keys();
		$variants = self::variants( $number );
		$meta     = array( 'relation' => 'OR' );
		foreach ( $keys as $key ) {
			foreach ( $variants as $variant ) {
				$meta[] = array(
					'key'   => $key,
					'value' => $variant,
				);
			}
		}
		// Broad safety net for oddly formatted values; results are verified below.
		$tail = substr( ltrim( $number, '+' ), -7 );
		foreach ( array( 'barar_atik_phone', 'billing_phone' ) as $key ) {
			$meta[] = array(
				'key'     => $key,
				'value'   => $tail,
				'compare' => 'LIKE',
			);
		}

		$query = new WP_User_Query(
			array(
				'fields'     => 'ID',
				'number'     => 50,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded lookup (fields=ID, number=50) on indexed meta keys, used only to resolve a phone number to a user for OTP. Necessary for the lookup feature.
				'meta_query' => $meta,
			)
		);

		$found = array();
		foreach ( (array) $query->get_results() as $user_id ) {
			$user_id = (int) $user_id;
			if ( $user_id <= 0 || isset( $found[ $user_id ] ) ) {
				continue;
			}
			foreach ( $keys as $key ) {
				$stored = get_user_meta( $user_id, $key, true );
				if ( ! is_string( $stored ) || '' === $stored ) {
					continue;
				}
				if ( self::normalize( $stored ) === $number ) {
					$found[ $user_id ] = $user_id;
					break;
				}
			}
		}

		return array_values( $found );
	}

	/**
	 * Save the normalized phone for a user (plugin meta + WooCommerce billing phone).
	 *
	 * @param int    $user_id User ID.
	 * @param string $number  Normalized number.
	 * @return void
	 */
	public static function store_for_user( $user_id, $number ) {
		$user_id = (int) $user_id;
		$number  = self::normalize( $number );
		if ( $user_id <= 0 || '' === $number ) {
			return;
		}
		update_user_meta( $user_id, 'barar_atik_phone', $number );
		if ( function_exists( 'WC' ) ) {
			update_user_meta( $user_id, 'billing_phone', $number );
		}
	}
}
