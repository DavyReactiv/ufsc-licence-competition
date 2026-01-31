<?php

namespace UFSC\Competitions\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WeightCategoryResolver {
	const OUT_OF_RANGE_LABEL = 'Hors catégorie';

	public static function resolve( $dob, $sex, $weight_kg, array $context = array() ): string {
		$result = self::resolve_with_details( $dob, $sex, $weight_kg, $context );
		return (string) ( $result['label'] ?? '' );
	}

	public static function resolve_with_details( $dob, $sex, $weight_kg, array $context = array() ): array {
		$weight_kg = self::sanitize_weight( $weight_kg );
		if ( null === $weight_kg ) {
			return array(
				'label' => '',
				'message' => __( 'Poids manquant.', 'ufsc-licence-competition' ),
				'status' => 'missing_weight',
			);
		}

		$age = self::get_age( $dob, $context );
		if ( null === $age ) {
			return array(
				'label' => '',
				'message' => __( 'Date de naissance manquante.', 'ufsc-licence-competition' ),
				'status' => 'missing_birthdate',
			);
		}

		$sex = self::normalize_sex( $sex );
		$missing_sex = '' === $sex;

		$context = self::normalize_context( $context );
		$rules = self::get_rules();
		$discipline = $context['discipline'];
		$table = $context['table'];

		$rule_set = $rules[ $discipline ] ?? $rules['default'] ?? array();
		if ( $table && isset( $rule_set[ $table ] ) ) {
			$rule_set = $rule_set[ $table ];
		}

		$age_group = self::resolve_age_group( $age, $rule_set['age_groups'] ?? array() );
		if ( ! $age_group ) {
			return array(
				'label' => self::OUT_OF_RANGE_LABEL,
				'message' => __( 'Âge hors catégorie.', 'ufsc-licence-competition' ),
				'status' => 'out_of_range',
			);
		}

		$weights = self::resolve_weights_for_group( $rule_set, $age_group, $sex );
		if ( empty( $weights ) ) {
			return array(
				'label' => '',
				'message' => __( 'Table de poids indisponible.', 'ufsc-licence-competition' ),
				'status' => 'no_table',
			);
		}

		$label = self::resolve_weight_label( $weight_kg, $weights );
		if ( '' === $label ) {
			return array(
				'label' => self::OUT_OF_RANGE_LABEL,
				'message' => __( 'Poids hors catégorie.', 'ufsc-licence-competition' ),
				'status' => 'out_of_range',
			);
		}

		return array(
			'label' => $label,
			'message' => $missing_sex ? __( 'Sexe manquant : catégorie neutre utilisée.', 'ufsc-licence-competition' ) : '',
			'status' => $missing_sex ? 'missing_sex' : 'ok',
		);
	}

	public static function get_weight_classes( $dob, $sex, array $context = array() ): array {
		$age = self::get_age( $dob, $context );
		if ( null === $age ) {
			return array();
		}

		$sex = self::normalize_sex( $sex );
		$context = self::normalize_context( $context );
		$rules = self::get_rules();
		$discipline = $context['discipline'];
		$table = $context['table'];

		$rule_set = $rules[ $discipline ] ?? $rules['default'] ?? array();
		if ( $table && isset( $rule_set[ $table ] ) ) {
			$rule_set = $rule_set[ $table ];
		}

		$age_group = self::resolve_age_group( $age, $rule_set['age_groups'] ?? array() );
		if ( ! $age_group ) {
			return array();
		}

		$weights = self::resolve_weights_for_group( $rule_set, $age_group, $sex );
		if ( empty( $weights ) ) {
			return array();
		}

		$labels = array();
		foreach ( $weights as $weight ) {
			$labels[] = self::format_weight_label( $weight );
		}

		return $labels;
	}

	public static function requires_weight( array $context = array() ): bool {
		$context = self::normalize_context( $context );
		$rules = self::get_rules();
		$discipline = $context['discipline'];
		if ( isset( $rules[ $discipline ] ) ) {
			return true;
		}

		return ! empty( $rules['default'] );
	}

	private static function get_rules(): array {
		$defaults = self::get_default_rules();
		$filtered = apply_filters( 'ufsc_competitions_weight_rules', $defaults );
		return is_array( $filtered ) ? $filtered : $defaults;
	}

	private static function get_default_rules(): array {
		$tatami_weights = array( -30, -35, -40, -45, -50, -55, -60, -65, -70, -75, -80, -85, -90, -95, -100, -1000 );
		$ring_weights = array( -48, -51, -54, -57, -60, -63.5, -67, -71, -75, -81, -86, -91, -100, -1000 );

		$age_groups = array(
			array( 'key' => 'poussin', 'label' => __( 'Poussin', 'ufsc-licence-competition' ), 'age_min' => 7, 'age_max' => 9 ),
			array( 'key' => 'pupille', 'label' => __( 'Pupille', 'ufsc-licence-competition' ), 'age_min' => 10, 'age_max' => 11 ),
			array( 'key' => 'benjamin', 'label' => __( 'Benjamin', 'ufsc-licence-competition' ), 'age_min' => 12, 'age_max' => 13 ),
			array( 'key' => 'minime', 'label' => __( 'Minime', 'ufsc-licence-competition' ), 'age_min' => 14, 'age_max' => 15 ),
			array( 'key' => 'cadet', 'label' => __( 'Cadet', 'ufsc-licence-competition' ), 'age_min' => 16, 'age_max' => 17 ),
			array( 'key' => 'junior', 'label' => __( 'Junior', 'ufsc-licence-competition' ), 'age_min' => 18, 'age_max' => 19 ),
			array( 'key' => 'senior', 'label' => __( 'Senior', 'ufsc-licence-competition' ), 'age_min' => 20, 'age_max' => 34 ),
			array( 'key' => 'veteran', 'label' => __( 'Vétéran', 'ufsc-licence-competition' ), 'age_min' => 35, 'age_max' => 99 ),
		);

		$defaults = array(
			'default' => array(
				'age_groups' => $age_groups,
				'weights' => array(
					'neutral' => $tatami_weights,
					'm' => $tatami_weights,
					'f' => $tatami_weights,
				),
				'table' => 'tatami',
			),
			'ring' => array(
				'age_groups' => $age_groups,
				'weights' => array(
					'neutral' => $ring_weights,
					'm' => $ring_weights,
					'f' => $ring_weights,
				),
				'table' => 'ring',
			),
			'tatami' => array(
				'age_groups' => $age_groups,
				'weights' => array(
					'neutral' => $tatami_weights,
					'm' => $tatami_weights,
					'f' => $tatami_weights,
				),
				'table' => 'tatami',
			),
		);

		return $defaults;
	}

