<?php
if(!defined('ABSPATH'))exit;

/**
 * Inventory Forecasting Module
 * 
 * Calculates weekly velocity (units sold per week), days-of-cover remaining,
 * and a suggested reorder quantity for each book based on configurable lead
 * time and target cover days.
 */

// ── Settings defaults ─────────────────────────────────────────────────────────
add_action('admin_init', function(){
    if(get_option('bookshop_forecast_lead_days') === false)
        update_option('bookshop_forecast_lead_days', 14);
    if(get_option('bookshop_forecast_target_cover_days') === false)
        update_option('bookshop_forecast_target_cover_days', 30);
    if(get_option('bookshop_forecast_lookback_weeks') === false)
        update_option('bookshop_forecast_lookback_weeks', 8);
});

/**
 * Get forecast data for all active books (or filtered by branch).
 *
 * @param array $args  Optional filters: branch_id, genre, limit, offset, order_by, order
 * @return array       ['rows' => [...], 'total' => int]
 */
function bs_get_inventory_forecast($args = []){
    global $wpdb;

    $a = wp_parse_args($args, [
        'branch_id'  => 0,
        'genre'      => '',
        'search'     => '',
        'limit'      => 50,
        'offset'     => 0,
        'order_by'   => 'days_cover',
        'order'      => 'ASC',
    ]);

    $lead_days   = max(1, intval(get_option('bookshop_forecast_lead_days', 14)));
    $target_days = max(1, intval(get_option('bookshop_forecast_target_cover_days', 30)));
    $lookback    = max(1, intval(get_option('bookshop_forecast_lookback_weeks', 8)));
    $since_date  = date('Y-m-d', strtotime("-{$lookback} weeks"));

    $branch_id = intval($a['branch_id']);

    // ── Build per-book velocity from sale_items in the lookback window ────
    $vel_where = "s.status='completed' AND s.created_at >= %s";
    $vel_params = [$since_date];
    if($branch_id){
        $vel_where .= " AND s.branch_id = %d";
        $vel_params[] = $branch_id;
    }

    // Get qty sold per book in the window
    $velocity_sql = $wpdb->prepare(
        "SELECT si.book_id, SUM(si.qty) AS total_sold
         FROM {$wpdb->prefix}bookshop_sale_items si
         INNER JOIN {$wpdb->prefix}bookshop_sales s ON s.id = si.sale_id
         WHERE $vel_where
         GROUP BY si.book_id",
        ...$vel_params
    );
    $vel_rows = $wpdb->get_results($velocity_sql, OBJECT_K);

    // ── Main books query ─────────────────────────────────────────────────
    $bk_where = ["b.status='active'"];
    $bk_params = [];

    if($a['genre']){
        $bk_where[] = "b.genre = %s";
        $bk_params[] = $a['genre'];
    }
    if($a['search']){
        $bk_where[] = "(b.title LIKE %s OR b.author LIKE %s OR b.isbn LIKE %s)";
        $s = '%' . $wpdb->esc_like($a['search']) . '%';
        $bk_params[] = $s;
        $bk_params[] = $s;
        $bk_params[] = $s;
    }

    $where_clause = implode(' AND ', $bk_where);

    // Count total for pagination
    $count_sql = "SELECT COUNT(*) FROM {$wpdb->prefix}bookshop_books b WHERE $where_clause";
    $total = $bk_params
        ? intval($wpdb->get_var($wpdb->prepare($count_sql, ...$bk_params)))
        : intval($wpdb->get_var($count_sql));

    // Fetch book rows
    $select_sql = "SELECT b.id, b.title, b.author, b.isbn, b.genre, b.stock_qty, b.low_stock_threshold, b.cost_price
                   FROM {$wpdb->prefix}bookshop_books b
                   WHERE $where_clause
                   ORDER BY b.title ASC";
    // We'll sort in PHP after calculating derived fields
    $books = $bk_params
        ? $wpdb->get_results($wpdb->prepare($select_sql, ...$bk_params))
        : $wpdb->get_results($select_sql);

    // If branch-specific, get branch stock instead of global
    $branch_stock = [];
    if($branch_id){
        $bs_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT book_id, qty FROM {$wpdb->prefix}bookshop_branch_stock WHERE branch_id=%d",
            $branch_id
        ));
        foreach($bs_rows as $r) $branch_stock[intval($r->book_id)] = intval($r->qty);
    }

    // ── Calculate forecast fields ────────────────────────────────────────
    $rows = [];
    foreach($books as $bk){
        $bid = intval($bk->id);
        $stock = $branch_id
            ? ($branch_stock[$bid] ?? 0)
            : intval($bk->stock_qty);

        $total_sold = isset($vel_rows[$bid]) ? intval($vel_rows[$bid]->total_sold) : 0;

        // Weekly velocity = total_sold / lookback_weeks
        $weekly_velocity = round($total_sold / $lookback, 2);

        // Daily velocity
        $daily_velocity = $weekly_velocity / 7;

        // Days of cover = current stock / daily velocity
        $days_cover = $daily_velocity > 0
            ? round($stock / $daily_velocity, 1)
            : ($stock > 0 ? 9999 : 0); // 9999 = effectively infinite cover

        // Suggested reorder qty:
        // Target: enough stock to last (lead_days + target_cover_days)
        // Reorder = ceil(daily_velocity * (lead + target)) - current_stock
        $needed = ceil($daily_velocity * ($lead_days + $target_days));
        $suggested_reorder = max(0, $needed - $stock);

        // Status flag
        $status = 'ok';
        if($days_cover <= $lead_days && $daily_velocity > 0){
            $status = 'critical'; // Will stock-out before next delivery
        } elseif($days_cover <= ($lead_days + 7) && $daily_velocity > 0){
            $status = 'warning'; // Less than a week buffer after lead time
        } elseif($stock <= intval($bk->low_stock_threshold)){
            $status = 'low';
        }

        $rows[] = (object)[
            'id'                => $bid,
            'title'             => $bk->title,
            'author'            => $bk->author,
            'isbn'              => $bk->isbn,
            'genre'             => $bk->genre,
            'stock_qty'         => $stock,
            'low_stock_threshold' => intval($bk->low_stock_threshold),
            'cost_price'        => floatval($bk->cost_price),
            'total_sold'        => $total_sold,
            'weekly_velocity'   => $weekly_velocity,
            'daily_velocity'    => round($daily_velocity, 2),
            'days_cover'        => $days_cover,
            'suggested_reorder' => $suggested_reorder,
            'reorder_cost'      => round($suggested_reorder * floatval($bk->cost_price), 2),
            'status'            => $status,
        ];
    }

    // ── Sort ─────────────────────────────────────────────────────────────
    $sort_field = $a['order_by'];
    $sort_dir   = strtoupper($a['order']) === 'DESC' ? -1 : 1;
    usort($rows, function($x, $y) use ($sort_field, $sort_dir){
        $va = $x->$sort_field ?? 0;
        $vb = $y->$sort_field ?? 0;
        if(is_string($va)) return $sort_dir * strcasecmp($va, $vb);
        return $sort_dir * ($va <=> $vb);
    });

    // ── Paginate ─────────────────────────────────────────────────────────
    $paged = array_slice($rows, intval($a['offset']), intval($a['limit']));

    return ['rows' => $paged, 'total' => $total];
}

