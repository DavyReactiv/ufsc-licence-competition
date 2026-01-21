<?php

namespace UFSC\Competitions\Repositories;

use UFSC\Competitions\Db;
use UFSC\Competitions\Services\LogService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CompetitionRepository {

	use RepositoryHelpers;

	/** @var LogService */
	private $logger;

	private static $table_columns_cache = array();

	private $allowed_order_cols = array(
		'event_start_datetime',
		'event_end_datetime',
		'registration_open_datetime',
		'registration_close_datetime',
		'weighin_start_datetime',
		'weighin_end_datetime',
		'name',
		'discipline',
		'type',
		'season',
		'status',
		'updated_at',
		'created_at',
	);

	public function __construct() {
		$this->logger = new LogService();
	}

	/**
	 * ------------------------------------------------------------
	 * READ
	 * ------------------------------------------------------------
	 */

	public function get( $id, $include_deleted = false ) {
		global $wpdb;

		$id = absint( $id );
		if ( ! $id ) {
			return null;
		}

		$table = Db::competitions_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id ) );

		$this->maybe_log_db_error_safe( __METHOD__ . ':get' );

		if ( $row && ! $include_deleted && $this->is_deleted_row( $row ) ) {
			return null;
		}

		return $row ?: null;
	}

	public function list( array $filters = array(), $limit = 20, $offset = 0 ) {
		global $wpdb;

		$table  = Db::competitions_table();
		$where  = $this->build_where( $filters ); // prepared fragment or empty
		$limit  = max( 1, (int) $limit );
		$offset = max( 0, (int) $offset );

		$order_by = $this->build_order_by( $filters );

		$sql = $wpdb->prepare(
			"SELECT * FROM {$table} {$where} ORDER BY {$order_by} LIMIT %d OFFSET %d",
			$limit,
			$offset
		);

		$rows = $wpdb->get_results( $sql );

		$this->maybe_log_db_error_safe( __METHOD__ . ':list' );

		$this->debug_log(
			'CompetitionRepository::list executed.',
			array(
				'filters'    => $filters,
				'query'      => isset( $wpdb->last_query ) ? $wpdb->last_query : $sql,
				'last_error' => isset( $wpdb->last_error ) ? $wpdb->last_error : '',
				'rows'       => is_array( $rows ) ? count( $rows ) : 0,
			)
		);

		return is_array( $rows ) ? $rows : array();
	}

	public function count( array $filters = array() ) {
		global $wpdb;

		$table = Db::competitions_table();
		$where = $this->build_where( $filters );

		$sql = "SELECT COUNT(1) FROM {$table} {$where}";
		$val = $wpdb->get_var( $sql );

		$this->maybe_log_db_error_safe( __METHOD__ . ':count' );

		$this->debug_log(
			'CompetitionRepository::count executed.',
			array(
				'filters'    => $filters,
				'query'      => isset( $wpdb->last_query ) ? $wpdb->last_query : $sql,
				'last_error' => isset( $wpdb->last_error ) ? $wpdb->last_error : '',
				'count'      => (int) $val,
			)
		);

		return (int) $val;
	}

	/**
	 * ------------------------------------------------------------
	 * WRITE / CRUD
	 * ------------------------------------------------------------
	 */

	/**
	 * Save (create or update).
	 * Returns saved ID or 0 on failure.
	 *
	 * Expected minimal fields (others ignored safely):
	 * - id (optional)
	 * - name, discipline, type, season, status
	 * - event_start_datetime, event_end_datetime, registration_open_datetime, registration_close_datetime,
	 *   weighin_start_datetime, weighin_end_datetime
	 */
	public function save( array $data ) {
		global $wpdb;

		$table = Db::competitions_table();

		$data = $this->sanitize( $data );

		$id = ! empty( $data['id'] ) ? absint( $data['id'] ) : 0;
		unset( $data['id'] );

		$now = current_time( 'mysql' );

		// If your schema has these columns, they will be used if present.
		if ( $this->column_exists( 'updated_at' ) ) {
			$data['updated_at'] = $now;
		}

		if ( $id > 0 ) {
			$updated = $wpdb->update(
				$table,
				$data,
				array( 'id' => $id )
			);

			$this->maybe_log_db_error_safe( __METHOD__ . ':save:update' );

			return ( false === $updated ) ? 0 : $id;
		}

		if ( $this->column_exists( 'created_at' ) && ! isset( $data['created_at'] ) ) {
			$data['created_at'] = $now;
		}
		if ( $this->column_exists( 'deleted_at' ) && ! isset( $data['deleted_at'] ) ) {
			// New items should not be deleted
			$data['deleted_at'] = null;
		}

		$inserted = $wpdb->insert( $table, $data );

		$this->maybe_log_db_error_safe( __METHOD__ . ':save:insert' );

		if ( false === $inserted ) {
			return 0;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Soft delete: set deleted_at to now
	 */
	public function trash( $id ) {
		global $wpdb;

		$id = absint( $id );
		if ( ! $id ) {
			return false;
		}

		$table = Db::competitions_table();

		if ( ! $this->column_exists( 'deleted_at' ) ) {
			// If schema doesn't support soft delete, fallback to hard delete is too risky -> refuse
			return false;
		}

		$updated = $wpdb->update(
			$table,
			array( 'deleted_at' => current_time( 'mysql' ) ),
			array( 'id' => $id )
		);

		$this->maybe_log_db_error_safe( __METHOD__ . ':trash' );

		return ( false === $updated ) ? false : true;
	}

	/**
	 * Restore: set deleted_at to NULL
	 */
	public function restore( $id ) {
		global $wpdb;

		$id = absint( $id );
		if ( ! $id ) {
			return false;
		}

		$table = Db::competitions_table();

		if ( ! $this->column_exists( 'deleted_at' ) ) {
			return false;
		}

		$updated = $wpdb->update(
			$table,
			array( 'deleted_at' => null ),
			array( 'id' => $id )
		);

		$this->maybe_log_db_error_safe( __METHOD__ . ':restore' );

		return ( false === $updated ) ? false : true;
	}

	/**
	 * Hard delete
	 */
	public function delete( $id ) {
		global $wpdb;

		$id = absint( $id );
		if ( ! $id ) {
			return false;
		}

		$table = Db::competitions_table();

		$deleted = $wpdb->delete(
			$table,
			array( 'id' => $id )
		);

		$this->maybe_log_db_error_safe( __METHOD__ . ':delete' );

		return ( false === $deleted ) ? false : true;
	}

	/**
	 * ------------------------------------------------------------
	 * SANITIZE / WHERE / ORDER
	 * ------------------------------------------------------------
	 */

	private function sanitize( array $data ) {
		$out = array();

		// Keep only known/expected fields (safe)
		$whitelist = array(
			'id',
			'name',
			'discipline',
			'type',
			'season',
			'status',
			'event_start_datetime',
			'event_end_datetime',
			'registration_open_datetime',
			'registration_close_datetime',
			'weighin_start_datetime',
			'weighin_end_datetime',
		);

		foreach ( $whitelist as $key ) {
			if ( array_key_exists( $key, $data ) ) {
				$out[ $key ] = $data[ $key ];
			}
		}

		if ( isset( $out['id'] ) ) {
			$out['id'] = absint( $out['id'] );
		}

		foreach ( array( 'name', 'discipline', 'type', 'season', 'status' ) as $k ) {
			if ( isset( $out[ $k ] ) ) {
				$out[ $k ] = sanitize_text_field( wp_unslash( (string) $out[ $k ] ) );
			}
		}

		// Normalize datetime-like inputs (accept empty => null)
		$date_fields = array(
			'event_start_datetime',
			'event_end_datetime',
			'registration_open_datetime',
			'registration_close_datetime',
			'weighin_start_datetime',
			'weighin_end_datetime',
		);

		foreach ( $date_fields as $f ) {
			if ( ! array_key_exists( $f, $out ) ) {
				continue;
			}
			$val = trim( (string) wp_unslash( $out[ $f ] ) );
			if ( '' === $val ) {
				$out[ $f ] = null;
				continue;
			}

			// Common HTML input: 2026-01-21T18:30
			$val = str_replace( 'T', ' ', $val );

			// If user passed only date (YYYY-MM-DD), keep as date at midnight
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $val ) ) {
				$val .= ' 00:00:00';
			}

			// Minimal validation: if it doesn't look like mysql datetime, keep as-is but sanitized
			$out[ $f ] = sanitize_text_field( $val );
		}

		// Drop null id on create
		if ( isset( $out['id'] ) && 0 === (int) $out['id'] ) {
			unset( $out['id'] );
		}

		return $out;
	}

	private function build_where( array $filters ) {
		global $wpdb;

		$clauses = array();

		// --- View / trash logic ---
		$view     = isset( $filters['view'] ) ? sanitize_key( (string) $filters['view'] ) : '';
		$is_trash = ( 'trash' === $view );

		$zero_date = '0000-00-00 00:00:00';

		// If the column doesn't exist, do not filter by deleted_at
		if ( $this->column_exists( 'deleted_at' ) ) {
			if ( $is_trash ) {
				$clauses[] = $wpdb->prepare(
					"(deleted_at IS NOT NULL AND deleted_at != %s AND deleted_at != '')",
					$zero_date
				);
			} else {
				$clauses[] = $wpdb->prepare(
					"(deleted_at IS NULL OR deleted_at = %s OR deleted_at = '')",
					$zero_date
				);
			}
		}

		// --- Filters (only if provided) ---
		if ( ! empty( $filters['status'] ) ) {
			$status = sanitize_key( (string) $filters['status'] );

			// Soft-compat mapping (optional but very useful)
			if ( 'open' === $status ) {
				$clauses[] = $wpdb->prepare( "(status = %s OR status = %s)", 'open', 'active' );
			} else {
				$clauses[] = $wpdb->prepare( "status = %s", $status );
			}
		}

		if ( ! empty( $filters['discipline'] ) ) {
			$clauses[] = $wpdb->prepare( "discipline = %s", sanitize_text_field( (string) $filters['discipline'] ) );
		}

		if ( ! empty( $filters['type'] ) ) {
			$clauses[] = $wpdb->prepare( "type = %s", sanitize_text_field( (string) $filters['type'] ) );
		}

		if ( ! empty( $filters['season'] ) ) {
			$clauses[] = $wpdb->prepare( "season = %s", sanitize_text_field( (string) $filters['season'] ) );
		}

		// --- Search ---
		if ( ! empty( $filters['s'] ) ) {
			$s    = sanitize_text_field( (string) $filters['s'] );
			$like = '%' . $wpdb->esc_like( $s ) . '%';

			$clauses[] = $wpdb->prepare(
				"(name LIKE %s OR discipline LIKE %s OR type LIKE %s OR season LIKE %s OR status LIKE %s)",
				$like, $like, $like, $like, $like
			);
		}

		if ( empty( $clauses ) ) {
			return '';
		}

		return 'WHERE ' . implode( ' AND ', $clauses );
	}

	private function build_order_by( array $filters ) {
		$col = isset( $filters['order_by'] ) ? sanitize_key( (string) $filters['order_by'] ) : '';
		$dir = isset( $filters['order_dir'] ) ? strtoupper( sanitize_key( (string) $filters['order_dir'] ) ) : '';

		if ( empty( $col ) || ! in_array( $col, $this->allowed_order_cols, true ) ) {
			$col = 'event_start_datetime';
		}

		if ( 'ASC' !== $dir && 'DESC' !== $dir ) {
			$dir = 'DESC';
		}

		return $col . ' ' . $dir;
	}

	/**
	 * ------------------------------------------------------------
	 * INTERNAL HELPERS
	 * ------------------------------------------------------------
	 */

	private function is_deleted_row( $row ) {
		$val = isset( $row->deleted_at ) ? (string) $row->deleted_at : '';
		$val = trim( $val );

		if ( '' === $val ) {
			return false;
		}
		if ( '0000-00-00 00:00:00' === $val ) {
			return false;
		}
		// If not empty and not zero-date -> considered deleted
		return true;
	}

	private function debug_log( $message, array $context = array() ) {
		if ( ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
			return;
		}

		$line = $message . ' ' . wp_json_encode( $context );

		if ( class_exists( '\\UFSC_LC_Logger' ) ) {
			\UFSC_LC_Logger::log( $line );
			return;
		}

		error_log( $line );
	}

	/**
	 * Safe wrapper so this repo does not fatal if RepositoryHelpers differs.
	 */
	private function maybe_log_db_error_safe( $context ) {
		if ( method_exists( $this, 'maybe_log_db_error' ) ) {
			$this->maybe_log_db_error( $context );
			return;
		}

		// Fallback minimal logging in debug
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			global $wpdb;
			$err = isset( $wpdb->last_error ) ? (string) $wpdb->last_error : '';
			if ( $err ) {
				$this->debug_log( 'DB error: ' . $context, array( 'last_error' => $err ) );
			}
		}
	}

	private function column_exists( $column ) {
		$column = (string) $column;
		if ( '' === $column ) {
			return false;
		}

		$cols = $this->get_table_columns();
		return in_array( $column, $cols, true );
	}

	private function get_table_columns() {
		// Cache per request
		if ( ! empty( self::$table_columns_cache ) && is_array( self::$table_columns_cache ) ) {
			return self::$table_columns_cache;
		}

		global $wpdb;
		$table = Db::competitions_table();

		$cols = array();

		// DESCRIBE is safe here (admin usage); if it fails we fallback to empty list.
		$results = $wpdb->get_results( "DESCRIBE {$table}" );
		if ( is_array( $results ) ) {
			foreach ( $results as $r ) {
				if ( isset( $r->Field ) ) {
					$cols[] = (string) $r->Field;
				}
			}
		}

		self::$table_columns_cache = $cols;

		return $cols;
	}
}
