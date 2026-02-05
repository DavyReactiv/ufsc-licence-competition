<?php

namespace UFSC\Competitions\Admin\Pages;

use UFSC\Competitions\Admin\Menu;
use UFSC\Competitions\Access\CompetitionAccess;
use UFSC\Competitions\Capabilities;
use UFSC\Competitions\Front\Repositories\CompetitionReadRepository;
use UFSC\Competitions\Repositories\ClubRepository;
use UFSC\Competitions\Services\DisciplineRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Access_Diagnostic_Page {
	private const RESULT_TRANSIENT_TTL = 300;
	private const NOTICE_TRANSIENT_TTL = 120;

	public static function register_actions(): void {
		add_action( 'admin_post_ufsc_competitions_access_diagnostic_test', array( __CLASS__, 'handle_test' ) );
	}

	public static function handle_test(): void {
		$can_manage = class_exists( Capabilities::class ) ? Capabilities::user_can_manage() : current_user_can( 'manage_options' );
		if ( ! $can_manage ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		$competition_id = isset( $_POST['competition_id'] ) ? absint( wp_unslash( $_POST['competition_id'] ) ) : 0;
		$club_id = isset( $_POST['club_id'] ) ? absint( wp_unslash( $_POST['club_id'] ) ) : 0;

		$notices = array();
		if ( ! wp_verify_nonce( isset( $_POST['_wpnonce'] ) ? wp_unslash( $_POST['_wpnonce'] ) : '', 'ufsc_comp_access_diag' ) ) {
			$notices[] = array(
				'type' => 'error',
				'message' => __( 'Votre session a expiré. Merci de recharger la page.', 'ufsc-licence-competition' ),
			);
		}

		if ( ! $competition_id ) {
			$notices[] = array(
				'type' => 'warning',
				'message' => __( 'Veuillez sélectionner une compétition.', 'ufsc-licence-competition' ),
			);
		}
		if ( ! $club_id ) {
			$notices[] = array(
				'type' => 'warning',
				'message' => __( 'Veuillez sélectionner un club.', 'ufsc-licence-competition' ),
			);
		}

		$user_id = (int) get_current_user_id();
		if ( function_exists( 'ufsc_comp_log' ) ) {
			ufsc_comp_log(
				'access_diag_inputs',
				array(
					'competition_id' => $competition_id,
					'club_id' => $club_id,
					'user_id' => $user_id,
				)
			);
		}

		if ( $notices ) {
			self::store_notices( $user_id, $notices );
			self::redirect_back( $competition_id, $club_id );
		}

		$competition_repo = new CompetitionReadRepository();
		$club_repo = new ClubRepository();
		$competition = $competition_repo->get( $competition_id );
		$club = $club_repo->get( $club_id );

		if ( ! $competition ) {
			$notices[] = array(
				'type' => 'error',
				'message' => __( 'Compétition introuvable.', 'ufsc-licence-competition' ),
			);
		}
		if ( ! $club ) {
			$notices[] = array(
				'type' => 'error',
				'message' => __( 'Club introuvable.', 'ufsc-licence-competition' ),
			);
		}

		if ( $notices ) {
			self::store_notices( $user_id, $notices );
			self::redirect_back( $competition_id, $club_id );
		}

		$access = new CompetitionAccess();
		$settings = $access->get_access_settings( $competition_id );
		$view_result = $access->can_view_competition_for_club( $competition_id, $club_id, $user_id );
		$register_result = $access->can_register_for_club( $competition_id, $club_id, $user_id );
		$engaged_result = $access->can_view_engaged_list( $competition_id, $club_id, $user_id );

		$club_region_raw = $club ? (string) ( $club->region ?? '' ) : '';
		$club_region_key = function_exists( 'ufsc_normalize_region_key' ) ? ufsc_normalize_region_key( $club_region_raw ) : $club_region_raw;

		if ( function_exists( 'ufsc_comp_log' ) ) {
			ufsc_comp_log(
				'access_diag_club',
				array(
					'club_id' => (int) ( $club->id ?? 0 ),
					'club_name' => (string) ( $club->nom ?? '' ),
					'region' => $club_region_raw,
					'region_key' => $club_region_key,
				)
			);
			ufsc_comp_log(
				'access_diag_rules',
				array(
					'access_mode' => $settings['access_mode'] ?? '',
					'allowed_regions_keys' => $settings['allowed_regions_keys'] ?? array(),
					'allowed_club_ids' => $settings['allowed_club_ids'] ?? array(),
					'allowed_disciplines' => $settings['allowed_disciplines'] ?? array(),
					'require_affiliated' => ! empty( $settings['require_affiliated'] ),
					'public_read' => ! empty( $settings['public_read'] ),
				)
			);
			ufsc_comp_log(
				'access_diag_result',
				array(
					'view_allowed' => (bool) ( $view_result->allowed ?? false ),
					'view_reason' => (string) ( $view_result->reason_code ?? '' ),
					'register_allowed' => (bool) ( $register_result->allowed ?? false ),
					'register_reason' => (string) ( $register_result->reason_code ?? '' ),
					'can_view_details' => (bool) ( $view_result->can_view_details ?? false ),
					'can_view_engaged' => (bool) ( $engaged_result->can_view_engaged ?? false ),
					'can_register' => (bool) ( $register_result->can_register ?? false ),
				)
			);
		}

		$result_payload = array(
			'competition_id' => $competition_id,
			'club_id' => $club_id,
			'competition' => $competition,
			'club' => $club,
			'settings' => $settings,
			'view_result' => $view_result,
			'register_result' => $register_result,
			'engaged_result' => $engaged_result,
		);

		set_transient( self::get_result_transient_key( $user_id ), $result_payload, self::RESULT_TRANSIENT_TTL );
		self::redirect_back( $competition_id, $club_id, array( 'ufsc_access_diag_result' => 1 ) );
	}

	public function render(): void {
		$can_manage = class_exists( Capabilities::class ) ? Capabilities::user_can_manage() : current_user_can( 'manage_options' );
		if ( ! $can_manage ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ) );
		}

		$competition_id = isset( $_GET['competition_id'] ) ? absint( wp_unslash( $_GET['competition_id'] ) ) : 0;
		$club_id = isset( $_GET['club_id'] ) ? absint( wp_unslash( $_GET['club_id'] ) ) : 0;
		$user_id = (int) get_current_user_id();
		$notices = self::consume_transient( self::get_notice_transient_key( $user_id ) );
		$result_payload = null;
		if ( isset( $_GET['ufsc_access_diag_result'] ) ) {
			$result_payload = self::consume_transient( self::get_result_transient_key( $user_id ) );
		}

		$competition_repo = new CompetitionReadRepository();
		$competitions = $competition_repo->list( array( 'view' => 'all' ), 200, 0 );

		$club_repo = new ClubRepository();
		$selected_club = $result_payload['club'] ?? ( $club_id ? $club_repo->get( $club_id ) : null );
		$selected_competition = $result_payload['competition'] ?? ( $competition_id ? $competition_repo->get( $competition_id ) : null );
		$settings = $result_payload['settings'] ?? array();
		$view_result = $result_payload['view_result'] ?? null;
		$register_result = $result_payload['register_result'] ?? null;
		$engaged_result = $result_payload['engaged_result'] ?? null;
		$access = new CompetitionAccess();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Diagnostic Accès', 'ufsc-licence-competition' ) . '</h1>';

		if ( $notices ) {
			foreach ( $notices as $notice ) {
				$type = $notice['type'] ?? 'info';
				$message = $notice['message'] ?? '';
				if ( ! $message ) {
					continue;
				}
				printf(
					'<div class="notice notice-%s"><p>%s</p></div>',
					esc_attr( $type ),
					esc_html( $message )
				);
			}
		}

		$this->render_form( $competitions, $competition_id, $selected_club );

		if ( $result_payload && $selected_competition && $selected_club ) {
			$this->render_results( $selected_competition, $selected_club, $settings, $view_result, $register_result, $engaged_result, $club_repo, $access );
		}

		echo '</div>';
	}

	private function render_form( array $competitions, int $competition_id, $selected_club ): void {
		$club_label = $selected_club ? ( new ClubRepository() )->get_region_label( $selected_club ) : '';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ufsc-access-diagnostic-form">';
		echo '<input type="hidden" name="action" value="ufsc_competitions_access_diagnostic_test" />';
		wp_nonce_field( 'ufsc_comp_access_diag' );
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

		submit_button( __( 'Tester', 'ufsc-licence-competition' ), 'primary', 'ufsc_access_diag_submit', false, array( 'value' => '1' ) );
		echo '</form>';
	}

	private function render_results( $competition, $club, array $settings, $view_result, $register_result, $engaged_result, ClubRepository $club_repo, CompetitionAccess $access ): void {
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
		$engaged_message = $engaged_result && $engaged_result->allowed
			? __( 'Accès autorisé.', 'ufsc-licence-competition' )
			: $access->get_denied_message( $engaged_result );

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
		echo '<tr><th>' . esc_html__( 'can_view_details', 'ufsc-licence-competition' ) . '</th><td>' . esc_html( $view_result && $view_result->can_view_details ? __( 'Oui', 'ufsc-licence-competition' ) : __( 'Non', 'ufsc-licence-competition' ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'can_view_engaged', 'ufsc-licence-competition' ) . '</th><td>' . esc_html( $engaged_result && $engaged_result->can_view_engaged ? __( 'Oui', 'ufsc-licence-competition' ) : __( 'Non', 'ufsc-licence-competition' ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'reason_code', 'ufsc-licence-competition' ) . '</th><td>' . esc_html( $view_result ? (string) $view_result->reason_code : '' ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Message utilisateur (lecture)', 'ufsc-licence-competition' ) . '</th><td>' . esc_html( $view_message ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'can_register', 'ufsc-licence-competition' ) . '</th><td>' . esc_html( $register_result && $register_result->allowed ? __( 'Oui', 'ufsc-licence-competition' ) : __( 'Non', 'ufsc-licence-competition' ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'reason_code', 'ufsc-licence-competition' ) . '</th><td>' . esc_html( $register_result ? (string) $register_result->reason_code : '' ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Message utilisateur (inscription)', 'ufsc-licence-competition' ) . '</th><td>' . esc_html( $register_message ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Message utilisateur (engagés)', 'ufsc-licence-competition' ) . '</th><td>' . esc_html( $engaged_message ) . '</td></tr>';
		echo '</tbody></table>';
	}

	private static function redirect_back( int $competition_id, int $club_id, array $extra_args = array() ): void {
		$args = array(
			'page' => Menu::PAGE_ACCESS_DIAGNOSTIC,
		);
		if ( $competition_id ) {
			$args['competition_id'] = $competition_id;
		}
		if ( $club_id ) {
			$args['club_id'] = $club_id;
		}
		if ( $extra_args ) {
			$args = array_merge( $args, $extra_args );
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	private static function store_notices( int $user_id, array $notices ): void {
		set_transient( self::get_notice_transient_key( $user_id ), $notices, self::NOTICE_TRANSIENT_TTL );
	}

	private static function consume_transient( string $key ): array {
		$data = get_transient( $key );
		if ( false !== $data ) {
			delete_transient( $key );
		}

		return is_array( $data ) ? $data : array();
	}

	private static function get_notice_transient_key( int $user_id ): string {
		return 'ufsc_access_diag_notice_' . $user_id;
	}

	private static function get_result_transient_key( int $user_id ): string {
		return 'ufsc_access_diag_result_' . $user_id;
	}
}
