<?php

namespace UFSC\Competitions\Services\UfscReference;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UfscReferenceFacade {
	private static function repo(): UfscReferenceRepository {
		return new UfscReferenceRepository();
	}

	public static function is_enabled(): bool {
		return self::repo()->is_enabled();
	}

	public static function resolve_age_category( string $birth_date, string $sex = '', array $context = array() ): ?array {
		$decision = self::build_age_category_decision( $birth_date, $sex, $context );
		return self::decision_to_legacy_result( $decision );
	}

	public static function explain_age_category( string $birth_date, string $sex = '', array $context = array() ): array {
		$decision = self::build_age_category_decision( $birth_date, $sex, $context );
		return $decision->to_array();
	}

	public static function resolve_weight_category( string $birth_date, string $sex, float $weight_kg, array $context = array() ): ?array {
		$decision = self::build_weight_category_decision( $birth_date, $sex, $weight_kg, $context );
		return self::decision_to_legacy_result( $decision );
	}

	public static function explain_weight_category( string $birth_date, string $sex, float $weight_kg, array $context = array() ): array {
		$decision = self::build_weight_category_decision( $birth_date, $sex, $weight_kg, $context );
		return $decision->to_array();
	}

	public static function resolve_timing( array $context = array() ): ?array {
		$decision = self::build_timing_decision( $context );
		return self::decision_to_legacy_result( $decision );
	}

	public static function explain_timing( array $context = array() ): array {
		$decision = self::build_timing_decision( $context );
		return $decision->to_array();
	}

	public static function resolve_obligations( array $context = array() ): ?array {
		$decision = self::build_obligations_decision( $context );
		return self::decision_to_legacy_result( $decision );
	}

	public static function explain_obligations( array $context = array() ): array {
		$decision = self::build_obligations_decision( $context );
		return $decision->to_array();
	}

	private static function build_age_category_decision( string $birth_date, string $sex = '', array $context = array() ): UfscReferenceDecision {
		$normalized_context = UfscReferenceDiagnostics::normalize_context( array_merge( $context, array( 'sex' => $sex ) ) );
		if ( ! self::is_enabled() ) {
			return self::finalize_decision(
				'age_category',
				UfscReferenceDiagnostics::decision(
					array(
						'type'               => 'age_category',
						'reason_code'        => UfscReferenceDecision::REASON_REFERENCE_DISABLED,
						'normalized_context' => $normalized_context,
					)
				)
			);
		}

		$age_result = self::calculate_age_with_reason( $birth_date, $context );
		if ( null === $age_result['age'] ) {
			return self::finalize_decision(
				'age_category',
				UfscReferenceDiagnostics::decision(
					array(
						'type'               => 'age_category',
						'reason_code'        => $age_result['reason'],
						'normalized_context' => $normalized_context,
					)
				)
			);
		}

		$age   = (int) $age_result['age'];
		$sex   = UfscReferenceNormalizer::normalize_sex( $sex );
		$rules = self::repo()->get_age_rules();

		foreach ( $rules as $rule ) {
			$min = isset( $rule['age_min'] ) ? (int) $rule['age_min'] : null;
			$max = isset( $rule['age_max'] ) ? (int) $rule['age_max'] : null;

			if ( null !== $min && $age < $min ) {
				continue;
			}
			if ( null !== $max && $age > $max ) {
				continue;
			}

			$rule_sex = isset( $rule['sex'] ) ? (string) $rule['sex'] : 'neutral';
			if ( 'neutral' !== $rule_sex && $sex && $sex !== $rule_sex ) {
				continue;
			}

			return self::finalize_decision(
				'age_category',
				UfscReferenceDiagnostics::decision(
					array(
						'type'               => 'age_category',
						'matched'            => true,
						'reason_code'        => UfscReferenceDecision::REASON_NONE,
						'fallback_used'      => false,
						'rule'               => (string) ( $rule['key'] ?? '' ),
						'value'              => array(
							'key'   => (string) ( $rule['key'] ?? '' ),
							'label' => (string) ( $rule['label'] ?? '' ),
							'age'   => $age,
						),
						'normalized_context' => $normalized_context,
					)
				)
			);
		}

		return self::finalize_decision(
			'age_category',
			UfscReferenceDiagnostics::decision(
				array(
					'type'               => 'age_category',
					'reason_code'        => UfscReferenceDecision::REASON_NO_COMPATIBLE_RULE,
					'normalized_context' => $normalized_context,
				)
			)
		);
	}

