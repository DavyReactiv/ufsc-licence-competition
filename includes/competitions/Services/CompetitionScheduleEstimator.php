<?php

namespace UFSC\Competitions\Services;

use UFSC\Competitions\Repositories\FightRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CompetitionScheduleEstimator {
	public function estimate( int $competition_id, array $day_slots = array(), array $breaks = array(), string $source = 'draft' ): array {
		$repo = new FightRepository();
		$fights = array();

		if ( 'scheduled' !== $source ) {
			$fights = $repo->get_draft_fights( $competition_id );
		}
		if ( empty( $fights ) ) {
			$fights = $repo->list( array( 'competition_id' => $competition_id, 'view' => 'all' ), 5000, 0 );
		}

		$surface_count = 1;
		$settings = get_option( 'ufsc_competitions_settings', array() );
		if ( ! empty( $settings['surface_details'] ) && is_array( $settings['surface_details'] ) ) {
			$surface_count = max( 1, count( $settings['surface_details'] ) );
		}

		if ( empty( $day_slots ) ) {
			$today = current_time( 'Y-m-d' );
			$day_slots[] = array( 'date' => $today, 'start_time' => '09:00', 'end_time' => '18:00' );
		}

		$surface_ends = array_fill( 0, $surface_count, null );
		$global_start = null;
		$global_end = null;
		$assigned = array_fill( 0, $surface_count, 0 );
		$overflow = false;

		foreach ( $fights as $fight ) {
			$surface_idx = array_search( min( array_map( static function( $v ) { return $v ? strtotime( $v ) : 0; }, $surface_ends ) ), array_map( static function( $v ) { return $v ? strtotime( $v ) : 0; }, $surface_ends ), true );
			$surface_idx = false === $surface_idx ? 0 : (int) $surface_idx;
			$start = $this->pick_next_start( $surface_ends[ $surface_idx ], $day_slots );
			if ( ! $start ) {
				$overflow = true;
				break;
			}

			$duration = $this->resolve_duration_minutes( $fight );
			$end = gmdate( 'Y-m-d H:i:s', strtotime( $start ) + (int) round( $duration * MINUTE_IN_SECONDS ) );
			$end = $this->apply_breaks( $start, $end, $breaks );

			$surface_ends[ $surface_idx ] = $this->fit_in_slots( $start, $end, $day_slots, $overflow );
			if ( ! $surface_ends[ $surface_idx ] ) {
				$overflow = true;
				break;
			}

			$global_start = $global_start ?: $start;
			$global_end = $surface_ends[ $surface_idx ] > $global_end ? $surface_ends[ $surface_idx ] : $global_end;
			$assigned[ $surface_idx ]++;
		}

		$per_surface = array();
		foreach ( $assigned as $index => $count ) {
			$per_surface[] = array(
				'surface_index' => $index + 1,
				'fights_count' => $count,
				'end_time' => $surface_ends[ $index ],
			);
		}

		return array(
			'total_fights' => count( $fights ),
			'estimated_start_datetime' => $global_start,
			'estimated_end_datetime' => $global_end,
			'per_surface' => $per_surface,
			'overflow' => $overflow,
		);
	}

	private function resolve_duration_minutes( $fight ): float {
		if ( is_array( $fight ) ) {
			$fight_duration = isset( $fight['fight_duration'] ) ? (float) $fight['fight_duration'] : 0;
			$fight_pause = isset( $fight['fight_pause'] ) ? (float) $fight['fight_pause'] : 0;
		} else {
			$fight_duration = isset( $fight->fight_duration ) ? (float) $fight->fight_duration : 0;
			$fight_pause = isset( $fight->fight_pause ) ? (float) $fight->fight_pause : 0;
		}

		if ( $fight_duration > 0 ) {
			return $fight_duration + max( 0, $fight_pause );
		}

		$settings = get_option( 'ufsc_competitions_settings', array() );
		$round_duration = isset( $settings['fight_duration'] ) ? (float) $settings['fight_duration'] : 2;
		$break_duration = isset( $settings['break_duration'] ) ? (float) $settings['break_duration'] : 1;
		return max( 1, $round_duration + $break_duration );
	}

	private function pick_next_start( $current, array $slots ): ?string {
		if ( $current ) {
			return $current;
		}
		$first = $slots[0] ?? array();
		if ( empty( $first['date'] ) || empty( $first['start_time'] ) ) {
			return null;
		}

		return $first['date'] . ' ' . $first['start_time'] . ':00';
	}

	private function apply_breaks( string $start, string $end, array $breaks ): string {
		$end_ts = strtotime( $end );
		foreach ( $breaks as $break_row ) {
			$break_start = strtotime( (string) ( $break_row['start_datetime'] ?? '' ) );
			$break_end = strtotime( (string) ( $break_row['end_datetime'] ?? '' ) );
			if ( $break_start && $break_end && $break_start < $end_ts && $break_end > strtotime( $start ) ) {
				$end_ts += max( 0, ( $break_end - $break_start ) );
			}
		}

		return gmdate( 'Y-m-d H:i:s', $end_ts );
	}

	private function fit_in_slots( string $start, string $end, array $slots, bool &$overflow ): ?string {
		$start_ts = strtotime( $start );
		$end_ts = strtotime( $end );
		foreach ( $slots as $slot ) {
			$slot_start = strtotime( $slot['date'] . ' ' . $slot['start_time'] . ':00' );
			$slot_end = strtotime( $slot['date'] . ' ' . $slot['end_time'] . ':00' );
			if ( $start_ts < $slot_start ) {
				$shift = $slot_start - $start_ts;
				$start_ts += $shift;
				$end_ts += $shift;
			}
			if ( $start_ts >= $slot_start && $end_ts <= $slot_end ) {
				return gmdate( 'Y-m-d H:i:s', $end_ts );
			}
		}
		$overflow = true;
		return null;
	}
}
