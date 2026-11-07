<?php
namespace UFSC\Competitions\Admin\Pages;
if (!defined('ABSPATH')) exit;

/** Read-only administrative license lookup with filters. */
final class Competition_License_Search_Page {
    const SLUG = 'ufsc-competitions-license-lookup';
    public static function register(): void {
        add_action('admin_menu',array(__CLASS__,'menu'),34);
    }
    public static function menu(): void {
        add_submenu_page('ufsc-competitions','Recherche licences','Recherche licences',
            \UFSC\Competitions\Capabilities::get_edit_capability(),self::SLUG,array(__CLASS__,'render'));
    }
    private static function first(array $columns,array $names): string {
        foreach($names as $name) if(in_array($name,$columns,true)) return $name;
        return '';
    }
    public static function render(): void {
        if(!current_user_can(\UFSC\Competitions\Capabilities::get_edit_capability()))
            wp_die('Accès refusé.','',array('response'=>403));
        $q=isset($_GET['q']) && is_string($_GET['q'])?sanitize_text_field(wp_unslash($_GET['q'])):'';
        $status=isset($_GET['license_status']) && is_string($_GET['license_status'])?sanitize_key(wp_unslash($_GET['license_status'])):'';
        $season=isset($_GET['license_season']) && is_string($_GET['license_season'])?sanitize_text_field(wp_unslash($_GET['license_season'])):'';
        echo '<div class="wrap"><h1>Recherche licences pour les plateaux</h1>';
        echo '<p>Consultation administrative : aucun changement de licence ni de combat.</p>';
        echo '<form method="get"><input type="hidden" name="page" value="'.esc_attr(self::SLUG).'">';
        echo '<p><label>Nom, prénom, numéro ou club <input name="q" class="regular-text" value="'.esc_attr($q).'" required></label> ';
        echo '<label>Statut <select name="license_status">';
        foreach(array(''=>'Tous','valide'=>'Validée','en_attente'=>'En attente','brouillon'=>'Brouillon','expire'=>'Expirée') as $v=>$label)
            echo '<option value="'.esc_attr($v).'" '.selected($status,$v,false).'>'.esc_html($label).'</option>';
        echo '</select></label> <label>Saison <input name="license_season" value="'.esc_attr($season).'" placeholder="2026-2027"></label> ';
        echo '<button class="button button-primary">Rechercher</button></p></form>';
        if(strlen($q)<2){echo '<p>Indiquez au moins deux caractères.</p></div>';return;}
        global $wpdb;
        $table=$wpdb->prefix.'ufsc_licences';
        if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table))!==$table){
            echo '<p>Table licences indisponible.</p></div>';return;
        }
        $columns=$wpdb->get_col("SHOW COLUMNS FROM {$table}",0);
        $last=self::first($columns,array('nom','nom_licence','last_name'));
        $first=self::first($columns,array('prenom','prenom_licence','first_name'));
        $num=self::first($columns,array('numero_licence_ffst','numero_licence_ufsc','numero_licence','num_licence','licence_numero'));
        $club=self::first($columns,array('club_name','club_nom','nom_club','association_name','structure_name'));
        $state=self::first($columns,array('statut','status'));
        $year=self::first($columns,array('saison','season','season_end_year','paid_season'));
        $search=array_filter(array($last,$first,$num,$club));
        if(!$search){echo '<p>Colonnes de recherche introuvables.</p></div>';return;}
        $conditions=array();$values=array();$like='%'.$wpdb->esc_like($q).'%';
        foreach($search as $field){$conditions[]=$field.' LIKE %s';$values[]=$like;}
        $where=array('('.implode(' OR ',$conditions).')');
        if($state && $status!==''){$where[]=$state.' = %s';$values[]=$status;}
        if($year && $season!==''){$where[]=$year.' = %s';$values[]=$season;}
        $fields=array_values(array_unique(array_intersect(array_filter(array('id',$last,$first,$num,$club,$state,$year)),$columns)));
        $sql='SELECT '.implode(',',$fields).' FROM '.$table.' WHERE '.implode(' AND ',$where).' LIMIT 51';
        $result=$wpdb->get_results($wpdb->prepare($sql,$values),ARRAY_A);
        echo '<h2>Résultats (50 maximum)</h2>';
        if(count((array)$result)>50) echo '<p>Affinez la recherche : plus de 50 résultats.</p>';
        echo '<table class="widefat striped"><thead><tr>';
        foreach($fields as $field) echo '<th>'.esc_html($field).'</th>';
        echo '</tr></thead><tbody>';
        foreach(array_slice((array)$result,0,50) as $record){
            echo '<tr>';
            foreach($fields as $field) echo '<td>'.esc_html((string)($record[$field]??'')).'</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }
}
