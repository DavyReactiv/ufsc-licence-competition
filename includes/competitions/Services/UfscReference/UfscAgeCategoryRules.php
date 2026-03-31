<?php

namespace UFSC\Competitions\Services\UfscReference;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UfscAgeCategoryRules {
	public static function all(): array {
		return array(
			array( 'key' => 'pre_poussins', 'label' => 'Pré-poussins', 'age_min' => 6, 'age_max' => 7, 'sex' => 'neutral' ),
			array( 'key' => 'poussins', 'label' => 'Poussins', 'age_min' => 8, 'age_max' => 9, 'sex' => 'neutral' ),
			array( 'key' => 'benjamins', 'label' => 'Benjamins', 'age_min' => 10, 'age_max' => 11, 'sex' => 'neutral' ),
			array( 'key' => 'minimes_filles', 'label' => 'Minimes filles', 'age_min' => 12, 'age_max' => 13, 'sex' => 'f' ),
			array( 'key' => 'minimes_garcons', 'label' => 'Minimes garçons', 'age_min' => 12, 'age_max' => 13, 'sex' => 'm' ),
			array( 'key' => 'cadettes', 'label' => 'Cadettes', 'age_min' => 14, 'age_max' => 15, 'sex' => 'f' ),
			array( 'key' => 'cadets', 'label' => 'Cadets', 'age_min' => 14, 'age_max' => 15, 'sex' => 'm' ),
			array( 'key' => 'juniors_filles', 'label' => 'Juniors filles', 'age_min' => 16, 'age_max' => 17, 'sex' => 'f' ),
			array( 'key' => 'juniors_garcons', 'label' => 'Juniors garçons', 'age_min' => 16, 'age_max' => 17, 'sex' => 'm' ),
			array( 'key' => 'seniors', 'label' => 'Seniors', 'age_min' => 18, 'age_max' => 34, 'sex' => 'neutral' ),
			array( 'key' => 'veterans_feminines', 'label' => 'Vétérans féminines', 'age_min' => 35, 'age_max' => 99, 'sex' => 'f' ),
			array( 'key' => 'veterans_masculins', 'label' => 'Vétérans masculins', 'age_min' => 35, 'age_max' => 99, 'sex' => 'm' ),
		);
	}
}
