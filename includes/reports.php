<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// Every report below accepts an optional $branch_id (0 = all branches).
// The branch filter is applied as `s.branch_id = %d` on the sales table; when
// 0 is passed we leave the WHERE alone so pre-v4 sales (branch_id NULL) and
// post-v4 sales are both included.

// ── Summary ───────────────────────────────────────────────────────────────────
function bs_report_summary( $from = '', $to = '', $branch_id = 0 ) {
    global $wpdb;
    $w = ["status='completed'"]; $p = [];
    if ( $from )      { $w[] = 'DATE(created_at) >= %s'; $p[] = $from; }
    if ( $to )        { $w[] = 'DATE(created_at) <= %s'; $p[] = $to; }
    if ( $branch_id ) { $w[] = 'branch_id = %d';         $p[] = intval($branch_id); }
    $wh = implode( ' AND ', $w );
    $sql = "SELECT COUNT(*) AS sales_count,
                   SUM(total)          AS revenue,
                   SUM(subtotal)       AS subtotal,
                   SUM(discount)       AS discounts,
                   SUM(promo_discount) AS promo_discounts,
                   SUM(tax)            AS tax,
                   SUM(total-tax)      AS revenue_ex_tax
            FROM {$wpdb->prefix}bookshop_sales WHERE $wh";
    return $p ? $wpdb->get_row( $wpdb->prepare( $sql, $p ) ) : $wpdb->get_row( $sql );
}

// ── Daily ─────────────────────────────────────────────────────────────────────
function bs_report_daily( $from = '', $to = '', $branch_id = 0 ) {
    global $wpdb;
    $w = ["status='completed'"]; $p = [];
    if ( $from )      { $w[] = 'DATE(created_at) >= %s'; $p[] = $from; }
    if ( $to )        { $w[] = 'DATE(created_at) <= %s'; $p[] = $to; }
    if ( $branch_id ) { $w[] = 'branch_id = %d';         $p[] = intval($branch_id); }
    $wh = implode( ' AND ', $w );
    $sql = "SELECT DATE(created_at) AS day, COUNT(*) AS sales_count, SUM(total) AS revenue
            FROM {$wpdb->prefix}bookshop_sales WHERE $wh
            GROUP BY DATE(created_at) ORDER BY day ASC";
    return $p ? $wpdb->get_results( $wpdb->prepare( $sql, $p ) ) : $wpdb->get_results( $sql );
}

// ── Hourly ────────────────────────────────────────────────────────────────────
function bs_report_hourly( $from = '', $to = '', $branch_id = 0 ) {
    global $wpdb;
    $w = ["status='completed'"]; $p = [];
    if ( $from )      { $w[] = 'DATE(created_at) >= %s'; $p[] = $from; }
    if ( $to )        { $w[] = 'DATE(created_at) <= %s'; $p[] = $to; }
    if ( $branch_id ) { $w[] = 'branch_id = %d';         $p[] = intval($branch_id); }
    $wh = implode( ' AND ', $w );
    $sql = "SELECT HOUR(created_at) AS hr, COUNT(*) AS sales_count, SUM(total) AS revenue
            FROM {$wpdb->prefix}bookshop_sales WHERE $wh
            GROUP BY HOUR(created_at) ORDER BY hr ASC";
    $rows = $p ? $wpdb->get_results( $wpdb->prepare( $sql, $p ) ) : $wpdb->get_results( $sql );
    $map  = [];
    foreach ( $rows as $r ) $map[ intval($r->hr) ] = $r;
    $out  = [];
    for ( $h = 0; $h < 24; $h++ ) {
        $out[] = $map[$h] ?? (object)['hr'=>$h,'sales_count'=>0,'revenue'=>0];
    }
    return $out;
}

