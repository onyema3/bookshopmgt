<?php
/**
 * Customer Tiers & Loyalty Enhancements
 * Bronze / Silver / Gold / Platinum based on lifetime spend
 */
if(!defined('ABSPATH'))exit;

function bs_get_tiers(){
    return [
        'bronze'   =>['label'=>'Bronze',   'min_spend'=>0,      'discount'=>0,   'color'=>'#cd7f32','icon'=>'🥉'],
        'silver'   =>['label'=>'Silver',   'min_spend'=>10000,  'discount'=>3,   'color'=>'#aaa','icon'=>'🥈'],
        'gold'     =>['label'=>'Gold',     'min_spend'=>50000,  'discount'=>5,   'color'=>'#c8860a','icon'=>'🥇'],
        'platinum' =>['label'=>'Platinum', 'min_spend'=>200000, 'discount'=>10,  'color'=>'#5e35b1','icon'=>'💎'],
    ];
}
function bs_get_customer_tier($customer_id){
    global $wpdb;
    $spend=floatval($wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(SUM(total),0) FROM {$wpdb->prefix}bookshop_sales
         WHERE customer_id=%d AND status='completed'",$customer_id)));
    $tiers=bs_get_tiers();
    $current='bronze';
    foreach($tiers as $key=>$tier){
        if($spend>=$tier['min_spend']) $current=$key;
    }
    return array_merge($tiers[$current],['key'=>$current,'lifetime_spend'=>$spend]);
}
function bs_update_customer_tier($customer_id){
    global $wpdb;
    $tier=bs_get_customer_tier($customer_id);
    $wpdb->update("{$wpdb->prefix}bookshop_customers",['tier'=>$tier['key']],['id'=>$customer_id]);
    return $tier;
}
// Hook: update tier after every sale
add_action('bs_sale_completed',function($sale_id){
    global $wpdb;
    $sale=$wpdb->get_row($wpdb->prepare("SELECT customer_id FROM {$wpdb->prefix}bookshop_sales WHERE id=%d",$sale_id));
    if($sale&&$sale->customer_id) bs_update_customer_tier($sale->customer_id);
});

// Birthday discount automation
function bs_send_birthday_discounts(){
    global $wpdb;
    $today=date('m-d');
    $customers=$wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}bookshop_customers
         WHERE status='active' AND birthday IS NOT NULL
         AND DATE_FORMAT(birthday,'%%m-%%d')=%s AND email!=''",
        $today));
    foreach($customers as $c){
        $code='BDAY-'.strtoupper(substr(md5($c->id.date('Y')),0,6));
        // Create a promo code if it doesn't exist
        $exists=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}bookshop_promotions WHERE code=%s",$code));
        if(!$exists){
            bs_save_promotion([
                'name'      =>"Birthday discount for {$c->name}",
                'code'      =>$code,'type'=>'percent','value'=>10,
                'usage_limit'=>1,'start_date'=>date('Y-m-d'),
                'end_date'  =>date('Y-m-d',strtotime('+7 days')),'status'=>'active',
            ]);
        }
        $shop=get_option('bookshop_receipt_header',get_bloginfo('name'));
        $msg="Happy Birthday {$c->name}! 🎂 As a gift from $shop, here's 10% off your next purchase.\nUse code: $code\nValid for 7 days.";
        wp_mail($c->email,"Happy Birthday from $shop!",$msg);
        // WhatsApp if available
        if($c->phone&&get_option('bookshop_whatsapp')){
            // Store for bulk dispatch
            $wpdb->insert("{$wpdb->prefix}bookshop_messages_queue",[
                'customer_id'=>$c->id,'type'=>'birthday','message'=>$msg,
                'phone'=>$c->phone,'email'=>$c->email,'status'=>'pending',
            ]);
        }
    }
    return count($customers);
}
add_action('bookshop_daily_tasks','bs_send_birthday_discounts');

// Segment customers
function bs_segment_customers($genre='',$days=180,$min_spend=0){
    global $wpdb;
    $since=date('Y-m-d',strtotime("-{$days} days"));
    if($genre){
        return $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT c.* FROM {$wpdb->prefix}bookshop_customers c
             JOIN {$wpdb->prefix}bookshop_sales s ON s.customer_id=c.id
             JOIN {$wpdb->prefix}bookshop_sale_items si ON si.sale_id=s.id
             JOIN {$wpdb->prefix}bookshop_books b ON b.id=si.book_id
             WHERE c.status='active' AND DATE(s.created_at)>=%s AND b.genre=%s",
            $since,$genre));
    }
    return $wpdb->get_results($wpdb->prepare(
        "SELECT c.*, SUM(s.total) AS total_spend FROM {$wpdb->prefix}bookshop_customers c
         JOIN {$wpdb->prefix}bookshop_sales s ON s.customer_id=c.id
         WHERE c.status='active' AND DATE(s.created_at)>=%s
         GROUP BY c.id HAVING total_spend>=%s ORDER BY total_spend DESC",
        $since,floatval($min_spend)));
}

