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
		$prepared = $san;
		// remove id from prepared to avoid inserting it
		unset( $prepared['id'] );

		// Build formats dynamically
		$formats = array_map( array( $this, 'value_to_format' ), array_values( $prepared ) );

		// Filter according to actual DB columns
		list( $filtered_prepared, $filtered_formats ) = $this->filter_prepared_and_formats_for_db( $prepared, $formats );

		$table = Db::competitions_table();

		if ( $id ) {
			// defensive update
			$update_data = $filtered_prepared;
			$update_formats = $filtered_formats;
			if ( empty( $update_data ) ) {
				// nothing to update
				return $id;
			}
			$where = array( 'id' => $id );
			$where_format = array( '%d' );
			$updated = $wpdb->update( $table, $update_data, $where, $update_formats, $where_format );
			if ( $updated === false ) {
				// log maybe
				return $id;
			}
			return $id;
		}

		// insert
		if ( empty( $filtered_prepared ) ) {
			return 0;
		}
		$inserted = $wpdb->insert( $table, $filtered_prepared, $filtered_formats );
		if ( false === $inserted ) {
			return 0;
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Defensive update method (keeps compatibility)
	 */
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

	/**
	 * Determine SQL format string for a PHP value
	 *
	 * @param mixed $v
	 * @return string
	 */
	private function value_to_format( $v ) {
		if ( is_int( $v ) ) {
			return '%d';
		}
		if ( is_float( $v ) || is_double( $v ) ) {
			return '%f';
		}
		// default to string
		return '%s';
	}

	/**
	 * Sanitize incoming data array for competitions.
	 * Extended to include organizer_*, venue_*, event_*, reg_*, weighin_* keys.
	 *
	 * @param array $data
	 * @return array
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
			'organizer_region',
			'organizer_email',
			'organizer_phone',
			// venue
			'venue_name',
			'venue_address1',
			'venue_address2',
			'venue_postcode',
			'venue_city',
			'venue_region',
			'venue_country',
			'venue_maps_url',
			'venue_access_info',
			// event
			'event_start_date',
			'event_end_date',
			'event_start_time',
			'event_end_time',
			// registration
			'reg_open_date',
			'reg_open_time',
			'reg_close_date',
			'reg_close_time',
			// weighin
			'weighin_date',
			'weighin_start_time',
			'weighin_end_time',
			'weighin_location_text',
		);

		$sanitized = array();
		foreach ( $data as $k => $v ) {
			if ( ! in_array( $k, $allowed, true ) ) {
				// ignore unknown keys (avoid blocking other modules)
				continue;
			}

			// specific sanitization
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

				case 'organizer_email':
					$sanitized[ $k ] = sanitize_email( $v );
					break;

				case 'organizer_phone':
					$sanitized[ $k ] = sanitize_text_field( $v );
					break;

				case 'venue_maps_url':
					$sanitized[ $k ] = esc_url_raw( $v );
					break;

				case 'venue_access_info':
				case 'weighin_location_text':
					$sanitized[ $k ] = sanitize_textarea_field( $v );
					break;

				case 'venue_country':
					$sanitized[ $k ] = strtoupper( substr( sanitize_text_field( $v ), 0, 2 ) );
					break;

				case 'event_start_date':
				case 'event_end_date':
				case 'reg_open_date':
				case 'reg_close_date':
				case 'weighin_date':
					// accept YYYY-MM-DD or empty
					$pat = '/^\d{4}-\d{2}-\d{2}$/';
					$sanitized[ $k ] = ( is_string( $v ) && preg_match( $pat, $v ) ) ? $v : null;
					break;

				case 'event_start_time':
				case 'event_end_time':
				case 'reg_open_time':
				case 'reg_close_time':
				case 'weighin_start_time':
				case 'weighin_end_time':
					// accept HH:MM or HH:MM:SS
					$pat = '/^\d{2}:\d{2}(:\d{2})?$/';
					$sanitized[ $k ] = ( is_string( $v ) && preg_match( $pat, $v ) ) ? $v : null;
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
	 * Build a WHERE clause from filters (very small, safe implementation).
	 */
	private function build_where( array $filters ) {
		global $wpdb;

		$where = array( '1=1' );

		if ( ! empty( $filters['view'] ) && 'all' !== $filters['view'] ) {
			if ( 'active' === $filters['view'] ) {
				$where[] = "deleted_at IS NULL";
			}
		}

		if ( ! empty( $filters['competition_ids'] ) && is_array( $filters['competition_ids'] ) ) {
			$ids = array_map( 'absint', $filters['competition_ids'] );
			if ( count( $ids ) ) {
				$where[] = 'id IN (' . implode( ',', $ids ) . ')';
			}
		}

		if ( ! empty( $filters['search'] ) ) {
			$search = esc_sql( like_escape( $filters['search'] ) );
			$where[] = "name LIKE '%{$search}%'";
		}

		$where_sql = 'WHERE ' . implode( ' AND ', $where );

		return $where_sql;
	}

	/**
	 * Filter prepared data and formats according to actual DB columns.
	 * This avoids inserting unknown columns when schema is out of date.
	 */
	private function filter_prepared_and_formats_for_db( array $prepared, array $formats ) {
		global $wpdb;

		$table = Db::competitions_table();

		// Cache columns per table name
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

	// ... (autres méthodes existantes du repository peuvent rester inchangées)
}
