<?php

namespace UFSC\Competitions\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FightGenerationPremiumPlanner {
	public static function plan( array $entries, array $context = array() ): array {
		$context = self::normalize_context( $context );
		$entries = self::normalize_entries( $entries );

		$anomalies = FightGenerationAnomalyReporter::analyze_entries(
			$entries,
			array(
				'require_weight_data' => ! empty( $context['require_weight_data'] ),
				'require_category_data' => ! empty( $context['require_category_data'] ),
				'allow_mixed_participant_types' => ! empty( $context['allow_mixed_participant_types'] ),
			)
		);

		$blocking = self::has_blocking_anomalies( $anomalies );
		if ( $blocking ) {
			return self::result( array(), $anomalies, true, $context, 'blocking_anomalies', count( $entries ) );
		}

		$count = count( $entries );
		if ( $count < 2 ) {
			$anomalies[] = FightGenerationAnomalyReporter::anomaly( 'insufficient_participants', true, array( 'count' => $count ) );
			return self::result( array(), $anomalies, true, $context, 'insufficient_participants', count( $entries ) );
		}

		$format = $context['format'];
		if ( ! in_array( $format, array( 'auto', 'elimination', 'round_robin' ), true ) ) {
			$anomalies[] = FightGenerationAnomalyReporter::anomaly( 'unsupported_format', true, array( 'format' => $format ) );
			return self::result( array(), $anomalies, true, $context, 'unsupported_format', count( $entries ) );
		}

		if ( 'auto' === $format ) {
			$format = ( 3 === $count ) ? 'round_robin' : 'elimination';
		}

		if ( 'round_robin' === $format && $count < 3 ) {
			$anomalies[] = FightGenerationAnomalyReporter::anomaly( 'planner_incomplete', true, array( 'format' => $format, 'count' => $count ) );
			return self::result( array(), $anomalies, true, $context, 'planner_incomplete', count( $entries ) );
		}

		$fights = array();
		if ( 'round_robin' === $format ) {
			$fights = self::build_round_robin_fights( $entries, $context );
		} else {
			$fights = self::build_elimination_fights( $entries, $context, $anomalies );
		}

		if ( empty( $fights ) ) {
			$anomalies[] = FightGenerationAnomalyReporter::anomaly( 'planner_incomplete', true, array( 'format' => $format ) );
			return self::result( array(), $anomalies, true, $context, 'planner_incomplete', count( $entries ) );
		}

		return self::result( $fights, $anomalies, false, $context, '', count( $entries ) );
	}

	private static function build_round_robin_fights( array $entries, array $context ): array {
		$fights = array();
		$next_no = (int) $context['start_no'];
		$count = count( $entries );

		for ( $i = 0; $i < $count; $i++ ) {
			for ( $j = $i + 1; $j < $count; $j++ ) {
				$fights[] = self::build_fight_payload( $context, $next_no, $entries[ $i ], $entries[ $j ], 1, 'round_robin' );
				$next_no++;
			}
		}

		return $fights;
	}

	private static function build_elimination_fights( array $entries, array $context, array &$anomalies ): array {
		$count = count( $entries );
		$bracket_size = self::next_power_of_two( $count );
		if ( $bracket_size < 2 ) {
			$anomalies[] = FightGenerationAnomalyReporter::anomaly( 'invalid_bracket_size', true, array( 'size' => $bracket_size ) );
			return array();
		}

		$seeded = self::seed_with_byes( $entries, $bracket_size );
		$fights = array();
		$next_no = (int) $context['start_no'];

		for ( $i = 0; $i < $bracket_size; $i += 2 ) {
			$red  = $seeded[ $i ] ?? null;
			$blue = $seeded[ $i + 1 ] ?? null;

			if ( null === $red && null === $blue ) {
				continue;
			}

			if ( null === $red || null === $blue ) {
				$anomalies[] = FightGenerationAnomalyReporter::anomaly(
					'invalid_bracket_size',
					false,
					array(
						'fight_no' => $next_no,
						'red_entry_id' => (int) ( $red->id ?? 0 ),
						'blue_entry_id' => (int) ( $blue->id ?? 0 ),
					)
				);
			}

			$fights[] = self::build_fight_payload( $context, $next_no, $red, $blue, 1, 'elimination' );
			$next_no++;
		}

		return $fights;
	}

	private static function build_fight_payload( array $context, int $fight_no, $red_entry, $blue_entry, int $round_no, string $format ): array {
		return array(
			'competition_id'     => (int) $context['competition_id'],
			'category_id'        => (int) $context['category_id'],
			'fight_no'           => $fight_no,
			'ring'               => '',
			'round_no'           => $round_no,
			'red_entry_id'       => $red_entry ? (int) ( $red_entry->id ?? 0 ) : null,
			'blue_entry_id'      => $blue_entry ? (int) ( $blue_entry->id ?? 0 ) : null,
			'winner_entry_id'    => null,
			'status'             => 'scheduled',
			'result_method'      => '',
			'score_red'          => '',
			'score_blue'         => '',
			'scheduled_at'       => null,
			'timing_profile_id'  => null,
			'round_duration'     => null,
			'rounds'             => null,
			'break_duration'     => null,
			'fight_pause'        => null,
			'fight_duration'     => null,
			'planner_format'     => $format,
		);
	}

	private static function normalize_entries( array $entries ): array {
		$normalized = array();
		foreach ( $entries as $entry ) {
			if ( is_object( $entry ) ) {
				$normalized[] = $entry;
			}
		}

		usort(
			$normalized,
			static function( $a, $b ) {
				$aid = (int) ( $a->id ?? 0 );
				$bid = (int) ( $b->id ?? 0 );
				if ( $aid === $bid ) {
					return 0;
				}
				return ( $aid < $bid ) ? -1 : 1;
			}
		);

		return $normalized;
	}

	private static function normalize_context( array $context ): array {
		$format = isset( $context['format'] ) ? sanitize_key( (string) $context['format'] ) : 'auto';
		if ( '' === $format ) {
			$format = 'auto';
		}

		return array(
			'competition_id' => absint( $context['competition_id'] ?? 0 ),
			'category_id' => absint( $context['category_id'] ?? 0 ),
			'start_no' => max( 1, absint( $context['start_no'] ?? 1 ) ),
			'format' => $format,
			'require_weight_data' => ! empty( $context['require_weight_data'] ),
			'require_category_data' => ! empty( $context['require_category_data'] ),
			'allow_mixed_participant_types' => ! empty( $context['allow_mixed_participant_types'] ),
		);
	}

	private static function result( array $fights, array $anomalies, bool $fallback, array $context, string $fallback_reason, int $entries_count = 0 ): array {
		return array(
			'ok'        => ! $fallback && ! empty( $fights ),
			'fights'    => array_values( $fights ),
			'anomalies' => array_values( $anomalies ),
			'fallback'  => $fallback,
			'fallback_reason' => $fallback_reason,
			'context'   => $context,
			'stats'     => array(
				'entries_count' => $entries_count,
				'generated_fights' => count( $fights ),
			),
		);
	}

	private static function has_blocking_anomalies( array $anomalies ): bool {
		foreach ( $anomalies as $anomaly ) {
			if ( ! empty( $anomaly['blocking'] ) ) {
				return true;
			}
		}

		return false;
	}

	private static function next_power_of_two( int $value ): int {
		$size = 1;
		while ( $size < $value ) {
			$size *= 2;
		}

		return $size;
	}

	private static function seed_with_byes( array $entries, int $size ): array {
		$seeded = $entries;
		while ( count( $seeded ) < $size ) {
			$seeded[] = null;
		}

		return $seeded;
	}
}
