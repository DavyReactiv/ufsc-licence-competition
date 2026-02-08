<?php

namespace UFSC\Competitions\Admin\Pages;

use UFSC\Competitions\Capabilities;
use UFSC\Competitions\Admin\Menu;
use UFSC\Competitions\Repositories\FightRepository;
use UFSC\Competitions\Repositories\CompetitionRepository;
use UFSC\Competitions\Repositories\CategoryRepository;
use UFSC\Competitions\Admin\Tables\Fights_Table;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Bouts_Page {
	private $repository;
	private $competitions;
	private $categories;

	public function __construct() {
		$this->repository = new FightRepository();
		$this->competitions = new CompetitionRepository();
		$this->categories = new CategoryRepository();
	}

	public function register_actions() {
		add_action( 'admin_post_ufsc_competitions_save_fight', array( $this, 'handle_save' ) );
		add_action( 'admin_post_ufsc_competitions_trash_fight', array( $this, 'handle_trash' ) );
		add_action( 'admin_post_ufsc_competitions_restore_fight', array( $this, 'handle_restore' ) );
		add_action( 'admin_post_ufsc_competitions_delete_fight', array( $this, 'handle_delete' ) );
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
		$competition_id = isset( $_GET['ufsc_competition_id'] ) ? absint( $_GET['ufsc_competition_id'] ) : 0;

		$this->render_notice( $notice );
		if ( $fight_notice && class_exists( '\UFSC\Competitions\Admin\Pages\Bouts_AutoGeneration' ) ) {
			\UFSC\Competitions\Admin\Pages\Bouts_AutoGeneration::render_notice( $fight_notice, $fight_message );
		}

		if ( in_array( $action, array( 'add', 'edit' ), true ) ) {
			$item = null;
			if ( 'edit' === $action && $id ) {
				$item = $this->repository->get( $id, true );
			}
			$this->render_form( $item );
			return;
		}

		$list_table = new Fights_Table();
		$this->maybe_handle_bulk_actions( $list_table, Menu::PAGE_BOUTS );
		$list_table->prepare_items();

		?>
		<div class="wrap ufsc-competitions-admin">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Combats', 'ufsc-licence-competition' ); ?></h1>
			<a href="<?php echo esc_url( add_query_arg( array( 'page' => Menu::PAGE_BOUTS, 'ufsc_action' => 'add' ), admin_url( 'admin.php' ) ) ); ?>" class="page-title-action"><?php esc_html_e( 'Ajouter', 'ufsc-licence-competition' ); ?></a>
			<hr class="wp-header-end">
			<div class="notice notice-info ufsc-competitions-helper"><p><?php esc_html_e( 'Planifier les combats, assigner les combattants, suivre les résultats.', 'ufsc-licence-competition' ); ?></p></div>
			<?php
			if ( class_exists( '\UFSC\Competitions\Admin\Pages\Bouts_AutoGeneration' ) ) {
				\UFSC\Competitions\Admin\Pages\Bouts_AutoGeneration::render_panel( $competition_id );
			}
			?>
			<?php $list_table->views(); ?>
			<form method="post">
				<input type="hidden" name="page" value="<?php echo esc_attr( Menu::PAGE_BOUTS ); ?>" />
				<?php $list_table->search_box( __( 'Rechercher', 'ufsc-licence-competition' ), 'ufsc-competitions-fights-search' ); ?>
				<div class="ufsc-competitions-table-wrap">
					<?php $list_table->display(); ?>
				</div>
			</form>
		</div>
		<?php
	}

	public function handle_save() {
		if ( ! Capabilities::user_can_manage() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'ufsc_competitions_save_fight' );

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$data = array(
			'competition_id'  => isset( $_POST['competition_id'] ) ? absint( $_POST['competition_id'] ) : 0,
			'category_id'     => isset( $_POST['category_id'] ) ? absint( $_POST['category_id'] ) : 0,
			'fight_no'        => isset( $_POST['fight_no'] ) ? absint( $_POST['fight_no'] ) : 0,
			'ring'            => isset( $_POST['ring'] ) ? sanitize_text_field( wp_unslash( $_POST['ring'] ) ) : '',
			'round_no'        => isset( $_POST['round_no'] ) ? absint( $_POST['round_no'] ) : 0,
			'red_entry_id'    => isset( $_POST['red_entry_id'] ) ? absint( $_POST['red_entry_id'] ) : 0,
			'blue_entry_id'   => isset( $_POST['blue_entry_id'] ) ? absint( $_POST['blue_entry_id'] ) : 0,
			'winner_entry_id' => isset( $_POST['winner_entry_id'] ) ? absint( $_POST['winner_entry_id'] ) : 0,
			'status'          => isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'scheduled',
			'result_method'   => isset( $_POST['result_method'] ) ? sanitize_text_field( wp_unslash( $_POST['result_method'] ) ) : '',
			'score_red'       => isset( $_POST['score_red'] ) ? sanitize_text_field( wp_unslash( $_POST['score_red'] ) ) : '',
			'score_blue'      => isset( $_POST['score_blue'] ) ? sanitize_text_field( wp_unslash( $_POST['score_blue'] ) ) : '',
			'scheduled_at'    => isset( $_POST['scheduled_at'] ) ? sanitize_text_field( wp_unslash( $_POST['scheduled_at'] ) ) : '',
		);

		if ( ! $data['competition_id'] || ! $data['fight_no'] ) {
			$this->redirect_with_notice( Menu::PAGE_BOUTS, 'error_required', $id );
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

		switch ( $method ) {
			case 'trash':
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

		$competition_filters = array( 'view' => 'all' );
		if ( function_exists( 'ufsc_lc_competitions_apply_scope_to_query_args' ) ) {
			$competition_filters = ufsc_lc_competitions_apply_scope_to_query_args( $competition_filters );
		}
		$competitions = $this->competitions->list( $competition_filters, 200, 0 );
		$categories = $this->categories->list( array( 'view' => 'all' ), 500, 0 );
		$action_label = $values['id'] ? __( 'Mettre à jour', 'ufsc-licence-competition' ) : __( 'Créer le combat', 'ufsc-licence-competition' );
		?>
		<div class="wrap ufsc-competitions-admin">
			<h1><?php echo esc_html( $values['id'] ? __( 'Modifier le combat', 'ufsc-licence-competition' ) : __( 'Nouveau combat', 'ufsc-licence-competition' ) ); ?></h1>
			<div class="notice notice-info ufsc-competitions-helper"><p><?php esc_html_e( 'Planifier les combats, assigner les combattants, suivre les résultats.', 'ufsc-licence-competition' ); ?></p></div>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ufsc-competitions-form">
				<?php wp_nonce_field( 'ufsc_competitions_save_fight' ); ?>
				<input type="hidden" name="action" value="ufsc_competitions_save_fight">
				<input type="hidden" name="id" value="<?php echo esc_attr( $values['id'] ); ?>">
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
				<?php submit_button( $action_label ); ?>
			</form>
		</div>
		<?php
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

	private function redirect_with_notice( $page, $notice, $id = 0 ) {
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

		wp_safe_redirect( $url );
		exit;
	}

	private function render_notice( $notice ) {
		$messages = array(
			'created'       => __( 'Combat créé.', 'ufsc-licence-competition' ),
			'updated'       => __( 'Combat mis à jour.', 'ufsc-licence-competition' ),
			'trashed'       => __( 'Combat déplacé dans la corbeille.', 'ufsc-licence-competition' ),
			'restored'      => __( 'Combat restauré.', 'ufsc-licence-competition' ),
			'deleted'       => __( 'Combat supprimé définitivement.', 'ufsc-licence-competition' ),
			'error_required'=> __( 'Veuillez renseigner la compétition et le numéro de combat.', 'ufsc-licence-competition' ),
			'not_found'     => __( 'Combat introuvable.', 'ufsc-licence-competition' ),
		);

		if ( ! $notice || ! isset( $messages[ $notice ] ) ) {
			return;
		}

		$type = in_array( $notice, array( 'error_required', 'not_found' ), true ) ? 'error' : 'success';
		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( $messages[ $notice ] ) );
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

		foreach ( $ids as $id ) {
			switch ( $action ) {
				case 'trash':
					$this->repository->soft_delete( $id );
					break;
				case 'restore':
					$this->repository->restore( $id );
					break;
				case 'delete':
					if ( ! Capabilities::user_can_delete() ) {
						wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
					}
					$this->repository->delete( $id );
					break;
			}
		}

		$notice_map = array(
			'trash'   => 'trashed',
			'restore' => 'restored',
			'delete'  => 'deleted',
		);

		$this->redirect_with_notice( $page_slug, $notice_map[ $action ] ?? 'updated' );
	}
}
