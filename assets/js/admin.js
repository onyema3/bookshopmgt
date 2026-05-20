/* Bookshop Manager Pro — Admin JS */
jQuery(function($){
const {ajax_url,nonce,export_url}=BSAdmin;
const currency=BSAdmin.currency||'₦';
const isAdmin=!!BSAdmin.is_admin;

// ── Utils ────────────────────────────────────────────────────
function fmt(n){return currency+parseFloat(n).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g,',');}
function esc(s){return $('<div>').text(s||'').html();}
function post(data){return $.post(ajax_url,{...data,nonce});}
function get(data){return $.get(ajax_url,data);}
function openModal(id){
    // Set display:flex directly so centering works, then animate opacity via CSS
    $(id).css({display:'flex',opacity:0}).animate({opacity:1},180);
}
function closeModals(){
    $('.bs-modal').animate({opacity:0},160,function(){$(this).css('display','none');});
}
$(document).on('click','.bs-modal-close',closeModals);
$(document).on('click','.bs-modal',function(e){if($(e.target).is('.bs-modal'))closeModals();});

// ── Tabs ─────────────────────────────────────────────────────
$(document).on('click','.bs-tab',function(){
    const tab=$(this).data('tab');
    $(this).addClass('active').siblings('.bs-tab').removeClass('active');
    $('.bs-tab-content').hide();$('#'+tab).show();
});

// ── Books: Filter ─────────────────────────────────────────────
function filterBooks(){
    const q=$('#bs-book-search').val().toLowerCase();
    const g=$('#bs-genre-filter').val().toLowerCase();
    const s=$('#bs-status-filter').val();
    const lowOnly=$('#bs-low-stock-filter').is(':checked');
    $('#bs-books-table tbody tr').each(function(){
        const title =$(this).data('title')||'';
        const author=$(this).data('author')||'';
        const isbn  =$(this).data('isbn')||'';
        const genre =$(this).data('genre')||'';
        const status=$(this).data('status')||'';
        const stock =parseInt($(this).data('stock'))||0;
        const thr   =parseInt($(this).data('threshold'))||5;
        const mQ=!q||(title+author+isbn).includes(q);
        const mG=!g||genre===g;
        const mS=!s||status===s;
        const mL=!lowOnly||stock<=thr;
        $(this).toggle(mQ&&mG&&mS&&mL);
    });
}
$('#bs-book-search,#bs-genre-filter,#bs-status-filter').on('input change',filterBooks);
$('#bs-low-stock-filter').on('change',filterBooks);

// Stock breakdown by branch for one book
$(document).on('click','.bs-book-by-branch',function(){
    var id   = $(this).data('id');
    var ttl  = $(this).data('title') || 'Book';
    $('#bs-book-by-branch-modal').data('book-id', id).data('book-title', ttl);
    loadBookBranchBreakdown(id, ttl);
    openModal('#bs-book-by-branch-modal');
});

function loadBookBranchBreakdown(id, ttl){
    $('#bs-book-by-branch-modal .bs-modal-header h2').text('Stock by Branch — '+ttl);
    $('#bs-book-by-branch-body').html('<em>Loading…</em>');
    get({action:'bs_get_book_branch_breakdown',book_id:id}).then(function(res){
        if(!res.success){
            $('#bs-book-by-branch-body').html('<em>'+(res.data||'Error')+'</em>');
            return;
        }
        var d = res.data || {};
        var rows = d.rows || [];
        var low  = parseInt(d.low||0);
        var globalQty = parseInt(d.global||0);
        var html = '<div style="margin-bottom:10px;font-size:.85rem;color:#666">'
                 + 'Global stock_qty: <strong>'+globalQty+'</strong>'
                 + ' &middot; Low-stock threshold: <strong>'+low+'</strong>'
                 + '</div>';
        var branchSum = 0;
        if(!rows.length){
            html += '<p style="color:#999">No branches available.</p>';
        } else {
            html += '<table class="bs-table bs-table-sm"><thead><tr><th>Branch</th><th>Qty</th></tr></thead><tbody>';
            rows.forEach(function(r){
                var q = parseInt(r.qty)||0;
                branchSum += q;
                var cls = q===0 ? 'bs-out-stock' : (q<=low ? 'bs-low-stock' : '');
                html += '<tr><td>'+esc(r.branch_name)+'</td>'
                     + '<td class="'+cls+'">'+q+'</td></tr>';
            });
            html += '</tbody><tfoot><tr><th>Across visible branches</th><th>'+branchSum+'</th></tr></tfoot></table>';
        }
        // Drift block. Only shown when the two figures actually disagree.
        // The reconcile button is only rendered for admins because the
        // server-side endpoint is admin-only — non-admins would just get
        // a 403 if they clicked it.
        if(globalQty !== branchSum){
            var delta = branchSum - globalQty;
            var dir   = delta > 0 ? 'higher' : 'lower';
            html += '<div style="margin-top:10px;padding:10px;background:#fef3c7;border:1px solid #fcd34d;border-radius:6px;font-size:.83rem;color:#92400e">'
                 + '⚠️ Global stock_qty differs from the per-branch sum by <strong>'
                 + Math.abs(delta) + '</strong>. The branch sum is ' + dir + ' than global.'
                 + '<br><span style="font-size:.78rem;color:#78350f">'
                 + 'Common causes: pre-v4 sales (only the global counter was decremented), '
                 + 'or books that were never seeded at one or more branches.'
                 + '</span>';
            if(isAdmin){
                // Two reconcile directions. Default is branches→global because
                // once multi-branch is on, per-branch counts are authoritative.
                // The global→branches option is for the rarer case where the
                // global figure is right and the drift is a missing seed —
                // the help text below the buttons spells out which to pick.
                html += '<div style="margin-top:10px;display:flex;flex-wrap:wrap;gap:6px">'
                     + '<button class="bs-btn bs-btn-secondary bs-reconcile-this-book" '
                     + 'data-book-id="'+id+'" data-direction="branches_to_global" '
                     + 'style="font-size:.78rem;padding:5px 12px">'
                     + '🔧 Set global = ' + branchSum + ' (sum of branches)'
                     + '</button>'
                     + '<button class="bs-btn bs-btn-secondary bs-reconcile-this-book" '
                     + 'data-book-id="'+id+'" data-direction="global_to_branches" '
                     + 'style="font-size:.78rem;padding:5px 12px;background:#fff;border:1px solid #d4a017">'
                     + '↘ Distribute global ' + globalQty + ' across branches'
                     + '</button>'
                     + '</div>'
                     + '<div style="font-size:.72rem;color:#78350f;margin-top:6px;line-height:1.4">'
                     + '<strong>Sum of branches</strong> is the usual fix — pick it when the global '
                     + 'counter is wrong (pre-v4 sales). <strong>Distribute</strong> only when the '
                     + 'global is right and a branch is missing a seed row; it splits global proportionally '
                     + 'across branches that already have a row.'
                     + '</div>';
            }
            html += '</div>';
        }
        // Recent activity panel — collapsed by default so it doesn't push the
        // breakdown table out of view, but its content loads eagerly so the
        // first click is instant. Only the disclosure state is lazy.
        html += '<details id="bs-book-activity-wrap" style="margin-top:14px" open>'
             +   '<summary style="cursor:pointer;font-weight:600;font-size:.88rem;color:#5b3e0a">'
             +     'Recent activity'
             +     ' <span style="color:#999;font-weight:400;font-size:.78rem">'
             +       '— last 20 stock changes for this book'
             +     '</span>'
             +   '</summary>'
             +   '<div id="bs-book-activity-body" style="margin-top:8px"><em>Loading…</em></div>'
             + '</details>';
        $('#bs-book-by-branch-body').html(html);
        loadBookActivity(id);
    });
}

function loadBookActivity(id){
    get({action:'bs_get_book_audit_activity', book_id:id, limit:20}).then(function(res){
        var $body = $('#bs-book-activity-body');
        if(!$body.length) return;
        if(!res.success){
            $body.html('<em style="color:#999">Could not load activity: '+esc(res.data||'unknown')+'</em>');
            return;
        }
        var rows = (res.data && res.data.rows) || [];
        if(!rows.length){
            // No audit rows yet most often means the instrumentation only
            // started running recently. Word it that way rather than
            // "no activity" so the admin doesn't conclude the book is dead.
            $body.html('<p style="color:#999;font-size:.83rem;margin:6px 0">'
                + 'No recent stock-affecting activity recorded for this book. '
                + 'Activity tracking covers sales, voids, refunds, transfers, '
                + 'manual adjustments, and reconciles.'
                + '</p>');
            return;
        }
        // Map raw action names to short, human-readable labels. Anything
        // not in this map falls back to the raw action so unexpected rows
        // still render rather than disappearing.
        var labels = {
            'branch_stock_sold':     'Sold',
            'global_stock_sold':     'Sold (no branch)',
            'branch_stock_adjusted': 'Adjusted',
            'global_stock_voided':   'Void (no branch)',
            'global_stock_refunded': 'Refund (no branch)',
            'branch_stock_set':      'Manual set',
            'stock_transfer':        'Transfer',
            'stock_reconciled':      'Reconciled'
        };
        var html = '<div style="overflow-x:auto;max-height:280px;overflow-y:auto;border:1px solid #e5e7eb;border-radius:6px">'
                 + '<table class="bs-table bs-table-sm" style="margin:0;font-size:.8rem">'
                 + '<thead style="position:sticky;top:0;background:#f5efe4;z-index:1">'
                 + '<tr><th>When</th><th>What</th><th>Detail</th><th>By</th></tr>'
                 + '</thead><tbody>';
        rows.forEach(function(r){
            var when = r.created_at || '';
            var act  = labels[r.action] || r.action;
            html += '<tr>'
                 +   '<td style="white-space:nowrap;color:#666">'+esc(when)+'</td>'
                 +   '<td style="white-space:nowrap"><strong>'+esc(act)+'</strong></td>'
                 +   '<td style="font-family:Menlo,Consolas,monospace;font-size:.78rem;color:#444">'+esc(r.details||'')+'</td>'
                 +   '<td style="white-space:nowrap;color:#666">'+esc(r.staff_name||'')+'</td>'
                 + '</tr>';
        });
        html += '</tbody></table></div>';
        $body.html(html);
    });
}

$(document).on('click','.bs-reconcile-this-book',function(){
    var $btn      = $(this);
    var bookId    = $btn.data('book-id');
    var direction = $btn.data('direction') || 'branches_to_global';
    var ttl       = $('#bs-book-by-branch-modal').data('book-title') || 'Book';
    var prompt    = direction === 'global_to_branches'
        ? 'This will distribute the global stock for "'+ttl+'" across the branches that already have a row, in proportion to their current counts. The change is logged in the audit trail. Continue?'
        : 'This will set the global stock_qty for "'+ttl+'" to the sum of its per-branch counts. The change is logged in the audit trail. Continue?';
    if(!confirm(prompt)) return;
    var origText = $btn.text();
    $btn.prop('disabled',true).text('Reconciling…');
    // Disable the sibling so the user can't double-fire while one is in flight.
    var $siblings = $btn.siblings('.bs-reconcile-this-book').prop('disabled',true);
    post({action:'bs_reconcile_book_stock', book_id: bookId, direction: direction}).then(function(res){
        if(!res.success){
            alert('Reconcile failed: '+(res.data||'Unknown error'));
            $btn.prop('disabled',false).text(origText);
            $siblings.prop('disabled',false);
            return;
        }
        // Refresh the modal in place so the warning disappears and the new
        // values are visible. Also refresh the row in the books table
        // if the user is on that page — easier to just reload the body.
        loadBookBranchBreakdown(bookId, ttl);
    });
});

// ── Drift tab on reports page ─────────────────────────────────────────────────
// Per-row reconcile: hits the same endpoint as the breakdown modal, then
// drops the row from the drift table on success. Counter-style — no full
// page reload, since the user is likely working through the list.
$(document).on('click','.bs-drift-reconcile',function(){
    var bookId = $(this).data('book-id');
    var ttl    = $(this).data('title') || 'Book';
    if(!confirm('Set global stock for "'+ttl+'" to the sum of its branches?')) return;
    var btn = $(this).prop('disabled',true).text('Working…');
    post({action:'bs_reconcile_book_stock', book_id: bookId}).then(function(res){
        if(!res.success){
            alert('Reconcile failed: '+(res.data||'Unknown error'));
            btn.prop('disabled',false);
            return;
        }
        // Drop the row. If that empties the table, swap the success state in.
        var $row = btn.closest('tr');
        $row.fadeOut(160,function(){
            $row.remove();
            if(!$('#bs-drift-table tbody tr').length){
                $('#bs-drift-table').replaceWith(
                    '<p style="color:var(--green,#2a7a3b);padding:24px;text-align:center;font-weight:600">'
                    + '✓ No drift detected — every active book\'s global stock matches its per-branch sum.'
                    + '</p>'
                );
                $('#bs-reconcile-all-drift').remove();
            }
        });
    });
});
$(document).on('click','#bs-reconcile-all-drift',function(){
    // Direction is read at click time so changing the dropdown after the
    // table renders takes effect immediately. Default mirrors the per-row
    // button: branches_to_global, the "branches are authoritative" case.
    var direction = $('#bs-reconcile-all-direction').val() || 'branches_to_global';
    var prompt    = direction === 'global_to_branches'
        ? 'Distribute the global stock_qty across each drifted book\'s branches (proportional to current counts)? Books with no seeded branch are skipped, not failed. The change is logged to the audit trail.'
        : 'Set global stock_qty to the per-branch sum for every drifted book? This is logged to the audit trail.';
    if(!confirm(prompt)) return;
    var btn = $(this).prop('disabled',true).text('Reconciling…');
    post({action:'bs_reconcile_all_drift', direction: direction}).then(function(res){
        btn.prop('disabled',false).text('🔧 Reconcile all');
        if(!res.success){
            alert('Reconcile-all failed: '+(res.data||'Unknown error'));
            return;
        }
        var changed = parseInt(res.data.changed||0);
        var skipped = (res.data.skipped||[]).length;
        // Easier to reload than to surgically remove every row — the user is
        // confirming a bulk action, so the tab refreshing is expected.
        var msg = 'Reconciled '+changed+' book'+(changed===1?'':'s')+'.';
        if(skipped) msg += ' '+skipped+' skipped (no seeded branch — re-seed first).';
        msg += ' Reloading the report.';
        alert(msg);
        location.reload();
    });
});

// ── Books: margin preview ─────────────────────────────────────
function updateMarginPreview(){
    const cost=parseFloat($('#bs-f-cost').val())||0;
    const price=parseFloat($('#bs-f-price').val())||0;
    if(price>0){
        const m=((price-cost)/price*100).toFixed(1);
        const profit=(price-cost).toFixed(2);
        $('#bs-margin-val').text(`${m}% margin — Profit per unit: ${currency}${profit}`);
        $('#bs-margin-preview').show();
    } else $('#bs-margin-preview').hide();
}
$(document).on('input','#bs-f-cost,#bs-f-price',updateMarginPreview);

// ── Books: ISBN lookup ────────────────────────────────────────
$(document).on('click','#bs-isbn-lookup',function(){
    const isbn=$('#bs-f-isbn').val().trim().replace(/[^0-9Xx]/g,'').toUpperCase();
    if(!isbn){alert('Enter an ISBN first');return;}
    const btn=$(this).prop('disabled',true).text('⏳ Searching…');
    $.ajax({
        url: ajax_url,
        method: 'GET',
        data: {action:'bs_lookup_isbn', isbn},
        timeout: 12000
    }).done(function(res){
        btn.prop('disabled',false).text('🔍 Lookup');
        if(res && res.success){
            const d=res.data;
            if(d.title)        $('#bs-f-title').val(d.title);
            if(d.author)       $('#bs-f-author').val(d.author);
            if(d.publisher)    $('#bs-f-publisher').val(d.publisher);
            if(d.publish_year) $('#bs-f-year').val(d.publish_year);
            if(d.description)  $('#bs-f-desc').val(d.description);
            if(d.cover_url)    $('#bs-f-cover').val(d.cover_url);
            if(d.genre)        $('#bs-f-genre').val(d.genre);
            // Show a small confirmation
            btn.text('✓ Found!');
            setTimeout(()=>btn.text('🔍 Lookup'),2000);
        } else {
            alert('Not found in Google Books or Open Library. Fill in the details manually.');
        }
    }).fail(function(){
        btn.prop('disabled',false).text('🔍 Lookup');
        alert('Lookup failed — check your internet connection or fill in manually.');
    });
});

// ── Books: Add/Edit/Save ──────────────────────────────────────
function clearBookForm(){
    $('#bs-book-id').val('');
    ['title','author','isbn','genre','publisher','year','cost','price','stock','threshold','location','barcode','cover','desc'].forEach(f=>{
        const el=$(`#bs-f-${f}`);
        el.is('select')?el.val('active'):el.val('');
    });
    $('#bs-f-status').val('active');
    $('#bs-f-threshold').val(5);
    $('#bs-margin-preview').hide();
    // Reset per-branch panel
    $('#bs-f-branch-stock-wrap').hide();
    $('#bs-f-branch-stock-list').html('<div style="color:var(--muted);font-size:.82rem">Loading branches…</div>');
    $('#bs-f-branch-stock-total').text('');
    $('#bs-f-stock-label').text('Stock Qty');
    $('#bs-f-stock').prop('readonly',false).css('background','');
}

// Load this user's allowed branches (with current per-branch qty for the
// given book id, or zeros for a brand-new book) and render the editable
// rows inside the modal. When no branches are returned (single-shop setup
// or non-admin without a branch), the whole panel stays hidden so the
// classic global Stock Qty input is the only one shown.
function loadBookBranches(bookId){
    var $list  = $('#bs-f-branch-stock-list');
    var $wrap  = $('#bs-f-branch-stock-wrap');
    var $total = $('#bs-f-branch-stock-total');
    $list.html('<div style="color:var(--muted);font-size:.82rem">Loading branches…</div>');
    get({action:'bs_get_book_branches', book_id: bookId||0}).then(function(res){
        if(!res || !res.success || !res.data || !res.data.branches || res.data.branches.length===0){
            $wrap.hide();
            return;
        }
        var rows = res.data.branches.map(function(b){
            return ''
                + '<div class="bs-bf-row" style="display:flex;align-items:center;gap:10px">'
                +   '<label style="flex:1;font-weight:500;font-size:.88rem;color:var(--ink);margin:0">'+escapeHtml(b.name)+'</label>'
                +   '<input type="number" min="0" class="bs-input bs-bf-qty" '
                +     'data-branch-id="'+b.id+'" value="'+parseInt(b.qty,10)+'" '
                +     'style="width:90px;padding:6px 8px;font-size:.88rem">'
                + '</div>';
        }).join('');
        $list.html(rows);
        $wrap.show();
        // The global Stock Qty becomes a derived sum once per-branch entry
        // is in play. Lock it down so a manager doesn't type a number into
        // both fields and then wonder why the branch values won.
        $('#bs-f-stock-label').text('Stock Qty (auto from branches)');
        $('#bs-f-stock').prop('readonly',true).css('background','#f5efe4');
        recalcBranchStockTotal();
    });
}

function recalcBranchStockTotal(){
    var sum = 0;
    $('#bs-f-branch-stock-list .bs-bf-qty').each(function(){
        sum += Math.max(0, parseInt($(this).val(),10) || 0);
    });
    $('#bs-f-branch-stock-total').text('Total across branches: '+sum);
    $('#bs-f-stock').val(sum);
}
$(document).on('input','.bs-bf-qty',recalcBranchStockTotal);

// Tiny HTML escaper for branch names that might contain quotes/&/<.
function escapeHtml(str){
    return String(str==null?'':str)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}
$(document).on('click','#bs-add-book',function(){
    clearBookForm();
    $('#bs-modal-title').text('Add New Book');
    openModal('#bs-book-modal');
    loadBookBranches(0);
});
$(document).on('click','.bs-edit-book',function(){
    get({action:'bs_get_book',id:$(this).data('id')}).then(res=>{
        if(!res.success)return alert('Could not load book.');
        const b=res.data;
        $('#bs-book-id').val(b.id);
        $('#bs-f-isbn').val(b.isbn);$('#bs-f-title').val(b.title);$('#bs-f-author').val(b.author);
        $('#bs-f-genre').val(b.genre);$('#bs-f-publisher').val(b.publisher);$('#bs-f-year').val(b.publish_year);
        $('#bs-f-cost').val(b.cost_price);$('#bs-f-price').val(b.sell_price);$('#bs-f-stock').val(b.stock_qty);
        $('#bs-f-threshold').val(b.low_stock_threshold);$('#bs-f-location').val(b.location);
        $('#bs-f-barcode').val(b.barcode);$('#bs-f-cover').val(b.cover_url);$('#bs-f-desc').val(b.description);
        $('#bs-f-status').val(b.status);
        $('#bs-modal-title').text('Edit Book');openModal('#bs-book-modal');
        updateMarginPreview();
        loadBookBranches(b.id);
    });
});
$(document).on('click','#bs-save-book',function(){
    const title=$('#bs-f-title').val().trim();
    if(!title){alert('Title is required before saving.');return;}
    const btn=$(this).prop('disabled',true).text('Saving…');
    const payload = {
        action:       'bs_save_book',
        id:           $('#bs-book-id').val()||0,
        title:        title,
        author:       $('#bs-f-author').val(),
        isbn:         $('#bs-f-isbn').val(),
        genre:        $('#bs-f-genre').val(),
        publisher:    $('#bs-f-publisher').val(),
        publish_year: $('#bs-f-year').val(),
        cost_price:   $('#bs-f-cost').val()||0,
        sell_price:   $('#bs-f-price').val()||0,
        stock_qty:    $('#bs-f-stock').val()||0,
        low_stock_threshold: $('#bs-f-threshold').val()||5,
        location:     $('#bs-f-location').val(),
        barcode:      $('#bs-f-barcode').val(),
        cover_url:    $('#bs-f-cover').val(),
        description:  $('#bs-f-desc').val(),
        status:       $('#bs-f-status').val()||'active',
    };
    // Append branch_stock[branch_id]=qty for any rendered per-branch inputs.
    // jQuery serializes a flat key like 'branch_stock[3]' verbatim, which is
    // exactly what PHP's $_POST['branch_stock'] expects (an associative
    // array keyed by branch id).
    $('#bs-f-branch-stock-list .bs-bf-qty').each(function(){
        var bid = parseInt($(this).data('branch-id'),10) || 0;
        if(!bid) return;
        payload['branch_stock['+bid+']'] = Math.max(0, parseInt($(this).val(),10) || 0);
    });
    $.ajax({
        url: ajax_url,
        method: 'POST',
        timeout: 15000,
        data: payload
    }).done(function(res){
        btn.prop('disabled',false).text('Save Book');
        if(res && res.success){
            location.reload();
        } else {
            alert('Save failed: '+(res.data||'Unknown error. Check the book title is filled in.'));
        }
    }).fail(function(xhr){
        btn.prop('disabled',false).text('Save Book');
        alert('Request failed ('+xhr.status+'). You may need to log in again.');
    });
});
$(document).on('click','.bs-delete-book',function(){
    if(!confirm('Archive this book?'))return;
    const id=$(this).data('id'),row=$(this).closest('tr');
    post({action:'bs_delete_book',id}).then(res=>{if(res.success)row.fadeOut(300,()=>row.remove());});
});
$(document).on('click','.bs-adjust-stock',function(){
    const id=$(this).data('id'),curr=$(this).data('qty');
    const qty=prompt('New stock quantity:',curr);
    if(qty===null||isNaN(qty))return;
    post({action:'bs_adjust_stock',id,qty:parseInt(qty)}).then(res=>{if(res.success)location.reload();});
});

// ── Books: Import CSV ─────────────────────────────────────────
$('#bs-import-csv-btn').on('click',()=>openModal('#bs-import-modal'));
$('#bs-csv-template').on('click',function(e){
    e.preventDefault();
    const rows=[['title','author','isbn','genre','publisher','publish_year','cost_price','sell_price','stock_qty','description'],
                ['Example Book','John Doe','9780000000000','Fiction','Publisher',2024,500,800,10,'A great book']];
    let csv=rows.map(r=>r.join(',')).join('\n');
    const a=document.createElement('a');a.href='data:text/csv;charset=utf-8,'+encodeURIComponent(csv);
    a.download='bookshop-template.csv';a.click();
});
$('#bs-do-import').on('click',function(){
    const file=$('#bs-csv-file')[0].files[0];
    if(!file){alert('Select a CSV file');return;}
    const fd=new FormData();fd.append('action','bs_import_csv');fd.append('nonce',nonce);fd.append('csv',file);
    const btn=$(this).prop('disabled',true).text('Importing…');
    $.ajax({url:ajax_url,type:'POST',data:fd,contentType:false,processData:false}).then(res=>{
        btn.prop('disabled',false).text('Import');
        if(res.success){
            const d=res.data;
            let msg=`<strong style="color:green">✓ Imported ${d.imported} book(s)</strong>`;
            if(d.errors&&d.errors.length)msg+=`<br><small style="color:red">${d.errors.join('<br>')}</small>`;
            $('#bs-import-result').html(msg);
            setTimeout(()=>location.reload(),1500);
        } else $('#bs-import-result').html('<span style="color:red">Import failed.</span>');
    });
});
$('#bs-import-woo-btn').on('click',function(){
    if(!confirm('Import products from WooCommerce?'))return;
    $(this).prop('disabled',true).text('Importing…');
    post({action:'bs_import_woo'}).then(res=>{
        $(this).prop('disabled',false).text('🛒 Import WooCommerce');
        if(res.success)alert('Imported '+res.data.imported+' products.');
        else alert('Import failed or WooCommerce not active.');
    });
});

// ── Sales: View items ─────────────────────────────────────────
$(document).on('click','.bs-view-sale-items',function(){
    const id=$(this).data('id'),ref=$(this).data('ref');
    $('#bs-sale-items-modal .bs-modal-header h2').text('Items — '+ref);
    $('#bs-sale-items-body').html('<em>Loading…</em>');
    openModal('#bs-sale-items-modal');
    get({action:'bs_get_sale_items',id}).then(res=>{
        if(!res.success){$('#bs-sale-items-body').html('<em>Error.</em>');return;}
        let html='<table class="bs-table bs-table-sm"><thead><tr><th>Title</th><th>Author</th><th>ISBN</th><th>Qty</th><th>Unit Price</th><th>Total</th></tr></thead><tbody>';
        res.data.forEach(i=>html+=`<tr><td>${esc(i.title)}</td><td>${esc(i.author)}</td><td>${esc(i.isbn)}</td><td>${i.qty}</td><td>${fmt(i.unit_price)}</td><td><strong>${fmt(i.line_total)}</strong></td></tr>`);
        html+='</tbody></table>';
        $('#bs-sale-items-body').html(html);
    });
});
$(document).on('click','.bs-void-sale',function(){
    if(!confirm('Void this sale? Stock will be restored.'))return;
    post({action:'bs_void_sale',id:$(this).data('id')}).then(res=>{
        if(res.success)location.reload();else alert('Cannot void sale.');
    });
});

// ── Sales: Reservation status ─────────────────────────────────
$(document).on('change','.bs-res-status-select',function(){
    post({action:'bs_update_reservation',id:$(this).data('id'),status:$(this).val()});
});

// ── Customers ─────────────────────────────────────────────────
function filterCustomers(){
    const q=$('#bs-cust-search').val().toLowerCase();
    $('#bs-cust-table tbody tr').each(function(){
        const n=$(this).data('name')||'';
        const p=$(this).data('phone')||'';
        const e=$(this).data('email')||'';
        $(this).toggle(!q||(n+p+e).includes(q));
    });
}
$('#bs-cust-search').on('input',filterCustomers);

$('#bs-add-customer').on('click',()=>{$('#bs-cust-id').val('');['name','phone','email','birthday','address','notes'].forEach(f=>$('#bs-cf-'+f).val(''));$('#bs-cf-status').val('active');openModal('#bs-cust-modal');});
$(document).on('click','.bs-edit-customer',function(){
    get({action:'bs_get_customer',id:$(this).data('id')}).then(res=>{
        if(!res.success)return;
        const c=res.data;
        $('#bs-cust-id').val(c.id);$('#bs-cf-name').val(c.name);$('#bs-cf-phone').val(c.phone);
        $('#bs-cf-email').val(c.email);$('#bs-cf-birthday').val(c.birthday||'');
        $('#bs-cf-address').val(c.address);$('#bs-cf-notes').val(c.notes);$('#bs-cf-status').val(c.status);
        openModal('#bs-cust-modal');
    });
});
$('#bs-save-customer').on('click',function(){
    if(!$('#bs-cf-name').val().trim()){alert('Name required');return;}
    const btn=$(this).prop('disabled',true).text('Saving…');
    post({action:'bs_save_customer',id:$('#bs-cust-id').val(),name:$('#bs-cf-name').val(),
        phone:$('#bs-cf-phone').val(),email:$('#bs-cf-email').val(),birthday:$('#bs-cf-birthday').val(),
        address:$('#bs-cf-address').val(),notes:$('#bs-cf-notes').val(),status:$('#bs-cf-status').val()
    }).then(res=>{btn.prop('disabled',false).text('Save');if(res.success)location.reload();});
});
$(document).on('click','.bs-view-cust-history',function(){
    const id=$(this).data('id'),name=$(this).data('name');
    $('#bs-cust-history-modal .bs-modal-header h2').text('History — '+name);
    $('#bs-cust-history-body').html('<em>Loading…</em>');
    openModal('#bs-cust-history-modal');
    // Load sales filtered by customer
    $.get(ajax_url,{action:'bs_get_cust_history',id}).then(res=>{
        if(!res||!res.success){$('#bs-cust-history-body').html('<em>No history.</em>');return;}
        let html='<table class="bs-table bs-table-sm"><thead><tr><th>Ref</th><th>Date</th><th>Total</th><th>Payment</th></tr></thead><tbody>';
        res.data.forEach(s=>html+=`<tr><td><code>${esc(s.sale_ref)}</code></td><td>${esc(s.created_at?.slice(0,10))}</td><td>${fmt(s.total)}</td><td>${esc(s.payment_method)}</td></tr>`);
        html+='</tbody></table>';$('#bs-cust-history-body').html(html);
    }).fail(()=>$('#bs-cust-history-body').html('<em>No history found.</em>'));
});
$(document).on('click','.bs-add-credit-btn',function(){
    const id=$(this).data('id'),name=$(this).data('name');
    const amount=prompt(`Add credit for ${name} (${currency}):`)
    if(!amount||isNaN(amount))return;
    const note=prompt('Reason:')||'';
    post({action:'bs_add_credit',customer_id:id,amount,note}).then(res=>{if(res.success){alert('Credit added!');location.reload();}});
});
$(document).on('click','.bs-adjust-loyalty-btn',function(){
    const id=$(this).data('id'),name=$(this).data('name');
    const pts=prompt(`Adjust loyalty points for ${name} (use negative to deduct):`);
    if(!pts||isNaN(pts))return;
    const note=prompt('Reason:')||'Manual adjustment';
    post({action:'bs_adjust_loyalty',customer_id:id,points:parseInt(pts),note}).then(res=>{if(res.success){alert('Points adjusted!');location.reload();}});
});

// ── Suppliers ─────────────────────────────────────────────────
$('#bs-add-supplier').on('click',()=>{$('#bs-sup-id').val('');['name','contact','email','phone','address','notes'].forEach(f=>$('#bs-sf-'+f).val(''));openModal('#bs-sup-modal');});
$(document).on('click','.bs-edit-supplier',function(){
    get({action:'bs_get_supplier',id:$(this).data('id')}).then(res=>{
        if(!res.success)return;const s=res.data;
        $('#bs-sup-id').val(s.id);$('#bs-sf-name').val(s.name);$('#bs-sf-contact').val(s.contact_name);
        $('#bs-sf-email').val(s.email);$('#bs-sf-phone').val(s.phone);$('#bs-sf-address').val(s.address);$('#bs-sf-notes').val(s.notes);
        openModal('#bs-sup-modal');
    });
});
$('#bs-save-supplier').on('click',function(){
    const btn=$(this).prop('disabled',true).text('Saving…');
    post({action:'bs_save_supplier',id:$('#bs-sup-id').val(),name:$('#bs-sf-name').val(),contact_name:$('#bs-sf-contact').val(),
        email:$('#bs-sf-email').val(),phone:$('#bs-sf-phone').val(),address:$('#bs-sf-address').val(),notes:$('#bs-sf-notes').val()
    }).then(res=>{btn.prop('disabled',false).text('Save');if(res.success)location.reload();});
});

// ── Purchase Orders ───────────────────────────────────────────
$('#bs-create-po').on('click',()=>{updatePOTotal();openModal('#bs-po-modal');});
$('#bs-po-add-item').on('click',()=>{
    const row=$('.bs-po-item:first').clone();
    row.find('input').val('');row.find('.bs-po-qty').val(1);
    $('#bs-po-items').append(row);
});
$(document).on('click','.bs-po-remove-item',function(){
    if($('.bs-po-item').length>1)$(this).closest('.bs-po-item').remove();
    updatePOTotal();
});
$(document).on('input','.bs-po-qty,.bs-po-cost',updatePOTotal);
function updatePOTotal(){
    let total=0;
    $('.bs-po-item').each(function(){
        const qty=parseFloat($(this).find('.bs-po-qty').val())||0;
        const cost=parseFloat($(this).find('.bs-po-cost').val())||0;
        total+=qty*cost;
    });
    $('#bs-po-total-display').text('Total: '+currency+total.toFixed(2));
}
$('#bs-save-po').on('click',function(){
    const items=[];
    $('.bs-po-item').each(function(){
        const bid=$(this).find('.bs-po-book-id').val();
        const qty=$(this).find('.bs-po-qty').val();
        const cost=$(this).find('.bs-po-cost').val();
        if(qty&&cost) items.push({book_id:bid||0,qty,cost});
    });
    if(!items.length){alert('Add at least one item');return;}
    const btn=$(this).prop('disabled',true).text('Creating…');
    post({action:'bs_create_po',supplier_id:$('#bs-po-supplier').val(),items:JSON.stringify(items),notes:$('#bs-po-notes').val()
    }).then(res=>{btn.prop('disabled',false).text('Create PO');if(res.success){alert('PO created!');location.reload();}});
});
// Book search in PO form
$(document).on('input','.bs-po-book-search',function(){
    const el=$(this);const q=el.val().trim();
    if(q.length<2)return;
    get({action:'bs_search_books',q}).then(res=>{
        if(!res.success)return;
        // simple first-match fill
        const b=res.data[0];if(!b)return;
        el.val(b.title);
        el.closest('.bs-po-item').find('.bs-po-book-id').val(b.id);
        el.closest('.bs-po-item').find('.bs-po-cost').val(b.cost_price);
    });
});
$(document).on('click','.bs-view-po,.bs-receive-po',function(){
    const id=$(this).data('id'),ref=$(this).data('ref');
    const isReceive=$(this).hasClass('bs-receive-po');
    $('#bs-po-view-modal .bs-modal-header h2').text('PO — '+ref);
    $('#bs-po-view-body').html('<em>Loading…</em>');
    openModal('#bs-po-view-modal');
    $('#bs-confirm-receive').toggle(isReceive).data('po-id',id);
    get({action:'bs_get_po_items',id}).then(res=>{
        let html='<table class="bs-table bs-table-sm"><thead><tr><th>Book</th><th>ISBN</th><th>Ordered</th>'+(isReceive?'<th>Received</th>':'')+'<th>Cost</th><th>Line Total</th></tr></thead><tbody>';
        (res.data||[]).forEach(i=>html+=`<tr><td>${esc(i.title)}</td><td>${esc(i.isbn)}</td><td>${i.qty_ordered}</td>${isReceive?`<td><input type='number' class='bs-input po-recv-qty' data-item-id='${i.id}' value='${i.qty_ordered}' min='0' max='${i.qty_ordered}' style='width:70px'></td>`:''}<td>${fmt(i.unit_cost)}</td><td>${fmt(i.unit_cost*i.qty_ordered)}</td></tr>`);
        html+='</tbody></table>';
        $('#bs-po-view-body').html(html);
    });
});
$('#bs-confirm-receive').on('click',function(){
    const po_id=$(this).data('po-id');
    const received={};
    $('.po-recv-qty').each(function(){received[$(this).data('item-id')]=$(this).val();});
    post({action:'bs_receive_po',po_id,received:JSON.stringify(received)}).then(res=>{if(res.success){alert('Stock updated!');location.reload();}});
});

// ── Promotions ────────────────────────────────────────────────
$('#bs-add-promo').on('click',()=>{$('#bs-promo-id').val('');$('#bs-pf-name,#bs-pf-code,#bs-pf-value,#bs-pf-min').val('');$('#bs-pf-limit').val(0);$('#bs-pf-buy').val(2);$('#bs-pf-get').val(1);$('#bs-pf-start,#bs-pf-end').val('');$('#bs-pf-type').val('percent');$('#bs-pf-status').val('active');$('#bs-pf-manager').prop('checked',false);toggleBxGy();openModal('#bs-promo-modal');});
$(document).on('click','.bs-edit-promo',function(){
    get({action:'bs_get_promotion',id:$(this).data('id')}).then(res=>{
        if(!res.success)return;const p=res.data;
        $('#bs-promo-id').val(p.id);$('#bs-pf-name').val(p.name);$('#bs-pf-code').val(p.code);
        $('#bs-pf-type').val(p.type);$('#bs-pf-value').val(p.value);$('#bs-pf-min').val(p.min_purchase);
        $('#bs-pf-buy').val(p.buy_qty);$('#bs-pf-get').val(p.get_qty);$('#bs-pf-limit').val(p.usage_limit||0);
        $('#bs-pf-start').val(p.start_date||'');$('#bs-pf-end').val(p.end_date||'');
        $('#bs-pf-status').val(p.status);$('#bs-pf-manager').prop('checked',p.requires_manager==1);
        toggleBxGy();openModal('#bs-promo-modal');
    });
});
function toggleBxGy(){const t=$('#bs-pf-type').val();$('#bs-pf-bxgy-group,#bs-pf-bxgy-group2').toggle(t==='buy_x_get_y');}
$('#bs-pf-type').on('change',toggleBxGy);
$('#bs-save-promo').on('click',function(){
    if(!$('#bs-pf-name').val().trim()){alert('Name required');return;}
    const btn=$(this).prop('disabled',true).text('Saving…');
    post({action:'bs_save_promotion',id:$('#bs-promo-id').val(),name:$('#bs-pf-name').val(),code:$('#bs-pf-code').val(),
        type:$('#bs-pf-type').val(),value:$('#bs-pf-value').val(),min_purchase:$('#bs-pf-min').val(),
        buy_qty:$('#bs-pf-buy').val(),get_qty:$('#bs-pf-get').val(),usage_limit:$('#bs-pf-limit').val(),
        start_date:$('#bs-pf-start').val(),end_date:$('#bs-pf-end').val(),status:$('#bs-pf-status').val(),
        requires_manager:$('#bs-pf-manager').is(':checked')?1:0
    }).then(res=>{btn.prop('disabled',false).text('Save Promotion');if(res.success)location.reload();});
});
$(document).on('click','.bs-delete-promo',function(){
    if(!confirm('Deactivate this promotion?'))return;
    post({action:'bs_delete_promotion',id:$(this).data('id')}).then(res=>{if(res.success)location.reload();});
});

// ── Reports: Charts ───────────────────────────────────────────
// ── Reports Charts ────────────────────────────────────────────
const PALETTE=['#c8860a','#2a7a3b','#1565c0','#8a5c00','#c0392b','#e67e22','#8e44ad','#16a085','#2c3e50','#7f8c8d','#d35400','#27ae60'];

// Daily Revenue + Transactions (dual axis)
const dailyEl=document.getElementById('bs-daily-chart');
if(dailyEl){
    const raw=JSON.parse(document.getElementById('bs-daily-data')?.textContent||'[]');
    if(raw.length){
        new Chart(dailyEl,{
            type:'bar',
            data:{
                labels:raw.map(r=>r.day),
                datasets:[
                    {label:'Revenue',data:raw.map(r=>parseFloat(r.revenue)||0),
                     backgroundColor:'rgba(200,134,10,.75)',borderColor:'#c8860a',borderWidth:1,borderRadius:4,yAxisID:'y'},
                    {label:'Transactions',type:'line',data:raw.map(r=>parseInt(r.sales_count)||0),
                     borderColor:'#2a7a3b',backgroundColor:'rgba(42,122,59,.1)',borderWidth:2,
                     pointRadius:3,tension:.3,yAxisID:'y1'},
                ]
            },
            options:{
                responsive:true,
                interaction:{mode:'index',intersect:false},
                plugins:{legend:{position:'top'}},
                scales:{
                    y:{beginAtZero:true,position:'left',ticks:{callback:v=>currency+v.toLocaleString()}},
                    y1:{beginAtZero:true,position:'right',grid:{drawOnChartArea:false}}
                }
            }
        });
    } else {
        dailyEl.parentElement.innerHTML+='<p style="color:#999;text-align:center;margin-top:20px">No sales data for this period.</p>';
        dailyEl.remove();
    }
}

// Genre doughnut
const genreEl=document.getElementById('bs-genre-chart');
if(genreEl){
    const raw=JSON.parse(document.getElementById('bs-genre-data')?.textContent||'[]');
    if(raw.length){
        new Chart(genreEl,{type:'doughnut',
            data:{labels:raw.map(r=>r.genre||'Unknown'),datasets:[{data:raw.map(r=>parseFloat(r.revenue)||0),backgroundColor:PALETTE}]},
            options:{responsive:true,plugins:{legend:{position:'right'},tooltip:{callbacks:{label:ctx=>ctx.label+': '+currency+parseFloat(ctx.raw).toLocaleString('en',{minimumFractionDigits:2})}}}}
        });
    }
}

// Hourly bar chart
const hourlyEl=document.getElementById('bs-hourly-chart');
if(hourlyEl){
    const raw=JSON.parse(document.getElementById('bs-hourly-data')?.textContent||'[]');
    const labels=raw.map(r=>{const h=parseInt(r.hr);return(h===0?'12am':h<12?h+'am':h===12?'12pm':(h-12)+'pm');});
    new Chart(hourlyEl,{type:'bar',
        data:{labels,datasets:[{label:'Sales',data:raw.map(r=>parseInt(r.sales_count)||0),backgroundColor:'rgba(200,134,10,.7)',borderRadius:3}]},
        options:{responsive:true,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{stepSize:1}}}}
    });
}

