<?php
if(!defined('ABSPATH'))exit;

/**
 * Gift Card AJAX Endpoints
 *
 * Admin endpoints: create, adjust, cancel, history, settings
 * POS endpoints: check balance, redeem (hooked into sale submission)
 * Public endpoints: purchase online, check balance
 */

// ═══════════════════════════════════════════════════════════════════════════════
// ADMIN ENDPOINTS
// ═══════════════════════════════════════════════════════════════════════════════

// ── Admin: Create gift card ───────────────────────────────────────────────────
add_action('wp_ajax_bs_admin_create_gift_card', function(){
    if(!bs_user_can_manage()) wp_send_json_error('Unauthorized', 403);
    if(!bs_verify('bs_admin_nonce')) wp_send_json_error('Bad nonce');

    $result = bs_create_gift_card([
        'value'           => floatval($_POST['value'] ?? 0),
        'purchaser_name'  => sanitize_text_field($_POST['purchaser_name'] ?? ''),
        'purchaser_email' => sanitize_email($_POST['purchaser_email'] ?? ''),
        'recipient_name'  => sanitize_text_field($_POST['recipient_name'] ?? ''),
        'recipient_email' => sanitize_email($_POST['recipient_email'] ?? ''),
        'message'         => sanitize_textarea_field($_POST['message'] ?? ''),
    ]);

    if(!empty($result['error'])) wp_send_json_error($result['error']);
    wp_send_json_success($result);
});

// ── Admin: Get gift card transaction history ──────────────────────────────────
add_action('wp_ajax_bs_get_gc_history', function(){
    if(!bs_user_can_manage()) wp_send_json_error('Unauthorized', 403);

    $card_id = intval($_GET['id'] ?? 0);
    if(!$card_id) wp_send_json_error('Missing card ID');

    $txns = bs_get_gc_transactions($card_id);
    wp_send_json_success($txns);
});

// ── Admin: Adjust gift card balance ───────────────────────────────────────────
add_action('wp_ajax_bs_admin_adjust_gc', function(){
    if(!bs_user_can_manage()) wp_send_json_error('Unauthorized', 403);
    if(!bs_verify('bs_admin_nonce')) wp_send_json_error('Bad nonce');

    $card_id = intval($_POST['id'] ?? 0);
    $amount  = floatval($_POST['amount'] ?? 0);
    $note    = sanitize_text_field($_POST['note'] ?? '');

    if(!$card_id) wp_send_json_error('Missing card ID');
    if($amount == 0) wp_send_json_error('Amount cannot be zero');

    $result = bs_adjust_gift_card($card_id, $amount, $note);
    if(!empty($result['error'])) wp_send_json_error($result['error']);
    wp_send_json_success($result);
});

// ── Admin: Cancel gift card ───────────────────────────────────────────────────
add_action('wp_ajax_bs_admin_cancel_gc', function(){
    if(!bs_user_can_manage()) wp_send_json_error('Unauthorized', 403);
    if(!bs_verify('bs_admin_nonce')) wp_send_json_error('Bad nonce');

    $card_id = intval($_POST['id'] ?? 0);
    $reason  = sanitize_text_field($_POST['reason'] ?? '');

    if(!$card_id) wp_send_json_error('Missing card ID');

    $result = bs_cancel_gift_card($card_id, $reason);
    if(!empty($result['error'])) wp_send_json_error($result['error']);
    wp_send_json_success($result);
});

// ── Admin: Save gift card settings ────────────────────────────────────────────
add_action('wp_ajax_bs_save_gc_settings', function(){
    if(!current_user_can('manage_options')) wp_send_json_error('Unauthorized', 403);
    if(!bs_verify('bs_admin_nonce')) wp_send_json_error('Bad nonce');

    update_option('bookshop_gc_prefix', strtoupper(sanitize_text_field($_POST['prefix'] ?? 'GC')));
    update_option('bookshop_gc_code_length', max(8, intval($_POST['code_length'] ?? 12)));
    update_option('bookshop_gc_expiry_months', max(0, intval($_POST['expiry_months'] ?? 12)));
    update_option('bookshop_gc_min_value', max(0, floatval($_POST['min_value'] ?? 500)));
    update_option('bookshop_gc_max_value', max(0, floatval($_POST['max_value'] ?? 100000)));
    update_option('bookshop_gc_denominations', sanitize_text_field($_POST['denominations'] ?? ''));
    update_option('bookshop_gc_online_enabled', ($_POST['online_enabled'] ?? '0') === '1' ? '1' : '0');

    bs_audit('gc_settings_updated', 'settings', 0, 'Gift card settings updated');
    wp_send_json_success(['message' => 'Settings saved']);
});

