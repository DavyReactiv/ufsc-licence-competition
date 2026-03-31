<?php

namespace UFSC\Competitions\Services\UfscReference;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UfscReferenceFallback {
	public static function none(): ?array {
		return null;
	}

	public static function passthrough( array $payload ): array {
		return $payload;
	}
}
