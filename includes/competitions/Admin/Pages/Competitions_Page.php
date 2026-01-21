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

	/** @var bool */
	private static $actions_registered = false;

	public function __construct() {
		$this->repository = class_exists( '\\UFSC\\Competitions\\Repositories\\CompetitionRepository' )
			? new CompetitionRepository()
			: null;

		$this->club_repository = class_exists( '\\UFSC\\Competitions\\Repositories\\ClubRepository' )
			? new ClubRepository()
			: null;

		// AJAX
		add_action( 'wp_ajax_ufsc_get_club', array( $this, 'ajax_get_club' ) );

		/**
		 * IMPORTANT:
		 * On enregistre les handlers admin-post ici (admin_init) pour ne pas dépendre
		 * d’un appel externe Menu::register_actions().
		 */
		add_action( 'admin_init', array( $this, 'register_actions' ), 1 );
	}

	/**
	 * Hook admin_post handlers.
	 */
	public function register_actions() {
		if ( self::$actions_registered ) {
			return;
		}
		self::$actions_registered = true;

		add_action( 'admin_post_ufsc_competitions_save_competition', array( $this, 'handle_save' ) );
		add_action( 'admin_post_ufsc_competitions_trash_competition', array( $this, 'handle_trash' ) );
		add_action( 'admin_post_ufsc_competitions_restore_competition', array( $this, 'handle_restore' ) );
		add_action( 'admin_post_ufsc_competitions_delete_competition', array( $this, 'handle_delete' ) );
	}

	/**
	 * Standard public render() expected by admin menu callbacks.
	 */
	public function render() {
		$cap = class_exists( '\\UFSC_LC_Capabilities' )
			? \UFSC_LC_Capabilities::get_manage_capability()
			: 'manage_options';

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

		// Bulk actions (nonce + cap)
		$this->maybe_handle_bulk_actions( $list_table );

		$list_table->prepare_items();

		// Debug UNIQUEMENT si ufsc_debug=1
		$show_debug = ( defined( 'WP_DEBUG' ) && WP_DEBUG )
			&& current_user_can( 'manage_options' )
			&& isset( $_GET['ufsc_debug'] )
			&& '1' === (string) wp_unslash( $_GET['ufsc_debug'] );

		if ( $show_debug && method_exists( $list_table, 'get_debug_info' ) ) {
			$debug = $list_table->get_debug_info();
			if ( is_array( $debug ) ) {
				echo '<div class="notice notice-info"><pre style="white-space:pre-wrap;">' . esc_html(
					sprintf(
						"Debug Competitions list:\nFilters: %s\nTotal (count): %d\nReturned (list): %d\n",
						wp_json_encode( $debug['filters'] ?? array(), JSON_PRETTY_PRINT ),
						(int) ( $debug['total_items'] ?? 0 ),
						(int) ( $debug['returned'] ?? 0 )
					)
				) . '</pre></div>';
			}
		}

		?>
		<div class="wrap ufsc-competitions-admin">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Compétitions', 'ufsc-licence-competition' ); ?></h1>

			<a href="<?php echo esc_url( add_query_arg( array( 'page' => Menu::PAGE_COMPETITIONS, 'ufsc_action' => 'add' ), admin_url( 'admin.php' ) ) ); ?>" class="page-title-action">
				<?php esc_html_e( 'Ajouter', 'ufsc-licence-competition' ); ?>
			</a>

			<hr class="wp-header-end">

			<?php if ( method_exists( $list_table, 'views' ) ) { $list_table->views(); } ?>

			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( Menu::PAGE_COMPETITIONS ); ?>" />
				<?php
				if ( isset( $_GET['ufsc_view'] ) ) {
					echo '<input type="hidden" name="ufsc_view" value="' . esc_attr( sanitize_key( wp_unslash( $_GET['ufsc_view'] ) ) ) . '" />';
				}
				?>
				<?php $list_table->search_box( __( 'Rechercher', 'ufsc-licence-competition' ), 'ufsc-competitions-search' ); ?>
				<?php $list_table->display(); ?>
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
		$back_url   = add_query_arg( array( 'page' => Menu::PAGE_COMPETITIONS ), admin_url( 'admin.php' ) );

		$name       = $is_edit ? (string) ( $item->name ?? '' ) : '';
		$discipline = $is_edit ? (string) ( $item->discipline ?? '' ) : '';
		$type       = $is_edit ? (string) ( $item->type ?? '' ) : '';
		$season     = $is_edit ? (string) ( $item->season ?? '' ) : '';
		$status     = $is_edit ? (string) ( $item->status ?? '' ) : 'open';

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
										'open'   => __( 'Ouverte', 'ufsc-licence-competition' ),
										'draft'  => __( 'Brouillon', 'ufsc-licence-competition' ),
										'closed' => __( 'Clôturée', 'ufsc-licence-competition' ),
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
		$cap = class_exists( '\\UFSC_LC_Capabilities' )
			? \UFSC_LC_Capabilities::get_manage_capability()
			: 'manage_options';

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
		if ( is_object( $list_table ) && isset( $list_table->_args['plural'] ) ) {
			$plural = $list_table->_args['plural'];
		}
		$nonce_action = $plural ? 'bulk-' . $plural : 'bulk-ufsc-competitions';

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ), $nonce_action ) ) {
			return;
		}

		$ids = array();
		if ( ! empty( $_REQUEST['ids'] ) && is_array( $_REQUEST['ids'] ) ) {
			$ids = array_map( 'absint', wp_unslash( $_REQUEST['ids'] ) );
		}
		$ids = array_filter( $ids );

		if ( empty( $ids ) || ! $this->repository ) {
			return;
		}

		foreach ( $ids as $id ) {
			if ( 'trash' === $action && method_exists( $this->repository, 'trash' ) ) {
				$this->repository->trash( $id );
			}
			if ( 'restore' === $action && method_exists( $this->repository, 'restore' ) ) {
				$this->repository->restore( $id );
			}
			if ( 'delete' === $action && method_exists( $this->repository, 'delete' ) ) {
				$this->repository->delete( $id );
			}
		}

		wp_safe_redirect( add_query_arg(
			array( 'page' => Menu::PAGE_COMPETITIONS, 'ufsc_notice' => 'saved' ),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	/**
	 * AJAX: get club snapshot.
	 */
	public function ajax_get_club() {
		$cap = class_exists( '\\UFSC_LC_Capabilities' )
			? \UFSC_LC_Capabilities::get_manage_capability()
			: 'manage_options';

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

		wp_send_json_success( array(
			'id'     => (int) ( $club->id ?? 0 ),
			'nom'    => sanitize_text_field( $club->nom ?? '' ),
			'region' => sanitize_text_field( $club->region ?? '' ),
		) );
	}

	/**
	 * Handlers (admin-post)
	 */
	public function handle_save() {
		$cap = class_exists( '\\UFSC_LC_Capabilities' )
			? \UFSC_LC_Capabilities::get_manage_capability()
			: 'manage_options';

		if ( ! current_user_can( $cap ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'ufsc_competitions_save_competition' );

		if ( ! $this->repository || ! method_exists( $this->repository, 'save' ) ) {
			$this->redirect_notice( 'invalid' );
		}

		$data = array(
			'id'         => isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0,
			'name'       => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
			'discipline' => isset( $_POST['discipline'] ) ? sanitize_text_field( wp_unslash( $_POST['discipline'] ) ) : '',
			'type'       => isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '',
			'season'     => isset( $_POST['season'] ) ? sanitize_text_field( wp_unslash( $_POST['season'] ) ) : '',
			'status'     => isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '',
		);

		$saved_id = $this->repository->save( $data );

		wp_safe_redirect( add_query_arg(
			array(
				'page'        => Menu::PAGE_COMPETITIONS,
				'ufsc_notice' => $saved_id ? 'saved' : 'invalid',
			),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	public function handle_trash() {
		$cap = class_exists( '\\UFSC_LC_Capabilities' )
			? \UFSC_LC_Capabilities::get_manage_capability()
			: 'manage_options';

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
		$cap = class_exists( '\\UFSC_LC_Capabilities' )
			? \UFSC_LC_Capabilities::get_manage_capability()
			: 'manage_options';

		if ( ! current_user_can( $cap ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		check_admin_referer( 'ufsc_competitions_restore_competition_' . $id );

		if ( $id && $this->repository && method_exists( $this->repository, 'restore' ) ) {
			$this->repository->restore( $id );
		}

		wp_safe_redirect( add_query_arg(
			array(
				'page'        => Menu::PAGE_COMPETITIONS,
				'ufsc_view'   => 'trash',
				'ufsc_notice' => 'saved',
			),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	public function handle_delete() {
		$cap = class_exists( '\\UFSC_LC_Capabilities' )
			? \UFSC_LC_Capabilities::get_manage_capability()
			: 'manage_options';

		if ( ! current_user_can( $cap ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		check_admin_referer( 'ufsc_competitions_delete_competition_' . $id );

		if ( $id && $this->repository && method_exists( $this->repository, 'delete' ) ) {
			$this->repository->delete( $id );
		}

		wp_safe_redirect( add_query_arg(
			array(
				'page'        => Menu::PAGE_COMPETITIONS,
				'ufsc_view'   => 'trash',
				'ufsc_notice' => 'saved',
			),
			admin_url( 'admin.php' )
		) );
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
		wp_safe_redirect( add_query_arg(
			array(
				'page'        => Menu::PAGE_COMPETITIONS,
				'ufsc_notice' => sanitize_key( $notice ),
			),
			admin_url( 'admin.php' )
		) );
		exit;
	}
}
