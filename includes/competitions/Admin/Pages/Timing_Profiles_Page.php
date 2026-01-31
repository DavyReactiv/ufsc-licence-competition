<?php

namespace UFSC\Competitions\Admin\Pages;

use UFSC\Competitions\Capabilities;
use UFSC\Competitions\Repositories\TimingProfileRepository;
use UFSC\Competitions\Services\DisciplineRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Timing_Profiles_Page {
	public function register_actions(): void {
		add_action( 'admin_post_ufsc_competitions_save_timing_profile', array( $this, 'handle_save' ) );
		add_action( 'admin_post_ufsc_competitions_delete_timing_profile', array( $this, 'handle_delete' ) );
	}

	public function render(): void {
		if ( ! Capabilities::user_can_manage() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ) );
		}

		$repo = new TimingProfileRepository();
		$profiles = $repo->list();
		$editing_id = isset( $_GET['profile_id'] ) ? absint( $_GET['profile_id'] ) : 0;
		$editing = $editing_id ? $repo->get( $editing_id ) : null;

		$notice = isset( $_GET['ufsc_notice'] ) ? sanitize_key( wp_unslash( $_GET['ufsc_notice'] ) ) : '';
		$messages = array(
			'saved' => __( 'Profil enregistré.', 'ufsc-licence-competition' ),
			'deleted' => __( 'Profil supprimé.', 'ufsc-licence-competition' ),
			'error' => __( 'Action impossible.', 'ufsc-licence-competition' ),
		);

		if ( $notice && isset( $messages[ $notice ] ) ) {
			printf(
				'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
				'error' === $notice ? 'error' : 'success',
				esc_html( $messages[ $notice ] )
			);
		}

		$disciplines = DisciplineRegistry::get_disciplines();
		?>
		<div class="wrap ufsc-competitions-admin">
			<h1><?php esc_html_e( 'Timing Profiles', 'ufsc-licence-competition' ); ?></h1>
			<p><?php esc_html_e( 'Définissez les durées par discipline et tranche d’âge. Les combats utilisent ces profils en mode "Par catégories".', 'ufsc-licence-competition' ); ?></p>

			<h2><?php echo $editing ? esc_html__( 'Modifier un profil', 'ufsc-licence-competition' ) : esc_html__( 'Ajouter un profil', 'ufsc-licence-competition' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'ufsc_competitions_save_timing_profile' ); ?>
				<input type="hidden" name="action" value="ufsc_competitions_save_timing_profile">
				<?php if ( $editing ) : ?>
					<input type="hidden" name="profile_id" value="<?php echo esc_attr( (int) $editing->id ); ?>">
				<?php endif; ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ufsc_timing_name"><?php esc_html_e( 'Nom du profil', 'ufsc-licence-competition' ); ?></label></th>
						<td><input name="name" type="text" id="ufsc_timing_name" class="regular-text" value="<?php echo esc_attr( $editing->name ?? '' ); ?>" required></td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_timing_discipline"><?php esc_html_e( 'Discipline', 'ufsc-licence-competition' ); ?></label></th>
						<td>
							<select name="discipline" id="ufsc_timing_discipline">
								<option value=""><?php esc_html_e( 'Toutes', 'ufsc-licence-competition' ); ?></option>
								<?php foreach ( $disciplines as $key => $label ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( (string) ( $editing->discipline ?? '' ), (string) $key ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_timing_age_min"><?php esc_html_e( 'Âge min', 'ufsc-licence-competition' ); ?></label></th>
						<td><input name="age_min" type="number" min="0" id="ufsc_timing_age_min" value="<?php echo esc_attr( $editing->age_min ?? '' ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_timing_age_max"><?php esc_html_e( 'Âge max', 'ufsc-licence-competition' ); ?></label></th>
						<td><input name="age_max" type="number" min="0" id="ufsc_timing_age_max" value="<?php echo esc_attr( $editing->age_max ?? '' ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_timing_level"><?php esc_html_e( 'Niveau / Classe', 'ufsc-licence-competition' ); ?></label></th>
						<td><input name="level" type="text" id="ufsc_timing_level" class="regular-text" value="<?php echo esc_attr( $editing->level ?? '' ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_timing_format"><?php esc_html_e( 'Format', 'ufsc-licence-competition' ); ?></label></th>
						<td><input name="format" type="text" id="ufsc_timing_format" class="regular-text" value="<?php echo esc_attr( $editing->format ?? '' ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_timing_round_duration"><?php esc_html_e( 'Durée d’un round (min)', 'ufsc-licence-competition' ); ?></label></th>
						<td><input name="round_duration" type="number" min="1" id="ufsc_timing_round_duration" value="<?php echo esc_attr( $editing->round_duration ?? 2 ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_timing_rounds"><?php esc_html_e( 'Nombre de rounds', 'ufsc-licence-competition' ); ?></label></th>
						<td><input name="rounds" type="number" min="1" id="ufsc_timing_rounds" value="<?php echo esc_attr( $editing->rounds ?? 1 ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_timing_break"><?php esc_html_e( 'Pause entre rounds (min)', 'ufsc-licence-competition' ); ?></label></th>
						<td><input name="break_duration" type="number" min="0" id="ufsc_timing_break" value="<?php echo esc_attr( $editing->break_duration ?? 1 ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_timing_pause"><?php esc_html_e( 'Pause entre combats (min)', 'ufsc-licence-competition' ); ?></label></th>
						<td><input name="fight_pause" type="number" min="0" id="ufsc_timing_pause" value="<?php echo esc_attr( $editing->fight_pause ?? 0 ); ?>"></td>
					</tr>
				</table>
				<?php submit_button( $editing ? __( 'Mettre à jour', 'ufsc-licence-competition' ) : __( 'Ajouter', 'ufsc-licence-competition' ) ); ?>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Profils existants', 'ufsc-licence-competition' ); ?></h2>
			<?php if ( empty( $profiles ) ) : ?>
				<p><?php esc_html_e( 'Aucun profil défini.', 'ufsc-licence-competition' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Nom', 'ufsc-licence-competition' ); ?></th>
							<th><?php esc_html_e( 'Discipline', 'ufsc-licence-competition' ); ?></th>
							<th><?php esc_html_e( 'Âge', 'ufsc-licence-competition' ); ?></th>
							<th><?php esc_html_e( 'Niveau', 'ufsc-licence-competition' ); ?></th>
							<th><?php esc_html_e( 'Timing', 'ufsc-licence-competition' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'ufsc-licence-competition' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $profiles as $profile ) : ?>
							<?php
							$discipline_label = $profile->discipline ? ( DisciplineRegistry::get_label( $profile->discipline ) ) : __( 'Toutes', 'ufsc-licence-competition' );
							$age_label = trim( (string) ( $profile->age_min ?? '' ) . ' - ' . (string) ( $profile->age_max ?? '' ) );
							$timing_label = sprintf(
								__( '%1$d x %2$d min, pause %3$d min, inter-combats %4$d min', 'ufsc-licence-competition' ),
								(int) ( $profile->rounds ?? 1 ),
								(int) ( $profile->round_duration ?? 2 ),
								(int) ( $profile->break_duration ?? 0 ),
								(int) ( $profile->fight_pause ?? 0 )
							);
							?>
							<tr>
								<td><?php echo esc_html( $profile->name ?? '' ); ?></td>
								<td><?php echo esc_html( $discipline_label ); ?></td>
								<td><?php echo esc_html( $age_label ); ?></td>
								<td><?php echo esc_html( $profile->level ?? '—' ); ?></td>
								<td><?php echo esc_html( $timing_label ); ?></td>
								<td>
									<a href="<?php echo esc_url( add_query_arg( 'profile_id', (int) $profile->id, admin_url( 'admin.php?page=ufsc-competitions-timing-profiles' ) ) ); ?>">
										<?php esc_html_e( 'Modifier', 'ufsc-licence-competition' ); ?>
									</a>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
										<?php wp_nonce_field( 'ufsc_competitions_delete_timing_profile_' . (int) $profile->id ); ?>
										<input type="hidden" name="action" value="ufsc_competitions_delete_timing_profile">
										<input type="hidden" name="profile_id" value="<?php echo esc_attr( (int) $profile->id ); ?>">
										<button type="submit" class="button-link delete" onclick="return confirm('<?php echo esc_js( __( 'Supprimer ce profil ?', 'ufsc-licence-competition' ) ); ?>');">
											<?php esc_html_e( 'Supprimer', 'ufsc-licence-competition' ); ?>
										</button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	public function handle_save(): void {
		if ( ! Capabilities::user_can_manage() ) {
			$this->redirect( 'error' );
		}

		check_admin_referer( 'ufsc_competitions_save_timing_profile' );

		$profile_id = isset( $_POST['profile_id'] ) ? absint( $_POST['profile_id'] ) : 0;
		$data = array(
			'name' => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
			'discipline' => isset( $_POST['discipline'] ) ? sanitize_key( wp_unslash( $_POST['discipline'] ) ) : '',
			'age_min' => isset( $_POST['age_min'] ) ? absint( $_POST['age_min'] ) : '',
			'age_max' => isset( $_POST['age_max'] ) ? absint( $_POST['age_max'] ) : '',
			'level' => isset( $_POST['level'] ) ? sanitize_text_field( wp_unslash( $_POST['level'] ) ) : '',
			'format' => isset( $_POST['format'] ) ? sanitize_text_field( wp_unslash( $_POST['format'] ) ) : '',
			'round_duration' => isset( $_POST['round_duration'] ) ? absint( $_POST['round_duration'] ) : 0,
			'rounds' => isset( $_POST['rounds'] ) ? absint( $_POST['rounds'] ) : 0,
			'break_duration' => isset( $_POST['break_duration'] ) ? absint( $_POST['break_duration'] ) : 0,
			'fight_pause' => isset( $_POST['fight_pause'] ) ? absint( $_POST['fight_pause'] ) : 0,
		);

		if ( '' === $data['name'] ) {
			$this->redirect( 'error' );
		}

		$repo = new TimingProfileRepository();
		if ( $profile_id ) {
			$repo->update( $profile_id, $data );
		} else {
			$repo->insert( $data );
		}

		$this->redirect( 'saved' );
	}

	public function handle_delete(): void {
		if ( ! Capabilities::user_can_manage() ) {
			$this->redirect( 'error' );
		}

		$profile_id = isset( $_POST['profile_id'] ) ? absint( $_POST['profile_id'] ) : 0;
		if ( ! $profile_id ) {
			$this->redirect( 'error' );
		}

		check_admin_referer( 'ufsc_competitions_delete_timing_profile_' . $profile_id );

		$repo = new TimingProfileRepository();
		$repo->delete( $profile_id );

		$this->redirect( 'deleted' );
	}

	private function redirect( string $notice ): void {
		$url = add_query_arg( 'ufsc_notice', $notice, admin_url( 'admin.php?page=ufsc-competitions-timing-profiles' ) );
		wp_safe_redirect( $url );
		exit;
	}
}
