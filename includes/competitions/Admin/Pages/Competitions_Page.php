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

/**
 * Admin page: Competitions
 *
 * List + Add/Edit form.
 * Defensive: avoid fatals if repositories/classes missing.
 */
class Competitions_Page {
	private $repository;
	private $categories;
	private $entries;
	private $fights;
	private $club_repository;

	public function __construct() {
		$this->repository = class_exists( '\\UFSC\\Competitions\\Repositories\\CompetitionRepository' ) ? new CompetitionRepository() : null;
		$this->categories = class_exists( '\\UFSC\\Competitions\\Repositories\\CategoryRepository' ) ? new CategoryRepository() : null;
		$this->entries    = class_exists( '\\UFSC\\Competitions\\Repositories\\EntryRepository' ) ? new EntryRepository() : null;
		$this->fights     = class_exists( '\\UFSC\\Competitions\\Repositories\\FightRepository' ) ? new FightRepository() : null;

		if ( class_exists( '\\UFSC\\Competitions\\Repositories\\ClubRepository' ) ) {
			$this->club_repository = new \UFSC\Competitions\Repositories\ClubRepository();
		} else {
			$this->club_repository = null;
		}

		add_action( 'wp_ajax_ufsc_get_club', array( $this, 'ajax_get_club' ) );
	}

	public function register_actions() {
		add_action( 'admin_post_ufsc_competitions_save_competition', array( $this, 'handle_save' ) );
		add_action( 'admin_post_ufsc_competitions_trash_competition', array( $this, 'handle_trash' ) );
		add_action( 'admin_post_ufsc_competitions_restore_competition', array( $this, 'handle_restore' ) );
		add_action( 'admin_post_ufsc_competitions_delete_competition', array( $this, 'handle_delete' ) );
	}

