<?php

define( 'ABSPATH', __DIR__ );

function __( $text, $domain = null ) { return $text; }
function sanitize_key( $key ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', str_replace( ' ', '_', (string) $key ) ) ); }
function sanitize_text_field( $text ) { return trim( strip_tags( (string) $text ) ); }
function apply_filters( $tag, $value ) { return 'ufsc_competitions_reference_enabled' === $tag ? true : $value; }
function get_option( $name, $default = false ) { return $default; }
function wp_timezone() { return new DateTimeZone( 'UTC' ); }

require_once __DIR__ . '/../includes/competitions/Services/DisciplineRegistry.php';
require_once __DIR__ . '/../includes/competitions/Services/CategoryPresetRegistry.php';
require_once __DIR__ . '/../includes/competitions/Services/UfscReference/UfscReferenceDecision.php';
require_once __DIR__ . '/../includes/competitions/Services/UfscReference/UfscReferenceNormalizer.php';
require_once __DIR__ . '/../includes/competitions/Services/UfscReference/UfscReferenceDiagnostics.php';
require_once __DIR__ . '/../includes/competitions/Services/UfscReference/UfscReferenceDebugLogger.php';
require_once __DIR__ . '/../includes/competitions/Services/UfscReference/UfscReferenceDateResolver.php';
require_once __DIR__ . '/../includes/competitions/Services/UfscReference/UfscReferenceFallback.php';
require_once __DIR__ . '/../includes/competitions/Services/UfscReference/UfscAgeCategoryRules.php';
require_once __DIR__ . '/../includes/competitions/Services/UfscReference/UfscWeightRules.php';
require_once __DIR__ . '/../includes/competitions/Services/UfscReference/UfscTimingRules.php';
require_once __DIR__ . '/../includes/competitions/Services/UfscReference/UfscObligationRules.php';
require_once __DIR__ . '/../includes/competitions/Services/UfscReference/UfscReferenceRepository.php';
require_once __DIR__ . '/../includes/competitions/Services/UfscReference/UfscReferenceFacade.php';
require_once __DIR__ . '/../includes/competitions/Services/WeightCategoryResolver.php';

use UFSC\Competitions\Services\CategoryPresetRegistry;
use UFSC\Competitions\Services\WeightCategoryResolver;
use UFSC\Competitions\Services\UfscReference\UfscReferenceFacade;

function assert_same_value( $expected, $actual, $label ) {
	if ( $expected !== $actual ) {
		fwrite( STDERR, sprintf( "FAIL %s: expected %s, got %s\n", $label, var_export( $expected, true ), var_export( $actual, true ) ) );
		exit( 1 );
	}
}

$reference = CategoryPresetRegistry::get_assaut_tatami_categories_reference();
assert_same_value( 125, count( $reference ), 'reference row count' );

$names = array_column( $reference, 'name' );
foreach ( array(
	'Pré-poussins mixte +47 kg',
	'Poussins M -18 kg',
	'Poussins F +47 kg',
	'Benjamins M +52 kg',
	'Minimes filles F -46 kg',
	'Minimes garçons M -47 kg',
	'Cadettes F -65 kg',
	'Cadettes F +65 kg',
	'Cadets M -74 kg',
	'Cadets M +74 kg',
	'Juniors filles F +70 kg',
	'Juniors garçons M +94 kg',
	'Seniors femmes F -50 kg',
	'Seniors femmes F +70 kg',
	'Seniors hommes M -57 kg',
	'Seniors hommes M +94 kg',
	'Vétérans féminines F +70 kg',
	'Vétérans masculins M +94 kg',
) as $expected_name ) {
	assert_same_value( true, in_array( $expected_name, $names, true ), $expected_name );
}

$context = array( 'discipline' => 'assaut', 'season_end_year' => 2025, 'age_reference' => '12-31' );
$cases = array(
	array( '2019-01-01', 'm', 48.0, '+47', 'pre-poussin open' ),
	array( '2017-01-01', 'f', 17.8, '-18', 'poussine -18' ),
	array( '2015-01-01', 'm', 53.0, '+52', 'benjamin open' ),
	array( '2013-01-01', 'f', 45.5, '-46', 'minime fille -46' ),
	array( '2013-01-01', 'm', 46.5, '-47', 'minime garçon -47' ),
	array( '2011-01-01', 'f', 64.8, '-65', 'cadette -65' ),
	array( '2011-01-01', 'f', 66.0, '+65', 'cadette +65' ),
	array( '2011-01-01', 'm', 73.5, '-74', 'cadet -74' ),
	array( '2011-01-01', 'm', 75.0, '+74', 'cadet +74' ),
	array( '2009-01-01', 'f', 71.0, '+70', 'junior fille +70' ),
	array( '2009-01-01', 'm', 95.0, '+94', 'junior garçon +94' ),
	array( '2007-01-01', 'f', 49.8, '-50', 'senior femme -50' ),
	array( '1985-01-01', 'f', 71.0, '+70', 'senior femme +70' ),
	array( '2007-01-01', 'm', 56.8, '-57', 'senior homme -57' ),
	array( '1985-01-01', 'm', 95.0, '+94', 'senior homme +94' ),
	array( '1984-01-01', 'f', 71.0, '+70', 'vétéran féminine' ),
	array( '1984-01-01', 'm', 95.0, '+94', 'vétéran masculin' ),
);

foreach ( $cases as $case ) {
	$result = WeightCategoryResolver::resolve( $case[0], $case[1], $case[2], $context );
	assert_same_value( $case[3], $result, $case[4] );
}

assert_same_value( false, in_array( '-47', WeightCategoryResolver::get_weight_classes( '2013-01-01', 'f', $context ), true ), 'minime fille cannot use garçon -47' );
assert_same_value( false, in_array( '+70', WeightCategoryResolver::get_weight_classes( '2009-01-01', 'm', $context ), true ), 'junior garçon cannot use fille +70' );

$minor_f = UfscReferenceFacade::resolve_age_category( '2013-01-01', 'f', $context );
$minor_m = UfscReferenceFacade::resolve_age_category( '2013-01-01', 'm', $context );
assert_same_value( 'minimes_filles', $minor_f['key'] ?? '', 'age category minimes filles' );
assert_same_value( 'minimes_garcons', $minor_m['key'] ?? '', 'age category minimes garçons' );

echo "ASSAUT / TATAMI reference tests passed.\n";
