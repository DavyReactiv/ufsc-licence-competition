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

		$has_stored_settings        = ! empty( $stored );
		$settings                  = array_merge( $defaults, $stored );
		$settings['surface_count']  = min( 32, max( 1, absint( $settings['surface_count'] ) ) );
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
			$settings['surface_count'] = min( 32, max( 1, absint( $data['surface_count'] ) ) );
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
		$groups     = self::group_entries_by_category( $eligible );
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
				$reason_messages = array(
					'status_not_approved'   => __( 'Validation admin requise.', 'ufsc-licence-competition' ),
					'weight_missing'        => __( 'Poids manquant.', 'ufsc-licence-competition' ),
					'weight_class_missing'  => __( 'Catégorie de poids manquante.', 'ufsc-licence-competition' ),
					'license_missing'       => __( 'Licence manquante.', 'ufsc-licence-competition' ),
					'club_missing'          => __( 'Club manquant.', 'ufsc-licence-competition' ),
					'entry_deleted'         => __( 'Inscription supprimée.', 'ufsc-licence-competition' ),
					'weighin_missing'       => __( 'Pesée valide requise.', 'ufsc-licence-competition' ),
					'reclass_pending'       => __( 'Reclassement pesée en attente.', 'ufsc-licence-competition' ),
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
					'ok'      => false,
					'message' => $message,
					'stats'   => array(
						'total_entries'      => $total_entries,
						'eligible_entries'   => 0,
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
			foreach ( $groups as $category_id => $group_entries ) {
				$group_entries = self::sort_entries_for_generation( $group_entries );
				$generated = self::build_fights_for_group( $competition_id, $category_id, $group_entries, $next_fight_no );
				$fights    = array_merge( $fights, $generated['fights'] );
				$next_fight_no = $generated['next_no'];
				$total_bye_slots += (int) ( $generated['bye_slots'] ?? 0 );
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
				$message = __( '0 combat généré : vérifiez les catégories, statuts d’inscription et pesées.', 'ufsc-licence-competition' );
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

		$apply_mode = 'replace' === $apply_mode ? 'replace' : 'append';
		if ( 'replace' === $apply_mode ) {
			return array(
				'ok'      => false,
				'message' => __( 'Le mode remplacement n’est pas disponible.', 'ufsc-licence-competition' ),
			);
		}

		$draft = self::get_draft( $competition_id );
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

		foreach ( $prepared_fights as $fight ) {
			$fight_repo->insert( $fight );
		}

		self::clear_draft( $competition_id );

		return array(
			'ok'      => true,
			'message' => __( 'Combats enregistrés.', 'ufsc-licence-competition' ),
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

		foreach ( $entries as $entry ) {
			$entry_id = (int) ( $entry->id ?? 0 );

			if ( function_exists( 'ufsc_lc_is_entry_eligible_from_entry' ) ) {
				$eligibility = ufsc_lc_is_entry_eligible_from_entry( $entry, 'fights' );
			} else {
				$eligibility = function_exists( 'ufsc_lc_is_entry_eligible' )
					? ufsc_lc_is_entry_eligible( $entry_id, 'fights' )
					: array( 'eligible' => false, 'reasons' => array( 'status_not_approved' ) );
			}

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
		);
	}

	private static function build_rejection_diagnostics( array $entries, array $selection ): array {
		$reason_counts = (array) ( $selection['reason_counts'] ?? array() );
		$total_rejected = 0;
		foreach ( $reason_counts as $count ) {
			$total_rejected += (int) $count;
		}

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
			'rejected_missing_sport_data'       => $sum( array( 'external_identity_incomplete', 'external_missing_required_sport_data', 'external_birth_date_invalid', 'external_birth_date_future', 'external_sex_invalid', 'external_minor_guardian_missing' ) ),
			'rejected_category_weight_level'    => $sum( array( 'weight_missing', 'weight_class_missing' ) ),
			'rejected_discipline'               => $sum( array( 'discipline_missing' ) ),
			'rejected_club'                     => $sum( array( 'club_missing' ) ),
			'rejected_incomplete_fighter_data'  => $sum( array( 'entry_missing', 'entry_not_found', 'entry_deleted' ) ),
			'rejected_duplicate_fighter_number' => self::count_duplicate_fighter_numbers( $entries ),
		);
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
			'settings_saved_at' => '',
		);
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

	private static function group_entries_by_category( array $entries ): array {
		$groups = array();

		foreach ( $entries as $entry ) {
			$category_id = 0;
			if ( is_object( $entry ) ) {
				$category_id = absint( $entry->category_id ?? 0 );
			} elseif ( is_array( $entry ) ) {
				$category_id = absint( $entry['category_id'] ?? 0 );
			}

			if ( ! $category_id ) {
				continue;
			}

			if ( ! isset( $groups[ $category_id ] ) ) {
				$groups[ $category_id ] = array();
			}
			$groups[ $category_id ][] = $entry;
		}

		return $groups;
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
			$type = in_array( $type, array( 'tatami', 'ring' ), true ) ? $type : 'tatami';

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
			$type   = in_array( $type, array( 'tatami', 'ring' ), true ) ? $type : 'tatami';
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
			$details[ $i ]['type'] = in_array( $type, array( 'tatami', 'ring' ), true ) ? $type : 'tatami';
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
			if ( in_array( $type, array( 'tatami', 'ring' ), true ) ) {
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
