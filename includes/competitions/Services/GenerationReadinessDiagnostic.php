<?php

namespace UFSC\Competitions\Services;

use UFSC\Competitions\Repositories\CompetitionRepository;
use UFSC\Competitions\Repositories\EntryRepository;
use UFSC\Competitions\Repositories\FightRepository;
use UFSC\Competitions\Repositories\WeighInRepository;
use UFSC\Competitions\Entries\EntriesWorkflow;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GenerationReadinessDiagnostic {
	public static function check( int $competition_id, array $settings = array(), array $draft = array() ): array {
		$competition_id = absint( $competition_id );
		$result = array(
			'blocking' => false,
			'errors'   => array(),
			'warnings' => array(),
			'summary'  => array(),
		);

		if ( ! $competition_id ) {
			return self::block( $result, 'missing_competition', __( 'Aucune compétition sélectionnée.', 'ufsc-licence-competition' ) );
		}

		$competition_repo = new CompetitionRepository();
		$competition      = $competition_repo->get( $competition_id, true );
		if ( ! $competition ) {
			return self::block( $result, 'competition_not_found', __( 'Compétition introuvable.', 'ufsc-licence-competition' ) );
		}
		if ( ! empty( $competition->deleted_at ) ) {
			self::add_error( $result, 'competition_deleted', __( 'La compétition est dans la corbeille.', 'ufsc-licence-competition' ) );
		}
		if ( 'archived' === sanitize_key( (string) ( $competition->status ?? '' ) ) ) {
			self::add_error( $result, 'competition_archived', __( 'La compétition est archivée.', 'ufsc-licence-competition' ) );
		}

		$entry_repo = new EntryRepository();
		$entries    = $entry_repo->list_with_details( array( 'view' => 'all', 'competition_id' => $competition_id ), 5000, 0 );
		$approved   = array();
		$missing_weight = 0;
		$missing_sex = 0;
		$missing_birth = 0;
		$fighter_numbers = array();
		$duplicate_numbers = 0;
		foreach ( $entries as $entry ) {
			$status = class_exists( EntriesWorkflow::class ) ? EntriesWorkflow::normalize_status( (string) ( $entry->status ?? '' ) ) : sanitize_key( (string) ( $entry->status ?? '' ) );
			if ( 'approved' !== $status ) {
				continue;
			}
			$approved[] = $entry;
			if ( '' === trim( (string) ( $entry->weight_kg ?? $entry->weight ?? '' ) ) ) {
				$missing_weight++;
			}
			if ( '' === trim( (string) ( $entry->sex ?? $entry->sexe ?? $entry->licensee_sex ?? '' ) ) ) {
				$missing_sex++;
			}
			$birth = function_exists( 'ufsc_comp_get_entry_birth_date' ) ? ufsc_comp_get_entry_birth_date( $entry ) : ( $entry->birth_date ?? $entry->birthdate ?? '' );
			if ( '' === trim( (string) $birth ) ) {
				$missing_birth++;
			}
			$number = absint( $entry->fighter_number ?? $entry->competition_number ?? $entry->dossard ?? 0 );
			if ( $number > 0 ) {
				if ( isset( $fighter_numbers[ $number ] ) ) {
					$duplicate_numbers++;
				}
				$fighter_numbers[ $number ] = true;
			}
		}

		$result['summary']['entries_total'] = count( $entries );
		$result['summary']['entries_approved'] = count( $approved );
		if ( count( $approved ) < 2 ) {
			self::add_error( $result, 'insufficient_approved_entries', __( 'Moins de deux inscriptions validées sont disponibles.', 'ufsc-licence-competition' ) );
		}
		if ( $missing_weight > 0 ) {
			self::add_error( $result, 'entries_missing_weight', sprintf( __( '%d inscription(s) validée(s) sans poids.', 'ufsc-licence-competition' ), $missing_weight ) );
		}
		if ( $missing_sex > 0 ) {
			self::add_error( $result, 'entries_missing_sex', sprintf( __( '%d inscription(s) validée(s) sans sexe.', 'ufsc-licence-competition' ), $missing_sex ) );
		}
		if ( $missing_birth > 0 ) {
			self::add_error( $result, 'entries_missing_birth_date', sprintf( __( '%d inscription(s) validée(s) sans date de naissance.', 'ufsc-licence-competition' ), $missing_birth ) );
		}
		if ( $duplicate_numbers > 0 ) {
			self::add_error( $result, 'duplicate_fighter_numbers', sprintf( __( '%d doublon(s) de numéro combattant détecté(s).', 'ufsc-licence-competition' ), $duplicate_numbers ) );
		}

		if ( function_exists( 'ufsc_comp_detect_categories_without_opponents' ) ) {
			$lone_groups = ufsc_comp_detect_categories_without_opponents( $competition_id );
			if ( ! empty( $lone_groups ) ) {
				$result['warnings'][] = array( 'code' => 'categories_without_opponents', 'message' => sprintf( __( '%d catégorie(s) sans opposant détectée(s).', 'ufsc-licence-competition' ), count( $lone_groups ) ) );
			}
		}

		$weighin_repo = new WeighInRepository();
		$allow_unweighed = ! empty( $settings['allow_unweighed'] );
		if ( ! $allow_unweighed && $weighin_repo->has_table() && ! empty( $approved ) ) {
			$entry_ids = array_values( array_filter( array_map( static function ( $entry ) { return absint( $entry->id ?? 0 ); }, $approved ) ) );
			$weighins = $weighin_repo->get_for_entries( $competition_id, $entry_ids );
			$missing_weighins = 0;
			$reclass_pending = 0;
			foreach ( $approved as $entry ) {
				$entry_id = absint( $entry->id ?? 0 );
				$row = $weighins[ $entry_id ] ?? null;
				if ( ! $weighin_repo->is_valid_weighin_row( $row, (float) ( $competition->weight_tolerance ?? 0 ), isset( $entry->weight_kg ) ? (float) $entry->weight_kg : null ) ) {
					$missing_weighins++;
				}
				$meta = $row && ! empty( $row->notes ) ? json_decode( (string) $row->notes, true ) : array();
				if ( is_array( $meta ) && ! empty( $meta['reclass_pending'] ) ) {
					$reclass_pending++;
				}
			}
			if ( $missing_weighins > 0 ) {
				self::add_error( $result, 'weighins_missing', sprintf( __( '%d pesée(s) manquante(s) ou non valide(s).', 'ufsc-licence-competition' ), $missing_weighins ) );
			}
			if ( $reclass_pending > 0 ) {
				self::add_error( $result, 'reclass_pending', sprintf( __( '%d reclassement(s) en attente.', 'ufsc-licence-competition' ), $reclass_pending ) );
			}
		}

		$surface_count = max( 1, absint( $settings['surface_count'] ?? 1 ) );
		if ( $surface_count > 1 && function_exists( 'ufsc_competition_get_surfaces' ) ) {
			$surfaces = (array) ufsc_competition_get_surfaces( $competition_id, array( 'fallback_count' => $surface_count ) );
			$active = 0;
			foreach ( $surfaces as $surface ) {
				if ( ! empty( $surface['active'] ) ) {
					$active++;
				}
			}
			if ( $active < $surface_count ) {
				self::add_error( $result, 'surfaces_missing', __( 'Le nombre de surfaces actives est insuffisant.', 'ufsc-licence-competition' ) );
			}
		}

		$fight_repo = new FightRepository();
		$scope = $fight_repo->can_regenerate_scope( $competition_id );
		if ( empty( $scope['allowed'] ) ) {
			self::add_error( $result, 'sensitive_fights_exist', __( 'Des combats en cours, terminés ou avec résultat existent déjà.', 'ufsc-licence-competition' ) );
		}

		if ( ! empty( $draft ) ) {
			$expected_hash = self::hash_draft( $draft );
			$stored_hash = sanitize_text_field( (string) ( $draft['draft_hash'] ?? '' ) );
			if ( '' !== $stored_hash && $stored_hash !== $expected_hash ) {
				self::add_error( $result, 'draft_hash_mismatch', __( 'Le brouillon de génération semble obsolète ou altéré.', 'ufsc-licence-competition' ) );
			}
		}

		$result['blocking'] = ! empty( $result['errors'] );
		return $result;
	}

	public static function hash_draft( array $draft ): string {
		$copy = $draft;
		unset( $copy['draft_hash'] );
		return hash( 'sha256', wp_json_encode( $copy ) ?: '' );
	}

	private static function block( array $result, string $code, string $message ): array {
		self::add_error( $result, $code, $message );
		$result['blocking'] = true;
		return $result;
	}

	private static function add_error( array &$result, string $code, string $message ): void {
		$result['errors'][] = array( 'code' => sanitize_key( $code ), 'message' => $message );
	}
}
