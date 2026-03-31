<?php

namespace UFSC\Competitions\Services;

use UFSC\Competitions\Entries\ParticipantTypes;
use UFSC\Competitions\Repositories\ExternalParticipantRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ExternalParticipantService {
	private $repository;

	public function __construct( ?ExternalParticipantRepository $repository = null ) {
		$this->repository = $repository ?: new ExternalParticipantRepository();
	}

	public function get_participant_type_for_entry( int $entry_id ): string {
		$row = $this->repository->get_by_entry_id( $entry_id );
		if ( ! $row ) {
			return ParticipantTypes::get_default();
		}

		return ParticipantTypes::normalize( (string) ( $row->participant_type ?? '' ) );
	}

	public function save_external_participant( int $entry_id, array $data ): bool {
		$data['participant_type'] = ParticipantTypes::EXTERNAL_NON_LICENSED;

		return $this->repository->upsert_for_entry( $entry_id, $data );
	}

	public function get_external_participant( int $entry_id ): ?array {
		$row = $this->repository->get_by_entry_id( $entry_id );
		if ( ! $row ) {
			return null;
		}

		return array(
			'entry_id'             => (int) ( $row->entry_id ?? 0 ),
			'participant_type'     => ParticipantTypes::normalize( (string) ( $row->participant_type ?? '' ) ),
			'first_name'           => (string) ( $row->first_name ?? '' ),
			'last_name'            => (string) ( $row->last_name ?? '' ),
			'birth_date'           => (string) ( $row->birth_date ?? '' ),
			'sex'                  => (string) ( $row->sex ?? '' ),
			'club_name'            => (string) ( $row->club_name ?? '' ),
			'structure_name'       => (string) ( $row->structure_name ?? '' ),
			'city'                 => (string) ( $row->city ?? '' ),
			'discipline'           => (string) ( $row->discipline ?? '' ),
			'category_label'       => (string) ( $row->category_label ?? '' ),
			'weight_kg'            => isset( $row->weight_kg ) ? (float) $row->weight_kg : null,
			'weight_class'         => (string) ( $row->weight_class ?? '' ),
			'level'                => (string) ( $row->level ?? '' ),
			'medical_notes'        => (string) ( $row->medical_notes ?? '' ),
			'legal_guardian_name'  => (string) ( $row->legal_guardian_name ?? '' ),
			'legal_guardian_phone' => (string) ( $row->legal_guardian_phone ?? '' ),
			'legal_guardian_email' => (string) ( $row->legal_guardian_email ?? '' ),
			'validation_status'    => (string) ( $row->validation_status ?? 'draft' ),
		);
	}
}
