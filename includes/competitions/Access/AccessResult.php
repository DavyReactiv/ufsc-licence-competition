<?php

namespace UFSC\Competitions\Access;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AccessResult {
	/** @var bool */
	public $allowed;

	/** @var string */
	public $reason_code;

	/** @var array */
	public $context;

	public function __construct( bool $allowed, string $reason_code = '', array $context = array() ) {
		$this->allowed = $allowed;
		$this->reason_code = $reason_code;
		$this->context = $context;
	}

	public static function allow( array $context = array() ): self {
		return new self( true, '', $context );
	}

	public static function deny( string $reason_code, array $context = array() ): self {
		return new self( false, $reason_code, $context );
	}
}
