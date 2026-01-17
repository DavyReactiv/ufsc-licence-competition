<?php

namespace UFSC\Competitions\Repositories;

use UFSC\Competitions\Db;
use UFSC\Competitions\Services\LogService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CategoryRepository {
	private $logger;

	public function __construct() {
		$this->logger = new LogService();
	}

	public function get( $id, $include_deleted = false ) {
		global $wpdb;

		$where_deleted = $include_deleted ? '' : 'AND deleted_at IS NULL';

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM " . Db::categories_table() . " WHERE id = %d {$where_deleted}",
				absint( $id )
			)
		);
	}

	public function get_by_competition_and_name( $competition_id, $name, $include_deleted = true ) {
		global $wpdb;

		$where_deleted = $include_deleted ? '' : 'AND deleted_at IS NULL';

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM " . Db::categories_table() . " WHERE competition_id = %d AND name = %s {$where_deleted}",
				absint( $competition_id ),
				sanitize_text_field( $name )
			)
		);
	}

	public function list( array $filters, $limit, $offset ) {
		global $wpdb;

		$where = $this->build_where( $filters );
		$sql   = "SELECT * FROM " . Db::categories_table() . " {$where} ORDER BY name ASC";
		$sql  .= $wpdb->prepare( ' LIMIT %d OFFSET %d', absint( $limit ), absint( $offset ) );

		return $wpdb->get_results( $sql );
	}

	public function count( array $filters ) {
		global $wpdb;

		$where = $this->build_where( $filters );

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . Db::categories_table() . " {$where}" );
	}

	public function insert( array $data ) {
		global $wpdb;

		$prepared = $this->sanitize( $data );

		$prepared['created_at'] = current_time( 'mysql' );
		$prepared['updated_at'] = current_time( 'mysql' );
		$prepared['created_by'] = get_current_user_id() ?: null;
		$prepared['updated_by'] = get_current_user_id() ?: null;

		$wpdb->insert( Db::categories_table(), $prepared, $this->get_insert_format() );
		$id = (int) $wpdb->insert_id;

		$this->logger->log( 'create', 'category', $id, 'Category created.', array( 'data' => $prepared ) );

		return $id;
	}

	public function update( $id, array $data ) {
		global $wpdb;

		$prepared = $this->sanitize( $data );
		$prepared['updated_at'] = current_time( 'mysql' );
		$prepared['updated_by'] = get_current_user_id() ?: null;

		$updated = $wpdb->update(
			Db::categories_table(),
			$prepared,
			array( 'id' => absint( $id ) ),
			$this->get_update_format(),
			array( '%d' )
		);

		$this->logger->log( 'update', 'category', $id, 'Category updated.', array( 'data' => $prepared ) );

		return $updated;
	}

	public function soft_delete( $id ) {
		return $this->set_deleted_at( $id, current_time( 'mysql' ), 'trash' );
	}

	public function restore( $id ) {
		return $this->set_deleted_at( $id, null, 'restore' );
	}

	public function delete( $id ) {
		global $wpdb;

		$deleted = $wpdb->delete( Db::categories_table(), array( 'id' => absint( $id ) ), array( '%d' ) );

		$this->logger->log( 'delete', 'category', $id, 'Category deleted permanently.', array() );

		return $deleted;
	}

	private function set_deleted_at( $id, $deleted_at, $action ) {
		global $wpdb;

		$updated = $wpdb->update(
			Db::categories_table(),
			array(
				'deleted_at' => $deleted_at,
				'updated_at' => current_time( 'mysql' ),
				'updated_by' => get_current_user_id() ?: null,
				'deleted_by' => $deleted_at ? ( get_current_user_id() ?: null ) : null,
			),
			array( 'id' => absint( $id ) ),
			array( '%s', '%s', '%d', '%d' ),
			array( '%d' )
		);

		$this->logger->log( $action, 'category', $id, 'Category status changed.', array( 'deleted_at' => $deleted_at ) );

		return $updated;
	}

	private function sanitize( array $data ) {
		return array(
			'competition_id' => isset( $data['competition_id'] ) && '' !== $data['competition_id'] ? absint( $data['competition_id'] ) : null,
			'discipline'     => sanitize_text_field( $data['discipline'] ?? '' ),
			'name'           => sanitize_text_field( $data['name'] ?? '' ),
			'age_min'        => isset( $data['age_min'] ) && '' !== $data['age_min'] ? absint( $data['age_min'] ) : null,
			'age_max'        => isset( $data['age_max'] ) && '' !== $data['age_max'] ? absint( $data['age_max'] ) : null,
			'weight_min'     => isset( $data['weight_min'] ) && '' !== $data['weight_min'] ? (float) $data['weight_min'] : null,
			'weight_max'     => isset( $data['weight_max'] ) && '' !== $data['weight_max'] ? (float) $data['weight_max'] : null,
			'sex'            => sanitize_text_field( $data['sex'] ?? '' ),
			'level'          => sanitize_text_field( $data['level'] ?? '' ),
			'format'         => sanitize_text_field( $data['format'] ?? '' ),
		);
	}

	private function build_where( array $filters ) {
		global $wpdb;

		$where = array( '1=1' );
		$view  = $filters['view'] ?? 'all';

		if ( 'trash' === $view ) {
			$where[] = 'deleted_at IS NOT NULL';
		} else {
			$where[] = 'deleted_at IS NULL';
		}

		if ( ! empty( $filters['competition_id'] ) ) {
			$where[] = $wpdb->prepare( 'competition_id = %d', absint( $filters['competition_id'] ) );
		}

		if ( ! empty( $filters['discipline'] ) ) {
			$where[] = $wpdb->prepare( 'discipline = %s', sanitize_text_field( $filters['discipline'] ) );
		}

		if ( ! empty( $filters['search'] ) ) {
			$like = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
			$where[] = $wpdb->prepare( 'name LIKE %s', $like );
		}

		return 'WHERE ' . implode( ' AND ', $where );
	}

	private function get_insert_format() {
		return array( '%d', '%s', '%s', '%d', '%d', '%f', '%f', '%s', '%s', '%s', '%d', '%d', '%s', '%s' );
	}

	private function get_update_format() {
		return array( '%d', '%s', '%s', '%d', '%d', '%f', '%f', '%s', '%s', '%s', '%s', '%d' );
	}
}
