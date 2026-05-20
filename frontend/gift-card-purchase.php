<?php
if(!defined('ABSPATH'))exit;

/**
 * Frontend Gift Card Purchase Flow
 *
 * Shortcode: [bookshop_gift_card]
 * Renders a public-facing page where customers can buy gift cards online
 * and check their gift card balance.
 */

add_shortcode('bookshop_gift_card', 'bs_render_gift_card_page');

function bs_render_gift_card_page($atts = []){
    if(get_option('bookshop_gc_online_enabled', '1') !== '1'){
        return '<p style="text-align:center;padding:40px;color:#888">Gift card purchasing is currently unavailable.</p>';
    }

    $cur = bs_currency();
    $min = floatval(get_option('bookshop_gc_min_value', 500));
    $max = floatval(get_option('bookshop_gc_max_value', 100000));
    $denoms = array_filter(array_map('trim', explode(',', get_option('bookshop_gc_denominations', '1000,2000,5000,10000,20000,50000'))));
    $shop_name = get_option('bookshop_receipt_header', get_bloginfo('name'));
    $nonce = wp_create_nonce('bs_gc_purchase_nonce');
    $ajax_url = admin_url('admin-ajax.php');

    ob_start();
    ?>
    <div id="bs-gc-app" class="bs-gc-wrapper">
        <!-- Tab navigation -->
        <div class="bs-gc-tabs">
            <button class="bs-gc-tab active" data-tab="buy">🎁 Buy a Gift Card</button>
            <button class="bs-gc-tab" data-tab="balance">🔍 Check Balance</button>
        </div>

        <!-- Buy Tab -->
        <div class="bs-gc-panel" id="gc-panel-buy">
            <div class="bs-gc-card-preview">
                <div class="bs-gc-card">
                    <div class="bs-gc-card-top">
                        <span class="bs-gc-card-logo">📚</span>
                        <span class="bs-gc-card-shop"><?=esc_html($shop_name)?></span>
                    </div>
                    <div class="bs-gc-card-value" id="gc-preview-value"><?=$cur?>0</div>
                    <div class="bs-gc-card-label">GIFT CARD</div>
                    <div class="bs-gc-card-to" id="gc-preview-to">For someone special</div>
                </div>
            </div>

            <form id="gc-purchase-form" class="bs-gc-form">
                <input type="hidden" name="nonce" value="<?=$nonce?>">

                <!-- Amount selection -->
                <div class="bs-gc-section">
                    <label class="bs-gc-label">Select Amount</label>
                    <div class="bs-gc-denoms">
                        <?php foreach($denoms as $d): $d = intval($d); ?>
                        <button type="button" class="bs-gc-denom" data-value="<?=$d?>"><?=$cur?><?=number_format($d)?></button>
                        <?php endforeach; ?>
                    </div>
                    <div class="bs-gc-custom-row">
                        <label class="bs-gc-label-sm">Or enter custom amount (<?=$cur?><?=number_format($min)?> – <?=$cur?><?=number_format($max)?>)</label>
                        <input type="number" id="gc-custom-amount" name="amount" min="<?=$min?>" max="<?=$max?>" step="100" placeholder="<?=$cur?> Amount" class="bs-gc-input">
                    </div>
                </div>

                <!-- Recipient details -->
                <div class="bs-gc-section">
                    <label class="bs-gc-label">Recipient Details</label>
                    <input type="text" name="recipient_name" placeholder="Recipient's Name *" class="bs-gc-input" required>
                    <input type="email" name="recipient_email" placeholder="Recipient's Email *" class="bs-gc-input" required>
                    <input type="tel" name="recipient_phone" placeholder="Recipient's Phone (optional)" class="bs-gc-input">
                    <textarea name="message" placeholder="Add a personal message (optional)" class="bs-gc-textarea" rows="3" maxlength="500"></textarea>
                </div>

                <!-- Purchaser details -->
                <div class="bs-gc-section">
                    <label class="bs-gc-label">Your Details</label>
                    <input type="text" name="purchaser_name" placeholder="Your Name *" class="bs-gc-input" required>
                    <input type="email" name="purchaser_email" placeholder="Your Email *" class="bs-gc-input" required>
                    <input type="tel" name="purchaser_phone" placeholder="Your Phone *" class="bs-gc-input" required>
                </div>

                <div class="bs-gc-summary" id="gc-order-summary" style="display:none">
                    <div class="bs-gc-summary-row"><span>Gift Card Value</span><strong id="gc-sum-value"></strong></div>
                    <div class="bs-gc-summary-row"><span>To</span><span id="gc-sum-to"></span></div>
                </div>

                <button type="submit" class="bs-gc-buy-btn" id="gc-buy-btn" disabled>
                    🎁 Purchase Gift Card — <span id="gc-buy-amount"><?=$cur?>0</span>
                </button>

                <div class="bs-gc-note">
                    Gift cards are delivered via email to the recipient. Valid for <?=intval(get_option('bookshop_gc_expiry_months',12))?> months from purchase.
                </div>
            </form>

            <!-- Success view (hidden initially) -->
            <div id="gc-success" class="bs-gc-success" style="display:none">
                <div class="bs-gc-success-icon">✅</div>
                <h3>Gift Card Purchased!</h3>
                <p>A gift card has been sent to <strong id="gc-success-email"></strong></p>
                <div class="bs-gc-success-code" id="gc-success-code"></div>
                <p class="bs-gc-success-value" id="gc-success-value"></p>
                <button class="bs-gc-buy-btn" onclick="location.reload()">Buy Another Gift Card</button>
            </div>
        </div>

        <!-- Balance Check Tab -->
        <div class="bs-gc-panel" id="gc-panel-balance" style="display:none">
            <div class="bs-gc-balance-form">
                <label class="bs-gc-label">Enter your gift card code</label>
                <div class="bs-gc-balance-row">
                    <input type="text" id="gc-balance-code" placeholder="e.g. GC-ABCD-EFGH-JKLM" class="bs-gc-input" style="text-transform:uppercase">
                    <button type="button" id="gc-check-balance-btn" class="bs-gc-check-btn">Check</button>
                </div>
                <div id="gc-balance-result" style="display:none"></div>
            </div>
        </div>
    </div>

    <style>
    .bs-gc-wrapper{max-width:600px;margin:0 auto;padding:20px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif}
    .bs-gc-tabs{display:flex;border-bottom:2px solid #e0d4c0;margin-bottom:24px}
    .bs-gc-tab{flex:1;padding:12px;background:none;border:none;font-size:.95rem;font-weight:600;cursor:pointer;color:#8a7a65;border-bottom:3px solid transparent;margin-bottom:-2px;transition:.2s}
    .bs-gc-tab.active{color:#1a1208;border-bottom-color:#c8860a}
    .bs-gc-panel{animation:fadeIn .3s ease}
    @keyframes fadeIn{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}
    .bs-gc-card-preview{display:flex;justify-content:center;margin-bottom:24px}
    .bs-gc-card{width:340px;height:200px;background:linear-gradient(135deg,#1a1208 0%,#3d2e1c 100%);border-radius:16px;padding:24px;color:#fff;display:flex;flex-direction:column;justify-content:space-between;box-shadow:0 8px 30px rgba(0,0,0,.2)}
    .bs-gc-card-top{display:flex;align-items:center;gap:8px}
    .bs-gc-card-logo{font-size:1.4rem}
    .bs-gc-card-shop{font-size:.8rem;opacity:.8;font-weight:500}
    .bs-gc-card-value{font-size:2.2rem;font-weight:700;color:#f5d87a}
    .bs-gc-card-label{font-size:.7rem;letter-spacing:3px;opacity:.6;text-transform:uppercase}
    .bs-gc-card-to{font-size:.85rem;opacity:.7;font-style:italic}
    .bs-gc-form{display:flex;flex-direction:column;gap:4px}
    .bs-gc-section{margin-bottom:20px}
    .bs-gc-label{display:block;font-size:.9rem;font-weight:700;margin-bottom:8px;color:#1a1208}
    .bs-gc-label-sm{display:block;font-size:.78rem;color:#8a7a65;margin-bottom:4px}
    .bs-gc-denoms{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:10px}
    .bs-gc-denom{padding:10px 16px;border:2px solid #e0d4c0;border-radius:8px;background:#fff;font-size:.9rem;font-weight:600;cursor:pointer;transition:.2s}
    .bs-gc-denom:hover{border-color:#c8860a;background:#fffbf0}
    .bs-gc-denom.selected{border-color:#c8860a;background:#f5d87a;color:#1a1208}
    .bs-gc-custom-row{margin-top:6px}
    .bs-gc-input{width:100%;padding:11px 14px;border:1.5px solid #e0d4c0;border-radius:8px;font-size:.9rem;margin-bottom:8px;box-sizing:border-box;transition:.2s}
    .bs-gc-input:focus{outline:none;border-color:#c8860a;box-shadow:0 0 0 3px rgba(200,134,10,.1)}
    .bs-gc-textarea{width:100%;padding:11px 14px;border:1.5px solid #e0d4c0;border-radius:8px;font-size:.9rem;resize:vertical;box-sizing:border-box}
    .bs-gc-textarea:focus{outline:none;border-color:#c8860a}
    .bs-gc-summary{background:#fffbf0;border:1.5px solid #e0d4c0;border-radius:10px;padding:14px;margin:12px 0}
    .bs-gc-summary-row{display:flex;justify-content:space-between;padding:4px 0;font-size:.88rem}
    .bs-gc-buy-btn{width:100%;padding:15px;background:#1a1208;color:#f5d87a;border:none;border-radius:10px;font-size:1rem;font-weight:700;cursor:pointer;transition:.2s;margin-top:10px}
    .bs-gc-buy-btn:hover{background:#3d2e1c}
    .bs-gc-buy-btn:disabled{background:#ccc;color:#888;cursor:not-allowed}
    .bs-gc-note{font-size:.75rem;color:#8a7a65;text-align:center;margin-top:10px}
    .bs-gc-success{text-align:center;padding:40px 20px}
    .bs-gc-success-icon{font-size:3rem;margin-bottom:12px}
    .bs-gc-success h3{font-size:1.4rem;margin-bottom:6px}
    .bs-gc-success-code{font-size:1.3rem;font-weight:700;font-family:monospace;background:#fffbf0;border:2px dashed #c8860a;border-radius:10px;padding:14px;margin:16px 0;letter-spacing:1px}
    .bs-gc-success-value{font-size:1.1rem;font-weight:600;color:#c8860a}
    .bs-gc-balance-form{max-width:400px;margin:0 auto;padding:30px 0}
    .bs-gc-balance-row{display:flex;gap:8px}
    .bs-gc-balance-row .bs-gc-input{margin-bottom:0}
    .bs-gc-check-btn{padding:11px 20px;background:#1a1208;color:#f5d87a;border:none;border-radius:8px;font-weight:700;cursor:pointer;white-space:nowrap}
    .bs-gc-check-btn:hover{background:#3d2e1c}
    #gc-balance-result{margin-top:16px;padding:16px;border-radius:10px;font-size:.9rem}
    .bs-gc-bal-active{background:#e8f8e8;border:1.5px solid #27ae60}
    .bs-gc-bal-error{background:#fde8e8;border:1.5px solid #c0392b;color:#c0392b}
    .bs-gc-bal-used{background:#f5f5f5;border:1.5px solid #999;color:#666}
    </style>

    <script>
    (function(){
        var cur = '<?=esc_js($cur)?>';
        var ajaxUrl = '<?=esc_js($ajax_url)?>';
        var nonce = '<?=esc_js($nonce)?>';
        var selectedAmount = 0;

        // Tabs
        document.querySelectorAll('.bs-gc-tab').forEach(function(tab){
            tab.addEventListener('click', function(){
                document.querySelectorAll('.bs-gc-tab').forEach(function(t){ t.classList.remove('active'); });
                document.querySelectorAll('.bs-gc-panel').forEach(function(p){ p.style.display='none'; });
                tab.classList.add('active');
                document.getElementById('gc-panel-'+tab.dataset.tab).style.display='block';
            });
        });

        // Denomination buttons
        document.querySelectorAll('.bs-gc-denom').forEach(function(btn){
            btn.addEventListener('click', function(){
                document.querySelectorAll('.bs-gc-denom').forEach(function(b){ b.classList.remove('selected'); });
                btn.classList.add('selected');
                var val = parseInt(btn.dataset.value);
                document.getElementById('gc-custom-amount').value = val;
                setAmount(val);
            });
        });

        // Custom amount input
        var customInput = document.getElementById('gc-custom-amount');
        customInput.addEventListener('input', function(){
            document.querySelectorAll('.bs-gc-denom').forEach(function(b){ b.classList.remove('selected'); });
            setAmount(parseFloat(this.value) || 0);
        });

        function setAmount(val){
            selectedAmount = val;
            document.getElementById('gc-preview-value').textContent = cur + numberFormat(val);
            document.getElementById('gc-buy-amount').textContent = cur + numberFormat(val);
            updateBuyBtn();
        }

        function numberFormat(n){ return n.toLocaleString(undefined, {minimumFractionDigits:0, maximumFractionDigits:0}); }

        // Live preview updates
        var form = document.getElementById('gc-purchase-form');
        form.addEventListener('input', function(e){
            if(e.target.name === 'recipient_name'){
                var v = e.target.value.trim();
                document.getElementById('gc-preview-to').textContent = v ? 'For ' + v : 'For someone special';
            }
            updateBuyBtn();
        });

        function updateBuyBtn(){
            var btn = document.getElementById('gc-buy-btn');
            var rName = form.querySelector('[name=recipient_name]').value.trim();
            var rEmail = form.querySelector('[name=recipient_email]').value.trim();
            var pName = form.querySelector('[name=purchaser_name]').value.trim();
            var pEmail = form.querySelector('[name=purchaser_email]').value.trim();
            var pPhone = form.querySelector('[name=purchaser_phone]').value.trim();
            btn.disabled = !(selectedAmount > 0 && rName && rEmail && pName && pEmail && pPhone);
        }

        // Submit purchase
        form.addEventListener('submit', function(e){
            e.preventDefault();
            var btn = document.getElementById('gc-buy-btn');
            btn.disabled = true;
            btn.textContent = 'Processing...';

            var data = new FormData(form);
            data.append('action', 'bs_purchase_gift_card_online');
            data.append('amount', selectedAmount);

            fetch(ajaxUrl, {method:'POST', body:data})
            .then(function(r){ return r.json(); })
            .then(function(res){
                if(res.success){
                    document.getElementById('gc-purchase-form').style.display = 'none';
                    document.querySelector('.bs-gc-card-preview').style.display = 'none';
                    var s = document.getElementById('gc-success');
                    s.style.display = 'block';
                    document.getElementById('gc-success-email').textContent = form.querySelector('[name=recipient_email]').value;
                    document.getElementById('gc-success-code').textContent = res.data.code;
                    document.getElementById('gc-success-value').textContent = cur + numberFormat(res.data.value);
                } else {
                    alert(res.data || 'Purchase failed. Please try again.');
                    btn.disabled = false;
                    btn.innerHTML = '🎁 Purchase Gift Card — <span id="gc-buy-amount">' + cur + numberFormat(selectedAmount) + '</span>';
                }
            })
            .catch(function(){
                alert('Network error. Please try again.');
                btn.disabled = false;
                btn.innerHTML = '🎁 Purchase Gift Card — <span id="gc-buy-amount">' + cur + numberFormat(selectedAmount) + '</span>';
            });
        });

        // Balance check
        document.getElementById('gc-check-balance-btn').addEventListener('click', function(){
            var code = document.getElementById('gc-balance-code').value.trim();
            if(!code){ alert('Please enter a gift card code'); return; }
            var btn = this;
            btn.textContent = 'Checking...';
            btn.disabled = true;

            var fd = new FormData();
            fd.append('action', 'bs_check_gc_balance_public');
            fd.append('code', code);
            fd.append('nonce', nonce);

            fetch(ajaxUrl, {method:'POST', body:fd})
            .then(function(r){ return r.json(); })
            .then(function(res){
                btn.textContent = 'Check';
                btn.disabled = false;
                var result = document.getElementById('gc-balance-result');
                result.style.display = 'block';
                if(res.success){
                    var d = res.data;
                    if(d.status === 'active'){
                        result.className = 'bs-gc-bal-active';
                        result.innerHTML = '<strong>Balance: ' + cur + parseFloat(d.balance).toLocaleString() + '</strong>'
                            + '<br><span style="font-size:.82rem;color:#555">Status: Active'
                            + (d.expires_at ? ' &middot; Expires: ' + d.expires_at : '') + '</span>';
                    } else {
                        result.className = 'bs-gc-bal-used';
                        result.innerHTML = '<strong>Status: ' + d.status.charAt(0).toUpperCase() + d.status.slice(1) + '</strong>'
                            + '<br><span style="font-size:.82rem">This card has been ' + d.status + '.</span>';
                    }
                } else {
                    result.className = 'bs-gc-bal-error';
                    result.textContent = res.data || 'Gift card not found';
                }
            })
            .catch(function(){
                btn.textContent = 'Check';
                btn.disabled = false;
                alert('Network error');
            });
        });
    })();
    </script>
    <?php
    return ob_get_clean();
}
