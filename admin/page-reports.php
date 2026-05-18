<?php
if(!defined('ABSPATH'))exit;

function bs_page_reports(){
    $from = sanitize_text_field($_GET['from'] ?? date('Y-m-01'));
    $to   = sanitize_text_field($_GET['to']   ?? date('Y-m-d'));
    $requested_branch = intval($_GET['branch'] ?? 0);

    // Resolve which branches this user is allowed to view, and coerce the
    // ?branch= param against that list. Non-admins with a home branch are
    // pinned to it: an unscoped report would otherwise expose every branch's
    // sales to a manager who's only meant to see their own location.
    $branches  = function_exists('bs_user_report_branches') ? bs_user_report_branches() : [];
    $branch_id = function_exists('bs_validate_report_branch')
        ? bs_validate_report_branch($requested_branch)
        : $requested_branch;
    if ($branch_id === false) {
        echo '<div class="wrap"><h1>📊 Reports & Analytics</h1>'
           . '<div class="notice notice-error"><p>You don\'t have access to that branch.</p></div></div>';
        return;
    }
    $branch_label = '';
    if ( $branch_id ) {
        foreach ( $branches as $b ) { if ( intval($b->id) === $branch_id ) { $branch_label = $b->name; break; } }
    }

    $sum    = bs_report_summary($from,$to,$branch_id);
    $profit = bs_report_profit($from,$to,$branch_id);
    $top    = bs_report_top_books($from,$to,15,$branch_id);
    $staff  = bs_report_staff($from,$to,$branch_id);
    $daily  = bs_report_daily($from,$to,$branch_id);
    $genre  = bs_report_genre($from,$to,$branch_id);
    $slow   = bs_report_slow_movers(30,15,$branch_id);
    $hourly = bs_report_hourly($from,$to,$branch_id);
    $pay    = bs_report_payment_methods($from,$to,$branch_id);

    $rev    = floatval($sum->revenue ?? 0);
    $gross  = floatval($profit->gross_profit ?? 0);
    $cogs   = floatval($profit->cogs ?? 0);
    $margin = $rev > 0 ? round(($gross/$rev)*100,1) : 0;
    $cnt    = intval($sum->sales_count ?? 0);
    $avg    = $cnt > 0 ? $rev/$cnt : 0;

    $base_url = admin_url('admin-ajax.php');
    $qs_args  = ['from'=>$from,'to'=>$to];
    if ( $branch_id ) $qs_args['branch'] = $branch_id;
    $qs = http_build_query($qs_args);
    // Same query string as a path fragment for the printable report URL
    // (which uses home_url, not admin-ajax).
    $print_qs = http_build_query($qs_args);
    ?>
    <div class="wrap bs-wrap">
    <div class="bs-header">
        <h1>📊 Reports & Analytics<?php if($branch_label): ?> <span style="font-size:.65em;color:var(--muted);font-weight:500">— <?=esc_html($branch_label)?></span><?php endif; ?></h1>
        <div style="display:flex;gap:6px;flex-wrap:wrap">
            <a href="<?=$base_url?>?action=bs_export_sales_csv&<?=$qs?>" class="bs-btn bs-btn-secondary" title="Export sales to CSV">📄 CSV</a>
            <a href="<?=$base_url?>?action=bs_export_sales_json&<?=$qs?>" class="bs-btn bs-btn-secondary" title="Export sales to JSON">{ } JSON</a>
            <a href="<?=$base_url?>?action=bs_export_inventory_csv<?=$branch_id?'&branch='.$branch_id:''?>" class="bs-btn bs-btn-secondary" title="Export full inventory">📦 Inventory</a>
            <a href="<?=$base_url?>?action=bs_export_report_pdf&<?=$qs?>" class="bs-btn bs-btn-primary" title="Download PDF report" style="background:#c0392b">📥 PDF</a>
            <a href="<?=home_url('/?bookshop_print_report=1&'.$print_qs)?>" target="_blank" class="bs-btn bs-btn-secondary" title="Open printable PDF-ready report">🖨️ Print</a>
        </div>
    </div>

    <!-- Date filters -->
    <form method="get" class="bs-toolbar" style="flex-wrap:wrap;gap:6px">
        <input type="hidden" name="page" value="bookshop-reports">
        <?php
        $shortcuts = [
            'Today'       => [date('Y-m-d'), date('Y-m-d')],
            'Yesterday'   => [date('Y-m-d',strtotime('-1 day')), date('Y-m-d',strtotime('-1 day'))],
            'This Week'   => [date('Y-m-d',strtotime('monday this week')), date('Y-m-d')],
            'This Month'  => [date('Y-m-01'), date('Y-m-d')],
            'Last Month'  => [date('Y-m-01',strtotime('-1 month')), date('Y-m-t',strtotime('-1 month'))],
            'This Year'   => [date('Y-01-01'), date('Y-m-d')],
            'Last 7 Days' => [date('Y-m-d',strtotime('-7 days')), date('Y-m-d')],
            'Last 30 Days'=> [date('Y-m-d',strtotime('-30 days')), date('Y-m-d')],
        ];
        $branch_qs = $branch_id ? '&branch='.$branch_id : '';
        foreach($shortcuts as $label=>[$f,$t]):
            $active = $from===$f&&$to===$t ? 'bs-btn-primary' : 'bs-btn-secondary';
        ?>
        <a href="?page=bookshop-reports&from=<?=$f?>&to=<?=$t?><?=$branch_qs?>" class="bs-btn <?=$active?>" style="font-size:.75rem;padding:5px 10px"><?=$label?></a>
        <?php endforeach; ?>
        <label style="display:flex;align-items:center;gap:4px;font-size:.83rem">
            From <input type="date" name="from" value="<?=esc_attr($from)?>" class="bs-input-sm">
        </label>
        <label style="display:flex;align-items:center;gap:4px;font-size:.83rem">
            To <input type="date" name="to" value="<?=esc_attr($to)?>" class="bs-input-sm">
        </label>
        <?php if(count($branches) > 1): ?>
        <label style="display:flex;align-items:center;gap:4px;font-size:.83rem">
            🏪 <select name="branch" class="bs-input-sm" style="min-width:140px">
                <?php if(function_exists('bs_user_is_admin') && bs_user_is_admin()): ?>
                <option value="0">All branches</option>
                <?php endif; ?>
                <?php foreach($branches as $b): ?>
                <option value="<?=intval($b->id)?>" <?=selected($branch_id,intval($b->id),false)?>><?=esc_html($b->name)?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php elseif(count($branches) === 1 && $branch_id): ?>
            <input type="hidden" name="branch" value="<?=intval($branch_id)?>">
        <?php endif; ?>
        <button class="bs-btn bs-btn-secondary">Apply</button>
    </form>

    <!-- KPI Stats -->
    <div class="bs-stats-row bs-stats-big">
        <?php
        bs_stat(bs_fmt($rev),   'Total Revenue',     'accent');
        bs_stat(bs_fmt($gross), 'Gross Profit',      'accent');
        bs_stat($margin.'%',   'Profit Margin');
        bs_stat($cnt,          'Transactions');
        bs_stat(bs_fmt($avg),  'Avg. Sale Value');
        bs_stat(bs_fmt($cogs), 'Cost of Goods');
        bs_stat(bs_fmt($sum->discounts??0), 'Discounts Given');
        bs_stat(bs_fmt($sum->tax??0),       'Tax Collected');
        ?>
    </div>

    <!-- Report Tabs -->
    <div class="bs-tabs">
        <button class="bs-tab active" data-tab="rpt-overview">Overview</button>
        <button class="bs-tab" data-tab="rpt-books">Books</button>
        <button class="bs-tab" data-tab="rpt-staff">Staff</button>
        <button class="bs-tab" data-tab="rpt-inventory">Inventory</button>
        <button class="bs-tab" data-tab="rpt-payments">Payments</button>
        <?php
        // Drift tab is admin-only because it surfaces cross-branch sums for
        // every active book in one place — a manager scoped to one branch
        // shouldn't see another location's per-book numbers via this tab.
        $is_admin_view = function_exists('bs_user_is_admin') && bs_user_is_admin();
        if ( $is_admin_view ):
            $drift_rows  = function_exists('bs_get_stock_drift') ? bs_get_stock_drift(0, 500) : [];
            $drift_count = count($drift_rows);
        ?>
        <button class="bs-tab" data-tab="rpt-drift">
            Drift<?php if($drift_count): ?> <span style="background:var(--red);color:#fff;border-radius:10px;padding:1px 8px;font-size:.7rem;margin-left:4px"><?=$drift_count?></span><?php endif; ?>
        </button>
        <?php endif; ?>
    </div>

    <!-- ── Overview Tab ───────────────────────────────────────────────────── -->
    <div id="rpt-overview" class="bs-tab-content">
        <div class="bs-reports-grid">

            <div class="bs-report-card" style="grid-column:1/-1">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
                    <h3>📈 Daily Revenue & Transactions</h3>
                </div>
                <canvas id="bs-daily-chart" height="120"></canvas>
                <script id="bs-daily-data" type="application/json"><?=esc_html(json_encode($daily))?></script>
            </div>

            <div class="bs-report-card">
                <h3>🎭 Revenue by Genre</h3>
                <canvas id="bs-genre-chart" height="220"></canvas>
                <script id="bs-genre-data" type="application/json"><?=esc_html(json_encode($genre))?></script>
            </div>

            <div class="bs-report-card">
                <h3>⏰ Sales by Hour of Day</h3>
                <canvas id="bs-hourly-chart" height="220"></canvas>
                <script id="bs-hourly-data" type="application/json"><?=esc_html(json_encode($hourly))?></script>
            </div>

            <div class="bs-report-card" style="grid-column:1/-1">
                <h3>📊 Profit Breakdown</h3>
                <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-top:4px">
                    <?php
                    $gm_pct  = $rev>0?round(($gross/$rev)*100,1):0;
                    $disc_pct= $rev>0?round((floatval($sum->discounts??0)/$rev)*100,1):0;
                    ?>
                    <div style="background:var(--warm);border-radius:8px;padding:14px;text-align:center">
                        <div style="font-size:1.4rem;font-weight:700;color:var(--amber-d)"><?=bs_fmt($rev)?></div>
                        <div style="font-size:.75rem;color:var(--muted)">Gross Revenue</div>
                    </div>
                    <div style="background:#f0fdf4;border-radius:8px;padding:14px;text-align:center;border:1px solid #bbf7d0">
                        <div style="font-size:1.4rem;font-weight:700;color:var(--green)"><?=bs_fmt($gross)?></div>
                        <div style="font-size:.75rem;color:var(--muted)">Gross Profit (<?=$gm_pct?>%)</div>
                    </div>
                    <div style="background:#fef2f2;border-radius:8px;padding:14px;text-align:center;border:1px solid #fecaca">
                        <div style="font-size:1.4rem;font-weight:700;color:var(--red)"><?=bs_fmt($cogs)?></div>
                        <div style="font-size:.75rem;color:var(--muted)">Cost of Goods Sold</div>
                    </div>
                    <div style="background:#fefce8;border-radius:8px;padding:14px;text-align:center;border:1px solid #fde68a">
                        <div style="font-size:1.4rem;font-weight:700;color:#92400e"><?=bs_fmt($sum->discounts??0)?></div>
                        <div style="font-size:.75rem;color:var(--muted)">Discounts Given (<?=$disc_pct?>%)</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Books Tab ──────────────────────────────────────────────────────── -->
    <div id="rpt-books" class="bs-tab-content" style="display:none">
        <div class="bs-reports-grid">
            <div class="bs-report-card" style="grid-column:1/-1">
                <h3>🏆 Top <?=count($top)?> Books by Units Sold</h3>
                <?php if($top): ?>
                <div style="overflow-x:auto">
                <table class="bs-table bs-table-sm">
                    <thead><tr><th>#</th><th>Title</th><th>Author</th><th>Genre</th><th>Units Sold</th><th>Revenue</th><th>COGS</th><th>Gross Profit</th><th>Margin</th></tr></thead>
                    <tbody>
                    <?php foreach($top as $i=>$b):
                        $bm = $b->revenue>0 ? round((($b->profit??0)/$b->revenue)*100,1) : 0;
                    ?>
                    <tr>
                        <td style="font-weight:700;color:var(--muted)"><?=$i+1?></td>
                        <td><strong><?=esc_html($b->title)?></strong></td>
                        <td><?=esc_html($b->author)?></td>
                        <td><span class="bs-badge bs-badge-active" style="background:var(--warm);color:var(--ink)"><?=esc_html($b->genre)?></span></td>
                        <td><strong><?=intval($b->units_sold)?></strong></td>
                        <td><?=bs_fmt($b->revenue)?></td>
                        <td><?=bs_fmt($b->cogs??0)?></td>
                        <td><?=bs_fmt($b->profit??0)?></td>
                        <td><span class="bs-margin <?=$bm>30?'bs-margin-good':($bm>10?'bs-margin-ok':'bs-margin-low')?>"><?=$bm?>%</span></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php else: echo '<p style="color:var(--muted);padding:20px">No sales in this period.</p>'; endif; ?>
            </div>

            <div class="bs-report-card" style="grid-column:1/-1">
                <h3>🐌 Slow Movers — No sales in last 30 days</h3>
                <div style="overflow-x:auto">
                <table class="bs-table bs-table-sm">
                    <thead><tr><th>Title</th><th>Author</th><th>Genre</th><th>Stock</th><th>Units Sold (30d)</th><th>Stock Value</th></tr></thead>
                    <tbody>
                    <?php foreach($slow as $b): ?>
                    <tr>
                        <td><?=esc_html($b->title)?></td>
                        <td><?=esc_html($b->author)?></td>
                        <td><?=esc_html($b->genre)?></td>
                        <td class="<?=$b->stock_qty>20?'':($b->stock_qty>5?'bs-low-stock':'bs-out-stock')?>"><?=intval($b->stock_qty)?></td>
                        <td><?=intval($b->units_sold)?></td>
                        <td><?=bs_fmt($b->stock_qty * $b->cost_price)?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Staff Tab ─────────────────────────────────────────────────────── -->
    <div id="rpt-staff" class="bs-tab-content" style="display:none">
        <div class="bs-report-card">
            <h3>👤 Staff Performance</h3>
            <div style="overflow-x:auto">
            <table class="bs-table">
                <thead><tr><th>Staff Member</th><th>Transactions</th><th>Total Revenue</th><th>Avg. Sale</th><th>Discounts Given</th><th>Revenue Share</th></tr></thead>
                <tbody>
                <?php foreach($staff as $s):
                    $share = $rev>0 ? round((floatval($s->revenue)/$rev)*100,1) : 0;
                    $savg  = $s->sales_count>0 ? floatval($s->revenue)/$s->sales_count : 0;
                ?>
                <tr>
                    <td><strong><?=esc_html($s->staff_name)?></strong></td>
                    <td><?=intval($s->sales_count)?></td>
                    <td><?=bs_fmt($s->revenue)?></td>
                    <td><?=bs_fmt($savg)?></td>
                    <td><?=bs_fmt($s->discounts??0)?></td>
                    <td>
                        <div style="display:flex;align-items:center;gap:8px">
                            <div style="flex:1;background:#e5e7eb;border-radius:20px;height:8px;min-width:80px">
                                <div style="width:<?=$share?>%;background:var(--amber);height:8px;border-radius:20px"></div>
                            </div>
                            <span style="font-weight:600;font-size:.82rem"><?=$share?>%</span>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>

    <!-- ── Inventory Tab ─────────────────────────────────────────────────── -->
    <div id="rpt-inventory" class="bs-tab-content" style="display:none">
        <?php
        global $wpdb;
        if ( $branch_id ) {
            // Per-branch inventory: stock comes from bookshop_branch_stock,
            // joined back to books for prices & low-stock thresholds.
            $inv_stats = $wpdb->get_row($wpdb->prepare(
                "SELECT COUNT(*) as total_titles,
                        SUM(bst.qty) as total_units,
                        SUM(bst.qty * b.cost_price) as inventory_cost,
                        SUM(bst.qty * b.sell_price) as inventory_value,
                        SUM(bst.qty * (b.sell_price-b.cost_price)) as potential_profit,
                        SUM(CASE WHEN bst.qty=0 THEN 1 ELSE 0 END) as out_of_stock,
                        SUM(CASE WHEN bst.qty>0 AND bst.qty<=b.low_stock_threshold THEN 1 ELSE 0 END) as low_stock
                 FROM {$wpdb->prefix}bookshop_branch_stock bst
                 JOIN {$wpdb->prefix}bookshop_books b ON b.id=bst.book_id
                 WHERE bst.branch_id=%d AND b.status='active'",
                $branch_id
            ));
        } else {
            $inv_stats = $wpdb->get_row("SELECT
                COUNT(*) as total_titles,
                SUM(stock_qty) as total_units,
                SUM(stock_qty * cost_price) as inventory_cost,
                SUM(stock_qty * sell_price) as inventory_value,
                SUM(stock_qty * (sell_price-cost_price)) as potential_profit,
                SUM(CASE WHEN stock_qty=0 THEN 1 ELSE 0 END) as out_of_stock,
                SUM(CASE WHEN stock_qty>0 AND stock_qty<=low_stock_threshold THEN 1 ELSE 0 END) as low_stock
                FROM {$wpdb->prefix}bookshop_books WHERE status='active'");
        }
        ?>
        <div class="bs-stats-row" style="margin-bottom:20px">
            <?php
            bs_stat(intval($inv_stats->total_titles??0), 'Active Titles');
            bs_stat(intval($inv_stats->total_units??0),  'Total Units in Stock');
            bs_stat(bs_fmt($inv_stats->inventory_cost??0), 'Inventory Cost Value', 'accent');
            bs_stat(bs_fmt($inv_stats->inventory_value??0),'Inventory Sell Value');
            bs_stat(bs_fmt($inv_stats->potential_profit??0),'Potential Gross Profit');
            bs_stat(intval($inv_stats->out_of_stock??0), 'Out of Stock');
            bs_stat(intval($inv_stats->low_stock??0),    'Low Stock');
            ?>
        </div>
        <div class="bs-report-card">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
                <h3>📦 Full Inventory Valuation<?php if($branch_label): ?> <span style="font-size:.78rem;color:var(--muted);font-weight:400">— <?=esc_html($branch_label)?></span><?php endif; ?></h3>
                <a href="<?=$base_url?>?action=bs_export_inventory_csv<?=$branch_id?'&branch='.$branch_id:''?>" class="bs-btn bs-btn-secondary" style="font-size:.78rem">⬇ Export</a>
            </div>
            <?php
            if ( $branch_id ) {
                // bs_get_branch_stock with no book_id returns rows joined to books.
                // Re-shape them so the table loop below can stay almost identical.
                $rows = bs_get_branch_stock( $branch_id );
                $all_books = [];
                foreach ( $rows as $r ) {
                    $all_books[] = (object)[
                        'id'                  => intval($r->book_id),
                        'title'               => $r->title,
                        'author'              => $r->author,
                        'genre'               => $r->genre ?? '',
                        'cost_price'          => floatval($r->cost_price),
                        'sell_price'          => floatval($r->sell_price),
                        'stock_qty'           => intval($r->qty),
                        'low_stock_threshold' => intval($r->low_stock_threshold),
                    ];
                }
            } else {
                $all_books = bs_get_books(['status'=>'active','limit'=>500]);
            }
            ?>
            <div style="overflow-x:auto;max-height:500px;overflow-y:auto">
            <table class="bs-table bs-table-sm">
                <thead><tr><th>Title</th><th>Author</th><th>Genre</th><th>Stock</th><th>Cost/Unit</th><th>Sell/Unit</th><th>Margin</th><th>Stock Cost</th><th>Stock Value</th></tr></thead>
                <tbody>
                <?php foreach($all_books as $b):
                    $bm = $b->sell_price>0 ? round((($b->sell_price-$b->cost_price)/$b->sell_price)*100,1) : 0;
                ?>
                <tr>
                    <td><?=esc_html($b->title)?></td>
                    <td><?=esc_html($b->author)?></td>
                    <td><?=esc_html($b->genre)?></td>
                    <td class="<?=$b->stock_qty==0?'bs-out-stock':($b->stock_qty<=$b->low_stock_threshold?'bs-low-stock':'')?>"><?=intval($b->stock_qty)?></td>
                    <td><?=bs_fmt($b->cost_price)?></td>
                    <td><?=bs_fmt($b->sell_price)?></td>
                    <td><span class="bs-margin <?=$bm>30?'bs-margin-good':($bm>10?'bs-margin-ok':'bs-margin-low')?>"><?=$bm?>%</span></td>
                    <td><?=bs_fmt($b->stock_qty*$b->cost_price)?></td>
                    <td><?=bs_fmt($b->stock_qty*$b->sell_price)?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>

    <!-- ── Payments Tab ──────────────────────────────────────────────────── -->
    <div id="rpt-payments" class="bs-tab-content" style="display:none">
        <div class="bs-reports-grid">
            <div class="bs-report-card">
                <h3>💳 Revenue by Payment Method</h3>
                <canvas id="bs-pay-chart" height="260"></canvas>
                <script id="bs-pay-data" type="application/json"><?=esc_html(json_encode($pay))?></script>
            </div>
            <div class="bs-report-card">
                <h3>💳 Payment Method Breakdown</h3>
                <table class="bs-table bs-table-sm" style="margin-top:8px">
                    <thead><tr><th>Method</th><th>Transactions</th><th>Revenue</th><th>Share</th></tr></thead>
                    <tbody>
                    <?php foreach($pay as $p):
                        $share = $rev>0 ? round((floatval($p->revenue)/$rev)*100,1) : 0;
                    ?>
                    <tr>
                        <td><span class="bs-badge bs-badge-<?=esc_attr($p->payment_method)?>"><?=esc_html(ucfirst($p->payment_method))?></span></td>
                        <td><?=intval($p->count)?></td>
                        <td><?=bs_fmt($p->revenue)?></td>
                        <td>
                            <div style="display:flex;align-items:center;gap:6px">
                                <div style="width:80px;background:#e5e7eb;border-radius:20px;height:6px">
                                    <div style="width:<?=$share?>%;background:var(--amber);height:6px;border-radius:20px"></div>
                                </div>
                                <span><?=$share?>%</span>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php if ( $is_admin_view ): ?>
    <!-- ── Drift Tab (admin-only) ────────────────────────────────────────── -->
    <div id="rpt-drift" class="bs-tab-content" style="display:none">
        <div class="bs-report-card">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px;margin-bottom:12px">
                <div>
                    <h3>🔧 Stock Drift</h3>
                    <p style="font-size:.83rem;color:var(--muted);max-width:640px;margin-top:4px">
                        Books whose global <code>stock_qty</code> disagrees with the sum of their per-branch counts.
                        Common causes: pre-v4 sales (which only decremented the global counter), or books that
                        were never seeded at one or more branches.
                    </p>
                </div>
                <?php if ( $drift_count ): ?>
                <button class="bs-btn bs-btn-primary" id="bs-reconcile-all-drift" style="font-size:.82rem">
                    🔧 Reconcile all (<?=$drift_count?>)
                </button>
                <?php endif; ?>
            </div>
            <?php if ( ! $drift_count ): ?>
                <p style="color:var(--green,#2a7a3b);padding:24px;text-align:center;font-weight:600">
                    ✓ No drift detected — every active book's global stock matches its per-branch sum.
                </p>
            <?php else: ?>
                <div style="overflow-x:auto">
                <table class="bs-table bs-table-sm" id="bs-drift-table">
                    <thead><tr>
                        <th>Title</th><th>Author</th><th>ISBN</th>
                        <th title="bookshop_books.stock_qty">Global</th>
                        <th title="SUM(bookshop_branch_stock.qty) across active branches">Branch Sum</th>
                        <th>Δ</th>
                        <th title="Active branches with a row for this book / total active branches">Coverage</th>
                        <th>Action</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ( $drift_rows as $r ):
                        $delta    = intval($r->branch_sum) - intval($r->global_qty);
                        $delta_cls= $delta > 0 ? 'bs-margin-good' : 'bs-margin-low';
                        $coverage = intval($r->branches_with_row).'/'.intval($r->active_branches);
                    ?>
                    <tr data-book-id="<?=intval($r->id)?>">
                        <td><strong><?=esc_html($r->title)?></strong></td>
                        <td><?=esc_html($r->author)?></td>
                        <td><code><?=esc_html($r->isbn)?></code></td>
                        <td><?=intval($r->global_qty)?></td>
                        <td><?=intval($r->branch_sum)?></td>
                        <td><span class="bs-margin <?=$delta_cls?>"><?=($delta>0?'+':'').$delta?></span></td>
                        <td><?=esc_html($coverage)?></td>
                        <td>
                            <button class="bs-btn bs-btn-secondary bs-drift-reconcile" data-book-id="<?=intval($r->id)?>" data-title="<?=esc_attr($r->title)?>" style="font-size:.75rem;padding:4px 10px">
                                Set global = <?=intval($r->branch_sum)?>
                            </button>
                            <button class="bs-icon-btn bs-book-by-branch" data-id="<?=intval($r->id)?>" data-title="<?=esc_attr($r->title)?>" title="See by branch">🏪</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <p style="font-size:.75rem;color:var(--muted);margin-top:8px">
                    Reconciling sets the global <code>stock_qty</code> to the branch sum. The change is logged to the audit trail.
                    Limited to the first 500 drifted books — if you have more, reconcile-all and refresh.
                </p>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    </div><!-- .bs-wrap -->

    <!-- Print styles -->
    <style>
    @media print {
        #adminmenuwrap,#adminmenuback,#wpadminbar,#screen-meta,.bs-header a,.bs-toolbar,.bs-tabs,
        .no-print,#wpfooter{display:none!important}
        .bs-wrap{max-width:100%!important}
        .bs-tab-content{display:block!important}
        .bs-reports-grid{display:block!important}
        .bs-report-card{page-break-inside:avoid;margin-bottom:20px;box-shadow:none;border:1px solid #ccc}
        .bs-stats-row{flex-wrap:wrap}
    }
    </style>
    <?php
}
