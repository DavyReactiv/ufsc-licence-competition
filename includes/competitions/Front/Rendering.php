<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'ufsc_render_club_note' ) ) {
	function ufsc_render_club_note( $raw_note, $format = 'auto' ) {
		$raw_note = trim( (string) $raw_note );

		if ( '' === $raw_note ) {
			return '';
		}

		$allowed_formats = array( 'auto', 'html', 'plain' );
		$format          = sanitize_key( (string) $format );

		if ( ! in_array( $format, $allowed_formats, true ) ) {
			$format = 'auto';
		}

		switch ( $format ) {
			case 'html':
				return '<div class="ufsc-club-note ufsc-club-note--html">' . wp_kses( $raw_note, ufsc_allowed_note_html() ) . '</div>';
			case 'plain':
				return '<div class="ufsc-club-note ufsc-club-note--plain">' . wpautop( esc_html( $raw_note ) ) . '</div>';
			case 'auto':
			default:
				return ufsc_render_club_note_auto( $raw_note );
		}
	}
}

if ( ! function_exists( 'ufsc_allowed_note_html' ) ) {
	function ufsc_allowed_note_html() {
		return array(
			'div'     => array( 'class' => true ),
			'section' => array( 'class' => true ),
			'p'       => array( 'class' => true ),
			'br'      => array(),
			'strong'  => array(),
			'em'      => array(),
			'ul'      => array( 'class' => true ),
			'ol'      => array( 'class' => true ),
			'li'      => array( 'class' => true ),
			'h2'      => array( 'class' => true ),
			'h3'      => array( 'class' => true ),
			'h4'      => array( 'class' => true ),
			'span'    => array( 'class' => true ),
			'a'       => array(
				'href'   => true,
				'target' => true,
				'rel'    => true,
				'class'  => true,
			),
		);
	}
}

if ( ! function_exists( 'ufsc_render_club_note_auto' ) ) {
	function ufsc_render_club_note_auto( $raw_note ) {
		$text = str_replace( array( "\r\n", "\r" ), "\n", trim( (string) $raw_note ) );
		$text = preg_replace( "/([^\n]+:\n)\n(?=[\\-•]\\s+)/u", '$1', $text );
		$lines = explode( "\n", $text );
		$lines = array_map( 'trim', $lines );

		$html   = '<div class="ufsc-club-note ufsc-club-note--auto">';
		$header = array();
		$i      = 0;
		$count  = count( $lines );

		while ( $i < $count && count( $header ) < 3 ) {
			if ( '' !== $lines[ $i ] ) {
				$header[] = $lines[ $i ];
			}
			++$i;
		}

		if ( ! empty( $header ) ) {
			$html .= '<div class="ufsc-club-note__hero">';
			if ( ! empty( $header[0] ) ) {
				$html .= '<h3 class="ufsc-club-note__title">' . esc_html( $header[0] ) . '</h3>';
			}
			if ( ! empty( $header[1] ) ) {
				$html .= '<p class="ufsc-club-note__subtitle">' . esc_html( $header[1] ) . '</p>';
			}
			if ( ! empty( $header[2] ) ) {
				$html .= '<p class="ufsc-club-note__meta">' . esc_html( $header[2] ) . '</p>';
			}
			$html .= '</div>';
		}

		$content = array_slice( $lines, $i );
		$blocks  = array();
		$current = array();
		foreach ( $content as $line ) {
			if ( '' === $line ) {
				if ( ! empty( $current ) ) {
					$blocks[] = $current;
					$current  = array();
				}
				continue;
			}
			$current[] = $line;
		}
		if ( ! empty( $current ) ) {
			$blocks[] = $current;
		}

		foreach ( $blocks as $block ) {
			$first = $block[0] ?? '';
			if ( '' === $first ) {
				continue;
			}
			if ( preg_match( '/:\s*$/u', $first ) && isset( $block[1] ) && preg_match( '/^[\\-•]\\s+/', $block[1] ) ) {
				$title      = rtrim( $first, " :" );
				$type_class = ufsc_detect_note_box_class( $title );
				$html      .= '<div class="ufsc-club-note__box ' . esc_attr( $type_class ) . '"><h4>' . esc_html( $title ) . '</h4><ul>';
				for ( $j = 1, $jcount = count( $block ); $j < $jcount; ++$j ) {
					$item  = preg_replace( '/^[\\-•]\\s+/u', '', $block[ $j ] );
					$html .= '<li>' . esc_html( (string) $item ) . '</li>';
				}
				$html .= '</ul></div>';
				continue;
			}
			if ( preg_match( '/:\s*$/u', $first ) && count( $block ) > 1 ) {
				$title      = rtrim( $first, " :" );
				$type_class = ufsc_detect_note_box_class( $title );
				$html      .= '<div class="ufsc-club-note__box ' . esc_attr( $type_class ) . '"><h4>' . esc_html( $title ) . '</h4>';
				for ( $j = 1, $jcount = count( $block ); $j < $jcount; ++$j ) {
					$html .= '<p>' . esc_html( $block[ $j ] ) . '</p>';
				}
				$html .= '</div>';
				continue;
			}
			if ( preg_match( '/^[\\-•]\\s+/', $first ) ) {
				$html .= '<div class="ufsc-club-note__box"><ul>';
				foreach ( $block as $line ) {
					$item  = preg_replace( '/^[\\-•]\\s+/u', '', $line );
					$html .= '<li>' . esc_html( (string) $item ) . '</li>';
				}
				$html .= '</ul></div>';
				continue;
			}

			$joined    = implode( ' ', $block );
			$box_class = '';
			if ( false !== stripos( $joined, 'date limite d’inscription' ) || false !== stripos( $joined, "date limite d'inscription" ) || false !== stripos( $joined, 'aucune inscription ne pourra être garantie' ) || false !== stripos( $joined, 'forclusion' ) ) {
				$box_class = 'ufsc-club-note__box--alert';
			}
			if ( false !== stripos( $joined, 'règlement light contact' ) || false !== stripos( $joined, 'reglement light contact' ) ) {
				$box_class = 'ufsc-club-note__box--dark';
			}
			$html .= '<div class="ufsc-club-note__box ' . esc_attr( $box_class ) . '">';
			foreach ( $block as $line ) {
				$html .= '<p>' . esc_html( $line ) . '</p>';
			}
			$html .= '</div>';
		}

		return $html . '</div>';
	}
}

if ( ! function_exists( 'ufsc_detect_note_box_class' ) ) {
	function ufsc_detect_note_box_class( $title ) {
		$title_l = function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $title, 'UTF-8' ) : strtolower( (string) $title );
		if ( false !== strpos( $title_l, 'rappel important' ) ) {
			return 'ufsc-club-note__box--warning';
		}
		if ( false !== strpos( $title_l, 'rappel règlement' ) || false !== strpos( $title_l, 'rappel reglement' ) ) {
			return 'ufsc-club-note__box--dark';
		}
		if ( false !== strpos( $title_l, 'documents obligatoires' ) ) {
			return 'ufsc-club-note__box--docs';
		}
		if ( false !== strpos( $title_l, 'équipement obligatoire' ) || false !== strpos( $title_l, 'equipement obligatoire' ) ) {
			return 'ufsc-club-note__box--equip';
		}
		return '';
	}
}
