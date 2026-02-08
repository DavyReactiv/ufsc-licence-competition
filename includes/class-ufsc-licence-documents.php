<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UFSC_LC_Licence_Documents {
	const SOURCE = 'UFSC';

	private $legacy_enabled = false;

	public function __construct( $legacy_enabled = false ) {
		$this->legacy_enabled = (bool) $legacy_enabled;
	}

	public function register() {
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_action( 'admin_post_ufsc_lc_upload_licence_pdf', array( $this, 'handle_upload' ) );
		add_action( 'admin_post_ufsc_lc_download_licence_pdf', array( $this, 'handle_download' ) );
		add_action( 'wp_ajax_ufsc_lc_search_clubs', array( $this, 'ajax_search_clubs' ) );

$legacy_allowed = apply_filters( 'ufsc_enable_legacy_admin_post', true );
$legacy_allowed = apply_filters( 'ufsc_lc_enable_legacy_admin_post', $legacy_allowed );

if ( $this->legacy_enabled && $legacy_allowed ) {
	add_action( 'admin_post_ufsc_upload_licence_pdf', array( $this, 'handle_upload' ) );
	add_action( 'admin_post_ufsc_download_licence_pdf', array( $this, 'handle_download' ) );
}


	public function create_table() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_name      = $this->get_documents_table();
		$meta_table_name = $this->get_documents_meta_table();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			licence_id bigint(20) unsigned NOT NULL,
			source varchar(50) NOT NULL,
			source_licence_number varchar(100) NOT NULL,
			attachment_id bigint(20) unsigned NULL,
			asptt_club_note varchar(255) NULL,
			source_created_at datetime NULL,
			imported_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_source_number (source, source_licence_number),
			KEY idx_licence_source (licence_id, source),
			KEY licence_id (licence_id),
			KEY idx_source_created_at (source_created_at)
		) {$charset_collate};";

		dbDelta( $sql );

		$meta_sql = "CREATE TABLE {$meta_table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			licence_id bigint(20) unsigned NOT NULL,
			source varchar(50) NOT NULL,
			meta_key varchar(190) NOT NULL,
			meta_value longtext NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_licence_meta (licence_id, source, meta_key),
			KEY idx_meta_key (meta_key),
			KEY idx_licence_source (licence_id, source)
		) {$charset_collate};";

		dbDelta( $meta_sql );

	}

	public function register_admin_menu() {
		$hook_suffix = add_menu_page(
			__( 'UFSC Licences', 'ufsc-licence-competition' ),
			__( 'UFSC Licences', 'ufsc-licence-competition' ),
			UFSC_LC_Capabilities::get_manage_read_capability(),
			UFSC_LC_Plugin::PARENT_SLUG,
			array( $this, 'render_admin_page' ),
			'dashicons-media-document',
			30
		);
		UFSC_LC_Admin_Assets::register_page( $hook_suffix );
	}

	public function render_admin_page() {
		if ( ! UFSC_LC_Capabilities::user_can_manage_read() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ) );
		}

		$can_edit = UFSC_LC_Capabilities::user_can_edit();
		$status = isset( $_GET['ufsc_status'] ) ? sanitize_text_field( wp_unslash( $_GET['ufsc_status'] ) ) : '';
		$message = isset( $_GET['ufsc_message'] ) ? sanitize_text_field( wp_unslash( $_GET['ufsc_message'] ) ) : '';
		$search  = $this->get_search_filters();

		$search_results = array();
		$search_notice  = array();

		if ( $search['submitted'] ) {
			$has_query = '' !== $search['query'];
			if ( ! $has_query && ( '' === $search['nom'] || '' === $search['prenom'] || '' === $search['date_naissance'] ) ) {
				$search_notice = array(
					'type'    => 'error',
					'message' => __( 'Veuillez renseigner le nom, le prénom et la date de naissance ou utiliser la recherche rapide.', 'ufsc-licence-competition' ),
				);
			} elseif ( ! $has_query && ! $search['search_without_club'] && ! $search['club_id'] ) {
				$search_notice = array(
					'type'    => 'error',
					'message' => __( 'Veuillez sélectionner un club ou activer la recherche élargie.', 'ufsc-licence-competition' ),
				);
			} else {
				$search_results = $this->find_licences( $search );
				if ( empty( $search_results ) ) {
					$search_notice = array(
						'type'    => 'warning',
						'message' => __( 'Aucune licence trouvée. Vérifiez l’orthographe, le club et la saison, ou essayez la recherche élargie.', 'ufsc-licence-competition' ),
					);
				}
			}
		}

		if ( $status && $message ) {
			$notice_classes = array(
				'success' => 'notice notice-success is-dismissible',
				'warning' => 'notice notice-warning is-dismissible',
				'error'   => 'notice notice-error is-dismissible',
			);
			$class = isset( $notice_classes[ $status ] ) ? $notice_classes[ $status ] : 'notice notice-info is-dismissible';
			echo '<div class="' . esc_attr( $class ) . '"><p>' . esc_html( $message ) . '</p></div>';
		}

		?>
		<div class="wrap ufsc-lc-documents">
			<h1><?php esc_html_e( 'Associer un PDF de licence', 'ufsc-licence-competition' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Recherchez une licence par identité, club et saison pour éviter les erreurs, puis associez le PDF nominatif.', 'ufsc-licence-competition' ); ?></p>

			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="ufsc-lc-document-search">
				<input type="hidden" name="page" value="ufsc-licence-documents">
				<input type="hidden" name="ufsc_search" value="1">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ufsc_query"><?php esc_html_e( 'Recherche', 'ufsc-licence-competition' ); ?></label></th>
						<td>
							<input name="ufsc_query" type="text" id="ufsc_query" class="regular-text" value="<?php echo esc_attr( $search['query'] ); ?>" placeholder="<?php esc_attr_e( 'N° licence UFSC, N° ASPTT ou texte', 'ufsc-licence-competition' ); ?>">
							<p class="description"><?php esc_html_e( 'Exemples : 123456, ASPTT 98765, Dupont.', 'ufsc-licence-competition' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_club_search"><?php esc_html_e( 'Club', 'ufsc-licence-competition' ); ?></label></th>
						<td>
							<input type="text" id="ufsc_club_search" class="regular-text ufsc-club-search" data-row-index="0" data-ajax-action="ufsc_lc_search_clubs" data-nonce-key="admin" value="<?php echo esc_attr( $search['club_search'] ); ?>" placeholder="<?php esc_attr_e( 'Rechercher un club…', 'ufsc-licence-competition' ); ?>">
							<select id="ufsc_club_id" name="ufsc_club_id" class="regular-text ufsc-club-select" data-row-index="0">
								<option value=""><?php esc_html_e( 'Sélectionner un club', 'ufsc-licence-competition' ); ?></option>
								<?php if ( $search['club_id'] ) : ?>
									<?php $club = $this->get_club_by_id( $search['club_id'] ); ?>
									<?php if ( $club ) : ?>
										<option value="<?php echo esc_attr( $club->id ); ?>" selected><?php echo esc_html( $club->nom ); ?></option>
									<?php endif; ?>
								<?php endif; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Tapez au moins 2 caractères pour filtrer les clubs ou laissez vide pour charger les premiers résultats.', 'ufsc-licence-competition' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_season"><?php echo esc_html( $search['season_label'] ); ?></label></th>
						<td><input name="ufsc_season" type="text" id="ufsc_season" class="regular-text" value="<?php echo esc_attr( $search['season'] ); ?>" placeholder="<?php esc_attr_e( 'Optionnel', 'ufsc-licence-competition' ); ?>"></td>
					</tr>
				</table>
				<details class="ufsc-lc-advanced-mode">
					<summary><?php esc_html_e( 'Mode avancé : recherche par identité', 'ufsc-licence-competition' ); ?></summary>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="ufsc_nom"><?php esc_html_e( 'Nom', 'ufsc-licence-competition' ); ?></label></th>
							<td><input name="ufsc_nom" type="text" id="ufsc_nom" class="regular-text" value="<?php echo esc_attr( $search['nom'] ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><label for="ufsc_prenom"><?php esc_html_e( 'Prénom', 'ufsc-licence-competition' ); ?></label></th>
							<td><input name="ufsc_prenom" type="text" id="ufsc_prenom" class="regular-text" value="<?php echo esc_attr( $search['prenom'] ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><label for="ufsc_date_naissance"><?php esc_html_e( 'Date de naissance', 'ufsc-licence-competition' ); ?></label></th>
							<td><input name="ufsc_date_naissance" type="date" id="ufsc_date_naissance" class="regular-text" value="<?php echo esc_attr( $search['date_naissance'] ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Recherche élargie', 'ufsc-licence-competition' ); ?></th>
							<td>
								<label for="ufsc_search_without_club">
									<input type="checkbox" name="ufsc_search_without_club" id="ufsc_search_without_club" value="1" <?php checked( $search['search_without_club'] ); ?>>
									<?php esc_html_e( 'Ignorer le club (attention aux homonymes)', 'ufsc-licence-competition' ); ?>
								</label>
							</td>
						</tr>
					</table>
				</details>
				<?php submit_button( __( 'Rechercher les licences', 'ufsc-licence-competition' ), 'secondary' ); ?>
			</form>

			<?php if ( ! $can_edit ) : ?>
				<div class="notice notice-info"><p><?php esc_html_e( 'Votre compte dispose d’un accès en lecture seule. L’association de PDF est réservée aux profils ayant les droits de modification.', 'ufsc-licence-competition' ); ?></p></div>
			<?php endif; ?>

			<?php if ( ! empty( $search_notice ) && isset( $search_notice['type'], $search_notice['message'] ) ) : ?>
				<?php $class = 'notice notice-' . esc_attr( $search_notice['type'] ) . ' is-dismissible'; ?>
				<div class="<?php echo esc_attr( $class ); ?>"><p><?php echo esc_html( $search_notice['message'] ); ?></p></div>
			<?php endif; ?>

			<?php if ( $search['submitted'] && ! empty( $search_results ) ) : ?>
				<?php if ( $search['search_without_club'] ) : ?>
					<div class="notice notice-warning is-dismissible"><p><?php esc_html_e( 'Recherche élargie sans club : vérifiez attentivement la licence avant association.', 'ufsc-licence-competition' ); ?></p></div>
				<?php endif; ?>
				<?php if ( $can_edit ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="ufsc-lc-document-associate">
						<?php wp_nonce_field( 'ufsc_lc_upload_licence_pdf', 'ufsc_lc_upload_nonce' ); ?>
						<input type="hidden" name="action" value="ufsc_lc_upload_licence_pdf">
						<?php if ( 1 === count( $search_results ) ) : ?>
							<?php $item = $search_results[0]; ?>
							<input type="hidden" name="matched_licence_id" value="<?php echo esc_attr( $item->id ); ?>">
							<div class="ufsc-lc-card">
								<h2><?php esc_html_e( 'Licence trouvée', 'ufsc-licence-competition' ); ?></h2>
								<?php echo wp_kses_post( $this->render_licence_summary( $item, $search['season_label'] ) ); ?>
								<?php
								$current_attachment_id = function_exists( 'ufsc_lc_licence_get_pdf_attachment_id' )
									? ufsc_lc_licence_get_pdf_attachment_id( (int) $item->id )
									: null;
								$current_pdf_url = $current_attachment_id ? wp_get_attachment_url( $current_attachment_id ) : '';
								?>
								<p class="description">
									<?php if ( $current_attachment_id && $current_pdf_url ) : ?>
										<?php echo esc_html__( 'PDF actuel :', 'ufsc-licence-competition' ) . ' '; ?>
										<a href="<?php echo esc_url( $current_pdf_url ); ?>" target="_blank" rel="noopener noreferrer">
											<?php esc_html_e( 'Voir le PDF', 'ufsc-licence-competition' ); ?>
										</a>
									<?php else : ?>
										<?php esc_html_e( 'PDF actuel : non généré.', 'ufsc-licence-competition' ); ?>
									<?php endif; ?>
								</p>
							</div>
						<?php else : ?>
							<h2><?php esc_html_e( 'Plusieurs licences correspondent', 'ufsc-licence-competition' ); ?></h2>
							<table class="widefat striped ufsc-lc-results-table">
								<thead>
									<tr>
										<th><?php esc_html_e( 'Sélection', 'ufsc-licence-competition' ); ?></th>
										<th><?php esc_html_e( 'N° licence', 'ufsc-licence-competition' ); ?></th>
										<th><?php esc_html_e( 'Nom', 'ufsc-licence-competition' ); ?></th>
										<th><?php esc_html_e( 'Prénom', 'ufsc-licence-competition' ); ?></th>
										<th><?php esc_html_e( 'Date de naissance', 'ufsc-licence-competition' ); ?></th>
										<th><?php esc_html_e( 'Club', 'ufsc-licence-competition' ); ?></th>
										<th><?php echo esc_html( $search['season_label'] ); ?></th>
										<th><?php esc_html_e( 'Catégorie', 'ufsc-licence-competition' ); ?></th>
										<th><?php esc_html_e( 'N° ASPTT', 'ufsc-licence-competition' ); ?></th>
										<th><?php esc_html_e( 'PDF', 'ufsc-licence-competition' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $search_results as $item ) : ?>
										<?php
										$nom_affiche = ufsc_lc_get_nom_affiche( $item );
										$birthdate   = ufsc_lc_format_birthdate( $item->date_naissance ?? '' );
										$category    = $item->categorie_affiche ?? ( $item->category_value ?? '' );
										if ( '' === $category && '' !== $birthdate ) {
											$season_end_year = UFSC_LC_Categories::sanitize_season_end_year( $item->season_value ?? '' );
											if ( null === $season_end_year ) {
												$season_end_year = $this->get_default_season_end_year();
											}
											$computed = UFSC_LC_Categories::category_from_birthdate( $birthdate, $season_end_year );
											$category = isset( $computed['category'] ) ? (string) $computed['category'] : '';
										} elseif ( '' === $birthdate ) {
											$category = '';
										}
										?>
										<tr>
											<td><input type="radio" name="matched_licence_id" value="<?php echo esc_attr( $item->id ); ?>" required></td>
											<td><?php echo esc_html( $item->licence_number ? $item->licence_number : __( '—', 'ufsc-licence-competition' ) ); ?></td>
											<td><?php echo esc_html( $nom_affiche ? $nom_affiche : __( '—', 'ufsc-licence-competition' ) ); ?></td>
											<td><?php echo esc_html( $item->prenom ? $item->prenom : __( '—', 'ufsc-licence-competition' ) ); ?></td>
											<td><?php echo esc_html( '' !== $birthdate ? $birthdate : __( '—', 'ufsc-licence-competition' ) ); ?></td>
											<td><?php echo esc_html( $item->club_name ? $item->club_name : __( '—', 'ufsc-licence-competition' ) ); ?></td>
											<td><?php echo esc_html( $item->season_value ? $item->season_value : __( '—', 'ufsc-licence-competition' ) ); ?></td>
											<td><?php echo esc_html( $category ? $category : __( '—', 'ufsc-licence-competition' ) ); ?></td>
											<td><?php echo esc_html( $item->asptt_number ? $item->asptt_number : __( '—', 'ufsc-licence-competition' ) ); ?></td>
											<td><?php echo esc_html( ! empty( $item->has_pdf ) ? __( 'Associé', 'ufsc-licence-competition' ) : __( 'Manquant', 'ufsc-licence-competition' ) ); ?></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						<?php endif; ?>
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><label for="licence_pdf"><?php esc_html_e( 'PDF nominatif', 'ufsc-licence-competition' ); ?></label></th>
								<td><input name="licence_pdf" type="file" id="licence_pdf" accept="application/pdf" required></td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Options', 'ufsc-licence-competition' ); ?></th>
								<td>
									<label for="ufsc_pdf_delete">
										<input type="checkbox" name="ufsc_pdf_delete" id="ufsc_pdf_delete" value="1">
										<?php esc_html_e( 'Supprimer le fichier du média après remplacement/détachement (irréversible).', 'ufsc-licence-competition' ); ?>
									</label>
								</td>
							</tr>
						</table>
						<div class="ufsc-lc-form-actions">
							<button type="submit" class="button button-primary" name="ufsc_pdf_action" value="attach">
								<?php esc_html_e( 'Associer le PDF', 'ufsc-licence-competition' ); ?>
							</button>
							<button type="submit" class="button" name="ufsc_pdf_action" value="replace">
								<?php esc_html_e( 'Remplacer le PDF', 'ufsc-licence-competition' ); ?>
							</button>
							<button type="submit" class="button button-secondary" name="ufsc_pdf_action" value="detach" formnovalidate>
								<?php esc_html_e( 'Détacher le PDF', 'ufsc-licence-competition' ); ?>
							</button>
						</div>
					</form>
				<?php else : ?>
					<div class="ufsc-lc-readonly-results">
						<?php if ( 1 === count( $search_results ) ) : ?>
							<?php $item = $search_results[0]; ?>
							<div class="ufsc-lc-card">
								<h2><?php esc_html_e( 'Licence trouvée', 'ufsc-licence-competition' ); ?></h2>
								<?php echo wp_kses_post( $this->render_licence_summary( $item, $search['season_label'] ) ); ?>
							</div>
						<?php else : ?>
							<h2><?php esc_html_e( 'Plusieurs licences correspondent', 'ufsc-licence-competition' ); ?></h2>
							<table class="widefat striped ufsc-lc-results-table">
								<thead>
									<tr>
										<th><?php esc_html_e( 'N° licence', 'ufsc-licence-competition' ); ?></th>
										<th><?php esc_html_e( 'Nom', 'ufsc-licence-competition' ); ?></th>
										<th><?php esc_html_e( 'Prénom', 'ufsc-licence-competition' ); ?></th>
										<th><?php esc_html_e( 'Date de naissance', 'ufsc-licence-competition' ); ?></th>
										<th><?php esc_html_e( 'Club', 'ufsc-licence-competition' ); ?></th>
										<th><?php echo esc_html( $search['season_label'] ); ?></th>
										<th><?php esc_html_e( 'Catégorie', 'ufsc-licence-competition' ); ?></th>
										<th><?php esc_html_e( 'N° ASPTT', 'ufsc-licence-competition' ); ?></th>
										<th><?php esc_html_e( 'PDF', 'ufsc-licence-competition' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $search_results as $item ) : ?>
										<?php
										$nom_affiche = ufsc_lc_get_nom_affiche( $item );
										$birthdate   = ufsc_lc_format_birthdate( $item->date_naissance ?? '' );
										$category    = $item->categorie_affiche ?? ( $item->category_value ?? '' );
										if ( '' === $category && '' !== $birthdate ) {
											$season_end_year = UFSC_LC_Categories::sanitize_season_end_year( $item->season_value ?? '' );
											if ( null === $season_end_year ) {
												$season_end_year = $this->get_default_season_end_year();
											}
											$computed = UFSC_LC_Categories::category_from_birthdate( $birthdate, $season_end_year );
											$category = isset( $computed['category'] ) ? (string) $computed['category'] : '';
										} elseif ( '' === $birthdate ) {
											$category = '';
										}
										?>
										<tr>
											<td><?php echo esc_html( $item->licence_number ? $item->licence_number : __( '—', 'ufsc-licence-competition' ) ); ?></td>
											<td><?php echo esc_html( $nom_affiche ? $nom_affiche : __( '—', 'ufsc-licence-competition' ) ); ?></td>
											<td><?php echo esc_html( $item->prenom ? $item->prenom : __( '—', 'ufsc-licence-competition' ) ); ?></td>
											<td><?php echo esc_html( '' !== $birthdate ? $birthdate : __( '—', 'ufsc-licence-competition' ) ); ?></td>
											<td><?php echo esc_html( $item->club_name ? $item->club_name : __( '—', 'ufsc-licence-competition' ) ); ?></td>
											<td><?php echo esc_html( $item->season_value ? $item->season_value : __( '—', 'ufsc-licence-competition' ) ); ?></td>
											<td><?php echo esc_html( $category ? $category : __( '—', 'ufsc-licence-competition' ) ); ?></td>
											<td><?php echo esc_html( $item->asptt_number ? $item->asptt_number : __( '—', 'ufsc-licence-competition' ) ); ?></td>
											<td><?php echo esc_html( ! empty( $item->has_pdf ) ? __( 'Associé', 'ufsc-licence-competition' ) : __( 'Manquant', 'ufsc-licence-competition' ) ); ?></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						<?php endif; ?>
					</div>
				<?php endif; ?>
			<?php endif; ?>

			<?php if ( $can_edit ) : ?>
				<details class="ufsc-lc-advanced-mode">
					<summary><?php esc_html_e( 'Mode avancé : association directe', 'ufsc-licence-competition' ); ?></summary>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
						<?php wp_nonce_field( 'ufsc_lc_upload_licence_pdf', 'ufsc_lc_upload_nonce' ); ?>
						<input type="hidden" name="action" value="ufsc_lc_upload_licence_pdf">
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><label for="licence_id"><?php esc_html_e( 'Licence ID', 'ufsc-licence-competition' ); ?></label></th>
								<td><input name="licence_id" type="number" id="licence_id" class="regular-text" min="1"></td>
							</tr>
							<tr>
								<th scope="row"><label for="numero_licence_delegataire"><?php esc_html_e( 'Numéro licence délégataire (optionnel)', 'ufsc-licence-competition' ); ?></label></th>
								<td><input name="numero_licence_delegataire" type="text" id="numero_licence_delegataire" class="regular-text"></td>
							</tr>
							<tr>
								<th scope="row"><label for="licence_pdf_advanced"><?php esc_html_e( 'PDF nominatif', 'ufsc-licence-competition' ); ?></label></th>
								<td><input name="licence_pdf" type="file" id="licence_pdf_advanced" accept="application/pdf" required></td>
							</tr>
						</table>
						<?php submit_button( __( 'Uploader et associer', 'ufsc-licence-competition' ) ); ?>
					</form>
				</details>
			<?php endif; ?>
		</div>
		<?php
	}

	public function ajax_search_clubs() {
		if ( ! UFSC_LC_Capabilities::user_can_read() ) {
			wp_send_json_error( array( 'message' => __( 'Accès refusé.', 'ufsc-licence-competition' ) ), 403 );
		}

		check_ajax_referer( 'ufsc_lc_admin_nonce', 'nonce' );

		$search = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		$search = trim( $search );
		$is_empty = '' === $search;
		if ( ! $is_empty && strlen( $search ) < 2 ) {
			UFSC_LC_Logger::log(
				__( 'Recherche clubs admin: terme trop court.', 'ufsc-licence-competition' ),
				array( 'search' => $search )
			);
			wp_send_json_success( array() );
		}

		global $wpdb;
		$table = $this->get_clubs_table();
		$like  = '%' . $wpdb->esc_like( $search ) . '%';
		$limit = $is_empty ? 50 : 20;

		$scope = function_exists( 'ufsc_lc_get_user_scope_region' ) ? ufsc_lc_get_user_scope_region() : null;
		$repository = class_exists( 'UFSC_LC_Licence_Repository' ) ? new UFSC_LC_Licence_Repository() : null;
		$region_column = $repository ? $repository->get_club_region_column() : '';

		$where_parts = array();
		$params = array();
		if ( ! $is_empty ) {
			$where_parts[] = 'nom LIKE %s';
			$params[] = $like;
		}
		if ( $scope && '' !== $region_column ) {
			$where_parts[] = "{$region_column} = %s";
			$params[] = $scope;
		}

		$where_sql = '';
		if ( ! empty( $where_parts ) ) {
			$where_sql = 'WHERE ' . implode( ' AND ', $where_parts );
		}

		$sql = "SELECT id, nom FROM {$table} {$where_sql} ORDER BY nom ASC LIMIT {$limit}";
		$results = empty( $params ) ? $wpdb->get_results( $sql ) : $wpdb->get_results( $wpdb->prepare( $sql, $params ) );

		if ( $wpdb->last_error ) {
			UFSC_LC_Logger::log(
				__( 'Recherche clubs admin: erreur SQL.', 'ufsc-licence-competition' ),
				array(
					'search' => $search,
					'error'  => $wpdb->last_error,
				)
			);
			wp_send_json_error( array( 'message' => __( 'Erreur de recherche.', 'ufsc-licence-competition' ) ), 500 );
		}

		$data = array();
		foreach ( $results as $club ) {
			$data[] = array(
				'id'    => (int) $club->id,
				'label' => $club->nom,
				'text'  => $club->nom,
			);
		}

		UFSC_LC_Logger::log(
			__( 'Recherche clubs admin: résultats chargés.', 'ufsc-licence-competition' ),
			array(
				'search'  => $search,
				'count'   => count( $data ),
				'fallback'=> $is_empty,
			)
		);

		wp_send_json_success( $data );
	}

	public function handle_upload() {
		if ( ! UFSC_LC_Capabilities::user_can_edit() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		$nonce = isset( $_POST['ufsc_lc_upload_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['ufsc_lc_upload_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'ufsc_lc_upload_licence_pdf' ) ) {
			wp_die( esc_html__( 'Requête invalide.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		$matched_licence_id = isset( $_POST['matched_licence_id'] ) ? absint( $_POST['matched_licence_id'] ) : 0;
		$licence_id         = isset( $_POST['licence_id'] ) ? absint( $_POST['licence_id'] ) : 0;
		$numero_licence     = isset( $_POST['numero_licence_delegataire'] ) ? sanitize_text_field( wp_unslash( $_POST['numero_licence_delegataire'] ) ) : '';
		$action             = isset( $_POST['ufsc_pdf_action'] ) ? sanitize_key( wp_unslash( $_POST['ufsc_pdf_action'] ) ) : 'attach';
		$action             = in_array( $action, array( 'attach', 'replace', 'detach' ), true ) ? $action : 'attach';
		$delete_old_file    = ! empty( $_POST['ufsc_pdf_delete'] );

		if ( $matched_licence_id ) {
			$licence = $this->get_licence_record( $matched_licence_id, '' );
		} else {
			if ( 0 === $licence_id && '' === $numero_licence ) {
				$this->redirect_with_message( 'error', __( 'Veuillez fournir un identifiant ou un numéro de licence.', 'ufsc-licence-competition' ) );
			}
			$licence = $this->get_licence_record( $licence_id, $numero_licence );
		}

		if ( ! $licence ) {
			$this->redirect_with_message( 'error', __( 'Licence introuvable.', 'ufsc-licence-competition' ) );
		}

		if ( class_exists( 'UFSC_LC_Scope' ) ) {
			UFSC_LC_Scope::enforce_object_scope( (int) $licence->id, 'licence' );
		} else {
			$repository = new UFSC_LC_Licence_Repository();
			$repository->assert_licence_in_scope( (int) $licence->id );
		}

		$current_attachment_id = function_exists( 'ufsc_lc_licence_get_pdf_attachment_id' )
			? ufsc_lc_licence_get_pdf_attachment_id( (int) $licence->id )
			: null;

		if ( 'detach' === $action ) {
			if ( $current_attachment_id ) {
				$this->detach_document( (int) $licence->id );
				$this->sync_pdf_meta( (int) $licence->id, 0 );
				if ( $delete_old_file ) {
					wp_delete_attachment( (int) $current_attachment_id, true );
				}
				$this->log_pdf_action( 'detach', (int) $licence->id, (int) $current_attachment_id, 0 );
				$this->redirect_with_message( 'success', __( 'PDF détaché avec succès.', 'ufsc-licence-competition' ) );
			}

			$this->redirect_with_message( 'warning', __( 'Aucun PDF à détacher.', 'ufsc-licence-competition' ) );
		}

		if ( empty( $_FILES['licence_pdf']['name'] ) ) {
			$this->redirect_with_message( 'error', __( 'Aucun fichier PDF fourni.', 'ufsc-licence-competition' ) );
		}

		$check = wp_check_filetype_and_ext( $_FILES['licence_pdf']['tmp_name'], $_FILES['licence_pdf']['name'] );
		if ( empty( $check['ext'] ) || 'pdf' !== strtolower( $check['ext'] ) ) {
			$this->redirect_with_message( 'error', __( 'Le fichier doit être un PDF.', 'ufsc-licence-competition' ) );
		}
		if ( ! empty( $_FILES['licence_pdf']['size'] ) ) {
			$max_size = wp_max_upload_size();
			if ( $max_size && (int) $_FILES['licence_pdf']['size'] > $max_size ) {
				$this->redirect_with_message( 'error', __( 'Le fichier PDF est trop volumineux.', 'ufsc-licence-competition' ) );
			}
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attachment_id = media_handle_upload( 'licence_pdf', 0, array(), array( 'test_form' => false ) );

		if ( is_wp_error( $attachment_id ) ) {
			$this->redirect_with_message( 'error', $attachment_id->get_error_message() );
		}

		$source_number = $matched_licence_id ? '' : $numero_licence;
		$this->upsert_document( (int) $licence->id, $source_number, (int) $attachment_id );
		$this->sync_pdf_meta( (int) $licence->id, (int) $attachment_id );
		$this->update_document_meta( (int) $licence->id, 'pdf_assigned_at', current_time( 'mysql' ) );
		$this->update_document_meta( (int) $licence->id, 'pdf_assigned_by', (int) get_current_user_id() );

		$effective_action = ( $current_attachment_id && 'attach' === $action ) ? 'replace' : $action;
		$this->log_pdf_action( $effective_action, (int) $licence->id, (int) $current_attachment_id, (int) $attachment_id );

		if ( $delete_old_file && $current_attachment_id && (int) $current_attachment_id !== (int) $attachment_id ) {
			wp_delete_attachment( (int) $current_attachment_id, true );
		}

		$this->redirect_with_message( 'success', __( 'PDF associé avec succès.', 'ufsc-licence-competition' ) );
	}

	public function handle_download() {
		if ( ! is_user_logged_in() ) {
			UFSC_LC_Logger::log( __( 'Téléchargement refusé: utilisateur non connecté.', 'ufsc-licence-competition' ) );
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		$licence_id = isset( $_GET['licence_id'] ) ? absint( $_GET['licence_id'] ) : 0;
		$nonce      = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

		if ( ! $licence_id || ! wp_verify_nonce( $nonce, 'ufsc_lc_download_licence_pdf_' . $licence_id ) ) {
			UFSC_LC_Logger::log(
				__( 'Téléchargement refusé: nonce invalide.', 'ufsc-licence-competition' ),
				array( 'licence_id' => $licence_id )
			);
			wp_die( esc_html__( 'Requête invalide.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		$licence = $this->get_licence_record( $licence_id, '' );
		if ( ! $licence ) {
			UFSC_LC_Logger::log(
				__( 'Téléchargement refusé: licence introuvable.', 'ufsc-licence-competition' ),
				array( 'licence_id' => $licence_id )
			);
			wp_die( esc_html__( 'Licence introuvable.', 'ufsc-licence-competition' ), '', array( 'response' => 404 ) );
		}

		if ( class_exists( 'UFSC_LC_Scope' ) ) {
			UFSC_LC_Scope::enforce_object_scope( (int) $licence->id, 'licence' );
		} else {
			$repository = new UFSC_LC_Licence_Repository();
			$repository->assert_licence_in_scope( (int) $licence->id );
		}

		if ( ! UFSC_LC_Capabilities::user_can_read() ) {
			$club = $this->get_club_by_id( (int) $licence->club_id );
			if ( ! $club || (int) $club->responsable_id !== (int) get_current_user_id() ) {
				UFSC_LC_Logger::log(
					__( 'Téléchargement refusé: utilisateur non autorisé.', 'ufsc-licence-competition' ),
					array(
						'licence_id' => (int) $licence->id,
						'club_id'    => (int) $licence->club_id,
					)
				);
				wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
			}
		}

		$attachment_id = function_exists( 'ufsc_lc_licence_get_pdf_attachment_id' )
			? ufsc_lc_licence_get_pdf_attachment_id( $licence_id )
			: null;
		if ( ! $attachment_id ) {
			UFSC_LC_Logger::log(
				__( 'Téléchargement refusé: document indisponible.', 'ufsc-licence-competition' ),
				array( 'licence_id' => $licence_id )
			);
			wp_die( esc_html__( 'Document indisponible.', 'ufsc-licence-competition' ), '', array( 'response' => 404 ) );
		}

		$file_path = get_attached_file( (int) $attachment_id );
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			UFSC_LC_Logger::log(
				__( 'Téléchargement refusé: fichier introuvable.', 'ufsc-licence-competition' ),
				array( 'attachment_id' => (int) $attachment_id )
			);
			wp_die( esc_html__( 'Fichier introuvable.', 'ufsc-licence-competition' ), '', array( 'response' => 404 ) );
		}

		$file_name = basename( $file_path );

if ( headers_sent() ) {
	wp_die( esc_html__( 'Export impossible.', 'ufsc-licence-competition' ) );
}

		while ( ob_get_level() ) {
			ob_end_clean();
		}
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . $file_name . '"' );
		header( 'Content-Length: ' . filesize( $file_path ) );

		readfile( $file_path );
		exit;
	}

	private function get_download_url( $licence_id ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'     => 'ufsc_lc_download_licence_pdf',
					'licence_id' => (int) $licence_id,
				),
				admin_url( 'admin-post.php' )
			),
			'ufsc_lc_download_licence_pdf_' . (int) $licence_id
		);
	}

	private function get_current_user_club_id() {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return 0;
		}

		$club_id = get_user_meta( $user_id, 'club_id', true );

		return $club_id ? (int) $club_id : 0;
	}

	private function get_club_by_id( $club_id ) {
		global $wpdb;

		if ( ! $club_id ) {
			return null;
		}

		if ( class_exists( 'UFSC_LC_Scope' ) ) {
			UFSC_LC_Scope::enforce_object_scope( (int) $club_id, 'club' );
		}

		$table = $this->get_clubs_table();

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, nom, responsable_id FROM {$table} WHERE id = %d",
				$club_id
			)
		);
	}

	private function get_search_filters() {
		$season_label  = __( 'Saison (optionnel)', 'ufsc-licence-competition' );
		$season_column = $this->get_season_column();
		if ( 'season_end_year' === $season_column ) {
			$season_label = __( 'Saison (année de fin)', 'ufsc-licence-competition' );
		} elseif ( '' !== $season_column ) {
			$season_label = __( 'Saison', 'ufsc-licence-competition' );
		}

		return array(
			'submitted'          => isset( $_GET['ufsc_search'] ),
			'query'              => isset( $_GET['ufsc_query'] ) ? sanitize_text_field( wp_unslash( $_GET['ufsc_query'] ) ) : '',
			'nom'                => isset( $_GET['ufsc_nom'] ) ? sanitize_text_field( wp_unslash( $_GET['ufsc_nom'] ) ) : '',
			'prenom'             => isset( $_GET['ufsc_prenom'] ) ? sanitize_text_field( wp_unslash( $_GET['ufsc_prenom'] ) ) : '',
			'date_naissance'     => isset( $_GET['ufsc_date_naissance'] ) ? sanitize_text_field( wp_unslash( $_GET['ufsc_date_naissance'] ) ) : '',
			'club_id'            => isset( $_GET['ufsc_club_id'] ) ? absint( $_GET['ufsc_club_id'] ) : 0,
			'club_search'        => isset( $_GET['ufsc_club_search'] ) ? sanitize_text_field( wp_unslash( $_GET['ufsc_club_search'] ) ) : '',
			'season'             => isset( $_GET['ufsc_season'] ) ? sanitize_text_field( wp_unslash( $_GET['ufsc_season'] ) ) : '',
			'search_without_club'=> isset( $_GET['ufsc_search_without_club'] ),
			'season_label'       => $season_label,
		);
	}

	private function find_licences( array $filters ) {
		global $wpdb;

		$licences_table = $this->get_licences_table();
		$clubs_table    = $this->get_clubs_table();
		$documents_table = $this->get_documents_table();
		$has_documents_table = $this->table_exists( $documents_table );

		$has_nom        = $this->has_column( $licences_table, 'nom' );
		$has_nom_licence = $this->has_column( $licences_table, 'nom_licence' );
		$name_clauses   = array();
		$params         = array();
		$where          = array();
		$query          = trim( (string) $filters['query'] );
		$has_query      = '' !== $query;

		if ( $has_query ) {
			$asptt_match = array();
			if ( preg_match( '/^asptt\s*[:\-]?\s*(.+)$/i', $query, $asptt_match ) ) {
				$asptt_value = trim( $asptt_match[1] );
				$asptt_columns = $this->get_asptt_number_columns();
				if ( '' !== $asptt_value && ! empty( $asptt_columns ) ) {
					$asptt_like = '%' . $wpdb->esc_like( $asptt_value ) . '%';
					$asptt_clauses = array();
					foreach ( $asptt_columns as $column ) {
						$asptt_clauses[] = "l.{$column} LIKE %s";
						$params[] = $asptt_like;
					}
					$where[] = '(' . implode( ' OR ', $asptt_clauses ) . ')';
				}
			} elseif ( ctype_digit( $query ) ) {
				$number_clauses = array( 'l.id = %d' );
				$params[] = (int) $query;
				foreach ( $this->get_licence_number_columns() as $column ) {
					$number_clauses[] = "l.{$column} = %s";
					$params[] = $query;
				}
				$where[] = '(' . implode( ' OR ', $number_clauses ) . ')';
			} else {
				if ( $has_nom ) {
					$name_clauses[] = 'l.nom LIKE %s';
					$params[]       = '%' . $wpdb->esc_like( $query ) . '%';
				}
				if ( $has_nom_licence ) {
					$name_clauses[] = 'l.nom_licence LIKE %s';
					$params[]       = '%' . $wpdb->esc_like( $query ) . '%';
				}
				$name_clauses[] = 'l.prenom LIKE %s';
				$params[]       = '%' . $wpdb->esc_like( $query ) . '%';

				$where[] = '(' . implode( ' OR ', $name_clauses ) . ')';

				if ( '' !== $filters['date_naissance'] ) {
					$where[] = 'l.date_naissance = %s';
					$params[] = $filters['date_naissance'];
				}
			}
		} else {
			if ( $has_nom ) {
				$name_clauses[] = 'l.nom LIKE %s';
				$params[]       = '%' . $wpdb->esc_like( $filters['nom'] ) . '%';
			}
			if ( $has_nom_licence ) {
				$name_clauses[] = 'l.nom_licence LIKE %s';
				$params[]       = '%' . $wpdb->esc_like( $filters['nom'] ) . '%';
			}
			if ( empty( $name_clauses ) ) {
				$name_clauses[] = '1=0';
			}

			$where[] = '(' . implode( ' OR ', $name_clauses ) . ')';
			$where[] = 'l.prenom LIKE %s';
			$where[] = 'l.date_naissance = %s';
			$params[] = '%' . $wpdb->esc_like( $filters['prenom'] ) . '%';
			$params[] = $filters['date_naissance'];
		}

		if ( $filters['club_id'] && ( $has_query || ! $filters['search_without_club'] ) ) {
			$where[] = 'l.club_id = %d';
			$params[] = $filters['club_id'];
		}

		if ( '' !== $filters['season'] ) {
			$season_sql = $this->get_season_coalesce_sql( 'l' );
			if ( "''" !== $season_sql ) {
				$where[] = "{$season_sql} = %s";
				$params[] = $filters['season'];
			}
		}

		if ( empty( $where ) ) {
			$where[] = '1=0';
		}

		$repository = new UFSC_LC_Licence_Repository();
		$repository->apply_scope_filter( $where, $params, 'c' );

		$category_column = $this->get_category_column();
		$category_sql    = $category_column ? "l.{$category_column} AS category_value" : "'' AS category_value";

		$category_parts = array();
		if ( $this->has_column( $licences_table, 'categorie' ) ) {
			$category_parts[] = 'NULLIF(l.categorie, \'\')';
		}
		if ( $this->has_column( $licences_table, 'category' ) ) {
			$category_parts[] = 'NULLIF(l.category, \'\')';
		}
		if ( $this->has_column( $licences_table, 'legacy_category' ) ) {
			$category_parts[] = 'NULLIF(l.legacy_category, \'\')';
		}
		$categorie_affiche_sql = empty( $category_parts ) ? "''" : 'COALESCE(' . implode( ', ', $category_parts ) . ')';

		$season_sql = $this->get_season_coalesce_sql( 'l' ) . ' AS season_value';
		$licence_number_sql = $this->get_licence_number_sql( 'l' );
		$asptt_number_sql   = $this->get_asptt_number_sql( 'l', $has_documents_table ? 'd' : '' );

		$where_sql = 'WHERE ' . implode( ' AND ', $where );

		$nom_parts = array();
		if ( $has_nom ) {
			$nom_parts[] = 'NULLIF(l.nom, \'\')';
		}
		if ( $has_nom_licence ) {
			$nom_parts[] = 'NULLIF(l.nom_licence, \'\')';
		}
		$nom_affiche_sql = empty( $nom_parts ) ? "''" : 'COALESCE(' . implode( ', ', $nom_parts ) . ')';

		$join_documents = '';
		$document_params = array();
		$select_documents = '0 AS has_pdf';

		if ( $has_documents_table ) {
			$join_documents = "LEFT JOIN {$documents_table} d ON d.licence_id = l.id AND d.source = %s";
			$document_params[] = self::SOURCE;
			$select_documents = "CASE WHEN d.attachment_id IS NULL THEN 0 ELSE 1 END AS has_pdf";
		}

		$sql = "SELECT l.id, {$licence_number_sql} AS licence_number, {$asptt_number_sql} AS asptt_number, {$nom_affiche_sql} AS nom_affiche, l.prenom, l.date_naissance, l.statut, l.competition, {$category_sql}, {$categorie_affiche_sql} AS categorie_affiche, {$season_sql},
			l.club_id, c.nom AS club_name, {$select_documents}
			FROM {$licences_table} l
			LEFT JOIN {$clubs_table} c ON c.id = l.club_id
			{$join_documents}
			{$where_sql}
			ORDER BY {$nom_affiche_sql} ASC, l.prenom ASC, l.id ASC
			LIMIT 50";

		return $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $document_params, $params ) ) );
	}

	private function render_licence_summary( $item, $season_label ) {
		$nom_affiche = ufsc_lc_get_nom_affiche( $item );
		$birthdate   = ufsc_lc_format_birthdate( $item->date_naissance ?? '' );
		$category    = $item->categorie_affiche ?? ( $item->category_value ?? '' );
		if ( '' === $category && '' !== $birthdate ) {
			$season_end_year = UFSC_LC_Categories::sanitize_season_end_year( $item->season_value ?? '' );
			if ( null === $season_end_year ) {
				$season_end_year = $this->get_default_season_end_year();
			}
			$computed = UFSC_LC_Categories::category_from_birthdate( $birthdate, $season_end_year );
			$category = isset( $computed['category'] ) ? (string) $computed['category'] : '';
		} elseif ( '' === $birthdate ) {
			$category = '';
		}
		$lines = array(
			'<strong>' . esc_html__( 'Licence ID', 'ufsc-licence-competition' ) . '</strong>: ' . esc_html( $item->id ),
			'<strong>' . esc_html__( 'N° licence', 'ufsc-licence-competition' ) . '</strong>: ' . esc_html( $item->licence_number ? $item->licence_number : __( '—', 'ufsc-licence-competition' ) ),
			'<strong>' . esc_html__( 'N° ASPTT', 'ufsc-licence-competition' ) . '</strong>: ' . esc_html( $item->asptt_number ? $item->asptt_number : __( '—', 'ufsc-licence-competition' ) ),
			'<strong>' . esc_html__( 'Nom', 'ufsc-licence-competition' ) . '</strong>: ' . esc_html( '' !== $nom_affiche ? $nom_affiche : __( '—', 'ufsc-licence-competition' ) ),
			'<strong>' . esc_html__( 'Prénom', 'ufsc-licence-competition' ) . '</strong>: ' . esc_html( $item->prenom ),
			'<strong>' . esc_html__( 'Date de naissance', 'ufsc-licence-competition' ) . '</strong>: ' . esc_html( '' !== $birthdate ? $birthdate : __( '—', 'ufsc-licence-competition' ) ),
			'<strong>' . esc_html__( 'Club', 'ufsc-licence-competition' ) . '</strong>: ' . esc_html( $item->club_name ? $item->club_name : __( '—', 'ufsc-licence-competition' ) ),
			'<strong>' . esc_html( $season_label ) . '</strong>: ' . esc_html( $item->season_value ? $item->season_value : __( '—', 'ufsc-licence-competition' ) ),
			'<strong>' . esc_html__( 'Catégorie', 'ufsc-licence-competition' ) . '</strong>: ' . esc_html( $category ? $category : __( '—', 'ufsc-licence-competition' ) ),
			'<strong>' . esc_html__( 'PDF', 'ufsc-licence-competition' ) . '</strong>: ' . esc_html( ! empty( $item->has_pdf ) ? __( 'Associé', 'ufsc-licence-competition' ) : __( 'Manquant', 'ufsc-licence-competition' ) ),
		);

		return '<ul class="ufsc-lc-summary"><li>' . implode( '</li><li>', $lines ) . '</li></ul>';
	}

	private function get_licence_record( $licence_id, $numero_licence ) {
		global $wpdb;

		$table = $this->get_licences_table();
		$licence_number_column = $this->has_column( $table, 'numero_licence_delegataire' ) ? 'numero_licence_delegataire' : ( $this->has_column( $table, 'licence_number' ) ? 'licence_number' : '' );
		$select_columns = 'id, club_id';
		if ( $licence_number_column ) {
			$select_columns .= ', ' . $licence_number_column;
		}

		if ( $licence_id ) {
			return $wpdb->get_row( $wpdb->prepare( "SELECT {$select_columns} FROM {$table} WHERE id = %d", $licence_id ) );
		}

		$licence_columns = $this->get_licence_number_columns();
		if ( empty( $licence_columns ) ) {
			return null;
		}

		$where_clauses = array();
		$params = array();
		foreach ( $licence_columns as $column ) {
			$where_clauses[] = "{$column} = %s";
			$params[] = $numero_licence;
		}

		$where_sql = implode( ' OR ', $where_clauses );
		$sql = "SELECT {$select_columns} FROM {$table} WHERE {$where_sql} LIMIT 1";

		return $wpdb->get_row( $wpdb->prepare( $sql, $params ) );
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

	private function detach_document( $licence_id ) {
		global $wpdb;

		$table = $this->get_documents_table();
		if ( ! $this->table_exists( $table ) ) {
			return;
		}

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET attachment_id = NULL, updated_at = %s WHERE licence_id = %d AND source = %s",
				current_time( 'mysql' ),
				$licence_id,
				self::SOURCE
			)
		);
	}

	private function sync_pdf_meta( $licence_id, $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		$this->update_document_meta( (int) $licence_id, 'ufsc_licence_pdf_attachment_id', $attachment_id );
		$this->update_document_meta( (int) $licence_id, 'pdf_attachment_id', $attachment_id );
	}

	private function log_pdf_action( $action, $licence_id, $old_attachment_id, $new_attachment_id ) {
		UFSC_LC_Logger::log(
			__( 'PDF licence mis à jour.', 'ufsc-licence-competition' ),
			array(
				'action'            => $action,
				'licence_id'        => (int) $licence_id,
				'old_attachment_id' => (int) $old_attachment_id,
				'new_attachment_id' => (int) $new_attachment_id,
			)
		);
	}

	private function upsert_document( $licence_id, $source_number, $attachment_id ) {
		global $wpdb;

		$table = $this->get_documents_table();

		$existing_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE licence_id = %d AND source = %s",
				$licence_id,
				self::SOURCE
			)
		);

		$source_number = '' !== $source_number ? $source_number : (string) $licence_id;

		$data = array(
			'licence_id'            => $licence_id,
			'source'                => self::SOURCE,
			'source_licence_number' => $source_number,
			'attachment_id'         => $attachment_id,
			'asptt_club_note'        => null,
			'updated_at'             => current_time( 'mysql' ),
		);

		$formats = array( '%d', '%s', '%s', '%d', '%s', '%s' );

		if ( $existing_id ) {
			$wpdb->update( $table, $data, array( 'id' => (int) $existing_id ), $formats, array( '%d' ) );
		} else {
			$data['imported_at'] = current_time( 'mysql' );
			$wpdb->insert( $table, $data, $formats );
		}
	}

	private function update_document_meta( $licence_id, $meta_key, $meta_value ) {
		global $wpdb;

		$table = $this->get_documents_meta_table();
		if ( ! $this->table_exists( $table ) ) {
			return;
		}

		$existing_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE licence_id = %d AND source = %s AND meta_key = %s",
				$licence_id,
				self::SOURCE,
				$meta_key
			)
		);

		$data = array(
			'licence_id' => $licence_id,
			'source'     => self::SOURCE,
			'meta_key'   => $meta_key,
			'meta_value' => maybe_serialize( $meta_value ),
			'updated_at' => current_time( 'mysql' ),
		);

		$formats = array( '%d', '%s', '%s', '%s', '%s' );

		if ( $existing_id ) {
			$wpdb->update( $table, $data, array( 'id' => (int) $existing_id ), $formats, array( '%d' ) );
		} else {
			$wpdb->insert( $table, $data, $formats );
		}
	}

	private function get_default_season_end_year() {
		if ( class_exists( 'UFSC_LC_Settings_Page' ) ) {
			return UFSC_LC_Settings_Page::get_default_season_end_year();
		}

		$year = (int) gmdate( 'Y' );
		return UFSC_LC_Categories::sanitize_season_end_year( $year );
	}

	private function get_season_column() {
		$table = $this->get_licences_table();

		if ( $this->has_column( $table, 'season_end_year' ) ) {
			return 'season_end_year';
		}

		foreach ( array( 'saison', 'season' ) as $column ) {
			if ( $this->has_column( $table, $column ) ) {
				return $column;
			}
		}

		return '';
	}

	private function get_category_column() {
		$table = $this->get_licences_table();

		if ( $this->has_column( $table, 'category' ) ) {
			return 'category';
		}

		if ( $this->has_column( $table, 'categorie' ) ) {
			return 'categorie';
		}

		return '';
	}

	private function get_licence_number_columns() {
		$table = $this->get_licences_table();
		$columns = array();
		foreach ( array( 'numero_licence_delegataire', 'licence_number' ) as $column ) {
			if ( $this->has_column( $table, $column ) ) {
				$columns[] = $column;
			}
		}
		return $columns;
	}

	private function get_asptt_number_columns() {
		$table = $this->get_licences_table();
		$columns = array();
		foreach ( array( 'numero_licence_asptt', 'asptt_number' ) as $column ) {
			if ( $this->has_column( $table, $column ) ) {
				$columns[] = $column;
			}
		}
		return $columns;
	}

	private function get_licence_number_sql( $alias ) {
		$parts = array();
		foreach ( $this->get_licence_number_columns() as $column ) {
			$parts[] = "NULLIF({$alias}.{$column}, '')";
		}
		$parts[] = "{$alias}.id";
		return 'COALESCE(' . implode( ', ', $parts ) . ')';
	}

	private function get_asptt_number_sql( $alias, $documents_alias = '' ) {
		$parts = array();
		foreach ( $this->get_asptt_number_columns() as $column ) {
			$parts[] = "NULLIF({$alias}.{$column}, '')";
		}
		if ( $documents_alias ) {
			$parts[] = "NULLIF({$documents_alias}.source_licence_number, '')";
		}
		if ( empty( $parts ) ) {
			return "''";
		}
		return 'COALESCE(' . implode( ', ', $parts ) . ')';
	}

	private function get_season_coalesce_sql( $alias ) {
		$table = $this->get_licences_table();
		$parts = array();
		foreach ( array( 'season_end_year', 'season', 'saison' ) as $column ) {
			if ( $this->has_column( $table, $column ) ) {
				$parts[] = "NULLIF({$alias}.{$column}, '')";
			}
		}
		if ( empty( $parts ) ) {
			return "''";
		}
		return 'COALESCE(' . implode( ', ', $parts ) . ')';
	}

	private function has_column( $table, $column ) {
		global $wpdb;

		$results = $wpdb->get_row( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $column ) );

		return ! empty( $results );
	}

	private function table_exists( $table ) {
		global $wpdb;

		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		return $exists === $table;
	}

	private function get_documents_table() {
		global $wpdb;

		return $wpdb->prefix . 'ufsc_licence_documents';
	}

	private function get_documents_meta_table() {
		global $wpdb;

		return $wpdb->prefix . 'ufsc_licence_documents_meta';
	}

	private function get_licences_table() {
		global $wpdb;

		return $wpdb->prefix . 'ufsc_licences';
	}

	private function get_clubs_table() {
		global $wpdb;

		return $wpdb->prefix . 'ufsc_clubs';
	}

	private function redirect_with_message( $status, $message ) {
		$redirect_url = add_query_arg(
			array(
				'page'         => 'ufsc-licence-documents',
				'ufsc_status'  => $status,
				'ufsc_message' => rawurlencode( $message ),
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect_url );
		exit;
	}
}
