<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ── POS: Submit sale ──────────────────────────────────────────────────────────
add_action('wp_ajax_bs_submit_sale', function() {
    if ( !bs_user_can_pos() ) wp_send_json_error('Unauthorized', 403);
    if ( !bs_verify('bs_pos_nonce') ) wp_send_json_error('Bad nonce');

    $cart     = json_decode( stripslashes($_POST['cart']    ?? '[]'), true );
    $payment  = sanitize_text_field( $_POST['payment']      ?? 'cash' );
    $pay_det  = json_decode( stripslashes($_POST['payment_details'] ?? '{}'), true );
    $discount = floatval( $_POST['discount']                ?? 0 );
    $promo    = sanitize_text_field( $_POST['promo_code']   ?? '' );
    $cid      = intval( $_POST['customer_id']               ?? 0 );
    $credit   = floatval( $_POST['credit_used']             ?? 0 );
    $loyalty  = intval( $_POST['loyalty_redeem']            ?? 0 );
    $note     = sanitize_textarea_field( $_POST['note']     ?? '' );

    if ( empty($cart) ) wp_send_json_error('Cart is empty');

    // Manager threshold check
    $threshold = intval( get_option('bookshop_manager_discount_threshold', 20) );
    $subtotal  = array_sum( array_map(function($i){ return floatval($i['price']) * intval($i['qty']); }, $cart) );
    if ( $discount > 0 && $subtotal > 0 ) {
        $pct = ($discount / $subtotal) * 100;
        if ( $pct > $threshold && !bs_user_can_manage() ) {
            wp_send_json_error(['code'=>'manager_required','message'=>"Discount over {$threshold}% requires manager approval."]);
        }
    }

    $shift  = bs_get_open_shift( get_current_user_id() );
    $result = bs_create_sale( $cart, get_current_user_id(), [
        'customer_id'     => $cid,
        'payment'         => $payment,
        'payment_details' => $pay_det,
        'discount'        => $discount,
        'promo_code'      => $promo,
        'credit_used'     => $credit,
        'loyalty_redeem'  => $loyalty,
        'note'            => $note,
        'shift_id'        => $shift ? $shift->id : 0,
    ]);
    wp_send_json_success($result);
});

// ── Admin: Get sale items ─────────────────────────────────────────────────────
add_action('wp_ajax_bs_get_sale_items', function() {
    if ( !current_user_can('manage_options') ) wp_send_json_error('Unauthorized', 403);
    $items = bs_get_sale_items( intval($_GET['id'] ?? 0) );
    wp_send_json_success($items);
});

// ── Admin: Void sale ──────────────────────────────────────────────────────────
add_action('wp_ajax_bs_void_sale', function() {
    if ( !bs_user_can_manage() ) wp_send_json_error('Unauthorized', 403);
    if ( !bs_verify('bs_admin_nonce') ) wp_send_json_error('Bad nonce');
    $ok = bs_void_sale( intval($_POST['id'] ?? 0) );
    $ok ? wp_send_json_success() : wp_send_json_error('Cannot void');
});

// ── POS: Validate promo code ──────────────────────────────────────────────────
add_action('wp_ajax_bs_validate_promo', function() {
    if ( !bs_user_can_pos() ) wp_send_json_error('Unauthorized', 403);
    $code     = sanitize_text_field( $_GET['code']     ?? '' );
    $subtotal = floatval( $_GET['subtotal']             ?? 0 );
    $promo    = bs_get_promo_by_code($code);
    if ( !$promo || !bs_promo_valid($promo, $subtotal) ) {
        wp_send_json_error('Invalid or expired promo code');
    }
    if ( $promo->requires_manager && !bs_user_can_manage() ) {
        wp_send_json_error(['code'=>'manager_required','message'=>'This promo requires manager approval.']);
    }
    $disc = bs_calc_promo_discount($promo, $subtotal);
    wp_send_json_success(['discount' => $disc, 'promo' => $promo]);
});

// ── POS: Email receipt ────────────────────────────────────────────────────────
add_action('wp_ajax_bs_send_receipt_email', function() {
    if ( !bs_user_can_pos() ) wp_send_json_error('Unauthorized', 403);
    $sale_id = intval( $_POST['sale_id'] ?? 0 );
    $email   = sanitize_email( $_POST['email'] ?? '' );
    if ( !$email || !$sale_id ) wp_send_json_error('Missing data');
    $sale  = bs_get_sale($sale_id);
    $items = bs_get_sale_items($sale_id);
    bs_send_email_receipt($email, $sale, $items);
    wp_send_json_success();
});

// ── POS: Shift open ───────────────────────────────────────────────────────────
add_action('wp_ajax_bs_open_shift', function() {
    if ( !bs_user_can_pos() ) wp_send_json_error('Unauthorized', 403);
    if ( !bs_verify('bs_pos_nonce') ) wp_send_json_error('Bad nonce');
    $id = bs_open_shift( get_current_user_id(), floatval($_POST['opening_cash'] ?? 0) );
    wp_send_json_success(['shift_id' => $id]);
});

// ── POS: Shift close ──────────────────────────────────────────────────────────
add_action('wp_ajax_bs_close_shift', function() {
    if ( !bs_user_can_pos() ) wp_send_json_error('Unauthorized', 403);
    if ( !bs_verify('bs_pos_nonce') ) wp_send_json_error('Bad nonce');
    $res = bs_close_shift(
        intval( $_POST['shift_id']     ?? 0 ),
        floatval( $_POST['closing_cash'] ?? 0 ),
        sanitize_textarea_field( $_POST['notes'] ?? '' )
    );
    $res ? wp_send_json_success($res) : wp_send_json_error('Could not close shift');
});

