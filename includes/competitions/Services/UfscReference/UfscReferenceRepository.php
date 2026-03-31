<?php

namespace UFSC\Competitions\Services\UfscReference;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UfscReferenceRepository {
	public function is_enabled(): bool {
		$enabled = (bool) apply_filters( 'ufsc_competitions_reference_enabled', false );
		return $enabled;
	}

	public function get_age_rules(): array {
		$rules = UfscAgeCategoryRules::all();
		$rules = apply_filters( 'ufsc_competitions_reference_age_rules', $rules );
		return is_array( $rules ) ? $rules : array();
	}

	public function get_weight_rules(): array {
		$rules = UfscWeightRules::all();
		$rules = apply_filters( 'ufsc_competitions_reference_weight_rules', $rules );
		return is_array( $rules ) ? $rules : array();
	}

	public function get_timing_rules(): array {
		$rules = UfscTimingRules::all();
		$rules = apply_filters( 'ufsc_competitions_reference_timing_rules', $rules );
		return is_array( $rules ) ? $rules : array();
	}

	public function get_obligation_rules(): array {
		$rules = UfscObligationRules::all();
		$rules = apply_filters( 'ufsc_competitions_reference_obligation_rules', $rules );
		return is_array( $rules ) ? $rules : array();
	}
}
