<?php

namespace UFSC\Competitions\Services;

use UFSC\Competitions\Capabilities;
use UFSC\Competitions\Repositories\FightRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ResultService {
	private $fights;
	private $logger;

	public function __construct() {
		$this->fights = new FightRepository();
		$this->logger = new LogService();
	}

	public function validate_result_payload( $fight, array $payload, bool $is_correction = false ): array {
		$fight  = FightResultPersistence::normalize( $fight );
		$status = $this->fights->get_effective_fight_status( $fight );
		if ( in_array( $status, array( FightRepository::STATUS_BYE, FightRepository::STATUS_PLACEHOLDER, FightRepository::STATUS_TRASHED ), true ) ) {
			return array( 'ok' => false, 'error' => 'unsupported_fight_status' );
		}
		if ( FightRepository::STATUS_LOCKED === $status ) {
			return array( 'ok' => false, 'error' => 'locked_result_cannot_be_modified_directly' );
		}
		if ( FightRepository::STATUS_COMPLETED === $status && ! $is_correction ) {
			return array( 'ok' => false, 'error' => 'completed_requires_correction_flow' );
		}

		$result_type = sanitize_key( (string) ( $payload['result_type'] ?? $payload['result_method'] ?? '' ) );
		$allowed     = array( 'points', 'arret_arbitre', 'forfait', 'abandon', 'disqualification', 'absence', 'no_contest', 'litige', 'annule' );
		if ( '' !== $result_type && ! in_array( $result_type, $allowed, true ) ) {
			return array( 'ok' => false, 'error' => 'invalid_result_type' );
		}

		$winner_entry_id = absint( $payload['winner_entry_id'] ?? 0 );
		$red_entry_id    = absint( $fight->red_entry_id ?? 0 );
		$blue_entry_id   = absint( $fight->blue_entry_id ?? 0 );
		$reason          = trim( (string) ( $payload['reason'] ?? '' ) );
		$winner_optional = in_array( $result_type, array( 'no_contest', 'litige', 'annule' ), true );
		if ( ! $winner_optional && $winner_entry_id <= 0 ) {
			return array( 'ok' => false, 'error' => 'winner_required' );
		}
		if ( $winner_entry_id > 0 && $winner_entry_id !== $red_entry_id && $winner_entry_id !== $blue_entry_id ) {
			return array( 'ok' => false, 'error' => 'winner_mismatch' );
		}
		if ( $is_correction || in_array( $result_type, array( 'litige', 'disqualification', 'no_contest' ), true ) ) {
			if ( '' === $reason ) {
				return array( 'ok' => false, 'error' => 'reason_required' );
			}
		}

		foreach ( array( 'score_red', 'score_blue' ) as $score_key ) {
			$score = trim( (string) ( $payload[ $score_key ] ?? '' ) );
			if ( '' === $score ) {
				continue;
			}
			if ( ! preg_match( '/^-?[0-9]+([\.,][0-9]+)?$/', $score ) ) {
				return array( 'ok' => false, 'error' => 'invalid_score_' . $score_key );
			}
		}

		return array( 'ok' => true );
	}

	public function record_result( int $fight_id, array $payload ): array {
		return $this->with_fight_lock(
			$fight_id,
			'record_result',
			function () use ( $fight_id, $payload ) {
				return DatabaseTransaction::run(
					function () use ( $fight_id, $payload ) {
						$fight = FightResultPersistence::normalize( $this->fights->get( $fight_id, true ) );
						if ( ! $fight ) {
							return array( 'ok' => false, 'error' => 'fight_not_found' );
						}

						$revision = $this->validate_expected_revision( $fight, $payload );
						if ( empty( $revision['ok'] ) ) {
							return $revision;
						}

						$safety = ( new CompetitionSafetyService() )->guard_fight_result_mutation( (int) $fight->competition_id, $fight_id, 'record_result', false );
						if ( empty( $safety['ok'] ) ) {
							return array(
								'ok'      => false,
								'error'   => (string) ( $safety['reason'] ?? 'safety_blocked' ),
								'message' => (string) ( $safety['message'] ?? '' ),
							);
						}

						$check = $this->validate_result_payload( $fight, $payload, false );
						if ( empty( $check['ok'] ) ) {
							return $check;
						}

						$new   = $this->build_update_payload( $fight, $payload, false );
						$write = FightResultPersistence::update_result( $fight_id, $new );
						if ( false === $write ) {
							throw new \RuntimeException( 'result_write_failed' );
						}

						$fresh = $this->verify_result_write( $fight_id, $new );
						$this->logger->audit( 'result_recorded', (int) $fight->competition_id, 'fight', $fight_id, $this->build_result_audit_payload( $fight, $fresh, $payload ) );

						return array( 'ok' => true, 'fight' => $fresh );
					}
				);
			}
		);
	}

	public function correct_result( int $fight_id, array $payload ): array {
		if ( ! Capabilities::user_can_correct_results() ) {
			return array( 'ok' => false, 'error' => 'missing_capability' );
		}

		return $this->with_fight_lock(
			$fight_id,
			'correct_result',
			function () use ( $fight_id, $payload ) {
				return DatabaseTransaction::run(
					function () use ( $fight_id, $payload ) {
						$fight = FightResultPersistence::normalize( $this->fights->get( $fight_id, true ) );
						if ( ! $fight ) {
							return array( 'ok' => false, 'error' => 'fight_not_found' );
						}

						$revision = $this->validate_expected_revision( $fight, $payload );
						if ( empty( $revision['ok'] ) ) {
							$this->logger->audit( 'result_correction_blocked', (int) $fight->competition_id, 'fight', $fight_id, array( 'reason' => 'concurrent_update' ) );
							return $revision;
						}

						$safety = ( new CompetitionSafetyService() )->guard_fight_result_mutation( (int) $fight->competition_id, $fight_id, 'correct_result', true );
						if ( empty( $safety['ok'] ) ) {
							$this->logger->audit( 'result_correction_blocked', (int) $fight->competition_id, 'fight', $fight_id, array( 'reason' => (string) ( $safety['reason'] ?? 'safety_blocked' ) ) );
							return array(
								'ok'      => false,
								'error'   => (string) ( $safety['reason'] ?? 'safety_blocked' ),
								'message' => (string) ( $safety['message'] ?? '' ),
							);
						}

						$check = $this->validate_result_payload( $fight, $payload, true );
						if ( empty( $check['ok'] ) ) {
							$this->logger->audit( 'result_correction_blocked', (int) $fight->competition_id, 'fight', $fight_id, array( 'reason' => (string) ( $check['error'] ?? 'validation_failed' ) ) );
							return $check;
						}

						$new   = $this->build_update_payload( $fight, $payload, true );
						$write = FightResultPersistence::update_result( $fight_id, $new );
						if ( false === $write ) {
							throw new \RuntimeException( 'result_correction_write_failed' );
						}

						$fresh = $this->verify_result_write( $fight_id, $new );
						$this->logger->audit( 'result_corrected', (int) $fight->competition_id, 'fight', $fight_id, $this->build_result_audit_payload( $fight, $fresh, $payload ) );

						return array( 'ok' => true, 'fight' => $fresh );
					}
				);
			}
		);
	}

	public function lock_result( int $fight_id, string $reason = '' ): array {
		return $this->with_fight_lock(
			$fight_id,
			'lock_result',
			function () use ( $fight_id, $reason ) {
				return DatabaseTransaction::run(
					function () use ( $fight_id, $reason ) {
						$fight = FightResultPersistence::normalize( $this->fights->get( $fight_id, true ) );
						if ( ! $fight ) {
							return array( 'ok' => false, 'error' => 'fight_not_found' );
						}

						$safety = ( new CompetitionSafetyService() )->assert_competition_ready( (int) $fight->competition_id, 'lock_result', array( 'fight_id' => $fight_id ) );
						if ( empty( $safety['ok'] ) ) {
							return array(
								'ok'      => false,
								'error'   => (string) ( $safety['reason'] ?? 'safety_blocked' ),
								'message' => (string) ( $safety['message'] ?? '' ),
							);
						}

						$status = $this->fights->get_effective_fight_status( $fight );
						if ( in_array( $status, array( FightRepository::STATUS_BYE, FightRepository::STATUS_PLACEHOLDER, FightRepository::STATUS_TRASHED ), true ) ) {
							return array( 'ok' => false, 'error' => 'lock_unsupported_status' );
						}
						if ( FightRepository::STATUS_COMPLETED !== $status ) {
							return array( 'ok' => false, 'error' => 'not_completed' );
						}

						$write = FightResultPersistence::update_status( $fight_id, FightRepository::STATUS_LOCKED );
						if ( false === $write ) {
							throw new \RuntimeException( 'result_lock_write_failed' );
						}

						$fresh = FightResultPersistence::normalize( $this->fights->get( $fight_id, true ) );
						if ( ! $fresh || FightRepository::STATUS_LOCKED !== $this->fights->get_effective_fight_status( $fresh ) ) {
							throw new \RuntimeException( 'result_lock_verification_failed' );
						}

						$this->logger->audit( 'result_locked', (int) $fight->competition_id, 'fight', $fight_id, array( 'reason' => sanitize_text_field( $reason ) ) );

						return array( 'ok' => true, 'fight' => $fresh );
					}
				);
			}
		);
	}

	public function build_result_audit_payload( $old_fight, $new_fight, array $context = array() ): array {
		return array(
			'old_status' => (string) ( $old_fight->status ?? '' ),
			'new_status' => (string) ( $new_fight->status ?? '' ),
			'old_result' => FightResultPersistence::get_method( $old_fight ),
			'new_result' => FightResultPersistence::get_method( $new_fight ),
			'old_winner' => (int) ( $old_fight->winner_entry_id ?? 0 ),
			'new_winner' => (int) ( $new_fight->winner_entry_id ?? 0 ),
			'reason'     => sanitize_textarea_field( (string) ( $context['reason'] ?? '' ) ),
		);
	}

	private function with_fight_lock( int $fight_id, string $action, callable $callback ): array {
		$lock_name = 'fight_result:' . absint( $fight_id );
		$token     = AtomicOperationLock::acquire( $lock_name, 30 );
		if ( '' === $token ) {
			return array(
				'ok'      => false,
				'error'   => 'operation_in_progress',
				'message' => __( 'Ce combat est déjà en cours de modification par un autre utilisateur. Actualisez avant de réessayer.', 'ufsc-licence-competition' ),
			);
		}

		try {
			$result = $callback();
			return is_array( $result ) ? $result : array( 'ok' => false, 'error' => 'invalid_result' );
		} catch ( \Throwable $error ) {
			$this->logger->audit(
				'result_write_failed',
				0,
				'fight',
				$fight_id,
				array(
					'action' => sanitize_key( $action ),
					'error'  => sanitize_text_field( $error->getMessage() ),
				)
			);
			return array(
				'ok'      => false,
				'error'   => 'database_write_failed',
				'message' => __( 'Le résultat n’a pas été enregistré. Aucune modification partielle n’a été conservée.', 'ufsc-licence-competition' ),
			);
		} finally {
			AtomicOperationLock::release( $lock_name, $token );
		}
	}

	private function validate_expected_revision( $fight, array $payload ): array {
		$expected = isset( $payload['expected_updated_at'] ) ? (string) $payload['expected_updated_at'] : '';
		if ( '' === $expected && isset( $_POST['expected_updated_at'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce is verified by the controller before service invocation.
			$expected = sanitize_text_field( wp_unslash( $_POST['expected_updated_at'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
		$expected = trim( $expected );
		if ( '' === $expected ) {
			return array( 'ok' => true );
		}

		$current = trim( (string) ( $fight->updated_at ?? '' ) );
		if ( '' !== $current && ! hash_equals( $current, $expected ) ) {
			return array(
				'ok'      => false,
				'error'   => 'concurrent_update',
				'message' => __( 'Ce combat a été modifié depuis l’ouverture de votre écran. Actualisez la page avant de saisir le résultat.', 'ufsc-licence-competition' ),
			);
		}

		return array( 'ok' => true );
	}

	private function verify_result_write( int $fight_id, array $expected ) {
		$fresh = FightResultPersistence::normalize( $this->fights->get( $fight_id, true ) );
		if ( ! $fresh ) {
			throw new \RuntimeException( 'result_verification_fight_missing' );
		}

		if ( FightRepository::STATUS_COMPLETED !== $this->fights->get_effective_fight_status( $fresh ) ) {
			throw new \RuntimeException( 'result_verification_status_mismatch' );
		}

		$expected_winner = absint( $expected['winner_entry_id'] ?? 0 );
		if ( $expected_winner !== absint( $fresh->winner_entry_id ?? 0 ) ) {
			throw new \RuntimeException( 'result_verification_winner_mismatch' );
		}

		$expected_method = sanitize_key( (string) ( $expected['result_method'] ?? $expected['result_type'] ?? '' ) );
		$current_method  = FightResultPersistence::get_method( $fresh );
		if ( $expected_method !== $current_method ) {
			throw new \RuntimeException( 'result_verification_method_mismatch' );
		}

		return $fresh;
	}

	private function build_update_payload( $fight, array $payload, bool $is_correction ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$result_type = sanitize_key( (string) ( $payload['result_type'] ?? $payload['result_method'] ?? '' ) );

		return array(
			'winner_entry_id' => absint( $payload['winner_entry_id'] ?? 0 ),
			'status'          => FightRepository::STATUS_COMPLETED,
			'result_method'   => $result_type,
			'result_type'     => $result_type,
			'score_red'       => sanitize_text_field( (string) ( $payload['score_red'] ?? '' ) ),
			'score_blue'      => sanitize_text_field( (string) ( $payload['score_blue'] ?? '' ) ),
			'completed_at'    => current_time( 'mysql' ),
			'result_note'     => sanitize_textarea_field( (string) ( $payload['note'] ?? '' ) ),
		);
	}
}
