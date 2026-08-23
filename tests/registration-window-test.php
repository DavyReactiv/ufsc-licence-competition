<?php

define( 'ABSPATH', __DIR__ . '/' );

function __( $text, $domain = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	return $text;
}
function sanitize_key( $key ) {
	$key = strtolower( (string) $key );
	return (string) preg_replace( '/[^a-z0-9_\-]/', '', $key );
}
function absint( $value ) {
	return abs( (int) $value );
}
function wp_timezone() {
	return new DateTimeZone( 'UTC' );
}
function wp_date( $format, $timestamp, $timezone = null ) {
	$timezone = $timezone instanceof DateTimeZone ? $timezone : new DateTimeZone( 'UTC' );
	$date = new DateTimeImmutable( '@' . $timestamp );
	return $date->setTimezone( $timezone )->format( $format );
}
function get_option( $key ) {
	return 'date_format' === $key ? 'd/m/Y' : 'H:i';
}
function add_filter() {
	return true;
}

require_once dirname( __DIR__ ) . '/includes/competitions/Services/RegistrationWindowService.php';

use UFSC\Competitions\Services\RegistrationWindowService;

function assert_true( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

$future_close = gmdate( 'Y-m-d H:i:s', time() + 7200 );
$future_open  = gmdate( 'Y-m-d H:i:s', time() + 3600 );
$past_close   = gmdate( 'Y-m-d H:i:s', time() - 3600 );
$later_close  = gmdate( 'Y-m-d H:i:s', time() + 14400 );

$open = (object) array(
	'id'                           => 0,
	'status'                       => 'open',
	'registration_open_datetime'  => '',
	'registration_close_datetime' => $future_close,
	'registration_deadline'        => '',
);
$result = RegistrationWindowService::evaluate( $open );
assert_true( true === $result['is_open'], 'Open competition with future close date must accept registrations.' );
assert_true( 'open' === $result['state'], 'Open window must report state=open.' );

$not_yet = clone $open;
$not_yet->registration_open_datetime = $future_open;
$result = RegistrationWindowService::evaluate( $not_yet );
assert_true( false === $result['is_open'], 'Future registration start must block early registrations.' );
assert_true( 'not_open_yet' === $result['state'], 'Future opening must report state=not_open_yet.' );

$closed = clone $open;
$closed->registration_close_datetime = $past_close;
$result = RegistrationWindowService::evaluate( $closed );
assert_true( false === $result['is_open'], 'Past forclusion must block writes.' );
assert_true( 'deadline_passed' === $result['state'], 'Past forclusion must report deadline_passed.' );

$earliest = clone $open;
$earliest->registration_close_datetime = $future_close;
$earliest->registration_deadline = $later_close;
$result = RegistrationWindowService::evaluate( $earliest );
assert_true( $future_close === $result['closes_at'], 'The earliest configured closing date must win.' );

$status_closed = clone $open;
$status_closed->status = 'closed';
$result = RegistrationWindowService::evaluate( $status_closed );
assert_true( false === $result['is_open'], 'Closed event status must block registration even without forclusion.' );
assert_true( 'competition_closed' === $result['state'], 'Closed event status must report competition_closed.' );

echo "registration-window-test: OK\n";