// ═══════════════════════════════════════════════════════════════════════════════
// POS ENDPOINTS
// ═══════════════════════════════════════════════════════════════════════════════

// ── POS: Check gift card balance ──────────────────────────────────────────────
add_action('wp_ajax_bs_pos_check_gc', function(){
    if(!bs_user_can_pos()) wp_send_json_error('Unauthorized', 403);

    $code = strtoupper(trim(sanitize_text_field($_POST['code'] ?? $_GET['code'] ?? '')));
    if(!$code) wp_send_json_error('Enter a gift card code');

    $result = bs_check_gc_balance($code);
    if(!empty($result['error'])) wp_send_json_error($result['error']);
    wp_send_json_success($result);
});

// ── POS: Gift card redemption hook ────────────────────────────────────────────
// After a sale is successfully created with payment_method='gift_card',
// redeem the gift card balance. This hooks into the sale submission flow.
add_action('wp_ajax_bs_submit_sale', 'bs_handle_gc_redemption_after_sale', 20);
function bs_handle_gc_redemption_after_sale(){
    // This runs AFTER the main bs_submit_sale handler (priority 20 vs default 10).
    // We check if the last JSON response was a success with gift_card payment.
    // Actually, we need to hook BEFORE the response is sent. Let's use a filter instead.
}
// Remove the above and use a proper integration point:
remove_action('wp_ajax_bs_submit_sale', 'bs_handle_gc_redemption_after_sale', 20);

/**
 * Hook into the sale creation to handle gift card payment.
 * This is called from the bs_submit_sale AJAX handler after the sale is created.
 * We add a filter that the existing handler checks.
 */
add_action('wp_ajax_bs_redeem_gc_for_sale', function(){
    if(!bs_user_can_pos()) wp_send_json_error('Unauthorized', 403);
    if(!bs_verify('bs_pos_nonce')) wp_send_json_error('Bad nonce');

    $code    = strtoupper(trim(sanitize_text_field($_POST['gc_code'] ?? '')));
    $amount  = floatval($_POST['amount'] ?? 0);
    $sale_id = intval($_POST['sale_id'] ?? 0);

    if(!$code) wp_send_json_error('Missing gift card code');
    if($amount <= 0) wp_send_json_error('Invalid amount');

    $result = bs_redeem_gift_card($code, $amount, [
        'sale_id'  => $sale_id,
        'staff_id' => get_current_user_id(),
        'note'     => $sale_id ? "Redeemed for sale #$sale_id" : 'POS redemption',
    ]);

    if(!empty($result['error'])) wp_send_json_error(['code'=>'gc_insufficient','message'=>$result['error']]);
    wp_send_json_success($result);
});

/**
 * Intercept gift card payment in the main sale submission.
 * We hook at priority 5 (before the main handler at 10) to validate the GC
 * first, then after the sale succeeds we redeem it.
 */
