<?php

namespace UFSC\Competitions\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FightDisplayService {
	/**
	 * @param object $fight
	 * @param object|null $entry
	 * @param string $corner
	 * @param array<int,object> $category_fights
	 */
	public static function format_corner_label( $fight, $entry, string $corner, array $category_fights, array $context = array() ): string {
		if ( $entry ) {
			return self::format_entry_label( $entry, $context );
		}

		$corner = 'blue' === $corner ? 'blue' : 'red';
		$status = strtolower( trim( (string) ( $fight->status ?? '' ) ) );
		if ( 'bye' === $status ) {
			return __( 'BYE', 'ufsc-licence-competition' );
		}

		$refs = self::resolve_previous_fight_refs( $fight, $category_fights );
		$reference_fight = 'red' === $corner ? ( $refs['red'] ?? null ) : ( $refs['blue'] ?? null );
		if ( is_object( $reference_fight ) ) {
			return self::format_winner_reference_label( $reference_fight, $category_fights );
		}

		return __( 'À déterminer', 'ufsc-licence-competition' );
	}

	/**
	 * @param object $fight
	 * @param array<int,object> $category_fights
	 */
	public static function format_phase_label( $fight, array $category_fights ): string {
		$round_no = max( 1, (int) ( $fight->round_no ?? 1 ) );
		$max_round = self::get_max_round_no( $category_fights );
		$remaining = max( 0, $max_round - $round_no );

		if ( 0 === $remaining ) {
			return __( 'Finale', 'ufsc-licence-competition' );
		}
		if ( 1 === $remaining ) {
			return __( 'Demi-finale', 'ufsc-licence-competition' );
		}
		if ( 2 === $remaining ) {
			return __( 'Quart de finale', 'ufsc-licence-competition' );
		}
		if ( 3 === $remaining ) {
			return __( 'Huitième de finale', 'ufsc-licence-competition' );
		}
		if ( 4 === $remaining ) {
			return __( 'Seizième de finale', 'ufsc-licence-competition' );
		}

		return __( 'Qualification', 'ufsc-licence-competition' );
	}

	private static function get_max_round_no( array $category_fights ): int {
		$max = 1;
		foreach ( $category_fights as $fight ) {
			$round_no = (int) ( $fight->round_no ?? 1 );
			if ( $round_no > $max ) {
				$max = $round_no;
			}
		}
		return $max;
	}

	/**
	 * @param object $fight
	 * @param array<int,object> $category_fights
	 * @return array{red:object|null,blue:object|null}
	 */
	private static function resolve_previous_fight_refs( $fight, array $category_fights ): array {
		$round_no = max( 1, (int) ( $fight->round_no ?? 1 ) );
		if ( $round_no <= 1 ) {
			return array( 'red' => null, 'blue' => null );
		}

		$current_round_fights = array_values(
			array_filter(
				$category_fights,
				static function ( $candidate ) use ( $round_no, $fight ) {
					return (int) ( $candidate->round_no ?? 1 ) === $round_no
						&& (int) ( $candidate->competition_id ?? 0 ) === (int) ( $fight->competition_id ?? 0 )
						&& (int) ( $candidate->category_id ?? 0 ) === (int) ( $fight->category_id ?? 0 );
				}
			)
		);

		$previous_round_fights = array_values(
			array_filter(
				$category_fights,
				static function ( $candidate ) use ( $round_no, $fight ) {
					return (int) ( $candidate->round_no ?? 1 ) === ( $round_no - 1 )
						&& (int) ( $candidate->competition_id ?? 0 ) === (int) ( $fight->competition_id ?? 0 )
						&& (int) ( $candidate->category_id ?? 0 ) === (int) ( $fight->category_id ?? 0 );
				}
			)
		);

		if ( ! $current_round_fights || ! $previous_round_fights ) {
			return array( 'red' => null, 'blue' => null );
		}

		usort(
			$current_round_fights,
			static function ( $a, $b ) {
				return (int) ( $a->fight_no ?? 0 ) <=> (int) ( $b->fight_no ?? 0 );
			}
		);
		usort(
			$previous_round_fights,
			static function ( $a, $b ) {
				return (int) ( $a->fight_no ?? 0 ) <=> (int) ( $b->fight_no ?? 0 );
			}
		);

		$position = null;
		foreach ( $current_round_fights as $index => $current_round_fight ) {
			if ( (int) ( $current_round_fight->id ?? 0 ) === (int) ( $fight->id ?? 0 ) ) {
				$position = $index;
				break;
			}
		}
		if ( null === $position ) {
			return array( 'red' => null, 'blue' => null );
		}

		$red_index = $position * 2;
		$blue_index = $red_index + 1;

		return array(
			'red'  => $previous_round_fights[ $red_index ] ?? null,
			'blue' => $previous_round_fights[ $blue_index ] ?? null,
		);
	}