	public function render() {
		if ( ! Capabilities::user_can_manage() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ) );
		}

		$action = isset( $_GET['ufsc_action'] ) ? sanitize_key( wp_unslash( $_GET['ufsc_action'] ) ) : '';
		$notice = isset( $_GET['ufsc_notice'] ) ? sanitize_key( wp_unslash( $_GET['ufsc_notice'] ) ) : '';
		$id     = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

		$this->render_notice( $notice );

		if ( in_array( $action, array( 'add', 'edit' ), true ) ) {
			$item = null;
			if ( 'edit' === $action && $id && $this->repository && method_exists( $this->repository, 'get' ) ) {
				$item = $this->repository->get( $id, true );
			}
			$this->render_form( $item );
			return;
		}

		$this->render_list();
	}

	private function render_list() {
		if ( ! class_exists( '\\UFSC\\Competitions\\Admin\\Tables\\Competitions_Table' ) ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Compétitions', 'ufsc-licence-competition' ) . '</h1>';
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Tableau introuvable : la classe Competitions_Table est manquante.', 'ufsc-licence-competition' ) . '</p></div>';
			echo '</div>';
			return;
		}

		$list_table = new \UFSC\Competitions\Admin\Tables\Competitions_Table();
		$this->maybe_handle_bulk_actions( $list_table, Menu::PAGE_COMPETITIONS );
		$list_table->prepare_items();

		?>
		<div class="wrap ufsc-competitions-admin">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Compétitions', 'ufsc-licence-competition' ); ?></h1>
			<a href="<?php echo esc_url( add_query_arg( array( 'page' => Menu::PAGE_COMPETITIONS, 'ufsc_action' => 'add' ), admin_url( 'admin.php' ) ) ); ?>" class="page-title-action"><?php esc_html_e( 'Ajouter', 'ufsc-licence-competition' ); ?></a>
			<hr class="wp-header-end">
			<?php $list_table->views(); ?>
			<form method="post">
				<input type="hidden" name="page" value="<?php echo esc_attr( Menu::PAGE_COMPETITIONS ); ?>" />
				<?php $list_table->display(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Safe getter for array/object rows.
	 */
	private function get_item_value( $item, string $key, $default = '' ) {
		if ( is_array( $item ) ) {
			return isset( $item[ $key ] ) ? $item[ $key ] : $default;
		}
		if ( is_object( $item ) ) {
			return isset( $item->{$key} ) ? $item->{$key} : $default;
		}
		return $default;
	}

	private function render_form( $item = null ): void {
		$values = array(
			'id'                          => absint( $this->get_item_value( $item, 'id', 0 ) ),
			'name'                        => (string) $this->get_item_value( $item, 'name', '' ),
			'discipline'                  => (string) $this->get_item_value( $item, 'discipline', '' ),
			'type'                        => (string) $this->get_item_value( $item, 'type', '' ),
			'season'                      => (string) $this->get_item_value( $item, 'season', '' ),
			'status'                      => (string) $this->get_item_value( $item, 'status', 'draft' ),

			'organizer_club_id'           => absint( $this->get_item_value( $item, 'organizer_club_id', 0 ) ),
			'organizer_club_name'         => (string) $this->get_item_value( $item, 'organizer_club_name', '' ),
			'organizer_region'            => (string) $this->get_item_value( $item, 'organizer_region', '' ),

			'venue_name'                  => (string) $this->get_item_value( $item, 'venue_name', '' ),
			'venue_address1'              => (string) $this->get_item_value( $item, 'venue_address1', '' ),
			'venue_address2'              => (string) $this->get_item_value( $item, 'venue_address2', '' ),
			'venue_postcode'              => (string) $this->get_item_value( $item, 'venue_postcode', '' ),
			'venue_city'                  => (string) $this->get_item_value( $item, 'venue_city', '' ),
			'venue_region'                => (string) $this->get_item_value( $item, 'venue_region', '' ),

			'event_start_datetime'        => (string) $this->get_item_value( $item, 'event_start_datetime', '' ),
			'event_end_datetime'          => (string) $this->get_item_value( $item, 'event_end_datetime', '' ),
			'registration_open_datetime'  => (string) $this->get_item_value( $item, 'registration_open_datetime', '' ),
			'registration_close_datetime' => (string) $this->get_item_value( $item, 'registration_close_datetime', '' ),
			'weighin_start_datetime'      => (string) $this->get_item_value( $item, 'weighin_start_datetime', '' ),
			'weighin_end_datetime'        => (string) $this->get_item_value( $item, 'weighin_end_datetime', '' ),

			'contact_email'               => (string) $this->get_item_value( $item, 'contact_email', '' ),
			'contact_phone'               => (string) $this->get_item_value( $item, 'contact_phone', '' ),
		);

		$clubs = array();
		if ( $this->club_repository && method_exists( $this->club_repository, 'list_for_select' ) ) {
			$clubs = $this->club_repository->list_for_select();
		}

		$format_for_input = function ( $mysql_datetime ) {
			if ( empty( $mysql_datetime ) ) {
				return '';
			}
			$ts = strtotime( $mysql_datetime );
			if ( false === $ts ) {
				return '';
			}
			return date( 'Y-m-d\TH:i', $ts );
		};

		$back_url = add_query_arg( array( 'page' => Menu::PAGE_COMPETITIONS ), admin_url( 'admin.php' ) );
		?>
		<div class="wrap ufsc-competitions-admin">
			<h1><?php echo esc_html( $values['id'] ? __( 'Modifier la compétition', 'ufsc-licence-competition' ) : __( 'Ajouter une compétition', 'ufsc-licence-competition' ) ); ?></h1>

			<p><a class="button" href="<?php echo esc_url( $back_url ); ?>">&larr; <?php esc_html_e( 'Retour', 'ufsc-licence-competition' ); ?></a></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'ufsc_competitions_save_competition', '_ufsc_nonce' ); ?>
				<input type="hidden" name="action" value="ufsc_competitions_save_competition">
				<input type="hidden" name="id" value="<?php echo esc_attr( $values['id'] ); ?>">

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
							</select>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Club organisateur', 'ufsc-licence-competition' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th><label><?php esc_html_e( 'Club', 'ufsc-licence-competition' ); ?></label></th>
						<td>
							<?php if ( ! empty( $clubs ) ) : ?>
								<select name="organizer_club_id">
									<option value="0"><?php esc_html_e( 'Sélectionner un club', 'ufsc-licence-competition' ); ?></option>
									<?php foreach ( $clubs as $cid => $label ) : ?>
										<option value="<?php echo esc_attr( $cid ); ?>" <?php selected( $values['organizer_club_id'], $cid ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( "L’adresse du club n’est pas forcément celle du lieu de compétition (lieu séparé ci-dessous).", 'ufsc-licence-competition' ); ?></p>
							<?php else : ?>
								<input name="organizer_club_name" type="text" value="<?php echo esc_attr( $values['organizer_club_name'] ); ?>" class="regular-text">
								<p class="description"><?php esc_html_e( 'Aucun repository clubs détecté, saisissez le nom manuellement.', 'ufsc-licence-competition' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>

					<tr>
						<th><label><?php esc_html_e( 'Région (snapshot)', 'ufsc-licence-competition' ); ?></label></th>
						<td><input name="organizer_region" class="regular-text" value="<?php echo esc_attr( $values['organizer_region'] ); ?>"></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Lieu de la compétition', 'ufsc-licence-competition' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="venue_name"><?php esc_html_e( 'Nom du lieu', 'ufsc-licence-competition' ); ?></label></th>
						<td><input id="venue_name" name="venue_name" type="text" value="<?php echo esc_attr( $values['venue_name'] ); ?>" class="regular-text" required></td>
					</tr>
					<tr>
						<th><label for="venue_address1"><?php esc_html_e( 'Adresse 1', 'ufsc-licence-competition' ); ?></label></th>
						<td><input id="venue_address1" name="venue_address1" type="text" value="<?php echo esc_attr( $values['venue_address1'] ); ?>" class="regular-text" required></td>
					</tr>
					<tr>
						<th><label for="venue_address2"><?php esc_html_e( 'Adresse 2', 'ufsc-licence-competition' ); ?></label></th>
						<td><input id="venue_address2" name="venue_address2" type="text" value="<?php echo esc_attr( $values['venue_address2'] ); ?>" class="regular-text"></td>
					</tr>
					<tr>
						<th><label for="venue_postcode"><?php esc_html_e( 'Code postal', 'ufsc-licence-competition' ); ?></label></th>
						<td><input id="venue_postcode" name="venue_postcode" type="text" value="<?php echo esc_attr( $values['venue_postcode'] ); ?>" class="small-text" required></td>
					</tr>
					<tr>
						<th><label for="venue_city"><?php esc_html_e( 'Ville', 'ufsc-licence-competition' ); ?></label></th>
						<td><input id="venue_city" name="venue_city" type="text" value="<?php echo esc_attr( $values['venue_city'] ); ?>" class="regular-text" required></td>
					</tr>
					<tr>
						<th><label for="venue_region"><?php esc_html_e( 'Région', 'ufsc-licence-competition' ); ?></label></th>
						<td><input id="venue_region" name="venue_region" type="text" value="<?php echo esc_attr( $values['venue_region'] ); ?>" class="regular-text"></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Dates & horaires', 'ufsc-licence-competition' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="event_start_datetime"><?php esc_html_e( 'Début manifestation', 'ufsc-licence-competition' ); ?></label></th>
						<td><input id="event_start_datetime" name="event_start_datetime" type="datetime-local" value="<?php echo esc_attr( $format_for_input( $values['event_start_datetime'] ) ); ?>" required></td>
					</tr>
					<tr>
						<th><label for="event_end_datetime"><?php esc_html_e( 'Fin manifestation', 'ufsc-licence-competition' ); ?></label></th>
						<td><input id="event_end_datetime" name="event_end_datetime" type="datetime-local" value="<?php echo esc_attr( $format_for_input( $values['event_end_datetime'] ) ); ?>"></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Inscriptions', 'ufsc-licence-competition' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="registration_open_datetime"><?php esc_html_e( 'Ouverture', 'ufsc-licence-competition' ); ?></label></th>
						<td><input id="registration_open_datetime" name="registration_open_datetime" type="datetime-local" value="<?php echo esc_attr( $format_for_input( $values['registration_open_datetime'] ) ); ?>"></td>
					</tr>
					<tr>
						<th><label for="registration_close_datetime"><?php esc_html_e( 'Fermeture', 'ufsc-licence-competition' ); ?></label></th>
						<td><input id="registration_close_datetime" name="registration_close_datetime" type="datetime-local" value="<?php echo esc_attr( $format_for_input( $values['registration_close_datetime'] ) ); ?>"></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Pesée', 'ufsc-licence-competition' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="weighin_start_datetime"><?php esc_html_e( 'Début pesée', 'ufsc-licence-competition' ); ?></label></th>
						<td><input id="weighin_start_datetime" name="weighin_start_datetime" type="datetime-local" value="<?php echo esc_attr( $format_for_input( $values['weighin_start_datetime'] ) ); ?>"></td>
					</tr>
					<tr>
						<th><label for="weighin_end_datetime"><?php esc_html_e( 'Fin pesée', 'ufsc-licence-competition' ); ?></label></th>
						<td><input id="weighin_end_datetime" name="weighin_end_datetime" type="datetime-local" value="<?php echo esc_attr( $format_for_input( $values['weighin_end_datetime'] ) ); ?>"></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Contact', 'ufsc-licence-competition' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="contact_email"><?php esc_html_e( 'Email', 'ufsc-licence-competition' ); ?></label></th>
						<td><input id="contact_email" name="contact_email" type="email" value="<?php echo esc_attr( $values['contact_email'] ); ?>" class="regular-text"></td>
					</tr>
					<tr>
						<th><label for="contact_phone"><?php esc_html_e( 'Téléphone', 'ufsc-licence-competition' ); ?></label></th>
						<td><input id="contact_phone" name="contact_phone" type="text" value="<?php echo esc_attr( $values['contact_phone'] ); ?>" class="regular-text"></td>
					</tr>
				</table>

				<?php submit_button( $values['id'] ? __( 'Mettre à jour la compétition', 'ufsc-licence-competition' ) : __( 'Créer la compétition', 'ufsc-licence-competition' ) ); ?>
			</form>
		</div>
		<?php
	}

	public function handle_save() {
		if ( ! Capabilities::user_can_manage() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ) );
		}

		if ( ! isset( $_POST['_ufsc_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_ufsc_nonce'] ) ), 'ufsc_competitions_save_competition' ) ) {
			wp_die( esc_html__( 'Nonce invalide.', 'ufsc-licence-competition' ) );
		}

		$input = wp_unslash( $_POST );

		$fields = array(
			'id','name','discipline','type','season','status',
			'organizer_club_id','organizer_club_name','organizer_region',
			'venue_name','venue_address1','venue_address2','venue_postcode','venue_city','venue_region',
			'event_start_datetime','event_end_datetime',
			'registration_open_datetime','registration_close_datetime',
			'weighin_start_datetime','weighin_end_datetime',
			'contact_email','contact_phone',
		);

		$data = array();
		foreach ( $fields as $f ) {
			if ( isset( $input[ $f ] ) ) {
				$data[ $f ] = $input[ $f ];
			}
		}

		// Required fields
		$required = array( 'name','discipline','type','venue_name','venue_address1','venue_postcode','venue_city','event_start_datetime' );
		foreach ( $required as $r ) {
			if ( empty( $data[ $r ] ) ) {
				$redirect = add_query_arg(
					array( 'page' => Menu::PAGE_COMPETITIONS, 'ufsc_action' => 'add', 'ufsc_notice' => 'invalid' ),
					admin_url( 'admin.php' )
				);
				wp_safe_redirect( $redirect );
				exit;
			}
		}

		// Snapshot club details if possible
		$club_id = isset( $data['organizer_club_id'] ) ? absint( $data['organizer_club_id'] ) : 0;
		if ( $club_id && $this->club_repository && method_exists( $this->club_repository, 'get' ) ) {
			$club = $this->club_repository->get( $club_id );
			if ( $club ) {
				$data['organizer_club_name'] = isset( $club->nom ) ? $club->nom : ( $data['organizer_club_name'] ?? '' );
				$data['organizer_region']    = isset( $club->region ) ? $club->region : ( $data['organizer_region'] ?? '' );
			}
		}

		if ( ! $this->repository || ! method_exists( $this->repository, 'save' ) ) {
			error_log( 'UFSC Competitions: repository missing on save' );
			$redirect = add_query_arg( array( 'page' => Menu::PAGE_COMPETITIONS, 'ufsc_notice' => 'error' ), admin_url( 'admin.php' ) );
			wp_safe_redirect( $redirect );
			exit;
		}

		$this->repository->save( $data );

		$redirect = add_query_arg( array( 'page' => Menu::PAGE_COMPETITIONS, 'ufsc_notice' => 'saved' ), admin_url( 'admin.php' ) );
		wp_safe_redirect( $redirect );
		exit;
	}

	public function ajax_get_club() {
		if ( ! Capabilities::user_can_manage() && ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'forbidden', 403 );
		}

		// Nonce optional for now (so you don't block until JS is added)
		if ( isset( $_REQUEST['nonce'] ) ) {
			$nonce = sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ) );
			if ( ! wp_verify_nonce( $nonce, 'ufsc_get_club' ) ) {
				wp_send_json_error( 'bad_nonce', 403 );
			}
		}

		$club_id = isset( $_REQUEST['club_id'] ) ? absint( wp_unslash( $_REQUEST['club_id'] ) ) : 0;
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
			'id'        => (int) ( $club->id ?? 0 ),
			'nom'       => sanitize_text_field( $club->nom ?? '' ),
			'adresse'   => sanitize_text_field( $club->adresse ?? '' ),
			'cp'        => sanitize_text_field( $club->code_postal ?? '' ),
			'ville'     => sanitize_text_field( $club->ville ?? '' ),
			'region'    => sanitize_text_field( $club->region ?? '' ),
			'email'     => sanitize_email( $club->email ?? '' ),
			'telephone' => sanitize_text_field( $club->telephone ?? ( $club->phone ?? '' ) ),
		);

		wp_send_json_success( $data );
	}

	public function handle_trash()   { $this->handle_state_action( 'trash', 'trashed' ); }
	public function handle_restore() { $this->handle_state_action( 'restore', 'restored' ); }
	public function handle_delete()  { $this->handle_state_action( 'delete', 'deleted' ); }

	private function handle_state_action( string $method, string $notice ) : void {
		if ( ! Capabilities::user_can_manage() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ) );
		}

		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		if ( ! $id ) {
			wp_safe_redirect( add_query_arg( array( 'page' => Menu::PAGE_COMPETITIONS, 'ufsc_notice' => 'invalid' ), admin_url( 'admin.php' ) ) );
			exit;
		}

		$nonce_key = 'ufsc_competitions_' . $method . '_' . $id;
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), $nonce_key ) ) {
			wp_die( esc_html__( 'Nonce invalide.', 'ufsc-licence-competition' ) );
		}

		$ok = false;
		if ( $this->repository && method_exists( $this->repository, $method ) ) {
			$ok = (bool) $this->repository->{$method}( $id );
		}

		$redirect = add_query_arg(
			array(
				'page'        => Menu::PAGE_COMPETITIONS,
				'ufsc_notice' => $ok ? $notice : 'error',
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	private function render_notice( $notice ) {
		if ( ! $notice ) {
			return;
		}

		$map = array(
			'not_found' => __( 'Élément non trouvé.', 'ufsc-licence-competition' ),
			'saved'     => __( 'Compétition enregistrée.', 'ufsc-licence-competition' ),
			'trashed'   => __( 'Compétition placée à la corbeille.', 'ufsc-licence-competition' ),
			'restored'  => __( 'Compétition restaurée.', 'ufsc-licence-competition' ),
			'deleted'   => __( 'Compétition supprimée.', 'ufsc-licence-competition' ),
			'invalid'   => __( 'Données invalides.', 'ufsc-licence-competition' ),
			'error'     => __( 'Une erreur est survenue.', 'ufsc-licence-competition' ),
		);

		if ( isset( $map[ $notice ] ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html( $map[ $notice ] ) . '</p></div>';
		}
	}

	private function maybe_handle_bulk_actions( $list_table, $page ) {
		// NOTE: keep your existing bulk implementation if you already have one.
		// Leaving this empty is fine — it avoids unexpected behavior.
	}
}
