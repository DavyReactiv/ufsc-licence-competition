<?php

namespace UFSC\Competitions\Services;

use UFSC\Competitions\Db;
use UFSC\Competitions\Repositories\CompetitionRepository;
use UFSC\Competitions\Repositories\EntryRepository;
use UFSC\Competitions\Repositories\FightRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central production safety checks for real competition data.
 *
 * This service is intentionally non-destructive: it only reads current state,
 * returns explicit blocking reasons, and logs refused sensitive actions.
 */
class CompetitionSafetyService {
	private $competitions;
	private $entries;
	private $fights;
	private $logger;

	public function __construct() {
		$this->competitions = new CompetitionRepository();
		$this->entries      = new EntryRepository();
		$this->fights       = new FightRepository();
		$this->logger       = new LogService();
	}

	public function assert_competition_ready( int $competition_id, string $action, array $context = array() ): array {
		$competition_id = absint( $competition_id );
		$action         = sanitize_key( $action );
		if ( $competition_id <= 0 ) {
			return $this->blocked( 0, $action, 'competition_invalid', __( 'Compétition invalide : aucune action sensible ne peut être exécutée sans compétition sélectionnée.', 'ufsc-licence-competition' ), $context );
		}

		$competition = $this->competitions->get( $competition_id, true );
		if ( ! $competition ) {
			return $this->blocked( $competition_id, $action, 'competition_not_found', __( 'Compétition introuvable : action bloquée pour protéger les données.', 'ufsc-licence-competition' ), $context );
		}

		if ( method_exists( $this->competitions, 'assert_competition_in_scope' ) ) {
			$this->competitions->assert_competition_in_scope( $competition_id );
		}

		if ( $this->is_competition_locked_status( $competition ) ) {
			return $this->blocked( $competition_id, $action, 'competition_locked_status', __( 'Action bloquée : cette compétition est verrouillée, terminée ou archivée.', 'ufsc-licence-competition' ), $context + array( 'status' => (string) ( $competition->status ?? '' ) ) );
		}

		return array( 'ok' => true, 'message' => '' );
	}

	public function guard_fight_generation( int $competition_id, string $action, array $context = array() ): array {
		$base = $this->assert_competition_ready( $competition_id, $action, $context );
		if ( empty( $base['ok'] ) ) {
			return $base;
		}

		$summary = $this->get_protection_summary( $competition_id );
		if ( (int) $summary['locked_fights'] > 0 ) {
			return $this->blocked( $competition_id, $action, 'locked_fights_present', __( 'Génération bloquée : des combats verrouillés existent déjà. Aucune régénération ne doit modifier une compétition verrouillée.', 'ufsc-licence-competition' ), $context + $summary );
		}
		if ( (int) $summary['results_entered'] > 0 ) {
			return $this->blocked( $competition_id, $action, 'results_present', __( 'Génération bloquée : des résultats sont déjà saisis. Utilisez une action sensible dédiée après sauvegarde et validation responsable.', 'ufsc-licence-competition' ), $context + $summary );
		}

		return array( 'ok' => true, 'message' => '', 'summary' => $summary );
	}

	public function guard_weighin_mutation( int $competition_id, int $entry_id, string $action, array $context = array() ): array {
		$base = $this->assert_competition_ready( $competition_id, $action, $context + array( 'entry_id' => $entry_id ) );
		if ( empty( $base['ok'] ) ) {
			return $base;
		}

		$entry_id = absint( $entry_id );
		$entry    = $this->entries->get( $entry_id, true );
		if ( ! $entry || (int) ( $entry->competition_id ?? 0 ) !== absint( $competition_id ) ) {
			return $this->blocked( $competition_id, $action, 'entry_competition_mismatch', __( 'Action bloquée : l’inscription ne correspond pas à la compétition sélectionnée.', 'ufsc-licence-competition' ), $context + array( 'entry_id' => $entry_id ) );
		}

		$impacts = $this->get_entry_fight_impacts( $competition_id, $entry_id );
		if ( $impacts['locked'] > 0 || $impacts['results'] > 0 ) {
			return $this->blocked( $competition_id, $action, 'weighin_impacts_results_or_locked_fights', __( 'Pesée bloquée : ce combattant est lié à un combat avec résultat ou verrouillé. Utilisez une procédure de correction sensible.', 'ufsc-licence-competition' ), $context + array( 'entry_id' => $entry_id, 'impacts' => $impacts ) );
		}

		return array( 'ok' => true, 'message' => '', 'impacts' => $impacts );
	}

