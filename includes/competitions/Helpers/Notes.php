<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'ufsc_normalize_club_note_format' ) ) {
	function ufsc_normalize_club_note_format( $format ): string {
		$format = sanitize_key( (string) $format );
		if ( ! in_array( $format, array( 'auto', 'html', 'plain' ), true ) ) {
			return 'auto';
		}
		return $format;
	}
}

if ( ! function_exists( 'ufsc_allowed_note_html' ) ) {
	function ufsc_allowed_note_html() {
		return array(
			'div'     => array( 'class' => true ),
			'section' => array( 'class' => true ),
			'p'       => array( 'class' => true ),
			'br'      => array(),
			'strong'  => array(),
			'em'      => array(),
			'ul'      => array( 'class' => true ),
			'ol'      => array( 'class' => true ),
			'li'      => array( 'class' => true ),
			'h2'      => array( 'class' => true ),
			'h3'      => array( 'class' => true ),
			'h4'      => array( 'class' => true ),
			'span'    => array( 'class' => true ),
			'a'       => array(
				'href'   => true,
				'target' => true,
				'rel'    => true,
				'class'  => true,
			),
		);
	}
}

if ( ! function_exists( 'ufsc_sanitize_club_note_for_storage' ) ) {
	function ufsc_sanitize_club_note_for_storage( $raw_note, $format = 'auto' ) {
		$format = ufsc_normalize_club_note_format( $format );
		$note   = is_scalar( $raw_note ) ? (string) $raw_note : '';

		if ( 'html' === $format ) {
			if ( function_exists( 'ufsc_allowed_note_html' ) ) {
				return wp_kses( $note, ufsc_allowed_note_html() );
			}
			return wp_kses_post( $note );
		}

		return sanitize_textarea_field( $note );
	}
}
