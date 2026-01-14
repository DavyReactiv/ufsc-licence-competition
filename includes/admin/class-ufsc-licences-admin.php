<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-ufsc-licences-list-table.php';

class UFSC_LC_Licences_Admin {
	public function register() {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 30 );
		add_action( 'admin_post_ufsc_lc_export_licences_csv', array( $this, 'handle_export_csv' ) );
	}

	public function register_menu() {
		remove_submenu_page( 'ufsc-licence-documents', 'ufsc-lc-status' );

		add_submenu_page(
			'ufsc-licence-documents',
			__( 'Licences', 'ufsc-licence-competition' ),
			__( 'Licences', 'ufsc-licence-competition' ),
			'manage_options',
			'ufsc-lc-licences',
			array( $this, 'render_page' )
		);

		add_submenu_page(
			'ufsc-licence-documents',
			__( 'UFSC LC — Status', 'ufsc-licence-competition' ),
			__( 'UFSC LC — Status', 'ufsc-licence-competition' ),
			'manage_options',
			'ufsc-lc-status',
			array( $this, 'render_status_page' )
		);

		$this->fix_submenu_links();
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

	public function render_status_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		global $wpdb;

		$tables = array(
			'ufsc_licences'          => $this->table_exists( 'ufsc_licences' ),
			'ufsc_clubs'             => $this->table_exists( 'ufsc_clubs' ),
			'ufsc_licence_documents' => $this->table_exists( 'ufsc_licence_documents' ),
		);

		$documents_count = null;
		if ( $tables['ufsc_licence_documents'] ) {
			$documents_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}ufsc_licence_documents" );
		}

		$missing_tables = array();
		foreach ( $tables as $table => $exists ) {
			if ( ! $exists ) {
				$missing_tables[] = $table;
			}
		}

		$plugin_active  = class_exists( 'UFSC_LC_Plugin' );
		$legacy_enabled = false;
		$legacy_label   = __( 'OFF', 'ufsc-licence-competition' );
		$db_version     = '0';
		if ( $plugin_active ) {
			$legacy_enabled = (bool) get_option( UFSC_LC_Plugin::LEGACY_OPTION, false );
			$legacy_label   = $legacy_enabled ? __( 'ON', 'ufsc-licence-competition' ) : __( 'OFF', 'ufsc-licence-competition' );
			$db_version     = (string) get_option( UFSC_LC_Plugin::DB_VERSION_OPTION, '0' );
		}
		$plugin_label   = $plugin_active ? '✅' : '❌';

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'UFSC LC — Status', 'ufsc-licence-competition' ); ?></h1>
			<?php if ( ! empty( $missing_tables ) ) : ?>
				<div class="notice notice-error">
					<p>
						<?php
						echo esc_html(
							sprintf(
								__( 'Dépendances manquantes : %s', 'ufsc-licence-competition' ),
								implode( ', ', $missing_tables )
							)
						);
						?>
					</p>
				</div>
			<?php endif; ?>
			<table class="widefat striped" style="max-width: 900px;">
				<tbody>
					<tr>
						<th><?php esc_html_e( 'Plugin add-on actif', 'ufsc-licence-competition' ); ?></th>
						<td><?php echo esc_html( $plugin_label ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Version DB installée', 'ufsc-licence-competition' ); ?></th>
						<td><?php echo esc_html( $db_version ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Legacy mode', 'ufsc-licence-competition' ); ?></th>
						<td><?php echo esc_html( $legacy_label ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Table UFSC licences', 'ufsc-licence-competition' ); ?></th>
						<td><?php echo esc_html( $tables['ufsc_licences'] ? '✅' : '❌' ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Table UFSC clubs', 'ufsc-licence-competition' ); ?></th>
						<td><?php echo esc_html( $tables['ufsc_clubs'] ? '✅' : '❌' ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Table documents add-on', 'ufsc-licence-competition' ); ?></th>
						<td><?php echo esc_html( $tables['ufsc_licence_documents'] ? '✅' : '❌' ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Nombre documents', 'ufsc-licence-competition' ); ?></th>
						<td><?php echo null !== $documents_count ? esc_html( (string) $documents_count ) : esc_html__( 'N/A', 'ufsc-licence-competition' ); ?></td>
					</tr>
				</tbody>
			</table>
		</div>
		<?php
	}

	private function table_exists( $short_name ) {
		global $wpdb;

		$table_name = $wpdb->prefix . $short_name;

		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );
	}

	private function fix_submenu_links() {
		global $submenu;

		if ( empty( $submenu['ufsc-licence-documents'] ) || ! is_array( $submenu['ufsc-licence-documents'] ) ) {
			return;
		}

		$slug_map = array(
			'ufsc-lc-status'       => 'admin.php?page=ufsc-lc-status',
			'ufsc-lc-licences'     => 'admin.php?page=ufsc-lc-licences',
			'ufsc-lc-asptt-import' => 'admin.php?page=ufsc-lc-asptt-import',
		);

		foreach ( $submenu['ufsc-licence-documents'] as $index => $item ) {
			if ( ! is_array( $item ) || ! isset( $item[2] ) ) {
				continue;
			}

			if ( isset( $slug_map[ $item[2] ] ) ) {
				$submenu['ufsc-licence-documents'][ $index ][2] = $slug_map[ $item[2] ];
			}
		}
	}
}
