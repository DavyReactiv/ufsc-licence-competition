<?php

namespace UFSC\Competitions\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CategoryAssigner {
	public function match_category( array $categories, array $licensee, array $rules = array() ) {
		if ( class_exists( '\\UFSC\\Competitions\\Services\\UfscReference\\UfscReferenceFacade' ) ) {
			$reference = \UFSC\Competitions\Services\UfscReference\UfscReferenceFacade::resolve_age_category(
				(string) ( $licensee['birth_date'] ?? '' ),
				(string) ( $licensee['sex'] ?? '' ),
				array(
					'reference_date' => (string) ( $rules['reference_date'] ?? '' ),
					'competition_date' => (string) ( $rules['competition_date'] ?? '' ),
					'event_start_datetime' => (string) ( $rules['event_start_datetime'] ?? '' ),
					'age_reference_date' => (string) ( $rules['age_reference_date'] ?? '' ),
					'season_end_year' => isset( $rules['season_end_year'] ) ? (int) $rules['season_end_year'] : 0,
					'age_reference' => (string) ( $rules['age_reference'] ?? '12-31' ),
				)
			);
			if ( is_array( $reference ) && ! empty( $reference['label'] ) ) {
				$reference_label = sanitize_text_field( (string) $reference['label'] );
				foreach ( $categories as $candidate ) {
					$candidate_name = sanitize_text_field( (string) ( $candidate['name'] ?? '' ) );
					if ( '' !== $candidate_name && 0 === strcasecmp( $candidate_name, $reference_label ) ) {
						return $candidate;
					}
				}
			}
		}

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
