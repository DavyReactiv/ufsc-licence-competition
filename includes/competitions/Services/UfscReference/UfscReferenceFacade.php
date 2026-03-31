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

	public static function resolve_age_category( string $birth_date, string $sex = '' ): ?array {
		if ( ! self::is_enabled() ) {
			return UfscReferenceFallback::none();
		}

		$age = self::calculate_age( $birth_date );
		if ( null === $age ) {
			return UfscReferenceFallback::none();
		}

		$sex = self::normalize_sex( $sex );
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
				'key' => (string) ( $rule['key'] ?? '' ),
				'label' => (string) ( $rule['label'] ?? '' ),
				'age' => $age,
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

		$discipline = sanitize_key( (string) ( $context['discipline'] ?? 'default' ) );
		$sex = self::normalize_sex( $sex );
		$weights_rules = self::repo()->get_weight_rules();
		$set = $weights_rules[ $discipline ] ?? $weights_rules['default'] ?? array();
		if ( empty( $set ) ) {
			return UfscReferenceFallback::none();
		}

		$thresholds = $set[ $sex ] ?? $set['neutral'] ?? array();
		foreach ( (array) $thresholds as $threshold ) {
			$threshold = (float) $threshold;
			if ( -1000.0 === $threshold || $weight_kg <= abs( $threshold ) ) {
				$label = -1000.0 === $threshold ? '+100' : '-' . rtrim( rtrim( number_format( abs( $threshold ), 1, '.', '' ), '0' ), '.' );
				return array(
					'label' => $label,
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

		$discipline = sanitize_key( (string) ( $context['discipline'] ?? 'default' ) );
		$rules = self::repo()->get_timing_rules();
		$timing = $rules[ $discipline ] ?? $rules['default'] ?? array();
		if ( empty( $timing ) ) {
			return UfscReferenceFallback::none();
		}

		return array(
			'rounds' => (int) ( $timing['rounds'] ?? 1 ),
			'round_duration' => (float) ( $timing['round_duration'] ?? 2.0 ),
			'break_duration' => (float) ( $timing['break_duration'] ?? 1.0 ),
			'fight_pause' => (float) ( $timing['fight_pause'] ?? 0.0 ),
			'source' => 'ufsc_reference',
		);
	}

	public static function resolve_obligations( array $context = array() ): ?array {
		if ( ! self::is_enabled() ) {
			return UfscReferenceFallback::none();
		}

		$discipline = sanitize_key( (string) ( $context['discipline'] ?? 'default' ) );
		$rules = self::repo()->get_obligation_rules();
		$rule = $rules[ $discipline ] ?? $rules['default'] ?? array();
		if ( empty( $rule ) ) {
			return UfscReferenceFallback::none();
		}

		$age = isset( $context['age'] ) ? (int) $context['age'] : 0;
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
			'rules' => $rule,
			'source' => 'ufsc_reference',
		);
	}

	private static function calculate_age( string $birth_date ): ?int {
		$birth_date = trim( $birth_date );
		if ( '' === $birth_date ) {
			return null;
		}
		try {
			$birth = new \DateTimeImmutable( $birth_date );
			$reference = new \DateTimeImmutable( 'now' );
			return (int) $birth->diff( $reference )->y;
		} catch ( \Exception $e ) {
			return null;
		}
	}

	private static function normalize_sex( string $sex ): string {
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
