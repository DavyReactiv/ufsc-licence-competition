<?php

namespace UFSC\Competitions\Services\UfscReference;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UfscTimingRules {
	public static function all(): array {
		return array(
			'default' => array(
				'rounds'         => 1,
				'round_duration' => 2.0,
				'break_duration' => 1.0,
				'fight_pause'    => 0.0,
			),
			'tatami'  => array(
				'rounds'         => 1,
				'round_duration' => 2.0,
				'break_duration' => 1.0,
				'fight_pause'    => 0.5,
			),
			'ring'    => array(
				'rounds'         => 3,
				'round_duration' => 2.0,
				'break_duration' => 1.0,
				'fight_pause'    => 1.0,
			),
		);
	}
}