// ── POS: Get open shift ───────────────────────────────────────────────────────
add_action('wp_ajax_bs_get_open_shift', function() {
    if ( !bs_user_can_pos() ) wp_send_json_error('Unauthorized', 403);
    $shift = bs_get_open_shift( get_current_user_id() );
    wp_send_json_success( $shift ?: null );
});

// ── Export: Sales CSV ─────────────────────────────────────────────────────────
add_action('wp_ajax_bs_export_sales_csv', function() {
    if ( !current_user_can('manage_options') ) wp_die('Unauthorized');
    $from  = sanitize_text_field($_GET['from'] ?? '');
    $to    = sanitize_text_field($_GET['to']   ?? '');
    $sales = bs_get_sales(['from'=>$from,'to'=>$to,'limit'=>10000]);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="sales-'.date('Y-m-d').'.csv"');
    $f = fopen('php://output', 'w');
    fputcsv($f, ['Ref','Date','Time','Staff','Customer','Payment','Subtotal','Discount','Promo Discount','Tax','Total','Loyalty Earned','Status','Note']);
    foreach ( $sales as $s ) {
        fputcsv($f, [
            $s->sale_ref,
            wp_date('d/m/Y', strtotime($s->created_at)),
            wp_date('H:i',   strtotime($s->created_at)),
            $s->staff_name,
            $s->customer_name ?? 'Walk-in',
            $s->payment_method,
            $s->subtotal, $s->discount, $s->promo_discount,
            $s->tax, $s->total, $s->loyalty_earned, $s->status, $s->note,
        ]);
    }
    fclose($f); exit;
});

// ── Export: Sales JSON ────────────────────────────────────────────────────────
add_action('wp_ajax_bs_export_sales_json', function() {
    if ( !current_user_can('manage_options') ) wp_die('Unauthorized');
    $from  = sanitize_text_field($_GET['from'] ?? '');
    $to    = sanitize_text_field($_GET['to']   ?? '');
    $sales = bs_get_sales(['from'=>$from,'to'=>$to,'limit'=>10000]);
    $out   = [];
    foreach ( $sales as $s ) {
        $items = bs_get_sale_items($s->id);
        $out[] = [
            'ref'            => $s->sale_ref,
            'date'           => $s->created_at,
            'staff'          => $s->staff_name,
            'customer'       => $s->customer_name ?? 'Walk-in',
            'payment_method' => $s->payment_method,
            'subtotal'       => floatval($s->subtotal),
            'discount'       => floatval($s->discount) + floatval($s->promo_discount),
            'tax'            => floatval($s->tax),
            'total'          => floatval($s->total),
            'status'         => $s->status,
            'items'          => array_map(function($i){ return ['title'=>$i->title,'author'=>$i->author,'isbn'=>$i->isbn,'qty'=>intval($i->qty),'unit_price'=>floatval($i->unit_price),'line_total'=>floatval($i->line_total)]; }, $items),
        ];
    }
    $payload = [
        'shop'        => get_option('bookshop_receipt_header', get_bloginfo('name')),
        'from'        => $from, 'to' => $to,
        'exported_at' => current_time('c'),
        'sales'       => $out,
    ];
    header('Content-Type: application/json; charset=UTF-8');
    header('Content-Disposition: attachment; filename="sales-'.date('Y-m-d').'.json"');
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
});

// ── Export: Inventory CSV ─────────────────────────────────────────────────────
add_action('wp_ajax_bs_export_inventory_csv', function() {
    if ( !current_user_can('manage_options') ) wp_die('Unauthorized');
    $books = bs_get_books(['status'=>'','limit'=>10000]);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="inventory-'.date('Y-m-d').'.csv"');
    $f = fopen('php://output', 'w');
    fputcsv($f, ['Title','Author','ISBN','Barcode','Genre','Publisher','Year','Location','Cost Price','Sell Price','Margin %','Stock Qty','Stock Cost Value','Stock Sell Value','Low Stock Threshold','Status']);
    foreach ( $books as $b ) {
        $margin = $b->sell_price > 0
            ? round((($b->sell_price - $b->cost_price) / $b->sell_price) * 100, 1) : 0;
        fputcsv($f, [
            $b->title, $b->author, $b->isbn, $b->barcode, $b->genre,
            $b->publisher, $b->publish_year, $b->location,
            $b->cost_price, $b->sell_price, $margin.'%',
            $b->stock_qty,
            round($b->stock_qty * $b->cost_price, 2),
            round($b->stock_qty * $b->sell_price, 2),
            $b->low_stock_threshold, $b->status,
        ]);
    }
    fclose($f); exit;
});

// ── Export: Report PDF (via mPDF-less HTML-to-PDF using browser print) ────────
// Returns a self-contained HTML page that auto-triggers print dialog
add_action('wp_ajax_bs_export_report_pdf', function() {
    if ( !current_user_can('manage_options') ) wp_die('Unauthorized');
    $from = sanitize_text_field($_GET['from'] ?? date('Y-m-01'));
    $to   = sanitize_text_field($_GET['to']   ?? date('Y-m-d'));
    // Redirect to the printable report page which handles PDF output
    wp_redirect(home_url('/?bookshop_print_report=1&from='.urlencode($from).'&to='.urlencode($to).'&auto_print=1'));
    exit;
});