// Payment methods doughnut
const payEl=document.getElementById('bs-pay-chart');
if(payEl){
    const raw=JSON.parse(document.getElementById('bs-pay-data')?.textContent||'[]');
    if(raw.length){
        new Chart(payEl,{type:'doughnut',
            data:{labels:raw.map(r=>r.payment_method.charAt(0).toUpperCase()+r.payment_method.slice(1)),
                  datasets:[{data:raw.map(r=>parseFloat(r.revenue)||0),backgroundColor:PALETTE}]},
            options:{responsive:true,plugins:{legend:{position:'right'},tooltip:{callbacks:{label:ctx=>ctx.label+': '+currency+parseFloat(ctx.raw).toLocaleString('en',{minimumFractionDigits:2})}}}}
        });
    }
}

// ── Staff: PIN ─────────────────────────────────────────────────
$(document).on('click','.bs-set-pin',function(){
    $('#bs-pin-user-id').val($(this).data('id'));
    $('#bs-pin-staff-name').text($(this).data('name'));
    $('#bs-pin-input').val('');
    openModal('#bs-pin-modal');
});
// Save PIN. Earlier this had two failure modes that both looked like
// "the button refused to save":
//   1. The .then() handler ignored the error case entirely — a bad nonce
//      (expired admin session) or any 4xx left the modal open with no
//      feedback. We now show the server message and re-enable the button.
//   2. The 4–8 digit constraint was only enforced on the login side, so
//      a non-numeric PIN saved fine but couldn't be used. Match the
//      login regex client-side so the user finds out before the round-trip.
$(document).on('click','#bs-save-pin',function(){
    var uid = $('#bs-pin-user-id').val();
    var pin = $('#bs-pin-input').val();
    if(!/^[0-9]{4,8}$/.test(pin)){
        alert('PIN must be 4–8 digits (numbers only).');
        return;
    }
    var $btn = $(this).prop('disabled',true).text('Saving…');
    post({action:'bs_set_pin', user_id:uid, pin:pin}).done(function(res){
        $btn.prop('disabled',false).text('Save PIN');
        if(res && res.success){
            closeModals();
            alert('PIN saved.');
            location.reload();
        } else {
            alert('Save failed: '+((res && res.data) ? res.data : 'Unknown error. Try logging out and back in if the problem persists.'));
        }
    }).fail(function(xhr){
        $btn.prop('disabled',false).text('Save PIN');
        alert('Request failed ('+xhr.status+'). You may need to refresh the page or log in again.');
    });
});

