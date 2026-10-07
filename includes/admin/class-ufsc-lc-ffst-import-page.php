<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class UFSC_LC_FFST_Import_Page {
	const PAGE_SLUG='ufsc-lc-import-ffst';

	public function register(){
		add_action('admin_menu',array($this,'register_menu'),35);
		add_action('admin_post_ufsc_lc_ffst_stage',array($this,'handle_stage'));
		add_action('admin_post_ufsc_lc_ffst_apply',array($this,'handle_apply'));
		add_action('admin_post_ufsc_lc_ffst_assign',array($this,'handle_assign'));
		add_action('admin_post_ufsc_lc_ffst_recheck',array($this,'handle_recheck'));
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

	public function handle_apply(){
		if(!UFSC_LC_Capabilities::user_can_import())wp_die(esc_html__('Accès refusé.','ufsc-licence-competition'),'',array('response'=>403));
		$batch_id=isset($_POST['batch_id'])?absint($_POST['batch_id']):0;
		check_admin_referer('ufsc_lc_ffst_apply_'.$batch_id,'ufsc_lc_ffst_apply_nonce');
		$selected=isset($_POST['ffst_selected'])&&is_array($_POST['ffst_selected'])?array_map('absint',wp_unslash($_POST['ffst_selected'])):array();
		$service=new UFSC_LC_FFST_Import_Service();
		$result=$service->apply_selected_rows($batch_id,$selected);
		if(is_wp_error($result))$this->redirect_notice('error',$result->get_error_message(),$batch_id);
		$this->redirect_notice('success',sprintf(_n('%d licence FFST synchronisée.','%d licences FFST synchronisées.',(int)$result,'ufsc-licence-competition'),(int)$result),$batch_id);
	}

	public function handle_assign(){
		if(!UFSC_LC_Capabilities::user_can_import())wp_die(esc_html__('Accès refusé.','ufsc-licence-competition'),'',array('response'=>403));
		$batch_id=isset($_POST['batch_id'])?absint($_POST['batch_id']):0;
		$row_id=isset($_POST['row_id'])?absint($_POST['row_id']):0;
		$licence_id=isset($_POST['licence_id'])?absint($_POST['licence_id']):0;
		check_admin_referer('ufsc_lc_ffst_assign_'.$batch_id.'_'.$row_id,'ufsc_lc_ffst_assign_nonce');
		$service=new UFSC_LC_FFST_Import_Service();
		$result=$service->assign_review_candidate($batch_id,$row_id,$licence_id);
		if(is_wp_error($result))$this->redirect_notice('error',$result->get_error_message(),$batch_id);
		$this->redirect_notice('success',__('Rapprochement contrôlé enregistré. Sélectionnez ensuite la ligne pour confirmer sa synchronisation.','ufsc-licence-competition'),$batch_id);
	}

	public function handle_recheck(){
		if(!UFSC_LC_Capabilities::user_can_import())wp_die(esc_html__('Accès refusé.','ufsc-licence-competition'),'',array('response'=>403));
		$batch_id=isset($_POST['batch_id'])?absint($_POST['batch_id']):0;
		check_admin_referer('ufsc_lc_ffst_recheck_'.$batch_id,'ufsc_lc_ffst_recheck_nonce');
		$service=new UFSC_LC_FFST_Import_Service();
		$result=$service->recheck_legacy_batch($batch_id);
		if(is_wp_error($result))$this->redirect_notice('error',$result->get_error_message(),$batch_id);
		$this->redirect_notice('success',sprintf(__('%d ligne(s) anciennes réexaminée(s), sans écriture dans UFSC Gestion.','ufsc-licence-competition'),(int)$result),$batch_id);
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
		$rows=$batch?$service->get_batch_rows($batch_id,$per_page,$offset):array();
		$actual_rows=$batch?$service->get_batch_row_count($batch_id):0;
		$pages=$actual_rows?max(1,(int)ceil($actual_rows/$per_page)):1;
		if($current_page>$pages){
			$current_page=$pages;
			$offset=($current_page-1)*$per_page;
			$rows=$batch?$service->get_batch_rows($batch_id,$per_page,$offset):array();
		}
		$counts_consistent=$batch?((int)$batch->total_rows===(int)$actual_rows):true;
		$duplicates=$batch?$service->audit_batch($batch_id):array();
		$legacy_count=$batch?$service->get_legacy_status_count($batch_id):0;
		$season=$this->active_season_end_year();
		?>
		<div class="wrap ufsc-lc-admin">
			<h1><?php esc_html_e('Import FFST — prévisualisation sécurisée','ufsc-licence-competition'); ?></h1>
			<?php ufsc_lc_render_scope_badge(); ?>
			<?php $this->render_notice(); ?>
			<div class="notice notice-info inline"><p><strong><?php esc_html_e('Flux sécurisé :','ufsc-licence-competition'); ?></strong>
			<?php esc_html_e('fichier → analyse → rapprochement avec UFSC Gestion → contrôle → validation. Aucune licence UFSC n’est créée automatiquement et aucun N° FFST existant différent n’est écrasé.','ufsc-licence-competition'); ?></p></div>

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
					<p>
						<span class="button disabled"><?php echo esc_html(sprintf(__('Total : %d','ufsc-licence-competition'),$batch->total_rows)); ?></span>
						<span class="button disabled"><?php echo esc_html(sprintf(__('Trouvées : %d','ufsc-licence-competition'),$batch->matched_rows)); ?></span>
						<span class="button disabled"><?php echo esc_html(sprintf(__('Ambiguës : %d','ufsc-licence-competition'),$batch->ambiguous_rows)); ?></span>
						<span class="button disabled"><?php echo esc_html(sprintf(__('Conflits : %d','ufsc-licence-competition'),$batch->conflict_rows)); ?></span>
						<span class="button disabled"><?php echo esc_html(sprintf(__('Introuvables : %d','ufsc-licence-competition'),$batch->not_found_rows)); ?></span>
					</p>
					<style>
						.ufsc-lc-ffst-import-grid{min-width:1330px;font-size:12px}
						.ufsc-lc-ffst-import-record{display:grid!important;grid-template-columns:36px 40px 105px 130px 95px 100px minmax(145px,1.3fr) 110px 110px minmax(170px,1.3fr) 200px;align-items:start;visibility:visible!important;opacity:1!important;height:auto!important;min-height:42px;position:relative;box-sizing:border-box;border-bottom:1px solid #dcdcde;background:#fff}
						.ufsc-lc-ffst-import-record:nth-of-type(even){background:#f6f7f7}
						.ufsc-lc-ffst-import-record>div{padding:11px 9px;word-break:break-word}
						.ufsc-lc-ffst-import-header{font-weight:700;background:#f0f0f1!important;border-bottom:2px solid #c3c4c7}
						.ufsc-lc-ffst-import-record:first-of-type{display:grid!important;visibility:visible!important}
					</style>
					<?php if(!$counts_consistent): ?>
						<div class="notice notice-error inline"><p><?php echo esc_html(sprintf(__('Attention : %1$d licences annoncées, mais %2$d lignes enregistrées. Validation désactivée jusqu’à résolution.','ufsc-licence-competition'),(int)$batch->total_rows,(int)$actual_rows)); ?></p></div>
					<?php endif; ?>
					<?php if($legacy_count>0 && 'preview'===$batch->status): ?>
						<div class="notice notice-warning inline"><p><?php echo esc_html(sprintf(__('%d ligne(s) possèdent un ancien statut technique incorrect (0). Vous pouvez recalculer leurs correspondances depuis les données déjà enregistrées, sans importer de nouveau PDF.','ufsc-licence-competition'),$legacy_count)); ?></p>
							<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
								<input type="hidden" name="action" value="ufsc_lc_ffst_recheck">
								<input type="hidden" name="batch_id" value="<?php echo esc_attr($batch_id); ?>">
								<?php wp_nonce_field('ufsc_lc_ffst_recheck_'.$batch_id,'ufsc_lc_ffst_recheck_nonce'); ?>
								<?php submit_button(__('Recalculer les rapprochements du lot','ufsc-licence-competition'),'secondary','submit',false); ?>
							</form>
						</div>
					<?php endif; ?>
					<p class="description">
						<?php echo esc_html(sprintf(__('Lignes stockées : %1$d — affichage %2$d à %3$d (page %4$d sur %5$d).','ufsc-licence-competition'),(int)$actual_rows,$actual_rows?$offset+1:0,min($actual_rows,$offset+count($rows)),$current_page,$pages)); ?>
					</p>
					<form id="ufsc-lc-ffst-selection" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
						<input type="hidden" name="action" value="ufsc_lc_ffst_apply">
						<input type="hidden" name="batch_id" value="<?php echo esc_attr($batch_id); ?>">
						<?php wp_nonce_field('ufsc_lc_ffst_apply_'.$batch_id,'ufsc_lc_ffst_apply_nonce'); ?>
					</form>
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
								<div role="columnheader"><?php esc_html_e('Contrôle','ufsc-licence-competition'); ?></div><div role="columnheader"><?php esc_html_e('Rapprochement','ufsc-licence-competition'); ?></div>
							</div>
							<?php foreach($rows as $row): ?>
								<div class="ufsc-lc-ffst-import-record" role="row" data-ffst-row="<?php echo esc_attr(absint($row->row_index)); ?>">
									<div role="cell">
										<?php if('matched'===$row->match_status && !isset($duplicates[absint($row->id)]) && $counts_consistent && 'preview'===$batch->status): ?>
											<input aria-label="<?php echo esc_attr(sprintf(__('Sélectionner la ligne %d','ufsc-licence-competition'),$row->row_index)); ?>" type="checkbox" form="ufsc-lc-ffst-selection" name="ffst_selected[]" value="<?php echo esc_attr($row->id); ?>">
										<?php endif; ?>
									</div>
									<div role="cell"><?php echo esc_html($row->row_index); ?></div>
									<div role="cell"><strong><?php echo esc_html($row->ffst_number?:'—'); ?></strong></div>
									<div role="cell"><?php echo esc_html($row->last_name?:'—'); ?></div>
									<div role="cell"><?php echo esc_html($row->first_name?:'—'); ?></div>
									<div role="cell"><?php echo esc_html($row->birthdate?:'—'); ?></div>
									<div role="cell"><?php echo esc_html($row->club_name?:'—'); ?></div>
									<div role="cell"><?php echo $row->match_licence_id?esc_html('#'.(int)$row->match_licence_id):'—'; ?></div>
									<div role="cell"><?php echo isset($duplicates[absint($row->id)])?'<strong style="color:#a00">Doublon</strong>':$this->status_badge($row->match_status); ?></div>
									<div role="cell"><?php echo esc_html(isset($duplicates[absint($row->id)])?$duplicates[absint($row->id)]:$row->match_message); ?></div>
									<div role="cell">
										<?php if('applied'===$row->match_status): ?>
											<strong><?php esc_html_e('Déjà synchronisée','ufsc-licence-competition'); ?></strong>
										<?php elseif(isset($duplicates[absint($row->id)])): ?>
											<strong><?php esc_html_e('Bloqué : doublon','ufsc-licence-competition'); ?></strong>
										<?php elseif('matched'===$row->match_status): ?>
											<strong><?php esc_html_e('Prête à valider','ufsc-licence-competition'); ?></strong>
										<?php else: ?>
											<?php $candidates=$service->get_review_candidates($row->id,$batch_id,20); ?>
											<?php if($candidates && 'preview'===$batch->status): ?>
												<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
													<input type="hidden" name="action" value="ufsc_lc_ffst_assign">
													<input type="hidden" name="batch_id" value="<?php echo esc_attr($batch_id); ?>">
													<input type="hidden" name="row_id" value="<?php echo esc_attr($row->id); ?>">
													<?php wp_nonce_field('ufsc_lc_ffst_assign_'.$batch_id.'_'.$row->id,'ufsc_lc_ffst_assign_nonce'); ?>
													<select name="licence_id" required aria-label="<?php esc_attr_e('Sélectionner une licence UFSC vérifiée','ufsc-licence-competition'); ?>">
														<option value=""><?php esc_html_e('Choisir un dossier','ufsc-licence-competition'); ?></option>
														<?php foreach($candidates as $candidate): ?>
															<option value="<?php echo esc_attr($candidate->id); ?>"><?php echo esc_html('#'.$candidate->id.' — '.$candidate->club_name); ?></option>
														<?php endforeach; ?>
													</select>
													<button class="button button-small" type="submit"><?php esc_html_e('Associer','ufsc-licence-competition'); ?></button>
												</form>
											<?php else: ?>
												<?php esc_html_e('Contrôle manuel requis : aucune correspondance sûre','ufsc-licence-competition'); ?>
											<?php endif; ?>
										<?php endif; ?>
									</div>
								</div>
							<?php endforeach; ?>
						</div>
					</div>
					<?php if($pages>1): ?>
						<nav aria-label="<?php esc_attr_e('Pages des licences du lot','ufsc-licence-competition'); ?>" style="margin-top:14px">
							<?php for($page_number=1;$page_number<=$pages;$page_number++): ?>
								<a class="button <?php echo $page_number===$current_page?'button-primary':''; ?>" href="<?php echo esc_url(add_query_arg(array('page'=>self::PAGE_SLUG,'batch_id'=>$batch_id,'ffst_page'=>$page_number),admin_url('admin.php'))); ?>"><?php echo esc_html($page_number); ?></a>
							<?php endfor; ?>
						</nav>
					<?php endif; ?>
					<?php if($counts_consistent && 0===$legacy_count && 'preview'===$batch->status && (int)$batch->matched_rows>0): ?>
						<div style="margin-top:18px">
							<button form="ufsc-lc-ffst-selection" class="button button-primary" type="submit" onclick="return confirm('Confirmer uniquement les lignes cochées ? Les doublons et associations non vérifiées sont bloqués.');"><?php esc_html_e('Valider les lignes sélectionnées','ufsc-licence-competition'); ?></button>
							<p class="description"><?php esc_html_e('Cochez les lignes sûres sur cette page (100 maximum). Aucune sélection = aucune écriture. Les pages suivantes se traitent séparément.','ufsc-licence-competition'); ?></p>
						</div>
					<?php elseif('applied'===$batch->status): ?>
						<div class="notice notice-success inline"><p><?php echo esc_html(sprintf(__('%d ligne(s) appliquée(s).','ufsc-licence-competition'),$batch->applied_rows)); ?></p></div>
					<?php endif; ?>
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
		$map=array('matched'=>array('Trouvée','#e7f6ec','#166534'),'applied'=>array('Appliquée','#dcfce7','#166534'),'ambiguous'=>array('À contrôler','#fff7d6','#92400e'),'conflict'=>array('Conflit','#fee2e2','#991b1b'),'not_found'=>array('Introuvable','#f3f4f6','#4b5563'));
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
