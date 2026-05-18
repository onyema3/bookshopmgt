<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function bs_create_sale( $cart, $staff_id, $opts = [] ) {
    global $wpdb;
    $o = wp_parse_args($opts, [
        'customer_id'  => 0,
        'payment'      => 'cash',
        'payment_details' => [],
        'discount'     => 0,
        'promo_code'   => '',
        'credit_used'  => 0,
        'loyalty_redeem' => 0,
        'note'         => '',
        'shift_id'     => 0,
        'branch_id'    => 0,
    ]);

    $subtotal = array_sum(array_map(function($i){ return floatval($i['price'])*intval($i['qty']); },$cart));
    $manual_disc = floatval($o['discount']);
    $promo_disc  = 0;
    $promo_used  = null;

    // Apply promo code
    if ($o['promo_code']) {
        $promo = bs_get_promo_by_code($o['promo_code']);
        if ($promo && bs_promo_valid($promo, $subtotal)) {
            $promo_disc = bs_calc_promo_discount($promo, $subtotal, $cart);
            $promo_used = $promo;
        }
    }

    $credit_used = min(floatval($o['credit_used']), $subtotal - $manual_disc - $promo_disc);
    $credit_used = max(0, $credit_used);

    // Loyalty redemption (points → currency)
    $loyalty_val = floatval(get_option('bookshop_loyalty_value', 10));
    $redeem_pts  = intval($o['loyalty_redeem']);
    $redeem_val  = $redeem_pts * $loyalty_val;

    $taxable  = $subtotal - $manual_disc - $promo_disc - $credit_used - $redeem_val;
    $tax      = bs_calc_tax($taxable);
    $total    = max(0, $taxable + $tax);

    // Loyalty earned
    $loyalty_rate = floatval(get_option('bookshop_loyalty_rate', 1)); // pts per 100
    $loyalty_earned = intval(floor($total / 100 * $loyalty_rate));

    $ref = bs_gen_ref('BS');

    $wpdb->insert("{$wpdb->prefix}bookshop_sales", [
        'sale_ref'        => $ref,
        'staff_id'        => intval($staff_id),
        'branch_id'       => intval($o['branch_id']) ?: null,
        'customer_id'     => intval($o['customer_id']) ?: null,
        'shift_id'        => intval($o['shift_id']) ?: null,
        'subtotal'        => $subtotal,
        'discount'        => $manual_disc,
        'promo_discount'  => $promo_disc,
        'credit_used'     => $credit_used,
        'tax'             => $tax,
        'total'           => $total,
        'payment_method'  => sanitize_text_field($o['payment']),
        'payment_details' => !empty($o['payment_details']) ? json_encode($o['payment_details']) : null,
        'promo_code'      => sanitize_text_field($o['promo_code']),
        'loyalty_earned'  => $loyalty_earned,
        'loyalty_redeemed'=> $redeem_pts,
        'note'            => sanitize_textarea_field($o['note']),
    ]);
    $sale_id = $wpdb->insert_id;

    foreach ($cart as $item) {
        $bid   = intval($item['id']);
        $qty   = intval($item['qty']);
        $price = floatval($item['price']);
        $wpdb->insert("{$wpdb->prefix}bookshop_sale_items", [
            'sale_id'    => $sale_id,
            'book_id'    => $bid,
            'qty'        => $qty,
            'unit_price' => $price,
            'line_total' => $price * $qty,
        ]);
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}bookshop_books SET stock_qty=GREATEST(0,stock_qty-%d) WHERE id=%d",
            $qty, $bid
        ));
    }

    // Update promo usage
    if ($promo_used) {
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}bookshop_promotions SET used_count=used_count+1 WHERE id=%d",
            $promo_used->id
        ));
    }

    // Customer loyalty & credit
    if ($o['customer_id']) {
        $cid = intval($o['customer_id']);
        if ($loyalty_earned > 0) {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->prefix}bookshop_customers SET loyalty_points=loyalty_points+%d WHERE id=%d",
                $loyalty_earned, $cid
            ));
            bs_log_loyalty($cid, $sale_id, $loyalty_earned, 'earned', "Earned on sale $ref");
        }
        if ($redeem_pts > 0) {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->prefix}bookshop_customers SET loyalty_points=GREATEST(0,loyalty_points-%d) WHERE id=%d",
                $redeem_pts, $cid
            ));
            bs_log_loyalty($cid, $sale_id, -$redeem_pts, 'redeemed', "Redeemed on sale $ref");
        }
        if ($credit_used > 0) {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->prefix}bookshop_customers SET credit_balance=GREATEST(0,credit_balance-%s) WHERE id=%d",
                $credit_used, $cid
            ));
        }
    }

    bs_audit('sale_created','sale',$sale_id,"Sale $ref — Total: ".bs_fmt($total));

    return ['sale_id'=>$sale_id,'ref'=>$ref,'total'=>$total,'tax'=>$tax,'loyalty_earned'=>$loyalty_earned];
}

