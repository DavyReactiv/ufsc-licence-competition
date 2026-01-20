<?php

namespace UFSC\Competitions\Admin\Pages;

use UFSC\Competitions\Capabilities;
use UFSC\Competitions\Admin\Menu;
use UFSC\Competitions\Repositories\CompetitionRepository;
use UFSC\Competitions\Repositories\ClubRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Competitions_Page {
	private $repository;
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

	// ... other methods remain unchanged ...

	private function render_list() {
		if ( ! class_exists( '\\UFSC\\Competitions\\Admin\\Tables\\Competitions_Table' ) ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Compétitions', 'ufsc-licence-competition' ) . '</h1>';
			echo '<div class="notice notice-warning"><p>' .
				esc_html__( 'Tableau introuvable : la classe Competitions_Table est manquante.', 'ufsc-licence-competition' ) .
			'</p></div></div>';
			return;
		}

		$list_table = new \UFSC\Competitions\Admin\Tables\Competitions_Table();

		// Handle bulk actions defensively (safe)
		$this->maybe_handle_bulk_actions( $list_table );

		$list_table->prepare_items();

		// Debug block: show filters + counts when WP_DEBUG
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$debug = method_exists( $list_table, 'get_debug_info' ) ? $list_table->get_debug_info() : null;
			if ( is_array( $debug ) ) {
				echo '<div class="notice notice-info"><pre style="white-space:pre-wrap;">';
				echo esc_html( sprintf( "Debug Competitions list:\nFilters: %s\nTotal (count): %s\nReturned (list): %s\n",
					wp_json_encode( $debug['filters'], JSON_PRETTY_PRINT ),
					(int) $debug['total_items'],
					(int) $debug['returned']
				) );
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
