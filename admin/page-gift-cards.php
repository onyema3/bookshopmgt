<?php
if(!defined('ABSPATH'))exit;

function bs_page_gift_cards(){
    $cur = bs_currency();
    $summary = bs_gc_summary();

    $status_filter = sanitize_text_field($_GET['status'] ?? '');
    $search        = sanitize_text_field($_GET['search'] ?? '');
    $page_num      = max(1, intval($_GET['paged'] ?? 1));
    $per_page      = 30;
    $offset        = ($page_num - 1) * $per_page;

    $result = bs_get_gift_cards([
        'status' => $status_filter,
        'search' => $search,
        'limit'  => $per_page,
        'offset' => $offset,
    ]);
    $cards = $result['rows'];
    $total = $result['total'];
    $pages = ceil($total / $per_page);
    ?>
    <div class="wrap bs-wrap">
    <div class="bs-header">
        <h1>🎁 Gift Cards</h1>
        <div style="display:flex;gap:6px">
            <button class="bs-btn bs-btn-primary" onclick="document.getElementById('gc-create-modal').style.display='flex'">+ Issue Gift Card</button>
            <button class="bs-btn bs-btn-secondary" onclick="document.getElementById('gc-settings-modal').style.display='flex'">⚙️ Settings</button>
        </div>
    </div>

    <!-- Summary stats -->
    <div class="bs-stats-row">
        <?php bs_stat($summary->total_cards, 'Total Issued'); ?>
        <?php bs_stat($summary->active_cards, 'Active Cards', true); ?>
        <?php bs_stat(bs_fmt($summary->outstanding_balance), 'Outstanding Balance'); ?>
        <?php bs_stat(bs_fmt($summary->total_redeemed), 'Total Redeemed'); ?>
        <?php bs_stat($summary->used_cards, 'Fully Used'); ?>
        <?php bs_stat($summary->expired_cards, 'Expired'); ?>
    </div>

    <!-- Filters -->
    <form method="get" class="bs-toolbar" style="margin:14px 0;gap:6px;flex-wrap:wrap">
        <input type="hidden" name="page" value="bookshop-gift-cards">
        <input type="text" name="search" value="<?=esc_attr($search)?>" placeholder="Search code, name, or email..." class="bs-input" style="width:220px">
        <select name="status" class="bs-input" style="min-width:130px">
            <option value="">All Statuses</option>
            <option value="active" <?=selected($status_filter,'active',false)?>>Active</option>
            <option value="used" <?=selected($status_filter,'used',false)?>>Used</option>
            <option value="expired" <?=selected($status_filter,'expired',false)?>>Expired</option>
            <option value="cancelled" <?=selected($status_filter,'cancelled',false)?>>Cancelled</option>
        </select>
        <button type="submit" class="bs-btn bs-btn-primary">Filter</button>
        <a href="?page=bookshop-gift-cards" class="bs-btn bs-btn-secondary">Reset</a>
    </form>

    <!-- Table -->
    <div style="overflow-x:auto">
    <table class="bs-table">
        <thead>
            <tr>
                <th>Code</th>
                <th>Value</th>
                <th>Balance</th>
                <th>Purchaser</th>
                <th>Recipient</th>
                <th>Status</th>
                <th>Expires</th>
                <th>Created</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if(empty($cards)): ?>
            <tr><td colspan="9" style="text-align:center;padding:30px;color:var(--muted)">No gift cards found.</td></tr>
        <?php else: foreach($cards as $gc):
            $status_colors = ['active'=>'#27ae60','used'=>'#8a7a65','expired'=>'#e67e22','cancelled'=>'#c0392b'];
            $sc = $status_colors[$gc->status] ?? '#999';
        ?>
            <tr>
                <td><code style="font-weight:700;font-size:.82rem;letter-spacing:.5px"><?=esc_html($gc->code)?></code></td>
                <td><?=bs_fmt($gc->initial_value)?></td>
                <td style="font-weight:700;<?=floatval($gc->balance)>0?'color:#27ae60':''?>"><?=bs_fmt($gc->balance)?></td>
                <td>
                    <span style="font-size:.82rem"><?=esc_html($gc->purchaser_name)?></span>
                    <?php if($gc->purchaser_email): ?>
                    <div style="font-size:.7rem;color:var(--muted)"><?=esc_html($gc->purchaser_email)?></div>
                    <?php endif; ?>
                </td>
                <td>
                    <span style="font-size:.82rem"><?=esc_html($gc->recipient_name)?></span>
                    <?php if($gc->recipient_email): ?>
                    <div style="font-size:.7rem;color:var(--muted)"><?=esc_html($gc->recipient_email)?></div>
                    <?php endif; ?>
                </td>
                <td>
                    <span style="display:inline-block;padding:2px 8px;border-radius:12px;font-size:.72rem;font-weight:600;color:#fff;background:<?=$sc?>">
                        <?=ucfirst($gc->status)?>
                    </span>
                </td>
                <td style="font-size:.8rem"><?=$gc->expires_at ? esc_html($gc->expires_at) : '&mdash;'?></td>
                <td style="font-size:.8rem"><?=date('M j, Y', strtotime($gc->created_at))?></td>
                <td>
                    <button class="bs-btn bs-btn-secondary" style="font-size:.72rem;padding:3px 8px" onclick="viewGcHistory(<?=$gc->id?>,'<?=esc_js($gc->code)?>')">History</button>
                    <?php if($gc->status === 'active'): ?>
                    <button class="bs-btn bs-btn-secondary" style="font-size:.72rem;padding:3px 8px" onclick="adjustGc(<?=$gc->id?>,'<?=esc_js($gc->code)?>',<?=floatval($gc->balance)?>)">Adjust</button>
                    <button class="bs-btn bs-btn-secondary" style="font-size:.72rem;padding:3px 8px;color:#c0392b" onclick="cancelGc(<?=$gc->id?>,'<?=esc_js($gc->code)?>')">Cancel</button>
                    <?php endif; ?>
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

    <!-- Create Gift Card Modal -->
    <?php
    $create_body = '
    <div style="display:grid;gap:12px">
        <div>
            <label style="font-size:.83rem;font-weight:600;display:block;margin-bottom:4px">Value (' . $cur . ') *</label>
            <input type="number" id="gc-new-value" min="' . intval(get_option('bookshop_gc_min_value',500)) . '" max="' . intval(get_option('bookshop_gc_max_value',100000)) . '" step="100" class="bs-input" style="width:100%" required>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
            <div>
                <label style="font-size:.83rem;font-weight:600;display:block;margin-bottom:4px">Purchaser Name</label>
                <input type="text" id="gc-new-pname" class="bs-input" style="width:100%">
            </div>
            <div>
                <label style="font-size:.83rem;font-weight:600;display:block;margin-bottom:4px">Purchaser Email</label>
                <input type="email" id="gc-new-pemail" class="bs-input" style="width:100%">
            </div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
            <div>
                <label style="font-size:.83rem;font-weight:600;display:block;margin-bottom:4px">Recipient Name</label>
                <input type="text" id="gc-new-rname" class="bs-input" style="width:100%">
            </div>
            <div>
                <label style="font-size:.83rem;font-weight:600;display:block;margin-bottom:4px">Recipient Email</label>
                <input type="email" id="gc-new-remail" class="bs-input" style="width:100%">
            </div>
        </div>
        <div>
            <label style="font-size:.83rem;font-weight:600;display:block;margin-bottom:4px">Personal Message (optional)</label>
            <textarea id="gc-new-message" class="bs-input" rows="2" style="width:100%;resize:vertical"></textarea>
        </div>
    </div>';
    $create_footer = '<button class="bs-btn bs-btn-secondary" onclick="document.getElementById(\'gc-create-modal\').style.display=\'none\'">Cancel</button>
        <button class="bs-btn bs-btn-primary" id="btn-create-gc">Issue Gift Card</button>';
    bs_modal('gc-create-modal', '🎁 Issue New Gift Card', $create_body, $create_footer);
    ?>

    <!-- History Modal -->
    <?php
    $hist_body = '<div id="gc-history-content" style="min-height:100px"><p style="color:var(--muted);text-align:center">Loading...</p></div>';
    bs_modal('gc-history-modal', '📋 Transaction History', $hist_body, '', 'lg');
    ?>

    <!-- Adjust Modal -->
    <?php
    $adjust_body = '
    <div style="display:grid;gap:12px">
        <input type="hidden" id="gc-adjust-id">
        <p style="font-size:.85rem">Card: <strong id="gc-adjust-code"></strong> — Current balance: <strong id="gc-adjust-bal"></strong></p>
        <div>
            <label style="font-size:.83rem;font-weight:600;display:block;margin-bottom:4px">Adjustment Amount (' . $cur . ')</label>
            <input type="number" id="gc-adjust-amount" step="0.01" class="bs-input" style="width:100%" placeholder="Positive to add, negative to deduct">
        </div>
        <div>
            <label style="font-size:.83rem;font-weight:600;display:block;margin-bottom:4px">Reason</label>
            <input type="text" id="gc-adjust-note" class="bs-input" style="width:100%" placeholder="Reason for adjustment">
        </div>
    </div>';
    $adjust_footer = '<button class="bs-btn bs-btn-secondary" onclick="document.getElementById(\'gc-adjust-modal\').style.display=\'none\'">Cancel</button>
        <button class="bs-btn bs-btn-primary" id="btn-adjust-gc">Apply Adjustment</button>';
    bs_modal('gc-adjust-modal', '🔧 Adjust Balance', $adjust_body, $adjust_footer);
    ?>

    <!-- Settings Modal -->
    <?php
    $gc_prefix = get_option('bookshop_gc_prefix', 'GC');
    $gc_code_len = get_option('bookshop_gc_code_length', 12);
    $gc_expiry = get_option('bookshop_gc_expiry_months', 12);
    $gc_min = get_option('bookshop_gc_min_value', 500);
    $gc_max = get_option('bookshop_gc_max_value', 100000);
    $gc_denoms = get_option('bookshop_gc_denominations', '1000,2000,5000,10000,20000,50000');
    $gc_online = get_option('bookshop_gc_online_enabled', '1');

    $settings_body = '
    <div style="display:grid;gap:12px">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
            <div>
                <label style="font-size:.83rem;font-weight:600;display:block;margin-bottom:4px">Code Prefix</label>
                <input type="text" id="gcs-prefix" value="' . esc_attr($gc_prefix) . '" class="bs-input" style="width:100%">
            </div>
            <div>
                <label style="font-size:.83rem;font-weight:600;display:block;margin-bottom:4px">Code Length (chars after prefix)</label>
                <input type="number" id="gcs-code-len" value="' . intval($gc_code_len) . '" min="8" max="20" class="bs-input" style="width:100%">
            </div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px">
            <div>
                <label style="font-size:.83rem;font-weight:600;display:block;margin-bottom:4px">Expiry (months, 0=never)</label>
                <input type="number" id="gcs-expiry" value="' . intval($gc_expiry) . '" min="0" class="bs-input" style="width:100%">
            </div>
            <div>
                <label style="font-size:.83rem;font-weight:600;display:block;margin-bottom:4px">Min Value (' . $cur . ')</label>
                <input type="number" id="gcs-min" value="' . intval($gc_min) . '" min="0" class="bs-input" style="width:100%">
            </div>
            <div>
                <label style="font-size:.83rem;font-weight:600;display:block;margin-bottom:4px">Max Value (' . $cur . ')</label>
                <input type="number" id="gcs-max" value="' . intval($gc_max) . '" min="0" class="bs-input" style="width:100%">
            </div>
        </div>
        <div>
            <label style="font-size:.83rem;font-weight:600;display:block;margin-bottom:4px">Denominations (comma-separated, for online purchase page)</label>
            <input type="text" id="gcs-denoms" value="' . esc_attr($gc_denoms) . '" class="bs-input" style="width:100%">
        </div>
        <div>
            <label style="font-size:.83rem;font-weight:600;display:block;margin-bottom:4px">
                <input type="checkbox" id="gcs-online" ' . checked($gc_online,'1',false) . '> Enable online gift card purchases
            </label>
        </div>
    </div>';
    $settings_footer = '<button class="bs-btn bs-btn-secondary" onclick="document.getElementById(\'gc-settings-modal\').style.display=\'none\'">Cancel</button>
        <button class="bs-btn bs-btn-primary" id="btn-save-gc-settings">Save Settings</button>';
    bs_modal('gc-settings-modal', '⚙️ Gift Card Settings', $settings_body, $settings_footer);
    ?>

    <script>
    jQuery(function($){
        // Create gift card
        $('#btn-create-gc').on('click', function(){
            var btn=$(this); btn.prop('disabled',true).text('Creating...');
            $.post(BSAdmin.ajax_url, {
                action:'bs_admin_create_gift_card',
                nonce:BSAdmin.nonce,
                value:$('#gc-new-value').val(),
                purchaser_name:$('#gc-new-pname').val(),
                purchaser_email:$('#gc-new-pemail').val(),
                recipient_name:$('#gc-new-rname').val(),
                recipient_email:$('#gc-new-remail').val(),
                message:$('#gc-new-message').val()
            }, function(res){
                btn.prop('disabled',false).text('Issue Gift Card');
                if(res.success){
                    alert('Gift card created!\nCode: '+res.data.code+'\nValue: <?=$cur?>'+parseFloat(res.data.value).toLocaleString());
                    location.reload();
                } else {
                    alert(res.data||'Error creating gift card');
                }
            });
        });

        // View history
        window.viewGcHistory = function(id, code){
            $('#gc-history-modal .bs-modal-header h2').text('📋 History: '+code);
            $('#gc-history-content').html('<p style="color:var(--muted);text-align:center">Loading...</p>');
            $('#gc-history-modal').show();
            $.get(BSAdmin.ajax_url, {action:'bs_get_gc_history', id:id, nonce:BSAdmin.nonce}, function(res){
                if(!res.success){ $('#gc-history-content').html('<p style="color:red">Error loading history</p>'); return; }
                var txns = res.data;
                if(!txns.length){ $('#gc-history-content').html('<p style="color:var(--muted);text-align:center">No transactions yet</p>'); return; }
                var html='<table class="bs-table" style="font-size:.82rem"><thead><tr><th>Date</th><th>Type</th><th>Amount</th><th>Balance After</th><th>Staff</th><th>Note</th></tr></thead><tbody>';
                txns.forEach(function(t){
                    var amtColor = parseFloat(t.amount)>=0?'#27ae60':'#c0392b';
                    var amtSign = parseFloat(t.amount)>=0?'+':'';
                    html+='<tr><td>'+t.created_at+'</td><td>'+t.type+'</td><td style="color:'+amtColor+';font-weight:600">'+amtSign+'<?=$cur?>'+Math.abs(parseFloat(t.amount)).toLocaleString(undefined,{minimumFractionDigits:2})+'</td><td><?=$cur?>'+parseFloat(t.balance_after).toLocaleString(undefined,{minimumFractionDigits:2})+'</td><td>'+(t.staff_name||'System')+'</td><td>'+(t.note||'&mdash;')+'</td></tr>';
                });
                html+='</tbody></table>';
                $('#gc-history-content').html(html);
            });
        };

        // Adjust balance
        window.adjustGc = function(id, code, balance){
            $('#gc-adjust-id').val(id);
            $('#gc-adjust-code').text(code);
            $('#gc-adjust-bal').text('<?=$cur?>'+balance.toLocaleString(undefined,{minimumFractionDigits:2}));
            $('#gc-adjust-amount').val('');
            $('#gc-adjust-note').val('');
            $('#gc-adjust-modal').show();
        };
        $('#btn-adjust-gc').on('click', function(){
            var btn=$(this); btn.prop('disabled',true).text('Applying...');
            $.post(BSAdmin.ajax_url, {
                action:'bs_admin_adjust_gc',
                nonce:BSAdmin.nonce,
                id:$('#gc-adjust-id').val(),
                amount:$('#gc-adjust-amount').val(),
                note:$('#gc-adjust-note').val()
            }, function(res){
                btn.prop('disabled',false).text('Apply Adjustment');
                if(res.success){ alert('Adjusted! New balance: <?=$cur?>'+parseFloat(res.data.new_balance).toLocaleString(undefined,{minimumFractionDigits:2})); location.reload(); }
                else alert(res.data||'Error');
            });
        });

        // Cancel card
        window.cancelGc = function(id, code){
            if(!confirm('Cancel gift card '+code+'?\nThis will zero its balance and cannot be undone.')) return;
            var reason = prompt('Reason for cancellation (optional):','');
            $.post(BSAdmin.ajax_url, {
                action:'bs_admin_cancel_gc',
                nonce:BSAdmin.nonce,
                id:id,
                reason:reason||''
            }, function(res){
                if(res.success){ alert('Gift card cancelled.'); location.reload(); }
                else alert(res.data||'Error');
            });
        };

        // Save settings
        $('#btn-save-gc-settings').on('click', function(){
            var btn=$(this); btn.prop('disabled',true).text('Saving...');
            $.post(BSAdmin.ajax_url, {
                action:'bs_save_gc_settings',
                nonce:BSAdmin.nonce,
                prefix:$('#gcs-prefix').val(),
                code_length:$('#gcs-code-len').val(),
                expiry_months:$('#gcs-expiry').val(),
                min_value:$('#gcs-min').val(),
                max_value:$('#gcs-max').val(),
                denominations:$('#gcs-denoms').val(),
                online_enabled:$('#gcs-online').is(':checked')?'1':'0'
            }, function(res){
                btn.prop('disabled',false).text('Save Settings');
                if(res.success){ alert('Settings saved.'); location.reload(); }
                else alert(res.data||'Error saving settings');
            });
        });
    });
    </script>
    <?php
}
