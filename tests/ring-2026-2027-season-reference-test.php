<?php
/** Source: Kickboxing 2026/2027 Ring weights (attached official age/weight table). */
define( 'ABSPATH', __DIR__ );
function __( $text, $domain = null ) { return $text; }
function sanitize_key( $key ) { return strtolower( preg_replace( '/[^a-z0-9_\\-]/', '', str_replace( ' ', '_', (string) $key ) ) ); }
function sanitize_text_field( $text ) { return trim( strip_tags( (string) $text ) ); }
function apply_filters( $name, $value ) { return $value; }
function wp_timezone() { return new DateTimeZone( 'UTC' ); }
require_once dirname(__DIR__) . '/includes/competitions/Services/WeightCategoryResolver.php';
$cls = 'UFSC\\Competitions\\Services\\WeightCategoryResolver';
$ctx = array( 'discipline' => 'ring', 'season_end_year' => 2027, 'age_reference' => '12-31' );
$tests = array(
 array('2011-02-01','f',39.9,'-40','cadette second year'),
 array('2011-02-01','m',44.9,'-45','cadet second year'),
 array('2009-02-01','f',65.1,'+65','junior female open'),
 array('2009-02-01','m',81.1,'+81','junior male open'),
 array('2008-02-01','f',47.9,'-48','senior female'),
 array('2008-02-01','m',63.3,'-63.5','senior male'),
 array('1986-02-01','m',92,'+91','senior male open'),
 array('2012-02-01','f',39.9,'Hors catégorie','underage excluded'),
 array('1985-02-01','f',50,'Hors catégorie','veteran excluded'),
);
$failed=false;
foreach($tests as $t) {
 $got=$cls::resolve($t[0],$t[1],$t[2],$ctx);
 $ok=$got===$t[3];
 echo ($ok?'PASS ':'FAIL ').$t[4].' expected='.$t[3].' actual='.$got.PHP_EOL;
 $failed=$failed||!$ok;
}
$classes=$cls::get_weight_classes('2009-02-01','m',$ctx);
$ok=in_array('-63.5',$classes,true)&&in_array('+81',$classes,true)&&!in_array('-40',$classes,true);
echo($ok?'PASS ':'FAIL ').'male junior ring class list'.PHP_EOL;
exit($failed||!$ok?1:0);
