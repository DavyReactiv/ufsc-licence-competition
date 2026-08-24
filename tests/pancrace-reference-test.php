<?php

define( 'ABSPATH', __DIR__ );

require_once __DIR__ . '/../includes/competitions/Services/Pancrace/PancraceReference2025.php';

use UFSC\Competitions\Services\Pancrace\PancraceReference2025;

function pancrace_assert_same( $expected, $actual, $label ) {
	if ( $expected !== $actual ) {
		fwrite( STDERR, sprintf( "FAIL %s: expected %s, got %s\n", $label, var_export( $expected, true ), var_export( $actual, true ) ) );
		exit( 1 );
	}
}

$groups = PancraceReference2025::age_weight_groups();
pancrace_assert_same( 7, count( $groups ), 'age/weight group count' );
pancrace_assert_same( 'Poussins', $groups['u9']['label'], 'U9 label' );
pancrace_assert_same( '+70 kg', PancraceReference2025::weight_labels( 'u9' )[12], 'U9 open weight' );
pancrace_assert_same( '+75 kg', PancraceReference2025::weight_labels( 'u11' )[13], 'U11 open weight' );
pancrace_assert_same( '+80 kg', PancraceReference2025::weight_labels( 'u13' )[12], 'U13 open weight' );
pancrace_assert_same( '+84 kg', PancraceReference2025::weight_labels( 'u15' )[11], 'U15 open weight' );
pancrace_assert_same( '+93 kg', PancraceReference2025::weight_labels( 'u17' )[10], 'U17 open weight' );
pancrace_assert_same( '+120 kg', PancraceReference2025::weight_labels( 'u40' )[8], 'U40 open weight' );
pancrace_assert_same( '+120 kg', PancraceReference2025::weight_labels( 'u50' )[8], 'U50 open weight' );

pancrace_assert_same( '-56 kg', PancraceReference2025::resolve_weight_label( 'u40', 55.8 ), 'senior -56' );
pancrace_assert_same( '-61 kg', PancraceReference2025::resolve_weight_label( 'u40', 60.2 ), 'senior -61' );
pancrace_assert_same( '+120 kg', PancraceReference2025::resolve_weight_label( 'u40', 121.0 ), 'senior open' );
pancrace_assert_same( '', PancraceReference2025::resolve_weight_label( 'unknown', 70 ), 'unknown age code' );

$classes = PancraceReference2025::competition_classes();
pancrace_assert_same( array( 'tatami' ), $classes['assaut_educatif']['surfaces'], 'assaut educatif surface' );
pancrace_assert_same( array( 'ring', 'cage' ), $classes['combat_classe_a']['surfaces'], 'class A surfaces' );
pancrace_assert_same( array( 'u49' ), $classes['combat_pro']['age_codes'], 'pro age code' );

$timings = PancraceReference2025::timing_profiles();
pancrace_assert_same( array( 'age_codes' => array( 'u9', 'u11' ), 'rounds' => 1, 'round_seconds' => 120, 'rest_seconds' => null ), $timings['poussins_benjamins'], 'poussins/benjamins timing' );
pancrace_assert_same( 2, $timings['minimes_cadets']['rounds'], 'minimes/cadets rounds' );
pancrace_assert_same( 180, $timings['seniors_veterans']['round_seconds'], 'senior round duration' );
pancrace_assert_same( 300, $timings['seniors_pro']['round_seconds'], 'pro round duration' );

$tolerances = PancraceReference2025::weighin_tolerances();
pancrace_assert_same( 1.0, $tolerances['assaut_educatif']['tolerance_kg'], 'educative weigh-in tolerance' );
pancrace_assert_same( 0.5, $tolerances['combat_amateur']['tolerance_kg'], 'amateur weigh-in tolerance' );
pancrace_assert_same( 0.0, $tolerances['combat_pro']['tolerance_kg'], 'pro weigh-in tolerance' );

pancrace_assert_same( true, PancraceReference2025::is_official_class_allowed( 'championship', 'combat_pro' ), 'pro championship allowed' );
pancrace_assert_same( false, PancraceReference2025::is_official_class_allowed( 'cup', 'combat_pro' ), 'pro cup forbidden in source reference' );
pancrace_assert_same( false, PancraceReference2025::is_official_class_allowed( 'open', 'combat_pro' ), 'pro open forbidden in source reference' );
pancrace_assert_same( true, PancraceReference2025::is_official_class_allowed( 'open', 'combat_classe_a' ), 'class A open allowed' );

$venue = PancraceReference2025::venue_surface_guidance();
pancrace_assert_same( 6, $venue['national']['surface_max'], 'national max surfaces' );
pancrace_assert_same( 3, $venue['regional']['surface_max'], 'regional max surfaces' );

echo "PANCRACE 2025 reference tests passed.\n";
