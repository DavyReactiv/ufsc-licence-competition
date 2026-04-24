<?php

namespace UFSC\Competitions\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BracketGenerator {
	public function generate( array $entries, int $target_size = 0 ): array {
		$matches = array();
		$count   = count( $entries );
		$target  = 1;
		while ( $target < $count ) {
			$target *= 2;
		}

		if ( $target_size > 0 ) {
			$target = max( $target, $target_size );
		}

		$byes    = max( 0, $target - $count );
		$entries = array_values( $entries );

		for ( $i = 0; $i < $target; $i += 2 ) {
			$red  = $entries[ $i ] ?? null;
			$blue = $entries[ $i + 1 ] ?? null;
			$matches[] = array(
				'red'  => $red,
				'blue' => $blue,
				'is_bye' => ! $red || ! $blue,
				'bye_winner' => $red ?: $blue,
			);
		}

		return array(
			'matches'    => $matches,
			'target_size'=> $target,
			'bye_slots'  => $byes,
		);
	}
}
