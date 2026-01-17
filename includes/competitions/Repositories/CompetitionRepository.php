<?php

namespace UFSC\Competitions\Repositories;

use UFSC\Competitions\Db;
use UFSC\Competitions\Services\LogService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CompetitionRepository {
	private $logger;

	public function __construct() {
		$this->logger = new LogService();
	}

	public function get( $id, $include_deleted = false ) {
		global $wpdb;

		$where_deleted = $include_deleted ? '' : 'AND deleted_at IS NULL';

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM " . Db::competitions_table() . " WHERE id = %d {$where_deleted}",
				absint( $id )
			)
		);
	}

	public function count( array $filters ) {
		global $wpdb;

		$where = $this->build_where( $filters );

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . Db::competitions_table() . " {$where}" );
	}

	public function list( array $filters, $limit, $offset ) {
		global $wpdb;

		$where = $this->build_where( $filters );
		$sql   = "SELECT * FROM " . Db::competitions_table() . " {$where} ORDER BY start_date DESC, id DESC";
		$sql  .= $wpdb->prepare( ' LIMIT %d OFFSET %d', absint( $limit ), absint( $offset ) );

		return $wpdb->get_results( $sql );
	}

	public function insert( array $data ) {
		global $wpdb;

		$prepared = $this->sanitize( $data );

		$prepared['created_at'] = current_time( 'mysql' );
		$prepared['updated_at'] = current_time( 'mysql' );
		$prepared['created_by'] = get_current_user_id() ?: null;
		$prepared['updated_by'] = get_current_user_id() ?: null;

		$wpdb->insert( Db::competitions_table(), $prepared, $this->get_insert_format() );
		$id = (int) $wpdb->insert_id;

		$this->logger->log( 'create', 'competition', $id, 'Competition created.', array( 'data' => $prepared ) );

		return $id;
	}

	public function update( $id, array $data ) {
		global $wpdb;

		$prepared = $this->sanitize( $data );
		$prepared['updated_at'] = current_time( 'mysql' );
		$prepared['updated_by'] = get_current_user_id() ?: null;

		$updated = $wpdb->update(
			Db::competitions_table(),
			$prepared,
			array( 'id' => absint( $id ) ),
			$this->get_update_format(),
			array( '%d' )
		);

		$this->logger->log( 'update', 'competition', $id, 'Competition updated.', array( 'data' => $prepared ) );

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

		$deleted = $wpdb->delete( Db::competitions_table(), array( 'id' => absint( $id ) ), array( '%d' ) );

		$this->logger->log( 'delete', 'competition', $id, 'Competition deleted permanently.', array() );

		return $deleted;
	}

	private function set_deleted_at( $id, $deleted_at, $action ) {
		global $wpdb;

		$updated = $wpdb->update(
			Db::competitions_table(),
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

		$this->logger->log( $action, 'competition', $id, 'Competition status changed.', array( 'deleted_at' => $deleted_at ) );

		return $updated;
	}

	private function sanitize( array $data ) {
		$status_options = array( 'draft', 'preparing', 'open', 'running', 'closed', 'archived' );

		$status = sanitize_text_field( $data['status'] ?? 'draft' );
		if ( ! in_array( $status, $status_options, true ) ) {
			$status = 'draft';
		}

		return array(
			'name'                 => sanitize_text_field( $data['name'] ?? '' ),
			'discipline'           => sanitize_text_field( $data['discipline'] ?? '' ),
			'type'                 => sanitize_text_field( $data['type'] ?? '' ),
			'season'               => sanitize_text_field( $data['season'] ?? '' ),
			'location'             => sanitize_text_field( $data['location'] ?? '' ),
			'start_date'           => $this->sanitize_date( $data['start_date'] ?? null ),
			'end_date'             => $this->sanitize_date( $data['end_date'] ?? null ),
			'registration_deadline'=> $this->sanitize_date( $data['registration_deadline'] ?? null ),
			'status'               => $status,
			'age_reference'        => sanitize_text_field( $data['age_reference'] ?? '12-31' ),
			'weight_tolerance'     => isset( $data['weight_tolerance'] ) ? (float) $data['weight_tolerance'] : 1.0,
			'allowed_formats'      => sanitize_text_field( $data['allowed_formats'] ?? '' ),
		);
	}

	private function sanitize_date( $value ) {
		$value = sanitize_text_field( (string) $value );
		if ( '' === $value ) {
			return null;
		}

		$date = date_create_from_format( 'Y-m-d', $value );
		if ( $date && $date->format( 'Y-m-d' ) === $value ) {
			return $value;
		}

		return null;
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

		if ( ! empty( $filters['status'] ) ) {
			$where[] = $wpdb->prepare( 'status = %s', sanitize_text_field( $filters['status'] ) );
		}

		if ( ! empty( $filters['discipline'] ) ) {
			$where[] = $wpdb->prepare( 'discipline = %s', sanitize_text_field( $filters['discipline'] ) );
		}

		if ( ! empty( $filters['search'] ) ) {
			$like = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
			$where[] = $wpdb->prepare( '(name LIKE %s OR location LIKE %s)', $like, $like );
		}

		return 'WHERE ' . implode( ' AND ', $where );
	}

	private function get_insert_format() {
		return array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%d', '%d', '%s', '%s' );
	}

	private function get_update_format() {
		return array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%d' );
	}
}
