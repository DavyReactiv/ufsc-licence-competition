<?php

namespace UFSC\Competitions\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the club-facing administrative/document checklist for one event.
 *
 * This service only informs the club. It does not claim that a document is
 * legally mandatory unless the event configuration explicitly marks it for
 * checking. Event regulations remain the source of truth.
 */
class CompetitionRequirementService {
	private static $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		add_filter( 'ufsc_competitions_club_submission_checklist', array( __CLASS__, 'filter_checklist' ), 20, 2 );
	}

	public static function filter_checklist( $items, $competition ): array {
		if ( ! is_object( $competition ) ) {
			return is_array( $items ) ? $items : array();
		}

		$competition_id = absint( $competition->id ?? 0 );
		$meta           = class_exists( CompetitionMeta::class ) ? CompetitionMeta::get( $competition_id ) : array();

		return self::build_checklist( $competition, $meta );
	}

	/**
	 * @param object $competition Competition row/object.
	 * @param array  $meta        Sanitized CompetitionMeta values.
	 * @return array<string>
	 */
	public static function build_checklist( $competition, array $meta ): array {
		$mode = sanitize_key( (string) ( $meta['requirements_mode'] ?? 'auto' ) );
		if ( ! in_array( $mode, array( 'auto', 'custom' ), true ) ) {
			$mode = 'auto';
		}

		$items = array(
			__( 'Identité, date de naissance et licence du combattant cohérentes.', 'ufsc-licence-competition' ),
			__( 'Poids, catégorie, niveau/classe et discipline vérifiés avant soumission.', 'ufsc-licence-competition' ),
		);

		if ( 'custom' === $mode ) {
			$check_license  = ! empty( $meta['require_valid_license'] );
			$check_medical  = ! empty( $meta['check_medical_document'] );
			$check_parental = ! empty( $meta['check_parental_authorization'] );
			$check_passport = ! empty( $meta['check_sport_passport'] );
		} else {
			$check_license  = ! empty( $meta['require_valid_license'] );
			$check_medical  = true;
			$check_parental = true;
			$check_passport = self::is_federal_competition_type( (string) ( $competition->type ?? '' ) );
		}

		if ( $check_license ) {
			$items[] = __( 'Vérifier que la licence UFSC est valide et éligible pour cet événement.', 'ufsc-licence-competition' );
		}
		if ( $check_medical ) {
			$items[] = __( 'Vérifier le certificat ou justificatif médical demandé par le règlement applicable, ainsi que sa validité.', 'ufsc-licence-competition' );
		}
		if ( $check_parental ) {
			$items[] = __( 'Pour tout mineur, vérifier l’autorisation du représentant légal et les pièces demandées par l’événement.', 'ufsc-licence-competition' );
		}
		if ( $check_passport ) {
			$items[] = __( 'Vérifier le passeport sportif lorsqu’il est demandé pour cette compétition fédérale.', 'ufsc-licence-competition' );
		}

		$note = sanitize_textarea_field( (string) ( $meta['requirements_note'] ?? '' ) );
		if ( '' !== $note ) {
			$items[] = sprintf(
				/* translators: %s: organizer requirement note */
				__( 'Consigne de l’organisateur : %s', 'ufsc-licence-competition' ),
				$note
			);
		}

		return array_values( array_unique( array_filter( $items ) ) );
	}

	private static function is_federal_competition_type( string $type ): bool {
		$type = sanitize_key( $type );
		if ( class_exists( CompetitionFilters::class ) ) {
			$type = CompetitionFilters::normalize_type_key( $type );
		}

		return in_array(
			$type,
			array(
				'competition',
				'tournoi',
				'coupe',
				'open',
				'championnat_regional',
				'selection_regionale',
				'championnat_national',
			),
			true
		);
	}
}
