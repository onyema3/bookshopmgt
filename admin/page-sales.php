<?php
if(!defined('ABSPATH'))exit;

function bs_page_sales(){
    $from=sanitize_text_field($_GET['from']??date('Y-m-01'));
    $to  =sanitize_text_field($_GET['to']??date('Y-m-d'));
    $sales=bs_get_sales(['from'=>$from,'to'=>$to,'limit'=>500,'status'=>'completed']);
    $voided=bs_get_sales(['from'=>$from,'to'=>$to,'limit'=>100,'status'=>'voided']);
    $rev=array_sum(array_column($sales,'total'));
    $tax=array_sum(array_column($sales,'tax'));

    // Reservations
    global $wpdb;
    $reservations=$wpdb->get_results("SELECT r.*,b.title AS book_title_linked FROM {$wpdb->prefix}bookshop_reservations r LEFT JOIN {$wpdb->prefix}bookshop_books b ON b.id=r.book_id ORDER BY r.created_at DESC LIMIT 100");
    $shifts=bs_get_shifts(['limit'=>20]);
    ?>
    <div class="wrap bs-wrap">
    <div class="bs-header">
        <h1>💳 Sales</h1>
        <div style="display:flex;gap:8px">
            <a href="<?=admin_url("admin-ajax.php?action=bs_export_sales_csv&from=$from&to=$to")?>" class="bs-btn bs-btn-secondary">⬇ Export CSV</a>
        </div>
    </div>

    <form method="get" class="bs-toolbar">
        <input type="hidden" name="page" value="bookshop-sales">
        <label>From <input type="date" name="from" value="<?=esc_attr($from)?>" class="bs-input-sm"></label>
        <label>To <input type="date" name="to" value="<?=esc_attr($to)?>" class="bs-input-sm"></label>
        <button class="bs-btn bs-btn-secondary">Filter</button>
    </form>

    <div class="bs-stats-row">
        <?php bs_stat(count($sales),'Transactions');
        bs_stat(bs_fmt($rev),'Revenue',true);
        bs_stat(bs_fmt($tax),'Tax Collected');
        bs_stat(count($sales)?bs_fmt($rev/count($sales)):'—','Avg. Sale');
        bs_stat(count($voided),'Voided'); ?>
    </div>

    <div class="bs-tabs">
        <button class="bs-tab active" data-tab="sales-tab">Sales Log</button>
        <button class="bs-tab" data-tab="shifts-tab">Shifts</button>
        <button class="bs-tab" data-tab="reservations-tab">Reservations</button>
    </div>

    <div id="sales-tab" class="bs-tab-content">
    <table class="bs-table">
        <thead><tr><th>Ref</th><th>Date</th><th>Staff</th><th>Customer</th><th>Payment</th><th>Subtotal</th><th>Discount</th><th>Tax</th><th>Total</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach($sales as $s): ?>
        <tr>
            <td><code><?=esc_html($s->sale_ref)?></code></td>
            <td><?=esc_html(wp_date('d M Y H:i',strtotime($s->created_at)))?></td>
            <td><?=esc_html($s->staff_name)?></td>
            <td><?=esc_html($s->customer_name??'Walk-in')?></td>
            <td><span class="bs-badge bs-badge-<?=esc_attr($s->payment_method)?>"><?=esc_html($s->payment_method)?></span></td>
            <td><?=bs_fmt($s->subtotal)?></td>
            <td><?=$s->discount>0||$s->promo_discount>0?bs_fmt($s->discount+$s->promo_discount):'-'?></td>
            <td><?=$s->tax>0?bs_fmt($s->tax):'-'?></td>
            <td><strong><?=bs_fmt($s->total)?></strong></td>
            <td>
                <button class="bs-btn-link bs-view-sale-items" data-id="<?=esc_attr($s->id)?>" data-ref="<?=esc_attr($s->sale_ref)?>">Items</button>
                <a class="bs-btn-link" href="<?=esc_url(home_url('/?bookshop_print_receipt=1&sale_id='.intval($s->id)))?>" target="_blank" rel="noopener" title="Open a printable duplicate receipt for this sale" style="color:#1565c0">Print</a>
                <button class="bs-btn-link bs-refund-sale" data-id="<?=esc_attr($s->id)?>" data-ref="<?=esc_attr($s->sale_ref)?>" style="color:#e67e22">Refund</button>
                <button class="bs-btn-link bs-void-sale" data-id="<?=esc_attr($s->id)?>" style="color:#c0392b">Void</button>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>

    <div id="shifts-tab" class="bs-tab-content" style="display:none">
    <table class="bs-table">
        <thead><tr><th>Staff</th><th>Opened</th><th>Closed</th><th>Opening Cash</th><th>Expected Cash</th><th>Closing Cash</th><th>Variance</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach($shifts as $sh): ?>
        <tr>
            <td><?=esc_html($sh->staff_name)?></td>
            <td><?=esc_html(wp_date('d M H:i',strtotime($sh->opened_at)))?></td>
            <?php $sh_closed = $sh->closed_at ? esc_html(wp_date('d M H:i',strtotime($sh->closed_at))) : '-'; ?><td><?=$sh_closed?></td>
            <td><?=bs_fmt($sh->opening_cash)?></td>
            <td><?=$sh->expected_cash!==null?bs_fmt($sh->expected_cash):'-'?></td>
            <td><?=$sh->closing_cash!==null?bs_fmt($sh->closing_cash):'-'?></td>
            <td><?php if($sh->variance!==null){
                $v=floatval($sh->variance);
                $cls=$v<0?'style="color:#c0392b"':($v>0?'style="color:#2a7a3b"':'');
                echo "<span $cls>".bs_fmt($v)."</span>";
            } else echo '-'; ?></td>
            <td><span class="bs-badge bs-badge-<?=$sh->status?>"><?=$sh->status?></span></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>

    <div id="reservations-tab" class="bs-tab-content" style="display:none">
    <table class="bs-table">
        <thead><tr><th>Customer</th><th>Phone</th><th>Book</th><th>ISBN</th><th>Qty</th><th>Date</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach($reservations as $r): ?>
        <tr>
            <td><?=esc_html($r->customer_name)?></td>
            <td><?=esc_html($r->customer_phone)?></td>
            <td><?=esc_html($r->book_title)?></td>
            <td><?=esc_html($r->isbn)?></td>
            <td><?=intval($r->qty)?></td>
            <td><?=esc_html(wp_date('d M Y',strtotime($r->created_at)))?></td>
            <td><span class="bs-badge bs-badge-<?=esc_attr($r->status)?>"><?=$r->status?></span></td>
            <td>
                <select class="bs-res-status-select bs-select-xs" data-id="<?=esc_attr($r->id)?>">
                    <?php foreach(['pending','notified','fulfilled','cancelled'] as $st) echo "<option value='$st'".selected($r->status,$st,false).">$st</option>"; ?>
                </select>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    </div>

    <?php
    ob_start(); ?>
    <div id="bs-sale-items-body">Loading...</div>
    <?php $body=ob_get_clean();
    $footer="<button class='bs-btn bs-btn-secondary bs-modal-close'>Close</button>";
    bs_modal('bs-sale-items-modal','Sale Items',$body,$footer,'lg');
    ?>
<?php
}