	private static function build_weight_category_decision( string $birth_date, string $sex, float $weight_kg, array $context = array() ): UfscReferenceDecision {
		$normalized_context = UfscReferenceDiagnostics::normalize_context( array_merge( $context, array( 'sex' => $sex ) ) );
		if ( ! self::is_enabled() ) {
			return self::finalize_decision( 'weight_category', UfscReferenceDiagnostics::decision( array( 'type' => 'weight_category', 'reason_code' => UfscReferenceDecision::REASON_REFERENCE_DISABLED, 'normalized_context' => $normalized_context ) ) );
		}

		if ( $weight_kg <= 0 ) {
			return self::finalize_decision( 'weight_category', UfscReferenceDiagnostics::decision( array( 'type' => 'weight_category', 'reason_code' => UfscReferenceDecision::REASON_WEIGHT_INVALID, 'normalized_context' => $normalized_context ) ) );
		}

		$context    = UfscReferenceNormalizer::normalize_context( $context );
		$discipline = $context['discipline'];
		$sex        = UfscReferenceNormalizer::normalize_sex( $sex );
		$age_group  = isset( $context['age_group'] ) ? sanitize_key( (string) $context['age_group'] ) : '';

		if ( '' === $sex ) {
			return self::finalize_decision( 'weight_category', UfscReferenceDiagnostics::decision( array( 'type' => 'weight_category', 'reason_code' => UfscReferenceDecision::REASON_SEX_UNRECOGNIZED, 'normalized_context' => $normalized_context ) ) );
		}
		if ( 'default' === $discipline ) {
			$normalized_context['debug_discipline'] = (string) ( $context['discipline'] ?? '' );
			return self::finalize_decision( 'weight_category', UfscReferenceDiagnostics::decision( array( 'type' => 'weight_category', 'reason_code' => UfscReferenceDecision::REASON_DISCIPLINE_UNKNOWN, 'normalized_context' => $normalized_context ) ) );
		}

		$age_reason = UfscReferenceDecision::REASON_NONE;
		if ( '' === $age_group ) {
			$age_result = self::calculate_age_with_reason( $birth_date, $context );
			$age_group  = self::resolve_age_group_key( $age_result['age'] );
			$age_reason = $age_result['reason'];
		}

		if ( 'default' === $age_group ) {
			return self::finalize_decision( 'weight_category', UfscReferenceDiagnostics::decision( array( 'type' => 'weight_category', 'reason_code' => UfscReferenceDecision::REASON_AGE_GROUP_UNDETERMINED, 'normalized_context' => $normalized_context, 'debug' => array( 'age_reason' => $age_reason ) ) ) );
		}

		$weights_rules = self::repo()->get_weight_rules();
		$set           = $weights_rules[ $discipline ] ?? $weights_rules['default'] ?? array();

		if ( empty( $set ) ) {
			return self::finalize_decision( 'weight_category', UfscReferenceDiagnostics::decision( array( 'type' => 'weight_category', 'reason_code' => UfscReferenceDecision::REASON_RULES_NOT_FOUND, 'normalized_context' => $normalized_context ) ) );
		}

		$thresholds = array();
		if ( isset( $set['age_groups'] ) && is_array( $set['age_groups'] ) ) {
			$group_set = $set['age_groups'][ $age_group ] ?? $set['age_groups']['default'] ?? array();
			if ( is_array( $group_set ) ) {
				$thresholds = $group_set[ $sex ] ?? $group_set['neutral'] ?? array();
			}
		}
		if ( empty( $thresholds ) ) {
			$thresholds = $set[ $sex ] ?? $set['neutral'] ?? array();
		}

		foreach ( (array) $thresholds as $threshold ) {
			$threshold = (float) $threshold;
			if ( -1000.0 === $threshold || $weight_kg <= abs( $threshold ) ) {
				$label = -1000.0 === $threshold ? '+100' : '-' . rtrim( rtrim( number_format( abs( $threshold ), 1, '.', '' ), '0' ), '.' );
				return self::finalize_decision(
					'weight_category',
					UfscReferenceDiagnostics::decision(
						array(
							'type'               => 'weight_category',
							'matched'            => true,
							'fallback_used'      => false,
							'value'              => array( 'label' => $label, 'source' => 'ufsc_reference' ),
							'rule'               => $age_group . ':' . $sex,
							'normalized_context' => $normalized_context,
						)
					)
				);
			}
		}

		return self::finalize_decision( 'weight_category', UfscReferenceDiagnostics::decision( array( 'type' => 'weight_category', 'reason_code' => UfscReferenceDecision::REASON_NO_COMPATIBLE_RULE, 'normalized_context' => $normalized_context ) ) );
	}

