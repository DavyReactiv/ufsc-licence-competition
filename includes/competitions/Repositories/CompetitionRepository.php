<?php

namespace UFSC\Competitions\Repositories;

use UFSC\Competitions\Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CompetitionRepository - backward-compatible API expected by admin screens.
 *
 * Implements: get, list, count, insert, update, trash, save
 */
class CompetitionRepository {
	private static $table_columns_cache = array();

	public function __construct() {
		// noop
	}

	/**
	 * Return a single competition.
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
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id ) );

		if ( $row && ! $include_deleted && ! empty( $row->deleted_at ) ) {
			return null;
		}

		return $row;
	}

	/**
	 * List competitions with basic filters, pagination.
	 *
	 * @param array $filters
	 * @param int $limit
	 * @param int $offset
	 * @return array
	 */
	public function list( array $filters = array(), $limit = 20, $offset = 0 ) {
		global $wpdb;

		$table = Db::competitions_table();
		$args  = array();
		$where = $this->build_where( $filters, $args );

		$order_by = 'event_start_datetime DESC';
		if ( ! empty( $filters['order_by'] ) ) {
			$allowed_cols = array( 'event_start_datetime', 'name', 'season', 'status' );
			if ( in_array( $filters['order_by'], $allowed_cols, true ) ) {
				$col = $filters['order_by'];
				$dir = ( ! empty( $filters['order_dir'] ) && strtoupper( $filters['order_dir'] ) === 'ASC' ) ? 'ASC' : 'DESC';
				$order_by = esc_sql( $col . ' ' . $dir );
			}
		}

		$limit  = max( 1, (int) $limit );
		$offset = max( 0, (int) $offset );

		$sql = "SELECT * FROM {$table} {$where} ORDER BY {$order_by} LIMIT %d OFFSET %d";
		$args[] = $limit;
		$args[] = $offset;

		$prepared = $wpdb->prepare( $sql, $args );
		$rows     = $wpdb->get_results( $prepared );

		return $rows ? $rows : array();
	}

	/**
	 * Count competitions matching filters.
	 *
	 * @param array $filters
	 * @return int
	 */
	public function count( array $filters = array() ) {
		global $wpdb;

		$table = Db::competitions_table();
		$args  = array();
		$where = $this->build_where( $filters, $args );

		$sql      = "SELECT COUNT(1) FROM {$table} {$where}";
		$prepared = ! empty( $args ) ? $wpdb->prepare( $sql, $args ) : $sql;

		return (int) $wpdb->get_var( $prepared );
	}

	/**
	 * Insert wrapper.
	 *
	 * @param array $data
	 * @return int inserted id
	 */
	public function insert( array $data ) {
		unset( $data['id'] );
		return $this->save( $data );
	}

	/**
	 * Update wrapper.
	 *
	 * @param int $id
	 * @param array $data
	 * @return bool|int
	 */
	public function update( $id, array $data ) {
		$id = absint( $id );
		if ( ! $id ) {
			return false;
		}
		$data['id'] = $id;
		$r = $this->save( $data );
		return $r ? $id : false;
	}

	/**
	 * Soft delete (trash) a record.
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
		$data  = array(
			'deleted_at' => current_time( 'mysql' ),
			'deleted_by' => get_current_user_id() ? get_current_user_id() : 0,
		);

		$updated = $wpdb->update( $table, $data, array( 'id' => $id ), array( '%s', '%d' ), array( '%d' ) );
		return $updated !== false;
	}

	/**
	 * Optional: restore a trashed record (if admin uses restore action).
	 *
	 * @param int $id
	 * @return bool
	 */
	public function restore( $id ) {
		global $wpdb;

		$id = absint( $id );
		if ( ! $id ) {
			return false;
		}

		$table = Db::competitions_table();
		$data  = array(
			'deleted_at' => null,
			'deleted_by' => 0,
		);

		$updated = $wpdb->update( $table, $data, array( 'id' => $id ), array( '%s', '%d' ), array( '%d' ) );
		return $updated !== false;
	}

	/**
	 * Optional: hard delete (if admin uses delete action).
	 *
	 * @param int $id
	 * @return bool
	 */
	public function delete( $id ) {
		global $wpdb;

		$id = absint( $id );
		if ( ! $id ) {
			return false;
		}

		$table   = Db::competitions_table();
		$deleted = $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );

