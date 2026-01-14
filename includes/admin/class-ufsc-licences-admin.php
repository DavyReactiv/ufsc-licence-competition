<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-ufsc-licences-list-table.php';

class UFSC_LC_Licences_Admin {
	public function register() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_ufsc_lc_export_licences_csv', array( $this, 'handle_export_csv' ) );
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

		$export_url = wp_nonce_url(
			add_query_arg(
				$list_table->get_filter_query_args(),
				admin_url( 'admin-post.php?action=ufsc_lc_export_licences_csv' )
			),
			'ufsc_lc_export_licences_csv'
		);

		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Licences', 'ufsc-licence-competition' ); ?></h1>
			<a href="<?php echo esc_url( $export_url ); ?>" class="page-title-action"><?php esc_html_e( 'Exporter CSV (filtres actifs)', 'ufsc-licence-competition' ); ?></a>
			<hr class="wp-header-end">
			<?php foreach ( $list_table->get_notices() as $notice ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?>"><p><?php echo esc_html( $notice['message'] ); ?></p></div>
			<?php endforeach; ?>
			<?php $list_table->views(); ?>
			<form method="get">
				<input type="hidden" name="page" value="ufsc-licences" />
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
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ) );
		}

		check_admin_referer( 'ufsc_lc_export_licences_csv' );

		$list_table = new UFSC_LC_Competition_Licences_List_Table();
		$filters = $list_table->get_sanitized_filters();
		$rows = $list_table->get_export_rows( $filters );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="licences-export.csv"' );

		$output = fopen( 'php://output', 'w' );
		if ( false === $output ) {
			wp_die( esc_html__( 'Export impossible.', 'ufsc-licence-competition' ) );
		}

		fputcsv(
			$output,
			array(
				'club',
				'nom',
				'prenom',
				'dob',
				'statut',
				'categorie',
				'competition',
				'n_asptt',
				'date_asptt',
				'has_pdf',
			)
		);

		foreach ( $rows as $row ) {
			fputcsv(
				$output,
				array(
					$row['club_name'],
					$row['nom_licence'],
					$row['prenom'],
					$row['date_naissance'],
					$row['statut'],
					$row['categorie'],
					$row['competition'],
					$row['asptt_number'],
					$row['date_asptt'],
					$row['has_pdf'],
				)
			);
		}

		fclose( $output );
		exit;
	}
}
