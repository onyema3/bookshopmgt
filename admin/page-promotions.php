<?php
if(!defined('ABSPATH'))exit;

function bs_page_promotions(){
    $promos=bs_get_promotions();
    ?>
    <div class="wrap bs-wrap">
    <div class="bs-header">
        <h1>🏷️ Promotions & Discount Codes</h1>
        <button class="bs-btn bs-btn-primary" id="bs-add-promo">+ New Promotion</button>
    </div>

    <table class="bs-table">
        <thead><tr><th>Name</th><th>Code</th><th>Type</th><th>Value</th><th>Min Purchase</th><th>Usage</th><th>Dates</th><th>Manager?</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach($promos as $p): ?>
        <tr>
            <td><strong><?=esc_html($p->name)?></strong></td>
            <td><?=$p->code?"<code>".esc_html($p->code)."</code>":'<em style="color:#999">No code</em>'?></td>
            <td><?=esc_html($p->type)?></td>
            <td><?=$p->type==='percent'?$p->value.'%':bs_fmt($p->value)?></td>
            <td><?=$p->min_purchase>0?bs_fmt($p->min_purchase):'-'?></td>
            <td><?=$p->usage_limit?"$p->used_count / $p->usage_limit":$p->used_count.' used'?></td>
            <td><?=($p->start_date?wp_date('d M',$p->start_date?strtotime($p->start_date):0):'∞').' → '.($p->end_date?wp_date('d M Y',strtotime($p->end_date)):'∞')?></td>
            <?php $pm_req = $p->requires_manager ? '✅ Yes' : '-'; ?><td><?=$pm_req?></td>
            <td><span class="bs-badge bs-badge-<?=$p->status?>"><?=$p->status?></span></td>
            <td>
                <button class="bs-icon-btn bs-edit-promo" data-id="<?=esc_attr($p->id)?>">✏️</button>
                <button class="bs-icon-btn bs-delete-promo" data-id="<?=esc_attr($p->id)?>">🗑️</button>
            </td>
        </tr>
        <?php endforeach; if(empty($promos)): ?>
        <tr><td colspan="10" style="text-align:center;color:#999;padding:24px">No promotions yet. Create one to get started.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>

    <?php
    ob_start(); ?>
    <input type="hidden" id="bs-promo-id">
    <div class="bs-form-grid">
        <div class="bs-form-group bs-span2"><label>Promotion Name *</label><input type="text" id="bs-pf-name" class="bs-input"></div>
        <div class="bs-form-group"><label>Coupon Code (optional)</label><input type="text" id="bs-pf-code" class="bs-input" style="text-transform:uppercase" placeholder="e.g. SAVE20"></div>
        <div class="bs-form-group"><label>Type</label>
            <select id="bs-pf-type" class="bs-input">
                <option value="percent">Percentage Off</option>
                <option value="fixed">Fixed Amount Off</option>
                <option value="buy_x_get_y">Buy X Get Y Free</option>
            </select>
        </div>
        <div class="bs-form-group"><label>Value (% or amount)</label><input type="number" id="bs-pf-value" class="bs-input" step="0.01" min="0"></div>
        <div class="bs-form-group"><label>Min Purchase (<?=bs_currency()?>)</label><input type="number" id="bs-pf-min" class="bs-input" step="0.01" min="0" value="0"></div>
        <div id="bs-pf-bxgy-group" class="bs-form-group" style="display:none"><label>Buy Qty</label><input type="number" id="bs-pf-buy" class="bs-input" min="1" value="2"></div>
        <div id="bs-pf-bxgy-group2" class="bs-form-group" style="display:none"><label>Get Qty Free</label><input type="number" id="bs-pf-get" class="bs-input" min="1" value="1"></div>
        <div class="bs-form-group"><label>Usage Limit (0 = unlimited)</label><input type="number" id="bs-pf-limit" class="bs-input" min="0" value="0"></div>
        <div class="bs-form-group"><label>Start Date</label><input type="date" id="bs-pf-start" class="bs-input"></div>
        <div class="bs-form-group"><label>End Date</label><input type="date" id="bs-pf-end" class="bs-input"></div>
        <div class="bs-form-group"><label>Status</label><select id="bs-pf-status" class="bs-input"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
        <div class="bs-form-group bs-span2">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                <input type="checkbox" id="bs-pf-manager"> Requires manager approval to apply
            </label>
        </div>
    </div>
    <?php $body=ob_get_clean();
    bs_modal('bs-promo-modal','Add / Edit Promotion',$body,
        "<button class='bs-btn bs-btn-secondary bs-modal-close'>Cancel</button><button class='bs-btn bs-btn-primary' id='bs-save-promo'>Save Promotion</button>");
    ?>
<?php
}
