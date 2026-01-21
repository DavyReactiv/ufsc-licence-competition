<?php

namespace UFSC\Competitions\Admin\Pages;

use UFSC\Competitions\Admin\Menu;
use UFSC\Competitions\Repositories\CompetitionRepository;
use UFSC\Competitions\Repositories\ClubRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Competitions_Page {
	/** @var CompetitionRepository|null */
	private $repository;

	/** @var ClubRepository|null */
	private $club_repository;

	public function __construct() {
		$this->repository = class_exists( '\\UFSC\\Competitions\\Repositories\\CompetitionRepository' )
			? new CompetitionRepository()
			: null;

		$this->club_repository = class_exists( '\\UFSC\\Competitions\\Repositories\\ClubRepository' )
			? new ClubRepository()
			: null;

		add_action( 'wp_ajax_ufsc_get_club', array( $this, 'ajax_get_club' ) );
	}

	/**
	 * Hook admin_post handlers.
	 * Ideally called once by Menu registration.
	 */
	public function register_actions() {
		add_action( 'admin_post_ufsc_competitions_save_competition', array( $this, 'handle_save' ) );
		add_action( 'admin_post_ufsc_competitions_trash_competition', array( $this, 'handle_trash' ) );
		add_action( 'admin_post_ufsc_competitions_restore_competition', array( $this, 'handle_restore' ) );
		add_action( 'admin_post_ufsc_competitions_delete_competition', array( $this, 'handle_delete' ) );
		add_action( 'admin_post_ufsc_competitions_archive_competition', array( $this, 'handle_archive' ) );
		add_action( 'admin_post_ufsc_competitions_unarchive_competition', array( $this, 'handle_unarchive' ) );
	}

	/**
	 * Standard public render() expected by admin menu callbacks.
	 */
	public function render() {
		$cap = \UFSC_LC_Capabilities::get_manage_capability();
		if ( ! current_user_can( $cap ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		if ( ! $this->repository ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Compétitions', 'ufsc-licence-competition' ) . '</h1>';
			echo '<div class="notice notice-error"><p>' .
				esc_html__( 'Repository indisponible. Vérifiez l’autoload / bootstrap du module Compétitions.', 'ufsc-licence-competition' ) .
			'</p></div></div>';
			return;
		}

		$action = isset( $_GET['ufsc_action'] ) ? sanitize_key( wp_unslash( $_GET['ufsc_action'] ) ) : '';
		$notice = isset( $_GET['ufsc_notice'] ) ? sanitize_key( wp_unslash( $_GET['ufsc_notice'] ) ) : '';
		$id     = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

		$this->render_notice( $notice );

		if ( in_array( $action, array( 'add', 'edit' ), true ) ) {
			$item = null;

			if ( 'edit' === $action ) {
				if ( ! $id ) {
					$this->redirect_notice( 'invalid' );
				}
				$item = $this->repository->get( $id, true );
				if ( ! $item ) {
					$this->redirect_notice( 'not_found' );
				}
			}

			$this->render_form( $item );
			return;
		}

		$this->render_list();
	}

	/**
	 * LIST VIEW
	 */
	private function render_list() {
		if ( ! class_exists( '\\UFSC\\Competitions\\Admin\\Tables\\Competitions_Table' ) ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Compétitions', 'ufsc-licence-competition' ) . '</h1>';
			echo '<div class="notice notice-warning"><p>' .
				esc_html__( 'Tableau introuvable : la classe Competitions_Table est manquante.', 'ufsc-licence-competition' ) .
			'</p></div></div>';
			return;
		}

		$list_table = new \UFSC\Competitions\Admin\Tables\Competitions_Table();

		// Bulk actions must be handled BEFORE prepare_items().
		$this->maybe_handle_bulk_actions( $list_table );

		$list_table->prepare_items();

		$current_view = isset( $_GET['ufsc_view'] ) ? sanitize_key( wp_unslash( $_GET['ufsc_view'] ) ) : 'all';
		$search       = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

		?>
		<div class="wrap ufsc-competitions-admin">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Compétitions', 'ufsc-licence-competition' ); ?></h1>

			<a href="<?php echo esc_url( add_query_arg( array( 'page' => Menu::MENU_SLUG, 'ufsc_action' => 'add' ), admin_url( 'admin.php' ) ) ); ?>" class="page-title-action">
				<?php esc_html_e( 'Ajouter', 'ufsc-licence-competition' ); ?>
			</a>

			<hr class="wp-header-end">

			<?php $list_table->views(); ?>

			<!-- Search (GET) -->
			<form method="get" style="margin: 8px 0 12px;">
				<input type="hidden" name="page" value="<?php echo esc_attr( Menu::MENU_SLUG ); ?>" />
				<input type="hidden" name="ufsc_view" value="<?php echo esc_attr( $current_view ); ?>" />
				<?php $list_table->search_box( __( 'Rechercher', 'ufsc-licence-competition' ), 'ufsc-competitions-search' ); ?>
			</form>

			<!-- Table + bulk actions (POST) -->
			<form method="post">
				<?php
				echo '<input type="hidden" name="page" value="' . esc_attr( Menu::MENU_SLUG ) . '" />';
				echo '<input type="hidden" name="ufsc_view" value="' . esc_attr( $current_view ) . '" />';
				if ( $search !== '' ) {
					echo '<input type="hidden" name="s" value="' . esc_attr( $search ) . '" />';
				}

				$list_table->display();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * FORM VIEW (add/edit)
	 *
	 * @param object|null $item
	 */
	private function render_form( $item ) {
		$is_edit = ( is_object( $item ) && ! empty( $item->id ) );

		$action_url = admin_url( 'admin-post.php' );
		$back_url   = add_query_arg( array( 'page' => Menu::MENU_SLUG ), admin_url( 'admin.php' ) );

		$name       = $is_edit ? (string) ( $item->name ?? '' ) : '';
		$discipline = $is_edit ? (string) ( $item->discipline ?? '' ) : '';
		$type       = $is_edit ? (string) ( $item->type ?? '' ) : '';
		$season     = $is_edit ? (string) ( $item->season ?? '' ) : '';
		$status     = $is_edit ? (string) ( $item->status ?? '' ) : 'open';

		// Optional datetime fields if present in schema.
		$event_start = $is_edit ? (string) ( $item->event_start_datetime ?? '' ) : '';
		$event_end   = $is_edit ? (string) ( $item->event_end_datetime ?? '' ) : '';

		?>
		<div class="wrap ufsc-competitions-admin">
			<h1><?php echo esc_html( $is_edit ? __( 'Modifier une compétition', 'ufsc-licence-competition' ) : __( 'Ajouter une compétition', 'ufsc-licence-competition' ) ); ?></h1>

			<p><a class="button" href="<?php echo esc_url( $back_url ); ?>">&larr; <?php esc_html_e( 'Retour à la liste', 'ufsc-licence-competition' ); ?></a></p>

			<form method="post" action="<?php echo esc_url( $action_url ); ?>">
				<input type="hidden" name="action" value="ufsc_competitions_save_competition" />
				<?php wp_nonce_field( 'ufsc_competitions_save_competition' ); ?>

				<?php if ( $is_edit ) : ?>
					<input type="hidden" name="id" value="<?php echo esc_attr( (int) $item->id ); ?>" />
				<?php endif; ?>

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="name"><?php esc_html_e( 'Nom', 'ufsc-licence-competition' ); ?></label></th>
							<td><input name="name" id="name" type="text" class="regular-text" value="<?php echo esc_attr( $name ); ?>" required /></td>
						</tr>

						<tr>
							<th scope="row"><label for="discipline"><?php esc_html_e( 'Discipline', 'ufsc-licence-competition' ); ?></label></th>
							<td><input name="discipline" id="discipline" type="text" class="regular-text" value="<?php echo esc_attr( $discipline ); ?>" /></td>
						</tr>

						<tr>
							<th scope="row"><label for="type"><?php esc_html_e( 'Type', 'ufsc-licence-competition' ); ?></label></th>
							<td><input name="type" id="type" type="text" class="regular-text" value="<?php echo esc_attr( $type ); ?>" /></td>
						</tr>

						<tr>
							<th scope="row"><label for="season"><?php esc_html_e( 'Saison', 'ufsc-licence-competition' ); ?></label></th>
							<td><input name="season" id="season" type="text" class="regular-text" value="<?php echo esc_attr( $season ); ?>" placeholder="2025-2026" /></td>
						</tr>

						<tr>
							<th scope="row"><label for="status"><?php esc_html_e( 'Statut', 'ufsc-licence-competition' ); ?></label></th>
							<td>
								<select name="status" id="status">
									<?php
									$choices = array(
										'open'     => __( 'Ouverte', 'ufsc-licence-competition' ),
										'draft'    => __( 'Brouillon', 'ufsc-licence-competition' ),
										'closed'   => __( 'Clôturée', 'ufsc-licence-competition' ),
										'archived' => __( 'Archivée', 'ufsc-licence-competition' ),
									);
									foreach ( $choices as $k => $label ) {
										printf(
											'<option value="%s"%s>%s</option>',
											esc_attr( $k ),
											selected( $status, $k, false ),
											esc_html( $label )
										);
									}
									?>
								</select>
							</td>
						</tr>

						<tr>
							<th scope="row"><label for="event_start_datetime"><?php esc_html_e( 'Début (datetime)', 'ufsc-licence-competition' ); ?></label></th>
							<td>
								<input name="event_start_datetime" id="event_start_datetime" type="text" class="regular-text"
									value="<?php echo esc_attr( $event_start ); ?>"
									placeholder="YYYY-MM-DD HH:MM:SS" />
							</td>
						</tr>

						<tr>
							<th scope="row"><label for="event_end_datetime"><?php esc_html_e( 'Fin (datetime)', 'ufsc-licence-competition' ); ?></label></th>
							<td>
								<input name="event_end_datetime" id="event_end_datetime" type="text" class="regular-text"
									value="<?php echo esc_attr( $event_end ); ?>"
									placeholder="YYYY-MM-DD HH:MM:SS" />
							</td>
						</tr>
					</tbody>
				</table>

				<?php submit_button( $is_edit ? __( 'Mettre à jour', 'ufsc-licence-competition' ) : __( 'Créer', 'ufsc-licence-competition' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Bulk actions: require nonce + capability.
	 */
	private function maybe_handle_bulk_actions( $list_table ) {
		$cap = \UFSC_LC_Capabilities::get_manage_capability();
		if ( ! current_user_can( $cap ) ) {
			return;
		}
		if ( ! $list_table || ! method_exists( $list_table, 'current_action' ) ) {
			return;
		}

		$action = $list_table->current_action();
		if ( empty( $action ) ) {
			return;
		}

		if ( empty( $_REQUEST['_wpnonce'] ) ) {
			return;
		}

		$plural = null;
		if ( is_object( $list_table ) && property_exists( $list_table, 'plural' ) ) {
			$plural = $list_table->plural;
		} elseif ( is_object( $list_table ) && isset( $list_table->_args['plural'] ) ) {
			$plural = $list_table->_args['plural'];
		}
		$nonce_action = $plural ? 'bulk-' . $plural : 'bulk-ufsc-competitions';

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ), $nonce_action ) ) {
			return;
		}

		$ids = array();
		if ( ! empty( $_REQUEST['ids'] ) && is_array( $_REQUEST['ids'] ) ) {
			$ids = array_map( 'absint', wp_unslash( $_REQUEST['ids'] ) );
		} elseif ( ! empty( $_REQUEST['id'] ) ) {
			$ids = array( absint( wp_unslash( $_REQUEST['id'] ) ) );
		}
		$ids = array_filter( $ids );

		if ( empty( $ids ) || ! $this->repository ) {
			return;
		}

		foreach ( $ids as $id ) {
			if ( 'trash' === $action && method_exists( $this->repository, 'trash' ) ) {
				$this->repository->trash( $id );
			} elseif ( 'restore' === $action && method_exists( $this->repository, 'restore' ) ) {
				$this->repository->restore( $id );
			} elseif ( 'delete' === $action && method_exists( $this->repository, 'delete' ) ) {
				$this->repository->delete( $id );
			} elseif ( 'archive' === $action && method_exists( $this->repository, 'archive' ) ) {
				$this->repository->archive( $id );
			} elseif ( 'unarchive' === $action && method_exists( $this->repository, 'unarchive' ) ) {
				$this->repository->unarchive( $id );
			}
		}

		$redirect_args = array( 'page' => Menu::MENU_SLUG, 'ufsc_notice' => 'saved' );
		if ( isset( $_REQUEST['ufsc_view'] ) ) {
			$redirect_args['ufsc_view'] = sanitize_key( wp_unslash( $_REQUEST['ufsc_view'] ) );
		}
		if ( isset( $_REQUEST['s'] ) ) {
			$redirect_args['s'] = sanitize_text_field( wp_unslash( $_REQUEST['s'] ) );
		}

		wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * AJAX: get club snapshot.
	 */
	public function ajax_get_club() {
		$cap = \UFSC_LC_Capabilities::get_manage_capability();
		if ( ! current_user_can( $cap ) ) {
			wp_send_json_error( 'forbidden', 403 );
		}

		check_ajax_referer( 'ufsc_get_club', 'nonce' );

		$club_id = isset( $_POST['club_id'] ) ? absint( wp_unslash( $_POST['club_id'] ) ) : 0;
		if ( ! $club_id ) {
			wp_send_json_error( 'missing', 400 );
		}

		if ( ! $this->club_repository || ! method_exists( $this->club_repository, 'get' ) ) {
			wp_send_json_error( 'club_repo_missing', 500 );
		}

		$club = $this->club_repository->get( $club_id );
		if ( ! $club ) {
			wp_send_json_error( 'not_found', 404 );
		}

		wp_send_json_success(
			array(
				'id'     => (int) ( $club->id ?? 0 ),
				'nom'    => sanitize_text_field( $club->nom ?? '' ),
				'region' => sanitize_text_field( $club->region ?? '' ),
			)
		);
	}

	/**
	 * Handlers (admin-post)
	 */
	public function handle_save() {
		$cap = \UFSC_LC_Capabilities::get_manage_capability();
		if ( ! current_user_can( $cap ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'ufsc_competitions_save_competition' );

		if ( ! $this->repository || ! method_exists( $this->repository, 'save' ) ) {
			$this->redirect_notice( 'invalid' );
		}

		$data = array(
			'id'                  => isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0,
			'name'                => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
			'discipline'           => isset( $_POST['discipline'] ) ? sanitize_text_field( wp_unslash( $_POST['discipline'] ) ) : '',
			'type'                => isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '',
			'season'              => isset( $_POST['season'] ) ? sanitize_text_field( wp_unslash( $_POST['season'] ) ) : '',
			'status'              => isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '',
			'event_start_datetime' => isset( $_POST['event_start_datetime'] ) ? sanitize_text_field( wp_unslash( $_POST['event_start_datetime'] ) ) : '',
			'event_end_datetime'   => isset( $_POST['event_end_datetime'] ) ? sanitize_text_field( wp_unslash( $_POST['event_end_datetime'] ) ) : '',
		);

		$saved_id = $this->repository->save( $data );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => Menu::MENU_SLUG,
					'ufsc_notice' => $saved_id ? 'saved' : 'invalid',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function handle_trash() {
		$cap = \UFSC_LC_Capabilities::get_manage_capability();
		if ( ! current_user_can( $cap ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		check_admin_referer( 'ufsc_competitions_trash_competition_' . $id );

		if ( $id && $this->repository && method_exists( $this->repository, 'trash' ) ) {
			$this->repository->trash( $id );
		}

		$this->redirect_notice( 'saved' );
	}

	public function handle_restore() {
		$cap = \UFSC_LC_Capabilities::get_manage_capability();
		if ( ! current_user_can( $cap ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		check_admin_referer( 'ufsc_competitions_restore_competition_' . $id );

		if ( $id && $this->repository && method_exists( $this->repository, 'restore' ) ) {
			$this->repository->restore( $id );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => Menu::MENU_SLUG,
					'ufsc_view'   => 'trash',
					'ufsc_notice' => 'saved',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function handle_delete() {
		$cap = \UFSC_LC_Capabilities::get_manage_capability();
		if ( ! current_user_can( $cap ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		check_admin_referer( 'ufsc_competitions_delete_competition_' . $id );

		if ( $id && $this->repository && method_exists( $this->repository, 'delete' ) ) {
			$this->repository->delete( $id );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => Menu::MENU_SLUG,
					'ufsc_view'   => 'trash',
					'ufsc_notice' => 'saved',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function handle_archive() {
		$cap = \UFSC_LC_Capabilities::get_manage_capability();
		if ( ! current_user_can( $cap ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		check_admin_referer( 'ufsc_competitions_archive_competition_' . $id );

		if ( $id && $this->repository && method_exists( $this->repository, 'archive' ) ) {
			$this->repository->archive( $id );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => Menu::MENU_SLUG,
					'ufsc_view'   => 'archived',
					'ufsc_notice' => 'saved',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function handle_unarchive() {
		$cap = \UFSC_LC_Capabilities::get_manage_capability();
		if ( ! current_user_can( $cap ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		check_admin_referer( 'ufsc_competitions_unarchive_competition_' . $id );

		if ( $id && $this->repository && method_exists( $this->repository, 'unarchive' ) ) {
			$this->repository->unarchive( $id );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => Menu::MENU_SLUG,
					'ufsc_view'   => 'all',
					'ufsc_notice' => 'saved',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	private function render_notice( $notice ) {
		if ( ! $notice ) {
			return;
		}

		$map = array(
			'saved'     => array( 'success', __( 'Sauvegardé.', 'ufsc-licence-competition' ) ),
			'invalid'   => array( 'error', __( 'Données invalides.', 'ufsc-licence-competition' ) ),
			'not_found' => array( 'error', __( 'Élément introuvable.', 'ufsc-licence-competition' ) ),
		);

		if ( ! isset( $map[ $notice ] ) ) {
			return;
		}

		$type = $map[ $notice ][0];
		$msg  = $map[ $notice ][1];

		echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
	}

	private function redirect_notice( $notice ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => Menu::MENU_SLUG,
					'ufsc_notice' => sanitize_key( $notice ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
