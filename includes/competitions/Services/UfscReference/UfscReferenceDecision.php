<?php

namespace UFSC\Competitions\Services\UfscReference;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UfscReferenceDecision {
	public const REASON_NONE = 'none';
	public const REASON_REFERENCE_DISABLED = 'reference_disabled';
	public const REASON_BIRTH_DATE_MISSING = 'birth_date_missing';
	public const REASON_BIRTH_DATE_INVALID = 'birth_date_invalid';
	public const REASON_DISCIPLINE_UNKNOWN = 'discipline_unknown';
	public const REASON_SEX_UNRECOGNIZED = 'sex_unrecognized';
	public const REASON_WEIGHT_INVALID = 'weight_invalid';
	public const REASON_AGE_GROUP_UNDETERMINED = 'age_group_undetermined';
	public const REASON_FORMAT_NOT_FOUND = 'format_not_found';
	public const REASON_LEVEL_NOT_FOUND = 'level_not_found';
	public const REASON_RULES_NOT_FOUND = 'rules_not_found';
	public const REASON_NO_COMPATIBLE_RULE = 'no_compatible_rule';
	public const REASON_CONTEXT_INCONSISTENT = 'context_inconsistent';

	/** @var array<string,mixed> */
	private $payload = array();

	/**
	 * @param array<string,mixed> $payload
	 */
	public function __construct( array $payload ) {
		$this->payload = $payload;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return $this->payload;
	}
}
