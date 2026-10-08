<?php
/**
 * Guard archived competition compatibility and club PDF action layout.
 */

$root       = dirname( __DIR__ );
$repository = file_get_contents( $root . '/includes/competitions/Repositories/CompetitionRepository.php' );
$licences   = file_get_contents( $root . '/includes/class-ufsc-club-licences-shortcode.php' );

$errors = array();

if ( false === strpos( $repository, "status IN ('archived','closed')" ) ) {
	$errors[] = 'archived view must keep legacy closed competitions visible';
}

if ( false === strpos( $repository, '\'archived\' === $view && \'archived\' === $st' ) ) {
	$errors[] = 'archived status filter must not hide legacy closed archives';
}

if ( false === strpos( $licences, 'grid-template-columns:repeat(2,minmax(96px,1fr))' ) ) {
	$errors[] = 'club PDF actions must use the balanced two-column layout';
}

if ( false === strpos( $licences, 'max-width:236px' ) ) {
	$errors[] = 'club PDF actions need a bounded width';
}

if ( $errors ) {
	fwrite( STDERR, "Archive/front layout guard failed:\n - " . implode( "\n - ", $errors ) . "\n" );
	exit( 1 );
}

echo "Archive/front layout guard: OK\n";
