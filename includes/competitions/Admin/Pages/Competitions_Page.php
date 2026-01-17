<?php

namespace UFSC\Competitions\Admin\Pages;

use UFSC\Competitions\Capabilities;
use UFSC\Competitions\Admin\Menu;
use UFSC\Competitions\Repositories\CompetitionRepository;
use UFSC\Competitions\Repositories\CategoryRepository;
use UFSC\Competitions\Repositories\EntryRepository;
use UFSC\Competitions\Repositories\FightRepository;
use UFSC\Competitions\Services\DisciplineRegistry;
use UFSC\Competitions\Services\CategoryPresetRegistry;
use UFSC\Competitions\Admin\Tables\Competitions_Table;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Competitions_Page {
	private $repository;
	private $categories;
	private $entries;
	private $fights;

	public function __construct() {
		$this->repository = new CompetitionRepository();
		$this->categories = new CategoryRepository();
		$this->entries = new EntryRepository();
		$this->fights = new FightRepository();
	}

	public function register_actions() {
		add_action( 'admin_post_ufsc_competitions_save_competition', array( $this, 'handle_save' ) );
		add_action( 'admin_post_ufsc_competitions_trash_competition', array( $this, 'handle_trash' ) );
		add_action( 'admin_post_ufsc_competitions_restore_competition', array( $this, 'handle_restore' ) );
		add_action( 'admin_post_ufsc_competitions_delete_competition', array( $this, 'handle_delete' ) );
		add_action( 'admin_post_ufsc_competitions_archive_competition', array( $this, 'handle_archive' ) );
		add_action( 'admin_post_ufsc_competitions_apply_preset', array( $this, 'handle_apply_preset' ) );
		add_action( 'admin_post_ufsc_competitions_set_status', array( $this, 'handle_set_status' ) );
		add_action( 'admin_post_ufsc_competitions_generate_fights', array( $this, 'handle_generate_fights' ) );
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

		if ( 'view' === $action && $id ) {
			$item = $this->repository->get( $id, true );
			if ( $item ) {
				$this->render_pilotage( $item );
				return;
			}
			$this->render_notice( 'not_found' );
		}

		$list_table = new Competitions_Table();
		$this->maybe_handle_bulk_actions( $list_table, Menu::PAGE_COMPETITIONS );
		$list_table->prepare_items();

		?>
		<div class="wrap ufsc-competitions-admin">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Compétitions', 'ufsc-licence-competition' ); ?></h1>
			<a href="<?php echo esc_url( add_query_arg( array( 'page' => Menu::PAGE_COMPETITIONS, 'ufsc_action' => 'add' ), admin_url( 'admin.php' ) ) ); ?>" class="page-title-action"><?php esc_html_e( 'Ajouter', 'ufsc-licence-competition' ); ?></a>
			<hr class="wp-header-end">
			<?php $this->render_helper_notice( __( 'Créer et piloter vos événements (type, discipline, dates, statuts, forclusion).', 'ufsc-licence-competition' ) ); ?>
			<?php $list_table->views(); ?>
			<form method="post">
				<input type="hidden" name="page" value="<?php echo esc_attr( Menu::PAGE_COMPETITIONS ); ?>" />
				<?php $list_table->search_box( __( 'Rechercher', 'ufsc-licence-competition' ), 'ufsc-competitions-search' ); ?>
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
			$this->redirect_with_notice( Menu::PAGE_COMPETITIONS, 'error_required', $id, $data );
		}

		if ( $id ) {
			$this->repository->update( $id, $data );
			$this->redirect_with_notice( Menu::PAGE_COMPETITIONS, 'updated', $id );
		}

		$new_id = $this->repository->insert( $data );
		$this->redirect_with_notice( Menu::PAGE_COMPETITIONS, 'created', $new_id );
	}

	public function handle_trash() {
		$this->handle_simple_action( 'ufsc_competitions_trash_competition', 'trash', Menu::PAGE_COMPETITIONS );
	}

	public function handle_restore() {
		$this->handle_simple_action( 'ufsc_competitions_restore_competition', 'restore', Menu::PAGE_COMPETITIONS );
	}

	public function handle_delete() {
		$this->handle_simple_action( 'ufsc_competitions_delete_competition', 'delete', Menu::PAGE_COMPETITIONS );
	}

	public function handle_archive() {
		$this->handle_simple_action( 'ufsc_competitions_archive_competition', 'archive', Menu::PAGE_COMPETITIONS );
	}

	public function handle_apply_preset() {
		if ( ! Capabilities::user_can_manage() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		$competition_id = isset( $_GET['competition_id'] ) ? absint( $_GET['competition_id'] ) : 0;
		check_admin_referer( 'ufsc_competitions_apply_preset_' . $competition_id );

		$competition = $competition_id ? $this->repository->get( $competition_id, true ) : null;
		if ( ! $competition ) {
			$this->redirect_with_notice( Menu::PAGE_COMPETITIONS, 'not_found', $competition_id );
		}

		$type = DisciplineRegistry::get_type( $competition->discipline );
		$preset = CategoryPresetRegistry::get_preset( $type );
		if ( empty( $preset['categories'] ) ) {
			$this->redirect_with_notice( Menu::PAGE_COMPETITIONS, 'preset_missing', $competition_id );
		}

		foreach ( $preset['categories'] as $category ) {
			$existing = $this->categories->get_by_competition_and_name( $competition_id, $category['name'], true );
			$data = array_merge(
				$category,
				array(
					'competition_id' => $competition_id,
					'discipline'     => $competition->discipline,
				)
			);

			if ( $existing ) {
				if ( ! empty( $existing->deleted_at ) ) {
					$this->categories->restore( $existing->id );
				}
				$this->categories->update( $existing->id, $data );
			} else {
				$this->categories->insert( $data );
			}
		}

		$this->redirect_with_notice( Menu::PAGE_COMPETITIONS, 'preset_applied', $competition_id );
	}

	public function handle_set_status() {
		if ( ! Capabilities::user_can_manage() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		$competition_id = isset( $_GET['competition_id'] ) ? absint( $_GET['competition_id'] ) : 0;
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		check_admin_referer( 'ufsc_competitions_set_status_' . $competition_id );

		if ( ! $competition_id ) {
			$this->redirect_with_notice( Menu::PAGE_COMPETITIONS, 'not_found' );
		}

		$this->repository->update_status( $competition_id, $status );
		$this->redirect_with_notice( Menu::PAGE_COMPETITIONS, 'updated', $competition_id );
	}

	public function handle_generate_fights() {
		if ( ! Capabilities::user_can_manage() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		$competition_id = isset( $_GET['competition_id'] ) ? absint( $_GET['competition_id'] ) : 0;
		check_admin_referer( 'ufsc_competitions_generate_fights_' . $competition_id );

		if ( ! $competition_id ) {
			$this->redirect_with_notice( Menu::PAGE_COMPETITIONS, 'not_found' );
		}

		$existing = $this->fights->count( array( 'view' => 'all', 'competition_id' => $competition_id ) );
		if ( $existing > 0 ) {
			$this->redirect_with_notice( Menu::PAGE_COMPETITIONS, 'fights_exists', $competition_id );
		}

		$entry_count = $this->entries->count( array( 'view' => 'all', 'competition_id' => $competition_id, 'status' => 'validated' ) );
		$category_count = $this->categories->count( array( 'view' => 'all', 'competition_id' => $competition_id ) );
		if ( $entry_count < 2 || 0 === $category_count ) {
			$this->redirect_with_notice( Menu::PAGE_COMPETITIONS, 'fights_blocked', $competition_id );
		}

		$this->generate_simple_fights( $competition_id );
		$this->redirect_with_notice( Menu::PAGE_COMPETITIONS, 'fights_generated', $competition_id );
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
			case 'archive':
				$this->repository->archive( $id );
				$this->redirect_with_notice( $page_slug, 'archived' );
				break;
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
			<?php $this->render_helper_notice( __( 'Créer et piloter vos événements (type, discipline, dates, statuts, forclusion).', 'ufsc-licence-competition' ) ); ?>
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
			'archived'      => __( 'Compétition archivée.', 'ufsc-licence-competition' ),
			'preset_applied' => __( 'Référentiel UFSC appliqué.', 'ufsc-licence-competition' ),
			'preset_missing' => __( 'Aucun référentiel UFSC disponible pour cette discipline.', 'ufsc-licence-competition' ),
			'fights_generated' => __( 'Combats générés.', 'ufsc-licence-competition' ),
			'fights_exists' => __( 'Des combats existent déjà pour cette compétition.', 'ufsc-licence-competition' ),
			'fights_blocked' => __( 'Impossible de générer les combats : catégories ou inscriptions validées manquantes.', 'ufsc-licence-competition' ),
			'error_required'=> __( 'Veuillez renseigner les champs obligatoires.', 'ufsc-licence-competition' ),
			'not_found'     => __( 'Compétition introuvable.', 'ufsc-licence-competition' ),
		);

		if ( ! $notice || ! isset( $messages[ $notice ] ) ) {
			return;
		}

		$error_notices = array( 'error_required', 'not_found', 'preset_missing', 'fights_exists', 'fights_blocked' );
		$type = in_array( $notice, $error_notices, true ) ? 'error' : 'success';
		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( $messages[ $notice ] ) );
	}

	private function render_helper_notice( $message ) {
		printf(
			'<div class="notice notice-info ufsc-competitions-helper"><p>%s</p></div>',
			esc_html( $message )
		);
	}

	private function render_pilotage( $competition ) {
		$stats = $this->get_competition_stats( $competition->id );
		$preset_label = $this->get_preset_label( $competition->discipline );
		$actions = $this->get_pilotage_actions( $competition, $stats, $preset_label );
		?>
		<div class="wrap ufsc-competitions-admin">
			<h1><?php echo esc_html( sprintf( __( 'Pilotage : %s', 'ufsc-licence-competition' ), $competition->name ) ); ?></h1>
			<div class="notice notice-info ufsc-competitions-helper"><p><?php esc_html_e( 'Vue de pilotage avec KPI et actions rapides.', 'ufsc-licence-competition' ); ?></p></div>

			<div class="ufsc-kpis">
				<div class="ufsc-kpi">
					<span class="ufsc-kpi__label"><?php esc_html_e( 'Catégories', 'ufsc-licence-competition' ); ?></span>
					<span class="ufsc-kpi__value"><?php echo esc_html( $stats['categories'] ); ?></span>
				</div>
				<div class="ufsc-kpi">
					<span class="ufsc-kpi__label"><?php esc_html_e( 'Inscriptions', 'ufsc-licence-competition' ); ?></span>
					<span class="ufsc-kpi__value"><?php echo esc_html( $stats['entries'] ); ?></span>
				</div>
				<div class="ufsc-kpi">
					<span class="ufsc-kpi__label"><?php esc_html_e( 'Combats', 'ufsc-licence-competition' ); ?></span>
					<span class="ufsc-kpi__value"><?php echo esc_html( $stats['fights'] ); ?></span>
				</div>
				<div class="ufsc-kpi">
					<span class="ufsc-kpi__label"><?php esc_html_e( 'Statut', 'ufsc-licence-competition' ); ?></span>
					<span class="ufsc-kpi__value"><?php echo esc_html( $this->format_status( $competition->status ) ); ?></span>
				</div>
			</div>

			<div class="ufsc-step-grid">
				<?php foreach ( $actions as $step ) : ?>
					<section class="ufsc-step-card <?php echo esc_attr( $step['status_class'] ); ?>">
						<h3><?php echo esc_html( $step['title'] ); ?></h3>
						<ul>
							<?php foreach ( $step['checklist'] as $item ) : ?>
								<li><?php echo esc_html( $item ); ?></li>
							<?php endforeach; ?>
						</ul>
						<div class="ufsc-step-card__actions">
							<?php foreach ( $step['actions'] as $action ) : ?>
								<?php if ( $action['disabled'] ) : ?>
									<a class="button disabled" aria-disabled="true"><?php echo esc_html( $action['label'] ); ?></a>
								<?php else : ?>
									<?php
									$classes = trim( 'button ' . $action['class'] . ( $action['confirm'] ? ' ufsc-confirm' : '' ) );
									?>
									<a class="<?php echo esc_attr( $classes ); ?>" href="<?php echo esc_url( $action['url'] ); ?>" <?php echo $action['confirm'] ? 'data-ufsc-confirm="' . esc_attr( $action['confirm'] ) . '"' : ''; ?>><?php echo esc_html( $action['label'] ); ?></a>
								<?php endif; ?>
							<?php endforeach; ?>
						</div>
						<?php if ( $step['notice'] ) : ?>
							<p class="description"><?php echo esc_html( $step['notice'] ); ?></p>
						<?php endif; ?>
					</section>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	private function get_competition_stats( $competition_id ) {
		return array(
			'categories' => $this->categories->count( array( 'view' => 'all', 'competition_id' => $competition_id ) ),
			'entries'    => $this->entries->count( array( 'view' => 'all', 'competition_id' => $competition_id ) ),
			'fights'     => $this->fights->count( array( 'view' => 'all', 'competition_id' => $competition_id ) ),
		);
	}

	private function get_preset_label( $discipline ) {
		$type = DisciplineRegistry::get_type( $discipline );
		$preset = CategoryPresetRegistry::get_preset( $type );

		return $preset['label'] ?? '';
	}

	private function get_pilotage_actions( $competition, array $stats, $preset_label ) {
		$base_url = admin_url( 'admin.php' );
		$actions = array();
		$categories_ready = $stats['categories'] > 0;
		$entries_ready = $stats['entries'] > 0;
		$fights_ready = $stats['fights'] > 0;

		$actions[] = array(
			'title' => __( 'Étape 1 · Compétition', 'ufsc-licence-competition' ),
			'checklist' => array(
				__( 'Type, discipline, dates, forclusion.', 'ufsc-licence-competition' ),
				__( 'Statut aligné avec l’ouverture.', 'ufsc-licence-competition' ),
			),
			'actions' => array(
				$this->action_link( __( 'Modifier', 'ufsc-licence-competition' ), add_query_arg( array( 'page' => Menu::PAGE_COMPETITIONS, 'ufsc_action' => 'edit', 'id' => $competition->id ), $base_url ), 'button-secondary', false, false ),
				$this->action_link( __( 'Ouvrir inscriptions', 'ufsc-licence-competition' ), $this->get_status_action_url( $competition->id, 'open' ), 'button-primary', $competition->status === 'open', __( 'Ouvrir les inscriptions ?', 'ufsc-licence-competition' ) ),
			),
			'notice' => '',
			'status_class' => 'open' === $competition->status ? 'ufsc-step-card--ok' : 'ufsc-step-card--warning',
		);

		$actions[] = array(
			'title' => __( 'Étape 2 · Catégories', 'ufsc-licence-competition' ),
			'checklist' => array(
				__( 'Appliquer le référentiel UFSC.', 'ufsc-licence-competition' ),
				__( 'Ajuster les catégories si besoin.', 'ufsc-licence-competition' ),
			),
			'actions' => array(
				$this->action_link( __( 'Appliquer référentiel', 'ufsc-licence-competition' ), $this->get_preset_action_url( $competition->id ), 'button-secondary', empty( $preset_label ), __( 'Appliquer le référentiel UFSC ?' , 'ufsc-licence-competition' ) ),
				$this->action_link( __( 'Voir catégories', 'ufsc-licence-competition' ), add_query_arg( array( 'page' => Menu::PAGE_CATEGORIES, 'ufsc_competition_id' => $competition->id ), $base_url ), 'button-secondary', false, false ),
			),
			'notice' => $categories_ready ? '' : ( $preset_label ? sprintf( __( 'Référentiel recommandé : %s.', 'ufsc-licence-competition' ), $preset_label ) : __( 'Aucun référentiel UFSC pour cette discipline.', 'ufsc-licence-competition' ) ),
			'status_class' => $categories_ready ? 'ufsc-step-card--ok' : 'ufsc-step-card--blocked',
		);

		$actions[] = array(
			'title' => __( 'Étape 3 · Inscriptions', 'ufsc-licence-competition' ),
			'checklist' => array(
				__( 'Vérifier les inscriptions.', 'ufsc-licence-competition' ),
				__( 'Valider les dossiers.', 'ufsc-licence-competition' ),
			),
			'actions' => array(
				$this->action_link( __( 'Voir inscriptions', 'ufsc-licence-competition' ), add_query_arg( array( 'page' => Menu::PAGE_ENTRIES, 'ufsc_competition_id' => $competition->id ), $base_url ), 'button-secondary', ! $categories_ready, false ),
			),
			'notice' => $entries_ready ? '' : __( 'Aucune inscription pour le moment.', 'ufsc-licence-competition' ),
			'status_class' => $entries_ready ? 'ufsc-step-card--ok' : 'ufsc-step-card--blocked',
		);

		$actions[] = array(
			'title' => __( 'Étape 4 · Combats', 'ufsc-licence-competition' ),
			'checklist' => array(
				__( 'Générer les combats.', 'ufsc-licence-competition' ),
				__( 'Planifier les aires.', 'ufsc-licence-competition' ),
			),
			'actions' => array(
				$this->action_link( __( 'Générer combats', 'ufsc-licence-competition' ), $this->get_generate_fights_url( $competition->id ), 'button-secondary', ! $entries_ready || ! $categories_ready || $fights_ready, __( 'Générer les combats ?' , 'ufsc-licence-competition' ) ),
				$this->action_link( __( 'Voir combats', 'ufsc-licence-competition' ), add_query_arg( array( 'page' => Menu::PAGE_BOUTS, 'ufsc_competition_id' => $competition->id ), $base_url ), 'button-secondary', ! $entries_ready, false ),
			),
			'notice' => $fights_ready ? '' : __( 'Aucun combat généré.', 'ufsc-licence-competition' ),
			'status_class' => $fights_ready ? 'ufsc-step-card--ok' : 'ufsc-step-card--blocked',
		);

		$actions[] = array(
			'title' => __( 'Étape 5 · Impression', 'ufsc-licence-competition' ),
			'checklist' => array(
				__( 'Contrôler les listings.', 'ufsc-licence-competition' ),
				__( 'Lancer l’impression.', 'ufsc-licence-competition' ),
			),
			'actions' => array(
				$this->action_link( __( 'Imprimer', 'ufsc-licence-competition' ), add_query_arg( array( 'page' => Menu::PAGE_PRINT, 'competition_id' => $competition->id ), $base_url ), 'button-secondary', false, false ),
			),
			'notice' => '',
			'status_class' => 'ufsc-step-card--ok',
		);

		$actions[] = array(
			'title' => __( 'Étape 6 · Clôture', 'ufsc-licence-competition' ),
			'checklist' => array(
				__( 'Renseigner les résultats.', 'ufsc-licence-competition' ),
				__( 'Clôturer puis archiver.', 'ufsc-licence-competition' ),
			),
			'actions' => array(
				$this->action_link( __( 'Clôturer', 'ufsc-licence-competition' ), $this->get_status_action_url( $competition->id, 'closed' ), 'button-secondary', 'closed' === $competition->status, __( 'Clôturer la compétition ?' , 'ufsc-licence-competition' ) ),
				$this->action_link( __( 'Archiver', 'ufsc-licence-competition' ), $this->get_archive_action_url( $competition->id ), 'button-secondary', 'archived' === $competition->status, __( 'Archiver la compétition ?' , 'ufsc-licence-competition' ) ),
			),
			'notice' => 'archived' === $competition->status ? __( 'Compétition archivée.', 'ufsc-licence-competition' ) : '',
			'status_class' => in_array( $competition->status, array( 'closed', 'archived' ), true ) ? 'ufsc-step-card--ok' : 'ufsc-step-card--warning',
		);

		return $actions;
	}

	private function action_link( $label, $url, $class, $disabled, $confirm ) {
		return array(
			'label'   => $label,
			'url'     => $url,
			'class'   => $class,
			'disabled'=> (bool) $disabled,
			'confirm' => $confirm,
		);
	}

	private function get_preset_action_url( $competition_id ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'ufsc_competitions_apply_preset',
					'competition_id' => $competition_id,
				),
				admin_url( 'admin-post.php' )
			),
			'ufsc_competitions_apply_preset_' . $competition_id
		);
	}

	private function get_status_action_url( $competition_id, $status ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'ufsc_competitions_set_status',
					'competition_id' => $competition_id,
					'status' => $status,
				),
				admin_url( 'admin-post.php' )
			),
			'ufsc_competitions_set_status_' . $competition_id
		);
	}

	private function get_generate_fights_url( $competition_id ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'ufsc_competitions_generate_fights',
					'competition_id' => $competition_id,
				),
				admin_url( 'admin-post.php' )
			),
			'ufsc_competitions_generate_fights_' . $competition_id
		);
	}

	private function get_archive_action_url( $competition_id ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'ufsc_competitions_archive_competition',
					'id' => $competition_id,
				),
				admin_url( 'admin-post.php' )
			),
			'ufsc_competitions_archive_competition_' . $competition_id
		);
	}

	private function format_status( $status ) {
		$labels = array(
			'draft'  => __( 'Brouillon', 'ufsc-licence-competition' ),
			'preparing' => __( 'Préparation', 'ufsc-licence-competition' ),
			'open'   => __( 'Ouvert', 'ufsc-licence-competition' ),
			'running' => __( 'En cours', 'ufsc-licence-competition' ),
			'closed' => __( 'Clos', 'ufsc-licence-competition' ),
			'archived' => __( 'Archivé', 'ufsc-licence-competition' ),
		);

		return $labels[ $status ] ?? $status;
	}

	private function generate_simple_fights( $competition_id ) {
		$entries = $this->entries->list( array( 'view' => 'all', 'competition_id' => $competition_id, 'status' => 'validated' ), 1000, 0 );
		$by_category = array();
		foreach ( $entries as $entry ) {
			if ( empty( $entry->category_id ) ) {
				continue;
			}
			$by_category[ $entry->category_id ][] = $entry;
		}

		$fight_no = 1;
		foreach ( $by_category as $category_id => $category_entries ) {
			usort(
				$category_entries,
				function( $a, $b ) {
					return (int) $a->licensee_id <=> (int) $b->licensee_id;
				}
			);

			for ( $i = 0; $i + 1 < count( $category_entries ); $i += 2 ) {
				$this->fights->insert(
					array(
						'competition_id' => $competition_id,
						'category_id'    => $category_id,
						'fight_no'       => $fight_no,
						'red_entry_id'   => $category_entries[ $i ]->id,
						'blue_entry_id'  => $category_entries[ $i + 1 ]->id,
						'status'         => 'scheduled',
					)
				);
				$fight_no++;
			}
		}
	}

	private function maybe_handle_bulk_actions( Competitions_Table $list_table, $page_slug ) {
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
				case 'archive':
					$this->repository->archive( $id );
					break;
			}
		}

		$notice_map = array(
			'trash'   => 'trashed',
			'restore' => 'restored',
			'delete'  => 'deleted',
			'archive' => 'archived',
		);

		$this->redirect_with_notice( $page_slug, $notice_map[ $action ] ?? 'updated' );
	}
}
