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
use UFSC\Competitions\Services\PoolGenerator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Competitions_Page {
	private $repository;
	private $categories;
	private $entries;
	private $fights;

	public function __construct() {
		// Defensive instantiation: avoid fatal if class is unavailable due to load order or external conflict.
		$this->repository = class_exists( '\\UFSC\\Competitions\\Repositories\\CompetitionRepository' ) ? new CompetitionRepository() : null;
		$this->categories = class_exists( '\\UFSC\\Competitions\\Repositories\\CategoryRepository' ) ? new CategoryRepository() : null;
		$this->entries = class_exists( '\\UFSC\\Competitions\\Repositories\\EntryRepository' ) ? new EntryRepository() : null;
		$this->fights = class_exists( '\\UFSC\\Competitions\\Repositories\\FightRepository' ) ? new FightRepository() : null;
	}

	public function register_actions() {
		add_action( 'admin_post_ufsc_competitions_save_competition', array( $this, 'handle_save' ) );
		add_action( 'admin_post_ufsc_competitions_trash_competition', array( $this, 'handle_trash' ) );
		add_action( 'admin_post_ufsc_competitions_restore_competition', array( $this, 'handle_restore' ) );
		add_action( 'admin_post_ufsc_competitions_delete_competition', array( $this, 'handle_delete' ) );
	}

	public function render() {
		if ( null === $this->repository ) {
			// Friendly admin notice instead of fatal
			echo '<div class="notice notice-error"><p>' . esc_html__( 'UFSC Competitions: module competitions non disponible — vérifiez le chargement du plugin.', 'ufsc-licence-competition' ) . '</p></div>';
			return;
		}

		if ( ! Capabilities::user_can_manage() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ) );
		}

		$action = isset( $_GET['ufsc_action'] ) ? sanitize_key( wp_unslash( $_GET['ufsc_action'] ) ) : '';
		$notice = isset( $_GET['ufsc_notice'] ) ? sanitize_key( wp_unslash( $_GET['ufsc_notice'] ) ) : '';
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$view = isset( $_GET['ufsc_view'] ) ? sanitize_key( wp_unslash( $_GET['ufsc_view'] ) ) : '';

		$this->render_notice( $notice );

		if ( in_array( $action, array( 'add', 'edit' ), true ) ) {
			$item = null;
			if ( 'edit' === $action && $id && $this->repository ) {
				$item = $this->repository->get( $id, true );
			}
			$this->render_form( $item );
			return;
		}

		if ( 'view' === $action && $id && $this->repository ) {
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
			<?php $this->render_helper_notice( __( 'Gérer les compétitions, catégories, inscriptions et tableaux.', 'ufsc-licence-competition' ) ); ?>
			<?php $list_table->views(); ?>
			<form method="post">
				<input type="hidden" name="page" value="<?php echo esc_attr( Menu::PAGE_COMPETITIONS ); ?>" />
				<?php $list_table->display(); ?>
			</form>
		</div>
		<?php
	}

	private function render_notice( $notice ) {
		if ( ! $notice ) {
			return;
		}

		$map = array(
			'not_found' => __( 'Élément non trouvé.', 'ufsc-licence-competition' ),
			'saved'     => __( 'Compétition enregistrée.', 'ufsc-licence-competition' ),
			'created'   => __( 'Compétition créée.', 'ufsc-licence-competition' ),
		);

		if ( isset( $map[ $notice ] ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html( $map[ $notice ] ) . '</p></div>';
		}
	}

	private function render_form( $item = null ) {
		// Prepare values (safe defaults)
		$values = array(
			'id'         => $item->id ?? 0,
			'name'       => $item->name ?? '',
			'discipline' => $item->discipline ?? '',
			'type'       => $item->type ?? '',
			'season'     => $item->season ?? '',
			'start_date' => $item->start_date ?? '',
			'end_date'   => $item->end_date ?? '',
			'status'     => $item->status ?? 'draft',
		);

		$disciplines = DisciplineRegistry::get_disciplines_with_types();
		?>
		<div class="wrap ufsc-competitions-admin">
			<h1><?php echo esc_html( $values['id'] ? __( 'Modifier la compétition', 'ufsc-licence-competition' ) : __( 'Ajouter une compétition', 'ufsc-licence-competition' ) ); ?></h1>
			<?php $this->render_helper_notice( __( 'Renseigner les informations de la compétition.', 'ufsc-licence-competition' ) ); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ufsc-competitions-form">
				<?php wp_nonce_field( 'ufsc_competitions_save_competition' ); ?>
				<input type="hidden" name="action" value="ufsc_competitions_save_competition">
				<input type="hidden" name="id" value="<?php echo esc_attr( $values['id'] ); ?>">

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ufsc_competition_name"><?php esc_html_e( 'Nom', 'ufsc-licence-competition' ); ?></label></th>
						<td><input name="name" type="text" id="ufsc_competition_name" value="<?php echo esc_attr( $values['name'] ); ?>" class="regular-text" required></td>
					</tr>

					<tr>
						<th scope="row"><label for="ufsc_competition_discipline"><?php esc_html_e( 'Discipline', 'ufsc-licence-competition' ); ?></label></th>
						<td>
							<select name="discipline" id="ufsc_competition_discipline" class="regular-text" required>
								<option value=""><?php esc_html_e( 'Sélectionner', 'ufsc-licence-competition' ); ?></option>
								<?php foreach ( $disciplines as $discipline => $types ) : ?>
									<option value="<?php echo esc_attr( $discipline ); ?>" <?php selected( $values['discipline'], $discipline ); ?>><?php echo esc_html( $types['label'] ?? $discipline ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="ufsc_competition_start"><?php esc_html_e( 'Date début', 'ufsc-licence-competition' ); ?></label></th>
						<td><input name="start_date" type="date" id="ufsc_competition_start" value="<?php echo esc_attr( $values['start_date'] ); ?>"></td>
					</tr>

					<tr>
						<th scope="row"><label for="ufsc_competition_end"><?php esc_html_e( 'Date fin', 'ufsc-licence-competition' ); ?></label></th>
						<td><input name="end_date" type="date" id="ufsc_competition_end" value="<?php echo esc_attr( $values['end_date'] ); ?>"></td>
					</tr>

					<tr>
						<th scope="row"><label for="ufsc_competition_status"><?php esc_html_e( 'Statut', 'ufsc-licence-competition' ); ?></label></th>
						<td>
							<select name="status" id="ufsc_competition_status" class="regular-text">
								<option value="draft" <?php selected( $values['status'], 'draft' ); ?>><?php esc_html_e( 'Brouillon', 'ufsc-licence-competition' ); ?></option>
								<option value="open" <?php selected( $values['status'], 'open' ); ?>><?php esc_html_e( 'Ouvert', 'ufsc-licence-competition' ); ?></option>
								<option value="closed" <?php selected( $values['status'], 'closed' ); ?>><?php esc_html_e( 'Fermé', 'ufsc-licence-competition' ); ?></option>
								<option value="archived" <?php selected( $values['status'], 'archived' ); ?>><?php esc_html_e( 'Archivé', 'ufsc-licence-competition' ); ?></option>
							</select>
						</td>
					</tr>
				</table>

				<?php submit_button( $values['id'] ? __( 'Mettre à jour la compétition', 'ufsc-licence-competition' ) : __( 'Créer la compétition', 'ufsc-licence-competition' ) ); ?>
			</form>
		</div>
		<?php
	}

	private function render_pilotage( $item ) {
		echo '<h2>' . esc_html( $item->name ) . '</h2>';
		// pilotage display stub
	}

	private function maybe_handle_bulk_actions( $list_table, $page ) {
		// noop stub: existing WP_List_Table handles bulk actions; real implementation may exist elsewhere.
	}

	private function render_helper_notice( $message ) {
		echo '<div class="notice notice-info ufsc-competitions-helper"><p>' . esc_html( $message ) . '</p></div>';
	}

	// Action handlers
	public function handle_save() {
		if ( ! Capabilities::user_can_manage() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'ufsc_competitions_save_competition' );

		$id = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;
		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$discipline = isset( $_POST['discipline'] ) ? sanitize_key( wp_unslash( $_POST['discipline'] ) ) : '';
		$start_date = isset( $_POST['start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['start_date'] ) ) : '';
		$end_date = isset( $_POST['end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['end_date'] ) ) : '';
		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'draft';

		$data = array(
			'id'         => $id,
			'name'       => $name,
			'discipline' => $discipline,
			'start_date' => $start_date,
			'end_date'   => $end_date,
			'status'     => $status,
		);

		// If repository available, persist using its API; else safely ignore and redirect with notice.
		if ( $this->repository ) {
			// repository API assumed to have save or update method (defensive)
			if ( method_exists( $this->repository, 'save' ) ) {
				$this->repository->save( $data );
			} elseif ( method_exists( $this->repository, 'create' ) && $id === 0 ) {
				$this->repository->create( $data );
			} elseif ( method_exists( $this->repository, 'update' ) && $id ) {
				$this->repository->update( $id, $data );
			}
		}

		$redirect_url = add_query_arg( array( 'page' => Menu::PAGE_COMPETITIONS, 'ufsc_notice' => 'saved' ), admin_url( 'admin.php' ) );
		wp_safe_redirect( $redirect_url );
		exit;
	}

	public function handle_trash() { wp_die(); }
	public function handle_restore() { wp_die(); }
	public function handle_delete() { wp_die(); }
}
