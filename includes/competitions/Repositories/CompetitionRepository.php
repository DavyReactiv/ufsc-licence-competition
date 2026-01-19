<?php

namespace UFSC\Competitions\Repositories;

use UFSC\Competitions\Db;
use UFSC\Competitions\Services\LogService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/../Services/LogService.php';

/**
 * CompetitionRepository
 *
 * Minimal, defensive repository implementation compatible with existing EntryRepository style.
 */
class CompetitionRepository {
	private $logger;

	/**
	 * Cache of table columns per table name to avoid repeated DESCRIBE queries.
	 * @var array<string, string[]>
	 */
	private static $table_columns_cache = array();

	public function __construct() {
		$this->logger = new LogService();
	}

	public function get( $id, $include_deleted = false ) {
		global $wpdb;

		$where_deleted = $include_deleted ? '' : 'AND deleted_at IS NULL';

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM " . Db::competitions_table() . " WHERE id = %d {$where_deleted}",
				absint( $id )
			)
		);
	}

	public function count( array $filters ) {
		global $wpdb;

		$where = $this->build_where( $filters );

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . Db::competitions_table() . " {$where}" );
	}

	public function list( array $filters, $limit, $offset ) {
		global $wpdb;

		$where = $this->build_where( $filters );
		$sql   = "SELECT * FROM " . Db::competitions_table() . " {$where} ORDER BY start_date DESC, id DESC";
		$sql  .= $wpdb->prepare( ' LIMIT %d OFFSET %d', absint( $limit ), absint( $offset ) );

		return $wpdb->get_results( $sql );
	}

	/**
	 * Insert a competition row.
	 *
	 * Uses dynamic filtering of fields to avoid inserting columns that do not exist in the DB.
	 */
	public function insert( array $data ) {
		global $wpdb;

		$prepared = $this->sanitize( $data );

		/*
		 * Order of keys must match get_insert_format():
		 * name, discipline, type, season, location, start_date, end_date,
		 * registration_deadline, status, age_reference, weight_tolerance,
		 * allowed_formats, created_by, updated_by, created_at, updated_at
		 */
		$prepared['created_by'] = get_current_user_id() ?: null;
		$prepared['updated_by'] = get_current_user_id() ?: null;
		$prepared['created_at'] = current_time( 'mysql' );
		$prepared['updated_at'] = current_time( 'mysql' );

		// Align prepared fields with actual table columns and formats (safe fallback if table schema is out-of-date).
		list( $filtered_prepared, $filtered_formats ) = $this->filter_prepared_and_formats_for_db( $prepared, $this->get_insert_format() );

		if ( empty( $filtered_prepared ) ) {
			$this->logger->log( 'error', 'competition', 0, 'Competition insert failed: no valid columns after filtering.', array( 'data' => $prepared ) );
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'UFSC Competition insert aborted: no valid columns after filtering — data: ' . print_r( $prepared, true ) );
			}
			return 0;
		}

		$result = $wpdb->insert( Db::competitions_table(), $filtered_prepared, $filtered_formats );

		if ( false === $result ) {
			$this->logger->log( 'error', 'competition', 0, 'Competition insert failed: db insert returned false.', array( 'data' => $filtered_prepared, 'formats' => $filtered_formats, 'wpdb_error' => $wpdb->last_error ) );
			return 0;
		}

		return (int) $wpdb->insert_id;
	}

	public function update( $id, array $data ) {
		global $wpdb;

		$prepared = $this->sanitize( $data );
		$prepared['updated_at'] = current_time( 'mysql' );
		$prepared['updated_by'] = get_current_user_id() ?: null;

		$updated = $wpdb->update(
			Db::competitions_table(),
			$prepared,
			array( 'id' => absint( $id ) ),
			$this->get_update_format(),
			array( '%d' )
		);

		$this->logger->log( 'update', 'competition', $id, 'Competition updated.', array( 'data' => $prepared ) );

		return $updated;
	}

	public function trash( $id ) {
		global $wpdb;

		$updated = $wpdb->update(
			Db::competitions_table(),
			array(
				'deleted_at' => current_time( 'mysql' ),
				'deleted_by' => get_current_user_id() ?: null,
			),
			array( 'id' => absint( $id ) ),
			array( '%s', '%d' ),
			array( '%d' )
		);

		$this->logger->log( 'trash', 'competition', $id, 'Competition trashed.' );

		return $updated;
	}

	/**
	 * Sanitize input array: keep only allowed keys and basic sanitization.
	 */
	private function sanitize( array $data ) {
		$allowed = array(
			'name', 'discipline', 'type', 'season', 'location',
			'start_date', 'end_date', 'registration_deadline', 'status',
			'age_reference', 'weight_tolerance', 'allowed_formats',
			'created_by', 'updated_by', 'created_at', 'updated_at',
		);

		$sanitized = array();
		foreach ( $allowed as $key ) {
			if ( array_key_exists( $key, $data ) ) {
				$value = $data[ $key ];
				// Very conservative sanitization:
				if ( is_string( $value ) ) {
					$sanitized[ $key ] = sanitize_text_field( $value );
				} else {
					$sanitized[ $key ] = $value;
				}
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

	/**
	 * Insert formats order for insert()
	 */
	private function get_insert_format() {
		return array(
			'%s', // name
			'%s', // discipline
			'%s', // type
			'%s', // season
			'%s', // location
			'%s', // start_date
			'%s', // end_date
			'%s', // registration_deadline
			'%s', // status
			'%s', // age_reference
			'%f', // weight_tolerance
			'%s', // allowed_formats
			'%d', // created_by
			'%d', // updated_by
			'%s', // created_at
			'%s', // updated_at
		);
	}

	/**
	 * Update formats fallback
	 */
	private function get_update_format() {
		// We return an array of formats for values in $prepared (used by $wpdb->update).
		// When $wpdb->update is invoked with an array of formats, it uses them.
		// Use safe defaults.
		return array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%d', '%d', '%s', '%s' );
	}
}
