<?php
if(!defined('ABSPATH'))exit;

function bs_page_books(){
    // Branch picker. Scoped via the same helper the reports page uses, so a
    // bookshop_manager pinned to one location can't peek at other branches'
    // stock by editing ?branch=. Admins see "All branches"; everyone else is
    // pinned to (or coerced into) their allowed branch.
    $requested_branch = intval($_GET['branch'] ?? 0);
    $allowed_branches = function_exists('bs_user_report_branches')
        ? bs_user_report_branches() : [];
    $branch_id = function_exists('bs_validate_report_branch')
        ? bs_validate_report_branch($requested_branch) : $requested_branch;
    if ($branch_id === false) {
        echo '<div class="wrap"><h1>📚 Books Inventory</h1>'
           . '<div class="notice notice-error"><p>You don\'t have access to that branch.</p></div></div>';
        return;
    }
    $branch_label = '';
    if ($branch_id) {
        foreach ($allowed_branches as $b) {
            if (intval($b->id) === $branch_id) { $branch_label = $b->name; break; }
        }
    }

    // bs_get_books rewrites stock_qty to the branch column when branch_id is
    // passed, so the rest of the table loop below is unchanged.
    $books=bs_get_books(['status'=>'','limit'=>500,'branch_id'=>$branch_id]);
    $genres=bs_genres();
    $total_books=bs_count_books('active');
    global $wpdb;
    if ($branch_id) {
        // Per-branch stock counts: a book is "low" only if it's low *here*, not globally.
        $low_stock=$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}bookshop_books b
             LEFT JOIN {$wpdb->prefix}bookshop_branch_stock bst
                    ON bst.book_id=b.id AND bst.branch_id=%d
             WHERE b.status='active' AND COALESCE(bst.qty,0)<=b.low_stock_threshold",
            $branch_id));
        $out_stock=$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}bookshop_books b
             LEFT JOIN {$wpdb->prefix}bookshop_branch_stock bst
                    ON bst.book_id=b.id AND bst.branch_id=%d
             WHERE b.status='active' AND COALESCE(bst.qty,0)=0",
            $branch_id));
    } else {
        $low_stock=$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}bookshop_books WHERE status='active' AND stock_qty<=low_stock_threshold");
        $out_stock=$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}bookshop_books WHERE status='active' AND stock_qty=0");
    }
    ?>
    <div class="wrap bs-wrap">
    <div class="bs-header">
        <h1>📚 Books Inventory<?php if($branch_label): ?> <span style="font-size:.6em;color:var(--muted);font-weight:500">— <?=esc_html($branch_label)?></span><?php endif; ?></h1>
        <div style="display:flex;gap:8px">
            <button class="bs-btn bs-btn-secondary" id="bs-import-csv-btn">⬆ Import CSV</button>
            <?php if(bs_woo_active()): ?>
            <button class="bs-btn bs-btn-secondary" id="bs-import-woo-btn">🛒 Import WooCommerce</button>
            <?php endif; ?>
            <button class="bs-btn bs-btn-primary" id="bs-add-book">+ Add Book</button>
        </div>
    </div>

    <div class="bs-stats-row">
        <?php bs_stat($total_books,'Total Books');
        bs_stat($low_stock,'Low Stock','amber');
        bs_stat($out_stock,'Out of Stock','red'); ?>
    </div>

    <div class="bs-toolbar">
        <input type="text" id="bs-book-search" placeholder="🔍 Search title, author, ISBN..." class="bs-search">
        <select id="bs-genre-filter" class="bs-select">
            <option value="">All Genres</option>
            <?php foreach($genres as $g) echo "<option>".esc_html($g)."</option>"; ?>
        </select>
        <select id="bs-status-filter" class="bs-select">
            <option value="">All Status</option>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
        </select>
        <label style="display:flex;align-items:center;gap:6px;font-size:.85rem">
            <input type="checkbox" id="bs-low-stock-filter"> Low stock only
        </label>
        <?php if(count($allowed_branches) > 1): ?>
            <!-- Branch picker triggers a server-side reload because bs_get_books
                 rewrites the SELECT (stock column changes source). The other
                 filters above are pure in-page hide/show. -->
            <form method="get" style="display:inline-flex;align-items:center;gap:4px;font-size:.83rem;margin-left:auto">
                <input type="hidden" name="page" value="bookshop-books">
                🏪
                <select name="branch" class="bs-select" onchange="this.form.submit()" style="min-width:140px">
                    <?php if(function_exists('bs_user_is_admin') && bs_user_is_admin()): ?>
                    <option value="0">All branches</option>
                    <?php endif; ?>
                    <?php foreach($allowed_branches as $b): ?>
                    <option value="<?=intval($b->id)?>" <?=selected($branch_id,intval($b->id),false)?>><?=esc_html($b->name)?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        <?php elseif(count($allowed_branches) === 1 && $branch_id): ?>
            <span style="margin-left:auto;font-size:.83rem;color:var(--muted)">🏪 <?=esc_html($branch_label)?></span>
        <?php endif; ?>
    </div>

    <!-- Bulk actions bar — hidden by default, JS reveals it when ≥1 row is checked.
         Keeping it here (not floating) so it doesn't fight the existing toolbar
         for layout when both are visible. -->
    <div id="bs-bulk-bar" class="bs-toolbar" style="display:none;background:#fdf8f0;border:1px solid #e0d4c0;border-radius:8px;padding:8px 12px;margin-top:8px;align-items:center">
        <strong id="bs-bulk-count" style="color:#5d4a00">0 selected</strong>
        <select id="bs-bulk-action" class="bs-select">
            <option value="">Bulk action…</option>
            <option value="price">% Price change</option>
            <option value="rename_genre">Rename genre</option>
            <option value="archive">Archive (mark inactive)</option>
            <option value="restore">Restore (mark active)</option>
        </select>
        <button class="bs-btn bs-btn-primary" id="bs-bulk-apply" type="button">Apply</button>
        <a href="#" id="bs-bulk-clear" style="font-size:.82rem;color:var(--muted);margin-left:auto">Clear selection</a>
    </div>

    <table class="bs-table" id="bs-books-table">
        <thead><tr>
            <th style="width:34px;text-align:center"><input type="checkbox" id="bs-select-all" title="Select all visible"></th>
            <th>Cover</th><th>Title / Author</th><th>ISBN</th><th>Genre</th>
            <th>Location</th><th>Cost</th><th>Price</th><th>Margin</th>
            <th><?=$branch_label ? 'Stock @ '.esc_html($branch_label) : 'Stock'?></th>
            <th>Status</th><th>Actions</th>
        </tr></thead>
        <tbody>
        <?php foreach($books as $b):
            $margin=$b->sell_price>0?round((($b->sell_price-$b->cost_price)/$b->sell_price)*100,1):0;
            $stock_cls=$b->stock_qty==0?'bs-out-stock':($b->stock_qty<=$b->low_stock_threshold?'bs-low-stock':'');
        ?>
        <tr data-id="<?=esc_attr($b->id)?>" data-title="<?=esc_attr(strtolower($b->title))?>"
            data-author="<?=esc_attr(strtolower($b->author))?>" data-isbn="<?=esc_attr($b->isbn)?>"
            data-genre="<?=esc_attr(strtolower($b->genre))?>" data-status="<?=esc_attr($b->status)?>"
            data-stock="<?=intval($b->stock_qty)?>" data-threshold="<?=intval($b->low_stock_threshold)?>">
            <td style="text-align:center"><input type="checkbox" class="bs-row-select" data-id="<?=esc_attr($b->id)?>"></td>
            <td><?php if($b->cover_url): ?><img src="<?=esc_url($b->cover_url)?>" class="bs-cover-thumb"><?php else: ?><span class="bs-no-cover">📖</span><?php endif; ?></td>
            <td><strong><?=esc_html($b->title)?></strong><br><small class="bs-muted"><?=esc_html($b->author)?></small>
                <?php if($b->location): ?><br><span class="bs-tag">📍 <?=esc_html($b->location)?></span><?php endif; ?></td>
            <td><code><?=esc_html($b->isbn)?></code></td>
            <td><?=esc_html($b->genre)?></td>
            <td><?=esc_html($b->location)?></td>
            <td><?=bs_fmt($b->cost_price)?></td>
            <td><?=bs_fmt($b->sell_price)?></td>
            <td><span class="bs-margin <?=$margin>30?'bs-margin-good':($margin>10?'bs-margin-ok':'bs-margin-low')?>"><?=$margin?>%</span></td>
            <td class="<?=$stock_cls?>" style="display:flex;align-items:center;gap:6px">
                <span><?=intval($b->stock_qty)?></span>
                <button class="bs-icon-btn bs-adjust-stock" title="Adjust stock" data-id="<?=esc_attr($b->id)?>" data-qty="<?=intval($b->stock_qty)?>">✏️</button>
                <?php if(!empty($allowed_branches) && (count($allowed_branches) > 1 || (function_exists('bs_user_is_admin') && bs_user_is_admin()))): ?>
                <button class="bs-icon-btn bs-book-by-branch" title="Stock breakdown by branch" data-id="<?=esc_attr($b->id)?>" data-title="<?=esc_attr($b->title)?>">🏪</button>
                <?php endif; ?>
            </td>
            <td><span class="bs-badge bs-badge-<?=$b->status?>"><?=$b->status?></span></td>
            <td>
                <button class="bs-icon-btn bs-edit-book" data-id="<?=esc_attr($b->id)?>" title="Edit">✏️</button>
                <button class="bs-icon-btn bs-delete-book" data-id="<?=esc_attr($b->id)?>" title="Archive">🗑️</button>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>

    <?php
    // Per-branch stock breakdown modal — rendered for everyone but only
    // populated/triggered when there's >1 branch in the system.
    bs_modal('bs-book-by-branch-modal','Stock by Branch',
        "<div id='bs-book-by-branch-body'>Loading…</div>",
        "<button class='bs-btn bs-btn-secondary bs-modal-close'>Close</button>");
    ?>

    <?php
    // Book modal
    ob_start(); ?>
    <input type="hidden" id="bs-book-id">
    <div class="bs-form-grid">
        <div class="bs-form-group bs-span2">
            <label>ISBN / Barcode</label>
            <div style="display:flex;gap:8px">
                <input type="text" id="bs-f-isbn" class="bs-input" placeholder="Enter ISBN then click lookup">
                <button class="bs-btn bs-btn-secondary" id="bs-isbn-lookup" type="button">🔍 Lookup</button>
            </div>
        </div>
        <div class="bs-form-group bs-span2">
            <label>Title *</label>
            <input type="text" id="bs-f-title" class="bs-input" required>
        </div>
        <div class="bs-form-group"><label>Author</label><input type="text" id="bs-f-author" class="bs-input"></div>
        <div class="bs-form-group"><label>Genre</label><input type="text" id="bs-f-genre" class="bs-input" list="bs-genre-list">
            <datalist id="bs-genre-list">
                <?php foreach(['Fiction','Non-Fiction','Science','History','Biography','Children','Romance','Thriller','Religion','Academic','Self-Help','Business','Poetry','Travel'] as $g) echo "<option>$g</option>"; ?>
            </datalist>
        </div>
        <div class="bs-form-group"><label>Publisher</label><input type="text" id="bs-f-publisher" class="bs-input"></div>
        <div class="bs-form-group"><label>Year</label><input type="number" id="bs-f-year" class="bs-input" min="1800" max="2099"></div>
        <div class="bs-form-group"><label>Cost Price (<?=bs_currency()?>)</label><input type="number" id="bs-f-cost" class="bs-input" step="0.01" min="0"></div>
        <div class="bs-form-group"><label>Selling Price (<?=bs_currency()?>)</label><input type="number" id="bs-f-price" class="bs-input" step="0.01" min="0"></div>
        <div class="bs-form-group"><label id="bs-f-stock-label">Stock Qty</label><input type="number" id="bs-f-stock" class="bs-input" min="0"></div>
        <div class="bs-form-group"><label>Low Stock Alert At</label><input type="number" id="bs-f-threshold" class="bs-input" min="0" value="5"></div>
        <div class="bs-form-group"><label>Shelf Location</label><input type="text" id="bs-f-location" class="bs-input" placeholder="e.g. A3, Shelf 2"></div>
        <div class="bs-form-group"><label>Barcode</label><input type="text" id="bs-f-barcode" class="bs-input"></div>
        <div class="bs-form-group"><label>Status</label>
            <select id="bs-f-status" class="bs-input"><option value="active">Active</option><option value="inactive">Inactive</option></select>
        </div>
        <div class="bs-form-group bs-span2"><label>Cover Image URL</label><input type="url" id="bs-f-cover" class="bs-input"></div>
        <div class="bs-form-group bs-span2"><label>Description</label><textarea id="bs-f-desc" class="bs-input" rows="3"></textarea></div>
        <div class="bs-form-group bs-span2" id="bs-f-branch-stock-wrap" style="display:none">
            <label style="display:flex;align-items:center;justify-content:space-between">
                <span>🏪 Stock by Branch</span>
                <span style="font-size:.78rem;color:var(--muted);font-weight:400">Sum will become the book's total stock.</span>
            </label>
            <div id="bs-f-branch-stock-list" style="background:#fdf8f0;border:1px solid #e0d4c0;border-radius:8px;padding:10px 12px;display:flex;flex-direction:column;gap:6px;font-size:.88rem">
                <div style="color:var(--muted);font-size:.82rem">Loading branches…</div>
            </div>
            <div id="bs-f-branch-stock-total" style="margin-top:6px;text-align:right;font-size:.82rem;color:var(--muted)"></div>
        </div>
        <div class="bs-form-group bs-span2" id="bs-margin-preview" style="background:#f5efe4;border-radius:8px;padding:10px;display:none">
            <strong>Margin Preview:</strong> <span id="bs-margin-val"></span>
        </div>
    </div>
    <?php $body=ob_get_clean();
    bs_modal('bs-book-modal','Add / Edit Book',$body,
        "<button class='bs-btn bs-btn-secondary bs-modal-close'>Cancel</button><button class='bs-btn bs-btn-primary' id='bs-save-book'>Save Book</button>",'lg');

    // CSV import modal
    ob_start(); ?>
    <p>Download the <a href="#" id="bs-csv-template">CSV template</a> and fill it in before importing.</p>
    <input type="file" id="bs-csv-file" accept=".csv" class="bs-input" style="margin-top:10px">
    <div id="bs-import-result" style="margin-top:12px"></div>
    <?php $body=ob_get_clean();
    bs_modal('bs-import-modal','Import Books (CSV)',$body,
        "<button class='bs-btn bs-btn-secondary bs-modal-close'>Close</button><button class='bs-btn bs-btn-primary' id='bs-do-import'>Import</button>");

    // Bulk: % price change modal. Number is signed (-100..1000), with checkbox
    // for whether to also bump cost_price (not the default — sellers usually
    // want to mark up margin without touching what they paid). Rounding mode
    // covers the two common cases: keep cents (decimals) for exact margins,
    // or round to whole units for shelf-friendly prices.
    ob_start(); ?>
    <p style="margin-bottom:12px;color:var(--muted);font-size:.9rem">
        Apply to <strong id="bs-bulk-price-count">0 selected books</strong>.
        Negative values discount; positive values mark up. Resulting prices are clamped to ≥ 0.
    </p>
    <div class="bs-form-grid">
        <div class="bs-form-group">
            <label>% change</label>
            <div style="display:flex;align-items:center;gap:6px">
                <input type="number" id="bs-bulk-pct" class="bs-input" step="0.1" min="-100" max="1000" placeholder="e.g. 10 or -5" style="max-width:140px">
                <span style="font-size:1.2em;color:var(--muted)">%</span>
            </div>
            <small style="color:var(--muted);font-size:.75rem">e.g. <code>10</code> = +10%, <code>-5</code> = −5%, <code>0</code> would be a no-op.</small>
        </div>
        <div class="bs-form-group">
            <label>Rounding</label>
            <select id="bs-bulk-round" class="bs-input">
                <option value="cent">Round to 2 decimals (e.g. 1,234.56)</option>
                <option value="whole">Round to whole units (e.g. 1,235)</option>
            </select>
        </div>
        <div class="bs-form-group bs-span2">
            <label style="display:inline-flex;align-items:center;gap:8px;cursor:pointer;font-weight:400">
                <input type="checkbox" id="bs-bulk-also-cost">
                <span>Also adjust <strong>cost price</strong> by the same percentage</span>
            </label>
            <small style="display:block;color:var(--muted);font-size:.75rem;margin-top:4px">
                Off by default. Cost price reflects what you paid the supplier — usually you want to bump the markup, not rewrite history.
            </small>
        </div>
    </div>
    <?php $body=ob_get_clean();
    bs_modal('bs-bulk-price-modal','Bulk: % Price Change',$body,
        "<button class='bs-btn bs-btn-secondary bs-modal-close'>Cancel</button><button class='bs-btn bs-btn-primary' id='bs-bulk-price-apply'>Apply</button>");

    // Bulk: rename genre modal. Single text input + datalist of existing
    // genres so the user can either type a new one or pick an existing one
    // to merge into.
    ob_start(); ?>
    <p style="margin-bottom:12px;color:var(--muted);font-size:.9rem">
        Rename the genre on <strong id="bs-bulk-genre-count">0 selected books</strong>.
        Pick an existing genre to merge them into one, or type a new name.
    </p>
    <div class="bs-form-group">
        <label>Rename to</label>
        <input type="text" id="bs-bulk-genre-to" class="bs-input" list="bs-bulk-genre-list" placeholder="e.g. Fiction" maxlength="100">
        <datalist id="bs-bulk-genre-list">
            <?php foreach($genres as $g) echo '<option value="'.esc_attr($g).'">'; ?>
        </datalist>
        <small style="color:var(--muted);font-size:.75rem">
            Leave blank to <em>clear</em> the genre on these books.
        </small>
    </div>
    <?php $body=ob_get_clean();
    bs_modal('bs-bulk-genre-modal','Bulk: Rename Genre',$body,
        "<button class='bs-btn bs-btn-secondary bs-modal-close'>Cancel</button><button class='bs-btn bs-btn-primary' id='bs-bulk-genre-apply'>Rename</button>");

    // Bulk: archive / restore confirmation. Same modal serves both — JS
    // updates the heading and footer button text based on the action.
    ob_start(); ?>
    <p id="bs-bulk-status-warning" style="font-size:.95rem">
        You are about to <strong id="bs-bulk-status-verb">archive</strong>
        <strong id="bs-bulk-status-count">0</strong> book<span id="bs-bulk-status-plural">s</span>.
    </p>
    <p style="margin-top:8px;color:var(--muted);font-size:.85rem">
        Archived books are hidden from the catalogue and POS search but their data, sales history, and stock counts are preserved.
        Use <em>Restore</em> to bring them back at any time.
    </p>
    <div id="bs-bulk-status-titles" style="max-height:200px;overflow:auto;background:#fdf8f0;border:1px solid #e0d4c0;border-radius:6px;padding:10px;margin-top:8px;font-size:.82rem;line-height:1.5"></div>
    <?php $body=ob_get_clean();
    bs_modal('bs-bulk-status-modal','Bulk: Confirm',$body,
        "<button class='bs-btn bs-btn-secondary bs-modal-close'>Cancel</button><button class='bs-btn bs-btn-primary' id='bs-bulk-status-apply'>Confirm</button>");
    ?>
    <?php
}
