<?php

namespace UFSC\Competitions\Repositories;

use UFSC\Competitions\Db;
use UFSC\Competitions\Services\LogService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Guard: avoid fatal if the class is already declared elsewhere
if ( ! class_exists( __NAMESPACE__ . '\\CompetitionRepository', false ) ) {

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

			// Log DB error if any
			$this->maybe_log_db_error( __METHOD__ . ':list' );

			// Detailed debug logging when WP_DEBUG
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				$last_query = isset( $wpdb->last_query ) ? $wpdb->last_query : $sql;
				$last_error = isset( $wpdb->last_error ) ? $wpdb->last_error : '';
				$debug_msg  = sprintf(
					'CompetitionRepository::list executed. filters=%s ; query=%s ; last_error=%s ; rows=%d',
					wp_json_encode( $filters ),
					$last_query,
					$last_error,
					is_array( $rows ) ? count( $rows ) : 0
				);

				if ( class_exists( '\\UFSC_LC_Logger' ) ) {
					\UFSC_LC_Logger::log( $debug_msg );
				} else {
					error_log( $debug_msg );
				}
			}

			return is_array( $rows ) ? $rows : array();
		}

		public function count( array $filters = array() ) {
			global $wpdb;

			$table = Db::competitions_table();
			$where = $this->build_where( $filters );

			$sql = "SELECT COUNT(1) FROM {$table} {$where}";
			$val = $wpdb->get_var( $sql );

			$this->maybe_log_db_error( __METHOD__ . ':count' );

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				$last_query = isset( $wpdb->last_query ) ? $wpdb->last_query : $sql;
				$last_error = isset( $wpdb->last_error ) ? $wpdb->last_error : '';
				$debug_msg  = sprintf(
					'CompetitionRepository::count executed. filters=%s ; query=%s ; last_error=%s ; count=%d',
					wp_json_encode( $filters ),
					$last_query,
					$last_error,
					(int) $val
				);

				if ( class_exists( '\\UFSC_LC_Logger' ) ) {
					\UFSC_LC_Logger::log( $debug_msg );
				} else {
					error_log( $debug_msg );
				}
			}

			return (int) $val;
		}

		// ... save, trash, restore, delete, sanitize, build_where, build_order_by, etc. remain the same ...
	}
}
