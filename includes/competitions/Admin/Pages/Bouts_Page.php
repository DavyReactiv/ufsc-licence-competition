<?php

namespace UFSC\Competitions\Admin\Pages;

use UFSC\Competitions\Capabilities;
use UFSC\Competitions\Admin\Menu;
use UFSC\Competitions\Repositories\FightRepository;
use UFSC\Competitions\Repositories\CompetitionRepository;
use UFSC\Competitions\Repositories\CategoryRepository;
use UFSC\Competitions\Admin\Tables\Fights_Table;
use UFSC\Competitions\Services\LogService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Bouts_Page {
	private $repository;
	private $competitions;
	private $categories;
	private $logger;

	public function __construct() {
		$this->repository = new FightRepository();
		$this->competitions = new CompetitionRepository();
		$this->categories = new CategoryRepository();
		$this->logger = new LogService();
	}

	public function register_actions() {
		add_action( 'admin_post_ufsc_competitions_save_fight', array( $this, 'handle_save' ) );
		add_action( 'admin_post_ufsc_competitions_trash_fight', array( $this, 'handle_trash' ) );
		add_action( 'admin_post_ufsc_competitions_restore_fight', array( $this, 'handle_restore' ) );
		add_action( 'admin_post_ufsc_competitions_delete_fight', array( $this, 'handle_delete' ) );
		add_action( 'admin_post_ufsc_competitions_correct_result', array( $this, 'handle_correct_result' ) );
	}

	public function render() {
		if ( ! Capabilities::user_can_manage() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ) );
		}

		$action = isset( $_GET['ufsc_action'] ) ? sanitize_key( wp_unslash( $_GET['ufsc_action'] ) ) : '';
		$notice = isset( $_GET['ufsc_notice'] ) ? sanitize_key( wp_unslash( $_GET['ufsc_notice'] ) ) : '';
		$fight_notice = isset( $_GET['ufsc_fight_notice'] ) ? sanitize_key( wp_unslash( $_GET['ufsc_fight_notice'] ) ) : '';
		$fight_message = isset( $_GET['ufsc_fight_message'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['ufsc_fight_message'] ) ) ) : '';
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$competition_id = $this->resolve_competition_context_id();

		$this->render_notice( $notice );
		if ( $fight_notice && class_exists( '\UFSC\Competitions\Admin\Pages\Bouts_AutoGeneration' ) ) {
			\UFSC\Competitions\Admin\Pages\Bouts_AutoGeneration::render_notice( $fight_notice, $fight_message );
		}

		if ( in_array( $action, array( 'add', 'edit', 'correct_result' ), true ) ) {
			$item = null;
			if ( in_array( $action, array( 'edit', 'correct_result' ), true ) && $id ) {
				$item = $this->repository->get( $id, true );
			}
			if ( 'correct_result' === $action ) {
				$this->render_correction_form( $item );
				return;
			}
			$this->render_form( $item );
			return;
		}

		$list_table = new Fights_Table();
		$this->maybe_handle_bulk_actions( $list_table, Menu::PAGE_BOUTS );
		$list_table->prepare_items();
		$total_fights = (int) $this->repository->count( array( 'view' => 'all' ) );
		$scheduled_fights = (int) $this->repository->count( array( 'view' => 'all', 'status' => 'scheduled' ) );
		$running_fights = (int) $this->repository->count( array( 'view' => 'all', 'status' => 'running' ) );
		$completed_fights = (int) $this->repository->count( array( 'view' => 'all', 'status' => 'completed' ) );

		?>
		<div class="wrap ufsc-competitions-admin">
			<header class="ufsc-admin-page-header">
				<div>
					<p class="ufsc-admin-page-kicker"><?php esc_html_e( 'Tableaux de combat', 'ufsc-licence-competition' ); ?></p>
					<h1 class="wp-heading-inline"><?php esc_html_e( 'Combats', 'ufsc-licence-competition' ); ?></h1>
					<p class="ufsc-admin-page-description"><?php esc_html_e( 'Générez, planifiez et suivez les combats par surface avec une lecture opérationnelle claire.', 'ufsc-licence-competition' ); ?></p>
				</div>
				<div class="ufsc-admin-page-actions">
					<a href="<?php echo esc_url( add_query_arg( array( 'page' => Menu::PAGE_BOUTS, 'ufsc_action' => 'add' ), admin_url( 'admin.php' ) ) ); ?>" class="button button-primary"><?php esc_html_e( 'Ajouter un combat', 'ufsc-licence-competition' ); ?></a>
				</div>
			</header>
			<section class="ufsc-kpis ufsc-kpis--premium">
				<article class="ufsc-kpi"><span class="ufsc-kpi__label"><?php esc_html_e( 'Total combats', 'ufsc-licence-competition' ); ?></span><strong class="ufsc-kpi__value"><?php echo esc_html( number_format_i18n( $total_fights ) ); ?></strong></article>
				<article class="ufsc-kpi"><span class="ufsc-kpi__label"><?php esc_html_e( 'Planifiés', 'ufsc-licence-competition' ); ?></span><strong class="ufsc-kpi__value"><?php echo esc_html( number_format_i18n( $scheduled_fights ) ); ?></strong></article>
				<article class="ufsc-kpi"><span class="ufsc-kpi__label"><?php esc_html_e( 'En cours', 'ufsc-licence-competition' ); ?></span><strong class="ufsc-kpi__value"><?php echo esc_html( number_format_i18n( $running_fights ) ); ?></strong></article>
				<article class="ufsc-kpi"><span class="ufsc-kpi__label"><?php esc_html_e( 'Terminés', 'ufsc-licence-competition' ); ?></span><strong class="ufsc-kpi__value"><?php echo esc_html( number_format_i18n( $completed_fights ) ); ?></strong></article>
			</section>
			<div class="notice notice-info ufsc-competitions-helper"><p><?php esc_html_e( 'Planifier les combats, assigner les combattants, suivre les résultats. Modifier un résultat peut impacter les combats suivants.', 'ufsc-licence-competition' ); ?></p></div>
			<div class="ufsc-admin-surface ufsc-admin-generation-surface">
				<?php
				if ( class_exists( '\UFSC\Competitions\Admin\Pages\Bouts_AutoGeneration' ) ) {
					\UFSC\Competitions\Admin\Pages\Bouts_AutoGeneration::render_panel( $competition_id );
				}
				?>
			</div>
			<section class="ufsc-admin-surface ufsc-admin-listing-surface">
			<?php $list_table->views(); ?>
			<form method="post" class="ufsc-admin-toolbar">
				<input type="hidden" name="page" value="<?php echo esc_attr( Menu::PAGE_BOUTS ); ?>" />
				<?php $list_table->search_box( __( 'Rechercher', 'ufsc-licence-competition' ), 'ufsc-competitions-fights-search' ); ?>
			</form>
			<form method="post">
				<input type="hidden" name="page" value="<?php echo esc_attr( Menu::PAGE_BOUTS ); ?>" />
				<div class="ufsc-competitions-table-wrap">
					<?php $list_table->display(); ?>
				</div>
			</form>
			</section>
		</div>
		<?php
	}

	public function handle_save() {
		if ( ! Capabilities::user_can_manage() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'ufsc_competitions_save_fight' );

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$raw_status = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : FightRepository::STATUS_SCHEDULED;
		$normalized_status = $this->repository->normalize_fight_status( $raw_status );
		if ( ! $this->repository->is_valid_fight_status( $normalized_status, false ) ) {
			$this->redirect_with_notice( Menu::PAGE_BOUTS, 'status_invalid', $id, __( 'Le statut demandé n’est pas autorisé.', 'ufsc-licence-competition' ) );
		}

		$data = array(
			'competition_id'  => isset( $_POST['competition_id'] ) ? absint( $_POST['competition_id'] ) : 0,
			'category_id'     => isset( $_POST['category_id'] ) ? absint( $_POST['category_id'] ) : 0,
			'fight_no'        => isset( $_POST['fight_no'] ) ? absint( $_POST['fight_no'] ) : 0,
			'ring'            => isset( $_POST['ring'] ) ? sanitize_text_field( wp_unslash( $_POST['ring'] ) ) : '',
			'round_no'        => isset( $_POST['round_no'] ) ? absint( $_POST['round_no'] ) : 0,
			'red_entry_id'    => isset( $_POST['red_entry_id'] ) ? absint( $_POST['red_entry_id'] ) : 0,
			'blue_entry_id'   => isset( $_POST['blue_entry_id'] ) ? absint( $_POST['blue_entry_id'] ) : 0,
			'winner_entry_id' => isset( $_POST['winner_entry_id'] ) ? absint( $_POST['winner_entry_id'] ) : 0,
			'status'          => $normalized_status,
			'result_method'   => isset( $_POST['result_method'] ) ? sanitize_text_field( wp_unslash( $_POST['result_method'] ) ) : '',
			'score_red'       => isset( $_POST['score_red'] ) ? sanitize_text_field( wp_unslash( $_POST['score_red'] ) ) : '',
			'score_blue'      => isset( $_POST['score_blue'] ) ? sanitize_text_field( wp_unslash( $_POST['score_blue'] ) ) : '',
			'scheduled_at'    => isset( $_POST['scheduled_at'] ) ? sanitize_text_field( wp_unslash( $_POST['scheduled_at'] ) ) : '',
		);

		if ( ! $data['competition_id'] || ! $data['fight_no'] ) {
			$this->redirect_with_notice( Menu::PAGE_BOUTS, 'error_required', $id );
		}

		if ( $id ) {
			$existing = $this->repository->get( $id, true );
			if ( $existing && $this->repository->is_fight_sensitive( $existing ) ) {
				$this->logger->log(
					'fight_update_blocked_sensitive',
					'fight',
					$id,
					'Tentative de modification bloquée sur un combat sensible.',
					array(
						'status'          => (string) ( $existing->status ?? '' ),
						'winner_entry_id' => (int) ( $existing->winner_entry_id ?? 0 ),
					)
				);
				$this->redirect_with_notice( Menu::PAGE_BOUTS, 'protected_edit', $id );
			}
			if ( $existing ) {
				$current_effective_status = $this->repository->get_effective_fight_status( $existing );
				if ( $current_effective_status !== $data['status'] && ! $this->repository->can_transition_status( $existing, $data['status'] ) ) {
					$message = __( 'Le statut demandé n’est pas autorisé.', 'ufsc-licence-competition' );
					if ( FightRepository::STATUS_COMPLETED === $current_effective_status && FightRepository::STATUS_SCHEDULED === $data['status'] ) {
						$message = __( 'Un combat terminé ne peut pas revenir au statut prévu sans procédure de correction supervisée.', 'ufsc-licence-competition' );
					} elseif ( $this->fight_has_result_payload( $existing ) ) {
						$message = __( 'Le statut de ce combat ne peut pas être modifié car il contient déjà un résultat.', 'ufsc-licence-competition' );
					}
					$this->redirect_with_notice( Menu::PAGE_BOUTS, 'status_transition_blocked', $id, $message );
				}
			}
		}

		if ( $id ) {
			$this->repository->update( $id, $data );
			$this->redirect_with_notice( Menu::PAGE_BOUTS, 'updated', $id );
		}

		$new_id = $this->repository->insert( $data );
		$this->redirect_with_notice( Menu::PAGE_BOUTS, 'created', $new_id );
	}

	public function handle_trash() {
		$this->handle_simple_action( 'ufsc_competitions_trash_fight', 'trash', Menu::PAGE_BOUTS );
	}

	public function handle_restore() {
		$this->handle_simple_action( 'ufsc_competitions_restore_fight', 'restore', Menu::PAGE_BOUTS );
	}

	public function handle_delete() {
		$this->handle_simple_action( 'ufsc_competitions_delete_fight', 'delete', Menu::PAGE_BOUTS );
	}

	private function handle_simple_action( $action, $method, $page_slug ) {
		if ( ! Capabilities::user_can_manage() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		check_admin_referer( $action . '_' . $id );

		if ( ! $id ) {
			$this->redirect_with_notice( $page_slug, 'not_found' );
		}

		$fight = $this->repository->get( $id, true );
		if ( ! $fight ) {
			$this->redirect_with_notice( $page_slug, 'not_found' );
		}

		switch ( $method ) {
			case 'trash':
				if ( ! $this->repository->can_delete_fight( $fight ) ) {
					$this->logger->log( 'fight_trash_blocked_sensitive', 'fight', $id, 'Mise en corbeille bloquée.', array( 'status' => (string) ( $fight->status ?? '' ) ) );
					$this->redirect_with_notice( $page_slug, 'protected_delete' );
				}
				$this->repository->soft_delete( $id );
				$this->redirect_with_notice( $page_slug, 'trashed' );
				break;
			case 'restore':
				$this->repository->restore( $id );
				$this->redirect_with_notice( $page_slug, 'restored' );
				break;
			case 'delete':
				if ( ! Capabilities::user_can_delete() ) {
					wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
				}
				if ( ! $this->repository->can_delete_fight( $fight ) ) {
					$this->logger->log( 'fight_delete_blocked_sensitive', 'fight', $id, 'Suppression définitive bloquée.', array( 'status' => (string) ( $fight->status ?? '' ) ) );
					$this->redirect_with_notice( $page_slug, 'protected_delete' );
				}
				$this->repository->delete( $id );
				$this->redirect_with_notice( $page_slug, 'deleted' );
				break;
		}
	}

	private function render_form( $item ) {
		$values = array(
			'id'              => $item->id ?? 0,
			'competition_id'  => $item->competition_id ?? 0,
			'category_id'     => $item->category_id ?? 0,
			'fight_no'        => $item->fight_no ?? 0,
			'ring'            => $item->ring ?? '',
			'round_no'        => $item->round_no ?? 0,
			'red_entry_id'    => $item->red_entry_id ?? 0,
			'blue_entry_id'   => $item->blue_entry_id ?? 0,
			'winner_entry_id' => $item->winner_entry_id ?? 0,
			'status'          => $item->status ?? 'scheduled',
			'result_method'   => $item->result_method ?? '',
			'score_red'       => $item->score_red ?? '',
			'score_blue'      => $item->score_blue ?? '',
			'scheduled_at'    => $this->format_datetime_local( $item->scheduled_at ?? '' ),
		);

		$competition_view = isset( $_GET['ufsc_competition_view'] ) ? sanitize_key( wp_unslash( $_GET['ufsc_competition_view'] ) ) : 'all_with_archived';
		$competition_filters = array( 'view' => \UFSC\Competitions\Repositories\CompetitionRepository::normalize_view( $competition_view ) );
		if ( function_exists( 'ufsc_lc_competitions_apply_scope_to_query_args' ) ) {
			$competition_filters = ufsc_lc_competitions_apply_scope_to_query_args( $competition_filters );
		}
		$competitions = $this->competitions->list( $competition_filters, 200, 0 );
		$categories = $this->categories->list( array( 'view' => 'all' ), 500, 0 );
		$action_label = $values['id'] ? __( 'Mettre à jour', 'ufsc-licence-competition' ) : __( 'Créer le combat', 'ufsc-licence-competition' );
		?>
		<div class="wrap ufsc-competitions-admin">
			<header class="ufsc-admin-page-header">
				<div>
					<p class="ufsc-admin-page-kicker"><?php esc_html_e( 'Gestion des combats', 'ufsc-licence-competition' ); ?></p>
					<h1><?php echo esc_html( $values['id'] ? __( 'Modifier le combat', 'ufsc-licence-competition' ) : __( 'Nouveau combat', 'ufsc-licence-competition' ) ); ?></h1>
					<p class="ufsc-admin-page-description"><?php esc_html_e( 'Renseignez les paramètres clés du combat sans altérer les flux de planification et de résultats.', 'ufsc-licence-competition' ); ?></p>
				</div>
			</header>
			<div class="notice notice-info ufsc-competitions-helper"><p><?php esc_html_e( 'Planifier les combats, assigner les combattants, suivre les résultats. Modifier un résultat peut impacter les combats suivants.', 'ufsc-licence-competition' ); ?></p></div>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ufsc-competitions-form">
				<?php wp_nonce_field( 'ufsc_competitions_save_fight' ); ?>
				<input type="hidden" name="action" value="ufsc_competitions_save_fight">
				<input type="hidden" name="id" value="<?php echo esc_attr( $values['id'] ); ?>">
				<div class="ufsc-admin-surface ufsc-admin-form-surface">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ufsc_fight_competition"><?php esc_html_e( 'Compétition', 'ufsc-licence-competition' ); ?></label></th>
						<td>
							<select name="competition_id" id="ufsc_fight_competition" class="regular-text" required>
								<option value="0"><?php esc_html_e( 'Sélectionner', 'ufsc-licence-competition' ); ?></option>
								<?php foreach ( $competitions as $competition ) : ?>
									<option value="<?php echo esc_attr( $competition->id ); ?>" <?php selected( $values['competition_id'], $competition->id ); ?>><?php echo esc_html( $competition->name ); ?></option>
								<?php endforeach; ?>
							</select>
							<?php if ( empty( $competitions ) ) : ?>
								<p class="description"><?php esc_html_e( 'Aucune compétition dans votre périmètre (scope). Vérifiez les filtres et la région.', 'ufsc-licence-competition' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_fight_category"><?php esc_html_e( 'Catégorie', 'ufsc-licence-competition' ); ?></label></th>
						<td>
							<select name="category_id" id="ufsc_fight_category" class="regular-text">
								<option value="0"><?php esc_html_e( 'Sélectionner', 'ufsc-licence-competition' ); ?></option>
								<?php foreach ( $categories as $category ) : ?>
									<option value="<?php echo esc_attr( $category->id ); ?>" <?php selected( $values['category_id'], $category->id ); ?>><?php echo esc_html( $category->name ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_fight_no"><?php esc_html_e( 'N° de combat', 'ufsc-licence-competition' ); ?></label></th>
						<td><input name="fight_no" type="number" id="ufsc_fight_no" value="<?php echo esc_attr( $values['fight_no'] ); ?>" required></td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_fight_ring"><?php esc_html_e( 'Ring/Tatami', 'ufsc-licence-competition' ); ?></label></th>
						<td><input name="ring" type="text" id="ufsc_fight_ring" value="<?php echo esc_attr( $values['ring'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_fight_round"><?php esc_html_e( 'Round', 'ufsc-licence-competition' ); ?></label></th>
						<td><input name="round_no" type="number" id="ufsc_fight_round" value="<?php echo esc_attr( $values['round_no'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_fight_red"><?php esc_html_e( 'Entrée rouge (ID)', 'ufsc-licence-competition' ); ?></label></th>
						<td><input name="red_entry_id" type="number" id="ufsc_fight_red" value="<?php echo esc_attr( $values['red_entry_id'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_fight_blue"><?php esc_html_e( 'Entrée bleue (ID)', 'ufsc-licence-competition' ); ?></label></th>
						<td><input name="blue_entry_id" type="number" id="ufsc_fight_blue" value="<?php echo esc_attr( $values['blue_entry_id'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_fight_winner"><?php esc_html_e( 'Vainqueur (ID)', 'ufsc-licence-competition' ); ?></label></th>
						<td><input name="winner_entry_id" type="number" id="ufsc_fight_winner" value="<?php echo esc_attr( $values['winner_entry_id'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_fight_status"><?php esc_html_e( 'Statut', 'ufsc-licence-competition' ); ?></label></th>
						<td>
							<select name="status" id="ufsc_fight_status" class="regular-text">
								<option value="scheduled" <?php selected( $values['status'], 'scheduled' ); ?>><?php esc_html_e( 'Planifié', 'ufsc-licence-competition' ); ?></option>
								<option value="running" <?php selected( $values['status'], 'running' ); ?>><?php esc_html_e( 'En cours', 'ufsc-licence-competition' ); ?></option>
								<option value="completed" <?php selected( $values['status'], 'completed' ); ?>><?php esc_html_e( 'Terminé', 'ufsc-licence-competition' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_fight_result_method"><?php esc_html_e( 'Méthode', 'ufsc-licence-competition' ); ?></label></th>
						<td><input name="result_method" type="text" id="ufsc_fight_result_method" class="regular-text" value="<?php echo esc_attr( $values['result_method'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_fight_score_red"><?php esc_html_e( 'Score rouge', 'ufsc-licence-competition' ); ?></label></th>
						<td><input name="score_red" type="text" id="ufsc_fight_score_red" value="<?php echo esc_attr( $values['score_red'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_fight_score_blue"><?php esc_html_e( 'Score bleu', 'ufsc-licence-competition' ); ?></label></th>
						<td><input name="score_blue" type="text" id="ufsc_fight_score_blue" value="<?php echo esc_attr( $values['score_blue'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_fight_scheduled"><?php esc_html_e( 'Horaire', 'ufsc-licence-competition' ); ?></label></th>
						<td><input name="scheduled_at" type="datetime-local" id="ufsc_fight_scheduled" value="<?php echo esc_attr( $values['scheduled_at'] ); ?>"></td>
					</tr>
				</table>
				</div>
				<?php submit_button( $action_label ); ?>
			</form>
		</div>
		<?php
	}

	private function resolve_competition_context_id(): int {
		$sources = array(
			'ufsc_competition_id' => isset( $_REQUEST['ufsc_competition_id'] ) ? absint( $_REQUEST['ufsc_competition_id'] ) : 0,
			'competition_id'      => isset( $_REQUEST['competition_id'] ) ? absint( $_REQUEST['competition_id'] ) : 0,
		);
		foreach ( $sources as $source => $competition_id ) {
			if ( $competition_id > 0 ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log(
						'UFSC Bouts_Page competition_context ' . wp_json_encode(
							array(
								'competition_id' => $competition_id,
								'source'         => $source,
							)
						)
					);
				}
				return $competition_id;
			}
		}

		return 0;
	}

	private function render_correction_form( $fight ) {
		if ( ! $fight ) {
			$this->redirect_with_notice( Menu::PAGE_BOUTS, 'not_found' );
		}

		$impacts = $this->get_impacted_fights( $fight );
		$has_played_impacts = ! empty( $impacts['played'] );
		?>
		<div class="wrap ufsc-competitions-admin">
			<header class="ufsc-admin-page-header">
				<div>
					<p class="ufsc-admin-page-kicker"><?php esc_html_e( 'Action sensible', 'ufsc-licence-competition' ); ?></p>
					<h1><?php esc_html_e( 'Corriger le résultat', 'ufsc-licence-competition' ); ?></h1>
					<p class="ufsc-admin-page-description"><?php esc_html_e( 'Cette action est auditée. Un motif est obligatoire et une supervision renforcée est exigée si des combats suivants sont déjà joués.', 'ufsc-licence-competition' ); ?></p>
				</div>
			</header>

			<div class="notice notice-warning"><p><?php esc_html_e( 'Ne corrigez un résultat que si nécessaire. Toute correction peut impacter la suite du tableau.', 'ufsc-licence-competition' ); ?></p></div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'ufsc_competitions_correct_result_' . (int) $fight->id ); ?>
				<input type="hidden" name="action" value="ufsc_competitions_correct_result" />
				<input type="hidden" name="fight_id" value="<?php echo esc_attr( (int) $fight->id ); ?>" />
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Combat', 'ufsc-licence-competition' ); ?></th>
						<td><?php echo esc_html( '#' . (int) ( $fight->fight_no ?? 0 ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_new_winner"><?php esc_html_e( 'Nouveau vainqueur (Entry ID)', 'ufsc-licence-competition' ); ?></label></th>
						<td><input type="number" required min="1" name="winner_entry_id" id="ufsc_new_winner" value="<?php echo esc_attr( (int) ( $fight->winner_entry_id ?? 0 ) ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_new_method"><?php esc_html_e( 'Méthode', 'ufsc-licence-competition' ); ?></label></th>
						<td><input type="text" class="regular-text" name="result_method" id="ufsc_new_method" value="<?php echo esc_attr( (string) ( $fight->result_method ?? '' ) ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_correction_reason"><?php esc_html_e( 'Motif (obligatoire)', 'ufsc-licence-competition' ); ?></label></th>
						<td><textarea name="correction_reason" id="ufsc_correction_reason" class="large-text" rows="4" required></textarea></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Impacts détectés', 'ufsc-licence-competition' ); ?></h2>
				<ul>
					<li><?php echo esc_html( sprintf( __( 'Combats suivants non joués : %d', 'ufsc-licence-competition' ), count( $impacts['pending'] ) ) ); ?></li>
					<li><?php echo esc_html( sprintf( __( 'Combats suivants déjà joués : %d', 'ufsc-licence-competition' ), count( $impacts['played'] ) ) ); ?></li>
				</ul>
				<?php if ( $has_played_impacts ) : ?>
					<p><label><input type="checkbox" name="supervisor_confirm" value="1" required> <?php esc_html_e( 'Je valide en tant que superviseur cette correction avec impacts déjà joués.', 'ufsc-licence-competition' ); ?></label></p>
				<?php endif; ?>

				<?php submit_button( __( 'Confirmer la correction', 'ufsc-licence-competition' ), 'primary' ); ?>
			</form>
		</div>
		<?php
	}

	public function handle_correct_result() {
		if ( ! Capabilities::user_can_manage() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		$fight_id = isset( $_POST['fight_id'] ) ? absint( $_POST['fight_id'] ) : 0;
		check_admin_referer( 'ufsc_competitions_correct_result_' . $fight_id );

		$fight = $this->repository->get( $fight_id, true );
		if ( ! $fight ) {
			$this->redirect_with_notice( Menu::PAGE_BOUTS, 'not_found' );
		}

		$winner_entry_id = isset( $_POST['winner_entry_id'] ) ? absint( $_POST['winner_entry_id'] ) : 0;
		$result_method   = isset( $_POST['result_method'] ) ? sanitize_text_field( wp_unslash( $_POST['result_method'] ) ) : '';
		$reason          = isset( $_POST['correction_reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['correction_reason'] ) ) : '';
		$supervised      = ! empty( $_POST['supervisor_confirm'] );

		if ( ! $winner_entry_id || '' === $reason ) {
			$this->redirect_with_notice( Menu::PAGE_BOUTS, 'correction_invalid' );
		}

		$red_entry_id  = (int) ( $fight->red_entry_id ?? 0 );
		$blue_entry_id = (int) ( $fight->blue_entry_id ?? 0 );
		if ( $red_entry_id <= 0 || $blue_entry_id <= 0 ) {
			$this->logger->log( 'result_correction_blocked_invalid_corners', 'fight', $fight_id, 'Correction bloquée : coins invalides.', array( 'red_entry_id' => $red_entry_id, 'blue_entry_id' => $blue_entry_id ) );
			$this->redirect_with_notice( Menu::PAGE_BOUTS, 'correction_invalid', 0, __( 'Correction impossible : les coins rouge/bleu doivent être renseignés.', 'ufsc-licence-competition' ) );
		}

		if ( $winner_entry_id !== $red_entry_id && $winner_entry_id !== $blue_entry_id ) {
			$this->logger->log( 'result_correction_blocked_winner_mismatch', 'fight', $fight_id, 'Correction bloquée : vainqueur hors coins.', array( 'winner_entry_id' => $winner_entry_id, 'red_entry_id' => $red_entry_id, 'blue_entry_id' => $blue_entry_id ) );
			$this->redirect_with_notice( Menu::PAGE_BOUTS, 'correction_invalid', 0, __( 'Correction impossible : le vainqueur doit correspondre au coin rouge ou bleu.', 'ufsc-licence-competition' ) );
		}

		$impacts = $this->get_impacted_fights( $fight );
		if ( ! empty( $impacts['played'] ) && ! $supervised ) {
			$this->logger->log(
				'result_correction_supervisor_required',
				'fight',
				$fight_id,
				'Correction bloquée : combats suivants déjà joués.',
				array(
					'impacted_played_fights' => wp_list_pluck( $impacts['played'], 'id' ),
				)
			);
			$this->redirect_with_notice( Menu::PAGE_BOUTS, 'correction_supervisor_required', 0, __( 'Validation superviseur obligatoire : des combats suivants sont déjà en cours/terminés.', 'ufsc-licence-competition' ) );
		}

		$old_winner = (int) ( $fight->winner_entry_id ?? 0 );
		$this->repository->update(
			$fight_id,
			array(
				'competition_id'  => (int) $fight->competition_id,
				'category_id'     => (int) $fight->category_id,
				'fight_no'        => (int) $fight->fight_no,
				'ring'            => (string) ( $fight->ring ?? '' ),
				'round_no'        => (int) ( $fight->round_no ?? 0 ),
				'red_entry_id'    => (int) ( $fight->red_entry_id ?? 0 ),
				'blue_entry_id'   => (int) ( $fight->blue_entry_id ?? 0 ),
				'winner_entry_id' => $winner_entry_id,
				'status'          => 'completed',
				'result_method'   => $result_method,
				'score_red'       => (string) ( $fight->score_red ?? '' ),
				'score_blue'      => (string) ( $fight->score_blue ?? '' ),
				'scheduled_at'    => (string) ( $fight->scheduled_at ?? '' ),
			)
		);
		$propagation = $this->propagate_winner_to_next_round( $fight, $old_winner, $winner_entry_id );

		$this->logger->log(
			'result_correction',
			'fight',
			$fight_id,
			'Correction de résultat supervisée',
			array(
				'reason' => $reason,
				'old_winner_entry_id' => $old_winner,
				'new_winner_entry_id' => $winner_entry_id,
				'impacted_played_fights' => wp_list_pluck( $impacts['played'], 'id' ),
				'impacted_pending_fights' => wp_list_pluck( $impacts['pending'], 'id' ),
				'next_round_propagation' => $propagation,
				'supervised' => $supervised ? 1 : 0,
			)
		);

		$this->redirect_with_notice( Menu::PAGE_BOUTS, 'correction_done' );
	}

	private function get_impacted_fights( $fight ): array {
		$filters = array(
			'view' => 'all',
			'competition_id' => (int) $fight->competition_id,
			'category_id' => (int) $fight->category_id,
		);
		$category_fights = $this->repository->list( $filters, 500, 0 );
		$pending = array();
		$played  = array();

		foreach ( $category_fights as $candidate ) {
			if ( (int) $candidate->id === (int) $fight->id ) {
				continue;
			}
			if ( (int) ( $candidate->fight_no ?? 0 ) <= (int) ( $fight->fight_no ?? 0 ) ) {
				continue;
			}
			if ( in_array( (string) ( $candidate->status ?? '' ), array( 'completed', 'running' ), true ) ) {
				$played[] = $candidate;
			} else {
				$pending[] = $candidate;
			}
		}

		return array(
			'pending' => $pending,
			'played' => $played,
		);
	}

	private function propagate_winner_to_next_round( $fight, int $old_winner_entry_id, int $new_winner_entry_id ): array {
		$result = array(
			'updated' => false,
			'next_fight_id' => 0,
			'slot' => '',
			'reason' => 'no_next_fight',
		);
		if ( $new_winner_entry_id <= 0 ) {
			$result['reason'] = 'invalid_new_winner';
			return $result;
		}

		$category_fights = $this->repository->list(
			array(
				'view' => 'all',
				'competition_id' => (int) ( $fight->competition_id ?? 0 ),
				'category_id' => (int) ( $fight->category_id ?? 0 ),
			),
			500,
			0
		);
		if ( ! is_array( $category_fights ) || empty( $category_fights ) ) {
			$result['reason'] = 'empty_category';
			return $result;
		}

		$current_round = max( 1, (int) ( $fight->round_no ?? 1 ) );
		$current_round_fights = array_values(
			array_filter(
				$category_fights,
				static function ( $candidate ) use ( $current_round ) {
					return (int) ( $candidate->round_no ?? 1 ) === $current_round;
				}
			)
		);
		$next_round_fights = array_values(
			array_filter(
				$category_fights,
				static function ( $candidate ) use ( $current_round ) {
					return (int) ( $candidate->round_no ?? 1 ) === ( $current_round + 1 );
				}
			)
		);

		if ( empty( $current_round_fights ) || empty( $next_round_fights ) ) {
			return $result;
		}

		usort(
			$current_round_fights,
			static function ( $a, $b ) {
				return (int) ( $a->fight_no ?? 0 ) <=> (int) ( $b->fight_no ?? 0 );
			}
		);
		usort(
			$next_round_fights,
			static function ( $a, $b ) {
				return (int) ( $a->fight_no ?? 0 ) <=> (int) ( $b->fight_no ?? 0 );
			}
		);

		$current_index = null;
		foreach ( $current_round_fights as $index => $current_fight ) {
			if ( (int) ( $current_fight->id ?? 0 ) === (int) ( $fight->id ?? 0 ) ) {
				$current_index = $index;
				break;
			}
		}
		if ( null === $current_index ) {
			$result['reason'] = 'current_index_not_found';
			return $result;
		}

		$next_index = (int) floor( $current_index / 2 );
		$next_fight = $next_round_fights[ $next_index ] ?? null;
		if ( ! $next_fight ) {
			return $result;
		}

		$slot = 0 === ( $current_index % 2 ) ? 'red_entry_id' : 'blue_entry_id';
		$existing_slot_value = (int) ( $next_fight->{$slot} ?? 0 );
		$next_status = (string) ( $next_fight->status ?? '' );
		$result['next_fight_id'] = (int) ( $next_fight->id ?? 0 );
		$result['slot'] = $slot;

		if ( in_array( $next_status, array( 'running', 'completed' ), true ) ) {
			$result['reason'] = 'next_fight_already_played';
			return $result;
		}

		if ( $existing_slot_value > 0 && $existing_slot_value !== $old_winner_entry_id && $existing_slot_value !== $new_winner_entry_id ) {
			$result['reason'] = 'slot_locked_with_other_entry';
			return $result;
		}

		$payload = array(
			'competition_id' => (int) ( $next_fight->competition_id ?? 0 ),
			'category_id' => (int) ( $next_fight->category_id ?? 0 ),
			'fight_no' => (int) ( $next_fight->fight_no ?? 0 ),
			'ring' => (string) ( $next_fight->ring ?? '' ),
			'round_no' => (int) ( $next_fight->round_no ?? 0 ),
			'red_entry_id' => (int) ( $next_fight->red_entry_id ?? 0 ),
			'blue_entry_id' => (int) ( $next_fight->blue_entry_id ?? 0 ),
			'winner_entry_id' => (int) ( $next_fight->winner_entry_id ?? 0 ),
			'status' => (string) ( $next_fight->status ?? 'scheduled' ),
			'result_method' => (string) ( $next_fight->result_method ?? '' ),
			'score_red' => (string) ( $next_fight->score_red ?? '' ),
			'score_blue' => (string) ( $next_fight->score_blue ?? '' ),
			'scheduled_at' => (string) ( $next_fight->scheduled_at ?? '' ),
		);
		$payload[ $slot ] = $new_winner_entry_id;

		if ( (int) $payload['winner_entry_id'] === $old_winner_entry_id && $old_winner_entry_id !== $new_winner_entry_id ) {
			$payload['winner_entry_id'] = 0;
			$payload['status'] = 'scheduled';
			$payload['result_method'] = '';
			$payload['score_red'] = '';
			$payload['score_blue'] = '';
		}

		$this->repository->update( (int) $next_fight->id, $payload );

		$result['updated'] = true;
		$result['reason'] = 'propagated';
		return $result;
	}

	private function format_datetime_local( $value ) {
		if ( empty( $value ) ) {
			return '';
		}

		$timestamp = strtotime( $value );
		if ( ! $timestamp ) {
			return '';
		}

		return date_i18n( 'Y-m-d\TH:i', $timestamp );
	}

	private function redirect_with_notice( $page, $notice, $id = 0, $message = '' ) {
		$url = add_query_arg(
			array(
				'page'        => $page,
				'ufsc_notice' => $notice,
			),
			admin_url( 'admin.php' )
		);

		if ( $id ) {
			$url = add_query_arg( 'id', $id, $url );
		}
		if ( '' !== $message ) {
			$url = add_query_arg( 'ufsc_message', rawurlencode( sanitize_text_field( (string) $message ) ), $url );
		}

		wp_safe_redirect( $url );
		exit;
	}

	private function render_notice( $notice ) {
		$custom_message = isset( $_GET['ufsc_message'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['ufsc_message'] ) ) ) : '';
		$messages = array(
			'created'       => __( 'Combat créé.', 'ufsc-licence-competition' ),
			'updated'       => __( 'Combat mis à jour.', 'ufsc-licence-competition' ),
			'trashed'       => __( 'Combat déplacé dans la corbeille.', 'ufsc-licence-competition' ),
			'restored'      => __( 'Combat restauré.', 'ufsc-licence-competition' ),
			'deleted'       => __( 'Combat supprimé définitivement.', 'ufsc-licence-competition' ),
			'error_required'=> __( 'Veuillez renseigner la compétition et le numéro de combat.', 'ufsc-licence-competition' ),
				'not_found'     => __( 'Combat introuvable.', 'ufsc-licence-competition' ),
				'protected_delete' => __( 'Action bloquée : ce combat est protégé (en cours, terminé ou contient un résultat).', 'ufsc-licence-competition' ),
				'protected_edit' => __( 'Modification bloquée : utilisez le workflow de correction supervisée pour un combat sensible.', 'ufsc-licence-competition' ),
				'bulk_partial' => __( 'Traitement partiel : certains combats ont été protégés.', 'ufsc-licence-competition' ),
				'correction_done' => __( 'Correction de résultat enregistrée et auditée.', 'ufsc-licence-competition' ),
				'correction_invalid' => __( 'Correction invalide : vainqueur et motif obligatoires.', 'ufsc-licence-competition' ),
				'correction_supervisor_required' => __( 'Validation superviseur obligatoire : des combats suivants sont déjà joués.', 'ufsc-licence-competition' ),
				'status_invalid' => __( 'Statut invalide.', 'ufsc-licence-competition' ),
				'status_transition_blocked' => __( 'Transition de statut refusée.', 'ufsc-licence-competition' ),
				);

		if ( ! $notice || ! isset( $messages[ $notice ] ) ) {
			return;
		}

		$type = in_array( $notice, array( 'error_required', 'not_found', 'correction_invalid', 'correction_supervisor_required', 'protected_delete', 'protected_edit', 'bulk_partial', 'status_invalid', 'status_transition_blocked' ), true ) ? 'error' : 'success';
		$text = '' !== $custom_message ? $custom_message : $messages[ $notice ];
		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( $text ) );
	}

	private function fight_has_result_payload( $fight ): bool {
		$winner_entry_id = absint( is_array( $fight ) ? ( $fight['winner_entry_id'] ?? 0 ) : ( $fight->winner_entry_id ?? 0 ) );
		if ( $winner_entry_id > 0 ) {
			return true;
		}

		$result_method = trim( (string) ( is_array( $fight ) ? ( $fight['result_method'] ?? '' ) : ( $fight->result_method ?? '' ) ) );
		if ( '' !== $result_method ) {
			return true;
		}

		$score_red  = trim( (string) ( is_array( $fight ) ? ( $fight['score_red'] ?? '' ) : ( $fight->score_red ?? '' ) ) );
		$score_blue = trim( (string) ( is_array( $fight ) ? ( $fight['score_blue'] ?? '' ) : ( $fight->score_blue ?? '' ) ) );
		return '' !== $score_red || '' !== $score_blue;
	}

	private function maybe_handle_bulk_actions( Fights_Table $list_table, $page_slug ) {
		$action = $list_table->current_action();
		if ( ! $action ) {
			return;
		}

		if ( ! Capabilities::user_can_manage() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'bulk-' . $list_table->_args['plural'] );

		$ids = isset( $_POST['ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['ids'] ) ) : array();
		$ids = array_filter( $ids );
		if ( ! $ids ) {
			return;
		}

		$processed = 0;
		$blocked   = 0;
		foreach ( $ids as $id ) {
			$fight = $this->repository->get( $id, true );
			if ( ! $fight ) {
				$blocked++;
				continue;
			}
			switch ( $action ) {
				case 'trash':
					if ( ! $this->repository->can_delete_fight( $fight ) ) {
						$blocked++;
						$this->logger->log( 'fight_bulk_trash_blocked_sensitive', 'fight', $id, 'Bulk corbeille bloquée.', array( 'status' => (string) ( $fight->status ?? '' ) ) );
						break;
					}
						$this->repository->soft_delete( $id );
						$processed++;
						break;
					case 'restore':
						$this->repository->restore( $id );
						$processed++;
						break;
					case 'delete':
						if ( ! Capabilities::user_can_delete() ) {
							wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
						}
						if ( ! $this->repository->can_delete_fight( $fight ) ) {
							$blocked++;
							$this->logger->log( 'fight_bulk_delete_blocked_sensitive', 'fight', $id, 'Bulk suppression bloquée.', array( 'status' => (string) ( $fight->status ?? '' ) ) );
							break;
						}
						$this->repository->delete( $id );
						$processed++;
						break;
				}
			}

		$notice_map = array(
			'trash'   => 'trashed',
			'restore' => 'restored',
			'delete'  => 'deleted',
		);
		$notice = $notice_map[ $action ] ?? 'updated';
		$message = '';
		if ( $blocked > 0 && in_array( $action, array( 'trash', 'delete' ), true ) ) {
			$notice  = 'bulk_partial';
			$message = sprintf(
				/* translators: 1: processed count, 2: blocked count */
				__( '%1$d combat(s) traité(s). %2$d combat(s) protégé(s) car en cours, terminés ou avec résultat.', 'ufsc-licence-competition' ),
				(int) $processed,
				(int) $blocked
			);
		}

		$this->redirect_with_notice( $page_slug, $notice, 0, $message );
	}
}
