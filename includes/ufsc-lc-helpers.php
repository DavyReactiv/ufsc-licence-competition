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
