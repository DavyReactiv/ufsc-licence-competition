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
		if ( ! $groups ) {
			$preview['eligible_entries']   = count( $eligible );
			$preview['excluded_unweighed'] = (int) ( $selection['excluded_unweighed'] ?? 0 );
			$preview['rejection_diagnostics'] = self::build_rejection_diagnostics( $entries, $selection );
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

				if ( ! isset( $groups[ $category_id ] ) ) {
					$groups[ $category_id ] = array();
				}
				$groups[ $category_id ][] = $entry;
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
			foreach ( $groups as $category_id => $group_entries ) {
				$group_entries = self::sort_entries_for_generation( $group_entries );
				$generated = self::build_fights_for_group( $competition_id, $category_id, $group_entries, $next_fight_no );
				$fights    = array_merge( $fights, $generated['fights'] );
				$next_fight_no = $generated['next_no'];
				$total_bye_slots += (int) ( $generated['bye_slots'] ?? 0 );
				if ( count( $group_entries ) < 2 ) {
					$ignored_groups++;
				}
			}

			$fights = self::assign_surfaces_and_schedule( $fights, $settings, (int) $competition_id );

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

			$draft = array(
				'competition_id' => $competition_id,
				'generated_at'   => current_time( 'mysql' ),
				'generated_by'   => get_current_user_id() ?: null,
				'settings'       => $settings,
				'stats'          => $stats,
				'warnings'       => $warnings,
				'fights'         => $fights,
			);

			self::save_draft( $competition_id, $draft );

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
			return array(
				'ok'      => false,
				'message' => __( 'Aucun brouillon disponible.', 'ufsc-licence-competition' ),
			);
		}

		$validation = self::validate_draft( $draft );
		if ( ! $validation['ok'] ) {
			return $validation;
		}

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

				return array(
					'ok'      => false,
					'message' => sprintf(
						/* translators: 1: table name, 2: SQL error, 3: competition id, 4: attempted inserts, 5: successful inserts, 6: columns */
						__( 'Échec insertion SQL. Table: %1$s | SQL: %2$s | competition_id: %3$d | inserts tentés: %4$d | inserts réussis: %5$d | colonnes: %6$s', 'ufsc-licence-competition' ),
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
		}
		$diagnostic['successful_inserts'] = $inserted;
		$diagnostic['failed_inserts'] = max( 0, $attempted - $inserted );
		$diagnostic['success'] = $inserted > 0;
		if ( $diagnostic['estimated_fights'] > 0 && 0 === $inserted ) {
			$diagnostic['success'] = false;
			$diagnostic['errors'][] = 'estimated_but_not_inserted';
		}
		$settings = self::get_settings( $competition_id );
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

	public static function generate_simple_pairing_fights( int $competition_id, array $settings = array() ): array {
		$fight_repo = new FightRepository();
		$regeneration_scope = $fight_repo->can_regenerate_scope( $competition_id );
		$existing_fights = self::get_existing_generation_blockers( $competition_id );
		if ( empty( $regeneration_scope['allowed'] ) || (int) ( $existing_fights['total'] ?? 0 ) > 0 ) {
			return array(
				'ok'      => false,
				'message' => __( 'Fallback simple pairing bloqué : des combats existent déjà pour cette compétition.', 'ufsc-licence-competition' ),
				'existing_fights' => (int) ( $existing_fights['total'] ?? 0 ),
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
			'rejected_category_weight_level'    => $sum( array( 'weight_missing', 'weight_class_missing' ) ),
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
			$rows[] = array(
				'group_key'         => (string) $group_key,
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
			sprintf( __( 'Catégorie manquante : %d', 'ufsc-licence-competition' ), (int) ( $reason_counts['weight_class_missing'] ?? 0 ) ),
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

	private static function get_generation_category_key( $entry, array $settings = array() ): string {
		$read = static function ( $entry, array $keys ): string {
			foreach ( $keys as $key ) {
				if ( is_object( $entry ) && isset( $entry->{$key} ) && '' !== trim( (string) $entry->{$key} ) ) {
					return sanitize_text_field( (string) $entry->{$key} );
				}
				if ( is_array( $entry ) && isset( $entry[$key] ) && '' !== trim( (string) $entry[$key] ) ) {
					return sanitize_text_field( (string) $entry[$key] );
				}
			}
			return '';
		};
		$category_id = absint( $read( $entry, array( 'category_id' ) ) );
		$discipline = self::normalize_discipline_for_generation( $read( $entry, array( 'discipline' ) ) );
		$sex = self::normalize_sex_value( $read( $entry, array( 'sex','sexe','gender','licensee_sex' ) ), $read( $entry, array( 'category','category_name','categorie','age_category','categorie_age' ) ) );
		$age_category = $read( $entry, array( 'category','category_name','categorie','age_category','categorie_age','category_label' ) );
		$weight_category = $read( $entry, array( 'weight_class','weight_category','weight_cat','categorie_poids','category_weight' ) );
		$level = $read( $entry, array( 'level','class','classe','niveau' ) );
		if ( '' === $age_category && $category_id <= 0 ) { return ''; }
		$key = array($discipline ?: 'na', $sex ?: 'x', $age_category ?: ('category_'.$category_id), $weight_category ?: 'poids_na');
		if ( ! empty( $settings['use_level_split'] ) ) { $key[] = ('' !== $level ? sanitize_key($level) : 'non_defini'); }
		return implode('|',$key);
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

	private static function build_fights_for_group( int $competition_id, int $category_id, array $entries, int $start_no ): array {
		$fights     = array();
		$count      = count( $entries );
		$next_no    = $start_no;
		$bye_slots  = 0;

		if ( class_exists( FightGenerationPremiumPlanner::class ) ) {
				$premium_plan = FightGenerationPremiumPlanner::plan(
					$entries,
					array(
						'competition_id' => $competition_id,
						'category_id' => $category_id,
						'start_no' => $start_no,
						'format' => 'auto',
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
					foreach ( $premium_fights as $premium_fight ) {
						$fight_no = isset( $premium_fight['fight_no'] ) ? absint( $premium_fight['fight_no'] ) : 0;
						if ( $fight_no > $max_no ) {
							$max_no = $fight_no;
						}
					}

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

		if ( 2 === $count ) {
			$fights[] = self::build_fight_payload( $competition_id, $category_id, $next_no, $entries[0], $entries[1], 1 );
			$next_no++;
		} elseif ( 3 === $count ) {
			foreach ( self::round_robin_pairs( $entries ) as $pair ) {
				$fights[] = self::build_fight_payload( $competition_id, $category_id, $next_no, $pair['red'], $pair['blue'], 1 );
				$next_no++;
			}
		} elseif ( 4 === $count ) {
			$fights[] = self::build_fight_payload( $competition_id, $category_id, $next_no, $entries[0], $entries[3], 1 );
			$next_no++;
			$fights[] = self::build_fight_payload( $competition_id, $category_id, $next_no, $entries[1], $entries[2], 1 );
			$next_no++;
			$fights[] = self::build_fight_payload( $competition_id, $category_id, $next_no, null, null, 2 );
			$next_no++;
		} elseif ( $count >= 5 && $count <= 8 ) {
			$bracket = new BracketGenerator();
			$plan    = $bracket->generate( $entries, 8 );
			foreach ( (array) ( $plan['matches'] ?? array() ) as $match ) {
				if ( ! empty( $match['is_bye'] ) ) {
					$fights[] = self::build_bye_payload( $competition_id, $category_id, $next_no, $match['red'] ?? null, $match['blue'] ?? null, 1 );
				} else {
					$fights[] = self::build_fight_payload( $competition_id, $category_id, $next_no, $match['red'] ?? null, $match['blue'] ?? null, 1 );
				}
					$next_no++;
				}
			$bye_slots += (int) ( $plan['bye_slots'] ?? 0 );
		} elseif ( $count >= 9 && $count <= 16 ) {
			$bracket = new BracketGenerator();
			$plan    = $bracket->generate( $entries, 16 );
			foreach ( (array) ( $plan['matches'] ?? array() ) as $match ) {
				if ( ! empty( $match['is_bye'] ) ) {
					$fights[] = self::build_bye_payload( $competition_id, $category_id, $next_no, $match['red'] ?? null, $match['blue'] ?? null, 1 );
				} else {
					$fights[] = self::build_fight_payload( $competition_id, $category_id, $next_no, $match['red'] ?? null, $match['blue'] ?? null, 1 );
				}
				$next_no++;
			}
			$bye_slots += (int) ( $plan['bye_slots'] ?? 0 );
		} elseif ( $count > 10 ) {
			$bracket = new BracketGenerator();
			$plan    = $bracket->generate( $entries );
			foreach ( (array) ( $plan['matches'] ?? array() ) as $match ) {
				if ( ! empty( $match['is_bye'] ) ) {
					$fights[] = self::build_bye_payload( $competition_id, $category_id, $next_no, $match['red'] ?? null, $match['blue'] ?? null, 1 );
				} else {
					$fights[] = self::build_fight_payload( $competition_id, $category_id, $next_no, $match['red'] ?? null, $match['blue'] ?? null, 1 );
				}
				$next_no++;
			}
			$bye_slots += (int) ( $plan['bye_slots'] ?? 0 );
		}

		return array(
			'fights'   => $fights,
			'next_no'  => $next_no,
			'bye_slots' => $bye_slots,
		);
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

	private static function build_fight_payload( int $competition_id, int $category_id, int $fight_no, $red_entry, $blue_entry, int $round_no ): array {
		return array(
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
	}

	private static function build_bye_payload( int $competition_id, int $category_id, int $fight_no, $red_entry, $blue_entry, int $round_no ): array {
		$payload = self::build_fight_payload( $competition_id, $category_id, $fight_no, $red_entry, $blue_entry, $round_no );
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
