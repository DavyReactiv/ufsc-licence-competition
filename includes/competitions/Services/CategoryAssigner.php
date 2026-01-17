<?php

namespace UFSC\Competitions\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CategoryAssigner {
	public function match_category( array $categories, array $licensee, array $rules = array() ) {
		$age_ref = $rules['age_reference'] ?? '12-31';
		$age     = $this->calculate_age( $licensee['birth_date'] ?? '', $age_ref );
		$weight  = isset( $licensee['weight'] ) ? (float) $licensee['weight'] : null;
		$sex     = isset( $licensee['sex'] ) ? sanitize_text_field( $licensee['sex'] ) : '';
		$level   = isset( $licensee['level'] ) ? sanitize_text_field( $licensee['level'] ) : '';

		foreach ( $categories as $category ) {
			if ( null !== $category['age_min'] && $age < (int) $category['age_min'] ) {
				continue;
			}
			if ( null !== $category['age_max'] && $age > (int) $category['age_max'] ) {
				continue;
			}
			if ( null !== $category['weight_min'] && null !== $weight && $weight < (float) $category['weight_min'] ) {
				continue;
			}
			if ( null !== $category['weight_max'] && null !== $weight && $weight > (float) $category['weight_max'] ) {
				continue;
			}
			if ( $category['sex'] && $sex && 0 !== strcasecmp( $category['sex'], $sex ) ) {
				continue;
			}
			if ( $category['level'] && $level && 0 !== strcasecmp( $category['level'], $level ) ) {
				continue;
			}

			return $category;
		}

		return null;
	}

	private function calculate_age( $birth_date, $age_reference ) {
		if ( ! $birth_date ) {
			return 0;
		}

		$birth = date_create( $birth_date );
		if ( ! $birth ) {
			return 0;
		}

		$ref_year = (int) date_i18n( 'Y' );
		$reference = date_create_from_format( 'Y-m-d', sprintf( '%d-%s', $ref_year, $age_reference ) );
		if ( ! $reference ) {
			$reference = date_create_from_format( 'Y-m-d', sprintf( '%d-12-31', $ref_year ) );
		}

		return (int) $birth->diff( $reference )->y;
	}
}
