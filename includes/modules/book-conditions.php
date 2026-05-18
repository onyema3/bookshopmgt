<?php
/**
 * Book Conditions — New, Used, Damaged with separate prices
 */
if(!defined('ABSPATH'))exit;

// Add condition columns to books table if missing
add_action('admin_init',function(){
    global $wpdb;
    $cols=$wpdb->get_col("SHOW COLUMNS FROM {$wpdb->prefix}bookshop_books",0);
    if(!in_array('condition_type',$cols)){
        $wpdb->query("ALTER TABLE {$wpdb->prefix}bookshop_books
            ADD COLUMN condition_type ENUM('new','used','damaged') NOT NULL DEFAULT 'new' AFTER status,
            ADD COLUMN condition_notes VARCHAR(255) DEFAULT '' AFTER condition_type,
            ADD COLUMN used_price DECIMAL(10,2) DEFAULT NULL AFTER condition_notes,
            ADD COLUMN damaged_price DECIMAL(10,2) DEFAULT NULL AFTER used_price");
    }
});

function bs_get_book_conditions(){ return ['new'=>'New','used'=>'Used','damaged'=>'Damaged']; }

// Search books for POS — return all conditions with their prices
add_filter('bs_search_books_result',function($books){
    foreach($books as &$b){
        $b->available_conditions=[];
        if($b->stock_qty>0) $b->available_conditions[]=['condition'=>'new','label'=>'New','price'=>$b->sell_price,'stock'=>$b->stock_qty];
        if(!empty($b->used_price)&&$b->used_stock>0) $b->available_conditions[]=['condition'=>'used','label'=>'Used','price'=>$b->used_price,'stock'=>$b->used_stock??0];
        if(!empty($b->damaged_price)&&$b->damaged_stock>0) $b->available_conditions[]=['condition'=>'damaged','label'=>'Damaged','price'=>$b->damaged_price,'stock'=>$b->damaged_stock??0];
    }
    return $books;
});
