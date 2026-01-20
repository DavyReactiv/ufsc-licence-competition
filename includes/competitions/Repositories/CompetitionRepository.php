<?php

namespace UFSC\Competitions\Repositories;

use UFSC\Competitions\Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CompetitionRepository - API stable attendue par l'admin.
 *
 * DB fields expected (minimum):
 * - id, name, discipline, type, season, status
 * - venue_name, venue_address1, venue_address2, venue_postcode, venue_city, venue_region
 * - organizer_club_id, organizer_club_name, organizer_region
 * - event_start_datetime, event_end_datetime
 * - registration_open_datetime, registration_close_datetime
 * - weighin_start_datetime, weighin_end_datetime
 * - contact_email, contact_phone
 * - created_at, updated_at, created_by, updated_by
 * - deleted_at, deleted_by
 */
class CompetitionRepository {
	private static $table_columns_cache = array();

	public function __construct() {
		// noop
	}

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
		return $row ?: null;
	}

	public function list( array $filters = array(), $limit = 20, $offset = 0 ) {
		global $wpdb;

		$table  = Db::competitions_table();
		$where  = $this->build_where( $filters );
		$limit  = max( 1, (int) $limit );
		$offset = max( 0, (int) $offset );

		$order_by = 'event_start_datetime DESC';
		$allowed_order_cols = array( 'event_start_datetime', 'name', 'season', 'status', 'updated_at', 'created_at' );

		if ( ! empty( $filters['order_by'] ) && in_array( $filters['order_by'], $allowed_order_cols, true ) ) {
			$dir = ( ! empty( $filters['order_dir'] ) && 'ASC' === strtoupper( (string) $filters['order_dir'] ) ) ? 'ASC' : 'DESC';
			$order_by = esc_sql( $filters['order_by'] ) . ' ' . $dir;
		}

		$sql = $wpdb->prepare(
			"SELECT * FROM {$table} {$where} ORDER BY {$order_by} LIMIT %d OFFSET %d",
			$limit,
			$offset
		);

		$rows = $wpdb->get_results( $sql );
		return is_array( $rows ) ? $rows : array();
	}

	public function count( array $filters = array() ) {
		global $wpdb;

		$table = Db::competitions_table();
		$where = $this->build_where( $filters );

		$sql = "SELECT COUNT(1) FROM {$table} {$where}";
		$val = $wpdb->get_var( $sql );
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
		global $wpdb;

		$id = absint( $id );
		if ( ! $id ) {
			return false;
		}

		$table = Db::competitions_table();

		$data = array(
			'deleted_at' => current_time( 'mysql' ),
			'deleted_by' => (int) get_current_user_id(),
		);

		$updated = $wpdb->update( $table, $data, array( 'id' => $id ), array( '%s', '%d' ), array( '%d' ) );
		if ( false === $updated ) {
			error_log( 'UFSC CompetitionRepository trash error: ' . $wpdb->last_error );
			return false;
		}
		return true;
	}

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
			'updated_at' => current_time( 'mysql' ),
			'updated_by' => (int) get_current_user_id(),
		);

		$updated = $wpdb->update( $table, $data, array( 'id' => $id ), array( '%s', '%d', '%s', '%d' ), array( '%d' ) );
		if ( false === $updated ) {
			error_log( 'UFSC CompetitionRepository restore error: ' . $wpdb->last_error );
			return false;
		}
		return true;
	}

	public function delete( $id ) {
		global $wpdb;

		$id = absint( $id );
		if ( ! $id ) {
			return false;
		}

		$table   = Db::competitions_table();
		$deleted = $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );

		if ( false === $deleted ) {
			error_log( 'UFSC CompetitionRepository delete error: ' . $wpdb->last_error );
			return false;
		}
		return true;
	}

	public function save( array $data ): int {
		global $wpdb;

		$san = $this->sanitize( $data );
		$id  = isset( $san['id'] ) ? absint( $san['id'] ) : 0;

		$now     = current_time( 'mysql' );
		$user_id = (int) get_current_user_id();

		$table = Db::competitions_table();

		if ( $id ) {
			$san['updated_at'] = $now;
			$san['updated_by'] = $user_id;

			unset( $san['id'] );

			$formats = array_map( array( $this, 'value_to_format' ), array_values( $san ) );
			list( $filtered, $filtered_formats ) = $this->filter_prepared_and_formats_for_db( $san, $formats );

			if ( empty( $filtered ) ) {
				return $id;
			}

			$updated = $wpdb->update( $table, $filtered, array( 'id' => $id ), $filtered_formats, array( '%d' ) );
			if ( false === $updated ) {
				error_log( 'UFSC CompetitionRepository update error: ' . $wpdb->last_error );
			}
			return $id;
		}

		// insert
		$san['created_at'] = $now;
		$san['updated_at'] = $now;
		$san['created_by'] = $user_id;
		$san['updated_by'] = $user_id;

		unset( $san['id'] );

		$formats = array_map( array( $this, 'value_to_format' ), array_values( $san ) );
		list( $filtered, $filtered_formats ) = $this->filter_prepared_and_formats_for_db( $san, $formats );

		if ( empty( $filtered ) ) {
			return 0;
		}

		$inserted = $wpdb->insert( $table, $filtered, $filtered_formats );
		if ( false === $inserted ) {
			error_log( 'UFSC CompetitionRepository insert error: ' . $wpdb->last_error );
			return 0;
		}

		return (int) $wpdb->insert_id;
	}

	private function build_where( array $filters ) {
		global $wpdb;

		$where = array( '1=1' );

		$view = isset( $filters['view'] ) ? (string) $filters['view'] : 'all';
		if ( 'trash' === $view ) {
			$where[] = 'deleted_at IS NOT NULL';
		} else {
			// default: active only
			$where[] = 'deleted_at IS NULL';
		}

		if ( ! empty( $filters['search'] ) ) {
			$search = $wpdb->esc_like( (string) $filters['search'] );
			$where[] = $wpdb->prepare( "name LIKE %s", '%' . $search . '%' );
		}

		if ( ! empty( $filters['discipline'] ) ) {
			$where[] = $wpdb->prepare( "discipline = %s", (string) $filters['discipline'] );
		}

		if ( ! empty( $filters['season'] ) ) {
			$where[] = $wpdb->prepare( "season = %s", (string) $filters['season'] );
		}

		if ( ! empty( $filters['status'] ) ) {
			$where[] = $wpdb->prepare( "status = %s", (string) $filters['status'] );
		}

		return 'WHERE ' . implode( ' AND ', $where );
	}

	private function value_to_format( $v ) {
		if ( is_int( $v ) ) {
			return '%d';
		}
		if ( is_float( $v ) || is_double( $v ) ) {
			return '%f';
		}
		// allow NULL for nullable datetime columns
		if ( null === $v ) {
			return '%s';
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
				return gmdate( 'Y-m-d H:i:s', $ts ); // store normalized
			}
		}

		return null;
	}

	public function sanitize( array $data ) {
		$allowed = array(
			'id','name','discipline','type','season','status',
			'organizer_club_id','organizer_club_name','organizer_region',
			'venue_name','venue_address1','venue_address2','venue_postcode','venue_city','venue_region',
			'event_start_datetime','event_end_datetime',
			'registration_open_datetime','registration_close_datetime',
			'weighin_start_datetime','weighin_end_datetime',
			'contact_email','contact_phone',
			'created_at','updated_at','created_by','updated_by',
			'deleted_at','deleted_by',
		);

		$out = array();

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
					$out[ $k ] = absint( $v );
					break;

				case 'contact_email':
					$out[ $k ] = sanitize_email( $v );
					break;

				case 'contact_phone':
					$out[ $k ] = sanitize_text_field( $v );
					break;

				case 'event_start_datetime':
				case 'event_end_datetime':
				case 'registration_open_datetime':
				case 'registration_close_datetime':
				case 'weighin_start_datetime':
				case 'weighin_end_datetime':
					$out[ $k ] = $this->sanitize_datetime( $v );
					break;

				default:
					if ( is_string( $v ) ) {
						$out[ $k ] = sanitize_text_field( $v );
					} else {
						$out[ $k ] = $v;
					}
					break;
			}
		}

		return $out;
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
