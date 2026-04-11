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
	public static function format_corner_label( $fight, $entry, string $corner, array $category_fights ): string {
		if ( $entry ) {
			return self::format_entry_label( $entry );
		}

		$corner = 'blue' === $corner ? 'blue' : 'red';
		$status = strtolower( trim( (string) ( $fight->status ?? '' ) ) );
		$round_no = max( 1, (int) ( $fight->round_no ?? 1 ) );

		if ( 'bye' === $status && 1 === $round_no ) {
			return __( 'BYE', 'ufsc-licence-competition' );
		}

		$refs = self::resolve_previous_fight_refs( $fight, $category_fights );
		$ref_no = 'red' === $corner ? (int) ( $refs['red'] ?? 0 ) : (int) ( $refs['blue'] ?? 0 );
		if ( $ref_no > 0 ) {
			return sprintf( __( 'Vainqueur combat %d', 'ufsc-licence-competition' ), $ref_no );
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
	 * @return array{red:int,blue:int}
	 */
	private static function resolve_previous_fight_refs( $fight, array $category_fights ): array {
		$round_no = max( 1, (int) ( $fight->round_no ?? 1 ) );
		if ( $round_no <= 1 ) {
			return array( 'red' => 0, 'blue' => 0 );
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
			return array( 'red' => 0, 'blue' => 0 );
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
			return array( 'red' => 0, 'blue' => 0 );
		}

		$red_index = $position * 2;
		$blue_index = $red_index + 1;

		return array(
			'red'  => isset( $previous_round_fights[ $red_index ] ) ? (int) ( $previous_round_fights[ $red_index ]->fight_no ?? 0 ) : 0,
			'blue' => isset( $previous_round_fights[ $blue_index ] ) ? (int) ( $previous_round_fights[ $blue_index ]->fight_no ?? 0 ) : 0,
		);
	}

	private static function format_entry_label( $entry ): string {
		$first_name = trim( (string) ( $entry->licensee_first_name ?? '' ) );
		$last_name = trim( (string) ( $entry->licensee_last_name ?? '' ) );

		$label = trim( $first_name . ' ' . $last_name );
		if ( '' !== $label ) {
			return $label;
		}

		return sprintf( __( 'Participant #%d', 'ufsc-licence-competition' ), (int) ( $entry->id ?? 0 ) );
	}
}
