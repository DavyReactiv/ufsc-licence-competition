<?php

namespace UFSC\Competitions\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DisciplineRegistry {
	const OPTION_NAME = 'ufsc_competitions_disciplines';

	public static function get_disciplines() {
		$default = array(
			'kickboxing'    => __( 'Kickboxing', 'ufsc-licence-competition' ),
			'k1'            => __( 'K1', 'ufsc-licence-competition' ),
			'full_contact'  => __( 'Full contact', 'ufsc-licence-competition' ),
			'mma'           => __( 'MMA', 'ufsc-licence-competition' ),
			'cardio_boxing' => __( 'Cardio boxing', 'ufsc-licence-competition' ),
			'autre'         => __( 'Autre', 'ufsc-licence-competition' ),
		);

		$stored = get_option( self::OPTION_NAME, array() );
		if ( is_array( $stored ) && ! empty( $stored ) ) {
			$clean = array();
			foreach ( $stored as $key => $label ) {
				$key = sanitize_key( $key );
				if ( '' === $key ) {
					continue;
				}
				$clean[ $key ] = sanitize_text_field( $label );
			}

			if ( $clean ) {
				return $clean;
			}
		}

		return $default;
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
}
