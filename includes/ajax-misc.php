<?php
if(!defined('ABSPATH'))exit;

// Suppliers
add_action('wp_ajax_bs_save_supplier',function(){
    if(!bs_user_can_manage()) wp_send_json_error('Unauthorized',403);
    if(!bs_verify('bs_admin_nonce')) wp_send_json_error('Bad nonce');
    $id=intval($_POST['id']??0);
    wp_send_json_success(['id'=>bs_save_supplier($_POST,$id)]);
});
add_action('wp_ajax_bs_get_supplier',function(){
    if(!bs_user_can_manage()) wp_send_json_error('Unauthorized',403);
    $s=bs_get_supplier(intval($_GET['id']));
    $s ? wp_send_json_success($s) : wp_send_json_error('Not found');
});

// Purchase Orders
add_action('wp_ajax_bs_create_po',function(){
    if(!bs_user_can_manage()) wp_send_json_error('Unauthorized',403);
    if(!bs_verify('bs_admin_nonce')) wp_send_json_error('Bad nonce');
    $items=json_decode(stripslashes($_POST['items']??'[]'),true);
    $id=bs_create_po(intval($_POST['supplier_id']??0),$items,get_current_user_id(),sanitize_textarea_field($_POST['notes']??''));
    wp_send_json_success(['id'=>$id]);
});
add_action('wp_ajax_bs_receive_po',function(){
    if(!bs_user_can_manage()) wp_send_json_error('Unauthorized',403);
    if(!bs_verify('bs_admin_nonce')) wp_send_json_error('Bad nonce');
    $received=json_decode(stripslashes($_POST['received']??'{}'),true);
    bs_receive_po(intval($_POST['po_id']),$received);
    wp_send_json_success();
});
add_action('wp_ajax_bs_get_po_items',function(){
    if(!bs_user_can_manage()) wp_send_json_error('Unauthorized',403);
    wp_send_json_success(bs_get_po_items(intval($_GET['id'])));
});

// Promotions
add_action('wp_ajax_bs_save_promotion',function(){
    if(!bs_user_can_manage()) wp_send_json_error('Unauthorized',403);
    if(!bs_verify('bs_admin_nonce')) wp_send_json_error('Bad nonce');
    $id=intval($_POST['id']??0);
    wp_send_json_success(['id'=>bs_save_promotion($_POST,$id)]);
});
add_action('wp_ajax_bs_get_promotion',function(){
    if(!bs_user_can_manage()) wp_send_json_error('Unauthorized',403);
    $p=bs_get_promotion(intval($_GET['id']));
    $p ? wp_send_json_success($p) : wp_send_json_error('Not found');
});
add_action('wp_ajax_bs_delete_promotion',function(){
    global $wpdb;
    if(!bs_user_can_manage()) wp_send_json_error('Unauthorized',403);
    if(!bs_verify('bs_admin_nonce')) wp_send_json_error('Bad nonce');
    $wpdb->update("{$wpdb->prefix}bookshop_promotions",['status'=>'inactive'],['id'=>intval($_POST['id'])]);
    wp_send_json_success();
});

// Reservations
add_action('wp_ajax_bs_update_reservation',function(){
    global $wpdb;
    if(!bs_user_can_manage()) wp_send_json_error('Unauthorized',403);
    if(!bs_verify('bs_admin_nonce')) wp_send_json_error('Bad nonce');
    $status=sanitize_text_field($_POST['status']??'');
    $wpdb->update("{$wpdb->prefix}bookshop_reservations",['status'=>$status],['id'=>intval($_POST['id'])]);
    wp_send_json_success();
});

// Audit log
add_action('wp_ajax_bs_get_audit_log',function(){
    if(!bs_user_can_manage()) wp_send_json_error('Unauthorized',403);
    wp_send_json_success(bs_get_audit_log(['limit'=>200]));
});

