<?php
if(!defined('ABSPATH'))exit;

function bs_page_customers(){
    $customers=bs_get_customers(['limit'=>200]);
    $loyalty_val=floatval(get_option('bookshop_loyalty_value',10));
    global $wpdb;
    $reservations=$wpdb->get_results("SELECT * FROM {$wpdb->prefix}bookshop_reservations ORDER BY created_at DESC LIMIT 50");
    ?>
    <div class="wrap bs-wrap">
    <div class="bs-header">
        <h1>👥 Customers</h1>
        <button class="bs-btn bs-btn-primary" id="bs-add-customer">+ Add Customer</button>
    </div>

    <div class="bs-toolbar">
        <input type="text" id="bs-cust-search" placeholder="🔍 Search name, phone, email..." class="bs-search">
    </div>

    <div class="bs-tabs">
        <button class="bs-tab active" data-tab="cust-tab">Customer List</button>
        <button class="bs-tab" data-tab="res-tab">Reservations / Wishlist</button>
    </div>

    <div id="cust-tab" class="bs-tab-content">
    <table class="bs-table" id="bs-cust-table">
        <thead><tr><th>Name</th><th>Phone</th><th>Email</th><th>Birthday</th><th>Loyalty Pts</th><th>Pts Value</th><th>Credit</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach($customers as $c): ?>
        <tr data-name="<?=esc_attr(strtolower($c->name))?>" data-phone="<?=esc_attr($c->phone)?>" data-email="<?=esc_attr($c->email)?>">
            <td><strong><?=esc_html($c->name)?></strong></td>
            <td><?=esc_html($c->phone)?></td>
            <td><?=esc_html($c->email)?></td>
            <td><?=$c->birthday?esc_html(wp_date('d M',strtotime($c->birthday))):'-'?></td>
            <td><strong><?=intval($c->loyalty_points)?></strong> pts</td>
            <td><?=bs_fmt($c->loyalty_points*$loyalty_val)?></td>
            <td><?=bs_fmt($c->credit_balance)?></td>
            <td><span class="bs-badge bs-badge-<?=$c->status?>"><?=$c->status?></span></td>
            <td>
                <button class="bs-icon-btn bs-edit-customer" data-id="<?=esc_attr($c->id)?>" title="Edit">✏️</button>
                <button class="bs-icon-btn bs-view-cust-history" data-id="<?=esc_attr($c->id)?>" data-name="<?=esc_attr($c->name)?>" title="History">📋</button>
                <button class="bs-icon-btn bs-add-credit-btn" data-id="<?=esc_attr($c->id)?>" data-name="<?=esc_attr($c->name)?>" title="Add Credit">💰</button>
                <button class="bs-icon-btn bs-adjust-loyalty-btn" data-id="<?=esc_attr($c->id)?>" data-name="<?=esc_attr($c->name)?>" title="Adjust Points">⭐</button>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>

    <div id="res-tab" class="bs-tab-content" style="display:none">
    <table class="bs-table">
        <thead><tr><th>Customer</th><th>Phone</th><th>Book</th><th>ISBN</th><th>Qty</th><th>Notes</th><th>Date</th><th>Status</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach($reservations as $r): ?>
        <tr>
            <td><?=esc_html($r->customer_name)?></td>
            <td><?=esc_html($r->customer_phone)?></td>
            <td><?=esc_html($r->book_title)?></td>
            <td><?=esc_html($r->isbn)?></td>
            <td><?=intval($r->qty)?></td>
            <td><?=esc_html($r->notes)?></td>
            <td><?=esc_html(wp_date('d M Y',strtotime($r->created_at)))?></td>
            <td><span class="bs-badge bs-badge-<?=esc_attr($r->status)?>"><?=$r->status?></span></td>
            <td><select class="bs-res-status-select bs-select-xs" data-id="<?=esc_attr($r->id)?>">
                <?php foreach(['pending','notified','fulfilled','cancelled'] as $st) echo "<option value='$st'".selected($r->status,$st,false).">$st</option>"; ?>
            </select></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    </div>

    <?php
    ob_start(); ?>
    <input type="hidden" id="bs-cust-id">
    <div class="bs-form-grid">
        <div class="bs-form-group"><label>Full Name *</label><input type="text" id="bs-cf-name" class="bs-input"></div>
        <div class="bs-form-group"><label>Phone</label><input type="text" id="bs-cf-phone" class="bs-input"></div>
        <div class="bs-form-group"><label>Email</label><input type="email" id="bs-cf-email" class="bs-input"></div>
        <div class="bs-form-group"><label>Birthday</label><input type="date" id="bs-cf-birthday" class="bs-input"></div>
        <div class="bs-form-group bs-span2"><label>Address</label><textarea id="bs-cf-address" class="bs-input" rows="2"></textarea></div>
        <div class="bs-form-group bs-span2"><label>Notes</label><textarea id="bs-cf-notes" class="bs-input" rows="2"></textarea></div>
        <div class="bs-form-group"><label>Status</label><select id="bs-cf-status" class="bs-input"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
    </div>
    <?php $body=ob_get_clean();
    bs_modal('bs-cust-modal','Add / Edit Customer',$body,
        "<button class='bs-btn bs-btn-secondary bs-modal-close'>Cancel</button><button class='bs-btn bs-btn-primary' id='bs-save-customer'>Save</button>");

    ob_start(); ?>
    <div id="bs-cust-history-body">Loading...</div>
    <?php $body=ob_get_clean();
    bs_modal('bs-cust-history-modal','Purchase History',$body,'','lg');
    ?>
<?php
}
