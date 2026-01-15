<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UFSC_LC_Status_Page {
	const ACTION_REBUILD_INDEXES = 'ufsc_lc_rebuild_indexes';
	const ACTION_RECREATE_TABLES = 'ufsc_lc_recreate_tables';
	private $menu_registered = false;

	public function register() {
		if ( $this->menu_registered ) {
			return;
		}

		$this->menu_registered = true;
		add_action( 'admin_menu', array( $this, 'register_menu' ), 30 );
		add_action( 'admin_post_' . self::ACTION_REBUILD_INDEXES, array( $this, 'handle_rebuild_indexes' ) );
		add_action( 'admin_post_' . self::ACTION_RECREATE_TABLES, array( $this, 'handle_recreate_tables' ) );
	}

	public function register_menu() {
		remove_submenu_page( UFSC_LC_Plugin::PARENT_SLUG, 'ufsc-lc-status' );
		$hook_suffix = add_submenu_page(
			UFSC_LC_Plugin::PARENT_SLUG,
			__( 'UFSC LC — Status', 'ufsc-licence-competition' ),
			__( 'UFSC LC — Status', 'ufsc-licence-competition' ),
			UFSC_LC_Plugin::CAPABILITY,
			'ufsc-lc-status',
			array( $this, 'render_page' )
		);

		if ( ! $hook_suffix ) {
			UFSC_LC_Logger::log( 'Status page not registered.' );
		} else {
			UFSC_LC_Admin_Assets::register_page( $hook_suffix );
		}
	}

	public function render_page() {
		if ( ! UFSC_LC_Capabilities::user_can_manage() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ) );
		}

		global $wpdb;

		$licences_table  = $wpdb->prefix . 'ufsc_licences';
		$clubs_table     = $wpdb->prefix . 'ufsc_clubs';
		$docs_table      = $wpdb->prefix . 'ufsc_licence_documents';
		$aliases_table   = $wpdb->prefix . 'ufsc_asptt_aliases';

		$tables = array(
			'licences'  => $this->table_exists( $licences_table ),
			'clubs'     => $this->table_exists( $clubs_table ),
			'documents' => $this->table_exists( $docs_table ),
			'aliases'   => $this->table_exists( $aliases_table ),
		);

		$counts = array(
			'licences'  => $tables['licences'] ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$licences_table}" ) : null,
			'clubs'     => $tables['clubs'] ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$clubs_table}" ) : null,
			'documents' => $tables['documents'] ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$docs_table}" ) : null,
		);

		$last_import = null;
		if ( $tables['documents'] ) {
			$last_import = $wpdb->get_var( "SELECT MAX(updated_at) FROM {$docs_table}" );
		}

		$legacy_enabled = (bool) get_option( UFSC_LC_Plugin::LEGACY_OPTION, false );
		$legacy_label   = $legacy_enabled ? __( 'ON', 'ufsc-licence-competition' ) : __( 'OFF', 'ufsc-licence-competition' );
		$status_ok      = __( 'OK', 'ufsc-licence-competition' );
		$status_ko      = __( 'KO', 'ufsc-licence-competition' );

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'UFSC LC — Status', 'ufsc-licence-competition' ); ?></h1>
			<table class="widefat striped" style="max-width: 900px;">
				<tbody>
					<tr>
						<th><?php esc_html_e( 'Version plugin (DB_VERSION)', 'ufsc-licence-competition' ); ?></th>
						<td><?php echo esc_html( UFSC_LC_Plugin::DB_VERSION ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Version DB installée', 'ufsc-licence-competition' ); ?></th>
						<td><?php echo esc_html( get_option( UFSC_LC_Plugin::DB_VERSION_OPTION, '0' ) ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Legacy mode', 'ufsc-licence-competition' ); ?></th>
						<td><?php echo esc_html( $legacy_label ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Table UFSC licences', 'ufsc-licence-competition' ); ?></th>
						<td><?php echo esc_html( $tables['licences'] ? $status_ok : $status_ko ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Table UFSC clubs', 'ufsc-licence-competition' ); ?></th>
						<td><?php echo esc_html( $tables['clubs'] ? $status_ok : $status_ko ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Table documents add-on', 'ufsc-licence-competition' ); ?></th>
						<td><?php echo esc_html( $tables['documents'] ? $status_ok : $status_ko ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Table aliases ASPTT', 'ufsc-licence-competition' ); ?></th>
						<td><?php echo esc_html( $tables['aliases'] ? $status_ok : $status_ko ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Compteur licences', 'ufsc-licence-competition' ); ?></th>
						<td><?php echo isset( $counts['licences'] ) ? esc_html( (string) $counts['licences'] ) : esc_html__( 'N/A', 'ufsc-licence-competition' ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Compteur clubs', 'ufsc-licence-competition' ); ?></th>
						<td><?php echo isset( $counts['clubs'] ) ? esc_html( (string) $counts['clubs'] ) : esc_html__( 'N/A', 'ufsc-licence-competition' ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Compteur documents', 'ufsc-licence-competition' ); ?></th>
						<td><?php echo isset( $counts['documents'] ) ? esc_html( (string) $counts['documents'] ) : esc_html__( 'N/A', 'ufsc-licence-competition' ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Dernière importation documents', 'ufsc-licence-competition' ); ?></th>
						<td><?php echo $last_import ? esc_html( $last_import ) : esc_html__( 'N/A', 'ufsc-licence-competition' ); ?></td>
					</tr>
				</tbody>
			</table>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top: 20px;">
				<?php wp_nonce_field( self::ACTION_REBUILD_INDEXES ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_REBUILD_INDEXES ); ?>">
				<?php submit_button( __( 'Rebuild indexes', 'ufsc-licence-competition' ), 'secondary', 'submit', false ); ?>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top: 10px;">
				<?php wp_nonce_field( self::ACTION_RECREATE_TABLES ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_RECREATE_TABLES ); ?>">
				<?php submit_button( __( 'Recreate tables', 'ufsc-licence-competition' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	public function handle_rebuild_indexes() {
		if ( ! UFSC_LC_Capabilities::user_can_manage() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::ACTION_REBUILD_INDEXES ) ) {
			wp_die( esc_html__( 'Requête invalide.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		$indexes = new UFSC_LC_Licence_Indexes();
		$indexes->ensure_indexes();

		wp_safe_redirect( $this->get_status_url() );
		exit;
	}

	public function handle_recreate_tables() {
		if ( ! UFSC_LC_Capabilities::user_can_manage() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::ACTION_RECREATE_TABLES ) ) {
			wp_die( esc_html__( 'Requête invalide.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		$plugin = UFSC_LC_Plugin::init( UFSC_LC_FILE );
		$plugin->recreate_tables_and_indexes();

		wp_safe_redirect( $this->get_status_url() );
		exit;
	}

	private function table_exists( $table ) {
		global $wpdb;

		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	private function get_status_url() {
		return add_query_arg(
			array(
				'page' => 'ufsc-lc-status',
			),
			admin_url( 'admin.php' )
		);
	}
}
