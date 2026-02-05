<?php

namespace UFSC\Competitions\Services;

use UFSC\Competitions\Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CompetitionFilters {
	private static $type_alias_map = null;
	private static $season_cache = null;

	public static function get_seasons(): array {
		if ( null !== self::$season_cache ) {
			return self::$season_cache;
		}

		global $wpdb;

		$table = Db::competitions_table();
		$rows  = $wpdb->get_col( "SELECT DISTINCT season FROM {$table} WHERE deleted_at IS NULL AND season <> '' ORDER BY season DESC" );

		if ( ! is_array( $rows ) ) {
			self::$season_cache = array();
			return self::$season_cache;
		}

		$seasons = array();
		foreach ( $rows as $season ) {
			$season = trim( (string) $season );
			if ( '' !== $season ) {
				$seasons[] = $season;
			}
		}

		self::$season_cache = array_values( array_unique( $seasons ) );
		return self::$season_cache;
	}

	public static function get_disciplines(): array {
		if ( class_exists( DisciplineRegistry::class ) ) {
			return DisciplineRegistry::get_disciplines();
		}

		return array();
	}

	public static function get_discipline_label( string $discipline ): string {
		$discipline = trim( $discipline );
		if ( '' === $discipline ) {
			return '';
		}

		if ( class_exists( DisciplineRegistry::class ) ) {
			return DisciplineRegistry::get_label( $discipline );
		}

		return $discipline;
	}

	public static function get_type_choices(): array {
		$choices = array(
			'open'                 => __( 'Open', 'ufsc-licence-competition' ),
			'gala'                 => __( 'Gala', 'ufsc-licence-competition' ),
			'championnat_regional' => __( 'Championnat régional', 'ufsc-licence-competition' ),
			'selection_regionale'  => __( 'Sélection régionale', 'ufsc-licence-competition' ),
			'championnat_national' => __( 'Championnat national', 'ufsc-licence-competition' ),
			'interclub'            => __( 'Interclub', 'ufsc-licence-competition' ),
			'stage'                => __( 'Stage', 'ufsc-licence-competition' ),
			'autre'                => __( 'Autre', 'ufsc-licence-competition' ),
		);

		return apply_filters( 'ufsc_competitions_type_choices', $choices );
	}

	public static function get_status_choices(): array {
		$choices = array(
			'open'   => __( 'Ouvert', 'ufsc-licence-competition' ),
			'closed' => __( 'Fermé', 'ufsc-licence-competition' ),
			'draft'  => __( 'Brouillon', 'ufsc-licence-competition' ),
		);

		return apply_filters( 'ufsc_competitions_status_choices', $choices );
	}

	public static function get_status_label( string $status ): string {
		$labels = array(
			'open'     => __( 'Ouvert', 'ufsc-licence-competition' ),
			'closed'   => __( 'Fermé', 'ufsc-licence-competition' ),
			'draft'    => __( 'Brouillon', 'ufsc-licence-competition' ),
			'archived' => __( 'Archivé', 'ufsc-licence-competition' ),
		);

		$key = sanitize_key( $status );
		return $labels[ $key ] ?? $status;
	}

	public static function normalize_type_key( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}

		$map = self::get_type_alias_map();
		$key = sanitize_key( $value );

		return $map[ $key ] ?? $key;
	}

	public static function get_type_label( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}

		$key = self::normalize_type_key( $value );
		$choices = self::get_type_choices();

		return $choices[ $key ] ?? $value;
	}

	public static function get_type_variants( string $value ): array {
		$value = trim( $value );
		if ( '' === $value ) {
			return array();
		}

		$key      = self::normalize_type_key( $value );
		$choices  = self::get_type_choices();
		$variants = array( $value );

		if ( '' !== $key ) {
			$variants[] = $key;
		}

		if ( isset( $choices[ $key ] ) ) {
			$variants[] = $choices[ $key ];
		}

		$aliases = self::get_type_aliases();
		if ( isset( $aliases[ $key ] ) ) {
			foreach ( $aliases[ $key ] as $alias ) {
				$variants[] = $alias;
			}
		}

		$variants = array_map( 'trim', $variants );
		$variants = array_filter( $variants );

		return array_values( array_unique( $variants ) );
	}

	private static function get_type_aliases(): array {
		return array(
			'championnat_regional' => array(
				'championnat regional',
				'championnat régional',
				'championnat-regional',
				'championnat-régional',
				'regional',
				'regionale',
				'régional',
				'régionale',
			),
			'selection_regionale'  => array(
				'selection regionale',
				'sélection regionale',
				'sélection régionale',
				'selection region',
				'selection-regional',
			),
			'championnat_national' => array(
				'championnat national',
				'championnat-national',
				'national',
			),
			'interclub'            => array(
				'inter-club',
				'inter club',
			),
		);
	}

	private static function get_type_alias_map(): array {
		if ( null !== self::$type_alias_map ) {
			return self::$type_alias_map;
		}

		$map = array();

		foreach ( array_keys( self::get_type_choices() ) as $choice ) {
			$map[ sanitize_key( $choice ) ] = $choice;
		}

		foreach ( self::get_type_aliases() as $canonical => $aliases ) {
			$map[ sanitize_key( $canonical ) ] = $canonical;
			foreach ( $aliases as $alias ) {
				$map[ sanitize_key( $alias ) ] = $canonical;
			}
		}

		self::$type_alias_map = $map;
		return self::$type_alias_map;
	}
}
