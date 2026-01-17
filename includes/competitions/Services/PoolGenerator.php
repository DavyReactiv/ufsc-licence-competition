<?php

namespace UFSC\Competitions\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PoolGenerator {
	public function generate( array $entries, $max_per_group = 6 ) {
		$groups = array();
		$index = 0;
		foreach ( $entries as $entry ) {
			$group_key = floor( $index / max( 1, (int) $max_per_group ) );
			if ( ! isset( $groups[ $group_key ] ) ) {
				$groups[ $group_key ] = array();
			}
			$groups[ $group_key ][] = $entry;
			$index++;
		}

		return array_values( $groups );
	}
}