/**
 * Summary stats for the forecast dashboard header.
 */
function bs_forecast_summary($branch_id = 0){
    $all = bs_get_inventory_forecast(['branch_id' => $branch_id, 'limit' => 99999]);
    $rows = $all['rows'];

    $critical = 0; $warning = 0; $low = 0;
    $total_reorder_cost = 0;
    $total_velocity = 0;

    foreach($rows as $r){
        if($r->status === 'critical') $critical++;
        elseif($r->status === 'warning') $warning++;
        elseif($r->status === 'low') $low++;
        $total_reorder_cost += $r->reorder_cost;
        $total_velocity += $r->weekly_velocity;
    }

    return (object)[
        'total_books'        => count($rows),
        'critical_count'     => $critical,
        'warning_count'      => $warning,
        'low_count'          => $low,
        'total_reorder_cost' => $total_reorder_cost,
        'avg_weekly_velocity'=> count($rows) > 0 ? round($total_velocity / count($rows), 2) : 0,
    ];
}

// ── AJAX: Get forecast data ───────────────────────────────────────────────────
add_action('wp_ajax_bs_get_forecast', function(){
    if(!bs_user_can_manage()) wp_send_json_error('Unauthorized', 403);

    $result = bs_get_inventory_forecast([
        'branch_id' => intval($_GET['branch_id'] ?? 0),
        'genre'     => sanitize_text_field($_GET['genre'] ?? ''),
        'search'    => sanitize_text_field($_GET['search'] ?? ''),
        'limit'     => intval($_GET['limit'] ?? 50),
        'offset'    => intval($_GET['offset'] ?? 0),
        'order_by'  => sanitize_text_field($_GET['order_by'] ?? 'days_cover'),
        'order'     => sanitize_text_field($_GET['order'] ?? 'ASC'),
    ]);

    wp_send_json_success($result);
});

// ── AJAX: Update forecast settings ───────────────────────────────────────────
add_action('wp_ajax_bs_save_forecast_settings', function(){
    if(!bs_user_can_manage()) wp_send_json_error('Unauthorized', 403);
    if(!bs_verify('bs_admin_nonce')) wp_send_json_error('Bad nonce');

    $lead   = max(1, intval($_POST['lead_days'] ?? 14));
    $target = max(1, intval($_POST['target_cover_days'] ?? 30));
    $lookback = max(1, intval($_POST['lookback_weeks'] ?? 8));

    update_option('bookshop_forecast_lead_days', $lead);
    update_option('bookshop_forecast_target_cover_days', $target);
    update_option('bookshop_forecast_lookback_weeks', $lookback);

    bs_audit('forecast_settings_updated', 'settings', 0, "Lead: {$lead}d, Target: {$target}d, Lookback: {$lookback}w");
    wp_send_json_success(['message' => 'Forecast settings saved']);
});
