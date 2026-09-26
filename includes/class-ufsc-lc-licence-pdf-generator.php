<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Automatic, designed UFSC licence PDF generator.
 *
 * This service is deliberately read-only against the master UFSC licence/club
 * tables. It only writes the generated attachment and the add-on's own
 * document association/meta tables.
 */
final class UFSC_LC_Licence_Pdf_Generator {
	const SOURCE           = 'UFSC';
	const TEMPLATE_VERSION = 'ufsc-card-v1-ffst';
	const ADMIN_PAGE_SLUG  = 'ufsc-licence-pdf-template';

	/**
	 * Register hooks without requiring any change in the master UFSC plugin.
	 */
	public static function register() {
		add_action( 'ufsc_licence_validated', array( __CLASS__, 'handle_licence_event' ), 20, 2 );
		add_action( 'ufsc_licence_created', array( __CLASS__, 'handle_licence_event' ), 30, 2 );
		add_action( 'admin_init', array( __CLASS__, 'maybe_generate_after_status_redirect' ), 50 );
		add_action( 'admin_post_ufsc_lc_generate_licence_pdf', array( __CLASS__, 'handle_manual_generation' ) );
		add_action( 'admin_menu', array( __CLASS__, 'register_admin_page' ), 45 );
		add_action( 'admin_notices', array( __CLASS__, 'render_engine_notice' ) );
	}

	/**
	 * Generic licence event callback.
	 *
	 * @param int $licence_id Licence id.
	 * @param int $club_id    Club id (unused, kept for hook compatibility).
	 */
	public static function handle_licence_event( $licence_id, $club_id = 0 ) {
		unset( $club_id );
		self::maybe_generate_for_licence( absint( $licence_id ), false );
	}

	/**
	 * UFSC Gestion currently redirects with updated_status + licence_id after a
	 * status change. This bridge catches that exact licence and re-checks its
	 * final database state. No status is trusted from the URL itself.
	 */
	public static function maybe_generate_after_status_redirect() {
		if ( ! is_admin() || empty( $_GET['updated_status'] ) || empty( $_GET['licence_id'] ) ) {
			return;
		}

		$licence_id = absint( $_GET['licence_id'] );
		if ( $licence_id <= 0 ) {
			return;
		}

		self::maybe_generate_for_licence( $licence_id, false );
	}

	/**
	 * Register the design preview page.
	 */
	public static function register_admin_page() {
		if ( ! class_exists( 'UFSC_LC_Plugin' ) || ! class_exists( 'UFSC_LC_Capabilities' ) ) {
			return;
		}

		$capability = method_exists( 'UFSC_LC_Capabilities', 'get_manage_read_capability' )
			? UFSC_LC_Capabilities::get_manage_read_capability()
			: 'manage_options';

		add_submenu_page(
			UFSC_LC_Plugin::PARENT_SLUG,
			__( 'Gabarit licence PDF', 'ufsc-licence-competition' ),
			__( 'Gabarit licence PDF', 'ufsc-licence-competition' ),
			$capability,
			self::ADMIN_PAGE_SLUG,
			array( __CLASS__, 'render_admin_page' )
		);
	}

	/**
	 * Show a non-blocking warning if the PDF engine is not installed.
	 */
	public static function render_engine_notice() {
		if ( ! is_admin() || class_exists( 'Dompdf\\Dompdf' ) ) {
			return;
		}

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( ! in_array( $page, array( self::ADMIN_PAGE_SLUG, 'ufsc-licence-documents' ), true ) ) {
			return;
		}

		echo '<div class="notice notice-warning"><p>';
		echo esc_html__( 'Génération automatique des licences PDF : Dompdf est indisponible. Le plugin reste fonctionnel et les validations de licences ne sont pas bloquées, mais les PDF automatiques ne pourront pas être produits tant que les dépendances Composer ne sont pas installées.', 'ufsc-licence-competition' );
		echo '</p></div>';
	}

