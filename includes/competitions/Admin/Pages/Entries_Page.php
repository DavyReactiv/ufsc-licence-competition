<?php

namespace UFSC\Competitions\Admin\Pages;

use UFSC\Competitions\Capabilities;
use UFSC\Competitions\Admin\Menu;
use UFSC\Competitions\Repositories\EntryRepository;
use UFSC\Competitions\Repositories\CompetitionRepository;
use UFSC\Competitions\Repositories\CategoryRepository;
use UFSC\Competitions\Admin\Tables\Entries_Table;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Entries_Page {
	private $repository;
	private $competition_repository;
	private $category_repository;

	public function __construct() {
		$this->repository = new EntryRepository();
		$this->competition_repository = new CompetitionRepository();
		$this->category_repository = new CategoryRepository();
	}

	public function register_actions() {
		static $registered = false;
		if ( $registered ) {
			return;
		}
		$registered = true;

		add_action( 'admin_post_ufsc_competitions_save_entry', array( $this, 'handle_save' ) );
		add_action( 'admin_post_ufsc_competitions_trash_entry', array( $this, 'handle_trash' ) );
		add_action( 'admin_post_ufsc_competitions_restore_entry', array( $this, 'handle_restore' ) );
		add_action( 'admin_post_ufsc_competitions_delete_entry', array( $this, 'handle_delete' ) );
		add_action( 'wp_ajax_ufsc_lc_search_licence', array( $this, 'ajax_search_licence' ) );
		add_action( 'wp_ajax_ufsc_lc_get_licensee', array( $this, 'ajax_get_licensee' ) );
	}

	public function render() {
		if ( ! Capabilities::user_can_manage() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ) );
		}

		$action = isset( $_GET['ufsc_action'] ) ? sanitize_key( wp_unslash( $_GET['ufsc_action'] ) ) : '';
		$notice = isset( $_GET['ufsc_notice'] ) ? sanitize_key( wp_unslash( $_GET['ufsc_notice'] ) ) : '';
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

		$this->render_notice( $notice );

		if ( in_array( $action, array( 'add', 'edit' ), true ) ) {
			$item = null;
			if ( 'edit' === $action && $id ) {
				$item = $this->repository->get( $id, true );
			}
			$this->render_form( $item );
			return;
		}

		$list_table = new Entries_Table();
		$this->maybe_handle_bulk_actions( $list_table, Menu::PAGE_ENTRIES );
		$list_table->prepare_items();

		?>
		<div class="wrap ufsc-competitions-admin">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Inscriptions', 'ufsc-licence-competition' ); ?></h1>
			<a href="<?php echo esc_url( add_query_arg( array( 'page' => Menu::PAGE_ENTRIES, 'ufsc_action' => 'add' ), admin_url( 'admin.php' ) ) ); ?>" class="page-title-action"><?php esc_html_e( 'Ajouter', 'ufsc-licence-competition' ); ?></a>
			<hr class="wp-header-end">
			<?php $this->render_helper_notice( __( 'Ajouter/valider les inscrits, contrôler doublons, gérer la forclusion.', 'ufsc-licence-competition' ) ); ?>
			<?php $list_table->views(); ?>
			<form method="post">
				<input type="hidden" name="page" value="<?php echo esc_attr( Menu::PAGE_ENTRIES ); ?>" />
				<?php $list_table->search_box( __( 'Rechercher', 'ufsc-licence-competition' ), 'ufsc-competition-entries-search' ); ?>
				<div class="ufsc-competitions-table-wrap">
					<?php $list_table->display(); ?>
				</div>
			</form>
		</div>
		<?php
	}

	public function handle_save() {
		if ( ! Capabilities::user_can_manage() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'ufsc_competitions_save_entry' );

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$data = array(
			'competition_id' => isset( $_POST['competition_id'] ) ? absint( $_POST['competition_id'] ) : 0,
			'category_id'    => isset( $_POST['category_id'] ) ? absint( $_POST['category_id'] ) : 0,
			'club_id'        => isset( $_POST['club_id'] ) ? absint( $_POST['club_id'] ) : 0,
			'licensee_id'    => isset( $_POST['licensee_id'] ) ? absint( $_POST['licensee_id'] ) : 0,
			'status'         => isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'draft',
		);
		$selected_licensee_id = isset( $_POST['selected_licensee_id'] ) ? absint( $_POST['selected_licensee_id'] ) : 0;
		if ( ! $data['licensee_id'] && $selected_licensee_id ) {
			$data['licensee_id'] = $selected_licensee_id;
		}

		if ( ! $data['competition_id'] || ! $data['licensee_id'] ) {
			$this->redirect_with_notice( Menu::PAGE_ENTRIES, 'error_required', $id );
		}

		if ( ! $id && $this->repository->get_by_competition_licensee( $data['competition_id'], $data['licensee_id'] ) ) {
			$this->redirect_with_notice( Menu::PAGE_ENTRIES, 'duplicate', $id );
		}

		if ( $id ) {
			$this->repository->update( $id, $data );
			$this->redirect_with_notice( Menu::PAGE_ENTRIES, 'updated', $id );
		}

		$new_id = $this->repository->insert( $data );
		$this->redirect_with_notice( Menu::PAGE_ENTRIES, 'created', $new_id );
	}

	public function handle_trash() {
		$this->handle_simple_action( 'ufsc_competitions_trash_entry', 'trash', Menu::PAGE_ENTRIES );
	}

	public function handle_restore() {
		$this->handle_simple_action( 'ufsc_competitions_restore_entry', 'restore', Menu::PAGE_ENTRIES );
	}

	public function handle_delete() {
		$this->handle_simple_action( 'ufsc_competitions_delete_entry', 'delete', Menu::PAGE_ENTRIES );
	}

	public function ajax_search_licence() {
		if ( ! Capabilities::user_can_manage() ) {
			wp_send_json_error( array( 'message' => __( 'Accès refusé.', 'ufsc-licence-competition' ) ), 403 );
		}

		check_ajax_referer( 'ufsc_lc_entries', 'nonce' );

		$nom            = isset( $_POST['nom'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['nom'] ) ) ) : '';
		$prenom         = isset( $_POST['prenom'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['prenom'] ) ) ) : '';
		$date_naissance = isset( $_POST['date_naissance'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['date_naissance'] ) ) ) : '';
		$competition_id = isset( $_POST['competition_id'] ) ? absint( $_POST['competition_id'] ) : 0;

		if ( '' === $nom && '' === $prenom && '' === $date_naissance ) {
			wp_send_json_error( array( 'message' => __( 'Veuillez saisir au moins un critère de recherche.', 'ufsc-licence-competition' ) ) );
		}

		$normalized_birthdate = $this->normalize_birthdate( $date_naissance );
		$season_end_year      = $this->get_competition_season_end_year( $competition_id );

		global $wpdb;

		$licences_table = $wpdb->prefix . 'ufsc_licences';
		$clubs_table    = $wpdb->prefix . 'ufsc_clubs';
		$name_expr      = "COALESCE(NULLIF(l.nom,''), NULLIF(l.nom_licence,''))";

		$where  = array();
		$params = array();

		if ( '' !== $nom ) {
			$where[]  = "{$name_expr} LIKE %s";
			$params[] = '%' . $wpdb->esc_like( $nom ) . '%';
		}

		if ( '' !== $prenom ) {
			$where[]  = 'l.prenom LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $prenom ) . '%';
		}

		if ( '' !== $normalized_birthdate ) {
			$where[]  = 'l.date_naissance = %s';
			$params[] = $normalized_birthdate;
		}

		$where_sql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';

		$sql = "SELECT l.id AS licence_id, {$name_expr} AS nom, l.prenom, l.date_naissance, l.club_id, c.nom AS club_nom
			FROM {$licences_table} l
			LEFT JOIN {$clubs_table} c ON c.id = l.club_id
			{$where_sql}
			ORDER BY {$name_expr} ASC, l.prenom ASC, l.id ASC
			LIMIT %d";

		$params[] = 20;
		$query    = $wpdb->prepare( $sql, $params );
		$rows     = $wpdb->get_results( $query, ARRAY_A );
		if ( $wpdb->last_error ) {
			wp_send_json_error(
				array(
					'message' => __( 'Erreur interne lors de la recherche.', 'ufsc-licence-competition' ),
				),
				500
			);
		}
		if ( ! is_array( $rows ) ) {
			$rows = array();
		}

		$results = array();
		foreach ( $rows as $row ) {
			$birthdate_raw = trim( sanitize_text_field( $row['date_naissance'] ?? '' ) );
			$category      = '';
			if ( $birthdate_raw && function_exists( 'ufsc_lc_compute_category_from_birthdate' ) ) {
				$category = ufsc_lc_compute_category_from_birthdate( $birthdate_raw, $season_end_year );
			}

			$results[] = array(
				'licence_id'         => absint( $row['licence_id'] ?? 0 ),
				'nom'                => trim( sanitize_text_field( $row['nom'] ?? '' ) ),
				'prenom'             => trim( sanitize_text_field( $row['prenom'] ?? '' ) ),
				'date_naissance'     => $birthdate_raw,
				'date_naissance_fmt' => $this->format_birthdate( $birthdate_raw ),
				'club_id'            => absint( $row['club_id'] ?? 0 ),
				'club_nom'           => trim( sanitize_text_field( $row['club_nom'] ?? '' ) ),
				'category'           => $category,
			);
		}

		wp_send_json_success(
			array(
				'results' => $results,
				'message' => $results ? '' : __( 'Aucun résultat trouvé.', 'ufsc-licence-competition' ),
			)
		);
	}

	public function ajax_get_licensee() {
		if ( ! Capabilities::user_can_manage() ) {
			wp_send_json_error( array( 'message' => __( 'Accès refusé.', 'ufsc-licence-competition' ) ), 403 );
		}

		check_ajax_referer( 'ufsc_lc_entries', 'nonce' );

		$licensee_id   = isset( $_POST['licensee_id'] ) ? absint( $_POST['licensee_id'] ) : 0;
		$competition_id = isset( $_POST['competition_id'] ) ? absint( $_POST['competition_id'] ) : 0;

		if ( ! $licensee_id ) {
			wp_send_json_error( array( 'message' => __( 'Identifiant licencié invalide.', 'ufsc-licence-competition' ) ) );
		}

		$season_end_year = $this->get_competition_season_end_year( $competition_id );

		global $wpdb;

		$licences_table = $wpdb->prefix . 'ufsc_licences';
		$clubs_table    = $wpdb->prefix . 'ufsc_clubs';
		$name_expr      = "COALESCE(NULLIF(l.nom,''), NULLIF(l.nom_licence,''))";

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT l.id AS licence_id, {$name_expr} AS nom, l.prenom, l.date_naissance, l.club_id, c.nom AS club_nom
				FROM {$licences_table} l
				LEFT JOIN {$clubs_table} c ON c.id = l.club_id
				WHERE l.id = %d
				LIMIT 1",
				$licensee_id
			),
			ARRAY_A
		);

		if ( ! $row ) {
			wp_send_json_error( array( 'message' => __( 'Licencié introuvable.', 'ufsc-licence-competition' ) ) );
		}

		$birthdate_raw = trim( sanitize_text_field( $row['date_naissance'] ?? '' ) );
		$category      = '';
		if ( $birthdate_raw && function_exists( 'ufsc_lc_compute_category_from_birthdate' ) ) {
			$category = ufsc_lc_compute_category_from_birthdate( $birthdate_raw, $season_end_year );
		}

		$data = array(
			'licence_id'         => absint( $row['licence_id'] ?? 0 ),
			'nom'                => trim( sanitize_text_field( $row['nom'] ?? '' ) ),
			'prenom'             => trim( sanitize_text_field( $row['prenom'] ?? '' ) ),
			'date_naissance'     => $birthdate_raw,
			'date_naissance_fmt' => $this->format_birthdate( $birthdate_raw ),
			'club_id'            => absint( $row['club_id'] ?? 0 ),
			'club_nom'           => trim( sanitize_text_field( $row['club_nom'] ?? '' ) ),
			'category'           => $category,
		);

		wp_send_json_success( $data );
	}

	private function normalize_birthdate( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return '';
		}

		$timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
		$formats  = array( 'Y-m-d', 'd/m/Y' );
		foreach ( $formats as $format ) {
			$parsed = \DateTimeImmutable::createFromFormat( '!' . $format, $raw, $timezone );
			if ( $parsed && $parsed->format( $format ) === $raw ) {
				return $parsed->format( 'Y-m-d' );
			}
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

		$timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
		$parsed   = \DateTimeImmutable::createFromFormat( '!Y-m-d', $raw, $timezone );
		if ( $parsed && $parsed->format( 'Y-m-d' ) === $raw ) {
			return $parsed->format( 'd/m/Y' );
		}

		return $raw;
	}

	private function get_competition_season_end_year( $competition_id ) {
		if ( ! $competition_id ) {
			return '';
		}

		$competition = $this->competition_repository->get( $competition_id, true );
		return $competition ? (string) ( $competition->season ?? '' ) : '';
	}

	private function handle_simple_action( $action, $method, $page_slug ) {
		if ( ! Capabilities::user_can_manage() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		check_admin_referer( $action . '_' . $id );

		if ( ! $id ) {
			$this->redirect_with_notice( $page_slug, 'not_found' );
		}

		switch ( $method ) {
			case 'trash':
				$this->repository->soft_delete( $id );
				$this->redirect_with_notice( $page_slug, 'trashed' );
				break;
			case 'restore':
				$this->repository->restore( $id );
				$this->redirect_with_notice( $page_slug, 'restored' );
				break;
			case 'delete':
				if ( ! Capabilities::user_can_delete() ) {
					wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
				}
				$this->repository->delete( $id );
				$this->redirect_with_notice( $page_slug, 'deleted' );
				break;
		}
	}

	private function render_form( $item ) {
		$values = array(
			'id'             => $item->id ?? 0,
			'competition_id' => $item->competition_id ?? 0,
			'category_id'    => $item->category_id ?? 0,
			'club_id'        => $item->club_id ?? 0,
			'licensee_id'    => $item->licensee_id ?? 0,
			'status'         => $item->status ?? 'draft',
		);

		$competitions = $this->competition_repository->list( array( 'view' => 'all' ), 200, 0 );
		$categories = $this->category_repository->list( array( 'view' => 'all' ), 500, 0 );
		$action_label = $values['id'] ? __( 'Mettre à jour', 'ufsc-licence-competition' ) : __( 'Créer l\'inscription', 'ufsc-licence-competition' );
		?>
		<div class="wrap ufsc-competitions-admin">
			<h1><?php echo esc_html( $values['id'] ? __( 'Modifier l\'inscription', 'ufsc-licence-competition' ) : __( 'Nouvelle inscription', 'ufsc-licence-competition' ) ); ?></h1>
			<?php $this->render_helper_notice( __( 'Ajouter/valider les inscrits, contrôler doublons, gérer la forclusion.', 'ufsc-licence-competition' ) ); ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ufsc-competitions-form">
				<?php wp_nonce_field( 'ufsc_competitions_save_entry' ); ?>
				<input type="hidden" name="action" value="ufsc_competitions_save_entry">
				<input type="hidden" name="id" value="<?php echo esc_attr( $values['id'] ); ?>">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ufsc_entry_competition"><?php esc_html_e( 'Compétition', 'ufsc-licence-competition' ); ?></label></th>
						<td>
							<select name="competition_id" id="ufsc_entry_competition" class="regular-text" required>
								<option value="0"><?php esc_html_e( 'Sélectionner', 'ufsc-licence-competition' ); ?></option>
								<?php foreach ( $competitions as $competition ) : ?>
									<option value="<?php echo esc_attr( $competition->id ); ?>" <?php selected( $values['competition_id'], $competition->id ); ?>><?php echo esc_html( $competition->name ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_entry_category"><?php esc_html_e( 'Catégorie', 'ufsc-licence-competition' ); ?></label></th>
						<td>
							<select name="category_id" id="ufsc_entry_category" class="regular-text">
								<option value="0"><?php esc_html_e( 'Auto / non assignée', 'ufsc-licence-competition' ); ?></option>
								<?php foreach ( $categories as $category ) : ?>
									<option value="<?php echo esc_attr( $category->id ); ?>" <?php selected( $values['category_id'], $category->id ); ?>><?php echo esc_html( $category->name ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description ufsc-entry-auto-category" id="ufsc_entry_auto_category_preview"></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_entry_licensee_search_nom"><?php esc_html_e( 'Rechercher un licencié', 'ufsc-licence-competition' ); ?></label></th>
						<td>
							<fieldset class="ufsc-entry-licensee-search">
								<div class="ufsc-entry-licensee-search-fields">
									<label>
										<span class="screen-reader-text"><?php esc_html_e( 'Nom', 'ufsc-licence-competition' ); ?></span>
										<input type="text" id="ufsc_entry_licensee_search_nom" placeholder="<?php echo esc_attr__( 'Nom', 'ufsc-licence-competition' ); ?>">
									</label>
									<label>
										<span class="screen-reader-text"><?php esc_html_e( 'Prénom', 'ufsc-licence-competition' ); ?></span>
										<input type="text" id="ufsc_entry_licensee_search_prenom" placeholder="<?php echo esc_attr__( 'Prénom', 'ufsc-licence-competition' ); ?>">
									</label>
									<label>
										<span class="screen-reader-text"><?php esc_html_e( 'Date de naissance', 'ufsc-licence-competition' ); ?></span>
										<input type="date" id="ufsc_entry_licensee_search_birthdate" placeholder="<?php echo esc_attr__( 'JJ/MM/AAAA', 'ufsc-licence-competition' ); ?>">
									</label>
									<button type="button" class="button" id="ufsc_entry_licensee_search_button"><?php esc_html_e( 'Rechercher', 'ufsc-licence-competition' ); ?></button>
								</div>
								<p class="description"><?php esc_html_e( 'Recherche par nom, prénom et/ou date de naissance (formats acceptés : JJ/MM/AAAA ou AAAA-MM-JJ).', 'ufsc-licence-competition' ); ?></p>
								<div class="ufsc-entry-licensee-search-message" id="ufsc_entry_licensee_search_message" role="status" aria-live="polite"></div>
								<div class="ufsc-entry-licensee-search-results" id="ufsc_entry_licensee_search_results"></div>
								<div class="ufsc-entry-licensee-search-actions">
									<button type="button" class="button button-primary" id="ufsc_entry_use_licensee" disabled><?php esc_html_e( 'Utiliser ce licencié', 'ufsc-licence-competition' ); ?></button>
									<span class="ufsc-entry-licensee-selected" id="ufsc_entry_licensee_selected"></span>
								</div>
								<input type="hidden" name="selected_licensee_id" id="ufsc_entry_selected_licensee" value="<?php echo esc_attr( $values['licensee_id'] ); ?>">
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_entry_licensee"><?php esc_html_e( 'ID licencié', 'ufsc-licence-competition' ); ?></label></th>
						<td><input name="licensee_id" type="number" id="ufsc_entry_licensee" value="<?php echo esc_attr( $values['licensee_id'] ); ?>" required></td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_entry_club"><?php esc_html_e( 'ID club', 'ufsc-licence-competition' ); ?></label></th>
						<td><input name="club_id" type="number" id="ufsc_entry_club" value="<?php echo esc_attr( $values['club_id'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_entry_status"><?php esc_html_e( 'Statut', 'ufsc-licence-competition' ); ?></label></th>
						<td>
							<select name="status" id="ufsc_entry_status" class="regular-text">
								<option value="draft" <?php selected( $values['status'], 'draft' ); ?>><?php esc_html_e( 'Brouillon', 'ufsc-licence-competition' ); ?></option>
								<option value="submitted" <?php selected( $values['status'], 'submitted' ); ?>><?php esc_html_e( 'Soumise', 'ufsc-licence-competition' ); ?></option>
								<option value="validated" <?php selected( $values['status'], 'validated' ); ?>><?php esc_html_e( 'Validée', 'ufsc-licence-competition' ); ?></option>
								<option value="rejected" <?php selected( $values['status'], 'rejected' ); ?>><?php esc_html_e( 'Rejetée', 'ufsc-licence-competition' ); ?></option>
								<option value="withdrawn" <?php selected( $values['status'], 'withdrawn' ); ?>><?php esc_html_e( 'Retirée', 'ufsc-licence-competition' ); ?></option>
							</select>
						</td>
					</tr>
				</table>
				<?php submit_button( $action_label ); ?>
			</form>
		</div>
		<?php
	}

	private function redirect_with_notice( $page, $notice, $id = 0 ) {
		$url = add_query_arg(
			array(
				'page'        => $page,
				'ufsc_notice' => $notice,
			),
			admin_url( 'admin.php' )
		);

		if ( $id ) {
			$url = add_query_arg( 'id', $id, $url );
		}

		wp_safe_redirect( $url );
		exit;
	}

	private function render_notice( $notice ) {
		$messages = array(
			'created'       => __( 'Inscription créée.', 'ufsc-licence-competition' ),
			'updated'       => __( 'Inscription mise à jour.', 'ufsc-licence-competition' ),
			'trashed'       => __( 'Inscription déplacée dans la corbeille.', 'ufsc-licence-competition' ),
			'restored'      => __( 'Inscription restaurée.', 'ufsc-licence-competition' ),
			'deleted'       => __( 'Inscription supprimée définitivement.', 'ufsc-licence-competition' ),
			'error_required'=> __( 'Veuillez renseigner la compétition et le licencié.', 'ufsc-licence-competition' ),
			'duplicate'     => __( 'Ce licencié est déjà inscrit à cette compétition.', 'ufsc-licence-competition' ),
			'not_found'     => __( 'Inscription introuvable.', 'ufsc-licence-competition' ),
		);

		if ( ! $notice || ! isset( $messages[ $notice ] ) ) {
			return;
		}

		$type = in_array( $notice, array( 'error_required', 'not_found', 'duplicate' ), true ) ? 'error' : 'success';
		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( $messages[ $notice ] ) );
	}

	private function render_helper_notice( $message ) {
		printf(
			'<div class="notice notice-info ufsc-competitions-helper"><p>%s</p></div>',
			esc_html( $message )
		);
	}

	private function maybe_handle_bulk_actions( Entries_Table $list_table, $page_slug ) {
		$action = $list_table->current_action();
		if ( ! $action ) {
			return;
		}

		if ( ! Capabilities::user_can_manage() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'bulk-' . $list_table->_args['plural'] );

		$ids = isset( $_POST['ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['ids'] ) ) : array();
		$ids = array_filter( $ids );
		if ( ! $ids ) {
			return;
		}

		foreach ( $ids as $id ) {
			switch ( $action ) {
				case 'trash':
					$this->repository->soft_delete( $id );
					break;
				case 'restore':
					$this->repository->restore( $id );
					break;
				case 'delete':
					if ( ! Capabilities::user_can_delete() ) {
						wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
					}
					$this->repository->delete( $id );
					break;
			}
		}

		$notice_map = array(
			'trash'   => 'trashed',
			'restore' => 'restored',
			'delete'  => 'deleted',
		);

		$this->redirect_with_notice( $page_slug, $notice_map[ $action ] ?? 'updated' );
	}
}
