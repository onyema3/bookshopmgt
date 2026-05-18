<?php
/**
 * Book Bundles / Sets
 * A bundle groups multiple books into a single SKU with a discounted price
 */
if(!defined('ABSPATH'))exit;

function bs_get_bundles($status='active'){
    global $wpdb;
    $w=$status?$wpdb->prepare("WHERE status=%s",$status):'';
    return $wpdb->get_results("SELECT * FROM {$wpdb->prefix}bookshop_bundles $w ORDER BY name ASC");
}
function bs_get_bundle($id){
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}bookshop_bundles WHERE id=%d",$id));
}
function bs_get_bundle_items($bundle_id){
    global $wpdb;
    return $wpdb->get_results($wpdb->prepare(
        "SELECT bi.*,b.title,b.author,b.isbn,b.sell_price,b.stock_qty
         FROM {$wpdb->prefix}bookshop_bundle_items bi
         JOIN {$wpdb->prefix}bookshop_books b ON b.id=bi.book_id
         WHERE bi.bundle_id=%d ORDER BY b.title ASC",
        $bundle_id));
}
function bs_save_bundle($data,$id=0){
    global $wpdb;
    $f=[
        'name'       =>sanitize_text_field($data['name']??''),
        'description'=>sanitize_textarea_field($data['description']??''),
        'price'      =>floatval($data['price']??0),
        'status'     =>in_array($data['status']??'active',['active','inactive'])?$data['status']:'active',
    ];
    if($id){ $wpdb->update("{$wpdb->prefix}bookshop_bundles",$f,['id'=>$id]); }
    else { $wpdb->insert("{$wpdb->prefix}bookshop_bundles",$f); $id=$wpdb->insert_id; }
    // Replace items
    if(isset($data['book_ids'])&&is_array($data['book_ids'])){
        $wpdb->delete("{$wpdb->prefix}bookshop_bundle_items",['bundle_id'=>$id]);
        foreach($data['book_ids'] as $book_id){
            $book_id=intval($book_id);
            if($book_id>0) $wpdb->insert("{$wpdb->prefix}bookshop_bundle_items",['bundle_id'=>$id,'book_id'=>$book_id,'qty'=>1]);
        }
    }
    return $id;
}
function bs_bundle_in_stock($bundle_id){
    $items=bs_get_bundle_items($bundle_id);
    foreach($items as $item){ if($item->stock_qty<$item->qty) return false; }
    return count($items)>0;
}
function bs_sell_bundle($bundle_id,$qty=1){
    // Returns cart items array ready for bs_create_sale
    $bundle=bs_get_bundle($bundle_id);
    if(!$bundle||$bundle->status!=='active') return false;
    $items=bs_get_bundle_items($bundle_id);
    if(empty($items)) return false;
    // Represent bundle as a virtual cart item using the first book's ID
    // and distribute cost proportionally
    return [['id'=>$bundle_id,'title'=>$bundle->name.' (Bundle)','price'=>$bundle->price,'qty'=>$qty,'is_bundle'=>true,'bundle_items'=>$items]];
}

// AJAX handlers
add_action('wp_ajax_bs_get_bundles',function(){
    if(!bs_user_can_pos()) wp_send_json_error('Unauthorized',403);
    wp_send_json_success(bs_get_bundles());
});
add_action('wp_ajax_bs_save_bundle',function(){
    if(!current_user_can('manage_options')) wp_send_json_error('Unauthorized',403);
    $id=intval($_POST['id']??0);
    $data=array_merge($_POST,['book_ids'=>json_decode(stripslashes($_POST['book_ids']??'[]'),true)]);
    wp_send_json_success(['id'=>bs_save_bundle($data,$id)]);
});
add_action('wp_ajax_bs_get_bundle_items',function(){
    if(!bs_user_can_pos()) wp_send_json_error('Unauthorized',403);
    $items=bs_get_bundle_items(intval($_GET['id']??0));
    wp_send_json_success($items);
});
