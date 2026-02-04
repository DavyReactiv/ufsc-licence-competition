<?php

namespace UFSC\Competitions\Exports;

use UFSC\Competitions\Entries\EntriesWorkflow;
use UFSC\Competitions\Repositories\CategoryRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Engaged_Entries_Export_Helper {

	public static function get_csv_columns(): array {
		return array(
			'last_name' => array(
				'label' => __( 'Nom', 'ufsc-licence-competition' ),
				'key'   => 'last_name',
			),
			'first_name' => array(
				'label' => __( 'Prénom', 'ufsc-licence-competition' ),
				'key'   => 'first_name',
			),
			'birthdate' => array(
				'label' => __( 'Date naissance', 'ufsc-licence-competition' ),
				'key'   => 'birthdate',
			),
			'club' => array(
				'label' => __( 'Club', 'ufsc-licence-competition' ),
				'key'   => 'club',
			),
			'category' => array(
				'label' => __( 'Catégorie', 'ufsc-licence-competition' ),
				'key'   => 'category',
			),
			'weight' => array(
				'label' => __( 'Poids', 'ufsc-licence-competition' ),
				'key'   => 'weight',
			),
			'weight_class' => array(
				'label' => __( 'Catégorie poids', 'ufsc-licence-competition' ),
				'key'   => 'weight_class',
			),
			'status' => array(
				'label' => __( 'Statut', 'ufsc-licence-competition' ),
				'key'   => 'status',
			),
		);
	}

	public static function build_csv_row( array $columns, $entry, $competition ): array {
		$last_name = self::get_entry_value( $entry, array( 'last_name', 'lastname', 'nom', 'licensee_last_name' ) );
		$first_name = self::get_entry_value( $entry, array( 'first_name', 'firstname', 'prenom', 'licensee_first_name' ) );
		$birthdate = self::get_entry_value( $entry, array( 'birth_date', 'birthdate', 'date_of_birth', 'dob', 'licensee_birthdate' ) );
		$club = (string) ( $entry->club_name ?? '' );
		$category = self::resolve_category_label( $entry, $competition );
		$weight = self::get_entry_value( $entry, array( 'weight', 'weight_kg', 'poids' ) );
		$weight_class = self::get_entry_value( $entry, array( 'weight_class', 'weight_cat', 'weight_category', 'weight_class_label', 'weight_category_label', 'weight_cat_label' ) );
		$status_raw = isset( $entry->status ) ? (string) $entry->status : '';
		$status_norm = EntriesWorkflow::normalize_status( $status_raw );
		$status_label = EntriesWorkflow::get_status_label( $status_norm );

		$row = array(
			'last_name'    => $last_name,
			'first_name'   => $first_name,
			'birthdate'    => $birthdate,
			'club'         => $club,
			'category'     => $category,
			'weight'       => $weight,
			'weight_class' => $weight_class,
			'status'       => $status_label,
		);

		$out = array();
		foreach ( $columns as $column ) {
			$key = $column['key'] ?? '';
			$value = isset( $row[ $key ] ) ? $row[ $key ] : '';
			$out[] = self::safe_csv_cell( $value );
		}

		return $out;
	}

	public static function prepare_export_headers(): void {
		if ( headers_sent() ) {
			wp_die( esc_html__( 'Export impossible.', 'ufsc-licence-competition' ) );
		}

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
	}

	public static function safe_csv_cell( $value ): string {
		if ( is_bool( $value ) ) {
			$value = $value ? '1' : '0';
		}

		if ( is_null( $value ) ) {
			return '';
		}

		$value = (string) $value;

		if ( $value !== '' && preg_match( '/^[=\\+\\-@\\t]/', $value ) ) {
			$value = "'" . $value;
		}

		return $value;
	}

	private static function resolve_category_label( $entry, $competition = null ): string {
		$label = self::get_entry_value( $entry, array( 'category', 'category_name', 'category_label', 'category_title' ) );
		if ( '' !== $label ) {
			return $label;
		}

		$category_id = absint( $entry->category_id ?? 0 );
		if ( $category_id && class_exists( CategoryRepository::class ) ) {
			static $cache = array();
			if ( ! array_key_exists( $category_id, $cache ) ) {
				$repo = new CategoryRepository();
				$category = $repo->get( $category_id, true );
				$cache[ $category_id ] = $category ? (string) ( $category->name ?? '' ) : '';
			}

			return (string) $cache[ $category_id ];
		}

		$birth_date = self::get_entry_value( $entry, array( 'birth_date', 'birthdate', 'date_of_birth', 'dob', 'licensee_birthdate' ) );
		if ( '' !== $birth_date && function_exists( 'ufsc_lc_compute_category_from_birthdate' ) ) {
			$season_end_year = '';
			if ( is_object( $competition ) && isset( $competition->season ) ) {
				$season_end_year = (string) $competition->season;
			}

			if ( '' !== $season_end_year ) {
				$computed = ufsc_lc_compute_category_from_birthdate( $birth_date, $season_end_year );
				if ( '' !== $computed ) {
					return $computed;
				}
			}
		}

		return '';
	}

	private static function get_entry_value( $entry, array $keys ): string {
		foreach ( $keys as $key ) {
			if ( isset( $entry->{$key} ) && '' !== (string) $entry->{$key} ) {
				return (string) $entry->{$key};
			}
		}

		return '';
	}
}
