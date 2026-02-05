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

	/** @var bool */
	public $can_view_details;

	/** @var bool */
	public $can_view_engaged_list;

	/** @var bool */
	public $can_view_engaged;

	/** @var bool */
	public $can_register;

	/** @var bool */
	public $can_export_engaged;

	public function __construct( bool $allowed, string $reason_code = '', array $context = array(), array $capabilities = array() ) {
		$this->allowed = $allowed;
		$this->reason_code = $reason_code;
		$this->context = $context;
		$this->apply_capabilities( $capabilities );
	}

	public static function allow( array $context = array(), array $capabilities = array() ): self {
		return new self( true, '', $context, $capabilities );
	}

	public static function deny( string $reason_code, array $context = array(), array $capabilities = array() ): self {
		return new self( false, $reason_code, $context, $capabilities );
	}

	private function apply_capabilities( array $capabilities ): void {
		$defaults = array(
			'can_view_details' => $this->allowed,
			'can_view_engaged_list' => $this->allowed,
			'can_view_engaged' => $this->allowed,
			'can_register' => $this->allowed,
			'can_export_engaged' => $this->allowed,
		);

		foreach ( $defaults as $key => $value ) {
			$override = array_key_exists( $key, $capabilities ) ? (bool) $capabilities[ $key ] : $value;
			$this->{$key} = $override;
		}
	}
}