// Save settings
add_action('wp_ajax_bs_save_settings',function(){
    // Sensitive: payment secret keys, API keys, store options. Admin-only.
    if(!current_user_can('manage_options')) wp_send_json_error('Unauthorized',403);
    // All text/password/number/url/email/select settings fields
    $text_fields=[
        // Store identity
        'bookshop_currency','bookshop_receipt_header','bookshop_tagline',
        'bookshop_phone','bookshop_store_email','bookshop_receipt_footer','bookshop_logo_url',
        // Tax
        'bookshop_tax_mode','bookshop_tax_rate','bookshop_tax_label',
        // Loyalty
        'bookshop_loyalty_rate','bookshop_loyalty_value',
        'bookshop_loyalty_expiry_months',
        // Operations
        'bookshop_low_stock_email','bookshop_whatsapp','bookshop_manager_discount_threshold',
        'bookshop_eod_email',
        // Payment gateways — public keys
        'bookshop_paystack_public_key',
        'bookshop_flutterwave_public_key',
        'bookshop_flw_currency',
        // Backup & API
        'bookshop_backup_email','bookshop_google_sheets_url',
    ];
    foreach($text_fields as $f){
        if(isset($_POST[$f])) update_option($f, sanitize_text_field($_POST[$f]));
    }

    // Secret keys — sanitize but preserve full string (no stripping of special chars)
    $secret_fields = [
        'bookshop_paystack_secret_key',
        'bookshop_flutterwave_secret_key',
    ];
    foreach($secret_fields as $f){
        if(isset($_POST[$f]) && !empty($_POST[$f])){
            // Only update if a non-empty value was submitted (avoid overwriting with blank)
            update_option($f, sanitize_text_field($_POST[$f]));
        }
    }

    // Textarea fields
    if(isset($_POST['bookshop_address'])){
        update_option('bookshop_address', sanitize_textarea_field($_POST['bookshop_address']));
    }
    if(isset($_POST['bookshop_ip_whitelist'])){
        update_option('bookshop_ip_whitelist', sanitize_textarea_field($_POST['bookshop_ip_whitelist']));
    }

    wp_send_json_success(['message'=>'Settings saved successfully']);
});

// Staff pin management
add_action('wp_ajax_bs_set_pin',function(){
    if(!bs_user_can_manage()) wp_send_json_error('Unauthorized',403);
    if(!bs_verify('bs_admin_nonce')) wp_send_json_error('Bad nonce');
    $uid=intval($_POST['user_id']??0);
    $pin=sanitize_text_field($_POST['pin']??'');
    if(!$uid||strlen($pin)<4) wp_send_json_error('Invalid PIN');
    update_user_meta($uid,'bookshop_pin',wp_hash_password($pin));
    wp_send_json_success();
});
add_action('wp_ajax_bs_pin_login','bs_handle_pin_login');
add_action('wp_ajax_nopriv_bs_pin_login','bs_handle_pin_login');
function bs_handle_pin_login(){
    $pin=sanitize_text_field($_POST['pin']??'');
    if(!$pin){ wp_send_json_error('No PIN'); }
    if(!preg_match('/^[0-9]{4,8}$/',$pin)){ wp_send_json_error('PIN must be 4–8 digits'); }
    $users=get_users(['meta_key'=>'bookshop_pin','fields'=>'all']);
    foreach($users as $u){
        $hash=get_user_meta($u->ID,'bookshop_pin',true);
        if(empty($hash)) continue;
        if(wp_check_password($pin,$hash,$u->ID)){
            if(!bs_user_can_pos($u->ID)) wp_send_json_error('No POS access');
            wp_clear_auth_cookie();
            wp_set_current_user($u->ID);
            wp_set_auth_cookie($u->ID,false);
            wp_send_json_success(['name'=>$u->display_name,'id'=>$u->ID]);
        }
    }
    // Slight delay to slow brute force
    usleep(300000);
    wp_send_json_error('Invalid PIN');
}

// Adjust loyalty manually
add_action('wp_ajax_bs_adjust_loyalty',function(){
    if(!bs_user_can_manage()) wp_send_json_error('Unauthorized',403);
    if(!bs_verify('bs_admin_nonce')) wp_send_json_error('Bad nonce');
    bs_adjust_loyalty(intval($_POST['customer_id']),intval($_POST['points']),sanitize_text_field($_POST['note']??''));
    wp_send_json_success();
});

// Add customer credit
add_action('wp_ajax_bs_add_credit',function(){
    if(!bs_user_can_manage()) wp_send_json_error('Unauthorized',403);
    if(!bs_verify('bs_admin_nonce')) wp_send_json_error('Bad nonce');
    bs_add_customer_credit(intval($_POST['customer_id']),floatval($_POST['amount']),sanitize_text_field($_POST['note']??''));
    wp_send_json_success();
});

