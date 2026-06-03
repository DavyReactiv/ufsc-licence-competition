<?php

namespace UFSC\Competitions\Admin\Pages;

use UFSC\Competitions\Capabilities;
use UFSC\Competitions\Admin\Menu;
use UFSC\Competitions\Repositories\CompetitionRepository;
use UFSC\Competitions\Repositories\EntryRepository;
use UFSC\Competitions\Services\FightAutoGenerationService;
use UFSC\Competitions\Services\GenerationLockService;
use UFSC\Competitions\Services\GenerationReadinessDiagnostic;
use UFSC\Competitions\Services\CompetitionSafetyService;
use UFSC\Competitions\Services\LogService;
use UFSC\Competitions\Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Bouts_AutoGeneration {
	public static function register_actions(): void {
		if ( ! FightAutoGenerationService::is_enabled() ) {
			return;
		}

		add_action( 'admin_post_ufsc_competitions_save_fight_settings', array( __CLASS__, 'handle_save_settings' ) );
		add_action( 'admin_post_ufsc_competitions_generate_fight_draft', array( __CLASS__, 'handle_generate_draft' ) );
		add_action( 'admin_post_ufsc_competitions_generate_fight_draft_override', array( __CLASS__, 'handle_generate_draft_override' ) );
		add_action( 'admin_post_ufsc_competitions_regenerate_fight_draft', array( __CLASS__, 'handle_regenerate_draft' ) );
		add_action( 'admin_post_ufsc_competitions_validate_fight_draft', array( __CLASS__, 'handle_validate_draft' ) );
		add_action( 'admin_post_ufsc_competitions_generate_fight_direct', array( __CLASS__, 'handle_generate_direct' ) );
		add_action( 'admin_post_ufsc_competitions_discard_fight_draft', array( __CLASS__, 'handle_discard_draft' ) );
		add_action( 'admin_post_ufsc_competitions_recalc_fight_schedule', array( __CLASS__, 'handle_recalc_schedule' ) );
		add_action( 'admin_post_ufsc_competitions_swap_fight_colors', array( __CLASS__, 'handle_swap_colors' ) );
		add_action( 'admin_post_ufsc_competitions_reorder_fights', array( __CLASS__, 'handle_reorder_fights' ) );
		add_action( 'admin_post_ufsc_competitions_test_fixture_create', array( __CLASS__, 'handle_test_fixture_create' ) );
		add_action( 'admin_post_ufsc_competitions_test_fixture_reset', array( __CLASS__, 'handle_test_fixture_reset' ) );
		add_action( 'admin_post_ufsc_competitions_test_fixture_delete', array( __CLASS__, 'handle_test_fixture_delete' ) );
		add_action( 'admin_post_ufsc_competitions_test_fixture_run', array( __CLASS__, 'handle_test_fixture_run' ) );
		add_action( 'admin_post_ufsc_competitions_test_fixture_open150', array( __CLASS__, 'handle_test_fixture_open150' ) );
		add_action( 'admin_post_ufsc_competitions_test_fixture_open150_generate', array( __CLASS__, 'handle_test_fixture_open150_generate' ) );
		add_action( 'admin_post_ufsc_competitions_test_fixture_add_results', array( __CLASS__, 'handle_test_fixture_add_results' ) );
		add_action( 'admin_post_ufsc_competitions_assign_fighter_numbers', array( __CLASS__, 'handle_assign_fighter_numbers' ) );
		add_action( 'admin_post_ufsc_competitions_record_fight_result', array( __CLASS__, 'handle_record_fight_result' ) );
	}

	public static function render_notice( string $notice, string $message = '' ): void {
		$messages = array(
			'settings_saved' => __( 'Paramètres enregistrés.', 'ufsc-licence-competition' ),
			'draft_ready' => __( 'Brouillon généré.', 'ufsc-licence-competition' ),
			'draft_error' => __( 'Impossible de générer le brouillon.', 'ufsc-licence-competition' ),
			'draft_validated' => __( 'Combats enregistrés.', 'ufsc-licence-competition' ),
			'draft_discarded' => __( 'Brouillon supprimé.', 'ufsc-licence-competition' ),
			'schedule_recalc' => __( 'Horaires recalculés.', 'ufsc-licence-competition' ),
			'swap_ok' => __( 'Couleurs inversées.', 'ufsc-licence-competition' ),
			'reorder_ok' => __( 'Combats réordonnés.', 'ufsc-licence-competition' ),
			'action_error' => __( 'Action impossible.', 'ufsc-licence-competition' ),
			'invalid_settings' => __( 'Paramètres invalides : vérifiez les surfaces et les champs timing (minutes/secondes).', 'ufsc-licence-competition' ),
			'action_protected' => __( 'Action sensible protégée.', 'ufsc-licence-competition' ),
		);

		if ( ! isset( $messages[ $notice ] ) ) {
			return;
		}

		$type = in_array( $notice, array( 'error', 'draft_error', 'action_error', 'action_protected', 'invalid_settings' ), true ) ? 'error' : 'success';
		$text = $message ? $message : $messages[ $notice ];

		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( $text ) );
	}

	public static function render_panel( int $competition_id ): void {
		if ( ! FightAutoGenerationService::is_enabled() ) {
			return;
		}
		$competition_id = self::resolve_competition_id( $competition_id );
		if ( $competition_id && class_exists( GenerationLockService::class ) && GenerationLockService::is_generation_locked( $competition_id ) ) {
			$lock = GenerationLockService::get_lock( $competition_id );
			echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__( 'Génération verrouillée', 'ufsc-licence-competition' ) . '</strong> — ' . esc_html__( 'Les inscriptions et catégories sont protégées depuis la génération validée.', 'ufsc-licence-competition' ) . ' ' . esc_html( (string) ( $lock['generation_locked_at'] ?? '' ) ) . '</p></div>';
		}

		$settings = FightAutoGenerationService::get_settings( $competition_id );
		$draft = $competition_id ? FightAutoGenerationService::get_draft( $competition_id ) : array();
		$draft_stats = $draft['stats'] ?? array();
		$draft_warnings = $draft['warnings'] ?? array();
		$locked = ! empty( $settings['auto_lock'] );
		$manual_mode = 'manual' === ( $settings['mode'] ?? 'auto' );
		$can_generate = $competition_id && ! $locked && ! $manual_mode;
		$has_draft = ! empty( $draft['fights'] );
		$counters = $competition_id ? FightAutoGenerationService::get_generation_counters( $competition_id, $settings ) : array(
			'total_entries' => 0,
			'eligible_entries' => 0,
			'excluded_unweighed' => 0,
			'can_override_unweighed' => false,
		);
		$preview = $competition_id ? FightAutoGenerationService::get_generation_preview( $competition_id, $settings ) : array();
		$diagnostic_settings = $settings;
		if ( ! empty( $draft['settings'] ) && is_array( $draft['settings'] ) ) {
			$diagnostic_settings = array_merge( $diagnostic_settings, $draft['settings'] );
		}
		$readiness = $competition_id ? GenerationReadinessDiagnostic::check( $competition_id, $diagnostic_settings, $draft ) : GenerationReadinessDiagnostic::check( 0, $diagnostic_settings, array() );
		$diagnostic_blocking = ! empty( $readiness['blocking'] );
		$estimated_fights = (int) ( $preview['estimated_fights'] ?? ( $readiness['summary']['estimated_fights'] ?? 0 ) );
		$can_generate_now = $can_generate && ! $diagnostic_blocking && $estimated_fights > 0 && ! empty( $preview['can_generate'] );
		$draft_fights_preview = isset( $draft['fights_preview'] ) && is_array( $draft['fights_preview'] ) ? $draft['fights_preview'] : ( isset( $draft['fights'] ) && is_array( $draft['fights'] ) ? $draft['fights'] : array() );
		$draft_groups = isset( $draft['groups'] ) && is_array( $draft['groups'] ) ? $draft['groups'] : array();
		$draft_excluded_entries = isset( $draft['excluded_entries'] ) && is_array( $draft['excluded_entries'] ) ? $draft['excluded_entries'] : array();
		$draft_surfaces = isset( $draft['surfaces'] ) && is_array( $draft['surfaces'] ) ? $draft['surfaces'] : array();
		$draft_summary = isset( $draft['summary'] ) && is_array( $draft['summary'] ) ? $draft['summary'] : array();
		$current_preview_hash = class_exists( GenerationReadinessDiagnostic::class ) ? GenerationReadinessDiagnostic::hash_draft( $preview ) : hash( 'sha256', wp_json_encode( $preview ) ?: '' );
		$draft_obsolete = $has_draft && ! empty( $draft['diagnostic_hash'] ) && ! hash_equals( (string) $draft['diagnostic_hash'], (string) $current_preview_hash );
		$can_validate_draft = $has_draft && ! $diagnostic_blocking && ! $draft_obsolete;
		$estimated_total_seconds = (int) ( $preview['estimated_total_seconds'] ?? 0 );
		$diagnostics = isset( $preview['rejection_diagnostics'] ) && is_array( $preview['rejection_diagnostics'] )
			? $preview['rejection_diagnostics']
			: ( isset( $counters['diagnostics'] ) && is_array( $counters['diagnostics'] ) ? $counters['diagnostics'] : array() );
		$competition_label = $competition_id ? sprintf( __( 'Compétition #%d', 'ufsc-licence-competition' ), $competition_id ) : __( 'Aucune compétition sélectionnée (ouvrez une compétition active).', 'ufsc-licence-competition' );
		$competition_repo = new CompetitionRepository();
		$entry_repo = new EntryRepository();
		$competition = $competition_id ? $competition_repo->get( $competition_id, true ) : null;
		$competition_name = $competition ? sanitize_text_field( (string) ( $competition->name ?? '' ) ) : '';
		$competition_label = $competition_name ? $competition_name : $competition_label;
		$competition_options = $competition_repo->list( array(), 100, 0 );
		$entries = $competition_id ? $entry_repo->list_with_details( array( 'view' => 'all', 'competition_id' => $competition_id ), 2000, 0 ) : array();
		$approved_count = 0;
		$detected_disciplines = array();
		foreach ( $entries as $entry ) {
			$status = sanitize_key( (string) ( $entry->status ?? '' ) );
			if ( 'approved' === $status ) {
				$approved_count++;
			}
			$raw_discipline = sanitize_text_field( (string) ( $entry->discipline ?? '' ) );
			if ( '' === $raw_discipline ) {
				continue;
			}
			$normalized = FightAutoGenerationService::normalize_discipline_for_generation( $raw_discipline );
			$detected_disciplines[ $raw_discipline ] = $normalized;
		}
		$competition_discipline_raw = $competition ? sanitize_text_field( (string) ( $competition->discipline ?? '' ) ) : '';
		$competition_discipline_normalized = FightAutoGenerationService::normalize_discipline_for_generation( $competition_discipline_raw );
		$timing_profile_label = 'category' === ( $settings['timing_mode'] ?? 'global' )
			? __( 'Profils par catégories actifs', 'ufsc-licence-competition' )
			: __( 'Timing global manuel', 'ufsc-licence-competition' );
		$generation_status_label = $can_generate ? __( 'Prêt à générer', 'ufsc-licence-competition' ) : __( 'Action requise avant génération', 'ufsc-licence-competition' );
		$generation_summary_message = $estimated_fights > 0
			? __( 'Prêt à générer : au moins un groupe contient 2 athlètes ou plus.', 'ufsc-licence-competition' )
			: __( 'Aucun groupe générable détecté. Vérifiez les motifs bloquants ci-dessous.', 'ufsc-licence-competition' );
		$last_saved = ! empty( $settings['settings_saved_at'] ) ? sanitize_text_field( (string) $settings['settings_saved_at'] ) : __( 'Non enregistré', 'ufsc-licence-competition' );
		?>
		<div class="ufsc-competitions-box">
			<?php self::render_competition_selector_block( $competition_id, $competition, $competition_options ); ?>
			<?php self::render_competition_quick_dashboard( $competition_id ); ?>
			<div class="ufsc-fightgen-header">
				<div>
					<h2><?php esc_html_e( 'Génération avancée des combats', 'ufsc-licence-competition' ); ?></h2>
					<p><?php esc_html_e( 'Pilotez la génération opérationnelle des combats : contrôles, réglages timing et validation du brouillon.', 'ufsc-licence-competition' ); ?></p>
				</div>
				<div class="ufsc-fightgen-header__meta">
					<span class="<?php echo esc_attr( self::status_badge_class( $can_generate ? 'ok' : 'warn' ) ); ?>"><?php echo esc_html( $generation_status_label ); ?></span>
					<span class="ufsc-badge ufsc-badge--info"><?php echo esc_html( $competition_label ); ?></span>
					<span class="ufsc-badge ufsc-badge--muted"><?php echo esc_html( sprintf( __( 'Dernier enregistrement : %s', 'ufsc-licence-competition' ), $last_saved ) ); ?></span>
					<span class="ufsc-badge ufsc-badge--muted"><?php echo esc_html( $timing_profile_label ); ?></span>
				</div>
			</div>
			<div class="notice notice-info inline">
				<p><strong><?php echo esc_html( sprintf( __( 'Génération des combats pour : %s', 'ufsc-licence-competition' ), $competition_label ) ); ?></strong></p>
				<p><?php esc_html_e( 'Cette page permet de créer les combats uniquement pour la compétition sélectionnée. Les combats en cours, terminés ou verrouillés ne sont jamais modifiés.', 'ufsc-licence-competition' ); ?></p>
				<p><?php echo esc_html( sprintf( __( 'Inscriptions liées : %1$d — Approuvées : %2$d', 'ufsc-licence-competition' ), count( $entries ), $approved_count ) ); ?></p>
				<p><?php echo esc_html( sprintf( __( 'Discipline compétition : %1$s (normalisée : %2$s)', 'ufsc-licence-competition' ), $competition_discipline_raw ?: '—', $competition_discipline_normalized ?: '—' ) ); ?></p>
				<?php if ( ! empty( $detected_disciplines ) ) : ?>
					<p><?php esc_html_e( 'Disciplines détectées dans les inscriptions :', 'ufsc-licence-competition' ); ?>
					<?php
					$pairs = array();
					foreach ( $detected_disciplines as $raw => $normalized ) {
						$pairs[] = sprintf( '%s → %s', $raw, $normalized );
					}
					echo esc_html( implode( ' | ', $pairs ) );
					?>
					</p>
				<?php endif; ?>
			</div>

			<?php self::render_generation_readiness_diagnostic( $readiness, $competition_id, $can_generate_now, $can_validate_draft ); ?>

			<div class="ufsc-fightgen-kpis">
				<div class="ufsc-fightgen-kpi"><span><?php esc_html_e( 'Inscriptions totales', 'ufsc-licence-competition' ); ?></span><strong><?php echo esc_html( (string) (int) ( $counters['total_entries'] ?? 0 ) ); ?></strong></div>
				<div class="ufsc-fightgen-kpi"><span><?php esc_html_e( 'Éligibles', 'ufsc-licence-competition' ); ?></span><strong><?php echo esc_html( (string) (int) ( $counters['eligible_entries'] ?? 0 ) ); ?></strong></div>
				<div class="ufsc-fightgen-kpi"><span><?php esc_html_e( 'Non pesés exclus', 'ufsc-licence-competition' ); ?></span><strong><?php echo esc_html( (string) (int) ( $counters['excluded_unweighed'] ?? 0 ) ); ?></strong></div>
				<div class="ufsc-fightgen-kpi"><span><?php esc_html_e( 'Combats estimés', 'ufsc-licence-competition' ); ?></span><strong><?php echo esc_html( (string) $estimated_fights ); ?></strong></div>
				<div class="ufsc-fightgen-kpi"><span><?php esc_html_e( 'Surfaces', 'ufsc-licence-competition' ); ?></span><strong><?php echo esc_html( (string) (int) ( $settings['surface_count'] ?? 1 ) ); ?></strong></div>
				<div class="ufsc-fightgen-kpi"><span><?php esc_html_e( 'Durée totale estimée', 'ufsc-licence-competition' ); ?></span><strong><?php echo esc_html( self::format_duration_label( $estimated_total_seconds ) ); ?></strong></div>
			</div>
			<div class="notice notice-<?php echo $estimated_fights > 0 ? 'success' : 'warning'; ?> inline"><p><?php echo esc_html( $generation_summary_message ); ?></p></div>
			<div class="ufsc-fightgen-precheck">
				<h3><?php esc_html_e( 'Aide au choix du format sportif', 'ufsc-licence-competition' ); ?></h3>
				<p><?php esc_html_e( 'Avant de générer les combats, vérifiez les formats proposés (combat direct, poule, tableau BYE, petite finale, repêchage). Ces choix influencent l’équité sportive et le timing total.', 'ufsc-licence-competition' ); ?></p>
				<ul>
					<li><?php esc_html_e( '2 combattants : combat direct recommandé.', 'ufsc-licence-competition' ); ?></li>
					<li><?php esc_html_e( '3 combattants : poule complète recommandée (plus équitable, plus long).', 'ufsc-licence-competition' ); ?></li>
					<li><?php esc_html_e( '5+ combattants : tableau avec BYE recommandé (plus rapide, moins équilibré).', 'ufsc-licence-competition' ); ?></li>
				</ul>
			</div>

			<div class="ufsc-fightgen-precheck">
				<h3><?php esc_html_e( 'Contrôles avant génération', 'ufsc-licence-competition' ); ?></h3>
				<ul>
					<li><span class="<?php echo esc_attr( self::status_badge_class( ! empty( $preview['precheck']['surfaces_ok'] ) ? 'ok' : 'danger' ) ); ?>"><?php echo ! empty( $preview['precheck']['surfaces_ok'] ) ? esc_html__( 'OK', 'ufsc-licence-competition' ) : esc_html__( 'À corriger', 'ufsc-licence-competition' ); ?></span> <?php esc_html_e( 'Paramètres surfaces', 'ufsc-licence-competition' ); ?></li>
					<li><span class="<?php echo esc_attr( self::status_badge_class( ! empty( $preview['precheck']['timing_ok'] ) ? 'ok' : 'danger' ) ); ?>"><?php echo ! empty( $preview['precheck']['timing_ok'] ) ? esc_html__( 'OK', 'ufsc-licence-competition' ) : esc_html__( 'Incomplet', 'ufsc-licence-competition' ); ?></span> <?php esc_html_e( 'Timing global/profil', 'ufsc-licence-competition' ); ?></li>
					<li><span class="<?php echo esc_attr( self::status_badge_class( ! empty( $preview['precheck']['eligible_ok'] ) ? 'ok' : 'warn' ) ); ?>"><?php echo ! empty( $preview['precheck']['eligible_ok'] ) ? esc_html__( 'Suffisant', 'ufsc-licence-competition' ) : esc_html__( 'Insuffisant', 'ufsc-licence-competition' ); ?></span> <?php esc_html_e( 'Inscriptions éligibles', 'ufsc-licence-competition' ); ?></li>
					<li><span class="<?php echo esc_attr( self::status_badge_class( empty( $preview['duplicate_fighter_numbers'] ) ? 'ok' : 'warn' ) ); ?>"><?php echo empty( $preview['duplicate_fighter_numbers'] ) ? esc_html__( 'Aucun doublon', 'ufsc-licence-competition' ) : esc_html__( 'Doublons détectés', 'ufsc-licence-competition' ); ?></span> <?php echo esc_html( sprintf( __( 'Numéros combattants en conflit : %d', 'ufsc-licence-competition' ), (int) ( $preview['duplicate_fighter_numbers'] ?? 0 ) ) ); ?></li>
					<li><span class="<?php echo esc_attr( self::status_badge_class( $can_generate ? 'ok' : 'danger' ) ); ?>"><?php echo $can_generate ? esc_html__( 'Oui', 'ufsc-licence-competition' ) : esc_html__( 'Non', 'ufsc-licence-competition' ); ?></span> <?php esc_html_e( 'Capacité de génération immédiate', 'ufsc-licence-competition' ); ?></li>
				</ul>
			</div>

			<?php if ( ! empty( $diagnostics ) ) : ?>
			<div class="ufsc-fightgen-precheck">
				<h3><?php esc_html_e( 'Athlètes bloqués (motifs bloquants)', 'ufsc-licence-competition' ); ?></h3>
				<ul>
					<li><?php echo esc_html( sprintf( __( 'Inscriptions totales : %d', 'ufsc-licence-competition' ), (int) ( $diagnostics['total_entries'] ?? 0 ) ) ); ?></li>
					<li><?php echo esc_html( sprintf( __( 'Éligibles : %d', 'ufsc-licence-competition' ), (int) ( $diagnostics['eligible_entries'] ?? 0 ) ) ); ?></li>
					<li><?php echo esc_html( sprintf( __( 'Rejetées par statut : %d', 'ufsc-licence-competition' ), (int) ( $diagnostics['rejected_status'] ?? 0 ) ) ); ?></li>
					<li><?php echo esc_html( sprintf( __( 'Rejetées par licence/type participant : %d', 'ufsc-licence-competition' ), (int) ( $diagnostics['rejected_license_or_participant'] ?? 0 ) ) ); ?></li>
					<li><?php echo esc_html( sprintf( __( 'Rejetées par pesée : %d', 'ufsc-licence-competition' ), (int) ( $diagnostics['rejected_weighin'] ?? 0 ) ) ); ?></li>
					<li><?php echo esc_html( sprintf( __( 'Rejetées par données sportives manquantes : %d', 'ufsc-licence-competition' ), (int) ( $diagnostics['rejected_missing_sport_data'] ?? 0 ) ) ); ?></li>
					<li><?php echo esc_html( sprintf( __( 'Avertissements non bloquants : %d', 'ufsc-licence-competition' ), (int) ( $diagnostics['non_blocking_warnings'] ?? 0 ) ) ); ?></li>
					<li><?php echo esc_html( sprintf( __( 'Rejetées par catégorie/poids/niveau : %d', 'ufsc-licence-competition' ), (int) ( $diagnostics['rejected_category_weight_level'] ?? 0 ) ) ); ?></li>
					<li><?php echo esc_html( sprintf( __( 'Rejetées par discipline : %d', 'ufsc-licence-competition' ), (int) ( $diagnostics['rejected_discipline'] ?? 0 ) ) ); ?></li>
					<li><?php echo esc_html( sprintf( __( 'Rejetées car club absent : %d', 'ufsc-licence-competition' ), (int) ( $diagnostics['rejected_club'] ?? 0 ) ) ); ?></li>
					<li><?php echo esc_html( sprintf( __( 'Rejetées car données combattant incomplètes : %d', 'ufsc-licence-competition' ), (int) ( $diagnostics['rejected_incomplete_fighter_data'] ?? 0 ) ) ); ?></li>
					<li><?php echo esc_html( sprintf( __( 'Rejetées par doublon n° combattant : %d', 'ufsc-licence-competition' ), (int) ( $diagnostics['rejected_duplicate_fighter_number'] ?? 0 ) ) ); ?></li>
				</ul>
				<?php if ( ! empty( $diagnostics['sport_data_subreasons'] ) && is_array( $diagnostics['sport_data_subreasons'] ) ) : ?>
					<p><strong><?php esc_html_e( 'Détail données sportives manquantes :', 'ufsc-licence-competition' ); ?></strong></p>
					<ul>
						<?php foreach ( $diagnostics['sport_data_subreasons'] as $reason_key => $reason_count ) : ?>
							<li><?php echo esc_html( sprintf( '%s : %d', sanitize_key( (string) $reason_key ), (int) $reason_count ) ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
				<?php if ( ! empty( $diagnostics['rejected_entries_preview'] ) && is_array( $diagnostics['rejected_entries_preview'] ) ) : ?>
					<p><strong><?php esc_html_e( 'Athlètes bloqués — exemples (10 premiers)', 'ufsc-licence-competition' ); ?></strong></p>
					<div class="table-responsive">
						<table class="widefat striped">
							<thead>
								<tr>
									<th><?php esc_html_e( 'ID', 'ufsc-licence-competition' ); ?></th>
									<th><?php esc_html_e( 'Nom', 'ufsc-licence-competition' ); ?></th>
									<th><?php esc_html_e( 'Prénom', 'ufsc-licence-competition' ); ?></th>
									<th><?php esc_html_e( 'Statut', 'ufsc-licence-competition' ); ?></th>
									<th><?php esc_html_e( 'Discipline', 'ufsc-licence-competition' ); ?></th>
									<th><?php esc_html_e( 'Catégorie', 'ufsc-licence-competition' ); ?></th>
									<th><?php esc_html_e( 'Cat. poids', 'ufsc-licence-competition' ); ?></th>
									<th><?php esc_html_e( 'Poids', 'ufsc-licence-competition' ); ?></th>
									<th><?php esc_html_e( 'Sexe brut', 'ufsc-licence-competition' ); ?></th>
									<th><?php esc_html_e( 'Sexe norm.', 'ufsc-licence-competition' ); ?></th>
									<th><?php esc_html_e( 'Naissance brute', 'ufsc-licence-competition' ); ?></th>
									<th><?php esc_html_e( 'Naissance norm.', 'ufsc-licence-competition' ); ?></th>
									<th><?php esc_html_e( 'Niveau brut', 'ufsc-licence-competition' ); ?></th>
									<th><?php esc_html_e( 'Niveau norm.', 'ufsc-licence-competition' ); ?></th>
									<th><?php esc_html_e( 'Motifs exacts', 'ufsc-licence-competition' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $diagnostics['rejected_entries_preview'] as $entry_diag ) : ?>
									<tr>
										<td><?php echo esc_html( (string) (int) ( $entry_diag['entry_id'] ?? 0 ) ); ?></td>
										<td><?php echo esc_html( (string) ( $entry_diag['last_name'] ?? '—' ) ); ?></td>
										<td><?php echo esc_html( (string) ( $entry_diag['first_name'] ?? '—' ) ); ?></td>
										<td><?php echo esc_html( (string) ( $entry_diag['status'] ?? '—' ) ); ?></td>
										<td><?php echo esc_html( (string) ( $entry_diag['discipline'] ?? '—' ) ); ?></td>
										<td><?php echo esc_html( (string) ( $entry_diag['category'] ?? '—' ) ); ?></td>
										<td><?php echo esc_html( (string) ( $entry_diag['weight_class'] ?? '—' ) ); ?></td>
										<td><?php echo esc_html( (string) ( $entry_diag['weight'] ?? '—' ) ); ?></td>
										<td><?php echo esc_html( (string) ( $entry_diag['sex_raw'] ?? '—' ) ); ?></td>
										<td><?php echo esc_html( (string) ( $entry_diag['sex_normalized'] ?? '—' ) ); ?></td>
										<td><?php echo esc_html( (string) ( $entry_diag['birthdate_raw'] ?? '—' ) ); ?></td>
										<td><?php echo esc_html( (string) ( $entry_diag['birthdate_normalized'] ?? '—' ) ); ?></td>
										<td><?php echo esc_html( (string) ( $entry_diag['level_raw'] ?? '—' ) ); ?></td>
										<td><?php echo esc_html( (string) ( $entry_diag['level_normalized'] ?? '—' ) ); ?></td>
										<td><?php echo esc_html( implode( ', ', array_map( 'sanitize_key', (array) ( $entry_diag['reasons'] ?? array() ) ) ) ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>
			</div>
			<?php endif; ?>
			<?php if ( ! empty( $preview['groups_preview'] ) && is_array( $preview['groups_preview'] ) ) : ?>
			<div class="ufsc-fightgen-precheck">
				<h3><?php esc_html_e( 'Groupes détectés avant génération', 'ufsc-licence-competition' ); ?></h3>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'ufsc_competitions_save_fight_settings' ); ?>
					<input type="hidden" name="action" value="ufsc_competitions_save_fight_settings">
					<input type="hidden" name="competition_id" value="<?php echo esc_attr( $competition_id ); ?>">
				<ul>
					<?php foreach ( $preview['groups_preview'] as $group_row ) : ?>
						<li>
							<strong><?php echo esc_html( (string) ( $group_row['group_key'] ?? '—' ) ); ?></strong>
							— <?php echo esc_html( sprintf( __( '%1$d athlètes, %2$d combats estimés', 'ufsc-licence-competition' ), (int) ( $group_row['entries_count'] ?? 0 ), (int) ( $group_row['estimated_fights'] ?? 0 ) ) ); ?>
								— <?php echo esc_html( sprintf( __( 'Format conseillé : %s', 'ufsc-licence-competition' ), (string) ( $group_row['format'] ?? 'tableau' ) ) ); ?>
								— <?php echo esc_html( sprintf( __( 'BYE estimés : %d', 'ufsc-licence-competition' ), (int) ( $group_row['bye_slots'] ?? 0 ) ) ); ?>
								<?php if ( ! empty( $group_row['recommendation'] ) && is_array( $group_row['recommendation'] ) ) : ?>
									— <?php echo esc_html( sprintf( __( 'Recommandation : %s', 'ufsc-licence-competition' ), (string) ( $group_row['recommendation']['label'] ?? '' ) ) ); ?>
									— <?php echo esc_html( (string) ( $group_row['recommendation']['explanation'] ?? '' ) ); ?>
								<?php endif; ?>
							— <span class="<?php echo esc_attr( self::status_badge_class( ( 'generable' === ( $group_row['status'] ?? '' ) ) ? 'ok' : 'warn' ) ); ?>"><?php echo esc_html( 'generable' === ( $group_row['status'] ?? '' ) ? __( 'Générable', 'ufsc-licence-competition' ) : __( 'Insuffisant', 'ufsc-licence-competition' ) ); ?></span>
								<?php if ( ! empty( $group_row['lone_fighter'] ) ) : ?>
									— <span class="<?php echo esc_attr( self::status_badge_class( 'warn' ) ); ?>"><?php esc_html_e( 'Combattant seul dans sa catégorie', 'ufsc-licence-competition' ); ?></span>
								<?php endif; ?>
								<br>
								<label>
									<?php esc_html_e( 'Choix admin', 'ufsc-licence-competition' ); ?> :
									<select name="group_generation_options[<?php echo esc_attr( (string) ( $group_row['group_key'] ?? '' ) ); ?>][format]">
										<?php $selected_group_format = sanitize_key( (string) ( $settings['group_generation_options'][ (string) ( $group_row['group_key'] ?? '' ) ]['format'] ?? 'auto' ) ); ?>
										<option value="auto" <?php selected( $selected_group_format, 'auto' ); ?>><?php esc_html_e( 'Auto / recommandation', 'ufsc-licence-competition' ); ?></option>
										<option value="direct" <?php selected( $selected_group_format, 'direct' ); ?>><?php esc_html_e( 'Combat direct', 'ufsc-licence-competition' ); ?></option>
										<option value="pool" <?php selected( $selected_group_format, 'pool' ); ?>><?php esc_html_e( 'Poule complète', 'ufsc-licence-competition' ); ?></option>
										<option value="bracket" <?php selected( $selected_group_format, 'bracket' ); ?>><?php esc_html_e( 'Tableau', 'ufsc-licence-competition' ); ?></option>
										<option value="bracket_bye" <?php selected( $selected_group_format, 'bracket_bye' ); ?>><?php esc_html_e( 'Tableau avec BYE', 'ufsc-licence-competition' ); ?></option>
										<option value="small_final" <?php selected( $selected_group_format, 'small_final' ); ?>><?php esc_html_e( 'Petite finale', 'ufsc-licence-competition' ); ?></option>
										<option value="repechage" <?php selected( $selected_group_format, 'repechage' ); ?>><?php esc_html_e( 'Repêchage', 'ufsc-licence-competition' ); ?></option>
										<option value="wait" <?php selected( $selected_group_format, 'wait' ); ?>><?php esc_html_e( 'Laisser en attente', 'ufsc-licence-competition' ); ?></option>
									</select>
								</label>
						</li>
					<?php endforeach; ?>
				</ul>
					<?php submit_button( __( 'Enregistrer les choix de format par groupe', 'ufsc-licence-competition' ), 'secondary', '', false ); ?>
				</form>
			</div>
			<?php endif; ?>

			<ul>
				<li><?php echo esc_html( sprintf( __( 'Inscriptions totales : %d', 'ufsc-licence-competition' ), (int) ( $counters['total_entries'] ?? 0 ) ) ); ?></li>
				<li><?php echo esc_html( sprintf( __( 'Inscriptions éligibles : %d', 'ufsc-licence-competition' ), (int) ( $counters['eligible_entries'] ?? 0 ) ) ); ?></li>
				<li><?php echo esc_html( sprintf( __( 'Exclues (non pesées) : %d', 'ufsc-licence-competition' ), (int) ( $counters['excluded_unweighed'] ?? 0 ) ) ); ?></li>
				<li><?php echo esc_html( sprintf( __( 'Byes estimés (si tableau incomplet) : %d', 'ufsc-licence-competition' ), (int) ( $draft_stats['bye_slots'] ?? 0 ) ) ); ?></li>
			</ul>
			<details class="ufsc-competitions-helper-details">
				<summary><?php esc_html_e( 'Aide rapide génération (ordre, poules, byes, phases)', 'ufsc-licence-competition' ); ?></summary>
				<ul>
					<li><?php esc_html_e( 'Ordre par défaut : catégories jeunes puis âgées, puis légers vers lourds, puis sexe/discipline/niveau.', 'ufsc-licence-competition' ); ?></li>
					<li><?php esc_html_e( 'Formats auto : 2=finale directe, 3=poule, 4=demi-finales + finale, 5-8=tableau de 8, 9-16=tableau de 16.', 'ufsc-licence-competition' ); ?></li>
					<li><?php esc_html_e( 'Byes : les tableaux sont complétés à la puissance de 2 supérieure pour conserver un bracket lisible.', 'ufsc-licence-competition' ); ?></li>
					<li><?php esc_html_e( 'Phases usuelles : qualification, seizième, huitième, quart, demi-finale, finale.', 'ufsc-licence-competition' ); ?></li>
					<li><?php esc_html_e( 'Rouge/Bleu : attribution stable par ordre de génération, inversion manuelle disponible sur le brouillon.', 'ufsc-licence-competition' ); ?></li>
					<li><?php esc_html_e( 'Timing : profils catégorie si disponibles, sinon fallback global (durée/pause ci-dessus).', 'ufsc-licence-competition' ); ?></li>
				</ul>
			</details>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ufsc-competitions-form">
				<?php wp_nonce_field( 'ufsc_competitions_save_fight_settings' ); ?>
				<input type="hidden" name="action" value="ufsc_competitions_save_fight_settings">
				<input type="hidden" name="competition_id" value="<?php echo esc_attr( $competition_id ); ?>">
				<table class="form-table" role="presentation">
					<tr><th colspan="2"><h3 class="ufsc-fightgen-section-title"><?php esc_html_e( 'Informations générales', 'ufsc-licence-competition' ); ?></h3></th></tr>
					<tr>
						<th scope="row"><label for="ufsc_plateau_name"><?php esc_html_e( 'Nom du plateau', 'ufsc-licence-competition' ); ?></label></th>
						<td><input name="plateau_name" type="text" id="ufsc_plateau_name" class="regular-text" value="<?php echo esc_attr( $settings['plateau_name'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_surface_count"><?php esc_html_e( 'Nombre de surfaces', 'ufsc-licence-competition' ); ?></label></th>
						<td><input name="surface_count" type="number" min="1" id="ufsc_surface_count" value="<?php echo esc_attr( $settings['surface_count'] ); ?>">
									<p class="description"><?php esc_html_e( 'Ajoutez autant de surfaces que nécessaire pour votre compétition. Une limite technique de sécurité peut être appliquée côté serveur.', 'ufsc-licence-competition' ); ?></p></td>
					</tr>
					<tr>
						<th colspan="2"><h3 class="ufsc-fightgen-section-title"><?php esc_html_e( 'Surfaces de combat', 'ufsc-licence-competition' ); ?></h3></th>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Surfaces', 'ufsc-licence-competition' ); ?></th>
						<td>
							<div class="ufsc-competitions-surfaces ufsc-surfaces-manager" data-surface-count="<?php echo esc_attr( $settings['surface_count'] ); ?>">
								<div class="ufsc-surfaces-toolbar">
									<div class="ufsc-surfaces-toolbar__actions">
										<button type="button" class="button button-primary ufsc-add-surface ufsc-surface-main-action">+ <?php esc_html_e( 'Ajouter une surface', 'ufsc-licence-competition' ); ?></button>
										<button type="button" class="button ufsc-add-five-surfaces">+ <?php esc_html_e( 'Ajouter 5 surfaces', 'ufsc-licence-competition' ); ?></button>
										<button type="button" class="button ufsc-duplicate-last-surface"><?php esc_html_e( 'Dupliquer la dernière', 'ufsc-licence-competition' ); ?></button>
									</div>
									<p class="description"><?php esc_html_e( 'Les surfaces actives seront utilisées pour répartir automatiquement les combats.', 'ufsc-licence-competition' ); ?></p>
									<p class="ufsc-surfaces-counter" aria-live="polite"></p>
								</div>
								<div class="ufsc-surfaces-list">
								<?php
								$surface_details = function_exists( 'ufsc_competition_get_surfaces' )
									? ufsc_competition_get_surfaces( $competition_id, array( 'fallback_count' => (int) $settings['surface_count'] ) )
									: ( isset( $settings['surface_details'] ) && is_array( $settings['surface_details'] ) ? $settings['surface_details'] : array() );
								$surface_types = function_exists( 'ufsc_competition_get_surface_types' ) ? ufsc_competition_get_surface_types() : array( 'tatami' => 'Tatami', 'ring' => 'Ring', 'aire' => 'Aire', 'cage' => 'Cage', 'zone' => 'Zone', 'autre' => 'Autre' );
								foreach ( $surface_details as $i => $detail ) :
									$surface_name = (string) ( $detail['name'] ?? '' );
									$surface_type = (string) ( $detail['type'] ?? 'tatami' );
									?>
									<div class="ufsc-competitions-surface-row ufsc-surface-row">
										<div class="ufsc-surface-header"><span class="ufsc-surface-badge"><?php echo esc_html( sprintf( __( 'Surface %d', 'ufsc-licence-competition' ), $i + 1 ) ); ?></span></div>
										<div class="ufsc-surface-fields">
										<label>
											<?php esc_html_e( 'Nom de la surface', 'ufsc-licence-competition' ); ?>
											<input name="surface_details[<?php echo esc_attr( $i ); ?>][name]" type="text" class="regular-text" value="<?php echo esc_attr( $surface_name ); ?>" placeholder="<?php echo esc_attr( (string) ( $i + 1 ) ); ?>">
										</label>
										<label>
											<?php esc_html_e( 'Type', 'ufsc-licence-competition' ); ?>
											<select name="surface_details[<?php echo esc_attr( $i ); ?>][type]" required>
												<?php foreach ( $surface_types as $surface_type_key => $surface_type_label ) : ?>
													<option value="<?php echo esc_attr( (string) $surface_type_key ); ?>" <?php selected( $surface_type, (string) $surface_type_key ); ?>><?php echo esc_html( (string) $surface_type_label ); ?></option>
												<?php endforeach; ?>
											</select>
										</label>
										<label><?php esc_html_e( 'Code court', 'ufsc-licence-competition' ); ?><input name="surface_details[<?php echo esc_attr( $i ); ?>][short_label]" type="text" value="<?php echo esc_attr( (string) ( $detail['short_label'] ?? '' ) ); ?>" class="small-text"></label>
										<label><input type="checkbox" name="surface_details[<?php echo esc_attr( $i ); ?>][active]" value="1" <?php checked( ! empty( $detail['active'] ) ); ?>> <?php esc_html_e( 'Active', 'ufsc-licence-competition' ); ?></label>
										<input type="hidden" name="surface_details[<?php echo esc_attr( $i ); ?>][uuid]" value="<?php echo esc_attr( (string) ( $detail['uuid'] ?? '' ) ); ?>">
										<input type="hidden" class="ufsc-surface-order" name="surface_details[<?php echo esc_attr( $i ); ?>][order]" value="<?php echo esc_attr( (string) ( $detail['order'] ?? ( $i + 1 ) ) ); ?>">
										</div>
										<div class="ufsc-surface-actions">
											<button type="button" class="button ufsc-duplicate-surface"><?php esc_html_e( 'Dupliquer', 'ufsc-licence-competition' ); ?></button>
											<button type="button" class="button ufsc-remove-surface ufsc-surface-danger-action"><?php esc_html_e( 'Supprimer', 'ufsc-licence-competition' ); ?></button>
											<button type="button" class="button ufsc-move-surface-up ufsc-surface-move-action" title="<?php esc_attr_e( 'Monter cette surface', 'ufsc-licence-competition' ); ?>" aria-label="<?php esc_attr_e( 'Monter cette surface', 'ufsc-licence-competition' ); ?>"><?php esc_html_e( 'Monter', 'ufsc-licence-competition' ); ?></button>
											<button type="button" class="button ufsc-move-surface-down ufsc-surface-move-action" title="<?php esc_attr_e( 'Descendre cette surface', 'ufsc-licence-competition' ); ?>" aria-label="<?php esc_attr_e( 'Descendre cette surface', 'ufsc-licence-competition' ); ?>"><?php esc_html_e( 'Descendre', 'ufsc-licence-competition' ); ?></button>
										</div>
									</div>
								<?php endforeach; ?>
								</div>
							</div>
							<p class="description"><?php esc_html_e( 'Ajoutez autant de surfaces que nécessaire pour votre compétition.', 'ufsc-licence-competition' ); ?></p>
						</td>
					</tr>
					<tr><th colspan="2"><h3 class="ufsc-fightgen-section-title"><?php esc_html_e( 'Timing', 'ufsc-licence-competition' ); ?></h3></th></tr>
					<tr>
						<th scope="row"><label for="ufsc_fight_duration"><?php esc_html_e( 'Durée (minutes)', 'ufsc-licence-competition' ); ?></label></th>
						<td><input name="fight_duration" type="number" min="0" max="30" id="ufsc_fight_duration" value="<?php echo esc_attr( $settings['fight_duration'] ); ?>">
									<p class="description"><?php esc_html_e( 'Utilisé pour l’horaire estimé lorsque aucun profil timing n’est appliqué.', 'ufsc-licence-competition' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_fight_duration_seconds"><?php esc_html_e( 'Durée (secondes)', 'ufsc-licence-competition' ); ?></label></th>
						<td><input name="fight_duration_seconds" type="number" min="0" max="59" id="ufsc_fight_duration_seconds" value="<?php echo esc_attr( $settings['fight_duration_seconds'] ?? 0 ); ?>">
									<p class="description"><?php esc_html_e( 'Exemple: 1 minute 30 = 1 min + 30 sec.', 'ufsc-licence-competition' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_break_duration"><?php esc_html_e( 'Pause (minutes)', 'ufsc-licence-competition' ); ?></label></th>
						<td><input name="break_duration" type="number" min="0" max="30" id="ufsc_break_duration" value="<?php echo esc_attr( $settings['break_duration'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_break_duration_seconds"><?php esc_html_e( 'Pause (secondes)', 'ufsc-licence-competition' ); ?></label></th>
						<td><input name="break_duration_seconds" type="number" min="0" max="59" id="ufsc_break_duration_seconds" value="<?php echo esc_attr( $settings['break_duration_seconds'] ?? 0 ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Timing global effectif', 'ufsc-licence-competition' ); ?></th>
						<td>
							<?php
							$fight_minutes = (int) ( $settings['fight_duration'] ?? 0 );
							$fight_seconds = (int) ( $settings['fight_duration_seconds'] ?? 0 );
							$pause_minutes = (int) ( $settings['break_duration'] ?? 0 );
							$pause_seconds = (int) ( $settings['break_duration_seconds'] ?? 0 );
							printf(
								'<strong>%s</strong>',
								esc_html(
									sprintf(
										/* translators: 1: fight duration mm:ss, 2: break duration mm:ss */
										__( 'Combat: %1$s — Pause inter-combat: %2$s', 'ufsc-licence-competition' ),
										sprintf( '%02d:%02d', $fight_minutes, $fight_seconds ),
										sprintf( '%02d:%02d', $pause_minutes, $pause_seconds )
									)
								)
							);
							?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Lecture métier', 'ufsc-licence-competition' ); ?></th>
						<td>
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: round style label, 2: inter combat pause */
									__( 'Exemple de lecture: 1 round de %1$s, pause inter-combat %2$s.', 'ufsc-licence-competition' ),
									self::format_duration_label( ( (int) ( $settings['fight_duration'] ?? 0 ) * MINUTE_IN_SECONDS ) + (int) ( $settings['fight_duration_seconds'] ?? 0 ) ),
									self::format_duration_label( ( (int) ( $settings['break_duration'] ?? 0 ) * MINUTE_IN_SECONDS ) + (int) ( $settings['break_duration_seconds'] ?? 0 ) )
								)
							);
							?>
						</td>
					</tr>
					<tr><th colspan="2"><h3 class="ufsc-fightgen-section-title"><?php esc_html_e( 'Règles appliquées', 'ufsc-licence-competition' ); ?></h3></th></tr>
					<tr>
						<th scope="row"><label for="ufsc_timing_mode"><?php esc_html_e( 'Timing combats', 'ufsc-licence-competition' ); ?></label></th>
						<td>
							<select name="timing_mode" id="ufsc_timing_mode">
								<option value="global" <?php selected( $settings['timing_mode'], 'global' ); ?>><?php esc_html_e( 'Global (actuel)', 'ufsc-licence-competition' ); ?></option>
								<option value="category" <?php selected( $settings['timing_mode'], 'category' ); ?>><?php esc_html_e( 'Par catégories (profils)', 'ufsc-licence-competition' ); ?></option>
							</select>
							<p class="description">
								<?php
								printf(
									'%s <a href="%s">%s</a>. %s',
									esc_html__( 'Les profils se gèrent dans', 'ufsc-licence-competition' ),
									esc_url( admin_url( 'admin.php?page=ufsc-competitions-timing-profiles' ) ),
									esc_html__( 'Timing Profiles', 'ufsc-licence-competition' ),
									esc_html__( 'Si des profils existent déjà pour la discipline, ils sont appliqués automatiquement.', 'ufsc-licence-competition' )
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_generation_mode"><?php esc_html_e( 'Mode', 'ufsc-licence-competition' ); ?></label></th>
						<td>
							<select name="mode" id="ufsc_generation_mode">
								<option value="auto" <?php selected( $settings['mode'], 'auto' ); ?>><?php esc_html_e( 'Automatique', 'ufsc-licence-competition' ); ?></option>
								<option value="manual" <?php selected( $settings['mode'], 'manual' ); ?>><?php esc_html_e( 'Manuel', 'ufsc-licence-competition' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Le mode manuel désactive la génération automatique.', 'ufsc-licence-competition' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Override pesée', 'ufsc-licence-competition' ); ?></th>
						<td>
							<label>
								<input name="allow_unweighed" type="checkbox" value="1" <?php checked( $settings['allow_unweighed'] ?? 0, 1 ); ?>>
								<?php esc_html_e( 'Inclure les compétiteurs non pesés (override admin explicite)', 'ufsc-licence-competition' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Désactivé par défaut pour sécuriser la génération automatique.', 'ufsc-licence-competition' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Règles externes', 'ufsc-licence-competition' ); ?></th>
						<td>
							<label><input name="guardian_required_for_minors" type="checkbox" value="1" <?php checked( $settings['guardian_required_for_minors'] ?? 0, 1 ); ?>> <?php esc_html_e( 'Mineur externe sans représentant légal = blocant', 'ufsc-licence-competition' ); ?></label>
							<p class="description"><?php esc_html_e( 'Par défaut, ce point est un avertissement non bloquant pour la génération sportive.', 'ufsc-licence-competition' ); ?></p>
							<label><input name="use_level_split" type="checkbox" value="1" <?php checked( $settings['use_level_split'] ?? 0, 1 ); ?>> <?php esc_html_e( 'Utiliser le niveau comme critère de séparation', 'ufsc-licence-competition' ); ?></label>
							<p class="description"><?php esc_html_e( 'Par défaut, le niveau non défini ne bloque pas et ne segmente pas les groupes.', 'ufsc-licence-competition' ); ?></p>
							<label><input name="include_submitted_club" type="checkbox" value="1" <?php checked( $settings['include_submitted_club'] ?? 0, 1 ); ?>> <?php esc_html_e( 'Inclure les inscriptions soumises club dans la génération', 'ufsc-licence-competition' ); ?></label>
							<br>
							<label><input name="include_draft_test" type="checkbox" value="1" <?php checked( $settings['include_draft_test'] ?? 0, 1 ); ?>> <?php esc_html_e( 'Inclure les brouillons (mode test uniquement)', 'ufsc-licence-competition' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Verrouillage auto', 'ufsc-licence-competition' ); ?></th>
						<td>
							<label>
								<input name="auto_lock" type="checkbox" value="1" <?php checked( $settings['auto_lock'], 1 ); ?>>
								<?php esc_html_e( 'Empêcher la régénération automatique', 'ufsc-licence-competition' ); ?>
							</label>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Enregistrer les paramètres', 'ufsc-licence-competition' ) ); ?>
			</form>

			<?php if ( ! $competition_id ) : ?>
				<p class="description"><?php esc_html_e( 'Sélectionnez une compétition pour activer la génération.', 'ufsc-licence-competition' ); ?></p>
			<?php else : ?>
				<div class="ufsc-fightgen-preview">
					<h3><?php esc_html_e( 'Aperçu avant génération', 'ufsc-licence-competition' ); ?></h3>
					<ul>
						<li><?php echo esc_html( sprintf( __( 'Combats estimés : %d', 'ufsc-licence-competition' ), $estimated_fights ) ); ?></li>
						<li>
							<?php
							$surface_distribution = array_map( 'intval', (array) ( $preview['estimated_per_surface'] ?? array() ) );
							$surface_distribution_label = $surface_distribution ? implode( ' / ', $surface_distribution ) : '—';
							echo esc_html( sprintf( __( 'Répartition moyenne par surface : %s', 'ufsc-licence-competition' ), $surface_distribution_label ) );
							?>
						</li>
						<li><?php echo esc_html( sprintf( __( 'Catégories détectées : %d', 'ufsc-licence-competition' ), (int) ( $preview['estimated_categories'] ?? 0 ) ) ); ?></li>
						<li><?php echo esc_html( sprintf( __( 'Inscriptions exclues (non pesées) : %d', 'ufsc-licence-competition' ), (int) ( $preview['excluded_unweighed'] ?? 0 ) ) ); ?></li>
						<li><?php echo esc_html( sprintf( __( 'Byes estimés (si tableau incomplet) : %d', 'ufsc-licence-competition' ), (int) ( $draft_stats['bye_slots'] ?? 0 ) ) ); ?></li>
						<li><?php esc_html_e( 'Règles de tri : âge, poids, sexe, discipline, niveau, identifiant.', 'ufsc-licence-competition' ); ?></li>
					</ul>
				</div>
				<div class="ufsc-competitions-actions">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( self::nonce_action( 'ufsc_competitions_generate_fight_draft', $competition_id ) ); ?>
						<input type="hidden" name="action" value="ufsc_competitions_generate_fight_draft">
						<input type="hidden" name="competition_id" value="<?php echo esc_attr( $competition_id ); ?>">
						<input type="hidden" name="surface_count" value="<?php echo esc_attr( (string) (int) ( $settings['surface_count'] ?? 1 ) ); ?>">
						<input type="hidden" name="timing_mode" value="<?php echo esc_attr( (string) ( $settings['timing_mode'] ?? 'global' ) ); ?>">
						<input type="hidden" name="allow_unweighed" value="<?php echo esc_attr( (string) (int) ( $settings['allow_unweighed'] ?? 0 ) ); ?>">
						<input type="hidden" name="allow_compatible_disciplines" value="<?php echo esc_attr( (string) (int) ( $settings['allow_compatible_disciplines'] ?? 0 ) ); ?>">
						<input type="hidden" name="guardian_required_for_minors" value="<?php echo esc_attr( (string) (int) ( $settings['guardian_required_for_minors'] ?? 0 ) ); ?>">
						<input type="hidden" name="use_level_split" value="<?php echo esc_attr( (string) (int) ( $settings['use_level_split'] ?? 0 ) ); ?>">
						<input type="hidden" name="generation_mode" value="draft">
						<?php submit_button( __( 'Créer le brouillon des combats', 'ufsc-licence-competition' ), 'secondary', '', false, $can_generate_now ? array() : array( 'disabled' => 'disabled' ) ); ?>
					</form>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( self::nonce_action( 'ufsc_competitions_generate_fight_direct', $competition_id ) ); ?>
						<input type="hidden" name="action" value="ufsc_competitions_generate_fight_direct">
						<input type="hidden" name="competition_id" value="<?php echo esc_attr( $competition_id ); ?>">
						<input type="hidden" name="generation_mode" value="direct">
						<?php submit_button( __( 'Génération directe désactivée : utiliser le brouillon', 'ufsc-licence-competition' ), 'secondary', '', false, array( 'disabled' => 'disabled' ) ); ?>
					</form>
					<?php if ( ! empty( $counters['can_override_unweighed'] ) ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( self::nonce_action( 'ufsc_competitions_generate_fight_draft_override', $competition_id ) ); ?>
							<input type="hidden" name="action" value="ufsc_competitions_generate_fight_draft_override">
							<input type="hidden" name="competition_id" value="<?php echo esc_attr( $competition_id ); ?>">
							<?php submit_button( __( 'Override : inclure les non pesés', 'ufsc-licence-competition' ), 'secondary', '', false, $can_generate ? array( 'onclick' => "return confirm('" . esc_js( __( 'Confirmer l’override pesée pour cette génération ?', 'ufsc-licence-competition' ) ) . "');" ) : array( 'disabled' => 'disabled' ) ); ?>
						</form>
					<?php endif; ?>
					<?php if ( $has_draft ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( self::nonce_action( 'ufsc_competitions_regenerate_fight_draft', $competition_id ) ); ?>
							<input type="hidden" name="action" value="ufsc_competitions_regenerate_fight_draft">
							<input type="hidden" name="competition_id" value="<?php echo esc_attr( $competition_id ); ?>">
							<?php submit_button( __( 'Régénérer (supprime le draft existant)', 'ufsc-licence-competition' ), 'secondary', '', false, $can_generate ? array( 'onclick' => "return confirm('" . esc_js( __( 'Confirmer la régénération ? Le brouillon actuel sera supprimé.', 'ufsc-licence-competition' ) ) . "');" ) : array( 'disabled' => 'disabled' ) ); ?>
						</form>
					<?php endif; ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'ufsc_competitions_validate_fight_draft' ); ?>
						<input type="hidden" name="action" value="ufsc_competitions_validate_fight_draft">
						<input type="hidden" name="competition_id" value="<?php echo esc_attr( $competition_id ); ?>">
						<fieldset class="ufsc-competitions-inline-options">
							<legend class="screen-reader-text"><?php esc_html_e( 'Mode de validation', 'ufsc-licence-competition' ); ?></legend>
							<label>
								<input type="radio" name="apply_mode" value="append" checked>
								<?php esc_html_e( 'Ajouter à la suite (par défaut)', 'ufsc-licence-competition' ); ?>
							</label>
							<label>
								<input type="radio" name="apply_mode" value="replace" disabled>
								<?php esc_html_e( 'Remplacer les combats existants (désactivé)', 'ufsc-licence-competition' ); ?>
							</label>
							<label class="description">
								<input type="checkbox" name="confirm_replace" value="1" disabled>
								<?php esc_html_e( 'Je confirme la suppression des combats existants', 'ufsc-licence-competition' ); ?>
							</label>
						</fieldset>
						<?php submit_button( __( 'Valider et créer les combats', 'ufsc-licence-competition' ), 'primary', '', false, $can_validate_draft ? array() : array( 'disabled' => 'disabled' ) ); ?>
						<p class="description"><?php esc_html_e( 'Cette action créera réellement les combats en base. Un snapshot sera créé avant validation et un rollback ciblé sera tenté en cas d’erreur.', 'ufsc-licence-competition' ); ?></p>
					</form>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'ufsc_competitions_discard_fight_draft' ); ?>
						<input type="hidden" name="action" value="ufsc_competitions_discard_fight_draft">
						<input type="hidden" name="competition_id" value="<?php echo esc_attr( $competition_id ); ?>">
					<?php submit_button( __( 'Supprimer le brouillon', 'ufsc-licence-competition' ), 'secondary', '', false, $draft ? array() : array( 'disabled' => 'disabled' ) ); ?>
					</form>
				</div>

				<?php if ( $draft ) : ?>
					<div class="ufsc-fightgen-result">
					<p><strong><?php esc_html_e( 'État du brouillon', 'ufsc-licence-competition' ); ?></strong></p>
					<p>
						<span class="<?php echo esc_attr( self::status_badge_class( $draft_obsolete ? 'danger' : 'ok' ) ); ?>"><?php echo esc_html( $draft_obsolete ? __( 'Brouillon obsolète', 'ufsc-licence-competition' ) : __( 'Brouillon valide', 'ufsc-licence-competition' ) ); ?></span>
						<?php if ( ! empty( $settings['sandbox_generation'] ) ) : ?><span class="ufsc-badge ufsc-badge--warn"><?php esc_html_e( 'Sandbox', 'ufsc-licence-competition' ); ?></span><?php endif; ?>
						<?php if ( ! empty( $settings['include_draft_test'] ) ) : ?><span class="ufsc-badge ufsc-badge--muted"><?php esc_html_e( 'Données test', 'ufsc-licence-competition' ); ?></span><?php endif; ?>
						<?php if ( class_exists( GenerationLockService::class ) && GenerationLockService::is_generation_locked( $competition_id ) ) : ?><span class="ufsc-badge ufsc-badge--danger"><?php esc_html_e( 'Génération verrouillée', 'ufsc-licence-competition' ); ?></span><?php endif; ?>
					</p>
					<?php if ( $draft_obsolete ) : ?>
						<div class="notice notice-warning inline"><p><?php esc_html_e( 'Ce brouillon n’est plus à jour. Merci de régénérer la prévisualisation.', 'ufsc-licence-competition' ); ?></p></div>
					<?php endif; ?>
					<ul>
						<li><?php echo esc_html( sprintf( __( 'ID brouillon : %s', 'ufsc-licence-competition' ), (string) ( $draft['draft_id'] ?? '—' ) ) ); ?></li>
						<li><?php echo esc_html( sprintf( __( 'Créé le : %s', 'ufsc-licence-competition' ), (string) ( $draft['created_at'] ?? $draft['generated_at'] ?? '—' ) ) ); ?></li>
						<li><?php echo esc_html( sprintf( __( 'Créé par : #%d', 'ufsc-licence-competition' ), (int) ( $draft['created_by'] ?? $draft['generated_by'] ?? 0 ) ) ); ?></li>
						<li><?php echo esc_html( sprintf( __( 'Combats : %d', 'ufsc-licence-competition' ), (int) ( $draft_stats['fights'] ?? 0 ) ) ); ?></li>
						<li><?php echo esc_html( sprintf( __( 'Groupes : %d', 'ufsc-licence-competition' ), count( $draft_groups ) ) ); ?></li>
						<li><?php echo esc_html( sprintf( __( 'Combattants inclus : %d', 'ufsc-licence-competition' ), (int) ( $draft_summary['eligible_entries'] ?? $draft_stats['eligible_entries'] ?? 0 ) ) ); ?></li>
						<li><?php echo esc_html( sprintf( __( 'Combattants exclus : %d', 'ufsc-licence-competition' ), count( $draft_excluded_entries ) ) ); ?></li>
						<li><?php echo esc_html( sprintf( __( 'Surfaces : %d', 'ufsc-licence-competition' ), count( $draft_surfaces ) ) ); ?></li>
						<li><?php echo esc_html( sprintf( __( 'Byes générés : %d', 'ufsc-licence-competition' ), (int) ( $draft_stats['bye_slots'] ?? 0 ) ) ); ?></li>
						<li><?php echo esc_html( sprintf( __( 'Placeholders : %d', 'ufsc-licence-competition' ), (int) ( $draft_stats['placeholders'] ?? 0 ) ) ); ?></li>
						<li><?php echo esc_html( sprintf( __( 'Warnings : %d', 'ufsc-licence-competition' ), count( $draft_warnings ) ) ); ?></li>
						<li><?php echo esc_html( sprintf( __( 'Conflits numéros combattants : %d', 'ufsc-licence-competition' ), (int) ( $preview['duplicate_fighter_numbers'] ?? 0 ) ) ); ?></li>
					</ul>
						<div class="ufsc-fightgen-result__actions">
							<a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=' . Menu::PAGE_BOUTS . '&ufsc_competition_id=' . $competition_id ) ); ?>"><?php esc_html_e( 'Voir les combats', 'ufsc-licence-competition' ); ?></a>
							<?php if ( $has_draft ) : ?>
								<button type="button" class="button button-secondary" onclick="window.print();"><?php esc_html_e( 'Imprimer', 'ufsc-licence-competition' ); ?></button>
							<?php else : ?>
								<button type="button" class="button button-secondary" disabled><?php esc_html_e( 'Imprimer', 'ufsc-licence-competition' ); ?></button>
							<?php endif; ?>
							<button type="button" class="button button-secondary" disabled><?php esc_html_e( 'Exporter (bientôt)', 'ufsc-licence-competition' ); ?></button>
						</div>
					<?php if ( $draft_warnings ) : ?>
						<p><strong><?php esc_html_e( 'Alertes', 'ufsc-licence-competition' ); ?></strong></p>
						<ul>
							<?php foreach ( $draft_warnings as $warning ) : ?>
								<li><?php echo esc_html( $warning ); ?></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
					<?php if ( $has_draft ) : ?>
						<h4><?php esc_html_e( 'Groupes / catégories du brouillon', 'ufsc-licence-competition' ); ?></h4>
						<?php if ( ! empty( $draft_groups ) ) : ?>
							<div class="ufsc-competitions-table-wrap">
								<?php foreach ( $draft_groups as $group_index => $group_row ) : ?>
									<?php
									$group_row = is_array( $group_row ) ? $group_row : array();
									$group_label = (string) ( $group_row['group_key'] ?? sprintf( 'Groupe %d', (int) $group_index + 1 ) );
									$group_status_raw = sanitize_key( (string) ( $group_row['status'] ?? '' ) );
									$group_status_label = 'generable' === $group_status_raw ? __( 'Prêt', 'ufsc-licence-competition' ) : ( 'warning' === $group_status_raw ? __( 'Warning', 'ufsc-licence-competition' ) : __( 'À contrôler', 'ufsc-licence-competition' ) );
									$group_status_badge = 'generable' === $group_status_raw ? 'ok' : ( 'warning' === $group_status_raw ? 'warn' : 'danger' );
									$group_athletes = isset( $group_row['athletes'] ) && is_array( $group_row['athletes'] ) ? $group_row['athletes'] : array();
									$group_athletes_total = count( $group_athletes );
									$group_athletes_visible = array_slice( $group_athletes, 0, 20 );
									?>
									<details class="ufsc-fightgen-group-details" style="margin:8px 0;">
										<summary>
											<strong><?php echo esc_html( $group_label ); ?></strong>
											<span class="<?php echo esc_attr( self::status_badge_class( $group_status_badge ) ); ?>"><?php echo esc_html( $group_status_label ); ?></span>
											<span class="ufsc-badge ufsc-badge--muted"><?php echo esc_html( sprintf( __( '%d combattants', 'ufsc-licence-competition' ), (int) ( $group_row['entries_count'] ?? $group_athletes_total ) ) ); ?></span>
											<span class="ufsc-badge ufsc-badge--info"><?php echo esc_html( sprintf( __( 'Format : %s', 'ufsc-licence-competition' ), (string) ( $group_row['format'] ?? '—' ) ) ); ?></span>
											<span class="ufsc-badge ufsc-badge--muted"><?php echo esc_html( sprintf( __( 'Combats : %d', 'ufsc-licence-competition' ), (int) ( $group_row['estimated_fights'] ?? 0 ) ) ); ?></span>
											<span class="ufsc-badge ufsc-badge--warn"><?php echo esc_html( sprintf( __( 'BYE : %d', 'ufsc-licence-competition' ), (int) ( $group_row['bye_slots'] ?? 0 ) ) ); ?></span>
										</summary>
										<ul>
											<li><?php echo esc_html( sprintf( __( 'Discipline : %s', 'ufsc-licence-competition' ), (string) ( $group_row['discipline'] ?? '—' ) ) ); ?></li>
											<li><?php echo esc_html( sprintf( __( 'Sexe : %s', 'ufsc-licence-competition' ), (string) ( $group_row['sex'] ?? $group_row['gender'] ?? '—' ) ) ); ?></li>
											<li><?php echo esc_html( sprintf( __( 'Âge : %s', 'ufsc-licence-competition' ), (string) ( $group_row['age_category'] ?? '—' ) ) ); ?></li>
											<li><?php echo esc_html( sprintf( __( 'Catégorie de poids : %s', 'ufsc-licence-competition' ), (string) ( $group_row['weight_category'] ?? $group_row['weight_class'] ?? '—' ) ) ); ?></li>
											<li><?php echo esc_html( sprintf( __( 'Niveau / classe : %s', 'ufsc-licence-competition' ), (string) ( $group_row['level'] ?? $group_row['class'] ?? '—' ) ) ); ?></li>
											<li><?php echo esc_html( sprintf( __( 'Surface proposée : %s', 'ufsc-licence-competition' ), (string) ( $group_row['surface'] ?? '—' ) ) ); ?></li>
										</ul>
										<table class="widefat striped">
											<thead><tr><th><?php esc_html_e( 'Nom', 'ufsc-licence-competition' ); ?></th><th><?php esc_html_e( 'Prénom', 'ufsc-licence-competition' ); ?></th><th><?php esc_html_e( 'Club', 'ufsc-licence-competition' ); ?></th><th><?php esc_html_e( 'Sexe', 'ufsc-licence-competition' ); ?></th><th><?php esc_html_e( 'Date naissance', 'ufsc-licence-competition' ); ?></th><th><?php esc_html_e( 'Âge', 'ufsc-licence-competition' ); ?></th><th><?php esc_html_e( 'Poids déclaré', 'ufsc-licence-competition' ); ?></th><th><?php esc_html_e( 'Poids officiel', 'ufsc-licence-competition' ); ?></th><th><?php esc_html_e( 'Cat. poids', 'ufsc-licence-competition' ); ?></th><th><?php esc_html_e( 'N° combattant', 'ufsc-licence-competition' ); ?></th><th><?php esc_html_e( 'Statut inscription', 'ufsc-licence-competition' ); ?></th><th><?php esc_html_e( 'Statut pesée', 'ufsc-licence-competition' ); ?></th><th><?php esc_html_e( 'Bon pour combat', 'ufsc-licence-competition' ); ?></th></tr></thead>
											<tbody>
											<?php foreach ( $group_athletes_visible as $athlete ) : $athlete = is_array( $athlete ) ? $athlete : array(); ?>
												<tr>
													<td><?php echo esc_html( (string) ( $athlete['last_name'] ?? $athlete['nom'] ?? '—' ) ); ?></td>
													<td><?php echo esc_html( (string) ( $athlete['first_name'] ?? $athlete['prenom'] ?? '—' ) ); ?></td>
													<td><?php echo esc_html( (string) ( $athlete['club_name'] ?? $athlete['club'] ?? '—' ) ); ?></td>
													<td><?php echo esc_html( (string) ( $athlete['sex'] ?? '—' ) ); ?></td>
													<td><?php echo esc_html( (string) ( $athlete['birth_date'] ?? $athlete['date_naissance'] ?? '—' ) ); ?></td>
													<td><?php echo esc_html( (string) ( $athlete['age'] ?? '—' ) ); ?></td>
													<td><?php echo esc_html( (string) ( $athlete['weight_kg'] ?? $athlete['poids'] ?? $athlete['weight'] ?? '—' ) ); ?></td>
													<td><?php echo esc_html( (string) ( $athlete['official_weight'] ?? $athlete['measured_weight'] ?? '—' ) ); ?></td>
													<td><?php echo esc_html( (string) ( $athlete['weight_category'] ?? $athlete['weight_class'] ?? '—' ) ); ?></td>
													<td><?php echo esc_html( (string) ( $athlete['fighter_number'] ?? $athlete['numero_combattant'] ?? '—' ) ); ?></td>
													<td><?php echo esc_html( (string) ( $athlete['entry_status'] ?? $athlete['status'] ?? '—' ) ); ?></td>
													<td><?php echo esc_html( (string) ( $athlete['weighin_status'] ?? '—' ) ); ?></td>
													<td><?php echo esc_html( ! empty( $athlete['fight_ready'] ) ? __( 'Bon pour combat', 'ufsc-licence-competition' ) : __( 'Pesée manquante', 'ufsc-licence-competition' ) ); ?></td>
												</tr>
											<?php endforeach; ?>
											</tbody>
										</table>
										<?php if ( $group_athletes_total > 20 ) : ?>
											<p class="description"><?php echo esc_html( sprintf( __( '... et %d autres combattants dans ce groupe.', 'ufsc-licence-competition' ), $group_athletes_total - 20 ) ); ?></p>
										<?php endif; ?>
									</details>
								<?php endforeach; ?>
							</div>
						<?php else : ?>
							<p class="description"><?php esc_html_e( 'Aucun groupe détaillé disponible dans ce brouillon. Régénérez le brouillon pour obtenir une preview enrichie.', 'ufsc-licence-competition' ); ?></p>
						<?php endif; ?>
						<h4><?php esc_html_e( 'Combats prévus', 'ufsc-licence-competition' ); ?></h4>
						<div class="ufsc-competitions-table-wrap">
							<table class="widefat striped">
								<thead>
										<tr>
											<th><?php esc_html_e( 'Combat', 'ufsc-licence-competition' ); ?></th>
											<th><?php esc_html_e( 'Phase', 'ufsc-licence-competition' ); ?></th>
											<th><?php esc_html_e( 'Round', 'ufsc-licence-competition' ); ?></th>
											<th><?php esc_html_e( 'Type', 'ufsc-licence-competition' ); ?></th>
											<th><?php esc_html_e( 'Surface', 'ufsc-licence-competition' ); ?></th>
										<th><?php esc_html_e( 'Horaire estimé', 'ufsc-licence-competition' ); ?></th>
										<th><?php esc_html_e( 'Catégorie', 'ufsc-licence-competition' ); ?></th>
										<th><?php esc_html_e( 'Coin rouge', 'ufsc-licence-competition' ); ?></th>
										<th><?php esc_html_e( 'Coin bleu', 'ufsc-licence-competition' ); ?></th>
										<th><?php esc_html_e( 'Statut', 'ufsc-licence-competition' ); ?></th>
									</tr>
								</thead>
								<tbody>
										<?php foreach ( array_slice( (array) $draft_fights_preview, 0, 100 ) as $fight_row ) : ?>
											<tr>
												<td><?php echo esc_html( (string) (int) ( $fight_row['fight_no'] ?? 0 ) ); ?></td>
												<td><?php echo esc_html( (string) ( $fight_row['phase'] ?? '—' ) ); ?></td>
												<td><?php echo esc_html( (string) ( $fight_row['round_label'] ?? ( $fight_row['round_no'] ?? '—' ) ) ); ?></td>
												<td><?php echo esc_html( (string) ( $fight_row['type'] ?? ( ! empty( $fight_row['is_bye'] ) ? 'bye' : 'fight' ) ) ); ?></td>
												<td><?php echo esc_html( (string) ( $fight_row['ring'] ?? '—' ) ); ?></td>
											<td><?php echo esc_html( (string) ( $fight_row['scheduled_at'] ?? '—' ) ); ?></td>
											<td><?php echo esc_html( (string) ( $fight_row['category_label'] ?? $fight_row['category_id'] ?? '—' ) ); ?></td>
											<td><?php echo esc_html( (string) ( $fight_row['red_label'] ?? $fight_row['red_entry_id'] ?? 'TBD' ) ); ?></td>
												<td><?php echo esc_html( (string) ( $fight_row['blue_label'] ?? $fight_row['blue_entry_id'] ?? ( 'bye' === (string) ( $fight_row['type'] ?? '' ) ? 'BYE' : 'TBD' ) ) ); ?></td>
												<td><?php echo esc_html( (string) ( $fight_row['status'] ?? ( 'bye' === (string) ( $fight_row['type'] ?? '' ) ? 'bye' : 'scheduled' ) ) ); ?></td>
											</tr>
										<?php endforeach; ?>
								</tbody>
							</table>
						</div>
						<?php if ( count( $draft_fights_preview ) > 100 ) : ?>
							<p class="description"><?php echo esc_html( sprintf( __( 'Affichage limité aux 100 premiers combats prévus (%d au total).', 'ufsc-licence-competition' ), count( $draft_fights_preview ) ) ); ?></p>
						<?php endif; ?>
					<?php else : ?>
						<p class="description"><?php esc_html_e( 'Aucun combat dans le brouillon courant. Vérifiez les éligibilités puis relancez la génération.', 'ufsc-licence-competition' ); ?></p>
					<?php endif; ?>
					<h4><?php esc_html_e( 'Combattants exclus de la génération', 'ufsc-licence-competition' ); ?></h4>
					<?php if ( ! empty( $draft_excluded_entries ) ) : ?>
						<div class="ufsc-competitions-table-wrap">
							<table class="widefat striped">
								<thead><tr><th><?php esc_html_e( 'Nom', 'ufsc-licence-competition' ); ?></th><th><?php esc_html_e( 'Prénom', 'ufsc-licence-competition' ); ?></th><th><?php esc_html_e( 'Club', 'ufsc-licence-competition' ); ?></th><th><?php esc_html_e( 'Catégorie', 'ufsc-licence-competition' ); ?></th><th><?php esc_html_e( 'Raison', 'ufsc-licence-competition' ); ?></th><th><?php esc_html_e( 'Action recommandée', 'ufsc-licence-competition' ); ?></th></tr></thead>
								<tbody>
								<?php foreach ( array_slice( $draft_excluded_entries, 0, 100 ) as $excluded ) : ?>
									<tr>
										<td><?php echo esc_html( (string) ( $excluded['last_name'] ?? '' ) ); ?></td>
										<td><?php echo esc_html( (string) ( $excluded['first_name'] ?? '' ) ); ?></td>
										<td><?php echo esc_html( (string) ( $excluded['club_name'] ?? '—' ) ); ?></td>
										<td><?php echo esc_html( (string) ( $excluded['category'] ?? '—' ) ); ?></td>
										<td><?php echo esc_html( (string) ( $excluded['primary_reason'] ?? 'other_reason' ) ); ?></td>
										<td><?php echo esc_html( (string) ( $excluded['recommended_action'] ?? '' ) ); ?></td>
									</tr>
								<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					<?php else : ?>
						<p class="description"><?php esc_html_e( 'Aucun combattant exclu du brouillon.', 'ufsc-licence-competition' ); ?></p>
					<?php endif; ?>
					<h4><?php esc_html_e( 'Répartition par surface', 'ufsc-licence-competition' ); ?></h4>
					<ul>
						<?php foreach ( $draft_surfaces as $surface ) : ?>
							<li><?php echo esc_html( sprintf( '%s (%s)', (string) ( $surface['label'] ?? __( 'Surface non nommée — à renommer pour le jour J.', 'ufsc-licence-competition' ) ), (string) ( $surface['type'] ?? 'surface' ) ) ); ?></li>
						<?php endforeach; ?>
					</ul>
					</div>
				<?php endif; ?>

				<hr />

				<h3><?php esc_html_e( 'Actions rapides', 'ufsc-licence-competition' ); ?></h3>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ufsc-competitions-form">
					<?php wp_nonce_field( 'ufsc_competitions_recalc_fight_schedule' ); ?>
					<input type="hidden" name="action" value="ufsc_competitions_recalc_fight_schedule">
					<input type="hidden" name="competition_id" value="<?php echo esc_attr( $competition_id ); ?>">
					<?php submit_button( __( 'Recalculer surfaces & horaires', 'ufsc-licence-competition' ), 'secondary', '', false, $has_draft ? array() : array( 'disabled' => 'disabled' ) ); ?>
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ufsc-competitions-form">
					<?php wp_nonce_field( 'ufsc_competitions_swap_fight_colors' ); ?>
					<input type="hidden" name="action" value="ufsc_competitions_swap_fight_colors">
					<input type="hidden" name="competition_id" value="<?php echo esc_attr( $competition_id ); ?>">
					<label for="ufsc_swap_fight_id"><?php esc_html_e( 'N° combat du brouillon', 'ufsc-licence-competition' ); ?></label>
					<input name="fight_id" type="number" id="ufsc_swap_fight_id" min="1" />
					<?php submit_button( __( 'Inverser rouge/bleu', 'ufsc-licence-competition' ), 'secondary', '', false, $has_draft ? array() : array( 'disabled' => 'disabled' ) ); ?>
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ufsc-competitions-form">
					<?php wp_nonce_field( 'ufsc_competitions_reorder_fights' ); ?>
					<input type="hidden" name="action" value="ufsc_competitions_reorder_fights">
					<input type="hidden" name="competition_id" value="<?php echo esc_attr( $competition_id ); ?>">
					<label for="ufsc_reorder_mode"><?php esc_html_e( 'Réordonner par', 'ufsc-licence-competition' ); ?></label>
					<select name="mode" id="ufsc_reorder_mode">
						<option value="fight_no"><?php esc_html_e( 'Numéro de combat', 'ufsc-licence-competition' ); ?></option>
						<option value="scheduled"><?php esc_html_e( 'Horaire', 'ufsc-licence-competition' ); ?></option>
						<option value="category"><?php esc_html_e( 'Catégorie', 'ufsc-licence-competition' ); ?></option>
					</select>
					<?php submit_button( __( 'Réordonner', 'ufsc-licence-competition' ), 'secondary', '', false, $has_draft ? array() : array( 'disabled' => 'disabled' ) ); ?>
				</form>
			<?php endif; ?>
			<?php self::render_competition_selector_block( $competition_id, $competition, $competition_options ); ?>

			<div class="ufsc-fightgen-precheck">
				<h3><?php esc_html_e( 'Mode test / Sandbox génération', 'ufsc-licence-competition' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Créez une compétition de test avec des athlètes fictifs pour vérifier la génération sans toucher aux vraies données.', 'ufsc-licence-competition' ); ?></p>
				<p class="description"><?php esc_html_e( 'Les scénarios “générables” créent des données complètes, pesées OK et catégories homogènes afin de tester toute la chaîne sans bloquant. Le scénario “anomalies” sert uniquement à tester le diagnostic d’erreurs.', 'ufsc-licence-competition' ); ?></p>
				<p><span class="ufsc-badge ufsc-badge--info">TEST</span> <span class="ufsc-badge ufsc-badge--muted"><?php esc_html_e( 'Données isolées', 'ufsc-licence-competition' ); ?></span> <span class="ufsc-badge ufsc-badge--warn"><?php esc_html_e( 'Suppression sécurisée', 'ufsc-licence-competition' ); ?></span></p>
				<p class="description"><?php esc_html_e( 'Réservé aux administrateurs. Les données créées sont marquées [TEST] et suivies pour suppression sécurisée.', 'ufsc-licence-competition' ); ?></p>
				<details class="ufsc-fightgen-help-details"><summary><strong><?php esc_html_e( 'Comprendre le mode test', 'ufsc-licence-competition' ); ?></strong></summary>
					<ul>
						<li><?php esc_html_e( 'Sandbox : mode isolé qui crée de fausses compétitions, clubs et combattants.', 'ufsc-licence-competition' ); ?></li>
						<li><?php esc_html_e( 'Scénarios générables : données complètes pour tester la génération sans bloquant.', 'ufsc-licence-competition' ); ?></li>
						<li><?php esc_html_e( 'Scénario anomalies : crée volontairement des erreurs pour tester le diagnostic.', 'ufsc-licence-competition' ); ?></li>
						<li><?php esc_html_e( 'BYE : passage automatique au tour suivant sans adversaire.', 'ufsc-licence-competition' ); ?></li>
						<li><?php esc_html_e( 'Brouillon / preview : aucun combat écrit tant que le brouillon n’est pas validé.', 'ufsc-licence-competition' ); ?></li>
					</ul>
				</details>
				<div class="ufsc-competitions-actions">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'ufsc_competitions_test_fixture_create' ); ?>
						<input type="hidden" name="action" value="ufsc_competitions_test_fixture_create">
						<input type="hidden" name="scenario" value="simple">
						<?php submit_button( __( 'Créer test simple générable', 'ufsc-licence-competition' ), 'secondary', '', false ); ?>
						<p class="description"><?php esc_html_e( 'Crée quelques catégories simples avec 2 combattants par catégorie.', 'ufsc-licence-competition' ); ?></p>
					</form>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'ufsc_competitions_test_fixture_create' ); ?>
						<input type="hidden" name="action" value="ufsc_competitions_test_fixture_create">
						<input type="hidden" name="scenario" value="brackets">
						<?php submit_button( __( 'Créer test tableaux/BYE', 'ufsc-licence-competition' ), 'secondary', '', false ); ?>
						<p class="description"><?php esc_html_e( 'Crée des catégories de 3 à 8 combattants pour vérifier tableaux et BYE.', 'ufsc-licence-competition' ); ?></p>
					</form>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'ufsc_competitions_test_fixture_create' ); ?>
						<input type="hidden" name="action" value="ufsc_competitions_test_fixture_create">
						<input type="hidden" name="scenario" value="pools">
						<?php submit_button( __( 'Créer test poules', 'ufsc-licence-competition' ), 'secondary', '', false ); ?>
						<p class="description"><?php esc_html_e( 'Crée des poules de 3 à 6 combattants.', 'ufsc-licence-competition' ); ?></p>
					</form>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'ufsc_competitions_test_fixture_create' ); ?>
						<input type="hidden" name="action" value="ufsc_competitions_test_fixture_create">
						<input type="hidden" name="scenario" value="anomalies">
						<?php submit_button( __( 'Créer test anomalies', 'ufsc-licence-competition' ), 'secondary', '', false ); ?>
						<p class="description"><?php esc_html_e( 'Crée volontairement des données incomplètes pour tester le diagnostic.', 'ufsc-licence-competition' ); ?></p>
					</form>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Confirmer la réinitialisation du jeu de test ?', 'ufsc-licence-competition' ) ); ?>');">
						<?php wp_nonce_field( 'ufsc_competitions_test_fixture_reset' ); ?>
						<input type="hidden" name="action" value="ufsc_competitions_test_fixture_reset">
						<?php submit_button( __( 'Réinitialiser les données test', 'ufsc-licence-competition' ), 'secondary', '', false ); ?>
					</form>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Suppression définitive des données [TEST] suivies. Confirmer ?', 'ufsc-licence-competition' ) ); ?>');">
						<?php wp_nonce_field( 'ufsc_competitions_test_fixture_delete' ); ?>
						<input type="hidden" name="action" value="ufsc_competitions_test_fixture_delete">
						<?php submit_button( __( 'Supprimer uniquement les données test', 'ufsc-licence-competition' ), 'delete', '', false ); ?>
						<p class="description"><?php esc_html_e( 'Supprime uniquement les données marquées test / sandbox.', 'ufsc-licence-competition' ); ?></p>
					</form>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'ufsc_competitions_test_fixture_run' ); ?>
						<input type="hidden" name="action" value="ufsc_competitions_test_fixture_run">
						<label><input type="checkbox" name="auto_validate" value="1"> <?php esc_html_e( 'Valider automatiquement les combats test après création du brouillon', 'ufsc-licence-competition' ); ?></label>
						<?php submit_button( __( 'Créer + générer test complet', 'ufsc-licence-competition' ), 'primary', '', false ); ?>
					</form>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( 'ufsc_competitions_test_fixture_open150' ); ?>
							<input type="hidden" name="action" value="ufsc_competitions_test_fixture_open150">
						<?php submit_button( __( 'Créer test complet OPEN150', 'ufsc-licence-competition' ), 'secondary', '', false ); ?>
						<p class="description"><?php esc_html_e( 'Crée un volume important pour tester la performance.', 'ufsc-licence-competition' ); ?></p>
						</form>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'ufsc_competitions_test_fixture_open150_generate' ); ?>
						<input type="hidden" name="action" value="ufsc_competitions_test_fixture_open150_generate">
						<?php submit_button( __( 'Créer + générer test Open 150', 'ufsc-licence-competition' ), 'primary', '', false ); ?>
					</form>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'ufsc_competitions_test_fixture_add_results' ); ?>
						<input type="hidden" name="action" value="ufsc_competitions_test_fixture_add_results">
						<?php submit_button( __( 'Ajouter résultats fictifs au test', 'ufsc-licence-competition' ), 'secondary', '', false ); ?>
						<p class="description"><?php esc_html_e( 'Ajoute des résultats sur une compétition test pour vérifier les podiums provisoires.', 'ufsc-licence-competition' ); ?></p>
					</form>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'ufsc_competitions_assign_fighter_numbers' ); ?>
						<input type="hidden" name="action" value="ufsc_competitions_assign_fighter_numbers">
						<input type="hidden" name="competition_id" value="<?php echo esc_attr( $competition_id ); ?>">
						<label><input type="checkbox" name="force_reassign" value="1"> <?php esc_html_e( 'Réattribuer tous les numéros (action sensible)', 'ufsc-licence-competition' ); ?></label>
						<?php submit_button( __( 'Attribuer les numéros combattants', 'ufsc-licence-competition' ), 'secondary', '', false ); ?>
					</form>
				</div>
				<?php if ( $competition_id > 0 ) : ?>
					<p><strong><?php esc_html_e( 'Liens rapides test', 'ufsc-licence-competition' ); ?> :</strong>
					<?php
					$links = array(
						'Diagnostic' => add_query_arg( array( 'page' => Menu::PAGE_BOUTS, 'competition_id' => $competition_id ), admin_url( 'admin.php' ) ),
						'Preview' => add_query_arg( array( 'page' => Menu::PAGE_BOUTS, 'competition_id' => $competition_id ), admin_url( 'admin.php' ) ),
						'Combats' => add_query_arg( array( 'page' => Menu::PAGE_BOUTS, 'competition_id' => $competition_id ), admin_url( 'admin.php' ) ),
						'Impressions' => add_query_arg( array( 'page' => Menu::PAGE_PRINT, 'competition_id' => $competition_id ), admin_url( 'admin.php' ) ),
						'Répartition surface' => add_query_arg( array( 'page' => Menu::PAGE_PRINT, 'competition_id' => $competition_id, 'print_type' => 'fights_by_surface' ), admin_url( 'admin.php' ) ),
					);
					$out = array();
					foreach ( $links as $label => $url ) { $out[] = '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>'; }
					echo wp_kses_post( implode( ' | ', $out ) );
					?></p>
				<?php endif; ?>

		</div>
		<?php
	}

	public static function handle_save_settings(): void {
		$competition_id = self::resolve_competition_id( isset( $_POST['competition_id'] ) ? absint( $_POST['competition_id'] ) : 0 );
		self::guard_action( 'ufsc_competitions_save_fight_settings', $competition_id );
		if ( function_exists( 'ufsc_competition_save_surfaces' ) ) {
			ufsc_competition_save_surfaces( $competition_id, wp_unslash( $_POST['surface_details'] ?? array() ) );
		}
		$surface_notice = '';
		if ( function_exists( 'ufsc_competition_get_surfaces' ) ) {
			$surfaces = (array) ufsc_competition_get_surfaces( $competition_id );
			$active   = 0;
			foreach ( $surfaces as $surface ) {
				if ( ! empty( $surface['active'] ) ) {
					$active++;
				}
			}
			$surface_notice = sprintf( 'Surfaces sauvegardées : %1$d surfaces, %2$d actives.', count( $surfaces ), $active );
		}
		$result = FightAutoGenerationService::save_settings_with_result( $competition_id, wp_unslash( $_POST ) );
		if ( empty( $result['ok'] ) ) {
			self::redirect( $competition_id, 'invalid_settings', (string) ( $result['message'] ?? '' ) );
		}
		self::redirect( $competition_id, 'settings_saved', $surface_notice );
	}

	public static function handle_generate_draft(): void {
		$competition_id = self::resolve_competition_id( isset( $_POST['competition_id'] ) ? absint( $_POST['competition_id'] ) : 0 );
		self::guard_action( self::nonce_action( 'ufsc_competitions_generate_fight_draft', $competition_id ), $competition_id );
		if ( ! $competition_id ) {
			self::redirect( 0, 'action_error', __( 'Compétition invalide : sélectionnez une compétition active avant la génération.', 'ufsc-licence-competition' ) );
		}

		$settings = FightAutoGenerationService::get_settings( $competition_id );
		$result = FightAutoGenerationService::generate_draft( $competition_id, $settings );
		self::redirect( $competition_id, $result['ok'] ? 'draft_ready' : 'draft_error', $result['message'] ?? '' );
	}

	public static function handle_generate_draft_override(): void {
		$competition_id = self::resolve_competition_id( isset( $_POST['competition_id'] ) ? absint( $_POST['competition_id'] ) : 0 );
		self::guard_action( self::nonce_action( 'ufsc_competitions_generate_fight_draft_override', $competition_id ), $competition_id );
		if ( ! $competition_id ) {
			self::redirect( 0, 'action_error', __( 'Compétition invalide.', 'ufsc-licence-competition' ) );
		}

		$settings = FightAutoGenerationService::get_settings( $competition_id );
		$settings['allow_unweighed'] = 1;
		$result = FightAutoGenerationService::generate_draft( $competition_id, $settings );
		self::redirect( $competition_id, $result['ok'] ? 'draft_ready' : 'draft_error', $result['message'] ?? '' );
	}

	public static function handle_regenerate_draft(): void {
		$competition_id = self::resolve_competition_id( isset( $_POST['competition_id'] ) ? absint( $_POST['competition_id'] ) : 0 );
		self::guard_action( self::nonce_action( 'ufsc_competitions_regenerate_fight_draft', $competition_id ), $competition_id );
		if ( ! $competition_id ) {
			self::redirect( 0, 'action_error', __( 'Compétition invalide.', 'ufsc-licence-competition' ) );
		}
		$safety = ( new CompetitionSafetyService() )->guard_fight_generation( $competition_id, 'regenerate_fight_draft' );
		if ( empty( $safety['ok'] ) ) {
			self::redirect( $competition_id, 'action_protected', (string) ( $safety['message'] ?? __( 'Régénération bloquée par la protection des données réelles.', 'ufsc-licence-competition' ) ) );
		}
		if ( class_exists( GenerationLockService::class ) && GenerationLockService::is_generation_locked( $competition_id ) && ! Capabilities::user_can_regenerate_fights() ) {
			( new LogService() )->audit( 'sensitive_action_blocked', $competition_id, 'competition', $competition_id, array( 'reason' => 'generation_locked' ) );
			self::redirect( $competition_id, 'action_protected', __( 'Régénération protégée : action sensible requise.', 'ufsc-licence-competition' ) );
		}

		FightAutoGenerationService::clear_draft( $competition_id );
		$settings = FightAutoGenerationService::get_settings( $competition_id );
		$result = FightAutoGenerationService::generate_draft( $competition_id, $settings );
		self::redirect( $competition_id, $result['ok'] ? 'draft_ready' : 'draft_error', $result['message'] ?? '' );
	}

	public static function handle_validate_draft(): void {
		$competition_id = self::resolve_competition_id( isset( $_POST['competition_id'] ) ? absint( $_POST['competition_id'] ) : 0 );
		self::guard_action( 'ufsc_competitions_validate_fight_draft', $competition_id );
		$apply_mode = isset( $_POST['apply_mode'] ) ? sanitize_key( wp_unslash( $_POST['apply_mode'] ) ) : 'append';
		$safety = ( new CompetitionSafetyService() )->guard_fight_generation( $competition_id, 'validate_fight_draft', array( 'apply_mode' => $apply_mode ) );
		if ( empty( $safety['ok'] ) ) {
			self::redirect( $competition_id, 'action_protected', (string) ( $safety['message'] ?? __( 'Validation bloquée par la protection des données réelles.', 'ufsc-licence-competition' ) ) );
		}
		if ( 'replace' === $apply_mode ) {
			self::redirect( $competition_id, 'action_error', __( 'Le mode remplacement n’est pas disponible.', 'ufsc-licence-competition' ) );
		}

		$result = FightAutoGenerationService::validate_and_apply_draft( $competition_id, $apply_mode );
		self::redirect( $competition_id, $result['ok'] ? 'draft_validated' : 'action_error', $result['message'] ?? '' );
	}
	public static function handle_generate_direct(): void {
		$competition_id = self::resolve_competition_id( isset( $_POST['competition_id'] ) ? absint( $_POST['competition_id'] ) : 0 );
		self::guard_action( self::nonce_action( 'ufsc_competitions_generate_fight_direct', $competition_id ), $competition_id );
		if ( ! Capabilities::current_user_can( Capabilities::SENSITIVE_OPS_CAPABILITY, $competition_id ) || empty( $_POST['confirm_direct_generation_preview'] ) ) {
			( new LogService() )->audit( 'generation_blocked', $competition_id, 'competition', $competition_id, array( 'reason' => 'direct_generation_disabled' ) );
			self::redirect( $competition_id, 'action_protected', __( 'La génération directe est désactivée : générez un brouillon, vérifiez la prévisualisation puis validez.', 'ufsc-licence-competition' ) );
		}

		$settings = FightAutoGenerationService::get_settings( $competition_id );
		$draft_result = FightAutoGenerationService::generate_draft( $competition_id, $settings );
		if ( empty( $draft_result['ok'] ) ) {
			self::redirect( $competition_id, 'draft_error', (string) ( $draft_result['message'] ?? '' ) );
		}
		self::redirect( $competition_id, 'draft_ready', __( 'Brouillon créé. Validation manuelle requise avant enregistrement.', 'ufsc-licence-competition' ) );
	}

	public static function handle_discard_draft(): void {
		$competition_id = self::resolve_competition_id( isset( $_POST['competition_id'] ) ? absint( $_POST['competition_id'] ) : 0 );
		self::guard_action( 'ufsc_competitions_discard_fight_draft', $competition_id );
		FightAutoGenerationService::clear_draft( $competition_id );
		self::redirect( $competition_id, 'draft_discarded' );
	}

	public static function handle_recalc_schedule(): void {
		$competition_id = self::resolve_competition_id( isset( $_POST['competition_id'] ) ? absint( $_POST['competition_id'] ) : 0 );
		self::guard_action( 'ufsc_competitions_recalc_fight_schedule', $competition_id );
		$safety = ( new CompetitionSafetyService() )->guard_fight_generation( $competition_id, 'recalc_fight_schedule' );
		if ( empty( $safety['ok'] ) ) {
			self::redirect( $competition_id, 'action_protected', (string) ( $safety['message'] ?? __( 'Recalcul bloqué par la protection des données réelles.', 'ufsc-licence-competition' ) ) );
		}
		if ( function_exists( 'ufsc_competition_save_surfaces' ) && isset( $_POST['surface_details'] ) ) {
			ufsc_competition_save_surfaces( $competition_id, wp_unslash( $_POST['surface_details'] ) );
		}
		$settings = FightAutoGenerationService::get_settings( $competition_id );
		$result = FightAutoGenerationService::recalc_schedule( $competition_id, $settings );
		if ( function_exists( 'ufsc_competition_assign_surfaces_and_times' ) ) {
			$assignment = ufsc_competition_assign_surfaces_and_times( $competition_id, array(), $settings );
			$msg = sprintf( __( '%1$d combats analysés, %2$d assignés, %3$d surfaces utilisées, %4$d sensibles ignorés.', 'ufsc-licence-competition' ), (int) ( $assignment['modifiable_fights'] ?? 0 ), (int) ( $assignment['assigned_fights'] ?? 0 ), (int) ( $assignment['surfaces_used'] ?? 0 ), (int) ( $assignment['skipped_sensitive'] ?? 0 ) );
			if ( ! empty( $assignment['last_sql_error'] ) ) {
				$msg .= ' SQL: ' . sanitize_text_field( (string) $assignment['last_sql_error'] );
			}
			self::redirect( $competition_id, ! empty( $assignment['success'] ) ? 'schedule_recalc' : 'action_error', $msg );
		}
		self::redirect( $competition_id, $result['ok'] ? 'schedule_recalc' : 'action_error', $result['message'] ?? '' );
	}

	public static function handle_swap_colors(): void {
		$competition_id = self::resolve_competition_id( isset( $_POST['competition_id'] ) ? absint( $_POST['competition_id'] ) : 0 );
		self::guard_action( 'ufsc_competitions_swap_fight_colors', $competition_id );
		$fight_id = isset( $_POST['fight_id'] ) ? absint( $_POST['fight_id'] ) : 0;
		if ( ! $fight_id ) {
			self::redirect( $competition_id, 'action_error', __( 'Combat invalide.', 'ufsc-licence-competition' ) );
		}
		$result = FightAutoGenerationService::swap_colors( $competition_id, $fight_id );
		self::redirect( $competition_id, $result['ok'] ? 'swap_ok' : 'action_error', $result['message'] ?? '' );
	}

	public static function handle_reorder_fights(): void {
		$competition_id = self::resolve_competition_id( isset( $_POST['competition_id'] ) ? absint( $_POST['competition_id'] ) : 0 );
		self::guard_action( 'ufsc_competitions_reorder_fights', $competition_id );
		$mode = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'fight_no';
		$result = FightAutoGenerationService::reorder_fights( $competition_id, $mode );
		self::redirect( $competition_id, $result['ok'] ? 'reorder_ok' : 'action_error', $result['message'] ?? '' );
	}


	public static function handle_test_fixture_create(): void {
		self::guard_action( 'ufsc_competitions_test_fixture_create', 0 );
		$scenario = isset( $_POST['scenario'] ) ? sanitize_key( wp_unslash( $_POST['scenario'] ) ) : 'simple';
		$result = self::create_test_fixture( $scenario );
		$competition_id = (int) ( $result['competition_id'] ?? 0 );
		$message = (string) ( $result['message'] ?? '' );
		if ( $competition_id > 0 ) {
			$message .= sprintf( ' [TEST] competition_id=%d', $competition_id );
		}
		self::redirect( $competition_id, empty( $result['ok'] ) ? 'action_error' : 'settings_saved', $message );
	}

	public static function handle_test_fixture_reset(): void {
		self::guard_action( 'ufsc_competitions_test_fixture_reset', 0 );
		self::delete_test_fixture();
		$result = self::create_test_fixture( 'simple' );
		self::redirect( (int) ( $result['competition_id'] ?? 0 ), empty( $result['ok'] ) ? 'action_error' : 'settings_saved', (string) ( $result['message'] ?? '' ) );
	}

	public static function handle_test_fixture_delete(): void {
		self::guard_action( 'ufsc_competitions_test_fixture_delete', 0 );
		$deleted = self::delete_test_fixture();
		( new LogService() )->audit( 'sandbox_deleted', 0, 'sandbox', 0, array( 'deleted_count' => (int) $deleted ) );
		self::redirect( 0, 'settings_saved', sprintf( __( 'Données de test supprimées: %d éléments.', 'ufsc-licence-competition' ), $deleted ) );
	}

	public static function handle_test_fixture_run(): void {
		self::guard_action( 'ufsc_competitions_test_fixture_run', 0 );
		$fixture = self::create_test_fixture( 'simple' );
		if ( empty( $fixture['ok'] ) ) { self::redirect( 0, 'action_error', (string) ( $fixture['message'] ?? '' ) ); }
		$competition_id = (int) $fixture['competition_id'];
		$settings = FightAutoGenerationService::get_settings( $competition_id );
		$settings['sandbox_generation'] = 1;
		$settings['allow_unweighed'] = 1;
		$preview = FightAutoGenerationService::get_generation_preview( $competition_id, $settings );
		$draft = FightAutoGenerationService::generate_draft( $competition_id, $settings );
		$fixture_ids = get_option( 'ufsc_generation_test_fixture_ids', array() );
		$entries_total = count( (array) ( $fixture_ids['entries'] ?? array() ) );
		$counters = FightAutoGenerationService::get_generation_counters( $competition_id, $settings );
		$msg = sprintf(
			'[TEST] comp=%1$d entries=%2$d approved=%3$d groups=%4$d generables=%5$d estimated=%6$d draft=%7$s',
			$competition_id,
			$entries_total,
			(int) ( $counters['eligible_entries'] ?? 0 ),
			(int) ( $preview['estimated_categories'] ?? 0 ),
			(int) ( $preview['estimated_categories'] ?? 0 ),
			(int) ( $preview['estimated_fights'] ?? 0 ),
			! empty( $draft['ok'] ) ? 'yes' : 'no'
		);
		if ( ! empty( $_POST['auto_validate'] ) && ! empty( $draft['ok'] ) ) {
			$apply = FightAutoGenerationService::validate_and_apply_draft( $competition_id, 'append' );
			$stats = is_array( $apply['stats'] ?? null ) ? $apply['stats'] : array();
			$msg .= sprintf(
				' | validate=%1$s inserts=%2$d/%3$d fights_created=%4$d %5$s',
				! empty( $apply['ok'] ) ? 'yes' : 'no',
				(int) ( $stats['inserts_success'] ?? 0 ),
				(int) ( $stats['inserts_attempted'] ?? 0 ),
				(int) ( $stats['inserts_success'] ?? 0 ),
				(string) ( $apply['message'] ?? '' )
			);
		}
		self::redirect( $competition_id, 'settings_saved', $msg );
	}

	public static function handle_test_fixture_open150(): void {
		self::guard_action( 'ufsc_competitions_test_fixture_open150', 0 );
		$result = self::create_test_fixture_open150();
		self::redirect( (int) ( $result['competition_id'] ?? 0 ), empty( $result['ok'] ) ? 'action_error' : 'settings_saved', (string) ( $result['message'] ?? '' ) );
	}

	public static function handle_test_fixture_open150_generate(): void {
		self::guard_action( 'ufsc_competitions_test_fixture_open150_generate', 0 );
		$fixture = self::create_test_fixture_open150();
		if ( empty( $fixture['ok'] ) ) {
			self::redirect( 0, 'action_error', (string) ( $fixture['message'] ?? '' ) );
		}
		$competition_id = (int) ( $fixture['competition_id'] ?? 0 );
		$settings = FightAutoGenerationService::get_settings( $competition_id );
		$settings['sandbox_generation'] = 1;
		$settings['direct_generation_context'] = 'test_fixture';
		$settings['allow_unweighed'] = 1;
		$preview = FightAutoGenerationService::get_generation_preview( $competition_id, $settings );
		$draft = FightAutoGenerationService::generate_draft( $competition_id, $settings );
		$apply = ! empty( $draft['ok'] ) ? FightAutoGenerationService::validate_and_apply_draft( $competition_id, 'append' ) : array( 'ok' => false );
		if ( empty( $apply['ok'] ) || (int) ( $apply['stats']['inserts_success'] ?? 0 ) <= 0 ) {
			$fallback = FightAutoGenerationService::generate_simple_pairing_fights( $competition_id, $settings );
			$msg = sprintf(
				'[OPEN150] fallback inserts=%1$d/%2$d groups=%3$d fights_est=%4$d',
				(int) ( $fallback['successful_inserts'] ?? 0 ),
				(int) ( $fallback['attempted_inserts'] ?? 0 ),
				(int) ( $preview['estimated_categories'] ?? 0 ),
				(int) ( $preview['estimated_fights'] ?? 0 )
			);
			self::redirect( $competition_id, ! empty( $fallback['ok'] ) ? 'draft_validated' : 'action_error', $msg );
		}
		$msg = sprintf(
			'[OPEN150] draft/apply inserts=%1$d/%2$d groups=%3$d fights_est=%4$d',
			(int) ( $apply['stats']['inserts_success'] ?? 0 ),
			(int) ( $apply['stats']['inserts_attempted'] ?? 0 ),
			(int) ( $preview['estimated_categories'] ?? 0 ),
			(int) ( $preview['estimated_fights'] ?? 0 )
		);
		self::redirect( $competition_id, 'draft_validated', $msg );
	}

	public static function handle_assign_fighter_numbers(): void {
		$competition_id = self::resolve_competition_id( isset( $_POST['competition_id'] ) ? absint( $_POST['competition_id'] ) : 0 );
		self::guard_action( 'ufsc_competitions_assign_fighter_numbers', $competition_id );
		global $wpdb;
		$entries_table = Db::entries_table();
		$fights_table  = Db::fights_table();
		$force = ! empty( $_POST['force_reassign'] );
		$fights_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$fights_table} WHERE competition_id = %d", $competition_id ) );
		if ( $fights_count > 0 && ! $force ) {
			self::redirect( $competition_id, 'action_error', 'Numérotation bloquée: des combats existent déjà. Cochez la réattribution sensible si nécessaire.' );
		}
		$entries = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$entries_table} WHERE competition_id = %d AND (deleted_at IS NULL OR deleted_at='') ORDER BY category ASC, weight_class ASC, club_name ASC, last_name ASC, first_name ASC, id ASC", $competition_id ) );
		$assigned = 0; $kept = 0; $i = 1;
		foreach ( (array) $entries as $entry ) {
			$current = function_exists( 'ufsc_competition_get_fighter_number' ) ? ufsc_competition_get_fighter_number( $entry ) : '';
			if ( '' !== $current && ! $force ) { $kept++; continue; }
			$number = str_pad( (string) $i, 3, '0', STR_PAD_LEFT );
			$wpdb->update( $entries_table, array( 'fighter_number' => $number ), array( 'id' => (int) $entry->id ), array( '%s' ), array( '%d' ) );
			$assigned++; $i++;
		}
		$duplicates = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM (SELECT fighter_number, COUNT(*) c FROM {$entries_table} WHERE competition_id=%d AND fighter_number IS NOT NULL AND fighter_number<>'' GROUP BY fighter_number HAVING c>1) t", $competition_id ) );
		$msg = sprintf( 'Numéros combattants: total=%1$d, attribués=%2$d, conservés=%3$d, doublons=%4$d, combats_existants=%5$d', count( (array) $entries ), $assigned, $kept, $duplicates, $fights_count );
		self::redirect( $competition_id, 'settings_saved', $msg );
	}

	public static function handle_record_fight_result(): void {
		$competition_id = self::resolve_competition_id( isset( $_POST['competition_id'] ) ? absint( $_POST['competition_id'] ) : 0 );
		self::guard_action( 'ufsc_competitions_record_fight_result', $competition_id );
		if ( ! Capabilities::user_can_record_results() ) {
			( new LogService() )->audit( 'sensitive_action_blocked', $competition_id, 'competition', $competition_id, array( 'reason' => 'missing_result_record_capability' ) );
			self::redirect( $competition_id, 'action_protected', __( 'Droit saisie résultat requis.', 'ufsc-licence-competition' ) );
		}
		$fight_id = absint( $_POST['fight_id'] ?? 0 );
		if ( $fight_id <= 0 ) {
			self::redirect( $competition_id, 'action_error', 'Combat invalide.' );
		}
		global $wpdb;
		$table = Db::fights_table();
		$fight = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%d", $fight_id ) );
		if ( ! $fight ) {
			self::redirect( $competition_id, 'action_error', 'Combat introuvable.' );
		}
		if ( (int) ( $fight->competition_id ?? 0 ) !== $competition_id ) {
			self::redirect( $competition_id, 'action_error', 'Combat hors compétition.' );
		}
		$status = sanitize_key( (string) ( $fight->status ?? '' ) );
		$is_correction = 'completed' === $status;
		$safety = ( new CompetitionSafetyService() )->guard_fight_result_mutation( $competition_id, $fight_id, 'record_fight_result', $is_correction );
		if ( empty( $safety['ok'] ) ) {
			self::redirect( $competition_id, 'action_error', (string) ( $safety['message'] ?? __( 'Saisie résultat bloquée par la protection des données réelles.', 'ufsc-licence-competition' ) ) );
		}
		if ( in_array( $status, array( 'running', 'locked' ), true ) ) {
			self::redirect( $competition_id, 'action_error', 'Combat verrouillé/en cours: utilisez Actions sensibles.' );
		}
		if ( 'completed' === $status && ( empty( $_POST['force_sensitive'] ) || ! Capabilities::user_can_correct_results() ) ) {
			( new LogService() )->audit( 'sensitive_action_blocked', $competition_id, 'fight', $fight_id, array( 'reason' => 'result_correction_requires_capability', 'old_payload' => $fight ) );
			self::redirect( $competition_id, 'action_error', 'Résultat déjà saisi: correction via Actions sensibles avec droit de correction.' );
		}
		$admin_reason = sanitize_text_field( (string) ( $_POST['correction_reason'] ?? '' ) );
		if ( 'completed' === $status && '' === $admin_reason ) {
			self::redirect( $competition_id, 'action_error', 'Motif obligatoire pour corriger un résultat terminé.' );
		}
		$result_type = sanitize_key( (string) ( $_POST['result_type'] ?? '' ) );
		$winner_slot = sanitize_key( (string) ( $_POST['winner_slot'] ?? '' ) );
		$winner_entry_id = 0;
		if ( 'red' === $winner_slot ) {
			$winner_entry_id = (int) ( $fight->red_entry_id ?? 0 );
		} elseif ( 'blue' === $winner_slot ) {
			$winner_entry_id = (int) ( $fight->blue_entry_id ?? 0 );
		}
		$result_note = sanitize_text_field( (string) ( $_POST['result_note'] ?? '' ) );
		$result_text = strtoupper( $winner_slot ?: 'nc' ) . '|' . strtoupper( $result_type ?: 'decision' ) . ( $result_note ? '|' . $result_note : '' );
		$update = array( 'status' => 'completed', 'result' => $result_text, 'updated_at' => current_time( 'mysql' ) );
		$formats = array( '%s', '%s', '%s' );
		foreach ( array( 'winner_entry_id' => $winner_entry_id ?: null, 'result_type' => $result_type ?: null, 'result_note' => $result_note ?: null, 'completed_at' => current_time( 'mysql' ) ) as $col => $value ) {
			$exists = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $col ) );
			if ( $exists ) { $update[ $col ] = $value; $formats[] = is_int( $value ) ? '%d' : '%s'; }
		}
		$wpdb->update( $table, $update, array( 'id' => $fight_id ), $formats, array( '%d' ) );
		$old_winner_entry_id = (int) ( $fight->winner_entry_id ?? 0 );
		$old_result = (string) ( $fight->result ?? '' );
		$propagation = self::maybe_propagate_winner( $fight, $winner_entry_id, $old_winner_entry_id );
		self::log_result_correction_event( $competition_id, (int) $fight_id, $old_result, $result_text, $old_winner_entry_id, $winner_entry_id, $propagation, $admin_reason );
		( new LogService() )->audit( 'completed' === $status ? 'result_corrected' : 'result_recorded', $competition_id, 'fight', (int) $fight_id, array( 'old_payload' => $fight, 'new_payload' => $update, 'reason' => $admin_reason, 'propagation' => $propagation ) );
		$diag = sprintf(
			'Résultat combat #%1$d | winner=%2$d | next=%3$d | slot=%4$s | propagation=%5$s (%6$s)',
			(int) ( $fight->fight_no ?? $fight_id ),
			(int) $winner_entry_id,
			(int) ( $propagation['next_fight_id'] ?? 0 ),
			(string) ( $propagation['next_slot'] ?? '-' ),
			! empty( $propagation['propagated'] ) ? 'oui' : 'non',
			(string) ( $propagation['reason'] ?? 'n/a' )
		);
		self::redirect( $competition_id, 'settings_saved', $diag );
	}

	private static function maybe_propagate_winner( $fight, int $winner_entry_id, int $old_winner_entry_id = 0 ): array {
		global $wpdb;
		$result = array( 'propagated' => false, 'next_fight_id' => (int) ( $fight->next_fight_id ?? 0 ), 'next_slot' => (string) ( $fight->next_slot ?? '' ), 'reason' => 'no_winner', 'old_slot_occupant' => 0 );
		if ( $winner_entry_id <= 0 ) { return $result; }
		$next_fight_id = (int) ( $fight->next_fight_id ?? 0 );
		$next_slot = sanitize_key( (string) ( $fight->next_slot ?? '' ) );
		if ( $next_fight_id <= 0 ) { $result['reason'] = 'no_next_fight'; return $result; }
		if ( ! in_array( $next_slot, array( 'red', 'blue' ), true ) ) { $result['reason'] = 'invalid_next_slot'; return $result; }
		$table = Db::fights_table();
		$next_fight = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%d", $next_fight_id ) );
		if ( ! $next_fight ) { $result['reason'] = 'next_fight_not_found'; return $result; }
		$next_status = sanitize_key( (string) ( $next_fight->status ?? '' ) );
		if ( in_array( $next_status, array( 'running', 'completed', 'locked' ), true ) ) { $result['reason'] = 'next_fight_protected'; return $result; }
		$slot_column = 'red' === $next_slot ? 'red_entry_id' : 'blue_entry_id';
		$current_value = (int) ( $next_fight->{$slot_column} ?? 0 );
		$result['old_slot_occupant'] = $current_value;
		if ( $old_winner_entry_id > 0 && $current_value > 0 && $current_value !== $old_winner_entry_id && $current_value !== $winner_entry_id ) { $result['reason'] = 'slot_not_from_source_fight'; return $result; }
		if ( $current_value > 0 && $current_value !== $winner_entry_id ) { $result['reason'] = 'slot_already_occupied'; return $result; }
		$updated = $wpdb->update( $table, array( $slot_column => $winner_entry_id, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $next_fight_id ), array( '%d', '%s' ), array( '%d' ) );
		if ( false === $updated ) { $result['reason'] = 'db_update_failed'; return $result; }
		$result['propagated'] = true;
		$result['reason'] = 'propagated';
		return $result;
	}

	private static function log_result_correction_event( int $competition_id, int $fight_id, string $old_result, string $new_result, int $old_winner_entry_id, int $new_winner_entry_id, array $propagation, string $admin_reason ): void {
		$payload = array(
			'user_id' => get_current_user_id(),
			'competition_id' => $competition_id,
			'fight_id' => $fight_id,
			'old_result' => $old_result,
			'new_result' => $new_result,
			'old_winner_entry_id' => $old_winner_entry_id,
			'new_winner_entry_id' => $new_winner_entry_id,
			'next_fight_id' => (int) ( $propagation['next_fight_id'] ?? 0 ),
			'next_slot' => (string) ( $propagation['next_slot'] ?? '' ),
			'propagated' => ! empty( $propagation['propagated'] ) ? 1 : 0,
			'reason' => (string) ( $propagation['reason'] ?? '' ),
			'admin_reason' => $admin_reason,
			'timestamp' => current_time( 'mysql' ),
		);
		if ( function_exists( 'error_log' ) ) {
			error_log( 'UFSC result correction audit: ' . wp_json_encode( $payload ) );
		}
	}

	private static function create_test_fixture( string $scenario = 'simple' ): array {
		self::delete_test_fixture();
		$comp_repo = new CompetitionRepository();
		$entry_repo = new EntryRepository();
		$season = gmdate( 'Y' ) . '/' . (string) ( (int) gmdate( 'Y' ) + 1 );
		$competition_id = (int) $comp_repo->save( array( 'name' => '[TEST] Génération combats', 'discipline' => 'light_contact', 'type' => 'open', 'season' => $season, 'status' => 'open' ) );
		if ( $competition_id <= 0 ) { return array( 'ok' => false, 'message' => __( 'Impossible de créer la compétition test.', 'ufsc-licence-competition' ) ); }
		FightAutoGenerationService::save_settings( $competition_id, array( 'surface_count' => 2, 'fight_duration' => 2, 'break_duration' => 1 ) );
		$ids = array( 'competition_id' => $competition_id, 'entries' => array(), 'weighins' => array() );
		$scenario = in_array( $scenario, array( 'simple', 'brackets', 'pools', 'anomalies' ), true ) ? $scenario : 'simple';
		if ( 'pools' === $scenario ) {
			$rows = array();
			foreach ( array( array( 'Cadet', 'M', '60kg', 3 ), array( 'Junior', 'F', '55kg', 4 ), array( 'Senior', 'M', '70kg', 5 ), array( 'Senior', 'F', '65kg', 6 ) ) as $group ) {
				for ( $n = 1; $n <= $group[3]; $n++ ) { $rows[] = array( $group[1], $group[2], $group[0], 'approved', true, 'light_contact', 'Club Test Poule ' . $n ); }
			}
		} elseif ( 'brackets' === $scenario ) {
			$rows = array();
			$defs = array( array( 'Senior', 'M', '70kg', 3 ), array( 'Senior', 'M', '75kg', 4 ), array( 'Senior', 'M', '80kg', 5 ), array( 'Senior', 'M', '85kg', 6 ), array( 'Senior', 'M', '90kg', 7 ), array( 'Senior', 'M', '95kg', 8 ) );
			foreach ( $defs as $idx => $group ) {
				for ( $n = 1; $n <= $group[3]; $n++ ) {
					$rows[] = array( $group[1], $group[2], $group[0], 'approved', true, 'light_contact', 'Club Test Tableau ' . ( ( $idx + $n ) % 4 + 1 ) );
				}
			}
		} elseif ( 'anomalies' === $scenario ) {
			$rows = array(
				array('M','70kg','Senior','approved',true,'light_contact','Club Test Nord'),
				array('M','','Senior','approved',true,'light_contact','Club Test Sans Poids'),
				array('F','55kg','Junior','approved',true,'light_contact',''),
				array('F','55kg','Junior','draft',false,'light_contact','Club Non Eligible'),
				array('M','90kg','Veteran','approved',true,'light_contact','Club Seul'),
			);
		} else {
			$rows = array();
			foreach ( array( array( 'Senior', 'M', '70kg' ), array( 'Senior', 'M', '80kg' ), array( 'Junior', 'F', '55kg' ), array( 'Minime', 'M', '45kg' ) ) as $group ) {
				for ( $n = 1; $n <= 4; $n++ ) { $rows[] = array( $group[1], $group[2], $group[0], 'approved', true, 'light_contact', 'Club Test ' . $n ); }
			}
		}
		$i=1; foreach($rows as $r){
			$eid=(int)$entry_repo->insert(array('competition_id'=>$competition_id,'status'=>$r[3],'first_name'=>'Test'.$i,'last_name'=>'Athlete','sex'=>$r[0],'category'=>$r[2],'category_name'=>$r[2],'weight_class'=>$r[1],'weight_kg'=>(float) preg_replace('/[^0-9.]/','',$r[1]),'discipline'=>$r[5],'participant_type'=>'external','club_name'=>$r[6],'level'=>'non_defini','fighter_number'=>str_pad((string)$i,3,'0',STR_PAD_LEFT),'license_number'=>'TEST-LIC-'.$competition_id.'-'.$i,'birthdate'=>( 'anomalies' === $scenario && 3 === $i ? '' : '2000-01-'.str_pad((string) (($i%28)+1),2,'0',STR_PAD_LEFT)),'notes'=>'[TEST][sandbox][fixture][created_by_sandbox][test_batch_id=' . $competition_id . '] scenario=' . $scenario));
			if($eid>0){$ids['entries'][]=$eid; if($r[4]){self::insert_test_weighin($competition_id,$eid, ( 'anomalies' === $scenario && 2 === $i ) ? 'over' : 'ok' );}} $i++;
		}
		update_option( 'ufsc_generation_test_fixture_ids', $ids, false );
		return array( 'ok' => true, 'competition_id' => $competition_id, 'message' => __( 'Compétition et données de test créées (sandbox complète).', 'ufsc-licence-competition' ) );
	}

	public static function handle_test_fixture_add_results(): void {
		self::guard_action( 'ufsc_competitions_test_fixture_add_results', 0 );
		global $wpdb;
		$ids = get_option( 'ufsc_generation_test_fixture_ids', array() );
		$competition_id = absint( $ids['competition_id'] ?? 0 );
		if ( $competition_id <= 0 || ! self::is_tracked_test_competition( $competition_id ) ) {
			self::redirect( 0, 'action_error', __( 'Aucune compétition test active trouvée.', 'ufsc-licence-competition' ) );
		}
		$table = Db::fights_table();
		$fights = $wpdb->get_results( $wpdb->prepare( "SELECT id, red_entry_id, blue_entry_id, fight_no FROM {$table} WHERE competition_id=%d ORDER BY fight_order ASC, id ASC LIMIT 12", $competition_id ) );
		$updated = 0;
		foreach ( (array) $fights as $idx => $fight ) {
			$winner = ( $idx % 2 === 0 ) ? (int) $fight->red_entry_id : (int) $fight->blue_entry_id;
			if ( $winner <= 0 ) {
				continue;
			}
			$note = 0 === $idx ? 'LITIGE_TEST' : ( 1 === $idx ? 'ABSENCE_TEST' : 'TEST_OK' );
			$ok = $wpdb->update( $table, array( 'status' => 'completed', 'winner_entry_id' => $winner, 'result' => 'TEST|' . $note, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => (int) $fight->id ), array( '%s', '%d', '%s', '%s' ), array( '%d' ) );
			if ( false !== $ok ) {
				$updated++;
			}
		}
		( new LogService() )->audit(
			'sandbox_fake_results_added',
			$competition_id,
			'sandbox',
			$competition_id,
			array(
				'updated_fights' => $updated,
				'source' => 'handle_test_fixture_add_results',
			)
		);
		self::redirect( $competition_id, 'settings_saved', sprintf( __( 'Résultats fictifs ajoutés: %d combats complétés.', 'ufsc-licence-competition' ), $updated ) );
	}

	private static function create_test_fixture_open150(): array {
		self::delete_test_fixture();
		$comp_repo = new CompetitionRepository();
		$entry_repo = new EntryRepository();
		$season = gmdate( 'Y' ) . '/' . (string) ( (int) gmdate( 'Y' ) + 1 );
		$competition_id = (int) $comp_repo->save( array( 'name' => '[TEST] Open Light Contact 150 participants', 'discipline' => 'light_contact', 'type' => 'open', 'season' => $season, 'status' => 'open' ) );
		if ( $competition_id <= 0 ) {
			return array( 'ok' => false, 'message' => __( 'Impossible de créer la compétition Open 150.', 'ufsc-licence-competition' ) );
		}
		FightAutoGenerationService::save_settings( $competition_id, array( 'surface_count' => 6, 'fight_duration' => 2, 'break_duration' => 1, 'include_submitted_club' => 1 ) );
		$ids = array( 'competition_id' => $competition_id, 'entries' => array(), 'weighins' => array() );
		$categories = array( 'Educatif', 'Minime', 'Cadet', 'Junior', 'Senior', 'Veteran' );
		$weights = array( '45kg', '50kg', '55kg', '60kg', '65kg', '70kg', '75kg', '80kg', '85kg' );
		$first_names = array( 'Lucas', 'Emma', 'Hugo', 'Lina', 'Nathan', 'Maelys', 'Yanis', 'Lea', 'Noe', 'Chloe', 'Louis', 'Camille', 'Gabriel', 'Jade', 'Arthur' );
		$last_names = array( 'Martin', 'Dubois', 'Bernard', 'Moreau', 'Petit', 'Robert', 'Richard', 'Durand', 'Garcia', 'Faure', 'Andre', 'Roux', 'Mercier', 'Blanc', 'Guerin' );
		$clubs = array(
			'[TEST] MFC Montluçon','[TEST] Savate Club du Born','[TEST] Boxing Academy Lyon','[TEST] Team Combat Sud','[TEST] Fight School Paris',
			'[TEST] Ring Auvergne','[TEST] Impact Fight Club','[TEST] Kick Boxing Limoges','[TEST] Elite Combat Bordeaux','[TEST] Team Occitanie Fight',
			'[TEST] Boxing Club Marseille','[TEST] Full Contact Nice','[TEST] K1 Academy Lille','[TEST] Fight Spirit Toulouse','[TEST] Club Combat Atlantique'
		);
		$statuses = array_merge( array_fill( 0, 120, 'approved' ), array_fill( 0, 15, 'submitted' ), array_fill( 0, 8, 'draft' ), array_fill( 0, 7, 'rejected' ) );
		shuffle( $statuses );
		for ( $i = 1; $i <= 150; $i++ ) {
			$category = $categories[ ( $i - 1 ) % count( $categories ) ];
			$weight = $weights[ ( $i - 1 ) % count( $weights ) ];
			$sex = ( $i % 3 === 0 ) ? 'F' : 'M';
			$status = $statuses[ $i - 1 ] ?? 'approved';
			$is_external = $i > 120;
			$first_name = $first_names[ ( $i - 1 ) % count( $first_names ) ];
			$last_name = strtoupper( $last_names[ ( $i - 1 ) % count( $last_names ) ] );
			$entry_id = (int) $entry_repo->insert( array(
				'competition_id' => $competition_id,
				'status' => $status,
				'first_name' => $first_name,
				'last_name' => $last_name,
				'participant_name' => $last_name . ' ' . $first_name,
				'sex' => $sex,
				'category' => $category,
				'category_name' => $category,
				'weight_class' => $weight,
				'discipline' => 'light_contact',
				'participant_type' => $is_external ? 'external_non_licensed' : 'licensed_ufsc',
				'club_name' => $clubs[ ( $i - 1 ) % count( $clubs ) ],
				'level' => ( 'Senior' === $category ) ? 'classe_d' : 'non_defini',
				'fighter_number' => str_pad( (string) $i, 3, '0', STR_PAD_LEFT ),
				'license_number' => $is_external ? '' : 'OPEN150-' . $competition_id . '-' . $i,
				'birthdate' => '2000-01-' . str_pad( (string) ( ( $i % 28 ) + 1 ), 2, '0', STR_PAD_LEFT ),
				'notes' => '[TEST_GENERATION_OPEN150]',
			) );
			if ( $entry_id > 0 ) {
				$ids['entries'][] = $entry_id;
				if ( $status === 'approved' && $i % 10 !== 0 ) {
					self::insert_test_weighin( $competition_id, $entry_id );
				}
			}
		}
		update_option( 'ufsc_generation_test_fixture_ids', $ids, false );
		return array( 'ok' => true, 'competition_id' => $competition_id, 'message' => __( 'Open 150 créé (inscriptions, pesées partielles, statuts mixtes).', 'ufsc-licence-competition' ) );
	}

	private static function insert_test_weighin( int $competition_id, int $entry_id, string $status = 'ok' ): void {
		global $wpdb; $table = Db::weighins_table(); if ( ! Db::table_exists( $table ) ) { return; }
		$data = array( 'competition_id' => $competition_id, 'entry_id' => $entry_id, 'status' => sanitize_key( $status ), 'notes' => wp_json_encode( array( 'marker' => '[TEST_GENERATION]' ) ) );
		$formats = array( '%d', '%d', '%s', '%s' );
		$wpdb->insert( $table, $data, $formats );
	}

	private static function delete_test_fixture(): int {
		global $wpdb;
		$ids = get_option( 'ufsc_generation_test_fixture_ids', array() );
		$count = 0;
		$entry_ids = array_map( 'absint', (array) ( $ids['entries'] ?? array() ) );
		$competition_id = absint( $ids['competition_id'] ?? 0 );
		$is_test_competition = self::is_tracked_test_competition( $competition_id );

		if ( $entry_ids && $is_test_competition ) {
			foreach ( $entry_ids as $entry_id ) {
				if ( $entry_id <= 0 || ! self::is_tracked_test_entry( $entry_id, $competition_id ) ) {
					continue;
				}
				$wpdb->delete( Db::weighins_table(), array( 'competition_id' => $competition_id, 'entry_id' => $entry_id ), array( '%d', '%d' ) );
				$count += (int) $wpdb->rows_affected;
				$wpdb->delete( Db::entries_table(), array( 'id' => $entry_id, 'competition_id' => $competition_id ), array( '%d', '%d' ) );
				$count += (int) $wpdb->rows_affected;
			}
		}
		if ( $competition_id > 0 && $is_test_competition ) {
			$wpdb->delete( Db::fights_table(), array( 'competition_id' => $competition_id ), array( '%d' ) ); $count += (int) $wpdb->rows_affected;
			$wpdb->delete( Db::competitions_table(), array( 'id' => $competition_id ), array( '%d' ) ); $count += (int) $wpdb->rows_affected;
			delete_option( 'ufsc_competitions_fight_generation_draft_' . $competition_id );
		}
		delete_option( 'ufsc_generation_test_fixture_ids' );
		return $count;
	}

	private static function is_tracked_test_competition( int $competition_id ): bool {
		global $wpdb;
		if ( $competition_id <= 0 || ! Db::table_exists( Db::competitions_table() ) ) {
			return false;
		}
		$name = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT name FROM ' . Db::competitions_table() . ' WHERE id = %d LIMIT 1', $competition_id ) );
		return 0 === strpos( $name, '[TEST]' );
	}

	private static function is_tracked_test_entry( int $entry_id, int $competition_id ): bool {
		global $wpdb;
		if ( $entry_id <= 0 || $competition_id <= 0 || ! Db::table_exists( Db::entries_table() ) ) {
			return false;
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT id, competition_id FROM ' . Db::entries_table() . ' WHERE id = %d LIMIT 1', $entry_id ) );
		return $row && (int) ( $row->competition_id ?? 0 ) === $competition_id;
	}

	private static function render_competition_quick_dashboard( int $competition_id ): void {
		if ( ! $competition_id || ! function_exists( 'ufsc_comp_get_competition_summary' ) ) {
			return;
		}
		$summary = ufsc_comp_get_competition_summary( $competition_id );
		$competition = $summary['competition'] ?? null;
		$diagnostics = is_array( $summary['diagnostics'] ?? null ) ? $summary['diagnostics'] : array();
		$kpis = array(
			__( 'Total inscrits', 'ufsc-licence-competition' ) => (int) ( $summary['total_entries'] ?? 0 ),
			__( 'Éligibles', 'ufsc-licence-competition' ) => (int) ( $summary['eligible'] ?? 0 ),
			__( 'Pesées OK', 'ufsc-licence-competition' ) => (int) ( $summary['weighins']['ok'] ?? 0 ),
			__( 'À contrôler', 'ufsc-licence-competition' ) => (int) ( $summary['non_eligible'] ?? 0 ) + (int) ( $summary['weighins']['todo'] ?? 0 ) + (int) ( $summary['weighins']['over'] ?? 0 ),
			__( 'Catégories prêtes', 'ufsc-licence-competition' ) => (int) ( $summary['ready'] ?? 0 ),
			__( 'Sans opposant', 'ufsc-licence-competition' ) => (int) ( $summary['lone'] ?? 0 ),
			__( 'Combats générés', 'ufsc-licence-competition' ) => (int) ( $summary['fights']['total'] ?? 0 ),
			__( 'Combats terminés', 'ufsc-licence-competition' ) => (int) ( $summary['fights']['completed'] ?? 0 ),
		);
		?>
		<section class="ufsc-comp-dashboard ufsc-admin-surface">
			<div class="ufsc-comp-dashboard__header">
				<div>
					<h3><?php esc_html_e( 'Vue rapide compétition', 'ufsc-licence-competition' ); ?></h3>
					<p><?php echo esc_html( sprintf( '%1$s — %2$s — %3$s — %4$s', (string) ( $competition->name ?? __( 'Compétition', 'ufsc-licence-competition' ) ), (string) ( $competition->event_start_datetime ?? $competition->registration_deadline ?? '—' ), (string) ( $competition->discipline ?? '—' ), (string) ( $competition->venue_city ?? $competition->location ?? '—' ) ) ); ?></p>
				</div>
				<span class="ufsc-badge ufsc-badge--info"><?php echo esc_html( sprintf( __( '%d catégories détectées', 'ufsc-licence-competition' ), count( $diagnostics ) ) ); ?></span>
			</div>
			<div class="ufsc-comp-kpis">
				<?php foreach ( $kpis as $label => $value ) : ?>
					<article class="ufsc-comp-kpi"><span><?php echo esc_html( $label ); ?></span><strong><?php echo esc_html( number_format_i18n( $value ) ); ?></strong></article>
				<?php endforeach; ?>
			</div>
			<div class="ufsc-comp-dashboard__meta">
				<?php foreach ( (array) ( $summary['statuses'] ?? array() ) as $status => $count ) : ?>
					<span class="ufsc-badge ufsc-badge--muted"><?php echo esc_html( sprintf( '%s : %d', $status, (int) $count ) ); ?></span>
				<?php endforeach; ?>
				<span class="ufsc-badge ufsc-badge--muted"><?php echo esc_html( sprintf( __( 'Pesées à faire : %d', 'ufsc-licence-competition' ), (int) ( $summary['weighins']['todo'] ?? 0 ) ) ); ?></span>
				<span class="ufsc-badge ufsc-badge--muted"><?php echo esc_html( sprintf( __( 'Hors limite : %d', 'ufsc-licence-competition' ), (int) ( $summary['weighins']['over'] ?? 0 ) ) ); ?></span>
				<span class="ufsc-badge ufsc-badge--muted"><?php echo esc_html( sprintf( __( 'Planifiés : %d', 'ufsc-licence-competition' ), (int) ( $summary['fights']['scheduled'] ?? 0 ) ) ); ?></span>
				<span class="ufsc-badge ufsc-badge--muted"><?php echo esc_html( sprintf( __( 'Verrouillés : %d', 'ufsc-licence-competition' ), (int) ( $summary['fights']['locked'] ?? 0 ) ) ); ?></span>
			</div>
			<?php if ( (int) ( $summary['fights']['total'] ?? 0 ) > 0 ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'Des combats existent déjà pour cette compétition. Générez uniquement les catégories sans combat ou travaillez sur des données test pour éviter les doublons.', 'ufsc-licence-competition' ); ?></p></div>
			<?php endif; ?>
			<?php if ( $diagnostics ) : ?>
				<h4><?php esc_html_e( 'État des catégories', 'ufsc-licence-competition' ); ?></h4>
				<table class="widefat striped ufsc-category-diagnostics"><thead><tr><th><?php esc_html_e( 'Catégorie', 'ufsc-licence-competition' ); ?></th><th><?php esc_html_e( 'Sexe', 'ufsc-licence-competition' ); ?></th><th><?php esc_html_e( 'Poids', 'ufsc-licence-competition' ); ?></th><th><?php esc_html_e( 'Inscrits', 'ufsc-licence-competition' ); ?></th><th><?php esc_html_e( 'Éligibles', 'ufsc-licence-competition' ); ?></th><th><?php esc_html_e( 'Statut', 'ufsc-licence-competition' ); ?></th><th><?php esc_html_e( 'Recommandation', 'ufsc-licence-competition' ); ?></th><th><?php esc_html_e( 'Action possible', 'ufsc-licence-competition' ); ?></th></tr></thead><tbody>
				<?php foreach ( $diagnostics as $row ) : ?>
					<?php $badge = in_array( $row['status'], array( 'ready', 'pool', 'bracket' ), true ) ? 'ok' : ( 'lone' === $row['status'] ? 'warn' : 'muted' ); ?>
					<tr><td><?php echo esc_html( (string) ( $row['category'] ?: '—' ) ); ?></td><td><?php echo esc_html( (string) ( $row['sex'] ?: '—' ) ); ?></td><td><?php echo esc_html( (string) ( $row['weight'] ?: '—' ) ); ?></td><td><?php echo esc_html( (string) (int) $row['total'] ); ?></td><td><?php echo esc_html( (string) (int) $row['eligible'] ); ?></td><td><span class="<?php echo esc_attr( self::status_badge_class( $badge ) ); ?>"><?php echo esc_html( (string) $row['label'] ); ?></span></td><td><?php echo esc_html( (string) $row['recommendation'] ); ?></td><td><?php echo esc_html( (int) $row['eligible'] >= 2 ? __( 'Générer', 'ufsc-licence-competition' ) : __( 'Contrôler', 'ufsc-licence-competition' ) ); ?></td></tr>
				<?php endforeach; ?>
				</tbody></table>
			<?php endif; ?>
		</section>
		<?php
	}

	private static function render_generation_readiness_diagnostic( array $diagnostic, int $competition_id, bool $can_generate_now, bool $can_validate_draft ): void {
		$summary = is_array( $diagnostic['summary'] ?? null ) ? $diagnostic['summary'] : array();
		$status = sanitize_key( (string) ( $summary['status'] ?? 'blocked' ) );
		$badge_status = 'ready' === $status ? 'ok' : ( 'blocked' === $status ? 'danger' : 'warn' );
		$refresh_url = add_query_arg(
			array(
				'page' => Menu::PAGE_BOUTS,
				'ufsc_competition_id' => $competition_id,
			),
			admin_url( 'admin.php' )
		);
		$kpis = array(
			__( 'Score', 'ufsc-licence-competition' ) => (int) ( $summary['score'] ?? 0 ) . '/100',
			__( 'Générables', 'ufsc-licence-competition' ) => (int) ( $summary['entries_generable'] ?? 0 ),
			__( 'Catégories prêtes', 'ufsc-licence-competition' ) => (int) ( $summary['categories_ready'] ?? 0 ),
			__( 'Combats estimés', 'ufsc-licence-competition' ) => (int) ( $summary['estimated_fights'] ?? 0 ),
			__( 'Pesées manquantes', 'ufsc-licence-competition' ) => (int) ( $summary['weighins_missing'] ?? 0 ),
			__( 'Combats sensibles', 'ufsc-licence-competition' ) => (int) ( $summary['fights_sensitive'] ?? 0 ),
		);
		?>
		<section class="ufsc-fightgen-precheck ufsc-generation-diagnostic">
			<div class="ufsc-comp-dashboard__header">
				<div>
					<h3><?php esc_html_e( 'Diagnostic avant génération', 'ufsc-licence-competition' ); ?></h3>
					<p><?php esc_html_e( 'Contrôle professionnel des inscriptions, pesées, catégories, surfaces et combats existants avant toute génération réelle.', 'ufsc-licence-competition' ); ?></p>
				</div>
				<p>
					<span class="<?php echo esc_attr( self::status_badge_class( $badge_status ) ); ?>"><?php echo esc_html( (string) ( $summary['status_label'] ?? __( 'À contrôler', 'ufsc-licence-competition' ) ) ); ?></span>
					<a class="button button-secondary" href="<?php echo esc_url( $refresh_url ); ?>"><?php esc_html_e( 'Rafraîchir le diagnostic', 'ufsc-licence-competition' ); ?></a>
				</p>
			</div>

			<div class="ufsc-comp-kpis">
				<?php foreach ( $kpis as $label => $value ) : ?>
					<article class="ufsc-comp-kpi"><span><?php echo esc_html( $label ); ?></span><strong><?php echo esc_html( (string) $value ); ?></strong></article>
				<?php endforeach; ?>
			</div>

			<?php self::render_diagnostic_messages( __( 'Bloquants', 'ufsc-licence-competition' ), (array) ( $diagnostic['errors'] ?? array() ), 'danger' ); ?>
			<?php self::render_diagnostic_messages( __( 'Warnings', 'ufsc-licence-competition' ), (array) ( $diagnostic['warnings'] ?? array() ), 'warn' ); ?>
			<?php self::render_diagnostic_messages( __( 'Informations', 'ufsc-licence-competition' ), (array) ( $diagnostic['infos'] ?? array() ), 'info' ); ?>

			<?php if ( ! empty( $diagnostic['actions'] ) && is_array( $diagnostic['actions'] ) ) : ?>
				<h4><?php esc_html_e( 'Actions recommandées', 'ufsc-licence-competition' ); ?></h4>
				<ul>
					<?php foreach ( array_slice( $diagnostic['actions'], 0, 12 ) as $action ) : ?>
						<li>
							<span class="<?php echo esc_attr( self::status_badge_class( 'high' === ( $action['priority'] ?? '' ) ? 'danger' : 'warn' ) ); ?>"><?php echo esc_html( strtoupper( (string) ( $action['priority'] ?? 'medium' ) ) ); ?></span>
							<?php if ( ! empty( $action['url'] ) ) : ?>
								<a href="<?php echo esc_url( (string) $action['url'] ); ?>"><?php echo esc_html( (string) ( $action['label'] ?? '' ) ); ?></a>
							<?php else : ?>
								<?php echo esc_html( (string) ( $action['label'] ?? '' ) ); ?>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<?php foreach ( (array) ( $diagnostic['sections'] ?? array() ) as $section ) : ?>
				<?php $section_status = sanitize_key( (string) ( $section['status'] ?? 'warning' ) ); ?>
				<details class="ufsc-diagnostic-section" <?php echo 'blocked' === $section_status ? 'open' : ''; ?>>
					<summary><span class="<?php echo esc_attr( self::status_badge_class( 'ready' === $section_status ? 'ok' : ( 'blocked' === $section_status ? 'danger' : 'warn' ) ) ); ?>"><?php echo esc_html( self::diagnostic_section_label( $section_status ) ); ?></span> <?php echo esc_html( (string) ( $section['title'] ?? '' ) ); ?></summary>
					<?php if ( ! empty( $section['summary'] ) && is_array( $section['summary'] ) ) : ?>
						<ul class="ufsc-diagnostic-summary">
							<?php foreach ( $section['summary'] as $key => $value ) : ?>
								<li><?php echo esc_html( sanitize_key( (string) $key ) . ' : ' . ( is_scalar( $value ) ? (string) $value : wp_json_encode( $value ) ) ); ?></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
					<?php if ( ! empty( $section['items'] ) && is_array( $section['items'] ) ) : ?>
						<table class="widefat striped"><tbody>
							<?php foreach ( $section['items'] as $item ) : ?>
								<?php $item_status = sanitize_key( (string) ( $item['status'] ?? 'info' ) ); ?>
								<tr><td><span class="<?php echo esc_attr( self::status_badge_class( 'blocked' === $item_status ? 'danger' : ( 'ok' === $item_status || 'ready' === $item_status ? 'ok' : 'warn' ) ) ); ?>"><?php echo esc_html( self::diagnostic_section_label( $item_status ) ); ?></span></td><td><?php echo esc_html( (string) ( $item['message'] ?? '' ) ); ?></td></tr>
							<?php endforeach; ?>
						</tbody></table>
					<?php endif; ?>
				</details>
			<?php endforeach; ?>

			<p class="description">
				<?php echo esc_html( $can_generate_now ? __( 'Création de brouillon autorisée : aucun bloquant critique détecté.', 'ufsc-licence-competition' ) : __( 'Création de brouillon désactivée tant que les bloquants ne sont pas corrigés.', 'ufsc-licence-competition' ) ); ?>
				<?php echo esc_html( $can_validate_draft ? __( ' Validation réelle possible avec le brouillon actuel.', 'ufsc-licence-competition' ) : __( ' Validation réelle indisponible sans brouillon valide et sans bloquant.', 'ufsc-licence-competition' ) ); ?>
			</p>
		</section>
		<?php
	}

	private static function render_diagnostic_messages( string $title, array $messages, string $status ): void {
		if ( empty( $messages ) ) {
			return;
		}
		?>
		<div class="notice notice-<?php echo esc_attr( 'danger' === $status ? 'error' : ( 'warn' === $status ? 'warning' : 'info' ) ); ?> inline">
			<p><strong><?php echo esc_html( $title ); ?></strong></p>
			<ul>
				<?php foreach ( array_slice( $messages, 0, 10 ) as $message ) : ?>
					<li><?php echo esc_html( is_array( $message ) ? (string) ( $message['message'] ?? '' ) : (string) $message ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}

	private static function diagnostic_section_label( string $status ): string {
		$labels = array(
			'ready' => __( 'OK', 'ufsc-licence-competition' ),
			'ok' => __( 'OK', 'ufsc-licence-competition' ),
			'warning' => __( 'Warning', 'ufsc-licence-competition' ),
			'warn' => __( 'Warning', 'ufsc-licence-competition' ),
			'blocked' => __( 'Bloquant', 'ufsc-licence-competition' ),
			'danger' => __( 'Bloquant', 'ufsc-licence-competition' ),
			'locked' => __( 'Verrouillé', 'ufsc-licence-competition' ),
			'archived' => __( 'Archivée', 'ufsc-licence-competition' ),
			'info' => __( 'Info', 'ufsc-licence-competition' ),
		);
		return $labels[ $status ] ?? $status;
	}

	private static function status_badge_class( string $status ): string {
		if ( 'ok' === $status ) {
			return 'ufsc-badge ufsc-badge--success';
		}
		if ( 'danger' === $status ) {
			return 'ufsc-badge ufsc-badge--danger';
		}
		return 'ufsc-badge ufsc-badge--warning';
	}

	private static function format_duration_label( int $seconds ): string {
		$seconds = max( 0, $seconds );
		$minutes = (int) floor( $seconds / MINUTE_IN_SECONDS );
		$remain  = $seconds % MINUTE_IN_SECONDS;

		if ( 0 === $seconds ) {
			return __( '0 sec', 'ufsc-licence-competition' );
		}
		if ( 0 === $remain ) {
			return sprintf( __( '%d min', 'ufsc-licence-competition' ), $minutes );
		}
		if ( 0 === $minutes ) {
			return sprintf( __( '%d sec', 'ufsc-licence-competition' ), $remain );
		}

		return sprintf( __( '%1$d min %2$d sec', 'ufsc-licence-competition' ), $minutes, $remain );
	}

	private static function ensure_manage_access(): void {
		if ( ! Capabilities::user_can_manage() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}
	}

	private static function resolve_competition_id( int $fallback = 0 ): int {
		$candidates = array(
			isset( $_GET['competition_id'] ) ? absint( wp_unslash( $_GET['competition_id'] ) ) : 0,
			isset( $_GET['ufsc_competition_id'] ) ? absint( wp_unslash( $_GET['ufsc_competition_id'] ) ) : 0,
			isset( $_POST['competition_id'] ) ? absint( wp_unslash( $_POST['competition_id'] ) ) : 0,
			isset( $_POST['ufsc_competition_id'] ) ? absint( wp_unslash( $_POST['ufsc_competition_id'] ) ) : 0,
			$fallback,
		);

		$repo = new CompetitionRepository();
		foreach ( $candidates as $candidate ) {
			$candidate = absint( $candidate );
			if ( $candidate <= 0 ) {
				continue;
			}
			$competition = $repo->get( $candidate, true );
			if ( $competition ) {
				return $candidate;
			}
		}

		$competitions = $repo->list( array( 'status' => 'open' ), 2, 0 );
		if ( 1 === count( $competitions ) ) {
			return absint( $competitions[0]->id ?? 0 );
		}

		return 0;
	}

	private static function render_competition_selector_block( int $competition_id, $competition, array $competition_options ): void {
		$current_url = remove_query_arg( array( 'competition_id', 'ufsc_competition_id' ) );
		?>
		<div class="notice notice-info inline">
			<p><strong><?php esc_html_e( 'Compétition active', 'ufsc-licence-competition' ); ?></strong></p>
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr( Menu::PAGE_BOUTS ); ?>">
				<select name="competition_id" required>
					<option value=""><?php esc_html_e( 'Sélectionner une compétition', 'ufsc-licence-competition' ); ?></option>
					<?php foreach ( $competition_options as $item ) : ?>
						<option value="<?php echo esc_attr( (string) (int) $item->id ); ?>" <?php selected( $competition_id, (int) $item->id ); ?>>
							<?php echo esc_html( sprintf( '[%d] %s — %s — %s', (int) $item->id, (string) $item->name, (string) ( $item->discipline ?? '—' ), (string) ( $item->season ?? '—' ) ) ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<?php submit_button( __( 'Charger la compétition', 'ufsc-licence-competition' ), 'secondary', '', false ); ?>
				<?php if ( $competition_id > 0 ) : ?>
					<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=' . Menu::PAGE_PRINT . '&competition_id=' . $competition_id . '&print_type=fights' ) ); ?>"><?php esc_html_e( 'Voir l’impression', 'ufsc-licence-competition' ); ?></a>
				<?php endif; ?>
			</form>
			<p><span class="ufsc-badge ufsc-badge--info"><?php echo esc_html( $competition ? (string) ( $competition->name ?? __( 'Compétition sélectionnée', 'ufsc-licence-competition' ) ) : __( 'Aucune compétition sélectionnée', 'ufsc-licence-competition' ) ); ?></span></p>
		</div>
		<?php
	}

	private static function guard_action( string $nonce_action, int $competition_id ): void {
		if ( ! FightAutoGenerationService::is_enabled() ) {
			self::redirect( $competition_id, 'action_error', __( 'La génération automatique est désactivée.', 'ufsc-licence-competition' ) );
		}

		self::ensure_manage_access();
		if ( $competition_id > 0 && function_exists( 'ufsc_lc_enforce_competition_access' ) ) {
			ufsc_lc_enforce_competition_access( $competition_id );
		}
		if ( ( false !== strpos( $nonce_action, 'generate' ) || false !== strpos( $nonce_action, 'validate_fight_draft' ) ) && ! Capabilities::user_can_generate_fights() ) {
			( new LogService() )->audit( 'sensitive_action_blocked', $competition_id, 'competition', $competition_id, array( 'reason' => 'missing_fight_generate_capability', 'nonce_action' => $nonce_action ) );
			self::redirect( $competition_id, 'action_protected', __( 'Droit génération combats requis.', 'ufsc-licence-competition' ) );
		}
		check_admin_referer( $nonce_action );
	}

	private static function nonce_action( string $base_action, int $competition_id ): string {
		return sanitize_key( $base_action ) . '_' . absint( $competition_id );
	}

	private static function redirect( int $competition_id, string $notice, string $message = '' ): void {
		$args = array(
			'page' => Menu::PAGE_BOUTS,
			'ufsc_fight_notice' => $notice,
		);

		if ( $competition_id ) {
			$args['ufsc_competition_id'] = $competition_id;
		}

		if ( '' !== $message ) {
			$args['ufsc_fight_message'] = $message;
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
