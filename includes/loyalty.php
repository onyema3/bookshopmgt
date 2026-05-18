<?php if(!defined('ABSPATH'))exit;
function bs_log_loyalty($cid,$sale_id,$pts,$type,$note=''){
    global $wpdb;
    $wpdb->insert("{$wpdb->prefix}bookshop_loyalty_log",['customer_id'=>$cid,'sale_id'=>$sale_id?:null,'points'=>$pts,'type'=>$type,'note'=>$note]);
}
function bs_get_loyalty_log($cid){
    global $wpdb;
    return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}bookshop_loyalty_log WHERE customer_id=%d ORDER BY created_at DESC LIMIT 50",$cid));
}
function bs_adjust_loyalty($cid,$pts,$note=''){
    global $wpdb;
    $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}bookshop_customers SET loyalty_points=GREATEST(0,loyalty_points+%d) WHERE id=%d",$pts,$cid));
    bs_log_loyalty($cid,null,$pts,'adjusted',$note);
}
