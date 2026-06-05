<?php

namespace UFSC\Competitions\Services\UfscReference;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UfscWeightRules {
	public static function all(): array {
		$assaut = array(
			'pre_poussins'        => array( 'neutral' => array( -18, -23, -28, -32, -37, -42, -47, 47 ) ),
			'poussins'            => array( 'm' => array( -18, -23, -28, -32, -37, -42, -47, 47 ), 'f' => array( -18, -23, -28, -32, -37, -42, -47, 47 ) ),
			'benjamins'           => array( 'm' => array( -23, -28, -32, -37, -42, -47, -52, 52 ), 'f' => array( -23, -28, -32, -37, -42, -47, -52, 52 ) ),
			'minimes_filles'      => array( 'f' => array( -28, -32, -37, -42, -46, -50, -55, -60, 60 ) ),
			'minimes_garcons'     => array( 'm' => array( -28, -32, -37, -42, -47, -52, -57, -63, -69, 69 ) ),
			'cadettes'            => array( 'f' => array( -37, -42, -46, -50, -55, -60, -65, 65 ) ),
			'cadets'              => array( 'm' => array( -37, -42, -47, -52, -57, -63, -69, -74, 74 ) ),
			'juniors_filles'      => array( 'f' => array( -42, -46, -50, -55, -60, -65, -70, 70 ) ),
			'juniors_garcons'     => array( 'm' => array( -47, -52, -57, -63, -69, -74, -79, -84, -89, -94, 94 ) ),
			'seniors_femmes'      => array( 'f' => array( -50, -55, -60, -65, -70, 70 ) ),
			'veterans_feminines' => array( 'f' => array( -50, -55, -60, -65, -70, 70 ) ),
			'seniors_hommes'      => array( 'm' => array( -57, -63, -69, -74, -79, -84, -89, -94, 94 ) ),
			'veterans_masculins' => array( 'm' => array( -57, -63, -69, -74, -79, -84, -89, -94, 94 ) ),
		);
		$ring = array( -48, -51, -54, -57, -60, -63.5, -67, -71, -75, -81, -86, -91, -100, -1000 );
		$senior_ring_m = array( -54, -57, -60, -63.5, -67, -71, -75, -81, -86, -91, -100, -1000 );
		$senior_ring_f = array( -48, -52, -56, -60, -65, -70, -75, -1000 );

		$assaut_set = array(
			'neutral'    => array(),
			'm'          => array(),
			'f'          => array(),
			'age_groups' => $assaut,
		);

		return array(
			'default' => $assaut_set,
			'assaut'  => $assaut_set,
			'tatami'  => $assaut_set,
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
