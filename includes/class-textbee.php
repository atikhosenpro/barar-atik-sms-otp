<?php
/**
 * TextBee API client built on the WordPress HTTP API.
 *
 * Reference: https://textbee.dev/docs/sending-sms/sending-sms
 * Base URL : https://api.textbee.dev/api/v1
 * Auth     : x-api-key request header.
 *
 * The API key never leaves this class and is never written to logs.
 *
 * @package Barar_Atik
 */

defined( 'ABSPATH' ) || exit;

class Barar_Atik_TextBee {

	const BASE         = 'https://api.textbee.dev/api/v1';
	const DIAG_TRANSIENT = 'barar_atik_diag';

	/**
	 * Send one message to one recipient through POST /gateway/send-sms.
	 *
	 * @param string $recipient Normalized number (+8801...).
	 * @param string $message   Message text.
	 * @param string $device_id Optional device id override.
	 * @return array Standard response array.
	 */
	public function send_sms( $recipient, $message, $device_id = '' ) {
		$body = array(
			'recipients' => array( $recipient ),
			'message'    => (string) $message,
		);

		$device_id = trim( (string) ( $device_id ? $device_id : Barar_Atik_Settings::get( 'device_id', '' ) ) );
		if ( '' !== $device_id ) {
			$body['deviceId'] = $device_id;
		}

		$res = $this->request( 'POST', '/gateway/send-sms', $body );

		if ( isset( $res['data']['smsBatchId'] ) ) {
			$res['batch_id'] = (string) $res['data']['smsBatchId'];
		}
		if ( $res['ok'] ) {
			$res['queued'] = ! empty( $res['data']['success'] );
			if ( ! $res['queued'] ) {
				$res['error'] = $res['message'] ? $res['message'] : __( 'TextBee did not confirm the message.', 'barar-atik-sms-otp' );
			}
		}

		$this->record( 'send-sms', $res['ok'] && $res['queued'], $res['status'], $res['ok'] ? $res['message'] : $res['error'] );

		return $res;
	}

	/**
	 * Read message history through GET /gateway/messages.
	 *
	 * Supported query parameters (see https://textbee.dev/docs/receiving-sms/message-history):
	 * direction, status, deviceIds, smsBatchId, search, from, to, order, page, limit, cursor.
	 *
	 * @param array $query Query parameters.
	 * @return array Standard response array with data/meta.
	 */
	public function get_messages( $query = array() ) {
		$query = wp_parse_args(
			$query,
			array(
				'limit' => 25,
				'page'  => 1,
			)
		);

		$clean = array();
		foreach ( $query as $key => $value ) {
			if ( '' === $value || null === $value || false === $value ) {
				continue;
			}
			$clean[ $key ] = $value;
		}

		$res = $this->request( 'GET', '/gateway/messages', null, $clean );
		$this->record( 'messages', $res['ok'], $res['status'], $res['ok'] ? 'fetched history' : $res['error'] );

		return $res;
	}

	/**
	 * Cheap account check through GET /gateway/stats.
	 *
	 * @return array Standard response array.
	 */
	public function stats() {
		$res    = $this->request( 'GET', '/gateway/stats' );
		$totals = array();
		if ( $res['ok'] && isset( $res['data'] ) && is_array( $res['data'] ) ) {
			$totals = $res['data'];
		}
		$res['totals'] = $totals;
		$this->record( 'stats', $res['ok'], $res['status'], $res['ok'] ? 'connection checked' : $res['error'] );

		return $res;
	}

	/**
	 * Create a webhook subscription through POST /webhooks.
	 *
	 * @param string $name   Label.
	 * @param string $url    Delivery URL (our REST endpoint).
	 * @param string $secret Signing secret (>= 20 chars).
	 * @param array  $events Event names.
	 * @return array Standard response array.
	 */
	public function create_webhook( $name, $url, $secret, $events ) {
		$body = array(
			'name'          => substr( (string) $name, 0, 64 ),
			'deliveryUrl'   => esc_url_raw( $url ),
			'signingSecret' => (string) $secret,
			'events'        => array_values( array_filter( (array) $events ) ),
		);

		$res = $this->request( 'POST', '/webhooks', $body );
		$this->record( 'webhook', $res['ok'], $res['status'], $res['ok'] ? 'webhook subscription created' : $res['error'] );

		return $res;
	}

	/**
	 * Perform an API request.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $path   Path below /api/v1.
	 * @param array|null $body   JSON body.
	 * @param array      $query  Query string parameters.
	 * @return array Standard response array.
	 */
	private function request( $method, $path, $body = null, $query = array() ) {
		$api_key = trim( (string) Barar_Atik_Settings::get( 'api_key', '' ) );

		$out = array(
			'ok'       => false,
			'status'   => 0,
			'queued'   => false,
			'batch_id' => '',
			'message'  => '',
			'error'    => '',
			'retryable'=> false,
			'data'     => null,
			'meta'     => null,
		);

		if ( '' === $api_key ) {
			$out['error'] = __( 'No TextBee API key configured yet.', 'barar-atik-sms-otp' );
			return $out;
		}

		$url = self::BASE . $path;
		if ( ! empty( $query ) ) {
			$url .= ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
		}

		$args = array(
			'timeout'    => 'GET' === $method ? 30 : 20,
			'redirection' => 5,
			'headers'    => array(
				'x-api-key'     => $api_key,
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
				'Cache-Control' => 'no-store',
			),
			'sslverify'  => true,
		);

		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$started = microtime( true );
		if ( 'GET' === $method ) {
			$response = wp_remote_get( $url, $args );
		} else {
			$response = wp_remote_request( $url, array_merge( $args, array( 'method' => strtoupper( $method ) ) ) );
		}
		$elapsed = round( ( microtime( true ) - $started ) * 1000 );
		$out['elapsed_ms'] = $elapsed;

		if ( is_wp_error( $response ) ) {
			$out['error']     = sprintf(
				/* translators: %s: transport error details. */
				__( 'Could not reach the TextBee API: %s', 'barar-atik-sms-otp' ),
				$response->get_error_message()
			);
			$out['retryable'] = true;
			return $out;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = (string) wp_remote_retrieve_body( $response );
		$out['status'] = $status;

		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) && '' !== $raw ) {
			$decoded = null;
		}

