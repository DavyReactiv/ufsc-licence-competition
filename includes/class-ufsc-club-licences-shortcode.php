<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UFSC_LC_Club_Licences_Shortcode {
	const SOURCE = 'ASPTT';

	private $legacy_enabled = false;
	private $licence_columns = null;
	private $documents_columns = null;

	public function __construct( $legacy_enabled = false ) {
		$this->legacy_enabled = (bool) $legacy_enabled;
	}

	public function register() {
		$this->register_shortcode( 'ufsc_lc_club_licences_asptt' );
		$this->register_shortcode( 'ufsc_lc_licences' );
		add_action( 'admin_post_ufsc_lc_download_asptt_pdf', array( $this, 'handle_download' ) );
		add_action( 'admin_post_ufsc_lc_pdf', array( $this, 'handle_pdf_proxy' ) );
		add_action( 'admin_post_nopriv_ufsc_lc_pdf', array( $this, 'handle_pdf_proxy' ) );
		add_action( 'admin_post_ufsc_lc_pdf_view', array( $this, 'handle_pdf_view' ) );
		add_action( 'admin_post_ufsc_lc_pdf_download', array( $this, 'handle_pdf_download' ) );

		if ( $this->legacy_enabled ) {
			$this->register_shortcode( 'ufsc_club_licences_asptt' );
			$this->register_shortcode( 'ufsc_licences' );
			add_action( 'admin_post_ufsc_download_asptt_pdf', array( $this, 'handle_download' ) );
		}
	}

	private function register_shortcode( $tag ) {
		if ( ! shortcode_exists( $tag ) ) {
			add_shortcode( $tag, array( $this, 'render_shortcode' ) );
		}
	}

	public function render_shortcode() {
		if ( ! is_user_logged_in() ) {
			return esc_html__( 'Accès réservé.', 'ufsc-licence-competition' );
		}

		$required_capability = class_exists( 'UFSC_LC_Settings_Page' ) ? UFSC_LC_Settings_Page::get_club_access_capability() : '';
		if ( $required_capability && ! current_user_can( $required_capability ) ) {
			return esc_html__( 'Accès réservé.', 'ufsc-licence-competition' );
		}

		$club_id = $this->get_current_user_club_id();
		if ( ! $club_id ) {
			return esc_html__( 'Aucun club associé à ce compte.', 'ufsc-licence-competition' );
		}

		wp_enqueue_style( 'dashicons' );

		$filters = $this->get_filters();

		$stats   = $this->get_stats( $club_id, $filters );
		$results = $this->get_licences( $club_id, $filters );
		$items   = $results['items'];
		$total_pages = isset( $results['total_pages'] ) ? (int) $results['total_pages'] : 1;
		$filters['paged'] = isset( $results['current_page'] ) ? (int) $results['current_page'] : $filters['paged'];

		ob_start();
		?>
		<style>
			.ufsc-licence-stats {
				display: grid;
				grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
				gap: 12px;
				margin-bottom: 16px;
			}
			.ufsc-licence-stat {
				background: #f6f7f7;
				border-radius: 8px;
				padding: 12px 16px;
				display: flex;
				flex-direction: column;
				gap: 4px;
			}
			.ufsc-licence-stat span {
				color: #50575e;
				font-size: 13px;
			}
			.ufsc-licence-stat strong {
				font-size: 20px;
			}
			.ufsc-licence-filters {
				display: flex;
				flex-wrap: wrap;
				gap: 12px;
				margin-bottom: 16px;
				align-items: flex-end;
			}
			.ufsc-licence-filters label {
				font-weight: 600;
				display: block;
				margin-bottom: 4px;
			}
			.ufsc-licence-table-wrapper {
				overflow-x: auto;
			}
			.ufsc-licence-table {
				width: 100%;
				border-collapse: collapse;
			}
			.ufsc-licence-table th,
			.ufsc-licence-table td {
				padding: 10px 12px;
				border-bottom: 1px solid #e0e0e0;
				text-align: left;
			}
			.ufsc-licence-table th {
				background: #f6f7f7;
			}
			.ufsc-licence-pagination {
				margin-top: 16px;
			}
			.ufsc-licence-actions {
				display: flex;
				align-items: center;
				gap: 8px;
				flex-wrap: wrap;
			}
			.ufsc-licence-actions .button {
				display: inline-flex;
				align-items: center;
				gap: 4px;
			}
			.ufsc-licence-badge {
				display: inline-flex;
				align-items: center;
				border-radius: 999px;
				background: #f0f0f1;
				color: #50575e;
				font-size: 12px;
				padding: 2px 10px;
			}
			@media (max-width: 782px) {
				.ufsc-licence-actions {
					flex-direction: column;
					align-items: flex-start;
				}
			}
		</style>
		<div class="ufsc-licence-stats">
			<div class="ufsc-licence-stat">
				<span><?php esc_html_e( 'Total licences', 'ufsc-licence-competition' ); ?></span>
				<strong><?php echo esc_html( number_format_i18n( $stats['total'] ) ); ?></strong>
			</div>
			<div class="ufsc-licence-stat">
				<span><?php esc_html_e( 'Avec PDF / Sans PDF', 'ufsc-licence-competition' ); ?></span>
				<strong><?php echo esc_html( number_format_i18n( $stats['with_pdf'] ) ); ?> / <?php echo esc_html( number_format_i18n( $stats['without_pdf'] ) ); ?></strong>
			</div>
			<div class="ufsc-licence-stat">
				<span><?php esc_html_e( 'Avec N° ASPTT / Sans N° ASPTT', 'ufsc-licence-competition' ); ?></span>
				<strong><?php echo esc_html( number_format_i18n( $stats['with_asptt'] ) ); ?> / <?php echo esc_html( number_format_i18n( $stats['without_asptt'] ) ); ?></strong>
			</div>
		</div>
		<form method="get" class="ufsc-licence-filters" id="ufsc-licence-filters">
			<div>
				<label for="ufsc-licence-search"><?php esc_html_e( 'Recherche', 'ufsc-licence-competition' ); ?></label>
				<input type="text" id="ufsc-licence-search" name="ufsc_q" value="<?php echo esc_attr( $filters['q'] ); ?>" placeholder="<?php esc_attr_e( 'Nom, prénom, N° ASPTT', 'ufsc-licence-competition' ); ?>" />
			</div>
			<div>
				<label for="ufsc-licence-status"><?php esc_html_e( 'Statut', 'ufsc-licence-competition' ); ?></label>
				<select id="ufsc-licence-status" name="ufsc_statut">
					<option value=""><?php esc_html_e( 'Tous', 'ufsc-licence-competition' ); ?></option>
					<?php foreach ( $this->get_distinct_values( 'statut', $club_id ) as $value ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $filters['statut'], $value ); ?>>
							<?php echo esc_html( $value ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>
			<div>
				<label for="ufsc-licence-category"><?php esc_html_e( 'Catégorie', 'ufsc-licence-competition' ); ?></label>
				<select id="ufsc-licence-category" name="ufsc_categorie">
					<option value=""><?php esc_html_e( 'Toutes', 'ufsc-licence-competition' ); ?></option>
					<?php foreach ( $this->get_distinct_values( 'categorie', $club_id ) as $value ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $filters['categorie'], $value ); ?>>
							<?php echo esc_html( $value ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>
			<div>
				<label for="ufsc-licence-competition"><?php esc_html_e( 'Compétition', 'ufsc-licence-competition' ); ?></label>
				<select id="ufsc-licence-competition" name="ufsc_competition">
					<option value=""><?php esc_html_e( 'Toutes', 'ufsc-licence-competition' ); ?></option>
					<?php foreach ( $this->get_distinct_values( 'competition', $club_id ) as $value ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $filters['competition'], $value ); ?>>
							<?php echo esc_html( $this->format_competition( $value ) ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>
			<div>
				<label for="ufsc-licence-pdf"><?php esc_html_e( 'PDF', 'ufsc-licence-competition' ); ?></label>
				<select id="ufsc-licence-pdf" name="ufsc_pdf">
					<option value=""><?php esc_html_e( 'Tous', 'ufsc-licence-competition' ); ?></option>
					<option value="1" <?php selected( $filters['pdf'], '1' ); ?>><?php esc_html_e( 'Avec PDF', 'ufsc-licence-competition' ); ?></option>
					<option value="0" <?php selected( $filters['pdf'], '0' ); ?>><?php esc_html_e( 'Sans PDF', 'ufsc-licence-competition' ); ?></option>
				</select>
			</div>
			<div>
				<label for="ufsc-licence-orderby"><?php esc_html_e( 'Trier par', 'ufsc-licence-competition' ); ?></label>
				<select id="ufsc-licence-orderby" name="ufsc_orderby">
					<option value="nom_licence" <?php selected( $filters['orderby'], 'nom_licence' ); ?>><?php esc_html_e( 'Nom', 'ufsc-licence-competition' ); ?></option>
					<option value="date_naissance" <?php selected( $filters['orderby'], 'date_naissance' ); ?>><?php esc_html_e( 'Date naissance', 'ufsc-licence-competition' ); ?></option>
					<option value="date_asptt" <?php selected( $filters['orderby'], 'date_asptt' ); ?>><?php esc_html_e( 'Date ASPTT', 'ufsc-licence-competition' ); ?></option>
				</select>
			</div>
			<div>
				<label for="ufsc-licence-order"><?php esc_html_e( 'Ordre', 'ufsc-licence-competition' ); ?></label>
				<select id="ufsc-licence-order" name="ufsc_order">
					<option value="asc" <?php selected( $filters['order'], 'asc' ); ?>><?php esc_html_e( 'Ascendant', 'ufsc-licence-competition' ); ?></option>
					<option value="desc" <?php selected( $filters['order'], 'desc' ); ?>><?php esc_html_e( 'Descendant', 'ufsc-licence-competition' ); ?></option>
				</select>
			</div>
			<div>
				<label for="ufsc-licence-per-page"><?php esc_html_e( 'Par page', 'ufsc-licence-competition' ); ?></label>
				<select id="ufsc-licence-per-page" name="ufsc_per_page">
					<?php foreach ( array( 10, 25, 50, 100 ) as $option ) : ?>
						<option value="<?php echo esc_attr( $option ); ?>" <?php selected( $filters['per_page'], $option ); ?>><?php echo esc_html( $option ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div>
				<button type="submit"><?php esc_html_e( 'Appliquer', 'ufsc-licence-competition' ); ?></button>
			</div>
		</form>

		<div class="ufsc-licence-table-wrapper">
			<table class="ufsc-licence-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Nom', 'ufsc-licence-competition' ); ?></th>
						<th><?php esc_html_e( 'Prénom', 'ufsc-licence-competition' ); ?></th>
						<th><?php esc_html_e( 'Date de naissance', 'ufsc-licence-competition' ); ?></th>
						<th><?php esc_html_e( 'Statut', 'ufsc-licence-competition' ); ?></th>
						<th><?php esc_html_e( 'Catégorie', 'ufsc-licence-competition' ); ?></th>
						<th><?php esc_html_e( 'N° ASPTT', 'ufsc-licence-competition' ); ?></th>
						<th><?php esc_html_e( 'PDF', 'ufsc-licence-competition' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $items ) ) : ?>
						<tr>
							<td colspan="7"><?php esc_html_e( 'Aucune licence trouvée.', 'ufsc-licence-competition' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $items as $item ) : ?>
							<?php
							$nom_affiche = ufsc_lc_get_nom_affiche( $item );
							$birthdate   = $this->format_birthdate( $item->date_naissance ?? '' );
							$category    = $this->get_category_display( $item );
							?>
							<tr>
								<td><?php echo esc_html( '' !== $nom_affiche ? $nom_affiche : __( '—', 'ufsc-licence-competition' ) ); ?></td>
								<td><?php echo esc_html( $item->prenom ); ?></td>
								<td><?php echo esc_html( '' !== $birthdate ? $birthdate : __( '—', 'ufsc-licence-competition' ) ); ?></td>
								<td><?php echo esc_html( $item->statut ); ?></td>
								<td><?php echo esc_html( '' !== $category ? $category : __( '—', 'ufsc-licence-competition' ) ); ?></td>
								<td><?php echo esc_html( $item->asptt_number ? $item->asptt_number : __( '—', 'ufsc-licence-competition' ) ); ?></td>
								<td>
									<?php if ( $item->attachment_id ) : ?>
										<div class="ufsc-licence-actions">
											<a class="button button-small" href="<?php echo esc_url( $this->get_pdf_action_url( 'view', $item->id ) ); ?>">
												<span class="dashicons dashicons-visibility" aria-hidden="true"></span>
												<?php esc_html_e( 'Voir', 'ufsc-licence-competition' ); ?>
											</a>
											<?php if ( ! class_exists( 'UFSC_LC_Settings_Page' ) || UFSC_LC_Settings_Page::is_pdf_download_allowed() ) : ?>
												<a class="button button-primary button-small" href="<?php echo esc_url( $this->get_pdf_action_url( 'download', $item->id ) ); ?>">
													<span class="dashicons dashicons-download" aria-hidden="true"></span>
													<?php esc_html_e( 'Télécharger', 'ufsc-licence-competition' ); ?>
												</a>
											<?php endif; ?>
										</div>
									<?php else : ?>
										<span class="ufsc-licence-badge"><?php esc_html_e( 'Non généré', 'ufsc-licence-competition' ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>

		<?php if ( $total_pages > 1 ) : ?>
			<div class="ufsc-licence-pagination">
				<?php echo wp_kses_post( $this->render_pagination( $filters, $total_pages ) ); ?>
			</div>
		<?php endif; ?>
		<script>
			(function() {
				var form = document.getElementById('ufsc-licence-filters');
				if (!form) {
					return;
				}
				var selects = form.querySelectorAll('select');
				selects.forEach(function(select) {
					select.addEventListener('change', function() {
						form.submit();
					});
				});
				var search = document.getElementById('ufsc-licence-search');
				if (search) {
					var timeout;
					search.addEventListener('input', function() {
						clearTimeout(timeout);
						timeout = setTimeout(function() {
							form.submit();
						}, 300);
					});
				}
			})();
		</script>
		<?php
		return ob_get_clean();
	}

	public function handle_download() {
		$this->handle_pdf_download( true );
	}

	public function handle_pdf_proxy() {
		$mode = isset( $_GET['mode'] ) ? sanitize_key( wp_unslash( $_GET['mode'] ) ) : 'view';
		$this->handle_pdf_request( 'download' === $mode ? 'attachment' : 'inline' );
	}

	public function handle_pdf_view() {
		$this->handle_pdf_request( 'inline' );
	}

	public function handle_pdf_download( $legacy = false ) {
		$this->handle_pdf_request( 'attachment', $legacy );
	}

	private function handle_pdf_request( $disposition, $legacy = false ) {
		$auth_required = ! class_exists( 'UFSC_LC_Settings_Page' ) || UFSC_LC_Settings_Page::is_pdf_auth_required();
		if ( $auth_required && ! is_user_logged_in() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 401 ) );
		}

		if ( 'attachment' === $disposition && class_exists( 'UFSC_LC_Settings_Page' ) && ! UFSC_LC_Settings_Page::is_pdf_download_allowed() ) {
			wp_die( esc_html__( 'Téléchargement désactivé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		$licence_id = isset( $_GET['licence_id'] ) ? absint( $_GET['licence_id'] ) : 0;

		if ( ! $licence_id ) {
			wp_die( esc_html__( 'Requête invalide.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		if ( $legacy ) {
			$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
			if ( ! wp_verify_nonce( $nonce, 'ufsc_lc_download_asptt_pdf_' . $licence_id ) ) {
				wp_die( esc_html__( 'Requête invalide.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
			}
		} else {
			check_admin_referer( 'ufsc_lc_pdf_' . $licence_id );
		}

		$licence = $this->get_licence_record( $licence_id );
		if ( ! $licence ) {
			wp_die( esc_html__( 'Licence introuvable.', 'ufsc-licence-competition' ) );
		}

		$require_club_match = ! class_exists( 'UFSC_LC_Settings_Page' ) || UFSC_LC_Settings_Page::is_pdf_club_match_required();
		if ( $require_club_match && ! UFSC_LC_Capabilities::user_can_manage() ) {
			$club_id = $this->get_current_user_club_id();
			if ( ! $club_id || (int) $licence->club_id !== (int) $club_id ) {
				wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
			}
		}

		$document = $this->get_document_by_licence( $licence_id );
		if ( ! $document || ! $document->attachment_id ) {
			wp_die( esc_html__( 'Document indisponible.', 'ufsc-licence-competition' ) );
		}

		$file_path = get_attached_file( (int) $document->attachment_id );
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			wp_die( esc_html__( 'Fichier introuvable.', 'ufsc-licence-competition' ) );
		}

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: ' . $disposition . '; filename="' . basename( $file_path ) . '"' );
		header( 'Content-Length: ' . filesize( $file_path ) );

		readfile( $file_path );
		exit;
	}

	private function get_filters() {
		$default_per_page = class_exists( 'UFSC_LC_Settings_Page' ) ? UFSC_LC_Settings_Page::get_licences_per_page() : 25;
		$get_value = static function ( $primary, $fallback = '' ) {
			if ( isset( $_GET[ $primary ] ) ) {
				return wp_unslash( $_GET[ $primary ] );
			}
			if ( '' !== $fallback && isset( $_GET[ $fallback ] ) ) {
				return wp_unslash( $_GET[ $fallback ] );
			}
			return '';
		};

		$filters = array(
			'q'          => sanitize_text_field( $get_value( 'ufsc_q', 'q' ) ),
			'statut'     => sanitize_text_field( $get_value( 'ufsc_statut', 'statut' ) ),
			'categorie'  => sanitize_text_field( $get_value( 'ufsc_categorie', 'categorie' ) ),
			'competition'=> sanitize_text_field( $get_value( 'ufsc_competition', 'competition' ) ),
			'pdf'        => sanitize_text_field( $get_value( 'ufsc_pdf', 'pdf' ) ),
			'orderby'    => sanitize_text_field( $get_value( 'ufsc_orderby', 'orderby' ) ),
			'order'      => strtolower( sanitize_text_field( $get_value( 'ufsc_order', 'order' ) ) ),
			'paged'      => max( 1, absint( $get_value( 'ufsc_page', 'paged' ) ) ),
			'per_page'   => absint( $get_value( 'ufsc_per_page', 'per_page' ) ),
		);

		if ( '' === $filters['orderby'] || ! in_array( $filters['orderby'], array( 'nom_licence', 'date_naissance', 'date_asptt' ), true ) ) {
			$filters['orderby'] = 'nom_licence';
		}

		$filters['order'] = 'desc' === $filters['order'] ? 'desc' : 'asc';

		$filters['per_page'] = in_array( $filters['per_page'], array( 10, 25, 50, 100 ), true ) ? $filters['per_page'] : $default_per_page;
		$filters['pdf']      = in_array( $filters['pdf'], array( '0', '1' ), true ) ? $filters['pdf'] : '';

		return $filters;
	}

	private function get_stats( $club_id, $filters ) {
		global $wpdb;

		$licences_table  = $this->get_licences_table();
		if ( ! $this->table_exists( $licences_table ) ) {
			return array(
				'total'         => 0,
				'with_pdf'      => 0,
				'without_pdf'   => 0,
				'with_asptt'    => 0,
				'without_asptt' => 0,
			);
		}

		$documents_table = $this->get_documents_table();
		$columns         = $this->get_licence_columns();
		$has_nom         = in_array( 'nom', $columns, true );
		$has_nom_licence = in_array( 'nom_licence', $columns, true );
		$has_prenom      = in_array( 'prenom', $columns, true );
		$has_statut      = in_array( 'statut', $columns, true );
		$has_competition = in_array( 'competition', $columns, true );
		$can_join_docs   = $this->table_exists( $documents_table )
			&& $this->has_document_column( 'licence_id' )
			&& $this->has_document_column( 'source' );
		$has_doc_attachment    = $can_join_docs && $this->has_document_column( 'attachment_id' );
		$has_doc_source_number = $can_join_docs && $this->has_document_column( 'source_licence_number' );

		$where  = array( 'l.club_id = %d' );
		$params = array( $club_id );

		$join_sql    = '';
		$join_params = array();

		if ( $can_join_docs ) {
			$join_sql    = "LEFT JOIN {$documents_table} d ON d.licence_id = l.id AND d.source = %s";
			$join_params = array( self::SOURCE );
		}

		if ( '' !== $filters['q'] ) {
			$like = '%' . $wpdb->esc_like( $filters['q'] ) . '%';
			$normalized = ufsc_lc_normalize_search( $filters['q'] );
			$like_normalized = $normalized ? '%' . $wpdb->esc_like( $normalized ) . '%' : '';
			$name_clauses = array();
			if ( $has_nom ) {
				$name_clauses[] = 'l.nom LIKE %s';
				$params[]       = $like;
				if ( $like_normalized ) {
					$name_clauses[] = 'LOWER(l.nom) LIKE %s';
					$params[]       = $like_normalized;
				}
			}
			if ( $has_nom_licence ) {
				$name_clauses[] = 'l.nom_licence LIKE %s';
				$params[]       = $like;
				if ( $like_normalized ) {
					$name_clauses[] = 'LOWER(l.nom_licence) LIKE %s';
					$params[]       = $like_normalized;
				}
			}
			$search_clauses = array();
			if ( ! empty( $name_clauses ) ) {
				$search_clauses[] = '(' . implode( ' OR ', $name_clauses ) . ')';
			}
			if ( $has_prenom ) {
				$search_clauses[] = 'l.prenom LIKE %s';
				$params[] = $like;
				if ( $like_normalized ) {
					$search_clauses[] = 'LOWER(l.prenom) LIKE %s';
					$params[] = $like_normalized;
				}
			}
			if ( $join_sql && $has_doc_source_number ) {
				$search_clauses[] = 'd.source_licence_number LIKE %s';
				$params[] = $like;
				if ( $like_normalized ) {
					$search_clauses[] = 'LOWER(d.source_licence_number) LIKE %s';
					$params[] = $like_normalized;
				}
			}

			if ( empty( $search_clauses ) ) {
				$where[] = '1=0';
			} else {
				$where[] = '(' . implode( ' OR ', $search_clauses ) . ')';
			}
		}

		if ( '' !== $filters['statut'] && $has_statut ) {
			$where[] = 'l.statut = %s';
			$params[] = $filters['statut'];
		}

		if ( '' !== $filters['categorie'] ) {
			$this->append_category_filter( $where, $params, $filters['categorie'] );
		}

		if ( '' !== $filters['competition'] && $has_competition ) {
			$where[] = 'l.competition = %s';
			$params[] = $filters['competition'];
		}

		if ( '' !== $filters['pdf'] ) {
			if ( $join_sql && $has_doc_attachment ) {
				$where[] = '1' === $filters['pdf'] ? 'd.attachment_id IS NOT NULL' : 'd.attachment_id IS NULL';
			} else {
				if ( '1' === $filters['pdf'] ) {
					return array(
						'total'         => 0,
						'with_pdf'      => 0,
						'without_pdf'   => 0,
						'with_asptt'    => 0,
						'without_asptt' => 0,
					);
				}
			}
		}

		$where_sql = 'WHERE ' . implode( ' AND ', $where );

		if ( $join_sql ) {
			$pdf_select = $has_doc_attachment ? 'SUM(CASE WHEN d.attachment_id IS NOT NULL THEN 1 ELSE 0 END) AS with_pdf' : '0 AS with_pdf';
			$asptt_select = $has_doc_source_number ? "SUM(CASE WHEN d.source_licence_number IS NOT NULL AND d.source_licence_number != '' THEN 1 ELSE 0 END) AS with_asptt" : '0 AS with_asptt';
			$select_sql = "SELECT COUNT(*) AS total,
				{$pdf_select},
				{$asptt_select}
				FROM {$licences_table} l
				{$join_sql}
				{$where_sql}";

			$row = $wpdb->get_row( $wpdb->prepare( $select_sql, array_merge( $join_params, $params ) ), ARRAY_A );
		} else {
			$select_sql = "SELECT COUNT(*) AS total FROM {$licences_table} l {$where_sql}";
			$row = $wpdb->get_row( $wpdb->prepare( $select_sql, $params ), ARRAY_A );
			$row['with_pdf']   = 0;
			$row['with_asptt'] = 0;
		}

		$total      = isset( $row['total'] ) ? (int) $row['total'] : 0;
		$with_pdf   = isset( $row['with_pdf'] ) ? (int) $row['with_pdf'] : 0;
		$with_asptt = isset( $row['with_asptt'] ) ? (int) $row['with_asptt'] : 0;

		return array(
			'total'         => $total,
			'with_pdf'      => $with_pdf,
			'without_pdf'   => max( 0, $total - $with_pdf ),
			'with_asptt'    => $with_asptt,
			'without_asptt' => max( 0, $total - $with_asptt ),
		);
	}

	private function get_licences( $club_id, $filters ) {
		global $wpdb;

		$licences_table  = $this->get_licences_table();
		if ( ! $this->table_exists( $licences_table ) ) {
			return array(
				'items' => array(),
				'total' => 0,
			);
		}

		$documents_table = $this->get_documents_table();
		$columns_source  = $filters['columns'] ?? $this->get_licence_columns();
		$columns         = $this->normalize_columns( $columns_source );
		if ( empty( $columns ) ) {
			$columns = $this->get_licence_columns();
		}
		if ( ! is_array( $columns ) ) {
			$columns = array();
		}
		$has_nom         = in_array( 'nom', $columns, true );
		$has_nom_licence = in_array( 'nom_licence', $columns, true );
		$has_prenom      = in_array( 'prenom', $columns, true );
		$has_birthdate   = in_array( 'date_naissance', $columns, true );
		$has_statut      = in_array( 'statut', $columns, true );
		$has_competition = in_array( 'competition', $columns, true );
		$can_join_docs   = $this->table_exists( $documents_table )
			&& $this->has_document_column( 'licence_id' )
			&& $this->has_document_column( 'source' );
		$has_doc_attachment    = $can_join_docs && $this->has_document_column( 'attachment_id' );
		$has_doc_source_number = $can_join_docs && $this->has_document_column( 'source_licence_number' );
		$has_doc_source_date   = $can_join_docs && $this->has_document_column( 'source_created_at' );

		$where  = array( 'l.club_id = %d' );
		$params = array( $club_id );

		$like = null;
		$like_normalized = null;
		if ( '' !== $filters['q'] ) {
			$like = '%' . $wpdb->esc_like( $filters['q'] ) . '%';
			$normalized = ufsc_lc_normalize_search( $filters['q'] );
			$like_normalized = $normalized ? '%' . $wpdb->esc_like( $normalized ) . '%' : null;
		}

		if ( '' !== $filters['statut'] && $has_statut ) {
			$where[] = 'l.statut = %s';
			$params[] = $filters['statut'];
		}

		if ( '' !== $filters['categorie'] ) {
			$this->append_category_filter( $where, $params, $filters['categorie'] );
		}

		if ( '' !== $filters['competition'] && $has_competition ) {
			$where[] = 'l.competition = %s';
			$params[] = $filters['competition'];
		}

		$where_sql = 'WHERE ' . implode( ' AND ', $where );
		$order     = 'desc' === $filters['order'] ? 'DESC' : 'ASC';
		$orderby   = 'l.' . $filters['orderby'];

		$join_sql = '';
		$category_select_parts = array();
		if ( in_array( 'categorie', $columns, true ) ) {
			$category_select_parts[] = "NULLIF(l.categorie, '')";
		}
		if ( in_array( 'category', $columns, true ) ) {
			$category_select_parts[] = "NULLIF(l.category, '')";
		}
		if ( in_array( 'legacy_category', $columns, true ) ) {
			$category_select_parts[] = "NULLIF(l.legacy_category, '')";
		}
		$category_select = empty( $category_select_parts ) ? "'' AS categorie_affiche" : 'COALESCE(' . implode( ', ', $category_select_parts ) . ') AS categorie_affiche';
		$nom_affiche_sql = $this->get_nom_affiche_sql( 'l', $has_nom, $has_nom_licence );
		$season_end_year_sql = in_array( 'season_end_year', $columns, true ) ? 'l.season_end_year AS season_end_year' : 'NULL AS season_end_year';
		$select_document_columns = 'NULL AS asptt_number, NULL AS date_asptt, NULL AS attachment_id';
		$document_params         = array();
		$prenom_select           = $has_prenom ? 'l.prenom' : 'NULL AS prenom';
		$birthdate_select        = $has_birthdate ? 'l.date_naissance' : 'NULL AS date_naissance';
		$statut_select           = $has_statut ? 'l.statut' : 'NULL AS statut';
		$competition_select      = $has_competition ? 'l.competition' : 'NULL AS competition';

		if ( $can_join_docs ) {
			$join_sql = "LEFT JOIN {$documents_table} d ON d.licence_id = l.id AND d.source = %s";
			$select_document_columns = sprintf(
				'%s AS asptt_number, %s AS date_asptt, %s AS attachment_id',
				$has_doc_source_number ? 'd.source_licence_number' : 'NULL',
				$has_doc_source_date ? 'd.source_created_at' : 'NULL',
				$has_doc_attachment ? 'd.attachment_id' : 'NULL'
			);
			$document_params         = array( self::SOURCE );
			if ( '' !== $filters['q'] ) {
				$name_clauses = array();
				if ( $has_nom ) {
					$name_clauses[] = 'l.nom LIKE %s';
					$params[]       = $like;
					if ( $like_normalized ) {
						$name_clauses[] = 'LOWER(l.nom) LIKE %s';
						$params[]       = $like_normalized;
					}
				}
				if ( $has_nom_licence ) {
					$name_clauses[] = 'l.nom_licence LIKE %s';
					$params[]       = $like;
					if ( $like_normalized ) {
						$name_clauses[] = 'LOWER(l.nom_licence) LIKE %s';
						$params[]       = $like_normalized;
					}
				}

				$search_clauses = array();
				if ( ! empty( $name_clauses ) ) {
					$search_clauses[] = '(' . implode( ' OR ', $name_clauses ) . ')';
				}
				if ( $has_prenom ) {
					$search_clauses[] = 'l.prenom LIKE %s';
					$params[] = $like;
					if ( $like_normalized ) {
						$search_clauses[] = 'LOWER(l.prenom) LIKE %s';
						$params[] = $like_normalized;
					}
				}
				if ( $has_doc_source_number ) {
					$search_clauses[] = 'd.source_licence_number LIKE %s';
					$params[] = $like;
					if ( $like_normalized ) {
						$search_clauses[] = 'LOWER(d.source_licence_number) LIKE %s';
						$params[] = $like_normalized;
					}
				}

				if ( empty( $search_clauses ) ) {
					$where[] = '1=0';
				} else {
					$where[] = '(' . implode( ' OR ', $search_clauses ) . ')';
				}
			}
			if ( '' !== $filters['pdf'] && $has_doc_attachment ) {
				$where[] = '1' === $filters['pdf'] ? 'd.attachment_id IS NOT NULL' : 'd.attachment_id IS NULL';
			}
		} else {
			if ( '' !== $filters['q'] ) {
				$name_clauses = array();
				if ( $has_nom ) {
					$name_clauses[] = 'l.nom LIKE %s';
					$params[]       = $like;
					if ( $like_normalized ) {
						$name_clauses[] = 'LOWER(l.nom) LIKE %s';
						$params[]       = $like_normalized;
					}
				}
				if ( $has_nom_licence ) {
					$name_clauses[] = 'l.nom_licence LIKE %s';
					$params[]       = $like;
					if ( $like_normalized ) {
						$name_clauses[] = 'LOWER(l.nom_licence) LIKE %s';
						$params[]       = $like_normalized;
					}
				}
				$search_clauses = array();
				if ( ! empty( $name_clauses ) ) {
					$search_clauses[] = '(' . implode( ' OR ', $name_clauses ) . ')';
				}
				if ( $has_prenom ) {
					$search_clauses[] = 'l.prenom LIKE %s';
					$params[] = $like;
					if ( $like_normalized ) {
						$search_clauses[] = 'LOWER(l.prenom) LIKE %s';
						$params[] = $like_normalized;
					}
				}

				if ( empty( $search_clauses ) ) {
					$where[] = '1=0';
				} else {
					$where[] = '(' . implode( ' OR ', $search_clauses ) . ')';
				}
			}
			if ( '' !== $filters['pdf'] && '1' === $filters['pdf'] ) {
				return array(
					'items' => array(),
					'total' => 0,
				);
			}
		}

		$where_sql = 'WHERE ' . implode( ' AND ', $where );

		$count_sql = "SELECT COUNT(*)
			FROM {$licences_table} l
			{$join_sql}
			{$where_sql}";

		$count_params = array_merge( $document_params, $params );
		$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $count_params ) );
		$total_pages = $filters['per_page'] ? (int) ceil( $total / $filters['per_page'] ) : 1;
		$total_pages = max( 1, $total_pages );
		$current_page = min( $filters['paged'], $total_pages );

		$offset = ( $current_page - 1 ) * $filters['per_page'];

		if ( 'date_asptt' === $filters['orderby'] ) {
			$orderby = ( $join_sql && $has_doc_source_date ) ? 'd.source_created_at' : $nom_affiche_sql;
		}
		if ( 'date_naissance' === $filters['orderby'] && ! $has_birthdate ) {
			$orderby = $nom_affiche_sql;
		}
		if ( 'nom_licence' === $filters['orderby'] ) {
			$orderby = $nom_affiche_sql;
		}

		$items_sql = "SELECT l.id, {$nom_affiche_sql} AS nom_affiche, {$prenom_select}, {$birthdate_select}, {$statut_select}, {$category_select}, {$season_end_year_sql}, {$competition_select},
			{$select_document_columns}
			FROM {$licences_table} l
			{$join_sql}
			{$where_sql}
			ORDER BY {$orderby} {$order}
			LIMIT %d OFFSET %d";

		$item_params = array_merge( $document_params, $params, array( $filters['per_page'], $offset ) );
		$items       = $wpdb->get_results( $wpdb->prepare( $items_sql, $item_params ) );

		return array(
			'items'        => $items,
			'total'        => $total,
			'total_pages'  => $total_pages,
			'current_page' => $current_page,
		);
	}

	private function render_pagination( $filters, $total_pages ) {
		$base = remove_query_arg( 'ufsc_page' );
		$base = add_query_arg( $this->get_pagination_query_args( $filters ), $base );

		return paginate_links(
			array(
				'base'      => add_query_arg( 'ufsc_page', '%#%', $base ),
				'format'    => '',
				'current'   => $filters['paged'],
				'total'     => $total_pages,
				'prev_text' => __( 'Précédent', 'ufsc-licence-competition' ),
				'next_text' => __( 'Suivant', 'ufsc-licence-competition' ),
				'type'      => 'list',
			)
		);
	}

	private function get_pagination_query_args( $filters ) {
		$args = array(
			'ufsc_q'          => $filters['q'],
			'ufsc_statut'     => $filters['statut'],
			'ufsc_categorie'  => $filters['categorie'],
			'ufsc_competition'=> $filters['competition'],
			'ufsc_pdf'        => $filters['pdf'],
			'ufsc_orderby'    => $filters['orderby'],
			'ufsc_order'      => $filters['order'],
			'ufsc_per_page'   => $filters['per_page'],
		);

		return array_filter(
			$args,
			static function ( $value ) {
				return '' !== $value && null !== $value;
			}
		);
	}

	private function get_nom_affiche_sql( $alias, $has_nom, $has_nom_licence ) {
		$parts = array();
		if ( $has_nom ) {
			$parts[] = "NULLIF({$alias}.nom, '')";
		}
		if ( $has_nom_licence ) {
			$parts[] = "NULLIF({$alias}.nom_licence, '')";
		}
		if ( empty( $parts ) ) {
			return "''";
		}
		return 'COALESCE(' . implode( ', ', $parts ) . ')';
	}

	private function get_category_display( $item ) {
		$category = $item->categorie_affiche ?? '';
		if ( '' !== $category ) {
			return $category;
		}

		$birthdate_raw = trim( (string) ( $item->date_naissance ?? '' ) );
		if ( '' === $birthdate_raw ) {
			return '';
		}

		$season_end_year = UFSC_LC_Categories::sanitize_season_end_year( $item->season_end_year ?? '' );
		if ( null === $season_end_year ) {
			$season_end_year = $this->get_default_season_end_year();
		}
		if ( null === $season_end_year ) {
			return '';
		}

		if ( function_exists( 'ufsc_lc_compute_category_from_birthdate' ) ) {
			return ufsc_lc_compute_category_from_birthdate( $birthdate_raw, $season_end_year );
		}

		return '';
	}

	private function format_birthdate( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return '';
		}

		if ( function_exists( 'ufsc_lc_format_birthdate' ) ) {
			return ufsc_lc_format_birthdate( $raw );
		}

		return $raw;
	}

	private function compute_category_from_birthdate( $birthdate, $season_end_year ) {
		if ( function_exists( 'ufsc_lc_compute_category_from_birthdate' ) ) {
			return ufsc_lc_compute_category_from_birthdate( $birthdate, $season_end_year );
		}

		return '';
	}

	private function get_default_season_end_year() {
		if ( class_exists( 'UFSC_LC_Settings_Page' ) ) {
			return UFSC_LC_Settings_Page::get_default_season_end_year();
		}

		$year = (int) gmdate( 'Y' );
		return UFSC_LC_Categories::sanitize_season_end_year( $year );
	}

	private function get_pdf_action_url( $mode, $licence_id ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'     => 'ufsc_lc_pdf',
					'licence_id' => (int) $licence_id,
					'mode'       => $mode,
				),
				admin_url( 'admin-post.php' )
			),
			'ufsc_lc_pdf_' . (int) $licence_id
		);
	}

	private function get_current_user_club_id() {
		global $wpdb;

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return 0;
		}

		$table = $this->get_clubs_table();

		$club_id = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE responsable_id = %d", $user_id )
		);

		if ( ! $club_id ) {
			$club_id = get_user_meta( $user_id, 'club_id', true );
		}

		return $club_id ? (int) $club_id : 0;
	}

	private function get_document_by_licence( $licence_id ) {
		global $wpdb;

		$table = $this->get_documents_table();

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, attachment_id FROM {$table} WHERE licence_id = %d AND source = %s",
				$licence_id,
				self::SOURCE
			)
		);
	}

	private function get_licence_record( $licence_id ) {
		global $wpdb;

		$table = $this->get_licences_table();

		return $wpdb->get_row(
			$wpdb->prepare( "SELECT id, club_id FROM {$table} WHERE id = %d", $licence_id )
		);
	}

	private function normalize_columns( $columns ) {
		if ( is_string( $columns ) ) {
			$columns = array_filter( array_map( 'trim', explode( ',', $columns ) ) );
		} elseif ( is_array( $columns ) ) {
			$columns = array_map(
				static function ( $value ) {
					return trim( (string) $value );
				},
				$columns
			);
			$columns = array_filter( $columns, 'strlen' );
		} else {
			$columns = array();
		}

		return $columns;
	}

	private function get_distinct_values( $column, $club_id ) {
		global $wpdb;

		$allowed = array( 'statut', 'categorie', 'competition' );
		if ( ! in_array( $column, $allowed, true ) ) {
			return array();
		}

		$table = $this->get_licences_table();
		$columns = $this->get_licence_columns();

		if ( 'categorie' === $column ) {
			$category_columns = $this->get_category_columns();
			if ( empty( $category_columns ) ) {
				return array();
			}
			if ( 1 === count( $category_columns ) ) {
				$column = $category_columns[0];
			} else {
				$select = 'COALESCE(' . implode(
					', ',
					array_map(
						static function ( $col ) {
							return "NULLIF({$col}, '')";
						},
						$category_columns
					)
				) . ')';

				$results = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT DISTINCT {$select} AS category FROM {$table} WHERE club_id = %d HAVING category IS NOT NULL AND category != '' ORDER BY category ASC",
						$club_id
					)
				);

				return array_filter( array_map( 'strval', $results ) );
			}
		}

		if ( ! $column || ! in_array( $column, $columns, true ) ) {
			return array();
		}

		$results = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT {$column} FROM {$table} WHERE club_id = %d AND {$column} IS NOT NULL AND {$column} != '' ORDER BY {$column} ASC",
				$club_id
			)
		);

		return array_filter( array_map( 'strval', $results ) );
	}

	private function get_category_column() {
		$columns = $this->get_category_columns();

		return $columns[0] ?? '';
	}

	private function get_category_columns() {
		$columns = $this->get_licence_columns();
		$available = array();

		foreach ( array( 'category', 'categorie', 'legacy_category' ) as $candidate ) {
			if ( in_array( $candidate, $columns, true ) ) {
				$available[] = $candidate;
			}
		}

		return $available;
	}

	private function append_category_filter( array &$where, array &$params, string $value ): void {
		$columns = $this->get_category_columns();
		if ( empty( $columns ) ) {
			return;
		}

		$normalized = $this->normalize_category_value( $value );
		if ( '' === $normalized ) {
			return;
		}

		$parts = array_map(
			static function ( $column ) {
				return "NULLIF(l.{$column}, '')";
			},
			$columns
		);
		$coalesce_sql = 'COALESCE(' . implode( ', ', $parts ) . ')';
		$expression = "TRIM(LOWER({$coalesce_sql}))";
		$collation = $this->get_category_collation();
		$value_clause = '%s';
		if ( '' !== $collation ) {
			$expression .= " COLLATE {$collation}";
			$value_clause .= " COLLATE {$collation}";
		}

		$where[] = "{$expression} = {$value_clause}";
		$params[] = $normalized;
	}

	private function normalize_category_value( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}

		if ( function_exists( 'mb_strtolower' ) ) {
			return mb_strtolower( $value );
		}

		return strtolower( $value );
	}

	private function get_category_collation(): string {
		static $cached = null;

		if ( null !== $cached ) {
			return $cached;
		}

		$cache_key = 'ufsc_lc_category_collation';
		$cached_value = wp_cache_get( $cache_key, 'ufsc_licence_competition' );
		if ( false !== $cached_value ) {
			$cached = (string) $cached_value;
			return $cached;
		}

		global $wpdb;
		$collation = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COLLATION_NAME FROM information_schema.COLLATIONS WHERE COLLATION_NAME = %s",
				'utf8mb4_0900_ai_ci'
			)
		);

		$cached = $collation ? 'utf8mb4_0900_ai_ci' : '';
		wp_cache_set( $cache_key, $cached, 'ufsc_licence_competition', HOUR_IN_SECONDS );

		return $cached;
	}

	private function get_licence_columns() {
		if ( null !== $this->licence_columns ) {
			return $this->licence_columns;
		}

		global $wpdb;

		$table = $this->get_licences_table();
		if ( ! $this->table_exists( $table ) ) {
			$this->licence_columns = array();
			return $this->licence_columns;
		}

		$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 );
		$this->licence_columns = is_array( $columns ) ? $columns : array();

		return $this->licence_columns;
	}

	private function get_documents_columns() {
		if ( null !== $this->documents_columns ) {
			return $this->documents_columns;
		}

		global $wpdb;

		$table = $this->get_documents_table();
		if ( ! $this->table_exists( $table ) ) {
			$this->documents_columns = array();
			return $this->documents_columns;
		}

		$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 );
		$this->documents_columns = is_array( $columns ) ? $columns : array();

		return $this->documents_columns;
	}

	private function has_document_column( string $column ): bool {
		$columns = $this->get_documents_columns();
		return in_array( $column, $columns, true );
	}

	private function format_competition( $value ) {
		if ( null === $value || '' === $value ) {
			return __( '—', 'ufsc-licence-competition' );
		}

		if ( is_numeric( $value ) ) {
			return (int) $value ? __( 'Compétition', 'ufsc-licence-competition' ) : __( 'Loisir', 'ufsc-licence-competition' );
		}

		return (string) $value;
	}

	private function get_documents_table() {
		global $wpdb;

		return $wpdb->prefix . 'ufsc_licence_documents';
	}

	private function get_licences_table() {
		global $wpdb;

		return $wpdb->prefix . 'ufsc_licences';
	}

	private function get_clubs_table() {
		global $wpdb;

		return $wpdb->prefix . 'ufsc_clubs';
	}

	private function table_exists( $table ) {
		global $wpdb;

		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}
}
