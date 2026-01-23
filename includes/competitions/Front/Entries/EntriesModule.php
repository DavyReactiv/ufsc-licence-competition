<?php

namespace UFSC\Competitions\Front\Entries;

use UFSC\Competitions\Front\Access\ClubAccess;
use UFSC\Competitions\Front\Repositories\CompetitionReadRepository;
use UFSC\Competitions\Front\Repositories\EntryFrontRepository;
use UFSC\Competitions\Repositories\CategoryRepository;
use UFSC\Competitions\Services\CategoryAssigner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EntriesModule {
	public static function register(): void {
		add_action( 'ufsc_competitions_front_registration_box', array( __CLASS__, 'render_notice' ), 5, 1 );
		add_action( 'ufsc_competitions_front_registration_box', array( __CLASS__, 'render' ), 10, 1 );

		add_action( 'admin_post_ufsc_competitions_entry_create', array( EntryActions::class, 'handle_create' ) );
		add_action( 'admin_post_ufsc_competitions_entry_update', array( EntryActions::class, 'handle_update' ) );
		add_action( 'admin_post_ufsc_competitions_entry_delete', array( EntryActions::class, 'handle_delete' ) );
		add_action( 'admin_post_ufsc_entry_submit', array( EntryActions::class, 'handle_submit' ) );
		add_action( 'admin_post_ufsc_entry_withdraw', array( EntryActions::class, 'handle_withdraw' ) );
		add_action( 'admin_post_ufsc_entry_cancel', array( EntryActions::class, 'handle_cancel' ) );
		add_action( 'admin_post_ufsc_entry_admin_validate', array( EntryActions::class, 'handle_admin_validate' ) );
		add_action( 'admin_post_ufsc_entry_admin_reject', array( EntryActions::class, 'handle_admin_reject' ) );
		add_action( 'admin_post_ufsc_entry_admin_reopen', array( EntryActions::class, 'handle_admin_reopen' ) );

		add_action( 'admin_post_nopriv_ufsc_competitions_entry_create', array( EntryActions::class, 'handle_not_logged_in' ) );
		add_action( 'admin_post_nopriv_ufsc_competitions_entry_update', array( EntryActions::class, 'handle_not_logged_in' ) );
		add_action( 'admin_post_nopriv_ufsc_competitions_entry_delete', array( EntryActions::class, 'handle_not_logged_in' ) );
		add_action( 'admin_post_nopriv_ufsc_entry_submit', array( EntryActions::class, 'handle_not_logged_in' ) );
		add_action( 'admin_post_nopriv_ufsc_entry_withdraw', array( EntryActions::class, 'handle_not_logged_in' ) );
		add_action( 'admin_post_nopriv_ufsc_entry_cancel', array( EntryActions::class, 'handle_not_logged_in' ) );
	}

	public static function render( $competition ): void {
		if ( ! $competition ) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			echo wp_kses_post( '<p>' . esc_html__( 'Vous devez être connecté pour vous inscrire.', 'ufsc-licence-competition' ) . '</p>' );
			return;
		}

		$club_access = new ClubAccess();
		$club_id = $club_access->get_club_id_for_user( get_current_user_id() );

		$entries = array();
		$editing_entry = null;
		$license_results = array();
		$selected_license = null;
		$license_term = isset( $_GET['ufsc_license_term'] ) ? sanitize_text_field( wp_unslash( $_GET['ufsc_license_term'] ) ) : '';
		$license_id = isset( $_GET['ufsc_license_id'] ) ? absint( $_GET['ufsc_license_id'] ) : 0;
		$repo = null;

		if ( $club_id ) {
			$repo = new EntryFrontRepository();
			$entries = $repo->list_by_competition_and_club( (int) $competition->id, (int) $club_id );
			$editing_entry = self::resolve_editing_entry( $entries );

			$license_results = self::get_license_search_results( $license_term, $club_id, $repo );
			if ( $license_id ) {
				$license_data = apply_filters( 'ufsc_competitions_front_license_by_id', null, $license_id, $club_id );
				if ( is_array( $license_data ) ) {
					$selected_license = $repo->normalize_license_result( $license_data );
				}
			}
		}

		$registration_open = self::is_registration_open( $competition, (int) $club_id );

		$prefill = array();
		if ( $selected_license && ! $editing_entry ) {
			$prefill = array(
				'first_name' => $selected_license['first_name'] ?? '',
				'last_name' => $selected_license['last_name'] ?? '',
				'birth_date' => $selected_license['birthdate'] ?? '',
				'sex' => $selected_license['sex'] ?? '',
			);
		}

		if ( ! $editing_entry && ! empty( $prefill['birth_date'] ) && empty( $prefill['category'] ) ) {
			$prefill['category'] = self::get_category_from_birthdate( $prefill['birth_date'], $prefill, $competition );
		}

		echo EntryFormRenderer::render(
			array(
				'competition' => $competition,
				'club_id' => $club_id,
				'entries' => $entries,
				'editing_entry' => $editing_entry,
				'registration_open' => $registration_open,
				'license_results' => $license_results,
				'selected_license' => $selected_license,
				'license_term' => $license_term,
				'license_id' => $license_id,
				'prefill' => $prefill,
				'entry_repo' => $repo,
			)
		);
	}

	public static function render_notice( $competition ): void {
		if ( ! $competition ) {
			return;
		}

		$notice = isset( $_GET['ufsc_notice'] ) ? sanitize_key( wp_unslash( $_GET['ufsc_notice'] ) ) : '';
		if ( '' === $notice ) {
			return;
		}

		$notice_html = EntryFormRenderer::render_notice( $notice );
		if ( '' === $notice_html ) {
			return;
		}

		echo wp_kses_post( $notice_html );
	}

	public static function get_fields_schema( $competition ): array {
		$schema = array(
			array(
				'name' => 'first_name',
				'label' => __( 'Prénom', 'ufsc-licence-competition' ),
				'type' => 'text',
				'required' => true,
				'columns' => array( 'first_name', 'firstname', 'prenom' ),
			),
			array(
				'name' => 'last_name',
				'label' => __( 'Nom', 'ufsc-licence-competition' ),
				'type' => 'text',
				'required' => true,
				'columns' => array( 'last_name', 'lastname', 'nom' ),
			),
			array(
				'name' => 'birth_date',
				'label' => __( 'Date de naissance', 'ufsc-licence-competition' ),
				'type' => 'date',
				'required' => true,
				'placeholder' => 'YYYY-MM-DD',
				'columns' => array( 'birth_date', 'birthdate', 'date_of_birth', 'dob' ),
			),
			array(
				'name' => 'sex',
				'label' => __( 'Sexe', 'ufsc-licence-competition' ),
				'type' => 'select',
				'required' => false,
				'options' => array(
					'm' => __( 'Homme', 'ufsc-licence-competition' ),
					'f' => __( 'Femme', 'ufsc-licence-competition' ),
					'x' => __( 'Autre', 'ufsc-licence-competition' ),
				),
				'columns' => array( 'sex', 'gender' ),
			),
			array(
				'name' => 'weight',
				'label' => __( 'Poids', 'ufsc-licence-competition' ),
				'type' => 'number',
				'required' => false,
				'placeholder' => '0.0',
				'columns' => array( 'weight', 'weight_kg', 'poids' ),
			),
			array(
				'name' => 'category',
				'label' => __( 'Catégorie', 'ufsc-licence-competition' ),
				'type' => 'text',
				'required' => false,
				'columns' => array( 'category', 'category_name' ),
			),
			array(
				'name' => 'level',
				'label' => __( 'Niveau / Classe', 'ufsc-licence-competition' ),
				'type' => 'text',
				'required' => false,
				'columns' => array( 'level', 'class', 'classe' ),
			),
		);

		return apply_filters( 'ufsc_competitions_entry_fields_schema', $schema, $competition );
	}

	public static function get_competition( int $competition_id ) {
		$repo = new CompetitionReadRepository();
		return $repo->get( $competition_id );
	}

	public static function is_registration_open( $competition, int $club_id = 0 ): bool {
		$is_open = (bool) $competition;

		if ( ! $competition || empty( $competition->status ) || 'open' !== (string) $competition->status ) {
			$is_open = false;
		}

		if ( $is_open && ! empty( $competition->registration_deadline ) ) {
			$deadline_raw = sanitize_text_field( (string) $competition->registration_deadline );
			$timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( wp_timezone_string() ?: 'UTC' );
			$deadline = date_create_from_format( 'Y-m-d H:i:s', $deadline_raw . ' 23:59:59', $timezone );
			if ( $deadline instanceof \DateTimeInterface ) {
				$deadline_ts = $deadline->getTimestamp();
				if ( current_time( 'timestamp' ) > $deadline_ts ) {
					$is_open = false;
				}
			}
		}

		return (bool) apply_filters( 'ufsc_competitions_front_registration_is_open', $is_open, $competition, $club_id );
	}

	private static function get_license_search_results( string $term, int $club_id, EntryFrontRepository $repo ): array {
		$results = apply_filters( 'ufsc_competitions_front_license_search_results', array(), $term, $club_id );
		if ( ! is_array( $results ) ) {
			$results = array();
		}

		return $repo->normalize_license_results( $results, 20 );
	}

	public static function get_category_from_birthdate( string $birthdate, array $license, $competition ): string {
		$category = '';

		if ( class_exists( CategoryAssigner::class ) ) {
			$repo = new CategoryRepository();
			$categories = $repo->list(
				array( 'competition_id' => (int) ( $competition->id ?? 0 ) ),
				200,
				0
			);

			if ( is_array( $categories ) && ! empty( $categories ) ) {
				$normalized = array();
				foreach ( $categories as $category_row ) {
					$normalized[] = array(
						'id' => (int) ( $category_row->id ?? 0 ),
						'name' => sanitize_text_field( $category_row->name ?? '' ),
						'age_min' => isset( $category_row->age_min ) ? (int) $category_row->age_min : null,
						'age_max' => isset( $category_row->age_max ) ? (int) $category_row->age_max : null,
						'weight_min' => isset( $category_row->weight_min ) ? (float) $category_row->weight_min : null,
						'weight_max' => isset( $category_row->weight_max ) ? (float) $category_row->weight_max : null,
						'sex' => sanitize_text_field( $category_row->sex ?? '' ),
						'level' => sanitize_text_field( $category_row->level ?? '' ),
					);
				}

				$assigner = new CategoryAssigner();
				$match = $assigner->match_category(
					$normalized,
					array(
						'birth_date' => $birthdate,
						'sex' => $license['sex'] ?? '',
						'weight' => $license['weight'] ?? null,
						'level' => $license['level'] ?? '',
					),
					array(
						'age_reference' => sanitize_text_field( $competition->age_reference ?? '12-31' ),
					)
				);

				if ( $match && ! empty( $match['name'] ) ) {
					$category = sanitize_text_field( $match['name'] );
				}
			}
		}

		return (string) apply_filters( 'ufsc_competitions_front_category_from_birthdate', $category, $birthdate, $competition );
	}

	private static function resolve_editing_entry( array $entries ) {
		$edit_id = isset( $_GET['ufsc_entry_edit'] ) ? absint( $_GET['ufsc_entry_edit'] ) : 0;
		if ( ! $edit_id ) {
			return null;
		}

		foreach ( $entries as $entry ) {
			if ( (int) ( $entry->id ?? 0 ) === $edit_id ) {
				return $entry;
			}
		}

		return null;
	}
}
