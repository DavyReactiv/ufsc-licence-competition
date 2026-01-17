<?php

namespace UFSC\Competitions\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CategoryPresetRegistry {
	const OPTION_NAME = 'ufsc_competitions_category_presets';
	const VERSION = '2024-2025';

	public static function get_package() {
		$stored = get_option( self::OPTION_NAME, array() );
		if ( is_array( $stored ) && ! empty( $stored['presets'] ) ) {
			return $stored;
		}

		return array();
	}

	public static function get_presets() {
		$package = self::get_package();
		if ( $package ) {
			return $package['presets'];
		}

		return array();
	}

	public static function get_preset( $type ) {
		$type = sanitize_key( $type );
		$presets = self::get_presets();
		if ( isset( $presets[ $type ] ) ) {
			return $presets[ $type ];
		}

		$defaults = self::get_default_presets();
		return $defaults[ $type ] ?? array();
	}

	public static function load_default_presets() {
		$package = array(
			'version'    => self::VERSION,
			'updated_at' => current_time( 'mysql' ),
			'presets'    => self::get_default_presets(),
		);

		update_option( self::OPTION_NAME, $package, false );

		return $package;
	}

	public static function get_default_presets() {
		return array(
			DisciplineRegistry::TYPE_TATAMI => array(
				'label'      => __( 'UFSC Tatami', 'ufsc-licence-competition' ),
				'categories' => self::build_categories(
					array(
						array( 'label' => __( 'Poussin', 'ufsc-licence-competition' ), 'age_min' => 7, 'age_max' => 9 ),
						array( 'label' => __( 'Pupille', 'ufsc-licence-competition' ), 'age_min' => 10, 'age_max' => 11 ),
						array( 'label' => __( 'Benjamin', 'ufsc-licence-competition' ), 'age_min' => 12, 'age_max' => 13 ),
						array( 'label' => __( 'Cadet', 'ufsc-licence-competition' ), 'age_min' => 14, 'age_max' => 15 ),
						array( 'label' => __( 'Junior', 'ufsc-licence-competition' ), 'age_min' => 16, 'age_max' => 17 ),
						array( 'label' => __( 'Senior', 'ufsc-licence-competition' ), 'age_min' => 18, 'age_max' => 34 ),
					),
					array(
						-30, -35, -40, -45, -50, -55, -60, -65, -70, -75, -80, -85, -90, -95, -100, -1000,
					)
				),
			),
			DisciplineRegistry::TYPE_RING => array(
				'label'      => __( 'UFSC Ring', 'ufsc-licence-competition' ),
				'categories' => self::build_categories(
					array(
						array( 'label' => __( 'Cadet', 'ufsc-licence-competition' ), 'age_min' => 14, 'age_max' => 15 ),
						array( 'label' => __( 'Junior', 'ufsc-licence-competition' ), 'age_min' => 16, 'age_max' => 17 ),
						array( 'label' => __( 'Senior', 'ufsc-licence-competition' ), 'age_min' => 18, 'age_max' => 39 ),
						array( 'label' => __( 'Vétéran', 'ufsc-licence-competition' ), 'age_min' => 40, 'age_max' => 99 ),
					),
					array(
						-48, -51, -54, -57, -60, -63.5, -67, -71, -75, -81, -86, -91, -100, -1000,
					),
					__( 'Ring', 'ufsc-licence-competition' )
				),
			),
		);
	}

	private static function build_categories( array $age_groups, array $weights, $format = '' ) {
		$categories = array();
		foreach ( $age_groups as $group ) {
			foreach ( $weights as $index => $weight ) {
				$min = $index === 0 ? null : abs( (float) $weights[ $index - 1 ] );
				$max = abs( (float) $weight );
				$label = $group['label'] . ' ' . self::format_weight_label( $weights[ $index ] );
				$categories[] = array(
					'name'       => $label,
					'age_min'    => $group['age_min'],
					'age_max'    => $group['age_max'],
					'weight_min' => $min,
					'weight_max' => $weights[ $index ] === -1000 ? null : $max,
					'sex'        => '',
					'level'      => '',
					'format'     => $format,
				);
			}
		}

		return $categories;
	}

	private static function format_weight_label( $weight ) {
		if ( $weight === -1000 ) {
			return __( '+100kg', 'ufsc-licence-competition' );
		}

		return sprintf( __( '%skg', 'ufsc-licence-competition' ), $weight );
	}
}
