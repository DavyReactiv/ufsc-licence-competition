<?php

namespace UFSC\Competitions\Admin\Pages;

use UFSC\Competitions\Admin\Menu;
use UFSC\Competitions\Capabilities;
use UFSC\Competitions\Repositories\CompetitionRepository;
use UFSC\Competitions\Repositories\EntryRepository;
use UFSC\Competitions\Repositories\FightRepository;
use UFSC\Competitions\Services\LogService;
use UFSC\Competitions\Services\FightDisplayService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Sensitive_Operations_Page {
	private $competitions;
	private $entries;
	private $fights;
	private $logger;
	private $partial_regen_preview = null;

	public function __construct() {
		$this->competitions = new CompetitionRepository();
		$this->entries = new EntryRepository();
		$this->fights = new FightRepository();
		$this->logger = new LogService();
	}

	public function render(): void {
		if ( ! Capabilities::user_can_manage() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ) );
		}

		$competition_id = isset( $_GET['competition_id'] ) ? absint( $_GET['competition_id'] ) : 0;
		$notice = $this->maybe_handle_post( $competition_id );
		$filters = array( 'view' => 'all' );
		if ( function_exists( 'ufsc_lc_competitions_apply_scope_to_query_args' ) ) {
			$filters = ufsc_lc_competitions_apply_scope_to_query_args( $filters );
		}
		$competitions = $this->competitions->list( $filters, 200, 0 );
		?>
		<div class="wrap ufsc-competitions-admin">
			<h1><?php esc_html_e( 'Actions sensibles', 'ufsc-licence-competition' ); ?></h1>
			<div class="notice notice-warning"><p><?php esc_html_e( 'Réintégration et régénération partielle sont réservées aux cas exceptionnels, avec motif obligatoire et audit.', 'ufsc-licence-competition' ); ?></p></div>
			<?php if ( $notice ) : ?><div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?>"><p><?php echo esc_html( $notice['message'] ); ?></p></div><?php endif; ?>
			<form method="get" class="ufsc-competitions-filter">
				<input type="hidden" name="page" value="<?php echo esc_attr( Menu::PAGE_SENSITIVE_OPS ); ?>" />
				<select name="competition_id" required>
					<option value="0"><?php esc_html_e( 'Sélectionner une compétition', 'ufsc-licence-competition' ); ?></option>
					<?php foreach ( $competitions as $competition ) : ?>
						<option value="<?php echo esc_attr( (int) $competition->id ); ?>" <?php selected( $competition_id, (int) $competition->id ); ?>><?php echo esc_html( (string) $competition->name ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php submit_button( __( 'Charger', 'ufsc-licence-competition' ), 'secondary', '', false ); ?>
			</form>

				<?php if ( $competition_id > 0 ) : ?>
				<div class="ufsc-step-grid">
				<section class="ufsc-step-card">
					<h2><?php esc_html_e( 'Réintégration exceptionnelle', 'ufsc-licence-competition' ); ?></h2>
					<p><?php esc_html_e( 'Ordre : réintégration simple → insertion bye/slot vide → régénération partielle non jouée → blocage explicite.', 'ufsc-licence-competition' ); ?></p>
					<form method="post">
						<?php wp_nonce_field( 'ufsc_sensitive_reintegrate_' . $competition_id ); ?>
						<input type="hidden" name="competition_id" value="<?php echo esc_attr( $competition_id ); ?>" />
						<input type="hidden" name="ufsc_sensitive_action" value="reintegrate" />
						<p><label><?php esc_html_e( 'Entry ID', 'ufsc-licence-competition' ); ?> <input type="number" name="entry_id" required min="1" /></label></p>
						<p><label><?php esc_html_e( 'Category ID', 'ufsc-licence-competition' ); ?> <input type="number" name="category_id" required min="1" /></label></p>
						<p><label><?php esc_html_e( 'Motif obligatoire', 'ufsc-licence-competition' ); ?><textarea name="reason" required rows="3" class="large-text"></textarea></label></p>
						<p><label><input type="checkbox" name="supervisor_confirm" value="1" required> <?php esc_html_e( 'Validation superviseur', 'ufsc-licence-competition' ); ?></label></p>
						<?php submit_button( __( 'Lancer la réintégration guidée', 'ufsc-licence-competition' ), 'secondary', '', false ); ?>
					</form>
				</section>

					<section class="ufsc-step-card ufsc-step-card--warning">
						<h2><?php esc_html_e( 'Régénération partielle (zones non jouées)', 'ufsc-licence-competition' ); ?></h2>
						<p><?php esc_html_e( 'Supprime uniquement les combats planifiés non joués après le dernier combat validé de la catégorie.', 'ufsc-licence-competition' ); ?></p>
						<form method="post">
							<?php wp_nonce_field( 'ufsc_sensitive_regen_' . $competition_id ); ?>
							<input type="hidden" name="competition_id" value="<?php echo esc_attr( $competition_id ); ?>" />
							<input type="hidden" name="ufsc_sensitive_action" value="partial_regen_simulate" />
							<p><label><?php esc_html_e( 'Category ID', 'ufsc-licence-competition' ); ?> <input type="number" name="category_id" required min="1" /></label></p>
							<p><label><?php esc_html_e( 'Motif obligatoire', 'ufsc-licence-competition' ); ?><textarea name="reason" required rows="3" class="large-text"></textarea></label></p>
							<p><label><input type="checkbox" name="supervisor_confirm" value="1" required> <?php esc_html_e( 'Je confirme la régénération partielle supervisée', 'ufsc-licence-competition' ); ?></label></p>
							<?php submit_button( __( 'Simuler la régénération', 'ufsc-licence-competition' ), 'secondary', '', false ); ?>
						</form>
					</section>
				</div>

					<?php if ( is_array( $this->partial_regen_preview ) ) : ?>
						<?php $this->render_partial_regen_preview( $competition_id, $this->partial_regen_preview ); ?>
					<?php endif; ?>
					<?php $this->render_fights_diagnostic( $competition_id ); ?>
					<?php endif; ?>
				</div>
			<?php
	}

	private function maybe_handle_post( int $competition_id ): ?array {
		if ( 'POST' !== $_SERVER['REQUEST_METHOD'] || empty( $_POST['ufsc_sensitive_action'] ) ) {
			return null;
		}
		$competition_id = $competition_id ?: ( isset( $_POST['competition_id'] ) ? absint( $_POST['competition_id'] ) : 0 );
		if ( ! Capabilities::user_can_manage() || ! $competition_id ) {
			return array( 'type' => 'error', 'message' => __( 'Action non autorisée.', 'ufsc-licence-competition' ) );
		}

		$action = sanitize_key( (string) wp_unslash( $_POST['ufsc_sensitive_action'] ) );
		if ( 'reintegrate' === $action ) {
			check_admin_referer( 'ufsc_sensitive_reintegrate_' . $competition_id );
			return $this->handle_reintegration( $competition_id );
		}
		if ( 'partial_regen_simulate' === $action ) {
			check_admin_referer( 'ufsc_sensitive_regen_' . $competition_id );
			return $this->handle_partial_regen_simulation( $competition_id );
		}
		if ( 'partial_regen_execute' === $action ) {
			check_admin_referer( 'ufsc_sensitive_regen_' . $competition_id );
			return $this->handle_partial_regen_execution( $competition_id );
		}

		return null;
	}

	private function handle_reintegration( int $competition_id ): array {
		$entry_id = isset( $_POST['entry_id'] ) ? absint( $_POST['entry_id'] ) : 0;
		$category_id = isset( $_POST['category_id'] ) ? absint( $_POST['category_id'] ) : 0;
		$reason = sanitize_textarea_field( (string) wp_unslash( $_POST['reason'] ?? '' ) );
		$supervised = ! empty( $_POST['supervisor_confirm'] );
		if ( ! $entry_id || ! $category_id || '' === $reason || ! $supervised ) {
			return array( 'type' => 'error', 'message' => __( 'Réintégration refusée : champs requis manquants.', 'ufsc-licence-competition' ) );
		}

		$entry = $this->entries->get( $entry_id, true );
		if ( ! $entry || (int) $entry->competition_id !== $competition_id ) {
			return array( 'type' => 'error', 'message' => __( 'Entry invalide pour cette compétition.', 'ufsc-licence-competition' ) );
		}

		$fights = $this->fights->list( array( 'view' => 'all', 'competition_id' => $competition_id, 'category_id' => $category_id ), 500, 0 );
		$completed = array_filter( $fights, static function( $f ) { return 'completed' === (string) ( $f->status ?? '' ); } );
		$advanced = array_filter( $completed, static function( $f ) { return (int) ( $f->round_no ?? 0 ) >= 2; } );
		if ( ! empty( $advanced ) ) {
			return array( 'type' => 'error', 'message' => __( 'Réintégration bloquée : phases avancées déjà engagées (quart/demi/finale).', 'ufsc-licence-competition' ) );
		}

		$this->entries->update(
			$entry_id,
			array(
				'category_id' => $category_id,
				'status' => 'approved',
			)
		);

		$this->logger->log(
			'entry_reintegration_exceptional',
			'entry',
			$entry_id,
			'Réintégration exceptionnelle validée',
			array(
				'competition_id' => $competition_id,
				'category_id' => $category_id,
				'reason' => $reason,
				'completed_fights_in_category' => count( $completed ),
			)
		);

		return array( 'type' => 'success', 'message' => __( 'Réintégration effectuée. Si nécessaire, lancez ensuite une régénération partielle sécurisée.', 'ufsc-licence-competition' ) );
	}

	private function handle_partial_regen_simulation( int $competition_id ): array {
		$category_id = isset( $_POST['category_id'] ) ? absint( $_POST['category_id'] ) : 0;
		$reason = sanitize_textarea_field( (string) wp_unslash( $_POST['reason'] ?? '' ) );
		$supervised = ! empty( $_POST['supervisor_confirm'] );
		if ( ! $category_id || '' === $reason || ! $supervised ) {
			return array( 'type' => 'error', 'message' => __( 'Régénération partielle refusée : champs requis manquants.', 'ufsc-licence-competition' ) );
		}
		$scope_guard = $this->fights->can_regenerate_scope( $competition_id, $category_id );
		if ( empty( $scope_guard['allowed'] ) ) {
			$this->logger->log(
				'partial_regeneration_blocked_sensitive_scope',
				'fight',
				$category_id,
				'Simulation bloquée : combats sensibles détectés.',
				array(
					'competition_id' => $competition_id,
					'blocking_count' => (int) ( $scope_guard['blocking_count'] ?? 0 ),
				)
			);
			return array(
				'type' => 'error',
				'message' => sprintf(
					/* translators: %d: sensitive fights count */
					__( 'Simulation bloquée : %d combat(s) en cours/terminé(s) ou avec résultat dans cette catégorie.', 'ufsc-licence-competition' ),
					(int) ( $scope_guard['blocking_count'] ?? 0 )
				),
			);
		}

		$plan = $this->build_partial_regen_plan( $competition_id, $category_id );
		if ( empty( $plan['all_fights'] ) ) {
			return array( 'type' => 'error', 'message' => __( 'Aucun combat pour cette catégorie.', 'ufsc-licence-competition' ) );
		}

		$this->logger->log(
			'partial_regeneration_safe',
			'fight',
			$category_id,
			'Régénération partielle sécurisée (simulation)',
			array(
				'mode' => 'simulation',
				'competition_id' => $competition_id,
				'category_id' => $category_id,
				'reason' => $reason,
				'max_completed_fight_no' => (int) $plan['max_completed_fight_no'],
				'planned_fight_ids' => wp_list_pluck( $plan['candidates'], 'id' ),
				'planned_count' => count( $plan['candidates'] ),
				'processed_count' => 0,
				'supervised' => $supervised ? 1 : 0,
			)
		);

		$this->partial_regen_preview = array(
			'competition_id' => $competition_id,
			'category_id' => $category_id,
			'reason' => $reason,
			'supervised' => $supervised ? 1 : 0,
			'max_completed_fight_no' => (int) $plan['max_completed_fight_no'],
			'candidates' => $plan['candidates'],
		);

		return array(
			'type' => 'warning',
			'message' => __( 'Simulation prête. Vérifiez la liste puis confirmez l’exécution finale.', 'ufsc-licence-competition' ),
		);
	}

	private function handle_partial_regen_execution( int $competition_id ): array {
		$category_id = isset( $_POST['category_id'] ) ? absint( $_POST['category_id'] ) : 0;
		$reason = sanitize_textarea_field( (string) wp_unslash( $_POST['reason'] ?? '' ) );
		$supervised = ! empty( $_POST['supervisor_confirm'] );
		$final_confirm = ! empty( $_POST['final_confirm'] );
		if ( ! $category_id || '' === $reason || ! $supervised || ! $final_confirm ) {
			return array( 'type' => 'error', 'message' => __( 'Exécution refusée : confirmation finale obligatoire.', 'ufsc-licence-competition' ) );
		}
		$scope_guard = $this->fights->can_regenerate_scope( $competition_id, $category_id );
		if ( empty( $scope_guard['allowed'] ) ) {
			$this->logger->log(
				'partial_regeneration_blocked_sensitive_scope',
				'fight',
				$category_id,
				'Exécution bloquée : combats sensibles détectés.',
				array(
					'competition_id' => $competition_id,
					'blocking_count' => (int) ( $scope_guard['blocking_count'] ?? 0 ),
				)
			);
			return array(
				'type' => 'error',
				'message' => sprintf(
					/* translators: %d: sensitive fights count */
					__( 'Exécution bloquée : %d combat(s) en cours/terminé(s) ou avec résultat dans cette catégorie.', 'ufsc-licence-competition' ),
					(int) ( $scope_guard['blocking_count'] ?? 0 )
				),
			);
		}

		$plan = $this->build_partial_regen_plan( $competition_id, $category_id );
		$planned_ids = wp_list_pluck( $plan['candidates'], 'id' );
		$deleted_ids = array();
		foreach ( $plan['candidates'] as $fight ) {
			$fight_id = (int) ( $fight->id ?? 0 );
			if ( ! $fight_id ) {
				continue;
			}
			$this->fights->soft_delete( $fight_id );
			$deleted_ids[] = $fight_id;
		}

		$this->logger->log(
			'partial_regeneration_safe',
			'fight',
			$category_id,
			'Régénération partielle sécurisée (exécution)',
			array(
				'mode' => 'execution',
				'competition_id' => $competition_id,
				'category_id' => $category_id,
				'reason' => $reason,
				'max_completed_fight_no' => (int) $plan['max_completed_fight_no'],
				'planned_fight_ids' => $planned_ids,
				'processed_fight_ids' => $deleted_ids,
				'planned_count' => count( $planned_ids ),
				'processed_count' => count( $deleted_ids ),
				'supervised' => $supervised ? 1 : 0,
			)
		);

		return array( 'type' => 'success', 'message' => sprintf( __( 'Régénération partielle exécutée : %d combats mis en corbeille.', 'ufsc-licence-competition' ), count( $deleted_ids ) ) );
	}

	private function build_partial_regen_plan( int $competition_id, int $category_id ): array {
		$fights = $this->fights->list( array( 'view' => 'all', 'competition_id' => $competition_id, 'category_id' => $category_id ), 500, 0 );
		$max_completed_no = 0;
		foreach ( $fights as $fight ) {
			if ( 'completed' === (string) ( $fight->status ?? '' ) ) {
				$max_completed_no = max( $max_completed_no, (int) ( $fight->fight_no ?? 0 ) );
			}
		}

		$candidates = array();
		foreach ( $fights as $fight ) {
			if ( (int) ( $fight->fight_no ?? 0 ) <= $max_completed_no ) {
				continue;
			}
			if ( in_array( (string) ( $fight->status ?? '' ), array( 'completed', 'running' ), true ) ) {
				continue;
			}
			$candidates[] = $fight;
		}

		return array(
			'all_fights' => $fights,
			'max_completed_fight_no' => $max_completed_no,
			'candidates' => $candidates,
		);
	}

	private function render_partial_regen_preview( int $competition_id, array $preview ): void {
		$candidates = $preview['candidates'] ?? array();
		$entry_ids = array();
		foreach ( $candidates as $fight ) {
			$entry_ids[] = absint( $fight->red_entry_id ?? 0 );
			$entry_ids[] = absint( $fight->blue_entry_id ?? 0 );
		}
		$entry_ids = array_values( array_filter( array_unique( $entry_ids ) ) );
		$entry_map = array();
		if ( $entry_ids ) {
			$rows = $this->entries->list_with_details( array( 'entry_ids' => $entry_ids, 'include_deleted' => true ), 2000, 0 );
			foreach ( $rows as $row ) {
				$entry_map[ (int) $row->id ] = $row;
			}
		}
		?>
		<section class="ufsc-admin-surface" style="margin-top:16px;">
			<h2><?php esc_html_e( 'Simulation — combats supprimables', 'ufsc-licence-competition' ); ?></h2>
			<p><strong><?php esc_html_e( 'Avertissement :', 'ufsc-licence-competition' ); ?></strong> <?php esc_html_e( 'Les combats ci-dessous seront mis en corbeille. Aucun combat running/completed ne sera supprimé. Vérifiez attentivement la liste avant validation.', 'ufsc-licence-competition' ); ?></p>
			<ul>
				<li><?php echo esc_html( sprintf( __( 'Compétition ID: %d', 'ufsc-licence-competition' ), (int) $competition_id ) ); ?></li>
				<li><?php echo esc_html( sprintf( __( 'Catégorie ID: %d', 'ufsc-licence-competition' ), (int) ( $preview['category_id'] ?? 0 ) ) ); ?></li>
				<li><?php echo esc_html( sprintf( __( 'Dernier fight_no completed: %d', 'ufsc-licence-competition' ), (int) ( $preview['max_completed_fight_no'] ?? 0 ) ) ); ?></li>
				<li><?php echo esc_html( sprintf( __( 'Combats candidats: %d', 'ufsc-licence-competition' ), count( $candidates ) ) ); ?></li>
			</ul>

			<div class="ufsc-competitions-table-wrap">
				<table class="widefat striped">
					<thead><tr><th>ID</th><th>fight_no</th><th><?php esc_html_e( 'Phase', 'ufsc-licence-competition' ); ?></th><th><?php esc_html_e( 'Coin rouge', 'ufsc-licence-competition' ); ?></th><th><?php esc_html_e( 'Coin bleu', 'ufsc-licence-competition' ); ?></th><th><?php esc_html_e( 'Statut', 'ufsc-licence-competition' ); ?></th></tr></thead>
					<tbody>
					<?php if ( empty( $candidates ) ) : ?>
						<tr><td colspan="6"><?php esc_html_e( 'Aucun combat supprimable détecté.', 'ufsc-licence-competition' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $candidates as $fight ) : ?>
						<?php
						$phase = class_exists( FightDisplayService::class ) ? FightDisplayService::format_phase_label( $fight, $candidates ) : '';
						$red = $entry_map[ (int) ( $fight->red_entry_id ?? 0 ) ] ?? null;
						$blue = $entry_map[ (int) ( $fight->blue_entry_id ?? 0 ) ] ?? null;
						$red_label = $red ? trim( (string) ( $red->licensee_last_name ?? '' ) . ' ' . (string) ( $red->licensee_first_name ?? '' ) ) : '—';
						$blue_label = $blue ? trim( (string) ( $blue->licensee_last_name ?? '' ) . ' ' . (string) ( $blue->licensee_first_name ?? '' ) ) : '—';
						?>
						<tr>
							<td><?php echo esc_html( (string) ( $fight->id ?? 0 ) ); ?></td>
							<td><?php echo esc_html( (string) ( $fight->fight_no ?? 0 ) ); ?></td>
							<td><?php echo esc_html( '' !== $phase ? $phase : '—' ); ?></td>
							<td><?php echo esc_html( '' !== $red_label ? $red_label : '—' ); ?></td>
							<td><?php echo esc_html( '' !== $blue_label ? $blue_label : '—' ); ?></td>
							<td><?php echo esc_html( (string) ( $fight->status ?? '' ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<form method="post" style="margin-top:12px;">
				<?php wp_nonce_field( 'ufsc_sensitive_regen_' . $competition_id ); ?>
				<input type="hidden" name="competition_id" value="<?php echo esc_attr( $competition_id ); ?>" />
				<input type="hidden" name="ufsc_sensitive_action" value="partial_regen_execute" />
				<input type="hidden" name="category_id" value="<?php echo esc_attr( (int) ( $preview['category_id'] ?? 0 ) ); ?>" />
				<input type="hidden" name="reason" value="<?php echo esc_attr( (string) ( $preview['reason'] ?? '' ) ); ?>" />
				<input type="hidden" name="supervisor_confirm" value="<?php echo esc_attr( ! empty( $preview['supervised'] ) ? '1' : '0' ); ?>" />
				<p><label><input type="checkbox" name="final_confirm" value="1" required> <?php esc_html_e( 'Confirmation finale : exécuter la mise en corbeille des combats listés.', 'ufsc-licence-competition' ); ?></label></p>
				<?php submit_button( __( 'Exécuter la régénération partielle', 'ufsc-licence-competition' ), 'delete', '', false ); ?>
			</form>
		</section>
		<?php
	}

	private function render_fights_diagnostic( int $competition_id ): void {
		$diagnostic = $this->build_fights_diagnostic( $competition_id );
		$total      = (int) ( $diagnostic['total'] ?? 0 );
		$issues     = (array) ( $diagnostic['issues'] ?? array() );
		$sample     = array_slice( (array) ( $diagnostic['sample'] ?? array() ), 0, 30 );
		?>
		<section class="ufsc-admin-surface" style="margin-top:16px;">
			<h2><?php esc_html_e( 'Diagnostic combats (non destructif)', 'ufsc-licence-competition' ); ?></h2>
			<p><?php esc_html_e( 'Ce contrôle ne modifie aucune donnée. Il signale uniquement les incohérences de workflow.', 'ufsc-licence-competition' ); ?></p>
			<ul>
				<li><?php echo esc_html( sprintf( __( 'Combats analysés : %d', 'ufsc-licence-competition' ), $total ) ); ?></li>
				<?php foreach ( $issues as $code => $count ) : ?>
					<li><?php echo esc_html( sprintf( '%s : %d', (string) $code, (int) $count ) ); ?></li>
				<?php endforeach; ?>
			</ul>

			<div class="ufsc-competitions-table-wrap">
				<table class="widefat striped">
					<thead><tr><th>ID</th><th>fight_no</th><th><?php esc_html_e( 'Statut', 'ufsc-licence-competition' ); ?></th><th><?php esc_html_e( 'Anomalies', 'ufsc-licence-competition' ); ?></th></tr></thead>
					<tbody>
					<?php if ( empty( $sample ) ) : ?>
						<tr><td colspan="4"><?php esc_html_e( 'Aucune incohérence détectée.', 'ufsc-licence-competition' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $sample as $row ) : ?>
						<tr>
							<td><?php echo esc_html( (string) (int) ( $row['id'] ?? 0 ) ); ?></td>
							<td><?php echo esc_html( (string) (int) ( $row['fight_no'] ?? 0 ) ); ?></td>
							<td><?php echo esc_html( (string) ( $row['status'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( implode( ', ', array_map( 'strval', (array) ( $row['issues'] ?? array() ) ) ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</section>
		<?php
	}

	private function build_fights_diagnostic( int $competition_id ): array {
		$fights = $this->fights->list(
			array(
				'view'           => 'all',
				'competition_id' => $competition_id,
			),
			5000,
			0
		);

		$issues_count = array(
			'scheduled_with_winner'       => 0,
			'scheduled_with_result_method'=> 0,
			'scheduled_with_score'        => 0,
			'completed_without_winner'    => 0,
			'red_equals_blue'             => 0,
			'empty_corners_without_flag'  => 0,
		);
		$sample = array();

		foreach ( $fights as $fight ) {
			$status       = sanitize_key( (string) ( $fight->status ?? '' ) );
			$winner       = absint( $fight->winner_entry_id ?? 0 );
			$result_method= trim( (string) ( $fight->result_method ?? '' ) );
			$score_red    = trim( (string) ( $fight->score_red ?? '' ) );
			$score_blue   = trim( (string) ( $fight->score_blue ?? '' ) );
			$red_entry_id = absint( $fight->red_entry_id ?? 0 );
			$blue_entry_id= absint( $fight->blue_entry_id ?? 0 );
			$row_issues   = array();

			if ( 'scheduled' === $status && $winner > 0 ) {
				$issues_count['scheduled_with_winner']++;
				$row_issues[] = 'scheduled_with_winner';
			}
			if ( 'scheduled' === $status && '' !== $result_method ) {
				$issues_count['scheduled_with_result_method']++;
				$row_issues[] = 'scheduled_with_result_method';
			}
			if ( 'scheduled' === $status && ( '' !== $score_red || '' !== $score_blue ) ) {
				$issues_count['scheduled_with_score']++;
				$row_issues[] = 'scheduled_with_score';
			}
			if ( 'completed' === $status && $winner <= 0 ) {
				$issues_count['completed_without_winner']++;
				$row_issues[] = 'completed_without_winner';
			}
			if ( $red_entry_id > 0 && $red_entry_id === $blue_entry_id ) {
				$issues_count['red_equals_blue']++;
				$row_issues[] = 'red_equals_blue';
			}
			if ( $red_entry_id <= 0 && $blue_entry_id <= 0 && ! in_array( $status, array( 'bye', 'placeholder' ), true ) ) {
				$issues_count['empty_corners_without_flag']++;
				$row_issues[] = 'empty_corners_without_flag';
			}

			if ( ! empty( $row_issues ) ) {
				$sample[] = array(
					'id'      => (int) ( $fight->id ?? 0 ),
					'fight_no'=> (int) ( $fight->fight_no ?? 0 ),
					'status'  => $status,
					'issues'  => $row_issues,
				);
			}
		}

		return array(
			'total'  => count( $fights ),
			'issues' => $issues_count,
			'sample' => $sample,
		);
	}
}
