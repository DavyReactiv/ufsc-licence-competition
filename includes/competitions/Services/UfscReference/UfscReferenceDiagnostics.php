<?php

namespace UFSC\Competitions\Services\UfscReference;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UfscReferenceDiagnostics {
	/**
	 * @param array<string,mixed> $payload
	 */
	public static function decision( array $payload ): UfscReferenceDecision {
		$defaults = array(
			'type'              => 'unknown',
			'matched'           => false,
			'reason_code'       => UfscReferenceDecision::REASON_NONE,
			'fallback_used'     => true,
			'source'            => 'ufsc_reference',
			'rule'              => '',
			'value'             => null,
			'normalized_context'=> array(),
			'debug'             => array(),
		);

		$merged = array_merge( $defaults, $payload );

		if ( ! is_array( $merged['normalized_context'] ) ) {
			$merged['normalized_context'] = array();
		}
		if ( ! is_array( $merged['debug'] ) ) {
			$merged['debug'] = array();
		}

		return new UfscReferenceDecision( $merged );
	}

	/**
	 * @param array<string,mixed> $context
	 */
	public static function normalize_context( array $context ): array {
		$normalized = UfscReferenceNormalizer::normalize_context( $context );
		$normalized['sex'] = UfscReferenceNormalizer::normalize_sex( (string) ( $context['sex'] ?? '' ) );

		if ( isset( $context['age_group'] ) ) {
			$normalized['age_group'] = sanitize_key( (string) $context['age_group'] );
		}

		return $normalized;
	}
}
