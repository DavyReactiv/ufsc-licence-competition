<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'ufsc_lc_get_nom_affiche' ) ) {
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
}

if ( ! function_exists( 'ufsc_lc_format_birthdate' ) ) {
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
}

if ( ! function_exists( 'ufsc_lc_compute_category_from_birthdate' ) ) {
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
}

if ( ! function_exists( 'ufsc_lc_normalize_search' ) ) {
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
}

if ( ! function_exists( 'ufsc_normalize_token' ) ) {
function ufsc_normalize_token( $value ): string {
	$value = trim( (string) $value );
	if ( '' === $value ) {
		return '';
	}

	$value = remove_accents( $value );
	$value = preg_replace( '/[-_\/]+/', ' ', $value );
	$value = preg_replace( '/\s+/', ' ', $value );
	$value = trim( $value );

	if ( function_exists( 'mb_strtoupper' ) ) {
		$value = mb_strtoupper( $value );
	} else {
		$value = strtoupper( $value );
	}

	return $value;
}
}

if ( ! function_exists( 'ufsc_normalize_region' ) ) {
function ufsc_normalize_region( $value ): string {
	$normalized = ufsc_normalize_token( $value );
	if ( '' === $normalized ) {
		return '';
	}

	$aliases = array(
		'PACA CORSE' => 'PACA CORSE',
		'AUVERGNE RHONE ALPES' => 'AUVERGNE-RHONE-ALPES',
	);

	$aliases = apply_filters( 'ufsc_competitions_region_aliases', $aliases );

	return isset( $aliases[ $normalized ] ) ? $aliases[ $normalized ] : $normalized;
}
}

if ( ! function_exists( 'ufsc_normalize_region_key' ) ) {
function ufsc_normalize_region_key( $label ): string {
	$value = trim( (string) $label );
	if ( '' === $value ) {
		return '';
	}

	$value = remove_accents( $value );
	$value = preg_replace( "/['’`]+/", '', $value );
	$value = preg_replace( '/[-_]+/', ' ', $value );
	$value = preg_replace( '/\s+/', ' ', $value );
	$value = trim( $value );

	if ( function_exists( 'mb_strtolower' ) ) {
		$value = mb_strtolower( $value );
	} else {
		$value = strtolower( $value );
	}

	$value = preg_replace( '/\s+ufsc$/', '', $value );
	$value = trim( $value );

	return $value;
}
}

if ( ! function_exists( 'ufsc_normalize_discipline' ) ) {
function ufsc_normalize_discipline( $value ): string {
	return ufsc_normalize_token( $value );
}
}

if ( ! function_exists( 'ufsc_extract_club_disciplines' ) ) {
function ufsc_extract_club_disciplines( $club ): array {
	if ( ! is_object( $club ) ) {
		return array();
	}

	$raw = '';
	if ( isset( $club->discipline ) ) {
		$raw = (string) $club->discipline;
	} elseif ( isset( $club->disciplines ) ) {
		$raw = (string) $club->disciplines;
	}

	$raw = trim( $raw );
	if ( '' === $raw ) {
		return array();
	}

	$parts = array_map( 'trim', preg_split( '/[;,]/', $raw ) );
	$parts = array_filter( $parts );

	$disciplines = array();
	foreach ( $parts as $part ) {
		$normalized = ufsc_normalize_discipline( $part );
		if ( '' !== $normalized ) {
			$disciplines[] = $normalized;
		}
	}

	return array_values( array_unique( $disciplines ) );
}
}

if ( ! function_exists( 'ufsc_get_competitions_list_url' ) ) {
function ufsc_get_competitions_list_url(): string {
	$url = (string) apply_filters( 'ufsc_competitions_front_list_url', '' );
	if ( '' !== $url ) {
		return $url;
	}

	$referer = wp_get_referer();
	if ( $referer ) {
		return $referer;
	}

	return home_url( '/' );
}
}

