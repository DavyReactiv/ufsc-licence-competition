<?php

namespace UFSC\Competitions\Repositories;

use UFSC\Competitions\Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WeighInRepository {
	public function has_table(): bool {
		global $wpdb;
		$table = Db::weighins_table();
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		return $exists === $table;
	}

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

	public function has_valid_weighin( int $competition_id, int $entry_id, float $competition_tolerance, ?float $entry_weight = null ): bool {
		$row = $this->get_for_entry( $competition_id, $entry_id );
		if ( ! $row ) {
			return false;
		}

		$status = sanitize_key( (string) ( $row->status ?? '' ) );
		if ( in_array( $status, array( 'ok', 'weighed', 'validated' ), true ) ) {
			if ( null === $entry_weight || ! isset( $row->weight_measured ) || '' === (string) $row->weight_measured ) {
				return true;
			}

			$measured = (float) $row->weight_measured;
			$delta = abs( $measured - $entry_weight );
			return $delta <= max( 0.0, $competition_tolerance );
		}

		return false;
	}
}
