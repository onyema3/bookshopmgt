<?php
/**
 * Returns & Refunds
 */
if(!defined('ABSPATH'))exit;

function bs_create_refund($sale_id,$items_to_refund,$reason='',$restock=true){
    global $wpdb;
    $sale=bs_get_sale($sale_id);
    if(!$sale||$sale->status==='voided') return['error'=>'Sale not found or already voided'];

    $refund_total=0;
    $refund_items=[];
    $sale_items=bs_get_sale_items($sale_id);
    $sale_items_map=[];
    foreach($sale_items as $si) $sale_items_map[$si->book_id]=$si;

    foreach($items_to_refund as $book_id=>$qty){
        $book_id=intval($book_id);$qty=intval($qty);
        if(!isset($sale_items_map[$book_id])) continue;
        $si=$sale_items_map[$book_id];
        $max_qty=intval($si->qty);
        $already_refunded=intval($wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(ri.qty),0) FROM {$wpdb->prefix}bookshop_refund_items ri
             JOIN {$wpdb->prefix}bookshop_refunds r ON r.id=ri.refund_id
             WHERE r.sale_id=%d AND ri.book_id=%d AND r.status='completed'",
            $sale_id,$book_id)));
        $available=$max_qty-$already_refunded;
        $qty=min($qty,$available);
        if($qty<=0) continue;
        $line=floatval($si->unit_price)*$qty;
        $refund_total+=$line;
        $unit_price = floatval($si->unit_price);
        $line_total = $line;
        $refund_items[]=['book_id'=>$book_id,'qty'=>$qty,'unit_price'=>$unit_price,'line_total'=>$line_total];
    }

    if(empty($refund_items)) return['error'=>'No valid items to refund'];

    $ref='RF-'.strtoupper(substr(md5(uniqid('',true)),0,6));
    $wpdb->insert("{$wpdb->prefix}bookshop_refunds",[
        'sale_id'  =>$sale_id,
        'ref'      =>$ref,
        'staff_id' =>get_current_user_id(),
        'amount'   =>$refund_total,
        'reason'   =>sanitize_text_field($reason),
        'restock'  =>$restock?1:0,
        'status'   =>'completed',
    ]);
    $refund_id=$wpdb->insert_id;

    foreach($refund_items as $ri){
        $wpdb->insert("{$wpdb->prefix}bookshop_refund_items",[
            'refund_id' =>$refund_id,
            'book_id'   =>$ri['book_id'],
            'qty'        =>$ri['qty'],
            'unit_price' =>$ri['unit_price'],
            'line_total' =>$ri['line_total'],
        ]);
        if($restock){
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->prefix}bookshop_books SET stock_qty=stock_qty+%d WHERE id=%d",
                $ri['qty'],$ri['book_id']
            ));
            // Restore at the same branch the original sale was made from.
            // Pre-migration sales have a NULL branch_id and are skipped.
            if ( !empty($sale->branch_id) ) {
                bs_adjust_branch_stock( intval($sale->branch_id), intval($ri['book_id']), intval($ri['qty']) );
            }
        }
    }
    bs_audit('refund_created','refund',$refund_id,"Refund $ref for sale {$sale->sale_ref} — ".bs_fmt($refund_total).". Reason: $reason");
    return['success'=>true,'ref'=>$ref,'amount'=>$refund_total,'refund_id'=>$refund_id];
}

function bs_get_refunds($sale_id=0){
    global $wpdb;
    $w=$sale_id?$wpdb->prepare("WHERE r.sale_id=%d",$sale_id):'';
    return $wpdb->get_results("SELECT r.*,u.display_name AS staff_name FROM {$wpdb->prefix}bookshop_refunds r LEFT JOIN {$wpdb->users} u ON u.ID=r.staff_id $w ORDER BY r.created_at DESC LIMIT 100");
}
function bs_get_refund_items($refund_id){
    global $wpdb;
    return $wpdb->get_results($wpdb->prepare(
        "SELECT ri.*,b.title,b.isbn FROM {$wpdb->prefix}bookshop_refund_items ri LEFT JOIN {$wpdb->prefix}bookshop_books b ON b.id=ri.book_id WHERE ri.refund_id=%d",
        $refund_id));
}

// AJAX
add_action('wp_ajax_bs_create_refund',function(){
    if(!bs_user_can_manage()) wp_send_json_error('Unauthorized — manager required',403);
    $sale_id =intval($_POST['sale_id']??0);
    $items   =json_decode(stripslashes($_POST['items']??'{}'),true);
    $reason  =sanitize_text_field($_POST['reason']??'');
    $restock =!empty($_POST['restock']);
    if(!$sale_id||empty($items)) wp_send_json_error('Missing data');
    $res=bs_create_refund($sale_id,$items,$reason,$restock);
    isset($res['error'])?wp_send_json_error($res['error']):wp_send_json_success($res);
});
add_action('wp_ajax_bs_get_refunds',function(){
    if(!bs_user_can_manage()) wp_send_json_error('Unauthorized',403);
    $items=bs_get_refunds(intval($_GET['sale_id']??0));
    wp_send_json_success($items);
});
