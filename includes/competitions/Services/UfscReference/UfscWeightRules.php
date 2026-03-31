<?php

namespace UFSC\Competitions\Services\UfscReference;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UfscWeightRules {
	public static function all(): array {
		$tatami = array( -30, -35, -40, -45, -50, -55, -60, -65, -70, -75, -80, -85, -90, -95, -100, -1000 );
		$ring   = array( -48, -51, -54, -57, -60, -63.5, -67, -71, -75, -81, -86, -91, -100, -1000 );

		return array(
			'default' => array(
				'neutral' => $tatami,
				'm'       => $tatami,
				'f'       => $tatami,
			),
			'tatami'  => array(
				'neutral' => $tatami,
				'm'       => $tatami,
				'f'       => $tatami,
			),
			'ring'    => array(
				'neutral' => $ring,
				'm'       => $ring,
				'f'       => $ring,
			),
		);
	}
}