	private static function build_timing_decision( array $context = array() ): UfscReferenceDecision {
		$normalized_context = UfscReferenceDiagnostics::normalize_context( $context );
		if ( ! self::is_enabled() ) {
			return self::finalize_decision( 'timing', UfscReferenceDiagnostics::decision( array( 'type' => 'timing', 'reason_code' => UfscReferenceDecision::REASON_REFERENCE_DISABLED, 'normalized_context' => $normalized_context ) ) );
		}

		$context    = UfscReferenceNormalizer::normalize_context( $context );
		$discipline = $context['discipline'];
		$format     = $context['format'];
		$level      = $context['level'];
		$age_group  = isset( $context['age_group'] ) ? sanitize_key( (string) $context['age_group'] ) : 'default';

		if ( 'default' === $discipline ) {
			return self::finalize_decision( 'timing', UfscReferenceDiagnostics::decision( array( 'type' => 'timing', 'reason_code' => UfscReferenceDecision::REASON_DISCIPLINE_UNKNOWN, 'normalized_context' => $normalized_context ) ) );
		}

		$rules  = self::repo()->get_timing_rules();
		$timing = $rules[ $discipline ] ?? $rules['default'] ?? array();
		if ( empty( $timing ) ) {
			return self::finalize_decision( 'timing', UfscReferenceDiagnostics::decision( array( 'type' => 'timing', 'reason_code' => UfscReferenceDecision::REASON_RULES_NOT_FOUND, 'normalized_context' => $normalized_context ) ) );
		}

		$reason_code = UfscReferenceDecision::REASON_NONE;
		if ( isset( $timing['profiles'] ) && is_array( $timing['profiles'] ) ) {
			$profile = $timing['profiles'][ $format ] ?? null;
			if ( null === $profile ) {
				$reason_code = UfscReferenceDecision::REASON_FORMAT_NOT_FOUND;
				$profile     = $timing['profiles']['default'] ?? array();
			}
			if ( is_array( $profile ) ) {
				$by_age = $profile[ $age_group ] ?? $profile['default'] ?? array();
				if ( is_array( $by_age ) ) {
					$candidate = $by_age[ $level ] ?? null;
					if ( null === $candidate ) {
						if ( UfscReferenceDecision::REASON_NONE === $reason_code ) {
							$reason_code = UfscReferenceDecision::REASON_LEVEL_NOT_FOUND;
						}
						$candidate = $by_age['default'] ?? array();
					}
					if ( is_array( $candidate ) && ! empty( $candidate ) ) {
						$timing = array_merge( $timing, $candidate );
					}
				}
			}
		}

		return self::finalize_decision(
			'timing',
			UfscReferenceDiagnostics::decision(
				array(
					'type'               => 'timing',
					'matched'            => true,
					'fallback_used'      => false,
					'reason_code'        => $reason_code,
					'rule'               => $discipline . ':' . $format . ':' . $age_group . ':' . $level,
					'value'              => array(
						'rounds'         => (int) ( $timing['rounds'] ?? 1 ),
						'round_duration' => (float) ( $timing['round_duration'] ?? 2.0 ),
						'break_duration' => (float) ( $timing['break_duration'] ?? 1.0 ),
						'fight_pause'    => (float) ( $timing['fight_pause'] ?? 0.0 ),
						'source'         => 'ufsc_reference',
					),
					'normalized_context' => $normalized_context,
				)
			)
		);
	}

