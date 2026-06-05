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
		if ( class_exists( '\\UFSC\\Competitions\\Services\\UfscReference\\UfscReferenceFacade' ) ) {
			$reference = \UFSC\Competitions\Services\UfscReference\UfscReferenceFacade::resolve_weight_category(
				(string) $dob,
				(string) $sex,
				(float) $weight_kg,
				$context
			);
			if ( is_array( $reference ) && ! empty( $reference['label'] ) ) {
				return array(
					'label' => (string) $reference['label'],
					'message' => '',
					'status' => 'ok',
				);
			}
		}

		$rules = self::get_rules();
		$discipline = $context['discipline'];
		$table = $context['table'];

		$rule_set = $rules[ $discipline ] ?? $rules['default'] ?? array();
		if ( $table && isset( $rule_set[ $table ] ) ) {
			$rule_set = $rule_set[ $table ];
		}

		$age_group = self::resolve_age_group( $age, $rule_set['age_groups'] ?? array(), $sex );
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

		$age_group = self::resolve_age_group( $age, $rule_set['age_groups'] ?? array(), $sex );
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
		$assaut_tatami_weights = array(
			'pre_poussins'        => array( 'neutral' => array( -18, -23, -28, -32, -37, -42, -47, 47 ) ),
			'poussins'            => array( 'm' => array( -18, -23, -28, -32, -37, -42, -47, 47 ), 'f' => array( -18, -23, -28, -32, -37, -42, -47, 47 ) ),
			'benjamins'           => array( 'm' => array( -23, -28, -32, -37, -42, -47, -52, 52 ), 'f' => array( -23, -28, -32, -37, -42, -47, -52, 52 ) ),
			'minimes_filles'      => array( 'f' => array( -28, -32, -37, -42, -46, -50, -55, -60, 60 ) ),
			'minimes_garcons'     => array( 'm' => array( -28, -32, -37, -42, -47, -52, -57, -63, -69, 69 ) ),
			'cadettes'            => array( 'f' => array( -37, -42, -46, -50, -55, -60, -65, 65 ) ),
			'cadets'              => array( 'm' => array( -37, -42, -47, -52, -57, -63, -69, -74, 74 ) ),
			'juniors_filles'      => array( 'f' => array( -42, -46, -50, -55, -60, -65, -70, 70 ) ),
			'juniors_garcons'     => array( 'm' => array( -47, -52, -57, -63, -69, -74, -79, -84, -89, -94, 94 ) ),
			'seniors_femmes'      => array( 'f' => array( -50, -55, -60, -65, -70, 70 ) ),
			'veterans_feminines' => array( 'f' => array( -50, -55, -60, -65, -70, 70 ) ),
			'seniors_hommes'      => array( 'm' => array( -57, -63, -69, -74, -79, -84, -89, -94, 94 ) ),
			'veterans_masculins' => array( 'm' => array( -57, -63, -69, -74, -79, -84, -89, -94, 94 ) ),
		);
		$ring_weights = array( -48, -51, -54, -57, -60, -63.5, -67, -71, -75, -81, -86, -91, -100, -1000 );

		$assaut_age_groups = array(
			array( 'key' => 'pre_poussins', 'label' => __( 'Pré-poussins mixte', 'ufsc-licence-competition' ), 'age_min' => 6, 'age_max' => 7 ),
			array( 'key' => 'poussins', 'label' => __( 'Poussins', 'ufsc-licence-competition' ), 'age_min' => 8, 'age_max' => 9 ),
			array( 'key' => 'benjamins', 'label' => __( 'Benjamins', 'ufsc-licence-competition' ), 'age_min' => 10, 'age_max' => 11 ),
			array( 'key' => 'minimes_filles', 'label' => __( 'Minimes filles', 'ufsc-licence-competition' ), 'age_min' => 12, 'age_max' => 13, 'sex' => 'f' ),
			array( 'key' => 'minimes_garcons', 'label' => __( 'Minimes garçons', 'ufsc-licence-competition' ), 'age_min' => 12, 'age_max' => 13, 'sex' => 'm' ),
			array( 'key' => 'cadettes', 'label' => __( 'Cadettes', 'ufsc-licence-competition' ), 'age_min' => 14, 'age_max' => 15, 'sex' => 'f' ),
			array( 'key' => 'cadets', 'label' => __( 'Cadets', 'ufsc-licence-competition' ), 'age_min' => 14, 'age_max' => 15, 'sex' => 'm' ),
			array( 'key' => 'juniors_filles', 'label' => __( 'Juniors filles', 'ufsc-licence-competition' ), 'age_min' => 16, 'age_max' => 17, 'sex' => 'f' ),
			array( 'key' => 'juniors_garcons', 'label' => __( 'Juniors garçons', 'ufsc-licence-competition' ), 'age_min' => 16, 'age_max' => 17, 'sex' => 'm' ),
			array( 'key' => 'seniors_femmes', 'label' => __( 'Seniors femmes', 'ufsc-licence-competition' ), 'age_min' => 18, 'age_max' => 40, 'sex' => 'f' ),
			array( 'key' => 'seniors_hommes', 'label' => __( 'Seniors hommes', 'ufsc-licence-competition' ), 'age_min' => 18, 'age_max' => 40, 'sex' => 'm' ),
			array( 'key' => 'veterans_feminines', 'label' => __( 'Vétérans féminines', 'ufsc-licence-competition' ), 'age_min' => 41, 'age_max' => 50, 'sex' => 'f' ),
			array( 'key' => 'veterans_masculins', 'label' => __( 'Vétérans masculins', 'ufsc-licence-competition' ), 'age_min' => 41, 'age_max' => 50, 'sex' => 'm' ),
		);
		$ring_age_groups = array(
			array( 'key' => 'cadet', 'label' => __( 'Cadet', 'ufsc-licence-competition' ), 'age_min' => 14, 'age_max' => 15 ),
			array( 'key' => 'junior', 'label' => __( 'Junior', 'ufsc-licence-competition' ), 'age_min' => 16, 'age_max' => 17 ),
			array( 'key' => 'senior', 'label' => __( 'Senior', 'ufsc-licence-competition' ), 'age_min' => 18, 'age_max' => 39 ),
			array( 'key' => 'veteran', 'label' => __( 'Vétéran', 'ufsc-licence-competition' ), 'age_min' => 40, 'age_max' => 99 ),
		);

		$assaut_rules = array(
			'age_groups' => $assaut_age_groups,
			'weights'    => $assaut_tatami_weights,
			'table'      => 'tatami',
		);

		return array(
			'default' => $assaut_rules,
			'assaut'  => $assaut_rules,
			'tatami'  => $assaut_rules,
			'ring'    => array(
				'age_groups' => $ring_age_groups,
				'weights' => array(
					'neutral' => $ring_weights,
					'm'       => $ring_weights,
					'f'       => $ring_weights,
				),
				'table' => 'ring',
			),
		);
	}

	private static function resolve_age_group( int $age, array $groups, string $sex = '' ): array {
		foreach ( $groups as $group ) {
			$min = isset( $group['age_min'] ) ? (int) $group['age_min'] : null;
			$max = isset( $group['age_max'] ) ? (int) $group['age_max'] : null;
			if ( null !== $min && $age < $min ) {
				continue;
			}
			if ( null !== $max && $age > $max ) {
				continue;
			}
			$group_sex = isset( $group['sex'] ) ? sanitize_key( (string) $group['sex'] ) : '';
			if ( '' !== $group_sex && '' !== $sex && $sex !== $group_sex ) {
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
			$threshold = (float) $threshold;
			$limit = abs( $threshold );
			if ( -1000.0 === $threshold ) {
				return self::format_weight_label( $threshold );
			}
			if ( $threshold > 0 ) {
				if ( $weight > $limit ) {
					return self::format_weight_label( $threshold );
				}
				continue;
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
		return ( $threshold > 0 ? '+' : '-' ) . $label;
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