	public function guard_fight_result_mutation( int $competition_id, int $fight_id, string $action, bool $is_correction = false, array $context = array() ): array {
		$base = $this->assert_competition_ready( $competition_id, $action, $context + array( 'fight_id' => $fight_id ) );
		if ( empty( $base['ok'] ) ) {
			return $base;
		}

		$fight_id = absint( $fight_id );
		$fight    = $this->fights->get( $fight_id, true );
		if ( ! $fight || (int) ( $fight->competition_id ?? 0 ) !== absint( $competition_id ) ) {
			return $this->blocked( $competition_id, $action, 'fight_competition_mismatch', __( 'Action bloquée : le combat ne correspond pas à la compétition sélectionnée.', 'ufsc-licence-competition' ), $context + array( 'fight_id' => $fight_id ) );
		}

		$status = $this->fights->get_effective_fight_status( $fight );
		if ( in_array( $status, array( FightRepository::STATUS_BYE, FightRepository::STATUS_PLACEHOLDER, FightRepository::STATUS_TRASHED ), true ) ) {
			return $this->blocked( $competition_id, $action, 'unsupported_fight_status', __( 'Résultat bloqué : ce combat est un BYE, un placeholder ou dans la corbeille.', 'ufsc-licence-competition' ), $context + array( 'fight_id' => $fight_id, 'status' => $status ) );
		}
		if ( FightRepository::STATUS_LOCKED === $status ) {
			return $this->blocked( $competition_id, $action, 'locked_fight_result', __( 'Résultat bloqué : ce combat est verrouillé et ne peut pas être modifié directement.', 'ufsc-licence-competition' ), $context + array( 'fight_id' => $fight_id, 'status' => $status ) );
		}
		if ( ! $is_correction && $this->fight_has_result_payload( $fight ) ) {
			return $this->blocked( $competition_id, $action, 'existing_result_requires_correction', __( 'Résultat déjà saisi : utilisez le workflow de correction tracée avec motif obligatoire.', 'ufsc-licence-competition' ), $context + array( 'fight_id' => $fight_id, 'status' => $status ) );
		}

		return array( 'ok' => true, 'message' => '', 'fight' => $fight, 'status' => $status );
	}

	public function get_protection_summary( int $competition_id ): array {
		$competition_id = absint( $competition_id );
		$competition    = $competition_id ? $this->competitions->get( $competition_id, true ) : null;
		$entries_count  = $competition_id ? (int) $this->entries->count( array( 'view' => 'all', 'competition_id' => $competition_id ) ) : 0;
		$fights         = $competition_id ? $this->fights->list( array( 'view' => 'all', 'competition_id' => $competition_id ), 5000, 0 ) : array();

		$fights_count    = count( $fights );
		$results_entered = 0;
		$locked_fights   = 0;
		$running_fights  = 0;
		foreach ( $fights as $fight ) {
			$status = $this->fights->get_effective_fight_status( $fight );
			if ( FightRepository::STATUS_LOCKED === $status ) {
				$locked_fights++;
			}
			if ( FightRepository::STATUS_RUNNING === $status ) {
				$running_fights++;
			}
			if ( $this->fight_has_result_payload( $fight ) || in_array( $status, array( FightRepository::STATUS_COMPLETED, FightRepository::STATUS_LOCKED ), true ) ) {
				$results_entered++;
			}
		}

		$is_locked_status = $competition ? $this->is_competition_locked_status( $competition ) : false;
		$blocked_actions = array();
		if ( $entries_count > 0 ) {
			$blocked_actions[] = __( 'suppression directe de compétition', 'ufsc-licence-competition' );
		}
		if ( $fights_count > 0 ) {
			$blocked_actions[] = __( 'régénération destructive des combats', 'ufsc-licence-competition' );
		}
		if ( $results_entered > 0 || $locked_fights > 0 ) {
			$blocked_actions[] = __( 'régénération après résultats/verrous', 'ufsc-licence-competition' );
			$blocked_actions[] = __( 'modification directe des pesées liées', 'ufsc-licence-competition' );
		}
		if ( $is_locked_status ) {
			$blocked_actions[] = __( 'actions sensibles sur compétition terminée/verrouillée', 'ufsc-licence-competition' );
		}

		return array(
			'competition_id'      => $competition_id,
			'competition_status'  => sanitize_key( (string) ( $competition->status ?? '' ) ),
			'is_real_data'        => $entries_count > 0 || $fights_count > 0,
			'is_locked_status'    => $is_locked_status,
			'entries_count'       => $entries_count,
			'fights_count'        => $fights_count,
			'results_entered'     => $results_entered,
			'locked_fights'       => $locked_fights,
			'running_fights'      => $running_fights,
			'blocked_actions'     => array_values( array_unique( $blocked_actions ) ),
			'protection_status'   => $this->resolve_protection_status( $entries_count, $fights_count, $results_entered, $locked_fights, $is_locked_status ),
		);
	}

