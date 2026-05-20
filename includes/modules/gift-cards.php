<?php
if(!defined('ABSPATH'))exit;

/**
 * Gift Cards Module
 *
 * Tables: bookshop_gift_cards, bookshop_gift_card_transactions
 * Features: code generation, balance management, redemption at POS,
 *           online purchase flow, admin management, full audit trail.
 */

// ── Table creation ────────────────────────────────────────────────────────────
function bs_install_gift_card_tables(){
    global $wpdb;
    $c = $wpdb->get_charset_collate();
    require_once ABSPATH.'wp-admin/includes/upgrade.php';

    dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}bookshop_gift_cards (
        id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        code            VARCHAR(30) NOT NULL,
        initial_value   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        balance         DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        currency        VARCHAR(10) NOT NULL DEFAULT '₦',
        purchaser_name  VARCHAR(255) DEFAULT '',
        purchaser_email VARCHAR(255) DEFAULT '',
        purchaser_phone VARCHAR(50) DEFAULT '',
        recipient_name  VARCHAR(255) DEFAULT '',
        recipient_email VARCHAR(255) DEFAULT '',
        recipient_phone VARCHAR(50) DEFAULT '',
        message         TEXT DEFAULT NULL,
        customer_id     BIGINT UNSIGNED DEFAULT NULL,
        sale_id         BIGINT UNSIGNED DEFAULT NULL,
        payment_ref     VARCHAR(100) DEFAULT '',
        payment_gateway VARCHAR(50) DEFAULT '',
        status          ENUM('active','used','expired','cancelled') NOT NULL DEFAULT 'active',
        expires_at      DATE DEFAULT NULL,
        created_by      BIGINT UNSIGNED DEFAULT NULL,
        created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY code (code),
        KEY customer_id (customer_id),
        KEY status (status),
        KEY expires_at (expires_at)
    ) $c;");

    dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}bookshop_gift_card_transactions (
        id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        card_id     BIGINT UNSIGNED NOT NULL,
        type        ENUM('purchase','redeem','refund','adjust','expire') NOT NULL DEFAULT 'redeem',
        amount      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        balance_after DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        sale_id     BIGINT UNSIGNED DEFAULT NULL,
        staff_id    BIGINT UNSIGNED DEFAULT NULL,
        note        VARCHAR(255) DEFAULT '',
        created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY card_id (card_id),
        KEY sale_id (sale_id)
    ) $c;");

    // Default settings
    $defaults = [
        'bookshop_gc_prefix'          => 'GC',
        'bookshop_gc_code_length'     => 12,
        'bookshop_gc_expiry_months'   => 12,
        'bookshop_gc_min_value'       => 500,
        'bookshop_gc_max_value'       => 100000,
        'bookshop_gc_denominations'   => '1000,2000,5000,10000,20000,50000',
        'bookshop_gc_online_enabled'  => '1',
    ];
    foreach($defaults as $k=>$v){
        if(get_option($k) === false) update_option($k, $v);
    }
}
add_action('admin_init', 'bs_install_gift_card_tables');
// Ensure tables exist for POS AJAX paths (admin_init doesn't fire for non-admin requests)
add_action('wp_ajax_bs_pos_check_gc',               'bs_install_gift_card_tables', 1);
add_action('wp_ajax_bs_purchase_gift_card_online',   'bs_install_gift_card_tables', 1);
add_action('wp_ajax_nopriv_bs_purchase_gift_card_online', 'bs_install_gift_card_tables', 1);
add_action('wp_ajax_nopriv_bs_check_gc_balance_public',  'bs_install_gift_card_tables', 1);

// ── Code generation ───────────────────────────────────────────────────────────
/**
 * Generate a unique gift card code.
 * Format: PREFIX-XXXX-XXXX-XXXX (alphanumeric, uppercase, no ambiguous chars)
 */
function bs_generate_gc_code(){
    global $wpdb;
    $prefix = strtoupper(trim(get_option('bookshop_gc_prefix', 'GC')));
    $length = max(8, intval(get_option('bookshop_gc_code_length', 12)));

    // Exclude ambiguous characters: 0, O, I, L, 1
    $chars = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $max_attempts = 20;

    for($i = 0; $i < $max_attempts; $i++){
        $raw = '';
        for($j = 0; $j < $length; $j++){
            $raw .= $chars[random_int(0, strlen($chars) - 1)];
        }
        // Format with dashes every 4 chars
        $formatted = $prefix . '-' . implode('-', str_split($raw, 4));

        // Ensure uniqueness
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}bookshop_gift_cards WHERE code = %s",
            $formatted
        ));
        if(!$exists) return $formatted;
    }

    // Fallback: add timestamp suffix
    return $prefix . '-' . strtoupper(substr(md5(uniqid('', true)), 0, $length));
}

