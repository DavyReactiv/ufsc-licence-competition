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
				'profiles'       => array(
					'default' => array(
						'default' => array(
							'default' => array( 'rounds' => 1, 'round_duration' => 2.0, 'break_duration' => 1.0, 'fight_pause' => 0.0 ),
						),
					),
				),
			),
			'tatami'  => array(
				'rounds'         => 1,
				'round_duration' => 2.0,
				'break_duration' => 1.0,
				'fight_pause'    => 0.5,
				'profiles'       => array(
					'elimination' => array(
						'junior' => array(
							'default' => array( 'rounds' => 2, 'round_duration' => 1.5, 'break_duration' => 0.5, 'fight_pause' => 0.5 ),
						),
						'senior' => array(
							'default' => array( 'rounds' => 2, 'round_duration' => 2.0, 'break_duration' => 1.0, 'fight_pause' => 0.5 ),
						),
					),
					'pool' => array(
						'default' => array(
							'default' => array( 'rounds' => 1, 'round_duration' => 2.0, 'break_duration' => 1.0, 'fight_pause' => 0.5 ),
						),
					),
				),
			),
			'ring'    => array(
				'rounds'         => 3,
				'round_duration' => 2.0,
				'break_duration' => 1.0,
				'fight_pause'    => 1.0,
				'profiles'       => array(
					'elimination' => array(
						'junior' => array(
							'default' => array( 'rounds' => 2, 'round_duration' => 2.0, 'break_duration' => 1.0, 'fight_pause' => 1.0 ),
						),
						'senior' => array(
							'default' => array( 'rounds' => 3, 'round_duration' => 2.0, 'break_duration' => 1.0, 'fight_pause' => 1.0 ),
						),
					),
					'title' => array(
						'senior' => array(
							'elite' => array( 'rounds' => 5, 'round_duration' => 2.0, 'break_duration' => 1.0, 'fight_pause' => 1.5 ),
						),
					),
				),
			),
		);
	}
}
