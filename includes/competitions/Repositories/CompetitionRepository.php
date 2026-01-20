<?php

namespace UFSC\Competitions\Repositories;

use UFSC\Competitions\Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CompetitionRepository
 *
 * Provides a backward-compatible repository API expected by admin pages/tables:
 * - get($id, $include_deleted = false)
 * - list(array $filters = [], $limit = 20, $offset = 0)
 * - count(array $filters = [])
 * - insert(array $data)
 * - update($id, array $data)
 * - trash($id)
 *
 * Internally uses save()/sanitize()/filter_prepared_and_formats_for_db for safety.
 */
class CompetitionRepository {
	private static $table_columns_cache = array();

	public function __construct() {
		// noop
	}

	/**
	 * Backwards compatible "list" method.
	 *
	 * @param array $filters
	 * @param int $limit
	 * @param int $offset
	 * @return array List of rows (objects)
	 */
	public function list( array $filters = array(), $limit = 20, $offset = 0 ) {
		global $wpdb;

		$table = Db::competitions_table();

		$where = $this->build_where( $filters );

		$order_by = 'event_start_datetime DESC';
		if ( ! empty( $filters['order_by'] ) ) {
			$order_by = esc_sql( $filters['order_by'] );
			if ( ! empty( $filters['order_dir'] ) && in_array( strtoupper( $filters['order_dir'] ), array( 'ASC', 'DESC' ), true ) ) {
				$order_by .= ' ' . strtoupper( $filters['order_dir'] );
			}
		}

		$limit = (int) $limit;
		$offset = (int) $offset;
		$sql = $wpdb->prepare( "SELECT * FROM {$table} {$where} ORDER BY {$order_by} LIMIT %d OFFSET %d", $limit, $offset );

		$rows = $wpdb->get_results( $sql );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Count rows matching filters.
	 *
	 * @param array $filters
	 * @return int
	 */
	public function count( array $filters = array() ) {
		global $wpdb;
		$table = Db::competitions_table();
		$where = $this->build_where( $filters );
		$sql = "SELECT COUNT(1) FROM {$table} {$where}";
		$count = $wpdb->get_var( $sql );
		return (int) $count;
	}

	/**
	 * Get a single competition by ID.
	 *
	 * @param int $id
	 * @param bool $include_deleted
	 * @return object|null
	 */
	public function get( $id, $include_deleted = false ) {
		global $wpdb;
		$id = absint( $id );
		if ( ! $id ) {
			return null;
		}
		$table = Db::competitions_table();
		$sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id );
		$row = $wpdb->get_row( $sql );
		if ( $row && ! $include_deleted ) {
			if ( isset( $row->deleted_at ) && ! empty( $row->deleted_at ) ) {
				return null;
			}
		}
		return $row;
	}

	/**
	 * Insert a new competition (wrapper around save).
	 *
	 * @param array $data
	 * @return int inserted ID or 0
	 */
	public function insert( array $data ) {
		// Ensure id not passed
		unset( $data['id'] );
		return $this->save( $data );
	}

	/**
	 * Update a competition by ID (wrapper around save).
	 *
	 * @param int $id
	 * @param array $data
	 * @return bool|int returns id on success, false on failure
	 */
	public function update( $id, array $data ) {
		$id = absint( $id );
		if ( ! $id ) {
			return false;
		}
		$data['id'] = $id;
		$result = $this->save( $data );
		return $result ? $id : false;
	}

	/**
	 * Move to trash (soft delete) by setting deleted_at and deleted_by.
	 *
	 * @param int $id
	 * @return bool
	 */
	public function trash( $id ) {
		global $wpdb;
		$id = absint( $id );
		if ( ! $id ) {
			return false;
		}

		$table = Db::competitions_table();
		$data = array(
			'deleted_at' => current_time( 'mysql' ),
			'deleted_by' => get_current_user_id() ? get_current_user_id() : null,
		);

		$formats = array( '%s', '%d' );
		$where = array( 'id' => $id );
		$where_format = array( '%d' );

		$updated = $wpdb->update( $table, $data, $where, $formats, $where_format );

		return $updated !== false;
	}

	/**
	 * Save (insert or update) a competition.
	 * Keeps existing save semantics: returns inserted id or existing id on update.
	 *
	 * @param array $data
	 * @return int
	 */
	public function save( array $data ): int {
		global $wpdb;

		$san = $this->sanitize( $data );

		$id = isset( $san['id'] ) ? absint( $san['id'] ) : 0;

		// auto timestamps and user info
		$now = current_time( 'mysql' );
		$user_id = get_current_user_id() ? get_current_user_id() : null;

		if ( $id ) {
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
			if ( $updated === false ) {
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

	/**
	 * Helper: determine SQL format string for a PHP value
	 */
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
	 * Sanitize incoming data for competitions (accepts new keys).
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
			'registration_deadline',
			'age_reference',
			'weight_tolerance',
			'allowed_formats',
			'created_by',
			'updated_by',
			'organizer_club_id',
			'organizer_club_name',
			'organizer_region',
			'venue_name',
			'venue_address1',
			'venue_address2',
			'venue_postcode',
			'venue_city',
			'venue_region',
			'event_start_datetime',
			'event_end_datetime',
			'registration_open_datetime',
			'registration_close_datetime',
			'weighin_start_datetime',
			'weighin_end_datetime',
			'contact_email',
			'contact_phone',
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

	/**
	 * Build WHERE clause from filters.
	 *
	 * Supported filters:
	 * - view: 'all'|'active' (deleted)
	 * - competition_ids (array)
	 * - search (string)
	 * - discipline, season, status
	 */
	private function build_where( array $filters ) {
		global $wpdb;

		$where = array( '1=1' );

		if ( ! empty( $filters['view'] ) && 'all' !== $filters['view'] ) {
			if ( 'active' === $filters['view'] ) {
				$where[] = 'deleted_at IS NULL';
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

		if ( ! empty( $filters['discipline'] ) ) {
			$discipline = esc_sql( $filters['discipline'] );
			$where[] = "discipline = '{$discipline}'";
		}

		if ( ! empty( $filters['season'] ) ) {
			$season = esc_sql( $filters['season'] );
			$where[] = "season = '{$season}'";
		}

		if ( ! empty( $filters['status'] ) ) {
			$status = esc_sql( $filters['status'] );
			$where[] = "status = '{$status}'";
		}

		$where_sql = 'WHERE ' . implode( ' AND ', $where );

		return $where_sql;
	}

	/**
	 * Filter prepared data and formats according to actual DB columns.
	 */
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
