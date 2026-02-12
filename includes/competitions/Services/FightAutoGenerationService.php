<?php

namespace UFSC\Competitions\Services;

use UFSC\Competitions\Repositories\CategoryRepository;
use UFSC\Competitions\Repositories\CompetitionRepository;
use UFSC\Competitions\Repositories\EntryRepository;
use UFSC\Competitions\Repositories\FightRepository;
use UFSC\Competitions\Repositories\TimingProfileRepository;
use UFSC\Competitions\Repositories\WeighInRepository;
use UFSC\Competitions\Services\CompetitionFilters;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FightAutoGenerationService {
	private const SETTINGS_PREFIX = 'ufsc_competitions_fight_settings_';
	private const LOCK_PREFIX     = 'ufsc_autogen_lock_';
	private const LOCK_TTL        = 60;

	public static function is_enabled(): bool {
		return (bool) apply_filters( 'ufsc_enable_auto_fight_generation', true );
	}

	public static function get_settings( int $competition_id ): array {
		$defaults = self::get_default_settings();
		if ( ! $competition_id ) {
			return $defaults;
		}

		$stored = get_option( self::SETTINGS_PREFIX . $competition_id, null );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$has_stored_settings = ! empty( $stored );
		$settings = array_merge( $defaults, $stored );
		$settings['surface_count'] = min( 32, max( 1, absint( $settings['surface_count'] ) ) );
		$settings['fight_duration'] = min( 30, max( 1, absint( $settings['fight_duration'] ) ) );
		$settings['break_duration'] = min( 30, max( 0, absint( $settings['break_duration'] ) ) );
		$settings['timing_mode'] = 'category' === ( $settings['timing_mode'] ?? 'global' ) ? 'category' : 'global';
		$settings['mode'] = 'manual' === $settings['mode'] ? 'manual' : 'auto';
		$settings['auto_lock'] = ! empty( $settings['auto_lock'] ) ? 1 : 0;
		$settings['allow_unweighed'] = ! empty( $settings['allow_unweighed'] ) ? 1 : 0;

		if ( ! $has_stored_settings ) {
			$competition_repo = new CompetitionRepository();
			$competition = $competition_repo->get( $competition_id, true );
			if ( $competition ) {
				$profile_repo = new TimingProfileRepository();
				$profiles = $profile_repo->list(
					array(
						'discipline' => sanitize_text_field( (string) ( $competition->discipline ?? '' ) ),
					),
					1,
					0
				);
				if ( empty( $profiles ) ) {
					$profiles = $profile_repo->list( array(), 1, 0 );
				}
				if ( ! empty( $profiles ) ) {
					$settings['timing_mode'] = 'category';
				}
			}
		}

		$settings['surface_details'] = self::normalize_surface_details( $settings );

		return $settings;
	}

	public static function save_settings( int $competition_id, array $data ): bool {
		if ( ! self::is_enabled() ) {
			return false;
		}

		if ( ! $competition_id ) {
			return false;
		}

		$settings = self::get_default_settings();
		if ( isset( $data['plateau_name'] ) ) {
			$settings['plateau_name'] = sanitize_text_field( (string) $data['plateau_name'] );
		}
		if ( isset( $data['surface_count'] ) ) {
			$settings['surface_count'] = min( 32, max( 1, absint( $data['surface_count'] ) ) );
		}
		$settings['surface_details'] = isset( $data['surface_details'] ) && is_array( $data['surface_details'] )
			? $data['surface_details']
			: array();
		if ( isset( $data['fight_duration'] ) ) {
			$settings['fight_duration'] = min( 30, max( 1, absint( $data['fight_duration'] ) ) );
		}
		if ( isset( $data['break_duration'] ) ) {
			$settings['break_duration'] = min( 30, max( 0, absint( $data['break_duration'] ) ) );
		}
		if ( isset( $data['timing_mode'] ) ) {
			$settings['timing_mode'] = 'category' === $data['timing_mode'] ? 'category' : 'global';
		}
		if ( isset( $data['mode'] ) ) {
			$settings['mode'] = 'manual' === $data['mode'] ? 'manual' : 'auto';
		}
		if ( isset( $data['auto_lock'] ) ) {
			$settings['auto_lock'] = ! empty( $data['auto_lock'] ) ? 1 : 0;
		}
		if ( isset( $data['allow_unweighed'] ) ) {
			$settings['allow_unweighed'] = ! empty( $data['allow_unweighed'] ) ? 1 : 0;
		}

		$settings['surface_details'] = self::sanitize_surface_details( $settings['surface_details'], $settings['surface_count'] );
		if ( empty( $settings['surface_details'] ) ) {
			return false;
		}

		return (bool) update_option( self::SETTINGS_PREFIX . $competition_id, $settings, false );
	}

	public static function get_draft( int $competition_id ): array {
		$fight_repo = new FightRepository();
		return $fight_repo->get_draft( $competition_id );
	}

	public static function save_draft( int $competition_id, array $draft ): bool {
		if ( ! self::is_enabled() ) {
			return false;
		}

		$fight_repo = new FightRepository();
		return $fight_repo->save_draft( $competition_id, $draft );
	}

	public static function clear_draft( int $competition_id ): bool {
		if ( ! self::is_enabled() ) {
			return false;
		}

		$fight_repo = new FightRepository();
		return $fight_repo->clear_draft( $competition_id );
	}

	public static function get_generation_counters( int $competition_id, array $settings ): array {
		if ( ! $competition_id ) {
			return array(
				'total_entries' => 0,
				'eligible_entries' => 0,
				'excluded_unweighed' => 0,
				'can_override_unweighed' => false,
			);
		}

		$competition_repo = new CompetitionRepository();
		$competition = $competition_repo->get( $competition_id, true );
		if ( ! $competition ) {
			return array(
				'total_entries' => 0,
				'eligible_entries' => 0,
				'excluded_unweighed' => 0,
				'can_override_unweighed' => false,
			);
		}

		$entry_repo = new EntryRepository();
		$entries = $entry_repo->list( array( 'view' => 'all', 'competition_id' => $competition_id ), 2000, 0 );
		$selection = self::select_eligible_entries( $entries, $competition_id, $competition, $settings );

		return array(
			'total_entries' => count( $entries ),
			'eligible_entries' => count( $selection['valid_entries'] ),
			'excluded_unweighed' => (int) ( $selection['excluded_unweighed'] ?? 0 ),
			'can_override_unweighed' => ! empty( $selection['enforce_weighin'] ) && (int) ( $selection['excluded_unweighed'] ?? 0 ) > 0,
		);
	}

	public static function generate_draft( int $competition_id, array $settings ): array {
		if ( ! self::is_enabled() ) {
			return array(
				'ok' => false,
				'message' => __( 'La génération automatique est désactivée.', 'ufsc-licence-competition' ),
			);
		}

		if ( ! $competition_id ) {
			return array(
				'ok' => false,
				'message' => __( 'Compétition invalide.', 'ufsc-licence-competition' ),
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

		$lock_key = self::get_lock_key( $competition_id );
		if ( get_transient( $lock_key ) ) {
			return array(
				'ok' => false,
				'message' => __( 'Génération déjà en cours, veuillez patienter.', 'ufsc-licence-competition' ),
			);
		}

		set_transient( $lock_key, 1, self::LOCK_TTL );

		try {
			$competition_repo = new CompetitionRepository();
			$competition = $competition_repo->get( $competition_id, true );
			if ( ! $competition ) {
				return array(
					'ok' => false,
					'message' => __( 'Compétition introuvable.', 'ufsc-licence-competition' ),
				);
			}

			$entry_repo = new EntryRepository();
			$entries = $entry_repo->list( array( 'view' => 'all', 'competition_id' => $competition_id ), 2000, 0 );
			$selection = self::select_eligible_entries( $entries, $competition_id, $competition, $settings );
			$valid_entries = $selection['valid_entries'];
			$ineligible_reasons = $selection['ineligible_reasons'];
			$excluded_unweighed = (int) ( $selection['excluded_unweighed'] ?? 0 );
			$total_entries = count( $entries );

			if ( ! $valid_entries ) {
				$reason_messages = array(
					'status_not_approved' => __( 'Validation admin requise.', 'ufsc-licence-competition' ),
					'weight_missing' => __( 'Poids manquant.', 'ufsc-licence-competition' ),
					'weight_class_missing' => __( 'Catégorie de poids manquante.', 'ufsc-licence-competition' ),
					'license_missing' => __( 'Licence manquante.', 'ufsc-licence-competition' ),
					'club_missing' => __( 'Club manquant.', 'ufsc-licence-competition' ),
					'entry_deleted' => __( 'Inscription supprimée.', 'ufsc-licence-competition' ),
					'weighin_missing' => __( 'Pesée valide requise.', 'ufsc-licence-competition' ),
				);

				$reasons = array();
				foreach ( array_keys( $ineligible_reasons ) as $reason ) {
					if ( isset( $reason_messages[ $reason ] ) ) {
						$reasons[] = $reason_messages[ $reason ];
					}
				}

				$message = __( 'Aucun combattant éligible pour générer des combats.', 'ufsc-licence-competition' );
				if ( $reasons ) {
					$message .= ' ' . implode( ' ', $reasons );
				}

				return array(
					'ok' => false,
					'message' => $message,
					'stats' => array(
						'total_entries' => $total_entries,
						'eligible_entries' => 0,
						'excluded_unweighed' => $excluded_unweighed,
					),
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
				'total_entries' => $total_entries,
				'eligible_entries' => count( $valid_entries ),
				'excluded_unweighed' => $excluded_unweighed,
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

			$message = __( 'Pré-génération terminée. Validez pour enregistrer définitivement.', 'ufsc-licence-competition' );
			if ( 0 === count( $fights ) ) {
				$message = __( '0 combat généré : vérifiez les catégories, statuts d’inscription et pesées.', 'ufsc-licence-competition' );
			}

			return array(
				'ok' => true,
				'message' => $message,
				'draft' => $draft,
			);
		} finally {
			delete_transient( $lock_key );
		}
	}

	public static function validate_and_apply_draft( int $competition_id, string $apply_mode = 'append' ): array {
		if ( ! self::is_enabled() ) {
			return array(
				'ok' => false,
				'message' => __( 'La génération automatique est désactivée.', 'ufsc-licence-competition' ),
			);
		}

		if ( ! $competition_id ) {
			return array(
				'ok' => false,
				'message' => __( 'Compétition invalide.', 'ufsc-licence-competition' ),
			);
		}

		$apply_mode = 'replace' === $apply_mode ? 'replace' : 'append';
		if ( 'replace' === $apply_mode ) {
			return array(
				'ok' => false,
				'message' => __( 'Le mode remplacement n’est pas disponible.', 'ufsc-licence-competition' ),
			);
		}

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
		$next_fight_no = $fight_repo->get_max_fight_no( $competition_id ) + 1;
		$prepared_fights = array();
		foreach ( $draft['fights'] as $fight ) {
			$fight['competition_id'] = $competition_id;
			$fight['fight_no'] = $next_fight_no;
			$prepared_fights[] = $fight;
			$next_fight_no++;
		}

		foreach ( $prepared_fights as $fight ) {
			$fight_repo->insert( $fight );
		}

		self::clear_draft( $competition_id );

		return array(
			'ok' => true,
			'message' => __( 'Combats enregistrés.', 'ufsc-licence-competition' ),
		);
	}

	public static function recalc_schedule( int $competition_id, array $settings ): array {
		if ( ! self::is_enabled() ) {
			return array(
				'ok' => false,
				'message' => __( 'La génération automatique est désactivée.', 'ufsc-licence-competition' ),
			);
		}

		if ( ! $competition_id ) {
			return array(
				'ok' => false,
				'message' => __( 'Compétition invalide.', 'ufsc-licence-competition' ),
			);
		}

		$fight_repo = new FightRepository();
		$fights = $fight_repo->get_draft_fights( $competition_id );
		if ( ! $fights ) {
			return array(
				'ok' => false,
				'message' => __( 'Aucun brouillon disponible.', 'ufsc-licence-competition' ),
			);
		}

		$fight_repo->recalc_draft_schedule( $competition_id, $settings );
		$updated = count( $fights );

		return array(
			'ok' => true,
			'message' => sprintf( __( 'Horaires recalculés (%d combats).', 'ufsc-licence-competition' ), $updated ),
		);
	}

	public static function swap_colors( int $competition_id, int $fight_id ): array {
		if ( ! self::is_enabled() ) {
			return array(
				'ok' => false,
				'message' => __( 'La génération automatique est désactivée.', 'ufsc-licence-competition' ),
			);
		}

		if ( ! $competition_id || ! $fight_id ) {
			return array(
				'ok' => false,
				'message' => __( 'Combat invalide.', 'ufsc-licence-competition' ),
			);
		}

		return ( new FightRepository() )->swap_draft_corners( $competition_id, $fight_id );
	}

	public static function reorder_fights( int $competition_id, string $mode ): array {
		if ( ! self::is_enabled() ) {
			return array(
				'ok' => false,
				'message' => __( 'La génération automatique est désactivée.', 'ufsc-licence-competition' ),
			);
		}

		if ( ! $competition_id ) {
			return array(
				'ok' => false,
				'message' => __( 'Compétition invalide.', 'ufsc-licence-competition' ),
			);
		}

		$mode = in_array( $mode, array( 'fight_no', 'scheduled', 'category' ), true ) ? $mode : 'fight_no';

		$fight_repo = new FightRepository();
		$fights = $fight_repo->get_draft_fights( $competition_id );
		if ( ! $fights ) {
			return array(
				'ok' => false,
				'message' => __( 'Aucun brouillon disponible.', 'ufsc-licence-competition' ),
			);
		}

		if ( 'scheduled' === $mode ) {
			usort(
				$fights,
				function( $a, $b ) {
					$time_a = ! empty( $a['scheduled_at'] ) ? strtotime( $a['scheduled_at'] ) : 0;
					$time_b = ! empty( $b['scheduled_at'] ) ? strtotime( $b['scheduled_at'] ) : 0;
					if ( $time_a === $time_b ) {
						return (int) ( $a['fight_no'] ?? 0 ) <=> (int) ( $b['fight_no'] ?? 0 );
					}
					return $time_a <=> $time_b;
				}
			);
		} elseif ( 'category' === $mode ) {
			usort(
				$fights,
				function( $a, $b ) {
					$cat_a = (int) ( $a['category_id'] ?? 0 );
					$cat_b = (int) ( $b['category_id'] ?? 0 );
					if ( $cat_a === $cat_b ) {
						return (int) ( $a['fight_no'] ?? 0 ) <=> (int) ( $b['fight_no'] ?? 0 );
					}
					return $cat_a <=> $cat_b;
				}
			);
		}

		$start_no = min( array_map( 'intval', wp_list_pluck( $fights, 'fight_no' ) ) );
		$fight_repo->update_draft_order( $competition_id, $fights, $start_no );

		return array(
			'ok' => true,
			'message' => __( 'Combats réordonnés.', 'ufsc-licence-competition' ),
		);
	}


	private static function select_eligible_entries( array $entries, int $competition_id, $competition, array $settings ): array {
		$valid_entries = array();
		$ineligible_reasons = array();
		$excluded_unweighed = 0;
		$allow_unweighed = ! empty( $settings['allow_unweighed'] );
		$weighin_repo = new WeighInRepository();
		$enforce_weighin = ! $allow_unweighed && $weighin_repo->has_table();
		$competition_tolerance = isset( $competition->weight_tolerance ) ? (float) $competition->weight_tolerance : 0.0;

		foreach ( $entries as $entry ) {
			$entry_id = (int) ( $entry->id ?? 0 );
			$eligibility = function_exists( 'ufsc_lc_is_entry_eligible' )
				? ufsc_lc_is_entry_eligible( $entry_id, 'fights' )
				: array( 'eligible' => false, 'reasons' => array( 'status_not_approved' ) );

			if ( empty( $eligibility['eligible'] ) ) {
				foreach ( $eligibility['reasons'] as $reason ) {
					$ineligible_reasons[ $reason ] = true;
				}
				continue;
			}

			if ( $enforce_weighin ) {
				$entry_weight = isset( $entry->weight_kg ) ? (float) $entry->weight_kg : null;
				$has_weighin = $weighin_repo->has_valid_weighin( $competition_id, $entry_id, $competition_tolerance, $entry_weight );
				if ( ! $has_weighin ) {
					$ineligible_reasons['weighin_missing'] = true;
					$excluded_unweighed++;
					continue;
				}
			}

			$valid_entries[] = $entry;
		}

		return array(
			'valid_entries' => $valid_entries,
			'ineligible_reasons' => $ineligible_reasons,
			'excluded_unweighed' => $excluded_unweighed,
			'enforce_weighin' => $enforce_weighin,
		);
	}

	private static function get_default_settings(): array {
		return array(
			'plateau_name' => '',
			'surface_count' => 1,
			'surface_labels' => '',
			'surface_details' => array(),
			'fight_duration' => 2,
			'break_duration' => 1,
			'timing_mode' => 'global',
			'mode' => 'auto',
			'auto_lock' => 0,
			'allow_unweighed' => 0,
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
				'discipline' => sanitize_text_field( $category->discipline ?? '' ),
				'format' => sanitize_text_field( $category->format ?? '' ),
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
			'timing_profile_id' => null,
			'round_duration' => null,
			'rounds' => null,
			'break_duration' => null,
			'fight_pause' => null,
			'fight_duration' => null,
		);
	}

	public static function assign_surfaces_and_schedule( array $fights, array $settings, int $competition_id ): array {
		$surface_labels = self::get_surface_labels( $settings );
		$surface_count = max( 1, count( $surface_labels ) );

		$duration = max( 1, absint( $settings['fight_duration'] ?? 2 ) );
		$break = max( 0, absint( $settings['break_duration'] ?? 1 ) );

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

		$profiles = array();
		$categories = array();
		$competition = null;
		$surface_types = self::get_surface_types_from_settings( $settings );

		if ( 'category' === ( $settings['timing_mode'] ?? 'global' ) ) {
			$profile_repo = new TimingProfileRepository();
			$profiles = $profile_repo->list();
			$category_repo = new CategoryRepository();
			$categories = $category_repo->list( array( 'view' => 'all', 'competition_id' => $competition_id ), 500, 0 );
			$competition_repo = new CompetitionRepository();
			$competition = $competition_repo->get( $competition_id, true );
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

			$timing = self::resolve_fight_timing( $fight, $settings, $profiles, $categories, $competition, $surface_types );
			$fights[ $index ] = array_merge( $fight, $timing );

			$fights[ $index ]['ring'] = $surface_labels[ $surface_index ];
			if ( $start_ts ) {
				$fights[ $index ]['scheduled_at'] = date_i18n( 'Y-m-d H:i:s', $surface_times[ $surface_index ] );
				$step = ( (int) $timing['fight_duration'] + (int) $timing['fight_pause'] ) * MINUTE_IN_SECONDS;
				$surface_times[ $surface_index ] += $step;
			}
		}

		return $fights;
	}

	private static function normalize_surface_details( array $settings ): array {
		$surface_count = max( 1, absint( $settings['surface_count'] ?? 1 ) );
		$details = array();
		$raw = isset( $settings['surface_details'] ) && is_array( $settings['surface_details'] )
			? $settings['surface_details']
			: array();

		foreach ( $raw as $detail ) {
			if ( ! is_array( $detail ) ) {
				continue;
			}
			$name = sanitize_text_field( (string) ( $detail['name'] ?? '' ) );
			$type = sanitize_key( (string) ( $detail['type'] ?? 'tatami' ) );
			$type = in_array( $type, array( 'tatami', 'ring' ), true ) ? $type : 'tatami';
			$details[] = array(
				'name' => $name,
				'type' => $type,
			);
		}

		if ( empty( $details ) && ! empty( $settings['surface_labels'] ) ) {
			$labels_raw = sanitize_text_field( (string) $settings['surface_labels'] );
			$labels = array_filter( array_map( 'trim', explode( ',', $labels_raw ) ) );
			foreach ( $labels as $label ) {
				$details[] = array(
					'name' => sanitize_text_field( $label ),
					'type' => 'tatami',
				);
			}
		}

		$details = self::fill_surface_defaults( $details, $surface_count );

		return $details;
	}

	private static function sanitize_surface_details( array $details, int $surface_count ): array {
		$surface_count = max( 1, $surface_count );
		$clean = array();
		for ( $i = 0; $i < $surface_count; $i++ ) {
			$detail = $details[ $i ] ?? array();
			$name = sanitize_text_field( (string) ( $detail['name'] ?? '' ) );
			$type = sanitize_key( (string) ( $detail['type'] ?? 'tatami' ) );
			$type = in_array( $type, array( 'tatami', 'ring' ), true ) ? $type : '';

			if ( '' === $name || '' === $type ) {
				return array();
			}

			$clean[] = array(
				'name' => $name,
				'type' => $type,
			);
		}

		return $clean;
	}

	private static function fill_surface_defaults( array $details, int $surface_count ): array {
		$surface_count = max( 1, $surface_count );
		for ( $i = 0; $i < $surface_count; $i++ ) {
			if ( ! isset( $details[ $i ] ) || ! is_array( $details[ $i ] ) ) {
				$details[ $i ] = array(
					'name' => sprintf( __( 'Surface %d', 'ufsc-licence-competition' ), $i + 1 ),
					'type' => 'tatami',
				);
				continue;
			}
			if ( '' === (string) ( $details[ $i ]['name'] ?? '' ) ) {
				$details[ $i ]['name'] = sprintf( __( 'Surface %d', 'ufsc-licence-competition' ), $i + 1 );
			}
			$type = sanitize_key( (string) ( $details[ $i ]['type'] ?? 'tatami' ) );
			$details[ $i ]['type'] = in_array( $type, array( 'tatami', 'ring' ), true ) ? $type : 'tatami';
		}

		return $details;
	}

	private static function get_surface_labels( array $settings ): array {
		$details = self::normalize_surface_details( $settings );
		$labels = array();
		foreach ( $details as $index => $detail ) {
			$name = sanitize_text_field( (string) ( $detail['name'] ?? '' ) );
			$labels[ $index ] = '' !== $name ? $name : sprintf( __( 'Surface %d', 'ufsc-licence-competition' ), $index + 1 );
		}

		return $labels;
	}

	private static function get_surface_types_from_settings( array $settings ): array {
		$details = self::normalize_surface_details( $settings );
		$types = array();
		foreach ( $details as $detail ) {
			$type = sanitize_key( (string) ( $detail['type'] ?? '' ) );
			if ( in_array( $type, array( 'tatami', 'ring' ), true ) ) {
				$types[] = $type;
			}
		}

		return array_values( array_unique( $types ) );
	}

	private static function resolve_fight_timing( array $fight, array $settings, array $profiles, array $categories, $competition = null, array $surface_types = array() ): array {
		$duration = max( 1, absint( $settings['fight_duration'] ?? 2 ) );
		$pause = max( 0, absint( $settings['break_duration'] ?? 1 ) );

		$timing = array(
			'timing_profile_id' => array_key_exists( 'timing_profile_id', $fight ) ? absint( $fight['timing_profile_id'] ) : 0,
			'round_duration' => array_key_exists( 'round_duration', $fight ) ? absint( $fight['round_duration'] ) : 0,
			'rounds' => array_key_exists( 'rounds', $fight ) ? absint( $fight['rounds'] ) : 0,
			'break_duration' => array_key_exists( 'break_duration', $fight ) ? absint( $fight['break_duration'] ) : 0,
			'fight_pause' => array_key_exists( 'fight_pause', $fight ) ? absint( $fight['fight_pause'] ) : 0,
			'fight_duration' => array_key_exists( 'fight_duration', $fight ) ? absint( $fight['fight_duration'] ) : 0,
		);

		if ( $timing['fight_duration'] > 0 ) {
			if ( ! array_key_exists( 'fight_pause', $fight ) || null === $fight['fight_pause'] ) {
				$timing['fight_pause'] = $pause;
			}
			return $timing;
		}

		if ( 'category' !== ( $settings['timing_mode'] ?? 'global' ) || empty( $profiles ) ) {
			$timing['fight_duration'] = $duration;
			$timing['fight_pause'] = $pause;
			return $timing;
		}

		$category_id = absint( $fight['category_id'] ?? 0 );
		$category = null;
		if ( $category_id ) {
			foreach ( $categories as $category_row ) {
				if ( (int) ( $category_row->id ?? 0 ) === $category_id ) {
					$category = $category_row;
					break;
				}
			}
		}

		$competition_type = $competition ? CompetitionFilters::normalize_type_key( (string) ( $competition->type ?? '' ) ) : '';
		$profile = self::match_timing_profile( $profiles, $category, $competition_type, $surface_types );
		if ( ! $profile ) {
			$timing['fight_duration'] = $duration;
			$timing['fight_pause'] = $pause;
			return $timing;
		}

		$round_duration = max( 1, absint( $profile->round_duration ?? 2 ) );
		$rounds = max( 1, absint( $profile->rounds ?? 1 ) );
		$break_duration = max( 0, absint( $profile->break_duration ?? 0 ) );
		$fight_pause = max( 0, absint( $profile->fight_pause ?? 0 ) );

		$timing['timing_profile_id'] = absint( $profile->id ?? 0 );
		$timing['round_duration'] = $round_duration;
		$timing['rounds'] = $rounds;
		$timing['break_duration'] = $break_duration;
		$timing['fight_pause'] = $fight_pause;
		$timing['fight_duration'] = ( $round_duration * $rounds ) + ( $rounds > 1 ? $break_duration * ( $rounds - 1 ) : 0 );

		return $timing;
	}

	private static function match_timing_profile( array $profiles, $category, string $competition_type = '', array $surface_types = array() ) {
		if ( empty( $profiles ) ) {
			return null;
		}

		$best = null;
		$best_score = -1;
		$category_discipline = $category ? sanitize_text_field( $category->discipline ?? '' ) : '';
		$category_level = $category ? sanitize_text_field( $category->level ?? '' ) : '';
		$category_format = $category ? sanitize_text_field( $category->format ?? '' ) : '';
		$category_age_min = $category && isset( $category->age_min ) ? (int) $category->age_min : null;
		$category_age_max = $category && isset( $category->age_max ) ? (int) $category->age_max : null;
		$surface_types = array_filter( array_map( 'sanitize_key', $surface_types ) );

		foreach ( $profiles as $profile ) {
			$score = 0;
			$discipline = sanitize_text_field( $profile->discipline ?? '' );
			$profile_competition_type = sanitize_key( (string) ( $profile->competition_type ?? '' ) );
			$profile_surface_type = sanitize_key( (string) ( $profile->surface_type ?? '' ) );
			$level = sanitize_text_field( $profile->level ?? '' );
			$format = sanitize_text_field( $profile->format ?? '' );
			$age_min = isset( $profile->age_min ) ? (int) $profile->age_min : null;
			$age_max = isset( $profile->age_max ) ? (int) $profile->age_max : null;

			if ( $discipline && $category_discipline && 0 !== strcasecmp( $discipline, $category_discipline ) ) {
				continue;
			}
			if ( $profile_competition_type && ( '' === $competition_type || $profile_competition_type !== $competition_type ) ) {
				continue;
			}
			if ( $profile_surface_type && ( empty( $surface_types ) || ! in_array( $profile_surface_type, $surface_types, true ) ) ) {
				continue;
			}
			if ( $level && $category_level && 0 !== strcasecmp( $level, $category_level ) ) {
				continue;
			}
			if ( $format && $category_format && 0 !== strcasecmp( $format, $category_format ) ) {
				continue;
			}
			if ( null !== $age_min && null !== $category_age_max && $category_age_max < $age_min ) {
				continue;
			}
			if ( null !== $age_max && null !== $category_age_min && $category_age_min > $age_max ) {
				continue;
			}

			if ( $discipline ) {
				$score++;
			}
			if ( $profile_competition_type ) {
				$score++;
			}
			if ( $profile_surface_type ) {
				$score++;
			}
			if ( null !== $age_min || null !== $age_max ) {
				$score++;
			}
			if ( $level ) {
				$score++;
			}
			if ( $format ) {
				$score++;
			}

			if ( $score > $best_score ) {
				$best = $profile;
				$best_score = $score;
			}
		}

		return $best;
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

	private static function get_lock_key( int $competition_id ): string {
		return self::LOCK_PREFIX . $competition_id;
	}
}
