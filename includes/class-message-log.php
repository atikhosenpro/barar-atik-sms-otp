<?php
/**
 * Lightweight message log: one small table for messages this plugin sends,
 * plus TextBee messages synced into the Message Center.
 *
 * Statuses mirror the documented TextBee states:
 * pending, dispatched, sent, delivered, failed, unknown, received.
 *
 * @package Barar_Atik
 */

defined( 'ABSPATH' ) || exit;

/*
 * This class is the plugin's own storage layer: every statement targets the
 * single `{prefix}barar_atik_sms` table and all user-supplied values go
 * through $wpdb->prepare() placeholders.
 *
 * The table name cannot be a placeholder, so it is interpolated from
 * $wpdb->prefix, and these rows are read/written on every send, so object
 * caching would only add invalidation bugs.
 *
 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 * phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 */

class Barar_Atik_Message_Log {

	/**
	 * Full table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'barar_atik_sms';
	}

	/**
	 * Status rank used to avoid moving a message backwards.
	 *
	 * @return array
	 */
	public static function ranks() {
		return array(
			'pending'   => 0,
			'unknown'   => 1,
			'dispatched'=> 2,
			'sent'      => 3,
			'delivered' => 4,
			'failed'    => 5,
			'received'  => 5,
		);
	}

	/**
	 * Friendly badge labels.
	 *
	 * @return array
	 */
	public static function status_labels() {
		return array(
			'pending'    => __( 'Pending / Queued', 'barar-atik-sms-otp' ),
			'dispatched' => __( 'Sending', 'barar-atik-sms-otp' ),
			'sent'       => __( 'Sent', 'barar-atik-sms-otp' ),
			'delivered'  => __( 'Delivered', 'barar-atik-sms-otp' ),
			'failed'     => __( 'Failed', 'barar-atik-sms-otp' ),
			'unknown'    => __( 'Unknown', 'barar-atik-sms-otp' ),
			'received'   => __( 'Received', 'barar-atik-sms-otp' ),
		);
	}