add_action('wp_ajax_bs_submit_sale', function(){
    if(empty($_POST['payment']) || $_POST['payment'] !== 'gift_card') return;

    $pay_det = json_decode(stripslashes($_POST['payment_details'] ?? '{}'), true);
    $gc_code = strtoupper(trim($pay_det['gc_code'] ?? ''));

    if(!$gc_code){
        wp_send_json_error(['code'=>'gc_missing','message'=>'Enter a gift card code']);
    }

    // Pre-validate the gift card exists and is active
    $card = bs_get_gift_card($gc_code);
    if(!$card){
        wp_send_json_error(['code'=>'gc_invalid','message'=>'Gift card not found']);
    }
    if($card->status !== 'active'){
        wp_send_json_error(['code'=>'gc_invalid','message'=>'Gift card is '.$card->status]);
    }
    if($card->expires_at && $card->expires_at < date('Y-m-d')){
        wp_send_json_error(['code'=>'gc_invalid','message'=>'Gift card has expired']);
    }

    // Calculate the sale total to check if GC has sufficient balance
    $cart = json_decode(stripslashes($_POST['cart'] ?? '[]'), true);
    $discount = floatval($_POST['discount'] ?? 0);
    $subtotal = array_sum(array_map(function($i){ return floatval($i['price'])*intval($i['qty']); }, $cart));
    $net = $subtotal - $discount; // approximate — server will compute exact total

    if(floatval($card->balance) < $net * 0.01){
        // Balance is practically zero
        wp_send_json_error(['code'=>'gc_insufficient','message'=>'Gift card balance is '.bs_fmt($card->balance).'. Insufficient for this sale.']);
    }

    // Store validated card info in a global for the post-sale hook
    $GLOBALS['bs_gc_pending'] = [
        'code'    => $gc_code,
        'card_id' => $card->id,
        'balance' => floatval($card->balance),
    ];
}, 5);

/**
 * After sale is successfully created with gift_card payment, redeem the card.
 * This hooks into the sale creation result.
 */
add_filter('bs_after_sale_created', function($result) {
    if(empty($GLOBALS['bs_gc_pending'])) return $result;
    if(!empty($result['error'])) return $result; // Sale failed, don't redeem

    $gc = $GLOBALS['bs_gc_pending'];
    $total = floatval($result['total']);
    $sale_id = intval($result['sale_id']);

    // Redeem the exact sale total (or card balance if less)
    $redeem_amount = min($total, $gc['balance']);

    $redeem_result = bs_redeem_gift_card($gc['code'], $redeem_amount, [
        'sale_id'  => $sale_id,
        'staff_id' => get_current_user_id(),
        'note'     => "Sale {$result['ref']}",
    ]);

    if(!empty($redeem_result['error'])){
        // Sale was created but GC redemption failed — log it but don't fail the sale
        bs_audit('gc_redeem_failed', 'gift_card', $gc['card_id'],
            "Failed to redeem for sale $sale_id: ".$redeem_result['error']);
    }

    // Attach GC info to the sale result for the receipt
    $result['gc_redeemed'] = $redeem_amount;
    $result['gc_code']     = $gc['code'];
    $result['gc_remaining'] = $redeem_result['new_balance'] ?? 0;

    unset($GLOBALS['bs_gc_pending']);
    return $result;
});

// ═══════════════════════════════════════════════════════════════════════════════
// PUBLIC ENDPOINTS (for online purchase and balance check)
// ═══════════════════════════════════════════════════════════════════════════════

// ── Public: Check gift card balance ───────────────────────────────────────────
add_action('wp_ajax_bs_check_gc_balance_public', 'bs_ajax_check_gc_balance_public');
add_action('wp_ajax_nopriv_bs_check_gc_balance_public', 'bs_ajax_check_gc_balance_public');
function bs_ajax_check_gc_balance_public(){
    if(!wp_verify_nonce($_POST['nonce'] ?? '', 'bs_gc_purchase_nonce')){
        wp_send_json_error('Invalid request');
    }

    $code = strtoupper(trim(sanitize_text_field($_POST['code'] ?? '')));
    if(!$code) wp_send_json_error('Enter a gift card code');

    $result = bs_check_gc_balance($code);
    if(!empty($result['error'])) wp_send_json_error($result['error']);
    wp_send_json_success($result);
}

