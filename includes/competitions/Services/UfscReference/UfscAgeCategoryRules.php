<?php

namespace UFSC\Competitions\Services\UfscReference;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UfscAgeCategoryRules {
	public static function all(): array {
		return array(
			array( 'key' => 'pre_poussins', 'label' => 'Pré-poussins mixte', 'age_min' => 6, 'age_max' => 7, 'birth_years' => '2018/2019', 'sex' => 'neutral' ),
			array( 'key' => 'poussins', 'label' => 'Poussins', 'age_min' => 8, 'age_max' => 9, 'birth_years' => '2016/2017', 'sex' => 'neutral' ),
			array( 'key' => 'benjamins', 'label' => 'Benjamins', 'age_min' => 10, 'age_max' => 11, 'birth_years' => '2014/2015', 'sex' => 'neutral' ),
			array( 'key' => 'minimes_filles', 'label' => 'Minimes filles', 'age_min' => 12, 'age_max' => 13, 'birth_years' => '2012/2013', 'sex' => 'f' ),
			array( 'key' => 'minimes_garcons', 'label' => 'Minimes garçons', 'age_min' => 12, 'age_max' => 13, 'birth_years' => '2012/2013', 'sex' => 'm' ),
			array( 'key' => 'cadettes', 'label' => 'Cadettes', 'age_min' => 14, 'age_max' => 15, 'birth_years' => '2010/2011', 'sex' => 'f' ),
			array( 'key' => 'cadets', 'label' => 'Cadets', 'age_min' => 14, 'age_max' => 15, 'birth_years' => '2010/2011', 'sex' => 'm' ),
			array( 'key' => 'juniors_filles', 'label' => 'Juniors filles', 'age_min' => 16, 'age_max' => 17, 'birth_years' => '2008/2009', 'sex' => 'f' ),
			array( 'key' => 'juniors_garcons', 'label' => 'Juniors garçons', 'age_min' => 16, 'age_max' => 17, 'birth_years' => '2008/2009', 'sex' => 'm' ),
			array( 'key' => 'seniors_femmes', 'label' => 'Seniors femmes', 'age_min' => 18, 'age_max' => 40, 'birth_years' => '1985/2007', 'sex' => 'f' ),
			array( 'key' => 'seniors_hommes', 'label' => 'Seniors hommes', 'age_min' => 18, 'age_max' => 40, 'birth_years' => '1985/2007', 'sex' => 'm' ),
			array( 'key' => 'veterans_feminines', 'label' => 'Vétérans féminines', 'age_min' => 41, 'age_max' => 50, 'birth_years' => '1975/1984', 'sex' => 'f' ),
			array( 'key' => 'veterans_masculins', 'label' => 'Vétérans masculins', 'age_min' => 41, 'age_max' => 50, 'birth_years' => '1975/1984', 'sex' => 'm' ),
		);
	}
}
