<?php

define( 'ABSPATH', __DIR__ . '/' );

function __( $text, $domain = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	return $text;
}
function sanitize_key( $value ) {
	$value = strtolower( (string) $value );
	return preg_replace( '/[^a-z0-9_\-]/', '', $value );
}
function sanitize_textarea_field( $value ) {
	return trim( strip_tags( (string) $value ) );
}
function add_filter() {
	return true;
}

require_once dirname( __DIR__ ) . '/includes/competitions/Services/CompetitionRequirementService.php';

use UFSC\Competitions\Services\CompetitionRequirementService;

function assert_true( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

$federal = (object) array(
	'id'   => 1,
	'type' => 'championnat_national',
);

$auto = CompetitionRequirementService::build_checklist(
	$federal,
	array(
		'requirements_mode'     => 'auto',
		'require_valid_license' => true,
	)
);
$auto_text = implode( ' | ', $auto );
assert_true( false !== strpos( $auto_text, 'médical' ), 'Auto mode must remind clubs about medical checks.' );
assert_true( false !== strpos( $auto_text, 'mineur' ), 'Auto mode must remind clubs about parental authorization.' );
assert_true( false !== strpos( $auto_text, 'passeport sportif' ), 'Federal auto mode must remind clubs about sport passport.' );
assert_true( false !== strpos( $auto_text, 'licence UFSC' ), 'Valid licence requirement must be reflected in the checklist.' );

$custom = CompetitionRequirementService::build_checklist(
	$federal,
	array(
		'requirements_mode'            => 'custom',
		'require_valid_license'        => false,
		'check_medical_document'       => true,
		'check_parental_authorization' => false,
		'check_sport_passport'         => false,
		'requirements_note'            => 'Original du certificat à présenter.',
	)
);
$custom_text = implode( ' | ', $custom );
assert_true( false !== strpos( $custom_text, 'médical' ), 'Custom medical reminder must be displayed.' );
assert_true( false === strpos( $custom_text, 'passeport sportif' ), 'Custom mode must allow passport reminder to be disabled.' );
assert_true( false === strpos( $custom_text, 'mineur' ), 'Custom mode must allow parental reminder to be disabled.' );
assert_true( false !== strpos( $custom_text, 'Original du certificat' ), 'Organizer note must be displayed.' );

echo "competition-requirements-test: OK\n";