<?php
if(!defined('ABSPATH'))exit;

function bs_page_branches(){
    $branches=bs_get_branches(false);
    global $wpdb;
    $transfers=$wpdb->get_results("SELECT st.*,bb.name AS from_name,tb.name AS to_name,bk.title AS book_title,u.display_name AS staff_name FROM {$wpdb->prefix}bookshop_stock_transfers st LEFT JOIN {$wpdb->prefix}bookshop_branches bb ON bb.id=st.from_branch_id LEFT JOIN {$wpdb->prefix}bookshop_branches tb ON tb.id=st.to_branch_id LEFT JOIN {$wpdb->prefix}bookshop_books bk ON bk.id=st.book_id LEFT JOIN {$wpdb->users} u ON u.ID=st.staff_id ORDER BY st.created_at DESC LIMIT 50");
    $takes=$wpdb->get_results("SELECT st.*,b.name AS branch_name,u.display_name AS staff_name FROM {$wpdb->prefix}bookshop_stock_takes st LEFT JOIN {$wpdb->prefix}bookshop_branches b ON b.id=st.branch_id LEFT JOIN {$wpdb->users} u ON u.ID=st.staff_id ORDER BY st.created_at DESC LIMIT 30");
    ?>
    <div class="wrap bs-wrap">
    <div class="bs-header">
        <h1>🏪 Branches & Stock</h1>
        <div style="display:flex;gap:8px">
            <button class="bs-btn bs-btn-secondary" id="bs-check-reorder">🔄 Check Reorder Points</button>
            <button class="bs-btn bs-btn-primary" id="bs-add-branch">+ Add Branch</button>
        </div>
    </div>

    <div class="bs-tabs">
        <button class="bs-tab active" data-tab="branches-tab">Branches</button>
        <button class="bs-tab" data-tab="transfers-tab">Stock Transfers</button>
        <button class="bs-tab" data-tab="stocktake-tab">Stock Takes</button>
    </div>

    <div id="branches-tab" class="bs-tab-content">
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px;margin-bottom:24px">
    <?php foreach($branches as $b):
        $stock_count=$wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(qty),0) FROM {$wpdb->prefix}bookshop_branch_stock WHERE branch_id=%d",$b->id));
        $sales_count=$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}bookshop_sales WHERE branch_id=%d AND status='completed'",$b->id)) ?? 0;
    ?>
    <div class="bs-card" style="position:relative">
        <span class="bs-badge bs-badge-<?=esc_attr($b->status)?>" style="position:absolute;top:14px;right:14px"><?=$b->status?></span>
        <h3 style="font-family:'Playfair Display',serif;font-size:1.1rem;margin-bottom:8px"><?=esc_html($b->name)?></h3>
        <?php if($b->address): ?><p style="font-size:.8rem;color:var(--muted);margin-bottom:4px">📍 <?=esc_html($b->address)?></p><?php endif; ?>
        <?php if($b->phone): ?><p style="font-size:.8rem;color:var(--muted);margin-bottom:4px">📞 <?=esc_html($b->phone)?></p><?php endif; ?>
        <?php if($b->manager): ?><p style="font-size:.8rem;color:var(--muted);margin-bottom:8px">👤 <?=esc_html($b->manager)?></p><?php endif; ?>
        <div style="display:flex;gap:12px;margin-top:10px">
            <div style="text-align:center"><div style="font-weight:700;font-size:1.1rem"><?=intval($stock_count)?></div><div style="font-size:.72rem;color:var(--muted)">Units</div></div>
            <div style="text-align:center"><div style="font-weight:700;font-size:1.1rem"><?=intval($sales_count)?></div><div style="font-size:.72rem;color:var(--muted)">Sales</div></div>
        </div>
        <div style="display:flex;gap:6px;margin-top:12px">
            <button class="bs-btn bs-btn-secondary bs-edit-branch" data-id="<?=esc_attr($b->id)?>" style="font-size:.78rem;padding:5px 10px">Edit</button>
            <button class="bs-btn bs-btn-secondary bs-view-branch-stock" data-id="<?=esc_attr($b->id)?>" data-name="<?=esc_attr($b->name)?>" style="font-size:.78rem;padding:5px 10px">View Stock</button>
            <button class="bs-btn bs-btn-secondary bs-transfer-stock-btn" data-id="<?=esc_attr($b->id)?>" data-name="<?=esc_attr($b->name)?>" style="font-size:.78rem;padding:5px 10px">Transfer</button>
        </div>
    </div>
    <?php endforeach;
    if(empty($branches)) echo '<p style="color:var(--muted)">No branches yet. Add your first branch to get started.</p>';
    ?>
    </div>
    </div>

    <div id="transfers-tab" class="bs-tab-content" style="display:none">
    <table class="bs-table">
        <thead><tr><th>Date</th><th>Book</th><th>From</th><th>To</th><th>Qty</th><th>Staff</th></tr></thead>
        <tbody>
        <?php foreach($transfers as $t): ?>
        <tr>
            <td><?=esc_html(wp_date('d M Y',strtotime($t->created_at)))?></td>
            <td><?=esc_html($t->book_title)?></td>
            <td><?=esc_html($t->from_name)?></td>
            <td><?=esc_html($t->to_name)?></td>
            <td><?=intval($t->qty)?></td>
            <td><?=esc_html($t->staff_name)?></td>
        </tr>
        <?php endforeach; if(empty($transfers)) echo '<tr><td colspan="6" style="text-align:center;color:#999;padding:24px">No transfers yet.</td></tr>'; ?>
        </tbody>
    </table>
    </div>

    <div id="stocktake-tab" class="bs-tab-content" style="display:none">
    <div style="margin-bottom:14px">
        <button class="bs-btn bs-btn-primary" id="bs-new-stocktake">+ New Stock Take</button>
    </div>
    <table class="bs-table">
        <thead><tr><th>Date</th><th>Branch</th><th>Staff</th><th>Status</th><th>Completed</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach($takes as $t): ?>
        <tr>
            <td><?=esc_html(wp_date('d M Y',strtotime($t->created_at)))?></td>
            <td><?=esc_html($t->branch_name)?></td>
            <td><?=esc_html($t->staff_name)?></td>
            <td><span class="bs-badge bs-badge-<?=esc_attr($t->status)?>"><?=$t->status?></span></td>
            <td><?=$t->completed_at?esc_html(wp_date('d M Y',strtotime($t->completed_at))):'-'?></td>
            <td><?php if($t->status==='in_progress'): ?><button class="bs-btn-link bs-do-stocktake" data-id="<?=esc_attr($t->id)?>">Enter Counts</button><?php endif; ?></td>
        </tr>
        <?php endforeach; if(empty($takes)) echo '<tr><td colspan="6" style="text-align:center;color:#999;padding:24px">No stock takes yet.</td></tr>'; ?>
        </tbody>
    </table>
    </div>
    </div>

    <?php
    // Branch modal — build body as string, then output directly
    $other_active = !empty($branches) ? count(array_filter($branches, function($b){ return $b->status==='active'; })) : 0;
    // First-time multi-branch setup: the shop has been running on a single
    // global stock_qty, so the new branch should inherit that. Once at least
    // one branch is already active, a fresh location starting at zero is the
    // less-surprising default — copying would inflate the apparent total.
    $default_backfill = $other_active === 0 ? 'copy' : 'zero';
    $checked_copy  = $default_backfill === 'copy' ? "checked" : '';
    $checked_zero  = $default_backfill === 'zero' ? "checked" : '';
    $branch_body = "
    <input type='hidden' id='bs-branch-id'>
    <div class='bs-form-grid'>
        <div class='bs-form-group bs-span2'><label>Branch Name *</label><input type='text' id='bs-bf-name' class='bs-input'></div>
        <div class='bs-form-group bs-span2'><label>Address</label><textarea id='bs-bf-address' class='bs-input' rows='2'></textarea></div>
        <div class='bs-form-group'><label>Phone</label><input type='text' id='bs-bf-phone' class='bs-input'></div>
        <div class='bs-form-group'><label>Email</label><input type='email' id='bs-bf-email' class='bs-input'></div>
        <div class='bs-form-group'><label>Manager Name</label><input type='text' id='bs-bf-manager' class='bs-input'></div>
        <div class='bs-form-group'><label>Status</label><select id='bs-bf-status' class='bs-input'><option value='active'>Active</option><option value='inactive'>Inactive</option></select></div>
        <div class='bs-form-group bs-span2' id='bs-branch-backfill-row'>
            <label>Initial stock for this branch</label>
            <div style='display:flex;flex-direction:column;gap:6px;font-size:.85rem;background:#fdf8f0;padding:10px 12px;border-radius:8px;border:1px solid #e0d4c0'>
                <label style='display:flex;align-items:flex-start;gap:8px;cursor:pointer'>
                    <input type='radio' name='bs-bf-backfill' value='copy' $checked_copy style='margin-top:3px'>
                    <span><strong>Copy from current global stock</strong><br><span style='color:#8a7a65;font-size:.78rem'>Each book starts at its current Stock Qty. Best for first-time multi-branch setup.</span></span>
                </label>
                <label style='display:flex;align-items:flex-start;gap:8px;cursor:pointer'>
                    <input type='radio' name='bs-bf-backfill' value='zero' $checked_zero style='margin-top:3px'>
                    <span><strong>Start empty (zero stock)</strong><br><span style='color:#8a7a65;font-size:.78rem'>Recommended when stock will be transferred in or counted via stock take.</span></span>
                </label>
                <label style='display:flex;align-items:flex-start;gap:8px;cursor:pointer'>
                    <input type='radio' name='bs-bf-backfill' value='' style='margin-top:3px'>
                    <span><strong>Skip — set up later</strong><br><span style='color:#8a7a65;font-size:.78rem'>Sales at this branch will be rejected until books are seeded.</span></span>
                </label>
            </div>
        </div>
        <div class='bs-form-group bs-span2' id='bs-branch-reseed-row' style='display:none'>
            <label>Re-seed missing books</label>
            <div style='background:#fef3c7;border:1px solid #fcd34d;border-radius:8px;padding:10px 12px;font-size:.85rem;color:#92400e'>
                <div id='bs-branch-reseed-summary' style='margin-bottom:8px'></div>
                <div id='bs-branch-reseed-actions' style='display:flex;gap:6px;flex-wrap:wrap'>
                    <button type='button' class='bs-btn bs-btn-secondary bs-branch-reseed-btn' data-mode='copy' style='font-size:.78rem;padding:5px 10px'>Seed at current global stock</button>
                    <button type='button' class='bs-btn bs-btn-secondary bs-branch-reseed-btn' data-mode='zero' style='font-size:.78rem;padding:5px 10px'>Seed at zero</button>
                </div>
                <div style='font-size:.75rem;color:#78350f;margin-top:6px'>
                    Existing rows are never overwritten — this only adds rows for books that don't yet have one at this branch.
                </div>
            </div>
        </div>
    </div>";
    bs_modal('bs-branch-modal','Add / Edit Branch',$branch_body,"<button class='bs-btn bs-btn-secondary bs-modal-close'>Cancel</button><button class='bs-btn bs-btn-primary' id='bs-save-branch'>Save Branch</button>");
    bs_modal('bs-branch-stock-modal','Branch Stock',"<div id='bs-branch-stock-body'>Loading...</div>",'','lg');
    ?>
<?php
}
