<?php
if(!defined('ABSPATH'))exit;

// WooCommerce stock sync — keeps WC product stock in sync with bookshop inventory
function bs_woo_sync_book($book_id){
    if(!function_exists('wc_get_product')) return;
    global $wpdb;
    $book=bs_get_book($book_id);
    if(!$book) return;
    // Look for linked WC product by SKU (ISBN)
    $product_id=wc_get_product_id_by_sku($book->isbn);
    if(!$product_id && $book->barcode) $product_id=wc_get_product_id_by_sku($book->barcode);
    if(!$product_id) return;
    $product=wc_get_product($product_id);
    if(!$product) return;
    $product->set_stock_quantity($book->stock_qty);
    $product->set_regular_price($book->sell_price);
    $product->save();
}

// Hook: after stock changes, sync to WC
add_action('bs_after_stock_change','bs_woo_sync_book');

// Import WC products into bookshop as books
function bs_import_from_woocommerce($limit=100){
    if(!function_exists('wc_get_products')) return ['error'=>'WooCommerce not active'];
    $products=wc_get_products(['limit'=>$limit,'status'=>'publish','type'=>'simple']);
    $imported=0;
    foreach($products as $p){
        $isbn=$p->get_sku();
        global $wpdb;
        $exists=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}bookshop_books WHERE isbn=%s LIMIT 1",$isbn));
        if($exists) continue;
        bs_save_book([
            'title'      =>$p->get_name(),
            'isbn'       =>$isbn,
            'sell_price' =>$p->get_regular_price(),
            'stock_qty'  =>$p->get_stock_quantity(),
            'cover_url'  =>wp_get_attachment_url($p->get_image_id()),
            'description'=>$p->get_short_description(),
            'status'     =>'active',
        ]);
        $imported++;
    }
    return['imported'=>$imported];
}

// Reservation shortcode sync — create WC order on reservation fulfillment (optional)
function bs_woo_active(){return function_exists('WC');}
