<?php

namespace UFSC\Competitions\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FightGenerationPremiumPlanner {
	public static function plan( array $entries, array $context = array() ): array {
		$context = self::normalize_context( $context );
		$entries = self::normalize_entries( $entries );
		$context['entries_count'] = count( $entries );

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
		$previous_round_fight_nos = array();

		for ( $i = 0; $i < $bracket_size; $i += 2 ) {
			$red  = $seeded[ $i ] ?? null;
			$blue = $seeded[ $i + 1 ] ?? null;

			if ( null === $red && null === $blue ) {
				continue;
			}

			if ( null === $red || null === $blue ) {
				$anomalies[] = FightGenerationAnomalyReporter::anomaly(
					'bye_required',
					false,
					array(
						'fight_no' => $next_no,
						'red_entry_id' => (int) ( $red->id ?? 0 ),
						'blue_entry_id' => (int) ( $blue->id ?? 0 ),
					)
				);
			}

			$fights[] = self::build_fight_payload( $context, $next_no, $red, $blue, 1, 'elimination' );
			$previous_round_fight_nos[] = $next_no;
			$next_no++;
		}

		$round_no = 2;
		while ( count( $previous_round_fight_nos ) > 1 ) {
			$current_round_fight_nos = array();
			for ( $i = 0; $i < count( $previous_round_fight_nos ); $i += 2 ) {
				$source_red = (int) ( $previous_round_fight_nos[ $i ] ?? 0 );
				$source_blue = (int) ( $previous_round_fight_nos[ $i + 1 ] ?? 0 );
				if ( $source_red <= 0 || $source_blue <= 0 ) {
					continue;
				}
				$fights[] = self::build_placeholder_payload( $context, $next_no, $round_no, $source_red, $source_blue, $bracket_size );
				$current_round_fight_nos[] = $next_no;
				$next_no++;
			}
			$previous_round_fight_nos = $current_round_fight_nos;
			$round_no++;
		}

		return $fights;
	}

	private static function build_fight_payload( array $context, int $fight_no, $red_entry, $blue_entry, int $round_no, string $format ): array {
		$has_red = $red_entry && ! empty( $red_entry->id );
		$has_blue = $blue_entry && ! empty( $blue_entry->id );
		$is_bye = ( $has_red xor $has_blue );

		$case_type = self::resolve_case_type( $format, $is_bye, (int) ( $context['entries_count'] ?? 0 ) );
		$phase = 'direct_final' === $case_type ? 'Finale directe' : 'Tour 1';

		return array(
			'competition_id'     => (int) $context['competition_id'],
			'category_id'        => (int) $context['category_id'],
			'fight_no'           => $fight_no,
			'ring'               => '',
			'round_no'           => $round_no,
			'round_label'        => $phase,
			'phase'              => $phase,
			'type'               => $is_bye ? 'bye' : 'fight',
			'red_entry_id'       => $red_entry ? (int) ( $red_entry->id ?? 0 ) : null,
			'blue_entry_id'      => $blue_entry ? (int) ( $blue_entry->id ?? 0 ) : null,
			'winner_entry_id'    => $is_bye ? ( $has_red ? (int) $red_entry->id : (int) $blue_entry->id ) : null,
			'status'             => $is_bye ? 'bye' : 'scheduled',
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
			'planner_bye'        => $is_bye ? 1 : 0,
			'case_type'          => $case_type,
			'group_key'          => (string) ( $context['group_key'] ?? '' ),
		);
	}

	private static function build_placeholder_payload( array $context, int $fight_no, int $round_no, int $source_red, int $source_blue, int $bracket_size ): array {
		$round_labels = array(
			2  => array( 1 => 'Finale' ),
			4  => array( 1 => 'Demi-finale', 2 => 'Finale' ),
			8  => array( 1 => 'Quart de finale', 2 => 'Demi-finale', 3 => 'Finale' ),
			16 => array( 1 => 'Huitième de finale', 2 => 'Quart de finale', 3 => 'Demi-finale', 4 => 'Finale' ),
			32 => array( 1 => 'Seizième de finale', 2 => 'Huitième de finale', 3 => 'Quart de finale', 4 => 'Demi-finale', 5 => 'Finale' ),
		);
		$label = $round_labels[ $bracket_size ][ $round_no ] ?? ( 'Tour ' . $round_no );

		return array(
			'competition_id'       => (int) $context['competition_id'],
			'category_id'          => (int) $context['category_id'],
			'fight_no'             => $fight_no,
			'ring'                 => '',
			'round_no'             => $round_no,
			'round_label'          => $label,
			'phase'                => $label,
			'type'                 => 'placeholder',
			'status'               => 'placeholder',
			'red_entry_id'         => null,
			'blue_entry_id'        => null,
			'winner_entry_id'      => null,
			'source_red_fight_no'  => $source_red,
			'source_blue_fight_no' => $source_blue,
			'planner_format'       => 'elimination',
			'planner_bye'          => 0,
			'case_type'            => 'bracket',
			'group_key'            => (string) ( $context['group_key'] ?? '' ),
		);
	}

	private static function resolve_case_type( string $format, bool $is_bye, int $entries_count = 0 ): string {
		if ( $is_bye ) {
			return 'bracket_with_bye';
		}
		if ( 'round_robin' === $format ) {
			return 3 === $entries_count ? 'pool_3' : 'pool';
		}
		if ( 'elimination' === $format && 2 === $entries_count ) {
			return 'direct_final';
		}
		return 'elimination' === $format ? 'bracket' : sanitize_key( $format );
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
			'group_key' => sanitize_text_field( (string) ( $context['group_key'] ?? '' ) ),
			'require_weight_data' => ! empty( $context['require_weight_data'] ),
			'require_category_data' => ! empty( $context['require_category_data'] ),
			'allow_mixed_participant_types' => ! empty( $context['allow_mixed_participant_types'] ),
		);
	}

	private static function result( array $fights, array $anomalies, bool $fallback, array $context, string $fallback_reason, int $entries_count = 0 ): array {
		$bye_count = 0;
		$placeholder_count = 0;
		$case_types = array();
		foreach ( $fights as $fight ) {
			if ( 'bye' === (string) ( $fight['status'] ?? '' ) || ! empty( $fight['planner_bye'] ) ) {
				$bye_count++;
			}
			if ( 'placeholder' === (string) ( $fight['status'] ?? '' ) || 'placeholder' === (string) ( $fight['type'] ?? '' ) ) {
				$placeholder_count++;
			}
			$case_type = sanitize_key( (string) ( $fight['case_type'] ?? '' ) );
			if ( '' !== $case_type ) {
				$case_types[ $case_type ] = true;
			}
		}

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
				'bye_count' => $bye_count,
				'placeholder_count' => $placeholder_count,
				'case_types' => array_keys( $case_types ),
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
