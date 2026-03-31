<?php

namespace UFSC\Competitions\Services\UfscReference;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UfscReferenceDebugLogger {
	/**
	 * @param array<string,mixed> $decision
	 */
	public static function maybe_log( string $topic, array $decision ): void {
		$enabled = (bool) apply_filters( 'ufsc_competitions_reference_debug_enabled', false, $topic, $decision );
		if ( ! $enabled ) {
			return;
		}

		$should_log = ! empty( $decision['fallback_used'] ) || ! empty( $decision['reason_code'] ) || ! empty( $decision['debug']['inconsistent_context'] );
		if ( ! $should_log ) {
			return;
		}

		error_log(
			sprintf(
				'[UFSC_REFERENCE][%s] %s',
				sanitize_key( $topic ),
				wp_json_encode( $decision )
			)
		);
	}
}