// ── Branches ──────────────────────────────────────────────────────────────────
add_action('wp_ajax_bs_save_branch',function(){
    if(!bs_user_can_manage()) wp_send_json_error('Unauthorized',403);
    $is_new   = empty($_POST['id']);
    $id       = intval($_POST['id'] ?? 0);
    $new_id   = bs_save_branch($_POST,$id);
    $backfill = !empty($_POST['backfill']) ? sanitize_text_field($_POST['backfill']) : '';

    // Backfill is only meaningful on the create path. Re-running it on edit
    // would either duplicate-attempt-then-no-op (for 'copy' it's a safe
    // INSERT IGNORE) or look like a no-op the manager can't make sense of,
    // so we just ignore it on update.
    $seeded = 0;
    if($is_new && $new_id && in_array($backfill,['copy','zero'],true)){
        $seeded = bs_backfill_branch_stock($new_id,$backfill);
    }
    wp_send_json_success(['id'=>$new_id,'backfilled'=>$seeded]);
});
add_action('wp_ajax_bs_get_branch',function(){
    if(!bs_user_can_manage()) wp_send_json_error('Unauthorized',403);
    $b=bs_get_branch(intval($_GET['id']??0));
    if(!$b){ wp_send_json_error('Not found'); }
    // Surface the count of active books that don't yet have a row in
    // bookshop_branch_stock for this branch. The edit modal uses this to
    // show a "re-seed missing books" prompt — a book added after the
    // branch was created has no row, which means the oversell guard will
    // reject every sale of it at this branch until the row exists.
    $b->missing_count = bs_count_missing_branch_stock_rows(intval($b->id));
    wp_send_json_success($b);
});
add_action('wp_ajax_bs_reseed_branch_stock',function(){
    // Mutates branch_stock — admin-only because it can affect what the
    // oversell guard accepts at this branch. (bs_user_can_manage would also
    // work, but keeping it admin-only matches the reconcile policy below.)
    if(!current_user_can('manage_options')) wp_send_json_error('Unauthorized',403);
    if(!bs_verify('bs_admin_nonce'))         wp_send_json_error('Bad nonce');
    $branch_id = intval($_POST['branch_id'] ?? 0);
    $mode      = sanitize_text_field($_POST['mode'] ?? 'zero');
    if(!$branch_id) wp_send_json_error('Missing branch_id');
    if(!in_array($mode,['copy','zero'],true)) wp_send_json_error('Invalid mode');
    if(!bs_get_branch($branch_id))            wp_send_json_error('Branch not found');
    $seeded = bs_backfill_branch_stock($branch_id, $mode);
    wp_send_json_success([
        'seeded'  => intval($seeded),
        'missing' => bs_count_missing_branch_stock_rows($branch_id),
    ]);
});

// ── Drift detection & reconciliation ──────────────────────────────────────────
// Mutating endpoints (reconcile) are admin-only because they overwrite
// bookshop_books.stock_qty, which is a global value that bs_user_can_manage
// alone shouldn't be allowed to change without trace. The read-only drift
// list is admin-only too — exposing cross-branch totals to a manager scoped
// to one branch would leak figures they're not allowed to see.
add_action('wp_ajax_bs_get_stock_drift',function(){
    if(!current_user_can('manage_options')) wp_send_json_error('Unauthorized',403);
    $rows = bs_get_stock_drift(0, 500);
    wp_send_json_success($rows);
});
add_action('wp_ajax_bs_reconcile_book_stock',function(){
    if(!current_user_can('manage_options')) wp_send_json_error('Unauthorized',403);
    if(!bs_verify('bs_admin_nonce'))         wp_send_json_error('Bad nonce');
    $res = bs_reconcile_book_stock(intval($_POST['book_id'] ?? 0));
    isset($res['error']) ? wp_send_json_error($res['error']) : wp_send_json_success($res);
});
add_action('wp_ajax_bs_reconcile_all_drift',function(){
    if(!current_user_can('manage_options')) wp_send_json_error('Unauthorized',403);
    if(!bs_verify('bs_admin_nonce'))         wp_send_json_error('Bad nonce');
    $res = bs_reconcile_all_drift(500);
    wp_send_json_success($res);
});
add_action('wp_ajax_bs_get_branch_stock',function(){
    if(!bs_user_can_manage()) wp_send_json_error('Unauthorized',403);
    $stock=bs_get_branch_stock(intval($_GET['id']??0));
    wp_send_json_success($stock);
});

