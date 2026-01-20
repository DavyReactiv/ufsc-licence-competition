<?php

namespace UFSC\Competitions\Admin\Pages;

use UFSC\Competitions\Capabilities;
use UFSC\Competitions\Admin\Menu;
use UFSC\Competitions\Repositories\CompetitionRepository;
use UFSC\Competitions\Repositories\ClubRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Competitions_Page {
	private $repository;
	private $club_repository;

	public function __construct() {
		$this->repository = class_exists( '\\UFSC\\Competitions\\Repositories\\CompetitionRepository' )
			? new CompetitionRepository()
			: null;

		$this->club_repository = class_exists( '\\UFSC\\Competitions\\Repositories\\ClubRepository' )
			? new ClubRepository()
			: null;

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

		if ( null === $this->repository ) {
			echo '<div class="notice notice-error"><p>' .
				esc_html__( 'UFSC Competitions: repository indisponible (classe non chargée). Vérifiez le bootstrap du plugin.', 'ufsc-licence-competition' ) .
			'</p></div>';
			return;
		}

		$action = isset( $_GET['ufsc_action'] ) ? sanitize_key( wp_unslash( $_GET['ufsc_action'] ) ) : '';
		$notice = isset( $_GET['ufsc_notice'] ) ? sanitize_key( wp_unslash( $_GET['ufsc_notice'] ) ) : '';
		$id     = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

		$this->render_notice( $notice );

		if ( in_array( $action, array( 'add', 'edit' ), true ) ) {
			$item = null;

			if ( 'edit' === $action && $id ) {
				$item = $this->repository->get( $id, true );

				if ( ! $item ) {
					$redirect = add_query_arg(
						array(
							'page'        => Menu::PAGE_COMPETITIONS,
							'ufsc_notice' => 'not_found',
						),
						admin_url( 'admin.php' )
					);
					wp_safe_redirect( $redirect );
					exit;
				}
			}

			$this->render_form( $item );
			return;
		}

		$this->render_list();
	}

	private function render_list() {
		if ( ! class_exists( '\\UFSC\\Competitions\\Admin\\Tables\\Competitions_Table' ) ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Compétitions', 'ufsc-licence-competition' ) . '</h1>';
			echo '<div class="notice notice-warning"><p>' .
				esc_html__( 'Tableau introuvable : la classe Competitions_Table est manquante.', 'ufsc-licence-competition' ) .
			'</p></div></div>';
			return;
		}

		$list_table = new \UFSC\Competitions\Admin\Tables\Competitions_Table();

		// Handle bulk actions defensively (safe)
		$this->maybe_handle_bulk_actions( $list_table );

		$list_table->prepare_items();
		?>
		<div class="wrap ufsc-competitions-admin">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Compétitions', 'ufsc-licence-competition' ); ?></h1>

			<a href="<?php echo esc_url( add_query_arg( array( 'page' => Menu::PAGE_COMPETITIONS, 'ufsc_action' => 'add' ), admin_url( 'admin.php' ) ) ); ?>" class="page-title-action">
				<?php esc_html_e( 'Ajouter', 'ufsc-licence-competition' ); ?>
			</a>

			<hr class="wp-header-end">

			<?php $list_table->views(); ?>

			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( Menu::PAGE_COMPETITIONS ); ?>" />
				<?php
				// keep view
				if ( isset( $_GET['ufsc_view'] ) ) {
					echo '<input type="hidden" name="ufsc_view" value="' . esc_attr( sanitize_key( wp_unslash( $_GET['ufsc_view'] ) ) ) . '" />';
				}
				$list_table->search_box( __( 'Rechercher', 'ufsc-licence-competition' ), 'ufsc-competitions' );
				$list_table->display();
				?>
			</form>
		</div>
		<?php
	}

	private function render_form( $item = null ): void {
		$values = array(
			'id'                          => $item->id ?? 0,
			'name'                        => $item->name ?? '',
			'discipline'                  => $item->discipline ?? '',
			'type'                        => $item->type ?? '',
			'season'                      => $item->season ?? '',
			'status'                      => $item->status ?? 'draft',

			'organizer_club_id'           => $item->organizer_club_id ?? 0,
			'organizer_club_name'         => $item->organizer_club_name ?? '',
			'organizer_region'            => $item->organizer_region ?? '',

			'venue_name'                  => $item->venue_name ?? '',
			'venue_address1'              => $item->venue_address1 ?? '',
			'venue_address2'              => $item->venue_address2 ?? '',
			'venue_postcode'              => $item->venue_postcode ?? '',
			'venue_city'                  => $item->venue_city ?? '',
			'venue_region'                => $item->venue_region ?? '',

			'event_start_datetime'        => $item->event_start_datetime ?? '',
			'event_end_datetime'          => $item->event_end_datetime ?? '',
			'registration_open_datetime'  => $item->registration_open_datetime ?? '',
			'registration_close_datetime' => $item->registration_close_datetime ?? '',
			'weighin_start_datetime'      => $item->weighin_start_datetime ?? '',
			'weighin_end_datetime'        => $item->weighin_end_datetime ?? '',

			'contact_email'               => $item->contact_email ?? '',
			'contact_phone'               => $item->contact_phone ?? '',
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

		?>
		<div class="wrap ufsc-competitions-admin">
			<h1>
				<?php
				echo esc_html(
					$values['id']
						? __( 'Modifier la compétition', 'ufsc-licence-competition' )
						: __( 'Ajouter une compétition', 'ufsc-licence-competition' )
				);
				?>
			</h1>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'ufsc_competitions_save_competition' ); ?>
				<input type="hidden" name="action" value="ufsc_competitions_save_competition">
				<input type="hidden" name="id" value="<?php echo esc_attr( (int) $values['id'] ); ?>">

				<!-- Optional nonce if you later use JS for club snapshot -->
				<input type="hidden" id="ufsc_get_club_nonce" value="<?php echo esc_attr( wp_create_nonce( 'ufsc_get_club' ) ); ?>" />

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

				<h2><?php esc_html_e( 'Club organisateur (snapshot)', 'ufsc-licence-competition' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th><?php esc_html_e( 'Club', 'ufsc-licence-competition' ); ?></th>
						<td>
							<?php if ( ! empty( $clubs ) ) : ?>
								<select name="organizer_club_id">
									<option value="0"><?php esc_html_e( 'Sélectionner un club', 'ufsc-licence-competition' ); ?></option>
									<?php foreach ( $clubs as $cid => $label ) : ?>
										<option value="<?php echo esc_attr( (int) $cid ); ?>" <?php selected( (int) $values['organizer_club_id'], (int) $cid ); ?>>
											<?php echo esc_html( $label ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'Le nom et la région seront figés en snapshot au moment de l’enregistrement.', 'ufsc-licence-competition' ); ?></p>
							<?php else : ?>
								<input name="organizer_club_name" type="text" value="<?php echo esc_attr( $values['organizer_club_name'] ); ?>" class="regular-text">
								<p class="description"><?php esc_html_e( 'Aucun repository clubs détecté : saisissez le nom manuellement.', 'ufsc-licence-competition' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>

					<tr>
						<th><?php esc_html_e( 'Région (snapshot)', 'ufsc-licence-competition' ); ?></th>
						<td><input readonly class="regular-text" value="<?php echo esc_attr( $values['organizer_region'] ); ?>"></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Lieu de la compétition', 'ufsc-licence-competition' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Adresse du lieu de compétition (différente de l’adresse du club).', 'ufsc-licence-competition' ); ?></p>
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

				<?php
				submit_button(
					$values['id']
						? __( 'Mettre à jour la compétition', 'ufsc-licence-competition' )
						: __( 'Créer la compétition', 'ufsc-licence-competition' )
				);
				?>
			</form>
		</div>
		<?php
	}

	public function handle_save() {
		if ( ! Capabilities::user_can_manage() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ) );
		}

		check_admin_referer( 'ufsc_competitions_save_competition' );

		if ( ! $this->repository ) {
			wp_die( esc_html__( 'Repository indisponible.', 'ufsc-licence-competition' ) );
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

		$required = array( 'name','discipline','type','venue_name','venue_address1','venue_postcode','venue_city','event_start_datetime' );
		foreach ( $required as $r ) {
			if ( empty( $data[ $r ] ) ) {
				$redirect = add_query_arg(
					array(
						'page'        => Menu::PAGE_COMPETITIONS,
						'ufsc_action' => empty( $data['id'] ) ? 'add' : 'edit',
						'id'          => isset( $data['id'] ) ? absint( $data['id'] ) : 0,
						'ufsc_notice' => 'invalid',
					),
					admin_url( 'admin.php' )
				);
				wp_safe_redirect( $redirect );
				exit;
			}
		}

		// Snapshot club name/region if club_id selected
		$club_id = isset( $data['organizer_club_id'] ) ? absint( $data['organizer_club_id'] ) : 0;
		if ( $club_id && $this->club_repository && method_exists( $this->club_repository, 'get' ) ) {
			$club = $this->club_repository->get( $club_id );
			if ( $club ) {
				$data['organizer_club_name'] = $club->nom ?? ( $data['organizer_club_name'] ?? '' );
				$data['organizer_region']    = $club->region ?? ( $data['organizer_region'] ?? '' );
			}
		}

		$saved_id = $this->repository->save( $data );

		$redirect = add_query_arg(
			array(
				'page'        => Menu::PAGE_COMPETITIONS,
				'ufsc_notice' => $saved_id ? 'saved' : 'invalid',
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	public function handle_trash() {
		if ( ! Capabilities::user_can_manage() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ) );
		}

		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		check_admin_referer( 'ufsc_competitions_trash_competition_' . $id );

		if ( $id && $this->repository ) {
			$this->repository->trash( $id );
		}

		$redirect = add_query_arg(
			array(
				'page'        => Menu::PAGE_COMPETITIONS,
				'ufsc_notice' => 'saved',
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	public function handle_restore() {
		if ( ! Capabilities::user_can_manage() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ) );
		}

		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		check_admin_referer( 'ufsc_competitions_restore_competition_' . $id );

		if ( $id && $this->repository && method_exists( $this->repository, 'restore' ) ) {
			$this->repository->restore( $id );
		}

		$redirect = add_query_arg(
			array(
				'page'        => Menu::PAGE_COMPETITIONS,
				'ufsc_view'   => 'trash',
				'ufsc_notice' => 'saved',
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	public function handle_delete() {
		if ( ! Capabilities::user_can_manage() || ! Capabilities::user_can_delete() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ) );
		}

		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		check_admin_referer( 'ufsc_competitions_delete_competition_' . $id );

		if ( $id && $this->repository && method_exists( $this->repository, 'delete' ) ) {
			$this->repository->delete( $id );
		}

		$redirect = add_query_arg(
			array(
				'page'        => Menu::PAGE_COMPETITIONS,
				'ufsc_view'   => 'trash',
				'ufsc_notice' => 'saved',
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	public function ajax_get_club() {
		if ( ! Capabilities::user_can_manage() ) {
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
			'id'     => (int) ( $club->id ?? 0 ),
			'nom'    => sanitize_text_field( $club->nom ?? '' ),
			'region' => sanitize_text_field( $club->region ?? '' ),
		);

		wp_send_json_success( $data );
	}

	/**
	 * Bulk actions handler (defensive).
	 * - Will not fatal if nonce isn't present or differs by WP version.
	 */
	private function maybe_handle_bulk_actions( $list_table ) {
		if ( ! $list_table || ! method_exists( $list_table, 'current_action' ) ) {
			return;
		}

		$action = $list_table->current_action();
		if ( empty( $action ) ) {
			return;
		}

		$ids = array();
		if ( ! empty( $_REQUEST['ids'] ) && is_array( $_REQUEST['ids'] ) ) {
			$ids = array_map( 'absint', wp_unslash( $_REQUEST['ids'] ) );
		}

		if ( empty( $ids ) ) {
			return;
		}

		if ( ! $this->repository ) {
			return;
		}

		// Nonce is usually present; if present, verify. If absent, continue (defensive).
		if ( isset( $_REQUEST['_wpnonce'] ) ) {
			$nonce = sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) );

			// Try common nonce actions used by WP_List_Table
			$ok = wp_verify_nonce( $nonce, 'bulk-ufsc-competitions' )
				|| wp_verify_nonce( $nonce, 'bulk-ufsc-competition' )
				|| wp_verify_nonce( $nonce, 'bulk-' . sanitize_key( $list_table->_args['plural'] ?? 'ufsc-competitions' ) );

			if ( ! $ok ) {
				wp_die( esc_html__( 'Nonce invalide.', 'ufsc-licence-competition' ) );
			}
		}

		$processed = 0;

		foreach ( $ids as $id ) {
			if ( ! $id ) {
				continue;
			}

			if ( 'trash' === $action ) {
				if ( $this->repository->trash( $id ) ) {
					$processed++;
				}
			} elseif ( 'restore' === $action && method_exists( $this->repository, 'restore' ) ) {
				if ( $this->repository->restore( $id ) ) {
					$processed++;
				}
			} elseif ( 'delete' === $action && Capabilities::user_can_delete() && method_exists( $this->repository, 'delete' ) ) {
				if ( $this->repository->delete( $id ) ) {
					$processed++;
				}
			}
		}

		$redirect = add_query_arg(
			array(
				'page'        => Menu::PAGE_COMPETITIONS,
				'ufsc_notice' => $processed ? 'saved' : 'invalid',
			),
			admin_url( 'admin.php' )
		);

		// Keep current view if present
		if ( isset( $_REQUEST['ufsc_view'] ) ) {
			$redirect = add_query_arg( 'ufsc_view', sanitize_key( wp_unslash( $_REQUEST['ufsc_view'] ) ), $redirect );
		}

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
			'invalid'   => __( 'Données invalides, vérifiez le formulaire.', 'ufsc-licence-competition' ),
		);

		if ( isset( $map[ $notice ] ) ) {
			$type = ( 'saved' === $notice ) ? 'success' : 'warning';
			echo '<div class="notice notice-' . esc_attr( $type ) . '"><p>' . esc_html( $map[ $notice ] ) . '</p></div>';
		}
	}
}
