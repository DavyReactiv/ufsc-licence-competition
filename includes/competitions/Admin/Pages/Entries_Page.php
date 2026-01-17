<?php

namespace UFSC\Competitions\Admin\Pages;

use UFSC\Competitions\Capabilities;
use UFSC\Competitions\Repositories\EntryRepository;
use UFSC\Competitions\Repositories\CompetitionRepository;
use UFSC\Competitions\Repositories\CategoryRepository;
use UFSC\Competitions\Admin\Tables\Entries_Table;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Entries_Page {
	private $repository;
	private $competition_repository;
	private $category_repository;

	public function __construct() {
		$this->repository = new EntryRepository();
		$this->competition_repository = new CompetitionRepository();
		$this->category_repository = new CategoryRepository();
	}

	public function register_actions() {
		add_action( 'admin_post_ufsc_competitions_save_entry', array( $this, 'handle_save' ) );
		add_action( 'admin_post_ufsc_competitions_trash_entry', array( $this, 'handle_trash' ) );
		add_action( 'admin_post_ufsc_competitions_restore_entry', array( $this, 'handle_restore' ) );
		add_action( 'admin_post_ufsc_competitions_delete_entry', array( $this, 'handle_delete' ) );
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

		$list_table = new Entries_Table();
		$list_table->prepare_items();

		?>
		<div class="wrap ufsc-competitions-admin">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Inscriptions', 'ufsc-licence-competition' ); ?></h1>
			<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'ufsc-competition-registrations', 'ufsc_action' => 'add' ), admin_url( 'admin.php' ) ) ); ?>" class="page-title-action"><?php esc_html_e( 'Ajouter', 'ufsc-licence-competition' ); ?></a>
			<hr class="wp-header-end">
			<?php $list_table->views(); ?>
			<form method="get">
				<input type="hidden" name="page" value="ufsc-competition-registrations" />
				<?php $list_table->search_box( __( 'Rechercher', 'ufsc-licence-competition' ), 'ufsc-competition-entries-search' ); ?>
				<?php $list_table->display(); ?>
			</form>
		</div>
		<?php
	}

	public function handle_save() {
		if ( ! Capabilities::user_can_manage() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'ufsc_competitions_save_entry' );

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$data = array(
			'competition_id' => isset( $_POST['competition_id'] ) ? absint( $_POST['competition_id'] ) : 0,
			'category_id'    => isset( $_POST['category_id'] ) ? absint( $_POST['category_id'] ) : 0,
			'club_id'        => isset( $_POST['club_id'] ) ? absint( $_POST['club_id'] ) : 0,
			'licensee_id'    => isset( $_POST['licensee_id'] ) ? absint( $_POST['licensee_id'] ) : 0,
			'status'         => isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'draft',
		);

		if ( ! $data['competition_id'] || ! $data['licensee_id'] ) {
			$this->redirect_with_notice( 'ufsc-competition-registrations', 'error_required', $id );
		}

		if ( ! $id && $this->repository->get_by_competition_licensee( $data['competition_id'], $data['licensee_id'] ) ) {
			$this->redirect_with_notice( 'ufsc-competition-registrations', 'duplicate', $id );
		}

		if ( $id ) {
			$this->repository->update( $id, $data );
			$this->redirect_with_notice( 'ufsc-competition-registrations', 'updated', $id );
		}

		$new_id = $this->repository->insert( $data );
		$this->redirect_with_notice( 'ufsc-competition-registrations', 'created', $new_id );
	}

	public function handle_trash() {
		$this->handle_simple_action( 'ufsc_competitions_trash_entry', 'trash', 'ufsc-competition-registrations' );
	}

	public function handle_restore() {
		$this->handle_simple_action( 'ufsc_competitions_restore_entry', 'restore', 'ufsc-competition-registrations' );
	}

	public function handle_delete() {
		$this->handle_simple_action( 'ufsc_competitions_delete_entry', 'delete', 'ufsc-competition-registrations' );
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
			case 'restore':
				$this->repository->restore( $id );
				$this->redirect_with_notice( $page_slug, 'restored' );
			case 'delete':
				$this->repository->delete( $id );
				$this->redirect_with_notice( $page_slug, 'deleted' );
		}
	}

	private function render_form( $item ) {
		$values = array(
			'id'             => $item->id ?? 0,
			'competition_id' => $item->competition_id ?? 0,
			'category_id'    => $item->category_id ?? 0,
			'club_id'        => $item->club_id ?? 0,
			'licensee_id'    => $item->licensee_id ?? 0,
			'status'         => $item->status ?? 'draft',
		);

		$competitions = $this->competition_repository->list( array( 'view' => 'all' ), 200, 0 );
		$categories = $this->category_repository->list( array( 'view' => 'all' ), 500, 0 );
		$action_label = $values['id'] ? __( 'Mettre à jour', 'ufsc-licence-competition' ) : __( 'Créer l\'inscription', 'ufsc-licence-competition' );
		?>
		<div class="wrap ufsc-competitions-admin">
			<h1><?php echo esc_html( $values['id'] ? __( 'Modifier l\'inscription', 'ufsc-licence-competition' ) : __( 'Nouvelle inscription', 'ufsc-licence-competition' ) ); ?></h1>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ufsc-competitions-form">
				<?php wp_nonce_field( 'ufsc_competitions_save_entry' ); ?>
				<input type="hidden" name="action" value="ufsc_competitions_save_entry">
				<input type="hidden" name="id" value="<?php echo esc_attr( $values['id'] ); ?>">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ufsc_entry_competition"><?php esc_html_e( 'Compétition', 'ufsc-licence-competition' ); ?></label></th>
						<td>
							<select name="competition_id" id="ufsc_entry_competition" class="regular-text" required>
								<option value="0"><?php esc_html_e( 'Sélectionner', 'ufsc-licence-competition' ); ?></option>
								<?php foreach ( $competitions as $competition ) : ?>
									<option value="<?php echo esc_attr( $competition->id ); ?>" <?php selected( $values['competition_id'], $competition->id ); ?>><?php echo esc_html( $competition->name ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_entry_category"><?php esc_html_e( 'Catégorie', 'ufsc-licence-competition' ); ?></label></th>
						<td>
							<select name="category_id" id="ufsc_entry_category" class="regular-text">
								<option value="0"><?php esc_html_e( 'Auto / non assignée', 'ufsc-licence-competition' ); ?></option>
								<?php foreach ( $categories as $category ) : ?>
									<option value="<?php echo esc_attr( $category->id ); ?>" <?php selected( $values['category_id'], $category->id ); ?>><?php echo esc_html( $category->name ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_entry_licensee"><?php esc_html_e( 'ID licencié', 'ufsc-licence-competition' ); ?></label></th>
						<td><input name="licensee_id" type="number" id="ufsc_entry_licensee" value="<?php echo esc_attr( $values['licensee_id'] ); ?>" required></td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_entry_club"><?php esc_html_e( 'ID club', 'ufsc-licence-competition' ); ?></label></th>
						<td><input name="club_id" type="number" id="ufsc_entry_club" value="<?php echo esc_attr( $values['club_id'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_entry_status"><?php esc_html_e( 'Statut', 'ufsc-licence-competition' ); ?></label></th>
						<td>
							<select name="status" id="ufsc_entry_status" class="regular-text">
								<option value="draft" <?php selected( $values['status'], 'draft' ); ?>><?php esc_html_e( 'Brouillon', 'ufsc-licence-competition' ); ?></option>
								<option value="submitted" <?php selected( $values['status'], 'submitted' ); ?>><?php esc_html_e( 'Soumise', 'ufsc-licence-competition' ); ?></option>
								<option value="validated" <?php selected( $values['status'], 'validated' ); ?>><?php esc_html_e( 'Validée', 'ufsc-licence-competition' ); ?></option>
								<option value="rejected" <?php selected( $values['status'], 'rejected' ); ?>><?php esc_html_e( 'Rejetée', 'ufsc-licence-competition' ); ?></option>
								<option value="withdrawn" <?php selected( $values['status'], 'withdrawn' ); ?>><?php esc_html_e( 'Retirée', 'ufsc-licence-competition' ); ?></option>
							</select>
						</td>
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
			'created'       => __( 'Inscription créée.', 'ufsc-licence-competition' ),
			'updated'       => __( 'Inscription mise à jour.', 'ufsc-licence-competition' ),
			'trashed'       => __( 'Inscription déplacée dans la corbeille.', 'ufsc-licence-competition' ),
			'restored'      => __( 'Inscription restaurée.', 'ufsc-licence-competition' ),
			'deleted'       => __( 'Inscription supprimée définitivement.', 'ufsc-licence-competition' ),
			'error_required'=> __( 'Veuillez renseigner la compétition et le licencié.', 'ufsc-licence-competition' ),
			'duplicate'     => __( 'Ce licencié est déjà inscrit à cette compétition.', 'ufsc-licence-competition' ),
			'not_found'     => __( 'Inscription introuvable.', 'ufsc-licence-competition' ),
		);

		if ( ! $notice || ! isset( $messages[ $notice ] ) ) {
			return;
		}

		$type = in_array( $notice, array( 'error_required', 'not_found', 'duplicate' ), true ) ? 'error' : 'success';
		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( $messages[ $notice ] ) );
	}
}
