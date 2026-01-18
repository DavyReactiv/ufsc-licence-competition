<?php

namespace UFSC\Competitions\Repositories;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/../Services/LogService.php';

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
			$this->logger->log( 'error', 'competition', 0, 'Competition insert failed.', array( 'error' => $wpdb->last_error, 'data' => $filtered_prepared ) );
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'UFSC Competition insert failed: ' . $wpdb->last_error . ' — data: ' . print_r( $filtered_prepared, true ) );
			}
			return 0;
		}

		$id = (int) $wpdb->insert_id;

		$this->logger->log( 'create', 'competition', $id, 'Competition created.', array( 'data' => $filtered_prepared ) );

		return $id;
	}

	public function update( $id, array $data ) {
		global $wpdb;

		$prepared = $this->sanitize( $data );
		$prepared['updated_at'] = current_time( 'mysql' );
		$prepared['updated_by'] = get_current_user_id() ?: null;

		list( $filtered_prepared, $filtered_formats ) = $this->filter_prepared_and_formats_for_db( $prepared, $this->get_update_format() );

		if ( empty( $filtered_prepared ) ) {
			$this->logger->log( 'error', 'competition', $id, 'Competition update aborted: no valid columns after filtering.', array( 'data' => $prepared ) );
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'UFSC Competition update aborted (id ' . intval( $id ) . '): no valid columns after filtering — data: ' . print_r( $prepared, true ) );
			}
			return false;
		}

		$updated = $wpdb->update(
			Db::competitions_table(),
			$filtered_prepared,
			array( 'id' => absint( $id ) ),
			$filtered_formats,
			array( '%d' )
		);

		if ( false === $updated ) {
			$this->logger->log( 'error', 'competition', $id, 'Competition update failed.', array( 'error' => $wpdb->last_error, 'data' => $filtered_prepared ) );
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'UFSC Competition update failed (id ' . intval( $id ) . '): ' . $wpdb->last_error . ' — data: ' . print_r( $filtered_prepared, true ) );
			}
			return false;
		}

		$this->logger->log( 'update', 'competition', $id, 'Competition updated.', array( 'data' => $filtered_prepared ) );

		return $updated;
	}

	public function soft_delete( $id ) {
		return $this->set_deleted_at( $id, current_time( 'mysql' ), 'trash' );
	}

	public function restore( $id ) {
		return $this->set_deleted_at( $id, null, 'restore' );
	}

	public function archive( $id ) {
		global $wpdb;

		$updated = $wpdb->update(
			Db::competitions_table(),
			array(
				'status'     => 'archived',
				'updated_at' => current_time( 'mysql' ),
				'updated_by' => get_current_user_id() ?: null,
			),
			array( 'id' => absint( $id ) ),
			array( '%s', '%s', '%d' ),
			array( '%d' )
		);

		$this->logger->log( 'archive', 'competition', $id, 'Competition archived.', array() );

		return $updated;
	}

	public function delete( $id ) {
		global $wpdb;

		$deleted = $wpdb->delete( Db::competitions_table(), array( 'id' => absint( $id ) ), array( '%d' ) );

		$this->logger->log( 'delete', 'competition', $id, 'Competition deleted permanently.', array() );

		return $deleted;
	}

	public function update_status( $id, $status ) {
		global $wpdb;

		$status_options = array( 'draft', 'preparing', 'open', 'running', 'closed', 'archived' );
		$status = sanitize_key( $status );
		if ( ! in_array( $status, $status_options, true ) ) {
			return false;
		}

		$updated = $wpdb->update(
			Db::competitions_table(),
			array(
				'status'     => $status,
				'updated_at' => current_time( 'mysql' ),
				'updated_by' => get_current_user_id() ?: null,
			),
			array( 'id' => absint( $id ) ),
			array( '%s', '%s', '%d' ),
			array( '%d' )
		);

		$this->logger->log( 'status', 'competition', $id, 'Competition status updated.', array( 'status' => $status ) );

		return $updated;
	}

	private function set_deleted_at( $id, $deleted_at, $action ) {
		global $wpdb;

		$updated = $wpdb->update(
			Db::competitions_table(),
			array(
				'deleted_at' => $deleted_at,
				'updated_at' => current_time( 'mysql' ),
				'updated_by' => get_current_user_id() ?: null,
			),
			array( 'id' => absint( $id ) ),
			array( '%s', '%s', '%d' ),
			array( '%d' )
		);

		$this->logger->log( $action, 'competition', $id, 'Competition moved to ' . $action . '.', array() );

		return $updated;
	}

	/**
	 * Sanitize incoming data before insert/update.
	 *
	 * Returns associative array in the canonical order expected by get_insert_format.
	 */
	private function sanitize( $data ) {
		$status_options = array( 'draft', 'preparing', 'open', 'running', 'closed', 'archived' );

		$status = sanitize_text_field( $data['status'] ?? 'draft' );
		if ( ! in_array( $status, $status_options, true ) ) {
			$status = 'draft';
		}

		return array(
			'name'                 => sanitize_text_field( $data['name'] ?? '' ),
			'discipline'           => sanitize_text_field( $data['discipline'] ?? '' ),
			'type'                 => sanitize_text_field( $data['type'] ?? '' ),
			'season'               => sanitize_text_field( $data['season'] ?? '' ),
			'location'             => sanitize_text_field( $data['location'] ?? '' ),
			'start_date'           => $this->sanitize_date( $data['start_date'] ?? null ),
			'end_date'             => $this->sanitize_date( $data['end_date'] ?? null ),
			'registration_deadline'=> $this->sanitize_date( $data['registration_deadline'] ?? null ),
			'status'               => $status,
			'age_reference'        => sanitize_text_field( $data['age_reference'] ?? '12-31' ),
			'weight_tolerance'     => isset( $data['weight_tolerance'] ) ? (float) $data['weight_tolerance'] : 1.0,
			'allowed_formats'      => sanitize_text_field( $data['allowed_formats'] ?? '' ),
		);
	}

	private function sanitize_date( $value ) {
		$value = sanitize_text_field( (string) $value );
		if ( '' === $value ) {
			return null;
		}

		$date = date_create_from_format( 'Y-m-d', $value );
		if ( $date && $date->format( 'Y-m-d' ) === $value ) {
			return $value;
		}

		return null;
	}

	private function build_where( array $filters ) {
		global $wpdb;

		$where = array( '1=1' );

		$view = $filters['view'] ?? 'all';
		if ( 'trash' === $view ) {
			$where[] = 'deleted_at IS NOT NULL';
		} else {
			$where[] = 'deleted_at IS NULL';
		}

		if ( ! empty( $filters['status'] ) ) {
			$where[] = $wpdb->prepare( 'status = %s', sanitize_text_field( $filters['status'] ) );
		}

		if ( ! empty( $filters['discipline'] ) ) {
			$where[] = $wpdb->prepare( 'discipline = %s', sanitize_text_field( $filters['discipline'] ) );
		}

		if ( ! empty( $filters['search'] ) ) {
			$like = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
			$where[] = $wpdb->prepare( '(name LIKE %s OR location LIKE %s)', $like, $like );
		}

		return 'WHERE ' . implode( ' AND ', $where );
	}

	/**
	 * Format array for insert: order must follow sanitize() keys plus created/updated fields.
	 *
	 * Note: filter_prepared_and_formats_for_db will align and trim formats dynamically to match actual columns.
	 */
	private function get_insert_format() {
		return array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%d', '%d', '%s', '%s' );
	}

	/**
	 * Format array for update: must align with sanitize() returned keys order used during update.
	 */
	private function get_update_format() {
		// Update format corresponds to keys returned by sanitize() plus updated_at, updated_by when present.
		return array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%d' );
	}

	/**
	 * Return array of column names for competitions table (cached).
	 *
	 * @return string[]
	 */
	private function get_table_columns() {
		global $wpdb;

		$table = Db::competitions_table();
		if ( isset( self::$table_columns_cache[ $table ] ) ) {
			return self::$table_columns_cache[ $table ];
		}

		$cols = array();
		$rows = $wpdb->get_results( "DESCRIBE {$table}" );
		if ( is_array( $rows ) ) {
			foreach ( $rows as $r ) {
				if ( isset( $r->Field ) ) {
					$cols[] = $r->Field;
				}
			}
		}

		self::$table_columns_cache[ $table ] = $cols;
		return $cols;
	}

	/**
	 * Given a $prepared associative array and a formats array (ordered), remove keys that are not present
	 * in the actual DB table and return the filtered prepared array and corresponding formats preserving order.
	 *
	 * @param array $prepared assoc name => value
	 * @param array $formats indexed formats aligned with keys order expected
	 * @return array [ filtered_prepared_assoc, filtered_formats_indexed ]
	 */
	private function filter_prepared_and_formats_for_db( array $prepared, array $formats ) {
		$columns = $this->get_table_columns();
		$filtered_prepared = array();
		$filtered_formats = array();

		// Ensure formats mapping aligns with the $prepared keys order.
		$keys = array_keys( $prepared );
		foreach ( $keys as $i => $key ) {
			if ( in_array( $key, $columns, true ) ) {
				$filtered_prepared[ $key ] = $prepared[ $key ];
				// If formats array is shorter than keys, fallback to %s
				$fmt = isset( $formats[ $i ] ) ? $formats[ $i ] : '%s';
				$filtered_formats[] = $fmt;
			}
		}

		return array( $filtered_prepared, $filtered_formats );
	}
}
