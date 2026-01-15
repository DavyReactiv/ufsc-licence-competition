<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-ufsc-licences-list-table.php';

class UFSC_LC_Licences_Admin {
	public function register() {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 30 );
		add_action( 'admin_post_ufsc_lc_export_csv', array( $this, 'handle_export_csv' ) );
		add_action( 'admin_post_ufsc_lc_export_licences_csv', array( $this, 'handle_export_csv' ) );
	}

	public function register_menu() {
		$hook_suffix = add_submenu_page(
			UFSC_LC_Plugin::PARENT_SLUG,
			__( 'Licences', 'ufsc-licence-competition' ),
			__( 'Licences', 'ufsc-licence-competition' ),
			UFSC_LC_Plugin::CAPABILITY,
			'ufsc-lc-licences',
			array( $this, 'render_page' )
		);
		UFSC_LC_Admin_Assets::register_page( $hook_suffix );
		// Status submenu is registered in UFSC_LC_Status_Page::register_menu().
	}

	public function render_page() {
		if ( ! UFSC_LC_Capabilities::user_can_manage() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ) );
		}

		$list_table = new UFSC_LC_Competition_Licences_List_Table();
		$list_table->prepare_items();

		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Licences', 'ufsc-licence-competition' ); ?></h1>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ufsc-lc-export-form" style="display:inline-block; margin-left: 8px;">
				<?php wp_nonce_field( 'ufsc_lc_export_csv', 'ufsc_lc_nonce' ); ?>
				<input type="hidden" name="action" value="ufsc_lc_export_csv">
				<?php foreach ( $list_table->get_filter_query_args() as $key => $value ) : ?>
					<input type="hidden" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $value ); ?>">
				<?php endforeach; ?>
				<?php submit_button( __( 'Exporter CSV (filtres actifs)', 'ufsc-licence-competition' ), 'secondary', 'submit', false ); ?>
			</form>
			<hr class="wp-header-end">
			<?php foreach ( $list_table->get_notices() as $notice ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?>"><p><?php echo esc_html( $notice['message'] ); ?></p></div>
			<?php endforeach; ?>
			<?php $list_table->views(); ?>
			<form method="get">
				<input type="hidden" name="page" value="ufsc-lc-licences" />
				<?php $filters = $list_table->get_sanitized_filters(); ?>
				<?php if ( 'all' !== $filters['tab'] ) : ?>
					<input type="hidden" name="ufsc_lc_tab" value="<?php echo esc_attr( $filters['tab'] ); ?>" />
				<?php endif; ?>
				<?php $list_table->search_box( __( 'Rechercher', 'ufsc-licence-competition' ), 'ufsc-licences-search' ); ?>
				<?php $list_table->display(); ?>
			</form>
		</div>
		<?php
	}

	public function handle_export_csv() {
		if ( ! UFSC_LC_Capabilities::user_can_manage() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ) );
		}

		if ( isset( $_REQUEST['ufsc_lc_nonce'] ) ) {
			check_admin_referer( 'ufsc_lc_export_csv', 'ufsc_lc_nonce' );
		} else {
			check_admin_referer( 'ufsc_lc_export_licences_csv' );
		}
		$list_table = new UFSC_LC_Competition_Licences_List_Table();
		$filters = $list_table->get_sanitized_filters();
		$exporter = new UFSC_LC_Exporter();
		$exporter->stream_licences_csv( $filters );
	}

}
