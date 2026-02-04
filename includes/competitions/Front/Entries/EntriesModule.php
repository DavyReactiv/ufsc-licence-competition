<?php

namespace UFSC\Competitions\Front\Entries;

use UFSC\Competitions\Access\CompetitionAccess;
use UFSC\Competitions\Front\Access\ClubAccess;
use UFSC\Competitions\Front\Front;
use UFSC\Competitions\Front\Repositories\CompetitionReadRepository;
use UFSC\Competitions\Front\Repositories\EntryFrontRepository;
use UFSC\Competitions\Repositories\ClubRepository;
use UFSC\Competitions\Repositories\CategoryRepository;
use UFSC\Competitions\Services\CategoryAssigner;
use UFSC\Competitions\Services\WeightCategoryResolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EntriesModule {
	public static function register(): void {
		add_action( 'ufsc_competitions_front_registration_box', array( __CLASS__, 'render_notice' ), 5, 1 );
		add_action( 'ufsc_competitions_front_registration_box', array( __CLASS__, 'render' ), 10, 1 );

		self::register_actions();
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	public static function register_actions(): void {
		static $registered = false;
		if ( $registered ) {
			return;
		}
		$registered = true;

		add_action( 'admin_post_ufsc_competitions_entry_create', array( EntryActions::class, 'handle_create' ) );
		add_action( 'admin_post_ufsc_competitions_entry_update', array( EntryActions::class, 'handle_update' ) );
		add_action( 'admin_post_ufsc_competitions_entry_delete', array( EntryActions::class, 'handle_delete' ) );
		add_action( 'admin_post_ufsc_entry_submit', array( EntryActions::class, 'handle_submit' ) );
		add_action( 'admin_post_ufsc_entry_withdraw', array( EntryActions::class, 'handle_withdraw' ) );
		add_action( 'admin_post_ufsc_entry_cancel', array( EntryActions::class, 'handle_cancel' ) );
		add_action( 'admin_post_ufsc_entry_admin_validate', array( EntryActions::class, 'handle_admin_validate' ) );
		add_action( 'admin_post_ufsc_entry_admin_reject', array( EntryActions::class, 'handle_admin_reject' ) );
		add_action( 'admin_post_ufsc_entry_admin_reopen', array( EntryActions::class, 'handle_admin_reopen' ) );

		add_action( 'admin_post_nopriv_ufsc_competitions_entry_create', array( EntryActions::class, 'handle_not_logged_in' ) );
		add_action( 'admin_post_nopriv_ufsc_competitions_entry_update', array( EntryActions::class, 'handle_not_logged_in' ) );
		add_action( 'admin_post_nopriv_ufsc_competitions_entry_delete', array( EntryActions::class, 'handle_not_logged_in' ) );
		add_action( 'admin_post_nopriv_ufsc_entry_submit', array( EntryActions::class, 'handle_not_logged_in' ) );
		add_action( 'admin_post_nopriv_ufsc_entry_withdraw', array( EntryActions::class, 'handle_not_logged_in' ) );
		add_action( 'admin_post_nopriv_ufsc_entry_cancel', array( EntryActions::class, 'handle_not_logged_in' ) );

		add_action( 'wp_ajax_ufsc_competitions_compute_category', array( __CLASS__, 'handle_compute_category' ) );
		add_action( 'wp_ajax_ufsc_competitions_license_search', array( __CLASS__, 'handle_license_search' ) );
	}

	public static function render( $competition ): void {
		if ( ! $competition ) {
			return;
		}

		$access = new CompetitionAccess();
		$user_id = is_user_logged_in() ? (int) get_current_user_id() : 0;
		$register_result = $access->can_register( (int) ( $competition->id ?? 0 ), 0, $user_id );
		if ( ! $register_result->allowed ) {
			if ( function_exists( 'ufsc_render_access_denied_notice' ) ) {
				echo wp_kses_post( ufsc_render_access_denied_notice( $register_result ) );
			} else {
				echo wp_kses_post( '<p>' . esc_html( $access->get_denied_message( $register_result ) ) . '</p>' );
			}
			return;
		}

		$club_id = (int) ( $register_result->context['club_id'] ?? 0 );

		$entries = array();
		$editing_entry = null;
		$license_results = array();
		$selected_license = null;
		$club_label = '';
		$license_term = isset( $_GET['ufsc_license_term'] ) ? sanitize_text_field( wp_unslash( $_GET['ufsc_license_term'] ) ) : '';
		$license_number = isset( $_GET['ufsc_license_number'] ) ? sanitize_text_field( wp_unslash( $_GET['ufsc_license_number'] ) ) : '';
		$license_birthdate = isset( $_GET['ufsc_license_birthdate'] ) ? sanitize_text_field( wp_unslash( $_GET['ufsc_license_birthdate'] ) ) : '';
		$license_birthdate = self::normalize_birthdate_input( $license_birthdate );
		$license_id = isset( $_GET['ufsc_license_id'] ) ? absint( $_GET['ufsc_license_id'] ) : 0;
		$repo = null;

		if ( $club_id ) {
			$repo = new EntryFrontRepository();
			$entries = $repo->list_by_competition_and_club( (int) $competition->id, (int) $club_id );
			$editing_entry = self::resolve_editing_entry( $entries );

			$club_repo = new ClubRepository();
			$club = $club_repo->get( $club_id );
			$club_label = $club_repo->get_region_label( $club );

			$license_results = self::get_license_search_results( $license_term, $license_number, $license_birthdate, $club_id, $repo );
			if ( ! $license_id && 1 === count( $license_results ) ) {
				$license_id = (int) ( $license_results[0]['id'] ?? 0 );
			}
			if ( $license_id ) {
				$license_data = apply_filters( 'ufsc_competitions_front_license_by_id', null, $license_id, $club_id );
				if ( is_array( $license_data ) ) {
					$selected_license = $repo->normalize_license_result( $license_data );
				}
			}
		}

		$registration_open = self::is_registration_open( $competition, (int) $club_id );

		$prefill = array();
		if ( $selected_license && ! $editing_entry ) {
			$prefill = array(
				'first_name' => $selected_license['first_name'] ?? '',
				'last_name' => $selected_license['last_name'] ?? '',
				'birth_date' => $selected_license['birthdate'] ?? '',
				'sex' => $selected_license['sex'] ?? '',
				'license_number' => $selected_license['license_number'] ?? '',
			);
		}

		if ( ! $editing_entry && ! empty( $prefill['birth_date'] ) && empty( $prefill['category'] ) ) {
			$prefill['category'] = self::get_category_from_birthdate( $prefill['birth_date'], $prefill, $competition );
		}

		echo EntryFormRenderer::render(
			array(
				'competition' => $competition,
				'club_id' => $club_id,
				'entries' => $entries,
				'editing_entry' => $editing_entry,
				'club_label' => $club_label,
				'registration_open' => $registration_open,
				'license_results' => $license_results,
				'selected_license' => $selected_license,
				'license_term' => $license_term,
				'license_number' => $license_number,
				'license_birthdate' => $license_birthdate,
				'return_url' => self::get_return_url( (int) ( $competition->id ?? 0 ) ),
				'license_id' => $license_id,
				'prefill' => $prefill,
				'entry_repo' => $repo,
			)
		);
	}

	public static function enqueue_assets(): void {
		$competition_id = Front::get_competition_id_from_request();
		if ( ! $competition_id ) {
			return;
		}

		$competition = self::get_competition( (int) $competition_id );
		if ( ! $competition ) {
			return;
		}

		$handle = 'ufsc-competitions-front-entries';
		$asset_path = UFSC_LC_DIR . 'includes/competitions/assets/front-entries.js';
		$asset_url = UFSC_LC_URL . 'includes/competitions/assets/front-entries.js';
		$version = file_exists( $asset_path ) ? (string) filemtime( $asset_path ) : '1.0.0';

		wp_enqueue_script( $handle, $asset_url, array(), $version, true );
		wp_localize_script(
			$handle,
			'ufscCompetitionsFront',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'ufsc_competitions_compute_category' ),
				'licenseSearchNonce' => wp_create_nonce( 'ufsc_competitions_license_search' ),
				'competitionId' => (int) $competition_id,
				'discipline' => sanitize_text_field( (string) ( $competition->discipline ?? '' ) ),
				'labels' => array(
					'loading' => __( 'Calcul en cours…', 'ufsc-licence-competition' ),
					'missing' => __( 'Veuillez renseigner la date de naissance.', 'ufsc-licence-competition' ),
					'error' => __( 'Catégorie indisponible.', 'ufsc-licence-competition' ),
					'weightMissing' => __( 'Poids manquant.', 'ufsc-licence-competition' ),
					'searching' => __( 'Recherche en cours…', 'ufsc-licence-competition' ),
					'searchEmpty' => __( 'Veuillez renseigner un nom ou un numéro de licence.', 'ufsc-licence-competition' ),
					'searchNoResult' => __( 'Aucun licencié trouvé.', 'ufsc-licence-competition' ),
					'searchMultiple' => __( 'Plusieurs licenciés trouvés. Sélectionnez la bonne personne.', 'ufsc-licence-competition' ),
					'searchError' => __( 'Recherche indisponible.', 'ufsc-licence-competition' ),
					'searchOne' => __( 'Licencié trouvé et pré-rempli.', 'ufsc-licence-competition' ),
				),
			)
		);
	}

	public static function render_notice( $competition ): void {
		if ( ! $competition ) {
			return;
		}

		$notice = isset( $_GET['ufsc_notice'] ) ? sanitize_key( wp_unslash( $_GET['ufsc_notice'] ) ) : '';
		if ( '' === $notice ) {
			return;
		}

		$notice_html = EntryFormRenderer::render_notice( $notice );
		if ( '' === $notice_html ) {
			return;
		}

		echo wp_kses_post( $notice_html );
	}

	public static function get_fields_schema( $competition ): array {
		$category_options = self::get_category_options( $competition );
		$schema = array(
			array(
				'name' => 'first_name',
				'label' => __( 'Prénom', 'ufsc-licence-competition' ),
				'type' => 'text',
				'required' => true,
				'columns' => array( 'first_name', 'firstname', 'prenom' ),
			),
			array(
				'name' => 'last_name',
				'label' => __( 'Nom', 'ufsc-licence-competition' ),
				'type' => 'text',
				'required' => true,
				'columns' => array( 'last_name', 'lastname', 'nom' ),
			),
			array(
				'name' => 'license_number',
				'label' => __( 'N° licence', 'ufsc-licence-competition' ),
				'type' => 'text',
				'required' => false,
				'readonly' => true,
				'columns' => array( 'license_number', 'licence_number', 'licensee_number', 'licence', 'license' ),
			),
			array(
				'name' => 'birth_date',
				'label' => __( 'Date de naissance', 'ufsc-licence-competition' ),
				'type' => 'date',
				'required' => true,
				'placeholder' => 'YYYY-MM-DD',
				'columns' => array( 'birth_date', 'birthdate', 'date_of_birth', 'dob' ),
			),
			array(
				'name' => 'sex',
				'label' => __( 'Sexe', 'ufsc-licence-competition' ),
				'type' => 'select',
				'required' => false,
				'options' => array(
					'm' => __( 'Homme', 'ufsc-licence-competition' ),
					'f' => __( 'Femme', 'ufsc-licence-competition' ),
					'x' => __( 'Autre', 'ufsc-licence-competition' ),
				),
				'columns' => array( 'sex', 'gender' ),
			),
			array(
				'name' => 'weight',
				'label' => __( 'Poids', 'ufsc-licence-competition' ),
				'type' => 'number',
				'required' => false,
				'placeholder' => __( 'ex: 67.5', 'ufsc-licence-competition' ),
				'columns' => array( 'weight', 'weight_kg', 'poids' ),
			),
			array(
				'name' => 'weight_class',
				'label' => __( 'Catégorie de poids', 'ufsc-licence-competition' ),
				'type' => 'select',
				'required' => false,
				'options' => array(),
				'columns' => array( 'weight_class', 'weight_cat', 'weight_category' ),
			),
			array(
				'name' => 'category',
				'label' => __( 'Catégorie', 'ufsc-licence-competition' ),
				'type' => $category_options ? 'select' : 'text',
				'required' => false,
				'options' => $category_options,
				'columns' => array( 'category', 'category_name' ),
			),
			array(
				'name' => 'level',
				'label' => __( 'Niveau / Classe', 'ufsc-licence-competition' ),
				'type' => 'text',
				'required' => false,
				'columns' => array( 'level', 'class', 'classe' ),
			),
		);

		return apply_filters( 'ufsc_competitions_entry_fields_schema', $schema, $competition );
	}

	public static function get_competition( int $competition_id ) {
		$repo = new CompetitionReadRepository();
		return $repo->get( $competition_id );
	}

	public static function is_registration_open( $competition, int $club_id = 0 ): bool {
		$is_open = (bool) $competition;

		if ( ! $competition || empty( $competition->status ) || 'open' !== (string) $competition->status ) {
			$is_open = false;
		}

		if ( $is_open && ! empty( $competition->registration_deadline ) ) {
			$deadline_raw = sanitize_text_field( (string) $competition->registration_deadline );
			$timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( wp_timezone_string() ?: 'UTC' );
			$deadline = null;

			if ( preg_match( '/^\\d{4}-\\d{2}-\\d{2}$/', $deadline_raw ) ) {
				$deadline = date_create_from_format( 'Y-m-d H:i:s', $deadline_raw . ' 23:59:59', $timezone );
			} elseif ( preg_match( '/^\\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}:\\d{2}$/', $deadline_raw ) ) {
				$deadline = date_create_from_format( 'Y-m-d H:i:s', $deadline_raw, $timezone );
			}
			if ( $deadline instanceof \DateTimeInterface ) {
				$deadline_ts = $deadline->getTimestamp();
				if ( current_time( 'timestamp' ) > $deadline_ts ) {
					$is_open = false;
				}
			}
		}

		return (bool) apply_filters( 'ufsc_competitions_front_registration_is_open', $is_open, $competition, $club_id );
	}

	private static function get_license_search_results( string $term, string $license_number, string $birthdate, int $club_id, EntryFrontRepository $repo ): array {
		$results = apply_filters( 'ufsc_competitions_front_license_search_results', array(), $term, $club_id, $license_number, $birthdate );
		if ( ! is_array( $results ) ) {
			$results = array();
		}

		return $repo->normalize_license_results( $results, 20 );
	}

	private static function normalize_birthdate_input( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}

		if ( preg_match( '/^\\d{2}\\/\\d{2}\\/\\d{4}$/', $value ) ) {
			$parts = explode( '/', $value );
			if ( 3 === count( $parts ) ) {
				$value = sprintf( '%04d-%02d-%02d', (int) $parts[2], (int) $parts[1], (int) $parts[0] );
			}
		}

		if ( preg_match( '/^\\d{4}-\\d{2}-\\d{2}$/', $value ) ) {
			return $value;
		}

		$date = date_create( $value );
		if ( $date ) {
			return $date->format( 'Y-m-d' );
		}

		return '';
	}

	public static function get_category_from_birthdate( string $birthdate, array $license, $competition ): string {
		$category = '';

		if ( class_exists( CategoryAssigner::class ) ) {
			$repo = new CategoryRepository();
			$categories = $repo->list(
				array( 'competition_id' => (int) ( $competition->id ?? 0 ) ),
				200,
				0
			);

			if ( is_array( $categories ) && ! empty( $categories ) ) {
				$normalized = array();
				foreach ( $categories as $category_row ) {
					$normalized[] = array(
						'id' => (int) ( $category_row->id ?? 0 ),
						'name' => sanitize_text_field( $category_row->name ?? '' ),
						'age_min' => isset( $category_row->age_min ) ? (int) $category_row->age_min : null,
						'age_max' => isset( $category_row->age_max ) ? (int) $category_row->age_max : null,
						'weight_min' => isset( $category_row->weight_min ) ? (float) $category_row->weight_min : null,
						'weight_max' => isset( $category_row->weight_max ) ? (float) $category_row->weight_max : null,
						'sex' => sanitize_text_field( $category_row->sex ?? '' ),
						'level' => sanitize_text_field( $category_row->level ?? '' ),
					);
				}

				$assigner = new CategoryAssigner();
				$match = $assigner->match_category(
					$normalized,
					array(
						'birth_date' => $birthdate,
						'sex' => $license['sex'] ?? '',
						'weight' => $license['weight'] ?? null,
						'level' => $license['level'] ?? '',
					),
					array(
						'age_reference' => sanitize_text_field( $competition->age_reference ?? '12-31' ),
					)
				);

				if ( $match && ! empty( $match['name'] ) ) {
					$category = sanitize_text_field( $match['name'] );
				}
			}
		}

		if ( '' === $category ) {
			$category = self::resolve_fallback_age_category( $birthdate, $competition );
		}

		return (string) apply_filters( 'ufsc_competitions_front_category_from_birthdate', $category, $birthdate, $competition );
	}

	public static function handle_compute_category(): void {
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'Accès refusé.', 'ufsc-licence-competition' ) ), 403 );
		}

		check_ajax_referer( 'ufsc_competitions_compute_category', 'nonce' );

		$competition_id = isset( $_POST['competition_id'] ) ? absint( $_POST['competition_id'] ) : 0;
		$birth_date = isset( $_POST['birth_date'] ) ? sanitize_text_field( wp_unslash( $_POST['birth_date'] ) ) : '';
		$weight = isset( $_POST['weight'] ) ? sanitize_text_field( wp_unslash( $_POST['weight'] ) ) : '';
		$sex = isset( $_POST['sex'] ) ? sanitize_key( wp_unslash( $_POST['sex'] ) ) : '';
		$level = isset( $_POST['level'] ) ? sanitize_text_field( wp_unslash( $_POST['level'] ) ) : '';

		$competition = self::get_competition( $competition_id );
		if ( ! $competition ) {
			wp_send_json_error( array( 'message' => __( 'Compétition introuvable.', 'ufsc-licence-competition' ) ), 404 );
		}

		$club_access = new ClubAccess();
		$club_id = $club_access->get_club_id_for_user( get_current_user_id() );
		if ( ! $club_id ) {
			wp_send_json_error( array( 'message' => __( 'Accès refusé.', 'ufsc-licence-competition' ) ), 403 );
		}

		$weight_value = '' !== $weight ? (float) str_replace( ',', '.', $weight ) : null;

		if ( '' === $birth_date || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $birth_date ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Veuillez renseigner la date de naissance.', 'ufsc-licence-competition' ),
					'errors' => array( 'birth_date' ),
				),
				422
			);
		}

		$category = self::get_category_from_birthdate(
			$birth_date,
			array(
				'sex' => $sex,
				'weight' => $weight_value,
				'level' => $level,
			),
			$competition
		);

		$weight_context = array(
			'discipline' => sanitize_key( (string) ( $competition->discipline ?? '' ) ),
			'age_reference' => sanitize_text_field( (string) ( $competition->age_reference ?? '12-31' ) ),
			'season_end_year' => isset( $competition->season ) ? (int) $competition->season : 0,
		);
		$weight_result = WeightCategoryResolver::resolve_with_details(
			$birth_date,
			$sex,
			$weight_value,
			$weight_context
		);
		$weight_classes = WeightCategoryResolver::get_weight_classes( $birth_date, $sex, $weight_context );

		wp_send_json_success(
			array(
				'category_age' => $category,
				'age_cat' => $category,
				'weight_cat' => $category,
				'label' => $category,
				'suggested_weight_class' => $weight_result['label'] ?? '',
				'weight_class' => $weight_result['label'] ?? '',
				'weight_classes' => $weight_classes,
				'weight_message' => $weight_result['message'] ?? '',
				'weight_status' => $weight_result['status'] ?? '',
			)
		);
	}

	public static function handle_license_search(): void {
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'Accès refusé.', 'ufsc-licence-competition' ) ), 403 );
		}

		check_ajax_referer( 'ufsc_competitions_license_search', 'nonce' );

		$term = isset( $_POST['term'] ) ? sanitize_text_field( wp_unslash( $_POST['term'] ) ) : '';
		$license_number = isset( $_POST['license_number'] ) ? sanitize_text_field( wp_unslash( $_POST['license_number'] ) ) : '';
		$birth_date = isset( $_POST['birth_date'] ) ? sanitize_text_field( wp_unslash( $_POST['birth_date'] ) ) : '';
		$birth_date = self::normalize_birthdate_input( $birth_date );

		if ( '' === $term && '' === $license_number && '' === $birth_date ) {
			wp_send_json_success(
				array(
					'found' => false,
					'results' => array(),
					'message' => __( 'Veuillez renseigner un nom ou un numéro de licence.', 'ufsc-licence-competition' ),
				)
			);
		}

		$club_access = new ClubAccess();
		$club_id = $club_access->get_club_id_for_user( get_current_user_id() );
		if ( ! $club_id ) {
			wp_send_json_error( array( 'message' => __( 'Accès refusé.', 'ufsc-licence-competition' ) ), 403 );
		}

		$repo = new EntryFrontRepository();
		$results = self::get_license_search_results( $term, $license_number, $birth_date, $club_id, $repo );

		wp_send_json_success(
			array(
				'found' => ! empty( $results ),
				'results' => $results,
				'message' => empty( $results ) ? __( 'Aucun licencié trouvé.', 'ufsc-licence-competition' ) : '',
			)
		);
	}

	private static function get_return_url( int $competition_id ): string {
		if ( $competition_id ) {
			$details_url = Front::get_competition_details_url( $competition_id );
			if ( $details_url ) {
				return $details_url;
			}
		}

		$referer = wp_get_referer();
		if ( $referer ) {
			return $referer;
		}

		return home_url( '/' );
	}

	private static function resolve_editing_entry( array $entries ) {
		$edit_id = isset( $_GET['ufsc_entry_edit'] ) ? absint( $_GET['ufsc_entry_edit'] ) : 0;
		if ( ! $edit_id ) {
			return null;
		}

		foreach ( $entries as $entry ) {
			if ( (int) ( $entry->id ?? 0 ) === $edit_id ) {
				return $entry;
			}
		}

		return null;
	}

	private static function get_category_options( $competition ): array {
		if ( ! $competition ) {
			return array();
		}

		$repo = new CategoryRepository();
		$categories = $repo->list(
			array( 'competition_id' => (int) ( $competition->id ?? 0 ) ),
			200,
			0
		);

		if ( ! is_array( $categories ) || ! $categories ) {
			return array();
		}

		$options = array();
		foreach ( $categories as $category ) {
			$name = sanitize_text_field( $category->name ?? '' );
			if ( '' === $name ) {
				continue;
			}
			$options[ $name ] = $name;
		}

		return $options;
	}

	private static function resolve_fallback_age_category( string $birthdate, $competition ): string {
		if ( '' === $birthdate || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $birthdate ) ) {
			return '';
		}

		$age_reference = sanitize_text_field( (string) ( $competition->age_reference ?? '12-31' ) );
		if ( ! preg_match( '/^\d{2}-\d{2}$/', $age_reference ) ) {
			$age_reference = '12-31';
		}
		$season_end_year = isset( $competition->season ) ? (int) $competition->season : 0;
		$timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );

		try {
			$birth = new \DateTimeImmutable( $birthdate, $timezone );
		} catch ( \Exception $e ) {
			return '';
		}

		$reference_date = null;
		if ( $season_end_year ) {
			$reference_date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $season_end_year . '-' . $age_reference, $timezone );
		}
		if ( ! $reference_date ) {
			$reference_date = new \DateTimeImmutable( 'now', $timezone );
		}

		$age = (int) $birth->diff( $reference_date )->y;

		$groups = array(
			array( 'label' => __( 'Poussin', 'ufsc-licence-competition' ), 'age_min' => 7, 'age_max' => 9 ),
			array( 'label' => __( 'Pupille', 'ufsc-licence-competition' ), 'age_min' => 10, 'age_max' => 11 ),
			array( 'label' => __( 'Benjamin', 'ufsc-licence-competition' ), 'age_min' => 12, 'age_max' => 13 ),
			array( 'label' => __( 'Minime', 'ufsc-licence-competition' ), 'age_min' => 14, 'age_max' => 15 ),
			array( 'label' => __( 'Cadet', 'ufsc-licence-competition' ), 'age_min' => 16, 'age_max' => 17 ),
			array( 'label' => __( 'Junior', 'ufsc-licence-competition' ), 'age_min' => 18, 'age_max' => 19 ),
			array( 'label' => __( 'Senior', 'ufsc-licence-competition' ), 'age_min' => 20, 'age_max' => 34 ),
			array( 'label' => __( 'Vétéran', 'ufsc-licence-competition' ), 'age_min' => 35, 'age_max' => 99 ),
		);

		foreach ( $groups as $group ) {
			$min = (int) $group['age_min'];
			$max = (int) $group['age_max'];
			if ( $age < $min || $age > $max ) {
				continue;
			}
			return (string) $group['label'];
		}

		return '';
	}
}
