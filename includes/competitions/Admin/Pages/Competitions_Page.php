<?php

namespace UFSC\Competitions\Admin\Pages;

use UFSC\Competitions\Capabilities;
use UFSC\Competitions\Repositories\CompetitionRepository;
use UFSC\Competitions\Services\DisciplineRegistry;
use UFSC\Competitions\Admin\Tables\Competitions_Table;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Competitions_Page {
	private $repository;

	public function __construct() {
		$this->repository = new CompetitionRepository();
	}

	public function register_actions() {
		add_action( 'admin_post_ufsc_competitions_save_competition', array( $this, 'handle_save' ) );
		add_action( 'admin_post_ufsc_competitions_trash_competition', array( $this, 'handle_trash' ) );
		add_action( 'admin_post_ufsc_competitions_restore_competition', array( $this, 'handle_restore' ) );
		add_action( 'admin_post_ufsc_competitions_delete_competition', array( $this, 'handle_delete' ) );
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

		$list_table = new Competitions_Table();
		$list_table->prepare_items();

		?>
		<div class="wrap ufsc-competitions-admin">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Compétitions', 'ufsc-licence-competition' ); ?></h1>
			<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'ufsc-competitions', 'ufsc_action' => 'add' ), admin_url( 'admin.php' ) ) ); ?>" class="page-title-action"><?php esc_html_e( 'Ajouter', 'ufsc-licence-competition' ); ?></a>
			<hr class="wp-header-end">
			<?php $list_table->views(); ?>
			<form method="get">
				<input type="hidden" name="page" value="ufsc-competitions" />
				<?php $list_table->search_box( __( 'Rechercher', 'ufsc-licence-competition' ), 'ufsc-competitions-search' ); ?>
				<?php $list_table->display(); ?>
			</form>
		</div>
		<?php
	}

	public function handle_save() {
		if ( ! Capabilities::user_can_manage() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'ufsc_competitions_save_competition' );

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$data = array(
			'name'                  => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
			'discipline'            => isset( $_POST['discipline'] ) ? sanitize_text_field( wp_unslash( $_POST['discipline'] ) ) : '',
			'type'                  => isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '',
			'season'                => isset( $_POST['season'] ) ? sanitize_text_field( wp_unslash( $_POST['season'] ) ) : '',
			'location'              => isset( $_POST['location'] ) ? sanitize_text_field( wp_unslash( $_POST['location'] ) ) : '',
			'start_date'            => isset( $_POST['start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['start_date'] ) ) : '',
			'end_date'              => isset( $_POST['end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['end_date'] ) ) : '',
			'registration_deadline' => isset( $_POST['registration_deadline'] ) ? sanitize_text_field( wp_unslash( $_POST['registration_deadline'] ) ) : '',
			'status'                => isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'draft',
			'age_reference'         => isset( $_POST['age_reference'] ) ? sanitize_text_field( wp_unslash( $_POST['age_reference'] ) ) : '12-31',
			'weight_tolerance'      => isset( $_POST['weight_tolerance'] ) ? sanitize_text_field( wp_unslash( $_POST['weight_tolerance'] ) ) : '1',
			'allowed_formats'       => isset( $_POST['allowed_formats'] ) ? sanitize_text_field( wp_unslash( $_POST['allowed_formats'] ) ) : '',
		);

		$data['discipline'] = DisciplineRegistry::normalize( $data['discipline'] );

		if ( '' === $data['name'] || '' === $data['discipline'] || '' === $data['type'] || '' === $data['season'] ) {
			$this->redirect_with_notice( 'ufsc-competitions', 'error_required', $id, $data );
		}

		if ( $id ) {
			$this->repository->update( $id, $data );
			$this->redirect_with_notice( 'ufsc-competitions', 'updated', $id );
		}

		$new_id = $this->repository->insert( $data );
		$this->redirect_with_notice( 'ufsc-competitions', 'created', $new_id );
	}

	public function handle_trash() {
		$this->handle_simple_action( 'ufsc_competitions_trash_competition', 'trash', 'ufsc-competitions' );
	}

	public function handle_restore() {
		$this->handle_simple_action( 'ufsc_competitions_restore_competition', 'restore', 'ufsc-competitions' );
	}

	public function handle_delete() {
		$this->handle_simple_action( 'ufsc_competitions_delete_competition', 'delete', 'ufsc-competitions' );
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
			'id'                    => $item->id ?? 0,
			'name'                  => $item->name ?? '',
			'discipline'            => $item->discipline ?? '',
			'type'                  => $item->type ?? '',
			'season'                => $item->season ?? '',
			'location'              => $item->location ?? '',
			'start_date'            => $item->start_date ?? '',
			'end_date'              => $item->end_date ?? '',
			'registration_deadline' => $item->registration_deadline ?? '',
			'status'                => $item->status ?? 'draft',
			'age_reference'         => $item->age_reference ?? '12-31',
			'weight_tolerance'      => $item->weight_tolerance ?? 1,
			'allowed_formats'       => $item->allowed_formats ?? '',
		);

		$action_label = $values['id'] ? __( 'Mettre à jour', 'ufsc-licence-competition' ) : __( 'Créer la compétition', 'ufsc-licence-competition' );
		$disciplines = DisciplineRegistry::get_disciplines();
		?>
		<div class="wrap ufsc-competitions-admin">
			<h1><?php echo esc_html( $values['id'] ? __( 'Modifier la compétition', 'ufsc-licence-competition' ) : __( 'Nouvelle compétition', 'ufsc-licence-competition' ) ); ?></h1>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ufsc-competitions-form">
				<?php wp_nonce_field( 'ufsc_competitions_save_competition' ); ?>
				<input type="hidden" name="action" value="ufsc_competitions_save_competition">
				<input type="hidden" name="id" value="<?php echo esc_attr( $values['id'] ); ?>">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ufsc_competition_name"><?php esc_html_e( 'Nom', 'ufsc-licence-competition' ); ?></label></th>
						<td><input name="name" type="text" id="ufsc_competition_name" class="regular-text" value="<?php echo esc_attr( $values['name'] ); ?>" required></td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_competition_discipline"><?php esc_html_e( 'Discipline', 'ufsc-licence-competition' ); ?></label></th>
						<td>
							<select name="discipline" id="ufsc_competition_discipline" class="regular-text" required>
								<option value=""><?php esc_html_e( 'Sélectionner', 'ufsc-licence-competition' ); ?></option>
								<?php foreach ( $disciplines as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $values['discipline'], $value ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_competition_type"><?php esc_html_e( 'Type', 'ufsc-licence-competition' ); ?></label></th>
						<td>
							<select name="type" id="ufsc_competition_type" class="regular-text" required>
								<?php $types = array( 'regional' => __( 'Régional', 'ufsc-licence-competition' ), 'national' => __( 'National', 'ufsc-licence-competition' ), 'open' => __( 'Open', 'ufsc-licence-competition' ), 'gala' => __( 'Gala', 'ufsc-licence-competition' ), 'interclub' => __( 'Interclub', 'ufsc-licence-competition' ) ); ?>
								<?php foreach ( $types as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $values['type'], $value ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_competition_season"><?php esc_html_e( 'Saison', 'ufsc-licence-competition' ); ?></label></th>
						<td><input name="season" type="text" id="ufsc_competition_season" class="regular-text" value="<?php echo esc_attr( $values['season'] ); ?>" placeholder="2024-2025" required></td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_competition_location"><?php esc_html_e( 'Lieu', 'ufsc-licence-competition' ); ?></label></th>
						<td><input name="location" type="text" id="ufsc_competition_location" class="regular-text" value="<?php echo esc_attr( $values['location'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_competition_start"><?php esc_html_e( 'Date de début', 'ufsc-licence-competition' ); ?></label></th>
						<td><input name="start_date" type="date" id="ufsc_competition_start" value="<?php echo esc_attr( $values['start_date'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_competition_end"><?php esc_html_e( 'Date de fin', 'ufsc-licence-competition' ); ?></label></th>
						<td><input name="end_date" type="date" id="ufsc_competition_end" value="<?php echo esc_attr( $values['end_date'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_competition_deadline"><?php esc_html_e( 'Date limite d\'inscription', 'ufsc-licence-competition' ); ?></label></th>
						<td><input name="registration_deadline" type="date" id="ufsc_competition_deadline" value="<?php echo esc_attr( $values['registration_deadline'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_competition_status"><?php esc_html_e( 'Statut', 'ufsc-licence-competition' ); ?></label></th>
						<td>
							<select name="status" id="ufsc_competition_status" class="regular-text">
								<option value="draft" <?php selected( $values['status'], 'draft' ); ?>><?php esc_html_e( 'Brouillon', 'ufsc-licence-competition' ); ?></option>
								<option value="preparing" <?php selected( $values['status'], 'preparing' ); ?>><?php esc_html_e( 'Préparation', 'ufsc-licence-competition' ); ?></option>
								<option value="open" <?php selected( $values['status'], 'open' ); ?>><?php esc_html_e( 'Ouvert', 'ufsc-licence-competition' ); ?></option>
								<option value="running" <?php selected( $values['status'], 'running' ); ?>><?php esc_html_e( 'En cours', 'ufsc-licence-competition' ); ?></option>
								<option value="closed" <?php selected( $values['status'], 'closed' ); ?>><?php esc_html_e( 'Clos', 'ufsc-licence-competition' ); ?></option>
								<option value="archived" <?php selected( $values['status'], 'archived' ); ?>><?php esc_html_e( 'Archivé', 'ufsc-licence-competition' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_competition_age_reference"><?php esc_html_e( 'Référence d\'âge', 'ufsc-licence-competition' ); ?></label></th>
						<td>
							<select name="age_reference" id="ufsc_competition_age_reference" class="regular-text">
								<option value="12-31" <?php selected( $values['age_reference'], '12-31' ); ?>><?php esc_html_e( '31/12', 'ufsc-licence-competition' ); ?></option>
								<option value="08-31" <?php selected( $values['age_reference'], '08-31' ); ?>><?php esc_html_e( '31/08', 'ufsc-licence-competition' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_competition_weight_tolerance"><?php esc_html_e( 'Tolérance pesée (kg)', 'ufsc-licence-competition' ); ?></label></th>
						<td><input name="weight_tolerance" type="number" step="0.1" min="0" id="ufsc_competition_weight_tolerance" value="<?php echo esc_attr( $values['weight_tolerance'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_competition_allowed_formats"><?php esc_html_e( 'Formats autorisés', 'ufsc-licence-competition' ); ?></label></th>
						<td>
							<input name="allowed_formats" type="text" id="ufsc_competition_allowed_formats" class="regular-text" value="<?php echo esc_attr( $values['allowed_formats'] ); ?>" placeholder="<?php esc_attr_e( 'pool, single_elim', 'ufsc-licence-competition' ); ?>">
							<p class="description"><?php esc_html_e( 'Séparer par des virgules.', 'ufsc-licence-competition' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( $action_label ); ?>
			</form>
		</div>
		<?php
	}

	private function redirect_with_notice( $page, $notice, $id = 0, array $data = array() ) {
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

		if ( $data ) {
			$url = add_query_arg( array( 'ufsc_action' => 'edit' ), $url );
		}

		wp_safe_redirect( $url );
		exit;
	}

	private function render_notice( $notice ) {
		$messages = array(
			'created'       => __( 'Compétition créée.', 'ufsc-licence-competition' ),
			'updated'       => __( 'Compétition mise à jour.', 'ufsc-licence-competition' ),
			'trashed'       => __( 'Compétition déplacée dans la corbeille.', 'ufsc-licence-competition' ),
			'restored'      => __( 'Compétition restaurée.', 'ufsc-licence-competition' ),
			'deleted'       => __( 'Compétition supprimée définitivement.', 'ufsc-licence-competition' ),
			'error_required'=> __( 'Veuillez renseigner les champs obligatoires.', 'ufsc-licence-competition' ),
			'not_found'     => __( 'Compétition introuvable.', 'ufsc-licence-competition' ),
		);

		if ( ! $notice || ! isset( $messages[ $notice ] ) ) {
			return;
		}

		$type = 'error_required' === $notice || 'not_found' === $notice ? 'error' : 'success';
		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( $messages[ $notice ] ) );
	}
}
