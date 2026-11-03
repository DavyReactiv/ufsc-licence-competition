<?php
/**
 * FFST matching: hyphen/space equivalence without fuzzy identity matching.
 * Standalone: php tests/ffst-hyphenated-names-reanalysis-test.php
 */
define( 'ABSPATH', __DIR__ . '/' );
function __( $v, $domain = '' ) { return $v; }
function absint( $v ) { return abs( (int) $v ); }
function remove_accents( $v ) { return strtr( $v, array( 'é'=>'e', 'è'=>'e', 'É'=>'E', 'È'=>'E' ) ); }
class FFSTMatchingFakeDB {
    public $prefix = 'wp_';
    public $candidates = array();
    public function prepare( $q, ...$args ) { return array( $q, $args ); }
    public function get_var( $query ) { return 'present'; } // column_exists -> true
    public function get_results( $query ) {
        $sql = is_array( $query ) ? $query[0] : $query;
        if ( false !== strpos( $sql, 'UPPER(numero_licence_ffst)' ) ) { return array(); }
        if ( false !== strpos( $sql, 'l.date_naissance=' ) ) { return $this->candidates; }
        return array();
    }
}
$wpdb = new FFSTMatchingFakeDB();
require dirname( __DIR__ ) . '/includes/import/class-ufsc-lc-ffst-import-service.php';
$service = new UFSC_LC_FFST_Import_Service();
$norm = new ReflectionMethod( $service, 'norm' );
$match = new ReflectionMethod( $service, 'resolve_match' );
$norm->setAccessible( true );
$match->setAccessible( true );
function check( $test, $label ) {
    if ( ! $test ) { fwrite( STDERR, "FAIL: {$label}\n" ); exit( 1 ); }
}
check( $norm->invoke( $service, 'Jean-Christophe' ) === $norm->invoke( $service, 'Jean christophe' ), 'hyphenated first name' );
check( $norm->invoke( $service, 'Marie-Claire' ) === $norm->invoke( $service, 'Marie claire' ), 'female hyphenated first name' );
check( $norm->invoke( $service, 'Jean-Pierre' ) === $norm->invoke( $service, 'JEAN–PIERRE' ), 'Unicode dash' );
check( $norm->invoke( $service, 'Marie-Claire' ) !== $norm->invoke( $service, 'Marie-Caroline' ), 'different first name remains distinct' );
$wpdb->candidates = array(
    (object) array( 'id'=>1426, 'last_name'=>'REFESSE', 'first_name'=>'Jean christophe', 'numero_licence_ffst'=>'',
        'club_name'=>'ASSOCIATION OMNISPORT DE SAINT GILLES', 'saison'=>'2026-2027' )
);
$row = array( 'ffst_number'=>'M212003','last_name'=>'REFESSE','first_name'=>'Jean-Christophe','birthdate'=>'1969-01-19','club_name'=>'ASSOCIATION OMNISPORT DE SAINT GILLES' );
$result = $match->invoke( $service, $row, 2027 );
check( UFSC_LC_FFST_Import_Service::MATCHED === $result['status'] && 1426 == $result['licence_id'], 'Jean-Christophe matched to Jean christophe in correct season' );
$row['first_name'] = 'Jean-Baptiste';
$result = $match->invoke( $service, $row, 2027 );
check( UFSC_LC_FFST_Import_Service::NOT_FOUND === $result['status'], 'different first name not matched' );
$row['first_name'] = 'Jean-Christophe';
$wpdb->candidates[0]->saison = '2025-2026';
$result = $match->invoke( $service, $row, 2027 );
check( UFSC_LC_FFST_Import_Service::NOT_FOUND === $result['status'], 'wrong season not matched even when identity is unique' );
$wpdb->candidates[0]->saison = '2026-2027';
$wpdb->candidates[] = (object) array( 'id'=>1499,'last_name'=>'REFESSE','first_name'=>'Jean christophe',
    'numero_licence_ffst'=>'','club_name'=>'ASSOCIATION OMNISPORT DE SAINT GILLES','saison'=>'2026-2027' );
$result = $match->invoke( $service, $row, 2027 );
check( UFSC_LC_FFST_Import_Service::AMBIGUOUS === $result['status'], 'ambiguous duplicates not auto-matched' );
echo "FFST matching normalization and identity guards: OK\n";
