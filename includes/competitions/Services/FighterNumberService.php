<?php

namespace UFSC\Competitions\Services;

use UFSC\Competitions\Repositories\WeighInRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FighterNumberService {
	/**
	 * @param int   $competition_id
	 * @param array $entries
	 * @return array<int,int>
	 */
	public static function build_map_from_entries( int $competition_id, array $entries ): array {
		$entry_ids = array_values( array_filter( array_map( 'absint', wp_list_pluck( $entries, 'id' ) ) ) );
		return self::build_map( $competition_id, $entry_ids );
	}

	/**
	 * @param int   $competition_id
	 * @param int[] $entry_ids
	 * @return array<int,int>
	 */
	public static function build_map( int $competition_id, array $entry_ids ): array {
		$competition_id = absint( $competition_id );
		$entry_ids      = array_values( array_filter( array_map( 'absint', $entry_ids ) ) );
		if ( ! $competition_id || empty( $entry_ids ) ) {
			return array();
		}

		$weighins = new WeighInRepository();
		$rows     = $weighins->get_for_entries( $competition_id, $entry_ids );
		$map      = array();
		foreach ( $rows as $entry_id => $row ) {
			$meta = json_decode( (string) ( $row->notes ?? '' ), true );
			if ( ! is_array( $meta ) ) {
				continue;
			}
			$fighter_number = absint( $meta['fighter_number'] ?? 0 );
			if ( $fighter_number > 0 ) {
				$map[ (int) $entry_id ] = $fighter_number;
			}
		}

		return $map;
	}

	/**
	 * @param object|null     $entry
	 * @param array<int,int>  $numbers_by_entry
	 */
	public static function resolve_for_entry( $entry, array $numbers_by_entry = array() ): int {
		if ( ! $entry ) {
			return 0;
		}

		$entry_id = (int) ( $entry->id ?? 0 );
		$number   = (int) ( $entry->fighter_number ?? $entry->competition_number ?? 0 );
		if ( $number > 0 ) {
			return $number;
		}

		return $entry_id > 0 ? (int) ( $numbers_by_entry[ $entry_id ] ?? 0 ) : 0;
	}
}
