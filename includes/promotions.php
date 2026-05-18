<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function bs_get_promotions($status=''){
    global $wpdb;
    $where=['1=1'];$p=[];
    if($status){$where[]='status=%s';$p[]=$status;}
    $sql="SELECT * FROM {$wpdb->prefix}bookshop_promotions WHERE ".implode(' AND ',$where)." ORDER BY created_at DESC";
    return $p ? $wpdb->get_results($wpdb->prepare($sql,$p)) : $wpdb->get_results($sql);
}
function bs_get_promotion($id){global $wpdb;return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}bookshop_promotions WHERE id=%d",$id));}
function bs_get_promo_by_code($code){
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}bookshop_promotions WHERE code=%s AND status='active'",$code));
}
function bs_save_promotion($data,$id=0){
    global $wpdb;
    $f=[
        'name'             =>sanitize_text_field($data['name']??''),
        'code'             =>strtoupper(sanitize_text_field($data['code']??'')),
        'type'             =>in_array($data['type']??'percent',['percent','fixed','buy_x_get_y','bundle'])?$data['type']:'percent',
        'value'            =>floatval($data['value']??0),
        'min_purchase'     =>floatval($data['min_purchase']??0),
        'buy_qty'          =>intval($data['buy_qty']??0)?:null,
        'get_qty'          =>intval($data['get_qty']??0)?:null,
        'usage_limit'      =>intval($data['usage_limit']??0)?:null,
        'requires_manager' =>intval($data['requires_manager']??0),
        'start_date'       =>!empty($data['start_date'])?sanitize_text_field($data['start_date']):null,
        'end_date'         =>!empty($data['end_date'])?sanitize_text_field($data['end_date']):null,
        'status'           =>in_array($data['status']??'active',['active','inactive'])?$data['status']:'active',
    ];
    if($id){$wpdb->update("{$wpdb->prefix}bookshop_promotions",$f,['id'=>$id]);return $id;}
    $wpdb->insert("{$wpdb->prefix}bookshop_promotions",$f);return $wpdb->insert_id;
}
function bs_promo_valid($promo,$subtotal){
    if($promo->status!=='active') return false;
    if($promo->usage_limit && $promo->used_count>=$promo->usage_limit) return false;
    if($promo->min_purchase>0 && $subtotal<$promo->min_purchase) return false;
    $today=date('Y-m-d');
    if($promo->start_date && $today<$promo->start_date) return false;
    if($promo->end_date   && $today>$promo->end_date)   return false;
    return true;
}
function bs_calc_promo_discount($promo,$subtotal,$cart=[]){
    switch($promo->type){
        case 'percent':    return round($subtotal*($promo->value/100),2);
        case 'fixed':      return min($promo->value,$subtotal);
        case 'buy_x_get_y':
            $total_qty=array_sum(array_column($cart,'qty'));
            $sets=intval($total_qty/($promo->buy_qty+$promo->get_qty));
            // cheapest item * free qty
            if(!$sets) return 0;
            $prices=array_map(function($i){ return floatval($i['price']); },$cart);
            sort($prices);
            return $prices[0]*$sets*$promo->get_qty;
        default: return 0;
    }
}
function bs_expire_promotions(){
    global $wpdb;
    $wpdb->query("UPDATE {$wpdb->prefix}bookshop_promotions SET status='inactive' WHERE end_date IS NOT NULL AND end_date < CURDATE() AND status='active'");
}
