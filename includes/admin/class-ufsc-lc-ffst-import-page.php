<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class UFSC_LC_FFST_Import_Page {
	const PAGE_SLUG='ufsc-lc-import-ffst';

	public function register(){
		add_action('admin_menu',array($this,'register_menu'),35);
		add_action('admin_post_ufsc_lc_ffst_stage',array($this,'handle_stage'));
		add_action('admin_post_ufsc_lc_ffst_apply',array($this,'handle_apply'));
		add_action('admin_post_ufsc_lc_ffst_reanalyse',array($this,'handle_reanalyse'));
	}

	public function register_menu(){
		$hook=add_submenu_page(
			UFSC_LC_Plugin::PARENT_SLUG,
			__('Import FFST','ufsc-licence-competition'),
			__('Import FFST','ufsc-licence-competition'),
			UFSC_LC_Capabilities::get_import_capability(),
			self::PAGE_SLUG,
			array($this,'render')
		);
		if($hook) UFSC_LC_Admin_Assets::register_page($hook);
	}

	public function handle_stage(){
		if(!UFSC_LC_Capabilities::user_can_import()) wp_die(esc_html__('Accès refusé.','ufsc-licence-competition'),'',array('response'=>403));
		check_admin_referer('ufsc_lc_ffst_stage','ufsc_lc_ffst_nonce');
		$file=isset($_FILES['ffst_file'])?$_FILES['ffst_file']:array();
		$season=isset($_POST['season_end_year'])?absint($_POST['season_end_year']):$this->active_season_end_year();
		$service=new UFSC_LC_FFST_Import_Service();
		$result=$service->stage_upload($file,$season);
		if(is_wp_error($result)) $this->redirect_notice('error',$result->get_error_message());
		wp_safe_redirect(add_query_arg(array('page'=>self::PAGE_SLUG,'batch_id'=>absint($result),'ufsc_lc_notice'=>'preview'),admin_url('admin.php')));
		exit;
	}

	/**
	 * Existing batches can be reassessed without reuploading the PDF.
	 * This endpoint only changes staging match status, never master licences.
	 */
	public function handle_reanalyse(){
		if(!UFSC_LC_Capabilities::user_can_import())wp_die(esc_html__('Accès refusé.','ufsc-licence-competition'),'',array('response'=>403));
		$batch_id=isset($_POST['batch_id'])?absint($_POST['batch_id']):0;
		check_admin_referer('ufsc_lc_ffst_reanalyse_'.$batch_id,'ufsc_lc_ffst_reanalyse_nonce');
		$service=new UFSC_LC_FFST_Import_Service();
        $cursor=isset($_POST['after_id'])?absint($_POST['after_id']):0;
        $previous_total=isset($_POST['matched_total'])?min(1000000,absint($_POST['matched_total'])):0;
        $result=$service->reanalyse_not_found_chunk($batch_id,$cursor);
        if(is_wp_error($result))$this->redirect_notice('error',$result->get_error_message(),$batch_id);
        $args=array(
            'page'=>self::PAGE_SLUG,'batch_id'=>$batch_id,
            'ffst_reanalyse_matched'=>min(1000000,$previous_total+absint($result['matched'])),
            'ffst_reanalyse_processed'=>absint($result['processed']),
            'ffst_reanalyse_more'=>!empty($result['has_more'])?1:0,
            'ffst_reanalyse_cursor'=>absint($result['next_cursor']),
        );
        wp_safe_redirect(add_query_arg($args,admin_url('admin.php')));
        exit;
	}

	public function handle_apply(){
		if(!UFSC_LC_Capabilities::user_can_import()) wp_die(esc_html__('Accès refusé.','ufsc-licence-competition'),'',array('response'=>403));
		$batch_id=isset($_POST['batch_id'])?absint($_POST['batch_id']):0;
		check_admin_referer('ufsc_lc_ffst_apply_'.$batch_id,'ufsc_lc_ffst_apply_nonce');
		$service=new UFSC_LC_FFST_Import_Service();
		$ids=isset($_POST['selected_row_ids'])?(array)wp_unslash($_POST['selected_row_ids']):array();
		$result=$service->apply_batch($batch_id,$ids);
		if(is_wp_error($result)) $this->redirect_notice('error',$result->get_error_message(),$batch_id);
        // Rapprochement FFST = numéro inscrit, PAS encore statut UFSC validé.
        // Passage immédiat à l'écran de confirmation pour les administrateurs.
        if ( (int) $result > 0 && class_exists( 'UFSC_FFST_Batch_Validation', false ) && current_user_can( 'manage_options' ) ) {
            wp_safe_redirect( add_query_arg( array(
                'page' => 'ufsc-ffst-validation',
                'batch_id' => $batch_id,
                'ufsc_ffst_synced' => (int) $result,
            ), admin_url( 'admin.php' ) ) );
            exit;
        }
		$this->redirect_notice('success',sprintf(_n('%d licence FFST synchronisée.','%d licences FFST synchronisées.',(int)$result,'ufsc-licence-competition'),(int)$result),$batch_id);
	}

	public function render(){
		if(!UFSC_LC_Capabilities::user_can_import()) wp_die(esc_html__('Accès refusé.','ufsc-licence-competition'));
		$service=new UFSC_LC_FFST_Import_Service();
		$service->create_tables();
		$batch_id=isset($_GET['batch_id'])?absint($_GET['batch_id']):0;
		$batch=$batch_id?$service->get_batch($batch_id):null;
		$per_page=100;
		$current_page=isset($_GET['ffst_page'])?max(1,absint($_GET['ffst_page'])):1;
		$offset=($current_page-1)*$per_page;
		$allowed_filters = array('matched','applied','not_found','ambiguous','conflict');
        $status_filter = isset($_GET['ffst_status']) ? sanitize_key(wp_unslash($_GET['ffst_status'])) : '';
        if(!in_array($status_filter,$allowed_filters,true))$status_filter='';
        $lot_search = isset($_GET['ffst_search']) ? sanitize_text_field(wp_unslash($_GET['ffst_search'])) : '';
        $lot_search = function_exists('mb_substr') ? mb_substr(trim($lot_search),0,100) : substr(trim($lot_search),0,100);
        $club_summary=$batch?$service->get_batch_club_summary($batch_id,250):array();
        $club_filter=isset($_GET['ffst_club'])?sanitize_text_field(wp_unslash($_GET['ffst_club'])):'';
        // The club must exist in this exact import batch; never trust a
        // forged query parameter or a club name from a different batch.
        if(''!==$club_filter && !$service->batch_club_in_summary($club_summary,$club_filter))$club_filter='';
        $actual_rows=$batch?$service->get_batch_row_count($batch_id):0;
        $display_rows=$batch?$service->count_batch_rows_filtered($batch_id,$status_filter,$lot_search,$club_filter):0;
        $pages=$display_rows?max(1,(int)ceil($display_rows/$per_page)):1;
        if($current_page>$pages)$current_page=$pages;
        $offset=($current_page-1)*$per_page;
        $rows=$batch?$service->get_batch_rows_filtered($batch_id,$status_filter,$lot_search,$per_page,$offset,$club_filter):array();
		$counts_consistent=$batch?((int)$batch->total_rows===(int)$actual_rows):true;
		$matched_count=0;$unknown_count=0;
		if($batch){
			$matched_count=$service->get_batch_status_count($batch_id,UFSC_LC_FFST_Import_Service::MATCHED);
			$unknown_count=$service->get_batch_unknown_status_count($batch_id);
		}
		$season=$this->active_season_end_year();
		?>
		<div class="wrap ufsc-lc-admin">
			<h1><?php esc_html_e('Import FFST — prévisualisation sécurisée','ufsc-licence-competition'); ?></h1>
			<?php ufsc_lc_render_scope_badge(); ?>
			<?php $this->render_notice(); ?>
			<div class="notice notice-info inline"><p><strong><?php esc_html_e('Flux sécurisé :','ufsc-licence-competition'); ?></strong>
			<?php esc_html_e('fichier → analyse → rapprochement avec UFSC Gestion → contrôle → validation. Aucune licence UFSC n’est créée automatiquement et aucun N° FFST existant différent n’est écrasé.','ufsc-licence-competition'); ?></p></div>
            <?php if ( class_exists( 'UFSC_FFST_Batch_Validation', false ) && current_user_can( 'manage_options' ) ) : ?>
                <p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=ufsc-ffst-validation' ) ); ?>"><?php esc_html_e( 'Voir les lots prêts à valider dans UFSC Gestion', 'ufsc-licence-competition' ); ?></a></p>
            <?php endif; ?>
            <?php if ( $batch && (int) $batch->applied_rows > 0 ) : ?>
                <div class="notice notice-<?php echo class_exists( 'UFSC_FFST_Batch_Validation', false ) && current_user_can( 'manage_options' ) ? 'success' : 'warning'; ?> inline" style="padding:12px 16px">
                    <p><strong><?php echo esc_html( sprintf( _n( '%d numéro FFST rapproché.', '%d numéros FFST rapprochés.', (int) $batch->applied_rows, 'ufsc-licence-competition' ), (int) $batch->applied_rows ) ); ?></strong>
                    <?php esc_html_e( 'Le statut des licences UFSC reste inchangé tant que la validation groupée n’est pas confirmée.', 'ufsc-licence-competition' ); ?></p>
                    <?php if ( class_exists( 'UFSC_FFST_Batch_Validation', false ) && current_user_can( 'manage_options' ) ) : ?>
                        <p><a class="button button-primary button-hero" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ufsc-ffst-validation', 'batch_id' => absint( $batch->id ) ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Étape suivante : passer les statuts UFSC à « Validée »', 'ufsc-licence-competition' ); ?></a></p>
                    <?php elseif ( ! class_exists( 'UFSC_FFST_Batch_Validation', false ) ) : ?>
                        <p><?php esc_html_e( 'Le centre de validation UFSC Gestion n’est pas chargé sur ce site. Vérifiez la version installée et l’activation du plugin UFSC Gestion.', 'ufsc-licence-competition' ); ?></p>
                    <?php else : ?>
                        <p><?php esc_html_e( 'Seul un administrateur WordPress peut confirmer le passage du statut UFSC à « Validée ». Contactez votre administrateur.', 'ufsc-licence-competition' ); ?></p>
                    <?php endif; ?>
                </div>
            <?php elseif ( $batch && (int) $matched_count > 0 ) : ?>
                <div class="notice notice-info inline"><p><?php esc_html_e( 'Prochaine étape : sélectionnez les licences trouvées, puis cliquez sur « Rapprocher les lignes sélectionnées ». Vous pourrez ensuite valider leur statut UFSC.', 'ufsc-licence-competition' ); ?></p></div>
            <?php endif; ?>

			<div class="card" style="max-width:1100px;padding:20px;margin-top:18px">
				<h2><?php esc_html_e('1. Charger le fichier fourni par la FFST','ufsc-licence-competition'); ?></h2>
				<p><?php esc_html_e('Formats acceptés : CSV, XLSX ou PDF texte. Pour un PDF scanné/non structuré, utilisez de préférence le tableur FFST.','ufsc-licence-competition'); ?></p>
				<form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
					<input type="hidden" name="action" value="ufsc_lc_ffst_stage">
					<?php wp_nonce_field('ufsc_lc_ffst_stage','ufsc_lc_ffst_nonce'); ?>
					<table class="form-table"><tbody>
						<tr><th><label for="ffst_file"><?php esc_html_e('Fichier FFST','ufsc-licence-competition'); ?></label></th>
						<td><input type="file" id="ffst_file" name="ffst_file" accept=".csv,.xlsx,.pdf" required></td></tr>
						<tr><th><label for="season_end_year"><?php esc_html_e('Saison','ufsc-licence-competition'); ?></label></th>
						<td><input type="number" id="season_end_year" name="season_end_year" min="2020" max="2100" value="<?php echo esc_attr($season); ?>">
						<p class="description"><?php echo esc_html(sprintf(__('Saison active : %d–%d (01/08 → 31/07).','ufsc-licence-competition'),$season-1,$season)); ?></p></td></tr>
					</tbody></table>
					<?php submit_button(__('Analyser sans modifier les licences','ufsc-licence-competition'),'primary','submit',false); ?>
				</form>
			</div>

			<?php if($batch): ?>
				<div class="card" style="max-width:1400px;padding:20px;margin-top:18px">
					<h2><?php echo esc_html(sprintf(__('2. Contrôle du lot #%d — %s','ufsc-licence-competition'),$batch->id,$batch->file_name)); ?></h2>
                    <p class="ufsc-ffst-step-description">Vérifiez les correspondances, résolvez les introuvables, puis rapprochez les licences et validez-les dans UFSC Gestion.</p>
                    <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" class="ufsc-ffst-global-search" role="search">
                        <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>">
                        <input type="hidden" name="batch_id" value="<?php echo esc_attr($batch_id); ?>">
                        <input type="hidden" name="ffst_status" value="<?php echo esc_attr($status_filter); ?>">
                        <label for="ffst-source-club"><strong>Club FFST du lot</strong></label>
                        <select name="ffst_club" id="ffst-source-club">
                            <option value="">Tous les clubs du lot</option>
                            <?php foreach($club_summary as $club): ?>
                                <option value="<?php echo esc_attr($club->club_name); ?>" <?php selected($club_filter,(string)$club->club_name); ?>><?php echo esc_html($club->club_name); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <label for="ffst-global-search"><strong>Rechercher dans tout le lot</strong></label>
                        <input id="ffst-global-search" type="search" name="ffst_search" value="<?php echo esc_attr($lot_search); ?>" maxlength="100" placeholder="Nom, prénom, N° FFST, club, naissance…" aria-describedby="ffst-global-search-help">
                        <button type="submit" class="button button-primary">Rechercher</button>
                        <?php if(''!==$lot_search || ''!==$club_filter): ?>
                            <a class="button" href="<?php echo esc_url(add_query_arg(array('page'=>self::PAGE_SLUG,'batch_id'=>$batch_id,'ffst_status'=>$status_filter,'ffst_page'=>1),admin_url('admin.php'))); ?>">Effacer les filtres</a>
                        <?php endif; ?>
                        <span id="ffst-global-search-help" class="description">Recherche sur toutes les pages, avec le filtre d’état sélectionné.</span>
                    </form>
                    <?php if(''!==$lot_search || ''!==$club_filter): ?>
                        <div class="ufsc-ffst-search-summary" role="status">
                            <?php echo esc_html(sprintf('%d ligne(s) trouvée(s) dans ce lot.',$display_rows)); ?>
                            <?php if(''!==$club_filter): ?>Club FFST : <strong><?php echo esc_html($club_filter); ?></strong>.<?php endif; ?>
                            <?php if(''!==$lot_search): ?>Recherche : <strong><?php echo esc_html($lot_search); ?></strong>.<?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <nav class="ufsc-ffst-metrics" aria-label="Filtrer les lignes du lot">
                        <?php
                        $metric_items = array(
                            '' => array( 'Toutes', (int) $batch->total_rows ),
                            'matched' => array( 'Trouvées', (int) $matched_count ),
                            'not_found' => array( 'Introuvables', (int) $batch->not_found_rows ),
                            'ambiguous' => array( 'Ambiguës', (int) $batch->ambiguous_rows ),
                            'conflict' => array( 'Conflits', (int) $batch->conflict_rows ),
                            'applied' => array( 'Appliquées', (int) $batch->applied_rows ),
                        );
                        foreach( $metric_items as $key => $metric ) :
                            $url = add_query_arg(array('page'=>self::PAGE_SLUG,'batch_id'=>$batch->id,'ffst_status'=>$key,'ffst_search'=>$lot_search,'ffst_club'=>$club_filter,'ffst_page'=>1),admin_url('admin.php'));
                        ?>
                            <a class="ufsc-ffst-metric <?php echo $status_filter===$key?'is-active':''; ?>" href="<?php echo esc_url($url); ?>" <?php echo $status_filter===$key?'aria-current="true"':''; ?>>
                                <strong><?php echo esc_html($metric[1]); ?></strong>
                                <span><?php echo esc_html($metric[0]); ?></span>
                            </a>
                        <?php endforeach; ?>
                    </nav>
                    <?php if($club_summary): ?>
                        <details class="ufsc-ffst-club-workqueue">
                            <summary><strong>Voir les clubs du lot et leurs dossiers à traiter</strong> <span class="description">(<?php echo esc_html(count($club_summary)); ?> club(s) affiché(s))</span></summary>
                            <div class="ufsc-ffst-workqueue-scroll"><table class="widefat striped">
                                <thead><tr><th>Club FFST</th><th>Total</th><th>Introuvables</th><th>Ambiguës</th><th>Conflits</th><th>À rapprocher</th><th>Appliquées</th><th>Action</th></tr></thead>
                                <tbody>
                                <?php foreach($club_summary as $club): ?>
                                    <?php $pending=(int)$club->not_found_rows+(int)$club->ambiguous_rows+(int)$club->conflict_rows; ?>
                                    <tr>
                                        <td><strong><?php echo esc_html($club->club_name); ?></strong></td>
                                        <td><?php echo esc_html((int)$club->total_rows); ?></td>
                                        <td><?php echo esc_html((int)$club->not_found_rows); ?></td>
                                        <td><?php echo esc_html((int)$club->ambiguous_rows); ?></td>
                                        <td><?php echo esc_html((int)$club->conflict_rows); ?></td>
                                        <td><?php echo esc_html((int)$club->matched_rows); ?></td>
                                        <td><?php echo esc_html((int)$club->applied_rows); ?></td>
                                        <td><a class="button button-small" href="<?php echo esc_url(add_query_arg(array('page'=>self::PAGE_SLUG,'batch_id'=>$batch_id,'ffst_club'=>(string)$club->club_name,'ffst_status'=>'','ffst_page'=>1),admin_url('admin.php'))); ?>">Ouvrir ce club</a></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table></div>
                            <p class="description">Les compteurs sont en lecture seule. Aucun rapprochement ni changement de statut n’est effectué par ce tableau. Maximum 250 clubs affichés par lot.</p>
                        </details>
                    <?php endif; ?>
                    <?php if(class_exists('UFSC_LC_FFST_Resolution_Admin',false)): ?>
                        <?php UFSC_LC_FFST_Resolution_Admin::render_tools($batch,$rows); ?>
                    <?php endif; ?>
                    <?php if(isset($_GET['ffst_reanalyse_processed'])): ?>
                        <div class="notice notice-info inline" role="status">
                            <p><strong><?php echo esc_html(absint($_GET['ffst_reanalyse_matched']??0)); ?> correspondance(s) retrouvée(s) depuis le début de cette réanalyse.</strong>
                            <?php echo esc_html(absint($_GET['ffst_reanalyse_processed'])); ?> ligne(s) examinée(s) dans la dernière étape.
                            <?php if(!empty($_GET['ffst_reanalyse_more'])): ?>
                                Il reste des lignes à parcourir. Utilisez « Continuer la réanalyse » ; les licences déjà rapprochées ne seront pas retraitées.
                            <?php else: ?>
                                Parcours terminé. Les introuvables restants nécessitent un autre club, une correction des données ou un rapprochement manuel.
                            <?php endif; ?>
                            </p>
                        </div>
                    <?php endif; ?>
                    <?php if($counts_consistent && (int)$batch->not_found_rows>0): ?>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:12px 0">
                            <input type="hidden" name="action" value="ufsc_lc_ffst_reanalyse">
                            <input type="hidden" name="batch_id" value="<?php echo esc_attr(absint($batch->id)); ?>">
                            <input type="hidden" name="after_id" value="<?php echo esc_attr(!empty($_GET['ffst_reanalyse_more'])?absint($_GET['ffst_reanalyse_cursor']??0):0); ?>">
                            <input type="hidden" name="matched_total" value="<?php echo esc_attr(!empty($_GET['ffst_reanalyse_more'])?absint($_GET['ffst_reanalyse_matched']??0):0); ?>">
                            <?php wp_nonce_field('ufsc_lc_ffst_reanalyse_'.$batch->id,'ufsc_lc_ffst_reanalyse_nonce'); ?>
                            <?php $resume_reanalyse=!empty($_GET['ffst_reanalyse_more']) && absint($_GET['ffst_reanalyse_cursor']??0)>0; ?>
                            <?php submit_button($resume_reanalyse?__('Continuer la réanalyse (100 lignes suivantes)','ufsc-licence-competition'):__('Relancer les introuvables (100 lignes par étape)','ufsc-licence-competition'),'secondary','submit',false,array('onclick'=>"return confirm('Vérifier les lignes introuvables de cette étape, sans modifier les fiches UFSC ?');")); ?>
                            <?php if($resume_reanalyse): ?>
                                <a class="button" href="<?php echo esc_url(add_query_arg(array('page'=>self::PAGE_SLUG,'batch_id'=>$batch_id,'ffst_status'=>'not_found','ffst_page'=>1),admin_url('admin.php'))); ?>">Recommencer depuis le début</a>
                            <?php endif; ?>
                            <p class="description"><?php esc_html_e('Vérifie les variantes de traits d’union et d’espaces dans les prénoms. L’identité complète, la date de naissance et la saison restent vérifiées ; aucune licence n’est créée ou validée automatiquement.','ufsc-licence-competition'); ?></p>
                        </form>
                    <?php endif; ?>
					<style>
						.ufsc-lc-ffst-import-grid{min-width:1150px;font-size:12px}
						.ufsc-lc-ffst-import-record{display:grid!important;grid-template-columns:55px 45px 105px 135px 110px 100px minmax(150px,1.4fr) 110px 110px minmax(180px,1.4fr);align-items:start;visibility:visible!important;opacity:1!important;height:auto!important;min-height:42px;position:relative;box-sizing:border-box;border-bottom:1px solid #dcdcde;background:#fff}
						.ufsc-lc-ffst-import-record:nth-of-type(even){background:#f6f7f7}
						.ufsc-lc-ffst-import-record>div{padding:11px 9px;word-break:break-word}
						.ufsc-lc-ffst-import-header{font-weight:700;background:#f0f0f1!important;border-bottom:2px solid #c3c4c7}
						.ufsc-lc-ffst-import-record:first-of-type{display:grid!important;visibility:visible!important}
					</style>
					<?php if(!$counts_consistent): ?>
						<div class="notice notice-error inline"><p><?php echo esc_html(sprintf(__('Attention : %1$d licences annoncées, mais %2$d lignes enregistrées. Validation désactivée jusqu’à résolution.','ufsc-licence-competition'),(int)$batch->total_rows,(int)$actual_rows)); ?></p></div>
					<?php endif; ?>
					<p class="description">
                        <?php echo esc_html(sprintf(__('Lignes stockées : %1$d — résultat filtré : %2$d — affichage %3$d à %4$d (page %5$d sur %6$d).','ufsc-licence-competition'),(int)$actual_rows,(int)$display_rows,$display_rows?$offset+1:0,min($display_rows,$offset+count($rows)),$current_page,$pages)); ?>
                    </p>
                    <?php if(''!==$lot_search && 0===$display_rows): ?>
                        <div class="notice notice-info inline"><p>Aucune licence ne correspond à votre recherche. Effacez le terme ou changez le filtre d’état. Les lignes du lot sont conservées.</p></div>
                    <?php endif; ?>
                    <div class="ufsc-ffst-toolbar">
                        <label for="ffst-live-search">Affiner les résultats de cette page</label>
                        <input type="search" id="ffst-live-search" placeholder="Nom, prénom, N° FFST, club…">
                        <label><input type="checkbox" id="ffst-select-all"> Sélectionner toutes les lignes trouvées de cette page</label>
                        <span id="ffst-live-count" aria-live="polite"></span>
                        <strong id="ffst-selected-count" aria-live="polite">0 licence sélectionnée</strong>
                    </div>
					<?php if($unknown_count): ?>
						<div class="notice notice-warning inline"><p><?php echo esc_html(sprintf(__('%d ancienne(s) ligne(s) possèdent un statut invalide (0). Elles ne peuvent pas être appliquées : réimportez le PDF pour créer un nouveau lot fiable. Aucune ancienne donnée n’est modifiée.','ufsc-licence-competition'),$unknown_count)); ?></p></div>
					<?php endif; ?>
					<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="ufsc-ffst-apply-form">
						<input type="hidden" name="action" value="ufsc_lc_ffst_apply">
						<input type="hidden" name="batch_id" value="<?php echo esc_attr($batch->id); ?>">
						<?php wp_nonce_field('ufsc_lc_ffst_apply_'.$batch->id,'ufsc_lc_ffst_apply_nonce'); ?>
						<p class="description"><?php esc_html_e('Sélectionnez uniquement les rapprochements vérifiés. Les doublons, conflits et dossiers ambigus sont bloqués. La sélection concerne cette page.','ufsc-licence-competition'); ?></p>
					<div style="overflow-x:auto;max-height:600px;overflow-y:auto">
						<div class="ufsc-lc-ffst-import-grid" role="table" aria-label="<?php esc_attr_e('Prévisualisation des licences FFST','ufsc-licence-competition'); ?>">
							<div class="ufsc-lc-ffst-import-record ufsc-lc-ffst-import-header" role="row">
								<div role="columnheader"><?php esc_html_e('Choix','ufsc-licence-competition'); ?></div><div role="columnheader">#</div><div role="columnheader"><?php esc_html_e('N° FFST','ufsc-licence-competition'); ?></div>
								<div role="columnheader"><?php esc_html_e('Nom','ufsc-licence-competition'); ?></div>
								<div role="columnheader"><?php esc_html_e('Prénom','ufsc-licence-competition'); ?></div>
								<div role="columnheader"><?php esc_html_e('Naissance','ufsc-licence-competition'); ?></div>
								<div role="columnheader"><?php esc_html_e('Club','ufsc-licence-competition'); ?></div>
								<div role="columnheader"><?php esc_html_e('Licence UFSC','ufsc-licence-competition'); ?></div>
								<div role="columnheader"><?php esc_html_e('État','ufsc-licence-competition'); ?></div>
								<div role="columnheader"><?php esc_html_e('Contrôle','ufsc-licence-competition'); ?></div>
							</div>
							<?php foreach($rows as $row): ?>
								<div class="ufsc-lc-ffst-import-record" role="row" data-ffst-row="<?php echo esc_attr(absint($row->row_index)); ?>" data-state="<?php echo esc_attr($row->match_status); ?>">
									<div role="cell"><?php if('matched'===$row->match_status && $counts_consistent): ?><label><input type="checkbox" name="selected_row_ids[]" value="<?php echo esc_attr(absint($row->id)); ?>" aria-label="<?php echo esc_attr(sprintf(__('Sélectionner la ligne %d','ufsc-licence-competition'),absint($row->row_index))); ?>"></label><?php else: ?>—<?php endif; ?></div>
									<div role="cell"><?php echo esc_html($row->row_index); ?></div>
									<div role="cell"><strong><?php echo esc_html($row->ffst_number?:'—'); ?></strong></div>
									<div role="cell"><?php echo esc_html($row->last_name?:'—'); ?></div>
									<div role="cell"><?php echo esc_html($row->first_name?:'—'); ?></div>
									<div role="cell"><?php echo esc_html($row->birthdate?:'—'); ?></div>
									<div role="cell"><?php echo esc_html($row->club_name?:'—'); ?></div>
									<div role="cell"><?php echo $row->match_licence_id?esc_html('#'.(int)$row->match_licence_id):'—'; ?></div>
									<div role="cell"><?php echo $this->status_badge($row->match_status); ?></div>
									<div role="cell"><?php echo esc_html($row->match_message); ?>
                                        <?php if(class_exists('UFSC_LC_FFST_Resolution_Admin',false)) UFSC_LC_FFST_Resolution_Admin::render_row_link($batch,$row); ?>
                                    </div>
								</div>
							<?php endforeach; ?>
						</div>
					</div>
					<?php if($pages>1): ?>
						<nav aria-label="<?php esc_attr_e('Pages des licences du lot','ufsc-licence-competition'); ?>" style="margin-top:14px">
							<?php for($page_number=1;$page_number<=$pages;$page_number++): ?>
								<a class="button <?php echo $page_number===$current_page?'button-primary':''; ?>" href="<?php echo esc_url(add_query_arg(array('page'=>self::PAGE_SLUG,'batch_id'=>$batch_id,'ffst_status'=>$status_filter,'ffst_search'=>$lot_search,'ffst_club'=>$club_filter,'ffst_page'=>$page_number),admin_url('admin.php'))); ?>"><?php echo esc_html($page_number); ?></a>
							<?php endfor; ?>
						</nav>
					<?php endif; ?>
					<?php if($counts_consistent && $matched_count>0): ?>
						<div style="margin-top:18px">
							<?php submit_button(__('Rapprocher les lignes sélectionnées','ufsc-licence-competition'),'primary','submit',false,array('onclick'=>"return confirm('Confirmer le rapprochement des licences sélectionnées avec UFSC Gestion ?');")); ?>
							<p class="description"><?php esc_html_e('Le N° FFST est conservé en texte. Les N° UFSC existants ne sont jamais remplacés. Les lignes non sélectionnées restent en attente.','ufsc-licence-competition'); ?></p>
						</div>
					<?php elseif('applied'===$batch->status): ?>
						<div class="notice notice-success inline"><p><?php echo esc_html(sprintf(__('%d ligne(s) déjà appliquée(s).','ufsc-licence-competition'),$batch->applied_rows)); ?></p></div>
					<?php endif; ?>
					</form>
                    <?php if(class_exists('UFSC_LC_FFST_Resolution_Admin',false)) UFSC_LC_FFST_Resolution_Admin::render_panel($batch); ?>
                    <script>
                    (function(){
                        var search=document.getElementById('ffst-live-search');
                        var selectAll=document.getElementById('ffst-select-all');
                        var counter=document.getElementById('ffst-live-count');
                        var selectedCounter=document.getElementById('ffst-selected-count');
                        var rows=Array.prototype.slice.call(document.querySelectorAll('.ufsc-lc-ffst-import-record[data-ffst-row]'));
                        function refresh(){
                            var q=(search.value||'').trim().toLocaleLowerCase();
                            var count=0;
                            rows.forEach(function(row){
                                var show=!q||row.textContent.toLocaleLowerCase().indexOf(q)!==-1;
                                row.hidden=!show;
                                // Hidden rows must never remain selected for the
                                // Apply action: it only acts on visible choices.
                                if(!show){
                                    var hiddenCheck=row.querySelector('input[name="selected_row_ids[]"]');
                                    if(hiddenCheck)hiddenCheck.checked=false;
                                }
                                if(show)count++;
                            });
                            counter.textContent=count+' ligne(s) visibles sur cette page';
                            refreshSelection();
                        }
                        function refreshSelection(){
                            var selected=0;
                            rows.forEach(function(row){
                                var checkbox=row.querySelector('input[name="selected_row_ids[]"]');
                                if(checkbox && checkbox.checked) selected++;
                            });
                            if(selectedCounter)selectedCounter.textContent=selected+' licence(s) sélectionnée(s) sur cette page';
                        }
                        if(search)search.addEventListener('input',refresh);
                        rows.forEach(function(row){
                            var checkbox=row.querySelector('input[name="selected_row_ids[]"]');
                            if(checkbox)checkbox.addEventListener('change',refreshSelection);
                        });
                        if(selectAll)selectAll.addEventListener('change',function(){
                            rows.forEach(function(row){
                                if(!row.hidden){
                                    var checkbox=row.querySelector('input[name="selected_row_ids[]"]');
                                    if(checkbox && !checkbox.disabled)checkbox.checked=selectAll.checked;
                                }
                            });
                            refreshSelection();
                        });
                        refresh();
                    })();
                    </script>
				</div>
			<?php endif; ?>

			<div class="card" style="max-width:1100px;padding:20px;margin-top:18px">
				<h2><?php esc_html_e('Historique récent','ufsc-licence-competition'); ?></h2>
				<table class="widefat striped"><thead><tr><th>ID</th><th><?php esc_html_e('Fichier','ufsc-licence-competition'); ?></th><th><?php esc_html_e('Saison','ufsc-licence-competition'); ?></th><th><?php esc_html_e('État','ufsc-licence-competition'); ?></th><th><?php esc_html_e('Date','ufsc-licence-competition'); ?></th></tr></thead><tbody>
				<?php foreach($service->get_recent_batches(10) as $item): ?>
					<tr><td><a href="<?php echo esc_url(add_query_arg(array('page'=>self::PAGE_SLUG,'batch_id'=>$item->id),admin_url('admin.php'))); ?>">#<?php echo esc_html($item->id); ?></a></td>
					<td><?php echo esc_html($item->file_name); ?></td><td><?php echo esc_html(($item->season_end_year-1).'-'.$item->season_end_year); ?></td>
					<td><?php echo esc_html($item->status); ?></td><td><?php echo esc_html($item->created_at); ?></td></tr>
				<?php endforeach; ?></tbody></table>
			</div>
		</div>
		<?php
	}

	private function status_badge($status){
		$map=array('matched'=>array('Trouvée','#e7f6ec','#166534'),'applied'=>array('Appliquée','#dcfce7','#166534'),'ambiguous'=>array('À contrôler','#fff7d6','#92400e'),'conflict'=>array('Conflit','#fee2e2','#991b1b'),'not_found'=>array('Introuvable','#f3f4f6','#4b5563'),'0'=>array('Ancien statut invalide','#fee2e2','#991b1b'));
		$v=isset($map[$status])?$map[$status]:array($status,'#f3f4f6','#374151');
		return '<span style="display:inline-block;padding:4px 8px;border-radius:999px;background:'.esc_attr($v[1]).';color:'.esc_attr($v[2]).';font-weight:700">'.esc_html($v[0]).'</span>';
	}
	private function active_season_end_year(){return function_exists('ufsc_lc_get_active_season_end_year')?absint(ufsc_lc_get_active_season_end_year()):2027;}
	private function render_notice(){
		if(empty($_GET['ufsc_lc_notice'])&&empty($_GET['message']))return;
		$type=isset($_GET['type'])?sanitize_key(wp_unslash($_GET['type'])):'success';$msg=isset($_GET['message'])?sanitize_text_field(wp_unslash($_GET['message'])):__('Analyse terminée : vérifiez les lignes avant validation.','ufsc-licence-competition');
		printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>',esc_attr(in_array($type,array('success','error','warning','info'),true)?$type:'info'),esc_html($msg));
	}
	private function redirect_notice($type,$message,$batch_id=0){
		$args=array('page'=>self::PAGE_SLUG,'type'=>$type,'message'=>$message);if($batch_id)$args['batch_id']=$batch_id;
		wp_safe_redirect(add_query_arg($args,admin_url('admin.php')));exit;
	}
}