// ── Settings ──────────────────────────────────────────────────
$('#bs-save-settings').on('click',function(){
    const btn=$(this).prop('disabled',true).text('Saving…');
    // Use FormData so password fields and textareas are all included
    const data={action:'bs_save_settings'};
    // Collect every .bs-setting element regardless of type
    $('.bs-setting').each(function(){
        const name=$(this).attr('name');
        if(!name) return;
        const tag=$(this).prop('tagName').toLowerCase();
        const type=$(this).attr('type')||'';
        // Include all input types including password, text, number, email, url, select, textarea
        data[name]=$(this).val();
    });
    $.ajax({url:ajax_url,method:'POST',data,timeout:15000})
     .done(function(res){
        btn.prop('disabled',false).text('💾 Save All Settings');
        if(res&&res.success){
            $('#bs-settings-msg').show();
            setTimeout(()=>$('#bs-settings-msg').hide(),3000);
        } else {
            alert('Save failed: '+(res&&res.data?res.data:'Unknown error'));
        }
    }).fail(function(xhr){
        btn.prop('disabled',false).text('💾 Save All Settings');
        alert('Request failed ('+xhr.status+'). Please try again.');
    });
});

// ── Branches ──────────────────────────────────────────────────────────────────
$(document).on('click','#bs-add-branch',function(){
    $('#bs-branch-id').val('');
    $('#bs-bf-name,#bs-bf-phone,#bs-bf-email,#bs-bf-manager').val('');
    $('#bs-bf-address').val('');
    $('#bs-bf-status').val('active');
    // Backfill choice only matters on the create path. Show it here.
    $('#bs-branch-backfill-row').show();
    // Re-seed row is for existing branches only.
    $('#bs-branch-reseed-row').hide();
    openModal('#bs-branch-modal');
});
$(document).on('click','.bs-edit-branch',function(){
    get({action:'bs_get_branch',id:$(this).data('id')}).then(function(res){
        if(!res.success) return;
        var b=res.data;
        $('#bs-branch-id').val(b.id);
        $('#bs-bf-name').val(b.name);$('#bs-bf-address').val(b.address);
        $('#bs-bf-phone').val(b.phone);$('#bs-bf-email').val(b.email);
        $('#bs-bf-manager').val(b.manager);$('#bs-bf-status').val(b.status);
        // Hide the *initial-stock* picker on edit — it controls the create
        // path only and INSERT IGNORE makes "Copy" deceptive on a branch
        // that's already accumulated genuine numbers.
        $('#bs-branch-backfill-row').hide();
        // Re-seed row: only show when there's actually something to seed.
        // The server returns missing_count = active books that don't yet
        // have a row at this branch, which is exactly what would currently
        // be rejected by the oversell guard.
        var missing = parseInt(b.missing_count||0);
        if(missing > 0){
            $('#bs-branch-reseed-summary').html(
                '<strong>'+missing+'</strong> active book'+(missing===1?'':'s')+
                ' '+(missing===1?'has':'have')+
                ' no row in this branch\'s stock yet. Sales of these books at this branch will be rejected by the oversell guard until they\'re seeded.'
            );
            // Reset disabled state in case the user is reopening after a
            // partial seed earlier in the session.
            $('#bs-branch-reseed-actions .bs-branch-reseed-btn')
                .prop('disabled',false)
                .each(function(){
                    var m=$(this).data('mode');
                    $(this).text(m==='copy'?'Seed at current global stock':'Seed at zero');
                });
            $('#bs-branch-reseed-row').show();
        } else {
            $('#bs-branch-reseed-row').hide();
        }
        openModal('#bs-branch-modal');
    });
});

