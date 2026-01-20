<?php

namespace UFSC\Competitions\Repositories;

use UFSC\Competitions\Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CompetitionRepository {
	private static $table_columns_cache = array();

	public function __construct() {
		// noop
	}

	/**
	 * Save data: insert or update depending on id
	 *
	 * @param array $data
	 * @return int Inserted/updated ID
	 */
	public function save( array $data ): int {
		global $wpdb;

		$san = $this->sanitize( $data );

		$id = isset( $san['id'] ) ? absint( $san['id'] ) : 0;

		// auto timestamps and user info
		$now = current_time( 'mysql' );
		$user_id = get_current_user_id() ? get_current_user_id() : null;

		if ( $id ) {
			// update flow
			$san['updated_at'] = $now;
			if ( $user_id ) {
				$san['updated_by'] = $user_id;
			}
			unset( $san['id'] );
			$formats = array_map( array( $this, 'value_to_format' ), array_values( $san ) );
			list( $filtered_prepared, $filtered_formats ) = $this->filter_prepared_and_formats_for_db( $san, $formats );

			if ( empty( $filtered_prepared ) ) {
				return $id;
			}

			$table = Db::competitions_table();
			$where = array( 'id' => $id );
			$where_format = array( '%d' );

			$updated = $wpdb->update( $table, $filtered_prepared, $where, $filtered_formats, $where_format );
			if ( false === $updated ) {
				// log and return existing id
				error_log( 'UFSC CompetitionRepository: update failed: ' . $wpdb->last_error );
			}
			return $id;
		}

		// insert flow
		$san['created_at'] = $now;
		$san['updated_at'] = $now;
		if ( $user_id ) {
			$san['created_by'] = $user_id;
			$san['updated_by'] = $user_id;
		}

		unset( $san['id'] );
		$formats = array_map( array( $this, 'value_to_format' ), array_values( $san ) );
		list( $filtered_prepared, $filtered_formats ) = $this->filter_prepared_and_formats_for_db( $san, $formats );

		if ( empty( $filtered_prepared ) ) {
			return 0;
		}

		$table = Db::competitions_table();
		$inserted = $wpdb->insert( $table, $filtered_prepared, $filtered_formats );
		if ( false === $inserted ) {
			error_log( 'UFSC CompetitionRepository: insert failed: ' . $wpdb->last_error );
			return 0;
		}
		return (int) $wpdb->insert_id;
	}

	public function update( $id, array $data ) {
		return $this->save( array_merge( $data, array( 'id' => $id ) ) );
	}

	private function value_to_format( $v ) {
		if ( is_int( $v ) ) {
			return '%d';
		}
		if ( is_float( $v ) || is_double( $v ) ) {
			return '%f';
		}
		return '%s';
	}

	/**
	 * Sanitize incoming data array for competitions.
	 */
	public function sanitize( array $data ) {
		$allowed = array(
			'id',
			'name',
			'discipline',
			'type',
			'season',
			'status',
			'location',
			'registration_deadline',
			'age_reference',
			'weight_tolerance',
			'allowed_formats',
			'created_by',
			'updated_by',
			// organizer snapshot
			'organizer_club_id',
			'organizer_club_name',
			'organizer_region',
			// venue
			'venue_name',
			'venue_address1',
			'venue_address2',
			'venue_postcode',
			'venue_city',
			'venue_region',
			// datetimes
			'event_start_datetime',
			'event_end_datetime',
			'registration_open_datetime',
			'registration_close_datetime',
			'weighin_start_datetime',
			'weighin_end_datetime',
			// contact
			'contact_email',
			'contact_phone',
			// legacy
			'created_at',
			'updated_at',
			'deleted_at',
			'deleted_by',
		);

		$sanitized = array();
		foreach ( $data as $k => $v ) {
			if ( ! in_array( $k, $allowed, true ) ) {
				continue;
			}

			switch ( $k ) {
				case 'id':
				case 'organizer_club_id':
				case 'created_by':
				case 'updated_by':
				case 'deleted_by':
					$sanitized[ $k ] = absint( $v );
					break;

				case 'weight_tolerance':
					$sanitized[ $k ] = is_numeric( $v ) ? (float) $v : 0.0;
					break;

				case 'contact_email':
					$sanitized[ $k ] = sanitize_email( $v );
					break;

				case 'contact_phone':
					$sanitized[ $k ] = sanitize_text_field( $v );
					break;

				case 'event_start_datetime':
				case 'event_end_datetime':
				case 'registration_open_datetime':
				case 'registration_close_datetime':
				case 'weighin_start_datetime':
				case 'weighin_end_datetime':
					$sanitized[ $k ] = $this->sanitize_datetime( $v );
					break;

				case 'venue_maps_url':
					$sanitized[ $k ] = esc_url_raw( $v );
					break;

				default:
					if ( is_string( $v ) ) {
						$sanitized[ $k ] = sanitize_text_field( $v );
					} else {
						$sanitized[ $k ] = $v;
					}
					break;
			}
		}

		return $sanitized;
	}

	/**
	 * Normalize datetime inputs to 'Y-m-d H:i:s' or null.
	 *
	 * Accepts:
	 * - 'YYYY-MM-DDTHH:MM' (datetime-local)
	 * - 'YYYY-MM-DD HH:MM:SS'
	 */
	private function sanitize_datetime( $value ) {
		if ( null === $value || '' === $value ) {
			return null;
		}
		$value = (string) $value;
		$value = str_replace( 'T', ' ', $value );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value ) ) {
			$value .= ':00';
		}
		if ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value ) ) {
			$ts = strtotime( $value );
			if ( false !== $ts ) {
				return date( 'Y-m-d H:i:s', $ts );
			}
		}
		return null;
	}

	private function filter_prepared_and_formats_for_db( array $prepared, array $formats ) {
		global $wpdb;

		$table = Db::competitions_table();

		if ( ! isset( self::$table_columns_cache[ $table ] ) ) {
			$cols = $wpdb->get_col( "DESCRIBE {$table}", 0 );
			self::$table_columns_cache[ $table ] = is_array( $cols ) ? $cols : array();
		}

		$columns = array_flip( self::$table_columns_cache[ $table ] );

		$filtered_prepared = array();
		$filtered_formats = array();

		$keys = array_keys( $prepared );
		foreach ( $keys as $i => $k ) {
			if ( isset( $columns[ $k ] ) ) {
				$filtered_prepared[ $k ] = $prepared[ $k ];
				$filtered_formats[] = isset( $formats[ $i ] ) ? $formats[ $i ] : '%s';
			}
		}

		return array( $filtered_prepared, $filtered_formats );
	}
}
