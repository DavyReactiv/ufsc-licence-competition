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

	public function list( array $filters, $limit, $offset ) {
		global $wpdb;

		$where = $this->build_where( $filters );
		$sql   = "SELECT * FROM " . Db::fights_table() . " {$where} ORDER BY fight_no ASC";
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

		$wpdb->insert( Db::fights_table(), $prepared, $this->get_insert_format() );
		$id = (int) $wpdb->insert_id;

		$this->logger->log( 'create', 'fight', $id, 'Fight created.', array( 'data' => $prepared ) );

		return $id;
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

		if ( ! empty( $filters['competition_id'] ) ) {
			$where[] = $wpdb->prepare( 'competition_id = %d', absint( $filters['competition_id'] ) );
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
}