// Stock breakdown for a single book across every branch the user is allowed
// to view. Used by the "🏪 By branch" button on the admin Books listing.
add_action('wp_ajax_bs_get_book_branch_breakdown',function(){
    if(!bs_user_can_manage()) wp_send_json_error('Unauthorized',403);
    global $wpdb;
    $book_id = intval($_GET['book_id'] ?? 0);
    if(!$book_id) wp_send_json_error('Missing book_id');

    $book = bs_get_book($book_id);
    if(!$book) wp_send_json_error('Book not found');

    // Scope to the branches this user is permitted to see (admin → all,
    // bookshop_manager → their home branch). Without this scoping a manager
    // could call the endpoint directly and read every branch's stock.
    $allowed = function_exists('bs_user_report_branches')
        ? bs_user_report_branches() : bs_get_branches(true);
    if(empty($allowed)) wp_send_json_success(['rows'=>[],'global'=>intval($book->stock_qty),'low'=>intval($book->low_stock_threshold)]);

    $allowed_ids = array_map(function($b){ return intval($b->id); }, $allowed);
    $placeholders = implode(',', array_fill(0, count($allowed_ids), '%d'));

    $params = array_merge([$book_id], $allowed_ids);
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT br.id AS branch_id, br.name AS branch_name,
                COALESCE(bst.qty, 0) AS qty
         FROM {$wpdb->prefix}bookshop_branches br
         LEFT JOIN {$wpdb->prefix}bookshop_branch_stock bst
                ON bst.branch_id = br.id AND bst.book_id = %d
         WHERE br.id IN ($placeholders)
         ORDER BY br.name ASC",
        $params
    ));

    wp_send_json_success([
        'rows'   => $rows,
        'global' => intval($book->stock_qty),
        'low'    => intval($book->low_stock_threshold),
        'title'  => $book->title,
    ]);
});
add_action('wp_ajax_bs_transfer_stock',function(){
    if(!bs_user_can_manage()) wp_send_json_error('Unauthorized',403);
    if(!bs_verify('bs_admin_nonce')) wp_send_json_error('Bad nonce');
    $res=bs_transfer_stock(intval($_POST['from']),intval($_POST['to']),intval($_POST['book_id']),intval($_POST['qty']));
    isset($res['error'])?wp_send_json_error($res['error']):wp_send_json_success($res);
});
add_action('wp_ajax_bs_check_reorder',function(){
    if(!bs_user_can_manage()) wp_send_json_error('Unauthorized',403);
    $books=bs_check_reorder_points();
    if(empty($books)){wp_send_json_success(['message'=>'No reorder needed','count'=>0]);return;}
    $po_id=bs_auto_create_reorder_po();
    wp_send_json_success(['message'=>count($books).' books need reordering — Draft PO created','count'=>count($books),'po_id'=>$po_id]);
});

// ── Per-branch login: select active branch (POS) ──────────────────────────────
add_action('wp_ajax_bs_set_active_branch',function(){
    if(!bs_user_can_pos()) wp_send_json_error('Unauthorized',403);
    if(!bs_verify('bs_pos_nonce')) wp_send_json_error('Bad nonce',403);

    $uid       = get_current_user_id();
    $branch_id = intval($_POST['branch_id'] ?? 0);

    // Block branch switching while a shift is open — closing the shift first
    // prevents cash variance accounting from spanning two locations.
    $open_shift = bs_get_open_shift($uid);
    $current    = bs_get_active_branch_id($uid);
    if($open_shift && $branch_id !== intval($current)){
        wp_send_json_error([
            'code'    => 'shift_open',
            'message' => 'Close your current shift before switching branches.',
        ]);
    }

    $res = bs_set_active_branch_id($uid, $branch_id);
    if($res === true){
        $b = $branch_id ? bs_get_branch($branch_id) : null;
        bs_audit('branch_session_set','user',$uid,$b?"Active branch: {$b->name}":'Active branch cleared');
        wp_send_json_success([
            'branch_id'   => $branch_id,
            'branch_name' => $b ? $b->name : '',
        ]);
    }
    $msg = [
        'invalid_branch' => 'That branch is not active.',
        'forbidden'      => 'You are not assigned to that branch.',
        'no_user'        => 'Not signed in.',
    ];
    wp_send_json_error($msg[$res] ?? 'Could not set branch');
});

