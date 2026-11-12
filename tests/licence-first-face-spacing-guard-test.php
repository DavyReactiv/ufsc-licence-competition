<?php
/**
 * Regression: first-face identity rows must not collide with the two licence
 * number panels in the Dompdf portrait A4 layout.
 */
$template = file_get_contents( dirname( __DIR__ ) . '/templates/licence-sportive-a6.html' );
$generator = file_get_contents( dirname( __DIR__ ) . '/includes/class-ufsc-lc-licence-pdf-generator.php' );
$style = static function ( $selector ) use ( $template ) {
    $pattern = '/' . preg_quote( $selector, '/' ) . '\\s*\\{([^}]+)\\}/';
    if ( ! preg_match( $pattern, $template, $matches ) ) { return null; }
    preg_match_all( '/([a-z-]+)\\s*:\\s*([^;]+);/', $matches[1], $rules, PREG_SET_ORDER );
    $out = array();
    foreach ( $rules as $rule ) { $out[trim( $rule[1] )] = trim( $rule[2] ); }
    return $out;
};
$fail = static function ( $ok, $message ) {
    if ( ! $ok ) { fwrite( STDERR, "FAIL: {$message}\\n" ); exit( 1 ); }
};
$address = $style( '.address' );
$category = $style( '.category' );
$region = $style( '.region' );
$number = $style( '.licence-number' );
$footer = $style( '.face-footer' );
$fail( is_array( $address ) && is_array( $category ) && is_array( $region )
    && is_array( $number ) && is_array( $footer ), 'All first-face elements remain present' );
$mm = static function ( $value ) { return (float) str_replace( 'mm', '', (string) $value ); };
$address_end = $mm( $address['top'] ) + $mm( $address['min-height'] );
$category_start = $mm( $category['top'] );
$region_start = $mm( $region['top'] );
$number_start = $mm( $number['top'] );
$number_end = $number_start + $mm( $number['height'] );
$fail( $category_start >= $address_end, 'Category does not overlap the address panel' );
$fail( $region_start >= $address_end, 'Region does not overlap the address panel' );
$fail( $number_start - $category_start >= 12, 'Category has room for label and value before number box' );
$fail( $number_start - $region_start >= 12, 'Region has room for label and value before number box' );
$fail( $number_end <= 126.2, 'Number boxes stay above bottom footer' );
$fail( false !== strpos( $template, '{{numero_licence_ufsc}}' )
    && false !== strpos( $template, '{{numero_licence_ffst}}' ), 'Both licence number bindings preserved' );
$fail( false !== strpos( $generator, 'ufsc-document-v18-section-spacing-a4' ), 'Updated design version will be regenerated' );
echo "Licence first-face vertical spacing guard OK\\n";