// ── CRUD ──────────────────────────────────────────────────────────────────────

/**
 * Create a gift card.
 */
function bs_create_gift_card($args = []){
    global $wpdb;

    $a = wp_parse_args($args, [
        'value'           => 0,
        'code'            => '',
        'purchaser_name'  => '',
        'purchaser_email' => '',
        'purchaser_phone' => '',
        'recipient_name'  => '',
        'recipient_email' => '',
        'recipient_phone' => '',
        'message'         => '',
        'customer_id'     => 0,
        'sale_id'         => 0,
        'payment_ref'     => '',
        'payment_gateway' => '',
        'created_by'      => get_current_user_id(),
    ]);

    $value = floatval($a['value']);
    if($value <= 0) return ['error' => 'Gift card value must be greater than zero'];

    $min = floatval(get_option('bookshop_gc_min_value', 500));
    $max = floatval(get_option('bookshop_gc_max_value', 100000));
    if($value < $min) return ['error' => 'Minimum gift card value is ' . bs_fmt($min)];
    if($value > $max) return ['error' => 'Maximum gift card value is ' . bs_fmt($max)];

    $code = $a['code'] ?: bs_generate_gc_code();

    // Calculate expiry
    $expiry_months = intval(get_option('bookshop_gc_expiry_months', 12));
    $expires_at = $expiry_months > 0
        ? date('Y-m-d', strtotime("+{$expiry_months} months"))
        : null;

    $wpdb->insert("{$wpdb->prefix}bookshop_gift_cards", [
        'code'            => $code,
        'initial_value'   => $value,
        'balance'         => $value,
        'currency'        => bs_currency(),
        'purchaser_name'  => sanitize_text_field($a['purchaser_name']),
        'purchaser_email' => sanitize_email($a['purchaser_email']),
        'purchaser_phone' => sanitize_text_field($a['purchaser_phone']),
        'recipient_name'  => sanitize_text_field($a['recipient_name']),
        'recipient_email' => sanitize_email($a['recipient_email']),
        'recipient_phone' => sanitize_text_field($a['recipient_phone']),
        'message'         => sanitize_textarea_field($a['message']),
        'customer_id'     => intval($a['customer_id']) ?: null,
        'sale_id'         => intval($a['sale_id']) ?: null,
        'payment_ref'     => sanitize_text_field($a['payment_ref']),
        'payment_gateway' => sanitize_text_field($a['payment_gateway']),
        'status'          => 'active',
        'expires_at'      => $expires_at,
        'created_by'      => intval($a['created_by']),
    ]);

    $card_id = $wpdb->insert_id;
    if(!$card_id) return ['error' => 'Failed to create gift card'];

    // Log initial transaction
    bs_gc_log_transaction($card_id, 'purchase', $value, $value, [
        'sale_id'  => intval($a['sale_id']) ?: null,
        'staff_id' => intval($a['created_by']),
        'note'     => 'Gift card created',
    ]);

    bs_audit('gift_card_created', 'gift_card', $card_id, "Code: $code, Value: " . bs_fmt($value));

    return ['card_id' => $card_id, 'code' => $code, 'value' => $value, 'expires_at' => $expires_at];
}

/**
 * Get a gift card by code or ID.
 */
function bs_get_gift_card($identifier){
    global $wpdb;
    if(is_numeric($identifier)){
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bookshop_gift_cards WHERE id = %d", $identifier
        ));
    }
    $code = strtoupper(trim($identifier));
    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}bookshop_gift_cards WHERE code = %s", $code
    ));
}

/**
 * List gift cards with filters.
 */
function bs_get_gift_cards($args = []){
    global $wpdb;
    $a = wp_parse_args($args, [
        'status'  => '',
        'search'  => '',
        'limit'   => 50,
        'offset'  => 0,
    ]);

    $where = ['1=1'];
    $params = [];

    if($a['status']){
        $where[] = 'gc.status = %s';
        $params[] = $a['status'];
    }
    if($a['search']){
        $where[] = '(gc.code LIKE %s OR gc.purchaser_name LIKE %s OR gc.recipient_name LIKE %s OR gc.purchaser_email LIKE %s)';
        $s = '%' . $wpdb->esc_like($a['search']) . '%';
        $params[] = $s; $params[] = $s; $params[] = $s; $params[] = $s;
    }

    $where_clause = implode(' AND ', $where);

    $count_sql = "SELECT COUNT(*) FROM {$wpdb->prefix}bookshop_gift_cards gc WHERE $where_clause";
    $total = $params
        ? intval($wpdb->get_var($wpdb->prepare($count_sql, ...$params)))
        : intval($wpdb->get_var($count_sql));

    $params[] = intval($a['limit']);
    $params[] = intval($a['offset']);
    $sql = "SELECT gc.* FROM {$wpdb->prefix}bookshop_gift_cards gc
            WHERE $where_clause
            ORDER BY gc.created_at DESC
            LIMIT %d OFFSET %d";
    $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params));

    return ['rows' => $rows, 'total' => $total];
}

