<?php

namespace UFSC\Competitions\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PrintRenderer {
	public function render_header( $title, array $meta = array(), array $context = array() ) {
		$document_title = isset( $context['document_title'] ) ? (string) $context['document_title'] : '';
		$generated_at = isset( $context['generated_at'] ) ? (string) $context['generated_at'] : '';

		$output = '<div class="ufsc-print-header">';
		if ( '' !== $document_title ) {
			$output .= '<p class="ufsc-print-header__kicker">' . esc_html( $document_title ) . '</p>';
		}
		$output .= '<h1>' . esc_html( $title ) . '</h1>';
		if ( $meta ) {
			$output .= '<ul class="ufsc-print-meta">';
			foreach ( $meta as $label => $value ) {
				$output .= '<li><strong>' . esc_html( $label ) . '</strong><span>' . esc_html( $value ) . '</span></li>';
			}
			$output .= '</ul>';
		}
		if ( '' !== $generated_at ) {
			$output .= '<p class="ufsc-print-header__generated">' . esc_html__( 'Généré le', 'ufsc-licence-competition' ) . ' ' . esc_html( $generated_at ) . '</p>';
		}
		$output .= '</div>';

		return $output;
	}
}
