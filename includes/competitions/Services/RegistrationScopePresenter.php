<?php

namespace UFSC\Competitions\Services;

use UFSC\Competitions\Access\CompetitionAccess;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only presenter for registration/access rules.
 *
 * Access decisions remain exclusively owned by CompetitionAccess. This class
 * only turns the existing settings/result into clear UI information so admin
 * and front screens do not duplicate access business rules.
 */
class RegistrationScopePresenter {
	public static function describe_for_competition( int $competition_id ): array {
		$competition_id = absint( $competition_id );
		$settings       = class_exists( CompetitionMeta::class ) ? CompetitionMeta::get( $competition_id ) : array();

		return self::describe( is_array( $settings ) ? $settings : array() );
	}

	public static function describe( array $settings ): array {
		$mode        = sanitize_key( (string) ( $settings['access_mode'] ?? 'affiliated' ) );
		$regions     = self::clean_labels( $settings['allowed_regions'] ?? array() );
		$disciplines = self::clean_keys( $settings['allowed_disciplines'] ?? array() );
		$club_ids    = self::clean_ids( $settings['allowed_club_ids'] ?? array() );
		$title       = __( 'Clubs affiliés UFSC', 'ufsc-licence-competition' );
		$summary     = __( 'Les inscriptions sont ouvertes aux clubs affiliés qui remplissent les conditions de la compétition.', 'ufsc-licence-competition' );
		$tone        = 'info';

		switch ( $mode ) {
			case 'all_clubs':
				$title   = __( 'Tous les clubs', 'ufsc-licence-competition' );
				$summary = __( 'Les inscriptions ne sont pas limitées à une région ou à une liste de clubs.', 'ufsc-licence-competition' );
				break;
			case 'clubs':
				$title   = __( 'Clubs sélectionnés', 'ufsc-licence-competition' );
				$summary = sprintf(
					/* translators: %d: number of selected clubs */
					__( '%d club(s) sont explicitement autorisé(s) à inscrire des combattants.', 'ufsc-licence-competition' ),
					count( $club_ids )
				);
				$tone = empty( $club_ids ) ? 'warning' : 'info';
				break;
			case 'regions':
				$title = __( 'Inscriptions par région', 'ufsc-licence-competition' );
				if ( $regions ) {
					$summary = sprintf(
						/* translators: %s: comma separated region labels */
						__( 'Seuls les clubs rattachés aux régions suivantes peuvent inscrire : %s.', 'ufsc-licence-competition' ),
						implode( ', ', $regions )
					);
				} else {
					$summary = __( 'Le mode région est actif mais aucune région n’est sélectionnée.', 'ufsc-licence-competition' );
					$tone    = 'warning';
				}
				break;
			case 'disciplines':
				$title   = __( 'Inscriptions par discipline', 'ufsc-licence-competition' );
				$summary = $disciplines
					? sprintf(
						/* translators: %s: comma separated discipline labels */
						__( 'Les clubs doivent correspondre aux disciplines autorisées : %s.', 'ufsc-licence-competition' ),
						implode( ', ', self::discipline_labels( $disciplines ) )
					)
					: __( 'Le mode discipline est actif mais aucune discipline n’est sélectionnée.', 'ufsc-licence-competition' );
				$tone = $disciplines ? 'info' : 'warning';
				break;
			case 'region_discipline':
				$title = __( 'Région + discipline', 'ufsc-licence-competition' );
				if ( $regions && $disciplines ) {
					$summary = sprintf(
						/* translators: 1: regions, 2: disciplines */
						__( 'Le club doit appartenir à une région autorisée (%1$s) ET correspondre à une discipline autorisée (%2$s).', 'ufsc-licence-competition' ),
						implode( ', ', $regions ),
						implode( ', ', self::discipline_labels( $disciplines ) )
					);
				} else {
					$summary = __( 'Le mode région + discipline est incomplet : sélectionnez au moins une région et une discipline.', 'ufsc-licence-competition' );
					$tone    = 'warning';
				}
				break;
			case 'affiliated':
			default:
				break;
		}

		$requirements = array();
		if ( ! empty( $settings['require_logged_in_club'] ) ) {
			$requirements[] = __( 'club connecté', 'ufsc-licence-competition' );
		}
		if ( ! empty( $settings['require_affiliated'] ) ) {
			$requirements[] = __( 'affiliation valide', 'ufsc-licence-competition' );
		}
		if ( ! empty( $settings['require_valid_license'] ) ) {
			$requirements[] = __( 'licence valide', 'ufsc-licence-competition' );
		}

		return array(
			'mode'             => $mode,
			'title'            => $title,
			'summary'          => $summary,
			'tone'             => $tone,
			'regions'          => $regions,
			'disciplines'      => $disciplines,
			'discipline_labels'=> self::discipline_labels( $disciplines ),
			'club_ids'         => $club_ids,
			'requirements'     => $requirements,
			'public_read'      => ! empty( $settings['public_read'] ),
			'is_region_scoped' => in_array( $mode, array( 'regions', 'region_discipline' ), true ),
		);
	}

	public static function describe_current_club( int $competition_id, int $club_id = 0, int $user_id = 0 ): array {
		$competition_id = absint( $competition_id );
		$club_id        = absint( $club_id );
		$user_id        = absint( $user_id );
		$base           = self::describe_for_competition( $competition_id );

		if ( ! class_exists( CompetitionAccess::class ) ) {
			$base['allowed'] = false;
			$base['reason']  = 'access_service_missing';
			$base['message'] = __( 'Le service de contrôle des inscriptions est indisponible.', 'ufsc-licence-competition' );
			return $base;
		}

		$access = new CompetitionAccess();
		$result = $access->can_register( $competition_id, $club_id, $user_id );

		$base['allowed'] = ! empty( $result->allowed );
		$base['reason']  = sanitize_key( (string) ( $result->reason_code ?? '' ) );
		$base['message'] = $base['allowed']
			? __( 'Votre club remplit les règles d’inscription de cette compétition.', 'ufsc-licence-competition' )
			: $access->get_denied_message( $result );
		$base['context'] = is_array( $result->context ?? null ) ? $result->context : array();

		return $base;
	}

	private static function clean_labels( $values ): array {
		$values = is_array( $values ) ? $values : array( $values );
		$output = array();
		foreach ( $values as $value ) {
			$value = sanitize_text_field( trim( (string) $value ) );
			if ( '' !== $value ) {
				$output[] = $value;
			}
		}
		return array_values( array_unique( $output ) );
	}

	private static function clean_keys( $values ): array {
		$values = is_array( $values ) ? $values : array( $values );
		$output = array();
		foreach ( $values as $value ) {
			$value = sanitize_key( (string) $value );
			if ( '' !== $value ) {
				$output[] = $value;
			}
		}
		return array_values( array_unique( $output ) );
	}

	private static function clean_ids( $values ): array {
		$values = is_array( $values ) ? $values : array( $values );
		$output = array();
		foreach ( $values as $value ) {
			$value = absint( $value );
			if ( $value > 0 ) {
				$output[] = $value;
			}
		}
		return array_values( array_unique( $output ) );
	}

	private static function discipline_labels( array $disciplines ): array {
		$output = array();
		foreach ( $disciplines as $discipline ) {
			$output[] = class_exists( DisciplineRegistry::class )
				? DisciplineRegistry::get_label( $discipline )
				: $discipline;
		}
		return array_values( array_unique( array_filter( $output ) ) );
	}
}
