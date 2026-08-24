<?php

define( 'ABSPATH', __DIR__ );

function __( $text, $domain = null ) { return $text; }
function sanitize_key( $key ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', str_replace( ' ', '_', (string) $key ) ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_textarea_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_title( $value ) { return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $value ) ), '-' ); }
function absint( $value ) { return abs( (int) $value ); }

require_once __DIR__ . '/../includes/competitions/Services/EventFormatRegistry.php';
require_once __DIR__ . '/../includes/competitions/Services/EventProgramRegistry.php';

use UFSC\Competitions\Services\EventProgramRegistry;

function event_program_assert_same( $expected, $actual, $label ) {
	if ( $expected !== $actual ) {
		fwrite( STDERR, sprintf( "FAIL %s: expected %s, got %s\n", $label, var_export( $expected, true ), var_export( $actual, true ) ) );
		exit( 1 );
	}
}

$blocks = EventProgramRegistry::sanitize_blocks(
	array(
		array(
			'label' => 'Carte principale',
			'mode'  => 'direct_fights',
		),
		array(
			'label'              => 'Ceinture -75 kg',
			'mode'               => 'tournament',
			'weight_class'       => '-75 kg',
			'participant_target' => 6,
			'trophy'             => 'Ceinture du gala',
		),
	),
	'gala'
);

event_program_assert_same( 2, count( $blocks ), 'gala has two program blocks' );
event_program_assert_same( 'direct_fights', $blocks[0]['mode'], 'first gala block direct card' );
event_program_assert_same( 'tournament', $blocks[1]['mode'], 'second gala block tournament' );
event_program_assert_same( '-75 kg', $blocks[1]['weight_class'], 'belt block weight' );
event_program_assert_same( 6, $blocks[1]['participant_target'], 'belt block six athletes' );

$summary = EventProgramRegistry::get_summary( array( 'blocks' => $blocks ) );
event_program_assert_same( true, $summary['is_hybrid'], 'gala program detected as hybrid' );
event_program_assert_same( 1, $summary['tournament_blocks'], 'one tournament block' );

$entries = array(
	(object) array( 'id' => 1, 'discipline' => 'kickboxing', 'weight_class' => '-75 kg' ),
	(object) array( 'id' => 2, 'discipline' => 'kickboxing', 'weight_class' => '-71 kg' ),
	(object) array( 'id' => 3, 'discipline' => 'kickboxing', 'weight_class' => '-75 kg' ),
);
$filtered = EventProgramRegistry::filter_entries_for_block( $entries, $blocks[1] );
event_program_assert_same( 2, count( $filtered ), 'program block filters matching weight entries' );
event_program_assert_same( 1, (int) $filtered[0]->id, 'first matching fighter retained' );
event_program_assert_same( 3, (int) $filtered[1]->id, 'second matching fighter retained' );

echo "Event program registry tests passed.\n";
