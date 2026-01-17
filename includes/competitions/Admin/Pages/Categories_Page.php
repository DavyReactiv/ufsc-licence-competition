<?php

namespace UFSC\Competitions\Admin\Pages;

use UFSC\Competitions\Capabilities;
use UFSC\Competitions\Admin\Menu;
use UFSC\Competitions\Repositories\CategoryRepository;
use UFSC\Competitions\Repositories\CompetitionRepository;
use UFSC\Competitions\Admin\Tables\Categories_Table;
use UFSC\Competitions\Services\DisciplineRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Categories_Page {
	private $repository;
	private $competition_repository;

	public function __construct() {
		$this->repository = new CategoryRepository();
		$this->competition_repository = new CompetitionRepository();
	}

	public function register_actions() {
		add_action( 'admin_post_ufsc_competitions_save_category', array( $this, 'handle_save' ) );
		add_action( 'admin_post_ufsc_competitions_trash_category', array( $this, 'handle_trash' ) );
		add_action( 'admin_post_ufsc_competitions_restore_category', array( $this, 'handle_restore' ) );
		add_action( 'admin_post_ufsc_competitions_delete_category', array( $this, 'handle_delete' ) );
	}

	public function render() {
		if ( ! Capabilities::user_can_manage() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ) );
		}

		$action = isset( $_GET['ufsc_action'] ) ? sanitize_key( wp_unslash( $_GET['ufsc_action'] ) ) : '';
		$notice = isset( $_GET['ufsc_notice'] ) ? sanitize_key( wp_unslash( $_GET['ufsc_notice'] ) ) : '';
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

		$this->render_notice( $notice );

		if ( in_array( $action, array( 'add', 'edit' ), true ) ) {
			$item = null;
			if ( 'edit' === $action && $id ) {
				$item = $this->repository->get( $id, true );
			}
			$this->render_form( $item );
			return;
		}

		$list_table = new Categories_Table();
		$this->maybe_handle_bulk_actions( $list_table, Menu::PAGE_CATEGORIES );
		$list_table->prepare_items();

		?>
		<div class="wrap ufsc-competitions-admin">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Catégories & formats', 'ufsc-licence-competition' ); ?></h1>
			<a href="<?php echo esc_url( add_query_arg( array( 'page' => Menu::PAGE_CATEGORIES, 'ufsc_action' => 'add' ), admin_url( 'admin.php' ) ) ); ?>" class="page-title-action"><?php esc_html_e( 'Ajouter', 'ufsc-licence-competition' ); ?></a>
			<hr class="wp-header-end">
			<?php $this->render_helper_notice( __( 'Définir âge/poids/sexe/niveau + format poules/élimination.', 'ufsc-licence-competition' ) ); ?>
			<?php $list_table->views(); ?>
			<form method="post">
				<input type="hidden" name="page" value="<?php echo esc_attr( Menu::PAGE_CATEGORIES ); ?>" />
				<?php $list_table->search_box( __( 'Rechercher', 'ufsc-licence-competition' ), 'ufsc-competitions-categories-search' ); ?>
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

		check_admin_referer( 'ufsc_competitions_save_category' );

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$data = array(
			'competition_id' => isset( $_POST['competition_id'] ) ? absint( $_POST['competition_id'] ) : 0,
			'discipline'     => isset( $_POST['discipline'] ) ? sanitize_text_field( wp_unslash( $_POST['discipline'] ) ) : '',
			'name'           => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
			'age_min'        => isset( $_POST['age_min'] ) ? sanitize_text_field( wp_unslash( $_POST['age_min'] ) ) : '',
			'age_max'        => isset( $_POST['age_max'] ) ? sanitize_text_field( wp_unslash( $_POST['age_max'] ) ) : '',
			'weight_min'     => isset( $_POST['weight_min'] ) ? sanitize_text_field( wp_unslash( $_POST['weight_min'] ) ) : '',
			'weight_max'     => isset( $_POST['weight_max'] ) ? sanitize_text_field( wp_unslash( $_POST['weight_max'] ) ) : '',
			'sex'            => isset( $_POST['sex'] ) ? sanitize_text_field( wp_unslash( $_POST['sex'] ) ) : '',
			'level'          => isset( $_POST['level'] ) ? sanitize_text_field( wp_unslash( $_POST['level'] ) ) : '',
			'format'         => isset( $_POST['format'] ) ? sanitize_text_field( wp_unslash( $_POST['format'] ) ) : '',
		);

		$data['discipline'] = DisciplineRegistry::normalize( $data['discipline'] );

		if ( '' === $data['name'] || '' === $data['discipline'] ) {
			$this->redirect_with_notice( Menu::PAGE_CATEGORIES, 'error_required', $id );
		}

		if ( $id ) {
			$this->repository->update( $id, $data );
			$this->redirect_with_notice( Menu::PAGE_CATEGORIES, 'updated', $id );
		}

		$new_id = $this->repository->insert( $data );
		$this->redirect_with_notice( Menu::PAGE_CATEGORIES, 'created', $new_id );
	}

	public function handle_trash() {
		$this->handle_simple_action( 'ufsc_competitions_trash_category', 'trash', Menu::PAGE_CATEGORIES );
	}

	public function handle_restore() {
		$this->handle_simple_action( 'ufsc_competitions_restore_category', 'restore', Menu::PAGE_CATEGORIES );
	}

	public function handle_delete() {
		$this->handle_simple_action( 'ufsc_competitions_delete_category', 'delete', Menu::PAGE_CATEGORIES );
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
			'id'             => $item->id ?? 0,
			'competition_id' => $item->competition_id ?? 0,
			'discipline'     => $item->discipline ?? '',
			'name'           => $item->name ?? '',
			'age_min'        => $item->age_min ?? '',
			'age_max'        => $item->age_max ?? '',
			'weight_min'     => $item->weight_min ?? '',
			'weight_max'     => $item->weight_max ?? '',
			'sex'            => $item->sex ?? '',
			'level'          => $item->level ?? '',
			'format'         => $item->format ?? '',
		);

		$competitions = $this->competition_repository->list( array( 'view' => 'all' ), 200, 0 );
		$disciplines  = DisciplineRegistry::get_disciplines();
		$action_label = $values['id'] ? __( 'Mettre à jour', 'ufsc-licence-competition' ) : __( 'Créer la catégorie', 'ufsc-licence-competition' );
		?>
		<div class="wrap ufsc-competitions-admin">
			<h1><?php echo esc_html( $values['id'] ? __( 'Modifier la catégorie', 'ufsc-licence-competition' ) : __( 'Nouvelle catégorie', 'ufsc-licence-competition' ) ); ?></h1>
			<?php $this->render_helper_notice( __( 'Définir âge/poids/sexe/niveau + format poules/élimination.', 'ufsc-licence-competition' ) ); ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ufsc-competitions-form">
				<?php wp_nonce_field( 'ufsc_competitions_save_category' ); ?>
				<input type="hidden" name="action" value="ufsc_competitions_save_category">
				<input type="hidden" name="id" value="<?php echo esc_attr( $values['id'] ); ?>">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ufsc_category_name"><?php esc_html_e( 'Nom', 'ufsc-licence-competition' ); ?></label></th>
						<td><input name="name" type="text" id="ufsc_category_name" class="regular-text" value="<?php echo esc_attr( $values['name'] ); ?>" required></td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_category_discipline"><?php esc_html_e( 'Discipline', 'ufsc-licence-competition' ); ?></label></th>
						<td>
							<select name="discipline" id="ufsc_category_discipline" class="regular-text" required>
								<option value=""><?php esc_html_e( 'Sélectionner', 'ufsc-licence-competition' ); ?></option>
								<?php foreach ( $disciplines as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $values['discipline'], $value ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_category_competition"><?php esc_html_e( 'Compétition (optionnel)', 'ufsc-licence-competition' ); ?></label></th>
						<td>
							<select name="competition_id" id="ufsc_category_competition" class="regular-text">
								<option value="0"><?php esc_html_e( 'Toutes', 'ufsc-licence-competition' ); ?></option>
								<?php foreach ( $competitions as $competition ) : ?>
									<option value="<?php echo esc_attr( $competition->id ); ?>" <?php selected( $values['competition_id'], $competition->id ); ?>><?php echo esc_html( $competition->name ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_category_age_min"><?php esc_html_e( 'Âge min', 'ufsc-licence-competition' ); ?></label></th>
						<td><input name="age_min" type="number" id="ufsc_category_age_min" value="<?php echo esc_attr( $values['age_min'] ); ?>" min="0"></td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_category_age_max"><?php esc_html_e( 'Âge max', 'ufsc-licence-competition' ); ?></label></th>
						<td><input name="age_max" type="number" id="ufsc_category_age_max" value="<?php echo esc_attr( $values['age_max'] ); ?>" min="0"></td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_category_weight_min"><?php esc_html_e( 'Poids min (kg)', 'ufsc-licence-competition' ); ?></label></th>
						<td><input name="weight_min" type="number" step="0.1" id="ufsc_category_weight_min" value="<?php echo esc_attr( $values['weight_min'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_category_weight_max"><?php esc_html_e( 'Poids max (kg)', 'ufsc-licence-competition' ); ?></label></th>
						<td><input name="weight_max" type="number" step="0.1" id="ufsc_category_weight_max" value="<?php echo esc_attr( $values['weight_max'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_category_sex"><?php esc_html_e( 'Sexe', 'ufsc-licence-competition' ); ?></label></th>
						<td><input name="sex" type="text" id="ufsc_category_sex" class="regular-text" value="<?php echo esc_attr( $values['sex'] ); ?>" placeholder="M/F/X"></td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_category_level"><?php esc_html_e( 'Niveau', 'ufsc-licence-competition' ); ?></label></th>
						<td><input name="level" type="text" id="ufsc_category_level" class="regular-text" value="<?php echo esc_attr( $values['level'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_category_format"><?php esc_html_e( 'Format', 'ufsc-licence-competition' ); ?></label></th>
						<td><input name="format" type="text" id="ufsc_category_format" class="regular-text" value="<?php echo esc_attr( $values['format'] ); ?>" placeholder="poule / élimination"></td>
					</tr>
				</table>
				<?php submit_button( $action_label ); ?>
			</form>
		</div>
		<?php
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
			'created'       => __( 'Catégorie créée.', 'ufsc-licence-competition' ),
			'updated'       => __( 'Catégorie mise à jour.', 'ufsc-licence-competition' ),
			'trashed'       => __( 'Catégorie déplacée dans la corbeille.', 'ufsc-licence-competition' ),
			'restored'      => __( 'Catégorie restaurée.', 'ufsc-licence-competition' ),
			'deleted'       => __( 'Catégorie supprimée définitivement.', 'ufsc-licence-competition' ),
			'error_required'=> __( 'Veuillez renseigner le nom et la discipline de la catégorie.', 'ufsc-licence-competition' ),
			'not_found'     => __( 'Catégorie introuvable.', 'ufsc-licence-competition' ),
		);

		if ( ! $notice || ! isset( $messages[ $notice ] ) ) {
			return;
		}

		$type = 'error_required' === $notice || 'not_found' === $notice ? 'error' : 'success';
		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( $messages[ $notice ] ) );
	}

	private function render_helper_notice( $message ) {
		printf(
			'<div class="notice notice-info ufsc-competitions-helper"><p>%s</p></div>',
			esc_html( $message )
		);
	}

	private function maybe_handle_bulk_actions( Categories_Table $list_table, $page_slug ) {
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
