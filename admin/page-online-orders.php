<?php
if(!defined('ABSPATH'))exit;

function bs_page_online_orders(){
    $orders=bs_get_online_orders(['limit'=>200]);
    $statuses=['pending','paid','processing','ready','completed','cancelled'];
    global $wpdb;
    $api_key=get_option('bookshop_api_key','');
    $webhooks=$wpdb->get_results("SELECT * FROM {$wpdb->prefix}bookshop_webhooks ORDER BY created_at DESC");
    ?>
    <div class="wrap bs-wrap">
    <div class="bs-header"><h1>🛒 Online Orders & API</h1></div>

    <div class="bs-tabs">
        <button class="bs-tab active" data-tab="orders-tab">Online Orders</button>
        <button class="bs-tab" data-tab="api-tab">REST API & Webhooks</button>
    </div>

    <div id="orders-tab" class="bs-tab-content">
    <?php
    $pending=count(array_filter($orders,function($o){return $o->status==='pending'||$o->status==='paid';}));
    $revenue=array_sum(array_column($orders,'total'));
    ?>
    <div class="bs-stats-row">
        <?php bs_stat(count($orders),'Total Orders');
        bs_stat($pending,'Pending/Paid','accent');
        bs_stat(bs_fmt($revenue),'Online Revenue'); ?>
    </div>
    <table class="bs-table">
        <thead><tr><th>Ref</th><th>Date</th><th>Customer</th><th>Phone</th><th>Type</th><th>Total</th><th>Payment</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach($orders as $o):
            $items=json_decode($o->items_data,true)??[];
        ?>
        <tr>
            <td><code><?=esc_html($o->ref)?></code></td>
            <td><?=esc_html(wp_date('d M Y H:i',strtotime($o->created_at)))?></td>
            <td><strong><?=esc_html($o->customer_name)?></strong><br><small><?=esc_html($o->customer_email)?></small></td>
            <td><?=esc_html($o->customer_phone)?></td>
            <?php $o_type_cls = ($o->type==='delivery') ? 'bs-badge-card' : 'bs-badge-cash'; ?>
            <td><span class="bs-badge <?=$o_type_cls?>"><?=$o->type?></span></td>
            <td><strong><?=bs_fmt($o->total)?></strong></td>
            <td><?=$o->payment_gateway?'<span class="bs-badge bs-badge-active">'.esc_html($o->payment_gateway).'</span>':'<span class="bs-badge bs-badge-inactive">None</span>'?></td>
            <td>
                <select class="bs-order-status-select bs-select-xs" data-id="<?=esc_attr($o->id)?>" onchange="bsUpdateOrderStatus(this)">
                    <?php foreach($statuses as $s) echo "<option value='$s'".selected($o->status,$s,false).">$s</option>"; ?>
                </select>
            </td>
            <td>
                <button class="bs-btn-link bs-view-online-order" data-id="<?=esc_attr($o->id)?>"
                    data-ref="<?=esc_attr($o->ref)?>" data-items='<?=esc_attr(json_encode($items))?>'>Items</button>
            </td>
        </tr>
        <?php endforeach; if(empty($orders)) echo '<tr><td colspan="9" style="text-align:center;color:#999;padding:24px">No online orders yet. Add [bookshop_catalogue] shortcode to a page to get started.</td></tr>'; ?>
        </tbody>
    </table>
    </div>

    <div id="api-tab" class="bs-tab-content" style="display:none">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;max-width:1100px">
        <div class="bs-card">
            <h3 style="font-family:'Playfair Display',serif;margin-bottom:14px">🔑 API Key</h3>
            <p style="font-size:.85rem;color:var(--muted);margin-bottom:12px">Use this key to authenticate REST API requests via the <code>X-Bookshop-Key</code> header.</p>
            <div style="display:flex;gap:8px;align-items:center">
                <input type="text" id="bs-api-key-display" value="<?=esc_attr($api_key)?>" class="bs-input" readonly style="flex:1;font-family:monospace;font-size:.8rem">
                <button class="bs-btn bs-btn-secondary" id="bs-gen-api-key">Regenerate</button>
            </div>
            <p style="font-size:.78rem;color:var(--muted);margin-top:8px">Base URL: <code><?=esc_html(home_url('/wp-json/bookshop/v1/'))?></code></p>
            <div style="margin-top:14px;background:var(--warm);border-radius:8px;padding:12px;font-size:.78rem">
                <strong>Example endpoints:</strong><br>
                <code>GET /books</code> — List all books<br>
                <code>GET /books/{id}</code> — Get a book<br>
                <code>POST /books</code> — Create a book<br>
                <code>PATCH /stock/{id}</code> — Update stock<br>
                <code>GET /sales?from=2024-01-01</code> — Sales report<br>
                <code>GET /reports/summary</code> — Summary stats<br>
                <code>GET /customers</code> — Customer list
            </div>
        </div>
        <div class="bs-card">
            <h3 style="font-family:'Playfair Display',serif;margin-bottom:14px">🔔 Webhooks</h3>
            <p style="font-size:.85rem;color:var(--muted);margin-bottom:12px">Webhooks fire a POST to your URL whenever an event occurs. Works with Zapier, Make, and any HTTP endpoint.</p>
            <div style="display:flex;gap:6px;margin-bottom:12px;flex-wrap:wrap">
                <input type="url" id="wh-url" class="bs-input" placeholder="https://your-endpoint.com/hook" style="flex:2;min-width:0">
                <select id="wh-event" class="bs-input" style="flex:1;min-width:120px">
                    <option value="sale.completed">sale.completed</option>
                    <option value="stock.low">stock.low</option>
                    <option value="*">All events</option>
                </select>
                <input type="text" id="wh-secret" class="bs-input" placeholder="Secret (optional)" style="flex:1;min-width:0">
                <button class="bs-btn bs-btn-primary" id="bs-add-webhook">Add</button>
            </div>
            <table class="bs-table bs-table-sm">
                <thead><tr><th>URL</th><th>Event</th><th>Status</th><th></th></tr></thead>
                <tbody id="wh-list">
                <?php foreach($webhooks as $wh): ?>
                <tr data-id="<?=esc_attr($wh->id)?>">
                    <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?=esc_html($wh->url)?></td>
                    <td><code><?=esc_html($wh->event)?></code></td>
                    <td><span class="bs-badge bs-badge-<?=esc_attr($wh->status)?>"><?=$wh->status?></span></td>
                    <td><button class="bs-btn-link bs-delete-webhook" data-id="<?=esc_attr($wh->id)?>">Delete</button></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Download backup -->
    <div class="bs-card" style="max-width:540px;margin-top:20px">
        <h3 style="font-family:'Playfair Display',serif;margin-bottom:10px">💾 Database Backup</h3>
        <p style="font-size:.85rem;color:var(--muted);margin-bottom:12px">Last backup: <strong><?=esc_html(get_option('bookshop_last_backup','Never'))?></strong></p>
        <div style="display:flex;gap:8px">
            <a href="<?=admin_url('admin-ajax.php?action=bs_download_backup')?>" class="bs-btn bs-btn-secondary">⬇ Download SQL Backup</a>
        </div>
    </div>
    </div>

    <div id="bs-online-order-modal" class="bs-modal" style="display:none">
        <div class="bs-modal-box">
            <div class="bs-modal-header"><h2 id="bs-oo-modal-title">Order Items</h2><button class="bs-modal-close">✕</button></div>
            <div class="bs-modal-body" id="bs-oo-modal-body"></div>
        </div>
    </div>

    <script>
    function bsUpdateOrderStatus(sel){
        jQuery.post(BSAdmin.ajax_url,{action:'bs_update_online_order_status',id:jQuery(sel).data('id'),status:jQuery(sel).val(),nonce:BSAdmin.nonce});
    }
    </script>
<?php
}