// Re-seed buttons inside the edit modal. Hits the admin-only re-seed
// endpoint, then refreshes the prompt in place — if missing_count is now
// zero, the section hides itself rather than reload the page (the rest of
// the modal state would be lost).
$(document).on('click','.bs-branch-reseed-btn',function(){
    var mode      = $(this).data('mode');
    var branchId  = parseInt($('#bs-branch-id').val()) || 0;
    if(!branchId){ alert('Save the branch first.'); return; }
    var allBtns   = $('.bs-branch-reseed-btn');
    var btn       = $(this).prop('disabled',true).text('Seeding…');
    allBtns.not(btn).prop('disabled',true);
    post({action:'bs_reseed_branch_stock', branch_id:branchId, mode:mode}).then(function(res){
        if(!res.success){
            alert('Re-seed failed: '+(res.data||'Unknown error'));
            allBtns.prop('disabled',false).each(function(){
                var m=$(this).data('mode');
                $(this).text(m==='copy'?'Seed at current global stock':'Seed at zero');
            });
            return;
        }
        var seeded  = parseInt(res.data.seeded||0);
        var missing = parseInt(res.data.missing||0);
        if(missing > 0){
            // Some books still missing (would only happen if "active" status
            // changed during the request). Update the count and let the user
            // try the other mode.
            $('#bs-branch-reseed-summary').html(
                '<strong>Seeded '+seeded+' book'+(seeded===1?'':'s')+'.</strong> '
                + '<strong>'+missing+'</strong> still missing.'
            );
            allBtns.prop('disabled',false).each(function(){
                var m=$(this).data('mode');
                $(this).text(m==='copy'?'Seed at current global stock':'Seed at zero');
            });
        } else {
            $('#bs-branch-reseed-summary').html(
                '<strong>✓ Seeded '+seeded+' book'+(seeded===1?'':'s')+'.</strong> '
                + 'No books are missing rows at this branch anymore.'
            );
            $('#bs-branch-reseed-actions').hide();
        }
    });
});
$(document).on('click','#bs-save-branch',function(){
    if(!$('#bs-bf-name').val().trim()){alert('Branch name required');return;}
    var isNew = !$('#bs-branch-id').val();
    var backfill = isNew ? ($('input[name="bs-bf-backfill"]:checked').val() || '') : '';
    const btn=$(this).prop('disabled',true).text('Saving…');
    post({action:'bs_save_branch',id:$('#bs-branch-id').val(),
        name:$('#bs-bf-name').val(),address:$('#bs-bf-address').val(),
        phone:$('#bs-bf-phone').val(),email:$('#bs-bf-email').val(),
        manager:$('#bs-bf-manager').val(),status:$('#bs-bf-status').val(),
        backfill:backfill
    }).then(function(res){
        btn.prop('disabled',false).text('Save Branch');
        if(!res.success){alert('Save failed.');return;}
        // If the server seeded N branch_stock rows, mention it before reload
        // so the manager has a chance to see what just happened.
        var seeded = res.data && parseInt(res.data.backfilled||0);
        if(seeded > 0){
            alert('Branch saved. ' + seeded + ' books seeded into this branch\'s stock.');
        }
        location.reload();
    });
});
$(document).on('click','.bs-view-branch-stock',function(){
    const id=$(this).data('id'),name=$(this).data('name');
    $('#bs-branch-stock-modal .bs-modal-header h2').text('Stock — '+name);
    $('#bs-branch-stock-body').html('<em>Loading…</em>');
    openModal('#bs-branch-stock-modal');
    get({action:'bs_get_branch_stock',id}).then(function(res){
        if(!res.success){$('#bs-branch-stock-body').html('<em>Error</em>');return;}
        var html='<table class="bs-table bs-table-sm"><thead><tr><th>Title</th><th>Author</th><th>ISBN</th><th>Stock</th><th>Price</th></tr></thead><tbody>';
        (res.data||[]).forEach(function(b){
            var cls=parseInt(b.qty)<=parseInt(b.low_stock_threshold)?'bs-low-stock':'';
            html+='<tr><td>'+esc(b.title)+'</td><td>'+esc(b.author)+'</td><td>'+esc(b.isbn)+'</td><td class="'+cls+'">'+b.qty+'</td><td>'+fmt(b.sell_price)+'</td></tr>';
        });
        if(!res.data.length) html+='<tr><td colspan="5" style="text-align:center;color:#999;padding:20px">No stock recorded for this branch.</td></tr>';
        html+='</tbody></table>';
        $('#bs-branch-stock-body').html(html);
    });
});
$(document).on('click','#bs-check-reorder',function(){
    const btn=$(this).prop('disabled',true).text('Checking…');
    post({action:'bs_check_reorder'}).then(function(res){
        btn.prop('disabled',false).text('🔄 Check Reorder Points');
        if(res.success) alert(res.data.message);
    });
});

