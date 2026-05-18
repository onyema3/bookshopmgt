<?php
/**
 * Loyalty Points Expiry
 * Points expire after N months of inactivity (configurable)
 */
if(!defined('ABSPATH'))exit;

function bs_expire_loyalty_points(){
    $months=intval(get_option('bookshop_loyalty_expiry_months',0));
    if($months<=0) return 0; // 0 = never expire
    global $wpdb;
    $cutoff=date('Y-m-d',strtotime("-{$months} months"));
    // Find customers whose last activity (sale or loyalty log) is before cutoff
    $customers=$wpdb->get_results($wpdb->prepare(
        "SELECT c.id,c.name,c.email,c.loyalty_points,
                MAX(COALESCE(s.created_at,'1970-01-01')) AS last_sale,
                MAX(COALESCE(ll.created_at,'1970-01-01')) AS last_loyalty
         FROM {$wpdb->prefix}bookshop_customers c
         LEFT JOIN {$wpdb->prefix}bookshop_sales s ON s.customer_id=c.id AND s.status='completed'
         LEFT JOIN {$wpdb->prefix}bookshop_loyalty_log ll ON ll.customer_id=c.id
         WHERE c.loyalty_points>0 AND c.status='active'
         GROUP BY c.id
         HAVING GREATEST(last_sale,last_loyalty) < %s",
        $cutoff
    ));
    $expired=0;
    foreach($customers as $c){
        if($c->loyalty_points<=0) continue;
        $wpdb->update("{$wpdb->prefix}bookshop_customers",['loyalty_points'=>0],['id'=>$c->id]);
        bs_log_loyalty($c->id,null,-$c->loyalty_points,'expired',"Points expired after {$months} months of inactivity");
        // Notify customer
        if($c->email){
            $shop=get_option('bookshop_receipt_header',get_bloginfo('name'));
            wp_mail($c->email,"Your loyalty points have expired — $shop",
                "<p>Hi {$c->name},</p><p>Your {$c->loyalty_points} loyalty points at $shop have expired due to {$months} months of inactivity. Visit us soon to earn new points!</p>",
                ['Content-Type: text/html; charset=UTF-8']);
        }
        $expired++;
    }
    return $expired;
}
add_action('bookshop_daily_tasks','bs_expire_loyalty_points');

add_action('wp_ajax_bs_run_loyalty_expiry',function(){
    if(!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
    $count=bs_expire_loyalty_points();
    wp_send_json_success(['expired'=>$count,'message'=>"Expired points for $count customers"]);
});