// ── Top Books ─────────────────────────────────────────────────────────────────
function bs_report_top_books( $from = '', $to = '', $limit = 10, $branch_id = 0 ) {
    global $wpdb;
    $w = ["s.status='completed'"]; $p = [];
    if ( $from )      { $w[] = 'DATE(s.created_at) >= %s'; $p[] = $from; }
    if ( $to )        { $w[] = 'DATE(s.created_at) <= %s'; $p[] = $to; }
    if ( $branch_id ) { $w[] = 's.branch_id = %d';         $p[] = intval($branch_id); }
    $wh = implode( ' AND ', $w ); $p[] = intval($limit);
    $sql = "SELECT b.title, b.author, b.genre,
                   SUM(si.qty)                           AS units_sold,
                   SUM(si.line_total)                    AS revenue,
                   SUM(si.qty * b.cost_price)            AS cogs,
                   SUM(si.line_total-(si.qty*b.cost_price)) AS profit
            FROM {$wpdb->prefix}bookshop_sale_items si
            JOIN {$wpdb->prefix}bookshop_sales s ON s.id = si.sale_id
            JOIN {$wpdb->prefix}bookshop_books  b ON b.id = si.book_id
            WHERE $wh
            GROUP BY si.book_id ORDER BY units_sold DESC LIMIT %d";
    return $wpdb->get_results( $wpdb->prepare( $sql, $p ) );
}

// ── Staff ─────────────────────────────────────────────────────────────────────
function bs_report_staff( $from = '', $to = '', $branch_id = 0 ) {
    global $wpdb;
    $w = ["s.status='completed'"]; $p = [];
    if ( $from )      { $w[] = 'DATE(s.created_at) >= %s'; $p[] = $from; }
    if ( $to )        { $w[] = 'DATE(s.created_at) <= %s'; $p[] = $to; }
    if ( $branch_id ) { $w[] = 's.branch_id = %d';         $p[] = intval($branch_id); }
    $wh = implode( ' AND ', $w );
    $sql = "SELECT u.display_name AS staff_name,
                   COUNT(s.id) AS sales_count,
                   SUM(s.total) AS revenue,
                   SUM(s.discount + s.promo_discount) AS discounts
            FROM {$wpdb->prefix}bookshop_sales s
            JOIN {$wpdb->users} u ON u.ID = s.staff_id
            WHERE $wh GROUP BY s.staff_id ORDER BY revenue DESC";
    return $p ? $wpdb->get_results( $wpdb->prepare( $sql, $p ) ) : $wpdb->get_results( $sql );
}

// ── Genre ─────────────────────────────────────────────────────────────────────
function bs_report_genre( $from = '', $to = '', $branch_id = 0 ) {
    global $wpdb;
    $w = ["s.status='completed'"]; $p = [];
    if ( $from )      { $w[] = 'DATE(s.created_at) >= %s'; $p[] = $from; }
    if ( $to )        { $w[] = 'DATE(s.created_at) <= %s'; $p[] = $to; }
    if ( $branch_id ) { $w[] = 's.branch_id = %d';         $p[] = intval($branch_id); }
    $wh = implode( ' AND ', $w );
    $sql = "SELECT b.genre, SUM(si.qty) AS units_sold, SUM(si.line_total) AS revenue
            FROM {$wpdb->prefix}bookshop_sale_items si
            JOIN {$wpdb->prefix}bookshop_sales s ON s.id = si.sale_id
            JOIN {$wpdb->prefix}bookshop_books  b ON b.id = si.book_id
            WHERE $wh GROUP BY b.genre ORDER BY revenue DESC";
    return $p ? $wpdb->get_results( $wpdb->prepare( $sql, $p ) ) : $wpdb->get_results( $sql );
}

// ── Payment Methods ───────────────────────────────────────────────────────────
function bs_report_payment_methods( $from = '', $to = '', $branch_id = 0 ) {
    global $wpdb;
    $w = ["status='completed'"]; $p = [];
    if ( $from )      { $w[] = 'DATE(created_at) >= %s'; $p[] = $from; }
    if ( $to )        { $w[] = 'DATE(created_at) <= %s'; $p[] = $to; }
    if ( $branch_id ) { $w[] = 'branch_id = %d';         $p[] = intval($branch_id); }
    $wh = implode( ' AND ', $w );
    $sql = "SELECT payment_method, COUNT(*) AS count, SUM(total) AS revenue, SUM(discount) AS discounts
            FROM {$wpdb->prefix}bookshop_sales WHERE $wh
            GROUP BY payment_method ORDER BY revenue DESC";
    return $p ? $wpdb->get_results( $wpdb->prepare( $sql, $p ) ) : $wpdb->get_results( $sql );
}

