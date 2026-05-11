<?php

namespace UFSC\Competitions\Admin\Pages;

use UFSC\Competitions\Capabilities;
use UFSC\Competitions\Admin\Menu;
use UFSC\Competitions\Repositories\CompetitionRepository;
use UFSC\Competitions\Repositories\EntryRepository;
use UFSC\Competitions\Services\FightAutoGenerationService;
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
		);

		if ( ! isset( $messages[ $notice ] ) ) {
			return;
		}

		$type = 'error' === $notice || 'draft_error' === $notice || 'action_error' === $notice ? 'error' : 'success';
		$text = $message ? $message : $messages[ $notice ];

		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( $text ) );
	}

	public static function render_panel( int $competition_id ): void {
		if ( ! FightAutoGenerationService::is_enabled() ) {
			return;
		}
		$competition_id = self::resolve_competition_id( $competition_id );

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
		$estimated_fights = (int) ( $preview['estimated_fights'] ?? 0 );
		$can_generate_now = $can_generate && $estimated_fights > 0 && ! empty( $preview['can_generate'] );
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
						<?php submit_button( __( 'Créer directement les combats planifiés', 'ufsc-licence-competition' ), 'primary', '', false, $can_generate_now ? array() : array( 'disabled' => 'disabled' ) ); ?>
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
						<?php submit_button( __( 'Valider et créer les combats', 'ufsc-licence-competition' ), 'primary', '', false, $has_draft ? array() : array( 'disabled' => 'disabled' ) ); ?>
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
					<p><strong><?php esc_html_e( 'Résultat de génération (brouillon actuel)', 'ufsc-licence-competition' ); ?></strong></p>
					<ul>
						<li><?php echo esc_html( sprintf( __( 'Combats : %d', 'ufsc-licence-competition' ), (int) ( $draft_stats['fights'] ?? 0 ) ) ); ?></li>
						<li><?php echo esc_html( sprintf( __( 'Groupes : %d', 'ufsc-licence-competition' ), (int) ( $draft_stats['groups'] ?? 0 ) ) ); ?></li>
						<li><?php echo esc_html( sprintf( __( 'Entrées : %d', 'ufsc-licence-competition' ), (int) ( $draft_stats['entries'] ?? 0 ) ) ); ?></li>
						<li><?php echo esc_html( sprintf( __( 'Byes générés : %d', 'ufsc-licence-competition' ), (int) ( $draft_stats['bye_slots'] ?? 0 ) ) ); ?></li>
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
						<div class="ufsc-competitions-table-wrap">
							<table class="widefat striped">
								<thead>
									<tr>
										<th><?php esc_html_e( 'Combat', 'ufsc-licence-competition' ); ?></th>
										<th><?php esc_html_e( 'Surface', 'ufsc-licence-competition' ); ?></th>
										<th><?php esc_html_e( 'Horaire estimé', 'ufsc-licence-competition' ); ?></th>
										<th><?php esc_html_e( 'Catégorie', 'ufsc-licence-competition' ); ?></th>
										<th><?php esc_html_e( 'Coin rouge', 'ufsc-licence-competition' ); ?></th>
										<th><?php esc_html_e( 'Coin bleu', 'ufsc-licence-competition' ); ?></th>
										<th><?php esc_html_e( 'Statut', 'ufsc-licence-competition' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( array_slice( (array) ( $draft['fights'] ?? array() ), 0, 80 ) as $fight_row ) : ?>
										<tr>
											<td><?php echo esc_html( (string) (int) ( $fight_row['fight_no'] ?? 0 ) ); ?></td>
											<td><?php echo esc_html( (string) ( $fight_row['ring'] ?? '—' ) ); ?></td>
											<td><?php echo esc_html( (string) ( $fight_row['scheduled_at'] ?? '—' ) ); ?></td>
											<td><?php echo esc_html( (string) ( $fight_row['category_label'] ?? $fight_row['category_id'] ?? '—' ) ); ?></td>
											<td><?php echo esc_html( (string) ( $fight_row['red_label'] ?? $fight_row['red_entry_id'] ?? 'TBD' ) ); ?></td>
											<td><?php echo esc_html( (string) ( $fight_row['blue_label'] ?? $fight_row['blue_entry_id'] ?? 'TBD' ) ); ?></td>
											<td><?php echo esc_html( (string) ( $fight_row['status'] ?? 'scheduled' ) ); ?></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					<?php else : ?>
						<p class="description"><?php esc_html_e( 'Aucun combat dans le brouillon courant. Vérifiez les éligibilités puis relancez la génération.', 'ufsc-licence-competition' ); ?></p>
					<?php endif; ?>
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
				<p><span class="ufsc-badge ufsc-badge--info">TEST</span> <span class="ufsc-badge ufsc-badge--muted"><?php esc_html_e( 'Données isolées', 'ufsc-licence-competition' ); ?></span> <span class="ufsc-badge ufsc-badge--warn"><?php esc_html_e( 'Suppression sécurisée', 'ufsc-licence-competition' ); ?></span></p>
				<p class="description"><?php esc_html_e( 'Réservé aux administrateurs. Les données créées sont marquées [TEST] et suivies pour suppression sécurisée.', 'ufsc-licence-competition' ); ?></p>
				<div class="ufsc-competitions-actions">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'ufsc_competitions_test_fixture_create' ); ?>
						<input type="hidden" name="action" value="ufsc_competitions_test_fixture_create">
						<?php submit_button( __( 'Créer une compétition de test', 'ufsc-licence-competition' ), 'secondary', '', false ); ?>
					</form>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Confirmer la réinitialisation du jeu de test ?', 'ufsc-licence-competition' ) ); ?>');">
						<?php wp_nonce_field( 'ufsc_competitions_test_fixture_reset' ); ?>
						<input type="hidden" name="action" value="ufsc_competitions_test_fixture_reset">
						<?php submit_button( __( 'Réinitialiser le test', 'ufsc-licence-competition' ), 'secondary', '', false ); ?>
					</form>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Suppression définitive des données [TEST] suivies. Confirmer ?', 'ufsc-licence-competition' ) ); ?>');">
						<?php wp_nonce_field( 'ufsc_competitions_test_fixture_delete' ); ?>
						<input type="hidden" name="action" value="ufsc_competitions_test_fixture_delete">
						<?php submit_button( __( 'Supprimer les données de test', 'ufsc-licence-competition' ), 'delete', '', false ); ?>
					</form>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'ufsc_competitions_test_fixture_run' ); ?>
						<input type="hidden" name="action" value="ufsc_competitions_test_fixture_run">
						<label><input type="checkbox" name="auto_validate" value="1"> <?php esc_html_e( 'Valider automatiquement les combats test après création du brouillon', 'ufsc-licence-competition' ); ?></label>
						<?php submit_button( __( 'Lancer un test complet', 'ufsc-licence-competition' ), 'primary', '', false ); ?>
					</form>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( 'ufsc_competitions_test_fixture_open150' ); ?>
							<input type="hidden" name="action" value="ufsc_competitions_test_fixture_open150">
							<?php submit_button( __( 'Créer test Open 150 participants', 'ufsc-licence-competition' ), 'secondary', '', false ); ?>
						</form>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'ufsc_competitions_test_fixture_open150_generate' ); ?>
						<input type="hidden" name="action" value="ufsc_competitions_test_fixture_open150_generate">
						<?php submit_button( __( 'Créer + générer test Open 150', 'ufsc-licence-competition' ), 'primary', '', false ); ?>
					</form>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'ufsc_competitions_assign_fighter_numbers' ); ?>
						<input type="hidden" name="action" value="ufsc_competitions_assign_fighter_numbers">
						<input type="hidden" name="competition_id" value="<?php echo esc_attr( $competition_id ); ?>">
						<label><input type="checkbox" name="force_reassign" value="1"> <?php esc_html_e( 'Réattribuer tous les numéros (action sensible)', 'ufsc-licence-competition' ); ?></label>
						<?php submit_button( __( 'Attribuer les numéros combattants', 'ufsc-licence-competition' ), 'secondary', '', false ); ?>
					</form>
				</div>
			</div>

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

		FightAutoGenerationService::clear_draft( $competition_id );
		$settings = FightAutoGenerationService::get_settings( $competition_id );
		$result = FightAutoGenerationService::generate_draft( $competition_id, $settings );
		self::redirect( $competition_id, $result['ok'] ? 'draft_ready' : 'draft_error', $result['message'] ?? '' );
	}

	public static function handle_validate_draft(): void {
		$competition_id = self::resolve_competition_id( isset( $_POST['competition_id'] ) ? absint( $_POST['competition_id'] ) : 0 );
		self::guard_action( 'ufsc_competitions_validate_fight_draft', $competition_id );
		$apply_mode = isset( $_POST['apply_mode'] ) ? sanitize_key( wp_unslash( $_POST['apply_mode'] ) ) : 'append';
		if ( 'replace' === $apply_mode ) {
			self::redirect( $competition_id, 'action_error', __( 'Le mode remplacement n’est pas disponible.', 'ufsc-licence-competition' ) );
		}

		$result = FightAutoGenerationService::validate_and_apply_draft( $competition_id, $apply_mode );
		self::redirect( $competition_id, $result['ok'] ? 'draft_validated' : 'action_error', $result['message'] ?? '' );
	}
	public static function handle_generate_direct(): void {
		$competition_id = self::resolve_competition_id( isset( $_POST['competition_id'] ) ? absint( $_POST['competition_id'] ) : 0 );
		self::guard_action( self::nonce_action( 'ufsc_competitions_generate_fight_direct', $competition_id ), $competition_id );
		$settings = FightAutoGenerationService::get_settings( $competition_id );
		$preview = FightAutoGenerationService::get_generation_preview( $competition_id, $settings );
		$draft_result = FightAutoGenerationService::generate_draft( $competition_id, $settings );
		if ( empty( $draft_result['ok'] ) ) {
			$fallback = FightAutoGenerationService::generate_simple_pairing_fights( $competition_id, $settings );
			if ( ! empty( $fallback['ok'] ) ) {
				$message = sprintf(
					'Fallback simple pairing exécuté | inserts_tentes=%1$d | inserts_reussis=%2$d | groupes_solo=%3$d',
					(int) ( $fallback['attempted_inserts'] ?? 0 ),
					(int) ( $fallback['successful_inserts'] ?? 0 ),
					count( (array) ( $fallback['lone_groups'] ?? array() ) )
				);
				self::redirect( $competition_id, 'draft_validated', $message );
			}
			self::redirect( $competition_id, 'draft_error', (string) ( $draft_result['message'] ?? '' ) );
		}
		$apply_result = FightAutoGenerationService::validate_and_apply_draft( $competition_id, 'append' );
		if ( empty( $apply_result['ok'] ) || (int) ( $apply_result['stats']['inserts_success'] ?? 0 ) <= 0 ) {
			$fallback = FightAutoGenerationService::generate_simple_pairing_fights( $competition_id, $settings );
			if ( ! empty( $fallback['ok'] ) ) {
				$message = sprintf(
					'Fallback simple pairing exécuté | inserts_tentes=%1$d | inserts_reussis=%2$d | groupes_solo=%3$d',
					(int) ( $fallback['attempted_inserts'] ?? 0 ),
					(int) ( $fallback['successful_inserts'] ?? 0 ),
					count( (array) ( $fallback['lone_groups'] ?? array() ) )
				);
				self::redirect( $competition_id, 'draft_validated', $message );
			}
		}
		$stats = (array) ( $apply_result['stats'] ?? array() );
		$diag_message = sprintf(
			'Action=direct | competition_id_received=%1$d | competition_id_used=%2$d | groups_generables=%3$d | combats_estimes=%4$d | inserts_tentes=%5$d | inserts_reussis=%6$d | draft=%7$s | result=%8$s',
			$competition_id,
			$competition_id,
			(int) ( $preview['estimated_categories'] ?? 0 ),
			(int) ( $preview['estimated_fights'] ?? 0 ),
			(int) ( $stats['inserts_attempted'] ?? 0 ),
			(int) ( $stats['inserts_success'] ?? 0 ),
			! empty( $draft_result['ok'] ) ? 'yes' : 'no',
			(string) ( $apply_result['message'] ?? '' )
		);
		self::redirect( $competition_id, ! empty( $apply_result['ok'] ) ? 'draft_validated' : 'action_error', $diag_message );
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
		$result = self::create_test_fixture();
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
		$result = self::create_test_fixture();
		self::redirect( (int) ( $result['competition_id'] ?? 0 ), empty( $result['ok'] ) ? 'action_error' : 'settings_saved', (string) ( $result['message'] ?? '' ) );
	}

	public static function handle_test_fixture_delete(): void {
		self::guard_action( 'ufsc_competitions_test_fixture_delete', 0 );
		$deleted = self::delete_test_fixture();
		self::redirect( 0, 'settings_saved', sprintf( __( 'Données de test supprimées: %d éléments.', 'ufsc-licence-competition' ), $deleted ) );
	}

	public static function handle_test_fixture_run(): void {
		self::guard_action( 'ufsc_competitions_test_fixture_run', 0 );
		$fixture = self::create_test_fixture();
		if ( empty( $fixture['ok'] ) ) { self::redirect( 0, 'action_error', (string) ( $fixture['message'] ?? '' ) ); }
		$competition_id = (int) $fixture['competition_id'];
		$settings = FightAutoGenerationService::get_settings( $competition_id );
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
		$status = sanitize_key( (string) ( $fight->status ?? '' ) );
		if ( in_array( $status, array( 'running', 'locked' ), true ) ) {
			self::redirect( $competition_id, 'action_error', 'Combat verrouillé/en cours: utilisez Actions sensibles.' );
		}
		if ( 'completed' === $status && empty( $_POST['force_sensitive'] ) ) {
			self::redirect( $competition_id, 'action_error', 'Résultat déjà saisi: correction via Actions sensibles.' );
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
		$propagation = self::maybe_propagate_winner( $fight, $winner_entry_id );
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

	private static function maybe_propagate_winner( $fight, int $winner_entry_id ): array {
		global $wpdb;
		$result = array( 'propagated' => false, 'next_fight_id' => (int) ( $fight->next_fight_id ?? 0 ), 'next_slot' => (string) ( $fight->next_slot ?? '' ), 'reason' => 'no_winner' );
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
		if ( $current_value > 0 && $current_value !== $winner_entry_id ) { $result['reason'] = 'slot_already_occupied'; return $result; }
		$updated = $wpdb->update( $table, array( $slot_column => $winner_entry_id, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $next_fight_id ), array( '%d', '%s' ), array( '%d' ) );
		if ( false === $updated ) { $result['reason'] = 'db_update_failed'; return $result; }
		$result['propagated'] = true;
		$result['reason'] = 'propagated';
		return $result;
	}

	private static function create_test_fixture(): array {
		self::delete_test_fixture();
		$comp_repo = new CompetitionRepository();
		$entry_repo = new EntryRepository();
		$season = gmdate( 'Y' ) . '/' . (string) ( (int) gmdate( 'Y' ) + 1 );
		$competition_id = (int) $comp_repo->save( array( 'name' => '[TEST] Génération combats', 'discipline' => 'light_contact', 'type' => 'open', 'season' => $season, 'status' => 'open' ) );
		if ( $competition_id <= 0 ) { return array( 'ok' => false, 'message' => __( 'Impossible de créer la compétition test.', 'ufsc-licence-competition' ) ); }
		FightAutoGenerationService::save_settings( $competition_id, array( 'surface_count' => 2, 'fight_duration' => 2, 'break_duration' => 1 ) );
		$ids = array( 'competition_id' => $competition_id, 'entries' => array(), 'weighins' => array() );
		$rows = array(
			array('M','70kg','A','approved',true,'light_contact','Club Test Nord'),
			array('M','70kg','A','approved',true,'light_contact','Club Test Nord'),
			array('F','60kg','A','approved',true,'light_contact','Club Test Sud'),
			array('F','60kg','A','approved',true,'light_contact','Club Test Sud'),
			array('F','60kg','A','approved',true,'light_contact','Club Test Est'),
			array('F','60kg','A','approved',true,'light_contact','Club Test Ouest'),
			array('M','80kg','A','approved',true,'light_contact','Club Test Centre'),
			array('M','80kg','A','approved',true,'light_contact','Club Test Centre'),
			array('M','80kg','A','approved',true,'light_contact','Club Test Centre'),
			array('M','75kg','A','approved',true,'light_contact','Club Test Nord'),
			array('M','75kg','A','approved',true,'light_contact','Club Test Sud'),
			array('M','55kg','Cadet','approved',true,'light_contact','Club Jeunes'),
			array('M','55kg','Cadet','approved',true,'light_contact','Club Jeunes'),
			array('F','55kg','Cadet','approved',true,'light_contact','Club Jeunes'),
			array('M','90kg','Junior','approved',true,'light_contact','Club Performance'),
			array('F','90kg','Junior','approved',true,'light_contact','Club Performance')
		);
		$i=1; foreach($rows as $r){
			$eid=(int)$entry_repo->insert(array('competition_id'=>$competition_id,'status'=>$r[3],'first_name'=>'Test'.$i,'last_name'=>'Athlete','sex'=>$r[0],'category'=>$r[2],'weight_class'=>$r[1],'discipline'=>$r[5],'participant_type'=>'external','club_name'=>$r[6],'level'=>'non_defini','license_number'=>'TEST-LIC-'.$competition_id.'-'.$i,'birthdate'=>'2000-01-'.str_pad((string) (($i%28)+1),2,'0',STR_PAD_LEFT),'notes'=>'[TEST_GENERATION]'));
			if($eid>0){$ids['entries'][]=$eid; if($r[4]){self::insert_test_weighin($competition_id,$eid);}} $i++;
		}
		update_option( 'ufsc_generation_test_fixture_ids', $ids, false );
		return array( 'ok' => true, 'competition_id' => $competition_id, 'message' => __( 'Compétition et données de test créées.', 'ufsc-licence-competition' ) );
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

	private static function insert_test_weighin( int $competition_id, int $entry_id ): void {
		global $wpdb; $table = Db::weighins_table(); if ( ! Db::table_exists( $table ) ) { return; }
		$data = array( 'competition_id' => $competition_id, 'entry_id' => $entry_id, 'status' => 'ok', 'notes' => wp_json_encode( array( 'marker' => '[TEST_GENERATION]' ) ) );
		$formats = array( '%d', '%d', '%s', '%s' );
		$wpdb->insert( $table, $data, $formats );
	}

	private static function delete_test_fixture(): int {
		global $wpdb;
		$ids = get_option( 'ufsc_generation_test_fixture_ids', array() );
		$count = 0;
		$entry_ids = array_map( 'absint', (array) ( $ids['entries'] ?? array() ) );
		$competition_id = absint( $ids['competition_id'] ?? 0 );
		if ( $entry_ids ) {
			foreach ( $entry_ids as $entry_id ) {
				if ( $entry_id <= 0 ) {
					continue;
				}
				$wpdb->delete( Db::weighins_table(), array( 'entry_id' => $entry_id ), array( '%d' ) );
				$count += (int) $wpdb->rows_affected;
				$wpdb->delete( Db::entries_table(), array( 'id' => $entry_id ), array( '%d' ) );
				$count += (int) $wpdb->rows_affected;
			}
		}
		if ( $competition_id > 0 ) {
			$wpdb->delete( Db::fights_table(), array( 'competition_id' => $competition_id ), array( '%d' ) ); $count += (int) $wpdb->rows_affected;
			$wpdb->delete( Db::competitions_table(), array( 'id' => $competition_id ), array( '%d' ) ); $count += (int) $wpdb->rows_affected;
			delete_option( 'ufsc_competitions_fight_generation_draft_' . $competition_id );
		}
		delete_option( 'ufsc_generation_test_fixture_ids' );
		return $count;
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
