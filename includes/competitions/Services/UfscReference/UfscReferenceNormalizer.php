<?php

namespace UFSC\Competitions\Services\UfscReference;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UfscReferenceNormalizer {
	public static function normalize_context(array $context): array {
		$discipline = sanitize_key( (string) ( $context['discipline'] ?? 'default' ) );
		$format     = sanitize_key( (string) ( $context['format'] ?? 'default' ) );
		$level      = sanitize_key( (string) ( $context['level'] ?? 'default' ) );

		if ( '' === $discipline ) {
			$discipline = 'default';
		}
		if ( '' === $format ) {
			$format = 'default';
		}
		if ( '' === $level ) {
			$level = 'default';
		}

		$context['discipline'] = $discipline;
		$context['format'] = $format;
		$context['level'] = $level;

		return $context;
	}

	public static function normalize_sex(string $sex): string {
		$sex = sanitize_key( $sex );
		if ( in_array( $sex, array( 'm', 'h', 'male', 'homme' ), true ) ) {
			return 'm';
		}
		if ( in_array( $sex, array( 'f', 'female', 'femme' ), true ) ) {
			return 'f';
		}
		return '';
	}
}