// ── Redemption ────────────────────────────────────────────────────────────────

/**
 * Redeem (spend) from a gift card.
 *
 * @param string $code   Gift card code
 * @param float  $amount Amount to redeem
 * @param array  $opts   sale_id, staff_id, note
 * @return array         Success or error
 */
function bs_redeem_gift_card($code, $amount, $opts = []){
    global $wpdb;

    $amount = floatval($amount);
    if($amount <= 0) return ['error' => 'Redemption amount must be positive'];

    $card = bs_get_gift_card($code);
    if(!$card) return ['error' => 'Gift card not found'];
    if($card->status !== 'active') return ['error' => 'Gift card is ' . $card->status];

    // Check expiry
    if($card->expires_at && $card->expires_at < date('Y-m-d')){
        $wpdb->update("{$wpdb->prefix}bookshop_gift_cards",
            ['status' => 'expired'],
            ['id' => $card->id]
        );
        return ['error' => 'Gift card has expired'];
    }

    $balance = floatval($card->balance);
    if($amount > $balance) return ['error' => 'Insufficient balance. Available: ' . bs_fmt($balance)];

    $new_balance = round($balance - $amount, 2);
    $new_status = $new_balance <= 0 ? 'used' : 'active';

    // Atomic update with balance check to prevent races
    $updated = $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->prefix}bookshop_gift_cards
         SET balance = %f, status = %s
         WHERE id = %d AND balance >= %f",
        $new_balance, $new_status, $card->id, $amount
    ));

    if(!$updated){
        return ['error' => 'Balance changed — please try again'];
    }

    $o = wp_parse_args($opts, ['sale_id' => 0, 'staff_id' => get_current_user_id(), 'note' => '']);

    bs_gc_log_transaction($card->id, 'redeem', -$amount, $new_balance, [
        'sale_id'  => intval($o['sale_id']) ?: null,
        'staff_id' => intval($o['staff_id']),
        'note'     => $o['note'] ?: 'Redeemed at POS',
    ]);

    bs_audit('gift_card_redeemed', 'gift_card', $card->id,
        "Code: {$card->code}, Amount: " . bs_fmt($amount) . ", Remaining: " . bs_fmt($new_balance));

    return [
        'success'       => true,
        'card_id'       => $card->id,
        'code'          => $card->code,
        'amount_used'   => $amount,
        'new_balance'   => $new_balance,
        'new_status'    => $new_status,
    ];
}

/**
 * Check balance of a gift card (public-safe).
 */
function bs_check_gc_balance($code){
    $card = bs_get_gift_card($code);
    if(!$card) return ['error' => 'Gift card not found'];

    // Check expiry
    if($card->status === 'active' && $card->expires_at && $card->expires_at < date('Y-m-d')){
        global $wpdb;
        $wpdb->update("{$wpdb->prefix}bookshop_gift_cards",
            ['status' => 'expired'],
            ['id' => $card->id]
        );
        $card->status = 'expired';
        $card->balance = 0;
    }

    return [
        'code'          => $card->code,
        'balance'       => floatval($card->balance),
        'initial_value' => floatval($card->initial_value),
        'status'        => $card->status,
        'expires_at'    => $card->expires_at,
    ];
}

/**
 * Adjust gift card balance (admin only — top-up or correction).
 */
function bs_adjust_gift_card($card_id, $amount, $note = ''){
    global $wpdb;

    $card = bs_get_gift_card($card_id);
    if(!$card) return ['error' => 'Gift card not found'];

    $amount = floatval($amount);
    $new_balance = max(0, round(floatval($card->balance) + $amount, 2));
    $new_status = $new_balance > 0 ? 'active' : 'used';

    $wpdb->update("{$wpdb->prefix}bookshop_gift_cards",
        ['balance' => $new_balance, 'status' => $new_status],
        ['id' => $card->id]
    );

    $type = $amount >= 0 ? 'adjust' : 'adjust';
    bs_gc_log_transaction($card->id, $type, $amount, $new_balance, [
        'staff_id' => get_current_user_id(),
        'note'     => $note ?: 'Manual adjustment',
    ]);

    bs_audit('gift_card_adjusted', 'gift_card', $card->id,
        "Code: {$card->code}, Adjustment: " . ($amount >= 0 ? '+' : '') . bs_fmt($amount) . ", New balance: " . bs_fmt($new_balance));

    return ['success' => true, 'new_balance' => $new_balance];
}

