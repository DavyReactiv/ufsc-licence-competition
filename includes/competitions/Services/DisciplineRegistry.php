<?php

namespace UFSC\Competitions\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DisciplineRegistry {
	const OPTION_NAME = 'ufsc_competitions_disciplines';
	const TYPE_TATAMI = 'tatami';
	const TYPE_RING = 'ring';
	const TYPE_OTHER = 'other';

	public static function get_disciplines() {
		$disciplines = self::get_disciplines_with_types();
		$labels = array();
		foreach ( $disciplines as $key => $data ) {
			$labels[ $key ] = $data['label'];
		}

		return $labels;
	}

	public static function get_disciplines_with_types() {
		$stored = get_option( self::OPTION_NAME, array() );
		if ( is_array( $stored ) && ! empty( $stored ) ) {
			$clean = array();
			foreach ( $stored as $key => $value ) {
				$key = sanitize_key( $key );
				if ( '' === $key ) {
					continue;
				}

				if ( is_array( $value ) ) {
					$label = sanitize_text_field( $value['label'] ?? '' );
					$type = sanitize_key( $value['type'] ?? '' );
				} else {
					$label = sanitize_text_field( $value );
					$type = '';
				}

				if ( '' === $label ) {
					continue;
				}

				$clean[ $key ] = array(
					'label' => $label,
					'type'  => self::normalize_type( $type ),
				);
			}

			if ( $clean ) {
				return $clean;
			}
		}

		return self::get_default_disciplines();
	}

	public static function get_type( $discipline ) {
		$discipline = sanitize_key( $discipline );
		$disciplines = self::get_disciplines_with_types();

		return $disciplines[ $discipline ]['type'] ?? self::TYPE_OTHER;
	}

	public static function is_valid( $discipline ) {
		$discipline = sanitize_key( $discipline );
		$disciplines = self::get_disciplines();

		return isset( $disciplines[ $discipline ] );
	}

	public static function normalize( $discipline ) {
		$discipline = sanitize_key( $discipline );
		if ( ! self::is_valid( $discipline ) ) {
			return '';
		}

		return $discipline;
	}

	public static function get_label( $discipline ) {
		$disciplines = self::get_disciplines();
		$discipline  = sanitize_key( $discipline );

		return $disciplines[ $discipline ] ?? $discipline;
	}

	private static function get_default_disciplines() {
		return array(
			'light_contact'  => array(
				'label' => __( 'Light Contact', 'ufsc-licence-competition' ),
				'type'  => self::TYPE_TATAMI,
			),
			'kick_light'     => array(
				'label' => __( 'Kick Light', 'ufsc-licence-competition' ),
				'type'  => self::TYPE_TATAMI,
			),
			'point_fighting' => array(
				'label' => __( 'Point Fighting', 'ufsc-licence-competition' ),
				'type'  => self::TYPE_TATAMI,
			),
			'k1_style_light' => array(
				'label' => __( 'K1 Style Light', 'ufsc-licence-competition' ),
				'type'  => self::TYPE_TATAMI,
			),
			'low_kick'       => array(
				'label' => __( 'Low Kick', 'ufsc-licence-competition' ),
				'type'  => self::TYPE_RING,
			),
			'full_contact'   => array(
				'label' => __( 'Full Contact', 'ufsc-licence-competition' ),
				'type'  => self::TYPE_RING,
			),
			'k1_style'       => array(
				'label' => __( 'K1 Style', 'ufsc-licence-competition' ),
				'type'  => self::TYPE_RING,
			),
			'kickboxing'     => array(
				'label' => __( 'Kickboxing', 'ufsc-licence-competition' ),
				'type'  => self::TYPE_TATAMI,
			),
			'k1'             => array(
				'label' => __( 'K1', 'ufsc-licence-competition' ),
				'type'  => self::TYPE_RING,
			),
			'mma'            => array(
				'label' => __( 'MMA', 'ufsc-licence-competition' ),
				'type'  => self::TYPE_OTHER,
			),
			'cardio_boxing'  => array(
				'label' => __( 'Cardio boxing', 'ufsc-licence-competition' ),
				'type'  => self::TYPE_OTHER,
			),
			'autre'          => array(
				'label' => __( 'Autre', 'ufsc-licence-competition' ),
				'type'  => self::TYPE_OTHER,
			),
		);
	}

	private static function normalize_type( $type ) {
		$type = sanitize_key( $type );
		$allowed = array( self::TYPE_TATAMI, self::TYPE_RING, self::TYPE_OTHER );
		if ( ! in_array( $type, $allowed, true ) ) {
			return self::TYPE_OTHER;
		}

		return $type;
	}
}
