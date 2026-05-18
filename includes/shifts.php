<?php
if(!defined('ABSPATH'))exit;

function bs_open_shift($staff_id,$opening_cash,$branch_id=0){
    global $wpdb;
    $branch_id=intval($branch_id);
    if(!$branch_id) return ['error'=>'No branch selected'];
    if(!bs_get_branch($branch_id)) return ['error'=>'Invalid branch'];
    // Close any open shift for this staff first
    $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}bookshop_shifts SET status='closed',closed_at=NOW() WHERE staff_id=%d AND status='open'",$staff_id));
    $wpdb->insert("{$wpdb->prefix}bookshop_shifts",[
        'staff_id'    =>$staff_id,
        'branch_id'   =>$branch_id,
        'opening_cash'=>floatval($opening_cash),
        'status'      =>'open',
    ]);
    $id=$wpdb->insert_id;
    bs_audit('shift_opened','shift',$id,"Opening cash: ".bs_fmt($opening_cash).", branch $branch_id");
    return ['shift_id'=>$id];
}
function bs_close_shift($shift_id,$closing_cash,$notes=''){
    global $wpdb;
    $shift=bs_get_shift($shift_id);
    if(!$shift||$shift->status==='closed') return false;
    // Calculate expected cash from cash sales this shift
    $expected=$wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(SUM(total),0) FROM {$wpdb->prefix}bookshop_sales WHERE shift_id=%d AND payment_method='cash' AND status='completed'",$shift_id
    ));
    $expected=floatval($shift->opening_cash)+floatval($expected);
    $variance=floatval($closing_cash)-$expected;
    $wpdb->update("{$wpdb->prefix}bookshop_shifts",['status'=>'closed','closed_at'=>current_time('mysql'),
        'closing_cash'=>floatval($closing_cash),'expected_cash'=>$expected,'variance'=>$variance,'notes'=>sanitize_textarea_field($notes)],['id'=>$shift_id]);
    bs_audit('shift_closed','shift',$shift_id,"Closing: ".bs_fmt($closing_cash).", Variance: ".bs_fmt($variance));
    return ['expected'=>$expected,'closing'=>$closing_cash,'variance'=>$variance];
}
function bs_get_shift($id){global $wpdb;return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}bookshop_shifts WHERE id=%d",$id));}
function bs_get_open_shift($staff_id){
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}bookshop_shifts WHERE staff_id=%d AND status='open' ORDER BY opened_at DESC LIMIT 1",$staff_id));
}
function bs_get_shifts($args=[]){
    global $wpdb;
    $a=wp_parse_args($args,['limit'=>50,'offset'=>0,'staff_id'=>0]);
    $where=['1=1'];$p=[];
    if($a['staff_id']){$where[]='s.staff_id=%d';$p[]=$a['staff_id'];}
    $sql="SELECT s.*,u.display_name AS staff_name FROM {$wpdb->prefix}bookshop_shifts s LEFT JOIN {$wpdb->users} u ON u.ID=s.staff_id WHERE ".implode(' AND ',$where)." ORDER BY s.opened_at DESC LIMIT %d OFFSET %d";
    $p[]=$a['limit'];$p[]=$a['offset'];
    return $wpdb->get_results($wpdb->prepare($sql,$p));
}
