<?php

namespace UFSC\Competitions\Admin\Pages;

use UFSC\Competitions\Admin\Menu;
use UFSC\Competitions\Admin\Exports\Entries_Export_Controller;
use UFSC\Competitions\Repositories\CompetitionRepository;
use UFSC\Competitions\Repositories\ClubRepository;
use UFSC\Competitions\Services\CompetitionFilters;
use UFSC\Competitions\Services\CompetitionMeta;
use UFSC\Competitions\Services\DisciplineRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Competitions_Page {
	/** @var bool */
	private static $actions_registered = false;

	/** @var CompetitionRepository|null */
	private $repository;

	/** @var ClubRepository|null */
	private $club_repository;

	public function __construct() {
		$this->repository = class_exists( '\\UFSC\\Competitions\\Repositories\\CompetitionRepository' )
			? new CompetitionRepository()
			: null;

		$this->club_repository = class_exists( '\\UFSC\\Competitions\\Repositories\\ClubRepository' )
			? new ClubRepository()
			: null;

		add_action( 'wp_ajax_ufsc_get_club', array( $this, 'ajax_get_club' ) );
		$this->register_actions();
	}

	/**
	 * Hook admin_post handlers.
	 * Ideally called once by Menu registration.
	 */
	public function register_actions() {
		if ( self::$actions_registered ) {
			return;
		}
		self::$actions_registered = true;

		add_action( 'admin_post_ufsc_competitions_save_competition', array( $this, 'handle_save' ) );
		add_action( 'admin_post_ufsc_competitions_trash_competition', array( $this, 'handle_trash' ) );
		add_action( 'admin_post_ufsc_competitions_restore_competition', array( $this, 'handle_restore' ) );
		add_action( 'admin_post_ufsc_competitions_delete_competition', array( $this, 'handle_delete' ) );
		add_action( 'admin_post_ufsc_competitions_archive_competition', array( $this, 'handle_archive' ) );
		add_action( 'admin_post_ufsc_competitions_unarchive_competition', array( $this, 'handle_unarchive' ) );
		add_action( 'admin_post_ufsc_competitions_bulk', array( $this, 'handle_bulk_actions' ) );
	}

	/**
	 * Standard public render() expected by admin menu callbacks.
	 */
	public function render() {
		$cap = \UFSC_LC_Capabilities::get_manage_capability();
		if ( ! current_user_can( $cap ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		if ( ! $this->repository ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Compétitions', 'ufsc-licence-competition' ) . '</h1>';
			echo '<div class="notice notice-error"><p>' .
				esc_html__( 'Repository indisponible. Vérifiez l’autoload / bootstrap du module Compétitions.', 'ufsc-licence-competition' ) .
			'</p></div></div>';
			return;
		}

		$action = isset( $_GET['ufsc_action'] ) ? sanitize_key( wp_unslash( $_GET['ufsc_action'] ) ) : '';
		$notice = isset( $_GET['ufsc_notice'] ) ? sanitize_key( wp_unslash( $_GET['ufsc_notice'] ) ) : '';
		$id     = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

		$this->render_notice( $notice );

		if ( in_array( $action, array( 'add', 'edit' ), true ) ) {
			$item = null;

			if ( 'edit' === $action ) {
				if ( ! $id ) {
					$this->redirect_notice( 'invalid' );
				}
				$item = $this->repository->get( $id, true );
				if ( ! $item ) {
					$this->redirect_notice( 'not_found' );
				}
			}

			$this->render_form( $item );
			return;
		}

		$this->render_list();
	}

	/**
	 * LIST VIEW
	 */
	private function render_list() {
		if ( ! class_exists( '\\UFSC\\Competitions\\Admin\\Tables\\Competitions_Table' ) ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Compétitions', 'ufsc-licence-competition' ) . '</h1>';
			echo '<div class="notice notice-warning"><p>' .
				esc_html__( 'Tableau introuvable : la classe Competitions_Table est manquante.', 'ufsc-licence-competition' ) .
			'</p></div></div>';
			return;
		}

		$list_table = new \UFSC\Competitions\Admin\Tables\Competitions_Table();

		$list_table->prepare_items();

		$current_view = isset( $_GET['ufsc_view'] ) ? sanitize_key( wp_unslash( $_GET['ufsc_view'] ) ) : 'all';
		$search       = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$is_edit      = false;

		?>
		<div class="wrap ufsc-competitions-admin">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Compétitions', 'ufsc-licence-competition' ); ?></h1>

			<a href="<?php echo esc_url( add_query_arg( array( 'page' => Menu::MENU_SLUG, 'ufsc_action' => 'add' ), admin_url( 'admin.php' ) ) ); ?>" class="page-title-action">
				<?php esc_html_e( 'Ajouter', 'ufsc-licence-competition' ); ?>
			</a>

			<hr class="wp-header-end">

			<?php $list_table->views(); ?>

			<!-- Search (GET) -->
			<form method="get" style="margin: 8px 0 12px;">
				<input type="hidden" name="page" value="<?php echo esc_attr( Menu::MENU_SLUG ); ?>" />
				<input type="hidden" name="ufsc_view" value="<?php echo esc_attr( $current_view ); ?>" />
				<?php $list_table->search_box( __( 'Rechercher', 'ufsc-licence-competition' ), 'ufsc-competitions-search' ); ?>
			</form>

			<!-- Table + bulk actions (POST) -->
			<form method="post" action="<?php echo esc_url( add_query_arg( array( 'action' => 'ufsc_competitions_bulk' ), admin_url( 'admin-post.php' ) ) ); ?>">
				<?php
				echo '<input type="hidden" name="page" value="' . esc_attr( Menu::MENU_SLUG ) . '" />';
				echo '<input type="hidden" name="ufsc_view" value="' . esc_attr( $current_view ) . '" />';
				if ( $search !== '' ) {
					echo '<input type="hidden" name="s" value="' . esc_attr( $search ) . '" />';
				}

				$list_table->display();
				?>
			</form>

			<?php if ( $is_edit && class_exists( Entries_Export_Controller::class ) ) : ?>
				<?php
				$export_controller = new Entries_Export_Controller();
				$history = $export_controller->get_exports_history( (int) $item->id );
				?>
				<h2><?php esc_html_e( 'Historique des exports PDF (inscriptions)', 'ufsc-licence-competition' ); ?></h2>
				<?php if ( empty( $history ) ) : ?>
					<p><?php esc_html_e( 'Aucun export enregistré.', 'ufsc-licence-competition' ); ?></p>
				<?php else : ?>
					<ul>
						<?php foreach ( array_reverse( $history ) as $export ) : ?>
							<?php
							$export_id = (string) ( $export['id'] ?? '' );
							$filename = (string) ( $export['filename'] ?? '' );
							$generated_at = (string) ( $export['generated_at'] ?? '' );
							$download_url = wp_nonce_url(
								add_query_arg(
									array(
										'action' => 'ufsc_competitions_download_entries_export',
										'competition_id' => (int) $item->id,
										'export_id' => $export_id,
										'mode' => 'download',
									),
									admin_url( 'admin-post.php' )
								),
								'ufsc_competitions_download_entries_export_' . $export_id
							);
							$view_url = wp_nonce_url(
								add_query_arg(
									array(
										'action' => 'ufsc_competitions_download_entries_export',
										'competition_id' => (int) $item->id,
										'export_id' => $export_id,
										'mode' => 'inline',
									),
									admin_url( 'admin-post.php' )
								),
								'ufsc_competitions_download_entries_export_' . $export_id
							);
							$display_date = function_exists( 'ufsc_lc_format_datetime' ) ? ufsc_lc_format_datetime( $generated_at ) : $generated_at;
							?>
							<li>
								<strong><?php echo esc_html( $filename ?: $export_id ); ?></strong>
								<?php if ( $display_date ) : ?>
									— <?php echo esc_html( $display_date ); ?>
								<?php endif; ?>
								<a href="<?php echo esc_url( $view_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Consulter', 'ufsc-licence-competition' ); ?></a>
								|
								<a href="<?php echo esc_url( $download_url ); ?>"><?php esc_html_e( 'Télécharger', 'ufsc-licence-competition' ); ?></a>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * FORM VIEW (add/edit)
	 *
	 * @param object|null $item
	 */
	private function render_form( $item ) {
		$is_edit = ( is_object( $item ) && ! empty( $item->id ) );

		$action_url = admin_url( 'admin-post.php' );
		$back_url   = add_query_arg( array( 'page' => Menu::MENU_SLUG ), admin_url( 'admin.php' ) );

		$name       = $is_edit ? (string) ( $item->name ?? '' ) : '';
		$discipline = $is_edit ? (string) ( $item->discipline ?? '' ) : '';
		$type       = $is_edit ? (string) ( $item->type ?? '' ) : '';
		$season     = $is_edit ? (string) ( $item->season ?? '' ) : '';
		$status     = $is_edit ? (string) ( $item->status ?? '' ) : 'open';

		// Optional datetime fields if present in schema.
		$event_start = $is_edit ? (string) ( $item->event_start_datetime ?? '' ) : '';
		$event_end   = $is_edit ? (string) ( $item->event_end_datetime ?? '' ) : '';

		$event_meta = $is_edit ? CompetitionMeta::get( (int) $item->id ) : CompetitionMeta::get( 0 );
		$event_meta = is_array( $event_meta ) ? $event_meta : array();

		$lieu_name              = (string) ( $event_meta['lieu_name'] ?? '' );
		$lieu_address           = (string) ( $event_meta['lieu_address'] ?? '' );
		$photo_evenement_id     = absint( $event_meta['photo_evenement_id'] ?? 0 );
		$weighin_start           = (string) ( $event_meta['weighin_start'] ?? '' );
		$weighin_end             = (string) ( $event_meta['weighin_end'] ?? '' );
		$briefing_time           = (string) ( $event_meta['briefing_time'] ?? '' );
		$fights_start            = (string) ( $event_meta['fights_start'] ?? '' );
		$event_end_estimated     = (string) ( $event_meta['event_end_estimated'] ?? '' );
		$registration_deadline   = (string) ( $event_meta['registration_deadline'] ?? '' );
		$organizer_contact_name  = (string) ( $event_meta['organizer_contact_name'] ?? '' );
		$organizer_phone         = (string) ( $event_meta['organizer_phone'] ?? '' );
		$organizer_email         = (string) ( $event_meta['organizer_email'] ?? '' );
		$club_notes              = (string) ( $event_meta['club_notes'] ?? '' );
		$access_mode             = (string) ( $event_meta['access_mode'] ?? 'affiliated' );
		$allowed_regions          = is_array( $event_meta['allowed_regions'] ?? null ) ? $event_meta['allowed_regions'] : array();
		$allowed_disciplines      = is_array( $event_meta['allowed_disciplines'] ?? null ) ? $event_meta['allowed_disciplines'] : array();
		$allowed_club_ids         = is_array( $event_meta['allowed_club_ids'] ?? null ) ? $event_meta['allowed_club_ids'] : array();
		$public_read              = ! empty( $event_meta['public_read'] );
		$require_affiliated       = isset( $event_meta['require_affiliated'] ) ? (bool) $event_meta['require_affiliated'] : true;
		$require_logged_in_club   = isset( $event_meta['require_logged_in_club'] ) ? (bool) $event_meta['require_logged_in_club'] : true;
		$require_valid_license    = ! empty( $event_meta['require_valid_license'] );
		$photo_evenement_url      = '';
		if ( $photo_evenement_id && function_exists( 'wp_attachment_is_image' ) && wp_attachment_is_image( $photo_evenement_id ) ) {
			$photo_evenement_url = function_exists( 'wp_get_attachment_image_url' )
				? (string) wp_get_attachment_image_url( $photo_evenement_id, 'medium' )
				: '';
		}
		$allowed_regions_map = array();
		if ( function_exists( 'ufsc_normalize_region_key' ) ) {
			foreach ( $allowed_regions as $region_label ) {
				$key = ufsc_normalize_region_key( $region_label );
				if ( '' === $key ) {
					continue;
				}
				if ( ! isset( $allowed_regions_map[ $key ] ) ) {
					$allowed_regions_map[ $key ] = (string) $region_label;
				}
			}
		} else {
			foreach ( $allowed_regions as $region_label ) {
				$label = trim( (string) $region_label );
				if ( '' !== $label ) {
					$allowed_regions_map[ $label ] = $label;
				}
			}
		}

		$allowed_regions = array_values( $allowed_regions_map );
		$allowed_region_keys = array_keys( $allowed_regions_map );

		$club_regions = $this->club_repository ? $this->club_repository->list_regions() : array();
		$club_select  = $this->club_repository ? $this->club_repository->list_for_select() : array();
		$regions_for_select = array();
		if ( function_exists( 'ufsc_normalize_region_key' ) ) {
			foreach ( $club_regions as $region ) {
				$key = ufsc_normalize_region_key( $region );
				if ( '' === $key ) {
					continue;
				}
				if ( ! isset( $regions_for_select[ $key ] ) ) {
					$regions_for_select[ $key ] = (string) $region;
				}
			}
		} else {
			foreach ( $club_regions as $region ) {
				$label = trim( (string) $region );
				if ( '' !== $label && ! isset( $regions_for_select[ $label ] ) ) {
					$regions_for_select[ $label ] = $label;
				}
			}
		}

		foreach ( $allowed_regions_map as $key => $label ) {
			if ( ! isset( $regions_for_select[ $key ] ) ) {
				$regions_for_select[ $key ] = $label;
			}
		}
		$disciplines  = class_exists( DisciplineRegistry::class ) ? DisciplineRegistry::get_disciplines() : array();
		$show_all_clubs_mode = (bool) apply_filters( 'ufsc_competitions_access_show_all_clubs_mode', true );
		$preview_mode_label = __( 'Clubs affiliés', 'ufsc-licence-competition' );
		switch ( $access_mode ) {
			case 'all_clubs':
				$preview_mode_label = __( 'Tous les clubs', 'ufsc-licence-competition' );
				break;
			case 'regions':
				$preview_mode_label = __( 'Régions', 'ufsc-licence-competition' );
				break;
			case 'clubs':
				$preview_mode_label = __( 'Clubs sélectionnés', 'ufsc-licence-competition' );
				break;
			case 'disciplines':
				$preview_mode_label = __( 'Disciplines', 'ufsc-licence-competition' );
				break;
			case 'region_discipline':
				$preview_mode_label = __( 'Région + discipline', 'ufsc-licence-competition' );
				break;
		}
		$preview_regions_count = count( array_filter( $allowed_regions ) );
		$preview_clubs_count = count( array_filter( $allowed_club_ids ) );
		$preview_affiliation_label = $require_affiliated ? __( 'Oui', 'ufsc-licence-competition' ) : __( 'Non', 'ufsc-licence-competition' );
		$clubs_mode_empty_warning = ( 'clubs' === $access_mode && empty( $allowed_club_ids ) );

		?>
		<div class="wrap ufsc-competitions-admin">
			<h1><?php echo esc_html( $is_edit ? __( 'Modifier une compétition', 'ufsc-licence-competition' ) : __( 'Ajouter une compétition', 'ufsc-licence-competition' ) ); ?></h1>

			<p><a class="button" href="<?php echo esc_url( $back_url ); ?>">&larr; <?php esc_html_e( 'Retour à la liste', 'ufsc-licence-competition' ); ?></a></p>

			<form method="post" action="<?php echo esc_url( $action_url ); ?>">
				<input type="hidden" name="action" value="ufsc_competitions_save_competition" />
				<?php wp_nonce_field( 'ufsc_competitions_save_competition' ); ?>

				<?php if ( $is_edit ) : ?>
					<input type="hidden" name="id" value="<?php echo esc_attr( (int) $item->id ); ?>" />
				<?php endif; ?>

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="name"><?php esc_html_e( 'Nom', 'ufsc-licence-competition' ); ?></label></th>
							<td><input name="name" id="name" type="text" class="regular-text" value="<?php echo esc_attr( $name ); ?>" required /></td>
						</tr>

						<tr>
							<th scope="row"><label for="discipline"><?php esc_html_e( 'Discipline', 'ufsc-licence-competition' ); ?></label></th>
							<td>
								<?php
								$discipline_choices = class_exists( DisciplineRegistry::class ) ? DisciplineRegistry::get_disciplines() : array();
								$discipline_key = $discipline ? DisciplineRegistry::normalize( $discipline ) : '';
								$discipline_selected = ( $discipline_key && isset( $discipline_choices[ $discipline_key ] ) ) ? $discipline_key : $discipline;
								?>
								<select name="discipline" id="discipline" class="regular-text">
									<option value=""><?php esc_html_e( 'Sélectionner', 'ufsc-licence-competition' ); ?></option>
									<?php foreach ( $discipline_choices as $value => $label ) : ?>
										<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $discipline_selected, $value ); ?>>
											<?php echo esc_html( $label ); ?>
										</option>
									<?php endforeach; ?>
									<?php if ( $discipline && ! isset( $discipline_choices[ $discipline_selected ] ) ) : ?>
										<option value="<?php echo esc_attr( $discipline ); ?>" selected>
											<?php echo esc_html( sprintf( __( 'Valeur existante : %s', 'ufsc-licence-competition' ), $discipline ) ); ?>
										</option>
									<?php endif; ?>
								</select>
								<p class="description"><?php esc_html_e( 'La discipline est utilisée pour les filtres, les catégories et le timing.', 'ufsc-licence-competition' ); ?></p>
							</td>
						</tr>

						<tr>
							<th scope="row"><label for="type"><?php esc_html_e( 'Type', 'ufsc-licence-competition' ); ?></label></th>
							<td>
								<?php
								$type_choices = CompetitionFilters::get_type_choices();
								$type_key = $type ? CompetitionFilters::normalize_type_key( $type ) : '';
								$type_selected = ( $type_key && isset( $type_choices[ $type_key ] ) ) ? $type_key : $type;
								?>
								<select name="type" id="type" class="regular-text">
									<option value=""><?php esc_html_e( 'Sélectionner', 'ufsc-licence-competition' ); ?></option>
									<?php foreach ( $type_choices as $value => $label ) : ?>
										<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $type_selected, $value ); ?>>
											<?php echo esc_html( $label ); ?>
										</option>
									<?php endforeach; ?>
									<?php if ( $type && ! isset( $type_choices[ $type_selected ] ) ) : ?>
										<option value="<?php echo esc_attr( $type ); ?>" selected>
											<?php echo esc_html( sprintf( __( 'Valeur existante : %s', 'ufsc-licence-competition' ), $type ) ); ?>
										</option>
									<?php endif; ?>
								</select>
								<p class="description"><?php esc_html_e( 'Le type alimente les filtres et le profil de timing.', 'ufsc-licence-competition' ); ?></p>
							</td>
						</tr>

						<tr>
							<th scope="row"><label for="season"><?php esc_html_e( 'Saison', 'ufsc-licence-competition' ); ?></label></th>
							<td><input name="season" id="season" type="text" class="regular-text" value="<?php echo esc_attr( $season ); ?>" placeholder="2025-2026" /></td>
						</tr>

						<tr>
							<th scope="row"><label for="status"><?php esc_html_e( 'Statut', 'ufsc-licence-competition' ); ?></label></th>
							<td>
								<select name="status" id="status">
									<?php
									$choices = array(
										'open'     => __( 'Ouverte', 'ufsc-licence-competition' ),
										'draft'    => __( 'Brouillon', 'ufsc-licence-competition' ),
										'closed'   => __( 'Clôturée', 'ufsc-licence-competition' ),
										'archived' => __( 'Archivée', 'ufsc-licence-competition' ),
									);
									foreach ( $choices as $k => $label ) {
										printf(
											'<option value="%s"%s>%s</option>',
											esc_attr( $k ),
											selected( $status, $k, false ),
											esc_html( $label )
										);
									}
									?>
								</select>
							</td>
						</tr>

						<tr>
							<th scope="row"><label for="event_start_datetime"><?php esc_html_e( 'Début (datetime)', 'ufsc-licence-competition' ); ?></label></th>
							<td>
								<input name="event_start_datetime" id="event_start_datetime" type="text" class="regular-text"
									value="<?php echo esc_attr( $event_start ); ?>"
									placeholder="YYYY-MM-DD HH:MM:SS" />
							</td>
						</tr>

						<tr>
							<th scope="row"><label for="event_end_datetime"><?php esc_html_e( 'Fin (datetime)', 'ufsc-licence-competition' ); ?></label></th>
							<td>
								<input name="event_end_datetime" id="event_end_datetime" type="text" class="regular-text"
									value="<?php echo esc_attr( $event_end ); ?>"
									placeholder="YYYY-MM-DD HH:MM:SS" />
							</td>
						</tr>
					</tbody>
				</table>

				<h2><?php esc_html_e( 'Informations événement', 'ufsc-licence-competition' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Tous ces champs sont optionnels. Ils sont affichés sur la page détail compétition.', 'ufsc-licence-competition' ); ?></p>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="lieu_name"><?php esc_html_e( 'Lieu (nom salle / complexe)', 'ufsc-licence-competition' ); ?></label></th>
							<td>
								<input name="lieu_name" id="lieu_name" type="text" class="regular-text" value="<?php echo esc_attr( $lieu_name ); ?>" />
								<p class="description"><?php esc_html_e( 'Optionnel – affiché aux clubs sur la page détail.', 'ufsc-licence-competition' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="lieu_address"><?php esc_html_e( 'Adresse complète', 'ufsc-licence-competition' ); ?></label></th>
							<td>
								<textarea name="lieu_address" id="lieu_address" class="large-text" rows="3"><?php echo esc_textarea( $lieu_address ); ?></textarea>
								<p class="description"><?php esc_html_e( 'Optionnel – affichée aux clubs sur la page détail.', 'ufsc-licence-competition' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Photo événement', 'ufsc-licence-competition' ); ?></th>
							<td>
								<div class="ufsc-competition-photo-field">
									<input type="hidden" name="photo_evenement_id" id="ufsc_photo_evenement_id" value="<?php echo esc_attr( $photo_evenement_id ); ?>" />
									<div class="ufsc-competition-photo-preview" data-ufsc-photo-preview>
										<?php if ( $photo_evenement_url ) : ?>
											<img src="<?php echo esc_url( $photo_evenement_url ); ?>" alt="" />
										<?php endif; ?>
									</div>
									<p>
										<button type="button" class="button ufsc-photo-select" data-ufsc-photo-select><?php esc_html_e( 'Choisir une photo', 'ufsc-licence-competition' ); ?></button>
										<button type="button" class="button ufsc-photo-remove" data-ufsc-photo-remove <?php echo $photo_evenement_id ? '' : 'style="display:none;"'; ?>>
											<?php esc_html_e( 'Supprimer la photo', 'ufsc-licence-competition' ); ?>
										</button>
									</p>
									<p class="description"><?php esc_html_e( 'Optionnel – image uniquement (JPEG/PNG/WebP). Les PDF sont refusés.', 'ufsc-licence-competition' ); ?></p>
								</div>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="weighin_start"><?php esc_html_e( 'Pesée – début', 'ufsc-licence-competition' ); ?></label></th>
							<td>
								<input name="weighin_start" id="weighin_start" type="text" class="regular-text" value="<?php echo esc_attr( $weighin_start ); ?>" placeholder="YYYY-MM-DD HH:MM:SS" />
								<p class="description"><?php esc_html_e( 'Optionnel – horaire de début de la pesée (format YYYY-MM-DD HH:MM:SS).', 'ufsc-licence-competition' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="weighin_end"><?php esc_html_e( 'Pesée – fin', 'ufsc-licence-competition' ); ?></label></th>
							<td>
								<input name="weighin_end" id="weighin_end" type="text" class="regular-text" value="<?php echo esc_attr( $weighin_end ); ?>" placeholder="YYYY-MM-DD HH:MM:SS" />
								<p class="description"><?php esc_html_e( 'Optionnel – horaire de fin de la pesée (format YYYY-MM-DD HH:MM:SS).', 'ufsc-licence-competition' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="briefing_time"><?php esc_html_e( 'Briefing', 'ufsc-licence-competition' ); ?></label></th>
							<td>
								<input name="briefing_time" id="briefing_time" type="text" class="regular-text" value="<?php echo esc_attr( $briefing_time ); ?>" placeholder="YYYY-MM-DD HH:MM:SS" />
								<p class="description"><?php esc_html_e( 'Optionnel – horaire de briefing (format YYYY-MM-DD HH:MM:SS).', 'ufsc-licence-competition' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="fights_start"><?php esc_html_e( 'Début combats', 'ufsc-licence-competition' ); ?></label></th>
							<td>
								<input name="fights_start" id="fights_start" type="text" class="regular-text" value="<?php echo esc_attr( $fights_start ); ?>" placeholder="YYYY-MM-DD HH:MM:SS" />
								<p class="description"><?php esc_html_e( 'Optionnel – horaire de début des combats (format YYYY-MM-DD HH:MM:SS).', 'ufsc-licence-competition' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="event_end_estimated"><?php esc_html_e( 'Fin prévisionnelle', 'ufsc-licence-competition' ); ?></label></th>
							<td>
								<input name="event_end_estimated" id="event_end_estimated" type="text" class="regular-text" value="<?php echo esc_attr( $event_end_estimated ); ?>" placeholder="YYYY-MM-DD HH:MM:SS" />
								<p class="description"><?php esc_html_e( 'Optionnel – fin prévisionnelle de l’événement (format YYYY-MM-DD HH:MM:SS).', 'ufsc-licence-competition' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="registration_deadline"><?php esc_html_e( 'Date limite d’inscription', 'ufsc-licence-competition' ); ?></label></th>
							<td>
								<input name="registration_deadline" id="registration_deadline" type="text" class="regular-text" value="<?php echo esc_attr( $registration_deadline ); ?>" placeholder="YYYY-MM-DD HH:MM:SS" />
								<p class="description"><?php esc_html_e( 'Optionnel – date/heure limite des inscriptions (format YYYY-MM-DD HH:MM:SS).', 'ufsc-licence-competition' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="organizer_contact_name"><?php esc_html_e( 'Contact organisateur (nom)', 'ufsc-licence-competition' ); ?></label></th>
							<td>
								<input name="organizer_contact_name" id="organizer_contact_name" type="text" class="regular-text" value="<?php echo esc_attr( $organizer_contact_name ); ?>" />
								<p class="description"><?php esc_html_e( 'Optionnel – affiché aux clubs sur la page détail.', 'ufsc-licence-competition' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="organizer_phone"><?php esc_html_e( 'Contact organisateur (téléphone)', 'ufsc-licence-competition' ); ?></label></th>
							<td>
								<input name="organizer_phone" id="organizer_phone" type="text" class="regular-text" value="<?php echo esc_attr( $organizer_phone ); ?>" />
								<p class="description"><?php esc_html_e( 'Optionnel – affiché aux clubs sur la page détail.', 'ufsc-licence-competition' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="organizer_email"><?php esc_html_e( 'Contact organisateur (email)', 'ufsc-licence-competition' ); ?></label></th>
							<td>
								<input name="organizer_email" id="organizer_email" type="email" class="regular-text" value="<?php echo esc_attr( $organizer_email ); ?>" />
								<p class="description"><?php esc_html_e( 'Optionnel – affiché aux clubs sur la page détail.', 'ufsc-licence-competition' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="club_notes"><?php esc_html_e( 'Notes clubs', 'ufsc-licence-competition' ); ?></label></th>
							<td>
								<textarea name="club_notes" id="club_notes" class="large-text" rows="4"><?php echo esc_textarea( $club_notes ); ?></textarea>
								<p class="description"><?php esc_html_e( 'Optionnel – message affiché sur la page détail.', 'ufsc-licence-competition' ); ?></p>
							</td>
						</tr>
					</tbody>
				</table>

				<h2><?php esc_html_e( 'Accès & éligibilité', 'ufsc-licence-competition' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Définissez qui peut voir la compétition et qui peut s’inscrire. Les administrateurs UFSC conservent toujours l’accès total.', 'ufsc-licence-competition' ); ?>
				</p>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="access_mode"><?php esc_html_e( 'Mode d’accès clubs', 'ufsc-licence-competition' ); ?></label></th>
							<td>
								<select name="access_mode" id="access_mode">
									<option value="affiliated" <?php selected( $access_mode, 'affiliated' ); ?>>
										<?php esc_html_e( 'Tous les clubs affiliés', 'ufsc-licence-competition' ); ?>
									</option>
									<?php if ( $show_all_clubs_mode ) : ?>
										<option value="all_clubs" <?php selected( $access_mode, 'all_clubs' ); ?>>
											<?php esc_html_e( 'Tous les clubs (même non affiliés)', 'ufsc-licence-competition' ); ?>
										</option>
									<?php endif; ?>
									<option value="clubs" <?php selected( $access_mode, 'clubs' ); ?>>
										<?php esc_html_e( 'Seulement certains clubs', 'ufsc-licence-competition' ); ?>
									</option>
									<option value="regions" <?php selected( $access_mode, 'regions' ); ?>>
										<?php esc_html_e( 'Par région', 'ufsc-licence-competition' ); ?>
									</option>
									<option value="disciplines" <?php selected( $access_mode, 'disciplines' ); ?>>
										<?php esc_html_e( 'Par discipline', 'ufsc-licence-competition' ); ?>
									</option>
									<option value="region_discipline" <?php selected( $access_mode, 'region_discipline' ); ?>>
										<?php esc_html_e( 'Par région + discipline (AND)', 'ufsc-licence-competition' ); ?>
									</option>
								</select>
								<p class="description"><?php esc_html_e( 'Appliqué aux inscriptions et à la lecture lorsque l’accès public n’est pas activé.', 'ufsc-licence-competition' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Régions autorisées', 'ufsc-licence-competition' ); ?></th>
							<td>
								<select name="allowed_regions[]" multiple="multiple" class="regular-text" size="6">
									<?php foreach ( $regions_for_select as $region_key => $region_label ) : ?>
										<?php $selected = in_array( $region_key, $allowed_region_keys, true ); ?>
										<option value="<?php echo esc_attr( $region_label ); ?>" <?php selected( $selected ); ?>>
											<?php echo esc_html( $region_label ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'Utilisé lorsque le mode d’accès est basé sur les régions.', 'ufsc-licence-competition' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Disciplines autorisées', 'ufsc-licence-competition' ); ?></th>
							<td>
								<select name="allowed_disciplines[]" multiple="multiple" class="regular-text" size="6">
									<?php foreach ( $disciplines as $discipline_key => $discipline_label ) : ?>
										<option value="<?php echo esc_attr( $discipline_key ); ?>" <?php selected( in_array( $discipline_key, $allowed_disciplines, true ) ); ?>>
											<?php echo esc_html( $discipline_label ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'Utilisé lorsque le mode d’accès est basé sur les disciplines.', 'ufsc-licence-competition' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Clubs autorisés', 'ufsc-licence-competition' ); ?></th>
							<td>
								<select name="allowed_club_ids[]" multiple="multiple" class="regular-text" size="8">
									<?php foreach ( $club_select as $club_id => $club_label ) : ?>
										<option value="<?php echo esc_attr( (int) $club_id ); ?>" <?php selected( in_array( (int) $club_id, $allowed_club_ids, true ) ); ?>>
											<?php echo esc_html( $club_label ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'Utilisé lorsque le mode d’accès est “certains clubs”.', 'ufsc-licence-competition' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Options lecture', 'ufsc-licence-competition' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="public_read" value="1" <?php checked( $public_read ); ?> />
									<?php esc_html_e( 'Accès public (lecture seule)', 'ufsc-licence-competition' ); ?>
								</label>
								<p class="description"><?php esc_html_e( 'Tout le monde peut voir le détail, mais les inscriptions suivent les règles ci-dessus.', 'ufsc-licence-competition' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Conditions inscriptions', 'ufsc-licence-competition' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="require_logged_in_club" value="1" <?php checked( $require_logged_in_club ); ?> />
									<?php esc_html_e( 'Exiger un club connecté', 'ufsc-licence-competition' ); ?>
								</label><br />
								<label>
									<input type="checkbox" name="require_affiliated" value="1" <?php checked( $require_affiliated ); ?> />
									<?php esc_html_e( 'Exiger un club affilié', 'ufsc-licence-competition' ); ?>
								</label><br />
								<label>
									<input type="checkbox" name="require_valid_license" value="1" <?php checked( $require_valid_license ); ?> />
									<?php esc_html_e( 'Exiger une licence valide', 'ufsc-licence-competition' ); ?>
								</label>
								<p class="description"><?php esc_html_e( 'Appliqué avant la création ou la validation d’une inscription.', 'ufsc-licence-competition' ); ?></p>
							</td>
						</tr>
					</tbody>
				</table>

				<h2><?php esc_html_e( 'Aperçu accès', 'ufsc-licence-competition' ); ?></h2>
				<?php if ( $clubs_mode_empty_warning ) : ?>
					<div class="notice notice-warning inline">
						<p><?php esc_html_e( 'Mode “clubs sélectionnés” sans club : accès refusé pour tous (hors administrateurs).', 'ufsc-licence-competition' ); ?></p>
					</div>
				<?php endif; ?>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><?php esc_html_e( 'Mode', 'ufsc-licence-competition' ); ?></th>
							<td><?php echo esc_html( $preview_mode_label ); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Nombre de régions sélectionnées', 'ufsc-licence-competition' ); ?></th>
							<td><?php echo esc_html( (string) $preview_regions_count ); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Nombre de clubs sélectionnés', 'ufsc-licence-competition' ); ?></th>
							<td><?php echo esc_html( (string) $preview_clubs_count ); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Condition affiliation', 'ufsc-licence-competition' ); ?></th>
							<td><?php echo esc_html( $preview_affiliation_label ); ?></td>
						</tr>
					</tbody>
				</table>

				<?php submit_button( $is_edit ? __( 'Mettre à jour', 'ufsc-licence-competition' ) : __( 'Créer', 'ufsc-licence-competition' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Bulk actions: admin-post handler with nonce + capability.
	 */
	public function handle_bulk_actions() {
		$cap = \UFSC_LC_Capabilities::get_manage_capability();
		if ( ! current_user_can( $cap ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		if ( empty( $_REQUEST['_wpnonce'] ) ) {
			$this->redirect_notice( 'invalid' );
		}

		$nonce_action = 'bulk-ufsc-competitions';
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ), $nonce_action ) ) {
			$this->redirect_notice( 'invalid' );
		}

		$action = '';
		if ( isset( $_REQUEST['ufsc_bulk_action'] ) && '-1' !== $_REQUEST['ufsc_bulk_action'] ) {
			$action = sanitize_key( wp_unslash( $_REQUEST['ufsc_bulk_action'] ) );
		}
		if ( isset( $_REQUEST['ufsc_bulk_action2'] ) && '-1' !== $_REQUEST['ufsc_bulk_action2'] ) {
			$action = sanitize_key( wp_unslash( $_REQUEST['ufsc_bulk_action2'] ) );
		}
		if ( empty( $action ) ) {
			$this->redirect_notice( 'invalid' );
		}

		$ids = array();
		if ( ! empty( $_REQUEST['ids'] ) && is_array( $_REQUEST['ids'] ) ) {
			$ids = array_map( 'absint', wp_unslash( $_REQUEST['ids'] ) );
		} elseif ( ! empty( $_REQUEST['id'] ) ) {
			$ids = array( absint( wp_unslash( $_REQUEST['id'] ) ) );
		}
		$ids = array_filter( $ids );

		if ( empty( $ids ) || ! $this->repository ) {
			$this->redirect_notice( 'invalid' );
		}

		foreach ( $ids as $id ) {
			if ( 'trash' === $action && method_exists( $this->repository, 'trash' ) ) {
				$this->repository->trash( $id );
			} elseif ( 'restore' === $action && method_exists( $this->repository, 'restore' ) ) {
				$this->repository->restore( $id );
			} elseif ( 'delete' === $action && method_exists( $this->repository, 'delete' ) ) {
				$this->repository->delete( $id );
			} elseif ( 'archive' === $action && method_exists( $this->repository, 'archive' ) ) {
				$this->repository->archive( $id );
			} elseif ( 'unarchive' === $action && method_exists( $this->repository, 'unarchive' ) ) {
				$this->repository->unarchive( $id );
			}
		}

		$redirect_args = array( 'page' => Menu::MENU_SLUG, 'ufsc_notice' => 'saved' );
		if ( isset( $_REQUEST['ufsc_view'] ) ) {
			$redirect_args['ufsc_view'] = sanitize_key( wp_unslash( $_REQUEST['ufsc_view'] ) );
		}
		if ( isset( $_REQUEST['s'] ) ) {
			$redirect_args['s'] = sanitize_text_field( wp_unslash( $_REQUEST['s'] ) );
		}

		wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * AJAX: get club snapshot.
	 */
	public function ajax_get_club() {
		$cap = \UFSC_LC_Capabilities::get_manage_capability();
		if ( ! current_user_can( $cap ) ) {
			wp_send_json_error( 'forbidden', 403 );
		}

		check_ajax_referer( 'ufsc_get_club', 'nonce' );

		$club_id = isset( $_POST['club_id'] ) ? absint( wp_unslash( $_POST['club_id'] ) ) : 0;
		if ( ! $club_id ) {
			wp_send_json_error( 'missing', 400 );
		}

		if ( ! $this->club_repository || ! method_exists( $this->club_repository, 'get' ) ) {
			wp_send_json_error( 'club_repo_missing', 500 );
		}

		$club = $this->club_repository->get( $club_id );
		if ( ! $club ) {
			wp_send_json_error( 'not_found', 404 );
		}

		wp_send_json_success(
			array(
				'id'     => (int) ( $club->id ?? 0 ),
				'nom'    => sanitize_text_field( $club->nom ?? '' ),
				'region' => sanitize_text_field( $club->region ?? '' ),
			)
		);
	}

	/**
	 * Handlers (admin-post)
	 */
	public function handle_save() {
		$cap = \UFSC_LC_Capabilities::get_manage_capability();
		if ( ! current_user_can( $cap ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'ufsc_competitions_save_competition' );

		if ( ! $this->repository || ! method_exists( $this->repository, 'save' ) ) {
			$this->redirect_notice( 'invalid' );
		}

		$raw_discipline = isset( $_POST['discipline'] ) ? sanitize_text_field( wp_unslash( $_POST['discipline'] ) ) : '';
		$raw_type = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';

		$data = array(
			'id'                  => isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0,
			'name'                => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
			'discipline'           => $this->normalize_discipline_value( $raw_discipline ),
			'type'                => $this->normalize_type_value( $raw_type ),
			'season'              => isset( $_POST['season'] ) ? sanitize_text_field( wp_unslash( $_POST['season'] ) ) : '',
			'status'              => isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '',
			'event_start_datetime' => isset( $_POST['event_start_datetime'] ) ? sanitize_text_field( wp_unslash( $_POST['event_start_datetime'] ) ) : '',
			'event_end_datetime'   => isset( $_POST['event_end_datetime'] ) ? sanitize_text_field( wp_unslash( $_POST['event_end_datetime'] ) ) : '',
		);

		$allowed_regions = isset( $_POST['allowed_regions'] ) ? wp_unslash( $_POST['allowed_regions'] ) : array();
		$allowed_regions_keys = array();
		if ( function_exists( 'ufsc_normalize_region_key' ) ) {
			foreach ( (array) $allowed_regions as $region_label ) {
				$key = ufsc_normalize_region_key( $region_label );
				if ( '' !== $key && ! in_array( $key, $allowed_regions_keys, true ) ) {
					$allowed_regions_keys[] = $key;
				}
			}
		}

		$saved_id = $this->repository->save( $data );
		if ( $saved_id ) {
			CompetitionMeta::save(
				(int) $saved_id,
				array(
					'lieu_name'             => isset( $_POST['lieu_name'] ) ? wp_unslash( $_POST['lieu_name'] ) : '',
					'lieu_address'          => isset( $_POST['lieu_address'] ) ? wp_unslash( $_POST['lieu_address'] ) : '',
					'photo_evenement_id'    => isset( $_POST['photo_evenement_id'] ) ? absint( wp_unslash( $_POST['photo_evenement_id'] ) ) : 0,
					'weighin_start'         => isset( $_POST['weighin_start'] ) ? wp_unslash( $_POST['weighin_start'] ) : '',
					'weighin_end'           => isset( $_POST['weighin_end'] ) ? wp_unslash( $_POST['weighin_end'] ) : '',
					'briefing_time'         => isset( $_POST['briefing_time'] ) ? wp_unslash( $_POST['briefing_time'] ) : '',
					'fights_start'          => isset( $_POST['fights_start'] ) ? wp_unslash( $_POST['fights_start'] ) : '',
					'event_end_estimated'   => isset( $_POST['event_end_estimated'] ) ? wp_unslash( $_POST['event_end_estimated'] ) : '',
					'registration_deadline' => isset( $_POST['registration_deadline'] ) ? wp_unslash( $_POST['registration_deadline'] ) : '',
					'organizer_contact_name' => isset( $_POST['organizer_contact_name'] ) ? wp_unslash( $_POST['organizer_contact_name'] ) : '',
					'organizer_phone'       => isset( $_POST['organizer_phone'] ) ? wp_unslash( $_POST['organizer_phone'] ) : '',
					'organizer_email'       => isset( $_POST['organizer_email'] ) ? wp_unslash( $_POST['organizer_email'] ) : '',
					'club_notes'            => isset( $_POST['club_notes'] ) ? wp_unslash( $_POST['club_notes'] ) : '',
					'access_mode'           => isset( $_POST['access_mode'] ) ? wp_unslash( $_POST['access_mode'] ) : '',
					'allowed_regions'       => $allowed_regions,
					'allowed_regions_keys'  => $allowed_regions_keys,
					'allowed_disciplines'   => isset( $_POST['allowed_disciplines'] ) ? wp_unslash( $_POST['allowed_disciplines'] ) : array(),
					'allowed_club_ids'      => isset( $_POST['allowed_club_ids'] ) ? wp_unslash( $_POST['allowed_club_ids'] ) : array(),
					'public_read'           => isset( $_POST['public_read'] ),
					'require_affiliated'    => isset( $_POST['require_affiliated'] ),
					'require_logged_in_club' => isset( $_POST['require_logged_in_club'] ),
					'require_valid_license' => isset( $_POST['require_valid_license'] ),
				)
			);
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => Menu::MENU_SLUG,
					'ufsc_notice' => $saved_id ? 'saved' : 'invalid',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function handle_trash() {
		$cap = \UFSC_LC_Capabilities::get_manage_capability();
		if ( ! current_user_can( $cap ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		check_admin_referer( 'ufsc_competitions_trash_competition_' . $id );

		if ( $id && $this->repository && method_exists( $this->repository, 'trash' ) ) {
			$this->repository->trash( $id );
		}

		$this->redirect_notice( 'saved' );
	}

	public function handle_restore() {
		$cap = \UFSC_LC_Capabilities::get_manage_capability();
		if ( ! current_user_can( $cap ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		check_admin_referer( 'ufsc_competitions_restore_competition_' . $id );

		if ( $id && $this->repository && method_exists( $this->repository, 'restore' ) ) {
			$this->repository->restore( $id );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => Menu::MENU_SLUG,
					'ufsc_view'   => 'trash',
					'ufsc_notice' => 'saved',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function handle_delete() {
		$cap = \UFSC_LC_Capabilities::get_manage_capability();
		if ( ! current_user_can( $cap ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		check_admin_referer( 'ufsc_competitions_delete_competition_' . $id );

		if ( $id && $this->repository && method_exists( $this->repository, 'delete' ) ) {
			$this->repository->delete( $id );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => Menu::MENU_SLUG,
					'ufsc_view'   => 'trash',
					'ufsc_notice' => 'saved',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	private function normalize_discipline_value( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}

		if ( class_exists( DisciplineRegistry::class ) ) {
			$normalized = DisciplineRegistry::normalize( $value );
			$choices = DisciplineRegistry::get_disciplines();
			if ( $normalized && isset( $choices[ $normalized ] ) ) {
				return $normalized;
			}
		}

		return $value;
	}

	private function normalize_type_value( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}

		$normalized = CompetitionFilters::normalize_type_key( $value );
		$choices = CompetitionFilters::get_type_choices();
		if ( $normalized && isset( $choices[ $normalized ] ) ) {
			return $normalized;
		}

		return $value;
	}

	public function handle_archive() {
		$cap = \UFSC_LC_Capabilities::get_manage_capability();
		if ( ! current_user_can( $cap ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		check_admin_referer( 'ufsc_competitions_archive_competition_' . $id );

		if ( $id && $this->repository && method_exists( $this->repository, 'archive' ) ) {
			$this->repository->archive( $id );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => Menu::MENU_SLUG,
					'ufsc_view'   => 'archived',
					'ufsc_notice' => 'saved',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function handle_unarchive() {
		$cap = \UFSC_LC_Capabilities::get_manage_capability();
		if ( ! current_user_can( $cap ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		check_admin_referer( 'ufsc_competitions_unarchive_competition_' . $id );

		if ( $id && $this->repository && method_exists( $this->repository, 'unarchive' ) ) {
			$this->repository->unarchive( $id );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => Menu::MENU_SLUG,
					'ufsc_view'   => 'all',
					'ufsc_notice' => 'saved',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	private function render_notice( $notice ) {
		if ( ! $notice ) {
			return;
		}

		$map = array(
			'saved'     => array( 'success', __( 'Sauvegardé.', 'ufsc-licence-competition' ) ),
			'invalid'   => array( 'error', __( 'Données invalides.', 'ufsc-licence-competition' ) ),
			'not_found' => array( 'error', __( 'Élément introuvable.', 'ufsc-licence-competition' ) ),
		);

		if ( ! isset( $map[ $notice ] ) ) {
			return;
		}

		$type = $map[ $notice ][0];
		$msg  = $map[ $notice ][1];

		echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
	}

	private function redirect_notice( $notice ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => Menu::MENU_SLUG,
					'ufsc_notice' => sanitize_key( $notice ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
