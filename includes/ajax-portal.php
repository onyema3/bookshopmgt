<?php
/**
 * Customer Portal AJAX Handlers
 */
if(!defined('ABSPATH'))exit;

// ── Login — find customer by phone or email ────────────────────────────────────
add_action('wp_ajax_nopriv_bs_portal_login','bs_portal_login_handler');
add_action('wp_ajax_bs_portal_login','bs_portal_login_handler');
function bs_portal_login_handler(){
    if(!check_ajax_referer('bs_portal_nonce','nonce',false)) wp_send_json_error('Invalid request');
    $identifier=sanitize_text_field($_POST['identifier']??'');
    if(empty($identifier)) wp_send_json_error('Please enter your phone or email');
    global $wpdb;
    // Search by phone or email
    $customer=$wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}bookshop_customers
         WHERE status='active' AND (phone=%s OR email=%s) LIMIT 1",
        $identifier, $identifier
    ));
    if(!$customer) wp_send_json_error('No account found with that phone or email. Please ask staff to register you in-store.');
    // Store in transient keyed by session
    if(!session_id() && !headers_sent()) @session_start();
    set_transient('bs_portal_customer_'.session_id(), $customer->id, HOUR_IN_SECONDS*8);
    wp_send_json_success(['name'=>$customer->name,'id'=>$customer->id]);
}

// ── Logout ────────────────────────────────────────────────────────────────────
add_action('wp_ajax_nopriv_bs_portal_logout','bs_portal_logout_handler');
add_action('wp_ajax_bs_portal_logout','bs_portal_logout_handler');
function bs_portal_logout_handler(){
    if(!session_id() && !headers_sent()) @session_start();
    delete_transient('bs_portal_customer_'.session_id());
    wp_send_json_success();
}

// ── Get customer data (AJAX refresh) ─────────────────────────────────────────
add_action('wp_ajax_nopriv_bs_portal_get_data','bs_portal_get_data_handler');
add_action('wp_ajax_bs_portal_get_data','bs_portal_get_data_handler');
function bs_portal_get_data_handler(){
    if(!check_ajax_referer('bs_portal_nonce','nonce',false)) wp_send_json_error('Invalid request');
    if(!session_id() && !headers_sent()) @session_start();
    $cid=intval(get_transient('bs_portal_customer_'.session_id()));
    if(!$cid) wp_send_json_error('Not logged in');
    global $wpdb;
    $customer=bs_get_customer($cid);
    if(!$customer) wp_send_json_error('Account not found');
    $tier=bs_get_customer_tier($cid);
    $loy_val=floatval(get_option('bookshop_loyalty_value',10));
    $sales=bs_get_sales(['customer_id'=>$cid,'limit'=>50]);
    $reservations=$wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}bookshop_reservations
         WHERE customer_email=%s OR customer_phone=%s
         ORDER BY created_at DESC LIMIT 20",
        $customer->email,$customer->phone
    ));
    $loy_log=bs_get_loyalty_log($cid);
    $total_spent=floatval($wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(SUM(total),0) FROM {$wpdb->prefix}bookshop_sales
         WHERE customer_id=%d AND status='completed'",$cid
    )));
    $tiers=bs_get_tiers();
    $tier_keys=array_keys($tiers);
    $current_idx=array_search($tier['key'],$tier_keys);
    $next_tier=isset($tier_keys[$current_idx+1])?$tiers[$tier_keys[$current_idx+1]]:null;
    $progress=0;
    if($next_tier){
        $range=$next_tier['min_spend']-$tier['min_spend'];
        $done=$total_spent-$tier['min_spend'];
        $progress=$range>0?min(100,round(($done/$range)*100)):100;
    }
    // Enrich sales with items
    $sales_enriched=[];
    foreach($sales as $s){
        $s->items=bs_get_sale_items($s->id);
        $sales_enriched[]=$s;
    }
    wp_send_json_success([
        'customer'     =>$customer,
        'tier'         =>$tier,
        'total_spent'  =>$total_spent,
        'next_tier'    =>$next_tier,
        'tier_progress'=>$progress,
        'loyalty_value'=>$loy_val,
        'sales'        =>$sales_enriched,
        'reservations' =>$reservations,
        'loyalty_log'  =>$loy_log,
        'currency'     =>bs_currency(),
    ]);
}

// ── Update profile ─────────────────────────────────────────────────────────────
add_action('wp_ajax_nopriv_bs_portal_update_profile','bs_portal_update_profile_handler');
add_action('wp_ajax_bs_portal_update_profile','bs_portal_update_profile_handler');
function bs_portal_update_profile_handler(){
    if(!check_ajax_referer('bs_portal_nonce','nonce',false)) wp_send_json_error('Invalid request');
    if(!session_id() && !headers_sent()) @session_start();
    $cid=intval(get_transient('bs_portal_customer_'.session_id()));
    if(!$cid) wp_send_json_error('Not logged in');
    $name=sanitize_text_field($_POST['name']??'');
    if(empty($name)) wp_send_json_error('Name is required');
    bs_save_customer([
        'name'    =>$name,
        'email'   =>sanitize_email($_POST['email']??''),
        'phone'   =>sanitize_text_field($_POST['phone']??''),
        'address' =>sanitize_textarea_field($_POST['address']??''),
        'birthday'=>sanitize_text_field($_POST['birthday']??''),
        'status'  =>'active',
    ],$cid);
    wp_send_json_success(['message'=>'Profile updated successfully!']);
}

// ── Submit reservation from portal ────────────────────────────────────────────
add_action('wp_ajax_nopriv_bs_portal_reserve','bs_portal_reserve_handler');
add_action('wp_ajax_bs_portal_reserve','bs_portal_reserve_handler');
function bs_portal_reserve_handler(){
    if(!check_ajax_referer('bs_portal_nonce','nonce',false)) wp_send_json_error('Invalid request');
    if(!session_id() && !headers_sent()) @session_start();
    $cid=intval(get_transient('bs_portal_customer_'.session_id()));
    if(!$cid) wp_send_json_error('Not logged in');
    $customer=bs_get_customer($cid);
    if(!$customer) wp_send_json_error('Account not found');
    $title=sanitize_text_field($_POST['title']??'');
    if(empty($title)) wp_send_json_error('Book title is required');
    global $wpdb;
    $wpdb->insert("{$wpdb->prefix}bookshop_reservations",[
        'customer_name' =>$customer->name,
        'customer_email'=>$customer->email,
        'customer_phone'=>$customer->phone,
        'book_title'    =>$title,
        'isbn'          =>sanitize_text_field($_POST['isbn']??''),
        'qty'           =>intval($_POST['qty']??1),
        'notes'         =>sanitize_textarea_field($_POST['notes']??''),
        'status'        =>'pending',
    ]);
    bs_send_reservation_notification([
        'name'=>$customer->name,'email'=>$customer->email,'phone'=>$customer->phone,
        'book_title'=>$title,'isbn'=>$_POST['isbn']??'','qty'=>$_POST['qty']??1,
    ]);
    wp_send_json_success(['message'=>'Reservation submitted! We\'ll contact you when your book is ready.']);
}
