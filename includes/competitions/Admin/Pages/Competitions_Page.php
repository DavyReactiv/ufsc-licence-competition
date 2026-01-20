<?php

namespace UFSC\Competitions\Admin\Pages;

use UFSC\Competitions\Capabilities;
use UFSC\Competitions\Admin\Menu;
use UFSC\Competitions\Repositories\CompetitionRepository;
use UFSC\Competitions\Repositories\CategoryRepository;
use UFSC\Competitions\Repositories\EntryRepository;
use UFSC\Competitions\Repositories\FightRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Competitions_Page {
	private $repository;
	private $categories;
	private $entries;
	private $fights;
	private $club_repository;

	public function __construct() {
		// Defensive instantiation: avoid fatal if classes missing
		$this->repository = class_exists( '\\UFSC\\Competitions\\Repositories\\CompetitionRepository' ) ? new CompetitionRepository() : null;
		$this->categories = class_exists( '\\UFSC\\Competitions\\Repositories\\CategoryRepository' ) ? new CategoryRepository() : null;
		$this->entries = class_exists( '\\UFSC\\Competitions\\Repositories\\EntryRepository' ) ? new EntryRepository() : null;
		$this->fights = class_exists( '\\UFSC\\Competitions\\Repositories\\FightRepository' ) ? new FightRepository() : null;

		// ClubRepository may not exist in older versions; guard
		if ( class_exists( '\\UFSC\\Competitions\\Repositories\\ClubRepository' ) ) {
			$this->club_repository = new \UFSC\Competitions\Repositories\ClubRepository();
		} else {
			$this->club_repository = null;
		}

		// register AJAX handler even if repo missing; handler will guard and return error
		add_action( 'wp_ajax_ufsc_get_club', array( $this, 'ajax_get_club' ) );
	}

	public function register_actions() {
		add_action( 'admin_post_ufsc_competitions_save_competition', array( $this, 'handle_save' ) );
		add_action( 'admin_post_ufsc_competitions_trash_competition', array( $this, 'handle_trash' ) );
		add_action( 'admin_post_ufsc_competitions_restore_competition', array( $this, 'handle_restore' ) );
		add_action( 'admin_post_ufsc_competitions_delete_competition', array( $this, 'handle_delete' ) );
	}

	public function render() {
		if ( null === $this->repository ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'UFSC Competitions: module unavailable — please check plugin files.', 'ufsc-licence-competition' ) . '</p></div>';
			return;
		}

		if ( ! Capabilities::user_can_manage() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ) );
		}

		$action = isset( $_GET['ufsc_action'] ) ? sanitize_key( wp_unslash( $_GET['ufsc_action'] ) ) : '';
		$notice = isset( $_GET['ufsc_notice'] ) ? sanitize_key( wp_unslash( $_GET['ufsc_notice'] ) ) : '';
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

		$this->render_notice( $notice );

		if ( in_array( $action, array( 'add', 'edit' ), true ) ) {
			$item = null;
			if ( 'edit' === $action && $id && method_exists( $this->repository, 'get' ) ) {
				$item = $this->repository->get( $id, true );
			}
			$this->render_form( $item );
			return;
		}

		$list_table = null;
		if ( class_exists( '\\UFSC\\Competitions\\Admin\\Tables\\Competitions_Table' ) ) {
			$list_table = new \UFSC\Competitions\Admin\Tables\Competitions_Table();
			$this->maybe_handle_bulk_actions( $list_table, Menu::PAGE_COMPETITIONS );
			$list_table->prepare_items();
		}

		?>
		<div class="wrap ufsc-competitions-admin">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Compétitions', 'ufsc-licence-competition' ); ?></h1>
			<a href="<?php echo esc_url( add_query_arg( array( 'page' => Menu::PAGE_COMPETITIONS, 'ufsc_action' => 'add' ), admin_url( 'admin.php' ) ) ); ?>" class="page-title-action"><?php esc_html_e( 'Ajouter', 'ufsc-licence-competition' ); ?></a>
			<hr class="wp-header-end">
			<?php $this->render_helper_notice( __( 'Gérer les compétitions, catégories, inscriptions et tableaux.', 'ufsc-licence-competition' ) ); ?>
			<?php if ( $list_table ) : ?>
				<?php $list_table->views(); ?>
				<form method="post">
					<input type="hidden" name="page" value="<?php echo esc_attr( Menu::PAGE_COMPETITIONS ); ?>" />
					<?php $list_table->display(); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_notice( $notice ) {
		if ( ! $notice ) {
			return;
		}

		$map = array(
			'not_found' => __( 'Élément non trouvé.', 'ufsc-licence-competition' ),
			'saved'     => __( 'Compétition enregistrée.', 'ufsc-licence-competition' ),
			'invalid'   => __( 'Données invalides, vérifiez le formulaire.', 'ufsc-licence-competition' ),
		);

		if ( isset( $map[ $notice ] ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html( $map[ $notice ] ) . '</p></div>';
		}
	}

	private function render_form( $item = null ) {
		$values = array(
			'id' => $item->id ?? 0,
			'name' => $item->name ?? '',
			'discipline' => $item->discipline ?? '',
			'type' => $item->type ?? '',
			'season' => $item->season ?? '',
			'status' => $item->status ?? 'draft',
			'organizer_club_id' => $item->organizer_club_id ?? 0,
			'organizer_club_name' => $item->organizer_club_name ?? '',
			'organizer_region' => $item->organizer_region ?? '',
			'venue_name' => $item->venue_name ?? '',
			'venue_address1' => $item->venue_address1 ?? '',
			'venue_address2' => $item->venue_address2 ?? '',
			'venue_postcode' => $item->venue_postcode ?? '',
			'venue_city' => $item->venue_city ?? '',
			'venue_region' => $item->venue_region ?? '',
			'event_start_datetime' => $item->event_start_datetime ?? '',
			'event_end_datetime' => $item->event_end_datetime ?? '',
			'registration_open_datetime' => $item->registration_open_datetime ?? '',
			'registration_close_datetime' => $item->registration_close_datetime ?? '',
			'weighin_start_datetime' => $item->weighin_start_datetime ?? '',
			'weighin_end_datetime' => $item->weighin_end_datetime ?? '',
			'contact_email' => $item->contact_email ?? '',
			'contact_phone' => $item->contact_phone ?? '',
		);

		// Prepare clubs list if repository available
		$clubs = array();
		if ( $this->club_repository && method_exists( $this->club_repository, 'list_for_select' ) ) {
			$clubs = $this->club_repository->list_for_select();
		}

		$ajax_nonce = wp_create_nonce( 'ufsc_get_club' );
		?>
		<div class="wrap ufsc-competitions-admin">
			<h1><?php echo esc_html( $values['id'] ? __( 'Modifier la compétition', 'ufsc-licence-competition' ) : __( 'Ajouter une compétition', 'ufsc-licence-competition' ) ); ?></h1>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ufsc-competitions-form">
				<?php wp_nonce_field( 'ufsc_competitions_save_competition' ); ?>
				<input type="hidden" name="action" value="ufsc_competitions_save_competition">
				<input type="hidden" name="id" value="<?php echo esc_attr( $values['id'] ); ?>">

				<h2><?php esc_html_e( 'Informations générales', 'ufsc-licence-competition' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="ufsc_name"><?php esc_html_e( 'Nom', 'ufsc-licence-competition' ); ?></label></th>
						<td><input id="ufsc_name" name="name" type="text" value="<?php echo esc_attr( $values['name'] ); ?>" class="regular-text" required></td>
					</tr>
					<tr>
						<th><label for="ufsc_discipline"><?php esc_html_e( 'Discipline', 'ufsc-licence-competition' ); ?></label></th>
						<td><input id="ufsc_discipline" name="discipline" type="text" value="<?php echo esc_attr( $values['discipline'] ); ?>" class="regular-text" required></td>
					</tr>
					<tr>
						<th><label for="ufsc_type"><?php esc_html_e( 'Type', 'ufsc-licence-competition' ); ?></label></th>
						<td><input id="ufsc_type" name="type" type="text" value="<?php echo esc_attr( $values['type'] ); ?>" class="regular-text" required></td>
					</tr>
					<tr>
						<th><label for="ufsc_season"><?php esc_html_e( 'Saison', 'ufsc-licence-competition' ); ?></label></th>
						<td><input id="ufsc_season" name="season" type="text" value="<?php echo esc_attr( $values['season'] ); ?>" class="regular-text"></td>
					</tr>
					<tr>
						<th><label for="ufsc_status"><?php esc_html_e( 'Statut', 'ufsc-licence-competition' ); ?></label></th>
						<td>
							<select id="ufsc_status" name="status">
								<option value="draft" <?php selected( $values['status'], 'draft' ); ?>><?php esc_html_e( 'Brouillon', 'ufsc-licence-competition' ); ?></option>
								<option value="open" <?php selected( $values['status'], 'open' ); ?>><?php esc_html_e( 'Ouvert', 'ufsc-licence-competition' ); ?></option>
								<option value="closed" <?php selected( $values['status'], 'closed' ); ?>><?php esc_html_e( 'Fermé', 'ufsc-licence-competition' ); ?></option>
								<option value="archived" <?php selected( $values['status'], 'archived' ); ?>><?php esc_html_e( 'Archivé', 'ufsc-licence-competition' ); ?></option>
							</select>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Club organisateur', 'ufsc-licence-competition' ); ?></h2>
				<?php if ( ! $this->club_repository ) : ?>
					<div class="notice notice-warning"><p><?php esc_html_e( 'Club repository non disponible. Vous pouvez saisir le nom du club manuellement.', 'ufsc-licence-competition' ); ?></p></div>
				<?php endif; ?>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="ufsc_organizer_club_id"><?php esc_html_e( 'Club organisateur', 'ufsc-licence-competition' ); ?></label></th>
						<td>
							<?php if ( $this->club_repository ) : ?>
								<select id="ufsc_organizer_club_id" name="organizer_club_id">
									<option value="0"><?php esc_html_e( 'Sélectionner un club', 'ufsc-licence-competition' ); ?></option>
									<?php foreach ( $clubs as $cid => $label ) : ?>
										<option value="<?php echo esc_attr( $cid ); ?>" <?php selected( $values['organizer_club_id'], $cid ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
							<?php else : ?>
								<input id="ufsc_organizer_club_name" name="organizer_club_name" type="text" value="<?php echo esc_attr( $values['organizer_club_name'] ); ?>" class="regular-text">
							<?php endif; ?>
							<p class="description"><?php esc_html_e( 'L’adresse du club n’est pas utilisée automatiquement comme lieu de la compétition.', 'ufsc-licence-competition' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label><?php esc_html_e( 'Région du club (snapshot)', 'ufsc-licence-competition' ); ?></label></th>
						<td><input readonly class="regular-text" value="<?php echo esc_attr( $values['organizer_region'] ); ?>"></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Lieu de la manifestation (venue)', 'ufsc-licence-competition' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="ufsc_venue_name"><?php esc_html_e( 'Nom du lieu', 'ufsc-licence-competition' ); ?></label></th>
						<td><input id="ufsc_venue_name" name="venue_name" type="text" value="<?php echo esc_attr( $values['venue_name'] ); ?>" class="regular-text" required></td>
					</tr>
					<tr>
						<th><label for="ufsc_venue_address1"><?php esc_html_e( 'Adresse 1', 'ufsc-licence-competition' ); ?></label></th>
						<td><input id="ufsc_venue_address1" name="venue_address1" type="text" value="<?php echo esc_attr( $values['venue_address1'] ); ?>" class="regular-text" required></td>
					</tr>
					<tr>
						<th><label for="ufsc_venue_address2"><?php esc_html_e( 'Adresse 2', 'ufsc-licence-competition' ); ?></label></th>
						<td><input id="ufsc_venue_address2" name="venue_address2" type="text" value="<?php echo esc_attr( $values['venue_address2'] ); ?>" class="regular-text"></td>
					</tr>
					<tr>
						<th><label for="ufsc_venue_postcode"><?php esc_html_e( 'Code postal', 'ufsc-licence-competition' ); ?></label></th>
						<td><input id="ufsc_venue_postcode" name="venue_postcode" type="text" value="<?php echo esc_attr( $values['venue_postcode'] ); ?>" class="small-text" required></td>
					</tr>
					<tr>
						<th><label for="ufsc_venue_city"><?php esc_html_e( 'Ville', 'ufsc-licence-competition' ); ?></label></th>
						<td><input id="ufsc_venue_city" name="venue_city" type="text" value="<?php echo esc_attr( $values['venue_city'] ); ?>" class="regular-text" required></td>
					</tr>
					<tr>
						<th><label for="ufsc_venue_region"><?php esc_html_e( 'Région', 'ufsc-licence-competition' ); ?></label></th>
						<td><input id="ufsc_venue_region" name="venue_region" type="text" value="<?php echo esc_attr( $values['venue_region'] ); ?>" class="regular-text" required></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Dates & horaires', 'ufsc-licence-competition' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="ufsc_event_start_datetime"><?php esc_html_e( 'Date & heure début', 'ufsc-licence-competition' ); ?></label></th>
						<td><input id="ufsc_event_start_datetime" name="event_start_datetime" type="datetime-local" value="<?php echo esc_attr( str_replace( ' ', 'T', $values['event_start_datetime'] ) ); ?>" required></td>
					</tr>
					<tr>
						<th><label for="ufsc_event_end_datetime"><?php esc_html_e( 'Date & heure fin', 'ufsc-licence-competition' ); ?></label></th>
						<td><input id="ufsc_event_end_datetime" name="event_end_datetime" type="datetime-local" value="<?php echo esc_attr( str_replace( ' ', 'T', $values['event_end_datetime'] ) ); ?>"></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Inscriptions', 'ufsc-licence-competition' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="ufsc_registration_open_datetime"><?php esc_html_e( 'Ouverture', 'ufsc-licence-competition' ); ?></label></th>
						<td><input id="ufsc_registration_open_datetime" name="registration_open_datetime" type="datetime-local" value="<?php echo esc_attr( str_replace( ' ', 'T', $values['registration_open_datetime'] ) ); ?>"></td>
					</tr>
					<tr>
						<th><label for="ufsc_registration_close_datetime"><?php esc_html_e( 'Fermeture', 'ufsc-licence-competition' ); ?></label></th>
						<td><input id="ufsc_registration_close_datetime" name="registration_close_datetime" type="datetime-local" value="<?php echo esc_attr( str_replace( ' ', 'T', $values['registration_close_datetime'] ) ); ?>"></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Pesée', 'ufsc-licence-competition' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="ufsc_weighin_start_datetime"><?php esc_html_e( 'Début pesée', 'ufsc-licence-competition' ); ?></label></th>
						<td><input id="ufsc_weighin_start_datetime" name="weighin_start_datetime" type="datetime-local" value="<?php echo esc_attr( str_replace( ' ', 'T', $values['weighin_start_datetime'] ) ); ?>"></td>
					</tr>
					<tr>
						<th><label for="ufsc_weighin_end_datetime"><?php esc_html_e( 'Fin pesée', 'ufsc-licence-competition' ); ?></label></th>
						<td><input id="ufsc_weighin_end_datetime" name="weighin_end_datetime" type="datetime-local" value="<?php echo esc_attr( str_replace( ' ', 'T', $values['weighin_end_datetime'] ) ); ?>"></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Contact', 'ufsc-licence-competition' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="ufsc_contact_email"><?php esc_html_e( 'Email contact', 'ufsc-licence-competition' ); ?></label></th>
						<td><input id="ufsc_contact_email" name="contact_email" type="email" value="<?php echo esc_attr( $values['contact_email'] ); ?>" class="regular-text"></td>
					</tr>
					<tr>
						<th><label for="ufsc_contact_phone"><?php esc_html_e( 'Téléphone contact', 'ufsc-licence-competition' ); ?></label></th>
						<td><input id="ufsc_contact_phone" name="contact_phone" type="text" value="<?php echo esc_attr( $values['contact_phone'] ); ?>" class="regular-text"></td>
					</tr>
				</table>

				<?php submit_button( $values['id'] ? __( 'Mettre à jour la compétition', 'ufsc-licence-competition' ) : __( 'Créer la compétition', 'ufsc-licence-competition' ) ); ?>
			</form>
		</div>
		<?php
	}

	private function render_helper_notice( $message ) {
		echo '<div class="notice notice-info ufsc-competitions-helper"><p>' . esc_html( $message ) . '</p></div>';
	}

	public function handle_save() {
		if ( ! Capabilities::user_can_manage() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ) );
		}

		check_admin_referer( 'ufsc_competitions_save_competition' );

		$input = wp_unslash( $_POST );

		// Fields to accept
		$fields = array(
			'id','name','discipline','type','season','status',
			'organizer_club_id','organizer_club_name','organizer_region',
			'venue_name','venue_address1','venue_address2','venue_postcode','venue_city','venue_region',
			'event_start_datetime','event_end_datetime',
			'registration_open_datetime','registration_close_datetime',
			'weighin_start_datetime','weighin_end_datetime',
			'contact_email','contact_phone'
		);

		$data = array();
		foreach ( $fields as $f ) {
			if ( isset( $input[ $f ] ) ) {
				$data[ $f ] = $input[ $f ];
			}
		}

		// Basic required validation
		$required = array( 'name','discipline','type','venue_name','venue_address1','venue_postcode','venue_city','event_start_datetime' );
		foreach ( $required as $r ) {
			if ( empty( $data[ $r ] ) ) {
				$redirect = add_query_arg( array( 'page' => Menu::PAGE_COMPETITIONS, 'ufsc_action' => 'add', 'ufsc_notice' => 'invalid' ), admin_url( 'admin.php' ) );
				wp_safe_redirect( $redirect );
				exit;
			}
		}

		// If organizer_club_id present and ClubRepository available, snapshot club info
		$club_id = isset( $data['organizer_club_id'] ) ? absint( $data['organizer_club_id'] ) : 0;
		if ( $club_id && $this->club_repository && method_exists( $this->club_repository, 'get' ) ) {
			$club = $this->club_repository->get( $club_id );
			if ( $club ) {
				$data['organizer_club_name'] = $club->nom ?? $data['organizer_club_name'] ?? '';
				$data['organizer_region'] = $club->region ?? $data['organizer_region'] ?? '';
			}
		} elseif ( $club_id ) {
			// fallback: query WPDB if repo missing
			global $wpdb;
			$clubs_table = $wpdb->prefix . 'ufsc_clubs';
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT nom, region FROM {$clubs_table} WHERE id = %d", $club_id ) );
			if ( $row ) {
				$data['organizer_club_name'] = $row->nom ?? $data['organizer_club_name'] ?? '';
				$data['organizer_region'] = $row->region ?? $data['organizer_region'] ?? '';
			}
		}

		// Ensure event_end defaults to start if empty
		if ( empty( $data['event_end_datetime'] ) && ! empty( $data['event_start_datetime'] ) ) {
			$data['event_end_datetime'] = $data['event_start_datetime'];
		}

		// Convert datetime-local values to 'Y-m-d H:i:s' format (handled in repository sanitize)
		$id = 0;
		if ( $this->repository && method_exists( $this->repository, 'save' ) ) {
			$id = $this->repository->save( $data );
		}

		$redirect = add_query_arg( array( 'page' => Menu::PAGE_COMPETITIONS, 'ufsc_notice' => 'saved' ), admin_url( 'admin.php' ) );
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * AJAX: return club info (safe subset) if repository available.
	 */
	public function ajax_get_club() {
		if ( ! Capabilities::user_can_manage() && ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'forbidden', 403 );
		}

		check_ajax_referer( 'ufsc_get_club', 'nonce' );

		$club_id = isset( $_POST['club_id'] ) ? absint( wp_unslash( $_POST['club_id'] ) ) : 0;
		if ( ! $club_id ) {
			wp_send_json_error( 'missing' );
		}

		if ( ! $this->club_repository || ! method_exists( $this->club_repository, 'get' ) ) {
			wp_send_json_error( 'club_repo_missing', 500 );
		}

		$club = $this->club_repository->get( $club_id );
		if ( ! $club ) {
			wp_send_json_error( 'not_found' );
		}

		$data = array(
			'id' => (int) $club->id,
			'nom' => sanitize_text_field( $club->nom ?? '' ),
			'adresse' => sanitize_text_field( $club->adresse ?? '' ),
			'complement_adresse' => sanitize_text_field( $club->complement_adresse ?? '' ),
			'code_postal' => sanitize_text_field( $club->code_postal ?? '' ),
			'ville' => sanitize_text_field( $club->ville ?? '' ),
			'region' => sanitize_text_field( $club->region ?? '' ),
			'email' => sanitize_email( $club->email ?? '' ),
			'telephone' => sanitize_text_field( $club->telephone ?? ( $club->phone ?? '' ) ),
		);

		wp_send_json_success( $data );
	}

	public function handle_trash() { wp_die(); }
	public function handle_restore() { wp_die(); }
	public function handle_delete() { wp_die(); }
}
