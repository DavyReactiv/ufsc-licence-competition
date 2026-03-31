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
				'fundus_required' => false,
				'equipment' => array(
					'protege_dents' => true,
					'coquille' => true,
					'gants' => true,
				),
				'other' => array(
					'passport_required' => true,
				),
			),
			'mma' => array(
				'certificate_medical' => true,
				'questionnaire_medical' => false,
				'fundus_required' => true,
				'ecg_required_age' => 35,
				'equipment' => array(
					'protege_dents' => true,
					'coquille' => true,
					'gants_mma' => true,
					'prot_tibias' => true,
				),
				'other' => array(
					'medical_control_day_of_event' => true,
				),
			),
			'ring' => array(
				'certificate_medical' => true,
				'questionnaire_medical' => false,
				'fundus_required' => false,
				'ecg_required_age' => 40,
				'equipment' => array(
					'casque' => false,
					'gants_boxe' => true,
					'coquille' => true,
					'protege_dents' => true,
				),
			),
		);
	}
}
