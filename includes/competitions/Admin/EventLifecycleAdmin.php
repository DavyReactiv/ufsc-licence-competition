<?php

namespace UFSC\Competitions\Admin;

use UFSC\Competitions\Capabilities;
use UFSC\Competitions\Services\CompetitionDuplicationService;
use UFSC\Competitions\Services\CompetitionFilters;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin enhancements for event lifecycle management.
 *
 * Keeps list filtering, printing shortcuts and safe duplication separate from
 * the competition table implementation to avoid a second business-data path.
 */
class EventLifecycleAdmin {
	private static $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		add_action( 'admin_post_ufsc_competitions_duplicate_competition', array( __CLASS__, 'handle_duplicate' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 60 );
		add_action( 'admin_notices', array( __CLASS__, 'render_notice' ), 5 );
	}

	public static function enqueue_assets(): void {
		if ( ! self::is_competitions_list_screen() ) {
			return;
		}

		$file = UFSC_LC_DIR . 'includes/competitions/assets/event-lifecycle-admin.js';
		if ( ! file_exists( $file ) ) {
			return;
		}

		wp_enqueue_script(
			'ufsc-competitions-event-lifecycle',
			UFSC_LC_URL . 'includes/competitions/assets/event-lifecycle-admin.js',
			array(),
			(string) filemtime( $file ),
			true
		);

		wp_localize_script(
			'ufsc-competitions-event-lifecycle',
			'ufscCompetitionLifecycle',
			array(
				'adminUrl'       => admin_url( 'admin.php' ),
				'adminPostUrl'   => admin_url( 'admin-post.php' ),
				'nonce'          => wp_create_nonce( 'ufsc_competitions_duplicate_competition' ),
				'canDuplicate'   => Capabilities::user_can_create(),
				'seasons'        => self::season_choices(),
				'statuses'       => CompetitionFilters::get_status_choices(),
				'disciplines'    => CompetitionFilters::get_disciplines(),
				'labels'         => array(
					'allSeasons'     => __( 'Toutes les saisons', 'ufsc-licence-competition' ),
					'allStatuses'    => __( 'Tous les statuts', 'ufsc-licence-competition' ),
					'allDisciplines' => __( 'Toutes les disciplines', 'ufsc-licence-competition' ),
					'filter'         => __( 'Filtrer', 'ufsc-licence-competition' ),
					'reset'          => __( 'Réinitialiser', 'ufsc-licence-competition' ),
					'print'          => __( 'Afficher / imprimer', 'ufsc-licence-competition' ),
					'results'        => __( 'Résultats', 'ufsc-licence-competition' ),
					'officials'      => __( 'Officiels', 'ufsc-licence-competition' ),
					'duplicate'      => __( 'Dupliquer en nouvelle édition', 'ufsc-licence-competition' ),
					'duplicateConfirm' => __( 'Créer une nouvelle édition en brouillon ? Les inscrits, combats, résultats, pesées et officiels ne seront pas copiés.', 'ufsc-licence-competition' ),
				),
			)
		);
	}

	public static function handle_duplicate(): void {
		if ( ! Capabilities::user_can_create() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'ufsc_competitions_duplicate_competition' );
		$source_id = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;
		if ( ! $source_id ) {
			self::redirect_list( 'duplicate_invalid' );
		}

		$service = new CompetitionDuplicationService();
		$result  = $service->duplicate( $source_id );
		if ( empty( $result['ok'] ) || empty( $result['id'] ) ) {
			self::redirect_list( 'duplicate_failed' );
		}

		$new_id = absint( $result['id'] );
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => Menu::MENU_SLUG,
					'ufsc_action' => 'edit',
					'id'          => $new_id,
					'ufsc_notice' => 'duplicated',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public static function render_notice(): void {
		if ( ! self::is_competitions_list_screen() ) {
			return;
		}

		$notice = isset( $_GET['ufsc_notice'] ) ? sanitize_key( wp_unslash( $_GET['ufsc_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$messages = array(
			'duplicated'        => array( 'success', __( 'Nouvelle édition créée en brouillon. Vérifiez la saison, les dates et les réglages avant ouverture des inscriptions.', 'ufsc-licence-competition' ) ),
			'duplicate_invalid' => array( 'error', __( 'Impossible de dupliquer cet événement : source invalide.', 'ufsc-licence-competition' ) ),
			'duplicate_failed'  => array( 'error', __( 'La duplication n’a pas pu être terminée. Aucun inscrit, combat ou résultat n’a été copié.', 'ufsc-licence-competition' ) ),
		);

		if ( ! isset( $messages[ $notice ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $messages[ $notice ][0] ),
			esc_html( $messages[ $notice ][1] )
		);
	}

	private static function season_choices(): array {
		$seasons = CompetitionFilters::get_seasons();
		$current = CompetitionDuplicationService::current_season();
		if ( '' !== $current && ! in_array( $current, $seasons, true ) ) {
			array_unshift( $seasons, $current );
		}

		return array_values( array_unique( array_filter( array_map( 'strval', $seasons ) ) ) );
	}

	private static function is_competitions_list_screen(): bool {
		if ( ! is_admin() ) {
			return false;
		}

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( Menu::MENU_SLUG !== $page ) {
			return false;
		}

		$action = isset( $_GET['ufsc_action'] ) ? sanitize_key( wp_unslash( $_GET['ufsc_action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return ! in_array( $action, array( 'add', 'edit' ), true );
	}

	private static function redirect_list( string $notice ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => Menu::MENU_SLUG,
					'ufsc_notice' => sanitize_key( $notice ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
