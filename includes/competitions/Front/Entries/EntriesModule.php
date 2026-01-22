<?php

namespace UFSC\Competitions\Front\Entries;

use UFSC\Competitions\Front\Access\ClubAccess;
use UFSC\Competitions\Front\Repositories\CompetitionReadRepository;
use UFSC\Competitions\Front\Repositories\EntryFrontRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EntriesModule {
	public static function register(): void {
		add_action( 'ufsc_competitions_front_registration_box', array( __CLASS__, 'render' ), 10, 1 );

		add_action( 'admin_post_ufsc_competitions_entry_create', array( EntryActions::class, 'handle_create' ) );
		add_action( 'admin_post_ufsc_competitions_entry_update', array( EntryActions::class, 'handle_update' ) );
		add_action( 'admin_post_ufsc_competitions_entry_delete', array( EntryActions::class, 'handle_delete' ) );

		add_action( 'admin_post_nopriv_ufsc_competitions_entry_create', array( EntryActions::class, 'handle_not_logged_in' ) );
		add_action( 'admin_post_nopriv_ufsc_competitions_entry_update', array( EntryActions::class, 'handle_not_logged_in' ) );
		add_action( 'admin_post_nopriv_ufsc_competitions_entry_delete', array( EntryActions::class, 'handle_not_logged_in' ) );
	}

	public static function render( $competition ): void {
		if ( ! $competition ) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			echo wp_kses_post( '<p>' . esc_html__( 'Vous devez être connecté pour vous inscrire.', 'ufsc-licence-competition' ) . '</p>' );
			return;
		}

		$club_access = new ClubAccess();
		$club_id = $club_access->get_club_id_for_user( get_current_user_id() );

		$entries = array();
		$editing_entry = null;
		if ( $club_id ) {
			$repo = new EntryFrontRepository();
			$entries = $repo->list_by_competition_and_club( (int) $competition->id, (int) $club_id );
			$editing_entry = self::resolve_editing_entry( $entries );
		}

		$notice = isset( $_GET['ufsc_entry_notice'] ) ? sanitize_key( wp_unslash( $_GET['ufsc_entry_notice'] ) ) : '';

		echo EntryFormRenderer::render(
			array(
				'competition' => $competition,
				'club_id' => $club_id,
				'entries' => $entries,
				'editing_entry' => $editing_entry,
				'notice' => $notice,
			)
		);
	}

	public static function get_fields_schema( $competition ): array {
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
				'placeholder' => '0.0',
				'columns' => array( 'weight', 'weight_kg', 'poids' ),
			),
			array(
				'name' => 'category',
				'label' => __( 'Catégorie', 'ufsc-licence-competition' ),
				'type' => 'text',
				'required' => false,
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
}
