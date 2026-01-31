<?php

namespace UFSC\Competitions\Repositories;

use UFSC\Competitions\Db;
use UFSC\Competitions\Services\LogService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EntryRepository {
	private $logger;

	public function __construct() {
		$this->logger = new LogService();
	}

	public function get( $id, $include_deleted = false ) {
		global $wpdb;

		$where_deleted = $include_deleted ? '' : 'AND deleted_at IS NULL';

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM " . Db::entries_table() . " WHERE id = %d {$where_deleted}",
				absint( $id )
			)
		);
	}

	public function get_by_competition_licensee( $competition_id, $licensee_id ) {
		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . Db::entries_table() . ' WHERE competition_id = %d AND licensee_id = %d',
				absint( $competition_id ),
				absint( $licensee_id )
			)
		);
	}

	public function list( array $filters, $limit, $offset ) {
		global $wpdb;

		$where = $this->build_where( $filters );
		$sql   = "SELECT * FROM " . Db::entries_table() . " {$where} ORDER BY created_at DESC";
		$sql  .= $wpdb->prepare( ' LIMIT %d OFFSET %d', absint( $limit ), absint( $offset ) );

		return $wpdb->get_results( $sql );
	}

	public function count( array $filters ) {
		global $wpdb;

		$where = $this->build_where( $filters );

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . Db::entries_table() . " {$where}" );
	}

	public function insert( array $data ) {
		global $wpdb;

		$prepared = $this->sanitize( $data );
		$prepared['created_at'] = current_time( 'mysql' );
		$prepared['updated_at'] = current_time( 'mysql' );
		$prepared['created_by'] = get_current_user_id() ?: null;
		$prepared['updated_by'] = get_current_user_id() ?: null;

		$wpdb->insert( Db::entries_table(), $prepared, $this->build_formats( $prepared ) );
		$id = (int) $wpdb->insert_id;

		$this->logger->log( 'create', 'entry', $id, 'Entry created.', array( 'data' => $prepared ) );

		return $id;
	}

	public function update( $id, array $data ) {
		global $wpdb;

		$prepared = $this->sanitize( $data );
		$prepared['updated_at'] = current_time( 'mysql' );
		$prepared['updated_by'] = get_current_user_id() ?: null;

		$updated = $wpdb->update(
			Db::entries_table(),
			$prepared,
			array( 'id' => absint( $id ) ),
			$this->build_formats( $prepared ),
			array( '%d' )
		);

		$this->logger->log( 'update', 'entry', $id, 'Entry updated.', array( 'data' => $prepared ) );

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

		$deleted = $wpdb->delete( Db::entries_table(), array( 'id' => absint( $id ) ), array( '%d' ) );

		$this->logger->log( 'delete', 'entry', $id, 'Entry deleted permanently.', array() );

		return $deleted;
	}

	private function set_deleted_at( $id, $deleted_at, $action ) {
		global $wpdb;

		$updated = $wpdb->update(
			Db::entries_table(),
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

		$this->logger->log( $action, 'entry', $id, 'Entry status changed.', array( 'deleted_at' => $deleted_at ) );

		return $updated;
	}

	private function sanitize( array $data ) {
		$table = Db::entries_table();
		$allowed_status = array( 'draft', 'submitted', 'validated', 'rejected', 'cancelled', 'withdrawn' );
		$status = sanitize_key( $data['status'] ?? 'draft' );
		if ( ! in_array( $status, $allowed_status, true ) ) {
			$status = 'draft';
		}

		$payload = array(
			'competition_id' => absint( $data['competition_id'] ?? 0 ),
			'category_id'    => isset( $data['category_id'] ) && '' !== $data['category_id'] ? absint( $data['category_id'] ) : null,
			'club_id'        => isset( $data['club_id'] ) && '' !== $data['club_id'] ? absint( $data['club_id'] ) : null,
			'licensee_id'    => absint( $data['licensee_id'] ?? 0 ),
			'status'         => $status,
			'assigned_at'    => isset( $data['assigned_at'] ) ? sanitize_text_field( $data['assigned_at'] ) : null,
		);

		if ( Db::has_table_column( $table, 'weight_kg' ) ) {
			$weight = isset( $data['weight_kg'] ) ? (float) str_replace( ',', '.', (string) $data['weight_kg'] ) : null;
			if ( null !== $weight && ( $weight <= 0 || $weight > 300 ) ) {
				$weight = null;
			}
			$payload['weight_kg'] = $weight;
		}

		if ( Db::has_table_column( $table, 'weight_class' ) ) {
			$weight_class = isset( $data['weight_class'] ) ? sanitize_text_field( $data['weight_class'] ) : '';
			$payload['weight_class'] = '' !== $weight_class ? $weight_class : null;
		}

		return $payload;
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

		if ( ! empty( $filters['competition_ids'] ) && is_array( $filters['competition_ids'] ) ) {
			$ids = array_filter( array_map( 'absint', $filters['competition_ids'] ) );
			if ( $ids ) {
				$where[] = 'competition_id IN (' . implode( ',', $ids ) . ')';
			}
		}

		if ( ! empty( $filters['status'] ) ) {
			$where[] = $wpdb->prepare( 'status = %s', sanitize_key( $filters['status'] ) );
		}

		if ( ! empty( $filters['search'] ) ) {
			$like = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
			$where[] = $wpdb->prepare( 'licensee_id LIKE %s', $like );
		}

		return 'WHERE ' . implode( ' AND ', $where );
	}

	private function build_formats( array $data ): array {
		$formats = array();
		foreach ( $data as $value ) {
			if ( is_int( $value ) ) {
				$formats[] = '%d';
			} elseif ( is_float( $value ) ) {
				$formats[] = '%f';
			} else {
				$formats[] = '%s';
			}
		}

		return $formats;
	}
}
