<?php

define( 'ABSPATH', __DIR__ );

function __( $text, $domain = null ) { return $text; }
function sanitize_key( $key ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', str_replace( ' ', '_', (string) $key ) ) ); }

require_once __DIR__ . '/../includes/competitions/Services/EventFormatRegistry.php';

use UFSC\Competitions\Services\EventFormatRegistry;

function event_format_assert_same( $expected, $actual, $label ) {
	if ( $expected !== $actual ) {
		fwrite( STDERR, sprintf( "FAIL %s: expected %s, got %s\n", $label, var_export( $expected, true ), var_export( $actual, true ) ) );
		exit( 1 );
	}
}

$choices = EventFormatRegistry::extend_type_choices( array( 'open' => 'Open', 'gala' => 'Gala' ) );
event_format_assert_same( true, isset( $choices['competition'], $choices['tournoi'], $choices['coupe'], $choices['open'], $choices['gala'] ), 'extended event type choices' );
event_format_assert_same( EventFormatRegistry::STRATEGY_TOURNAMENT, EventFormatRegistry::get_strategy( 'tournoi' ), 'tournoi strategy' );
event_format_assert_same( EventFormatRegistry::STRATEGY_CARD, EventFormatRegistry::get_strategy( 'gala' ), 'gala strategy' );
event_format_assert_same( EventFormatRegistry::STRATEGY_MIXED, EventFormatRegistry::get_strategy( 'interclub' ), 'interclub strategy' );
event_format_assert_same( false, EventFormatRegistry::supports_automatic_fights( 'stage' ), 'stage automatic fights disabled' );
event_format_assert_same( true, EventFormatRegistry::uses_direct_fight_card( 'gala' ), 'gala direct card' );
event_format_assert_same( true, EventFormatRegistry::supports_brackets( 'open' ), 'open brackets' );
event_format_assert_same( true, EventFormatRegistry::supports_pools( 'championnat_national' ), 'championship pools' );

echo "Event format registry tests passed.\n";
