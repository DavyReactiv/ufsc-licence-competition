<?php

namespace UFSC\Competitions\Services\UfscReference;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UfscObligationRules {
	public static function all(): array {
		return array(
			'default' => array(
				'certificate_medical' => true,
				'questionnaire_medical' => false,
				'ecg_required_age' => 40,
			),
			'mma' => array(
				'certificate_medical' => true,
				'questionnaire_medical' => false,
				'fundus_required' => true,
				'ecg_required_age' => 35,
			),
		);
	}
}
