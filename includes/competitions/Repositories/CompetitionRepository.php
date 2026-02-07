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

	public function list( array $filters = array(), $limit = 20, $offset = 0 ) {
		global $wpdb;

		$table  = Db::competitions_table();
		$scope_region = isset( $filters['scope_region'] ) ? sanitize_key( (string) $filters['scope_region'] ) : '';
		$filters_no_scope = $filters;
		unset( $filters_no_scope['scope_region'] );
		$where  = $this->build_where( $filters_no_scope ); // prepared fragment or empty
		$limit  = max( 1, (int) $limit );
		$offset = max( 0, (int) $offset );

		$order_by = $this->build_order_by( $filters_no_scope );

		if ( '' !== $scope_region ) {
			$sql = "SELECT * FROM {$table} {$where} ORDER BY {$order_by}";
			$rows = $wpdb->get_results( $sql );
			$rows = $this->filter_competitions_by_scope( $rows, $scope_region );
			$rows = array_slice( $rows, $offset, $limit );
		} else {
			$sql = $wpdb->prepare(
				"SELECT * FROM {$table} {$where} ORDER BY {$order_by} LIMIT %d OFFSET %d",
				$limit,
				$offset
			);
			$rows = $wpdb->get_results( $sql );
		}

		$this->maybe_log_db_error( __METHOD__ . ':list' );

		return is_array( $rows ) ? $rows : array();
	}

	public function count( array $filters = array() ) {
		global $wpdb;

		$table = Db::competitions_table();
		$scope_region = isset( $filters['scope_region'] ) ? sanitize_key( (string) $filters['scope_region'] ) : '';
		$filters_no_scope = $filters;
		unset( $filters_no_scope['scope_region'] );
		$where = $this->build_where( $filters_no_scope );

		if ( '' !== $scope_region ) {
			$sql = "SELECT * FROM {$table} {$where}";
			$rows = $wpdb->get_results( $sql );
			$rows = $this->filter_competitions_by_scope( $rows, $scope_region );
			$val = is_array( $rows ) ? count( $rows ) : 0;
		} else {
			$sql = "SELECT COUNT(1) FROM {$table} {$where}";
			$val = $wpdb->get_var( $sql );
		}

		$this->maybe_log_db_error( __METHOD__ . ':count' );

		return (int) $val;
	}

	public function assert_competition_in_scope( int $competition_id ): void {
		$scope_region = function_exists( 'ufsc_competitions_get_user_scope_region' )
			? ufsc_competitions_get_user_scope_region()
			: '';
		$scope_region = is_string( $scope_region ) ? sanitize_key( $scope_region ) : '';
		if ( '' === $scope_region ) {
			return;
		}

		if ( ! $this->competition_matches_scope( $competition_id, $scope_region ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}
	}

	private function filter_competitions_by_scope( $rows, string $scope_region ): array {
		if ( ! is_array( $rows ) || '' === $scope_region ) {
			return is_array( $rows ) ? $rows : array();
		}

		$filtered = array();
		foreach ( $rows as $row ) {
			$competition_id = (int) ( $row->id ?? 0 );
			if ( ! $competition_id ) {
				continue;
			}

			if ( $this->competition_matches_scope( $competition_id, $scope_region ) ) {
				$filtered[] = $row;
			}
		}

		return $filtered;
	}

	private function competition_matches_scope( int $competition_id, string $scope_region ): bool {
		if ( '' === $scope_region ) {
			return true;
		}

		if ( ! class_exists( '\UFSC\Competitions\Services\CompetitionMeta' ) ) {
			return true;
		}

		$meta = \UFSC\Competitions\Services\CompetitionMeta::get( $competition_id );
		$allowed_keys = isset( $meta['allowed_regions_keys'] ) && is_array( $meta['allowed_regions_keys'] )
			? $meta['allowed_regions_keys']
			: array();
		$allowed_labels = isset( $meta['allowed_regions'] ) && is_array( $meta['allowed_regions'] )
			? $meta['allowed_regions']
			: array();

		if ( empty( $allowed_keys ) && empty( $allowed_labels ) ) {
			return true;
		}

		$scope_key = $this->normalize_region_key( $scope_region );
		foreach ( $allowed_keys as $key ) {
			if ( $scope_key === $this->normalize_region_key( (string) $key ) ) {
				return true;
			}
		}

		foreach ( $allowed_labels as $label ) {
			if ( $scope_key === $this->normalize_region_key( (string) $label ) ) {
				return true;
			}
		}

		return false;
	}

	private function normalize_region_key( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}

		if ( function_exists( 'ufsc_normalize_region_key' ) ) {
			$normalized = ufsc_normalize_region_key( $value );
			if ( is_string( $normalized ) && '' !== $normalized ) {
				return $normalized;
			}
		}

		return sanitize_key( $value );
	}

	/**
	 * Create or update a competition.
	 * Expects keys: id, name, discipline, type, season, status (optional), event_start_datetime (optional)
	 *
	 * @param array $data
	 * @return int Saved ID or 0 on failure
	 */
	public function save( array $data ) {
		global $wpdb;

		$table = Db::competitions_table();
		$now   = current_time( 'mysql' );
		$uid   = get_current_user_id();

		$clean = $this->sanitize_competition_data( $data );

		$id = isset( $clean['id'] ) ? absint( $clean['id'] ) : 0;
		unset( $clean['id'] );

		if ( $id ) {
			$clean['updated_at'] = $now;
			$clean['updated_by'] = $uid;

			$formats = $this->formats_for( $clean );

			$updated = $wpdb->update(
				$table,
				$clean,
				array( 'id' => $id ),
				$formats,
				array( '%d' )
			);

			$this->maybe_log_db_error( __METHOD__ . ':update' );

			return ( false === $updated ) ? 0 : $id;
		}

		$clean['created_at'] = $now;
		$clean['updated_at'] = $now;
		$clean['created_by'] = $uid;
		$clean['updated_by'] = $uid;

		$formats = $this->formats_for( $clean );

		$inserted = $wpdb->insert( $table, $clean, $formats );

		$this->maybe_log_db_error( __METHOD__ . ':insert' );

		return $inserted ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Soft delete -> trash.
	 */
	public function trash( int $id ): bool {
		global $wpdb;

		$id = absint( $id );
		if ( ! $id ) {
			return false;
		}

		$table = Db::competitions_table();

		$updated = $wpdb->update(
			$table,
			array(
				'deleted_at' => current_time( 'mysql' ),
				'deleted_by' => get_current_user_id(),
				'updated_at' => current_time( 'mysql' ),
				'updated_by' => get_current_user_id(),
			),
			array( 'id' => $id ),
			array( '%s', '%d', '%s', '%d' ),
			array( '%d' )
		);

		$this->maybe_log_db_error( __METHOD__ . ':trash' );

		return ( false !== $updated );
	}

	public function restore( int $id ): bool {
		global $wpdb;

		$id = absint( $id );
		if ( ! $id ) {
			return false;
		}

		$table = Db::competitions_table();

		$updated = $wpdb->update(
			$table,
			array(
				'deleted_at' => null,
				'deleted_by' => null,
				'updated_at' => current_time( 'mysql' ),
				'updated_by' => get_current_user_id(),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s', '%d' ),
			array( '%d' )
		);

		$this->maybe_log_db_error( __METHOD__ . ':restore' );

		return ( false !== $updated );
	}

	public function delete( int $id ): bool {
		global $wpdb;

		$id = absint( $id );
		if ( ! $id ) {
			return false;
		}

		$table = Db::competitions_table();

		$deleted = $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );

		$this->maybe_log_db_error( __METHOD__ . ':delete' );

		return (bool) $deleted;
	}

	/**
	 * Archive: non-destructive (status=archived).
	 */
	public function archive( int $id ): bool {
		global $wpdb;

		$id = absint( $id );
		if ( ! $id ) {
			return false;
		}

		$table = Db::competitions_table();

		$updated = $wpdb->update(
			$table,
			array(
				'status'     => 'archived',
				'updated_at' => current_time( 'mysql' ),
				'updated_by' => get_current_user_id(),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%d' ),
			array( '%d' )
		);

		$this->maybe_log_db_error( __METHOD__ . ':archive' );

		return ( false !== $updated );
	}

	/**
	 * Unarchive: returns to open (safe default).
	 */
	public function unarchive( int $id ): bool {
		global $wpdb;

		$id = absint( $id );
		if ( ! $id ) {
			return false;
		}

		$table = Db::competitions_table();

		$updated = $wpdb->update(
			$table,
			array(
				'status'     => 'open',
				'updated_at' => current_time( 'mysql' ),
				'updated_by' => get_current_user_id(),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%d' ),
			array( '%d' )
		);

		$this->maybe_log_db_error( __METHOD__ . ':unarchive' );

		return ( false !== $updated );
	}

	/**
	 * Sanitize input data without breaking existing fields.
	 */
	private function sanitize_competition_data( array $data ): array {
		$out = array();

		$out['id'] = isset( $data['id'] ) ? absint( $data['id'] ) : 0;

		$out['name']       = isset( $data['name'] ) ? sanitize_text_field( (string) $data['name'] ) : '';
		$out['discipline'] = isset( $data['discipline'] ) ? sanitize_text_field( (string) $data['discipline'] ) : '';
		$out['type']       = isset( $data['type'] ) ? sanitize_text_field( (string) $data['type'] ) : '';
		$out['season']     = isset( $data['season'] ) ? sanitize_text_field( (string) $data['season'] ) : '';

		$status = isset( $data['status'] ) ? sanitize_key( (string) $data['status'] ) : 'open';
		if ( ! in_array( $status, array( 'open', 'draft', 'closed', 'archived' ), true ) ) {
			$status = 'open';
		}
		$out['status'] = $status;

		// Optional datetime
		if ( isset( $data['event_start_datetime'] ) ) {
			$v = sanitize_text_field( (string) $data['event_start_datetime'] );
			$out['event_start_datetime'] = $v ?: null;
		}

		// Keep required NOT NULL fields safe
		if ( '' === $out['name'] ) {
			$out['name'] = '(sans nom)';
		}
		if ( '' === $out['discipline'] ) {
			$out['discipline'] = '';
		}
		if ( '' === $out['type'] ) {
			$out['type'] = '';
		}
		if ( '' === $out['season'] ) {
			$out['season'] = '';
		}

		return $out;
	}

	/**
	 * Build WHERE clause (prepared) based on filters.
	 * Supported filters:
	 * - view: all | archived | trash
	 * - status, discipline, season
	 * - s (search)
	 */
	private function build_where( array $filters ): string {
		global $wpdb;

		$where  = array();
		$view   = isset( $filters['view'] ) ? (string) $filters['view'] : 'all';

		// Trash filter
		if ( 'trash' === $view ) {
			$where[] = "deleted_at IS NOT NULL";
		} else {
			$where[] = "deleted_at IS NULL";

			// Archived filter (non-destructive)
			if ( 'archived' === $view ) {
				$where[] = $wpdb->prepare( "status = %s", 'archived' );
			} else {
				$where[] = $wpdb->prepare( "status != %s", 'archived' );
			}
		}

		// Optional status filter (applies only when not trash)
		if ( isset( $filters['status'] ) && '' !== (string) $filters['status'] && 'trash' !== $view ) {
			$st = sanitize_key( (string) $filters['status'] );
			$where[] = $wpdb->prepare( "status = %s", $st );
		}

		if ( isset( $filters['discipline'] ) && '' !== (string) $filters['discipline'] ) {
			$disc = sanitize_text_field( (string) $filters['discipline'] );
			$where[] = $wpdb->prepare( "discipline = %s", $disc );
		}

		if ( isset( $filters['season'] ) && '' !== (string) $filters['season'] ) {
			$season = sanitize_text_field( (string) $filters['season'] );
			$where[] = $wpdb->prepare( "season = %s", $season );
		}

		// Search
		if ( isset( $filters['s'] ) && '' !== (string) $filters['s'] ) {
			$term = sanitize_text_field( (string) $filters['s'] );
			$like = '%' . $wpdb->esc_like( $term ) . '%';
			$where[] = $wpdb->prepare( "(name LIKE %s OR discipline LIKE %s OR type LIKE %s OR season LIKE %s)", $like, $like, $like, $like );
		}

		if ( empty( $where ) ) {
			return '';
		}

		return 'WHERE ' . implode( ' AND ', $where );
	}

	private function build_order_by( array $filters ): string {
		$order_by = isset( $filters['order_by'] ) ? sanitize_key( (string) $filters['order_by'] ) : '';
		$order_dir = isset( $filters['order_dir'] ) ? strtoupper( sanitize_key( (string) $filters['order_dir'] ) ) : 'DESC';

		if ( ! in_array( $order_dir, array( 'ASC', 'DESC' ), true ) ) {
			$order_dir = 'DESC';
		}

		if ( ! $order_by || ! in_array( $order_by, $this->allowed_order_cols, true ) ) {
			return "updated_at DESC";
		}

		return "{$order_by} {$order_dir}";
	}

	private function formats_for( array $data ): array {
		$formats = array();
		foreach ( $data as $k => $v ) {
			if ( in_array( $k, array( 'id', 'created_by', 'updated_by', 'deleted_by', 'organizer_club_id' ), true ) ) {
				$formats[] = '%d';
			} elseif ( in_array( $k, array( 'weight_tolerance' ), true ) ) {
				$formats[] = '%f';
			} else {
				$formats[] = '%s';
			}
		}
		return $formats;
	}
}