// ── Slow Movers ───────────────────────────────────────────────────────────────
// Branch-aware variant: when $branch_id is set, "stock" comes from
// bookshop_branch_stock instead of the global stock_qty, and the sales join
// is scoped to that branch.
function bs_report_slow_movers( $days = 30, $limit = 20, $branch_id = 0 ) {
    global $wpdb;
    $since = date( 'Y-m-d', strtotime("-{$days} days") );
    $branch_id = intval($branch_id);

    if ( $branch_id ) {
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT b.*, bst.qty AS stock_qty,
                    COALESCE(SUM(si.qty), 0) AS units_sold
             FROM {$wpdb->prefix}bookshop_branch_stock bst
             JOIN {$wpdb->prefix}bookshop_books b ON b.id = bst.book_id
             LEFT JOIN {$wpdb->prefix}bookshop_sale_items si ON si.book_id = b.id
             LEFT JOIN {$wpdb->prefix}bookshop_sales s
                   ON s.id = si.sale_id
                  AND DATE(s.created_at) >= %s
                  AND s.status = 'completed'
                  AND s.branch_id = %d
             WHERE b.status = 'active' AND bst.branch_id = %d AND bst.qty > 0
             GROUP BY b.id ORDER BY units_sold ASC, bst.qty DESC LIMIT %d",
            $since, $branch_id, $branch_id, intval($limit)
        ) );
    }

    return $wpdb->get_results( $wpdb->prepare(
        "SELECT b.*,
                COALESCE(SUM(si.qty), 0) AS units_sold
         FROM {$wpdb->prefix}bookshop_books b
         LEFT JOIN {$wpdb->prefix}bookshop_sale_items si ON si.book_id = b.id
         LEFT JOIN {$wpdb->prefix}bookshop_sales s
               ON s.id = si.sale_id AND DATE(s.created_at) >= %s AND s.status = 'completed'
         WHERE b.status = 'active' AND b.stock_qty > 0
         GROUP BY b.id ORDER BY units_sold ASC, b.stock_qty DESC LIMIT %d",
        $since, intval($limit)
    ) );
}

// ── Profit ────────────────────────────────────────────────────────────────────
function bs_report_profit( $from = '', $to = '', $branch_id = 0 ) {
    global $wpdb;
    $w = ["s.status='completed'"]; $p = [];
    if ( $from )      { $w[] = 'DATE(s.created_at) >= %s'; $p[] = $from; }
    if ( $to )        { $w[] = 'DATE(s.created_at) <= %s'; $p[] = $to; }
    if ( $branch_id ) { $w[] = 's.branch_id = %d';         $p[] = intval($branch_id); }
    $wh = implode( ' AND ', $w );
    $sql = "SELECT SUM(si.line_total)                        AS revenue,
                   SUM(si.qty * b.cost_price)                AS cogs,
                   SUM(si.line_total-(si.qty*b.cost_price))  AS gross_profit
            FROM {$wpdb->prefix}bookshop_sale_items si
            JOIN {$wpdb->prefix}bookshop_sales s ON s.id = si.sale_id
            JOIN {$wpdb->prefix}bookshop_books  b ON b.id = si.book_id
            WHERE $wh";
    return $p ? $wpdb->get_row( $wpdb->prepare( $sql, $p ) ) : $wpdb->get_row( $sql );
}

// ── Export helpers ────────────────────────────────────────────────────────────
function bs_export_sales_csv( $from = '', $to = '', $branch_id = 0 ) {
    $sales = bs_get_sales(['from'=>$from,'to'=>$to,'branch_id'=>intval($branch_id),'limit'=>10000]);
    $rows  = [['Ref','Date','Time','Staff','Customer','Payment','Subtotal','Discount','Promo Discount','Tax','Total','Loyalty Earned','Status','Note']];
    foreach ( $sales as $s ) {
        $rows[] = [
            $s->sale_ref,
            wp_date('d/m/Y', strtotime($s->created_at)),
            wp_date('H:i',   strtotime($s->created_at)),
            $s->staff_name,
            $s->customer_name ?? 'Walk-in',
            $s->payment_method,
            $s->subtotal, $s->discount, $s->promo_discount,
            $s->tax, $s->total, $s->loyalty_earned, $s->status, $s->note,
        ];
    }
    return $rows;
}