function bs_void_sale( $sale_id ) {
    global $wpdb;
    $sale = bs_get_sale($sale_id);
    if (!$sale || $sale->status === 'voided') return false;
    $wpdb->update("{$wpdb->prefix}bookshop_sales",['status'=>'voided'],['id'=>$sale_id]);
    // Restore stock
    $items = bs_get_sale_items($sale_id);
    foreach ($items as $item) {
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}bookshop_books SET stock_qty=stock_qty+%d WHERE id=%d",
            $item->qty, $item->book_id
        ));
    }
    bs_audit('sale_voided','sale',$sale_id,"Voided sale {$sale->sale_ref}");
    return true;
}

function bs_get_sale( $id ) {
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare(
        "SELECT s.*, u.display_name AS staff_name FROM {$wpdb->prefix}bookshop_sales s
         LEFT JOIN {$wpdb->users} u ON u.ID=s.staff_id WHERE s.id=%d", $id
    ));
}

function bs_get_sales( $args = [] ) {
    global $wpdb;
    $a = wp_parse_args($args,['staff_id'=>0,'customer_id'=>0,'from'=>'','to'=>'','limit'=>100,'offset'=>0,'status'=>'']);
    $where=['1=1']; $p=[];
    if ($a['staff_id'])    { $where[]='s.staff_id=%d';       $p[]=$a['staff_id']; }
    if ($a['customer_id']) { $where[]='s.customer_id=%d';    $p[]=$a['customer_id']; }
    if ($a['from'])        { $where[]='DATE(s.created_at)>=%s'; $p[]=$a['from']; }
    if ($a['to'])          { $where[]='DATE(s.created_at)<=%s'; $p[]=$a['to']; }
    if ($a['status'])      { $where[]='s.status=%s';         $p[]=$a['status']; }
    $sql = "SELECT s.*,u.display_name AS staff_name,c.name AS customer_name
            FROM {$wpdb->prefix}bookshop_sales s
            LEFT JOIN {$wpdb->users} u ON u.ID=s.staff_id
            LEFT JOIN {$wpdb->prefix}bookshop_customers c ON c.id=s.customer_id
            WHERE ".implode(' AND ',$where)."
            ORDER BY s.created_at DESC LIMIT %d OFFSET %d";
    $p[]=$a['limit']; $p[]=$a['offset'];
    return $wpdb->get_results($wpdb->prepare($sql,$p));
}

function bs_get_sale_items( $sale_id ) {
    global $wpdb;
    return $wpdb->get_results($wpdb->prepare(
        "SELECT si.*,b.title,b.author,b.isbn FROM {$wpdb->prefix}bookshop_sale_items si
         LEFT JOIN {$wpdb->prefix}bookshop_books b ON b.id=si.book_id WHERE si.sale_id=%d", $sale_id
    ));
}
