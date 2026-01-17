<?php

namespace UFSC\Competitions\Repositories;

use UFSC\Competitions\Db;
use UFSC\Competitions\Services\LogService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FightRepository {
	private $logger;

	public function __construct() {
		$this->logger = new LogService();
	}

	public function get( $id, $include_deleted = false ) {
		global $wpdb;

		$where_deleted = $include_deleted ? '' : 'AND deleted_at IS NULL';

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM " . Db::fights_table() . " WHERE id = %d {$where_deleted}",
				absint( $id )
			)
		);
	}

	public function list( array $filters, $limit, $offset ) {
		global $wpdb;

		$where = $this->build_where( $filters );
		$sql   = "SELECT * FROM " . Db::fights_table() . " {$where} ORDER BY fight_no ASC";
		$sql  .= $wpdb->prepare( ' LIMIT %d OFFSET %d', absint( $limit ), absint( $offset ) );

		return $wpdb->get_results( $sql );
	}

	public function count( array $filters ) {
		global $wpdb;

		$where = $this->build_where( $filters );

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . Db::fights_table() . " {$where}" );
	}

	public function insert( array $data ) {
		global $wpdb;

		$prepared = $this->sanitize( $data );
		$prepared['created_at'] = current_time( 'mysql' );
		$prepared['updated_at'] = current_time( 'mysql' );
		$prepared['created_by'] = get_current_user_id() ?: null;
		$prepared['updated_by'] = get_current_user_id() ?: null;

		$wpdb->insert( Db::fights_table(), $prepared, $this->get_insert_format() );
		$id = (int) $wpdb->insert_id;

		$this->logger->log( 'create', 'fight', $id, 'Fight created.', array( 'data' => $prepared ) );

		return $id;
	}

	public function update( $id, array $data ) {
		global $wpdb;

		$prepared = $this->sanitize( $data );
		$prepared['updated_at'] = current_time( 'mysql' );
		$prepared['updated_by'] = get_current_user_id() ?: null;

		$updated = $wpdb->update(
			Db::fights_table(),
			$prepared,
			array( 'id' => absint( $id ) ),
			$this->get_update_format(),
			array( '%d' )
		);

		$this->logger->log( 'update', 'fight', $id, 'Fight updated.', array( 'data' => $prepared ) );

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

		$deleted = $wpdb->delete( Db::fights_table(), array( 'id' => absint( $id ) ), array( '%d' ) );

		$this->logger->log( 'delete', 'fight', $id, 'Fight deleted permanently.', array() );

		return $deleted;
	}

	public function delete_by_competition( $competition_id ) {
		global $wpdb;

		$deleted = $wpdb->delete(
			Db::fights_table(),
			array( 'competition_id' => absint( $competition_id ) ),
			array( '%d' )
		);

		$this->logger->log( 'rollback', 'fight', $competition_id, 'Fight generation rollback.', array() );

		return $deleted;
	}

	private function set_deleted_at( $id, $deleted_at, $action ) {
		global $wpdb;

		$updated = $wpdb->update(
			Db::fights_table(),
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

		$this->logger->log( $action, 'fight', $id, 'Fight status changed.', array( 'deleted_at' => $deleted_at ) );

		return $updated;
	}

	private function sanitize( array $data ) {
		$status = sanitize_key( $data['status'] ?? 'scheduled' );

		return array(
			'competition_id'  => absint( $data['competition_id'] ?? 0 ),
			'category_id'     => absint( $data['category_id'] ?? 0 ),
			'fight_no'        => absint( $data['fight_no'] ?? 0 ),
			'ring'            => sanitize_text_field( $data['ring'] ?? '' ),
			'round_no'        => isset( $data['round_no'] ) ? absint( $data['round_no'] ) : null,
			'red_entry_id'    => isset( $data['red_entry_id'] ) ? absint( $data['red_entry_id'] ) : null,
			'blue_entry_id'   => isset( $data['blue_entry_id'] ) ? absint( $data['blue_entry_id'] ) : null,
			'winner_entry_id' => isset( $data['winner_entry_id'] ) ? absint( $data['winner_entry_id'] ) : null,
			'status'          => $status,
			'result_method'   => sanitize_text_field( $data['result_method'] ?? '' ),
			'score_red'       => sanitize_text_field( $data['score_red'] ?? '' ),
			'score_blue'      => sanitize_text_field( $data['score_blue'] ?? '' ),
			'scheduled_at'    => isset( $data['scheduled_at'] ) ? sanitize_text_field( $data['scheduled_at'] ) : null,
		);
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

		if ( ! empty( $filters['competition_id'] ) ) {
			$where[] = $wpdb->prepare( 'competition_id = %d', absint( $filters['competition_id'] ) );
		}

		if ( ! empty( $filters['competition_ids'] ) && is_array( $filters['competition_ids'] ) ) {
			$ids = array_filter( array_map( 'absint', $filters['competition_ids'] ) );
			if ( $ids ) {
				$where[] = 'competition_id IN (' . implode( ',', $ids ) . ')';
			}
		}

		if ( ! empty( $filters['category_id'] ) ) {
			$where[] = $wpdb->prepare( 'category_id = %d', absint( $filters['category_id'] ) );
		}

		if ( ! empty( $filters['status'] ) ) {
			$where[] = $wpdb->prepare( 'status = %s', sanitize_key( $filters['status'] ) );
		}

		return 'WHERE ' . implode( ' AND ', $where );
	}

	private function get_insert_format() {
		return array( '%d', '%d', '%d', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' );
	}

	private function get_update_format() {
		return array( '%d', '%d', '%d', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d' );
	}
}