// ── Messaging ─────────────────────────────────────────────────────────────────
// Map<id, customer> — single source of truth for the recipient list. Segment
// load, search-add, and per-chip removal all mutate this same store so the
// chip render and the eventual send always see a consistent set.
var msgRecipients = new Map();

function msgRenderRecipients(){
    var $count = $('#msg-segment-result');
    var $list  = $('#msg-recipient-list');
    var $clear = $('#msg-clear-all');
    var customers = Array.from(msgRecipients.values());

    if (customers.length === 0) {
        $count.html('<em>No recipients selected.</em>');
        $list.html('<div style="text-align:center;color:#bba89c;padding:14px;font-size:.82rem">Use a segment filter or the search above to add customers.</div>');
        $clear.hide();
        return;
    }

    var withEmail = customers.filter(function(c){return c.email;}).length;
    var withPhone = customers.filter(function(c){return c.phone;}).length;
    $count.html('<strong>'+customers.length+'</strong> recipient'+(customers.length!==1?'s':'')+
                ' &mdash; <span style="color:#2a7a3b">'+withEmail+' email</span>'+
                ', <span style="color:#004085">'+withPhone+' phone</span>');
    $clear.show();

    var html = customers.map(function(c){
        var meta = c.email || c.phone || '';
        return '<span class="msg-chip" style="display:inline-flex;align-items:center;gap:6px;background:#fff;border:1px solid #e0d4c0;border-radius:14px;padding:4px 4px 4px 10px;margin:3px;font-size:.78rem;line-height:1.4;max-width:100%">' +
            '<span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:200px"><strong>'+esc(c.name||'?')+'</strong>'+(meta?' <span style="color:#8a7a65">'+esc(meta)+'</span>':'')+'</span>' +
            '<button class="msg-remove-recipient" data-id="'+c.id+'" title="Remove" style="border:0;background:#f5ede0;color:#8a7a65;cursor:pointer;width:18px;height:18px;border-radius:50%;font-size:.85em;line-height:1;padding:0">×</button>' +
            '</span>';
    }).join('');
    $list.html(html);
}

