<?php

define( 'ABSPATH', __DIR__ );

function __( $text, $domain = null ) { return $text; }
function sanitize_key( $key ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', str_replace( ' ', '_', (string) $key ) ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function absint( $value ) { return abs( (int) $value ); }

require_once __DIR__ . '/../includes/competitions/Services/RegistrationScopePresenter.php';

use UFSC\Competitions\Services\RegistrationScopePresenter;

function registration_scope_assert_same( $expected, $actual, $label ) {
	if ( $expected !== $actual ) {
		fwrite( STDERR, sprintf( "FAIL %s: expected %s, got %s\n", $label, var_export( $expected, true ), var_export( $actual, true ) ) );
		exit( 1 );
	}
}

$region_scope = RegistrationScopePresenter::describe(
	array(
		'access_mode'            => 'regions',
		'allowed_regions'        => array( 'Auvergne-Rhône-Alpes', 'Occitanie' ),
		'require_logged_in_club' => true,
		'require_affiliated'     => true,
		'require_valid_license'  => true,
	)
);
registration_scope_assert_same( true, $region_scope['is_region_scoped'], 'region scope detected' );
registration_scope_assert_same( 2, count( $region_scope['regions'] ), 'two regions presented' );
registration_scope_assert_same( 3, count( $region_scope['requirements'] ), 'three registration requirements' );
registration_scope_assert_same( 'info', $region_scope['tone'], 'configured region scope is informational' );

$invalid_scope = RegistrationScopePresenter::describe(
	array(
		'access_mode'     => 'region_discipline',
		'allowed_regions' => array(),
	)
);
registration_scope_assert_same( 'warning', $invalid_scope['tone'], 'incomplete region discipline mode warns admin' );

echo "Registration scope presenter tests passed.\n";
