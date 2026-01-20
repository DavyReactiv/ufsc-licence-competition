<?php

namespace UFSC\Competitions\Repositories;

use UFSC\Competitions\Db;
use UFSC\Competitions\Services\LogService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CompetitionRepository
 *
 * Minimal, defensive repository for competitions.
 * - Uses RepositoryHelpers::maybe_log_db_error for conditional DB error logging.
 * - build_order_by uses an allowlist to avoid ORDER BY injection.
 * - set_deleted_at writes explicit NULL when restoring.
 */
class CompetitionRepository {

	use RepositoryHelpers;

	/** @var LogService */
	private $logger;

	/** @var string[] cached table columns */
	private static $table_columns_cache = array();

	/**
	 * Allowed columns for ORDER BY (safety).
	 * Must match real DB column names in competitions table.
	 *
	 * @var string[]
	 */
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
	 * Optional helper: check if a row exists (even deleted if include_deleted true).
	 */
	public function exists( $id, $include_deleted = true ): bool {
		return (bool) $this->get( $id, (bool) $include_deleted );
	}

	public function get( $id, $include_deleted = false ) {
		global $wpdb;

		$id = absint( $id );
		if ( ! $id ) {
			return null;
		}

		$table = Db::competitions_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id ) );

		$this->maybe_log_db_error( __METHOD__ . ':get' );

		if ( $row && ! $include_deleted && ! empty( $row->deleted_at ) ) {
			return null;
		}

		return $row ?: null;
	}

	/**
	 * List competitions with filters, pagination.
	 *
	 * @param array $filters
	 * @param int   $limit
	 * @param int   $offset
	 * @return array
	 */
	public function list( array $filters = array(), $limit = 20, $offset = 0 ) {
		global $wpdb;

		$table  = Db::competitions_table();
		$where  = $this->build_where( $filters );
		$limit  = max( 1, (int) $limit );
		$offset = max( 0, (int) $offset );

		$order_by = $this->build_order_by( $filters );

		$sql = $wpdb->prepare(
			"SELECT * FROM {$table} {$where} ORDER BY {$order_by} LIMIT %d OFFSET %d",
			$limit,
			$offset
		);

		$rows = $wpdb->get_results( $sql );

		$this->maybe_log_db_error( __METHOD__ . ':list' );

		return is_array( $rows ) ? $rows : array();
	}

	public function count( array $filters = array() ) {
		global $wpdb;

		$table = Db::competitions_table();
		$where = $this->build_where( $filters );

		$sql = "SELECT COUNT(1) FROM {$table} {$where}";
		$val = $wpdb->get_var( $sql );

		$this->maybe_log_db_error( __METHOD__ . ':count' );

		return (int) $val;
	}

	public function insert( array $data ) {
		unset( $data['id'] );
		return $this->save( $data );
	}

	public function update( $id, array $data ) {
		$id = absint( $id );
		if ( ! $id ) {
			return false;
		}
		$data['id'] = $id;

		$saved_id = $this->save( $data );
		return $saved_id ? $id : false;
	}

	public function trash( $id ) {
		return $this->set_deleted_at( $id, current_time( 'mysql' ), 'trash' );
	}

	public function restore( $id ) {
		return $this->set_deleted_at( $id, null, 'restore' );
	}

	public function delete( $id ) {
		global $wpdb;

		$id = absint( $id );
		if ( ! $id ) {
			return false;
		}

		$table = Db::competitions_table();

		$deleted = $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );

		$this->maybe_log_db_error( __METHOD__ . ':delete' );

		if ( false === $deleted ) {
			return false;
		}

		$this->logger->log( 'delete', 'competition', $id, 'Competition deleted.' );

		return (bool) $deleted;
	}

	/**
	 * Save (insert or update) a competition.
	 *
	 * @param array $data
	 * @return int|false Inserted id or false on failure
	 */
	public function save( array $data ) {
		global $wpdb;

		$id = isset( $data['id'] ) ? absint( $data['id'] ) : 0;
		$prepared = $this->sanitize( $data );

		// Fill timestamps and user ids
		$now = current_time( 'mysql' );
		if ( $id ) {
			$prepared['updated_at'] = $now;
			$prepared['updated_by'] = get_current_user_id() ?: null;

			$update_formats = $this->get_update_format( $prepared );
			$updated = $wpdb->update(
				Db::competitions_table(),
				$prepared,
				array( 'id' => $id ),
				$update_formats,
				array( '%d' )
			);

			$this->maybe_log_db_error( __METHOD__ . ':save:update' );

			if ( false === $updated ) {
				return false;
			}

			$this->logger->log( 'update', 'competition', $id, 'Competition updated.', array( 'data' => $prepared ) );

			return $id;
		}

		$prepared['created_at'] = $now;
		$prepared['updated_at'] = $now;
		$prepared['created_by'] = get_current_user_id() ?: null;
		$prepared['updated_by'] = get_current_user_id() ?: null;

		$insert_formats = $this->get_insert_format( $prepared );
		$wpdb->insert( Db::competitions_table(), $prepared, $insert_formats );
		$insert_id = (int) $wpdb->insert_id;

		$this->maybe_log_db_error( __METHOD__ . ':save:insert' );

		if ( ! $insert_id ) {
			return false;
		}

		$this->logger->log( 'create', 'competition', $insert_id, 'Competition created.', array( 'data' => $prepared ) );

		return $insert_id;
	}

	/**
	 * Set deleted_at (NULL to restore).
	 *
	 * @param int         $id
	 * @param string|null $deleted_at mysql datetime or null
	 * @param string      $action context for logging
	 * @return bool
	 */
	private function set_deleted_at( $id, $deleted_at, $action = '' ) {
		global $wpdb;

		$id = absint( $id );
		if ( ! $id ) {
			return false;
		}

		$table = Db::competitions_table();
		$user_id = get_current_user_id() ?: null;
		$now = current_time( 'mysql' );

		if ( null === $deleted_at ) {
			// restore -> set deleted_at = NULL
			$sql = $wpdb->prepare(
				"UPDATE {$table} SET deleted_at = NULL, deleted_by = %d, updated_at = %s, updated_by = %d WHERE id = %d",
				(int) $user_id,
				$now,
				(int) $user_id,
				$id
			);
		} else {
			$sql = $wpdb->prepare(
				"UPDATE {$table} SET deleted_at = %s, deleted_by = %d, updated_at = %s, updated_by = %d WHERE id = %d",
				$deleted_at,
				(int) $user_id,
				$now,
				(int) $user_id,
				$id
			);
		}

		$updated = $wpdb->query( $sql );

		$this->maybe_log_db_error( __METHOD__ . ':set_deleted_at' );

		if ( false === $updated ) {
			return false;
		}

		$this->logger->log( $action ?: 'trash/restore', 'competition', $id, sprintf( 'Competition %s performed.', $action ) );

		return (bool) $updated;
	}

	/**
	 * Sanitize input array - minimal defensive sanitizer.
	 *
	 * @param array $data
	 * @return array
	 */
	private function sanitize( array $data ) {
		$out = array();

		// Known columns and expected types. Expand if needed.
		$fields = array(
			'name'                          => 'text',
			'description'                   => 'text',
			'discipline'                    => 'text',
			'type'                          => 'text',
			'season'                        => 'text',
			'status'                        => 'text',
			'event_start_datetime'          => 'datetime',
			'event_end_datetime'            => 'datetime',
			'registration_open_datetime'    => 'datetime',
			'registration_close_datetime'   => 'datetime',
			'weighin_start_datetime'        => 'datetime',
			'weighin_end_datetime'          => 'datetime',
			'created_at'                    => 'datetime',
			'updated_at'                    => 'datetime',
			'created_by'                    => 'int',
			'updated_by'                    => 'int',
			'deleted_at'                    => 'datetime',
			'deleted_by'                    => 'int',
			// Add other known columns if present
		);

		foreach ( $fields as $col => $type ) {
			if ( array_key_exists( $col, $data ) ) {
				$val = $data[ $col ];
				if ( $type === 'int' ) {
					$out[ $col ] = $val !== '' && null !== $val ? absint( $val ) : null;
				} elseif ( $type === 'datetime' ) {
					// Accept 'Y-m-d H:i:s' or 'Y-m-d\TH:i' (datetime-local) formats.
					if ( empty( $val ) ) {
						$out[ $col ] = null;
					} else {
						$val = trim( (string) $val );
						// If contains 'T', try parse as local datetime (browser)
						if ( false !== strpos( $val, 'T' ) ) {
							$dt = \DateTime::createFromFormat( 'Y-m-d\TH:i', $val, wp_timezone() );
							if ( $dt ) {
								$out[ $col ] = $dt->format( 'Y-m-d H:i:s' );
							} else {
								$out[ $col ] = sanitize_text_field( $val );
							}
						} else {
							$out[ $col ] = sanitize_text_field( $val );
						}
					}
				} else {
					$out[ $col ] = sanitize_text_field( wp_unslash( $val ) );
				}
			}
		}

		// Keep unknown fields? No - removed for safety.
		return $out;
	}

	/**
	 * Build a safe WHERE clause based on filters.
	 *
	 * Supported filters (defensive): view (trash/all), discipline, type, season, status, s (search).
	 *
	 * @param array $filters
	 * @return string SQL fragment starting with WHERE or empty string.
	 */
	private function build_where( array $filters ) {
		global $wpdb;

		$table_alias = ''; // not using alias
		$clauses = array();
		$params = array();

		// default: exclude deleted rows unless explicit view=trash or view=all
		$view = isset( $filters['view'] ) ? sanitize_key( $filters['view'] ) : '';
		if ( 'trash' === $view ) {
			$clauses[] = 'deleted_at IS NOT NULL';
		} elseif ( 'all' === $view ) {
			// no clause
		} else {
			$clauses[] = 'deleted_at IS NULL';
		}

		// equality filters (whitelist)
		$eq_map = array(
			'discipline' => 'discipline',
			'type'       => 'type',
			'season'     => 'season',
			'status'     => 'status',
			'id'         => 'id',
		);
		foreach ( $eq_map as $key => $col ) {
			if ( isset( $filters[ $key ] ) && '' !== $filters[ $key ] ) {
				if ( 'id' === $key ) {
					$clauses[] = "{$col} = %d";
					$params[] = absint( $filters[ $key ] );
				} else {
					$clauses[] = "{$col} = %s";
					$params[] = sanitize_text_field( wp_unslash( $filters[ $key ] ) );
				}
			}
		}

		// search
		if ( isset( $filters['s'] ) && '' !== $filters['s'] ) {
			$search_raw = sanitize_text_field( wp_unslash( $filters['s'] ) );
			$like = '%' . $wpdb->esc_like( $search_raw ) . '%';
			// search commonly on name or description
			$clauses[] = '(name LIKE %s OR description LIKE %s)';
			$params[] = $like;
			$params[] = $like;
		}

		if ( empty( $clauses ) ) {
			return '';
		}

		$where_sql = 'WHERE ' . implode( ' AND ', $clauses );

		if ( empty( $params ) ) {
			return $where_sql;
		}

		// Prepare the where clause with parameters
		array_unshift( $params, $where_sql );
		$where_sql_prepared = call_user_func_array( array( $wpdb, 'prepare' ), $params );

		return $where_sql_prepared;
	}

	/**
	 * Build safe ORDER BY clause using a whitelist.
	 *
	 * @param array $filters
	 * @return string
	 */
	private function build_order_by( array $filters ) {
		$orderby = isset( $filters['orderby'] ) ? sanitize_key( $filters['orderby'] ) : '';
		$order   = isset( $filters['order'] ) ? strtoupper( sanitize_key( $filters['order'] ) ) : '';

		if ( ! in_array( $orderby, $this->allowed_order_cols, true ) ) {
			// default ordering
			return 'created_at DESC';
		}

		$order = ( 'DESC' === $order ) ? 'DESC' : 'ASC';

		// Return the column and order. Column names are trusted from allowlist.
		return $orderby . ' ' . $order;
	}

	/**
	 * Derive insert format array from prepared data.
	 *
	 * @param array $prepared
	 * @return array
	 */
	private function get_insert_format( array $prepared ) {
		$formats = array();
		foreach ( $prepared as $val ) {
			$formats[] = is_int( $val ) ? '%d' : '%s';
		}
		return $formats;
	}

	/**
	 * Derive update format array from prepared data.
	 *
	 * @param array $prepared
	 * @return array
	 */
	private function get_update_format( array $prepared ) {
		return $this->get_insert_format( $prepared );
	}
}
