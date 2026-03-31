<?php

namespace UFSC\Competitions\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FightGenerationPremiumPlanner {
	/**
	 * Defensive planner placeholder for Lot 5.
	 *
	 * Returns a normalized payload so callers can safely fallback.
	 */
	public static function plan( array $entries, array $context = array() ): array {
		$anomalies = array();
		foreach ( $entries as $entry ) {
			$missing = FightGenerationAnomalyReporter::missing_required_fields(
				$entry,
				array( 'id' )
			);
			if ( ! empty( $missing ) ) {
				$anomalies[] = array(
					'entry_id'  => (int) ( is_object( $entry ) ? ( $entry->id ?? 0 ) : 0 ),
					'anomalies' => $missing,
				);
			}
		}

		return array(
			'ok'        => empty( $anomalies ),
			'fights'    => array(),
			'anomalies' => $anomalies,
			'fallback'  => ! empty( $anomalies ),
			'context'   => $context,
		);
	}
}
