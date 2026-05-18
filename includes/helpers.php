<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function bs_currency()   { return get_option('bookshop_currency', '₦'); }
function bs_fmt( $n )    { return bs_currency() . number_format( floatval($n), 2 ); }
function bs_tax_mode()   { return get_option('bookshop_tax_mode', 'none'); }
function bs_tax_rate()   { return floatval( get_option('bookshop_tax_rate', 0) ) / 100; }

function bs_calc_tax( $amount ) {
    $mode = bs_tax_mode();
    $rate = bs_tax_rate();
    if ( $mode === 'none' || $rate <= 0 ) return 0;
    if ( $mode === 'inclusive' ) return $amount - ( $amount / (1 + $rate) );
    return $amount * $rate;   // exclusive
}

function bs_user_can_pos( $uid = 0 ) {
    if ( !$uid ) $uid = get_current_user_id();
    if ( !$uid ) return false;
    // Admins always have access
    if ( user_can($uid, 'manage_options') ) return true;
    // Bookshop staff/manager roles
    if ( user_can($uid, 'bookshop_pos') )   return true;
    // Fallback: check if user has bookshop_staff or bookshop_manager role
    $u = new WP_User($uid);
    return in_array('bookshop_staff',   (array)$u->roles)
        || in_array('bookshop_manager', (array)$u->roles);
}
function bs_user_can_manage( $uid = 0 ) {
    if ( !$uid ) $uid = get_current_user_id();
    if ( !$uid ) return false;
    if ( user_can($uid, 'manage_options') )  return true;
    if ( user_can($uid, 'bookshop_manager')) return true;
    $u = new WP_User($uid);
    return in_array('bookshop_manager', (array)$u->roles);
}
function bs_user_is_admin( $uid = 0 ) {
    if ( !$uid ) $uid = get_current_user_id();
    return user_can($uid, 'manage_options');
}

function bs_gen_ref( $prefix = 'BS' ) {
    return $prefix . '-' . strtoupper( substr( md5( uniqid('', true) ), 0, 8 ) );
}

function bs_genres() {
    global $wpdb;
    return $wpdb->get_col("SELECT DISTINCT genre FROM {$wpdb->prefix}bookshop_books WHERE genre!='' ORDER BY genre ASC");
}

function bs_nonce_field( $action ) {
    return wp_nonce_field( $action, '_wpnonce', true, false );
}

function bs_verify( $action ) {
    return check_ajax_referer( $action, 'nonce', false );
}
