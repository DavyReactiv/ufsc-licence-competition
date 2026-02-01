<?php

namespace UFSC\Competitions\Repositories;

use UFSC\Competitions\Db;
use UFSC\Competitions\Services\LogService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( __NAMESPACE__ . '\\FightRepository', false ) ) {
	return;
}

class FightRepository {
	private const DRAFT_PREFIX = 'ufsc_competitions_fight_draft_';

	private $logger;

	public function __construct() {
		$this->logger = new LogService();
	}

	public function get( $id, $include_deleted = false ) {
		global $wpdb;

		$where_deleted = ( $include_deleted || ! $this->has_deleted_at_column() ) ? '' : 'AND deleted_at IS NULL';

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
		$order_by = $this->build_order_by();
		$sql   = "SELECT * FROM " . Db::fights_table() . " {$where} {$order_by}";
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

		$existing = $this->get( $id, true );
		if ( $existing ) {
			foreach ( array( 'timing_profile_id', 'round_duration', 'rounds', 'break_duration', 'fight_pause', 'fight_duration' ) as $field ) {
				if ( ! array_key_exists( $field, $data ) && isset( $existing->{$field} ) ) {
					$data[ $field ] = $existing->{$field};
				}
			}
		}

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

	public function get_max_fight_no( $competition_id ): int {
		global $wpdb;

		$competition_id = absint( $competition_id );
		if ( ! $competition_id ) {
			return 0;
		}

		if ( ! $this->has_fight_no_column() ) {
			return 0;
		}

		$max = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MAX(fight_no) FROM " . Db::fights_table() . " WHERE competition_id = %d",
				$competition_id
			)
		);

		return absint( $max );
	}

	public function get_draft( int $competition_id ): array {
		if ( ! $competition_id ) {
			return array();
		}

		$draft = get_option( self::DRAFT_PREFIX . $competition_id, array() );
		return is_array( $draft ) ? $draft : array();
	}

	public function save_draft( int $competition_id, array $draft ): bool {
		if ( ! $competition_id ) {
			return false;
		}

		return (bool) update_option( self::DRAFT_PREFIX . $competition_id, $draft, false );
	}

	public function clear_draft( int $competition_id ): bool {
		if ( ! $competition_id ) {
			return false;
		}

		return (bool) delete_option( self::DRAFT_PREFIX . $competition_id );
	}

	public function get_draft_fights( int $competition_id ): array {
		$draft = $this->get_draft( $competition_id );
		$fights = $draft['fights'] ?? array();
		return is_array( $fights ) ? $fights : array();
	}

	public function update_draft_order( int $competition_id, array $ordered_fights, int $start_no ): array {
		$draft = $this->get_draft( $competition_id );
		if ( empty( $draft['fights'] ) || ! is_array( $draft['fights'] ) ) {
			return array();
		}

		$next_no = max( 1, absint( $start_no ) );
		$updated_fights = array();

		foreach ( $ordered_fights as $fight ) {
			if ( ! is_array( $fight ) ) {
				continue;
			}
			$fight['fight_no'] = $next_no;
			$updated_fights[] = $fight;
			$next_no++;
		}

		$draft['fights'] = $updated_fights;
		$this->save_draft( $competition_id, $draft );

		return $updated_fights;
	}

	public function swap_draft_corners( int $competition_id, int $fight_no ): array {
		$draft = $this->get_draft( $competition_id );
		$fights = $draft['fights'] ?? array();
		if ( ! is_array( $fights ) || ! $fights ) {
			return array(
				'ok' => false,
				'message' => __( 'Aucun brouillon disponible.', 'ufsc-licence-competition' ),
			);
		}

		$updated = false;
		foreach ( $fights as $index => $fight ) {
			if ( (int) ( $fight['fight_no'] ?? 0 ) !== $fight_no ) {
				continue;
			}
			$red = $fight['red_entry_id'] ?? null;
			$blue = $fight['blue_entry_id'] ?? null;
			$fights[ $index ]['red_entry_id'] = $blue;
			$fights[ $index ]['blue_entry_id'] = $red;
			$updated = true;
			break;
		}

		if ( ! $updated ) {
			return array(
				'ok' => false,
				'message' => __( 'Combat introuvable dans le brouillon.', 'ufsc-licence-competition' ),
			);
		}

		$draft['fights'] = $fights;
		$this->save_draft( $competition_id, $draft );

		return array(
			'ok' => true,
			'message' => __( 'Couleurs inversées.', 'ufsc-licence-competition' ),
		);
	}

	public function recalc_draft_schedule( int $competition_id, array $settings ): array {
		$draft = $this->get_draft( $competition_id );
		$fights = $draft['fights'] ?? array();
		if ( ! is_array( $fights ) || ! $fights ) {
			return array();
		}

		$fights = \UFSC\Competitions\Services\FightAutoGenerationService::assign_surfaces_and_schedule( $fights, $settings, $competition_id );
		$draft['fights'] = $fights;
		$this->save_draft( $competition_id, $draft );

		return $fights;
	}

	private function set_deleted_at( $id, $deleted_at, $action ) {
		global $wpdb;

		if ( ! $this->has_deleted_at_column() ) {
			$this->logger->log( $action, 'fight', $id, 'Fight status change skipped: deleted_at column missing.', array() );
			return 0;
		}

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
			'timing_profile_id' => isset( $data['timing_profile_id'] ) ? absint( $data['timing_profile_id'] ) : null,
			'round_duration' => isset( $data['round_duration'] ) ? absint( $data['round_duration'] ) : null,
			'rounds' => isset( $data['rounds'] ) ? absint( $data['rounds'] ) : null,
			'break_duration' => isset( $data['break_duration'] ) ? absint( $data['break_duration'] ) : null,
			'fight_pause' => isset( $data['fight_pause'] ) ? absint( $data['fight_pause'] ) : null,
			'fight_duration' => isset( $data['fight_duration'] ) ? absint( $data['fight_duration'] ) : null,
		);
	}

	private function build_where( array $filters ) {
		global $wpdb;

		$where = array( '1=1' );

		$view = $filters['view'] ?? 'all';

		if ( $this->has_deleted_at_column() ) {
			// Soft-delete support: deleted_at NULL = active, NOT NULL = trashed.
			if ( 'trash' === $view ) {
				$where[] = 'deleted_at IS NOT NULL';
			} else {
				$where[] = 'deleted_at IS NULL';
			}
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

	private function build_order_by(): string {
		if ( $this->has_fight_no_column() ) {
			// fight_no is the human-facing fight order sequence.
			return 'ORDER BY fight_no ASC';
		}

		return 'ORDER BY id ASC';
	}

	private function has_deleted_at_column(): bool {
		return Db::has_table_column( Db::fights_table(), 'deleted_at' );
	}

	private function has_fight_no_column(): bool {
		return Db::has_table_column( Db::fights_table(), 'fight_no' );
	}

	private function get_insert_format() {
		return array( '%d', '%d', '%d', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%d', '%d' );
	}

	private function get_update_format() {
		return array( '%d', '%d', '%d', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%d' );
	}
}
