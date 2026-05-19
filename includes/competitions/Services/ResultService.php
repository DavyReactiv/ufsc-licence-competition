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
		$status = $this->fights->get_effective_fight_status( $fight );
		if ( in_array( $status, array( FightRepository::STATUS_BYE, FightRepository::STATUS_PLACEHOLDER, FightRepository::STATUS_TRASHED ), true ) ) {
			return array( 'ok' => false, 'error' => 'unsupported_fight_status' );
		}
		if ( FightRepository::STATUS_LOCKED === $status && ! Capabilities::user_can_correct_results() ) {
			return array( 'ok' => false, 'error' => 'locked_requires_sensitive_right' );
		}
		if ( FightRepository::STATUS_COMPLETED === $status && ! $is_correction ) {
			return array( 'ok' => false, 'error' => 'completed_requires_correction_flow' );
		}

		$result_type = sanitize_key( (string) ( $payload['result_type'] ?? $payload['result_method'] ?? '' ) );
		$allowed = array( 'points', 'arret_arbitre', 'forfait', 'abandon', 'disqualification', 'absence', 'no_contest', 'litige', 'annule' );
		if ( '' !== $result_type && ! in_array( $result_type, $allowed, true ) ) {
			return array( 'ok' => false, 'error' => 'invalid_result_type' );
		}

		$winner_entry_id = absint( $payload['winner_entry_id'] ?? 0 );
		$red_entry_id = absint( $fight->red_entry_id ?? 0 );
		$blue_entry_id = absint( $fight->blue_entry_id ?? 0 );
		$reason = trim( (string) ( $payload['reason'] ?? '' ) );
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

		return array( 'ok' => true );
	}

	public function record_result( int $fight_id, array $payload ): array {
		$fight = $this->fights->get( $fight_id, true );
		if ( ! $fight ) { return array( 'ok' => false, 'error' => 'fight_not_found' ); }
		$check = $this->validate_result_payload( $fight, $payload, false );
		if ( ! $check['ok'] ) { return $check; }
		$new = $this->build_update_payload( $fight, $payload, false );
		$this->fights->update( $fight_id, $new );
		$this->logger->audit( 'result_recorded', (int) $fight->competition_id, 'fight', $fight_id, $this->build_result_audit_payload( $fight, (object) $new, $payload ) );
		return array( 'ok' => true, 'fight' => $fight );
	}

	public function correct_result( int $fight_id, array $payload ): array {
		$fight = $this->fights->get( $fight_id, true );
		if ( ! $fight ) { return array( 'ok' => false, 'error' => 'fight_not_found' ); }
		if ( ! Capabilities::user_can_correct_results() ) {
			$this->logger->audit( 'result_correction_blocked', (int) $fight->competition_id, 'fight', $fight_id, array( 'reason' => 'missing_capability' ) );
			return array( 'ok' => false, 'error' => 'missing_capability' );
		}
		$check = $this->validate_result_payload( $fight, $payload, true );
		if ( ! $check['ok'] ) { return $check; }
		$new = $this->build_update_payload( $fight, $payload, true );
		$this->fights->update( $fight_id, $new );
		$this->logger->audit( 'result_corrected', (int) $fight->competition_id, 'fight', $fight_id, $this->build_result_audit_payload( $fight, (object) $new, $payload ) );
		return array( 'ok' => true, 'fight' => $fight );
	}

	public function lock_result( int $fight_id, string $reason = '' ): array {
		$fight = $this->fights->get( $fight_id, true );
		if ( ! $fight ) { return array( 'ok' => false, 'error' => 'fight_not_found' ); }
		if ( FightRepository::STATUS_COMPLETED !== $this->fights->get_effective_fight_status( $fight ) ) {
			return array( 'ok' => false, 'error' => 'not_completed' );
		}
		$this->fights->update( $fight_id, array( 'status' => FightRepository::STATUS_LOCKED ) );
		$this->logger->audit( 'result_locked', (int) $fight->competition_id, 'fight', $fight_id, array( 'reason' => sanitize_text_field( $reason ) ) );
		return array( 'ok' => true );
	}

	public function build_result_audit_payload( $old_fight, $new_fight, array $context = array() ): array {
		return array(
			'old_status' => (string) ( $old_fight->status ?? '' ),
			'new_status' => (string) ( $new_fight->status ?? '' ),
			'old_result' => (string) ( $old_fight->result_method ?? '' ),
			'new_result' => (string) ( $new_fight->result_method ?? '' ),
			'old_winner' => (int) ( $old_fight->winner_entry_id ?? 0 ),
			'new_winner' => (int) ( $new_fight->winner_entry_id ?? 0 ),
			'reason' => sanitize_textarea_field( (string) ( $context['reason'] ?? '' ) ),
		);
	}

	private function build_update_payload( $fight, array $payload, bool $is_correction ): array {
		$result_type = sanitize_key( (string) ( $payload['result_type'] ?? $payload['result_method'] ?? '' ) );
		return array(
			'competition_id' => (int) $fight->competition_id,
			'category_id' => (int) $fight->category_id,
			'fight_no' => (int) $fight->fight_no,
			'ring' => (string) ( $fight->ring ?? '' ),
			'round_no' => (int) ( $fight->round_no ?? 0 ),
			'red_entry_id' => (int) ( $fight->red_entry_id ?? 0 ),
			'blue_entry_id' => (int) ( $fight->blue_entry_id ?? 0 ),
			'winner_entry_id' => absint( $payload['winner_entry_id'] ?? 0 ),
			'status' => FightRepository::STATUS_COMPLETED,
			'result_method' => $result_type,
			'score_red' => sanitize_text_field( (string) ( $payload['score_red'] ?? '' ) ),
			'score_blue' => sanitize_text_field( (string) ( $payload['score_blue'] ?? '' ) ),
			'scheduled_at' => (string) ( $fight->scheduled_at ?? '' ),
			'completed_at' => current_time( 'mysql' ),
			'result_note' => sanitize_textarea_field( (string) ( $payload['note'] ?? '' ) ),
		);
	}
}