/**
 * Cancel / deactivate a gift card.
 */
function bs_cancel_gift_card($card_id, $reason = ''){
    global $wpdb;

    $card = bs_get_gift_card($card_id);
    if(!$card) return ['error' => 'Gift card not found'];
    if($card->status === 'cancelled') return ['error' => 'Already cancelled'];

    $wpdb->update("{$wpdb->prefix}bookshop_gift_cards",
        ['status' => 'cancelled', 'balance' => 0],
        ['id' => $card->id]
    );

    bs_gc_log_transaction($card->id, 'adjust', -floatval($card->balance), 0, [
        'staff_id' => get_current_user_id(),
        'note'     => 'Cancelled: ' . ($reason ?: 'No reason given'),
    ]);

    bs_audit('gift_card_cancelled', 'gift_card', $card->id,
        "Code: {$card->code}, Reason: " . ($reason ?: 'No reason'));

    return ['success' => true];
}

// ── Transaction logging ───────────────────────────────────────────────────────
function bs_gc_log_transaction($card_id, $type, $amount, $balance_after, $opts = []){
    global $wpdb;
    $wpdb->insert("{$wpdb->prefix}bookshop_gift_card_transactions", [
        'card_id'       => intval($card_id),
        'type'          => $type,
        'amount'        => floatval($amount),
        'balance_after' => floatval($balance_after),
        'sale_id'       => $opts['sale_id'] ?? null,
        'staff_id'      => $opts['staff_id'] ?? get_current_user_id(),
        'note'          => sanitize_text_field($opts['note'] ?? ''),
    ]);
}

/**
 * Get transaction history for a gift card.
 */
function bs_get_gc_transactions($card_id, $limit = 50){
    global $wpdb;
    return $wpdb->get_results($wpdb->prepare(
        "SELECT t.*, u.display_name AS staff_name
         FROM {$wpdb->prefix}bookshop_gift_card_transactions t
         LEFT JOIN {$wpdb->users} u ON u.ID = t.staff_id
         WHERE t.card_id = %d
         ORDER BY t.created_at DESC
         LIMIT %d",
        intval($card_id), intval($limit)
    ));
}

// ── Expiry cron ───────────────────────────────────────────────────────────────
add_action('bookshop_daily_tasks', 'bs_expire_gift_cards');
function bs_expire_gift_cards(){
    global $wpdb;
    $today = date('Y-m-d');
    $expired = $wpdb->get_results($wpdb->prepare(
        "SELECT id, code, balance FROM {$wpdb->prefix}bookshop_gift_cards
         WHERE status = 'active' AND expires_at IS NOT NULL AND expires_at < %s",
        $today
    ));
    foreach($expired as $card){
        $wpdb->update("{$wpdb->prefix}bookshop_gift_cards",
            ['status' => 'expired'],
            ['id' => $card->id]
        );
        if(floatval($card->balance) > 0){
            bs_gc_log_transaction($card->id, 'expire', -floatval($card->balance), 0, [
                'staff_id' => 0,
                'note'     => 'Auto-expired',
            ]);
        }
        bs_audit('gift_card_expired', 'gift_card', $card->id, "Code: {$card->code}, Forfeited balance: " . bs_fmt($card->balance));
    }
}

// ── Summary stats ─────────────────────────────────────────────────────────────
function bs_gc_summary(){
    global $wpdb;
    $t = $wpdb->prefix . 'bookshop_gift_cards';
    return (object)[
        'total_cards'       => intval($wpdb->get_var("SELECT COUNT(*) FROM $t")),
        'active_cards'      => intval($wpdb->get_var("SELECT COUNT(*) FROM $t WHERE status='active'")),
        'total_issued'      => floatval($wpdb->get_var("SELECT COALESCE(SUM(initial_value),0) FROM $t") ?: 0),
        'outstanding_balance' => floatval($wpdb->get_var("SELECT COALESCE(SUM(balance),0) FROM $t WHERE status='active'") ?: 0),
        'total_redeemed'    => floatval($wpdb->get_var("SELECT COALESCE(SUM(initial_value - balance),0) FROM $t WHERE status IN ('active','used')") ?: 0),
        'used_cards'        => intval($wpdb->get_var("SELECT COUNT(*) FROM $t WHERE status='used'")),
        'expired_cards'     => intval($wpdb->get_var("SELECT COUNT(*) FROM $t WHERE status='expired'")),
    ];
}
