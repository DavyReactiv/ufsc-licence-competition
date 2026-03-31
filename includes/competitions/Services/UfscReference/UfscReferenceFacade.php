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
		if ( ! self::is_enabled() ) {
			return UfscReferenceFallback::none();
		}

		$age = self::calculate_age( $birth_date, $context );
		if ( null === $age ) {
			return UfscReferenceFallback::none();
		}

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

			return array(
				'key'   => (string) ( $rule['key'] ?? '' ),
				'label' => (string) ( $rule['label'] ?? '' ),
				'age'   => $age,
			);
		}

		return UfscReferenceFallback::none();
	}

	public static function resolve_weight_category( string $birth_date, string $sex, float $weight_kg, array $context = array() ): ?array {
		if ( ! self::is_enabled() ) {
			return UfscReferenceFallback::none();
		}

		if ( $weight_kg <= 0 ) {
			return UfscReferenceFallback::none();
		}

		$context    = UfscReferenceNormalizer::normalize_context( $context );
		$discipline = $context['discipline'];
		$sex        = UfscReferenceNormalizer::normalize_sex( $sex );
		$age_group  = isset( $context['age_group'] ) ? sanitize_key( (string) $context['age_group'] ) : '';

		if ( '' === $age_group ) {
			$age       = self::calculate_age( $birth_date, $context );
			$age_group = self::resolve_age_group_key( $age );
		}

		$weights_rules = self::repo()->get_weight_rules();
		$set           = $weights_rules[ $discipline ] ?? $weights_rules['default'] ?? array();

		if ( empty( $set ) ) {
			return UfscReferenceFallback::none();
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
				$label = -1000.0 === $threshold
					? '+100'
					: '-' . rtrim( rtrim( number_format( abs( $threshold ), 1, '.', '' ), '0' ), '.' );

				return array(
					'label'  => $label,
					'source' => 'ufsc_reference',
				);
			}
		}

		return UfscReferenceFallback::none();
	}

	public static function resolve_timing( array $context = array() ): ?array {
		if ( ! self::is_enabled() ) {
			return UfscReferenceFallback::none();
		}

		$context    = UfscReferenceNormalizer::normalize_context( $context );
		$discipline = $context['discipline'];
		$format     = $context['format'];
		$level      = $context['level'];
		$age_group  = isset( $context['age_group'] ) ? sanitize_key( (string) $context['age_group'] ) : 'default';

		$rules  = self::repo()->get_timing_rules();
		$timing = $rules[ $discipline ] ?? $rules['default'] ?? array();

		if ( empty( $timing ) ) {
			return UfscReferenceFallback::none();
		}

		if ( isset( $timing['profiles'] ) && is_array( $timing['profiles'] ) ) {
			$profile = $timing['profiles'][ $format ] ?? $timing['profiles']['default'] ?? array();
			if ( is_array( $profile ) ) {
				$by_age = $profile[ $age_group ] ?? $profile['default'] ?? array();
				if ( is_array( $by_age ) ) {
					$candidate = $by_age[ $level ] ?? $by_age['default'] ?? array();
					if ( is_array( $candidate ) && ! empty( $candidate ) ) {
						$timing = array_merge( $timing, $candidate );
					}
				}
			}
		}

		return array(
			'rounds'         => (int) ( $timing['rounds'] ?? 1 ),
			'round_duration' => (float) ( $timing['round_duration'] ?? 2.0 ),
			'break_duration' => (float) ( $timing['break_duration'] ?? 1.0 ),
			'fight_pause'    => (float) ( $timing['fight_pause'] ?? 0.0 ),
			'source'         => 'ufsc_reference',
		);
	}

	public static function resolve_obligations( array $context = array() ): ?array {
		if ( ! self::is_enabled() ) {
			return UfscReferenceFallback::none();
		}

		$context    = UfscReferenceNormalizer::normalize_context( $context );
		$discipline = $context['discipline'];

		$rules = self::repo()->get_obligation_rules();
		$rule  = $rules[ $discipline ] ?? $rules['default'] ?? array();

		if ( empty( $rule ) ) {
			return UfscReferenceFallback::none();
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

		return array(
			'warnings' => array_values( array_unique( $warnings ) ),
			'rules'    => $rule,
			'source'   => 'ufsc_reference',
		);
	}

	private static function calculate_age( string $birth_date, array $context = array() ): ?int {
		$birth_date = trim( $birth_date );
		if ( '' === $birth_date ) {
			return null;
		}

		try {
			$birth     = new \DateTimeImmutable( $birth_date );
			$reference = UfscReferenceDateResolver::resolve( $context );

			return (int) $birth->diff( $reference )->y;
		} catch ( \Exception $e ) {
			return null;
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