	/**
	 * Create the table (activation).
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			dedup_key varchar(64) DEFAULT NULL,
			direction varchar(10) NOT NULL DEFAULT 'sent',
			status varchar(20) NOT NULL DEFAULT 'pending',
			source varchar(20) NOT NULL DEFAULT 'system',
			event varchar(40) NOT NULL DEFAULT '',
			ref_type varchar(20) NOT NULL DEFAULT '',
			ref_id bigint(20) unsigned NOT NULL DEFAULT 0,
			recipient varchar(64) NOT NULL DEFAULT '',
			message text,
			sms_batch_id varchar(64) NOT NULL DEFAULT '',
			textbee_id varchar(64) NOT NULL DEFAULT '',
			attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
			error text,
			requested_at datetime DEFAULT NULL,
			sent_at datetime DEFAULT NULL,
			delivered_at datetime DEFAULT NULL,
			failed_at datetime DEFAULT NULL,
			received_at datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY dedup_key (dedup_key),
			KEY textbee_id (textbee_id),
			KEY sms_batch_id (sms_batch_id),
			KEY status (status),
			KEY direction (direction),
			KEY created_at (created_at)
		) {$collate};";

		dbDelta( $sql );

		update_option( Barar_Atik_Settings::DB_FLAG, BARAR_ATIK_VERSION );
	}

	/**
	 * Delete log rows older than the retention window (daily cron).
	 *
	 * Every OTP sends a row, so without this the table only ever grows.
	 *
	 * @return void
	 */
	public static function prune() {
		global $wpdb;

		/**
		 * Filters how many days message log rows are kept (0 keeps everything).
		 *
		 * @param int $days Days.
		 */
		$days = (int) apply_filters( 'barar_atik_log_retention_days', 90 );
		if ( $days <= 0 ) {
			return;
		}

		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - ( $days * DAY_IN_SECONDS ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE created_at < %s LIMIT 1000', $cutoff ) );
	}

	/**
	 * Create the table when the plugin files were updated without re-activation.
	 *
	 * @return void
	 */
	public static function maybe_install() {
		if ( get_option( Barar_Atik_Settings::DB_FLAG ) === BARAR_ATIK_VERSION ) {
			return;
		}
		self::install();
	}

	/**
	 * Insert a row. Returns 0 when a duplicate dedup key already exists.
	 *
	 * @param array $row Row data.
	 * @return int New ID or 0.
	 */
	public function insert( $row ) {
		global $wpdb;

		$table = self::table();

		$defaults = array(
			'dedup_key'    => null,
			'direction'    => 'sent',
			'status'       => 'pending',
			'source'       => 'system',
			'event'        => '',
			'ref_type'     => '',
			'ref_id'       => 0,
			'recipient'    => '',
			'message'      => '',
			'sms_batch_id' => '',
			'textbee_id'   => '',
			'attempts'     => 0,
			'error'        => '',
			'requested_at' => null,
			'sent_at'      => null,
			'delivered_at' => null,
			'failed_at'    => null,
			'received_at'  => null,
		);
		$row = array_merge( $defaults, $row );

		$now = current_time( 'mysql' );
		$row['created_at'] = $now;
		$row['updated_at'] = $now;

		if ( ! empty( $row['dedup_key'] ) ) {
			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE dedup_key = %s", $row['dedup_key'] ) );
			if ( $exists ) {
				return 0;
			}
		} else {
			$row['dedup_key'] = null;
		}

		$result = $wpdb->insert( $table, $row );
		if ( false === $result ) {
			// Unique-key race: another request inserted the same dedup key first.
			if ( ! empty( $row['dedup_key'] ) ) {
				return 0;
			}
			return 0;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Fetch one row.
	 *
	 * @param int $id Row ID.
	 * @return array|null
	 */
	public function get( $id ) {
		global $wpdb;
		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A );
		return $row ? $row : null;
	}

	/**
	 * Find a row by its dedup key.
	 *
	 * @param string $key Dedup key.
	 * @return array|null
	 */
	public function by_dedup( $key ) {
		global $wpdb;
		$table = self::table();
		if ( '' === (string) $key ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE dedup_key = %s", $key ), ARRAY_A );
		return $row ? $row : null;
	}

	/**
	 * Find rows by TextBee batch id.
	 *
	 * @param string $batch Batch id.
	 * @return array
	 */
	public function by_batch( $batch ) {
		global $wpdb;
		$table = self::table();
		if ( '' === (string) $batch ) {
			return array();
		}
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE sms_batch_id = %s", $batch ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Find a row by its TextBee message id.
	 *
	 * @param string $textbee_id Message id.
	 * @return array|null
	 */
	public function by_textbee_id( $textbee_id ) {
		global $wpdb;
		$table = self::table();
		if ( '' === (string) $textbee_id ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE textbee_id = %s", $textbee_id ), ARRAY_A );
		return $row ? $row : null;
	}

	/**
	 * Overwrite fields directly (no status guard).
	 *
	 * @param int   $id    Row ID.
	 * @param array $field Field => value pairs.
	 * @return bool
	 */
	public function update( $id, $fields ) {
		global $wpdb;
		if ( ! $id || empty( $fields ) ) {
			return false;
		}
		$fields['updated_at'] = current_time( 'mysql' );
		$result = $wpdb->update(
			self::table(),
			$fields,
			array( 'id' => (int) $id ),
			null,
			array( '%d' )
		);
		return false !== $result;
	}

	/**
	 * Grouped status counts for the summary badges.
	 *
	 * @return array
	 */
	public function counts() {
		global $wpdb;
		$table = self::table();

		$out = array(
			'sent'      => 0,
			'delivered' => 0,
			'failed'    => 0,
			'pending'   => 0,
			'received'  => 0,
		);

		$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$table} GROUP BY status", ARRAY_A );
		foreach ( (array) $rows as $row ) {
			$status = (string) $row['status'];
			$total  = (int) $row['total'];

			if ( isset( $out[ $status ] ) ) {
				$out[ $status ] += $total;
			} else {
				// pending, dispatched, unknown and anything unexpected: still in flight.
				$out['pending'] += $total;
			}
		}

		return $out;
	}

	/**
	 * Query rows for the Message Center.
	 *
	 * @param array $args direction, status, search, paged, per_page.
	 * @return array{rows: array, total: int}
	 */
	public function query( $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'direction' => '',
				'status'    => '',
				'search'    => '',
				'paged'     => 1,
				'per_page'  => 20,
			)
		);

		$table  = self::table();
		$where  = array();
		$params = array();

		if ( in_array( $args['direction'], array( 'sent', 'received' ), true ) ) {
			$where[]  = 'direction = %s';
			$params[] = $args['direction'];
		}
		if ( $args['status'] && array_key_exists( $args['status'], self::status_labels() ) ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}
		$search = trim( (string) $args['search'] );
		if ( '' !== $search ) {
			$like    = '%' . $wpdb->esc_like( $search ) . '%';
			$where[] = '(recipient LIKE %s OR message LIKE %s OR sms_batch_id LIKE %s OR textbee_id LIKE %s OR error LIKE %s OR ref_id = %d)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = (int) $search;
		}

		/*
		 * $where_sql is assembled above from fixed column names and %s/%d
		 * placeholders only, and $params is filled in the same order, so the
		 * placeholder count always matches the arguments passed below.
		 *
		 * phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		 * phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		 */
		$where_sql = $where ? ' WHERE ' . implode( ' AND ', $where ) : '';

		if ( $params ) {
			$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table}{$where_sql}", $params ) );
		} else {
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		}

		$per_page = max( 5, min( 100, (int) $args['per_page'] ) );
		$paged    = max( 1, (int) $args['paged'] );
		$offset   = ( $paged - 1 ) * $per_page;

		$params[] = $per_page;
		$params[] = $offset;

		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table}{$where_sql} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d", $params ),
			ARRAY_A
		);
		/*
		 * phpcs:enable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		 * phpcs:enable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		 */

