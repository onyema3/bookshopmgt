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

    if ( empty($cart) || !is_array($cart) ) {
        return ['error'=>'Cart is empty','code'=>'empty_cart'];
    }

    // Aggregate by book_id. A cashier scanning the same title twice ends up
    // with two cart entries; a per-line stock check would pass each one but
    // their *sum* could oversell. We sum here and then enforce on the sum.
    $by_book = [];
    foreach ( $cart as $item ) {
        $bid = intval($item['id']);
        $qty = intval($item['qty']);
        if ( $bid <= 0 || $qty <= 0 ) continue;
        $by_book[$bid] = ( $by_book[$bid] ?? 0 ) + $qty;
    }
    if ( empty($by_book) ) {
        return ['error'=>'Cart is empty','code'=>'empty_cart'];
    }

    $branch_id = intval($o['branch_id']);

    // ── Friendly pre-check ────────────────────────────────────────────────
    // Read current stock and reject up front so the cashier sees a clear
    // "Only N in stock for X" message. The actual oversell guard is the
    // atomic conditional UPDATE below; this is purely for UX so the
    // common case doesn't surface as a generic race error.
    foreach ( $by_book as $bid => $needed ) {
        if ( $branch_id ) {
            $have = intval( $wpdb->get_var( $wpdb->prepare(
                "SELECT qty FROM {$wpdb->prefix}bookshop_branch_stock
                 WHERE branch_id=%d AND book_id=%d",
                $branch_id, $bid
            ) ) );
        } else {
            $have = intval( $wpdb->get_var( $wpdb->prepare(
                "SELECT stock_qty FROM {$wpdb->prefix}bookshop_books WHERE id=%d", $bid
            ) ) );
        }
        if ( $have < $needed ) {
            $title = $wpdb->get_var( $wpdb->prepare(
                "SELECT title FROM {$wpdb->prefix}bookshop_books WHERE id=%d", $bid
            ) );
            return [
                'error'     => sprintf( 'Only %d in stock for "%s"', $have, $title ?: "book #$bid" ),
                'code'      => 'insufficient_stock',
                'book_id'   => $bid,
                'available' => $have,
                'requested' => $needed,
            ];
        }
    }

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

    // ── Transaction: stock decrement → sale row → side effects ───────────
    // Wrapping every write in one transaction means a race-lost decrement,
    // a failed insert, or an exploding side-effect rolls the whole thing
    // back. Without this, we used to get a sale row with no stock change
    // (or vice versa) when anything mid-flight failed.
    $wpdb->query('START TRANSACTION');

    // For the per-branch path, ensure a branch_stock row exists for every
    // book in the cart before we issue the conditional UPDATE — otherwise
    // the UPDATE has nothing to match and looks identical to a race-loss.
    if ( $branch_id ) {
        foreach ( array_keys($by_book) as $bid ) {
            $wpdb->query( $wpdb->prepare(
                "INSERT IGNORE INTO {$wpdb->prefix}bookshop_branch_stock (branch_id, book_id, qty)
                 VALUES (%d, %d, 0)",
                $branch_id, $bid
            ) );
        }
    }

    // Atomic per-book decrement. Each UPDATE only succeeds when there's
    // still enough stock; if another cashier just sold the last copy we
    // get affected_rows=0 and roll back. This is the actual oversell
    // guard — the pre-check above is purely cosmetic.
    foreach ( $by_book as $bid => $needed ) {
        if ( $branch_id ) {
            $ok = $wpdb->query( $wpdb->prepare(
                "UPDATE {$wpdb->prefix}bookshop_branch_stock
                    SET qty = qty - %d
                  WHERE branch_id = %d AND book_id = %d AND qty >= %d",
                $needed, $branch_id, $bid, $needed
            ) );
            // Mirror the decrement in the global stock_qty so cross-branch
            // aggregates (the catalogue, the inventory tab when no branch
            // is selected, the slow-mover report's "all branches" path) stay
            // in sync. GREATEST(0,…) here is defensive — branch stock is
            // the authoritative gate above, and we don't want a pre-v4
            // backfill miss to push global negative.
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$wpdb->prefix}bookshop_books
                    SET stock_qty = GREATEST(0, stock_qty - %d)
                  WHERE id = %d",
                $needed, $bid
            ) );
        } else {
            // Global-only path (online orders, REST API, no active branch).
            // Same atomic guarantee against the global stock_qty.
            $ok = $wpdb->query( $wpdb->prepare(
                "UPDATE {$wpdb->prefix}bookshop_books
                    SET stock_qty = stock_qty - %d
                  WHERE id = %d AND stock_qty >= %d",
                $needed, $bid, $needed
            ) );
        }
        if ( $ok !== 1 ) {
            $wpdb->query('ROLLBACK');
            return [
                'error'   => 'Stock changed while completing this sale — please try again.',
                'code'    => 'stock_race',
                'book_id' => $bid,
            ];
        }
        // Audit the per-book decrement so the breakdown modal's recent
        // activity panel can show "Sale BS-… — branch N: -2" rather than
        // the user having to cross-reference sale_items by hand. Done
        // here (not via bs_adjust_branch_stock) because the conditional
        // UPDATE above is what actually enforces oversell; using the
        // helper would race with that guard.
        if ( $branch_id ) {
            bs_audit( 'branch_stock_sold', 'book', $bid,
                "Branch $branch_id: -$needed (sale $ref)" );
        } else {
            // Global-only path: still emit a book-scoped audit row so the
            // per-book activity panel can attribute the decrement, even
            // though no branch is involved.
            bs_audit( 'global_stock_sold', 'book', $bid,
                "Global stock_qty: -$needed (sale $ref, no branch)" );
        }
    }

    $wpdb->insert("{$wpdb->prefix}bookshop_sales", [
        'sale_ref'        => $ref,
        'staff_id'        => intval($staff_id),
        'branch_id'       => $branch_id ?: null,
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

    // sale_items uses the *original* (un-aggregated) cart so the receipt
    // and admin sale-detail view show every scan as a separate line.
    foreach ( $cart as $item ) {
        $bid   = intval($item['id']);
        $qty   = intval($item['qty']);
        if ( $bid <= 0 || $qty <= 0 ) continue;
        $price = floatval($item['price']);
        $wpdb->insert("{$wpdb->prefix}bookshop_sale_items", [
            'sale_id'    => $sale_id,
            'book_id'    => $bid,
            'qty'        => $qty,
            'unit_price' => $price,
            'line_total' => $price * $qty,
        ]);
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

    $wpdb->query('COMMIT');

    $sale_result = ['sale_id'=>$sale_id,'ref'=>$ref,'total'=>$total,'tax'=>$tax,'loyalty_earned'=>$loyalty_earned];
    return apply_filters('bs_after_sale_created', $sale_result);
}

function bs_void_sale( $sale_id ) {
    global $wpdb;
    $sale_id = intval($sale_id);
    $sale = bs_get_sale($sale_id);
    if ( !$sale || $sale->status === 'voided' ) return false;

    // ── Transaction: status flip → global restock → branch restock ───────
    // All three need to commit together. Without a transaction, an
    // exploding query mid-loop would leave the sale marked voided but with
    // some line items un-restocked, which then drifts the branch and global
    // counters apart silently.
    $wpdb->query('START TRANSACTION');

    // Conditional status flip — guards against two concurrent void attempts
    // both restoring stock. Whichever transaction commits first wins; the
    // second sees affected_rows=0 and rolls back without touching stock.
    $flipped = $wpdb->query( $wpdb->prepare(
        "UPDATE {$wpdb->prefix}bookshop_sales
            SET status = 'voided'
          WHERE id = %d AND status <> 'voided'",
        $sale_id
    ) );
    if ( $flipped !== 1 ) {
        $wpdb->query('ROLLBACK');
        return false;
    }

    $items = bs_get_sale_items($sale_id);
    foreach ( $items as $item ) {
        $bid = intval($item->book_id);
        $qty = intval($item->qty);

        // Restocking can never go negative, so a direct add is correct here.
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->prefix}bookshop_books
                SET stock_qty = stock_qty + %d
              WHERE id = %d",
            $qty, $bid
        ) );

        // Mirror the restock at the branch the sale was made from. Sales
        // recorded before the v4 migration have branch_id=NULL and only
        // ever decremented global stock, so we skip them here.
        if ( !empty($sale->branch_id) ) {
            bs_adjust_branch_stock( intval($sale->branch_id), $bid, $qty,
                "void of sale {$sale->sale_ref}" );
        } else {
            // Pre-v4 sale: only a global counter to restock. Still emit
            // a book-scoped audit row so the per-book activity panel
            // attributes this to the void rather than leaving a silent gap.
            bs_audit( 'global_stock_voided', 'book', $bid,
                "Global stock_qty: +$qty (void of sale {$sale->sale_ref}, no branch)" );
        }
    }

    bs_audit('sale_voided','sale',$sale_id,"Voided sale {$sale->sale_ref}");

    $wpdb->query('COMMIT');
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
    $a = wp_parse_args($args,['staff_id'=>0,'customer_id'=>0,'branch_id'=>0,'from'=>'','to'=>'','limit'=>100,'offset'=>0,'status'=>'']);
    $where=['1=1']; $p=[];
    if ($a['staff_id'])    { $where[]='s.staff_id=%d';       $p[]=$a['staff_id']; }
    if ($a['customer_id']) { $where[]='s.customer_id=%d';    $p[]=$a['customer_id']; }
    if ($a['branch_id'])   { $where[]='s.branch_id=%d';      $p[]=$a['branch_id']; }
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
