<?php

namespace UFSC\Competitions\Admin\Pages;

use UFSC\Competitions\Admin\Menu;
use UFSC\Competitions\Access\CompetitionAccess;
use UFSC\Competitions\Capabilities;
use UFSC\Competitions\Db;
use UFSC\Competitions\Front\Repositories\CompetitionReadRepository;
use UFSC\Competitions\Repositories\CompetitionRepository;
use UFSC\Competitions\Repositories\TimingProfileRepository;
use UFSC\Competitions\Repositories\ClubRepository;
use UFSC\Competitions\Services\CompetitionScheduleEstimator;
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
		if ( function_exists( 'ufsc_lc_comp_log' ) ) {
			ufsc_lc_comp_log(
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
		$club_region_key = function_exists( 'ufsc_lc_normalize_region_key' ) ? ufsc_lc_normalize_region_key( $club_region_raw ) : $club_region_raw;

		if ( function_exists( 'ufsc_lc_comp_log' ) ) {
			ufsc_lc_comp_log(
				'access_diag_club',
				array(
					'club_id' => (int) ( $club->id ?? 0 ),
					'club_name' => (string) ( $club->nom ?? '' ),
					'region' => $club_region_raw,
					'region_key' => $club_region_key,
				)
			);
			ufsc_lc_comp_log(
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
			ufsc_lc_comp_log(
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
		$competition_filters = array( 'view' => 'all' );
		if ( function_exists( 'ufsc_lc_competitions_apply_scope_to_query_args' ) ) {
			$competition_filters = ufsc_lc_competitions_apply_scope_to_query_args( $competition_filters );
		}
		$competitions = $competition_repo->list( $competition_filters, 200, 0 );

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

		if ( (bool) apply_filters( 'ufsc_competitions_enable_qa_diagnostics', false ) ) {
			$this->render_qa_diagnostics();
		}

		echo '</div>';
	}

	private function render_qa_diagnostics(): void {
		$checks = $this->build_qa_checks();

		echo '<hr />';
		echo '<h2>' . esc_html__( 'Checklist QA (lecture seule)', 'ufsc-licence-competition' ) . '</h2>';
		echo '<p>' . esc_html__( 'Ces vérifications sont optionnelles et n’écrivent aucune donnée en base.', 'ufsc-licence-competition' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Check', 'ufsc-licence-competition' ) . '</th>';
		echo '<th>' . esc_html__( 'Statut', 'ufsc-licence-competition' ) . '</th>';
		echo '<th>' . esc_html__( 'Détails actionnables', 'ufsc-licence-competition' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $checks as $check ) {
			$status = isset( $check['status'] ) ? sanitize_key( (string) $check['status'] ) : 'warn';
			if ( ! in_array( $status, array( 'ok', 'warn', 'fail' ), true ) ) {
				$status = 'warn';
			}

			$label = 'OK';
			$class = 'notice-success';
			if ( 'warn' === $status ) {
				$label = 'WARN';
				$class = 'notice-warning';
			} elseif ( 'fail' === $status ) {
				$label = 'FAIL';
				$class = 'notice-error';
			}

			echo '<tr>';
			echo '<td>' . esc_html( (string) ( $check['label'] ?? '' ) ) . '</td>';
			echo '<td><span class="notice inline ' . esc_attr( $class ) . '" style="padding:0 8px;margin:0;"><p style="margin:6px 0;">' . esc_html( $label ) . '</p></span></td>';
			echo '<td>' . esc_html( (string) ( $check['detail'] ?? '' ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	private function build_qa_checks(): array {
		global $wpdb;

		$checks = array();
		$required_tables = array(
			'competitions' => Db::competitions_table(),
			'categories' => Db::categories_table(),
			'entries' => Db::entries_table(),
			'fights' => Db::fights_table(),
			'timing_profiles' => Db::timing_profiles_table(),
		);

		$weighins_table = Db::weighins_table();
		$weighins_exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $weighins_table ) ) === $weighins_table );
		if ( $weighins_exists ) {
			$required_tables['weighins'] = $weighins_table;
		}

		$missing_tables = array();
		foreach ( $required_tables as $label => $table_name ) {
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) !== $table_name ) {
				$missing_tables[] = $label;
			}
		}

		$checks[] = array(
			'label' => __( 'DB: tables attendues', 'ufsc-licence-competition' ),
			'status' => empty( $missing_tables ) ? 'ok' : 'fail',
			'detail' => empty( $missing_tables )
				? __( 'Toutes les tables principales existent.', 'ufsc-licence-competition' )
				: sprintf( __( 'Tables manquantes: %s. Lancer la routine de mise à niveau du plugin.', 'ufsc-licence-competition' ), implode( ', ', $missing_tables ) ),
		);

		$critical_columns = array(
			Db::competitions_table() => array( 'status', 'deleted_at' ),
			Db::fights_table() => array( 'deleted_at', 'round_duration' ),
			Db::timing_profiles_table() => array( 'round_duration' ),
		);
		$missing_columns = array();
		foreach ( $critical_columns as $table_name => $columns ) {
			foreach ( $columns as $column_name ) {
				if ( ! Db::has_table_column( $table_name, $column_name ) ) {
					$missing_columns[] = str_replace( $wpdb->prefix, '', $table_name ) . '.' . $column_name;
				}
			}
		}

		$duration_type = '';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Db::timing_profiles_table() ) ) === Db::timing_profiles_table() ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SHOW COLUMNS FROM " . Db::timing_profiles_table() . " LIKE %s", 'round_duration' ) );
			$duration_type = is_object( $row ) && isset( $row->Type ) ? strtolower( (string) $row->Type ) : '';
		}

		$supports_decimal = ( false !== strpos( $duration_type, 'decimal' ) || false !== strpos( $duration_type, 'float' ) || false !== strpos( $duration_type, 'double' ) );
		$checks[] = array(
			'label' => __( 'DB: colonnes critiques', 'ufsc-licence-competition' ),
			'status' => empty( $missing_columns ) && $supports_decimal ? 'ok' : ( empty( $missing_columns ) ? 'warn' : 'fail' ),
			'detail' => empty( $missing_columns )
				? ( $supports_decimal
					? sprintf( __( 'Colonnes OK, round_duration=%s compatible décimal.', 'ufsc-licence-competition' ), $duration_type )
					: sprintf( __( 'Colonnes présentes mais type round_duration=%s: vérifier le support de 1.5 minute.', 'ufsc-licence-competition' ), $duration_type ?: 'inconnu' ) )
				: sprintf( __( 'Colonnes manquantes: %s. Déclencher une mise à niveau.', 'ufsc-licence-competition' ), implode( ', ', $missing_columns ) ),
		);

		$scope = function_exists( 'ufsc_lc_competitions_get_user_scope_region' ) ? ufsc_lc_competitions_get_user_scope_region() : null;
		$scope_label = ( null === $scope || '' === $scope ) ? __( 'historique (aucun scope forcé)', 'ufsc-licence-competition' ) : $scope;
		$caps = array(
			'ufsc_manage_competitions' => current_user_can( 'ufsc_manage_competitions' ),
			'manage_options' => current_user_can( 'manage_options' ),
		);
		$checks[] = array(
			'label' => __( 'Utilisateur: scope + capabilities', 'ufsc-licence-competition' ),
			'status' => 'ok',
			'detail' => sprintf(
				'ufsc_region_scope=%1$s | caps: ufsc_manage_competitions=%2$s, manage_options=%3$s',
				$scope_label,
				$caps['ufsc_manage_competitions'] ? 'yes' : 'no',
				$caps['manage_options'] ? 'yes' : 'no'
			),
		);

		$competition_repo = new CompetitionRepository();
		$counts = array();
		foreach ( array( 'all_with_archived', 'active', 'archived', 'trash' ) as $view ) {
			$args = array( 'view' => $view );
			if ( function_exists( 'ufsc_lc_competitions_apply_scope_to_query_args' ) ) {
				$args = ufsc_lc_competitions_apply_scope_to_query_args( $args );
			}
			$counts[ $view ] = (int) $competition_repo->count( $args );
		}
		$checks[] = array(
			'label' => __( 'Compétitions visibles par vue', 'ufsc-licence-competition' ),
			'status' => 'ok',
			'detail' => sprintf(
				'all_with_archived=%1$d | active=%2$d | archived=%3$d | trash=%4$d',
				$counts['all_with_archived'],
				$counts['active'],
				$counts['archived'],
				$counts['trash']
			),
		);

		$timing_repo = new TimingProfileRepository();
		$timing_count = method_exists( $timing_repo, 'count' ) ? (int) $timing_repo->count() : count( $timing_repo->list() );
		$support_15 = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Db::timing_profiles_table() . ' WHERE round_duration = %f', 1.5 ) );
		$checks[] = array(
			'label' => __( 'Timing profiles + durée 1.5', 'ufsc-licence-competition' ),
			'status' => $timing_count > 0 && 0 === $support_15 ? 'warn' : 'ok',
			'detail' => sprintf( __( 'Profils=%1$d | profils avec round_duration=1.5: %2$d', 'ufsc-licence-competition' ), $timing_count, $support_15 ),
		);

		$estimator = new CompetitionScheduleEstimator();
		$estimate_zero = $estimator->estimate( 0, array(), array(), 'scheduled' );
		$estimate_sample = array( 'not_run' => true );
		if ( $counts['all_with_archived'] > 0 ) {
			$first_comp_filters = array( 'view' => 'all_with_archived' );
			if ( function_exists( 'ufsc_lc_competitions_apply_scope_to_query_args' ) ) {
				$first_comp_filters = ufsc_lc_competitions_apply_scope_to_query_args( $first_comp_filters );
			}
			$first_comp = $competition_repo->list( $first_comp_filters, 1, 0 );
			$first_id = ! empty( $first_comp[0]->id ) ? (int) $first_comp[0]->id : 0;
			if ( $first_id > 0 ) {
				$estimate_sample = $estimator->estimate( $first_id );
			}
		}

		$checks[] = array(
			'label' => __( 'Estimateur: calcul lecture seule', 'ufsc-licence-competition' ),
			'status' => isset( $estimate_zero['total_fights'] ) && isset( $estimate_zero['overflow'] ) ? 'ok' : 'fail',
			'detail' => sprintf(
				'competition=0 => total_fights=%1$d, overflow=%2$s | sample=%3$s',
				(int) ( $estimate_zero['total_fights'] ?? -1 ),
				empty( $estimate_zero['overflow'] ) ? 'no' : 'yes',
				isset( $estimate_sample['not_run'] ) ? 'n/a' : sprintf( 'total_fights=%d, overflow=%s', (int) ( $estimate_sample['total_fights'] ?? 0 ), empty( $estimate_sample['overflow'] ) ? 'no' : 'yes' )
			),
		);

		$checks[] = array(
			'label' => __( 'Estimateur: audit écriture DB', 'ufsc-licence-competition' ),
			'status' => 'ok',
			'detail' => __( 'Aucun INSERT/UPDATE/DELETE dans le service d’estimation (audit code).', 'ufsc-licence-competition' ),
		);

		return $checks;
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
		$club_region = function_exists( 'ufsc_lc_normalize_region_key' ) ? ufsc_lc_normalize_region_key( $club_region_raw ) : $club_region_raw;
		$club_region_missing = '' === trim( $club_region_raw );
		$club_disciplines = function_exists( 'ufsc_lc_extract_club_disciplines' ) ? ufsc_lc_extract_club_disciplines( $club ) : array();

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

		$current_context = function_exists( 'ufsc_lc_current_club_context' ) ? ufsc_lc_current_club_context( (int) get_current_user_id() ) : array();
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
