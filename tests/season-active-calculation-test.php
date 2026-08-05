<?php
/**
 * Standalone checks for UFSC licence active season calculation.
 * Run with: php tests/season-active-calculation-test.php
 */

define( 'ABSPATH', __DIR__ . '/../' );

if ( ! function_exists( 'absint' ) ) {
	function absint( $maybeint ) {
		return abs( (int) $maybeint );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		$key = strtolower( (string) $key );
		return preg_replace( '/[^a-z0-9_\-]/', '', $key );
	}
}

$GLOBALS['ufsc_lc_test_now'] = new DateTimeImmutable( '2026-08-05 12:00:00', new DateTimeZone( 'Europe/Paris' ) );
$GLOBALS['ufsc_lc_test_settings'] = array();

function current_datetime() {
	return $GLOBALS['ufsc_lc_test_now'];
}

function apply_filters( $hook, $value ) {
	if ( 'ufsc_lc_active_season_datetime' === $hook ) {
		return $GLOBALS['ufsc_lc_test_now'];
	}
	return $value;
}

function ufsc_lc_get_settings() {
	return $GLOBALS['ufsc_lc_test_settings'];
}

function ufsc_get_current_season_end_year() {
	// Simulates a stale UFSC Gestion value using a September switchover.
	return 2026;
}

require_once __DIR__ . '/../includes/class-ufsc-lc-categories.php';
require_once __DIR__ . '/../includes/ufsc-lc-helpers.php';

function assert_same_season( $expected, $actual, $label ) {
	if ( (int) $expected !== (int) $actual ) {
		fwrite( STDERR, sprintf( "FAIL %s: expected %d, got %d\n", $label, $expected, $actual ) );
		exit( 1 );
	}
	echo sprintf( "PASS %s => %d\n", $label, $actual );
}

function set_test_context( $date, $settings ) {
	$GLOBALS['ufsc_lc_test_now'] = new DateTimeImmutable( $date, new DateTimeZone( 'Europe/Paris' ) );
	$GLOBALS['ufsc_lc_test_settings'] = $settings;
}

$split_august = array(
	'season_rule'        => 'split',
	'season_start_month' => 8,
);

foreach ( array(
	'2026-07-31 12:00:00' => 2026,
	'2026-08-01 00:00:00' => 2027,
	'2026-08-05 12:00:00' => 2027,
	'2027-01-01 12:00:00' => 2027,
	'2027-07-31 23:59:59' => 2027,
	'2027-08-01 00:00:00' => 2028,
) as $date => $expected ) {
	set_test_context( $date, $split_august );
	assert_same_season( $expected, ufsc_lc_get_active_season_end_year(), $date );
}

set_test_context( '2026-08-05 12:00:00', array( 'season_rule' => 'split' ) );
assert_same_season( 2027, ufsc_lc_get_active_season_end_year(), 'missing season_start_month defaults to August' );

set_test_context( '2026-08-05 12:00:00', array( 'season_rule' => 'split', 'season_start_month' => 8 ) );
assert_same_season( 2027, ufsc_lc_get_active_season_end_year(), 'explicit August' );

set_test_context( '2026-08-05 12:00:00', array( 'season_rule' => 'split', 'season_start_month' => 9 ) );
assert_same_season( 2026, ufsc_lc_get_active_season_end_year(), 'explicit September remains respected' );

set_test_context( '2026-08-05 12:00:00', array( 'season_rule' => 'split', 'season_start_month' => 99 ) );
assert_same_season( 2027, ufsc_lc_get_active_season_end_year(), 'invalid month falls back to August' );

set_test_context( '2026-08-05 12:00:00', $split_august );
assert_same_season( 2027, ufsc_lc_get_active_season_end_year(), 'stale UFSC Gestion value ignored' );

if ( '2026–2027' !== ufsc_lc_format_season_label( 2027 ) ) {
	fwrite( STDERR, "FAIL season label for 2027\n" );
	exit( 1 );
}
echo "PASS season label 2027 => 2026–2027\n";
