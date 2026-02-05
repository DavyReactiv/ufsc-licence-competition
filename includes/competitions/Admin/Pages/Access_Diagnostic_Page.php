<?php

namespace UFSC\Competitions\Admin\Pages;

use UFSC\Competitions\Access\CompetitionAccess;
use UFSC\Competitions\Capabilities;
use UFSC\Competitions\Front\Repositories\CompetitionReadRepository;
use UFSC\Competitions\Repositories\ClubRepository;
use UFSC\Competitions\Services\DisciplineRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Access_Diagnostic_Page {
	public function render(): void {
		if ( class_exists( Capabilities::class ) && ! Capabilities::user_can_manage() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ) );
		}

		$competition_id = 0;
		$club_id = 0;
		$submitted = false;
		$errors = array();

		if ( isset( $_POST['ufsc_access_diagnostic_submit'] ) ) {
			check_admin_referer( 'ufsc_competitions_access_diagnostic' );
			$submitted = true;
			$competition_id = isset( $_POST['competition_id'] ) ? absint( wp_unslash( $_POST['competition_id'] ) ) : 0;
			$club_id = isset( $_POST['club_id'] ) ? absint( wp_unslash( $_POST['club_id'] ) ) : 0;

			if ( ! $competition_id ) {
				$errors[] = __( 'Veuillez sélectionner une compétition.', 'ufsc-licence-competition' );
			}
			if ( ! $club_id ) {
				$errors[] = __( 'Veuillez sélectionner un club.', 'ufsc-licence-competition' );
			}
		}

		$competition_repo = new CompetitionReadRepository();
		$competitions = $competition_repo->list( array( 'view' => 'all' ), 200, 0 );

		$club_repo = new ClubRepository();
		$selected_club = $club_id ? $club_repo->get( $club_id ) : null;
		$selected_competition = $competition_id ? $competition_repo->get( $competition_id ) : null;

		$access = new CompetitionAccess();
		$settings = array();
		$view_result = null;
		$register_result = null;

		if ( $submitted && empty( $errors ) ) {
			if ( ! $selected_competition ) {
				$errors[] = __( 'Compétition introuvable.', 'ufsc-licence-competition' );
			}
			if ( ! $selected_club ) {
				$errors[] = __( 'Club introuvable.', 'ufsc-licence-competition' );
			}

			if ( empty( $errors ) ) {
				$settings = $access->get_access_settings( $competition_id );
				$user_id = (int) get_current_user_id();
				$view_result = $access->can_view_competition_for_club( $competition_id, $club_id, $user_id );
				$register_result = $access->can_register_for_club( $competition_id, $club_id, $user_id );
			}
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Diagnostic Accès', 'ufsc-licence-competition' ) . '</h1>';

		if ( $errors ) {
			foreach ( $errors as $error ) {
				echo '<div class="notice notice-error"><p>' . esc_html( $error ) . '</p></div>';
			}
		}

		$this->render_form( $competitions, $competition_id, $selected_club );

		if ( $submitted && empty( $errors ) && $selected_competition && $selected_club ) {
			$this->render_results( $selected_competition, $selected_club, $settings, $view_result, $register_result, $club_repo, $access );
		}

		echo '</div>';
	}

	private function render_form( array $competitions, int $competition_id, $selected_club ): void {
		$club_label = $selected_club ? ( new ClubRepository() )->get_region_label( $selected_club ) : '';

		echo '<form method="post" class="ufsc-access-diagnostic-form">';
		wp_nonce_field( 'ufsc_competitions_access_diagnostic' );
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="ufsc_access_competition">' . esc_html__( 'Compétition', 'ufsc-licence-competition' ) . '</label></th><td>';
		echo '<select name="competition_id" id="ufsc_access_competition" class="regular-text">';
		echo '<option value="">' . esc_html__( 'Sélectionner…', 'ufsc-licence-competition' ) . '</option>';
		foreach ( $competitions as $competition ) {
			$id = (int) ( $competition->id ?? 0 );
			if ( ! $id ) {
				continue;
			}
			$label_bits = array();
			$label_bits[] = (string) ( $competition->name ?? '' );
			if ( ! empty( $competition->season ) ) {
				$label_bits[] = (string) $competition->season;
			}
			if ( ! empty( $competition->status ) ) {
				$label_bits[] = (string) $competition->status;
			}
			$label = trim( implode( ' · ', array_filter( $label_bits ) ) );
			echo '<option value="' . esc_attr( (string) $id ) . '"' . selected( $competition_id, $id, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="ufsc_access_club">' . esc_html__( 'Club', 'ufsc-licence-competition' ) . '</label></th><td>';
		echo '<input type="text" id="ufsc_access_club_search" class="regular-text ufsc-club-search" data-row-index="0" data-ajax-action="ufsc_lc_search_clubs" data-nonce-key="admin" placeholder="' . esc_attr__( 'Rechercher un club…', 'ufsc-licence-competition' ) . '" />';
		echo '<select name="club_id" id="ufsc_access_club" class="regular-text ufsc-club-select" data-row-index="0">';
		if ( $selected_club && $club_label ) {
			echo '<option value="' . esc_attr( (string) ( $selected_club->id ?? 0 ) ) . '" selected>' . esc_html( $club_label ) . '</option>';
		}
		echo '</select>';
		echo '</td></tr>';
		echo '</tbody></table>';

		submit_button( __( 'Tester', 'ufsc-licence-competition' ), 'primary', 'ufsc_access_diagnostic_submit' );
		echo '</form>';
	}

	private function render_results( $competition, $club, array $settings, $view_result, $register_result, ClubRepository $club_repo, CompetitionAccess $access ): void {
		$club_region_raw = $club ? (string) ( $club->region ?? '' ) : '';
		$club_region = function_exists( 'ufsc_normalize_region_key' ) ? ufsc_normalize_region_key( $club_region_raw ) : $club_region_raw;
		$club_region_missing = '' === trim( $club_region_raw );
		$club_disciplines = function_exists( 'ufsc_extract_club_disciplines' ) ? ufsc_extract_club_disciplines( $club ) : array();

		$allowed_regions = $settings['allowed_regions_labels'] ?? array();
		$allowed_regions_keys = $settings['allowed_regions_keys'] ?? array();
		$allowed_disciplines = $settings['allowed_disciplines'] ?? array();
		$allowed_clubs = $settings['allowed_club_ids'] ?? array();

		$allowed_disciplines_labels = array();
		foreach ( $allowed_disciplines as $discipline ) {
			$key = sanitize_key( (string) $discipline );
			$label = DisciplineRegistry::get_label( $key );
			$allowed_disciplines_labels[] = $label !== $key ? $label . ' (' . $key . ')' : $label;
		}

		$allowed_club_labels = array();
		foreach ( $allowed_clubs as $club_id ) {
			$club_id = absint( $club_id );
			if ( ! $club_id ) {
				continue;
			}
			$club_row = $club_repo->get( $club_id );
			$label = $club_row ? $club_repo->get_region_label( $club_row ) : '';
			$allowed_club_labels[] = $label ? $club_id . ' – ' . $label : (string) $club_id;
		}

		$view_message = $view_result && $view_result->allowed
			? __( 'Accès autorisé.', 'ufsc-licence-competition' )
			: $access->get_denied_message( $view_result );
		$register_message = $register_result && $register_result->allowed
			? __( 'Accès autorisé.', 'ufsc-licence-competition' )
			: $access->get_denied_message( $register_result );

		echo '<hr />';
		echo '<h2>' . esc_html__( 'Résultats', 'ufsc-licence-competition' ) . '</h2>';
		if ( $club_region_missing ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Région manquante', 'ufsc-licence-competition' ) . '</p></div>';
		}

		echo '<h3>' . esc_html__( 'Club', 'ufsc-licence-competition' ) . '</h3>';
		echo '<table class="widefat striped"><tbody>';
		echo '<tr><th>' . esc_html__( 'ID', 'ufsc-licence-competition' ) . '</th><td>' . esc_html( (string) ( $club->id ?? '' ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Nom', 'ufsc-licence-competition' ) . '</th><td>' . esc_html( (string) ( $club->nom ?? '' ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Région (source)', 'ufsc-licence-competition' ) . '</th><td>' . esc_html( '' === $club_region_raw ? '—' : $club_region_raw ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Région (clé)', 'ufsc-licence-competition' ) . '</th><td>' . esc_html( '' === $club_region ? '—' : $club_region ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Source région (champ)', 'ufsc-licence-competition' ) . '</th><td>' . esc_html__( 'region', 'ufsc-licence-competition' ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Disciplines', 'ufsc-licence-competition' ) . '</th><td>' . esc_html( $club_disciplines ? implode( ' / ', $club_disciplines ) : '—' ) . '</td></tr>';
		echo '</tbody></table>';

		$current_context = function_exists( 'ufsc_current_club_context' ) ? ufsc_current_club_context( (int) get_current_user_id() ) : array();
		if ( $current_context ) {
			$context_affiliated = ! empty( $current_context['affiliated'] ) ? __( 'Oui', 'ufsc-licence-competition' ) : __( 'Non', 'ufsc-licence-competition' );
			echo '<h3>' . esc_html__( 'Contexte club (utilisateur connecté)', 'ufsc-licence-competition' ) . '</h3>';
			echo '<table class="widefat striped"><tbody>';
			echo '<tr><th>' . esc_html__( 'club_id', 'ufsc-licence-competition' ) . '</th><td>' . esc_html( (string) ( $current_context['club_id'] ?? '' ) ) . '</td></tr>';
			echo '<tr><th>' . esc_html__( 'Nom', 'ufsc-licence-competition' ) . '</th><td>' . esc_html( (string) ( $current_context['club_name'] ?? '' ) ) . '</td></tr>';
			echo '<tr><th>' . esc_html__( 'Région', 'ufsc-licence-competition' ) . '</th><td>' . esc_html( (string) ( $current_context['region'] ?? '' ) ) . '</td></tr>';
			echo '<tr><th>' . esc_html__( 'Affilié', 'ufsc-licence-competition' ) . '</th><td>' . esc_html( $context_affiliated ) . '</td></tr>';
			echo '<tr><th>' . esc_html__( 'Source', 'ufsc-licence-competition' ) . '</th><td>' . esc_html( (string) ( $current_context['source'] ?? '' ) ) . '</td></tr>';
			echo '<tr><th>' . esc_html__( 'Meta key source', 'ufsc-licence-competition' ) . '</th><td>' . esc_html( (string) ( $current_context['source_meta_key'] ?? '' ) ) . '</td></tr>';
			echo '</tbody></table>';
		}

		echo '<h3>' . esc_html__( 'Règles compétition', 'ufsc-licence-competition' ) . '</h3>';
		echo '<table class="widefat striped"><tbody>';
		echo '<tr><th>' . esc_html__( 'Mode', 'ufsc-licence-competition' ) . '</th><td>' . esc_html( (string) ( $settings['access_mode'] ?? '' ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Régions autorisées (labels)', 'ufsc-licence-competition' ) . '</th><td>' . esc_html( $allowed_regions ? implode( ' / ', $allowed_regions ) : '—' ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Régions autorisées (clés)', 'ufsc-licence-competition' ) . '</th><td>' . esc_html( $allowed_regions_keys ? implode( ' / ', $allowed_regions_keys ) : '—' ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Disciplines autorisées', 'ufsc-licence-competition' ) . '</th><td>' . esc_html( $allowed_disciplines_labels ? implode( ' / ', $allowed_disciplines_labels ) : '—' ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Clubs autorisés', 'ufsc-licence-competition' ) . '</th><td>' . esc_html( $allowed_club_labels ? implode( ' / ', $allowed_club_labels ) : '—' ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Affiliation requise', 'ufsc-licence-competition' ) . '</th><td>' . esc_html( ! empty( $settings['require_affiliated'] ) ? __( 'Oui', 'ufsc-licence-competition' ) : __( 'Non', 'ufsc-licence-competition' ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Accès public (lecture)', 'ufsc-licence-competition' ) . '</th><td>' . esc_html( ! empty( $settings['public_read'] ) ? __( 'Oui', 'ufsc-licence-competition' ) : __( 'Non', 'ufsc-licence-competition' ) ) . '</td></tr>';
		echo '</tbody></table>';

		echo '<h3>' . esc_html__( 'Résultats accès', 'ufsc-licence-competition' ) . '</h3>';
		echo '<table class="widefat striped"><tbody>';
		echo '<tr><th>' . esc_html__( 'can_view', 'ufsc-licence-competition' ) . '</th><td>' . esc_html( $view_result && $view_result->allowed ? __( 'Oui', 'ufsc-licence-competition' ) : __( 'Non', 'ufsc-licence-competition' ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'reason_code', 'ufsc-licence-competition' ) . '</th><td>' . esc_html( $view_result ? (string) $view_result->reason_code : '' ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Message', 'ufsc-licence-competition' ) . '</th><td>' . esc_html( $view_message ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'can_register', 'ufsc-licence-competition' ) . '</th><td>' . esc_html( $register_result && $register_result->allowed ? __( 'Oui', 'ufsc-licence-competition' ) : __( 'Non', 'ufsc-licence-competition' ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'reason_code', 'ufsc-licence-competition' ) . '</th><td>' . esc_html( $register_result ? (string) $register_result->reason_code : '' ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Message', 'ufsc-licence-competition' ) . '</th><td>' . esc_html( $register_message ) . '</td></tr>';
		echo '</tbody></table>';
	}
}
