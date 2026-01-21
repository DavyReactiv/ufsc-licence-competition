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
	 * Optional: used by Menu::register() to register actions early.
	 */
	public function register_actions() {
		// Keep for future admin_post handlers if needed.
		// Example:
		// add_action('admin_post_ufsc_competition_save', array($this,'handle_save'));
	}

	/**
	 * Admin page callback (public).
	 * WordPress submenu MUST call a public method.
	 */
	public function render() {
		if ( ! current_user_can( \UFSC_LC_Capabilities::get_manage_capability() ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ) );
		}

		// If later you add actions (add/edit), you can route here:
		// $action = isset($_GET['ufsc_action']) ? sanitize_key(wp_unslash($_GET['ufsc_action'])) : '';
		// switch ($action) { case 'add': $this->render_form(); return; ... }

		$this->render_list();
	}

	/**
	 * AJAX: fetch club info (kept minimal, secure).
	 */
	public function ajax_get_club() {
		if ( ! current_user_can( \UFSC_LC_Capabilities::get_manage_capability() ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}

		$club_id = isset( $_GET['club_id'] ) ? absint( $_GET['club_id'] ) : 0;
		if ( ! $club_id ) {
			wp_send_json_error( array( 'message' => 'missing_club_id' ), 400 );
		}

		if ( ! $this->club_repository || ! method_exists( $this->club_repository, 'get' ) ) {
			wp_send_json_error( array( 'message' => 'club_repository_unavailable' ), 500 );
		}

		try {
			$club = $this->club_repository->get( $club_id );
			if ( ! $club ) {
				wp_send_json_error( array( 'message' => 'not_found' ), 404 );
			}
			wp_send_json_success( array( 'club' => $club ) );
		} catch ( \Throwable $e ) {
			wp_send_json_error( array( 'message' => 'error', 'detail' => $e->getMessage() ), 500 );
		}
	}

	/**
	 * List view (internal). Can stay private.
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

		// Bulk actions (defensive)
		if ( method_exists( $this, 'maybe_handle_bulk_actions' ) ) {
			// If you later re-add that method in this class, it will be used.
			$this->maybe_handle_bulk_actions( $list_table );
		} elseif ( method_exists( $list_table, 'process_bulk_action' ) ) {
			// WP_List_Table convention
			$list_table->process_bulk_action();
		}

		$list_table->prepare_items();

		// Debug block: show filters + counts when WP_DEBUG
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$debug = method_exists( $list_table, 'get_debug_info' ) ? $list_table->get_debug_info() : null;
			if ( is_array( $debug ) ) {
				echo '<div class="notice notice-info"><pre style="white-space:pre-wrap;">';
				echo esc_html(
					sprintf(
						"Debug Competitions list:\nFilters: %s\nTotal (count): %s\nReturned (list): %s\n",
						wp_json_encode( $debug['filters'], JSON_PRETTY_PRINT ),
						isset( $debug['total_items'] ) ? (int) $debug['total_items'] : 0,
						isset( $debug['returned'] ) ? (int) $debug['returned'] : 0
					)
				);
				echo '</pre></div>';
			}
		}
		?>
		<div class="wrap ufsc-competitions-admin">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Compétitions', 'ufsc-licence-competition' ); ?></h1>

			<a href="<?php echo esc_url( add_query_arg( array( 'page' => Menu::PAGE_COMPETITIONS, 'ufsc_action' => 'add' ), admin_url( 'admin.php' ) ) ); ?>" class="page-title-action">
				<?php esc_html_e( 'Ajouter', 'ufsc-licence-competition' ); ?>
			</a>

			<hr class="wp-header-end">

			<?php $list_table->views(); ?>

			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( Menu::PAGE_COMPETITIONS ); ?>" />
				<?php
				// keep view
				if ( isset( $_GET['ufsc_view'] ) ) {
					echo '<input type="hidden" name="ufsc_view" value="' . esc_attr( sanitize_key( wp_unslash( $_GET['ufsc_view'] ) ) ) . '" />';
				}
				?>
				<?php $list_table->search_box( __( 'Rechercher', 'ufsc-licence-competition' ), 'ufsc-competitions-search' ); ?>
				<div class="ufsc-competitions-table-wrap">
					<?php $list_table->display(); ?>
				</div>
			</form>
		</div>
		<?php
	}
}
