<?php

namespace UFSC\Competitions\Services;

use UFSC\Competitions\Entries\EntriesWorkflow;
use UFSC\Competitions\Repositories\CompetitionRepository;
use UFSC\Competitions\Repositories\EntryRepository;
use UFSC\Competitions\Repositories\FightRepository;
use UFSC\Competitions\Repositories\WeighInRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GenerationReadinessDiagnostic {
	private const PROBLEM_LIMIT = 20;
	private const PAGE_COMPETITIONS = 'ufsc-competitions';
	private const PAGE_CATEGORIES = 'ufsc-competitions-categories';
	private const PAGE_ENTRIES = 'ufsc-competitions-entries';
	private const PAGE_WEIGHINS = 'ufsc-competitions-weighins';
	private const PAGE_BOUTS = 'ufsc-competitions-bouts';
	private const PAGE_SENSITIVE_OPS = 'ufsc-competitions-sensitive-ops';

	public static function check( int $competition_id, array $settings = array(), array $draft = array() ): array {
		$competition_id = absint( $competition_id );
		$result = array(
			'blocking' => false,
			'errors'   => array(),
			'warnings' => array(),
			'infos'    => array(),
			'summary'  => self::default_summary( $competition_id ),
			'sections' => array(),
			'actions'  => array(),
		);

		if ( ! $competition_id ) {
			self::add_section( $result, 'competition', __( 'État de la compétition', 'ufsc-licence-competition' ), 'blocked', array(), array() );
			self::add_error( $result, 'missing_competition', __( 'Aucune compétition sélectionnée.', 'ufsc-licence-competition' ) );
			self::add_action( $result, __( 'Sélectionner une compétition active', 'ufsc-licence-competition' ), 'high', 'competition', self::admin_url( self::PAGE_COMPETITIONS ) );
			return self::finalize( $result );
		}

		$competition_repo = new CompetitionRepository();
		$competition      = $competition_repo->get( $competition_id, true );
		if ( ! $competition ) {
			self::add_section( $result, 'competition', __( 'État de la compétition', 'ufsc-licence-competition' ), 'blocked', array( 'competition_id' => $competition_id ), array() );
			self::add_error( $result, 'competition_not_found', __( 'Compétition introuvable.', 'ufsc-licence-competition' ) );
			self::add_action( $result, __( 'Revenir à la liste des compétitions', 'ufsc-licence-competition' ), 'high', 'competition', self::admin_url( self::PAGE_COMPETITIONS ) );
			return self::finalize( $result );
		}

		$entry_repo   = new EntryRepository();
		$fight_repo   = new FightRepository();
		$weighin_repo = new WeighInRepository();
		$entries      = $entry_repo->list_with_details( array( 'view' => 'all', 'competition_id' => $competition_id ), 5000, 0 );
		$fights       = $fight_repo->list( array( 'view' => 'all', 'competition_id' => $competition_id ), 5000, 0 );
		$analysis     = self::analyse_entries( $entries, $settings );
		$weighins     = self::analyse_weighins( $competition_id, $competition, $analysis['approved'], $settings, $weighin_repo );
		$categories   = self::analyse_categories( $competition_id, $analysis['approved'], $weighins['by_entry'], $settings );
		$surfaces     = self::analyse_surfaces( $competition_id, $settings, $draft, $categories['summary']['estimated_fights'] ?? 0 );
		$fight_state  = self::analyse_fights( $competition_id, $fights, $fight_repo );
		$draft_cases  = self::analyse_draft_cases( $draft );

		self::build_competition_section( $result, $competition_id, $competition, $settings, $draft, $fight_state, $surfaces );
		self::build_entries_section( $result, $competition_id, $analysis );
		self::build_weighins_section( $result, $competition_id, $weighins, self::weighins_required( $settings ) );
		self::build_categories_section( $result, $competition_id, $categories, ! empty( $settings['strict_lone_categories'] ) || ! empty( $settings['block_categories_without_opponents'] ) );
		self::build_surfaces_section( $result, $competition_id, $surfaces );
		self::build_fights_section( $result, $competition_id, $fight_state );
		self::build_draft_cases_section( $result, $competition_id, $draft_cases );

		if ( ! empty( $draft ) ) {
			$expected_hash = self::hash_draft( $draft );
			$stored_hash   = sanitize_text_field( (string) ( $draft['draft_hash'] ?? '' ) );
			if ( '' !== $stored_hash && $stored_hash !== $expected_hash ) {
				self::add_error( $result, 'draft_hash_mismatch', __( 'Le brouillon de génération semble obsolète ou altéré.', 'ufsc-licence-competition' ) );
				self::add_action( $result, __( 'Régénérer le brouillon avant validation', 'ufsc-licence-competition' ), 'high', 'generation', self::admin_url( self::PAGE_BOUTS, $competition_id ) );
			} else {
				self::add_info( $result, 'draft_valid', __( 'Un brouillon de génération valide est disponible.', 'ufsc-licence-competition' ) );
			}
		} else {
			self::add_info( $result, 'draft_missing', __( 'Aucun brouillon validable : générez une prévisualisation avant toute création réelle.', 'ufsc-licence-competition' ) );
			self::add_action( $result, __( 'Générer un brouillon avant validation réelle', 'ufsc-licence-competition' ), 'medium', 'generation', self::admin_url( self::PAGE_BOUTS, $competition_id ) );
		}

		$result['summary'] = array_merge(
			$result['summary'],
			array(
				'entries_total'        => (int) $analysis['summary']['total'],
				'entries_approved'     => (int) $analysis['summary']['approved'],
				'entries_generable'    => (int) $analysis['summary']['generable'],
				'entries_to_correct'   => (int) $analysis['summary']['to_correct'],
				'weighins_required'    => self::weighins_required( $settings ),
				'weighins_valid'       => (int) $weighins['summary']['valid'],
				'weighins_missing'     => (int) $weighins['summary']['missing'],
				'categories_total'     => (int) $categories['summary']['groups'],
				'categories_ready'     => (int) $categories['summary']['ready'],
				'categories_lone'      => (int) $categories['summary']['lone'],
				'estimated_fights'     => (int) $categories['summary']['estimated_fights'],
				'surface_count'        => (int) $surfaces['summary']['requested'],
				'surfaces_active'      => (int) $surfaces['summary']['active'],
				'fights_existing'      => (int) $fight_state['summary']['total'],
				'fights_sensitive'     => (int) $fight_state['summary']['sensitive'],
			)
		);

		return self::finalize( $result );
	}


	private static function default_summary( int $competition_id ): array {
		return array(
			'competition_id' => $competition_id,
			'status' => 'blocked',
			'status_label' => __( 'Bloquée', 'ufsc-licence-competition' ),
			'score' => 0,
			'entries_total' => 0,
			'entries_approved' => 0,
			'entries_generable' => 0,
			'entries_to_correct' => 0,
			'weighins_required' => true,
			'weighins_valid' => 0,
			'weighins_missing' => 0,
			'categories_total' => 0,
			'categories_ready' => 0,
			'categories_lone' => 0,
			'estimated_fights' => 0,
			'surface_count' => 0,
			'surfaces_active' => 0,
			'fights_existing' => 0,
			'fights_sensitive' => 0,
		);
	}

	private static function weighins_required( array $settings ): bool {
		if ( ! empty( $settings['allow_unweighed'] ) || ! empty( $settings['sandbox_generation'] ) ) {
			return false;
		}
		foreach ( array( 'weighins_required', 'require_weighins', 'require_weighin' ) as $key ) {
			if ( array_key_exists( $key, $settings ) && ! (bool) $settings[ $key ] ) {
				return false;
			}
		}
		return true;
	}

	public static function hash_draft( array $draft ): string {
		$copy = $draft;
		unset( $copy['draft_hash'] );
		return hash( 'sha256', wp_json_encode( $copy ) ?: '' );
	}

	private static function build_competition_section( array &$result, int $competition_id, $competition, array $settings, array $draft, array $fight_state, array $surfaces ): void {
		$items = array();
		$status = 'ready';
		$competition_status = sanitize_key( (string) ( $competition->status ?? '' ) );
		if ( ! empty( $competition->deleted_at ) ) {
			$status = 'blocked';
			self::add_error( $result, 'competition_deleted', __( 'La compétition est dans la corbeille.', 'ufsc-licence-competition' ) );
		}
		if ( 'archived' === $competition_status ) {
			$status = 'archived';
			self::add_error( $result, 'competition_archived', __( 'La compétition est archivée.', 'ufsc-licence-competition' ) );
		}
		if ( class_exists( GenerationLockService::class ) && GenerationLockService::is_generation_locked( $competition_id ) ) {
			$status = 'locked';
			self::add_error( $result, 'competition_generation_locked', __( 'Une génération a déjà été validée : la génération réelle est verrouillée pour éviter les doublons.', 'ufsc-licence-competition' ) );
			self::add_action( $result, __( 'Consulter les combats existants avant toute régénération', 'ufsc-licence-competition' ), 'high', 'fights', self::admin_url( self::PAGE_BOUTS, $competition_id ) );
		}
		if ( empty( $competition->event_start_datetime ) && empty( $competition->date ) && empty( $competition->competition_date ) ) {
			$status = 'incomplete';
			self::add_warning( $result, 'competition_missing_date', __( 'La date de compétition n’est pas renseignée.', 'ufsc-licence-competition' ) );
		}
		if ( '' === trim( (string) ( $competition->discipline ?? '' ) ) ) {
			$status = 'incomplete';
			self::add_warning( $result, 'competition_missing_discipline', __( 'La discipline de la compétition n’est pas renseignée.', 'ufsc-licence-competition' ) );
		}
		if ( '' === trim( (string) ( $competition->venue_city ?? $competition->location ?? $competition->venue ?? '' ) ) ) {
			$status = 'incomplete';
			self::add_warning( $result, 'competition_missing_location', __( 'Le lieu de compétition n’est pas renseigné.', 'ufsc-licence-competition' ) );
		}
		if ( ! empty( $surfaces['blocking'] ) ) {
			$status = 'blocked';
		}
		if ( ! empty( $fight_state['summary']['sensitive'] ) ) {
			$status = 'blocked';
		}
		if ( ! empty( $settings['generation_in_progress'] ) ) {
			$status = 'blocked';
			self::add_error( $result, 'generation_in_progress', __( 'Une génération semble déjà en cours.', 'ufsc-licence-competition' ) );
		}

		$items[] = self::item( 'status', sprintf( __( 'Statut : %s', 'ufsc-licence-competition' ), $competition_status ?: __( 'non défini', 'ufsc-licence-competition' ) ), 'info' );
		$items[] = self::item( 'date', sprintf( __( 'Date : %s', 'ufsc-licence-competition' ), (string) ( $competition->event_start_datetime ?? $competition->date ?? $competition->competition_date ?? '—' ) ), 'info' );
		$items[] = self::item( 'discipline', sprintf( __( 'Discipline : %s', 'ufsc-licence-competition' ), (string) ( $competition->discipline ?? '—' ) ), 'info' );
		$items[] = self::item( 'location', sprintf( __( 'Lieu : %s', 'ufsc-licence-competition' ), (string) ( $competition->venue_city ?? $competition->location ?? $competition->venue ?? '—' ) ), 'info' );
		$items[] = self::item( 'draft', ! empty( $draft ) ? __( 'Brouillon disponible.', 'ufsc-licence-competition' ) : __( 'Aucun brouillon disponible.', 'ufsc-licence-competition' ), ! empty( $draft ) ? 'ok' : 'info' );
		$items[] = self::item( 'fights', sprintf( __( 'Combats existants : %d', 'ufsc-licence-competition' ), (int) $fight_state['summary']['total'] ), empty( $fight_state['summary']['sensitive'] ) ? 'info' : 'blocked' );

		self::add_section( $result, 'competition', __( 'État de la compétition', 'ufsc-licence-competition' ), $status, array( 'label' => self::status_label( $status ) ), $items );
	}

	private static function analyse_entries( array $entries, array $settings ): array {
		$summary = array_fill_keys( array( 'total', 'approved', 'draft', 'pending', 'refused', 'cancelled', 'generable', 'blocking', 'to_correct', 'non_generable' ), 0 );
		$issues = array();
		$approved = array();
		$name_keys = array();
		$license_keys = array();
		$fighter_numbers = array();
		$duplicate_name_birth = 0;
		$duplicate_license = 0;
		$duplicate_numbers = 0;

		foreach ( $entries as $entry ) {
			$summary['total']++;
			$status = self::entry_status( $entry );
			if ( isset( $summary[ $status ] ) ) {
				$summary[ $status ]++;
			} elseif ( 'approved' !== $status ) {
				$summary['pending']++;
			}
			if ( 'approved' !== $status ) {
				$summary['non_generable']++;
				continue;
			}

			$summary['approved']++;
			$approved[] = $entry;
			$entry_id = absint( $entry->id ?? 0 );
			$blocking_issues = array();
			$warning_issues = array();
			$first = trim( (string) ( $entry->first_name ?? $entry->firstname ?? $entry->prenom ?? '' ) );
			$last = trim( (string) ( $entry->last_name ?? $entry->lastname ?? $entry->nom ?? '' ) );
			$birth = self::entry_birth( $entry );
			$club = trim( (string) ( $entry->club_name ?? $entry->club ?? $entry->club_id ?? '' ) );
			$sex = trim( (string) ( $entry->sex ?? $entry->sexe ?? $entry->licensee_sex ?? '' ) );
			$weight = trim( (string) ( $entry->weight_kg ?? $entry->weight ?? '' ) );
			$category = trim( (string) ( $entry->category_name ?? $entry->category ?? $entry->category_id ?? '' ) );

			if ( '' === $first ) { $warning_issues[] = __( 'prénom manquant', 'ufsc-licence-competition' ); }
			if ( '' === $last ) { $warning_issues[] = __( 'nom manquant', 'ufsc-licence-competition' ); }
			if ( '' === $sex ) { $blocking_issues[] = __( 'sexe manquant', 'ufsc-licence-competition' ); }
			if ( '' === $birth ) { $blocking_issues[] = __( 'date de naissance manquante', 'ufsc-licence-competition' ); }
			if ( '' === $weight ) { $blocking_issues[] = __( 'poids déclaré manquant', 'ufsc-licence-competition' ); }
			if ( '' === $category ) { $warning_issues[] = __( 'catégorie manquante', 'ufsc-licence-competition' ); }
			if ( '' === $club ) { $warning_issues[] = __( 'club manquant', 'ufsc-licence-competition' ); }

			$name_key = sanitize_key( remove_accents( strtolower( $last . '_' . $first . '_' . $birth ) ) );
			if ( '' !== trim( $last . $first . $birth ) && isset( $name_keys[ $name_key ] ) ) {
				$duplicate_name_birth++;
				self::push_limited( $issues, self::item( 'duplicate_identity', self::entry_label( $entry ) . ' — ' . __( 'doublon possible nom/prénom/date de naissance', 'ufsc-licence-competition' ), 'warning' ) );
			}
			$name_keys[ $name_key ] = true;

			$license = trim( (string) ( $entry->license_id ?? $entry->licence_id ?? $entry->license_number ?? $entry->licence_number ?? '' ) );
			if ( '' !== $license ) {
				$key = sanitize_key( $license );
				if ( isset( $license_keys[ $key ] ) ) {
					$duplicate_license++;
					$blocking_issues[] = __( 'doublon licence', 'ufsc-licence-competition' );
				}
				$license_keys[ $key ] = true;
			}

			$number = absint( $entry->fighter_number ?? $entry->competition_number ?? $entry->dossard ?? 0 );
			if ( $number > 0 ) {
				if ( isset( $fighter_numbers[ $number ] ) ) {
					$duplicate_numbers++;
					$blocking_issues[] = __( 'doublon numéro combattant', 'ufsc-licence-competition' );
				}
				$fighter_numbers[ $number ] = $entry_id ?: true;
			}

			if ( ! empty( $blocking_issues ) ) {
				$summary['blocking']++;
				$summary['to_correct']++;
				self::push_limited( $issues, self::item( 'approved_incomplete_blocking', self::entry_label( $entry ) . ' — ' . implode( ', ', $blocking_issues ), 'blocked' ) );
			} else {
				$summary['generable']++;
			}
			if ( ! empty( $warning_issues ) ) {
				$summary['to_correct']++;
				self::push_limited( $issues, self::item( 'approved_incomplete_warning', self::entry_label( $entry ) . ' — ' . implode( ', ', $warning_issues ), 'warning' ) );
			}
		}

		$summary['duplicate_identity'] = $duplicate_name_birth;
		$summary['duplicate_license'] = $duplicate_license;
		$summary['duplicate_fighter_numbers'] = $duplicate_numbers;

		return array(
			'summary'  => $summary,
			'issues'   => $issues,
			'approved' => $approved,
		);
	}

	private static function build_entries_section( array &$result, int $competition_id, array $analysis ): void {
		$summary = $analysis['summary'];
		$status = 'ready';
		if ( (int) $summary['approved'] < 2 ) {
			$status = 'blocked';
			self::add_error( $result, 'insufficient_approved_entries', __( 'Moins de deux inscriptions validées sont disponibles.', 'ufsc-licence-competition' ) );
			self::add_action( $result, __( 'Valider les inscriptions exploitables', 'ufsc-licence-competition' ), 'high', 'entries', self::admin_url( self::PAGE_ENTRIES, $competition_id ) );
		}
		if ( (int) $summary['blocking'] > 0 ) {
			$status = 'blocked';
			self::add_error( $result, 'approved_entries_incomplete', sprintf( __( '%d inscription(s) validée(s) ont des données indispensables manquantes.', 'ufsc-licence-competition' ), (int) $summary['blocking'] ) );
			self::add_action( $result, __( 'Corriger les données indispensables des inscriptions validées', 'ufsc-licence-competition' ), 'high', 'entries', self::admin_url( self::PAGE_ENTRIES, $competition_id ) );
		} elseif ( (int) $summary['to_correct'] > 0 ) {
			$status = 'warning';
			self::add_warning( $result, 'approved_entries_to_review', sprintf( __( '%d inscription(s) validée(s) ont des informations administratives à compléter.', 'ufsc-licence-competition' ), (int) $summary['to_correct'] ) );
		}
		if ( (int) $summary['pending'] > 0 || (int) $summary['draft'] > 0 ) {
			self::add_warning( $result, 'entries_not_approved', sprintf( __( '%d inscription(s) non validée(s) ne seront pas utilisées.', 'ufsc-licence-competition' ), (int) $summary['pending'] + (int) $summary['draft'] ) );
			self::add_action( $result, __( 'Contrôler les inscriptions en attente ou brouillon', 'ufsc-licence-competition' ), 'medium', 'entries', self::admin_url( self::PAGE_ENTRIES, $competition_id ) );
		}
		if ( (int) $summary['duplicate_identity'] > 0 ) {
			self::add_warning( $result, 'duplicate_identity_possible', sprintf( __( '%d doublon(s) possible(s) nom/prénom/date de naissance.', 'ufsc-licence-competition' ), (int) $summary['duplicate_identity'] ) );
		}
		if ( (int) $summary['duplicate_license'] > 0 ) {
			$status = 'blocked';
			self::add_error( $result, 'duplicate_license', sprintf( __( '%d doublon(s) de licence détecté(s).', 'ufsc-licence-competition' ), (int) $summary['duplicate_license'] ) );
		}
		if ( (int) $summary['duplicate_fighter_numbers'] > 0 ) {
			$status = 'blocked';
			self::add_error( $result, 'duplicate_fighter_numbers', sprintf( __( '%d doublon(s) de numéro combattant détecté(s).', 'ufsc-licence-competition' ), (int) $summary['duplicate_fighter_numbers'] ) );
			self::add_action( $result, __( 'Corriger ou réattribuer les numéros combattants', 'ufsc-licence-competition' ), 'high', 'entries', self::admin_url( self::PAGE_WEIGHINS, $competition_id ) );
		}

		self::add_section(
			$result,
			'entries',
			__( 'Inscriptions', 'ufsc-licence-competition' ),
			$status,
			array(
				'total'       => (int) $summary['total'],
				'generable'   => (int) $summary['generable'],
				'approved'    => (int) $summary['approved'],
				'blocking'    => (int) $summary['blocking'],
				'to_correct'  => (int) $summary['to_correct'],
				'pending'     => (int) $summary['pending'],
				'draft'       => (int) $summary['draft'],
				'refused'     => (int) $summary['refused'],
				'cancelled'   => (int) $summary['cancelled'],
			),
			$analysis['issues']
		);
	}

	private static function analyse_weighins( int $competition_id, $competition, array $approved, array $settings, WeighInRepository $repo ): array {
		$summary = array_fill_keys( array( 'required', 'total', 'missing', 'valid', 'pending', 'out_of_limit', 'reclass_pending', 'absent', 'missing_official_weight', 'missing_fighter_number', 'duplicate_fighter_numbers', 'incoherent_entry' ), 0 );
		$summary['required'] = self::weighins_required( $settings ) ? 1 : 0;
		$issues = array();
		$by_entry = array();
		$numbers = array();
		$entry_ids = array_values( array_filter( array_map( static function ( $entry ) { return absint( $entry->id ?? 0 ); }, $approved ) ) );
		$rows = $repo->get_for_entries( $competition_id, $entry_ids );
		$tolerance = (float) ( $competition->weight_tolerance ?? 0 );

		foreach ( $approved as $entry ) {
			$entry_id = absint( $entry->id ?? 0 );
			$row = $rows[ $entry_id ] ?? null;
			$by_entry[ $entry_id ] = $row;
			$declared_weight = isset( $entry->weight_kg ) && '' !== (string) $entry->weight_kg ? (float) $entry->weight_kg : null;
			if ( ! $row ) {
				$summary['missing']++;
				self::push_limited( $issues, self::item( 'weighin_missing', self::entry_label( $entry ) . ' — ' . __( 'pesée manquante', 'ufsc-licence-competition' ), 'blocked' ) );
				continue;
			}
			$summary['total']++;
			$status = sanitize_key( (string) ( $row->status ?? '' ) );
			$meta = ! empty( $row->notes ) ? json_decode( (string) $row->notes, true ) : array();
			$meta = is_array( $meta ) ? $meta : array();
			if ( $repo->is_valid_weighin_row( $row, $tolerance, $declared_weight ) ) {
				$summary['valid']++;
			} elseif ( in_array( $status, array( 'out_of_limit', 'over', 'ko' ), true ) ) {
				$summary['out_of_limit']++;
				self::push_limited( $issues, self::item( 'weighin_out_of_limit', self::entry_label( $entry ) . ' — ' . __( 'pesée hors limite', 'ufsc-licence-competition' ), 'blocked' ) );
			} elseif ( in_array( $status, array( 'absent', 'no_show' ), true ) ) {
				$summary['absent']++;
				self::push_limited( $issues, self::item( 'weighin_absent', self::entry_label( $entry ) . ' — ' . __( 'absent à la pesée', 'ufsc-licence-competition' ), 'blocked' ) );
			} else {
				$summary['pending']++;
				self::push_limited( $issues, self::item( 'weighin_pending', self::entry_label( $entry ) . ' — ' . __( 'pesée en attente ou non valide', 'ufsc-licence-competition' ), 'blocked' ) );
			}
			if ( ! empty( $meta['reclass_pending'] ) || in_array( $status, array( 'awaiting_reclassification', 'reclass_pending' ), true ) ) {
				$summary['reclass_pending']++;
				self::push_limited( $issues, self::item( 'reclass_pending', self::entry_label( $entry ) . ' — ' . __( 'reclassement en attente', 'ufsc-licence-competition' ), 'blocked' ) );
			}
			if ( ! isset( $row->weight_measured ) || '' === (string) $row->weight_measured ) {
				$summary['missing_official_weight']++;
				self::push_limited( $issues, self::item( 'official_weight_missing', self::entry_label( $entry ) . ' — ' . __( 'poids officiel manquant', 'ufsc-licence-competition' ), 'warning' ) );
			}
			$number = absint( $meta['fighter_number'] ?? $entry->fighter_number ?? $entry->competition_number ?? $entry->dossard ?? 0 );
			if ( $number <= 0 ) {
				$summary['missing_fighter_number']++;
			} elseif ( isset( $numbers[ $number ] ) ) {
				$summary['duplicate_fighter_numbers']++;
				self::push_limited( $issues, self::item( 'weighin_duplicate_fighter_number', self::entry_label( $entry ) . ' — ' . __( 'numéro combattant dupliqué à la pesée', 'ufsc-licence-competition' ), 'blocked' ) );
			}
			$numbers[ $number ] = true;
		}

		return array( 'summary' => $summary, 'issues' => $issues, 'by_entry' => $by_entry );
	}

	private static function build_weighins_section( array &$result, int $competition_id, array $weighins, bool $weighins_required ): void {
		$summary = $weighins['summary'];
		$status = 'ready';
		if ( ! $weighins_required ) {
			$status = 'warning';
			self::add_info( $result, 'weighins_not_required', __( 'Les pesées ne sont pas bloquantes pour cette génération (override ou réglage pesées non requises).', 'ufsc-licence-competition' ) );
		} else {
			foreach ( array( 'missing', 'pending', 'out_of_limit', 'reclass_pending', 'absent', 'duplicate_fighter_numbers' ) as $key ) {
				if ( (int) $summary[ $key ] > 0 ) {
					$status = 'blocked';
				}
			}
			if ( 'blocked' === $status ) {
				self::add_error( $result, 'weighins_not_ready', __( 'Les pesées ne sont pas prêtes pour une génération sécurisée.', 'ufsc-licence-competition' ) );
				self::add_action( $result, __( 'Réaliser ou corriger les pesées bloquantes', 'ufsc-licence-competition' ), 'high', 'weighins', self::admin_url( self::PAGE_WEIGHINS, $competition_id ) );
			}
		}
		if ( (int) $summary['missing_official_weight'] > 0 ) {
			self::add_warning( $result, 'official_weight_missing', sprintf( __( '%d pesée(s) sans poids officiel.', 'ufsc-licence-competition' ), (int) $summary['missing_official_weight'] ) );
		}
		if ( (int) $summary['missing_fighter_number'] > 0 ) {
			self::add_warning( $result, 'fighter_number_missing', sprintf( __( '%d compétiteur(s) sans numéro combattant.', 'ufsc-licence-competition' ), (int) $summary['missing_fighter_number'] ) );
		}
		self::add_section( $result, 'weighins', __( 'Pesées', 'ufsc-licence-competition' ), $status, $summary, $weighins['issues'] );
	}

	private static function analyse_categories( int $competition_id, array $approved, array $weighins_by_entry, array $settings ): array {
		$groups = array();
		foreach ( $approved as $entry ) {
			$key_parts = array(
				self::entry_value( $entry, array( 'discipline' ), __( 'Sans discipline', 'ufsc-licence-competition' ) ),
				self::entry_value( $entry, array( 'sex', 'sexe', 'licensee_sex' ), __( 'Sans sexe', 'ufsc-licence-competition' ) ),
				self::entry_age_bucket( $entry ),
				self::entry_value( $entry, array( 'category_name', 'category', 'category_id' ), __( 'Sans catégorie', 'ufsc-licence-competition' ) ),
				self::entry_value( $entry, array( 'weight_category', 'weight_class', 'weight_cat', 'categorie_poids', 'category_weight' ), __( 'Catégorie poids non définie', 'ufsc-licence-competition' ) ),
			);
			if ( ! empty( $settings['use_level_split'] ) ) {
				$key_parts[] = self::entry_value( $entry, array( 'level', 'niveau', 'class' ), __( 'Niveau non défini', 'ufsc-licence-competition' ) );
			}
			$key = implode( '|', array_map( 'sanitize_key', $key_parts ) );
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'label' => implode( ' / ', $key_parts ),
					'total' => 0,
					'eligible' => 0,
					'weighins_ok' => 0,
					'missing_weight' => 0,
					'missing_birth' => 0,
					'missing_sex' => 0,
					'duplicates' => 0,
					'format' => '',
					'status' => 'info',
				);
			}
			$groups[ $key ]['total']++;
			$sex = trim( (string) ( $entry->sex ?? $entry->sexe ?? $entry->licensee_sex ?? '' ) );
			$birth = self::entry_birth( $entry );
			$weight = trim( (string) ( $entry->weight_kg ?? $entry->weight ?? '' ) );
			if ( '' === $sex ) { $groups[ $key ]['missing_sex']++; }
			if ( '' === $birth ) { $groups[ $key ]['missing_birth']++; }
			if ( '' === $weight ) { $groups[ $key ]['missing_weight']++; }
			if ( '' !== $sex && '' !== $birth && '' !== $weight ) { $groups[ $key ]['eligible']++; }
			$row = $weighins_by_entry[ absint( $entry->id ?? 0 ) ] ?? null;
			if ( $row && in_array( sanitize_key( (string) ( $row->status ?? '' ) ), array( 'ok', 'weighed', 'validated' ), true ) ) {
				$groups[ $key ]['weighins_ok']++;
			}
		}

		$summary = array( 'groups' => count( $groups ), 'ready' => 0, 'lone' => 0, 'blocked' => 0, 'estimated_fights' => 0 );
		$items = array();
		foreach ( $groups as &$group ) {
			$eligible = (int) $group['eligible'];
			$group['format'] = self::recommended_format( $eligible );
			if ( 0 === $eligible ) {
				$group['status'] = 'info';
			} elseif ( 1 === $eligible ) {
				$group['status'] = 'warning';
				$summary['lone']++;
			} else {
				$group['status'] = 'ready';
				$summary['ready']++;
				$summary['estimated_fights'] += max( 1, $eligible - 1 );
			}
			if ( $group['missing_weight'] || $group['missing_birth'] || $group['missing_sex'] ) {
				$group['status'] = 0 === $eligible ? 'blocked' : $group['status'];
			}
			self::push_limited( $items, self::item( 'category_group', sprintf( '%1$s — %2$d éligible(s) / %3$d inscrit(s) — %4$s', $group['label'], $eligible, (int) $group['total'], $group['format'] ), $group['status'] ) );
		}
		unset( $group );

		return array( 'groups' => array_values( $groups ), 'summary' => $summary, 'items' => $items );
	}

	private static function build_categories_section( array &$result, int $competition_id, array $categories, bool $strict_lone ): void {
		$summary = $categories['summary'];
		$status = 'ready';
		if ( (int) $summary['ready'] <= 0 ) {
			$status = 'blocked';
			self::add_error( $result, 'no_generable_category', __( 'Aucune catégorie ne contient au moins deux combattants éligibles.', 'ufsc-licence-competition' ) );
			self::add_action( $result, __( 'Contrôler les catégories et les inscriptions éligibles', 'ufsc-licence-competition' ), 'high', 'categories', self::admin_url( self::PAGE_CATEGORIES, $competition_id ) );
		}
		if ( (int) $summary['lone'] > 0 ) {
			if ( $strict_lone ) {
				$status = 'blocked';
				self::add_error( $result, 'categories_without_opponents_strict', sprintf( __( '%d catégorie(s) sans opposant bloquent la génération en mode strict.', 'ufsc-licence-competition' ), (int) $summary['lone'] ) );
			} else {
				self::add_warning( $result, 'categories_without_opponents', sprintf( __( '%d catégorie(s) sans opposant : à contrôler, BYE/reclassement possible.', 'ufsc-licence-competition' ), (int) $summary['lone'] ) );
				self::add_action( $result, __( 'Vérifier les catégories sans opposant', 'ufsc-licence-competition' ), 'medium', 'categories', self::admin_url( self::PAGE_CATEGORIES, $competition_id ) );
			}
		}
		self::add_section( $result, 'categories', __( 'Catégories', 'ufsc-licence-competition' ), $status, $summary, $categories['items'] );
	}

	private static function analyse_surfaces( int $competition_id, array $settings, array $draft, int $estimated_fights ): array {
		$requested = max( 1, absint( $settings['surface_count'] ?? 1 ) );
		$surfaces = function_exists( 'ufsc_competition_get_surfaces' ) ? (array) ufsc_competition_get_surfaces( $competition_id, array( 'fallback_count' => $requested ) ) : array();
		$active = 0;
		$named = 0;
		$items = array();
		foreach ( $surfaces as $surface ) {
			$is_active = ! empty( $surface['active'] );
			if ( $is_active ) { $active++; }
			$name = trim( (string) ( $surface['name'] ?? $surface['label'] ?? '' ) );
			$type = trim( (string) ( $surface['type'] ?? '' ) );
			if ( '' !== $name ) { $named++; }
			self::push_limited( $items, self::item( 'surface', sprintf( '%1$s — %2$s — %3$s', $name ?: __( 'Surface sans nom', 'ufsc-licence-competition' ), $type ?: __( 'type non défini', 'ufsc-licence-competition' ), $is_active ? __( 'active', 'ufsc-licence-competition' ) : __( 'inactive', 'ufsc-licence-competition' ) ), $is_active ? 'ok' : 'warning' ) );
		}
		$per_surface = $active > 0 ? (int) ceil( max( 0, $estimated_fights ) / $active ) : 0;
		return array(
			'blocking' => $requested > 1 && $active < $requested,
			'summary' => array(
				'requested' => $requested,
				'defined' => count( $surfaces ),
				'active' => $active,
				'named' => $named,
				'estimated_fights' => $estimated_fights,
				'estimated_per_surface' => $per_surface,
				'average_duration_seconds' => (int) ( $settings['fight_duration_seconds'] ?? $settings['duration_seconds'] ?? 0 ),
			),
			'items' => $items,
		);
	}

	private static function build_surfaces_section( array &$result, int $competition_id, array $surfaces ): void {
		$summary = $surfaces['summary'];
		$status = 'ready';
		if ( ! empty( $surfaces['blocking'] ) ) {
			$status = 'blocked';
			self::add_error( $result, 'surfaces_missing', __( 'Le nombre de surfaces actives est insuffisant pour la génération multi-surface.', 'ufsc-licence-competition' ) );
			self::add_action( $result, __( 'Définir les surfaces de combat', 'ufsc-licence-competition' ), 'high', 'surfaces', self::admin_url( self::PAGE_BOUTS, $competition_id ) );
		} elseif ( (int) $summary['named'] < (int) $summary['active'] ) {
			$status = 'warning';
			self::add_warning( $result, 'surface_names_missing', __( 'Certaines surfaces actives n’ont pas de nom clair.', 'ufsc-licence-competition' ) );
		}
		self::add_section( $result, 'surfaces', __( 'Surfaces / Plateau', 'ufsc-licence-competition' ), $status, $summary, $surfaces['items'] );
	}

	private static function analyse_draft_cases( array $draft ): array {
		$summary = array_fill_keys(
			array(
				'groups_total',
				'groups_without_fight',
				'isolated_participants',
				'insufficient_groups',
				'odd_groups',
				'bye_count',
				'placeholder_count',
				'fights_total',
				'fights_missing_group_key',
				'fights_missing_category_id',
				'fights_without_real_opponent',
			),
			0
		);
		$items = array();
		$groups = isset( $draft['groups'] ) && is_array( $draft['groups'] ) ? $draft['groups'] : array();
		$fights = isset( $draft['fights'] ) && is_array( $draft['fights'] ) ? $draft['fights'] : ( isset( $draft['fights_preview'] ) && is_array( $draft['fights_preview'] ) ? $draft['fights_preview'] : array() );

		foreach ( $groups as $group ) {
			$group = is_array( $group ) ? $group : array();
			$summary['groups_total']++;
			$label = sanitize_text_field( (string) ( $group['label'] ?? $group['group_key'] ?? __( 'Groupe sans libellé', 'ufsc-licence-competition' ) ) );
			$fighter_count = (int) ( $group['fighter_count'] ?? $group['entries_count'] ?? count( (array) ( $group['athletes'] ?? array() ) ) );
			$fight_count = (int) ( $group['fight_count'] ?? $group['estimated_fights'] ?? 0 );
			$bye_count = (int) ( $group['bye_count'] ?? $group['bye_slots'] ?? 0 );
			$warnings = array_values( array_filter( array_map( 'sanitize_key', (array) ( $group['warnings'] ?? array() ) ) ) );

			if ( $fight_count <= 0 ) {
				$summary['groups_without_fight']++;
				self::push_limited( $items, self::item( 'draft_group_without_fight', sprintf( __( '%s — aucun combat prévu dans le brouillon.', 'ufsc-licence-competition' ), $label ), 'warning' ) );
			}
			if ( 1 === $fighter_count || in_array( 'isolated_participant', $warnings, true ) ) {
				$summary['isolated_participants'] += max( 0, $fighter_count );
				self::push_limited( $items, self::item( 'isolated_participant', sprintf( __( '%s — participant seul à contrôler.', 'ufsc-licence-competition' ), $label ), 'warning' ) );
			}
			if ( $fighter_count < 2 || in_array( 'insufficient_participants', $warnings, true ) ) {
				$summary['insufficient_groups']++;
			}
			if ( $fighter_count > 1 && 1 === ( $fighter_count % 2 ) ) {
				$summary['odd_groups']++;
				self::push_limited( $items, self::item( 'odd_participant_count', sprintf( __( '%s — nombre impair de participants.', 'ufsc-licence-competition' ), $label ), 'warning' ) );
			}
			if ( $bye_count > 0 ) {
				self::push_limited( $items, self::item( 'bye_generated', sprintf( __( '%s — %d BYE prévu(s).', 'ufsc-licence-competition' ), $label, $bye_count ), 'info' ) );
			}
		}

		foreach ( $fights as $fight ) {
			$fight = is_array( $fight ) ? $fight : array();
			$summary['fights_total']++;
			$type = sanitize_key( (string) ( $fight['type'] ?? '' ) );
			$status = sanitize_key( (string) ( $fight['status'] ?? '' ) );
			$is_bye = 'bye' === $type || 'bye' === $status;
			$is_placeholder = 'placeholder' === $type || 'placeholder' === $status;
			$red_id = absint( $fight['red_entry_id'] ?? 0 );
			$blue_id = absint( $fight['blue_entry_id'] ?? 0 );
			$fight_no = absint( $fight['fight_no'] ?? $fight['preview_number'] ?? 0 );
			if ( $is_bye ) {
				$summary['bye_count']++;
			}
			if ( $is_placeholder ) {
				$summary['placeholder_count']++;
			}
			if ( '' === trim( (string) ( $fight['group_key'] ?? '' ) ) ) {
				$summary['fights_missing_group_key']++;
				self::push_limited( $items, self::item( 'fight_missing_group_key', sprintf( __( 'Combat brouillon #%d — group_key absent.', 'ufsc-licence-competition' ), $fight_no ), 'warning' ) );
			}
			if ( absint( $fight['category_id'] ?? 0 ) <= 0 ) {
				$summary['fights_missing_category_id']++;
				self::push_limited( $items, self::item( 'fight_missing_category_id', sprintf( __( 'Combat brouillon #%d — category_id absent.', 'ufsc-licence-competition' ), $fight_no ), 'blocked' ) );
			}
			if ( ! $is_bye && ! $is_placeholder && ( $red_id <= 0 || $blue_id <= 0 ) ) {
				$summary['fights_without_real_opponent']++;
				self::push_limited( $items, self::item( 'fight_without_real_opponent', sprintf( __( 'Combat brouillon #%d — adversaire réel manquant.', 'ufsc-licence-competition' ), $fight_no ), 'blocked' ) );
			}
		}

		return array( 'summary' => $summary, 'items' => $items );
	}

	private static function build_draft_cases_section( array &$result, int $competition_id, array $draft_cases ): void {
		$summary = $draft_cases['summary'];
		$status = 'ready';
		if ( (int) $summary['fights_missing_category_id'] > 0 || (int) $summary['fights_without_real_opponent'] > 0 ) {
			$status = 'blocked';
			self::add_error( $result, 'draft_payload_incomplete', __( 'Le brouillon contient des combats avec category_id absent ou adversaire réel manquant.', 'ufsc-licence-competition' ) );
		}
		if ( (int) $summary['groups_without_fight'] > 0 || (int) $summary['isolated_participants'] > 0 || (int) $summary['odd_groups'] > 0 || (int) $summary['fights_missing_group_key'] > 0 ) {
			if ( 'blocked' !== $status ) {
				$status = 'warning';
			}
			self::add_warning( $result, 'draft_special_cases_to_review', __( 'Le brouillon contient des cas particuliers à vérifier : participant seul, groupe impair, groupe sans combat ou group_key absent.', 'ufsc-licence-competition' ) );
		}
		if ( (int) $summary['bye_count'] > 0 ) {
			self::add_info( $result, 'draft_byes_present', sprintf( __( '%d BYE prévu(s) dans le brouillon.', 'ufsc-licence-competition' ), (int) $summary['bye_count'] ) );
		}

		self::add_section( $result, 'draft_cases', __( 'Brouillon — cas particuliers', 'ufsc-licence-competition' ), $status, $summary, $draft_cases['items'] );
	}

	private static function analyse_fights( int $competition_id, array $fights, FightRepository $repo ): array {
		$summary = array_fill_keys( array( 'total', 'scheduled', 'running', 'completed', 'locked', 'bye', 'placeholder', 'trashed', 'cancelled', 'sensitive', 'generation_blocking' ), 0 );
		$items = array();
		$blocking_statuses = array( 'scheduled', 'running', 'completed', 'locked', 'bye', 'placeholder' );
		foreach ( $fights as $fight ) {
			$summary['total']++;
			$status = $repo->get_effective_fight_status( $fight );
			if ( isset( $summary[ $status ] ) ) {
				$summary[ $status ]++;
			}
			if ( in_array( $status, $blocking_statuses, true ) || $repo->is_fight_sensitive( $fight ) ) {
				$summary['sensitive']++;
				$summary['generation_blocking']++;
				self::push_limited( $items, self::item( 'generation_blocking_fight', sprintf( __( 'Combat #%1$d — statut %2$s', 'ufsc-licence-competition' ), (int) ( $fight->fight_no ?? $fight->id ?? 0 ), $status ), 'blocked' ) );
			}
		}
		return array( 'summary' => $summary, 'items' => $items );
	}

	private static function build_fights_section( array &$result, int $competition_id, array $fight_state ): void {
		$summary = $fight_state['summary'];
		$status = 'ready';
		if ( (int) $summary['generation_blocking'] > 0 ) {
			$status = 'blocked';
			self::add_error( $result, 'sensitive_fights_exist', __( 'Des combats actifs ou déjà générés existent déjà : scheduled/running/completed/locked/BYE/placeholder bloquent une nouvelle génération globale.', 'ufsc-licence-competition' ) );
			self::add_action( $result, __( 'Consulter les combats existants', 'ufsc-licence-competition' ), 'high', 'fights', self::admin_url( self::PAGE_BOUTS, $competition_id ) );
			self::add_action( $result, __( 'Utiliser Actions sensibles pour une régénération encadrée', 'ufsc-licence-competition' ), 'high', 'sensitive_ops', self::admin_url( self::PAGE_SENSITIVE_OPS, $competition_id ) );
		} elseif ( (int) $summary['total'] > 0 ) {
			$status = 'warning';
			self::add_warning( $result, 'scheduled_fights_exist', __( 'Des combats non sensibles existent déjà : vérifiez le risque de doublon avant validation.', 'ufsc-licence-competition' ) );
			self::add_action( $result, __( 'Vérifier les combats déjà planifiés', 'ufsc-licence-competition' ), 'medium', 'fights', self::admin_url( self::PAGE_BOUTS, $competition_id ) );
		}
		self::add_section( $result, 'fights', __( 'Combats existants', 'ufsc-licence-competition' ), $status, $summary, $fight_state['items'] );
	}

	private static function finalize( array $result ): array {
		$result['blocking'] = ! empty( $result['errors'] );
		if ( $result['blocking'] ) {
			$result['summary']['status'] = 'blocked';
			$result['summary']['status_label'] = __( 'Bloquée', 'ufsc-licence-competition' );
		} elseif ( ! empty( $result['warnings'] ) ) {
			$result['summary']['status'] = 'warning';
			$result['summary']['status_label'] = __( 'À contrôler', 'ufsc-licence-competition' );
		} else {
			$result['summary']['status'] = 'ready';
			$result['summary']['status_label'] = __( 'Prête', 'ufsc-licence-competition' );
		}
		$penalty = min( 80, count( $result['errors'] ) * 25 + count( $result['warnings'] ) * 8 );
		$result['summary']['score'] = max( 0, 100 - $penalty );
		foreach ( $result['sections'] as $key => $section ) {
			if ( ! empty( $section['items_truncated'] ) ) {
				$result['sections'][ $key ]['items'][] = self::item( 'truncated', sprintf( __( '… et %d autre(s) problème(s).', 'ufsc-licence-competition' ), (int) $section['items_truncated'] ), 'info' );
			}
		}
		return $result;
	}

	private static function entry_status( $entry ): string {
		$status = class_exists( EntriesWorkflow::class ) ? EntriesWorkflow::normalize_status( (string) ( $entry->status ?? '' ) ) : sanitize_key( (string) ( $entry->status ?? '' ) );
		$map = array(
			'approved' => 'approved',
			'validated' => 'approved',
			'draft' => 'draft',
			'pending' => 'pending',
			'submitted' => 'pending',
			'awaiting' => 'pending',
			'refused' => 'refused',
			'rejected' => 'refused',
			'cancelled' => 'cancelled',
			'canceled' => 'cancelled',
		);
		return $map[ $status ] ?? $status;
	}

	private static function entry_birth( $entry ): string {
		return trim( (string) ( function_exists( 'ufsc_comp_get_entry_birth_date' ) ? ufsc_comp_get_entry_birth_date( $entry ) : ( $entry->birth_date ?? $entry->birthdate ?? $entry->date_naissance ?? '' ) ) );
	}

	private static function entry_value( $entry, array $keys, string $default ): string {
		foreach ( $keys as $key ) {
			if ( isset( $entry->{$key} ) && '' !== trim( (string) $entry->{$key} ) ) {
				return sanitize_text_field( (string) $entry->{$key} );
			}
		}
		return $default;
	}

	private static function entry_age_bucket( $entry ): string {
		$age_category = self::entry_value( $entry, array( 'age_category', 'age_cat', 'category_age' ), '' );
		if ( '' !== $age_category ) {
			return $age_category;
		}
		$birth = self::entry_birth( $entry );
		if ( '' === $birth ) {
			return __( 'Âge inconnu', 'ufsc-licence-competition' );
		}
		$age = function_exists( 'ufsc_comp_calculate_age' ) ? (int) ufsc_comp_calculate_age( $birth ) : 0;
		if ( $age <= 0 ) {
			return __( 'Âge inconnu', 'ufsc-licence-competition' );
		}
		if ( $age < 12 ) { return __( 'U12', 'ufsc-licence-competition' ); }
		if ( $age < 15 ) { return __( 'U15', 'ufsc-licence-competition' ); }
		if ( $age < 18 ) { return __( 'U18', 'ufsc-licence-competition' ); }
		return __( 'Senior', 'ufsc-licence-competition' );
	}

	private static function entry_label( $entry ): string {
		$first = trim( (string) ( $entry->first_name ?? $entry->firstname ?? $entry->prenom ?? '' ) );
		$last = trim( (string) ( $entry->last_name ?? $entry->lastname ?? $entry->nom ?? '' ) );
		$name = trim( $first . ' ' . $last );
		return $name ?: sprintf( __( 'Inscription #%d', 'ufsc-licence-competition' ), absint( $entry->id ?? 0 ) );
	}

	private static function recommended_format( int $eligible ): string {
		if ( $eligible <= 0 ) { return __( 'catégorie vide', 'ufsc-licence-competition' ); }
		if ( 1 === $eligible ) { return __( 'BYE / reclassement à contrôler', 'ufsc-licence-competition' ); }
		if ( 2 === $eligible ) { return __( 'combat simple', 'ufsc-licence-competition' ); }
		if ( 3 === $eligible ) { return __( 'poule', 'ufsc-licence-competition' ); }
		if ( 4 === $eligible ) { return __( 'tableau ou poule', 'ufsc-licence-competition' ); }
		if ( 5 === $eligible ) { return __( 'tableau avec BYE ou poule', 'ufsc-licence-competition' ); }
		if ( 6 === $eligible ) { return __( 'poule ou tableau', 'ufsc-licence-competition' ); }
		return __( 'tableau recommandé', 'ufsc-licence-competition' );
	}

	private static function add_section( array &$result, string $key, string $title, string $status, array $summary, array $items ): void {
		$visible = array_slice( $items, 0, self::PROBLEM_LIMIT );
		$result['sections'][ sanitize_key( $key ) ] = array(
			'key' => sanitize_key( $key ),
			'title' => $title,
			'status' => sanitize_key( $status ),
			'summary' => $summary,
			'items' => $visible,
			'items_truncated' => max( 0, count( $items ) - count( $visible ) ),
		);
	}

	private static function add_error( array &$result, string $code, string $message ): void {
		$result['errors'][] = array( 'code' => sanitize_key( $code ), 'message' => $message );
	}

	private static function add_warning( array &$result, string $code, string $message ): void {
		$result['warnings'][] = array( 'code' => sanitize_key( $code ), 'message' => $message );
	}

	private static function add_info( array &$result, string $code, string $message ): void {
		$result['infos'][] = array( 'code' => sanitize_key( $code ), 'message' => $message );
	}

	private static function add_action( array &$result, string $label, string $priority, string $target, string $url = '' ): void {
		$result['actions'][] = array(
			'label' => $label,
			'priority' => sanitize_key( $priority ),
			'target' => sanitize_key( $target ),
			'url' => esc_url_raw( $url ),
		);
	}

	private static function item( string $code, string $message, string $status ): array {
		return array( 'code' => sanitize_key( $code ), 'message' => $message, 'status' => sanitize_key( $status ) );
	}

	private static function push_limited( array &$items, array $item ): void {
		$items[] = $item;
	}

	private static function status_label( string $status ): string {
		$labels = array(
			'ready' => __( 'Prête', 'ufsc-licence-competition' ),
			'incomplete' => __( 'À compléter', 'ufsc-licence-competition' ),
			'locked' => __( 'Verrouillée', 'ufsc-licence-competition' ),
			'archived' => __( 'Archivée', 'ufsc-licence-competition' ),
			'blocked' => __( 'Bloquée', 'ufsc-licence-competition' ),
			'warning' => __( 'À contrôler', 'ufsc-licence-competition' ),
		);
		return $labels[ $status ] ?? $status;
	}

	private static function admin_url( string $page, int $competition_id = 0 ): string {
		$args = array( 'page' => $page );
		if ( $competition_id > 0 ) {
			$args['competition_id'] = $competition_id;
			$args['ufsc_competition_id'] = $competition_id;
		}
		return admin_url( 'admin.php?' . http_build_query( $args ) );
	}
}
