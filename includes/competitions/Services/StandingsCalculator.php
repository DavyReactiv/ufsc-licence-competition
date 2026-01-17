<?php

namespace UFSC\Competitions\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class StandingsCalculator {
	public function calculate( array $results ) {
		$standings = array();
		foreach ( $results as $result ) {
			$entry_id = (int) ( $result['entry_id'] ?? 0 );
			if ( ! $entry_id ) {
				continue;
			}
			if ( ! isset( $standings[ $entry_id ] ) ) {
				$standings[ $entry_id ] = array(
					'entry_id' => $entry_id,
					'wins'     => 0,
					'points'   => 0,
					'diff'     => 0,
				);
			}
			$standings[ $entry_id ]['wins'] += (int) ( $result['wins'] ?? 0 );
			$standings[ $entry_id ]['points'] += (int) ( $result['points'] ?? 0 );
			$standings[ $entry_id ]['diff'] += (int) ( $result['diff'] ?? 0 );
		}

		usort(
			$standings,
			function( $a, $b ) {
				if ( $a['wins'] !== $b['wins'] ) {
					return $b['wins'] <=> $a['wins'];
				}
				if ( $a['points'] !== $b['points'] ) {
					return $b['points'] <=> $a['points'];
				}

				return $b['diff'] <=> $a['diff'];
			}
		);

		return $standings;
	}
}