	public function log_blocked_action( int $competition_id, string $action, string $reason, array $context = array() ): void {
		$this->logger->audit(
			'sensitive_action_blocked',
			absint( $competition_id ),
			'competition',
			absint( $competition_id ),
			array(
				'action'  => sanitize_key( $action ),
				'reason'  => sanitize_key( $reason ),
				'context' => $this->sanitize_context( $context ),
			)
		);
	}

	private function blocked( int $competition_id, string $action, string $reason, string $message, array $context = array() ): array {
		$this->log_blocked_action( $competition_id, $action, $reason, $context );
		return array(
			'ok'      => false,
			'reason'  => sanitize_key( $reason ),
			'message' => $message,
		);
	}

	private function is_competition_locked_status( $competition ): bool {
		$status = sanitize_key( (string) ( $competition->status ?? '' ) );
		return in_array( $status, array( 'locked', 'closed', 'archived', 'completed', 'finished', 'terminee', 'termine' ), true );
	}

	private function get_entry_fight_impacts( int $competition_id, int $entry_id ): array {
		$fights = $this->fights->list( array( 'view' => 'all', 'competition_id' => $competition_id ), 5000, 0 );
		$impacts = array( 'total' => 0, 'results' => 0, 'locked' => 0, 'fight_ids' => array() );
		foreach ( $fights as $fight ) {
			if ( (int) ( $fight->red_entry_id ?? 0 ) !== $entry_id && (int) ( $fight->blue_entry_id ?? 0 ) !== $entry_id && (int) ( $fight->winner_entry_id ?? 0 ) !== $entry_id ) {
				continue;
			}
			$impacts['total']++;
			$impacts['fight_ids'][] = (int) ( $fight->id ?? 0 );
			$status = $this->fights->get_effective_fight_status( $fight );
			if ( FightRepository::STATUS_LOCKED === $status ) {
				$impacts['locked']++;
			}
			if ( $this->fight_has_result_payload( $fight ) || in_array( $status, array( FightRepository::STATUS_COMPLETED, FightRepository::STATUS_LOCKED ), true ) ) {
				$impacts['results']++;
			}
		}
		return $impacts;
	}

	private function fight_has_result_payload( $fight ): bool {
		foreach ( array( 'winner_entry_id', 'result_method', 'score_red', 'score_blue', 'result', 'result_note' ) as $field ) {
			$value = is_array( $fight ) ? ( $fight[ $field ] ?? '' ) : ( $fight->{$field} ?? '' );
			if ( '' !== trim( (string) $value ) && '0' !== trim( (string) $value ) ) {
				return true;
			}
		}
		return false;
	}

	private function resolve_protection_status( int $entries_count, int $fights_count, int $results_entered, int $locked_fights, bool $is_locked_status ): string {
		if ( $is_locked_status || $locked_fights > 0 || $results_entered > 0 ) {
			return __( 'Protection maximale : résultats ou verrous présents', 'ufsc-licence-competition' );
		}
		if ( $fights_count > 0 ) {
			return __( 'Protection élevée : combats existants', 'ufsc-licence-competition' );
		}
		if ( $entries_count > 0 ) {
			return __( 'Protection active : inscriptions réelles détectées', 'ufsc-licence-competition' );
		}
		return __( 'Préparation : aucune donnée réelle détectée', 'ufsc-licence-competition' );
	}

	private function sanitize_context( array $context ): array {
		$clean = array();
		foreach ( $context as $key => $value ) {
			$key = is_int( $key ) ? $key : sanitize_key( (string) $key );
			if ( is_array( $value ) ) {
				$clean[ $key ] = $this->sanitize_context( $value );
			} elseif ( is_scalar( $value ) || null === $value ) {
				$clean[ $key ] = is_string( $value ) ? sanitize_textarea_field( $value ) : $value;
			}
		}
		return $clean;
	}
}
