<?php

namespace UFSC\Competitions\Admin\Pages;

use UFSC\Competitions\Capabilities;
use UFSC\Competitions\Admin\Menu;
use UFSC\Competitions\Repositories\CompetitionRepository;
use UFSC\Competitions\Repositories\CategoryRepository;
use UFSC\Competitions\Repositories\EntryRepository;
use UFSC\Competitions\Repositories\ClubRepository;
use UFSC\Competitions\Repositories\FightRepository;
use UFSC\Competitions\Services\DisciplineRegistry;
use UFSC\Competitions\Services\CategoryPresetRegistry;
use UFSC\Competitions\Admin\Tables\Competitions_Table;
use UFSC\Competitions\Services\PoolGenerator;

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
		$this->repository = class_exists( '\\UFSC\\Competitions\\Repositories\\CompetitionRepository' ) ? new CompetitionRepository() : null;
		$this->categories = class_exists( '\\UFSC\\Competitions\\Repositories\\CategoryRepository' ) ? new CategoryRepository() : null;
		$this->entries = class_exists( '\\UFSC\\Competitions\\Repositories\\EntryRepository' ) ? new EntryRepository() : null;
		$this->fights = class_exists( '\\UFSC\\Competitions\\Repositories\\FightRepository' ) ? new FightRepository() : null;
		$this->club_repository = class_exists( '\\UFSC\\Competitions\\Repositories\\ClubRepository' ) ? new ClubRepository() : new ClubRepository();

		// register ajax
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
			echo '<div class="notice notice-error"><p>' . esc_html__( 'UFSC Competitions: module competitions non disponible — vérifiez le chargement du plugin.', 'ufsc-licence-competition' ) . '</p></div>';
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
			if ( 'edit' === $action && $id && $this->repository ) {
				$item = $this->repository->get( $id, true );
			}
			$this->render_form( $item );
			return;
		}

		if ( 'view' === $action && $id && $this->repository ) {
			$item = $this->repository->get( $id, true );
			if ( $item ) {
				$this->render_pilotage( $item );
				return;
			}
			$this->render_notice( 'not_found' );
		}

		$list_table = new Competitions_Table();
		$this->maybe_handle_bulk_actions( $list_table, Menu::PAGE_COMPETITIONS );
		$list_table->prepare_items();

		?>
		<div class="wrap ufsc-competitions-admin">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Compétitions', 'ufsc-licence-competition' ); ?></h1>
			<a href="<?php echo esc_url( add_query_arg( array( 'page' => Menu::PAGE_COMPETITIONS, 'ufsc_action' => 'add' ), admin_url( 'admin.php' ) ) ); ?>" class="page-title-action"><?php esc_html_e( 'Ajouter', 'ufsc-licence-competition' ); ?></a>
			<hr class="wp-header-end">
			<?php $this->render_helper_notice( __( 'Gérer les compétitions, catégories, inscriptions et tableaux.', 'ufsc-licence-competition' ) ); ?>
			<?php $list_table->views(); ?>
			<form method="post">
				<input type="hidden" name="page" value="<?php echo esc_attr( Menu::PAGE_COMPETITIONS ); ?>" />
				<?php $list_table->display(); ?>
			</form>
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
			// organizer snapshot
			'organizer_club_id' => $item->organizer_club_id ?? 0,
			'organizer_region' => $item->organizer_region ?? '',
			'organizer_email' => $item->organizer_email ?? '',
			'organizer_phone' => $item->organizer_phone ?? '',
			// venue
			'venue_name' => $item->venue_name ?? '',
			'venue_address1' => $item->venue_address1 ?? '',
			'venue_address2' => $item->venue_address2 ?? '',
			'venue_postcode' => $item->venue_postcode ?? '',
			'venue_city' => $item->venue_city ?? '',
			'venue_region' => $item->venue_region ?? '',
			'venue_country' => $item->venue_country ?? 'FR',
			'venue_maps_url' => $item->venue_maps_url ?? '',
			'venue_access_info' => $item->venue_access_info ?? '',
			// event
			'event_start_date' => $item->event_start_date ?? '',
			'event_end_date' => $item->event_end_date ?? '',
			'event_start_time' => $item->event_start_time ?? '',
			'event_end_time' => $item->event_end_time ?? '',
			// registration
			'reg_open_date' => $item->reg_open_date ?? '',
			'reg_open_time' => $item->reg_open_time ?? '',
			'reg_close_date' => $item->reg_close_date ?? '',
			'reg_close_time' => $item->reg_close_time ?? '',
			// weighin
			'weighin_date' => $item->weighin_date ?? '',
			'weighin_start_time' => $item->weighin_start_time ?? '',
			'weighin_end_time' => $item->weighin_end_time ?? '',
			'weighin_location_text' => $item->weighin_location_text ?? '',
		);

		$disciplines = DisciplineRegistry::get_disciplines_with_types();
		$clubs = $this->club_repository->list_for_select();
		$ajax_nonce = wp_create_nonce( 'ufsc_get_club' );
		?>
		<div class="wrap ufsc-competitions-admin">
			<h1><?php echo esc_html( $values['id'] ? __( 'Modifier la compétition', 'ufsc-licence-competition' ) : __( 'Ajouter une compétition', 'ufsc-licence-competition' ) ); ?></h1>
			<div class="ufsc-competitions-form-wrap">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ufsc-competitions-form">
					<?php wp_nonce_field( 'ufsc_competitions_save_competition' ); ?>
					<input type="hidden" name="action" value="ufsc_competitions_save_competition">
					<input type="hidden" name="id" value="<?php echo esc_attr( $values['id'] ); ?>">

					<h2><?php esc_html_e( 'Identité & calendrier', 'ufsc-licence-competition' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th><label for="ufsc_comp_name"><?php esc_html_e( 'Nom', 'ufsc-licence-competition' ); ?></label></th>
							<td><input id="ufsc_comp_name" name="name" type="text" value="<?php echo esc_attr( $values['name'] ); ?>" class="regular-text" required></td>
						</tr>

						<tr>
							<th><label for="ufsc_comp_discipline"><?php esc_html_e( 'Discipline', 'ufsc-licence-competition' ); ?></label></th>
							<td>
								<select id="ufsc_comp_discipline" name="discipline" required>
									<option value=""><?php esc_html_e( 'Sélectionner', 'ufsc-licence-competition' ); ?></option>
									<?php foreach ( $disciplines as $d => $meta ) : ?>
										<option value="<?php echo esc_attr( $d ); ?>" <?php selected( $values['discipline'], $d ); ?>><?php echo esc_html( $meta['label'] ?? $d ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>

						<tr>
							<th><label for="ufsc_comp_type"><?php esc_html_e( 'Type', 'ufsc-licence-competition' ); ?></label></th>
							<td><input id="ufsc_comp_type" name="type" type="text" value="<?php echo esc_attr( $values['type'] ); ?>" class="regular-text" required></td>
						</tr>

						<tr>
							<th><label for="ufsc_comp_season"><?php esc_html_e( 'Saison', 'ufsc-licence-competition' ); ?></label></th>
							<td><input id="ufsc_comp_season" name="season" type="text" value="<?php echo esc_attr( $values['season'] ); ?>" class="regular-text" required></td>
						</tr>

						<tr>
							<th><label for="ufsc_comp_status"><?php esc_html_e( 'Statut', 'ufsc-licence-competition' ); ?></label></th>
							<td>
								<select id="ufsc_comp_status" name="status">
									<option value="draft" <?php selected( $values['status'], 'draft' ); ?>><?php esc_html_e( 'Brouillon', 'ufsc-licence-competition' ); ?></option>
									<option value="preparing" <?php selected( $values['status'], 'preparing' ); ?>><?php esc_html_e( 'Préparation', 'ufsc-licence-competition' ); ?></option>
									<option value="open" <?php selected( $values['status'], 'open' ); ?>><?php esc_html_e( 'Inscriptions ouvertes', 'ufsc-licence-competition' ); ?></option>
									<option value="running" <?php selected( $values['status'], 'running' ); ?>><?php esc_html_e( 'En cours', 'ufsc-licence-competition' ); ?></option>
									<option value="closed" <?php selected( $values['status'], 'closed' ); ?>><?php esc_html_e( 'Clôturée', 'ufsc-licence-competition' ); ?></option>
									<option value="archived" <?php selected( $values['status'], 'archived' ); ?>><?php esc_html_e( 'Archivée', 'ufsc-licence-competition' ); ?></option>
								</select>
							</td>
						</tr>
					</table>

					<h2><?php esc_html_e( 'Club organisateur', 'ufsc-licence-competition' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th><label for="ufsc_org_club"><?php esc_html_e( 'Club organisateur', 'ufsc-licence-competition' ); ?></label></th>
							<td>
								<select id="ufsc_org_club" name="organizer_club_id" required>
									<option value="0"><?php esc_html_e( 'Sélectionner un club', 'ufsc-licence-competition' ); ?></option>
									<?php foreach ( $clubs as $cid => $label ) : ?>
										<option value="<?php echo esc_attr( $cid ); ?>" <?php selected( $values['organizer_club_id'], $cid ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'Les informations du club sont stockées dans la table des clubs. Seule une snapshot (région/email/tel) est conservée ici.', 'ufsc-licence-competition' ); ?></p>
							</td>
						</tr>

						<tr>
							<th><?php esc_html_e( 'Infos club (snapshot)', 'ufsc-licence-competition' ); ?></th>
							<td>
								<input type="text" readonly class="regular-text" id="ufsc_org_region" placeholder="<?php esc_attr_e( 'Région', 'ufsc-licence-competition' ); ?>" value="<?php echo esc_attr( $values['organizer_region'] ); ?>">
								<input type="text" readonly class="regular-text" id="ufsc_org_email" placeholder="<?php esc_attr_e( 'Email', 'ufsc-licence-competition' ); ?>" value="<?php echo esc_attr( $values['organizer_email'] ); ?>">
								<input type="text" readonly class="regular-text" id="ufsc_org_phone" placeholder="<?php esc_attr_e( 'Téléphone', 'ufsc-licence-competition' ); ?>" value="<?php echo esc_attr( $values['organizer_phone'] ); ?>">
							</td>
						</tr>
					</table>

					<h2><?php esc_html_e( 'Lieu de la compétition (manifestation)', 'ufsc-licence-competition' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Adresse distincte de l’adresse du club. Vous pouvez copier l’adresse du club manuellement si nécessaire.', 'ufsc-licence-competition' ); ?></p>
					<table class="form-table" role="presentation">
						<tr>
							<th><label for="ufsc_venue_name"><?php esc_html_e( 'Nom du lieu', 'ufsc-licence-competition' ); ?></label></th>
							<td><input id="ufsc_venue_name" name="venue_name" type="text" value="<?php echo esc_attr( $values['venue_name'] ); ?>" class="regular-text" required></td>
						</tr>
						<tr>
							<th><label for="ufsc_venue_address1"><?php esc_html_e( 'Adresse (ligne 1)', 'ufsc-licence-competition' ); ?></label></th>
							<td><input id="ufsc_venue_address1" name="venue_address1" type="text" value="<?php echo esc_attr( $values['venue_address1'] ); ?>" class="regular-text" required></td>
						</tr>
						<tr>
							<th><label for="ufsc_venue_address2"><?php esc_html_e( 'Complément d\'adresse', 'ufsc-licence-competition' ); ?></label></th>
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
						<tr>
							<th><label for="ufsc_venue_country"><?php esc_html_e( 'Pays', 'ufsc-licence-competition' ); ?></label></th>
							<td><input id="ufsc_venue_country" name="venue_country" type="text" value="<?php echo esc_attr( $values['venue_country'] ); ?>" class="small-text"></td>
						</tr>
						<tr>
							<th><label for="ufsc_venue_maps_url"><?php esc_html_e( 'URL carte / plan', 'ufsc-licence-competition' ); ?></label></th>
							<td><input id="ufsc_venue_maps_url" name="venue_maps_url" type="url" value="<?php echo esc_attr( $values['venue_maps_url'] ); ?>" class="regular-text"></td>
						</tr>
						<tr>
							<th><label for="ufsc_venue_access_info"><?php esc_html_e( 'Accès / remarques', 'ufsc-licence-competition' ); ?></label></th>
							<td><textarea id="ufsc_venue_access_info" name="venue_access_info" rows="4" class="large-text"><?php echo esc_textarea( $values['venue_access_info'] ); ?></textarea></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Actions', 'ufsc-licence-competition' ); ?></th>
							<td>
								<button type="button" id="ufsc_copy_club_address" class="button"><?php esc_html_e( 'Copier l\'adresse du club', 'ufsc-licence-competition' ); ?></button>
							</td>
						</tr>
					</table>

					<h2><?php esc_html_e( 'Dates & horaires de la manifestation', 'ufsc-licence-competition' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th><label for="ufsc_event_start_date"><?php esc_html_e( 'Date début', 'ufsc-licence-competition' ); ?></label></th>
							<td><input id="ufsc_event_start_date" name="event_start_date" type="date" value="<?php echo esc_attr( $values['event_start_date'] ); ?>" required></td>
						</tr>
						<tr>
							<th><label for="ufsc_event_end_date"><?php esc_html_e( 'Date fin', 'ufsc-licence-competition' ); ?></label></th>
							<td><input id="ufsc_event_end_date" name="event_end_date" type="date" value="<?php echo esc_attr( $values['event_end_date'] ); ?>"></td>
						</tr>
						<tr>
							<th><label for="ufsc_event_start_time"><?php esc_html_e( 'Heure début', 'ufsc-licence-competition' ); ?></label></th>
							<td><input id="ufsc_event_start_time" name="event_start_time" type="time" value="<?php echo esc_attr( $values['event_start_time'] ); ?>"></td>
						</tr>
						<tr>
							<th><label for="ufsc_event_end_time"><?php esc_html_e( 'Heure fin', 'ufsc-licence-competition' ); ?></label></th>
							<td><input id="ufsc_event_end_time" name="event_end_time" type="time" value="<?php echo esc_attr( $values['event_end_time'] ); ?>"></td>
						</tr>
					</table>

					<h2><?php esc_html_e( 'Inscriptions', 'ufsc-licence-competition' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th><label for="ufsc_reg_open_date"><?php esc_html_e( 'Ouverture', 'ufsc-licence-competition' ); ?></label></th>
							<td>
								<input id="ufsc_reg_open_date" name="reg_open_date" type="date" value="<?php echo esc_attr( $values['reg_open_date'] ); ?>">
								<input id="ufsc_reg_open_time" name="reg_open_time" type="time" value="<?php echo esc_attr( $values['reg_open_time'] ); ?>">
							</td>
						</tr>
						<tr>
							<th><label for="ufsc_reg_close_date"><?php esc_html_e( 'Fermeture', 'ufsc-licence-competition' ); ?></label></th>
							<td>
								<input id="ufsc_reg_close_date" name="reg_close_date" type="date" value="<?php echo esc_attr( $values['reg_close_date'] ); ?>">
								<input id="ufsc_reg_close_time" name="reg_close_time" type="time" value="<?php echo esc_attr( $values['reg_close_time'] ); ?>">
							</td>
						</tr>
					</table>

					<h2><?php esc_html_e( 'Pesée', 'ufsc-licence-competition' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th><label for="ufsc_weighin_date"><?php esc_html_e( 'Date de pesée', 'ufsc-licence-competition' ); ?></label></th>
							<td><input id="ufsc_weighin_date" name="weighin_date" type="date" value="<?php echo esc_attr( $values['weighin_date'] ); ?>"></td>
						</tr>
						<tr>
							<th><label for="ufsc_weighin_start"><?php esc_html_e( 'Heure début', 'ufsc-licence-competition' ); ?></label></th>
							<td><input id="ufsc_weighin_start" name="weighin_start_time" type="time" value="<?php echo esc_attr( $values['weighin_start_time'] ); ?>"></td>
						</tr>
						<tr>
							<th><label for="ufsc_weighin_end"><?php esc_html_e( 'Heure fin', 'ufsc-licence-competition' ); ?></label></th>
							<td><input id="ufsc_weighin_end" name="weighin_end_time" type="time" value="<?php echo esc_attr( $values['weighin_end_time'] ); ?>"></td>
						</tr>
						<tr>
							<th><label for="ufsc_weighin_location"><?php esc_html_e( 'Lieu de pesée (si différent)', 'ufsc-licence-competition' ); ?></label></th>
							<td><textarea id="ufsc_weighin_location" name="weighin_location_text" rows="3" class="large-text"><?php echo esc_textarea( $values['weighin_location_text'] ); ?></textarea></td>
						</tr>
					</table>

					<?php submit_button( $values['id'] ? __( 'Mettre à jour la compétition', 'ufsc-licence-competition' ) : __( 'Créer la compétition', 'ufsc-licence-competition' ) ); ?>
				</form>
			</div>
		</div>

		<script>
		( function() {
			document.addEventListener( 'DOMContentLoaded', function() {
				var btn = document.getElementById('ufsc_copy_club_address');
				if (!btn) return;
				btn.addEventListener('click', function() {
					var select = document.getElementById('ufsc_org_club');
					if (!select) return;
					var clubId = select.value;
					if (!clubId || clubId === '0') {
						alert('<?php echo esc_js( __( 'Sélectionnez d\'abord un club.', 'ufsc-licence-competition' ) ); ?>');
						return;
					}

					// AJAX fetch club row (secure)
					var data = new FormData();
					data.append('action', 'ufsc_get_club');
					data.append('club_id', clubId);
					data.append('nonce', '<?php echo esc_js( $ajax_nonce ); ?>');

					fetch( '<?php echo admin_url( 'admin-ajax.php' ); ?>', {
						method: 'POST',
						credentials: 'same-origin',
						body: data
					} ).then(function(res){ return res.json(); }).then(function(json){
						if (!json || !json.success) {
							alert('<?php echo esc_js( __( 'Impossible de récupérer l\'adresse du club.', 'ufsc-licence-competition' ) ); ?>');
							return;
						}
						var club = json.data;
						// copy relevant fields if present
						if (club.adresse) {
							document.getElementById('ufsc_venue_address1').value = club.adresse || '';
						}
						if (club.complement_adresse) {
							document.getElementById('ufsc_venue_address2').value = club.complement_adresse || '';
						}
						if (club.code_postal) {
							document.getElementById('ufsc_venue_postcode').value = club.code_postal || '';
						}
						if (club.ville) {
							document.getElementById('ufsc_venue_city').value = club.ville || '';
						}
						if (club.region) {
							document.getElementById('ufsc_venue_region').value = club.region || '';
						}
					}).catch(function(){
						alert('<?php echo esc_js( __( 'Erreur réseau lors de la récupération du club.', 'ufsc-licence-competition' ) ); ?>');
					});
				});
			});
		} )();
		</script>
		<?php
	}

	private function render_pilotage( $item ) {
		echo '<h2>' . esc_html( $item->name ) . '</h2>';
		// pilotage display stub
	}

	private function maybe_handle_bulk_actions( $list_table, $page ) {
		// noop stub
	}

	private function render_helper_notice( $message ) {
		echo '<div class="notice notice-info ufsc-competitions-helper"><p>' . esc_html( $message ) . '</p></div>';
	}

	public function handle_save() {
		if ( ! Capabilities::user_can_manage() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'ufsc_competitions_save_competition' );

		$input = $_POST;
		// sanitize via repository layer
		$data = array();
		$fields = array(
			'id','name','discipline','type','season','status',
			'organizer_club_id','venue_name','venue_address1','venue_address2','venue_postcode','venue_city','venue_region','venue_country','venue_maps_url','venue_access_info',
			'event_start_date','event_end_date','event_start_time','event_end_time',
			'reg_open_date','reg_open_time','reg_close_date','reg_close_time',
			'weighin_date','weighin_start_time','weighin_end_time','weighin_location_text'
		);

		foreach ( $fields as $f ) {
			if ( isset( $input[ $f ] ) ) {
				$data[ $f ] = wp_unslash( $input[ $f ] );
			}
		}

		// basic required validation
		$required = array( 'name','discipline','type','season','organizer_club_id','venue_name','venue_address1','venue_postcode','venue_city','venue_region','event_start_date' );
		foreach ( $required as $r ) {
			if ( empty( $data[ $r ] ) ) {
				$redirect = add_query_arg( array( 'page' => Menu::PAGE_COMPETITIONS, 'ufsc_action' => 'add', 'ufsc_notice' => 'invalid' ), admin_url( 'admin.php' ) );
				wp_safe_redirect( $redirect );
				exit;
			}
		}

		// load club snapshot
		$clubId = absint( $data['organizer_club_id'] );
		$club = $this->club_repository->get( $clubId );
		if ( $club ) {
			$data['organizer_region'] = $club->region ?? '';
			$data['organizer_email'] = $club->email ?? '';
			$data['organizer_phone'] = $club->telephone ?? ( $club->phone ?? '' );
		}

		// enforce event_end_date default
		if ( empty( $data['event_end_date'] ) ) {
			$data['event_end_date'] = $data['event_start_date'];
		}

		// logical checks: reg_open <= reg_close if both provided
		if ( ! empty( $data['reg_open_date'] ) && ! empty( $data['reg_close_date'] ) ) {
			if ( strtotime( $data['reg_open_date'] ) > strtotime( $data['reg_close_date'] ) ) {
				$redirect = add_query_arg( array( 'page' => Menu::PAGE_COMPETITIONS, 'ufsc_action' => 'add', 'ufsc_notice' => 'invalid' ), admin_url( 'admin.php' ) );
				wp_safe_redirect( $redirect );
				exit;
			}
		}

		// weighin times ordering
		if ( ! empty( $data['weighin_start_time'] ) && ! empty( $data['weighin_end_time'] ) ) {
			if ( strtotime( $data['weighin_start_time'] ) > strtotime( $data['weighin_end_time'] ) ) {
				$redirect = add_query_arg( array( 'page' => Menu::PAGE_COMPETITIONS, 'ufsc_action' => 'add', 'ufsc_notice' => 'invalid' ), admin_url( 'admin.php' ) );
				wp_safe_redirect( $redirect );
				exit;
			}
		}

		// persist
		$id = 0;
		if ( $this->repository ) {
			$id = $this->repository->save( $data );
		}

		$redirect = add_query_arg( array( 'page' => Menu::PAGE_COMPETITIONS, 'ufsc_notice' => 'saved' ), admin_url( 'admin.php' ) );
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Ajax handler: returns club data (safe subset) for a club_id
	 */
	public function ajax_get_club() {
		if ( ! current_user_can( 'manage_options' ) && ! Capabilities::user_can_manage() ) {
			wp_send_json_error( 'forbidden', 403 );
		}

		check_ajax_referer( 'ufsc_get_club', 'nonce' );

		$club_id = isset( $_POST['club_id'] ) ? absint( $_POST['club_id'] ) : 0;
		if ( ! $club_id ) {
			wp_send_json_error( 'missing' );
		}

		$club = $this->club_repository->get( $club_id );
		if ( ! $club ) {
			wp_send_json_error( 'not_found' );
		}

		// Prepare safe subset
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
