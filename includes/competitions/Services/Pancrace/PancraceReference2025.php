<?php

namespace UFSC\Competitions\Services\Pancrace;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only Pancrace 2025 reference extracted from the FFKMDA technical rules.
 *
 * Important: the source document names age bands with U-codes (U9, U11, ...)
 * but does not define numeric age_min/age_max boundaries. This class therefore
 * keeps those U-codes as the canonical age reference instead of inventing DOB
 * thresholds.
 */
class PancraceReference2025 {
	public const VERSION = '2025-v2';
	public const DISCIPLINE = 'pancrace';

	public static function source(): array {
		return array(
			'title' => 'Pancrace - Les règles techniques et de sécurité 2025',
			'publisher' => 'FFKMDA - Commission Nationale de Pancrace et Submission',
			'version' => self::VERSION,
		);
	}

	public static function forms(): array {
		return array(
			'assaut' => array(
				'label' => 'Assaut',
				'percussions' => 'touches',
				'prehensions' => array( 'projections', 'soumissions' ),
				'ko_allowed' => false,
			),
			'combat' => array(
				'label' => 'Combat',
				'percussions' => 'frappes',
				'prehensions' => array( 'projections', 'soumissions' ),
				'ko_allowed' => true,
			),
		);
	}

	public static function age_weight_groups(): array {
		return array(
			'u9' => self::group( 'Poussins', 'U9', array( -20, -23, -26, -30, -35, -40, -45, -50, -55, -60, -65, -70, 70 ) ),
			'u11' => self::group( 'Benjamins', 'U11', array( -20, -23, -26, -30, -35, -40, -45, -50, -55, -60, -65, -70, -75, 75 ) ),
			'u13' => self::group( 'Minimes', 'U13', array( -26, -30, -35, -40, -45, -50, -55, -60, -65, -70, -75, -80, 80 ) ),
			'u15' => self::group( 'Cadets', 'U15', array( -30, -35, -40, -45, -50, -55, -60, -65, -70, -77, -84, 84 ) ),
			'u17' => self::group( 'Juniors', 'U17', array( -40, -45, -50, -55, -60, -65, -70, -77, -84, -93, 93 ) ),
			'u40' => self::group( 'Seniors', 'U40', array( -56, -61, -65, -70, -77, -84, -93, -120, 120 ) ),
			'u50' => self::group( 'Vétérans', 'U50', array( -56, -61, -65, -70, -77, -84, -93, -120, 120 ) ),
		);
	}

	public static function competition_classes(): array {
		return array(
			'assaut_educatif' => array(
				'label' => 'Assaut éducatif',
				'form' => 'assaut',
				'age_codes' => array( 'u9', 'u11', 'u13', 'u15', 'u17' ),
				'surfaces' => array( 'tatami' ),
			),
			'assaut_technique' => array(
				'label' => 'Assaut technique',
				'form' => 'assaut',
				'age_codes' => array( 'u40', 'u50' ),
				'surfaces' => array( 'tatami' ),
			),
			'combat_junior' => array(
				'label' => 'Combat Junior',
				'form' => 'combat',
				'age_codes' => array( 'u17' ),
				'surfaces' => array( 'ring', 'cage' ),
			),
			'combat_classe_b' => array(
				'label' => 'Combat Classe B',
				'form' => 'combat',
				'age_codes' => array( 'u40' ),
				'surfaces' => array( 'ring', 'cage' ),
			),
			'combat_classe_a' => array(
				'label' => 'Combat Classe A',
				'form' => 'combat',
				'age_codes' => array( 'u40' ),
				'surfaces' => array( 'ring', 'cage' ),
			),
			'combat_pro' => array(
				'label' => 'Combat Pro',
				'form' => 'combat',
				'age_codes' => array( 'u49' ),
				'surfaces' => array( 'ring', 'cage' ),
				'note' => 'Les seniors pros peuvent combattre jusqu’à 49 ans inclus.',
			),
		);
	}

	public static function timing_profiles(): array {
		return array(
			'poussins_benjamins' => self::timing( array( 'u9', 'u11' ), 1, 120, null ),
			'minimes_cadets' => self::timing( array( 'u13', 'u15' ), 2, 120, 60 ),
			'juniors' => self::timing( array( 'u17' ), 3, 120, 60 ),
			'seniors_veterans' => self::timing( array( 'u40', 'u50' ), 3, 180, 60 ),
			'seniors_pro' => self::timing( array( 'u49' ), 3, 300, 60 ),
		);
	}