if ( ! function_exists( 'ufsc_render_access_denied_notice' ) ) {
function ufsc_render_access_denied_notice( \UFSC\Competitions\Access\AccessResult $result, string $list_url = '' ): string {
	$messages = array(
		'not_logged_in' => __( 'Connexion requise pour accéder à cette compétition.', 'ufsc-licence-competition' ),
		'not_club' => __( 'Aucun club associé à votre compte.', 'ufsc-licence-competition' ),
		'club_not_linked' => __( 'Impossible d’identifier votre club.', 'ufsc-licence-competition' ),
		'club_not_resolved' => __( 'Impossible d’identifier votre club.', 'ufsc-licence-competition' ),
		'not_affiliated' => __( 'Accès réservé aux clubs affiliés UFSC.', 'ufsc-licence-competition' ),
		'club_region_missing' => __( 'Votre club n’a pas de région renseignée.', 'ufsc-licence-competition' ),
		'club_not_allowed' => __( 'Votre club n’est pas autorisé pour cette compétition.', 'ufsc-licence-competition' ),
		'region_mismatch' => __( 'Région non autorisée pour cette compétition.', 'ufsc-licence-competition' ),
		'discipline_mismatch' => __( 'Discipline non autorisée pour cette compétition.', 'ufsc-licence-competition' ),
		'registration_closed' => __( 'Les inscriptions sont fermées pour cette compétition.', 'ufsc-licence-competition' ),
		'invalid_license' => __( 'Une licence valide est requise pour s’inscrire.', 'ufsc-licence-competition' ),
		'not_allowed_by_rule' => __( 'Conditions d’accès non remplies.', 'ufsc-licence-competition' ),
	);

	$reason_code = $result->reason_code ? (string) $result->reason_code : '';
	$message = $messages[ $reason_code ] ?? __( 'Accès refusé.', 'ufsc-licence-competition' );

	$extra = '';
	if ( 'not_allowed_by_rule' === $reason_code ) {
		$extra = __( 'Contactez l’administration UFSC si vous pensez qu’il s’agit d’une erreur.', 'ufsc-licence-competition' );
	}

	$list_url = $list_url ? $list_url : ufsc_get_competitions_list_url();

	$button = '';
	if ( $list_url ) {
		$button = sprintf(
			'<a class="button" href="%s">%s</a>',
			esc_url( $list_url ),
			esc_html__( 'Retour à la liste', 'ufsc-licence-competition' )
		);
	}

	$contact_button = '';
	if ( 'club_region_missing' === $reason_code ) {
		$contact_button = sprintf(
			'<a class="button" href="%s">%s</a>',
			esc_url( 'mailto:secretariat@ufsc-france.org' ),
			esc_html__( 'Contacter l’administration UFSC', 'ufsc-licence-competition' )
		);
	}

	$buttons = array();
	if ( $button ) {
		$buttons[] = $button;
	}
	if ( $contact_button ) {
		$buttons[] = $contact_button;
	}

	return sprintf(
		'<div class="notice notice-warning ufsc-access-denied"><h3>%s</h3><p>%s</p>%s%s</div>',
		esc_html__( 'Accès réservé', 'ufsc-licence-competition' ),
		esc_html( $message ),
		$extra ? '<p>' . esc_html( $extra ) . '</p>' : '',
		$buttons ? '<p>' . implode( ' ', $buttons ) . '</p>' : ''
	);
}
}

if ( ! function_exists( 'ufsc_lc_table_exists' ) ) {
function ufsc_lc_table_exists( $table_name ) {
	global $wpdb;

	if ( '' === $table_name ) {
		return false;
	}

	$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );
	return $exists === $table_name;
}
}

if ( ! function_exists( 'ufsc_get_current_season_end_year' ) ) {
function ufsc_get_current_season_end_year() {
	$timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
	$now      = new DateTimeImmutable( 'now', $timezone );
	$year     = (int) $now->format( 'Y' );
	$month    = (int) $now->format( 'n' );
	$day      = (int) $now->format( 'j' );

	$is_new_season = $month > 9 || ( 9 === $month && $day >= 1 );

	return $is_new_season ? $year + 1 : $year;
}
}

if ( ! function_exists( 'ufsc_get_current_season_label' ) ) {
function ufsc_get_current_season_label() {
	$end_year = ufsc_get_current_season_end_year();
	$start_year = $end_year - 1;

	return sprintf( '%d-%d', $start_year, $end_year );
}
}

if ( ! function_exists( 'ufsc_licence_get_pdf_attachment_id' ) ) {
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
}

if ( ! function_exists( 'ufsc_licence_get_pdf_url' ) ) {
function ufsc_licence_get_pdf_url( $licence_id ) {
	$attachment_id = ufsc_licence_get_pdf_attachment_id( $licence_id );
	if ( ! $attachment_id ) {
		return null;
	}

	$url = wp_get_attachment_url( $attachment_id );
	return $url ? $url : null;
}
}

if ( ! function_exists( 'ufsc_licence_has_pdf' ) ) {
function ufsc_licence_has_pdf( $licence_id ) {
	return null !== ufsc_licence_get_pdf_attachment_id( $licence_id );
}
}
