<?php

namespace UFSC\Competitions\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FightGenerationAnomalyReporter {
	public static function anomaly( string $code, bool $blocking = true, array $meta = array() ): array {
		return array(
			'code' => sanitize_key( $code ),
			'blocking' => $blocking,
			'severity' => $blocking ? 'error' : 'warning',
			'meta' => $meta,
		);
	}

	public static function missing_required_fields( $entry, array $required_fields ): array {
		$anomalies = array();
		foreach ( $required_fields as $field ) {
			$value = '';
			if ( is_object( $entry ) && isset( $entry->{$field} ) ) {
				$value = (string) $entry->{$field};
			}
			if ( '' === trim( $value ) ) {
				$anomalies[] = 'missing_' . sanitize_key( (string) $field );
			}
		}

		return array_values( array_unique( $anomalies ) );
	}

	public static function analyze_entries( array $entries, array $context = array() ): array {
		$anomalies = array();
		$count     = count( $entries );

		if ( $count < 2 ) {
			$anomalies[] = self::anomaly(
				'insufficient_participants',
				true,
				array( 'count' => $count )
			);
		}

		$seen_ids          = array();
		$participant_types = array();
		$require_weight    = ! empty( $context['require_weight_data'] );
		$require_category  = ! empty( $context['require_category_data'] );
		$allow_mixed_types = ! empty( $context['allow_mixed_participant_types'] );

		foreach ( $entries as $entry ) {
			$entry_id = (int) ( is_object( $entry ) ? ( $entry->id ?? 0 ) : 0 );
			if ( $entry_id <= 0 ) {
				$anomalies[] = self::anomaly( 'missing_id', true );
				continue;
			}

			if ( isset( $seen_ids[ $entry_id ] ) ) {
				$anomalies[] = self::anomaly( 'duplicate_participant', true, array( 'entry_id' => $entry_id ) );
			}
			$seen_ids[ $entry_id ] = true;

			$participant_type = sanitize_key( (string) ( $entry->participant_type ?? 'licensed_ufsc' ) );
			if ( ! in_array( $participant_type, array( 'licensed_ufsc', 'external_non_licensed' ), true ) ) {
				$anomalies[] = self::anomaly(
					'invalid_participant_type',
					true,
					array(
						'entry_id' => $entry_id,
						'participant_type' => $participant_type,
					)
				);
			} else {
				$participant_types[ $participant_type ] = true;
			}

			$first_name = trim( (string) ( $entry->first_name ?? $entry->licensee_first_name ?? '' ) );
			$last_name  = trim( (string) ( $entry->last_name ?? $entry->licensee_last_name ?? '' ) );
			if ( '' === $first_name || '' === $last_name ) {
				$anomalies[] = self::anomaly( 'missing_participant_identity', true, array( 'entry_id' => $entry_id ) );
			}

			if ( $require_weight ) {
				$weight = isset( $entry->weight ) && '' !== (string) $entry->weight
					? (string) $entry->weight
					: (string) ( $entry->weight_kg ?? '' );
				if ( '' === trim( $weight ) ) {
					$anomalies[] = self::anomaly( 'missing_weight_data', true, array( 'entry_id' => $entry_id ) );
				}
			}

			if ( $require_category ) {
				$category = trim( (string) ( $entry->category ?? $entry->category_name ?? '' ) );
				if ( '' === $category ) {
					$anomalies[] = self::anomaly( 'missing_category_data', true, array( 'entry_id' => $entry_id ) );
				}
			}
		}

		if ( ! $allow_mixed_types && count( $participant_types ) > 1 ) {
			$anomalies[] = self::anomaly( 'mixed_incompatible_participants', true );
		}

		return $anomalies;
	}
}
