<?php

namespace UFSC\Competitions\Repositories;

use UFSC\Competitions\Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CompetitionRepository {
	private static $table_columns_cache = array();

	public function __construct() {
		// constructor if needed
	}

	public function save( array $data ): int {
		global $wpdb;

		$san = $this->sanitize( $data );

		$id = isset( $san['id'] ) ? absint( $san['id'] ) : 0;
		$prepared = $san;
		unset( $prepared['id'] );

		$formats = array_map( array( $this, 'value_to_format' ), array_values( $prepared ) );

		list( $filtered_prepared, $filtered_formats ) = $this->filter_prepared_and_formats_for_db( $prepared, $formats );

		$table = Db::competitions_table();

		if ( $id ) {
			$update_data = $filtered_prepared;
			$update_formats = $filtered_formats;
			if ( empty( $update_data ) ) {
				return $id;
			}
			$where = array( 'id' => $id );
			$where_format = array( '%d' );
			$updated = $wpdb->update( $table, $update_data, $where, $update_formats, $where_format );
			if ( $updated === false ) {
				return $id;
			}
			return $id;
		}

		if ( empty( $filtered_prepared ) ) {
			return 0;
		}
		$inserted = $wpdb->insert( $table, $filtered_prepared, $filtered_formats );
		if ( false === $inserted ) {
			return 0;
		}
		return (int) $wpdb->insert_id;
	}

	public function update( $id, array $data ) {
		global $wpdb;
		$id = absint( $id );
		if ( ! $id ) {
			return false;
		}

		$san = $this->sanitize( $data );
		unset( $san['id'] );
		$formats = array_map( array( $this, 'value_to_format' ), array_values( $san ) );
		list( $filtered_prepared, $filtered_formats ) = $this->filter_prepared_and_formats_for_db( $san, $formats );

		if ( empty( $filtered_prepared ) ) {
			return false;
		}

		$table = Db::competitions_table();
		$where = array( 'id' => $id );
		$where_format = array( '%d' );

		return $wpdb->update( $table, $filtered_prepared, $where, $filtered_formats, $where_format );
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
	 * Accept only known keys to avoid blocking other modules.
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
			// datetimes (stored as 'Y-m-d H:i:s')
			'event_start_datetime',
			'event_end_datetime',
			'registration_open_datetime',
			'registration_close_datetime',
			'weighin_start_datetime',
			'weighin_end_datetime',
			// contact
			'contact_email',
			'contact_phone',
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
					// Accept datetime-local (YYYY-MM-DDTHH:MM or YYYY-MM-DD HH:MM:SS)
					$sanitized[ $k ] = $this->sanitize_datetime( $v );
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
	 * - 'YYYY-MM-DDTHH:MM' (datetime-local default)
	 * - 'YYYY-MM-DD HH:MM:SS'
	 * - 'YYYY-MM-DD HH:MM'
	 */
	private function sanitize_datetime( $value ) {
		if ( null === $value || '' === $value ) {
			return null;
		}
		$value = (string) $value;
		// replace T with space (from datetime-local)
		$value = str_replace( 'T', ' ', $value );

		// If seconds missing, append :00
		if ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value ) ) {
			$value .= ':00';
		}

		// Validate final format
		if ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value ) ) {
			// optional: further check with strtotime
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