		return $deleted !== false;
	}

	/**
	 * Save (insert or update)
	 *
	 * @param array $data
	 * @return int inserted id or existing id
	 */
	public function save( array $data ): int {
		global $wpdb;

		$san = $this->sanitize( $data );

		$id = isset( $san['id'] ) ? absint( $san['id'] ) : 0;

		$now     = current_time( 'mysql' );
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

			$table   = Db::competitions_table();
			$updated = $wpdb->update( $table, $filtered_prepared, array( 'id' => $id ), $filtered_formats, array( '%d' ) );

			if ( $updated === false ) {
				error_log( 'UFSC CompetitionRepository update error: ' . $wpdb->last_error );
			}

			return $id;
		}

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

		$table    = Db::competitions_table();
		$inserted = $wpdb->insert( $table, $filtered_prepared, $filtered_formats );

		if ( false === $inserted ) {
			error_log( 'UFSC CompetitionRepository insert error: ' . $wpdb->last_error );
			return 0;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Build WHERE clause from filters (prepared).
	 *
	 * @param array $filters
	 * @param array $args Filled by reference with prepare args
	 * @return string
	 */
	private function build_where( array $filters, array &$args ) {
		global $wpdb;

		$where = array( '1=1' );

		// view=active means not deleted
		if ( ! empty( $filters['view'] ) && 'all' !== $filters['view'] ) {
			if ( 'active' === $filters['view'] ) {
				$where[] = 'deleted_at IS NULL';
			}
		}

		if ( ! empty( $filters['competition_ids'] ) && is_array( $filters['competition_ids'] ) ) {
			$ids = array_values( array_filter( array_map( 'absint', $filters['competition_ids'] ) ) );
			if ( ! empty( $ids ) ) {
				$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
				$where[] = "id IN ({$placeholders})";
				foreach ( $ids as $id ) {
					$args[] = $id;
				}
			}
		}

		if ( ! empty( $filters['search'] ) ) {
			$like = '%' . $wpdb->esc_like( (string) $filters['search'] ) . '%';
			$where[] = 'name LIKE %s';
			$args[]  = $like;
		}

		if ( ! empty( $filters['discipline'] ) ) {
			$where[] = 'discipline = %s';
			$args[]  = (string) $filters['discipline'];
		}

		if ( ! empty( $filters['season'] ) ) {
			$where[] = 'season = %s';
			$args[]  = (string) $filters['season'];
		}

		if ( ! empty( $filters['status'] ) ) {
			$where[] = 'status = %s';
			$args[]  = (string) $filters['status'];
		}

		$clause = 'WHERE ' . implode( ' AND ', $where );

		// Only prepare if args exist; caller will prepare full SQL.
		if ( ! empty( $args ) ) {
			$clause = $wpdb->prepare( $clause, $args );
			// After prepare, args should not be re-used by caller for WHERE.
			$args = array();
		}

		return $clause;
	}

	private function value_to_format( $v ) {
		// Treat numeric strings as numbers to get cleaner formats.
		if ( is_int( $v ) || ( is_string( $v ) && ctype_digit( $v ) ) ) {
			return '%d';
		}
		if ( is_float( $v ) || is_double( $v ) || ( is_string( $v ) && is_numeric( $v ) ) ) {
			return '%f';
		}
		return '%s';
	}

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

	public function sanitize( array $data ) {
		$allowed = array(
			'id','name','discipline','type','season','status','location','registration_deadline',
			'age_reference','weight_tolerance','allowed_formats','created_by','updated_by',
			'organizer_club_id','organizer_club_name','organizer_region',
			'venue_name','venue_address1','venue_address2','venue_postcode','venue_city','venue_region',
			'event_start_datetime','event_end_datetime',
			'registration_open_datetime','registration_close_datetime',
			'weighin_start_datetime','weighin_end_datetime',
			'contact_email','contact_phone',
			'created_at','updated_at','deleted_at','deleted_by',
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

	private function filter_prepared_and_formats_for_db( array $prepared, array $formats ) {
		global $wpdb;

		$table = Db::competitions_table();

		if ( ! isset( self::$table_columns_cache[ $table ] ) ) {
			$cols = $wpdb->get_col( "DESCRIBE {$table}", 0 );
			self::$table_columns_cache[ $table ] = is_array( $cols ) ? $cols : array();
		}

		$columns = array_flip( self::$table_columns_cache[ $table ] );

		$filtered_prepared = array();
		$filtered_formats  = array();

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
if ( ! empty( $filters['view'] ) ) {
	if ( 'active' === $filters['view'] ) {
		$where[] = 'deleted_at IS NULL';
	} elseif ( 'trash' === $filters['view'] ) {
		$where[] = 'deleted_at IS NOT NULL';
	}
}