	private static function resolve_age_group( int $age, array $groups ): array {
		foreach ( $groups as $group ) {
			$min = isset( $group['age_min'] ) ? (int) $group['age_min'] : null;
			$max = isset( $group['age_max'] ) ? (int) $group['age_max'] : null;
			if ( null !== $min && $age < $min ) {
				continue;
			}
			if ( null !== $max && $age > $max ) {
				continue;
			}
			return $group;
		}

		return array();
	}

	private static function resolve_weights_for_group( array $rule_set, array $age_group, string $sex ): array {
		$weights = $rule_set['weights'] ?? array();
		if ( isset( $weights[ $age_group['key'] ?? '' ] ) && is_array( $weights[ $age_group['key'] ?? '' ] ) ) {
			$weights = $weights[ $age_group['key'] ?? '' ];
		}

		if ( is_array( $weights ) && isset( $weights[ $sex ] ) ) {
			$weights = $weights[ $sex ];
		} elseif ( is_array( $weights ) && isset( $weights['neutral'] ) ) {
			$weights = $weights['neutral'];
		}

		return array_values( array_map( 'floatval', (array) $weights ) );
	}

	private static function resolve_weight_label( float $weight, array $weights ): string {
		$weights = array_values( array_map( 'floatval', $weights ) );
		foreach ( $weights as $threshold ) {
			$limit = abs( (float) $threshold );
			if ( -1000.0 === (float) $threshold ) {
				return self::format_weight_label( $threshold );
			}
			if ( $weight <= $limit ) {
				return self::format_weight_label( $threshold );
			}
		}

		return '';
	}

	private static function format_weight_label( $threshold ): string {
		$threshold = (float) $threshold;
		if ( -1000.0 === $threshold ) {
			return '+100';
		}

		$label = rtrim( rtrim( number_format( abs( $threshold ), 1, '.', '' ), '0' ), '.' );
		return '-' . $label;
	}

	private static function sanitize_weight( $weight ): ?float {
		if ( null === $weight || '' === $weight ) {
			return null;
		}
		if ( is_string( $weight ) ) {
			$weight = str_replace( ',', '.', $weight );
		}
		$weight = (float) $weight;
		if ( $weight <= 0 || $weight > 300 ) {
			return null;
		}

		return $weight;
	}

	private static function get_age( $dob, array $context ): ?int {
		$dob = is_scalar( $dob ) ? trim( (string) $dob ) : '';
		if ( '' === $dob ) {
			return null;
		}

		$context = self::normalize_context( $context );
		$season_end_year = $context['season_end_year'];
		$age_reference = $context['age_reference'];
		$timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );

		try {
			$birth = new \DateTimeImmutable( $dob, $timezone );
		} catch ( \Exception $e ) {
			return null;
		}

		$reference_date = null;
		if ( $season_end_year ) {
			$reference_date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $season_end_year . '-' . $age_reference, $timezone );
		}
		if ( ! $reference_date ) {
			$reference_date = new \DateTimeImmutable( 'now', $timezone );
		}

		return (int) $birth->diff( $reference_date )->y;
	}

	private static function normalize_sex( $sex ): string {
		$sex = sanitize_key( (string) $sex );
		if ( in_array( $sex, array( 'm', 'h', 'male', 'homme' ), true ) ) {
			return 'm';
		}
		if ( in_array( $sex, array( 'f', 'female', 'femme' ), true ) ) {
			return 'f';
		}

		return '';
	}

	private static function normalize_context( array $context ): array {
		$discipline = sanitize_key( (string) ( $context['discipline'] ?? '' ) );
		$table = sanitize_key( (string) ( $context['table'] ?? '' ) );
		$age_reference = sanitize_text_field( (string) ( $context['age_reference'] ?? '12-31' ) );
		if ( ! preg_match( '/^\d{2}-\d{2}$/', $age_reference ) ) {
			$age_reference = '12-31';
		}
		$season_end_year = isset( $context['season_end_year'] ) ? (int) $context['season_end_year'] : 0;

		if ( class_exists( DisciplineRegistry::class ) ) {
			$type = DisciplineRegistry::get_type( $discipline );
			if ( DisciplineRegistry::TYPE_RING === $type ) {
				$discipline = 'ring';
			} elseif ( DisciplineRegistry::TYPE_TATAMI === $type ) {
				$discipline = 'tatami';
			}
		}

		return array(
			'discipline' => $discipline ?: 'default',
			'table' => $table,
			'age_reference' => $age_reference,
			'season_end_year' => $season_end_year ?: 0,
		);
	}
}
