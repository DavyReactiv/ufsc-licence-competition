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

	private static function get_max_surfaces(): int {
		return max( 1, (int) apply_filters( 'ufsc_competition_max_surfaces', 500 ) );
	}

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

		$has_stored_settings        = ! empty( $stored );
		$settings                  = array_merge( $defaults, $stored );
		$settings['surface_count']  = min( self::get_max_surfaces(), max( 1, absint( $settings['surface_count'] ) ) );
		$settings['fight_duration']         = min( 30, max( 0, absint( $settings['fight_duration'] ) ) );
		$settings['fight_duration_seconds'] = min( 59, max( 0, absint( $settings['fight_duration_seconds'] ?? 0 ) ) );
		$settings['break_duration']         = min( 30, max( 0, absint( $settings['break_duration'] ) ) );
		$settings['break_duration_seconds'] = min( 59, max( 0, absint( $settings['break_duration_seconds'] ?? 0 ) ) );
		if ( 0 === $settings['fight_duration'] && 0 === $settings['fight_duration_seconds'] ) {
			$settings['fight_duration'] = 1;
		}
		$settings['timing_mode']    = 'category' === ( $settings['timing_mode'] ?? 'global' ) ? 'category' : 'global';
		$settings['mode']           = 'manual' === $settings['mode'] ? 'manual' : 'auto';
		$settings['auto_lock']      = ! empty( $settings['auto_lock'] ) ? 1 : 0;
		$settings['allow_unweighed'] = ! empty( $settings['allow_unweighed'] ) ? 1 : 0;
		$settings['use_level_split'] = ! empty( $settings['use_level_split'] ) ? 1 : 0;
		$settings['guardian_required_for_minors'] = ! empty( $settings['guardian_required_for_minors'] ) ? 1 : 0;
		$settings['settings_saved_at'] = sanitize_text_field( (string) ( $settings['settings_saved_at'] ?? '' ) );

		if ( ! $has_stored_settings ) {
			$competition_repo = new CompetitionRepository();
			$competition      = $competition_repo->get( $competition_id, true );
			if ( $competition ) {
				$profile_repo = new TimingProfileRepository();
				$profiles     = $profile_repo->list(
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
		} elseif ( ! array_key_exists( 'timing_mode', $stored ) ) {
			// Backward-compatible auto-enable for legacy settings saved before timing_mode existed.
			$competition_repo = new CompetitionRepository();
			$competition      = $competition_repo->get( $competition_id, true );
			if ( $competition ) {
				$profile_repo = new TimingProfileRepository();
				$profiles     = $profile_repo->list(
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
		$result = self::save_settings_with_result( $competition_id, $data );
		return ! empty( $result['ok'] );
	}

	public static function save_settings_with_result( int $competition_id, array $data ): array {
		if ( ! self::is_enabled() ) {
			return array(
				'ok'      => false,
				'message' => __( 'La génération automatique est désactivée.', 'ufsc-licence-competition' ),
				'errors'  => array( 'disabled' ),
			);
		}

		if ( ! $competition_id ) {
			return array(
				'ok'      => false,
				'message' => __( 'Compétition invalide.', 'ufsc-licence-competition' ),
				'errors'  => array( 'competition_id' ),
			);
		}

		$settings = self::get_default_settings();
		$errors   = array();

		if ( isset( $data['plateau_name'] ) ) {
			$settings['plateau_name'] = sanitize_text_field( (string) $data['plateau_name'] );
		}
		if ( isset( $data['surface_count'] ) ) {
			if ( ! is_scalar( $data['surface_count'] ) || ! is_numeric( (string) $data['surface_count'] ) ) {
				$errors[] = 'surface_count';
			}
			$settings['surface_count'] = min( self::get_max_surfaces(), max( 1, absint( $data['surface_count'] ) ) );
		}

		$settings['surface_details'] = isset( $data['surface_details'] ) && is_array( $data['surface_details'] )
			? $data['surface_details']
			: array();

		if ( isset( $data['fight_duration'] ) ) {
			if ( ! is_scalar( $data['fight_duration'] ) || ! is_numeric( (string) $data['fight_duration'] ) ) {
				$errors[] = 'fight_duration';
			}
			$settings['fight_duration'] = min( 30, max( 0, absint( $data['fight_duration'] ) ) );
		}
		if ( isset( $data['fight_duration_seconds'] ) ) {
			if ( ! is_scalar( $data['fight_duration_seconds'] ) || ! is_numeric( (string) $data['fight_duration_seconds'] ) ) {
				$errors[] = 'fight_duration_seconds';
			}
			$settings['fight_duration_seconds'] = min( 59, max( 0, absint( $data['fight_duration_seconds'] ) ) );
		}
		if ( isset( $data['break_duration'] ) ) {
			if ( ! is_scalar( $data['break_duration'] ) || ! is_numeric( (string) $data['break_duration'] ) ) {
				$errors[] = 'break_duration';
			}
			$settings['break_duration'] = min( 30, max( 0, absint( $data['break_duration'] ) ) );
		}
		if ( isset( $data['break_duration_seconds'] ) ) {
			if ( ! is_scalar( $data['break_duration_seconds'] ) || ! is_numeric( (string) $data['break_duration_seconds'] ) ) {
				$errors[] = 'break_duration_seconds';
			}
			$settings['break_duration_seconds'] = min( 59, max( 0, absint( $data['break_duration_seconds'] ) ) );
		}
		if ( isset( $data['timing_mode'] ) ) {
			$raw_timing_mode = is_scalar( $data['timing_mode'] ) ? sanitize_key( (string) $data['timing_mode'] ) : '';
			if ( ! in_array( $raw_timing_mode, array( 'global', 'category' ), true ) ) {
				$errors[] = 'timing_mode';
			}
			$settings['timing_mode'] = 'category' === $raw_timing_mode ? 'category' : 'global';
		}
		if ( isset( $data['mode'] ) ) {
			$raw_mode = is_scalar( $data['mode'] ) ? sanitize_key( (string) $data['mode'] ) : '';
			if ( ! in_array( $raw_mode, array( 'auto', 'manual' ), true ) ) {
				$errors[] = 'mode';
			}
			$settings['mode'] = 'manual' === $raw_mode ? 'manual' : 'auto';
		}
		if ( isset( $data['auto_lock'] ) ) {
			$settings['auto_lock'] = ! empty( $data['auto_lock'] ) ? 1 : 0;
		}
		if ( isset( $data['allow_unweighed'] ) ) {
			$settings['allow_unweighed'] = ! empty( $data['allow_unweighed'] ) ? 1 : 0;
		}
		if ( isset( $data['use_level_split'] ) ) {
			$settings['use_level_split'] = ! empty( $data['use_level_split'] ) ? 1 : 0;
		}
		if ( isset( $data['guardian_required_for_minors'] ) ) {
			$settings['guardian_required_for_minors'] = ! empty( $data['guardian_required_for_minors'] ) ? 1 : 0;
		}
		$settings['group_generation_options'] = array();
		if ( isset( $data['group_generation_options'] ) && is_array( $data['group_generation_options'] ) ) {
			foreach ( $data['group_generation_options'] as $group_key => $row ) {
				$format = sanitize_key( (string) ( $row['format'] ?? 'auto' ) );
				$settings['group_generation_options'][ sanitize_text_field( (string) $group_key ) ] = array( 'format' => $format );
			}
		}

		$settings['surface_details'] = self::sanitize_surface_details( $settings['surface_details'], $settings['surface_count'] );
		if ( empty( $settings['surface_details'] ) ) {
			$settings['surface_details'] = self::sanitize_surface_details( array(), $settings['surface_count'] );
		}
		if ( 0 === $settings['fight_duration'] && 0 === $settings['fight_duration_seconds'] ) {
			$errors[] = 'fight_duration_total';
		}
		if ( ! empty( $errors ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'Paramètres invalides : corrigez les champs timing/surfaces puis réessayez.', 'ufsc-licence-competition' ),
				'errors'  => array_values( array_unique( $errors ) ),
			);
		}
		$settings['settings_saved_at'] = current_time( 'mysql' );
		$option_key = self::SETTINGS_PREFIX . $competition_id;
		$existing   = get_option( $option_key, null );
		$updated    = update_option( $option_key, $settings, false );
		if ( ! $updated && $existing === $settings ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'UFSC FightAutoGenerationService settings_unchanged_but_valid ' . wp_json_encode( array( 'competition_id' => $competition_id ) ) );
			}
			return array(
				'ok'      => true,
				'message' => '',
				'errors'  => array(),
			);
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log(
				'UFSC FightAutoGenerationService settings_saved ' . wp_json_encode(
					array(
						'competition_id' => $competition_id,
						'updated'        => (bool) $updated,
						'surface_count'  => (int) $settings['surface_count'],
						'surface_types'  => self::get_surface_types_from_settings( $settings ),
					)
				)
			);
		}

		return array(
			'ok'      => (bool) $updated,
			'message' => (bool) $updated ? '' : __( 'Échec de sauvegarde des paramètres.', 'ufsc-licence-competition' ),
			'errors'  => array(),
		);
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
				'total_entries'          => 0,
				'eligible_entries'       => 0,
				'excluded_unweighed'     => 0,
				'can_override_unweighed' => false,
			);
		}

		$competition_repo = new CompetitionRepository();
		$competition      = $competition_repo->get( $competition_id, true );
		if ( ! $competition ) {
			return array(
				'total_entries'          => 0,
				'eligible_entries'       => 0,
				'excluded_unweighed'     => 0,
				'can_override_unweighed' => false,
			);
		}

		$entry_repo = new EntryRepository();
		$entries    = $entry_repo->list_with_details( array( 'view' => 'all', 'competition_id' => $competition_id ), 2000, 0 );

		$selection = self::select_eligible_entries( $entries, $competition_id, $competition, $settings );

		return array(
			'total_entries'          => count( $entries ),
			'eligible_entries'       => count( $selection['valid_entries'] ),
			'excluded_unweighed'     => (int) ( $selection['excluded_unweighed'] ?? 0 ),
			'can_override_unweighed' => ! empty( $selection['enforce_weighin'] ) && (int) ( $selection['excluded_unweighed'] ?? 0 ) > 0,
			'diagnostics'            => self::build_rejection_diagnostics( $entries, $selection ),
		);
	}

	public static function get_generation_preview( int $competition_id, array $settings ): array {
		$preview = array(
			'estimated_fights'            => 0,
			'estimated_categories'        => 0,
			'surface_count'               => max( 1, absint( $settings['surface_count'] ?? 1 ) ),
			'estimated_total_seconds'     => 0,
			'estimated_per_surface'       => array(),
			'eligible_entries'            => 0,
			'excluded_unweighed'          => 0,
			'duplicate_fighter_numbers'   => 0,
			'can_generate'                => false,
			'precheck'                    => array(),
			'rejection_diagnostics'       => array(),
			'groups_preview'              => array(),
		);

		if ( ! $competition_id ) {
			return $preview;
		}

		$competition_repo = new CompetitionRepository();
		$competition      = $competition_repo->get( $competition_id, true );
		if ( ! $competition ) {
			return $preview;
		}

		$entry_repo = new EntryRepository();
		$entries    = $entry_repo->list_with_details( array( 'view' => 'all', 'competition_id' => $competition_id ), 2000, 0 );
		$selection  = self::select_eligible_entries( $entries, $competition_id, $competition, $settings );
		$eligible   = (array) ( $selection['valid_entries'] ?? array() );
		$groups     = self::group_entries_by_category( $eligible, $settings );
		$group_stats = self::summarize_generation_groups( $groups );
		if ( ! $groups ) {
			$preview['eligible_entries']   = count( $eligible );
			$preview['excluded_unweighed'] = (int) ( $selection['excluded_unweighed'] ?? 0 );
			$preview['rejection_diagnostics'] = self::build_rejection_diagnostics( $entries, $selection );
			$preview['group_diagnostics'] = $group_stats;
			return $preview;
		}

		$estimated_fights = 0;
		foreach ( $groups as $group_entries ) {
			$estimated_fights += self::estimate_fights_for_group_size( count( $group_entries ) );
		}

		$surface_count = max( 1, absint( $settings['surface_count'] ?? 1 ) );
		$fight_seconds = self::get_global_fight_seconds( $settings );
		$pause_seconds = self::get_global_pause_seconds( $settings );
		$step_seconds  = $fight_seconds + $pause_seconds;

		$per_surface = array();
		for ( $i = 0; $i < $surface_count; $i++ ) {
			$bucket = (int) floor( $estimated_fights / $surface_count ) + ( $i < ( $estimated_fights % $surface_count ) ? 1 : 0 );
			$per_surface[] = $bucket;
		}

		$duplicates = self::count_duplicate_fighter_numbers( $entries );
		$timing_ok  = $fight_seconds > 0;
		$surfaces_ok = $surface_count > 0 && count( self::normalize_surface_details( $settings ) ) >= $surface_count;
		$eligible_ok = count( $eligible ) >= 2;

		$preview['estimated_fights']          = $estimated_fights;
		$preview['estimated_categories']      = count( $groups );
		$preview['estimated_total_seconds']   = $estimated_fights * $step_seconds;
		$preview['estimated_per_surface']     = $per_surface;
		$preview['groups_generable']          = (int) ( $group_stats['generable_groups'] ?? 0 );
		$preview['groups_insufficient']       = (int) ( $group_stats['insufficient_groups'] ?? 0 );
		$preview['isolated_participants']     = (int) ( $group_stats['isolated_participants'] ?? 0 );
		$preview['odd_groups']                = (int) ( $group_stats['odd_groups'] ?? 0 );
		$preview['group_diagnostics']         = $group_stats;
		$preview['eligible_entries']          = count( $eligible );
		$preview['excluded_unweighed']        = (int) ( $selection['excluded_unweighed'] ?? 0 );
		$preview['duplicate_fighter_numbers'] = $duplicates;
		$preview['can_generate']              = $surfaces_ok && $timing_ok && $eligible_ok && empty( $settings['auto_lock'] ) && 'manual' !== ( $settings['mode'] ?? 'auto' );
		$preview['rejection_diagnostics']     = self::build_rejection_diagnostics( $entries, $selection );
		$preview['precheck']                  = array(
			'surfaces_ok' => $surfaces_ok,
			'timing_ok'   => $timing_ok,
			'eligible_ok' => $eligible_ok,
			'duplicates_ok' => 0 === $duplicates,
		);
		$preview['groups_preview']            = self::build_groups_preview( $groups, $settings );

		return $preview;
	}

	public static function generate_draft( int $competition_id, array $settings ): array {
		if ( ! self::is_enabled() ) {
			return array(
				'ok'      => false,
				'message' => __( 'La génération automatique est désactivée.', 'ufsc-licence-competition' ),
			);
		}

		if ( ! $competition_id ) {
			return array(
				'ok'      => false,
				'message' => __( 'Compétition invalide.', 'ufsc-licence-competition' ),
			);
		}

		if ( 'manual' === ( $settings['mode'] ?? 'auto' ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'Mode manuel actif : activez le mode automatique pour générer.', 'ufsc-licence-competition' ),
			);
		}

		if ( ! empty( $settings['auto_lock'] ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'La génération automatique est verrouillée.', 'ufsc-licence-competition' ),
			);
		}

		$lock_key = self::get_lock_key( $competition_id );
		if ( get_transient( $lock_key ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'Génération déjà en cours, veuillez patienter.', 'ufsc-licence-competition' ),
			);
		}

		set_transient( $lock_key, 1, self::LOCK_TTL );

			try {
				$competition_repo = new CompetitionRepository();
				$competition      = $competition_repo->get( $competition_id, true );
			if ( ! $competition ) {
				return array(
					'ok'      => false,
					'message' => __( 'Compétition introuvable.', 'ufsc-licence-competition' ),
				);
				}

				$fight_repo         = new FightRepository();
				$regeneration_scope = $fight_repo->can_regenerate_scope( $competition_id );
				if ( empty( $regeneration_scope['allowed'] ) ) {
					$blocking_count = (int) ( $regeneration_scope['blocking_count'] ?? 0 );
					( new LogService() )->log(
						'generation_blocked_sensitive_scope',
						'fight',
						$competition_id,
						'Génération bloquée : combats sensibles détectés.',
						array(
							'blocking_count' => $blocking_count,
							'reason'         => (string) ( $regeneration_scope['reason'] ?? '' ),
						)
					);
					return array(
						'ok'      => false,
						'message' => sprintf(
							/* translators: %d: sensitive fights count */
							__( 'Génération bloquée : %d combat(s) en cours/terminé(s) ou avec résultat existent déjà dans ce périmètre. Utilisez le workflow d’actions sensibles.', 'ufsc-licence-competition' ),
							$blocking_count
						),
					);
				}

				$existing_fights = self::get_existing_generation_blockers( $competition_id );
				if ( (int) $existing_fights['total'] > 0 ) {
				return array(
					'ok'      => false,
					'message' => sprintf(
						/* translators: 1: draft fights count, 2: scheduled fights count */
						__( 'Génération bloquée : des combats existent déjà (draft: %1$d, scheduled: %2$d). Utilisez l’action de régénération dédiée ou validez/supprimez les combats existants.', 'ufsc-licence-competition' ),
						(int) $existing_fights['draft'],
						(int) $existing_fights['scheduled']
					),
					'stats'   => array(
						'existing_fights' => (int) $existing_fights['total'],
					),
				);
			}

			$entry_repo = new EntryRepository();
			$entries    = $entry_repo->list_with_details( array( 'view' => 'all', 'competition_id' => $competition_id ), 2000, 0 );

			$total_entries = count( $entries );
			if ( 0 === $total_entries ) {
				return array(
					'ok'      => false,
					'message' => __( 'Aucune inscription approuvée trouvée pour cette compétition.', 'ufsc-licence-competition' ),
				);
			}

			$selection         = self::select_eligible_entries( $entries, $competition_id, $competition, $settings );
			$valid_entries     = $selection['valid_entries'];
			$ineligible_reasons = $selection['ineligible_reasons'];
			$reason_counts      = (array) ( $selection['reason_counts'] ?? array() );
			$excluded_unweighed = (int) ( $selection['excluded_unweighed'] ?? 0 );

			if ( ! $valid_entries ) {
				$message = self::build_generation_rejection_message( $total_entries, $reason_counts, 0 );

				return array(
					'ok'      => false,
					'message' => $message,
					'stats'   => array(
						'total_entries'      => $total_entries,
						'eligible_entries'   => 0,
						'rejected_entries'   => $total_entries,
						'excluded_unweighed' => $excluded_unweighed,
						'reason_counts'      => $reason_counts,
					),
				);
			}
			$duplicate_fighters = self::count_duplicate_fighter_numbers( $valid_entries );
			if ( $duplicate_fighters > 0 ) {
				return array(
					'ok'      => false,
					'message' => sprintf(
						/* translators: %d: number of duplicate fighter-number conflicts */
						__( 'Génération bloquée : %d conflit(s) de numéros combattants détecté(s). Corrigez les doublons avant de relancer.', 'ufsc-licence-competition' ),
						$duplicate_fighters
					),
					'stats'   => array(
						'duplicate_fighter_numbers' => $duplicate_fighters,
						'total_entries'             => $total_entries,
						'eligible_entries'          => count( $valid_entries ),
					),
				);
			}

			$category_repo = new CategoryRepository();
			$categories    = $category_repo->list( array( 'view' => 'all', 'competition_id' => $competition_id ), 500, 0 );
			$normalized_categories = self::normalize_categories( $categories );

			$assigner  = new CategoryAssigner();
			$groups    = array();
			$warnings  = array();

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

				$entry->category_id = $category_id;
				$group_key = self::get_generation_category_key( $entry, $settings );
				if ( '' === $group_key ) {
					$warnings[] = sprintf(
						__( 'Entrée #%d non affectée : clé de groupe incomplète.', 'ufsc-licence-competition' ),
						(int) ( $entry->id ?? 0 )
					);
					continue;
				}

				$draft_group_key = $category_id . '|' . $group_key;
				if ( ! isset( $groups[ $draft_group_key ] ) ) {
					$groups[ $draft_group_key ] = array();
				}
				$groups[ $draft_group_key ][] = $entry;
			}

			if ( ! $groups ) {
				return array(
					'ok'      => false,
					'message' => __( 'Aucune catégorie exploitable pour générer des combats.', 'ufsc-licence-competition' ),
				);
			}

			$groups = self::sort_groups_for_generation( $groups, $normalized_categories );

			$next_fight_no = $fight_repo->get_max_fight_no( $competition_id ) + 1;

			$fights         = array();
			$total_bye_slots = 0;
			$ignored_groups = 0;
			foreach ( $groups as $draft_group_key => $group_entries ) {
				$group_entries = self::sort_entries_for_generation( $group_entries );
				$category_id = ! empty( $group_entries ) ? absint( $group_entries[0]->category_id ?? 0 ) : 0;
				if ( $category_id <= 0 ) {
					$category_id = absint( strtok( (string) $draft_group_key, '|' ) );
				}
				$group_key = self::get_generation_category_key( $group_entries[0] ?? null, $settings );
				$generated = self::build_fights_for_group( $competition_id, $category_id, $group_entries, $next_fight_no, $group_key );
				$fights    = array_merge( $fights, $generated['fights'] );
				$next_fight_no = $generated['next_no'];
				$total_bye_slots += (int) ( $generated['bye_slots'] ?? 0 );
				if ( ! empty( $generated['warnings'] ) && is_array( $generated['warnings'] ) ) {
					$warnings = array_merge( $warnings, $generated['warnings'] );
				}
				if ( count( $group_entries ) < 2 ) {
					$ignored_groups++;
				}
			}

			$fights = self::assign_surfaces_and_schedule( $fights, $settings, (int) $competition_id );

			$fights = self::normalize_preview_fights( $fights );
			$stats = array(
				'entries'           => count( $valid_entries ),
				'groups'            => count( $groups ),
				'fights'            => count( $fights ),
				'total_entries'     => $total_entries,
				'eligible_entries'  => count( $valid_entries ),
				'excluded_unweighed' => $excluded_unweighed,
				'bye_slots'         => $total_bye_slots,
				'ignored_groups'    => $ignored_groups,
				'reason_counts'      => $reason_counts,
			);
			$full_preview = self::get_generation_preview( $competition_id, $settings );
			$excluded_entries = array();
			if ( ! empty( $selection['rejected_entries'] ) && is_array( $selection['rejected_entries'] ) ) {
				foreach ( $selection['rejected_entries'] as $rejected ) {
					$reasons = array_values( array_filter( array_map( 'sanitize_key', (array) ( $rejected['reasons'] ?? array() ) ) ) );
					$excluded_entries[] = array(
						'entry_id'          => (int) ( $rejected['entry_id'] ?? 0 ),
						'first_name'        => sanitize_text_field( (string) ( $rejected['first_name'] ?? '' ) ),
						'last_name'         => sanitize_text_field( (string) ( $rejected['last_name'] ?? '' ) ),
						'club_name'         => sanitize_text_field( (string) ( $rejected['club_name'] ?? '' ) ),
						'category'          => sanitize_text_field( (string) ( $rejected['category'] ?? '' ) ),
						'primary_reason'    => (string) ( $reasons[0] ?? 'other_reason' ),
						'reasons'           => $reasons,
						'recommended_action'=> self::recommended_action_for_rejection_reason( (string) ( $reasons[0] ?? '' ) ),
					);
				}
			}

			$draft = array(
				'draft_id'        => 'draft_' . $competition_id . '_' . wp_generate_uuid4(),
				'competition_id' => $competition_id,
				'generated_at'   => current_time( 'mysql' ),
				'created_at'     => current_time( 'mysql' ),
				'generated_by'   => get_current_user_id() ?: null,
				'created_by'     => get_current_user_id() ?: null,
				'settings'       => $settings,
				'stats'          => $stats,
				'summary'        => $stats,
				'warnings'       => $warnings,
				'groups'         => self::enrich_groups_preview_from_fights(
					isset( $full_preview['groups_preview'] ) && is_array( $full_preview['groups_preview'] ) ? $full_preview['groups_preview'] : array(),
					$fights
				),
				'fights_preview' => $fights,
				'fights'         => $fights,
				'surfaces'       => self::normalize_surface_details( $settings ),
				'excluded_entries' => $excluded_entries,
			);
			$draft['diagnostic_hash'] = class_exists( GenerationReadinessDiagnostic::class ) ? GenerationReadinessDiagnostic::hash_draft( $full_preview ) : hash( 'sha256', wp_json_encode( $full_preview ) ?: '' );
			$draft['draft_hash'] = class_exists( GenerationReadinessDiagnostic::class ) ? GenerationReadinessDiagnostic::hash_draft( $draft ) : hash( 'sha256', wp_json_encode( $draft ) ?: '' );

			self::save_draft( $competition_id, $draft );
			( new LogService() )->audit( 'generation_preview_created', $competition_id, 'competition', $competition_id, array( 'draft_hash' => $draft['draft_hash'], 'stats' => $stats ) );

			$message = __( 'Pré-génération terminée. Validez pour enregistrer définitivement.', 'ufsc-licence-competition' );
			if ( 0 === count( $fights ) ) {
				$message = self::build_generation_rejection_message( $total_entries, $reason_counts, count( $valid_entries ), $ignored_groups );
			}

			return array(
				'ok'      => true,
				'message' => $message,
				'draft'   => $draft,
			);
		} finally {
			delete_transient( $lock_key );
		}
	}

	private static function recommended_action_for_rejection_reason( string $reason ): string {
		switch ( sanitize_key( $reason ) ) {
			case 'missing_weight':
			case 'weight_missing':
			case 'weight_class_missing':
				return 'Corriger le poids déclaré, la catégorie de poids ou valider la pesée.';
			case 'category_unresolved':
				return 'Renseigner ou recalculer la catégorie sportive avant génération.';
			case 'discipline_missing':
				return 'Renseigner la discipline du combattant.';
			case 'missing_sex':
			case 'sex_missing':
				return 'Renseigner le sexe du combattant.';
			case 'missing_birthdate':
			case 'birthdate_missing':
				return 'Renseigner la date de naissance.';
			case 'level_missing':
				return 'Renseigner le niveau/la classe ou désactiver la séparation par niveau.';
			case 'weighin_missing':
				return 'Saisir une pesée valide ou activer allow_unweighed en Sandbox.';
			case 'duplicate_fighter_number':
				return 'Résoudre le doublon de numéro combattant.';
			default:
				return 'Corriger les données de l’inscription puis régénérer le brouillon.';
		}
	}

	public static function validate_and_apply_draft( int $competition_id, string $apply_mode = 'append' ): array {
		$diagnostic = array(
			'success'              => false,
			'competition_id'       => $competition_id,
			'entries_found'        => 0,
			'entries_approved'     => 0,
			'eligible_entries'     => 0,
			'blocked_entries'      => 0,
			'warnings_count'       => 0,
			'groups_created'       => 0,
			'groups_rejected'      => 0,
			'estimated_fights'     => 0,
			'attempted_inserts'    => 0,
			'successful_inserts'   => 0,
			'failed_inserts'       => 0,
			'byes_inserted'        => 0,
			'placeholders_inserted'=> 0,
			'surfaces_received'    => 0,
			'surfaces_active'      => 0,
			'surfaces_used'        => 0,
			'assigned_fights'      => 0,
			'last_sql_error'       => '',
			'errors'               => array(),
			'warnings'             => array(),
		);
		if ( ! self::is_enabled() ) {
			return array(
				'ok'      => false,
				'message' => __( 'La génération automatique est désactivée.', 'ufsc-licence-competition' ),
				'diagnostic' => $diagnostic,
			);
		}

		if ( ! $competition_id ) {
			return array(
				'ok'      => false,
				'message' => __( 'Compétition invalide.', 'ufsc-licence-competition' ),
				'diagnostic' => $diagnostic,
			);
		}

		$safety = class_exists( CompetitionSafetyService::class ) ? ( new CompetitionSafetyService() )->guard_fight_generation( $competition_id, 'validate_fight_draft', array( 'apply_mode' => $apply_mode ) ) : array( 'ok' => true );
		if ( empty( $safety['ok'] ) ) {
			return array(
				'ok' => false,
				'message' => (string) ( $safety['message'] ?? __( 'Validation bloquée par la protection des données réelles.', 'ufsc-licence-competition' ) ),
				'diagnostic' => $diagnostic,
			);
		}

		$apply_mode = 'replace' === $apply_mode ? 'replace' : 'append';
		if ( 'replace' === $apply_mode ) {
			return array(
				'ok'      => false,
				'message' => __( 'Le mode remplacement n’est pas disponible.', 'ufsc-licence-competition' ),
			);
		}

		$draft = self::get_draft( $competition_id );
		$diagnostic['estimated_fights'] = is_array( $draft['fights'] ?? null ) ? count( $draft['fights'] ) : 0;
		$diagnostic['warnings_count'] = is_array( $draft['warnings'] ?? null ) ? count( $draft['warnings'] ) : 0;
		if ( empty( $draft['fights'] ) || ! is_array( $draft['fights'] ) ) {
			( new LogService() )->audit( 'generation_blocked', $competition_id, 'competition', $competition_id, array( 'reason' => 'missing_draft' ) );
			return array(
				'ok'      => false,
				'message' => __( 'Aucun brouillon disponible : générez et vérifiez une prévisualisation avant validation.', 'ufsc-licence-competition' ),
			);
		}

		$settings = self::get_settings( $competition_id );
		if ( ! empty( $draft['settings'] ) && is_array( $draft['settings'] ) ) {
			$settings = array_merge( $settings, $draft['settings'] );
		}
		$readiness = class_exists( GenerationReadinessDiagnostic::class ) ? GenerationReadinessDiagnostic::check( $competition_id, $settings, $draft ) : array( 'blocking' => false, 'errors' => array(), 'warnings' => array(), 'summary' => array() );
		if ( ! empty( $readiness['blocking'] ) ) {
			( new LogService() )->audit( 'generation_blocked', $competition_id, 'competition', $competition_id, array( 'reason' => 'readiness_diagnostic', 'diagnostic' => $readiness ) );
			return array(
				'ok'      => false,
				'message' => self::format_readiness_errors( $readiness ),
				'diagnostic' => $diagnostic,
				'readiness' => $readiness,
			);
		}

		$validation = self::validate_draft( $draft );
		if ( ! $validation['ok'] ) {
			( new LogService() )->audit( 'generation_blocked', $competition_id, 'competition', $competition_id, array( 'reason' => 'draft_validation_failed', 'validation' => $validation ) );
			return $validation;
		}

		$snapshot_id = class_exists( GenerationSnapshotService::class ) ? ( new GenerationSnapshotService() )->create_snapshot( $competition_id, 'before_generation_apply', array( 'draft_hash' => (string) ( $draft['draft_hash'] ?? '' ) ) ) : '';
		if ( '' === $snapshot_id ) {
			( new LogService() )->audit( 'generation_blocked', $competition_id, 'competition', $competition_id, array( 'reason' => 'snapshot_failed' ) );
			return array(
				'ok'      => false,
				'message' => __( 'Génération bloquée : impossible de créer le snapshot de sécurité.', 'ufsc-licence-competition' ),
				'diagnostic' => $diagnostic,
			);
		}
		( new LogService() )->audit( 'generation_draft_validated', $competition_id, 'competition', $competition_id, array( 'snapshot_id' => $snapshot_id, 'draft_hash' => (string) ( $draft['draft_hash'] ?? '' ) ) );

		$fight_repo    = new FightRepository();
		$next_fight_no = $fight_repo->get_max_fight_no( $competition_id ) + 1;

		$prepared_fights = array();
		foreach ( $draft['fights'] as $fight ) {
			$fight['competition_id'] = $competition_id;
			$fight['fight_no']       = $next_fight_no;
			$prepared_fights[]       = $fight;
			$next_fight_no++;
		}

		$attempted = count( $prepared_fights );
		$inserted  = 0;
		$inserted_ids = array();
		$table     = Db::fights_table();
		$diagnostic['attempted_inserts'] = $attempted;

		foreach ( $prepared_fights as $fight ) {
			$insert_id = (int) $fight_repo->insert( $fight );
			if ( $insert_id <= 0 ) {
				global $wpdb;
				$last_error = isset( $wpdb->last_error ) ? (string) $wpdb->last_error : '';
				$diagnostic['last_sql_error'] = $last_error;
				$diagnostic['failed_inserts'] = max( 1, $attempted - $inserted );
				$diagnostic['errors'][] = $last_error ?: 'sql_insert_failed';
				$columns = implode( ', ', array_keys( $fight ) );
				$rolled_back = self::rollback_inserted_fights( $inserted_ids, $competition_id, $snapshot_id );
				( new LogService() )->audit( 'generation_failed', $competition_id, 'competition', $competition_id, array( 'snapshot_id' => $snapshot_id, 'error' => $last_error, 'inserted_ids' => $inserted_ids, 'rolled_back' => $rolled_back ) );

				return array(
					'ok'      => false,
					'message' => sprintf(
						/* translators: 1: table name, 2: SQL error, 3: competition id, 4: attempted inserts, 5: successful inserts, 6: columns */
						__( 'Échec insertion SQL. Rollback ciblé exécuté. Table: %1$s | SQL: %2$s | competition_id: %3$d | inserts tentés: %4$d | inserts réussis: %5$d | colonnes: %6$s', 'ufsc-licence-competition' ),
						$table,
						$last_error ?: 'n/a',
						$competition_id,
						$attempted,
						$inserted,
						$columns
					),
					'diagnostic' => $diagnostic,
				);
			}

			$inserted++;
			$inserted_ids[] = $insert_id;
		}
		$diagnostic['successful_inserts'] = $inserted;
		$diagnostic['failed_inserts'] = max( 0, $attempted - $inserted );
		$diagnostic['success'] = $inserted > 0;
		if ( $diagnostic['estimated_fights'] > 0 && 0 === $inserted ) {
			$diagnostic['success'] = false;
			$diagnostic['errors'][] = 'estimated_but_not_inserted';
		}
		if ( function_exists( 'ufsc_competition_assign_surfaces_and_times' ) ) {
			$assignment = ufsc_competition_assign_surfaces_and_times( $competition_id, array(), $settings );
			$diagnostic['assigned_fights'] = (int) ( $assignment['assigned_fights'] ?? 0 );
			$diagnostic['surfaces_active'] = (int) ( $assignment['surfaces_active'] ?? 0 );
			$diagnostic['surfaces_used'] = (int) ( $assignment['surfaces_used'] ?? 0 );
			if ( ! empty( $assignment['skipped_sensitive'] ) ) {
				$diagnostic['warnings'][] = 'skipped_sensitive=' . (int) $assignment['skipped_sensitive'];
			}
			if ( ! empty( $assignment['last_sql_error'] ) ) {
				$diagnostic['last_sql_error'] = (string) $assignment['last_sql_error'];
			}
		}
		update_option( 'ufsc_competition_last_generation_diagnostic_' . $competition_id, array(
			'timestamp' => current_time( 'mysql' ),
			'competition_id' => $competition_id,
			'estimated_fights' => (int) $diagnostic['estimated_fights'],
			'successful_inserts' => (int) $diagnostic['successful_inserts'],
			'assigned_fights' => (int) ( $diagnostic['assigned_fights'] ?? 0 ),
			'errors' => (array) $diagnostic['errors'],
			'warnings' => (array) $diagnostic['warnings'],
		), false );

		self::clear_draft( $competition_id );
		if ( class_exists( GenerationLockService::class ) ) {
			GenerationLockService::lock_after_generation( $competition_id, array( 'snapshot_id' => $snapshot_id, 'inserted' => $inserted ) );
		}
		( new LogService() )->audit( 'generation_applied', $competition_id, 'competition', $competition_id, array( 'snapshot_id' => $snapshot_id, 'inserted_ids' => $inserted_ids, 'inserted' => $inserted ) );

		return array(
			'ok'      => true,
			'message' => sprintf(
				/* translators: %d: created fights count */
				__( '%d combats créés et planifiés.', 'ufsc-licence-competition' ),
				$inserted
			),
			'stats'    => array(
				'inserts_attempted' => $attempted,
				'inserts_success'   => $inserted,
			),
			'diagnostic' => $diagnostic,
		);
	}


	private static function format_readiness_errors( array $readiness ): string {
		$messages = array();
		foreach ( (array) ( $readiness['errors'] ?? array() ) as $error ) {
			if ( is_array( $error ) && ! empty( $error['message'] ) ) {
				$messages[] = sanitize_text_field( (string) $error['message'] );
			}
		}
		if ( empty( $messages ) ) {
			return __( 'Génération bloquée par le diagnostic de sécurité.', 'ufsc-licence-competition' );
		}
		return __( 'Génération bloquée :', 'ufsc-licence-competition' ) . ' ' . implode( ' | ', $messages );
	}

	private static function rollback_inserted_fights( array $inserted_ids, int $competition_id, string $snapshot_id = '' ): int {
		$fight_repo = new FightRepository();
		$rolled_back = 0;
		foreach ( array_filter( array_map( 'absint', $inserted_ids ) ) as $fight_id ) {
			$fight = $fight_repo->get( $fight_id, true );
			if ( ! $fight || (int) ( $fight->competition_id ?? 0 ) !== absint( $competition_id ) ) {
				continue;
			}
			$deleted = $fight_repo->soft_delete( $fight_id );
			if ( false !== $deleted ) {
				$rolled_back++;
			}
		}
		if ( $rolled_back > 0 ) {
			( new LogService() )->audit( 'generation_soft_rolled_back', $competition_id, 'competition', $competition_id, array( 'snapshot_id' => $snapshot_id, 'soft_rolled_back_ids' => $inserted_ids, 'soft_rolled_back_count' => $rolled_back ) );
		}
		return $rolled_back;
	}

	/**
	 * Legacy direct-generation fallback kept for tracked sandbox fixtures only.
	 *
	 * @deprecated Use generate_draft() then validate_and_apply_draft() for every real competition.
	 */
	public static function generate_simple_pairing_fights( int $competition_id, array $settings = array() ): array {
		$competition_id = absint( $competition_id );
		$logger         = new LogService();
		$blocked        = static function ( string $reason, string $message, array $context = array() ) use ( $competition_id, $logger ): array {
			$logger->audit(
				'generation_blocked',
				$competition_id,
				'competition',
				$competition_id,
				array_merge( array( 'reason' => $reason, 'source' => 'generate_simple_pairing_fights' ), $context )
			);

			return array(
				'ok'      => false,
				'message' => $message,
				'reason'  => $reason,
			);
		};

		if ( $competition_id <= 0 ) {
			return $blocked(
				'competition_id_invalid',
				__( 'Génération directe bloquée : compétition invalide.', 'ufsc-licence-competition' )
			);
		}

		$competition_repo = new CompetitionRepository();
		$competition      = $competition_repo->get( $competition_id, true );
		if ( ! $competition ) {
			return $blocked(
				'competition_not_found',
				__( 'Génération directe bloquée : compétition introuvable.', 'ufsc-licence-competition' )
			);
		}

		$competition_name = trim( (string) ( $competition->name ?? '' ) );
		$is_test_competition = 1 === preg_match( '/^\s*\[TEST\]/i', $competition_name );
		$context             = sanitize_key( (string) ( $settings['direct_generation_context'] ?? '' ) );
		if ( empty( $settings['sandbox_generation'] ) || 'test_fixture' !== $context || ! $is_test_competition ) {
			return $blocked(
				'direct_fallback_test_fixture_only',
				__( 'Génération directe bloquée : utilisez le workflow brouillon puis validation. Le fallback direct est réservé aux fixtures [TEST].', 'ufsc-licence-competition' ),
				array( 'competition_name' => $competition_name, 'direct_generation_context' => $context )
			);
		}

		if ( class_exists( GenerationLockService::class ) && GenerationLockService::is_generation_locked( $competition_id ) ) {
			return $blocked(
				'generation_locked',
				__( 'Génération directe bloquée : la génération est verrouillée pour cette compétition.', 'ufsc-licence-competition' ),
				array( 'competition_name' => $competition_name )
			);
		}

		if ( class_exists( CompetitionSafetyService::class ) ) {
			$safety = ( new CompetitionSafetyService() )->guard_fight_generation(
				$competition_id,
				'direct_simple_pairing_fallback',
				array( 'competition_name' => $competition_name, 'direct_generation_context' => $context )
			);
			if ( empty( $safety['ok'] ) ) {
				return $blocked(
					(string) ( $safety['reason'] ?? 'competition_safety_blocked' ),
					(string) ( $safety['message'] ?? __( 'Génération directe bloquée par les protections compétition.', 'ufsc-licence-competition' ) ),
					array( 'competition_name' => $competition_name )
				);
			}
		}

		$fight_repo = new FightRepository();
		$regeneration_scope = $fight_repo->can_regenerate_scope( $competition_id );
		$existing_fights = self::get_existing_generation_blockers( $competition_id );
		if ( empty( $regeneration_scope['allowed'] ) || (int) ( $existing_fights['total'] ?? 0 ) > 0 ) {
			return $blocked(
				'direct_fallback_existing_or_sensitive_fights',
				__( 'Fallback simple pairing bloqué : des combats existent déjà pour cette compétition.', 'ufsc-licence-competition' ),
				array(
					'existing_fights' => (int) ( $existing_fights['total'] ?? 0 ),
					'regeneration_scope' => $regeneration_scope,
				)
			);
		}

		$entry_repo = new EntryRepository();
		$entries = $entry_repo->list_with_details( array( 'view' => 'all', 'competition_id' => $competition_id ), 5000, 0 );
		$groups = array();
		$lone  = array();
		foreach ( $entries as $entry ) {
			if ( ! empty( $entry->deleted_at ) ) {
				continue;
			}
			$evaluation = function_exists( 'ufsc_competition_evaluate_entry_eligibility' )
				? ufsc_competition_evaluate_entry_eligibility( $entry, array( 'is_test' => true ), array( 'status' => (string) ( $entry->status ?? 'approved' ) ) )
				: array( 'eligible' => true, 'normalized' => array() );
			if ( empty( $evaluation['eligible'] ) ) {
				continue;
			}
			$n = (array) ( $evaluation['normalized'] ?? array() );
			$group_key = sprintf(
				'%s|%s|%s',
				sanitize_key( (string) ( $n['discipline'] ?? $entry->discipline ?? 'light_contact' ) ),
				sanitize_text_field( (string) ( $n['age_category'] ?? $entry->category ?? $entry->category_name ?? 'nc' ) ),
				sanitize_text_field( (string) ( $n['weight_category'] ?? $entry->weight_class ?? 'nc' ) )
			);
			$groups[ $group_key ][] = $entry;
		}
		$next_no = $fight_repo->get_max_fight_no( $competition_id ) + 1;
		$attempted = 0;
		$inserted = 0;
		$group_diagnostics = array();
		foreach ( $groups as $group_key => $group_entries ) {
			usort( $group_entries, static function ( $a, $b ) { return (int) $a->id <=> (int) $b->id; } );
			$count = count( $group_entries );
			$group_format = sanitize_key( (string) ( $settings['group_generation_options'][ $group_key ]['format'] ?? 'auto' ) );
			$diag = array( 'group_key' => $group_key, 'format_chosen' => $group_format, 'entries' => $count, 'created' => 0, 'bye_slots' => 0, 'warnings' => array() );
			if ( 'wait' === $group_format || 'none' === $group_format ) {
				$lone[] = $group_key . ':wait';
				$diag['warnings'][] = 'left_waiting_by_admin';
				$group_diagnostics[] = $diag;
				continue;
			}
			if ( $count < 2 ) {
				$lone[] = $group_key;
				continue;
			}
			if ( 'pool' === $group_format ) {
				for ( $i = 0; $i < $count; $i++ ) {
					for ( $j = $i + 1; $j < $count; $j++ ) {
						$attempted++;
						$id = $fight_repo->insert( array(
							'competition_id' => $competition_id, 'round_no' => 1, 'fight_no' => $next_no++,
							'red_entry_id' => (int) $group_entries[ $i ]->id, 'blue_entry_id' => (int) $group_entries[ $j ]->id, 'status' => 'scheduled',
							'timing_profile_id' => null, 'round_duration' => 120, 'rounds' => 1, 'break_duration' => 60, 'fight_pause' => 60, 'fight_duration' => 180,
						) );
						if ( $id > 0 ) { $inserted++; $diag['created']++; }
					}
				}
				$group_diagnostics[] = $diag;
				continue;
			}
			if ( 'bracket' === $group_format && $count >= 4 ) {
				if ( $count >= 8 ) {
					$quarter_ids = array();
					for ( $q = 0; $q < 4; $q++ ) {
						$attempted++;
						$quarter_id = $fight_repo->insert( array( 'competition_id' => $competition_id, 'round_no' => 1, 'fight_no' => $next_no++, 'red_entry_id' => (int) $group_entries[ $q * 2 ]->id, 'blue_entry_id' => (int) $group_entries[ ( $q * 2 ) + 1 ]->id, 'status' => 'scheduled', 'phase' => 'Quart de finale', 'group_key' => (string) $group_key ) );
						if ( $quarter_id > 0 ) { $inserted++; $diag['created']++; $quarter_ids[] = (int) $quarter_id; }
					}
					$semi_ids = array();
					for ( $s = 0; $s < 2; $s++ ) {
						$attempted++;
						$semi_id = $fight_repo->insert( array( 'competition_id' => $competition_id, 'round_no' => 2, 'fight_no' => $next_no++, 'red_entry_id' => null, 'blue_entry_id' => null, 'status' => 'placeholder', 'phase' => 'Demi-finale', 'group_key' => (string) $group_key, 'source_red_fight_id' => (int) ( $quarter_ids[ $s * 2 ] ?? 0 ), 'source_blue_fight_id' => (int) ( $quarter_ids[ ( $s * 2 ) + 1 ] ?? 0 ) ) );
						if ( $semi_id > 0 ) { $inserted++; $diag['created']++; $semi_ids[] = (int) $semi_id; }
					}
					$attempted++;
					$final_id = $fight_repo->insert( array( 'competition_id' => $competition_id, 'round_no' => 3, 'fight_no' => $next_no++, 'red_entry_id' => null, 'blue_entry_id' => null, 'status' => 'placeholder', 'phase' => 'Finale', 'group_key' => (string) $group_key, 'source_red_fight_id' => (int) ( $semi_ids[0] ?? 0 ), 'source_blue_fight_id' => (int) ( $semi_ids[1] ?? 0 ) ) );
					if ( $final_id > 0 ) { $inserted++; $diag['created']++; }
					foreach ( $quarter_ids as $quarter_index => $quarter_id ) {
						$target_semi = $semi_ids[ (int) floor( $quarter_index / 2 ) ] ?? 0;
						if ( $target_semi > 0 ) { $fight_repo->update( $quarter_id, array( 'next_fight_id' => $target_semi, 'next_slot' => 0 === ( $quarter_index % 2 ) ? 'red' : 'blue' ) ); }
					}
					foreach ( $semi_ids as $semi_index => $semi_id ) {
						if ( $final_id > 0 ) { $fight_repo->update( $semi_id, array( 'next_fight_id' => (int) $final_id, 'next_slot' => 0 === (int) $semi_index ? 'red' : 'blue' ) ); }
					}
					$group_diagnostics[] = $diag;
					continue;
				}
				$semi_ids = array();
				$pairs = array( array( 0, 1 ), array( 2, 3 ) );
				foreach ( $pairs as $pair_index => $pair ) {
					$attempted++;
					$id = $fight_repo->insert( array( 'competition_id' => $competition_id, 'round_no' => 1, 'fight_no' => $next_no++, 'red_entry_id' => (int) $group_entries[ $pair[0] ]->id, 'blue_entry_id' => (int) $group_entries[ $pair[1] ]->id, 'status' => 'scheduled', 'timing_profile_id' => null, 'round_duration' => 120, 'rounds' => 1, 'break_duration' => 60, 'fight_pause' => 60, 'fight_duration' => 180, 'phase' => 'Demi-finale', 'group_key' => (string) $group_key ) );
					if ( $id > 0 ) { $inserted++; $diag['created']++; $semi_ids[ $pair_index ] = (int) $id; }
				}
				$attempted++;
				$pid = $fight_repo->insert( array( 'competition_id' => $competition_id, 'round_no' => 2, 'fight_no' => $next_no++, 'red_entry_id' => null, 'blue_entry_id' => null, 'status' => 'placeholder', 'phase' => 'Finale', 'group_key' => (string) $group_key, 'source_red_fight_id' => (int) ( $semi_ids[0] ?? 0 ), 'source_blue_fight_id' => (int) ( $semi_ids[1] ?? 0 ) ) );
				if ( $pid > 0 ) { $inserted++; $diag['created']++; }
				if ( $pid > 0 && ! empty( $semi_ids ) ) {
					foreach ( $semi_ids as $semi_index => $semi_id ) {
						$fight_repo->update( (int) $semi_id, array( 'next_fight_id' => (int) $pid, 'next_slot' => 0 === (int) $semi_index ? 'red' : 'blue' ) );
					}
				}
				$group_diagnostics[] = $diag;
				continue;
			}
			if ( 'bracket_bye' === $group_format && $count >= 5 ) {
				$bracket_size = 1;
				while ( $bracket_size < $count ) { $bracket_size *= 2; }
				$bye = max( 0, $bracket_size - $count );
				$diag['bye_slots'] = $bye;
				$fighters_for_round = array_slice( $group_entries, $bye );
				for ( $i = 0; $i + 1 < count( $fighters_for_round ); $i += 2 ) {
					$attempted++;
					$id = $fight_repo->insert( array( 'competition_id' => $competition_id, 'round_no' => 1, 'fight_no' => $next_no++, 'red_entry_id' => (int) $fighters_for_round[ $i ]->id, 'blue_entry_id' => (int) $fighters_for_round[ $i + 1 ]->id, 'status' => 'scheduled', 'timing_profile_id' => null, 'round_duration' => 120, 'rounds' => 1, 'break_duration' => 60, 'fight_pause' => 60, 'fight_duration' => 180 ) );
					if ( $id > 0 ) { $inserted++; $diag['created']++; }
				}
				$group_diagnostics[] = $diag;
				continue;
			}
			if ( 'repechage' === $group_format ) {
				$diag['warnings'][] = 'repechage_not_supported';
			}
			for ( $i = 0; $i + 1 < $count; $i += 2 ) {
				$attempted++;
				$id = $fight_repo->insert( array(
					'competition_id'   => $competition_id,
					'round_no'         => 1,
					'fight_no'         => $next_no++,
					'red_entry_id'     => (int) $group_entries[ $i ]->id,
					'blue_entry_id'    => (int) $group_entries[ $i + 1 ]->id,
					'status'           => 'scheduled',
					'timing_profile_id'=> null,
					'round_duration'   => 120,
					'rounds'           => 1,
					'break_duration'   => 60,
					'fight_pause'      => 60,
					'fight_duration'   => 180,
				) );
					if ( $id > 0 ) {
						$inserted++; $diag['created']++;
					}
				}
				if ( $count % 2 === 1 ) {
					$lone[] = $group_key;
				}
				$group_diagnostics[] = $diag;
		}
		update_option( 'ufsc_competition_last_group_generation_' . $competition_id, $group_diagnostics, false );
		return array(
			'ok' => $inserted > 0,
			'attempted_inserts' => $attempted,
			'successful_inserts' => $inserted,
			'lone_groups' => $lone,
		);
	}

	public static function recalc_schedule( int $competition_id, array $settings ): array {
		if ( ! self::is_enabled() ) {
			return array(
				'ok'      => false,
				'message' => __( 'La génération automatique est désactivée.', 'ufsc-licence-competition' ),
			);
		}

		if ( ! $competition_id ) {
			return array(
				'ok'      => false,
				'message' => __( 'Compétition invalide.', 'ufsc-licence-competition' ),
			);
		}

		$fight_repo = new FightRepository();
		$fights     = $fight_repo->get_draft_fights( $competition_id );
		if ( ! $fights ) {
			return array(
				'ok'      => false,
				'message' => __( 'Aucun brouillon disponible.', 'ufsc-licence-competition' ),
			);
		}

		$fight_repo->recalc_draft_schedule( $competition_id, $settings );
		$updated = count( $fights );

		return array(
			'ok'      => true,
			'message' => sprintf( __( 'Horaires recalculés (%d combats).', 'ufsc-licence-competition' ), $updated ),
		);
	}

	public static function swap_colors( int $competition_id, int $fight_id ): array {
		if ( ! self::is_enabled() ) {
			return array(
				'ok'      => false,
				'message' => __( 'La génération automatique est désactivée.', 'ufsc-licence-competition' ),
			);
		}

		if ( ! $competition_id || ! $fight_id ) {
			return array(
				'ok'      => false,
				'message' => __( 'Combat invalide.', 'ufsc-licence-competition' ),
			);
		}

		return ( new FightRepository() )->swap_draft_corners( $competition_id, $fight_id );
	}

	public static function reorder_fights( int $competition_id, string $mode ): array {
		if ( ! self::is_enabled() ) {
			return array(
				'ok'      => false,
				'message' => __( 'La génération automatique est désactivée.', 'ufsc-licence-competition' ),
			);
		}

		if ( ! $competition_id ) {
			return array(
				'ok'      => false,
				'message' => __( 'Compétition invalide.', 'ufsc-licence-competition' ),
			);
		}

		$mode = in_array( $mode, array( 'fight_no', 'scheduled', 'category' ), true ) ? $mode : 'fight_no';

		$fight_repo = new FightRepository();
		$fights     = $fight_repo->get_draft_fights( $competition_id );
		if ( ! $fights ) {
			return array(
				'ok'      => false,
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
			'ok'      => true,
			'message' => __( 'Combats réordonnés.', 'ufsc-licence-competition' ),
		);
	}

	private static function get_existing_generation_blockers( int $competition_id ): array {
		$fight_repo = new FightRepository();

		$draft = $fight_repo->count(
			array(
				'competition_id' => $competition_id,
				'status'         => 'draft',
			)
		);

		$scheduled = $fight_repo->count(
			array(
				'competition_id' => $competition_id,
				'status'         => 'scheduled',
			)
		);

		return array(
			'draft'     => (int) $draft,
			'scheduled' => (int) $scheduled,
			'total'     => (int) $draft + (int) $scheduled,
		);
	}

	private static function select_eligible_entries( array $entries, int $competition_id, $competition, array $settings ): array {
		$valid_entries      = array();
		$ineligible_reasons = array();
		$reason_counts      = array();
		$excluded_unweighed = 0;
		$rejected_entries   = array();

		$allow_unweighed = ! empty( $settings['allow_unweighed'] );
		$weighin_repo    = new WeighInRepository();
		$enforce_weighin = ! $allow_unweighed && $weighin_repo->has_table();
		$weighins_by_entry = array();
		if ( $enforce_weighin && method_exists( $weighin_repo, 'get_for_entries' ) ) {
			$entry_ids = array_values(
				array_filter(
					array_map(
						static function ( $entry ) {
							return (int) ( $entry->id ?? 0 );
						},
						$entries
					)
				)
			);
			$weighins_by_entry = $weighin_repo->get_for_entries( $competition_id, $entry_ids );
		}

		$competition_tolerance = isset( $competition->weight_tolerance ) ? (float) $competition->weight_tolerance : 0.0;
		$entry_assigned_to_active_fight = self::get_entries_already_assigned_to_active_fights( $competition_id );


		foreach ( $entries as $entry ) {
			$entry_id = (int) ( $entry->id ?? 0 );
			if ( $entry_id > 0 && isset( $entry_assigned_to_active_fight[ $entry_id ] ) ) {
				$ineligible_reasons['already_assigned_fight'] = true;
				$reason_counts['already_assigned_fight']      = (int) ( $reason_counts['already_assigned_fight'] ?? 0 ) + 1;
				$rejected_entries[] = self::build_rejected_entry_snapshot( $entry, array( 'already_assigned_fight' ) );
				continue;
			}


			if ( function_exists( 'ufsc_lc_is_entry_eligible_from_entry' ) ) {
				$eligibility = ufsc_lc_is_entry_eligible_from_entry( $entry, 'fights' );
			} else {
				$eligibility = function_exists( 'ufsc_lc_is_entry_eligible' )
					? ufsc_lc_is_entry_eligible( $entry_id, 'fights' )
					: array( 'eligible' => false, 'reasons' => array( 'status_not_approved' ) );
			}

			$entry_warnings = array();
			if ( empty( $eligibility['eligible'] ) ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log(
						'UFSC FightAutoGenerationService entry_excluded ' . wp_json_encode(
							array(
								'entry_id' => $entry_id,
								'reasons'  => array_values( (array) ( $eligibility['reasons'] ?? array() ) ),
								'context'  => 'eligibility',
							)
						)
					);
				}
				foreach ( (array) ( $eligibility['reasons'] ?? array() ) as $reason ) {
					$reason = sanitize_key( (string) $reason );
					if ( '' === $reason ) {
						continue;
					}
					$ineligible_reasons[ $reason ] = true;
					$reason_counts[ $reason ]      = (int) ( $reason_counts[ $reason ] ?? 0 ) + 1;
				}
				$raw_reasons = array_values( array_filter( array_map( 'sanitize_key', (array) ( $eligibility['reasons'] ?? array() ) ) ) );
				$blocking_reasons = array_values( array_diff( $raw_reasons, self::get_non_blocking_reasons( $settings ) ) );
				$entry_warnings   = array_values( array_intersect( $raw_reasons, self::get_non_blocking_reasons( $settings ) ) );
				if ( ! empty( $blocking_reasons ) ) {
					$rejected_entries[] = self::build_rejected_entry_snapshot(
						$entry,
						$blocking_reasons,
						(string) ( $eligibility['status'] ?? '' )
					);
					continue;
				}
			}

			$grouping_reasons = self::get_entry_grouping_rejection_reasons( $entry, $settings );
			if ( ! empty( $grouping_reasons ) ) {
				foreach ( $grouping_reasons as $reason ) {
					$ineligible_reasons[ $reason ] = true;
					$reason_counts[ $reason ]      = (int) ( $reason_counts[ $reason ] ?? 0 ) + 1;
				}
				$rejected_entries[] = self::build_rejected_entry_snapshot( $entry, $grouping_reasons, (string) ( $eligibility['status'] ?? '' ) );
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( 'UFSC FightAutoGenerationService entry_excluded ' . wp_json_encode( array( 'entry_id' => $entry_id, 'reasons' => $grouping_reasons, 'context' => 'grouping' ) ) );
				}
				continue;
			}

			if ( $enforce_weighin ) {
				$entry_weight = isset( $entry->weight_kg ) ? (float) $entry->weight_kg : null;
				$row          = $weighins_by_entry[ $entry_id ] ?? null;
				$has_weighin  = method_exists( $weighin_repo, 'is_valid_weighin_row' )
					? $weighin_repo->is_valid_weighin_row( $row, $competition_tolerance, $entry_weight )
					: $weighin_repo->has_valid_weighin( $competition_id, $entry_id, $competition_tolerance, $entry_weight );
				if ( ! $has_weighin ) {
					$ineligible_reasons['weighin_missing'] = true;
					$reason_counts['weighin_missing']      = (int) ( $reason_counts['weighin_missing'] ?? 0 ) + 1;
					$excluded_unweighed++;
					$rejected_entries[] = self::build_rejected_entry_snapshot( $entry, array( 'weighin_missing' ) );
					if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						error_log( 'UFSC FightAutoGenerationService entry_excluded ' . wp_json_encode( array( 'entry_id' => $entry_id, 'reasons' => array( 'weighin_missing' ), 'context' => 'weighin' ) ) );
					}
					continue;
				}

				$meta = self::extract_weighin_notes_meta( $row );
				if ( ! empty( $meta['reclass_pending'] ) ) {
					$ineligible_reasons['reclass_pending'] = true;
					$reason_counts['reclass_pending']      = (int) ( $reason_counts['reclass_pending'] ?? 0 ) + 1;
					$excluded_unweighed++;
					$rejected_entries[] = self::build_rejected_entry_snapshot( $entry, array( 'reclass_pending' ) );
					if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						error_log( 'UFSC FightAutoGenerationService entry_excluded ' . wp_json_encode( array( 'entry_id' => $entry_id, 'reasons' => array( 'reclass_pending' ), 'context' => 'weighin' ) ) );
					}
					continue;
				}
			}

			$valid_entries[] = $entry;
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log(
				'UFSC FightAutoGenerationService eligibility_summary ' . wp_json_encode(
					array(
						'competition_id'      => $competition_id,
						'total_entries'       => count( $entries ),
						'eligible_entries'    => count( $valid_entries ),
						'excluded_unweighed'  => $excluded_unweighed,
						'exclusion_reasons'   => array_keys( $ineligible_reasons ),
						'reason_counts'       => $reason_counts,
					)
				)
			);
		}

		return array(
			'valid_entries'      => $valid_entries,
			'ineligible_reasons' => $ineligible_reasons,
			'reason_counts'      => $reason_counts,
			'excluded_unweighed' => $excluded_unweighed,
			'enforce_weighin'    => $enforce_weighin,
			'rejected_entries'   => $rejected_entries,
		);
	}

	private static function build_rejection_diagnostics( array $entries, array $selection ): array {
		$reason_counts = (array) ( $selection['reason_counts'] ?? array() );
		$non_blocking_reasons = self::get_non_blocking_reasons( self::get_settings( 0 ) );
		$total_rejected = 0;
		foreach ( $reason_counts as $count ) {
			$total_rejected += (int) $count;
		}
		foreach ( $non_blocking_reasons as $reason_key ) {
			$total_rejected -= (int) ( $reason_counts[ $reason_key ] ?? 0 );
		}
		$total_rejected = max( 0, $total_rejected );

		$sum = static function ( array $keys ) use ( $reason_counts ): int {
			$total = 0;
			foreach ( $keys as $key ) {
				$total += (int) ( $reason_counts[ $key ] ?? 0 );
			}
			return $total;
		};

		return array(
			'total_entries'                     => count( $entries ),
			'eligible_entries'                  => count( (array) ( $selection['valid_entries'] ?? array() ) ),
			'rejected_total'                    => $total_rejected,
			'rejected_status'                   => $sum( array( 'status_not_approved', 'status_not_pending', 'status_not_exportable' ) ),
			'rejected_license_or_participant'   => $sum( array( 'license_missing', 'external_not_allowed_for_competition' ) ),
			'rejected_weighin'                  => $sum( array( 'weighin_missing', 'reclass_pending' ) ),
			'rejected_missing_sport_data'       => $sum( array( 'external_identity_incomplete', 'external_missing_required_sport_data', 'external_birth_date_invalid', 'external_birth_date_future', 'external_sex_invalid' ) ),
			'non_blocking_warnings'             => $sum( $non_blocking_reasons ),
			'rejected_category_weight_level'    => $sum( array( 'weight_missing', 'weight_class_missing', 'category_unresolved' ) ),
			'rejected_grouping'                 => $sum( array( 'discipline_missing', 'sex_missing', 'weight_class_missing', 'category_unresolved', 'birthdate_missing', 'level_missing' ) ),
			'rejected_discipline'               => $sum( array( 'discipline_missing' ) ),
			'rejected_club'                     => $sum( array( 'club_missing' ) ),
			'rejected_incomplete_fighter_data'  => $sum( array( 'entry_missing', 'entry_not_found', 'entry_deleted' ) ),
			'rejected_duplicate_fighter_number' => self::count_duplicate_fighter_numbers( $entries ),
			'sport_data_subreasons'             => self::build_subreason_counts(
				$reason_counts,
				array(
					'external_identity_incomplete',
					'external_missing_required_sport_data',
					'external_birth_date_invalid',
					'external_birth_date_future',
					'external_sex_invalid',
					'external_minor_guardian_missing',
					'external_guardian_email_invalid',
					'external_guardian_phone_invalid',
				)
			),
			'rejected_entries_preview'          => array_slice( (array) ( $selection['rejected_entries'] ?? array() ), 0, 10 ),
		);
	}

	private static function build_groups_preview( array $groups, array $settings ): array {
		$rows = array();
		foreach ( $groups as $group_key => $group_entries ) {
			$group_entries = is_array( $group_entries ) ? $group_entries : array();
			$count         = count( $group_entries );
			$status        = $count >= 2 ? 'generable' : 'insufficient';
			$athletes      = array();
			foreach ( $group_entries as $entry ) {
				$athletes[] = array(
					'id'         => (int) ( $entry->id ?? 0 ),
					'last_name'  => self::pick_entry_value( $entry, array( 'licensee_last_name', 'last_name', 'lastname', 'nom', 'family_name' ) ),
					'first_name' => self::pick_entry_value( $entry, array( 'licensee_first_name', 'first_name', 'firstname', 'prenom', 'given_name' ) ),
				);
			}
			$group_warnings = array();
			if ( 1 === $count ) {
				$group_warnings[] = 'isolated_participant';
			}
			if ( $count < 2 ) {
				$group_warnings[] = 'insufficient_participants';
			}
			if ( $count > 1 && 1 === ( $count % 2 ) ) {
				$group_warnings[] = 'odd_participant_count';
			}
			$group_components = ! empty( $group_entries ) ? self::get_generation_group_components( $group_entries[0], $settings ) : array();
			$rows[] = array(
				'group_key'         => (string) $group_key,
				'group_components'  => $group_components,
				'warnings'          => $group_warnings,
				'entries_count'     => $count,
				'estimated_fights'  => self::estimate_fights_for_group_size( $count ),
				'status'            => $status,
				'format'            => self::recommend_group_format( $count, $settings ),
				'bye_slots'         => self::estimate_bye_slots( $count ),
				'lone_fighter'      => 1 === $count,
				'recommendation'    => function_exists( 'ufsc_competition_recommend_group_format' )
					? ufsc_competition_recommend_group_format(
						array( 'entries_count' => $count ),
						sanitize_key( (string) ( $settings['competition_type'] ?? '' ) ),
						$settings
					)
					: array(),
				'use_level_split'   => ! empty( $settings['use_level_split'] ),
				'athletes'          => $athletes,
			);
		}
		return $rows;
	}

	private static function estimate_bye_slots( int $count ): int {
		if ( $count <= 1 ) {
			return 0;
		}
		$pow2 = 1;
		while ( $pow2 < $count ) {
			$pow2 *= 2;
		}
		return max( 0, $pow2 - $count );
	}

	private static function recommend_group_format( int $count, array $settings ): string {
		if ( $count <= 1 ) {
			return 'combattant_seul';
		}
		if ( 2 === $count ) {
			return 'combat_direct';
		}
		if ( 3 === $count ) {
			return ! empty( $settings['prefer_round_robin_for_3'] ) ? 'poule_complete' : 'tableau_avec_bye';
		}
		if ( 4 === $count ) {
			return 'demi_finales_finale';
		}
		if ( $count <= 8 ) {
			return 'tableau_avec_bye';
		}
		return 'tableau_par_tours';
	}


	private static function get_entries_already_assigned_to_active_fights( int $competition_id ): array {
		$fight_repo = new FightRepository();
		$fights     = $fight_repo->list( array( 'view' => 'all', 'competition_id' => $competition_id ), 5000, 0 );
		$assigned   = array();
		foreach ( $fights as $fight ) {
			$status = $fight_repo->get_effective_fight_status( $fight );
			if ( in_array( $status, array( FightRepository::STATUS_CANCELLED, FightRepository::STATUS_TRASHED ), true ) ) {
				continue;
			}
			$red  = absint( $fight->red_entry_id ?? 0 );
			$blue = absint( $fight->blue_entry_id ?? 0 );
			if ( $red > 0 ) { $assigned[ $red ] = true; }
			if ( $blue > 0 ) { $assigned[ $blue ] = true; }
		}

		return $assigned;
	}

	private static function build_generation_rejection_message( int $total_entries, array $reason_counts, int $eligible_entries = 0, int $ignored_groups = 0 ): string {
		$rejected_entries = max( 0, $total_entries - $eligible_entries );
		$lines = array(
			__( 'Aucun combat n’a été généré.', 'ufsc-licence-competition' ),
			sprintf( __( '%1$d participants analysés, %2$d éligibles, %3$d rejetés.', 'ufsc-licence-competition' ), $total_entries, $eligible_entries, $rejected_entries ),
			sprintf( __( 'Statut non compatible : %d', 'ufsc-licence-competition' ), (int) ( $reason_counts['status_not_approved'] ?? 0 ) ),
			sprintf( __( 'Catégorie manquante : %d', 'ufsc-licence-competition' ), (int) ( ( $reason_counts['weight_class_missing'] ?? 0 ) + ( $reason_counts['category_unresolved'] ?? 0 ) ) ),
			sprintf( __( 'Pesée non validée : %d', 'ufsc-licence-competition' ), (int) ( ( $reason_counts['weighin_missing'] ?? 0 ) + ( $reason_counts['reclass_pending'] ?? 0 ) ) ),
			sprintf( __( 'Déjà affecté à un combat : %d', 'ufsc-licence-competition' ), (int) ( $reason_counts['already_assigned_fight'] ?? 0 ) ),
			sprintf( __( 'Groupes avec moins de 2 combattants : %d', 'ufsc-licence-competition' ), $ignored_groups ),
		);

		return implode( ' ', $lines );
	}


	private static function get_non_blocking_reasons( array $settings ): array {
		$reasons = array(
			'external_minor_guardian_missing',
			'external_guardian_email_invalid',
			'external_guardian_phone_invalid',
			'club_missing',
		);
		if ( empty( $settings['guardian_required_for_minors'] ) ) {
			$reasons[] = 'external_minor_guardian_missing';
		}
		return array_values( array_unique( $reasons ) );
	}

	private static function build_subreason_counts( array $reason_counts, array $keys ): array {
		$counts = array();
		foreach ( $keys as $key ) {
			$value = (int) ( $reason_counts[ $key ] ?? 0 );
			if ( $value > 0 ) {
				$counts[ $key ] = $value;
			}
		}

		return $counts;
	}

	private static function get_entry_grouping_rejection_reasons( $entry, array $settings ): array {
		$category_label = self::pick_entry_value( $entry, array( 'category', 'category_name', 'categorie', 'age_category', 'categorie_age', 'category_label' ) );
		$category_id    = absint( self::pick_entry_value( $entry, array( 'category_id' ) ) );
		$discipline     = self::normalize_discipline_for_generation( self::pick_entry_value( $entry, array( 'discipline' ) ) );
		$sex            = self::normalize_sex_value( self::pick_entry_value( $entry, array( 'sex', 'sexe', 'gender', 'genre', 'fighter_sex', 'participant_gender', 'licensee_sex' ) ), $category_label );
		$weight_class   = self::pick_entry_value( $entry, array( 'weight_class', 'weight_category', 'weight_cat', 'categorie_poids', 'category_weight' ) );
		$birth_raw      = self::pick_entry_value( $entry, array( 'birth_date', 'date_naissance', 'birthdate', 'dob', 'date_of_birth', 'naissance', 'licensee_birthdate' ) );
		$level          = sanitize_key( self::pick_entry_value( $entry, array( 'level', 'class', 'classe', 'niveau' ) ) );
		$reasons        = array();

		if ( '' === $discipline ) {
			$reasons[] = 'discipline_missing';
		}
		if ( '' === $sex ) {
			$reasons[] = 'sex_missing';
		}
		if ( '' === $weight_class ) {
			$reasons[] = 'weight_class_missing';
		}
		if ( $category_id <= 0 && '' === $category_label ) {
			$reasons[] = 'category_unresolved';
			if ( '' === self::normalize_birth_date_value( $birth_raw ) ) {
				$reasons[] = 'birthdate_missing';
			}
		}
		if ( ! empty( $settings['use_level_split'] ) && ( '' === $level || 'non_defini' === $level ) ) {
			$reasons[] = 'level_missing';
		}

		return array_values( array_unique( array_map( 'sanitize_key', $reasons ) ) );
	}

	private static function get_generation_group_components( $entry, array $settings = array() ): array {
		$category_label = self::pick_entry_value( $entry, array( 'category', 'category_name', 'categorie', 'age_category', 'categorie_age', 'category_label' ) );
		$category_id    = absint( self::pick_entry_value( $entry, array( 'category_id' ) ) );
		$discipline     = self::normalize_discipline_for_generation( self::pick_entry_value( $entry, array( 'discipline' ) ) );
		$sex            = self::normalize_sex_value( self::pick_entry_value( $entry, array( 'sex', 'sexe', 'gender', 'genre', 'fighter_sex', 'participant_gender', 'licensee_sex' ) ), $category_label );
		$weight_class   = self::pick_entry_value( $entry, array( 'weight_class', 'weight_category', 'weight_cat', 'categorie_poids', 'category_weight' ) );
		$level          = sanitize_key( self::pick_entry_value( $entry, array( 'level', 'class', 'classe', 'niveau' ) ) );

		return array(
			'category_id'     => $category_id,
			'category_key'    => $category_id > 0 ? 'category_' . $category_id : 'category_unresolved',
			'discipline'      => '' !== $discipline ? $discipline : 'discipline_missing',
			'sex'             => '' !== $sex ? $sex : 'sex_missing',
			'age_category'    => '' !== $category_label ? sanitize_text_field( $category_label ) : ( $category_id > 0 ? 'category_' . $category_id : 'category_unresolved' ),
			'weight_category' => '' !== $weight_class ? sanitize_text_field( $weight_class ) : 'weight_missing',
			'level'           => '' !== $level ? $level : 'non_defini',
			'use_level_split' => ! empty( $settings['use_level_split'] ),
		);
	}

	private static function build_generation_group_key_from_components( array $components ): string {
		$key = array(
			sanitize_key( (string) ( $components['discipline'] ?? 'discipline_missing' ) ),
			sanitize_key( (string) ( $components['sex'] ?? 'sex_missing' ) ),
			sanitize_key( (string) ( $components['category_key'] ?? 'category_unresolved' ) ),
			sanitize_key( (string) ( $components['age_category'] ?? 'category_unresolved' ) ),
			sanitize_key( (string) ( $components['weight_category'] ?? 'weight_missing' ) ),
		);
		if ( ! empty( $components['use_level_split'] ) ) {
			$key[] = sanitize_key( (string) ( $components['level'] ?? 'non_defini' ) );
		}

		return implode( '|', $key );
	}

	private static function build_rejected_entry_snapshot( $entry, array $reasons, string $status = '' ): array {
		$name_keys = array( 'licensee_last_name', 'last_name', 'lastname', 'nom', 'family_name' );
		$first_keys = array( 'licensee_first_name', 'first_name', 'firstname', 'prenom', 'given_name' );
		$discipline_keys = array( 'discipline' );
		$category_keys = array( 'category_name', 'category', 'categorie' );
		$weight_class_keys = array( 'weight_class', 'weight_category', 'weight_cat', 'categorie_poids', 'category_weight' );
		$weight_keys = array( 'weight_kg', 'weight', 'poids' );
		$level_keys = array( 'level', 'class', 'classe', 'niveau' );
		$birth_keys = array( 'birth_date', 'date_naissance', 'birthdate', 'dob', 'date_of_birth', 'naissance', 'licensee_birthdate' );
		$sex_keys = array( 'sex', 'sexe', 'gender', 'genre', 'fighter_sex', 'participant_gender', 'licensee_sex' );

		$birth_raw  = self::pick_entry_value( $entry, $birth_keys );
		$sex_raw    = self::pick_entry_value( $entry, $sex_keys );
		$level_raw  = self::pick_entry_value( $entry, $level_keys );
		$category   = self::pick_entry_value( $entry, $category_keys );
		$sex_normalized = self::normalize_sex_value( $sex_raw, $category );
		$date_normalized = self::normalize_birth_date_value( $birth_raw );
		$level_normalized = '' !== $level_raw ? $level_raw : 'non_defini';
		$group_components = self::get_generation_group_components( $entry );
		$resolved_status = '' !== $status ? $status : sanitize_key( (string) ( $entry->status ?? '' ) );

		return array(
			'entry_id'               => (int) ( $entry->id ?? 0 ),
			'last_name'              => self::pick_entry_value( $entry, $name_keys ),
			'first_name'             => self::pick_entry_value( $entry, $first_keys ),
			'status'                 => $resolved_status,
			'discipline'             => self::pick_entry_value( $entry, $discipline_keys ),
			'category'               => $category,
			'weight_class'           => self::pick_entry_value( $entry, $weight_class_keys ),
			'weight'                 => self::pick_entry_value( $entry, $weight_keys ),
			'sex_raw'                => $sex_raw,
			'sex_normalized'         => $sex_normalized,
			'birthdate_raw'          => $birth_raw,
			'birthdate_normalized'   => $date_normalized,
			'level_raw'              => $level_raw,
			'level_normalized'       => $level_normalized,
			'group_key'              => self::build_generation_group_key_from_components( $group_components ),
			'group_components'       => $group_components,
			'reasons'                => array_values( array_filter( array_map( 'sanitize_key', $reasons ) ) ),
		);
	}

	private static function pick_entry_value( $entry, array $keys ): string {
		foreach ( $keys as $key ) {
			if ( isset( $entry->{$key} ) ) {
				$value = trim( (string) $entry->{$key} );
				if ( '' !== $value ) {
					return sanitize_text_field( $value );
				}
			}
		}

		return '';
	}

	private static function normalize_birth_date_value( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		if ( preg_match( '/^(\d{4})-\d{2}-\d{2}/', $value, $matches ) ) {
			return $matches[0];
		}
		if ( preg_match( '/^(\d{2})\/(\d{2})\/(\d{4})$/', $value, $matches ) ) {
			return $matches[3] . '-' . $matches[2] . '-' . $matches[1];
		}
		if ( preg_match( '/^(\d{2})-(\d{2})-(\d{4})$/', $value, $matches ) ) {
			return $matches[3] . '-' . $matches[2] . '-' . $matches[1];
		}

		return '';
	}

	private static function normalize_sex_value( string $raw, string $category = '' ): string {
		$raw = sanitize_key( $raw );
		if ( in_array( $raw, array( 'f', 'female', 'feminin', 'woman', 'femme' ), true ) ) {
			return 'f';
		}
		if ( in_array( $raw, array( 'm', 'h', 'male', 'masculin', 'homme' ), true ) ) {
			return 'm';
		}

		$category_upper = function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $category ) : strtoupper( $category );
		if ( '' !== $category_upper ) {
			if ( preg_match( '/(?:\s|^)(F|FEM|FEMININ|FEMME)(?:\s|$)/u', $category_upper ) ) {
				return 'f';
			}
			if ( preg_match( '/(?:\s|^)(H|M|HOM|HOMME|MASC|MASCULIN)(?:\s|$)/u', $category_upper ) ) {
				return 'm';
			}
		}

		return '';
	}

	private static function get_default_settings(): array {
		return array(
			'plateau_name'    => '',
			'surface_count'   => 1,
			'surface_labels'  => '',
			'surface_details' => array(),
			'fight_duration'  => 2,
			'fight_duration_seconds' => 0,
			'break_duration'  => 1,
			'break_duration_seconds' => 0,
			'timing_mode'     => 'global',
			'mode'            => 'auto',
			'auto_lock'       => 0,
			'allow_unweighed' => 0,
			'use_level_split' => 0,
			'guardian_required_for_minors' => 0,
			'group_generation_options' => array(),
			'allow_compatible_disciplines' => 0,
			'settings_saved_at' => '',
		);
	}

	public static function normalize_discipline_for_generation( string $value ): string {
		$raw = function_exists( 'remove_accents' ) ? remove_accents( $value ) : $value;
		$raw = strtolower( sanitize_text_field( $raw ) );
		$raw = str_replace( array( '-', '_', '/' ), ' ', $raw );
		$raw = trim( preg_replace( '/\s+/', ' ', $raw ) );
		$map = array(
			'kickboxing'                => 'kickboxing',
			'kick boxing'               => 'kickboxing',
			'boxe pieds poings'         => 'kickboxing',
			'light contact'             => 'light_contact',
			'kick light'                => 'light_contact',
			'kickboxing light'          => 'light_contact',
			'kickboxing light contact'  => 'light_contact',
			'kick boxing light contact' => 'light_contact',
			'full contact'              => 'full_contact',
			'plein contact'             => 'full_contact',
			'k1'                        => 'k1',
			'k 1'                       => 'k1',
			'k1 rules'                  => 'k1',
			'muay thai'                 => 'muay_thai',
			'boxe thai'                 => 'muay_thai',
			'thaiboxing'                => 'muay_thai',
		);
		if ( isset( $map[ $raw ] ) ) {
			return $map[ $raw ];
		}
		return sanitize_key( str_replace( ' ', '_', $raw ) );
	}

	private static function estimate_fights_for_group_size( int $count ): int {
		if ( $count < 2 ) {
			return 0;
		}
		if ( 2 === $count ) {
			return 1;
		}
		if ( 3 === $count ) {
			return 3;
		}
		if ( 4 === $count ) {
			return 3;
		}

		$bracket_size = 1;
		while ( $bracket_size < $count ) {
			$bracket_size *= 2;
		}

		return $bracket_size - 1;
	}

	private static function group_entries_by_category( array $entries, array $settings = array() ): array {
		$groups = array();

		foreach ( $entries as $entry ) {
			$group_key = self::get_generation_category_key( $entry, $settings );
			if ( '' === $group_key ) {
				continue;
			}
			if ( ! isset( $groups[ $group_key ] ) ) {
				$groups[ $group_key ] = array();
			}
			$groups[ $group_key ][] = $entry;
		}

		return $groups;
	}

	private static function summarize_generation_groups( array $groups ): array {
		$summary = array(
			'total_groups'           => count( $groups ),
			'generable_groups'       => 0,
			'insufficient_groups'    => 0,
			'isolated_participants'  => 0,
			'odd_groups'             => 0,
			'participants_in_groups' => 0,
		);

		foreach ( $groups as $group_entries ) {
			$count = count( (array) $group_entries );
			$summary['participants_in_groups'] += $count;
			if ( $count >= 2 ) {
				$summary['generable_groups']++;
			} else {
				$summary['insufficient_groups']++;
			}
			if ( 1 === $count ) {
				$summary['isolated_participants']++;
			}
			if ( $count > 1 && 1 === ( $count % 2 ) ) {
				$summary['odd_groups']++;
			}
		}

		return $summary;
	}

	private static function get_generation_category_key( $entry, array $settings = array() ): string {
		$reasons = self::get_entry_grouping_rejection_reasons( $entry, $settings );
		if ( ! empty( $reasons ) ) {
			return '';
		}

		return self::build_generation_group_key_from_components( self::get_generation_group_components( $entry, $settings ) );
	}

	private static function count_duplicate_fighter_numbers( array $entries ): int {
		$seen       = array();
		$duplicates = 0;

		foreach ( $entries as $entry ) {
			$number = absint(
				$entry->fighter_number
				?? $entry->competition_number
				?? $entry->dossard
				?? 0
			);
			if ( $number <= 0 ) {
				continue;
			}
			if ( isset( $seen[ $number ] ) ) {
				$duplicates++;
				continue;
			}
			$seen[ $number ] = true;
		}

		return $duplicates;
	}

	private static function get_global_fight_seconds( array $settings ): int {
		$minutes = max( 0, absint( $settings['fight_duration'] ?? 2 ) );
		$seconds = min( 59, max( 0, absint( $settings['fight_duration_seconds'] ?? 0 ) ) );
		$total   = ( $minutes * MINUTE_IN_SECONDS ) + $seconds;
		return max( 1, $total );
	}

	private static function get_global_pause_seconds( array $settings ): int {
		$minutes = max( 0, absint( $settings['break_duration'] ?? 1 ) );
		$seconds = min( 59, max( 0, absint( $settings['break_duration_seconds'] ?? 0 ) ) );
		return ( $minutes * MINUTE_IN_SECONDS ) + $seconds;
	}

	private static function extract_weighin_notes_meta( $row ): array {
		if ( ! $row || ! isset( $row->notes ) ) {
			return array();
		}
		$decoded = json_decode( (string) $row->notes, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	private static function normalize_categories( array $categories ): array {
		$normalized = array();

		foreach ( $categories as $category ) {
			$normalized[] = array(
				'id'         => (int) ( $category->id ?? 0 ),
				'name'       => sanitize_text_field( $category->name ?? '' ),
				'age_min'    => isset( $category->age_min ) ? (int) $category->age_min : null,
				'age_max'    => isset( $category->age_max ) ? (int) $category->age_max : null,
				'weight_min' => isset( $category->weight_min ) ? (float) $category->weight_min : null,
				'weight_max' => isset( $category->weight_max ) ? (float) $category->weight_max : null,
				'sex'        => sanitize_text_field( $category->sex ?? '' ),
				'level'      => sanitize_text_field( $category->level ?? '' ),
				'discipline' => sanitize_text_field( $category->discipline ?? '' ),
				'format'     => sanitize_text_field( $category->format ?? '' ),
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
		if ( '' === $level ) {
			$level = 'non_defini';
		}

		return array(
			'birth_date' => $birth_date,
			'sex'        => $sex,
			'weight'     => $weight,
			'level'      => $level,
		);
	}

	private static function build_fights_for_group( int $competition_id, int $category_id, array $entries, int $start_no, string $group_key = '' ): array {
		$fights     = array();
		$count      = count( $entries );
		$next_no    = $start_no;
		$bye_slots  = 0;
		$warnings   = array();
		if ( '' === $group_key && ! empty( $entries ) ) {
			$group_key = self::get_generation_category_key( $entries[0], self::get_settings( $competition_id ) );
		}
		$format     = self::determine_generation_format( $entries, self::get_settings( $competition_id ), array( 'category_id' => $category_id, 'group_key' => $group_key ) );
		$format_label = self::get_generation_format_label( $format );

		if ( class_exists( FightGenerationPremiumPlanner::class ) ) {
				$premium_plan = FightGenerationPremiumPlanner::plan(
					$entries,
					array(
						'competition_id' => $competition_id,
						'category_id' => $category_id,
						'start_no' => $start_no,
						'format' => 'auto',
						'group_key' => $group_key,
						'require_weight_data' => true,
						'require_category_data' => true,
					)
				);

			if ( is_array( $premium_plan ) ) {
				$premium_anomalies = isset( $premium_plan['anomalies'] ) && is_array( $premium_plan['anomalies'] )
					? $premium_plan['anomalies']
					: array();
				if ( ! empty( $premium_anomalies ) ) {
					do_action(
						'ufsc_competitions_fight_generation_anomalies',
						$competition_id,
						$category_id,
						$premium_anomalies
					);
				}

				$premium_fights = isset( $premium_plan['fights'] ) && is_array( $premium_plan['fights'] )
					? $premium_plan['fights']
					: array();
				$premium_fallback = ! empty( $premium_plan['fallback'] );
				if ( ! $premium_fallback && ! empty( $premium_fights ) ) {
					$max_no = $start_no;
					foreach ( $premium_fights as &$premium_fight ) {
						if ( '' !== $group_key && empty( $premium_fight['group_key'] ) ) {
							$premium_fight['group_key'] = $group_key;
						}
						$fight_no = isset( $premium_fight['fight_no'] ) ? absint( $premium_fight['fight_no'] ) : 0;
						if ( $fight_no > $max_no ) {
							$max_no = $fight_no;
						}
					}
					unset( $premium_fight );

					return array(
						'fights'  => $premium_fights,
						'next_no' => max( $next_no, $max_no + 1 ),
					);
				}

				$fallback_reason = sanitize_key( (string) ( $premium_plan['fallback_reason'] ?? '' ) );
				if ( '' !== $fallback_reason ) {
					do_action(
						'ufsc_competitions_fight_generation_anomalies',
						$competition_id,
						$category_id,
						array(
							array(
								'code' => 'planner_incomplete',
								'blocking' => true,
								'severity' => 'error',
								'meta' => array( 'fallback_reason' => $fallback_reason ),
							),
						)
					);
				}
			}
		}

		if ( 'combat_simple' === $format ) {
			$fights[] = self::build_fight_payload( $competition_id, $category_id, $next_no, $entries[0], $entries[1], 1, $group_key );
			if ( self::same_club( $entries[0], $entries[1] ) ) {
				$warnings[] = sprintf( 'Catégorie #%d : premier tour même club (best effort).', $category_id );
			}
			$next_no++;
		} elseif ( 'poule' === $format ) {
			$pool_preview = self::build_pool_preview( $entries, array( 'category_id' => $category_id ), self::get_settings( $competition_id ) );
			foreach ( (array) ( $pool_preview['fights'] ?? array() ) as $pfight ) {
				$pfight['competition_id'] = $competition_id;
				$pfight['category_id'] = $category_id;
				$pfight['fight_no'] = $next_no;
				$pfight['round_no'] = 1;
				$pfight['phase'] = (string) ( $pfight['phase'] ?? 'Poule' );
				$pfight['status'] = 'scheduled';
				if ( '' !== $group_key && empty( $pfight['group_key'] ) ) {
					$pfight['group_key'] = $group_key;
				}
				$fights[] = $pfight;
				$next_no++;
			}
			if ( ! empty( $pool_preview['warnings'] ) ) {
				$warnings = array_merge( $warnings, (array) $pool_preview['warnings'] );
			}
		} elseif ( 4 === $count && 'tableau' === $format ) {
			$fights[] = self::build_fight_payload( $competition_id, $category_id, $next_no, $entries[0], $entries[3], 1, $group_key );
			if ( self::same_club( $entries[0], $entries[3] ) ) {
				$warnings[] = sprintf( 'Catégorie #%d : quart/demi même club détecté.', $category_id );
			}
			$next_no++;
			$fights[] = self::build_fight_payload( $competition_id, $category_id, $next_no, $entries[1], $entries[2], 1, $group_key );
			if ( self::same_club( $entries[1], $entries[2] ) ) {
				$warnings[] = sprintf( 'Catégorie #%d : quart/demi même club détecté.', $category_id );
			}
			$next_no++;
			$fights[] = self::build_fight_payload( $competition_id, $category_id, $next_no, null, null, 2, $group_key );
			$next_no++;
		} elseif ( 'tableau_bye' === $format || 'tableau' === $format ) {
			$bracket_preview = self::build_bracket_preview( $entries, array( 'category_id' => $category_id ), self::get_settings( $competition_id ) );
			foreach ( (array) ( $bracket_preview['fights'] ?? array() ) as $bfight ) {
				$bfight['competition_id'] = $competition_id;
				$bfight['category_id'] = $category_id;
				$bfight['fight_no'] = $next_no;
				$bfight['round_no'] = (int) ( $bfight['round_no'] ?? $bfight['round'] ?? 1 );
				$bfight['status'] = (string) ( $bfight['status'] ?? 'scheduled' );
				if ( '' !== $group_key && empty( $bfight['group_key'] ) ) {
					$bfight['group_key'] = $group_key;
				}
				$fights[] = $bfight;
				$next_no++;
			}
			$bye_slots += (int) ( $bracket_preview['bye_count'] ?? 0 );
			if ( ! empty( $bracket_preview['warnings'] ) ) {
				$warnings = array_merge( $warnings, (array) $bracket_preview['warnings'] );
			}
		} elseif ( 'unsupported' === $format ) {
			$warnings[] = sprintf( 'Catégorie #%d : format non supporté (%s).', $category_id, $format_label );
		}

		return array(
			'fights'   => $fights,
			'next_no'  => $next_no,
			'bye_slots' => $bye_slots,
			'warnings' => $warnings,
		);
	}

	private static function determine_generation_format( array $entries, array $settings, array $group = array() ): string {
		$count = count( $entries );
		$allow_pool = ! empty( $settings['prefer_round_robin_for_3'] ) || ! empty( $settings['prefer_pool_for_4_6'] );
		if ( $count <= 1 ) { return 'single'; }
		if ( 2 === $count ) { return 'combat_simple'; }
		if ( $count >= 3 && $count <= 6 && $allow_pool ) { return 'poule'; }
		if ( 4 === $count || 8 === $count ) { return 'tableau'; }
		if ( $count >= 3 && $count <= 32 ) { return 'tableau_bye'; }
		return 'unsupported';
	}

	private static function get_generation_format_label( string $format ): string {
		switch ( sanitize_key( $format ) ) {
			case 'combat_simple': return 'Combat simple';
			case 'poule': return 'Poule';
			case 'tableau': return 'Tableau';
			case 'tableau_bye': return 'Tableau avec BYE';
			case 'single': return 'Sans opposant';
			default: return 'Non supporté';
		}
	}

	private static function determine_bracket_size( int $fighter_count ): int {
		if ( $fighter_count <= 1 ) { return 0; }
		if ( 2 === $fighter_count ) { return 2; }
		if ( $fighter_count <= 4 ) { return 4; }
		if ( $fighter_count <= 8 ) { return 8; }
		if ( $fighter_count <= 16 ) { return 16; }
		if ( $fighter_count <= 32 ) { return 32; }
		return 0;
	}

	private static function build_bracket_preview( array $entries, array $group, array $settings ): array {
		$count = count( $entries );
		$size = self::determine_bracket_size( $count );
		$out = array( 'fights' => array(), 'bye_count' => 0, 'placeholder_count' => 0, 'warnings' => array(), 'bracket_size' => $size, 'rounds' => array() );
		if ( $size <= 0 || $size > 32 ) {
			$out['warnings'][] = 'Tableau supérieur à 32 non supporté dans ce lot.';
			return $out;
		}
		$round_labels = array( 2 => array( 1 => 'Finale' ), 4 => array( 1 => 'Demi-finales', 2 => 'Finale' ), 8 => array( 1 => 'Quarts', 2 => 'Demi-finales', 3 => 'Finale' ), 16 => array( 1 => 'Huitièmes', 2 => 'Quarts', 3 => 'Demi-finales', 4 => 'Finale' ), 32 => array( 1 => 'Seizièmes', 2 => 'Huitièmes', 3 => 'Quarts', 4 => 'Demi-finales', 5 => 'Finale' ) );
		$bracket = new BracketGenerator();
		$plan = $bracket->generate( $entries, $size );
		$r1 = array();
		foreach ( (array) ( $plan['matches'] ?? array() ) as $match ) {
			$is_bye = ! empty( $match['is_bye'] );
			$r1[] = array(
				'phase' => $round_labels[ $size ][1] ?? 'Tour 1',
				'round' => 1,
				'round_no' => 1,
				'round_label' => $round_labels[ $size ][1] ?? 'Tour 1',
				'type' => $is_bye ? 'bye' : 'fight',
				'status' => $is_bye ? 'bye' : 'scheduled',
				'red_entry_id' => (int) ( $match['red']->id ?? 0 ),
				'blue_entry_id' => (int) ( $match['blue']->id ?? 0 ),
				'red_label' => self::entry_label( $match['red'] ?? null ),
				'blue_label' => $is_bye ? 'BYE — Qualifié automatiquement' : self::entry_label( $match['blue'] ?? null ),
			);
			if ( $is_bye ) { $out['bye_count']++; }
		}
		$out['fights'] = array_merge( $out['fights'], $r1 );
		$prev_count = count( $r1 );
		$round_start_no = 1;
		$round_no = 2;
		while ( $prev_count > 1 ) {
			$current = (int) floor( $prev_count / 2 );
			for ( $i = 0; $i < $current; $i++ ) {
				$src_a = $round_start_no + ( $i * 2 );
				$src_b = $round_start_no + ( $i * 2 ) + 1;
				$out['fights'][] = array(
					'phase' => $round_labels[ $size ][ $round_no ] ?? ( 'Tour ' . $round_no ),
					'round' => $round_no,
					'round_no' => $round_no,
					'round_label' => $round_labels[ $size ][ $round_no ] ?? ( 'Tour ' . $round_no ),
					'type' => 'placeholder',
					'status' => 'placeholder',
					'red_label' => 'Vainqueur combat ' . $src_a,
					'blue_label' => 'Vainqueur combat ' . $src_b,
					'source_red_fight_no' => $src_a,
					'source_blue_fight_no' => $src_b,
				);
				$out['placeholder_count']++;
			}
			$round_start_no += $prev_count;
			$prev_count = $current;
			$round_no++;
		}
		$out['warnings'][] = 'Propagation complète du tableau à finaliser dans un lot suivant.';
		return $out;
	}

	private static function build_pool_preview( array $entries, array $group, array $settings ): array {
		$pool_size = count( $entries );
		$pairs = self::round_robin_pairs( $entries );
		$pairs = self::order_round_robin_pairs( $pairs );
		$fights = array();
		$warnings = array();
		$seen = array();
		$last_entry = 0;
		foreach ( $pairs as $idx => $pair ) {
			$red_id = (int) ( $pair['red']->id ?? 0 );
			$blue_id = (int) ( $pair['blue']->id ?? 0 );
			if ( $red_id <= 0 || $blue_id <= 0 || $red_id === $blue_id ) {
				continue;
			}
			$key = $red_id < $blue_id ? ( $red_id . '-' . $blue_id ) : ( $blue_id . '-' . $red_id );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			if ( $last_entry > 0 && ( $red_id === $last_entry || $blue_id === $last_entry ) ) {
				$warnings[] = 'Repos athlète à contrôler : certains combattants ont deux combats rapprochés.';
			}
			$fights[] = array(
				'phase' => 'Poule',
				'round' => 1,
				'round_label' => 'Poule',
				'type' => 'fight',
				'status' => 'scheduled',
				'red_entry_id' => $red_id,
				'blue_entry_id' => $blue_id,
				'red_label' => self::entry_label( $pair['red'] ?? null ),
				'blue_label' => self::entry_label( $pair['blue'] ?? null ),
			);
			$last_entry = $blue_id;
		}
		if ( self::pool_has_same_club( $entries ) ) {
			$warnings[] = 'Attention : plusieurs combattants du même club sont présents dans cette poule.';
		}
		$warnings = array_values( array_unique( $warnings ) );
		return array(
			'fights' => $fights,
			'fight_count' => count( $fights ),
			'warnings' => $warnings,
			'pool_size' => $pool_size,
			'rounds' => array( 'Poule' ),
		);
	}

	private static function order_round_robin_pairs( array $pairs ): array {
		$ordered = array();
		$remaining = array_values( $pairs );
		$last_entry = 0;
		while ( ! empty( $remaining ) ) {
			$pick = 0;
			foreach ( $remaining as $i => $pair ) {
				$red_id = (int) ( $pair['red']->id ?? 0 );
				$blue_id = (int) ( $pair['blue']->id ?? 0 );
				if ( $last_entry > 0 && $red_id !== $last_entry && $blue_id !== $last_entry ) {
					$pick = $i;
					break;
				}
			}
			$chosen = $remaining[ $pick ];
			$ordered[] = $chosen;
			$last_entry = (int) ( $chosen['blue']->id ?? 0 );
			array_splice( $remaining, $pick, 1 );
		}
		return $ordered;
	}

	private static function pool_has_same_club( array $entries ): bool {
		$clubs = array();
		foreach ( $entries as $entry ) {
			$key = (int) ( $entry->club_id ?? 0 );
			if ( $key <= 0 ) {
				$key = crc32( strtolower( sanitize_text_field( (string) ( $entry->club_name ?? '' ) ) ) );
			}
			if ( isset( $clubs[ $key ] ) ) {
				return true;
			}
			$clubs[ $key ] = true;
		}
		return false;
	}

	private static function entry_label( $entry ): string {
		if ( ! is_object( $entry ) ) { return 'TBD'; }
		$ln = sanitize_text_field( (string) ( $entry->licensee_last_name ?? $entry->last_name ?? '' ) );
		$fn = sanitize_text_field( (string) ( $entry->licensee_first_name ?? $entry->first_name ?? '' ) );
		$label = trim( $ln . ' ' . $fn );
		return '' === $label ? ( 'Entrée #' . (int) ( $entry->id ?? 0 ) ) : $label;
	}

	private static function normalize_preview_fights( array $fights ): array {
		$normalized = array();
		$index = 1;
		foreach ( $fights as $fight ) {
			$fight = is_array( $fight ) ? $fight : array();
			$type = sanitize_key( (string) ( $fight['type'] ?? '' ) );
			if ( '' === $type ) {
				$type = ! empty( $fight['is_bye'] ) ? 'bye' : ( 'placeholder' === (string) ( $fight['status'] ?? '' ) ? 'placeholder' : 'fight' );
			}
			$status = (string) ( $fight['status'] ?? ( 'bye' === $type ? 'bye' : ( 'placeholder' === $type ? 'placeholder' : 'scheduled' ) ) );
			$fight['preview_number'] = (int) $index;
			$fight['round'] = (int) ( $fight['round_no'] ?? 1 );
			$fight['group_label'] = (string) ( $fight['group_key'] ?? ( $fight['category_label'] ?? '' ) );
			$fight['red_corner'] = (string) ( $fight['red_label'] ?? ( $fight['red_entry_id'] ?? '' ) );
			$fight['blue_corner'] = (string) ( $fight['blue_label'] ?? ( $fight['blue_entry_id'] ?? '' ) );
			$fight['type'] = $type;
			$fight['status'] = $status;
			$fight['surface_name'] = (string) ( $fight['ring'] ?? '' );
			$fight['scheduled_order'] = (int) ( $fight['fight_no'] ?? $index );
			$fight['scheduled_time'] = (string) ( $fight['scheduled_at'] ?? '' );
			$fight['warnings'] = isset( $fight['warnings'] ) && is_array( $fight['warnings'] ) ? $fight['warnings'] : array();
			$normalized[] = $fight;
			$index++;
		}
		return $normalized;
	}

	private static function enrich_groups_preview_from_fights( array $groups, array $fights ): array {
		$by_group = array();
		foreach ( $fights as $fight ) {
			$key = (string) ( $fight['group_key'] ?? '' );
			if ( '' === $key ) {
				$key = (string) ( $fight['category_id'] ?? '' );
			}
			if ( '' === $key ) { continue; }
			if ( ! isset( $by_group[ $key ] ) ) {
				$by_group[ $key ] = array( 'fight_count' => 0, 'bye_count' => 0, 'placeholder_count' => 0, 'round_labels' => array() );
			}
			$by_group[ $key ]['fight_count']++;
			if ( 'bye' === (string) ( $fight['type'] ?? '' ) ) { $by_group[ $key ]['bye_count']++; }
			if ( 'placeholder' === (string) ( $fight['type'] ?? '' ) ) { $by_group[ $key ]['placeholder_count']++; }
			$round_label = sanitize_text_field( (string) ( $fight['round_label'] ?? '' ) );
			if ( '' !== $round_label ) {
				$by_group[ $key ]['round_labels'][ $round_label ] = true;
			}
		}
		foreach ( $groups as $i => $group ) {
			$group = is_array( $group ) ? $group : array();
			$key = (string) ( $group['group_key'] ?? '' );
			$group['label'] = (string) ( $group['group_key'] ?? sprintf( 'Groupe %d', $i + 1 ) );
			$group['fighter_count'] = (int) ( $group['entries_count'] ?? count( (array) ( $group['athletes'] ?? array() ) ) );
			$group['format_label'] = self::get_generation_format_label( (string) ( $group['format'] ?? '' ) );
			$stats = $by_group[ $key ] ?? array();
			$group['fight_count'] = (int) ( $stats['fight_count'] ?? ( $group['estimated_fights'] ?? 0 ) );
			$group['bye_count'] = (int) ( $stats['bye_count'] ?? ( $group['bye_slots'] ?? 0 ) );
			$group['placeholder_count'] = (int) ( $stats['placeholder_count'] ?? 0 );
			$group['warnings'] = isset( $group['warnings'] ) && is_array( $group['warnings'] ) ? $group['warnings'] : array();
			$group['fighters'] = isset( $group['athletes'] ) && is_array( $group['athletes'] ) ? $group['athletes'] : array();
			if ( 'poule' === (string) ( $group['format'] ?? '' ) ) {
				$group['pool_size'] = (int) $group['fighter_count'];
				$group['pool_fight_count'] = (int) $group['fight_count'];
				$group['pool_rounds'] = array_keys( (array) ( $stats['round_labels'] ?? array() ) );
				$group['bye_count'] = 0;
				$group['placeholder_count'] = 0;
			}
			$groups[ $i ] = $group;
		}
		return $groups;
	}

	private static function same_club( $entry_a, $entry_b ): bool {
		$club_a = (int) ( $entry_a->club_id ?? 0 );
		$club_b = (int) ( $entry_b->club_id ?? 0 );
		if ( $club_a > 0 && $club_b > 0 ) {
			return $club_a === $club_b;
		}
		$name_a = sanitize_text_field( (string) ( $entry_a->club_name ?? '' ) );
		$name_b = sanitize_text_field( (string) ( $entry_b->club_name ?? '' ) );
		return '' !== $name_a && '' !== $name_b && 0 === strcasecmp( $name_a, $name_b );
	}

	private static function sort_groups_for_generation( array $groups, array $normalized_categories ): array {
		$category_map = array();
		foreach ( $normalized_categories as $category ) {
			$category_map[ (int) ( $category['id'] ?? 0 ) ] = $category;
		}

		$keys = array_keys( $groups );
		usort(
			$keys,
			static function ( $a, $b ) use ( $category_map ) {
				$category_a = $category_map[ (int) $a ] ?? array();
				$category_b = $category_map[ (int) $b ] ?? array();

				$age_a = isset( $category_a['age_min'] ) ? (int) $category_a['age_min'] : PHP_INT_MAX;
				$age_b = isset( $category_b['age_min'] ) ? (int) $category_b['age_min'] : PHP_INT_MAX;
				if ( $age_a !== $age_b ) {
					return $age_a <=> $age_b;
				}

				$weight_a = isset( $category_a['weight_min'] ) ? (float) $category_a['weight_min'] : INF;
				$weight_b = isset( $category_b['weight_min'] ) ? (float) $category_b['weight_min'] : INF;
				if ( $weight_a !== $weight_b ) {
					return $weight_a <=> $weight_b;
				}

				foreach ( array( 'sex', 'discipline', 'level', 'name' ) as $field ) {
					$value_a = sanitize_text_field( (string) ( $category_a[ $field ] ?? '' ) );
					$value_b = sanitize_text_field( (string) ( $category_b[ $field ] ?? '' ) );
					$cmp = strcasecmp( $value_a, $value_b );
					if ( 0 !== $cmp ) {
						return $cmp;
					}
				}

				return (int) $a <=> (int) $b;
			}
		);

		$sorted = array();
		foreach ( $keys as $category_id ) {
			$sorted[ $category_id ] = $groups[ $category_id ];
		}

		return $sorted;
	}

	private static function sort_entries_for_generation( array $entries ): array {
		usort(
			$entries,
			static function ( $entry_a, $entry_b ) {
				$weight_a = isset( $entry_a->weight_kg ) && '' !== (string) $entry_a->weight_kg ? (float) $entry_a->weight_kg : INF;
				$weight_b = isset( $entry_b->weight_kg ) && '' !== (string) $entry_b->weight_kg ? (float) $entry_b->weight_kg : INF;
				if ( $weight_a !== $weight_b ) {
					return $weight_a <=> $weight_b;
				}

				$sex_a = sanitize_text_field( (string) ( $entry_a->sex ?? $entry_a->gender ?? '' ) );
				$sex_b = sanitize_text_field( (string) ( $entry_b->sex ?? $entry_b->gender ?? '' ) );
				$sex_cmp = strcasecmp( $sex_a, $sex_b );
				if ( 0 !== $sex_cmp ) {
					return $sex_cmp;
				}

				$level_a = sanitize_text_field( (string) ( $entry_a->level ?? $entry_a->class ?? '' ) );
				$level_b = sanitize_text_field( (string) ( $entry_b->level ?? $entry_b->class ?? '' ) );
				$level_cmp = strcasecmp( $level_a, $level_b );
				if ( 0 !== $level_cmp ) {
					return $level_cmp;
				}

				$id_a = (int) ( $entry_a->id ?? 0 );
				$id_b = (int) ( $entry_b->id ?? 0 );
				return $id_a <=> $id_b;
			}
		);

		return $entries;
	}

	private static function round_robin_pairs( array $entries ): array {
		$pairs = array();
		$count = count( $entries );

		for ( $i = 0; $i < $count; $i++ ) {
			for ( $j = $i + 1; $j < $count; $j++ ) {
				$pairs[] = array(
					'red'  => $entries[ $i ],
					'blue' => $entries[ $j ],
				);
			}
		}

		return $pairs;
	}

	private static function build_fight_payload( int $competition_id, int $category_id, int $fight_no, $red_entry, $blue_entry, int $round_no, string $group_key = '' ): array {
		$payload = array(
			'competition_id'     => $competition_id,
			'category_id'        => $category_id,
			'fight_no'           => $fight_no,
			'ring'               => '',
			'round_no'           => $round_no,
			'red_entry_id'       => $red_entry ? (int) ( $red_entry->id ?? 0 ) : null,
			'blue_entry_id'      => $blue_entry ? (int) ( $blue_entry->id ?? 0 ) : null,
			'winner_entry_id'    => null,
			'status'             => 'scheduled',
			'result_method'      => '',
			'score_red'          => '',
			'score_blue'         => '',
			'scheduled_at'       => null,
			'timing_profile_id'  => null,
			'round_duration'     => null,
			'rounds'             => null,
			'break_duration'     => null,
			'fight_pause'        => null,
			'fight_duration'     => null,
		);
		if ( '' !== $group_key ) {
			$payload['group_key'] = $group_key;
		}

		return $payload;
	}

	private static function build_bye_payload( int $competition_id, int $category_id, int $fight_no, $red_entry, $blue_entry, int $round_no, string $group_key = '' ): array {
		$payload = self::build_fight_payload( $competition_id, $category_id, $fight_no, $red_entry, $blue_entry, $round_no, $group_key );
		$qualified_entry_id = $red_entry ? (int) ( $red_entry->id ?? 0 ) : ( $blue_entry ? (int) ( $blue_entry->id ?? 0 ) : 0 );

		$payload['status']          = FightRepository::STATUS_BYE;
		$payload['winner_entry_id'] = $qualified_entry_id > 0 ? $qualified_entry_id : null;
		$payload['result_method']   = '';
		$payload['score_red']       = '';
		$payload['score_blue']      = '';

		return $payload;
	}

	public static function assign_surfaces_and_schedule( array $fights, array $settings, int $competition_id ): array {
		$surface_labels = self::get_surface_labels( $settings );
		$surface_count  = max( 1, count( $surface_labels ) );

		$duration_minutes = max( 0, absint( $settings['fight_duration'] ?? 2 ) );
		$duration_seconds = min( 59, max( 0, absint( $settings['fight_duration_seconds'] ?? 0 ) ) );
		$break_minutes    = max( 0, absint( $settings['break_duration'] ?? 1 ) );
		$break_seconds    = min( 59, max( 0, absint( $settings['break_duration_seconds'] ?? 0 ) ) );
		$global_duration_seconds = ( $duration_minutes * MINUTE_IN_SECONDS ) + $duration_seconds;
		$global_break_seconds    = ( $break_minutes * MINUTE_IN_SECONDS ) + $break_seconds;
		if ( $global_duration_seconds < 1 ) {
			$global_duration_seconds = MINUTE_IN_SECONDS;
		}

		$meta     = CompetitionMeta::get( $competition_id );
		$start    = $meta['fights_start'] ?? '';
		$start_ts = 0;

		if ( $start ) {
			$timezone   = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( wp_timezone_string() ?: 'UTC' );
			$start_date = date_create_from_format( 'Y-m-d H:i:s', $start, $timezone );
			if ( $start_date instanceof \DateTimeInterface ) {
				$start_ts = $start_date->getTimestamp();
			}
		}

		$surface_times = array();
		for ( $i = 0; $i < $surface_count; $i++ ) {
			$surface_times[ $i ] = $start_ts;
		}

		$profiles     = array();
		$categories   = array();
		$competition  = null;
		$surface_types = self::get_surface_types_from_settings( $settings );

		if ( 'category' === ( $settings['timing_mode'] ?? 'global' ) ) {
			$profile_repo = new TimingProfileRepository();
			$profiles     = $profile_repo->list();

			$category_repo = new CategoryRepository();
			$categories    = $category_repo->list( array( 'view' => 'all', 'competition_id' => $competition_id ), 500, 0 );

			$competition_repo = new CompetitionRepository();
			$competition      = $competition_repo->get( $competition_id, true );
		}

		foreach ( $fights as $index => $fight ) {
			if ( FightRepository::STATUS_BYE === sanitize_key( (string) ( $fight['status'] ?? '' ) ) ) {
				$fights[ $index ]['ring'] = '';
				$fights[ $index ]['scheduled_at'] = null;
				continue;
			}

			$surface_index = 0;
			$min_time      = $surface_times[0];

			for ( $i = 1; $i < $surface_count; $i++ ) {
				if ( $surface_times[ $i ] < $min_time ) {
					$min_time      = $surface_times[ $i ];
					$surface_index = $i;
				}
			}

			$timing = self::resolve_fight_timing( $fight, $settings, $profiles, $categories, $competition, $surface_types );
			$fights[ $index ] = array_merge( $fight, $timing );

			$fights[ $index ]['ring'] = $surface_labels[ $surface_index ];
			if ( $start_ts ) {
				$fights[ $index ]['scheduled_at'] = date_i18n( 'Y-m-d H:i:s', $surface_times[ $surface_index ] );
				$step = (int) ( $timing['fight_duration_seconds'] ?? $global_duration_seconds ) + (int) ( $timing['fight_pause_seconds'] ?? $global_break_seconds );
				$surface_times[ $surface_index ] += $step;
			}
		}

		return $fights;
	}

	private static function normalize_surface_details( array $settings ): array {
		$surface_count = max( 1, absint( $settings['surface_count'] ?? 1 ) );
		$details       = array();
		$raw           = isset( $settings['surface_details'] ) && is_array( $settings['surface_details'] )
			? $settings['surface_details']
			: array();

		foreach ( $raw as $detail ) {
			if ( ! is_array( $detail ) ) {
				continue;
			}
			$name = sanitize_text_field( (string) ( $detail['name'] ?? '' ) );
			$type = sanitize_key( (string) ( $detail['type'] ?? 'tatami' ) );
			$type = in_array( $type, array( 'tatami', 'ring', 'aire' ), true ) ? $type : 'tatami';

			$details[] = array(
				'name' => $name,
				'type' => $type,
			);
		}

		if ( empty( $details ) && ! empty( $settings['surface_labels'] ) ) {
			$labels_raw = sanitize_text_field( (string) $settings['surface_labels'] );
			$labels     = array_filter( array_map( 'trim', explode( ',', $labels_raw ) ) );
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
		$clean         = array();

		for ( $i = 0; $i < $surface_count; $i++ ) {
			$detail = $details[ $i ] ?? array();
			$name   = sanitize_text_field( (string) ( $detail['name'] ?? '' ) );
			$type   = sanitize_key( (string) ( $detail['type'] ?? 'tatami' ) );
			$type   = in_array( $type, array( 'tatami', 'ring', 'aire' ), true ) ? $type : 'tatami';
			if ( '' === $name ) {
				$name = (string) ( $i + 1 );
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
					'name' => (string) ( $i + 1 ),
					'type' => 'tatami',
				);
				continue;
			}
			if ( '' === (string) ( $details[ $i ]['name'] ?? '' ) ) {
				$details[ $i ]['name'] = (string) ( $i + 1 );
			}
			$type = sanitize_key( (string) ( $details[ $i ]['type'] ?? 'tatami' ) );
			$details[ $i ]['type'] = in_array( $type, array( 'tatami', 'ring', 'aire' ), true ) ? $type : 'tatami';
		}

		return $details;
	}

	private static function get_surface_labels( array $settings ): array {
		$details = self::normalize_surface_details( $settings );
		$labels  = array();

		foreach ( $details as $index => $detail ) {
			$name          = sanitize_text_field( (string) ( $detail['name'] ?? '' ) );
			$labels[ $index ] = '' !== $name ? $name : sprintf( __( 'Surface %d', 'ufsc-licence-competition' ), $index + 1 );
		}

		return $labels;
	}

	private static function get_surface_types_from_settings( array $settings ): array {
		$details = self::normalize_surface_details( $settings );
		$types   = array();

		foreach ( $details as $detail ) {
			$type = sanitize_key( (string) ( $detail['type'] ?? '' ) );
			if ( in_array( $type, array( 'tatami', 'ring', 'aire' ), true ) ) {
				$types[] = $type;
			}
		}

		return array_values( array_unique( $types ) );
	}

	private static function resolve_fight_timing( array $fight, array $settings, array $profiles, array $categories, $competition = null, array $surface_types = array() ): array {
		$duration_minutes = max( 0, absint( $settings['fight_duration'] ?? 2 ) );
		$duration_seconds = min( 59, max( 0, absint( $settings['fight_duration_seconds'] ?? 0 ) ) );
		$pause_minutes    = max( 0, absint( $settings['break_duration'] ?? 1 ) );
		$pause_seconds    = min( 59, max( 0, absint( $settings['break_duration_seconds'] ?? 0 ) ) );
		$duration_total_seconds = ( $duration_minutes * MINUTE_IN_SECONDS ) + $duration_seconds;
		$pause_total_seconds    = ( $pause_minutes * MINUTE_IN_SECONDS ) + $pause_seconds;
		if ( $duration_total_seconds < 1 ) {
			$duration_total_seconds = MINUTE_IN_SECONDS;
		}

		$timing = array(
			'timing_profile_id' => array_key_exists( 'timing_profile_id', $fight ) ? absint( $fight['timing_profile_id'] ) : 0,
			'round_duration'    => array_key_exists( 'round_duration', $fight ) ? absint( $fight['round_duration'] ) : 0,
			'rounds'            => array_key_exists( 'rounds', $fight ) ? absint( $fight['rounds'] ) : 0,
			'break_duration'    => array_key_exists( 'break_duration', $fight ) ? absint( $fight['break_duration'] ) : 0,
			'fight_pause'       => array_key_exists( 'fight_pause', $fight ) ? absint( $fight['fight_pause'] ) : 0,
			'fight_duration'    => array_key_exists( 'fight_duration', $fight ) ? absint( $fight['fight_duration'] ) : 0,
			'fight_duration_seconds' => 0,
			'fight_pause_seconds'    => 0,
		);

		if ( $timing['fight_duration'] > 0 ) {
			if ( ! array_key_exists( 'fight_pause', $fight ) || null === $fight['fight_pause'] ) {
				$timing['fight_pause'] = $pause_minutes;
			}
			$timing['fight_duration_seconds'] = (int) $timing['fight_duration'] * MINUTE_IN_SECONDS;
			$timing['fight_pause_seconds']    = (int) $timing['fight_pause'] * MINUTE_IN_SECONDS;
			return $timing;
		}

		if ( 'category' !== ( $settings['timing_mode'] ?? 'global' ) || empty( $profiles ) ) {
			$timing['fight_duration']         = $duration_minutes;
			$timing['fight_pause']            = $pause_minutes;
			$timing['fight_duration_seconds'] = $duration_total_seconds;
			$timing['fight_pause_seconds']    = $pause_total_seconds;
			return $timing;
		}

		$category_id = absint( $fight['category_id'] ?? 0 );
		$category    = null;

		if ( $category_id ) {
			foreach ( $categories as $category_row ) {
				if ( (int) ( $category_row->id ?? 0 ) === $category_id ) {
					$category = $category_row;
					break;
				}
			}
		}

		$competition_type       = $competition ? CompetitionFilters::normalize_type_key( (string) ( $competition->type ?? '' ) ) : '';
		$competition_discipline = $competition ? sanitize_text_field( (string) ( $competition->discipline ?? '' ) ) : '';
		$profile                = self::match_timing_profile( $profiles, $category, $competition_type, $surface_types, $competition_discipline );

		if ( ! $profile ) {
			$timing['fight_duration']         = $duration_minutes;
			$timing['fight_pause']            = $pause_minutes;
			$timing['fight_duration_seconds'] = $duration_total_seconds;
			$timing['fight_pause_seconds']    = $pause_total_seconds;
			return $timing;
		}

		$round_duration = max( 1, absint( $profile->round_duration ?? 2 ) );
		$rounds         = max( 1, absint( $profile->rounds ?? 1 ) );
		$break_duration = max( 0, absint( $profile->break_duration ?? 0 ) );
		$fight_pause    = max( 0, absint( $profile->fight_pause ?? 0 ) );

		$timing['timing_profile_id'] = absint( $profile->id ?? 0 );
		$timing['round_duration']    = $round_duration;
		$timing['rounds']            = $rounds;
		$timing['break_duration']    = $break_duration;
		$timing['fight_pause']       = $fight_pause;
		$timing['fight_duration']    = ( $round_duration * $rounds ) + ( $rounds > 1 ? $break_duration * ( $rounds - 1 ) : 0 );
		$timing['fight_duration_seconds'] = (int) $timing['fight_duration'] * MINUTE_IN_SECONDS;
		$timing['fight_pause_seconds']    = (int) $timing['fight_pause'] * MINUTE_IN_SECONDS;

		return $timing;
	}

	private static function match_timing_profile( array $profiles, $category, string $competition_type = '', array $surface_types = array(), string $competition_discipline = '' ) {
		if ( empty( $profiles ) ) {
			return null;
		}

		$best       = null;
		$best_score = -1;

		$category_discipline = $category ? sanitize_text_field( $category->discipline ?? '' ) : '';
		if ( '' === $category_discipline ) {
			$category_discipline = sanitize_text_field( $competition_discipline );
		}
		$category_level      = $category ? sanitize_text_field( $category->level ?? '' ) : '';
		$category_format     = $category ? sanitize_text_field( $category->format ?? '' ) : '';
		$category_age_min    = $category && isset( $category->age_min ) ? (int) $category->age_min : null;
		$category_age_max    = $category && isset( $category->age_max ) ? (int) $category->age_max : null;

		$surface_types = array_filter( array_map( 'sanitize_key', $surface_types ) );

		foreach ( $profiles as $profile ) {
			$score = 0;

			$discipline              = sanitize_text_field( $profile->discipline ?? '' );
			$profile_competition_type = sanitize_key( (string) ( $profile->competition_type ?? '' ) );
			$profile_surface_type     = sanitize_key( (string) ( $profile->surface_type ?? '' ) );
			$level                   = sanitize_text_field( $profile->level ?? '' );
			$format                  = sanitize_text_field( $profile->format ?? '' );

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
				$best       = $profile;
				$best_score = $score;
			}
		}

		return $best;
	}

	private static function validate_draft( array $draft ): array {
		$competition_id = absint( $draft['competition_id'] ?? 0 );
		if ( ! $competition_id ) {
			return array(
				'ok'      => false,
				'message' => __( 'Brouillon invalide.', 'ufsc-licence-competition' ),
			);
		}

		$fights = $draft['fights'] ?? array();
		if ( ! is_array( $fights ) || ! $fights ) {
			return array(
				'ok'      => false,
				'message' => __( 'Aucun combat à enregistrer.', 'ufsc-licence-competition' ),
			);
		}

		foreach ( $fights as $fight ) {
			if ( empty( $fight['competition_id'] ) || empty( $fight['fight_no'] ) ) {
				return array(
					'ok'      => false,
					'message' => __( 'Brouillon incomplet : numéro de combat manquant.', 'ufsc-licence-competition' ),
				);
			}
		}

		return array(
			'ok'      => true,
			'message' => '',
		);
	}

	private static function get_lock_key( int $competition_id ): string {
		return self::LOCK_PREFIX . $competition_id;
	}
}
