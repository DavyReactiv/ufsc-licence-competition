<?php

namespace UFSC\Competitions\Services\UfscReference;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UfscWeightRules {
	public static function all(): array {
		$tatami = array( -30, -35, -40, -45, -50, -55, -60, -65, -70, -75, -80, -85, -90, -95, -100, -1000 );
		$ring   = array( -48, -51, -54, -57, -60, -63.5, -67, -71, -75, -81, -86, -91, -100, -1000 );

		$junior_tatami_m = array( -42, -47, -52, -57, -63, -69, -74, -79, -84, -89, -94, -100, -1000 );
		$junior_tatami_f = array( -40, -45, -50, -55, -60, -65, -70, -75, -80, -85, -90, -1000 );
		$senior_ring_m   = array( -54, -57, -60, -63.5, -67, -71, -75, -81, -86, -91, -100, -1000 );
		$senior_ring_f   = array( -48, -52, -56, -60, -65, -70, -75, -1000 );

		return array(
			'default' => array(
				'neutral'    => $tatami,
				'm'          => $tatami,
				'f'          => $tatami,
				'age_groups' => array(
					'default' => array(
						'neutral' => $tatami,
						'm'       => $tatami,
						'f'       => $tatami,
					),
				),
			),
			'tatami'  => array(
				'neutral'    => $tatami,
				'm'          => $tatami,
				'f'          => $tatami,
				'age_groups' => array(
					'cadet' => array(
						'neutral' => $tatami,
						'm'       => $tatami,
						'f'       => $tatami,
					),
					'junior' => array(
						'neutral' => $tatami,
						'm'       => $junior_tatami_m,
						'f'       => $junior_tatami_f,
					),
					'senior' => array(
						'neutral' => $tatami,
						'm'       => $tatami,
						'f'       => $tatami,
					),
				),
			),
			'ring'    => array(
				'neutral'    => $ring,
				'm'          => $ring,
				'f'          => $ring,
				'age_groups' => array(
					'junior' => array(
						'neutral' => $ring,
						'm'       => $ring,
						'f'       => $ring,
					),
					'senior' => array(
						'neutral' => $ring,
						'm'       => $senior_ring_m,
						'f'       => $senior_ring_f,
					),
				),
			),
		);
	}
}