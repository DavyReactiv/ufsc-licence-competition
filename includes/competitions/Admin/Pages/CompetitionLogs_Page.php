<?php

namespace UFSC\Competitions\Admin\Pages;

use UFSC\Competitions\Admin\Menu;
use UFSC\Competitions\Capabilities;
use UFSC\Competitions\Services\AuditLogger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CompetitionLogs_Page {
	public function register_actions(): void {
		add_action( 'admin_post_ufsc_competitions_purge_logs', array( $this, 'handle_purge' ) );
		add_action( 'admin_post_ufsc_competitions_download_logs_csv', array( $this, 'handle_download_csv' ) );
	}

	public function render(): void {
		if ( ! Capabilities::user_can_manage() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ) );
		}

		$notice = isset( $_GET['ufsc_notice'] ) ? sanitize_key( wp_unslash( $_GET['ufsc_notice'] ) ) : '';
		$this->render_notice( $notice );

		$filters = $this->get_filters_from_request();
		$logs    = AuditLogger::get_logs( $filters );
		$download_url = $this->build_download_url( $filters );

		?>
		<div class="wrap ufsc-competitions-admin">
			<h1><?php esc_html_e( 'Logs', 'ufsc-licence-competition' ); ?></h1>

			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="ufsc-competitions-form" style="margin-bottom: 12px;">
				<input type="hidden" name="page" value="<?php echo esc_attr( Menu::PAGE_LOGS ); ?>">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ufsc_log_competition"><?php esc_html_e( 'Competition ID', 'ufsc-licence-competition' ); ?></label></th>
						<td><input type="number" id="ufsc_log_competition" name="competition_id" value="<?php echo esc_attr( $filters['competition_id'] ); ?>" class="small-text"></td>
						<th scope="row"><label for="ufsc_log_club"><?php esc_html_e( 'Club ID', 'ufsc-licence-competition' ); ?></label></th>
						<td><input type="number" id="ufsc_log_club" name="club_id" value="<?php echo esc_attr( $filters['club_id'] ); ?>" class="small-text"></td>
						<th scope="row"><label for="ufsc_log_action"><?php esc_html_e( 'Action', 'ufsc-licence-competition' ); ?></label></th>
						<td><?php $this->render_action_filter( $filters['action'] ); ?></td>
					</tr>
				</table>
				<?php submit_button( __( 'Filtrer', 'ufsc-licence-competition' ), 'secondary', '', false ); ?>
				<a class="button" href="<?php echo esc_url( $download_url ); ?>"><?php esc_html_e( 'Télécharger CSV', 'ufsc-licence-competition' ); ?></a>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom: 16px;">
				<?php wp_nonce_field( 'ufsc_competitions_purge_logs' ); ?>
				<input type="hidden" name="action" value="ufsc_competitions_purge_logs">
				<?php submit_button( __( 'Purger', 'ufsc-licence-competition' ), 'delete' ); ?>
			</form>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Date', 'ufsc-licence-competition' ); ?></th>
						<th><?php esc_html_e( 'Action', 'ufsc-licence-competition' ); ?></th>
						<th><?php esc_html_e( 'Competition', 'ufsc-licence-competition' ); ?></th>
						<th><?php esc_html_e( 'Club', 'ufsc-licence-competition' ); ?></th>
						<th><?php esc_html_e( 'Entry', 'ufsc-licence-competition' ); ?></th>
						<th><?php esc_html_e( 'User', 'ufsc-licence-competition' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $logs ) ) : ?>
						<tr>
							<td colspan="6"><?php esc_html_e( 'Aucun log.', 'ufsc-licence-competition' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $logs as $row ) : ?>
							<tr>
								<td><?php echo esc_html( function_exists( 'ufsc_lc_format_datetime' ) ? ufsc_lc_format_datetime( $row['timestamp'] ?? '' ) : ( $row['timestamp'] ?? '' ) ); ?></td>
								<td><?php echo esc_html( $row['action'] ?? '' ); ?></td>
								<td><?php echo esc_html( (string) ( $row['competition_id'] ?? '' ) ); ?></td>
								<td><?php echo esc_html( (string) ( $row['club_id'] ?? '' ) ); ?></td>
								<td><?php echo esc_html( (string) ( $row['entry_id'] ?? '' ) ); ?></td>
								<td><?php echo esc_html( (string) ( $row['user_id'] ?? '' ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	public function handle_purge(): void {
		if ( ! Capabilities::user_can_manage() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'ufsc_competitions_purge_logs' );

		AuditLogger::purge_all();

		$this->redirect_with_notice( 'logs_purged' );
	}

	public function handle_download_csv(): void {
		if ( ! Capabilities::user_can_manage() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'ufsc_competitions_download_logs_csv' );

		$filters = $this->get_filters_from_request();
		$logs    = AuditLogger::get_logs( $filters );

		$filename = sanitize_file_name( 'ufsc-competitions-logs.csv' );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		$handle = fopen( 'php://output', 'w' );
		if ( ! $handle ) {
			wp_die( esc_html__( 'Export impossible.', 'ufsc-licence-competition' ) );
		}

		fwrite( $handle, "\xEF\xBB\xBF" );

		$headers = array(
			'date',
			'action',
			'competition_id',
			'club_id',
			'entry_id',
			'user_id',
			'status_from',
			'status_to',
			'ip',
			'user_agent',
			'context',
		);
		fputcsv( $handle, $headers, ';' );

		foreach ( $logs as $row ) {
			$context = isset( $row['context'] ) ? wp_json_encode( $row['context'] ) : '';
			$output = array(
				$row['timestamp'] ?? '',
				$row['action'] ?? '',
				$row['competition_id'] ?? '',
				$row['club_id'] ?? '',
				$row['entry_id'] ?? '',
				$row['user_id'] ?? '',
				$row['status_from'] ?? '',
				$row['status_to'] ?? '',
				$row['ip'] ?? '',
				$row['user_agent'] ?? '',
				$context,
			);
			fputcsv( $handle, $output, ';' );
		}

		fclose( $handle );
		exit;
	}

	private function render_notice( string $notice ): void {
		$messages = array(
			'logs_purged' => __( 'Logs purgés.', 'ufsc-licence-competition' ),
		);

		if ( ! $notice || ! isset( $messages[ $notice ] ) ) {
			return;
		}

		printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $messages[ $notice ] ) );
	}

	private function render_action_filter( string $current ): void {
		$actions = array(
			'' => __( 'Tous', 'ufsc-licence-competition' ),
			'entry_create' => __( 'Create', 'ufsc-licence-competition' ),
			'entry_update' => __( 'Update', 'ufsc-licence-competition' ),
			'entry_delete' => __( 'Delete', 'ufsc-licence-competition' ),
			'entry_submit' => __( 'Submit', 'ufsc-licence-competition' ),
			'entry_withdraw' => __( 'Withdraw', 'ufsc-licence-competition' ),
			'entry_cancel' => __( 'Cancel', 'ufsc-licence-competition' ),
			'entry_validate' => __( 'Validate', 'ufsc-licence-competition' ),
			'entry_reject' => __( 'Reject', 'ufsc-licence-competition' ),
			'entry_reopen' => __( 'Reopen', 'ufsc-licence-competition' ),
			'export_approved_csv' => __( 'Export CSV (approuvé)', 'ufsc-licence-competition' ),
		);

		?>
		<select name="log_action" id="ufsc_log_action">
			<?php foreach ( $actions as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current, $value ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	private function get_filters_from_request(): array {
		$competition_id = isset( $_GET['competition_id'] ) ? absint( $_GET['competition_id'] ) : 0;
		$club_id        = isset( $_GET['club_id'] ) ? absint( $_GET['club_id'] ) : 0;
		$action         = isset( $_GET['log_action'] ) ? sanitize_key( wp_unslash( $_GET['log_action'] ) ) : '';

		return array(
			'competition_id' => $competition_id,
			'club_id'        => $club_id,
			'action'         => $action,
		);
	}

	private function build_download_url( array $filters ): string {
		$url = add_query_arg(
			array(
				'action'         => 'ufsc_competitions_download_logs_csv',
				'competition_id' => $filters['competition_id'],
				'club_id'        => $filters['club_id'],
				'log_action'     => $filters['action'],
			),
			admin_url( 'admin-post.php' )
		);

		return wp_nonce_url( $url, 'ufsc_competitions_download_logs_csv' );
	}

	private function redirect_with_notice( string $notice ): void {
		$url = add_query_arg(
			array(
				'page'        => Menu::PAGE_LOGS,
				'ufsc_notice' => $notice,
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $url );
		exit;
	}
}