// Initial render so the empty-state message shows up on page load.
$(function(){ if($('#msg-recipient-list').length) msgRenderRecipients(); });

// Segment load — replaces the current set with the segment results.
$(document).on('click','#bs-load-segment',function(){
    var genre=$('#msg-genre').val(), days=$('#msg-days').val(), spend=$('#msg-min-spend').val();
    var btn=$(this).prop('disabled',true).text('Loading…');
    get({action:'bs_get_customer_segment',genre:genre,days:days,min_spend:spend}).then(function(res){
        btn.prop('disabled',false).text('Load Segment');
        if(!res.success){alert('Failed to load');return;}
        msgRecipients.clear();
        (res.data||[]).forEach(function(c){ msgRecipients.set(parseInt(c.id), c); });
        msgRenderRecipients();
    });
});

// Clear all
$(document).on('click','#msg-clear-all',function(){
    msgRecipients.clear();
    msgRenderRecipients();
});

// Per-chip remove
$(document).on('click','.msg-remove-recipient',function(){
    var id = parseInt($(this).data('id'));
    msgRecipients.delete(id);
    msgRenderRecipients();
});

// Search-add: debounced search, pick a result to add, click-outside dismiss.
var msgSearchTimer = null;
$(document).on('input','#msg-customer-search',function(){
    clearTimeout(msgSearchTimer);
    var q = $(this).val().trim();
    var $box = $('#msg-customer-search-results');
    if(q.length < 2){ $box.hide().empty(); return; }
    msgSearchTimer = setTimeout(function(){
        get({action:'bs_search_customers', q:q}).then(function(res){
            if(!res.success) return;
            var results = res.data || [];
            if(results.length === 0){
                $box.html('<div style="padding:10px;color:#8a7a65;font-size:.82rem">No matches</div>').show();
                return;
            }
            $box.html(results.map(function(c){
                var inSet = msgRecipients.has(parseInt(c.id));
                var meta  = c.email || c.phone || '';
                return '<div class="msg-search-result" data-customer=\''+esc(JSON.stringify(c))+'\' style="padding:8px 12px;cursor:pointer;border-bottom:1px solid #f5ede0;'+(inSet?'opacity:.55;':'')+'">' +
                    '<strong>'+esc(c.name)+'</strong>' +
                    (meta?' <span style="color:#8a7a65;font-size:.85em">'+esc(meta)+'</span>':'') +
                    (inSet?' <span style="color:#2a7a3b;font-size:.78em;float:right">✓ added</span>':'') +
                    '</div>';
            }).join('')).show();
        });
    }, 200);
});

$(document).on('click','.msg-search-result',function(){
    var c;
    try { c = JSON.parse($(this).attr('data-customer')); } catch(e){ return; }
    if(c && c.id && !msgRecipients.has(parseInt(c.id))){
        msgRecipients.set(parseInt(c.id), c);
        msgRenderRecipients();
    }
    $('#msg-customer-search').val('').focus();
    $('#msg-customer-search-results').hide().empty();
});

// Click outside the search dropdown closes it.
$(document).on('click', function(e){
    if(!$(e.target).closest('#msg-customer-search-results, #msg-customer-search').length){
        $('#msg-customer-search-results').hide();
    }
});

// Preview — render the same HTML the recipient will see.
$(document).on('click','#bs-preview-msg',function(){
    var subject = $('#msg-subject').val().trim();
    var body    = $('#msg-body').val().trim();
    if(!body){ alert('Type a message body first to preview it.'); return; }
    var btn = $(this).prop('disabled',true).text('Loading…');
    post({action:'bs_preview_bulk_email', subject:subject, body:body}).then(function(res){
        btn.prop('disabled',false).text('👁 Preview');
        if(!res.success){ alert('Preview failed: '+(res.data||'')); return; }
        $('#msg-preview-subject').html('<strong>Subject:</strong> '+esc(res.data.subject||'(no subject)'));
        // res.data.html is server-built from sanitised text + esc_html, so it
        // can be injected directly here.
        $('#msg-preview-body').html(res.data.html);
        $('#msg-preview-modal').show();
    });
});
$(document).on('click','#msg-preview-close',function(){ $('#msg-preview-modal').hide(); });
$(document).on('click','#msg-preview-modal',function(e){
    if(e.target.id === 'msg-preview-modal') $(this).hide();
});

// Send
$(document).on('click','#bs-send-msg',function(){
    var ids = Array.from(msgRecipients.keys());
    if(!ids.length){ alert('No recipients selected. Use a segment filter or the search above to add customers.'); return; }
    var channel = $('#msg-channel').val();
    var subject = $('#msg-subject').val().trim();
    var body    = $('#msg-body').val().trim();
    if(!body){ alert('Message body required'); return; }
    if((channel==='email'||channel==='both') && !subject){ alert('Email subject required'); return; }
    var btn = $(this).prop('disabled',true).text('Sending…');

    if(channel==='email' || channel==='both'){
        post({action:'bs_send_bulk_email', customer_ids:JSON.stringify(ids), subject:subject, body:body}).then(function(res){
            btn.prop('disabled',false).text('Send Message');
            if(res.success) $('#msg-send-result').html('<span style="color:var(--green)">✓ Sent to '+res.data.sent+' customers ('+res.data.failed+' failed)</span>');
            else            $('#msg-send-result').html('<span style="color:var(--red)">Error: '+esc(res.data)+'</span>');
        });
    }
    if(channel==='whatsapp' || channel==='both'){
        post({action:'bs_get_whatsapp_links', customer_ids:JSON.stringify(ids), message:body}).then(function(res){
            btn.prop('disabled',false).text('Send Message');
            if(!res.success) return;
            var links = res.data || [];
            var html = links.map(function(l){
                return '<div style="padding:6px 0;border-bottom:1px solid #f0e8d8;display:flex;align-items:center;gap:10px"><span style="flex:1;font-size:.83rem">'+esc(l.name)+' ('+esc(l.phone)+')</span><a href="'+esc(l.url)+'" target="_blank" class="bs-btn bs-btn-secondary" style="font-size:.75rem;padding:4px 10px">Open WhatsApp</a></div>';
            }).join('');
            $('#msg-wa-links-body').html(html);
            $('#msg-wa-links').show();
        });
    }
});

$('#msg-channel').on('change',function(){
    $('#msg-email-fields').toggle($(this).val()!=='whatsapp');
});

