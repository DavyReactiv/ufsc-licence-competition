<?php
/**
 * UFSC LC - Helpers (canonical)
 * - Normalisation (nom/birthdate/search/region/discipline)
 * - Cache versioning + cache key builder + scope key
 * - Query count logger (debug)
 * - Access denied notice helpers
 * - Season helpers
 * - PDF helpers (attachment id/url/has_pdf)
 * - Backward compatibility wrappers (ufsc_* -> ufsc_lc_*)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'ufsc_lc_get_nom_affiche' ) ) {
	function ufsc_lc_get_nom_affiche( $row ) {
		$nom_affiche = '';
		$nom         = '';
		$nom_licence  = '';

		if ( is_array( $row ) ) {
			$nom_affiche = $row['nom_affiche'] ?? '';
			$nom         = $row['nom'] ?? '';
			$nom_licence  = $row['nom_licence'] ?? '';
		} elseif ( is_object( $row ) ) {
			$nom_affiche = $row->nom_affiche ?? '';
			$nom         = $row->nom ?? '';
			$nom_licence  = $row->nom_licence ?? '';
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

if ( ! function_exists( 'ufsc_lc_get_asptt_number' ) ) {
	function ufsc_lc_get_asptt_number( $row ) {
		$candidates = array();

		if ( is_array( $row ) ) {
			$candidates = array(
				$row['asptt_number'] ?? '',
				$row['numero_licence_asptt'] ?? '',
				$row['n_asptt'] ?? '',
				$row['source_licence_number'] ?? '',
			);
		} elseif ( is_object( $row ) ) {
			$candidates = array(
				$row->asptt_number ?? '',
				$row->numero_licence_asptt ?? '',
				$row->n_asptt ?? '',
				$row->source_licence_number ?? '',
			);
		}

		foreach ( $candidates as $candidate ) {
			$candidate = trim( (string) $candidate );
			if ( '' !== $candidate ) {
				return $candidate;
			}
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
		$value = trim( (string) $value );

		if ( function_exists( 'mb_strtolower' ) ) {
			$value = mb_strtolower( $value );
		} else {
			$value = strtolower( $value );
		}

		return $value;
	}
}

/**
 * Canonical LC helpers (avoid collisions with UFSC Gestion / Competitions).
 */
