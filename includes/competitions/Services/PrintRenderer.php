<?php

namespace UFSC\Competitions\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PrintRenderer {
	public function render_header( $title, array $meta = array() ) {
		$output = '<div class="ufsc-print-header">';
		$output .= '<h1>' . esc_html( $title ) . '</h1>';
		if ( $meta ) {
			$output .= '<ul class="ufsc-print-meta">';
			foreach ( $meta as $label => $value ) {
				$output .= '<li><strong>' . esc_html( $label ) . '</strong> ' . esc_html( $value ) . '</li>';
			}
			$output .= '</ul>';
		}
		$output .= '</div>';

		return $output;
	}
}
