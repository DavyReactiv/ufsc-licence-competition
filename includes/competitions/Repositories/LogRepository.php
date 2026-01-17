<?php

namespace UFSC\Competitions\Repositories;

use UFSC\Competitions\Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LogRepository {
	public function list( array $filters, $limit, $offset ) {
		global $wpdb;

		$where = array( '1=1' );
		if ( ! empty( $filters['object_type'] ) ) {
			$where[] = $wpdb->prepare( 'object_type = %s', sanitize_key( $filters['object_type'] ) );
		}
		if ( ! empty( $filters['object_id'] ) ) {
			$where[] = $wpdb->prepare( 'object_id = %d', absint( $filters['object_id'] ) );
		}
		$where_sql = 'WHERE ' . implode( ' AND ', $where );

		$sql  = "SELECT * FROM " . Db::logs_table() . " {$where_sql} ORDER BY created_at DESC";
		$sql .= $wpdb->prepare( ' LIMIT %d OFFSET %d', absint( $limit ), absint( $offset ) );

		return $wpdb->get_results( $sql );
	}
}
