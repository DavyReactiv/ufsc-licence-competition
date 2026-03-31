<?php

namespace UFSC\Competitions\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FightGenerationAnomalyReporter {
	public static function missing_required_fields( $entry, array $required_fields ): array {
		$anomalies = array();
		foreach ( $required_fields as $field ) {
			$value = '';
			if ( is_object( $entry ) && isset( $entry->{$field} ) ) {
				$value = (string) $entry->{$field};
			}
			if ( '' === trim( $value ) ) {
				$anomalies[] = 'missing_' . sanitize_key( (string) $field );
			}
		}

		return array_values( array_unique( $anomalies ) );
	}
}
