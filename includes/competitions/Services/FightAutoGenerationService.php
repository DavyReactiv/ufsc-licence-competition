<?php

namespace UFSC\Competitions\Services;

use UFSC\Competitions\Repositories\CategoryRepository;
use UFSC\Competitions\Repositories\CompetitionRepository;
use UFSC\Competitions\Repositories\EntryRepository;
use UFSC\Competitions\Repositories\FightRepository;
use UFSC\Competitions\Front\Repositories\EntryFrontRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FightAutoGenerationService {
	private const SETTINGS_PREFIX = 'ufsc_competitions_fight_settings_';
	private const DRAFT_PREFIX    = 'ufsc_competitions_fight_draft_';

	public static function is_enabled(): bool {
		return (bool) apply_filters( 'ufsc_enable_auto_fight_generation', true );
	}

	public static function get_settings( int $competition_id ): array {
		$defaults = self::get_default_settings();
		if ( ! $competition_id ) {
			return $defaults;
		}

		$stored = get_option( self::SETTINGS_PREFIX . $competition_id, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$settings = array_merge( $defaults, $stored );
		$settings['surface_count'] = max( 1, absint( $settings['surface_count'] ) );
		$settings['fight_duration'] = max( 1, absint( $settings['fight_duration'] ) );
		$settings['break_duration'] = max( 0, absint( $settings['break_duration'] ) );
		$settings['mode'] = 'manual' === $settings['mode'] ? 'manual' : 'auto';
		$settings['auto_lock'] = ! empty( $settings['auto_lock'] ) ? 1 : 0;

		return $settings;
	}

	public static function save_settings( int $competition_id, array $data ): bool {
		if ( ! $competition_id ) {
			return false;
		}

		$settings = self::get_default_settings();
		if ( isset( $data['plateau_name'] ) ) {
			$settings['plateau_name'] = sanitize_text_field( (string) $data['plateau_name'] );
		}
		if ( isset( $data['surface_count'] ) ) {
			$settings['surface_count'] = max( 1, absint( $data['surface_count'] ) );
		}
		if ( isset( $data['surface_labels'] ) ) {
			$settings['surface_labels'] = sanitize_text_field( (string) $data['surface_labels'] );
		}
		if ( isset( $data['fight_duration'] ) ) {
			$settings['fight_duration'] = max( 1, absint( $data['fight_duration'] ) );
		}
		if ( isset( $data['break_duration'] ) ) {
			$settings['break_duration'] = max( 0, absint( $data['break_duration'] ) );
		}
		if ( isset( $data['mode'] ) ) {
			$settings['mode'] = 'manual' === $data['mode'] ? 'manual' : 'auto';
		}
		if ( isset( $data['auto_lock'] ) ) {
			$settings['auto_lock'] = ! empty( $data['auto_lock'] ) ? 1 : 0;
		}

		return (bool) update_option( self::SETTINGS_PREFIX . $competition_id, $settings, false );
	}

	public static function get_draft( int $competition_id ): array {
		if ( ! $competition_id ) {
			return array();
		}

		$draft = get_option( self::DRAFT_PREFIX . $competition_id, array() );
		return is_array( $draft ) ? $draft : array();
	}

	public static function save_draft( int $competition_id, array $draft ): bool {
		if ( ! $competition_id ) {
			return false;
		}

		return (bool) update_option( self::DRAFT_PREFIX . $competition_id, $draft, false );
	}

	public static function clear_draft( int $competition_id ): bool {
		if ( ! $competition_id ) {
			return false;
		}

		return (bool) delete_option( self::DRAFT_PREFIX . $competition_id );
	}

	public static function generate_draft( int $competition_id, array $settings ): array {
		if ( ! self::is_enabled() ) {
			return array(
				'ok' => false,
				'message' => __( 'La génération automatique est désactivée.', 'ufsc-licence-competition' ),
			);
		}

		if ( 'manual' === ( $settings['mode'] ?? 'auto' ) ) {
			return array(
				'ok' => false,
				'message' => __( 'Mode manuel actif : activez le mode automatique pour générer.', 'ufsc-licence-competition' ),
			);
		}

		if ( ! empty( $settings['auto_lock'] ) ) {
			return array(
				'ok' => false,
				'message' => __( 'La génération automatique est verrouillée.', 'ufsc-licence-competition' ),
			);
		}

		$competition_repo = new CompetitionRepository();
		$competition = $competition_repo->get( $competition_id, true );
		if ( ! $competition ) {
			return array(
				'ok' => false,
				'message' => __( 'Compétition introuvable.', 'ufsc-licence-competition' ),
			);
		}

		$entry_repo = new EntryRepository();
		$entry_front = new EntryFrontRepository();
		$entries = $entry_repo->list( array( 'view' => 'all', 'competition_id' => $competition_id ), 2000, 0 );

		$valid_entries = array();
		foreach ( $entries as $entry ) {
			if ( 'validated' !== $entry_front->get_entry_status( $entry ) ) {
				continue;
			}
			$valid_entries[] = $entry;
		}

		if ( ! $valid_entries ) {
			return array(
				'ok' => false,
				'message' => __( 'Aucune inscription validée pour générer des combats.', 'ufsc-licence-competition' ),
			);
		}

		$category_repo = new CategoryRepository();
		$categories = $category_repo->list( array( 'view' => 'all', 'competition_id' => $competition_id ), 500, 0 );
		$normalized_categories = self::normalize_categories( $categories );

		$assigner = new CategoryAssigner();
		$groups = array();
		$warnings = array();

		foreach ( $valid_entries as $entry ) {
			$category_id = absint( $entry->category_id ?? 0 );
			if ( ! $category_id ) {
				$match = $assigner->match_category(
					$normalized_categories,
					self::normalize_entry_fields( $entry ),
					array(
						'age_reference' => sanitize_text_field( $competition->age_reference ?? '12-31' ),
					)
				);
				if ( $match && ! empty( $match['id'] ) ) {
					$category_id = absint( $match['id'] );
				}
			}

			if ( ! $category_id ) {
				$warnings[] = sprintf(
					__( 'Entrée #%d non affectée à une catégorie.', 'ufsc-licence-competition' ),
					(int) ( $entry->id ?? 0 )
				);
				continue;
			}

			if ( ! isset( $groups[ $category_id ] ) ) {
				$groups[ $category_id ] = array();
			}
			$groups[ $category_id ][] = $entry;
		}

		if ( ! $groups ) {
			return array(
				'ok' => false,
				'message' => __( 'Aucune catégorie exploitable pour générer des combats.', 'ufsc-licence-competition' ),
			);
		}

		$fight_repo = new FightRepository();
		$next_fight_no = $fight_repo->get_max_fight_no( $competition_id ) + 1;

		$fights = array();
		foreach ( $groups as $category_id => $group_entries ) {
			$generated = self::build_fights_for_group( $competition_id, $category_id, $group_entries, $next_fight_no );
			$fights = array_merge( $fights, $generated['fights'] );
			$next_fight_no = $generated['next_no'];
		}

		$fights = self::assign_surfaces_and_schedule( $fights, $settings, (int) $competition_id );

		$stats = array(
			'entries' => count( $valid_entries ),
			'groups'  => count( $groups ),
			'fights'  => count( $fights ),
		);

		$draft = array(
			'competition_id' => $competition_id,
			'generated_at' => current_time( 'mysql' ),
			'generated_by' => get_current_user_id() ?: null,
			'settings' => $settings,
			'stats' => $stats,
			'warnings' => $warnings,
			'fights' => $fights,
		);

		self::save_draft( $competition_id, $draft );

		return array(
			'ok' => true,
			'message' => __( 'Pré-génération terminée. Validez pour enregistrer définitivement.', 'ufsc-licence-competition' ),
			'draft' => $draft,
		);
	}

	public static function validate_and_apply_draft( int $competition_id ): array {
		$draft = self::get_draft( $competition_id );
		if ( empty( $draft['fights'] ) || ! is_array( $draft['fights'] ) ) {
			return array(
				'ok' => false,
				'message' => __( 'Aucun brouillon disponible.', 'ufsc-licence-competition' ),
			);
		}

		$validation = self::validate_draft( $draft );
		if ( ! $validation['ok'] ) {
			return $validation;
		}

		$fight_repo = new FightRepository();
		foreach ( $draft['fights'] as $fight ) {
			$fight_repo->insert( $fight );
		}

		self::clear_draft( $competition_id );

		return array(
			'ok' => true,
			'message' => __( 'Combats enregistrés.', 'ufsc-licence-competition' ),
		);
	}

	public static function recalc_schedule( int $competition_id, array $settings ): array {
		$fight_repo = new FightRepository();
		$fights = $fight_repo->list( array( 'view' => 'all', 'competition_id' => $competition_id ), 2000, 0 );
		if ( ! $fights ) {
			return array(
				'ok' => false,
				'message' => __( 'Aucun combat à recalculer.', 'ufsc-licence-competition' ),
			);
		}

		$updated = 0;
		$prepared = array();
		foreach ( $fights as $fight ) {
			$prepared[] = array(
				'id' => (int) ( $fight->id ?? 0 ),
				'competition_id' => (int) ( $fight->competition_id ?? 0 ),
				'category_id' => (int) ( $fight->category_id ?? 0 ),
				'fight_no' => (int) ( $fight->fight_no ?? 0 ),
				'ring' => (string) ( $fight->ring ?? '' ),
				'round_no' => isset( $fight->round_no ) ? (int) $fight->round_no : null,
				'red_entry_id' => isset( $fight->red_entry_id ) ? (int) $fight->red_entry_id : null,
				'blue_entry_id' => isset( $fight->blue_entry_id ) ? (int) $fight->blue_entry_id : null,
				'winner_entry_id' => isset( $fight->winner_entry_id ) ? (int) $fight->winner_entry_id : null,
				'status' => (string) ( $fight->status ?? 'scheduled' ),
				'result_method' => (string) ( $fight->result_method ?? '' ),
				'score_red' => (string) ( $fight->score_red ?? '' ),
				'score_blue' => (string) ( $fight->score_blue ?? '' ),
				'scheduled_at' => isset( $fight->scheduled_at ) ? (string) $fight->scheduled_at : null,
			);
		}

		$prepared = self::assign_surfaces_and_schedule( $prepared, $settings, $competition_id );
		foreach ( $prepared as $fight ) {
			if ( empty( $fight['id'] ) ) {
				continue;
			}
			$fight_repo->update(
				$fight['id'],
				array(
					'competition_id' => $competition_id,
					'category_id' => $fight['category_id'],
					'fight_no' => $fight['fight_no'],
					'ring' => $fight['ring'],
					'round_no' => $fight['round_no'],
					'red_entry_id' => $fight['red_entry_id'],
					'blue_entry_id' => $fight['blue_entry_id'],
					'winner_entry_id' => $fight['winner_entry_id'],
					'status' => $fight['status'],
					'result_method' => $fight['result_method'],
					'score_red' => $fight['score_red'],
					'score_blue' => $fight['score_blue'],
					'scheduled_at' => $fight['scheduled_at'],
				)
			);
			$updated++;
		}

		return array(
			'ok' => true,
			'message' => sprintf( __( 'Horaires recalculés (%d combats).', 'ufsc-licence-competition' ), $updated ),
		);
	}

	public static function swap_colors( int $fight_id ): array {
		$fight_repo = new FightRepository();
		$fight = $fight_repo->get( $fight_id, true );
		if ( ! $fight ) {
			return array(
				'ok' => false,
				'message' => __( 'Combat introuvable.', 'ufsc-licence-competition' ),
			);
		}

		$fight_repo->update(
			$fight_id,
			array(
				'competition_id' => (int) ( $fight->competition_id ?? 0 ),
				'category_id' => (int) ( $fight->category_id ?? 0 ),
				'fight_no' => (int) ( $fight->fight_no ?? 0 ),
				'ring' => (string) ( $fight->ring ?? '' ),
				'round_no' => isset( $fight->round_no ) ? (int) $fight->round_no : null,
				'red_entry_id' => isset( $fight->blue_entry_id ) ? (int) $fight->blue_entry_id : null,
				'blue_entry_id' => isset( $fight->red_entry_id ) ? (int) $fight->red_entry_id : null,
				'winner_entry_id' => isset( $fight->winner_entry_id ) ? (int) $fight->winner_entry_id : null,
				'status' => (string) ( $fight->status ?? 'scheduled' ),
				'result_method' => (string) ( $fight->result_method ?? '' ),
				'score_red' => (string) ( $fight->score_red ?? '' ),
				'score_blue' => (string) ( $fight->score_blue ?? '' ),
				'scheduled_at' => isset( $fight->scheduled_at ) ? (string) $fight->scheduled_at : null,
			)
		);

		return array(
			'ok' => true,
			'message' => __( 'Couleurs inversées.', 'ufsc-licence-competition' ),
		);
	}

	public static function reorder_fights( int $competition_id, string $mode ): array {
		$fight_repo = new FightRepository();
		$fights = $fight_repo->list( array( 'view' => 'all', 'competition_id' => $competition_id ), 2000, 0 );
		if ( ! $fights ) {
			return array(
				'ok' => false,
				'message' => __( 'Aucun combat à réordonner.', 'ufsc-licence-competition' ),
			);
		}

		if ( 'scheduled' === $mode ) {
			usort(
				$fights,
				function( $a, $b ) {
					$time_a = $a->scheduled_at ? strtotime( $a->scheduled_at ) : 0;
					$time_b = $b->scheduled_at ? strtotime( $b->scheduled_at ) : 0;
					if ( $time_a === $time_b ) {
						return (int) ( $a->fight_no ?? 0 ) <=> (int) ( $b->fight_no ?? 0 );
					}
					return $time_a <=> $time_b;
				}
			);
		} elseif ( 'category' === $mode ) {
			usort(
				$fights,
				function( $a, $b ) {
					$cat_a = (int) ( $a->category_id ?? 0 );
					$cat_b = (int) ( $b->category_id ?? 0 );
					if ( $cat_a === $cat_b ) {
						return (int) ( $a->fight_no ?? 0 ) <=> (int) ( $b->fight_no ?? 0 );
					}
					return $cat_a <=> $cat_b;
				}
			);
		}

		$index = 1;
		foreach ( $fights as $fight ) {
			$fight_repo->update(
				(int) ( $fight->id ?? 0 ),
				array(
					'competition_id' => (int) ( $fight->competition_id ?? 0 ),
					'category_id' => (int) ( $fight->category_id ?? 0 ),
					'fight_no' => $index,
					'ring' => (string) ( $fight->ring ?? '' ),
					'round_no' => isset( $fight->round_no ) ? (int) $fight->round_no : null,
					'red_entry_id' => isset( $fight->red_entry_id ) ? (int) $fight->red_entry_id : null,
					'blue_entry_id' => isset( $fight->blue_entry_id ) ? (int) $fight->blue_entry_id : null,
					'winner_entry_id' => isset( $fight->winner_entry_id ) ? (int) $fight->winner_entry_id : null,
					'status' => (string) ( $fight->status ?? 'scheduled' ),
					'result_method' => (string) ( $fight->result_method ?? '' ),
					'score_red' => (string) ( $fight->score_red ?? '' ),
					'score_blue' => (string) ( $fight->score_blue ?? '' ),
					'scheduled_at' => isset( $fight->scheduled_at ) ? (string) $fight->scheduled_at : null,
				)
			);
			$index++;
		}

		return array(
			'ok' => true,
			'message' => __( 'Combats réordonnés.', 'ufsc-licence-competition' ),
		);
	}

	private static function get_default_settings(): array {
		return array(
			'plateau_name' => '',
			'surface_count' => 1,
			'surface_labels' => '',
			'fight_duration' => 2,
			'break_duration' => 1,
			'mode' => 'auto',
			'auto_lock' => 0,
		);
	}

	private static function normalize_categories( array $categories ): array {
		$normalized = array();
		foreach ( $categories as $category ) {
			$normalized[] = array(
				'id' => (int) ( $category->id ?? 0 ),
				'name' => sanitize_text_field( $category->name ?? '' ),
				'age_min' => isset( $category->age_min ) ? (int) $category->age_min : null,
				'age_max' => isset( $category->age_max ) ? (int) $category->age_max : null,
				'weight_min' => isset( $category->weight_min ) ? (float) $category->weight_min : null,
				'weight_max' => isset( $category->weight_max ) ? (float) $category->weight_max : null,
				'sex' => sanitize_text_field( $category->sex ?? '' ),
				'level' => sanitize_text_field( $category->level ?? '' ),
			);
		}

		return $normalized;
	}

	private static function normalize_entry_fields( $entry ): array {
		$birth_date = '';
		foreach ( array( 'birth_date', 'birthdate', 'date_of_birth', 'dob' ) as $key ) {
			if ( isset( $entry->{$key} ) && '' !== (string) $entry->{$key} ) {
				$birth_date = sanitize_text_field( (string) $entry->{$key} );
				break;
			}
		}

		$sex = '';
		foreach ( array( 'sex', 'gender' ) as $key ) {
			if ( isset( $entry->{$key} ) && '' !== (string) $entry->{$key} ) {
				$sex = sanitize_text_field( (string) $entry->{$key} );
				break;
			}
		}

		$weight = null;
		foreach ( array( 'weight', 'weight_kg', 'poids' ) as $key ) {
			if ( isset( $entry->{$key} ) && '' !== (string) $entry->{$key} ) {
				$weight = (float) str_replace( ',', '.', (string) $entry->{$key} );
				break;
			}
		}

		$level = '';
		foreach ( array( 'level', 'class', 'classe' ) as $key ) {
			if ( isset( $entry->{$key} ) && '' !== (string) $entry->{$key} ) {
				$level = sanitize_text_field( (string) $entry->{$key} );
				break;
			}
		}

		return array(
			'birth_date' => $birth_date,
			'sex' => $sex,
			'weight' => $weight,
			'level' => $level,
		);
	}

	private static function build_fights_for_group( int $competition_id, int $category_id, array $entries, int $start_no ): array {
		$fights = array();
		$count = count( $entries );
		$next_no = $start_no;

		if ( 2 === $count ) {
			$fights[] = self::build_fight_payload( $competition_id, $category_id, $next_no, $entries[0], $entries[1], 1 );
			$next_no++;
		} elseif ( $count >= 3 && $count <= 5 ) {
			foreach ( self::round_robin_pairs( $entries ) as $pair ) {
				$fights[] = self::build_fight_payload( $competition_id, $category_id, $next_no, $pair['red'], $pair['blue'], 1 );
				$next_no++;
			}
		} elseif ( $count >= 6 && $count <= 10 ) {
			$pool_generator = new PoolGenerator();
			$pools = $pool_generator->generate( $entries, 4 );
			$winners = array();

			foreach ( $pools as $pool_entries ) {
				foreach ( self::round_robin_pairs( $pool_entries ) as $pair ) {
					$fights[] = self::build_fight_payload( $competition_id, $category_id, $next_no, $pair['red'], $pair['blue'], 1 );
					$next_no++;
				}

				if ( ! empty( $pool_entries[0] ) ) {
					$winners[] = $pool_entries[0];
				}
			}

			if ( count( $winners ) >= 2 ) {
				$bracket = new BracketGenerator();
				$matches = $bracket->generate( $winners );
				foreach ( $matches as $match ) {
					$fights[] = self::build_fight_payload( $competition_id, $category_id, $next_no, $match['red'], $match['blue'], 2 );
					$next_no++;
				}
			}
		} elseif ( $count > 10 ) {
			$bracket = new BracketGenerator();
			$matches = $bracket->generate( $entries );
			foreach ( $matches as $match ) {
				$fights[] = self::build_fight_payload( $competition_id, $category_id, $next_no, $match['red'], $match['blue'], 1 );
				$next_no++;
			}
		}

		return array(
			'fights' => $fights,
			'next_no' => $next_no,
		);
	}

	private static function round_robin_pairs( array $entries ): array {
		$pairs = array();
		$count = count( $entries );
		for ( $i = 0; $i < $count; $i++ ) {
			for ( $j = $i + 1; $j < $count; $j++ ) {
				$pairs[] = array(
					'red' => $entries[ $i ],
					'blue' => $entries[ $j ],
				);
			}
		}

		return $pairs;
	}

	private static function build_fight_payload( int $competition_id, int $category_id, int $fight_no, $red_entry, $blue_entry, int $round_no ): array {
		return array(
			'competition_id' => $competition_id,
			'category_id' => $category_id,
			'fight_no' => $fight_no,
			'ring' => '',
			'round_no' => $round_no,
			'red_entry_id' => $red_entry ? (int) ( $red_entry->id ?? 0 ) : null,
			'blue_entry_id' => $blue_entry ? (int) ( $blue_entry->id ?? 0 ) : null,
			'winner_entry_id' => null,
			'status' => 'scheduled',
			'result_method' => '',
			'score_red' => '',
			'score_blue' => '',
			'scheduled_at' => null,
		);
	}

	private static function assign_surfaces_and_schedule( array $fights, array $settings, int $competition_id ): array {
		$surface_count = max( 1, absint( $settings['surface_count'] ?? 1 ) );
		$labels_raw = sanitize_text_field( (string) ( $settings['surface_labels'] ?? '' ) );
		$labels = array_filter( array_map( 'trim', explode( ',', $labels_raw ) ) );

		$surface_labels = array();
		for ( $i = 0; $i < $surface_count; $i++ ) {
			if ( ! empty( $labels[ $i ] ) ) {
				$surface_labels[ $i ] = $labels[ $i ];
			} else {
				$surface_labels[ $i ] = sprintf( __( 'Surface %d', 'ufsc-licence-competition' ), $i + 1 );
			}
		}

		$duration = max( 1, absint( $settings['fight_duration'] ?? 2 ) );
		$break = max( 0, absint( $settings['break_duration'] ?? 1 ) );
		$step = ( $duration + $break ) * MINUTE_IN_SECONDS;

		$meta = CompetitionMeta::get( $competition_id );
		$start = $meta['fights_start'] ?? '';
		$start_ts = 0;
		if ( $start ) {
			$timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( wp_timezone_string() ?: 'UTC' );
			$start_date = date_create_from_format( 'Y-m-d H:i:s', $start, $timezone );
			if ( $start_date instanceof \DateTimeInterface ) {
				$start_ts = $start_date->getTimestamp();
			}
		}

		$surface_times = array();
		for ( $i = 0; $i < $surface_count; $i++ ) {
			$surface_times[ $i ] = $start_ts;
		}

		foreach ( $fights as $index => $fight ) {
			$surface_index = 0;
			$min_time = $surface_times[0];
			for ( $i = 1; $i < $surface_count; $i++ ) {
				if ( $surface_times[ $i ] < $min_time ) {
					$min_time = $surface_times[ $i ];
					$surface_index = $i;
				}
			}

			$fights[ $index ]['ring'] = $surface_labels[ $surface_index ];
			if ( $start_ts ) {
				$fights[ $index ]['scheduled_at'] = date_i18n( 'Y-m-d H:i:s', $surface_times[ $surface_index ] );
				$surface_times[ $surface_index ] += $step;
			}
		}

		return $fights;
	}

	private static function validate_draft( array $draft ): array {
		$competition_id = absint( $draft['competition_id'] ?? 0 );
		if ( ! $competition_id ) {
			return array(
				'ok' => false,
				'message' => __( 'Brouillon invalide.', 'ufsc-licence-competition' ),
			);
		}

		$fights = $draft['fights'] ?? array();
		if ( ! is_array( $fights ) || ! $fights ) {
			return array(
				'ok' => false,
				'message' => __( 'Aucun combat à enregistrer.', 'ufsc-licence-competition' ),
			);
		}

		foreach ( $fights as $fight ) {
			if ( empty( $fight['competition_id'] ) || empty( $fight['fight_no'] ) ) {
				return array(
					'ok' => false,
					'message' => __( 'Brouillon incomplet : numéro de combat manquant.', 'ufsc-licence-competition' ),
				);
			}
		}

		return array(
			'ok' => true,
			'message' => '',
		);
	}
}
