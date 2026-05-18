<?php
if(!defined('ABSPATH'))exit;

// ── Search customers ──────────────────────────────────────────────────────────
add_action('wp_ajax_bs_search_customers',function(){
    if(!bs_user_can_pos()) wp_send_json_error('Unauthorized',403);
    $q=sanitize_text_field($_GET['q']??'');
    wp_send_json_success(bs_search_customer($q));
});

// ── Get single customer ───────────────────────────────────────────────────────
add_action('wp_ajax_bs_get_customer',function(){
    if(!bs_user_can_pos()) wp_send_json_error('Unauthorized',403);
    $c=bs_get_customer(intval($_GET['id']??0));
    $c ? wp_send_json_success($c) : wp_send_json_error('Not found');
});

// ── Save customer (admin) ─────────────────────────────────────────────────────
add_action('wp_ajax_bs_save_customer',function(){
    if(!current_user_can('manage_options')&&!bs_user_can_pos()) wp_send_json_error('Unauthorized',403);
    $id=intval($_POST['id']??0);
    $res=bs_save_customer($_POST,$id);
    $res ? wp_send_json_success(['id'=>$res]) : wp_send_json_error('Could not save customer');
});

// ── Quick add customer from POS ───────────────────────────────────────────────
// No nonce check — capability check is the security layer for logged-in POS users
add_action('wp_ajax_bs_quick_add_customer',function(){
    if(!bs_user_can_pos()) wp_send_json_error('Unauthorized',403);

    $name  = sanitize_text_field($_POST['name']  ?? '');
    $phone = sanitize_text_field($_POST['phone'] ?? '');
    $email = sanitize_email(     $_POST['email'] ?? '');

    if(empty($name))  wp_send_json_error('Name is required');
    if(empty($phone)) wp_send_json_error('Phone is required');

    $id = bs_save_customer([
        'name'   => $name,
        'phone'  => $phone,
        'email'  => $email,
        'status' => 'active',
    ]);

    if(!$id) wp_send_json_error('Database error saving customer');

    $c = bs_get_customer($id);
    if(!$c) wp_send_json_error('Customer saved but could not retrieve — please search for them');

    wp_send_json_success($c);
});

// ── Customer loyalty info ─────────────────────────────────────────────────────
add_action('wp_ajax_bs_get_customer_loyalty',function(){
    if(!bs_user_can_pos()) wp_send_json_error('Unauthorized',403);
    $c=bs_get_customer(intval($_GET['id']??0));
    if(!$c) wp_send_json_error('Not found');
    $val=floatval(get_option('bookshop_loyalty_value',10));
    wp_send_json_success([
        'points'          => intval($c->loyalty_points),
        'credit'          => floatval($c->credit_balance),
        'point_value'     => $val,
        'max_redeem_value'=> $c->loyalty_points*$val,
    ]);
});

// ── Add reservation ───────────────────────────────────────────────────────────
add_action('wp_ajax_bs_add_reservation',function(){
    global $wpdb;
    $d=[
        'customer_name'  => sanitize_text_field($_POST['name']??''),
        'customer_email' => sanitize_email($_POST['email']??''),
        'customer_phone' => sanitize_text_field($_POST['phone']??''),
        'book_title'     => sanitize_text_field($_POST['book_title']??''),
        'isbn'           => sanitize_text_field($_POST['isbn']??''),
        'qty'            => intval($_POST['qty']??1),
        'notes'          => sanitize_textarea_field($_POST['notes']??''),
        'status'         => 'pending',
    ];
    $wpdb->insert("{$wpdb->prefix}bookshop_reservations",$d);
    bs_send_reservation_notification($d);
    wp_send_json_success(['id'=>$wpdb->insert_id]);
});
add_action('wp_ajax_nopriv_bs_add_reservation','wp_ajax_bs_add_reservation');

// ── Customer purchase history (for admin panel) ───────────────────────────────
add_action('wp_ajax_bs_get_cust_history',function(){
    if(!current_user_can('manage_options')) wp_send_json_error('Unauthorized',403);
    $id=intval($_GET['id']??0);
    $sales=bs_get_sales(['customer_id'=>$id,'limit'=>100]);
    wp_send_json_success($sales);
});