	private static function format_winner_reference_label( $reference_fight, array $category_fights ): string {
		$fight_no = (int) ( $reference_fight->fight_no ?? 0 );
		if ( $fight_no <= 0 ) {
			return __( 'À déterminer', 'ufsc-licence-competition' );
		}

		$round_no = max( 1, (int) ( $reference_fight->round_no ?? 1 ) );
		$max_round = self::get_max_round_no( $category_fights );
		$remaining = max( 0, $max_round - $round_no );
		$index_in_round = self::get_index_in_round( $reference_fight, $category_fights );
		$slot_no = $index_in_round + 1;

		if ( 0 === $remaining ) {
			return __( 'Vainqueur finale', 'ufsc-licence-competition' );
		}
		if ( 1 === $remaining ) {
			return sprintf( __( 'Vainqueur demi-finale %d', 'ufsc-licence-competition' ), $slot_no );
		}
		if ( 2 === $remaining ) {
			return sprintf( __( 'Vainqueur quart de finale %d', 'ufsc-licence-competition' ), $slot_no );
		}
		if ( 3 === $remaining ) {
			return sprintf( __( 'Vainqueur huitième de finale %d', 'ufsc-licence-competition' ), $slot_no );
		}

		return sprintf( __( 'Vainqueur combat %d', 'ufsc-licence-competition' ), $fight_no );
	}

	private static function get_index_in_round( $reference_fight, array $category_fights ): int {
		$round_no = max( 1, (int) ( $reference_fight->round_no ?? 1 ) );
		$round_fights = array_values(
			array_filter(
				$category_fights,
				static function ( $candidate ) use ( $round_no, $reference_fight ) {
					return (int) ( $candidate->round_no ?? 1 ) === $round_no
						&& (int) ( $candidate->competition_id ?? 0 ) === (int) ( $reference_fight->competition_id ?? 0 )
						&& (int) ( $candidate->category_id ?? 0 ) === (int) ( $reference_fight->category_id ?? 0 );
				}
			)
		);

		usort(
			$round_fights,
			static function ( $a, $b ) {
				return (int) ( $a->fight_no ?? 0 ) <=> (int) ( $b->fight_no ?? 0 );
			}
		);

		foreach ( $round_fights as $index => $round_fight ) {
			if ( (int) ( $round_fight->id ?? 0 ) === (int) ( $reference_fight->id ?? 0 ) ) {
				return $index;
			}
		}

		return 0;
	}

	private static function format_entry_label( $entry, array $context = array() ): string {
		$first_name = trim( (string) ( $entry->licensee_first_name ?? '' ) );
		$last_name = trim( (string) ( $entry->licensee_last_name ?? '' ) );
		$numbers_by_entry = isset( $context['fighter_numbers_by_entry'] ) && is_array( $context['fighter_numbers_by_entry'] )
			? $context['fighter_numbers_by_entry']
			: array();
		$entry_id = (int) ( $entry->id ?? 0 );
		$fighter_number = (int) ( $entry->fighter_number ?? $entry->competition_number ?? 0 );
		if ( $fighter_number <= 0 && $entry_id > 0 ) {
			$fighter_number = (int) ( $numbers_by_entry[ $entry_id ] ?? 0 );
		}

		$label = trim( $first_name . ' ' . $last_name );
		if ( $fighter_number > 0 ) {
			$prefix = '#' . $fighter_number;
			$label  = '' !== $label ? $prefix . ' · ' . $label : $prefix;
		}
		if ( '' !== $label ) {
			return $label;
		}

		return sprintf( __( 'Participant #%d', 'ufsc-licence-competition' ), $entry_id );
	}
}
