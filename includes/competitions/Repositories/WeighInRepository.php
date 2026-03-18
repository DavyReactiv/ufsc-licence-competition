<?php

namespace UFSC\Competitions\Repositories;

use UFSC\Competitions\Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Repository for competition weigh-ins.
 *
 * Non-destructive, safe for production. Includes a FQCN guard to avoid
 * redeclaration collisions in multi-plugin UFSC environments.
 */
if ( class_exists( __NAMESPACE__ . '\\WeighInRepository', false ) ) {
	return;
}

class WeighInRepository {
	private $has_table_cache = null;

	/**
	 * Check whether the weigh-ins table exists.
	 */
	public function has_table(): bool {
		global $wpdb;

		if ( null !== $this->has_table_cache ) {
			return (bool) $this->has_table_cache;
		}

		$table  = Db::weighins_table();
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		$this->has_table_cache = ( $exists === $table );

		return (bool) $this->has_table_cache;
	}

	/**
	 * Get weigh-in row for a given entry in a competition.
	 *
	 * @return object|null
	 */
	public function get_for_entry( int $competition_id, int $entry_id ) {
		global $wpdb;

		if ( ! $competition_id || ! $entry_id || ! $this->has_table() ) {
			return null;
		}

		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . Db::weighins_table() . ' WHERE competition_id = %d AND entry_id = %d LIMIT 1',
				$competition_id,
				$entry_id
			)
		);
	}

	/**
	 * True if the entry has a valid weigh-in status and (optionally) fits tolerance.
	 *
	 * @param int        $competition_id
	 * @param int        $entry_id
	 * @param float      $competition_tolerance Allowed delta in kg (>=0).
	 * @param float|null $entry_weight          Optional reference weight (e.g. declared/expected).
	 */
	public function has_valid_weighin( int $competition_id, int $entry_id, float $competition_tolerance, ?float $entry_weight = null ): bool {
		$row = $this->get_for_entry( $competition_id, $entry_id );
		return $this->is_valid_weighin_row( $row, $competition_tolerance, $entry_weight );
	}

	/**
	 * Batch-load weigh-ins for entry ids in one query.
	 *
	 * @param int   $competition_id Competition id.
	 * @param int[] $entry_ids Entry ids.
	 * @return array<int,object> Map entry_id => weigh-in row.
	 */
	public function get_for_entries( int $competition_id, array $entry_ids ): array {
		global $wpdb;

		$competition_id = absint( $competition_id );
		$entry_ids      = array_values( array_filter( array_map( 'absint', $entry_ids ) ) );
		if ( ! $competition_id || empty( $entry_ids ) || ! $this->has_table() ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $entry_ids ), '%d' ) );
		$params       = array_merge( array( $competition_id ), $entry_ids );
		$sql          = 'SELECT * FROM ' . Db::weighins_table() . " WHERE competition_id = %d AND entry_id IN ({$placeholders})";
		$rows         = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$indexed = array();
		foreach ( $rows as $row ) {
			$key = absint( $row->entry_id ?? 0 );
			if ( $key ) {
				$indexed[ $key ] = $row;
			}
		}

		return $indexed;
	}

	/**
	 * Validate a weigh-in row according to legacy status + tolerance rules.
	 *
	 * @param object|null $row Weigh-in row.
	 * @param float       $competition_tolerance Allowed delta in kg (>=0).
	 * @param float|null  $entry_weight Optional reference weight.
	 */
	public function is_valid_weighin_row( $row, float $competition_tolerance, ?float $entry_weight = null ): bool {
		if ( ! $row ) {
			return false;
		}

		$status = sanitize_key( (string) ( $row->status ?? '' ) );

		// Accept legacy/compatible statuses.
		if ( in_array( $status, array( 'ok', 'weighed', 'validated' ), true ) ) {

			// If no reference weight is provided, status alone is considered valid.
			if ( null === $entry_weight ) {
				return true;
			}

			// If measured weight is missing, treat as valid when status is OK (legacy tolerance behavior).
			if ( ! isset( $row->weight_measured ) || '' === (string) $row->weight_measured ) {
				return true;
			}

			$measured = (float) $row->weight_measured;
			$delta    = abs( $measured - $entry_weight );

			return $delta <= max( 0.0, $competition_tolerance );
		}

		return false;
	}
}
