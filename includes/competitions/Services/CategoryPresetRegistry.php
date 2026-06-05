<?php

namespace UFSC\Competitions\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CategoryPresetRegistry {
	const OPTION_NAME = 'ufsc_competitions_category_presets';
	const VERSION = '2025-2026-assaut-tatami';

	public static function get_package() {
		$stored = get_option( self::OPTION_NAME, array() );
		if ( is_array( $stored ) && ! empty( $stored['presets'] ) && self::VERSION === (string) ( $stored['version'] ?? '' ) ) {
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
				'label'      => __( 'UFSC Assaut / Tatami', 'ufsc-licence-competition' ),
				'categories' => self::get_assaut_tatami_categories_reference(),
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

	/**
	 * Official UFSC ASSAUT / TATAMI age, sex, birth-year and weight reference.
	 *
	 * This method is intentionally pure/read-only. Importers may use it to add
	 * missing category rows, but it never updates or deletes existing categories,
	 * inscriptions, weigh-ins, fights or results.
	 */
	public static function get_assaut_tatami_categories_reference(): array {
		$groups = array(
			array( 'label' => 'Pré-poussins mixte', 'key' => 'pre_poussins_mixte', 'age_min' => 6, 'age_max' => 7, 'birth_years' => '2018/2019', 'sexes' => array( '' ), 'weights' => array( -18, -23, -28, -32, -37, -42, -47, 47 ) ),
			array( 'label' => 'Poussins', 'key' => 'poussins', 'age_min' => 8, 'age_max' => 9, 'birth_years' => '2016/2017', 'sexes' => array( 'm', 'f' ), 'weights' => array( -18, -23, -28, -32, -37, -42, -47, 47 ) ),
			array( 'label' => 'Benjamins', 'key' => 'benjamins', 'age_min' => 10, 'age_max' => 11, 'birth_years' => '2014/2015', 'sexes' => array( 'm', 'f' ), 'weights' => array( -23, -28, -32, -37, -42, -47, -52, 52 ) ),
			array( 'label' => 'Minimes filles', 'key' => 'minimes_filles', 'age_min' => 12, 'age_max' => 13, 'birth_years' => '2012/2013', 'sexes' => array( 'f' ), 'weights' => array( -28, -32, -37, -42, -46, -50, -55, -60, 60 ) ),
			array( 'label' => 'Minimes garçons', 'key' => 'minimes_garcons', 'age_min' => 12, 'age_max' => 13, 'birth_years' => '2012/2013', 'sexes' => array( 'm' ), 'weights' => array( -28, -32, -37, -42, -47, -52, -57, -63, -69, 69 ) ),
			array( 'label' => 'Cadettes', 'key' => 'cadettes', 'age_min' => 14, 'age_max' => 15, 'birth_years' => '2010/2011', 'sexes' => array( 'f' ), 'weights' => array( -37, -42, -46, -50, -55, -60, -65, 65 ) ),
			array( 'label' => 'Cadets', 'key' => 'cadets', 'age_min' => 14, 'age_max' => 15, 'birth_years' => '2010/2011', 'sexes' => array( 'm' ), 'weights' => array( -37, -42, -47, -52, -57, -63, -69, -74, 74 ) ),
			array( 'label' => 'Juniors filles', 'key' => 'juniors_filles', 'age_min' => 16, 'age_max' => 17, 'birth_years' => '2008/2009', 'sexes' => array( 'f' ), 'weights' => array( -42, -46, -50, -55, -60, -65, -70, 70 ) ),
			array( 'label' => 'Juniors garçons', 'key' => 'juniors_garcons', 'age_min' => 16, 'age_max' => 17, 'birth_years' => '2008/2009', 'sexes' => array( 'm' ), 'weights' => array( -47, -52, -57, -63, -69, -74, -79, -84, -89, -94, 94 ) ),
			array( 'label' => 'Seniors femmes', 'key' => 'seniors_femmes', 'age_min' => 18, 'age_max' => 40, 'birth_years' => '1985/2007', 'sexes' => array( 'f' ), 'weights' => array( -50, -55, -60, -65, -70, 70 ) ),
			array( 'label' => 'Vétérans féminines', 'key' => 'veterans_feminines', 'age_min' => 41, 'age_max' => 50, 'birth_years' => '1975/1984', 'sexes' => array( 'f' ), 'weights' => array( -50, -55, -60, -65, -70, 70 ) ),
			array( 'label' => 'Seniors hommes', 'key' => 'seniors_hommes', 'age_min' => 18, 'age_max' => 40, 'birth_years' => '1985/2007', 'sexes' => array( 'm' ), 'weights' => array( -57, -63, -69, -74, -79, -84, -89, -94, 94 ) ),
			array( 'label' => 'Vétérans masculins', 'key' => 'veterans_masculins', 'age_min' => 41, 'age_max' => 50, 'birth_years' => '1975/1984', 'sexes' => array( 'm' ), 'weights' => array( -57, -63, -69, -74, -79, -84, -89, -94, 94 ) ),
		);

		$categories = array();
		foreach ( $groups as $group ) {
			foreach ( $group['sexes'] as $sex ) {
				foreach ( $group['weights'] as $index => $weight ) {
					$previous = 0 === $index ? null : abs( (float) $group['weights'][ $index - 1 ] );
					$is_open = (float) $weight > 0;
					$sex_suffix = '' === $sex ? '' : ' ' . strtoupper( $sex );
					$categories[] = array(
						'name'        => $group['label'] . $sex_suffix . ' ' . self::format_weight_label( $weight ),
						'age_min'     => $group['age_min'],
						'age_max'     => $group['age_max'],
						'birth_years' => $group['birth_years'],
						'weight_min'  => $is_open ? abs( (float) $weight ) : $previous,
						'weight_max'  => $is_open ? null : abs( (float) $weight ),
						'sex'         => $sex,
						'level'       => '',
						'format'      => 'Assaut Tatami',
						'reference'   => $group['key'],
					);
				}
			}
		}

		return $categories;
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
		$weight = (float) $weight;
		if ( -1000.0 === $weight ) {
			return __( '+100 kg', 'ufsc-licence-competition' );
		}
		$prefix = $weight > 0 ? '+' : '-';
		$label = rtrim( rtrim( number_format( abs( $weight ), 1, '.', '' ), '0' ), '.' );
		return sprintf( __( '%1$s%2$s kg', 'ufsc-licence-competition' ), $prefix, $label );
	}
}
