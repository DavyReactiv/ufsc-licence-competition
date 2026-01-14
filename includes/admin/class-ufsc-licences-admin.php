<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-ufsc-licences-list-table.php';

class UFSC_LC_Licences_Admin {
	public function register() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
	}

	public function register_menu() {
		add_submenu_page(
			'ufsc-licence-documents',
			__( 'Licences', 'ufsc-licence-competition' ),
			__( 'Licences', 'ufsc-licence-competition' ),
			'manage_options',
			'ufsc-licences',
			array( $this, 'render_page' )
		);
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$list_table = new UFSC_LC_Competition_Licences_List_Table();
		$list_table->prepare_items();

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Licences', 'ufsc-licence-competition' ); ?></h1>
			<form method="get">
				<input type="hidden" name="page" value="ufsc-licences" />
				<?php $list_table->search_box( __( 'Rechercher', 'ufsc-licence-competition' ), 'ufsc-licences-search' ); ?>
				<?php $list_table->display(); ?>
			</form>
		</div>
		<?php
	}
}
