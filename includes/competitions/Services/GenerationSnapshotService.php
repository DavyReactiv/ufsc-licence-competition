<?php

namespace UFSC\Competitions\Services;

use UFSC\Competitions\Db;
use UFSC\Competitions\Repositories\CategoryRepository;
use UFSC\Competitions\Repositories\CompetitionRepository;
use UFSC\Competitions\Repositories\EntryRepository;
use UFSC\Competitions\Repositories\FightRepository;
use UFSC\Competitions\Repositories\WeighInRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GenerationSnapshotService {
	private const OPTION_PREFIX = 'ufsc_competition_snapshot_';
	private const INDEX_OPTION  = 'ufsc_competition_snapshot_index';
	private const MAX_INDEX     = 100;

	public function create_snapshot( int $competition_id, string $action_type, array $context = array() ): string {
		$competition_id = absint( $competition_id );
		$action_type    = sanitize_key( $action_type );
		if ( ! $competition_id || '' === $action_type ) {
			$this->log_failure( $competition_id, 'invalid_snapshot_request', $context );
			return '';
		}

		try {
			$payload = $this->build_payload( $competition_id, $action_type, $context );
			$snapshot_id = $this->build_snapshot_id( $competition_id, $action_type );
			$stored = update_option( self::OPTION_PREFIX . $snapshot_id, $payload, false );
			if ( ! $stored && false === get_option( self::OPTION_PREFIX . $snapshot_id, false ) ) {
				$this->log_failure( $competition_id, 'snapshot_option_write_failed', array( 'snapshot_id' => $snapshot_id ) + $context );
				return '';
			}

			$this->append_index( $snapshot_id, $competition_id, $action_type );
			( new LogService() )->audit(
				'snapshot_created',
				$competition_id,
				'snapshot',
				0,
				array(
					'snapshot_id' => $snapshot_id,
					'action_type' => $action_type,
					'summary'     => $payload['summary'] ?? array(),
				)
			);

			return $snapshot_id;
		} catch ( \Throwable $e ) {
			$this->log_failure( $competition_id, 'snapshot_exception', array( 'error' => $e->getMessage() ) + $context );
			return '';
		}
	}

	private function build_payload( int $competition_id, string $action_type, array $context ): array {
		$competition_repo = new CompetitionRepository();
		$category_repo    = new CategoryRepository();
		$entry_repo       = new EntryRepository();
		$fight_repo       = new FightRepository();
		$weighin_repo     = new WeighInRepository();

		$competition = $competition_repo->get( $competition_id, true );
		$categories  = $category_repo->list( array( 'view' => 'all', 'competition_id' => $competition_id ), 5000, 0 );
		$entries     = $entry_repo->list_with_details( array( 'view' => 'all', 'competition_id' => $competition_id, 'include_deleted' => true ), 5000, 0 );
		$fights      = $fight_repo->list( array( 'view' => 'all', 'competition_id' => $competition_id ), 5000, 0 );
		$entry_ids   = array_values( array_filter( array_map( 'absint', wp_list_pluck( $entries, 'id' ) ) ) );
		$weighins    = $weighin_repo->get_for_entries( $competition_id, $entry_ids );
		$settings    = class_exists( FightAutoGenerationService::class ) ? FightAutoGenerationService::get_settings( $competition_id ) : array();
		$draft       = class_exists( FightAutoGenerationService::class ) ? FightAutoGenerationService::get_draft( $competition_id ) : array();
		$surfaces    = function_exists( 'ufsc_competition_get_surfaces' ) ? ufsc_competition_get_surfaces( $competition_id ) : array();

		return array(
			'created_at'     => current_time( 'mysql' ),
			'created_by'     => get_current_user_id() ?: null,
			'competition_id' => $competition_id,
			'action_type'    => $action_type,
			'context'        => $this->sanitize_deep( $context ),
			'competition'    => $this->normalize_rows( array( $competition ) )[0] ?? null,
			'categories'     => $this->normalize_rows( $categories ),
			'entries'        => $this->normalize_rows( $entries ),
			'weighins'       => $this->normalize_rows( array_values( $weighins ) ),
			'fights'         => $this->normalize_rows( $fights ),
			'settings'       => $this->sanitize_deep( $settings ),
			'surfaces'       => $this->sanitize_deep( is_array( $surfaces ) ? $surfaces : array() ),
			'draft'          => $this->sanitize_deep( is_array( $draft ) ? $draft : array() ),
			'summary'        => array(
				'categories' => count( (array) $categories ),
				'entries'    => count( (array) $entries ),
				'weighins'   => count( (array) $weighins ),
				'fights'     => count( (array) $fights ),
			),
		);
	}

	private function normalize_rows( array $rows ): array {
		$normalized = array();
		foreach ( $rows as $row ) {
			if ( null === $row ) {
				continue;
			}
			$normalized[] = $this->sanitize_deep( is_object( $row ) ? get_object_vars( $row ) : (array) $row );
		}
		return $normalized;
	}

	private function sanitize_deep( $value ) {
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $key => $item ) {
				$out[ is_int( $key ) ? $key : sanitize_key( (string) $key ) ] = $this->sanitize_deep( $item );
			}
			return $out;
		}
		if ( is_object( $value ) ) {
			return $this->sanitize_deep( get_object_vars( $value ) );
		}
		if ( is_string( $value ) ) {
			return sanitize_textarea_field( $value );
		}
		return is_scalar( $value ) || null === $value ? $value : '';
	}

	private function build_snapshot_id( int $competition_id, string $action_type ): string {
		return sprintf( '%d_%s_%s_%s', $competition_id, $action_type, gmdate( 'YmdHis' ), wp_generate_password( 8, false, false ) );
	}

	private function append_index( string $snapshot_id, int $competition_id, string $action_type ): void {
		$index = get_option( self::INDEX_OPTION, array() );
		$index = is_array( $index ) ? $index : array();
		$index[] = array(
			'snapshot_id'    => $snapshot_id,
			'competition_id' => $competition_id,
			'action_type'    => $action_type,
			'created_at'     => current_time( 'mysql' ),
			'created_by'     => get_current_user_id() ?: null,
		);
		if ( count( $index ) > self::MAX_INDEX ) {
			$index = array_slice( $index, -1 * self::MAX_INDEX );
		}
		update_option( self::INDEX_OPTION, $index, false );
	}

	private function log_failure( int $competition_id, string $reason, array $context = array() ): void {
		( new LogService() )->audit(
			'snapshot_failed',
			$competition_id,
			'snapshot',
			0,
			array(
				'reason' => sanitize_key( $reason ),
				'context' => $context,
			)
		);
	}
}
