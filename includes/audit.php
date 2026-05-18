<?php
if(!defined('ABSPATH'))exit;
function bs_audit($action,$obj_type='',$obj_id=null,$details=''){
    global $wpdb;
    $wpdb->insert("{$wpdb->prefix}bookshop_audit_log",['staff_id'=>get_current_user_id(),'action'=>$action,'object_type'=>$obj_type,'object_id'=>$obj_id,'details'=>$details]);
}
function bs_get_audit_log($args=[]){
    global $wpdb;
    $a=wp_parse_args($args,[
        'limit'       =>100,
        'offset'      =>0,
        'staff_id'    =>0,
        'action'      =>'',
        // Object-based filters. Used by the per-book audit panel on the
        // breakdown modal. action_in is a list — needed because branch
        // stock changes show up under several action names ('sale_created',
        // 'refund_created', 'stock_transfer', 'stock_take_completed', etc.)
        // and the panel wants them all together.
        'object_type' =>'',
        'object_id'   =>0,
        'action_in'   =>[],
    ]);
    $where=['1=1'];$p=[];
    if($a['staff_id']){    $where[]='a.staff_id=%d'; $p[]=$a['staff_id']; }
    if($a['action']){      $where[]='a.action=%s';   $p[]=$a['action']; }
    if($a['object_type']){ $where[]='a.object_type=%s'; $p[]=$a['object_type']; }
    if($a['object_id']){   $where[]='a.object_id=%d'; $p[]=$a['object_id']; }
    if(!empty($a['action_in']) && is_array($a['action_in'])){
        $in   = array_values(array_filter($a['action_in'],'is_string'));
        if($in){
            $ph = implode(',', array_fill(0, count($in), '%s'));
            $where[] = "a.action IN ($ph)";
            foreach($in as $v) $p[] = $v;
        }
    }
    $sql="SELECT a.*,u.display_name AS staff_name FROM {$wpdb->prefix}bookshop_audit_log a LEFT JOIN {$wpdb->users} u ON u.ID=a.staff_id WHERE ".implode(' AND ',$where)." ORDER BY a.created_at DESC LIMIT %d OFFSET %d";
    $p[]=$a['limit'];$p[]=$a['offset'];
    return $wpdb->get_results($wpdb->prepare($sql,$p));
}
