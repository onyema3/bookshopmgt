<?php
/**
 * Hold / Park a Sale
 * Persists parked carts in the database per staff member.
 */
if(!defined('ABSPATH'))exit;

function bs_park_sale($staff_id,$cart,$customer=null,$note=''){
    global $wpdb;
    $ref='HOLD-'.strtoupper(substr(md5(uniqid('',true)),0,6));
    $wpdb->insert("{$wpdb->prefix}bookshop_held_sales",[
        'ref'        =>$ref,
        'staff_id'   =>intval($staff_id),
        'cart_data'  =>json_encode($cart),
        'customer_id'=>$customer?intval($customer['id']):null,
        'customer_data'=>$customer?json_encode($customer):null,
        'note'       =>sanitize_text_field($note),
        'status'     =>'held',
    ]);
    return ['id'=>$wpdb->insert_id,'ref'=>$ref];
}
function bs_get_held_sales($staff_id=0){
    global $wpdb;
    $w=$staff_id?"AND staff_id=".intval($staff_id):'';
    return $wpdb->get_results(
        "SELECT hs.*, u.display_name AS staff_name
         FROM {$wpdb->prefix}bookshop_held_sales hs
         LEFT JOIN {$wpdb->users} u ON u.ID=hs.staff_id
         WHERE hs.status='held' $w
         ORDER BY hs.created_at DESC"
    );
}
function bs_recall_held_sale($id){
    global $wpdb;
    $sale=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}bookshop_held_sales WHERE id=%d AND status='held'",$id));
    if(!$sale) return false;
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
    $res=bs_park_sale(get_current_user_id(),$cart,$customer,$note);
    wp_send_json_success($res);
});
add_action('wp_ajax_bs_get_held_sales',function(){
    if(!bs_user_can_pos()) wp_send_json_error('Unauthorized',403);
    $sales=bs_get_held_sales(get_current_user_id());
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
    $data=bs_recall_held_sale(intval($_POST['id']??0));
    $data?wp_send_json_success($data):wp_send_json_error('Sale not found');
});
add_action('wp_ajax_bs_delete_held_sale',function(){
    if(!bs_user_can_pos()) wp_send_json_error('Unauthorized',403);
    bs_delete_held_sale(intval($_POST['id']??0));
    wp_send_json_success();
});