		// Human readable, secret-free message from the documented response shapes.
		$api_message = '';
		if ( is_array( $decoded ) ) {
			if ( isset( $decoded['data']['message'] ) && is_string( $decoded['data']['message'] ) ) {
				$api_message = $decoded['data']['message'];
			} elseif ( isset( $decoded['message'] ) && is_string( $decoded['message'] ) ) {
				$api_message = $decoded['message'];
			} elseif ( isset( $decoded['error'] ) && is_string( $decoded['error'] ) ) {
				$api_message = $decoded['error'];
			}
		}
		$out['message'] = $api_message;

		if ( $status >= 200 && $status < 300 ) {
			$out['ok'] = true;
			if ( is_array( $decoded ) ) {
				$out['data'] = isset( $decoded['data'] ) && is_array( $decoded['data'] ) ? $decoded['data'] : $decoded;
				$out['meta'] = isset( $decoded['meta'] ) && is_array( $decoded['meta'] ) ? $decoded['meta'] : null;
			}
			return $out;
		}

		// Failure: build a safe, human readable explanation.
		switch ( $status ) {
			case 401:
				$out['error'] = __( 'TextBee rejected the API key (401 Unauthorized). Update the API key.', 'barar-atik-sms-otp' );
				break;
			case 400:
				$out['error'] = sprintf(
					/* translators: %s: API explanation. */
					__( 'TextBee rejected the request (400): %s', 'barar-atik-sms-otp' ),
					$api_message ? $api_message : __( 'invalid device, number or message.', 'barar-atik-sms-otp' )
				);
				break;
			case 404:
				$out['error'] = sprintf(
					/* translators: %s: API explanation. */
					__( 'TextBee could not find the resource (404): %s', 'barar-atik-sms-otp' ),
					$api_message ? $api_message : __( 'check the Device ID.', 'barar-atik-sms-otp' )
				);
				break;
			case 429:
				$out['error']    = sprintf(
					/* translators: %s: API explanation. */
					__( 'TextBee rate or quota limit reached (429): %s', 'barar-atik-sms-otp' ),
					$api_message ? $api_message : __( 'nothing was sent.', 'barar-atik-sms-otp' )
				);
				$out['retryable'] = true;
				break;
			default:
				$out['error'] = $api_message
					? sprintf(
						/* translators: 1: HTTP status, 2: API explanation. */
						__( 'TextBee API error (HTTP %1$s): %2$s', 'barar-atik-sms-otp' ),
						$status,
						$api_message
					)
					: sprintf(
						/* translators: %s: HTTP status. */
						__( 'Unexpected response from TextBee (HTTP %s).', 'barar-atik-sms-otp' ),
						$status
					);
				if ( $status >= 500 ) {
					$out['retryable'] = true;
				}
		}

		return $out;
	}

	/**
	 * Store the last API outcomes for the admin status cards (no secrets).
	 *
	 * @param string $action  Action label.
	 * @param bool   $ok      Success flag.
	 * @param int    $status  HTTP status.
	 * @param string $message Safe message.
	 * @return void
	 */
	private function record( $action, $ok, $status, $message ) {
		$diag = get_transient( self::DIAG_TRANSIENT );
		$diag = is_array( $diag ) ? $diag : array();

		$entry = array(
			'at'      => time(),
			'action'  => (string) $action,
			'status'  => (int) $status,
			'message' => self::safe_text( (string) $message ),
		);

		if ( $ok ) {
			$diag['last_success'] = $entry;
		} else {
			$diag['last_failure'] = $entry;
		}

		set_transient( self::DIAG_TRANSIENT, $diag, WEEK_IN_SECONDS );
	}

	/**
	 * Recorded diagnostics for the admin.
	 *
	 * @return array
	 */
	public static function diagnostics() {
		$diag = get_transient( self::DIAG_TRANSIENT );
		return is_array( $diag ) ? $diag : array();
	}

	/**
	 * Remove anything that could look like a credential from a message.
	 *
	 * @param string $text Text to clean.
	 * @return string
	 */
	public static function safe_text( $text ) {
		$text = wp_strip_all_tags( (string) $text );
		$key  = (string) Barar_Atik_Settings::get( 'api_key', '' );
		if ( '' !== $key && false !== strpos( $text, $key ) ) {
			$text = str_replace( $key, '[redacted]', $text );
		}
		return $text;
	}
}
