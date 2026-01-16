<?php
/**
 * File: class-ufsc-lc-asptt-import-service.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UFSC_LC_ASPTT_Import_Service {
	const SOURCE = 'ASPTT';

	const STATUS_LINKED               = 'linked';
	const STATUS_CLUB_NOT_FOUND       = 'club_not_found';
	const STATUS_NEEDS_REVIEW         = 'needs_review';
	const STATUS_LICENCE_MISSING      = 'licence_not_found';
	const STATUS_INVALID_ASPTT_NUMBER = 'invalid_asptt_number';
	const STATUS_INVALID_SEASON       = 'invalid_season';
	const STATUS_INVALID_BIRTHDATE    = 'invalid_birthdate';

	const MAX_FILE_SIZE          = 5242880; // 5 MB.
	const PREVIEW_DEFAULT_LIMIT  = 50;
	const PREVIEW_MIN_LIMIT      = 10;
	const PREVIEW_MAX_LIMIT      = 200;
	const IMPORT_CHUNK_SIZE      = 200;

	public function validate_upload( $file ) {
		if ( empty( $file ) || empty( $file['tmp_name'] ) ) {
			return new WP_Error( 'missing_file', __( 'Fichier manquant.', 'ufsc-licence-competition' ) );
		}

		if ( ! empty( $file['error'] ) ) {
			return new WP_Error( 'upload_error', __( 'Erreur lors du téléversement.', 'ufsc-licence-competition' ) );
		}

		if ( ! empty( $file['size'] ) && (int) $file['size'] > self::MAX_FILE_SIZE ) {
			return new WP_Error( 'file_too_large', __( 'Le fichier dépasse la taille maximale autorisée (5 Mo).', 'ufsc-licence-competition' ) );
		}

		$check = wp_check_filetype_and_ext(
			$file['tmp_name'],
			$file['name'],
			array(
				'csv' => 'text/csv',
				'txt' => 'text/plain',
			)
		);

		if ( empty( $check['ext'] ) || 'csv' !== strtolower( $check['ext'] ) ) {
			return new WP_Error( 'invalid_type', __( 'Le fichier doit être un CSV.', 'ufsc-licence-competition' ) );
		}

		return true;
	}

	public function store_upload( $file ) {
		$validated = $this->validate_upload( $file );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		$upload_dir = wp_upload_dir();
		$target_dir = trailingslashit( $upload_dir['basedir'] ) . 'ufsc-lc';
		if ( ! wp_mkdir_p( $target_dir ) ) {
			return new WP_Error( 'upload_dir', __( 'Impossible de créer le dossier de destination.', 'ufsc-licence-competition' ) );
		}

		$this->ensure_upload_protection( $target_dir );

		$overrides = array(
			'test_form' => false,
			'mimes'     => array(
				'csv' => 'text/csv',
				'txt' => 'text/plain',
			),
		);

		$movefile = wp_handle_upload( $file, $overrides );

		if ( empty( $movefile['file'] ) ) {
			$message = ! empty( $movefile['error'] ) ? $movefile['error'] : __( 'Téléversement impossible.', 'ufsc-licence-competition' );
			return new WP_Error( 'upload_error', $message );
		}

		$filename    = wp_basename( $movefile['file'] );
		$destination = trailingslashit( $target_dir ) . $filename;

		if ( ! rename( $movefile['file'], $destination ) ) {
			return new WP_Error( 'move_failed', __( 'Impossible de déplacer le fichier.', 'ufsc-licence-competition' ) );
		}

		return array(
			'path' => $destination,
			'name' => $filename,
			'size' => (int) $file['size'],
		);
	}

	public function build_preview( $file_path, $force_club_id, $mapping = array(), $limit = 0, $season_end_year_override = null ) {
		$preview_rows = array();
		$errors       = array();
		$stats        = array(
			'total'                => 0,
			'clubs_linked'         => 0,
			'licences_linked'      => 0,
			'club_not_found'       => 0,
			'needs_review'         => 0,
			'licence_not_found'    => 0,
			'invalid_asptt_number' => 0,
			'invalid_season'       => 0,
			'invalid_birthdate'    => 0,
		);

		$preview_limit = $this->sanitize_preview_limit( $limit );

		$parsed = $this->read_csv( $file_path, $mapping, $preview_limit );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		$headers = $parsed['headers_raw'];
		$mapping = $parsed['mapping'];

		foreach ( $parsed['rows'] as $row ) {
			$stats['total']++;
			$result = $this->process_row( $row, $force_club_id, $stats, $season_end_year_override );

			if ( $result['preview'] ) {
				$preview_rows[] = $result['preview'];
			}
			if ( ! empty( $result['error'] ) ) {
				$errors[] = $result['error'];
			}
		}

		return array(
			'rows'          => $preview_rows,
			'stats'         => $stats,
			'errors'        => $errors,
			'headers'       => $headers,
			'mapping'       => $mapping,
			'preview_limit' => $preview_limit,
		);
	}

	public function import_from_file( $file_path, $force_club_id, $mapping = array(), $auto_approve = true, $season_end_year_override = null, $auto_save_alias = true ) {
		global $wpdb;

		$use_transactions = false;
		if ( $wpdb->has_cap( 'transactions' ) ) {
			$use_transactions = false !== $wpdb->query( 'START TRANSACTION' );
		}

		$inserted  = array();
		$has_error = false;

		$stats = array(
			'total'      => 0,
			'ok'         => 0,
			'errors'     => 0,
			'duplicates' => 0,
		);

		$has_meta_table = $this->table_exists( $this->get_documents_meta_table() );

		$process_result = $this->iterate_csv_rows_in_chunks(
			$file_path,
			$mapping,
			self::IMPORT_CHUNK_SIZE,
			function( $rows ) use ( $force_club_id, $auto_approve, $has_meta_table, &$inserted, &$has_error, &$stats, $wpdb, $season_end_year_override, $auto_save_alias ) {

				foreach ( $rows as $row ) {
					$stats['total']++;

					$stats_tmp = null;
					$result    = $this->process_row( $row, $force_club_id, $stats_tmp, $season_end_year_override );
					$data   = $result['data'];

					if ( ! empty( $result['error'] ) ) {
						$stats['errors']++;
						continue;
					}

					if ( empty( $data['status'] ) || self::STATUS_LINKED !== $data['status'] || '' === $data['asptt_number'] ) {
						$stats['errors']++;
						continue;
					}

					// Update season/category/age_ref on licence.
					$this->update_licence_season_data(
						(int) $data['licence_id'],
						$data['season_end_year'],
						$data['category'],
						$data['age_ref']
					);

					// Save alias (force club mode).
					$this->save_alias_for_row( $force_club_id, $data, $auto_save_alias );

					// Upsert document.
					$doc_id = $this->upsert_document(
						(int) $data['licence_id'],
						$data['asptt_number'],
						$data['attachment_id'],
						$data['note'],
						$data['source_created_at']
					);

					if ( $has_meta_table ) {
						$review_status = 'pending';
						$link_mode     = ! empty( $data['link_mode'] ) ? $data['link_mode'] : 'none';

						if ( $auto_approve && ! empty( $data['auto_linked'] ) ) {
							$review_status = 'approved';
						}

						$this->upsert_document_meta( (int) $data['licence_id'], self::SOURCE, 'confidence_score', (int) $data['confidence_score'] );
						$this->upsert_document_meta( (int) $data['licence_id'], self::SOURCE, 'link_mode', $link_mode );
						$this->upsert_document_meta( (int) $data['licence_id'], self::SOURCE, 'review_status', $review_status );
						$this->upsert_document_meta( (int) $data['licence_id'], self::SOURCE, 'club_resolution', $data['club_resolution'] );
						$this->upsert_document_meta( (int) $data['licence_id'], self::SOURCE, 'person_resolution', $data['person_resolution'] );
					}

					// SQL error guard (both false return and wpdb->last_error).
					if ( false === $doc_id || ! empty( $wpdb->last_error ) ) {
						$has_error = true;
						$this->log_import_warning(
							__( 'Erreur SQL lors de l’import ASPTT.', 'ufsc-licence-competition' ),
							array( 'error' => $wpdb->last_error )
						);
						return false;
					}

					// Duplicate check for business key (ASPTT number) pointing to another licence.
					$duplicate = $this->find_document_by_source_number( $data['asptt_number'] );
					if ( $duplicate && (int) $duplicate->licence_id !== (int) $data['licence_id'] ) {
						$stats['errors']++;
						$stats['duplicates']++;

						$this->log_import_warning(
							__( 'Doublon détecté sur la clé métier.', 'ufsc-licence-competition' ),
							array(
								'asptt_number' => $data['asptt_number'],
								'licence_id'   => (int) $data['licence_id'],
								'existing_id'  => (int) $duplicate->licence_id,
							)
						);

						continue;
					}

					$inserted[] = (int) $doc_id;
					$stats['ok']++;
				}

				return true;
			}
		);

		if ( is_wp_error( $process_result ) ) {
			return $process_result;
		}

		if ( $use_transactions ) {
			if ( $has_error ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'import_failed', __( 'Import interrompu, transaction annulée.', 'ufsc-licence-competition' ) );
			}
			$wpdb->query( 'COMMIT' );
		} elseif ( $has_error ) {
			$this->rollback_inserted( $inserted );
			return new WP_Error( 'import_failed', __( 'Import interrompu, rollback partiel effectué.', 'ufsc-licence-competition' ) );
		}

		return array(
			'inserted'          => $inserted,
			'used_transactions' => $use_transactions,
			'stats'             => $stats,
		);
	}

	public function export_errors_csv( $errors ) {
		$filename = 'asptt-errors-' . gmdate( 'Ymd-His' ) . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		$output = fopen( 'php://output', 'w' );
		fwrite( $output, "\xEF\xBB\xBF" );

		fputcsv(
			$output,
			array(
				__( 'Nom', 'ufsc-licence-competition' ),
				__( 'Prenom', 'ufsc-licence-competition' ),
				__( 'DateNaissance', 'ufsc-licence-competition' ),
				__( 'Note', 'ufsc-licence-competition' ),
				__( 'N° Licence', 'ufsc-licence-competition' ),
				__( 'Date ASPTT brute', 'ufsc-licence-competition' ),
				__( 'Status', 'ufsc-licence-competition' ),
				__( 'Erreur', 'ufsc-licence-competition' ),
			),
			';'
		);

		foreach ( $errors as $error ) {
			fputcsv(
				$output,
				array(
					$error['nom'],
					$error['prenom'],
					$error['date_naissance'],
					$error['note'],
					$error['asptt_number'],
					$error['source_created_at_raw'],
					$error['status'],
					$error['error'],
				),
				';'
			);
		}

		fclose( $output );
		exit;
	}

	public function sanitize_mapping( $mapping ) {
		$allowed   = $this->get_allowed_columns();
		$sanitized = array();

		foreach ( (array) $mapping as $header => $column ) {
			$header_key = sanitize_text_field( wp_unslash( $header ) );
			$column_key = sanitize_text_field( wp_unslash( $column ) );

			if ( '' === $column_key || ! in_array( $column_key, $allowed, true ) ) {
				continue;
			}

			$sanitized[ $header_key ] = $column_key;
		}

		return $sanitized;
	}

	public function sanitize_preview_limit( $limit ) {
		$limit = absint( $limit );

		if ( $limit <= 0 ) {
			return self::PREVIEW_DEFAULT_LIMIT;
		}
		if ( $limit < self::PREVIEW_MIN_LIMIT ) {
			return self::PREVIEW_MIN_LIMIT;
		}
		if ( $limit > self::PREVIEW_MAX_LIMIT ) {
			return self::PREVIEW_MAX_LIMIT;
		}

		return $limit;
	}

	private function process_row( $row, $force_club_id, &$stats = null, $season_end_year_override = null ) {
		$note               = isset( $row['Note'] ) ? sanitize_text_field( $row['Note'] ) : '';
		$nom                = isset( $row['Nom'] ) ? sanitize_text_field( $row['Nom'] ) : '';
		$prenom             = isset( $row['Prenom'] ) ? sanitize_text_field( $row['Prenom'] ) : '';
		$dob                = isset( $row['Date de naissance'] ) ? sanitize_text_field( $row['Date de naissance'] ) : '';
		$raw_season_end_year = isset( $row['Saison (année de fin)'] ) ? sanitize_text_field( $row['Saison (année de fin)'] ) : '';
		$season_end_year     = UFSC_LC_Categories::sanitize_season_end_year( $raw_season_end_year );
		$season_end_year_override = UFSC_LC_Categories::sanitize_season_end_year( $season_end_year_override );

		$asptt_no = isset( $row['N° Licence'] ) ? sanitize_text_field( $row['N° Licence'] ) : '';
		$asptt_no = trim( $asptt_no );

		$genre          = isset( $row['genre'] ) ? sanitize_text_field( $row['genre'] ) : '';
		$raw_created_at = isset( $row['Date de création de la licence'] ) ? sanitize_text_field( $row['Date de création de la licence'] ) : '';

		$source_created_at = $this->parse_source_created_at( $raw_created_at );

		$row_errors       = array();
		$club_suggestions = array();
		$category         = '';
		$age_ref          = null;

		$licence_id       = 0;
		$status           = self::STATUS_CLUB_NOT_FOUND;
		$skip_resolution  = false;
		$person_resolution = 'none';

		$resolved = array(
			'status'      => self::STATUS_CLUB_NOT_FOUND,
			'club_id'     => 0,
			'suggestions' => array(),
			'resolution'  => 'none',
		);

		// Season + category computation at 31/12 (handled by UFSC_LC_Categories).
		if ( null === $season_end_year && null !== $season_end_year_override ) {
			$season_end_year = $season_end_year_override;
		}

		if ( null === $season_end_year ) {
			$default_season_end_year = $this->get_default_season_end_year();
			$season_end_year         = UFSC_LC_Categories::sanitize_season_end_year( $default_season_end_year );
		}

		if ( null === $season_end_year ) {
			$status          = self::STATUS_INVALID_SEASON;
			$skip_resolution = true;

			if ( is_array( $stats ) ) {
				$stats['invalid_season']++;
			}

			$row_errors[] = __( 'Saison de fin manquante ou invalide.', 'ufsc-licence-competition' );

			$this->log_import_warning(
				'Saison de fin manquante ou invalide.',
				array(
					'nom'            => $nom,
					'prenom'         => $prenom,
					'date_naissance' => $dob,
					'saison'         => $raw_season_end_year,
				)
			);
		} else {
			$computed = UFSC_LC_Categories::category_from_birthdate( $dob, $season_end_year );
			$category = $computed['category'];
			$age_ref  = $computed['age'];

			if ( '' === $dob || null === $age_ref ) {
				$status          = self::STATUS_INVALID_BIRTHDATE;
				$skip_resolution = true;

				if ( is_array( $stats ) ) {
					$stats['invalid_birthdate']++;
				}

				$row_errors[] = __( 'Date de naissance invalide.', 'ufsc-licence-competition' );

				$this->log_import_warning(
					'Date de naissance invalide.',
					array(
						'nom'            => $nom,
						'prenom'         => $prenom,
						'date_naissance' => $dob,
					)
				);
			}
		}

		if ( '' === $asptt_no ) {
			$status          = self::STATUS_INVALID_ASPTT_NUMBER;
			$skip_resolution = true;

			if ( is_array( $stats ) ) {
				$stats['invalid_asptt_number']++;
			}

			$row_errors[] = __( 'N° Licence ASPTT manquant.', 'ufsc-licence-competition' );

			$this->log_import_warning(
				__( 'N° Licence ASPTT manquant.', 'ufsc-licence-competition' ),
				array(
					'nom'            => $nom,
					'prenom'         => $prenom,
					'date_naissance' => $dob,
					'note'           => $note,
				)
			);
		}

		if ( ! $skip_resolution && '' !== $asptt_no ) {
			$resolved         = $this->resolve_club( $note, $force_club_id );
			$club_suggestions = $resolved['suggestions'];

			if ( self::STATUS_LINKED === $resolved['status'] ) {
				if ( is_array( $stats ) ) {
					$stats['clubs_linked']++;
				}

				$licence_match    = $this->find_licence_match( $resolved['club_id'], $nom, $prenom, $dob, $genre );
				$licence_id       = $licence_match['id'];
				$person_resolution = $licence_match['resolution'];

				if ( $licence_id ) {
					$status = self::STATUS_LINKED;

					if ( is_array( $stats ) ) {
						$stats['licences_linked']++;
					}
				} else {
					$status = self::STATUS_LICENCE_MISSING;

					if ( is_array( $stats ) ) {
						$stats['licence_not_found']++;
					}

					$row_errors[] = __( 'Licence introuvable.', 'ufsc-licence-competition' );
				}
			} elseif ( self::STATUS_NEEDS_REVIEW === $resolved['status'] ) {
				$status = self::STATUS_NEEDS_REVIEW;

				if ( is_array( $stats ) ) {
					$stats['needs_review']++;
				}

				$row_errors[] = __( 'Plusieurs clubs possibles.', 'ufsc-licence-competition' );
			} else {
				$status = self::STATUS_CLUB_NOT_FOUND;

				if ( is_array( $stats ) ) {
					$stats['club_not_found']++;
				}

				$row_errors[] = __( 'Club introuvable.', 'ufsc-licence-competition' );
			}
		}

		if ( '' !== $raw_created_at && null === $source_created_at ) {
			$row_errors[] = sprintf(
				/* translators: %s: raw date string */
				__( 'Date de création de la licence invalide: %s', 'ufsc-licence-competition' ),
				$raw_created_at
			);

			$this->log_import_warning(
				__( 'Date de création de la licence invalide.', 'ufsc-licence-competition' ),
				array( 'value' => $raw_created_at )
			);
		}

		$confidence = $this->compute_confidence_score(
			array(
				'status'            => $status,
				'club_resolution'   => $resolved['resolution'],
				'person_resolution' => $person_resolution,
				'raw_created_at'    => $raw_created_at,
				'source_created_at' => $source_created_at,
				'has_error'         => ! empty( $row_errors ),
			)
		);

		$review_status = $confidence['auto_linked'] ? 'auto' : 'pending';

		$preview = array(
			'nom'                  => $nom,
			'prenom'               => $prenom,
			'date_naissance'       => $dob,
			'season_end_year'      => $season_end_year,
			'category'             => $category,
			'age_ref'              => $age_ref,
			'note'                 => $note,
			'asptt_number'         => $asptt_no,
			'source_created_at'    => $source_created_at,
			'source_created_at_raw'=> $raw_created_at,
			'club_id'              => $resolved['club_id'],
			'club_suggestions'     => $club_suggestions,
			'status'               => $status,
			'licence_id'           => $licence_id,
			'attachment_id'        => 0,
			'has_error'            => ! empty( $row_errors ),
			'confidence_score'     => $confidence['confidence_score'],
			'link_mode'            => $confidence['link_mode'],
			'review_status'        => $review_status,
			'auto_linked'          => $confidence['auto_linked'],
			'club_resolution'      => $resolved['resolution'],
			'person_resolution'    => $person_resolution,
		);

		$error = null;
		if ( ! empty( $row_errors ) ) {
			$error = array(
				'nom'                  => $nom,
				'prenom'               => $prenom,
				'date_naissance'       => $dob,
				'note'                 => $note,
				'asptt_number'         => $asptt_no,
				'source_created_at'    => ( null !== $source_created_at ) ? $source_created_at : $raw_created_at,
				'source_created_at_raw'=> $raw_created_at,
				'status'               => $status,
				'error'                => implode( ' | ', $row_errors ),
			);
		}

		return array(
			'preview' => $preview,
			'error'   => $error,
			'data'    => $preview,
		);
	}

	private function read_csv( $file_path, $mapping, $limit = 0 ) {
		if ( ! is_readable( $file_path ) ) {
			return new WP_Error( 'file_read', __( 'Fichier illisible.', 'ufsc-licence-competition' ) );
		}

		$handle = fopen( $file_path, 'r' );
		if ( ! $handle ) {
			return new WP_Error( 'file_open', __( 'Impossible d’ouvrir le fichier.', 'ufsc-licence-competition' ) );
		}

		$delimiter  = $this->detect_delimiter( $handle );
		$header     = array();
		$raw_header = array();
		$rows       = array();

		while ( false !== ( $data = fgetcsv( $handle, 0, $delimiter ) ) ) {
			$data = $this->convert_row_encoding( $data );

			if ( $this->is_empty_row( $data ) ) {
				continue;
			}

			if ( empty( $header ) ) {
				$raw_header = $data;
				$header     = $this->map_headers( $data, $mapping );
				continue;
			}

			if ( $this->looks_like_header( $data, $header ) ) {
				continue;
			}

			$row = array();
			foreach ( $header as $index => $column ) {
				if ( '' === $column ) {
					continue;
				}
				$row[ $column ] = isset( $data[ $index ] ) ? $data[ $index ] : '';
			}

			$rows[] = $row;

			if ( $limit > 0 && count( $rows ) >= $limit ) {
				break;
			}
		}

		fclose( $handle );

		return array(
			'rows'        => $rows,
			'headers'     => $header,
			'headers_raw' => $raw_header,
			'mapping'     => $mapping,
		);
	}

	private function iterate_csv_rows_in_chunks( $file_path, $mapping, $chunk_size, $callback ) {
		if ( ! is_readable( $file_path ) ) {
			return new WP_Error( 'file_read', __( 'Fichier illisible.', 'ufsc-licence-competition' ) );
		}

		$handle = fopen( $file_path, 'r' );
		if ( ! $handle ) {
			return new WP_Error( 'file_open', __( 'Impossible d’ouvrir le fichier.', 'ufsc-licence-competition' ) );
		}

		$delimiter = $this->detect_delimiter( $handle );
		$header    = array();
		$chunk     = array();

		while ( false !== ( $data = fgetcsv( $handle, 0, $delimiter ) ) ) {
			$data = $this->convert_row_encoding( $data );

			if ( $this->is_empty_row( $data ) ) {
				continue;
			}

			if ( empty( $header ) ) {
				$header = $this->map_headers( $data, $mapping );
				continue;
			}

			if ( $this->looks_like_header( $data, $header ) ) {
				continue;
			}

			$row = array();
			foreach ( $header as $index => $column ) {
				if ( '' === $column ) {
					continue;
				}
				$row[ $column ] = isset( $data[ $index ] ) ? $data[ $index ] : '';
			}

			$chunk[] = $row;

			if ( $chunk_size > 0 && count( $chunk ) >= $chunk_size ) {
				if ( false === call_user_func( $callback, $chunk ) ) {
					fclose( $handle );
					return false;
				}
				$chunk = array();
			}
		}

		if ( ! empty( $chunk ) ) {
			if ( false === call_user_func( $callback, $chunk ) ) {
				fclose( $handle );
				return false;
			}
		}

		fclose( $handle );
		return true;
	}

	private function detect_delimiter( $handle ) {
		$line = fgets( $handle );
		rewind( $handle );

		if ( false === $line ) {
			return ';';
		}

		$semicolon = substr_count( $line, ';' );
		$comma     = substr_count( $line, ',' );

		return $comma > $semicolon ? ',' : ';';
	}

	private function convert_row_encoding( array $data ) {
		foreach ( $data as $index => $value ) {
			$data[ $index ] = $this->convert_to_utf8( $value );
		}
		return $data;
	}

	private function convert_to_utf8( $value ) {
		$value = (string) $value;
		if ( '' === $value ) {
			return '';
		}

		if ( function_exists( 'mb_detect_encoding' ) ) {
			$encoding = mb_detect_encoding( $value, array( 'UTF-8', 'ISO-8859-1', 'Windows-1252' ), true );
			if ( $encoding && 'UTF-8' !== $encoding ) {
				$value = mb_convert_encoding( $value, 'UTF-8', $encoding );
			}
		}

		return $value;
	}

	private function is_empty_row( $data ) {
		foreach ( $data as $value ) {
			if ( '' !== trim( (string) $value ) ) {
				return false;
			}
		}
		return true;
	}

	private function looks_like_header( $data, $header ) {
		$normalized = array();
		foreach ( $data as $value ) {
			$normalized[] = $this->normalize_header( $value );
		}

		$header_normalized = array();
		foreach ( $header as $value ) {
			$header_normalized[] = $this->normalize_header( $value );
		}

		return ! empty( $normalized ) && $normalized === $header_normalized;
	}

	private function map_headers( $headers, $mapping ) {
		$mapped   = array();
		$used     = array();
		$allowed  = $this->get_allowed_columns();
		$mapping  = $this->sanitize_mapping( $mapping );

		foreach ( $headers as $header ) {
			$header = trim( (string) $header );
			$key    = isset( $mapping[ $header ] ) ? $mapping[ $header ] : $this->map_header( $header );

			if ( ! in_array( $key, $allowed, true ) ) {
				$key = '';
			}
			if ( $key && isset( $used[ $key ] ) ) {
				$key = '';
			}
			if ( $key ) {
				$used[ $key ] = true;
			}

			$mapped[] = $key;
		}

		return $mapped;
	}

	private function map_header( $header ) {
		$normalized = $this->normalize_header( $header );

		$mapping = array(
			'nom'                         => 'Nom',
			'prenom'                      => 'Prenom',
			'genre'                       => 'genre',
			'sexe'                        => 'genre',
			'date de naissance'           => 'Date de naissance',
			'date naissance'              => 'Date de naissance',
			'date of birth'               => 'Date de naissance',
			'birth date'                  => 'Date de naissance',
			'saison'                      => 'Saison (année de fin)',
			'fin de saison'               => 'Saison (année de fin)',
			'saison fin'                  => 'Saison (année de fin)',
			'annee de fin de saison'      => 'Saison (année de fin)',
			'annee fin saison'            => 'Saison (année de fin)',
			'season end year'             => 'Saison (année de fin)',
			'season end'                  => 'Saison (année de fin)',
			'season'                      => 'Saison (année de fin)',
			'season_end_year'             => 'Saison (année de fin)',
			'saison_fin'                  => 'Saison (année de fin)',
			'n licence'                   => 'N° Licence',
			'no licence'                  => 'N° Licence',
			'numero licence'              => 'N° Licence',
			'numero de licence'           => 'N° Licence',
			'num licence'                 => 'N° Licence',
			'licence number'              => 'N° Licence',
			'license number'              => 'N° Licence',
			'date de creation de la licence' => 'Date de création de la licence',
			'date d creation de la licence'  => 'Date de création de la licence',
			'date creation de la licence'    => 'Date de création de la licence',
			'date creation licence'          => 'Date de création de la licence',
			'date de creation licence'       => 'Date de création de la licence',
			'date creation de licence'       => 'Date de création de la licence',
			'date creation'                  => 'Date de création de la licence',
			'note'                           => 'Note',
		);

		return isset( $mapping[ $normalized ] ) ? $mapping[ $normalized ] : '';
	}

	private function normalize_header( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}

		$value = remove_accents( $value );
		$value = strtolower( $value );
		$value = preg_replace( '/[^a-z0-9]+/u', ' ', $value );
		$value = preg_replace( '/\s+/', ' ', $value );

		return trim( $value );
	}

	private function resolve_club( $note, $force_club_id ) {
		if ( $force_club_id ) {
			return array(
				'status'      => self::STATUS_LINKED,
				'club_id'     => $force_club_id,
				'suggestions' => array(),
				'resolution'  => 'forced',
			);
		}

		if ( '' === $note ) {
			return array(
				'status'      => self::STATUS_CLUB_NOT_FOUND,
				'club_id'     => 0,
				'suggestions' => array(),
				'resolution'  => 'none',
			);
		}

		$normalized = $this->normalize_name( $note );

		$club = $this->find_club_by_name( $normalized );
		if ( $club ) {
			return array(
				'status'      => self::STATUS_LINKED,
				'club_id'     => (int) $club->id,
				'suggestions' => array(),
				'resolution'  => 'exact',
			);
		}

		$alias = $this->find_alias( $normalized );
		if ( $alias ) {
			return array(
				'status'      => self::STATUS_LINKED,
				'club_id'     => (int) $alias->club_id,
				'suggestions' => array(),
				'resolution'  => 'alias',
			);
		}

		$suggestions = $this->find_club_suggestions( $normalized );
		if ( 1 === count( $suggestions ) ) {
			return array(
				'status'      => self::STATUS_LINKED,
				'club_id'     => (int) $suggestions[0]->id,
				'suggestions' => array(),
				'resolution'  => 'suggestion',
			);
		}

		if ( count( $suggestions ) > 1 ) {
			$suggestion_rows = array();
			foreach ( $suggestions as $suggestion ) {
				$suggestion_rows[] = array(
					'id'   => (int) $suggestion->id,
					'name' => $suggestion->nom,
				);
			}

			return array(
				'status'      => self::STATUS_NEEDS_REVIEW,
				'club_id'     => 0,
				'suggestions' => $suggestion_rows,
				'resolution'  => 'none',
			);
		}

		return array(
			'status'      => self::STATUS_CLUB_NOT_FOUND,
			'club_id'     => 0,
			'suggestions' => array(),
			'resolution'  => 'none',
		);
	}

	private function find_licence_match( $club_id, $nom, $prenom, $dob, $genre ) {
		global $wpdb;

		$table = $this->get_licences_table();

		$normalized_nom    = $this->normalize_name( $nom );
		$normalized_prenom = $this->normalize_name( $prenom );
		$dob_value         = $this->parse_date( $dob );

		if ( ! $dob_value ) {
			return array(
				'id'         => 0,
				'resolution' => 'none',
			);
		}

		$sql = "SELECT id, sexe, nom_licence, prenom, date_naissance FROM {$table}
			WHERE club_id = %d AND date_naissance = %s";

		$results = $wpdb->get_results( $wpdb->prepare( $sql, $club_id, $dob_value ) );
		if ( empty( $results ) ) {
			return array(
				'id'         => 0,
				'resolution' => 'none',
			);
		}

		$matches = array();
		foreach ( $results as $row ) {
			if ( $this->normalize_name( $row->nom_licence ) === $normalized_nom
				&& $this->normalize_name( $row->prenom ) === $normalized_prenom ) {
				$matches[] = $row;
			}
		}

		if ( empty( $matches ) ) {
			return array(
				'id'         => 0,
				'resolution' => 'none',
			);
		}

		if ( 1 === count( $matches ) ) {
			return array(
				'id'         => (int) $matches[0]->id,
				'resolution' => 'unique',
			);
		}

		if ( $genre ) {
			$genre_normalized = strtolower( trim( $genre ) );
			foreach ( $matches as $match ) {
				if ( strtolower( $match->sexe ) === $genre_normalized ) {
					return array(
						'id'         => (int) $match->id,
						'resolution' => 'gender_tiebreak',
					);
				}
			}
		}

		return array(
			'id'         => 0,
			'resolution' => 'none',
		);
	}

	private function compute_confidence( $context ) {
		return $this->compute_confidence_score( $context );
	}

	private function compute_confidence_score( $context ) {
		$score = 0;

		$club_resolution   = isset( $context['club_resolution'] ) ? $context['club_resolution'] : 'none';
		$person_resolution = isset( $context['person_resolution'] ) ? $context['person_resolution'] : 'none';

		switch ( $club_resolution ) {
			case 'forced':
				$score += 50;
				break;
			case 'exact':
				$score += 45;
				break;
			case 'alias':
				$score += 35;
				break;
			case 'suggestion':
			case 'suggestion_unique':
				$score += 25;
				break;
		}

		switch ( $person_resolution ) {
			case 'unique':
				$score += 45;
				break;
			case 'gender_tiebreak':
				$score += 20;
				break;
		}

		$raw_created_at    = isset( $context['raw_created_at'] ) ? (string) $context['raw_created_at'] : '';
		$source_created_at = isset( $context['source_created_at'] ) ? $context['source_created_at'] : null;

		if ( '' !== $raw_created_at && null === $source_created_at ) {
			$score -= 20;
		}

		$score = max( 0, min( 100, $score ) );

		$has_error   = ! empty( $context['has_error'] );
		$status      = isset( $context['status'] ) ? $context['status'] : '';
		$auto_linked = ( self::STATUS_LINKED === $status && $score >= 85 && ! $has_error );

		if ( self::STATUS_LINKED === $status ) {
			$link_mode = $auto_linked ? 'auto' : 'manual';
		} else {
			$link_mode = 'none';
		}

		return array(
			'confidence_score' => (int) $score,
			'auto_linked'      => $auto_linked,
			'link_mode'        => $link_mode,
		);
	}

	private function save_alias_for_row( $force_club_id, $data, $auto_save_alias ) {
		if ( $auto_save_alias && ! empty( $force_club_id ) && '' !== $data['note'] ) {
			$this->save_alias( (int) $force_club_id, $data['note'] );
		}
	}

	private function get_default_season_end_year() {
		$option = get_option( 'ufsc_lc_default_season_end_year', 2026 );
		$year   = UFSC_LC_Categories::sanitize_season_end_year( $option );

		return $year ? $year : 2026;
	}

	public function save_alias( $club_id, $alias ) {
		global $wpdb;

		$table = $this->get_aliases_table();

		$normalized = $this->normalize_name( $alias );
		if ( '' === $normalized ) {
			return;
		}

		$exists = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE alias_normalized = %s", $normalized )
		);

		if ( $exists ) {
			return;
		}

		$wpdb->insert(
			$table,
			array(
				'club_id'          => $club_id,
				'alias'            => $alias,
				'alias_normalized' => $normalized,
				'created_at'       => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s' )
		);
	}

	private function upsert_document( $licence_id, $source_licence_number, $attachment_id, $note, $source_created_at ) {
		global $wpdb;

		$table = $this->get_documents_table();

		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE licence_id = %d AND source = %s",
				$licence_id,
				self::SOURCE
			)
		);

		$data = array(
			'licence_id'             => $licence_id,
			'source'                 => self::SOURCE,
			'source_licence_number'  => $source_licence_number,
			'attachment_id'          => $attachment_id ? $attachment_id : null,
			'asptt_club_note'        => $note,
			'updated_at'             => current_time( 'mysql' ),
		);

		$field_formats = array(
			'licence_id'            => '%d',
			'source'                => '%s',
			'source_licence_number' => '%s',
			'attachment_id'         => '%d',
			'asptt_club_note'       => '%s',
			'updated_at'            => '%s',
			'source_created_at'     => '%s',
			'imported_at'           => '%s',
		);

		$formats = array();
		foreach ( array_keys( $data ) as $field ) {
			if ( isset( $field_formats[ $field ] ) ) {
				$formats[] = $field_formats[ $field ];
			}
		}

		if ( null !== $source_created_at ) {
			$data['source_created_at'] = $source_created_at;
			$formats[]                 = '%s';
		}

		if ( $existing ) {
			$updated = $wpdb->update(
				$table,
				$data,
				array( 'id' => (int) $existing ),
				$formats,
				array( '%d' )
			);

			if ( false === $updated && ! empty( $wpdb->last_error ) ) {
				return false;
			}

			return (int) $existing;
		}

		$data['imported_at'] = current_time( 'mysql' );

		$formats = array();
		foreach ( array_keys( $data ) as $field ) {
			if ( isset( $field_formats[ $field ] ) ) {
				$formats[] = $field_formats[ $field ];
			}
		}

		$wpdb->insert( $table, $data, $formats );

		if ( ! empty( $wpdb->last_error ) ) {
			return false;
		}

		return (int) $wpdb->insert_id;
	}

	private function upsert_document_meta( $licence_id, $source, $meta_key, $meta_value ) {
		global $wpdb;

		$table = $this->get_documents_meta_table();
		if ( ! $this->table_exists( $table ) ) {
			return false;
		}

		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE licence_id = %d AND source = %s AND meta_key = %s",
				$licence_id,
				$source,
				$meta_key
			)
		);

		$data = array(
			'licence_id' => $licence_id,
			'source'     => $source,
			'meta_key'   => $meta_key,
			'meta_value' => maybe_serialize( $meta_value ),
			'updated_at' => current_time( 'mysql' ),
		);

		$formats = array( '%d', '%s', '%s', '%s', '%s' );

		if ( $existing ) {
			return false !== $wpdb->update(
				$table,
				$data,
				array( 'id' => (int) $existing ),
				$formats,
				array( '%d' )
			);
		}

		return false !== $wpdb->insert( $table, $data, $formats );
	}

	private function normalize_name( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}

		$value = strtoupper( $value );
		$value = remove_accents( $value );
		$value = preg_replace( '/[^A-Z0-9]+/u', ' ', $value );
		$value = preg_replace( '/\s+/', ' ', $value );

		return trim( $value );
	}

	private function parse_date( $value ) {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}

		$parts = explode( '/', $value );
		if ( 3 !== count( $parts ) ) {
			return '';
		}

		$day   = str_pad( $parts[0], 2, '0', STR_PAD_LEFT );
		$month = str_pad( $parts[1], 2, '0', STR_PAD_LEFT );
		$year  = $parts[2];

		if ( ! checkdate( (int) $month, (int) $day, (int) $year ) ) {
			return '';
		}

		return $year . '-' . $month . '-' . $day;
	}

	private function parse_source_created_at( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return null;
		}

		if ( preg_match( '/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2}$/', $value ) ) {
			$date = DateTime::createFromFormat( 'Y-m-d H:i:s', $value );
			return $this->validate_datetime( $date ) ? $date->format( 'Y-m-d H:i:s' ) : null;
		}

		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			$date = DateTime::createFromFormat( 'Y-m-d', $value );
			return $this->validate_datetime( $date ) ? $date->format( 'Y-m-d 00:00:00' ) : null;
		}

		if ( preg_match( '/^\d{1,2}\/\d{1,2}\/\d{4}$/', $value ) ) {
			$date = DateTime::createFromFormat( 'd/m/Y', $value );
			return $this->validate_datetime( $date ) ? $date->format( 'Y-m-d 00:00:00' ) : null;
		}

		return null;
	}

	private function validate_datetime( $date ) {
		if ( ! $date ) {
			return false;
		}

		$errors = DateTime::getLastErrors();
		if ( ! empty( $errors['warning_count'] ) || ! empty( $errors['error_count'] ) ) {
			return false;
		}

		return true;
	}

	private function log_import_warning( $message, $context = array() ) {
		UFSC_LC_Logger::log( $message, $context );
	}

	private function find_club_by_name( $normalized ) {
		global $wpdb;

		$table = $this->get_clubs_table();

		$results = $wpdb->get_results( "SELECT id, nom FROM {$table}" );
		foreach ( $results as $club ) {
			if ( $this->normalize_name( $club->nom ) === $normalized ) {
				return $club;
			}
		}

		return null;
	}

	private function find_alias( $normalized ) {
		global $wpdb;

		$table = $this->get_aliases_table();

		return $wpdb->get_row(
			$wpdb->prepare( "SELECT id, club_id FROM {$table} WHERE alias_normalized = %s", $normalized )
		);
	}

	private function find_document_by_source_number( $source_licence_number ) {
		global $wpdb;

		$table = $this->get_documents_table();

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, licence_id FROM {$table} WHERE source = %s AND source_licence_number = %s",
				self::SOURCE,
				$source_licence_number
			)
		);
	}

	public function insert_import_log( $data ) {
		global $wpdb;

		$table = $this->get_import_logs_table();

		$wpdb->insert(
			$table,
			array(
				'user_id'       => isset( $data['user_id'] ) ? (int) $data['user_id'] : 0,
				'file_name'     => isset( $data['file_name'] ) ? (string) $data['file_name'] : '',
				'mode'          => isset( $data['mode'] ) ? (string) $data['mode'] : 'import',
				'total_rows'    => isset( $data['total_rows'] ) ? (int) $data['total_rows'] : 0,
				'success_rows'  => isset( $data['success_rows'] ) ? (int) $data['success_rows'] : 0,
				'error_rows'    => isset( $data['error_rows'] ) ? (int) $data['error_rows'] : 0,
				'status'        => isset( $data['status'] ) ? (string) $data['status'] : 'completed',
				'error_message' => isset( $data['error_message'] ) ? (string) $data['error_message'] : null,
				'created_at'    => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s' )
		);
	}

	public function get_import_logs( $limit = 10 ) {
		global $wpdb;

		$table = $this->get_import_logs_table();

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, user_id, file_name, mode, total_rows, success_rows, error_rows, status, error_message, created_at
				FROM {$table}
				ORDER BY created_at DESC
				LIMIT %d",
				absint( $limit )
			),
			ARRAY_A
		);
	}

	private function find_club_suggestions( $normalized ) {
		global $wpdb;

		$table  = $this->get_clubs_table();
		$tokens = array_filter( explode( ' ', $normalized ) );

		if ( empty( $tokens ) ) {
			return array();
		}

		$like_clauses = array();
		$params       = array();

		foreach ( $tokens as $token ) {
			$like_clauses[] = 'nom LIKE %s';
			$params[]       = '%' . $wpdb->esc_like( $token ) . '%';
		}

		$sql = "SELECT id, nom FROM {$table} WHERE " . implode( ' AND ', $like_clauses );

		return $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
	}

	public function search_clubs( $term ) {
		global $wpdb;

		$table = $this->get_clubs_table();
		$like  = '%' . $wpdb->esc_like( $term ) . '%';

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, nom FROM {$table} WHERE nom LIKE %s ORDER BY nom ASC LIMIT 20",
				$like
			)
		);
	}

	private function get_allowed_columns() {
		return array(
			'Nom',
			'Prenom',
			'Date de naissance',
			'Saison (année de fin)',
			'N° Licence',
			'Date de création de la licence',
			'Note',
			'genre',
		);
	}

	private function ensure_upload_protection( $directory ) {
		$index_file = trailingslashit( $directory ) . 'index.php';
		if ( ! file_exists( $index_file ) ) {
			file_put_contents( $index_file, "<?php\n" );
		}

		$htaccess_file = trailingslashit( $directory ) . '.htaccess';
		if ( ! file_exists( $htaccess_file ) ) {
			file_put_contents( $htaccess_file, "Options -Indexes\n<IfModule mod_autoindex.c>\nIndexOptions -FancyIndexing\n</IfModule>\n" );
		}
	}

	private function rollback_inserted( $ids ) {
		if ( empty( $ids ) ) {
			return;
		}

		global $wpdb;

		$table        = $this->get_documents_table();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE id IN ({$placeholders})",
				$ids
			)
		);
	}

	private function update_licence_season_data( $licence_id, $season_end_year, $category, $age_ref ) {
		global $wpdb;

		if ( ! $licence_id ) {
			return;
		}

		$table = $this->get_licences_table();
		if ( ! $this->table_exists( $table ) ) {
			return;
		}

		if (
			! $this->column_exists( $table, 'season_end_year' )
			|| ! $this->column_exists( $table, 'category' )
			|| ! $this->column_exists( $table, 'age_ref' )
		) {
			return;
		}

		$season_end_year = UFSC_LC_Categories::sanitize_season_end_year( $season_end_year );
		if ( null === $season_end_year ) {
			return;
		}

		$age_ref_sql = null === $age_ref ? 'NULL' : '%d';

		$params = array( $season_end_year, $category );
		if ( null !== $age_ref ) {
			$params[] = $age_ref;
		}
		$params[] = $licence_id;

		$sql = $wpdb->prepare(
			"UPDATE {$table} SET season_end_year = %d, category = %s, age_ref = {$age_ref_sql} WHERE id = %d",
			$params
		);

		$wpdb->query( $sql );
	}

	private function table_exists( $table ) {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	private function column_exists( $table, $column ) {
		global $wpdb;

		$column = sanitize_key( $column );

		return (bool) $wpdb->get_var(
			$wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $column )
		);
	}

	private function get_clubs_table() {
		global $wpdb;
		return $wpdb->prefix . 'ufsc_clubs';
	}

	private function get_aliases_table() {
		global $wpdb;
		return $wpdb->prefix . 'ufsc_asptt_aliases';
	}

	private function get_documents_table() {
		global $wpdb;
		return $wpdb->prefix . 'ufsc_licence_documents';
	}

	private function get_documents_meta_table() {
		global $wpdb;
		return $wpdb->prefix . 'ufsc_licence_documents_meta';
	}

	private function get_import_logs_table() {
		global $wpdb;
		return $wpdb->prefix . 'ufsc_asptt_import_logs';
	}

	private function get_licences_table() {
		global $wpdb;
		return $wpdb->prefix . 'ufsc_licences';
	}
}
