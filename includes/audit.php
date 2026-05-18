<?php
if(!defined('ABSPATH'))exit;
function bs_audit($action,$obj_type='',$obj_id=null,$details=''){
    global $wpdb;
    $wpdb->insert("{$wpdb->prefix}bookshop_audit_log",['staff_id'=>get_current_user_id(),'action'=>$action,'object_type'=>$obj_type,'object_id'=>$obj_id,'details'=>$details]);
}
function bs_get_audit_log($args=[]){
    global $wpdb;
    $a=wp_parse_args($args,['limit'=>100,'offset'=>0,'staff_id'=>0,'action'=>'']);
    $where=['1=1'];$p=[];
    if($a['staff_id']){$where[]='a.staff_id=%d';$p[]=$a['staff_id'];}
    if($a['action']){$where[]='a.action=%s';$p[]=$a['action'];}
    $sql="SELECT a.*,u.display_name AS staff_name FROM {$wpdb->prefix}bookshop_audit_log a LEFT JOIN {$wpdb->users} u ON u.ID=a.staff_id WHERE ".implode(' AND ',$where)." ORDER BY a.created_at DESC LIMIT %d OFFSET %d";
    $p[]=$a['limit'];$p[]=$a['offset'];
    return $wpdb->get_results($wpdb->prepare($sql,$p));
}
