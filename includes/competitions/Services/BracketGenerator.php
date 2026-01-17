<?php

namespace UFSC\Competitions\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BracketGenerator {
	public function generate( array $entries ) {
		$matches = array();
		$count = count( $entries );
		$target = 1;
		while ( $target < $count ) {
			$target *= 2;
		}

		$byes = $target - $count;
		$entries = array_values( $entries );
		for ( $i = 0; $i < $count; $i += 2 ) {
			$red = $entries[ $i ] ?? null;
			$blue = $entries[ $i + 1 ] ?? null;
			if ( $byes > 0 && ! $blue ) {
				$blue = null;
				$byes--;
			}
			$matches[] = array(
				'red'  => $red,
				'blue' => $blue,
			);
		}

		return $matches;
	}
}
