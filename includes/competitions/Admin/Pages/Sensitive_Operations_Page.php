<?php

namespace UFSC\Competitions\Admin\Pages;

use UFSC\Competitions\Admin\Menu;
use UFSC\Competitions\Capabilities;
use UFSC\Competitions\Repositories\CompetitionRepository;
use UFSC\Competitions\Repositories\EntryRepository;
use UFSC\Competitions\Repositories\FightRepository;
use UFSC\Competitions\Services\LogService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Sensitive_Operations_Page {
	private $competitions;
	private $entries;
	private $fights;
	private $logger;

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
						<input type="hidden" name="ufsc_sensitive_action" value="partial_regen" />
						<p><label><?php esc_html_e( 'Category ID', 'ufsc-licence-competition' ); ?> <input type="number" name="category_id" required min="1" /></label></p>
						<p><label><?php esc_html_e( 'Motif obligatoire', 'ufsc-licence-competition' ); ?><textarea name="reason" required rows="3" class="large-text"></textarea></label></p>
						<p><label><input type="checkbox" name="supervisor_confirm" value="1" required> <?php esc_html_e( 'Je confirme la régénération partielle supervisée', 'ufsc-licence-competition' ); ?></label></p>
						<?php submit_button( __( 'Exécuter la régénération partielle', 'ufsc-licence-competition' ), 'delete', '', false ); ?>
					</form>
				</section>
			</div>
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
		if ( 'partial_regen' === $action ) {
			check_admin_referer( 'ufsc_sensitive_regen_' . $competition_id );
			return $this->handle_partial_regen( $competition_id );
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

	private function handle_partial_regen( int $competition_id ): array {
		$category_id = isset( $_POST['category_id'] ) ? absint( $_POST['category_id'] ) : 0;
		$reason = sanitize_textarea_field( (string) wp_unslash( $_POST['reason'] ?? '' ) );
		$supervised = ! empty( $_POST['supervisor_confirm'] );
		if ( ! $category_id || '' === $reason || ! $supervised ) {
			return array( 'type' => 'error', 'message' => __( 'Régénération partielle refusée : champs requis manquants.', 'ufsc-licence-competition' ) );
		}

		$fights = $this->fights->list( array( 'view' => 'all', 'competition_id' => $competition_id, 'category_id' => $category_id ), 500, 0 );
		if ( empty( $fights ) ) {
			return array( 'type' => 'error', 'message' => __( 'Aucun combat pour cette catégorie.', 'ufsc-licence-competition' ) );
		}

		$max_completed_no = 0;
		foreach ( $fights as $fight ) {
			if ( 'completed' === (string) ( $fight->status ?? '' ) ) {
				$max_completed_no = max( $max_completed_no, (int) ( $fight->fight_no ?? 0 ) );
			}
		}

		$deleted = 0;
		foreach ( $fights as $fight ) {
			if ( (int) ( $fight->fight_no ?? 0 ) <= $max_completed_no ) {
				continue;
			}
			if ( in_array( (string) ( $fight->status ?? '' ), array( 'completed', 'running' ), true ) ) {
				continue;
			}
			$this->fights->soft_delete( (int) $fight->id );
			$deleted++;
		}

		$this->logger->log(
			'partial_regeneration_safe',
			'fight',
			$category_id,
			'Régénération partielle sécurisée',
			array(
				'competition_id' => $competition_id,
				'category_id' => $category_id,
				'reason' => $reason,
				'max_completed_fight_no' => $max_completed_no,
				'soft_deleted_fights' => $deleted,
			)
		);

		return array( 'type' => 'success', 'message' => sprintf( __( 'Régénération partielle prête : %d combats non joués ont été retirés (corbeille). Relancez la génération sur la zone concernée.', 'ufsc-licence-competition' ), $deleted ) );
	}
}