	public static function weighin_tolerances(): array {
		return array(
			'assaut_educatif' => array( 'age_codes' => array( 'u9', 'u11', 'u13', 'u15', 'u17' ), 'tolerance_kg' => 1.0 ),
			'assaut_technique' => array( 'age_codes' => array( 'u40', 'u50' ), 'tolerance_kg' => 0.5 ),
			'combat_amateur' => array( 'age_codes' => array( 'u17', 'u40' ), 'tolerance_kg' => 0.5 ),
			'combat_pro' => array( 'age_codes' => array( 'u49' ), 'tolerance_kg' => 0.0 ),
		);
	}

	public static function official_event_formats(): array {
		return array(
			'championship' => array(
				'label' => 'Championnat',
				'allowed_classes' => array( 'assaut_educatif', 'assaut_technique', 'combat_junior', 'combat_classe_b', 'combat_classe_a', 'combat_pro' ),
			),
			'cup' => array(
				'label' => 'Coupe',
				'allowed_classes' => array( 'assaut_educatif', 'assaut_technique', 'combat_junior', 'combat_classe_b', 'combat_classe_a' ),
			),
			'open' => array(
				'label' => 'Open',
				'allowed_classes' => array( 'assaut_educatif', 'assaut_technique', 'combat_junior', 'combat_classe_b', 'combat_classe_a' ),
			),
		);
	}

	public static function venue_surface_guidance(): array {
		return array(
			'national' => array( 'surface_min' => 1, 'surface_max' => 6 ),
			'regional' => array( 'surface_min' => 1, 'surface_max' => 3 ),
			'pro_semifinal_final' => array( 'surface_min' => 1, 'surface_max' => 1 ),
			'pro_round_of_16_quarterfinal' => array( 'surface_min' => 1, 'surface_max' => 1 ),
		);
	}

	public static function surfaces(): array {
		return array( 'tatami', 'ring', 'cage' );
	}

	public static function weight_labels( string $age_code ): array {
		$age_code = self::normalize_key( $age_code );
		$groups = self::age_weight_groups();
		if ( ! isset( $groups[ $age_code ] ) ) {
			return array();
		}

		return array_map( array( __CLASS__, 'format_weight_label' ), $groups[ $age_code ]['weights'] );
	}

	public static function resolve_weight_label( string $age_code, $weight_kg ): string {
		$age_code = self::normalize_key( $age_code );
		$groups = self::age_weight_groups();
		if ( ! isset( $groups[ $age_code ] ) || ! is_numeric( $weight_kg ) ) {
			return '';
		}

		$weight_kg = (float) $weight_kg;
		foreach ( $groups[ $age_code ]['weights'] as $threshold ) {
			$threshold = (float) $threshold;
			if ( $threshold < 0 && $weight_kg <= abs( $threshold ) ) {
				return self::format_weight_label( $threshold );
			}
			if ( $threshold > 0 && $weight_kg > $threshold ) {
				return self::format_weight_label( $threshold );
			}
		}

		return '';
	}

	public static function is_official_class_allowed( string $official_format, string $competition_class ): bool {
		$official_format = self::normalize_key( $official_format );
		$competition_class = self::normalize_key( $competition_class );
		$formats = self::official_event_formats();

		return isset( $formats[ $official_format ] ) && in_array( $competition_class, $formats[ $official_format ]['allowed_classes'], true );
	}

	private static function group( string $label, string $age_code, array $weights ): array {
		return array(
			'label' => $label,
			'age_code' => $age_code,
			'weights' => $weights,
		);
	}

	private static function timing( array $age_codes, int $rounds, int $round_seconds, ?int $rest_seconds ): array {
		return array(
			'age_codes' => $age_codes,
			'rounds' => $rounds,
			'round_seconds' => $round_seconds,
			'rest_seconds' => $rest_seconds,
		);
	}

	private static function format_weight_label( $weight ): string {
		$weight = (float) $weight;
		$prefix = $weight > 0 ? '+' : '-';
		$value = rtrim( rtrim( number_format( abs( $weight ), 1, '.', '' ), '0' ), '.' );

		return $prefix . $value . ' kg';
	}

	private static function normalize_key( string $value ): string {
		return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', str_replace( ' ', '_', $value ) ) );
	}
}