// ── Public: Purchase gift card online ─────────────────────────────────────────
add_action('wp_ajax_bs_purchase_gift_card_online', 'bs_ajax_purchase_gc_online');
add_action('wp_ajax_nopriv_bs_purchase_gift_card_online', 'bs_ajax_purchase_gc_online');
function bs_ajax_purchase_gc_online(){
    if(!wp_verify_nonce($_POST['nonce'] ?? '', 'bs_gc_purchase_nonce')){
        wp_send_json_error('Invalid request');
    }

    if(get_option('bookshop_gc_online_enabled', '1') !== '1'){
        wp_send_json_error('Online gift card purchases are currently disabled');
    }

    $value = floatval($_POST['amount'] ?? 0);
    if($value <= 0) wp_send_json_error('Invalid amount');

    $min = floatval(get_option('bookshop_gc_min_value', 500));
    $max = floatval(get_option('bookshop_gc_max_value', 100000));
    if($value < $min) wp_send_json_error('Minimum value is ' . bs_fmt($min));
    if($value > $max) wp_send_json_error('Maximum value is ' . bs_fmt($max));

    $recipient_name  = sanitize_text_field($_POST['recipient_name'] ?? '');
    $recipient_email = sanitize_email($_POST['recipient_email'] ?? '');
    $purchaser_name  = sanitize_text_field($_POST['purchaser_name'] ?? '');
    $purchaser_email = sanitize_email($_POST['purchaser_email'] ?? '');
    $purchaser_phone = sanitize_text_field($_POST['purchaser_phone'] ?? '');

    if(!$recipient_name || !$recipient_email){
        wp_send_json_error('Recipient name and email are required');
    }
    if(!$purchaser_name || !$purchaser_email || !$purchaser_phone){
        wp_send_json_error('Your name, email, and phone are required');
    }

    // Create the gift card
    $result = bs_create_gift_card([
        'value'           => $value,
        'purchaser_name'  => $purchaser_name,
        'purchaser_email' => $purchaser_email,
        'purchaser_phone' => $purchaser_phone,
        'recipient_name'  => $recipient_name,
        'recipient_email' => $recipient_email,
        'recipient_phone' => sanitize_text_field($_POST['recipient_phone'] ?? ''),
        'message'         => sanitize_textarea_field($_POST['message'] ?? ''),
        'payment_gateway' => 'online',
        'created_by'      => get_current_user_id() ?: 0,
    ]);

    if(!empty($result['error'])) wp_send_json_error($result['error']);

    // Send notification email to recipient
    $shop_name = get_option('bookshop_receipt_header', get_bloginfo('name'));
    $subject = "You've received a {$shop_name} Gift Card!";
    $message = sprintf(
        "Hi %s,\n\n%s has sent you a %s gift card from %s!\n\n" .
        "Your Gift Card Code: %s\n" .
        "Value: %s%s\n\n" .
        "%s\n\n" .
        "To redeem, present this code at any %s location or use it at checkout online.\n\n" .
        "This card expires on: %s\n\n" .
        "Enjoy your books!\n%s",
        $recipient_name,
        $purchaser_name,
        bs_fmt($value),
        $shop_name,
        $result['code'],
        bs_currency(),
        number_format($value, 2),
        $_POST['message'] ? "Message from {$purchaser_name}: \"" . sanitize_textarea_field($_POST['message']) . "\"" : '',
        $shop_name,
        $result['expires_at'] ?: 'Never',
        $shop_name
    );

    wp_mail($recipient_email, $subject, $message);

    // Also send confirmation to purchaser
    $confirm_subject = "Your {$shop_name} Gift Card Purchase Confirmation";
    $confirm_message = sprintf(
        "Hi %s,\n\n" .
        "Thank you for purchasing a gift card from %s!\n\n" .
        "Gift Card Code: %s\n" .
        "Value: %s\n" .
        "Recipient: %s (%s)\n" .
        "Expires: %s\n\n" .
        "The gift card has been emailed to the recipient.\n\n" .
        "Thank you!\n%s",
        $purchaser_name,
        $shop_name,
        $result['code'],
        bs_fmt($value),
        $recipient_name,
        $recipient_email,
        $result['expires_at'] ?: 'Never',
        $shop_name
    );

    wp_mail($purchaser_email, $confirm_subject, $confirm_message);

    bs_audit('gift_card_purchased_online', 'gift_card', $result['card_id'],
        "Online purchase by {$purchaser_name} ({$purchaser_email}), Value: " . bs_fmt($value));

    wp_send_json_success([
        'code'  => $result['code'],
        'value' => $value,
    ]);
}
