<?php

namespace UFSC\Competitions\Services;

use UFSC\Competitions\Entries\EntriesWorkflow;
use UFSC\Competitions\Repositories\EntryRepository;
use UFSC\Competitions\Repositories\WeighInRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only audit of fighter numbers for one competition.
 *
 * Fighter numbers are event-local identifiers used by weigh-ins, printouts,
 * surfaces, results and the future public live view. This service never
 * assigns, changes or removes a number.
 */
class FighterNumberAuditService {
	private $entries;
	private $weighins;

	public function __construct() {
		$this->entries  = new EntryRepository();
		$this->weighins = new WeighInRepository();
	}

	public function audit( int $competition_id ): array {
		$competition_id = absint( $competition_id );
		if ( $competition_id <= 0 ) {
			return $this->empty_result();
		}

		$entries = $this->entries->list_with_details(
			array(
				'competition_id' => $competition_id,
				'view'           => 'all',
			),
			5000,
			0
		);
		$entry_ids = array_values( array_filter( array_map( 'absint', wp_list_pluck( $entries, 'id' ) ) ) );
		$weighins  = $this->weighins->get_for_entries( $competition_id, $entry_ids );

		$assigned                = 0;
		$eligible_without_number = array();
		$premature_numbers       = array();
		$numbers                 = array();

		foreach ( $entries as $entry ) {
			$entry_id = absint( $entry->id ?? 0 );
			if ( $entry_id <= 0 ) {
				continue;
			}

			$row    = $weighins[ $entry_id ] ?? null;
			$meta   = $this->extract_meta( (string) ( $row->notes ?? '' ) );
			$number = absint( $meta['fighter_number'] ?? $entry->fighter_number ?? $entry->competition_number ?? $entry->dossard ?? 0 );
			$status = $this->normalize_weighin_status( (string) ( $row->status ?? '' ), $meta );
			$entry_status = EntriesWorkflow::normalize_status( (string) ( $entry->status ?? '' ) );
			$is_number_ready = 'approved' === $entry_status && in_array( $status, array( 'weighed', 'validated', 'reclassified' ), true );

			if ( $number > 0 ) {
				++$assigned;
				if ( ! isset( $numbers[ $number ] ) ) {
					$numbers[ $number ] = array();
				}
				$numbers[ $number ][] = $entry_id;
			}

			if ( $is_number_ready && $number <= 0 ) {
				$eligible_without_number[] = $entry_id;
			}
			if ( ! $is_number_ready && $number > 0 ) {
				$premature_numbers[] = array(
					'entry_id' => $entry_id,
					'number'   => $number,
					'status'   => $status,
				);
			}
		}

		$duplicates = array();
		foreach ( $numbers as $number => $ids ) {
			if ( count( $ids ) > 1 ) {
				$duplicates[] = array(
					'number'    => (int) $number,
					'entry_ids' => array_values( array_map( 'absint', $ids ) ),
				);
			}
		}

		return array(
			'total_entries'             => count( $entries ),
			'assigned'                  => $assigned,
			'eligible_without_number'   => $eligible_without_number,
			'eligible_missing_count'    => count( $eligible_without_number ),
			'premature_numbers'         => $premature_numbers,
			'premature_count'           => count( $premature_numbers ),
			'duplicates'                => $duplicates,
			'duplicate_count'           => count( $duplicates ),
			'is_healthy'                => empty( $eligible_without_number ) && empty( $premature_numbers ) && empty( $duplicates ),
		);
	}

	private function normalize_weighin_status( string $status, array $meta ): string {
		$status = sanitize_key( $status );
		if ( 'ok' === $status ) {
			$status = 'weighed';
		}
		if ( in_array( $status, array( 'awaiting_reclass', 'waiting_reclassification' ), true ) ) {
			$status = 'awaiting_reclassification';
		}
		if ( ! empty( $meta['reclass_pending'] ) && in_array( $status, array( 'weighed', 'validated' ), true ) ) {
			$status = 'awaiting_reclassification';
		}

		return $status;
	}

	private function extract_meta( string $notes ): array {
		$decoded = json_decode( $notes, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	private function empty_result(): array {
		return array(
			'total_entries'           => 0,
			'assigned'                => 0,
			'eligible_without_number' => array(),
			'eligible_missing_count'  => 0,
			'premature_numbers'       => array(),
			'premature_count'         => 0,
			'duplicates'              => array(),
			'duplicate_count'         => 0,
			'is_healthy'              => true,
		);
	}
}