if ( ! function_exists( 'ufsc_lc_normalize_token' ) ) {
	function ufsc_lc_normalize_token( $value ): string {
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

if ( ! function_exists( 'ufsc_lc_normalize_region' ) ) {
	function ufsc_lc_normalize_region( $value ): string {
		$normalized = ufsc_lc_normalize_token( $value );
		if ( '' === $normalized ) {
			return '';
		}

		$aliases = array(
			'PACA CORSE'           => 'PACA CORSE',
			'AUVERGNE RHONE ALPES' => 'AUVERGNE-RHONE-ALPES',
		);

		// Keep existing hook name for compatibility with competitions module.
		$aliases = apply_filters( 'ufsc_competitions_region_aliases', $aliases );

		return isset( $aliases[ $normalized ] ) ? $aliases[ $normalized ] : $normalized;
	}
}

if ( ! function_exists( 'ufsc_lc_normalize_region_key' ) ) {
	function ufsc_lc_normalize_region_key( $label ): string {
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

if ( ! function_exists( 'ufsc_lc_normalize_discipline' ) ) {
	function ufsc_lc_normalize_discipline( $value ): string {
		return ufsc_lc_normalize_token( $value );
	}
}

if ( ! function_exists( 'ufsc_lc_extract_club_disciplines' ) ) {
	function ufsc_lc_extract_club_disciplines( $club ): array {
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
			$normalized = ufsc_lc_normalize_discipline( $part );
			if ( '' !== $normalized ) {
				$disciplines[] = $normalized;
			}
		}

		return array_values( array_unique( $disciplines ) );
	}
}

if ( ! function_exists( 'ufsc_lc_get_cache_version' ) ) {
	function ufsc_lc_get_cache_version( string $bucket, int $id = 0 ): string {
		$key    = sprintf( 'ufsc_lc_cache_version_%s_%d', $bucket, $id );
		$cached = wp_cache_get( $key, 'ufsc_licence_competition' );
		if ( false !== $cached ) {
			return (string) $cached;
		}

		$version = get_option( $key, '' );
		if ( '' === $version ) {
			$version = (string) time();
			add_option( $key, $version, '', false );
		}

		wp_cache_set( $key, $version, 'ufsc_licence_competition' );

		return (string) $version;
	}
}

if ( ! function_exists( 'ufsc_lc_build_cache_key' ) ) {
	function ufsc_lc_build_cache_key( string $prefix, array $parts ): string {
		$normalize = static function ( $value ) use ( &$normalize ) {
			if ( is_array( $value ) ) {
				$normalized = array();
				foreach ( $value as $key => $item ) {
					$normalized[ (string) $key ] = $normalize( $item );
				}
				ksort( $normalized );
				return $normalized;
			}

			if ( is_bool( $value ) ) {
				return $value ? 1 : 0;
			}

			if ( is_scalar( $value ) || null === $value ) {
				return (string) $value;
			}

			return '';
		};

		$normalized = $normalize( $parts );
		$payload    = wp_json_encode( $normalized );

		return $prefix . '_' . md5( (string) $payload );
	}
}

if ( ! function_exists( 'ufsc_lc_get_scope_cache_key' ) ) {
	function ufsc_lc_get_scope_cache_key(): string {
		if ( function_exists( 'ufsc_lc_get_user_scope_region' ) ) {
			$scope = ufsc_lc_get_user_scope_region();
			if ( null !== $scope && '' !== $scope ) {
				return (string) $scope;
			}
		}

		return 'all';
	}
}

if ( ! function_exists( 'ufsc_lc_get_scope_label' ) ) {
	function ufsc_lc_get_scope_label( string $region ): string {
		$region = sanitize_key( $region );
		if ( '' === $region ) {
			return '';
		}

		// Special marker used by scope normalization to represent “no region assigned”.
		if ( '__no_region__' === $region ) {
			return __( 'Non assignée', 'ufsc-licence-competition' );
		}

		if ( function_exists( 'ufsc_get_region_label' ) ) {
			$label = ufsc_get_region_label( $region );
			if ( is_string( $label ) && '' !== trim( $label ) ) {
				return trim( $label );
			}
		}

		return strtoupper( $region );
	}
}

if ( ! function_exists( 'ufsc_lc_render_scope_badge' ) ) {
	function ufsc_lc_render_scope_badge(): void {
		if ( ! class_exists( 'UFSC_LC_Scope' ) || ! method_exists( 'UFSC_LC_Scope', 'get_user_scope_region' ) ) {
			return;
		}

		$scope = UFSC_LC_Scope::get_user_scope_region();
		if ( null === $scope || '' === $scope ) {
			return;
		}

		printf(
			'<div class="notice notice-info inline"><p><strong>%s</strong></p></div>',
			esc_html( sprintf( __( 'Scope : %s', 'ufsc-licence-competition' ), ufsc_lc_get_scope_label( $scope ) ) )
		);
	}
}

if ( ! function_exists( 'ufsc_lc_bump_cache_version' ) ) {
	function ufsc_lc_bump_cache_version( string $bucket, int $id = 0 ): void {
		$key     = sprintf( 'ufsc_lc_cache_version_%s_%d', $bucket, $id );
		$version = (string) time();
		update_option( $key, $version, false );
		wp_cache_set( $key, $version, 'ufsc_licence_competition' );
	}
}

if ( ! function_exists( 'ufsc_lc_log_query_count' ) ) {
	/**
	 * Log only when UFSC_LC_DEBUG_QUERIES is enabled AND thresholds exceeded.
	 * Prevents spam and keeps prod safe.
	 */
	function ufsc_lc_log_query_count( string $context, array $data = array(), int $threshold_queries = 200, float $threshold_time = 1.5 ): void {
		static $logged = array();

		if ( ! defined( 'UFSC_LC_DEBUG_QUERIES' ) || ! UFSC_LC_DEBUG_QUERIES ) {
			return;
		}

		if ( ! defined( 'WP_DEBUG_LOG' ) || ! WP_DEBUG_LOG ) {
			return;
		}

		$log_key_parts = array(
			'context' => $context,
			'screen'  => $data['screen'] ?? '',
			'club_id' => $data['club_id'] ?? '',
			'cache'   => $data['cache'] ?? '',
		);
		$log_key = ufsc_lc_build_cache_key( 'ufsc_lc_log', $log_key_parts );

		if ( isset( $logged[ $log_key ] ) ) {
			return;
		}

		global $wpdb;
		if ( ! isset( $wpdb->num_queries ) ) {
			return;
		}

		$num_queries = (int) $wpdb->num_queries;
		$elapsed     = function_exists( 'timer_stop' ) ? (float) timer_stop( 0 ) : 0.0;

		if ( $num_queries < $threshold_queries && $elapsed < $threshold_time ) {
			return;
		}

		$logged[ $log_key ] = true;

		error_log(
			sprintf(
				'UFSC LC query count high (%d, %.3fs) on %s. screen=%s club_id=%s cache=%s',
				$num_queries,
				$elapsed,
				$context,
				(string) ( $data['screen'] ?? '' ),
				(string) ( $data['club_id'] ?? '' ),
				(string) ( $data['cache'] ?? '' )
			)
		);
	}
}

if ( ! function_exists( 'ufsc_lc_get_competitions_list_url' ) ) {
	function ufsc_lc_get_competitions_list_url(): string {
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

if ( ! function_exists( 'ufsc_lc_render_access_denied_notice' ) ) {
	function ufsc_lc_render_access_denied_notice( \UFSC\Competitions\Access\AccessResult $result, string $list_url = '' ): string {
		$messages = array(
			'not_logged_in'        => __( 'Connexion requise pour accéder à cette compétition.', 'ufsc-licence-competition' ),
			'not_club'             => __( 'Aucun club associé à votre compte.', 'ufsc-licence-competition' ),
			'club_not_linked'      => __( 'Impossible d’identifier votre club.', 'ufsc-licence-competition' ),
			'club_not_resolved'    => __( 'Impossible d’identifier votre club.', 'ufsc-licence-competition' ),
			'not_affiliated'       => __( 'Accès réservé aux clubs affiliés UFSC.', 'ufsc-licence-competition' ),
			'club_region_missing'  => __( 'Votre club n’a pas de région renseignée.', 'ufsc-licence-competition' ),
			'club_not_allowed'     => __( 'Votre club n’est pas autorisé pour cette compétition.', 'ufsc-licence-competition' ),
			'region_mismatch'      => __( 'Région non autorisée pour cette compétition.', 'ufsc-licence-competition' ),
			'discipline_mismatch'  => __( 'Discipline non autorisée pour cette compétition.', 'ufsc-licence-competition' ),
			'registration_closed'  => __( 'Les inscriptions sont fermées pour cette compétition.', 'ufsc-licence-competition' ),
			'invalid_license'      => __( 'Une licence valide est requise pour s’inscrire.', 'ufsc-licence-competition' ),
			'not_allowed_by_rule'  => __( 'Conditions d’accès non remplies.', 'ufsc-licence-competition' ),
		);

		$reason_code = $result->reason_code ? (string) $result->reason_code : '';
		$message     = $messages[ $reason_code ] ?? __( 'Accès refusé.', 'ufsc-licence-competition' );

		$extra = '';
		if ( 'not_allowed_by_rule' === $reason_code ) {
			$extra = __( 'Contactez l’administration UFSC si vous pensez qu’il s’agit d’une erreur.', 'ufsc-licence-competition' );
		}

		$list_url = $list_url ? $list_url : ufsc_lc_get_competitions_list_url();

		$buttons = array();

		if ( $list_url ) {
			$buttons[] = sprintf(
				'<a class="button" href="%s">%s</a>',
				esc_url( $list_url ),
				esc_html__( 'Retour à la liste', 'ufsc-licence-competition' )
			);
		}

		if ( 'club_region_missing' === $reason_code ) {
			$buttons[] = sprintf(
				'<a class="button" href="%s">%s</a>',
				esc_url( 'mailto:secretariat@ufsc-france.org' ),
				esc_html__( 'Contacter l’administration UFSC', 'ufsc-licence-competition' )
			);
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

		if ( class_exists( 'UFSC_LC_Schema_Cache' ) ) {
			return UFSC_LC_Schema_Cache::table_exists( $table_name );
		}

		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );
		return $exists === $table_name;
	}
}

/**
 * Season helpers: LC-first, fallback to UFSC master if already defined.
 */
if ( ! function_exists( 'ufsc_lc_get_current_season_end_year' ) ) {
	function ufsc_lc_get_current_season_end_year() {
		if ( function_exists( 'ufsc_get_current_season_end_year' ) ) {
			return ufsc_get_current_season_end_year();
		}

		$timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
		$now      = new DateTimeImmutable( 'now', $timezone );
		$year     = (int) $now->format( 'Y' );
		$month    = (int) $now->format( 'n' );
		$day      = (int) $now->format( 'j' );

		$is_new_season = $month > 9 || ( 9 === $month && $day >= 1 );

		return $is_new_season ? $year + 1 : $year;
	}
}

if ( ! function_exists( 'ufsc_lc_get_current_season_label' ) ) {
	function ufsc_lc_get_current_season_label() {
		if ( function_exists( 'ufsc_get_current_season_label' ) ) {
			return ufsc_get_current_season_label();
		}

		$end_year   = ufsc_lc_get_current_season_end_year();
		$start_year = $end_year - 1;

		return sprintf( '%d-%d', $start_year, $end_year );
	}
}

/**
 * PDF helpers: canonical LC names.
 */
if ( ! function_exists( 'ufsc_lc_licence_get_pdf_attachment_id' ) ) {
	function ufsc_lc_licence_get_pdf_attachment_id( $licence_id ) {
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

		// IMPORTANT: keep SOURCE aligned with the table you store PDFs into.
		// In your shortcode class you join documents with SOURCE = 'ASPTT'.
		// Here, for PDF licence files, you used 'UFSC'. We keep it as-is to avoid regressions.
		$source        = 'UFSC';
		$attachment_id = null;

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
					"SELECT meta_value
					 FROM {$meta_table}
					 WHERE licence_id = %d AND source = %s AND meta_key IN (%s, %s)
					 ORDER BY FIELD(meta_key, %s, %s)
					 LIMIT 1",
					$licence_id,
					$source,
					$preferred_key,
					$legacy_key,
					$preferred_key,
					$legacy_key
				)
			);
			$attachment_id = $attachment_id ? absint( $attachment_id ) : null;

			// Write-back preferred key if missing (best-effort).
			if ( $attachment_id ) {
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

if ( ! function_exists( 'ufsc_lc_licence_get_pdf_url' ) ) {
	function ufsc_lc_licence_get_pdf_url( $licence_id ) {
		$attachment_id = ufsc_lc_licence_get_pdf_attachment_id( $licence_id );
		if ( ! $attachment_id ) {
			return null;
		}

		$url = wp_get_attachment_url( $attachment_id );
		return $url ? $url : null;
	}
}

if ( ! function_exists( 'ufsc_lc_licence_has_pdf' ) ) {
	function ufsc_lc_licence_has_pdf( $licence_id ) {
		return null !== ufsc_lc_licence_get_pdf_attachment_id( $licence_id );
	}
}

/**
 * Backward compatibility wrappers (ONLY if not already defined elsewhere).
 * This prevents regressions with code calling ufsc_* names.
 */
if ( ! function_exists( 'ufsc_normalize_token' ) ) {
	function ufsc_normalize_token( $value ): string {
		return ufsc_lc_normalize_token( $value );
	}
}

if ( ! function_exists( 'ufsc_normalize_region' ) ) {
	function ufsc_normalize_region( $value ): string {
		return ufsc_lc_normalize_region( $value );
	}
}

if ( ! function_exists( 'ufsc_normalize_region_key' ) ) {
	function ufsc_normalize_region_key( $label ): string {
		return ufsc_lc_normalize_region_key( $label );
	}
}

if ( ! function_exists( 'ufsc_normalize_discipline' ) ) {
	function ufsc_normalize_discipline( $value ): string {
		return ufsc_lc_normalize_discipline( $value );
	}
}

if ( ! function_exists( 'ufsc_extract_club_disciplines' ) ) {
	function ufsc_extract_club_disciplines( $club ): array {
		return ufsc_lc_extract_club_disciplines( $club );
	}
}

if ( ! function_exists( 'ufsc_get_competitions_list_url' ) ) {
	function ufsc_get_competitions_list_url(): string {
		return ufsc_lc_get_competitions_list_url();
	}
}

if ( ! function_exists( 'ufsc_render_access_denied_notice' ) ) {
	function ufsc_render_access_denied_notice( \UFSC\Competitions\Access\AccessResult $result, string $list_url = '' ): string {
		return ufsc_lc_render_access_denied_notice( $result, $list_url );
	}
}

if ( ! function_exists( 'ufsc_licence_get_pdf_attachment_id' ) ) {
	function ufsc_licence_get_pdf_attachment_id( $licence_id ) {
		return ufsc_lc_licence_get_pdf_attachment_id( $licence_id );
	}
}

if ( ! function_exists( 'ufsc_licence_get_pdf_url' ) ) {
	function ufsc_licence_get_pdf_url( $licence_id ) {
		return ufsc_lc_licence_get_pdf_url( $licence_id );
	}
}

if ( ! function_exists( 'ufsc_licence_has_pdf' ) ) {
	function ufsc_licence_has_pdf( $licence_id ) {
		return ufsc_lc_licence_has_pdf( $licence_id );
	}
}