	private static function build_obligations_decision( array $context = array() ): UfscReferenceDecision {
		$normalized_context = UfscReferenceDiagnostics::normalize_context( $context );
		if ( ! self::is_enabled() ) {
			return self::finalize_decision( 'obligations', UfscReferenceDiagnostics::decision( array( 'type' => 'obligations', 'reason_code' => UfscReferenceDecision::REASON_REFERENCE_DISABLED, 'normalized_context' => $normalized_context ) ) );
		}

		$context    = UfscReferenceNormalizer::normalize_context( $context );
		$discipline = $context['discipline'];
		if ( 'default' === $discipline ) {
			return self::finalize_decision( 'obligations', UfscReferenceDiagnostics::decision( array( 'type' => 'obligations', 'reason_code' => UfscReferenceDecision::REASON_DISCIPLINE_UNKNOWN, 'normalized_context' => $normalized_context ) ) );
		}

		$rules = self::repo()->get_obligation_rules();
		$rule  = $rules[ $discipline ] ?? $rules['default'] ?? array();
		if ( empty( $rule ) ) {
			return self::finalize_decision( 'obligations', UfscReferenceDiagnostics::decision( array( 'type' => 'obligations', 'reason_code' => UfscReferenceDecision::REASON_RULES_NOT_FOUND, 'normalized_context' => $normalized_context ) ) );
		}

		$age      = isset( $context['age'] ) ? (int) $context['age'] : 0;
		$warnings = array();
		if ( ! empty( $rule['certificate_medical'] ) && empty( $context['certificate_medical'] ) ) {
			$warnings[] = 'certificate_missing';
		}
		if ( ! empty( $rule['fundus_required'] ) && empty( $context['fundus'] ) ) {
			$warnings[] = 'fundus_missing';
		}
		if ( ! empty( $rule['ecg_required_age'] ) && $age >= (int) $rule['ecg_required_age'] && empty( $context['ecg'] ) ) {
			$warnings[] = 'ecg_missing';
		}

		$result = array(
			'warnings' => array_values( array_unique( $warnings ) ),
			'rules'    => $rule,
			'source'   => 'ufsc_reference',
		);

		$strict = (bool) apply_filters( 'ufsc_competitions_reference_obligations_strict', false, $context, $result );
		if ( $strict && ! empty( $result['warnings'] ) ) {
			return self::finalize_decision(
				'obligations',
				UfscReferenceDiagnostics::decision(
					array(
						'type'               => 'obligations',
						'reason_code'        => UfscReferenceDecision::REASON_NO_COMPATIBLE_RULE,
						'normalized_context' => $normalized_context,
						'debug'              => array( 'strict_mode' => true, 'warnings' => $result['warnings'] ),
					)
				)
			);
		}

		return self::finalize_decision(
			'obligations',
			UfscReferenceDiagnostics::decision(
				array(
					'type'               => 'obligations',
					'matched'            => true,
					'fallback_used'      => false,
					'value'              => $result,
					'rule'               => $discipline,
					'normalized_context' => $normalized_context,
				)
			)
		);
	}

	private static function decision_to_legacy_result( UfscReferenceDecision $decision ): ?array {
		$payload = $decision->to_array();
		return ! empty( $payload['matched'] ) && is_array( $payload['value'] ) ? $payload['value'] : UfscReferenceFallback::none();
	}

	private static function finalize_decision( string $topic, UfscReferenceDecision $decision ): UfscReferenceDecision {
		$payload = $decision->to_array();
		$payload = apply_filters( 'ufsc_competitions_reference_decision_payload', $payload, $topic );
		if ( ! is_array( $payload ) ) {
			$payload = $decision->to_array();
		}
		if ( ! isset( $payload['normalized_context'] ) || ! is_array( $payload['normalized_context'] ) ) {
			$payload['normalized_context'] = array();
		}

		if ( self::has_inconsistent_context( $payload['normalized_context'] ) ) {
			$payload['debug']['inconsistent_context'] = true;
			if ( empty( $payload['reason_code'] ) || UfscReferenceDecision::REASON_NONE === $payload['reason_code'] ) {
				$payload['reason_code'] = UfscReferenceDecision::REASON_CONTEXT_INCONSISTENT;
			}
		}

		UfscReferenceDebugLogger::maybe_log( $topic, $payload );
		return new UfscReferenceDecision( $payload );
	}

	private static function has_inconsistent_context( array $context ): bool {
		if ( isset( $context['weight_kg'] ) && (float) $context['weight_kg'] < 0 ) {
			return true;
		}
		if ( isset( $context['age'] ) && (int) $context['age'] < 0 ) {
			return true;
		}
		return false;
	}

	/**
	 * @return array{age:?int,reason:string}
	 */
	private static function calculate_age_with_reason( string $birth_date, array $context = array() ): array {
		$birth_date = trim( $birth_date );
		if ( '' === $birth_date ) {
			return array( 'age' => null, 'reason' => UfscReferenceDecision::REASON_BIRTH_DATE_MISSING );
		}

		try {
			$birth     = new \DateTimeImmutable( $birth_date );
			$reference = UfscReferenceDateResolver::resolve( $context );
			return array( 'age' => (int) $birth->diff( $reference )->y, 'reason' => UfscReferenceDecision::REASON_NONE );
		} catch ( \Exception $e ) {
			return array( 'age' => null, 'reason' => UfscReferenceDecision::REASON_BIRTH_DATE_INVALID );
		}
	}

	private static function resolve_age_group_key( ?int $age ): string {
		if ( null === $age || $age <= 0 ) {
			return 'default';
		}
		if ( $age <= 15 ) {
			return 'cadet';
		}
		if ( $age <= 17 ) {
			return 'junior';
		}
		return 'senior';
	}
}
