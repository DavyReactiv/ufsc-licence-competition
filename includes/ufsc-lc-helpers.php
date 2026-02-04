<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function ufsc_lc_get_nom_affiche( $row ) {
	$nom_affiche = '';
	$nom         = '';
	$nom_licence = '';

	if ( is_array( $row ) ) {
		$nom_affiche = $row['nom_affiche'] ?? '';
		$nom         = $row['nom'] ?? '';
		$nom_licence = $row['nom_licence'] ?? '';
	} elseif ( is_object( $row ) ) {
		$nom_affiche = $row->nom_affiche ?? '';
		$nom         = $row->nom ?? '';
		$nom_licence = $row->nom_licence ?? '';
	}

	$nom_affiche = trim( (string) $nom_affiche );
	if ( '' !== $nom_affiche ) {
		return $nom_affiche;
	}

	$nom = trim( (string) $nom );
	if ( '' !== $nom ) {
		return $nom;
	}

	$nom_licence = trim( (string) $nom_licence );
	if ( '' !== $nom_licence ) {
		return $nom_licence;
	}

	return '';
}

function ufsc_lc_format_birthdate( $raw ) {
	$raw = trim( (string) $raw );
	if ( '' === $raw ) {
		return '';
	}

	$timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
	$formats  = array( 'Y-m-d', 'd/m/Y' );
	foreach ( $formats as $format ) {
		$parsed = DateTimeImmutable::createFromFormat( '!' . $format, $raw, $timezone );
		if ( $parsed && $parsed->format( $format ) === $raw ) {
			return $parsed->format( 'd/m/Y' );
		}
	}

	return '';
}

function ufsc_lc_compute_category_from_birthdate( $birthdate, $season_end_year ) {
	if ( ! class_exists( 'UFSC_LC_Categories' ) ) {
		return '';
	}

	$birthdate = trim( (string) $birthdate );
	if ( '' === $birthdate ) {
		return '';
	}

	$season_end_year = UFSC_LC_Categories::sanitize_season_end_year( $season_end_year );
	if ( null === $season_end_year ) {
		return '';
	}

	$computed = UFSC_LC_Categories::category_from_birthdate( $birthdate, $season_end_year );
	return isset( $computed['category'] ) ? (string) $computed['category'] : '';
}

function ufsc_lc_normalize_search( $value ) {
	$value = remove_accents( (string) $value );
	$value = preg_replace( '/\s+/', ' ', $value );
	$value = trim( $value );

	if ( function_exists( 'mb_strtolower' ) ) {
		$value = mb_strtolower( $value );
	} else {
		$value = strtolower( $value );
	}

	return $value;
}

function ufsc_lc_table_exists( $table_name ) {
	global $wpdb;

	if ( '' === $table_name ) {
		return false;
	}

	$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );
	return $exists === $table_name;
}

function ufsc_get_current_season_end_year() {
	$timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
	$now      = new DateTimeImmutable( 'now', $timezone );
	$year     = (int) $now->format( 'Y' );
	$month    = (int) $now->format( 'n' );
	$day      = (int) $now->format( 'j' );

	$is_new_season = $month > 9 || ( 9 === $month && $day >= 1 );

	return $is_new_season ? $year + 1 : $year;
}

function ufsc_get_current_season_label() {
	$end_year = ufsc_get_current_season_end_year();
	$start_year = $end_year - 1;

	return sprintf( '%d-%d', $start_year, $end_year );
}

function ufsc_licence_get_pdf_attachment_id( $licence_id ) {
	$licence_id = absint( $licence_id );
	if ( ! $licence_id ) {
		return null;
	}

	static $cache = array();
	if ( array_key_exists( $licence_id, $cache ) ) {
		return $cache[ $licence_id ];
	}

	global $wpdb;
	$documents_table = $wpdb->prefix . 'ufsc_licence_documents';
	$meta_table      = $wpdb->prefix . 'ufsc_licence_documents_meta';
	$source          = 'UFSC';
	$attachment_id   = null;

	if ( ufsc_lc_table_exists( $documents_table ) ) {
		$attachment_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT attachment_id FROM {$documents_table} WHERE licence_id = %d AND source = %s",
				$licence_id,
				$source
			)
		);
		$attachment_id = $attachment_id ? absint( $attachment_id ) : null;
	}

	if ( ! $attachment_id && ufsc_lc_table_exists( $meta_table ) ) {
		$preferred_key = 'ufsc_licence_pdf_attachment_id';
		$legacy_key    = 'pdf_attachment_id';

		$attachment_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT meta_value FROM {$meta_table} WHERE licence_id = %d AND source = %s AND meta_key IN (%s, %s) ORDER BY FIELD(meta_key, %s, %s) LIMIT 1",
				$licence_id,
				$source,
				$preferred_key,
				$legacy_key,
				$preferred_key,
				$legacy_key
			)
		);
		$attachment_id = $attachment_id ? absint( $attachment_id ) : null;

		if ( $attachment_id && $preferred_key !== $legacy_key ) {
			$existing = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT meta_value FROM {$meta_table} WHERE licence_id = %d AND source = %s AND meta_key = %s",
					$licence_id,
					$source,
					$preferred_key
				)
			);
			if ( empty( $existing ) ) {
				$wpdb->query(
					$wpdb->prepare(
						"INSERT INTO {$meta_table} (licence_id, source, meta_key, meta_value, updated_at)
						VALUES (%d, %s, %s, %s, %s)
						ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value), updated_at = VALUES(updated_at)",
						$licence_id,
						$source,
						$preferred_key,
						(string) $attachment_id,
						current_time( 'mysql' )
					)
				);
			}
		}
	}

	$cache[ $licence_id ] = $attachment_id ? (int) $attachment_id : null;
	return $cache[ $licence_id ];
}

function ufsc_licence_get_pdf_url( $licence_id ) {
	$attachment_id = ufsc_licence_get_pdf_attachment_id( $licence_id );
	if ( ! $attachment_id ) {
		return null;
	}

	$url = wp_get_attachment_url( $attachment_id );
	return $url ? $url : null;
}

function ufsc_licence_has_pdf( $licence_id ) {
	return null !== ufsc_licence_get_pdf_attachment_id( $licence_id );
}
