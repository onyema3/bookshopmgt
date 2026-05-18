<?php
/**
 * Hold / Park a Sale
 * Persists parked carts in the database per staff member.
 */
if(!defined('ABSPATH'))exit;

function bs_park_sale($staff_id,$cart,$customer=null,$note='',$branch_id=0){
    global $wpdb;
    $ref='HOLD-'.strtoupper(substr(md5(uniqid('',true)),0,6));
    $wpdb->insert("{$wpdb->prefix}bookshop_held_sales",[
        'ref'        =>$ref,
        'staff_id'   =>intval($staff_id),
        'branch_id'  =>intval($branch_id) ?: null,
        'cart_data'  =>json_encode($cart),
        'customer_id'=>$customer?intval($customer['id']):null,
        'customer_data'=>$customer?json_encode($customer):null,
        'note'       =>sanitize_text_field($note),
        'status'     =>'held',
    ]);
    return ['id'=>$wpdb->insert_id,'ref'=>$ref];
}
function bs_get_held_sales($staff_id=0,$branch_id=0){
    global $wpdb;
    $where=["hs.status='held'"];
    if($staff_id)  $where[]=$wpdb->prepare("hs.staff_id=%d",intval($staff_id));
    if($branch_id) $where[]=$wpdb->prepare("hs.branch_id=%d",intval($branch_id));
    $sql="SELECT hs.*, u.display_name AS staff_name
          FROM {$wpdb->prefix}bookshop_held_sales hs
          LEFT JOIN {$wpdb->users} u ON u.ID=hs.staff_id
          WHERE ".implode(' AND ',$where)."
          ORDER BY hs.created_at DESC";
    return $wpdb->get_results($sql);
}
function bs_recall_held_sale($id,$branch_id=0){
    global $wpdb;
    $branch_id=intval($branch_id);
    $sale=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}bookshop_held_sales WHERE id=%d AND status='held'",$id));
    if(!$sale) return false;
    // Refuse to recall a sale parked at a different branch.
    if($branch_id && $sale->branch_id && intval($sale->branch_id)!==$branch_id) return false;
    $wpdb->update("{$wpdb->prefix}bookshop_held_sales",['status'=>'recalled'],['id'=>$id]);
    return [
        'cart'     =>json_decode($sale->cart_data,true),
        'customer' =>$sale->customer_data?json_decode($sale->customer_data,true):null,
        'note'     =>$sale->note,
        'ref'      =>$sale->ref,
    ];
}
function bs_delete_held_sale($id){
    global $wpdb;
    $wpdb->update("{$wpdb->prefix}bookshop_held_sales",['status'=>'cancelled'],['id'=>$id]);
}

// AJAX handlers
add_action('wp_ajax_bs_park_sale',function(){
    if(!bs_user_can_pos()) wp_send_json_error('Unauthorized',403);
    $cart    =json_decode(stripslashes($_POST['cart']??'[]'),true);
    $customer=json_decode(stripslashes($_POST['customer']??'null'),true);
    $note    =sanitize_text_field($_POST['note']??'');
    if(empty($cart)) wp_send_json_error('Cart is empty');

    // Branch is required so the sale can only be recalled at the same location.
    $uid       = get_current_user_id();
    $shift     = bs_get_open_shift($uid);
    $branch_id = $shift && $shift->branch_id ? intval($shift->branch_id) : bs_get_active_branch_id($uid);
    if(!$branch_id) wp_send_json_error([
        'code'    => 'no_branch',
        'message' => 'Select a branch before parking a sale.',
    ]);

    $res=bs_park_sale($uid,$cart,$customer,$note,$branch_id);
    wp_send_json_success($res);
});
add_action('wp_ajax_bs_get_held_sales',function(){
    if(!bs_user_can_pos()) wp_send_json_error('Unauthorized',403);
    $uid       = get_current_user_id();
    $branch_id = bs_get_active_branch_id($uid);

    // Always scope held sales to the current branch so a parked sale can't
    // surface at the wrong location. Managers see all staff at their branch;
    // regular staff see only their own.
    $staff_filter = bs_user_can_manage($uid) ? 0 : $uid;
    $sales=bs_get_held_sales($staff_filter,$branch_id);
    // Decode cart for preview
    foreach($sales as &$s){
        $cart=json_decode($s->cart_data,true);
        $s->item_count=array_sum(array_column($cart,'qty'));
        $s->subtotal=array_sum(array_map(function($i){return floatval($i['price'])*intval($i['qty']);},$cart));
    }
    wp_send_json_success($sales);
});
add_action('wp_ajax_bs_recall_held_sale',function(){
    if(!bs_user_can_pos()) wp_send_json_error('Unauthorized',403);
    $branch_id=bs_get_active_branch_id(get_current_user_id());
    $data=bs_recall_held_sale(intval($_POST['id']??0),$branch_id);
    $data?wp_send_json_success($data):wp_send_json_error('Sale not found at this branch');
});
add_action('wp_ajax_bs_delete_held_sale',function(){
    if(!bs_user_can_pos()) wp_send_json_error('Unauthorized',403);
    bs_delete_held_sale(intval($_POST['id']??0));
    wp_send_json_success();
});
