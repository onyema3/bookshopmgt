<?php
if(!defined('ABSPATH'))exit;

function bs_page_suppliers(){
    $suppliers=bs_get_suppliers();
    $pos=bs_get_purchase_orders(['limit'=>100]);
    ?>
    <div class="wrap bs-wrap">
    <div class="bs-header">
        <h1>🚚 Suppliers & Purchase Orders</h1>
        <div style="display:flex;gap:8px">
            <button class="bs-btn bs-btn-secondary" id="bs-add-supplier">+ Add Supplier</button>
            <button class="bs-btn bs-btn-primary" id="bs-create-po">+ New Purchase Order</button>
        </div>
    </div>

    <div class="bs-tabs">
        <button class="bs-tab active" data-tab="sup-tab">Suppliers</button>
        <button class="bs-tab" data-tab="po-tab">Purchase Orders</button>
    </div>

    <div id="sup-tab" class="bs-tab-content">
    <table class="bs-table">
        <thead><tr><th>Name</th><th>Contact</th><th>Email</th><th>Phone</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach($suppliers as $s): ?>
        <tr>
            <td><strong><?=esc_html($s->name)?></strong></td>
            <td><?=esc_html($s->contact_name)?></td>
            <td><?=esc_html($s->email)?></td>
            <td><?=esc_html($s->phone)?></td>
            <td><span class="bs-badge bs-badge-<?=$s->status?>"><?=$s->status?></span></td>
            <td><button class="bs-icon-btn bs-edit-supplier" data-id="<?=esc_attr($s->id)?>">✏️</button></td>
        </tr>
        <?php endforeach; if(empty($suppliers)): ?>
        <tr><td colspan="6" style="text-align:center;color:#999;padding:24px">No suppliers yet. Add one to get started.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>

    <div id="po-tab" class="bs-tab-content" style="display:none">
    <table class="bs-table">
        <thead><tr><th>PO Ref</th><th>Supplier</th><th>Created by</th><th>Total</th><th>Status</th><th>Created</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach($pos as $po): ?>
        <tr>
            <td><code><?=esc_html($po->po_ref)?></code></td>
            <td><?=esc_html($po->supplier_name??'—')?></td>
            <td><?=esc_html($po->staff_name)?></td>
            <td><?=bs_fmt($po->total)?></td>
            <td><span class="bs-badge bs-badge-<?=esc_attr($po->status)?>"><?=$po->status?></span></td>
            <td><?=esc_html(wp_date('d M Y',strtotime($po->created_at)))?></td>
            <td>
                <button class="bs-btn-link bs-view-po" data-id="<?=esc_attr($po->id)?>" data-ref="<?=esc_attr($po->po_ref)?>">View</button>
                <?php if($po->status==='ordered'||$po->status==='draft'): ?>
                <button class="bs-btn-link bs-receive-po" data-id="<?=esc_attr($po->id)?>" data-ref="<?=esc_attr($po->po_ref)?>">Receive</button>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; if(empty($pos)): ?>
        <tr><td colspan="7" style="text-align:center;color:#999;padding:24px">No purchase orders yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
    </div>

    <?php
    // Supplier modal
    ob_start(); ?>
    <input type="hidden" id="bs-sup-id">
    <div class="bs-form-grid">
        <div class="bs-form-group bs-span2"><label>Company Name *</label><input type="text" id="bs-sf-name" class="bs-input"></div>
        <div class="bs-form-group"><label>Contact Person</label><input type="text" id="bs-sf-contact" class="bs-input"></div>
        <div class="bs-form-group"><label>Email</label><input type="email" id="bs-sf-email" class="bs-input"></div>
        <div class="bs-form-group"><label>Phone</label><input type="text" id="bs-sf-phone" class="bs-input"></div>
        <div class="bs-form-group bs-span2"><label>Address</label><textarea id="bs-sf-address" class="bs-input" rows="2"></textarea></div>
        <div class="bs-form-group bs-span2"><label>Notes</label><textarea id="bs-sf-notes" class="bs-input" rows="2"></textarea></div>
    </div>
    <?php $body=ob_get_clean();
    bs_modal('bs-sup-modal','Add / Edit Supplier',$body,
        "<button class='bs-btn bs-btn-secondary bs-modal-close'>Cancel</button><button class='bs-btn bs-btn-primary' id='bs-save-supplier'>Save</button>");

    // Create PO modal
    ob_start(); ?>
    <div class="bs-form-grid">
        <div class="bs-form-group bs-span2"><label>Supplier</label>
            <select id="bs-po-supplier" class="bs-input">
                <option value="">— No Supplier —</option>
                <?php foreach($suppliers as $s) echo "<option value='{$s->id}'>".esc_html($s->name)."</option>"; ?>
            </select>
        </div>
        <div class="bs-form-group bs-span2"><label>Notes</label><textarea id="bs-po-notes" class="bs-input" rows="2"></textarea></div>
    </div>
    <h4 style="margin:16px 0 8px">Items</h4>
    <div id="bs-po-items">
        <div class="bs-po-item" style="display:flex;gap:8px;margin-bottom:8px">
            <input type="text" placeholder="Book title / ISBN search" class="bs-input bs-po-book-search" style="flex:2">
            <input type="hidden" class="bs-po-book-id">
            <input type="number" placeholder="Qty" class="bs-input bs-po-qty" min="1" value="1" style="width:70px">
            <input type="number" placeholder="Cost" class="bs-input bs-po-cost" step="0.01" min="0" style="width:100px">
            <button class="bs-btn bs-btn-secondary bs-po-remove-item" type="button">✕</button>
        </div>
    </div>
    <button class="bs-btn bs-btn-secondary" id="bs-po-add-item" type="button">+ Add Item</button>
    <div style="margin-top:12px;font-weight:700" id="bs-po-total-display">Total: <?=bs_currency()?>0.00</div>
    <?php $body=ob_get_clean();
    bs_modal('bs-po-modal','New Purchase Order',$body,
        "<button class='bs-btn bs-btn-secondary bs-modal-close'>Cancel</button><button class='bs-btn bs-btn-primary' id='bs-save-po'>Create PO</button>",'lg');

    // View/Receive PO modal
    ob_start(); ?>
    <div id="bs-po-view-body">Loading...</div>
    <?php $body=ob_get_clean();
    bs_modal('bs-po-view-modal','Purchase Order',$body,"<button class='bs-btn bs-btn-secondary bs-modal-close'>Close</button><button class='bs-btn bs-btn-primary' id='bs-confirm-receive' style='display:none'>Confirm Receipt</button>",'lg');
    ?>
<?php
}
