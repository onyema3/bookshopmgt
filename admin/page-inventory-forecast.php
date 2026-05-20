<?php
if(!defined('ABSPATH'))exit;

function bs_page_inventory_forecast(){
    $cur = bs_currency();
    $lead_days   = intval(get_option('bookshop_forecast_lead_days', 14));
    $target_days = intval(get_option('bookshop_forecast_target_cover_days', 30));
    $lookback    = intval(get_option('bookshop_forecast_lookback_weeks', 8));

    // Branch filter
    $branches = function_exists('bs_user_report_branches') ? bs_user_report_branches() : [];
    $branch_id = intval($_GET['branch'] ?? 0);
    if($branch_id && function_exists('bs_validate_report_branch')){
        $validated = bs_validate_report_branch($branch_id);
        if($validated === false) $branch_id = 0;
        else $branch_id = $validated;
    }

    $genre_filter = sanitize_text_field($_GET['genre'] ?? '');
    $search       = sanitize_text_field($_GET['search'] ?? '');
    $page_num     = max(1, intval($_GET['paged'] ?? 1));
    $per_page     = 50;
    $offset       = ($page_num - 1) * $per_page;
    $order_by     = sanitize_text_field($_GET['orderby'] ?? 'days_cover');
    $order        = strtoupper(sanitize_text_field($_GET['order'] ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC';

    $forecast = bs_get_inventory_forecast([
        'branch_id' => $branch_id,
        'genre'     => $genre_filter,
        'search'    => $search,
        'limit'     => $per_page,
        'offset'    => $offset,
        'order_by'  => $order_by,
        'order'     => $order,
    ]);

    $rows  = $forecast['rows'];
    $total = $forecast['total'];
    $pages = ceil($total / $per_page);

    $summary = bs_forecast_summary($branch_id);
    $genres  = bs_genres();
    ?>
    <div class="wrap bs-wrap">
    <div class="bs-header">
        <h1>📈 Inventory Forecast</h1>
        <button class="bs-btn bs-btn-secondary" onclick="document.getElementById('forecast-settings-modal').style.display='flex'">⚙️ Settings</button>
    </div>

    <!-- Summary stats -->
    <div class="bs-stats-row">
        <?php bs_stat($summary->total_books, 'Active Books'); ?>
        <?php bs_stat($summary->critical_count, 'Critical (Stock-out Risk)', true); ?>
        <?php bs_stat($summary->warning_count, 'Warning'); ?>
        <?php bs_stat($summary->low_count, 'Low Stock'); ?>
        <?php bs_stat(bs_fmt($summary->total_reorder_cost), 'Est. Reorder Cost'); ?>
        <?php bs_stat($summary->avg_weekly_velocity . '/wk', 'Avg Velocity'); ?>
    </div>

    <p style="font-size:.8rem;color:var(--muted);margin:8px 0 14px">
        Based on sales over the last <strong><?=$lookback?> weeks</strong>.
        Lead time: <strong><?=$lead_days?> days</strong> | Target cover: <strong><?=$target_days?> days</strong>.
    </p>

    <!-- Filters -->
    <form method="get" class="bs-toolbar" style="flex-wrap:wrap;gap:6px;margin-bottom:12px">
        <input type="hidden" name="page" value="bookshop-forecast">
        <input type="text" name="search" value="<?=esc_attr($search)?>" placeholder="Search title/author/ISBN..." class="bs-input" style="width:200px">
        <select name="genre" class="bs-input" style="min-width:140px">
            <option value="">All Genres</option>
            <?php foreach($genres as $g): ?>
            <option value="<?=esc_attr($g)?>" <?=selected($genre_filter,$g,false)?>><?=esc_html($g)?></option>
            <?php endforeach; ?>
        </select>
        <?php if(count($branches) > 1): ?>
        <select name="branch" class="bs-input" style="min-width:140px">
            <?php if(function_exists('bs_user_is_admin') && bs_user_is_admin()): ?>
            <option value="0">All Branches</option>
            <?php endif; ?>
            <?php foreach($branches as $b): ?>
            <option value="<?=intval($b->id)?>" <?=selected($branch_id,intval($b->id),false)?>><?=esc_html($b->name)?></option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <button type="submit" class="bs-btn bs-btn-primary">Filter</button>
        <a href="?page=bookshop-forecast" class="bs-btn bs-btn-secondary">Reset</a>
    </form>

    <!-- Legend -->
    <div style="display:flex;gap:14px;margin-bottom:10px;font-size:.75rem">
        <span><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#c0392b;margin-right:3px"></span> Critical — will stock-out before delivery</span>
        <span><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#e67e22;margin-right:3px"></span> Warning — less than 1 week buffer</span>
        <span><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#f39c12;margin-right:3px"></span> Low — below threshold</span>
        <span><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#27ae60;margin-right:3px"></span> OK</span>
    </div>

    <!-- Table -->
    <div style="overflow-x:auto">
    <table class="bs-table">
        <thead>
            <tr>
                <?php
                $cols = [
                    'title'             => 'Book',
                    'stock_qty'         => 'Stock',
                    'total_sold'        => 'Sold ('.$lookback.'wk)',
                    'weekly_velocity'   => 'Velocity/wk',
                    'days_cover'        => 'Days Cover',
                    'suggested_reorder' => 'Suggested Reorder',
                    'reorder_cost'      => 'Reorder Cost',
                    'status'            => 'Status',
                ];
                foreach($cols as $col_key => $col_label):
                    $is_active = ($order_by === $col_key);
                    $next_order = ($is_active && $order === 'ASC') ? 'DESC' : 'ASC';
                    $arrow = $is_active ? ($order === 'ASC' ? ' ▲' : ' ▼') : '';
                    $url = add_query_arg(['orderby'=>$col_key,'order'=>$next_order]);
                ?>
                <th><a href="<?=esc_url($url)?>" style="text-decoration:none;color:inherit"><?=$col_label?><?=$arrow?></a></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
        <?php if(empty($rows)): ?>
            <tr><td colspan="8" style="text-align:center;padding:30px;color:var(--muted)">No books found.</td></tr>
        <?php else: foreach($rows as $r):
            $status_colors = ['critical'=>'#c0392b','warning'=>'#e67e22','low'=>'#f39c12','ok'=>'#27ae60'];
            $bg = $r->status === 'critical' ? 'rgba(192,57,43,.06)' : ($r->status === 'warning' ? 'rgba(230,126,34,.05)' : '');
        ?>
            <tr style="<?=$bg?'background:'.$bg:''?>">
                <td>
                    <strong style="font-size:.83rem"><?=esc_html($r->title)?></strong>
                    <div style="font-size:.72rem;color:var(--muted)"><?=esc_html($r->author)?><?=$r->isbn?' &middot; '.$r->isbn:''?></div>
                </td>
                <td style="font-weight:600;<?=$r->stock_qty <= $r->low_stock_threshold?'color:#c0392b':''?>"><?=$r->stock_qty?></td>
                <td><?=$r->total_sold?></td>
                <td><strong><?=$r->weekly_velocity?></strong></td>
                <td>
                    <?php if($r->days_cover >= 9999): ?>
                        <span style="color:var(--muted)">&#8734;</span>
                    <?php else: ?>
                        <strong style="color:<?=$status_colors[$r->status]?>"><?=$r->days_cover?>d</strong>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if($r->suggested_reorder > 0): ?>
                        <strong style="color:#1565c0"><?=$r->suggested_reorder?> units</strong>
                    <?php else: ?>
                        <span style="color:var(--muted)">&mdash;</span>
                    <?php endif; ?>
                </td>
                <td><?=$r->reorder_cost > 0 ? bs_fmt($r->reorder_cost) : '&mdash;'?></td>
                <td>
                    <span style="display:inline-block;padding:2px 8px;border-radius:12px;font-size:.72rem;font-weight:600;color:#fff;background:<?=$status_colors[$r->status]?>">
                        <?=ucfirst($r->status)?>
                    </span>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
    </div>

    <!-- Pagination -->
    <?php if($pages > 1): ?>
    <div style="display:flex;justify-content:center;gap:4px;margin:16px 0">
        <?php for($p=1;$p<=$pages;$p++):
            $cls = $p===$page_num ? 'bs-btn bs-btn-primary' : 'bs-btn bs-btn-secondary';
            $url = add_query_arg('paged', $p);
        ?>
        <a href="<?=esc_url($url)?>" class="<?=$cls?>" style="min-width:32px;text-align:center;padding:4px 8px;font-size:.8rem"><?=$p?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>

    </div><!-- .wrap -->

    <!-- Settings Modal -->
    <?php
    $settings_body = '
    <div style="display:grid;gap:14px">
        <div>
            <label style="font-size:.83rem;font-weight:600;display:block;margin-bottom:4px">Lookback Period (weeks)</label>
            <input type="number" id="fc-lookback" value="'.$lookback.'" min="1" max="52" class="bs-input" style="width:100%">
            <span style="font-size:.72rem;color:var(--muted)">How many weeks of sales history to analyze</span>
        </div>
        <div>
            <label style="font-size:.83rem;font-weight:600;display:block;margin-bottom:4px">Lead Time (days)</label>
            <input type="number" id="fc-lead" value="'.$lead_days.'" min="1" max="90" class="bs-input" style="width:100%">
            <span style="font-size:.72rem;color:var(--muted)">Average days between ordering and receiving stock</span>
        </div>
        <div>
            <label style="font-size:.83rem;font-weight:600;display:block;margin-bottom:4px">Target Cover (days)</label>
            <input type="number" id="fc-target" value="'.$target_days.'" min="1" max="180" class="bs-input" style="width:100%">
            <span style="font-size:.72rem;color:var(--muted)">How many days of stock to maintain after reorder arrives</span>
        </div>
    </div>';
    $settings_footer = '<button class="bs-btn bs-btn-secondary" onclick="document.getElementById(\'forecast-settings-modal\').style.display=\'none\'">Cancel</button>
        <button class="bs-btn bs-btn-primary" id="btn-save-forecast-settings">Save Settings</button>';
    bs_modal('forecast-settings-modal', '⚙️ Forecast Settings', $settings_body, $settings_footer);
    ?>

    <script>
    jQuery(function($){
        $('#btn-save-forecast-settings').on('click', function(){
            var btn = $(this);
            btn.prop('disabled',true).text('Saving...');
            $.post(BSAdmin.ajax_url, {
                action: 'bs_save_forecast_settings',
                nonce: BSAdmin.nonce,
                lead_days: $('#fc-lead').val(),
                target_cover_days: $('#fc-target').val(),
                lookback_weeks: $('#fc-lookback').val()
            }, function(res){
                btn.prop('disabled',false).text('Save Settings');
                if(res.success){
                    alert('Settings saved. Refreshing...');
                    location.reload();
                } else {
                    alert(res.data || 'Error saving settings');
                }
            });
        });
    });
    </script>
    <?php
}