// ── Online Orders & API ────────────────────────────────────────────────────────
$(document).on('click','#bs-gen-api-key',function(){
    if(!confirm('Regenerate API key? The old key will stop working immediately.')) return;
    post({action:'bs_generate_api_key'}).then(function(res){
        if(res.success) $('#bs-api-key-display').val(res.data.key);
    });
});
$(document).on('click','#bs-add-webhook',function(){
    const url=$('#wh-url').val().trim();
    const event=$('#wh-event').val();
    const secret=$('#wh-secret').val();
    if(!url){alert('URL required');return;}
    post({action:'bs_add_webhook',url,event,secret}).then(function(res){
        if(res.success){
            $('#wh-list').append('<tr data-id="'+res.data.id+'"><td>'+esc(url)+'</td><td><code>'+esc(event)+'</code></td><td><span class="bs-badge bs-badge-active">active</span></td><td><button class="bs-btn-link bs-delete-webhook" data-id="'+res.data.id+'">Delete</button></td></tr>');
            $('#wh-url,#wh-secret').val('');
        }
    });
});
$(document).on('click','.bs-delete-webhook',function(){
    if(!confirm('Delete this webhook?')) return;
    const id=$(this).data('id'),row=$(this).closest('tr');
    post({action:'bs_delete_webhook',id}).then(function(res){if(res.success) row.remove();});
});
$(document).on('click','.bs-view-online-order',function(){
    const ref=$(this).data('ref');
    const items=$(this).data('items')||[];
    $('#bs-oo-modal-title').text('Order — '+ref);
    var html='<div style="padding:16px"><table class="bs-table bs-table-sm"><thead><tr><th>Book</th><th>Qty</th><th>Price</th><th>Total</th></tr></thead><tbody>';
    (Array.isArray(items)?items:[]).forEach(function(i){
        html+='<tr><td>'+esc(i.title||'')+'</td><td>'+i.qty+'</td><td>'+fmt(i.price)+'</td><td>'+fmt(i.price*i.qty)+'</td></tr>';
    });
    html+='</tbody></table></div>';
    $('#bs-oo-modal-body').html(html);
    openModal('#bs-online-order-modal');
});

// ── Settings page extras ───────────────────────────────────────────────────────
$(document).on('click','#bs-send-eod-now',function(){
    var btn=$(this).prop('disabled',true).text('Sending…');
    post({action:'bs_send_eod_now'}).then(function(res){
        btn.prop('disabled',false).text('📧 Send EOD Report Now');
        alert(res.success?res.data.message:'Failed: '+(res.data||'Unknown error'));
    });
});

// SMS test send. Surfaces the provider's verbatim error so admins can debug
// "Send failed" without having to dig into the messages_queue table — most
// SMS providers reject for clear reasons (invalid sender ID, insufficient
// credit, unrecognised destination) that the message text spells out.
$(document).on('click','#bs-sms-test',function(){
    var phone = $('#bs-sms-test-to').val().trim();
    if(!phone){ alert('Enter a recipient phone number first.'); return; }
    var $btn = $(this).prop('disabled',true).text('Sending…');
    var $out = $('#bs-sms-test-result').html('<span style="color:var(--muted)">Sending…</span>');
    post({action:'bs_sms_test', phone:phone}).done(function(res){
        $btn.prop('disabled',false).text('📤 Send Test SMS');
        if(res && res.success){
            $out.html('<span style="color:var(--green,#2a7a3b)">✓ '+esc(res.data.message)+'</span>');
        } else {
            $out.html('<span style="color:var(--red,#c0392b)">✗ '+esc((res&&res.data)?res.data:'Unknown error')+'</span>');
        }
    }).fail(function(xhr){
        $btn.prop('disabled',false).text('📤 Send Test SMS');
        $out.html('<span style="color:var(--red,#c0392b)">✗ HTTP '+xhr.status+' — try saving settings first.</span>');
    });
});
$(document).on('click','#bs-run-expiry',function(){
    if(!confirm('Run loyalty points expiry now? This will expire points for inactive customers.')) return;
    var btn=$(this).prop('disabled',true).text('Running…');
    post({action:'bs_run_loyalty_expiry'}).then(function(res){
        btn.prop('disabled',false).text('⏰ Run Points Expiry Now');
        alert(res.success?res.data.message:'Error: '+(res.data||'Unknown'));
    });
});
$(document).on('click','#bs-sync-sheets-now',function(){
    var btn=$(this).prop('disabled',true).text('Syncing…');
    post({action:'bs_sync_sheets',date:new Date().toISOString().split('T')[0]}).then(function(res){
        btn.prop('disabled',false).text('🔄 Sync Today to Sheets');
        alert(res.success?'Synced '+res.data.rows+' rows to Google Sheets':'Error: '+(res.data||'Unknown'));
    });
});
$(document).on('click','#bs-send-drift-digest-now',function(){
    if(!confirm('Send the drift digest now to the configured recipient?')) return;
    var btn=$(this).prop('disabled',true).text('Sending…');
    post({action:'bs_send_drift_digest_now'}).then(function(res){
        btn.prop('disabled',false).text('📧 Send Drift Digest Now');
        alert(res.success?res.data.message:'Error: '+(res.data||'Unknown'));
    });
});

// ── Refunds (on sales page) ────────────────────────────────────────────────────
$(document).on('click','.bs-refund-sale',function(){
    var saleId=$(this).data('id');
    var ref=$(this).data('ref');
    // Get sale items first
    get({action:'bs_get_sale_items',id:saleId}).then(function(res){
        if(!res.success) return alert('Could not load sale items');
        var items=res.data;
        var html='<p style="margin-bottom:12px;color:var(--muted);font-size:.85rem">Select items and quantities to refund:</p>';
        html+='<table class="bs-table bs-table-sm"><thead><tr><th>Book</th><th>Sold</th><th>Refund Qty</th></tr></thead><tbody>';
        items.forEach(function(i){
            html+='<tr><td>'+esc(i.title)+'</td><td>'+i.qty+'</td><td><input type="number" class="bs-input refund-qty" data-book-id="'+i.book_id+'" min="0" max="'+i.qty+'" value="0" style="width:70px"></td></tr>';
        });
        html+='</tbody></table>';
        html+='<div style="margin-top:12px"><label style="font-size:.8rem;font-weight:600">Reason *</label><input type="text" id="refund-reason" class="bs-input" placeholder="Customer return, defective, etc." style="margin-top:4px"></div>';
        html+='<label style="margin-top:10px;display:flex;align-items:center;gap:8px;font-size:.85rem;cursor:pointer"><input type="checkbox" id="refund-restock" checked> Restock returned books</label>';

        // Show in modal
        $('#bs-sale-items-modal .bs-modal-header h2').text('Refund — '+ref);
        $('#bs-sale-items-body').html(html);
        // Swap footer button
        $('#bs-sale-items-modal .bs-modal-footer').html(
            '<button class="bs-btn bs-btn-secondary bs-modal-close">Cancel</button>'+
            '<button class="bs-btn bs-btn-primary" id="bs-confirm-refund" data-sale-id="'+saleId+'">Process Refund</button>'
        );
        openModal('#bs-sale-items-modal');
    });
});
$(document).on('click','#bs-confirm-refund',function(){
    var saleId=$(this).data('sale-id');
    var reason=$('#refund-reason').val().trim();
    if(!reason){alert('Please provide a reason for the refund');return;}
    var items={};
    $('.refund-qty').each(function(){
        var qty=parseInt($(this).val())||0;
        if(qty>0) items[$(this).data('book-id')]=qty;
    });
    if(!Object.keys(items).length){alert('Select at least one item to refund');return;}
    var restock=$('#refund-restock').is(':checked')?1:0;
    var btn=$(this).prop('disabled',true).text('Processing…');
    post({action:'bs_create_refund',sale_id:saleId,items:JSON.stringify(items),reason:reason,restock:restock}).then(function(res){
        btn.prop('disabled',false).text('Process Refund');
        if(res.success){
            alert('Refund '+res.data.ref+' processed — '+currency+parseFloat(res.data.amount).toFixed(2));
            closeModals();location.reload();
        } else {
            alert('Refund failed: '+(res.data||'Unknown error'));
        }
    });
});

// ── Backup Restore ─────────────────────────────────────────────────────────────
$(document).on('click', '#bs-restore-btn', function() {
    const file = $('#bs-restore-file')[0].files[0];
    if (!file) { alert('Please select a .sql backup file first.'); return; }
    if (!confirm('⚠️ WARNING: This will overwrite your existing bookshop data with the backup.\n\nAre you sure you want to continue?')) return;

    const fd  = new FormData();
    fd.append('action',      'bs_restore_backup');
    fd.append('nonce',       nonce);
    fd.append('backup_file', file);

    const btn = $(this).prop('disabled', true).text('Restoring…');
    const res = $('#bs-restore-result').show().html('<span style="color:var(--muted)">⏳ Uploading and restoring, please wait…</span>');

    $.ajax({
        url:         ajax_url,
        type:        'POST',
        data:        fd,
        processData: false,
        contentType: false,
        timeout:     120000, // 2 min for large files
    }).done(function(r) {
        btn.prop('disabled', false).text('🔄 Restore Backup');
        if (r && r.success) {
            res.html('<span style="color:var(--green)">✅ ' + esc(r.data.message) + '</span>');
            if (r.data.error_details && r.data.error_details.length) {
                res.append('<details style="margin-top:8px"><summary style="cursor:pointer;font-size:.78rem">Show errors</summary><pre style="font-size:.72rem;background:#f5f5f5;padding:8px;border-radius:6px;overflow:auto">' + r.data.error_details.map(esc).join('\n') + '</pre></details>');
            }
        } else {
            res.html('<span style="color:var(--red)">❌ Error: ' + esc((r && r.data) ? r.data : 'Unknown error') + '</span>');
        }
    }).fail(function(xhr) {
        btn.prop('disabled', false).text('🔄 Restore Backup');
        res.html('<span style="color:var(--red)">❌ Upload failed (' + xhr.status + '). Check file size and try again.</span>');
    });
});

