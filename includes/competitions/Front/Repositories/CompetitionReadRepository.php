<?php

namespace UFSC\Competitions\Front\Repositories;

use UFSC\Competitions\Db;
use UFSC\Competitions\Repositories\RepositoryHelpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CompetitionReadRepository {
	use RepositoryHelpers;

	public function get( int $id ) {
		global $wpdb;

		$id = absint( $id );
		if ( ! $id ) {
			return null;
		}

		$table = Db::competitions_table();

		$sql = $wpdb->prepare(
			"SELECT * FROM {$table} WHERE id = %d AND deleted_at IS NULL AND status != %s LIMIT 1",
			$id,
			'archived'
		);

		$row = $wpdb->get_row( $sql );

		$this->maybe_log_db_error( __METHOD__ . ':get' );

		return $row ?: null;
	}

	public function list( array $filters = array(), int $limit = 10, int $offset = 0 ): array {
		global $wpdb;

		$table  = Db::competitions_table();
		$where  = $this->build_where( $filters );
		$limit  = max( 1, (int) $limit );
		$offset = max( 0, (int) $offset );

		$order_by = 'event_start_datetime DESC, updated_at DESC';

		$sql = $wpdb->prepare(
			"SELECT * FROM {$table} {$where} ORDER BY {$order_by} LIMIT %d OFFSET %d",
			$limit,
			$offset
		);

		$rows = $wpdb->get_results( $sql );

		$this->maybe_log_db_error( __METHOD__ . ':list' );

		return is_array( $rows ) ? $rows : array();
	}

	public function count( array $filters = array() ): int {
		global $wpdb;

		$table = Db::competitions_table();
		$where = $this->build_where( $filters );

		$sql = "SELECT COUNT(1) FROM {$table} {$where}";
		$val = $wpdb->get_var( $sql );

		$this->maybe_log_db_error( __METHOD__ . ':count' );

		return (int) $val;
	}

	private function build_where( array $filters ): string {
		global $wpdb;

		$where = array();

		$view = isset( $filters['view'] ) ? (string) $filters['view'] : 'open';
		if ( ! in_array( $view, array( 'open', 'all' ), true ) ) {
			$view = 'open';
		}

		$where[] = 'deleted_at IS NULL';

		if ( 'open' === $view ) {
			$where[] = $wpdb->prepare( 'status = %s', 'open' );
		} else {
			$where[] = $wpdb->prepare( 'status != %s', 'archived' );
		}

		if ( isset( $filters['discipline'] ) && '' !== (string) $filters['discipline'] ) {
			$disc = sanitize_text_field( (string) $filters['discipline'] );
			$where[] = $wpdb->prepare( 'discipline = %s', $disc );
		}

		if ( isset( $filters['type'] ) && '' !== (string) $filters['type'] ) {
			$type = sanitize_text_field( (string) $filters['type'] );
			$where[] = $wpdb->prepare( 'type = %s', $type );
		}

		if ( isset( $filters['season'] ) && '' !== (string) $filters['season'] ) {
			$season = sanitize_text_field( (string) $filters['season'] );
			$where[] = $wpdb->prepare( 'season = %s', $season );
		}

		if ( isset( $filters['s'] ) && '' !== (string) $filters['s'] ) {
			$term = sanitize_text_field( (string) $filters['s'] );
			$like = '%' . $wpdb->esc_like( $term ) . '%';
			$where[] = $wpdb->prepare( '(name LIKE %s OR discipline LIKE %s OR type LIKE %s OR season LIKE %s)', $like, $like, $like, $like );
		}

		if ( empty( $where ) ) {
			return '';
		}

		return 'WHERE ' . implode( ' AND ', $where );
	}
}