	/**
	 * Render the designed sample and generator diagnostics.
	 */
	public static function render_admin_page() {
		if ( class_exists( 'UFSC_LC_Capabilities' ) && ! UFSC_LC_Capabilities::user_can_read() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		$sample = array(
			'first_name'     => 'Alexandre',
			'last_name'      => 'MARTIN',
			'license_number' => 'UFSC-2026-001245',
			'club_name'      => 'Club UFSC Démonstration',
			'birthdate'      => '14/03/1994',
			'season'         => '2026–2027',
			'category'       => 'Senior',
			'profile'        => 'Compétiteur',
			'role'           => '',
			'photo_uri'      => '',
			'logo_uri'       => self::get_default_logo_data_uri(),
			'qr_uri'         => '',
			'generated_at'   => wp_date( 'd/m/Y' ),
			'initials'       => 'AM',
		);

		$status  = isset( $_GET['ufsc_pdf_status'] ) ? sanitize_key( wp_unslash( $_GET['ufsc_pdf_status'] ) ) : '';
		$message = isset( $_GET['ufsc_pdf_message'] ) ? sanitize_text_field( wp_unslash( $_GET['ufsc_pdf_message'] ) ) : '';
		?>
		<div class="wrap ufsc-lc-pdf-template-admin">
			<h1><?php esc_html_e( 'Gabarit automatique de licence UFSC', 'ufsc-licence-competition' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Aperçu du document généré automatiquement lorsqu’une licence est validée et possède un numéro de licence UFSC. Le PDF reste associé à la licence dans le système existant.', 'ufsc-licence-competition' ); ?></p>

			<?php if ( $message ) : ?>
				<div class="notice notice-<?php echo esc_attr( 'success' === $status ? 'success' : ( 'warning' === $status ? 'warning' : 'error' ) ); ?> is-dismissible"><p><?php echo esc_html( $message ); ?></p></div>
			<?php endif; ?>

			<div style="margin:20px 0;padding:16px 18px;background:#fff;border:1px solid #dcdcde;border-radius:8px;max-width:920px;">
				<strong><?php esc_html_e( 'Moteur PDF :', 'ufsc-licence-competition' ); ?></strong>
				<?php if ( class_exists( 'Dompdf\\Dompdf' ) ) : ?>
					<span style="color:#188038;font-weight:700;">● <?php esc_html_e( 'Dompdf disponible', 'ufsc-licence-competition' ); ?></span>
				<?php else : ?>
					<span style="color:#b45309;font-weight:700;">● <?php esc_html_e( 'Dompdf à installer via Composer', 'ufsc-licence-competition' ); ?></span>
				<?php endif; ?>
				<span style="margin-left:18px;"><strong><?php esc_html_e( 'Version du gabarit :', 'ufsc-licence-competition' ); ?></strong> <?php echo esc_html( self::TEMPLATE_VERSION ); ?></span>
			</div>

			<div style="max-width:920px;overflow:auto;padding:18px;background:#e8eaed;border-radius:12px;">
				<?php echo self::build_card_markup( $sample, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Internal escaped template. ?>
			</div>

			<div style="max-width:920px;margin-top:22px;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:18px;">
				<h2 style="margin-top:0;"><?php esc_html_e( 'Règles de génération', 'ufsc-licence-competition' ); ?></h2>
				<p><?php esc_html_e( 'Le PDF est généré uniquement si la licence est réellement validée en base et si son numéro UFSC est renseigné. Un PDF ajouté manuellement est conservé et n’est jamais remplacé automatiquement.', 'ufsc-licence-competition' ); ?></p>
				<p><?php esc_html_e( 'Le document n’affiche ni adresse, ni téléphone, ni e-mail. Les anciennes références ASPTT restent utilisables en compatibilité interne mais ne figurent pas sur le gabarit.', 'ufsc-licence-competition' ); ?></p>
			</div>
		</div>
		<?php
	}

	/**
	 * Manual generation/regeneration endpoint.
	 */
	public static function handle_manual_generation() {
		if ( ! class_exists( 'UFSC_LC_Capabilities' ) || ! UFSC_LC_Capabilities::user_can_edit() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		$licence_id = isset( $_REQUEST['licence_id'] ) ? absint( $_REQUEST['licence_id'] ) : 0;
		$nonce      = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';
		if ( ! $licence_id || ! wp_verify_nonce( $nonce, 'ufsc_lc_generate_licence_pdf_' . $licence_id ) ) {
			wp_die( esc_html__( 'Requête invalide.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		$result = self::maybe_generate_for_licence( $licence_id, true );
		$url    = wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=' . self::ADMIN_PAGE_SLUG );

		if ( is_wp_error( $result ) ) {
			$url = add_query_arg(
				array(
					'ufsc_pdf_status'  => 'error',
					'ufsc_pdf_message' => $result->get_error_message(),
				),
				$url
			);
		} else {
			$url = add_query_arg(
				array(
					'ufsc_pdf_status'  => 'success',
					'ufsc_pdf_message' => __( 'Licence PDF générée et associée avec succès.', 'ufsc-licence-competition' ),
				),
				$url
			);
		}

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Generate and associate a PDF when the licence is eligible.
	 *
	 * @param int  $licence_id Licence id.
	 * @param bool $force      Regenerate an already generated PDF.
	 * @return int|WP_Error Attachment id on success, WP_Error otherwise.
	 */
	public static function maybe_generate_for_licence( $licence_id, $force = false ) {
		$licence_id = absint( $licence_id );
		if ( $licence_id <= 0 ) {
			return new WP_Error( 'ufsc_lc_pdf_invalid_licence', __( 'Licence invalide.', 'ufsc-licence-competition' ) );
		}

		$lock_key = 'ufsc_lc_pdf_generation_lock_' . $licence_id;
		if ( get_transient( $lock_key ) ) {
			return new WP_Error( 'ufsc_lc_pdf_locked', __( 'Une génération PDF est déjà en cours pour cette licence.', 'ufsc-licence-competition' ) );
		}
		set_transient( $lock_key, 1, 60 );

		try {
			$licence = self::get_licence_record( $licence_id );
			if ( ! $licence ) {
				return new WP_Error( 'ufsc_lc_pdf_not_found', __( 'Licence introuvable.', 'ufsc-licence-competition' ) );
			}

			if ( 'valide' !== self::normalize_status( $licence ) ) {
				return new WP_Error( 'ufsc_lc_pdf_not_validated', __( 'Le PDF n’est généré que pour une licence validée.', 'ufsc-licence-competition' ) );
			}

			$license_number = self::resolve_ufsc_license_number( $licence );
			if ( '' === $license_number ) {
				return new WP_Error( 'ufsc_lc_pdf_missing_number', __( 'Le numéro de licence UFSC doit être attribué avant la génération du PDF.', 'ufsc-licence-competition' ) );
			}

			if ( ! self::documents_tables_exist() ) {
				return new WP_Error( 'ufsc_lc_pdf_tables_missing', __( 'Les tables de documents du plugin Licence Compétition sont indisponibles.', 'ufsc-licence-competition' ) );
			}

			$current = self::get_document_row( $licence_id );
			if ( $current && ! empty( $current->attachment_id ) ) {
				$current_generator = (string) self::get_document_meta( $licence_id, 'pdf_generator' );
				if ( '' === $current_generator ) {
					return new WP_Error( 'ufsc_lc_pdf_manual_preserved', __( 'Un PDF a été associé manuellement à cette licence : il est conservé et ne sera pas remplacé automatiquement.', 'ufsc-licence-competition' ) );
				}

				if ( ! $force && self::TEMPLATE_VERSION === (string) self::get_document_meta( $licence_id, 'pdf_template_version' ) ) {
					$file = get_attached_file( (int) $current->attachment_id );
					if ( $file && is_readable( $file ) ) {
						return (int) $current->attachment_id;
					}
				}
			}

			if ( ! class_exists( 'Dompdf\\Dompdf' ) ) {
				self::log( 'automatic_pdf_skipped', $licence_id, array( 'reason' => 'dompdf_missing' ) );
				return new WP_Error( 'ufsc_lc_pdf_engine_missing', __( 'Dompdf est indisponible : installez les dépendances Composer du plugin pour activer la génération automatique.', 'ufsc-licence-competition' ) );
			}

			$data     = self::build_template_data( $licence, $license_number );
			$html     = self::build_pdf_html( $data );
			$pdf      = self::render_pdf( $html );
			$snapshot = hash( 'sha256', wp_json_encode( $data ) );

			if ( is_wp_error( $pdf ) ) {
				return $pdf;
			}

			$attachment_id = self::store_pdf_attachment( $licence_id, $license_number, $data['season'], $pdf );
			if ( is_wp_error( $attachment_id ) ) {
				return $attachment_id;
			}

			$old_attachment_id = $current && ! empty( $current->attachment_id ) ? absint( $current->attachment_id ) : 0;
			self::upsert_document( $licence_id, $license_number, $attachment_id );
			self::set_document_meta( $licence_id, 'ufsc_licence_pdf_attachment_id', $attachment_id );
			self::set_document_meta( $licence_id, 'pdf_attachment_id', $attachment_id );
			self::set_document_meta( $licence_id, 'pdf_generator', self::TEMPLATE_VERSION );
			self::set_document_meta( $licence_id, 'pdf_template_version', self::TEMPLATE_VERSION );
			self::set_document_meta( $licence_id, 'pdf_generated_at', current_time( 'mysql' ) );
			self::set_document_meta( $licence_id, 'pdf_snapshot_hash', $snapshot );

			update_post_meta( $attachment_id, '_ufsc_lc_licence_id', $licence_id );
			update_post_meta( $attachment_id, '_ufsc_lc_pdf_generator', self::TEMPLATE_VERSION );
			update_post_meta( $attachment_id, '_ufsc_lc_template_version', self::TEMPLATE_VERSION );

			self::bump_caches( $licence );
			self::log(
				'automatic_pdf_generated',
				$licence_id,
				array(
					'attachment_id'     => $attachment_id,
					'old_attachment_id' => $old_attachment_id,
					'template_version'  => self::TEMPLATE_VERSION,
				)
			);

			/**
			 * Generated files are intentionally preserved by default for traceability.
			 * Sites that explicitly want cleanup may opt in with this filter.
			 */
			$delete_previous = (bool) apply_filters( 'ufsc_lc_license_pdf_delete_previous_generated_attachment', false, $old_attachment_id, $attachment_id, $licence_id );
			if ( $delete_previous && $old_attachment_id && $old_attachment_id !== $attachment_id ) {
				wp_delete_attachment( $old_attachment_id, true );
			}

			do_action( 'ufsc_lc_license_pdf_generated', $licence_id, $attachment_id, $data );

			return $attachment_id;
		} catch ( Exception $exception ) {
			self::log( 'automatic_pdf_exception', $licence_id, array( 'message' => $exception->getMessage() ) );
			return new WP_Error( 'ufsc_lc_pdf_exception', __( 'La génération du PDF a rencontré une erreur technique.', 'ufsc-licence-competition' ) );
		} finally {
			delete_transient( $lock_key );
		}
	}

	/**
	 * Build the immutable snapshot used by the PDF.
	 */
	private static function build_template_data( $licence, $license_number ) {
		$first_name = trim( (string) ( $licence->prenom ?? '' ) );
		$last_name  = trim( (string) ( $licence->nom ?? ( $licence->nom_licence ?? '' ) ) );
		$birthdate  = self::format_birthdate( (string) ( $licence->date_naissance ?? '' ) );
		$season     = self::format_season( $licence );
		$category   = self::resolve_category( $licence, $birthdate );
		$profile    = self::resolve_profile( $licence );
		$role       = self::resolve_role( $licence );
		$initials   = strtoupper( self::first_character( $first_name ) . self::first_character( $last_name ) );

		$data = array(
			'first_name'     => $first_name,
			'last_name'      => strtoupper( $last_name ),
			'license_number' => $license_number,
			'club_name'      => trim( (string) ( $licence->club_name ?? '' ) ),
			'birthdate'      => $birthdate,
			'season'         => $season,
			'category'       => $category,
			'profile'        => $profile,
			'role'           => $role,
			'photo_uri'      => self::image_value_to_data_uri( $licence->photo_identite ?? '' ),
			'logo_uri'       => self::get_default_logo_data_uri(),
			'qr_uri'         => '',
			'generated_at'   => wp_date( 'd/m/Y' ),
			'initials'       => '' !== $initials ? $initials : 'UF',
		);

		$data['logo_uri'] = (string) apply_filters( 'ufsc_lc_license_pdf_logo_data_uri', $data['logo_uri'], $licence, $data );
		$data['qr_uri']   = (string) apply_filters( 'ufsc_lc_license_pdf_qr_data_uri', '', $licence, $data );
		$data             = (array) apply_filters( 'ufsc_lc_license_pdf_data', $data, $licence );

		return $data;
	}

	/**
	 * Render Dompdf bytes.
	 *
	 * @param string $html Complete HTML.
	 * @return string|WP_Error
	 */
	private static function render_pdf( $html ) {
		try {
			$options = class_exists( 'Dompdf\\Options' ) ? new \Dompdf\Options() : null;
			if ( $options ) {
				$options->set( 'isRemoteEnabled', false );
				$options->set( 'isHtml5ParserEnabled', true );
				$dompdf = new \Dompdf\Dompdf( $options );
			} else {
				$dompdf = new \Dompdf\Dompdf();
			}

			$dompdf->setPaper( 'A6', 'landscape' );
			$dompdf->loadHtml( $html, 'UTF-8' );
			$dompdf->render();
			$output = $dompdf->output();

			if ( ! is_string( $output ) || '' === $output ) {
				return new WP_Error( 'ufsc_lc_pdf_empty', __( 'Le moteur PDF n’a produit aucun contenu.', 'ufsc-licence-competition' ) );
			}

			return $output;
		} catch ( Exception $exception ) {
			return new WP_Error( 'ufsc_lc_pdf_render_error', __( 'Impossible de générer la licence PDF.', 'ufsc-licence-competition' ) );
		}
	}

	/**
	 * Complete HTML document passed to Dompdf.
	 */
	private static function build_pdf_html( array $data ) {
		$markup = self::build_card_markup( $data, false );
		$html   = '<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>Licence UFSC</title></head><body>' . $markup . '</body></html>';

		return (string) apply_filters( 'ufsc_lc_license_pdf_html', $html, $data );
	}

	/**
	 * Designed A6 landscape card. CSS intentionally uses Dompdf-safe primitives.
	 *
	 * @param array $data       Template data.
	 * @param bool  $is_preview Browser preview mode.
	 * @return string
	 */
	private static function build_card_markup( array $data, $is_preview = false ) {
		$first_name     = esc_html( (string) ( $data['first_name'] ?? '' ) );
		$last_name      = esc_html( (string) ( $data['last_name'] ?? '' ) );
		$license_number = esc_html( (string) ( $data['license_number'] ?? '' ) );
		$club_name      = esc_html( (string) ( $data['club_name'] ?? '' ) );
		$birthdate      = esc_html( (string) ( $data['birthdate'] ?? '' ) );
		$season         = esc_html( (string) ( $data['season'] ?? '' ) );
		$category       = esc_html( (string) ( $data['category'] ?? '' ) );
		$profile        = esc_html( (string) ( $data['profile'] ?? '' ) );
		$role           = esc_html( (string) ( $data['role'] ?? '' ) );
		$generated_at   = esc_html( (string) ( $data['generated_at'] ?? '' ) );
		$initials       = esc_html( (string) ( $data['initials'] ?? 'UF' ) );
		$photo_uri      = self::safe_data_uri( (string) ( $data['photo_uri'] ?? '' ) );
		$logo_uri       = self::safe_data_uri( (string) ( $data['logo_uri'] ?? '' ) );
		$qr_uri         = self::safe_data_uri( (string) ( $data['qr_uri'] ?? '' ) );
		$scale_style    = $is_preview ? 'box-shadow:0 12px 32px rgba(17,19,24,.25);' : '';

		$photo = $photo_uri
			? '<img class="ufsc-card-photo-img" src="' . esc_attr( $photo_uri ) . '" alt="">'
			: '<div class="ufsc-card-photo-fallback"><span>' . $initials . '</span></div>';

		$logo = $logo_uri
			? '<img class="ufsc-card-logo-img" src="' . esc_attr( $logo_uri ) . '" alt="UFSC">'
			: '<div class="ufsc-card-logo-text">UFSC</div>';

		$verify = $qr_uri
			? '<img class="ufsc-card-qr" src="' . esc_attr( $qr_uri ) . '" alt="QR">'
			: '<div class="ufsc-card-stamp"><span class="ufsc-card-stamp-check">✓</span><span>LICENCE<br>VALIDÉE</span></div>';

		$role_row = '' !== trim( wp_strip_all_tags( $role ) )
			? '<div class="ufsc-card-role">' . esc_html__( 'Fonction', 'ufsc-licence-competition' ) . ' : <strong>' . $role . '</strong></div>'
			: '';

		$styles = '<style>
			@page{size:A6 landscape;margin:0}
			html,body{margin:0;padding:0;background:#fff;font-family:DejaVu Sans,Arial,sans-serif;color:#111318}
			*{box-sizing:border-box}
			.ufsc-card{position:relative;width:148mm;height:105mm;overflow:hidden;background:#f4f5f7;' . $scale_style . '}
			.ufsc-card-top{position:absolute;left:0;top:0;width:148mm;height:22mm;background:#111318}
			.ufsc-card-redline{position:absolute;left:0;top:22mm;width:148mm;height:2.4mm;background:#c91f2c}
			.ufsc-card-diagonal{position:absolute;right:-20mm;top:-15mm;width:64mm;height:55mm;background:#1b1e24;transform:rotate(-13deg)}
			.ufsc-card-logo{position:absolute;left:8mm;top:4mm;width:22mm;height:14mm;text-align:left;z-index:3}
			.ufsc-card-logo-img{max-width:21mm;max-height:14mm}
			.ufsc-card-logo-text{display:inline-block;font-size:22px;font-weight:900;letter-spacing:1px;color:#fff;border:2px solid #fff;padding:1.2mm 2.2mm;line-height:1}
			.ufsc-card-headline{position:absolute;left:34mm;top:4.2mm;color:#fff;z-index:3}
			.ufsc-card-headline .kicker{font-size:6.6px;letter-spacing:1.5px;text-transform:uppercase;color:#d7d9dd;margin-bottom:1.4mm}
			.ufsc-card-headline .title{font-size:15.5px;line-height:1;font-weight:800;letter-spacing:.35px}
			.ufsc-card-season{position:absolute;right:7.5mm;top:5mm;z-index:4;color:#fff;text-align:right}
			.ufsc-card-season .label{display:block;font-size:6px;letter-spacing:1px;text-transform:uppercase;color:#c9cbd0}
			.ufsc-card-season .value{display:block;margin-top:1mm;font-size:11px;font-weight:800}
			.ufsc-card-photo{position:absolute;left:8mm;top:30mm;width:34mm;height:46mm;border-radius:3mm;overflow:hidden;background:#d9dce1;border:1px solid #c6c9cf}
			.ufsc-card-photo-img{width:34mm;height:46mm;object-fit:cover}
			.ufsc-card-photo-fallback{width:34mm;height:46mm;background:#20242b;text-align:center;color:#fff}
			.ufsc-card-photo-fallback span{display:block;padding-top:16mm;font-size:24px;font-weight:800;letter-spacing:1px}
			.ufsc-card-photo-caption{position:absolute;left:8mm;top:78.5mm;width:34mm;text-align:center;font-size:6.3px;color:#6c7078;text-transform:uppercase;letter-spacing:.55px}
			.ufsc-card-main{position:absolute;left:48mm;top:30mm;width:91mm;height:57mm}
			.ufsc-card-person{font-size:19px;line-height:1.05;font-weight:900;color:#111318;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
			.ufsc-card-person .first{font-weight:500}
			.ufsc-card-number-label{margin-top:3.2mm;font-size:6.4px;text-transform:uppercase;letter-spacing:1px;color:#6a6f78}
			.ufsc-card-number{margin-top:.5mm;font-size:14px;font-weight:900;color:#c91f2c;letter-spacing:.45px}
			.ufsc-card-divider{height:1px;background:#d8dbe0;margin:3mm 0 2.4mm}
			.ufsc-card-grid{width:70mm;border-collapse:collapse;table-layout:fixed}
			.ufsc-card-grid td{padding:0 2.6mm 2.3mm 0;vertical-align:top}
			.ufsc-card-grid .label{display:block;font-size:5.8px;text-transform:uppercase;letter-spacing:.65px;color:#7a7f87;margin-bottom:.65mm}
			.ufsc-card-grid .value{display:block;font-size:8.2px;font-weight:700;color:#1c2026;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
			.ufsc-card-club{margin-top:.3mm;width:72mm}
			.ufsc-card-club .label{display:block;font-size:5.8px;text-transform:uppercase;letter-spacing:.65px;color:#7a7f87;margin-bottom:.6mm}
			.ufsc-card-club .value{display:block;font-size:8.3px;font-weight:800;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
			.ufsc-card-role{margin-top:1.6mm;font-size:6.9px;color:#5f646d}
			.ufsc-card-verify{position:absolute;right:6mm;bottom:16.5mm;width:20mm;height:20mm}
			.ufsc-card-qr{width:20mm;height:20mm}
			.ufsc-card-stamp{width:20mm;height:20mm;border:1.3px solid #c91f2c;border-radius:10mm;text-align:center;color:#c91f2c;font-size:5.4px;font-weight:800;line-height:1.15;padding-top:2.6mm}
			.ufsc-card-stamp-check{display:block;font-size:10px;line-height:1;margin-bottom:1mm}
			.ufsc-card-bottom{position:absolute;left:0;bottom:0;width:148mm;height:12mm;background:#111318;color:#fff}
			.ufsc-card-bottom-left{position:absolute;left:8mm;top:3mm;font-size:6.1px;line-height:1.45;color:#d8dae0}
			.ufsc-card-bottom-left strong{color:#fff}
			.ufsc-card-bottom-right{position:absolute;right:7.5mm;top:3.3mm;text-align:right;font-size:5.7px;line-height:1.4;color:#b9bdc5}
			.ufsc-card-watermark{position:absolute;right:25mm;bottom:18mm;font-size:28px;font-weight:900;letter-spacing:2px;color:#e5e7ea;transform:rotate(-8deg);z-index:0}
		</style>';

		return $styles
			. '<div class="ufsc-card">'
			. '<div class="ufsc-card-top"></div><div class="ufsc-card-diagonal"></div><div class="ufsc-card-redline"></div>'
			. '<div class="ufsc-card-logo">' . $logo . '</div>'
			. '<div class="ufsc-card-headline"><div class="kicker">Union Française des Sports de Combat</div><div class="title">LICENCE OFFICIELLE</div></div>'
			. '<div class="ufsc-card-season"><span class="label">Saison sportive</span><span class="value">' . $season . '</span></div>'
			. '<div class="ufsc-card-watermark">UFSC</div>'
			. '<div class="ufsc-card-photo">' . $photo . '</div><div class="ufsc-card-photo-caption">Titulaire de la licence</div>'
			. '<div class="ufsc-card-main">'
			. '<div class="ufsc-card-person"><span class="first">' . $first_name . '</span> ' . $last_name . '</div>'
			. '<div class="ufsc-card-number-label">Numéro de licence UFSC</div><div class="ufsc-card-number">' . $license_number . '</div>'
			. '<div class="ufsc-card-divider"></div>'
			. '<table class="ufsc-card-grid" role="presentation"><tr>'
			. '<td><span class="label">Date de naissance</span><span class="value">' . ( $birthdate ? $birthdate : '—' ) . '</span></td>'
			. '<td><span class="label">Catégorie</span><span class="value">' . ( $category ? $category : '—' ) . '</span></td>'
			. '<td><span class="label">Profil</span><span class="value">' . ( $profile ? $profile : 'Pratiquant' ) . '</span></td>'
			. '</tr></table>'
			. '<div class="ufsc-card-club"><span class="label">Club affilié</span><span class="value">' . ( $club_name ? $club_name : '—' ) . '</span></div>'
			. $role_row
			. '</div>'
			. '<div class="ufsc-card-verify">' . $verify . '</div>'
			. '<div class="ufsc-card-bottom"><div class="ufsc-card-bottom-left"><strong>UFSC</strong> · Sous l’égide de la FFST<br>Licence nominative valable pour la saison indiquée</div><div class="ufsc-card-bottom-right">Document généré le ' . $generated_at . '<br>ufsc-france.fr</div></div>'
			. '</div>';
	}

	/**
	 * Store generated bytes in the WordPress media library.
	 *
	 * @param int    $licence_id     Licence id.
	 * @param string $license_number Official UFSC number.
	 * @param string $season         Season label.
	 * @param string $pdf            Raw PDF bytes.
	 * @return int|WP_Error
	 */
	private static function store_pdf_attachment( $licence_id, $license_number, $season, $pdf ) {
		$number_slug = sanitize_file_name( strtolower( str_replace( ' ', '-', $license_number ) ) );
		$season_slug = sanitize_file_name( strtolower( str_replace( array( '–', '/', ' ' ), '-', $season ) ) );
		$filename    = 'licence-ufsc-' . ( $number_slug ? $number_slug : $licence_id ) . ( $season_slug ? '-' . $season_slug : '' ) . '.pdf';
		$upload      = wp_upload_bits( $filename, null, $pdf );

		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error( 'ufsc_lc_pdf_upload_error', sanitize_text_field( $upload['error'] ) );
		}

		$attachment = array(
			'post_mime_type' => 'application/pdf',
			'post_title'     => sprintf( 'Licence UFSC %s', $license_number ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		);
		$attachment_id = wp_insert_attachment( $attachment, $upload['file'] );
		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		return absint( $attachment_id );
	}

	/**
	 * Read the full licence row plus club name. Master tables are never written.
	 */
	private static function get_licence_record( $licence_id ) {
		global $wpdb;
		$licences_table = $wpdb->prefix . 'ufsc_licences';
		$clubs_table    = $wpdb->prefix . 'ufsc_clubs';

		if ( ! self::table_exists( $licences_table ) || ! self::table_exists( $clubs_table ) ) {
			return null;
		}

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT l.*, c.nom AS club_name FROM {$licences_table} l LEFT JOIN {$clubs_table} c ON c.id = l.club_id WHERE l.id = %d LIMIT 1",
				$licence_id
			)
		);
	}

	/**
	 * Canonical valid-status resolution with safe legacy fallback.
	 */
	private static function normalize_status( $licence ) {
		if ( function_exists( 'ufsc_get_licence_status_from_record' ) ) {
			return (string) ufsc_get_licence_status_from_record( $licence );
		}

		$raw = trim( (string) ( $licence->statut ?? ( $licence->status ?? '' ) ) );
		$key = strtolower( remove_accents( $raw ) );
		$valid = array( 'valide', 'validee', 'valid', 'validated', 'approved', 'active', 'actif', 'applied' );

		return in_array( $key, $valid, true ) ? 'valide' : $key;
	}

	/**
	 * Prefer the canonical UFSC number. If that canonical column exists but is
	 * empty, do not silently substitute an ASPTT/delegated identifier.
	 */
	private static function resolve_ufsc_license_number( $licence ) {
		if ( property_exists( $licence, 'numero_licence_ufsc' ) ) {
			return trim( (string) $licence->numero_licence_ufsc );
		}

		foreach ( array( 'numero_licence', 'num_licence', 'licence_number', 'numero_licence_delegataire' ) as $field ) {
			if ( isset( $licence->{$field} ) && '' !== trim( (string) $licence->{$field} ) ) {
				return trim( (string) $licence->{$field} );
			}
		}

		return '';
	}

	private static function format_birthdate( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value || '0000-00-00' === $value ) {
			return '';
		}
		if ( function_exists( 'ufsc_lc_format_birthdate' ) ) {
			return (string) ufsc_lc_format_birthdate( $value );
		}
		$timestamp = strtotime( $value );
		return $timestamp ? wp_date( 'd/m/Y', $timestamp ) : $value;
	}

	private static function format_season( $licence ) {
		if ( function_exists( 'ufsc_get_licence_season_label' ) ) {
			$label = trim( (string) ufsc_get_licence_season_label( $licence ) );
			if ( '' !== $label ) {
				return str_replace( '-', '–', $label );
			}
		}

		foreach ( array( 'season', 'saison', 'paid_season', 'season_end_year' ) as $field ) {
			if ( ! isset( $licence->{$field} ) || '' === trim( (string) $licence->{$field} ) ) {
				continue;
			}
			$value = trim( (string) $licence->{$field} );
			if ( function_exists( 'ufsc_lc_normalize_season_end_year' ) && function_exists( 'ufsc_lc_format_season_label' ) ) {
				$end_year = ufsc_lc_normalize_season_end_year( $value );
				if ( $end_year ) {
					return str_replace( '-', '–', (string) ufsc_lc_format_season_label( $end_year ) );
				}
			}
			if ( 'season_end_year' === $field && ctype_digit( $value ) ) {
				$end = (int) $value;
				return ( $end - 1 ) . '–' . $end;
			}
			return str_replace( '/', '–', str_replace( '-', '–', $value ) );
		}

		if ( function_exists( 'ufsc_lc_get_active_season_end_year' ) && function_exists( 'ufsc_lc_format_season_label' ) ) {
			return str_replace( '-', '–', (string) ufsc_lc_format_season_label( ufsc_lc_get_active_season_end_year() ) );
		}

		$year = (int) wp_date( 'Y' );
		return $year . '–' . ( $year + 1 );
	}

	private static function resolve_category( $licence, $birthdate ) {
		foreach ( array( 'categorie_affiche', 'category', 'categorie' ) as $field ) {
			if ( isset( $licence->{$field} ) && '' !== trim( (string) $licence->{$field} ) ) {
				return trim( (string) $licence->{$field} );
			}
		}

		if ( '' !== $birthdate && class_exists( 'UFSC_LC_Categories' ) ) {
			$raw_birthdate = (string) ( $licence->date_naissance ?? '' );
			$season_value  = (string) ( $licence->season_end_year ?? ( $licence->paid_season ?? ( $licence->saison ?? ( $licence->season ?? '' ) ) ) );
			$end_year      = function_exists( 'ufsc_lc_normalize_season_end_year' ) ? ufsc_lc_normalize_season_end_year( $season_value ) : null;
			if ( $end_year ) {
				$computed = UFSC_LC_Categories::category_from_birthdate( $raw_birthdate, $end_year );
				if ( is_array( $computed ) && ! empty( $computed['category'] ) ) {
					return (string) $computed['category'];
				}
			}
		}

		return '';
	}

	private static function resolve_profile( $licence ) {
		$value = $licence->competition ?? '';
		if ( is_bool( $value ) || is_numeric( $value ) ) {
			return ! empty( $value ) ? __( 'Compétiteur', 'ufsc-licence-competition' ) : __( 'Pratiquant', 'ufsc-licence-competition' );
		}
		$key = strtolower( remove_accents( trim( (string) $value ) ) );
		return in_array( $key, array( '1', 'oui', 'yes', 'true', 'competition', 'competiteur' ), true )
			? __( 'Compétiteur', 'ufsc-licence-competition' )
			: __( 'Pratiquant', 'ufsc-licence-competition' );
	}

	private static function resolve_role( $licence ) {
		$role = sanitize_key( (string) ( $licence->role ?? '' ) );
		$map  = array(
			'president'  => __( 'Président', 'ufsc-licence-competition' ),
			'secretaire' => __( 'Secrétaire', 'ufsc-licence-competition' ),
			'tresorier'  => __( 'Trésorier', 'ufsc-licence-competition' ),
			'entraineur' => __( 'Entraîneur', 'ufsc-licence-competition' ),
			'coach'      => __( 'Coach', 'ufsc-licence-competition' ),
			'officiel'   => __( 'Officiel', 'ufsc-licence-competition' ),
		);
		return $map[ $role ] ?? '';
	}

	private static function first_character( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, 1, 'UTF-8' ) : substr( $value, 0, 1 );
	}

	/**
	 * Convert an attachment id or media URL to an embedded data URI.
	 */
	private static function image_value_to_data_uri( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}

		$attachment_id = ctype_digit( $value ) ? absint( $value ) : 0;
		if ( ! $attachment_id && filter_var( $value, FILTER_VALIDATE_URL ) && function_exists( 'attachment_url_to_postid' ) ) {
			$attachment_id = absint( attachment_url_to_postid( $value ) );
		}
		if ( ! $attachment_id ) {
			return '';
		}

		$path = get_attached_file( $attachment_id );
		return self::file_to_data_uri( $path );
	}

	private static function get_default_logo_data_uri() {
		$site_icon_id = absint( get_option( 'site_icon', 0 ) );
		if ( $site_icon_id ) {
			$uri = self::file_to_data_uri( get_attached_file( $site_icon_id ) );
			if ( $uri ) {
				return $uri;
			}
		}
		return '';
	}

	private static function file_to_data_uri( $path ) {
		if ( ! $path || ! is_readable( $path ) ) {
			return '';
		}
		$mime = function_exists( 'mime_content_type' ) ? mime_content_type( $path ) : '';
		if ( ! is_string( $mime ) || 0 !== strpos( $mime, 'image/' ) ) {
			$filetype = wp_check_filetype( $path );
			$mime = isset( $filetype['type'] ) ? (string) $filetype['type'] : '';
		}
		if ( 0 !== strpos( (string) $mime, 'image/' ) ) {
			return '';
		}
		$contents = file_get_contents( $path );
		if ( false === $contents ) {
			return '';
		}
		return 'data:' . $mime . ';base64,' . base64_encode( $contents );
	}

	private static function safe_data_uri( $value ) {
		$value = trim( (string) $value );
		return preg_match( '#^data:image/(?:png|jpe?g|gif|webp|svg\+xml);base64,[A-Za-z0-9+/=\r\n]+$#i', $value ) ? $value : '';
	}

	private static function documents_tables_exist() {
		global $wpdb;
		return self::table_exists( $wpdb->prefix . 'ufsc_licence_documents' )
			&& self::table_exists( $wpdb->prefix . 'ufsc_licence_documents_meta' );
	}

	private static function table_exists( $table ) {
		global $wpdb;
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	private static function get_document_row( $licence_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'ufsc_licence_documents';
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, attachment_id, source_licence_number FROM {$table} WHERE licence_id = %d AND source = %s LIMIT 1",
				$licence_id,
				self::SOURCE
			)
		);
	}

	private static function get_document_meta( $licence_id, $meta_key ) {
		global $wpdb;
		$table = $wpdb->prefix . 'ufsc_licence_documents_meta';
		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT meta_value FROM {$table} WHERE licence_id = %d AND source = %s AND meta_key = %s LIMIT 1",
				$licence_id,
				self::SOURCE,
				$meta_key
			)
		);
		return null === $value ? null : maybe_unserialize( $value );
	}

	private static function upsert_document( $licence_id, $license_number, $attachment_id ) {
		global $wpdb;
		$table    = $wpdb->prefix . 'ufsc_licence_documents';
		$existing = self::get_document_row( $licence_id );
		$data     = array(
			'licence_id'            => $licence_id,
			'source'                => self::SOURCE,
			'source_licence_number' => $license_number,
			'attachment_id'         => $attachment_id,
			'updated_at'             => current_time( 'mysql' ),
		);

		if ( $existing ) {
			$wpdb->update(
				$table,
				$data,
				array( 'id' => absint( $existing->id ) ),
				array( '%d', '%s', '%s', '%d', '%s' ),
				array( '%d' )
			);
		} else {
			$data['imported_at'] = current_time( 'mysql' );
			$wpdb->insert( $table, $data, array( '%d', '%s', '%s', '%d', '%s', '%s' ) );
		}
	}

	private static function set_document_meta( $licence_id, $meta_key, $meta_value ) {
		global $wpdb;
		$table       = $wpdb->prefix . 'ufsc_licence_documents_meta';
		$existing_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE licence_id = %d AND source = %s AND meta_key = %s LIMIT 1",
				$licence_id,
				self::SOURCE,
				$meta_key
			)
		);
		$data = array(
			'licence_id' => $licence_id,
			'source'     => self::SOURCE,
			'meta_key'   => $meta_key,
			'meta_value' => maybe_serialize( $meta_value ),
			'updated_at' => current_time( 'mysql' ),
		);

		if ( $existing_id ) {
			$wpdb->update( $table, $data, array( 'id' => absint( $existing_id ) ), array( '%d', '%s', '%s', '%s', '%s' ), array( '%d' ) );
		} else {
			$wpdb->insert( $table, $data, array( '%d', '%s', '%s', '%s', '%s' ) );
		}
	}

	private static function bump_caches( $licence ) {
		if ( ! function_exists( 'ufsc_lc_bump_cache_version' ) ) {
			return;
		}
		if ( ! empty( $licence->club_id ) ) {
			ufsc_lc_bump_cache_version( 'club', absint( $licence->club_id ) );
		}
		ufsc_lc_bump_cache_version( 'status', 0 );
	}

	private static function log( $action, $licence_id, array $context = array() ) {
		$context = array_merge(
			array(
				'action'     => $action,
				'licence_id' => absint( $licence_id ),
			),
			$context
		);
		if ( class_exists( 'UFSC_LC_Logger' ) ) {
			UFSC_LC_Logger::log( __( 'Génération licence PDF.', 'ufsc-licence-competition' ), $context );
		}
	}
}