// ── Per-branch login: admin assigns home branch to a staff user ───────────────
add_action('wp_ajax_bs_admin_set_user_branch',function(){
    if(!bs_user_can_manage()) wp_send_json_error('Unauthorized',403);
    if(!bs_verify('bs_admin_nonce')) wp_send_json_error('Bad nonce',403);

    $uid       = intval($_POST['user_id']   ?? 0);
    $branch_id = intval($_POST['branch_id'] ?? 0);
    if(!$uid) wp_send_json_error('Missing user_id');

    if(!bs_set_user_branch($uid,$branch_id)){
        wp_send_json_error('Invalid branch');
    }

    // If the user we just reassigned has a stale active branch they no longer
    // belong to, clear it so they get the picker again next login.
    if($branch_id){
        $active = bs_get_active_branch_id($uid);
        if($active && $active !== $branch_id && !bs_user_can_manage($uid)){
            delete_user_meta($uid, BS_USER_ACTIVE_BRANCH_META);
        }
    } else {
        delete_user_meta($uid, BS_USER_ACTIVE_BRANCH_META);
    }
    wp_send_json_success(['user_id'=>$uid,'branch_id'=>$branch_id]);
});

// ── Online Orders ─────────────────────────────────────────────────────────────
add_action('wp_ajax_bs_update_online_order_status',function(){
    if(!bs_user_can_manage()) wp_send_json_error('Unauthorized',403);
    if(!bs_verify('bs_admin_nonce')) wp_send_json_error('Bad nonce',403);
    $res=bs_update_online_order_status(intval($_POST['id']??0),sanitize_text_field($_POST['status']??''));
    if(is_array($res) && isset($res['error'])){
        wp_send_json_error($res['error']);
    }
    wp_send_json_success(is_array($res)?$res:['ok'=>true]);
});

// ── Webhooks (admin panel JS) ─────────────────────────────────────────────────
add_action('wp_ajax_bs_delete_webhook',function(){
    global $wpdb;
    if(!bs_user_can_manage()) wp_send_json_error('Unauthorized',403);
    $wpdb->delete("{$wpdb->prefix}bookshop_webhooks",['id'=>intval($_POST['id']??0)]);
    wp_send_json_success();
});
add_action('wp_ajax_bs_add_webhook',function(){
    global $wpdb;
    if(!bs_user_can_manage()) wp_send_json_error('Unauthorized',403);
    $wpdb->insert("{$wpdb->prefix}bookshop_webhooks",[
        'url'   =>esc_url_raw($_POST['url']??''),
        'event' =>sanitize_text_field($_POST['event']??'sale.completed'),
        'secret'=>sanitize_text_field($_POST['secret']??''),
        'status'=>'active',
    ]);
    wp_send_json_success(['id'=>$wpdb->insert_id]);
});

// ── Stock Take AJAX handlers ──────────────────────────────────────────────────
add_action('wp_ajax_bs_create_stocktake',function(){
    if(!bs_user_can_manage()) wp_send_json_error('Unauthorized',403);
    $branch_id=intval($_POST['branch_id']??0);
    if(!$branch_id) wp_send_json_error('Branch required');
    $id=bs_create_stock_take($branch_id,get_current_user_id());
    wp_send_json_success(['take_id'=>$id]);
});

add_action('wp_ajax_bs_submit_stocktake',function(){
    if(!bs_user_can_manage()) wp_send_json_error('Unauthorized',403);
    $take_id=intval($_POST['take_id']??0);
    $counts=json_decode(stripslashes($_POST['counts']??'{}'),true);
    if(!$take_id||empty($counts)) wp_send_json_error('Missing data');
    $variances=bs_submit_stock_take($take_id,$counts);
    $variances!==false ? wp_send_json_success($variances) : wp_send_json_error('Could not submit stock take');
});

add_action('wp_ajax_bs_get_all_books_for_count',function(){
    if(!bs_user_can_manage()) wp_send_json_error('Unauthorized',403);
    $books=bs_get_books(['status'=>'active','limit'=>1000,'orderby'=>'title']);
    wp_send_json_success($books);
});