		return array(
			'rows'  => is_array( $rows ) ? $rows : array(),
			'total' => $total,
		);
	}

	/**
	 * Batch ids that still need a status check.
	 *
	 * @param int $limit Maximum batches.
	 * @return string[]
	 */
	public function pending_batches( $limit = 5 ) {
		global $wpdb;
		$table = self::table();
		$rows  = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT sms_batch_id FROM {$table} WHERE sms_batch_id <> '' AND status IN ('pending','dispatched') ORDER BY updated_at DESC LIMIT %d",
				max( 1, (int) $limit )
			)
		);
		return is_array( $rows ) ? array_filter( array_map( 'strval', $rows ) ) : array();
	}

	/**
	 * Rows with an open delivery question (for the local OTP/failure summaries).
	 *
	 * @param int    $limit Limit.
	 * @param string $status Optional status filter.
	 * @return array
	 */
	public function recent( $limit = 10, $status = '' ) {
		global $wpdb;
		$table = self::table();
		if ( $status ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY created_at DESC LIMIT %d", $status, (int) $limit ),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d", (int) $limit ),
				ARRAY_A
			);
		}
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Convert an ISO 8601 TextBee timestamp to site-local MySQL datetime.
	 *
	 * @param string $iso Timestamp.
	 * @return string|null
	 */
	public static function parse_time( $iso ) {
		$iso = trim( (string) $iso );
		if ( '' === $iso ) {
			return null;
		}
		$ts = strtotime( $iso );
		if ( false === $ts ) {
			return null;
		}
		return wp_date( 'Y-m-d H:i:s', $ts );
	}

	/**
	 * Insert or update a local row from a TextBee history/webhook message object.
	 *
	 * Never overwrites the stored message text of an existing row (keeps
	 * redacted OTP messages redacted).
	 *
	 * @param array $remote        Decoded TextBee message.
	 * @param array $extra         Optional: source, store_message, ref fields.
	 * @return string created|updated|ignored
	 */
	public function merge_remote( $remote, $extra = array() ) {
		if ( empty( $remote['_id'] ) && empty( $remote['smsId'] ) ) {
			return 'ignored';
		}

		$textbee_id = (string) ( ! empty( $remote['_id'] ) ? $remote['_id'] : $remote['smsId'] );
		$direction  = isset( $remote['direction'] ) && 'received' === $remote['direction'] ? 'received' : ( isset( $remote['sender'] ) ? 'received' : 'sent' );
		$status     = isset( $remote['status'] ) && is_string( $remote['status'] ) ? sanitize_key( $remote['status'] ) : '';
		$labels     = self::status_labels();
		if ( ! isset( $labels[ $status ] ) ) {
			// Unknown/legacy values map to unknown (never to delivered).
			$status = 'received' === $direction ? 'received' : 'unknown';
		}

		$recipient = (string) ( ! empty( $remote['recipient'] ) ? $remote['recipient'] : ( ! empty( $remote['sender'] ) ? $remote['sender'] : '' ) );
		$batch     = (string) ( ! empty( $remote['smsBatch'] ) ? $remote['smsBatch'] : ( ! empty( $remote['smsBatchId'] ) ? $remote['smsBatchId'] : '' ) );

		$timestamps = array(
			'requested_at' => self::parse_time( isset( $remote['requestedAt'] ) ? $remote['requestedAt'] : '' ),
			'sent_at'      => self::parse_time( isset( $remote['sentAt'] ) ? $remote['sentAt'] : '' ),
			'delivered_at' => self::parse_time( isset( $remote['deliveredAt'] ) ? $remote['deliveredAt'] : '' ),
			'failed_at'    => self::parse_time( isset( $remote['failedAt'] ) ? $remote['failedAt'] : '' ),
			'received_at'  => self::parse_time( isset( $remote['receivedAt'] ) ? $remote['receivedAt'] : '' ),
		);
		$timestamps = array_filter( $timestamps );

		$error = '';
		if ( ! empty( $remote['errorMessage'] ) ) {
			$error = (string) $remote['errorMessage'];
			if ( ! empty( $remote['errorCode'] ) ) {
				$error = '[' . $remote['errorCode'] . '] ' . $error;
			}
		}

		$row = $this->by_textbee_id( $textbee_id );

		if ( ! $row && $batch && 'sent' === $direction ) {
			$candidates = $this->by_batch( $batch );
			foreach ( $candidates as $candidate ) {
				if ( $candidate['textbee_id'] ) {
					continue;
				}
				if ( $recipient && $candidate['recipient'] !== $recipient ) {
					continue;
				}
				$row = $candidate;
				break;
			}
		}

		if ( $row ) {
			$fields = array();
			if ( empty( $row['textbee_id'] ) ) {
				$fields['textbee_id'] = $textbee_id;
			}
			if ( $error && empty( $row['error'] ) ) {
				$fields['error'] = $error;
			}
			if ( $batch && empty( $row['sms_batch_id'] ) ) {
				$fields['sms_batch_id'] = $batch;
			}
			foreach ( $timestamps as $key => $value ) {
				if ( empty( $row[ $key ] ) ) {
					$fields[ $key ] = $value;
				}
			}

			// Status with regression guard.
			$ranks   = self::ranks();
			$current = isset( $ranks[ $row['status'] ] ) ? $ranks[ $row['status'] ] : -1;
			$next    = isset( $ranks[ $status ] ) ? $ranks[ $status ] : -1;
			if ( $next >= $current && $status !== $row['status'] ) {
				$fields['status'] = $status;
			}

			if ( ! empty( $fields ) ) {
				$this->update( $row['id'], $fields );
			}
			return 'updated';
		}

		$store_message = array_key_exists( 'store_message', $extra ) ? (bool) $extra['store_message'] : true;
		$message       = $store_message && ! empty( $remote['message'] ) ? (string) $remote['message'] : '';

		$new_id = $this->insert(
			array(
				'dedup_key'    => 'tb:' . $textbee_id,
				'direction'    => $direction,
				'status'       => $status,
				'source'       => isset( $extra['source'] ) ? sanitize_key( $extra['source'] ) : 'sync',
				'event'        => isset( $extra['event'] ) ? sanitize_key( $extra['event'] ) : '',
				'ref_type'     => isset( $extra['ref_type'] ) ? sanitize_key( $extra['ref_type'] ) : '',
				'ref_id'       => isset( $extra['ref_id'] ) ? (int) $extra['ref_id'] : 0,
				'recipient'    => $recipient,
				'message'      => $message,
				'sms_batch_id' => $batch,
				'textbee_id'   => $textbee_id,
				'error'        => $error,
				'requested_at' => isset( $timestamps['requested_at'] ) ? $timestamps['requested_at'] : null,
				'sent_at'      => isset( $timestamps['sent_at'] ) ? $timestamps['sent_at'] : null,
				'delivered_at' => isset( $timestamps['delivered_at'] ) ? $timestamps['delivered_at'] : null,
				'failed_at'    => isset( $timestamps['failed_at'] ) ? $timestamps['failed_at'] : null,
				'received_at'  => isset( $timestamps['received_at'] ) ? $timestamps['received_at'] : null,
			)
		);

		return $new_id ? 'created' : 'ignored';
	}
}