// ── Stock Take ────────────────────────────────────────────────────────────────
$(document).on('click','#bs-new-stocktake',function(){
    var branches=$('.bs-view-branch-stock').map(function(){
        return '<option value="'+$(this).data('id')+'">'+esc($(this).data('name'))+'</option>';
    }).get().join('');
    if(!branches){
        alert('No branches found. Please add a branch first.');
        return;
    }
    var html='<div class="bs-form-group" style="margin-bottom:14px">'
        +'<label>Select Branch</label>'
        +'<select id="st-branch-id" class="bs-input"><option value="">-- Select Branch --</option>'+branches+'</select>'
        +'</div>'
        +'<p style="font-size:.83rem;color:var(--muted)">After creating the stock take, use "Enter Counts" to record your physical count for each book.</p>';
    // Build and show modal
    $('#bs-branch-stock-modal .bs-modal-header h2').text('New Stock Take');
    $('#bs-branch-stock-body').html(html);
    $('#bs-branch-stock-modal .bs-modal-footer').remove();
    var footer=$('<div class="bs-modal-footer">'
        +'<button class="bs-btn bs-btn-secondary bs-modal-close">Cancel</button>'
        +'<button class="bs-btn bs-btn-primary" id="bs-create-stocktake-btn">Create Stock Take</button>'
        +'</div>');
    $('#bs-branch-stock-modal .bs-modal-box').append(footer);
    openModal('#bs-branch-stock-modal');
});

$(document).on('click','#bs-create-stocktake-btn',function(){
    var bid=$('#st-branch-id').val();
    if(!bid){alert('Please select a branch');return;}
    var btn=$(this).prop('disabled',true).text('Creating...');
    post({action:'bs_create_stocktake',branch_id:bid}).then(function(res){
        btn.prop('disabled',false).text('Create Stock Take');
        if(res.success){
            alert('Stock take created! Use "Enter Counts" to record your physical count.');
            closeModals();
            location.reload();
        } else {
            alert('Error: '+(res.data||'Unknown error'));
        }
    });
});

// Enter counts for an in-progress stock take
$(document).on('click','.bs-do-stocktake',function(){
    var takeId=$(this).data('id');
    // Load branch stock to allow entering counts
    $('#bs-branch-stock-modal .bs-modal-header h2').text('Enter Stock Counts');
    $('#bs-branch-stock-body').html('<p style="color:var(--muted);padding:20px">Loading inventory...</p>');
    openModal('#bs-branch-stock-modal');
    // Get all books to count
    get({action:'bs_get_all_books_for_count'}).then(function(res){
        if(!res.success){$('#bs-branch-stock-body').html('<em>Error loading books</em>');return;}
        var cur=currency;
        var html='<div style="max-height:420px;overflow-y:auto">'
            +'<table class="bs-table bs-table-sm">'
            +'<thead><tr><th>Title</th><th>Author</th><th>Expected</th><th>Counted Qty</th></tr></thead><tbody>';
        (res.data||[]).forEach(function(b){
            html+='<tr>'
                +'<td>'+esc(b.title)+'</td>'
                +'<td style="color:var(--muted)">'+esc(b.author)+'</td>'
                +'<td>'+parseInt(b.stock_qty)+'</td>'
                +'<td><input type="number" class="bs-input st-count-input" data-book-id="'+b.id+'"'
                +' value="'+parseInt(b.stock_qty)+'" min="0" style="width:80px;padding:4px 6px"></td>'
                +'</tr>';
        });
        html+='</tbody></table></div>';
        $('#bs-branch-stock-body').html(html);
        // Add submit footer
        $('#bs-branch-stock-modal .bs-modal-footer').remove();
        var footer=$('<div class="bs-modal-footer">'
            +'<button class="bs-btn bs-btn-secondary bs-modal-close">Cancel</button>'
            +'<button class="bs-btn bs-btn-primary" id="bs-submit-stocktake" data-take-id="'+takeId+'">Submit Counts</button>'
            +'</div>');
        $('#bs-branch-stock-modal .bs-modal-box').append(footer);
    });
});

$(document).on('click','#bs-submit-stocktake',function(){
    var takeId=$(this).data('take-id');
    var counts={};
    $('.st-count-input').each(function(){
        counts[$(this).data('book-id')]=$(this).val();
    });
    var btn=$(this).prop('disabled',true).text('Submitting...');
    post({action:'bs_submit_stocktake',take_id:takeId,counts:JSON.stringify(counts)}).then(function(res){
        btn.prop('disabled',false).text('Submit Counts');
        if(res.success){
            var variances=res.data||[];
            var msg='Stock take complete!';
            if(variances.length) msg+=' '+variances.length+' variance(s) found and adjusted.';
            alert(msg);
            closeModals();
            location.reload();
        } else {
            alert('Error: '+(res.data||'Unknown error'));
        }
    });
});


// ── Stock Transfer ────────────────────────────────────────────────────────────
$(document).on('click','.bs-transfer-stock-btn',function(){
    var fromId=$(this).data('id');
    var fromName=$(this).data('name');
    // Build branch + book pickers
    var toOptions=$('.bs-view-branch-stock').map(function(){
        var bid=$(this).data('id'),bname=$(this).data('name');
        if(parseInt(bid)===parseInt(fromId)) return '';
        return '<option value="'+bid+'">'+esc(bname)+'</option>';
    }).get().join('');
    if(!toOptions){
        alert('You need at least 2 active branches to transfer stock.');
        return;
    }
    var html='<p style="margin-bottom:12px;color:var(--muted);font-size:.85rem">Transferring from <strong>'+esc(fromName)+'</strong></p>'
        +'<div class="bs-form-group" style="margin-bottom:12px">'
        +'  <label>Destination Branch</label>'
        +'  <select id="xfer-to-branch" class="bs-input">'+toOptions+'</select>'
        +'</div>'
        +'<div class="bs-form-group" style="margin-bottom:12px">'
        +'  <label>Book (search by title or ISBN)</label>'
        +'  <input type="text" id="xfer-book-search" class="bs-input" placeholder="Type to search..." autocomplete="off">'
        +'  <div id="xfer-book-results" style="border:1px solid var(--border);border-radius:6px;margin-top:4px;max-height:160px;overflow-y:auto;display:none"></div>'
        +'  <input type="hidden" id="xfer-book-id">'
        +'  <div id="xfer-book-selected" style="margin-top:6px;font-size:.82rem"></div>'
        +'</div>'
        +'<div class="bs-form-group" style="margin-bottom:12px">'
        +'  <label>Quantity</label>'
        +'  <input type="number" id="xfer-qty" class="bs-input" min="1" value="1">'
        +'</div>';
    $('#bs-branch-stock-modal .bs-modal-header h2').text('Transfer Stock — '+fromName);
    $('#bs-branch-stock-body').html(html);
    $('#bs-branch-stock-modal .bs-modal-footer').remove();
    $('#bs-branch-stock-modal .bs-modal-box').append(
        '<div class="bs-modal-footer">'
        +'<button class="bs-btn bs-btn-secondary bs-modal-close">Cancel</button>'
        +'<button class="bs-btn bs-btn-primary" id="bs-confirm-transfer" data-from="'+fromId+'">Transfer</button>'
        +'</div>'
    );
    openModal('#bs-branch-stock-modal');
});

// Book search inside the transfer modal
var xferSearchTimer=null;
$(document).on('input','#xfer-book-search',function(){
    var q=$(this).val().trim();
    clearTimeout(xferSearchTimer);
    if(q.length<2){$('#xfer-book-results').hide().empty();return;}
    xferSearchTimer=setTimeout(function(){
        get({action:'bs_search_books',q:q}).then(function(res){
            if(!res.success){$('#xfer-book-results').hide();return;}
            var html=(res.data||[]).slice(0,8).map(function(b){
                return '<div class="xfer-book-pick" data-id="'+b.id+'" data-title="'+esc(b.title)+'" '
                    +'style="padding:8px 10px;cursor:pointer;border-bottom:1px solid #f0e8d8">'
                    +'<strong>'+esc(b.title)+'</strong>'
                    +'<div style="font-size:.75rem;color:var(--muted)">'+esc(b.author||'')+(b.isbn?' &middot; '+esc(b.isbn):'')+' &middot; Stock: '+parseInt(b.stock_qty)+'</div>'
                    +'</div>';
            }).join('');
            if(!html) html='<div style="padding:8px;color:var(--muted)">No matches</div>';
            $('#xfer-book-results').html(html).show();
        });
    },220);
});
$(document).on('click','.xfer-book-pick',function(){
    $('#xfer-book-id').val($(this).data('id'));
    $('#xfer-book-selected').html('Selected: <strong>'+esc($(this).data('title'))+'</strong>');
    $('#xfer-book-results').hide();
    $('#xfer-book-search').val($(this).data('title'));
});

$(document).on('click','#bs-confirm-transfer',function(){
    var from=$(this).data('from');
    var to=$('#xfer-to-branch').val();
    var bookId=$('#xfer-book-id').val();
    var qty=parseInt($('#xfer-qty').val())||0;
    if(!to){alert('Choose a destination branch');return;}
    if(!bookId){alert('Select a book to transfer');return;}
    if(qty<1){alert('Quantity must be at least 1');return;}
    var btn=$(this).prop('disabled',true).text('Transferring…');
    post({action:'bs_transfer_stock',from:from,to:to,book_id:bookId,qty:qty}).then(function(res){
        btn.prop('disabled',false).text('Transfer');
        if(res.success){
            alert('Transfer complete.');
            closeModals();
            location.reload();
        } else {
            alert('Transfer failed: '+(res.data||'Unknown error'));
        }
    });
});

// ── Staff: Home branch assignment ─────────────────────────────────────────────
$(document).on('change','.bs-staff-branch',function(){
    var $sel    = $(this);
    var uid     = $sel.data('uid');
    var bid     = $sel.val();
    var $status = $('.bs-staff-branch-status[data-uid="'+uid+'"]');
    var prev    = $sel.data('prev-value');
    if(typeof prev==='undefined') prev=$sel.find('option[selected]').val()||'0';
    $sel.prop('disabled',true);
    $status.text('Saving…').css('color','var(--muted)');
    post({action:'bs_admin_set_user_branch',user_id:uid,branch_id:bid}).then(function(res){
        $sel.prop('disabled',false);
        if(res && res.success){
            $sel.data('prev-value',bid);
            $status.text('Saved').css('color','var(--green,#2a7a3b)');
            setTimeout(function(){ $status.text(''); },1800);
        } else {
            $sel.val(prev);
            $status.text((res && res.data)?res.data:'Save failed').css('color','var(--red,#c0392b)');
        }
    }).fail(function(){
        $sel.prop('disabled',false).val(prev);
        $status.text('Network error').css('color','var(--red,#c0392b)');
    });
});
});
