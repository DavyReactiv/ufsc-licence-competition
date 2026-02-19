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
			UFSC_LC_Capabilities::get_manage_capability(),
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

		$screen_id = '';
		if ( function_exists( 'get_current_screen' ) ) {
			$screen    = get_current_screen();
			$screen_id = $screen ? (string) $screen->id : '';
			if ( $screen && $screen_id !== '' && false === strpos( $screen_id, 'ufsc-lc-status' ) ) {
				return;
			}
		}

		global $wpdb;

		$licences_table = $wpdb->prefix . 'ufsc_licences';
		$clubs_table    = $wpdb->prefix . 'ufsc_clubs';
		$docs_table     = $wpdb->prefix . 'ufsc_licence_documents';
		$aliases_table  = $wpdb->prefix . 'ufsc_asptt_aliases';
		$season_column  = $this->get_season_column( $licences_table );
		$scope_region   = class_exists( 'UFSC_LC_Scope' ) ? UFSC_LC_Scope::get_user_scope_region() : null;
		$scope_enabled  = is_string( $scope_region ) && '' !== $scope_region;

		$cache_key = '';
		if ( function_exists( 'ufsc_lc_build_cache_key' ) && function_exists( 'ufsc_lc_get_cache_version' ) ) {
			$cache_key = ufsc_lc_build_cache_key(
				'ufsc_lc_status',
				array(
					'version' => ufsc_lc_get_cache_version( 'status', 0 ),
					'scope'   => $scope_enabled ? $scope_region : 'all',
				)
			);
		} elseif ( function_exists( 'ufsc_lc_get_cache_version' ) ) {
			// Fallback legacy (still versioned).
			$cache_key = 'ufsc_lc_status_' . ufsc_lc_get_cache_version( 'status', 0 );
		}

		$cache_hit = false;
		$cached    = $cache_key ? get_transient( $cache_key ) : false;

		if ( is_array( $cached ) ) {
			$cache_hit    = true;
			$tables        = $cached['tables'];
			$counts        = $cached['counts'];
			$last_import   = $cached['last_import'];
			$season_counts = $cached['season_counts'];
		} else {
			$tables = array(
				'licences'  => $this->table_exists( $licences_table ),
				'clubs'     => $this->table_exists( $clubs_table ),
				'documents' => $this->table_exists( $docs_table ),
				'aliases'   => $this->table_exists( $aliases_table ),
			);

			$scope_join  = '';
			$scope_where = '';
			$scope_args  = array();
			$scope_hard_denied = false;
			if ( $scope_enabled ) {
				if ( ! $tables['clubs'] || ! $this->has_column( $clubs_table, 'region' ) ) {
					$scope_hard_denied = true;
				} else {
					$scope_where = ' WHERE c.region = %s';
					$scope_args[] = $scope_region;
				}
			}

			$counts = array(
				'licences'  => null,
				'clubs'     => null,
				'documents' => null,
			);

			if ( $scope_hard_denied ) {
				$counts = array( 'licences' => 0, 'clubs' => 0, 'documents' => 0 );
			}

			if ( $tables['licences'] && ! $scope_hard_denied ) {
				if ( '' !== $scope_where ) {
					$counts['licences'] = (int) $wpdb->get_var(
						$wpdb->prepare( "SELECT COUNT(*) FROM {$licences_table} l INNER JOIN {$clubs_table} c ON c.id = l.club_id{$scope_where}", $scope_args )
					);
				} else {
					$counts['licences'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$licences_table}" );
				}
			}

			if ( $tables['clubs'] && ! $scope_hard_denied ) {
				if ( '' !== $scope_where ) {
					$counts['clubs'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$clubs_table} c{$scope_where}", $scope_args ) );
				} else {
					$counts['clubs'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$clubs_table}" );
				}
			}

			if ( $tables['documents'] && ! $scope_hard_denied ) {
				if ( '' !== $scope_where && $tables['licences'] ) {
					$counts['documents'] = (int) $wpdb->get_var(
						$wpdb->prepare( "SELECT COUNT(*) FROM {$docs_table} d INNER JOIN {$licences_table} l ON l.id = d.licence_id INNER JOIN {$clubs_table} c ON c.id = l.club_id{$scope_where}", $scope_args )
					);
				} else {
					$counts['documents'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$docs_table}" );
				}
			}

			$last_import = null;
			if ( $tables['documents'] && ! $scope_hard_denied ) {
				$import_column = $this->has_column( $docs_table, 'imported_at' ) ? 'imported_at' : 'updated_at';
				if ( '' !== $scope_where && $tables['licences'] ) {
					$last_import = $wpdb->get_var(
						$wpdb->prepare( "SELECT MAX(d.{$import_column}) FROM {$docs_table} d INNER JOIN {$licences_table} l ON l.id = d.licence_id INNER JOIN {$clubs_table} c ON c.id = l.club_id{$scope_where}", $scope_args )
					);
				} else {
					$last_import = $wpdb->get_var( "SELECT MAX({$import_column}) FROM {$docs_table}" );
				}
			}

			$season_counts = array();
			if ( $tables['licences'] && $season_column && ! $scope_hard_denied ) {
				$season_sql = "SELECT l.{$season_column} AS saison, COUNT(*) AS total FROM {$licences_table} l";
				if ( '' !== $scope_where ) {
					$season_sql .= " INNER JOIN {$clubs_table} c ON c.id = l.club_id";
				}
				$season_sql .= " WHERE l.{$season_column} IS NOT NULL AND l.{$season_column} != ''";
				if ( '' !== $scope_where ) {
					$season_sql .= " AND c.region = %s";
				}
				$season_sql .= " GROUP BY l.{$season_column} ORDER BY l.{$season_column} DESC";
				$results = '' !== $scope_where ? $wpdb->get_results( $wpdb->prepare( $season_sql, $scope_args ) ) : $wpdb->get_results( $season_sql );

				foreach ( $results as $row ) {
					$season_counts[] = array(
						'saison' => (string) $row->saison,
						'total'  => (int) $row->total,
					);
				}
			}

			if ( $cache_key ) {
				set_transient(
					$cache_key,
					array(
						'tables'        => $tables,
						'counts'        => $counts,
						'last_import'   => $last_import,
						'season_counts' => $season_counts,
					),
					MINUTE_IN_SECONDS
				);
			}
		}

		$legacy_enabled      = (bool) get_option( UFSC_LC_Plugin::LEGACY_OPTION, false );
		$legacy_label        = $legacy_enabled ? __( 'ON', 'ufsc-licence-competition' ) : __( 'OFF', 'ufsc-licence-competition' );
		$status_ok           = __( 'OK', 'ufsc-licence-competition' );
		$status_ko           = __( 'KO', 'ufsc-licence-competition' );
		$last_import_display = '';

		if ( $last_import ) {
			$formatted            = mysql2date( 'd/m/Y H:i', $last_import );
			$last_import_display  = '' !== $formatted ? $formatted : $last_import;
		}

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'UFSC LC — Status', 'ufsc-licence-competition' ); ?></h1>
			<?php ufsc_lc_render_scope_badge(); ?>
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
						<th><?php esc_html_e( 'Dernier import', 'ufsc-licence-competition' ); ?></th>
						<td><?php echo $last_import_display ? esc_html( $last_import_display ) : esc_html__( 'N/A', 'ufsc-licence-competition' ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Comptes licences par saison', 'ufsc-licence-competition' ); ?></th>
						<td>
							<?php if ( empty( $season_counts ) ) : ?>
								<?php echo esc_html__( 'N/A', 'ufsc-licence-competition' ); ?>
							<?php else : ?>
								<ul style="margin: 0; padding-left: 18px;">
									<?php foreach ( $season_counts as $season ) : ?>
										<li>
											<?php
											echo esc_html(
												sprintf(
													'%s : %d',
													$season['saison'],
													$season['total']
												)
											);
											?>
										</li>
									<?php endforeach; ?>
								</ul>
							<?php endif; ?>
						</td>
					</tr>
				</tbody>
			</table>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top: 20px;" class="ufsc-lc-confirm" data-confirm="<?php echo esc_attr__( 'Reconstruire les index ? Cette action peut être longue.', 'ufsc-licence-competition' ); ?>">
				<?php wp_nonce_field( self::ACTION_REBUILD_INDEXES ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_REBUILD_INDEXES ); ?>">
				<?php submit_button( __( 'Rebuild indexes', 'ufsc-licence-competition' ), 'secondary', 'submit', false ); ?>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top: 10px;" class="ufsc-lc-confirm" data-confirm="<?php echo esc_attr__( 'Recréer les tables ? Cette action peut modifier la base de données.', 'ufsc-licence-competition' ); ?>">
				<?php wp_nonce_field( self::ACTION_RECREATE_TABLES ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_RECREATE_TABLES ); ?>">
				<?php submit_button( __( 'Recreate tables', 'ufsc-licence-competition' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<?php

		if ( function_exists( 'ufsc_lc_log_query_count' ) ) {
			ufsc_lc_log_query_count(
				'admin: status page',
				array(
					'screen' => $screen_id,
					'cache'  => $cache_hit ? 'hit' : 'miss',
				)
			);
		}
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

	private function has_column( $table, $column ) {
		global $wpdb;

		$column = sanitize_key( $column );
		$exists = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $column ) );

		return ! empty( $exists );
	}

	private function get_season_column( $table ) {
		$candidates = array( 'saison', 'season' );
		foreach ( $candidates as $candidate ) {
			if ( $this->has_column( $table, $candidate ) ) {
				return $candidate;
			}
		}

		return '';
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
