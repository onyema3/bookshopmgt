<?php
if(!defined('ABSPATH'))exit;

function bs_page_settings(){
    $logo    = get_option('bookshop_logo_url','');
    $address = get_option('bookshop_address','');
    $tagline = get_option('bookshop_tagline','');
    $footer  = get_option('bookshop_receipt_footer','Thank you for shopping with us!');
    $phone   = get_option('bookshop_phone','');
    $email_s = get_option('bookshop_store_email','');
    ?>
    <div class="wrap bs-wrap">
    <div class="bs-header"><h1>⚙️ Settings</h1></div>

    <!-- Store Identity -->
    <div class="bs-card" style="max-width:760px;margin-bottom:20px">
        <h3 style="font-family:'Playfair Display',serif;margin-bottom:16px">🏪 Store Identity</h3>
        <div class="bs-form-grid">
            <div class="bs-form-group bs-span2">
                <label>Store Name</label>
                <input type="text" name="bookshop_receipt_header" class="bs-input bs-setting"
                    value="<?=esc_attr(get_option('bookshop_receipt_header',get_bloginfo('name')))?>">
            </div>
            <div class="bs-form-group bs-span2">
                <label>Tagline / Slogan</label>
                <input type="text" name="bookshop_tagline" class="bs-input bs-setting"
                    placeholder="e.g. Your neighbourhood bookshop" value="<?=esc_attr($tagline)?>">
            </div>
            <div class="bs-form-group bs-span2">
                <label>Address (shown on receipts)</label>
                <textarea name="bookshop_address" class="bs-input bs-setting" rows="2"
                    placeholder="123 Book Street, Lagos, Nigeria"><?=esc_textarea($address)?></textarea>
            </div>
            <div class="bs-form-group">
                <label>Store Phone</label>
                <input type="text" name="bookshop_phone" class="bs-input bs-setting"
                    value="<?=esc_attr($phone)?>" placeholder="+234 800 000 0000">
            </div>
            <div class="bs-form-group">
                <label>Store Email</label>
                <input type="email" name="bookshop_store_email" class="bs-input bs-setting"
                    value="<?=esc_attr($email_s)?>">
            </div>
            <div class="bs-form-group bs-span2">
                <label>Logo URL <small style="color:var(--muted)">(upload via Media Library, paste URL here)</small></label>
                <div style="display:flex;gap:8px;align-items:center">
                    <input type="url" name="bookshop_logo_url" id="bs-logo-url" class="bs-input bs-setting"
                        value="<?=esc_attr($logo)?>" placeholder="https://...">
                    <button type="button" id="bs-pick-logo" class="bs-btn bs-btn-secondary">📁 Pick</button>
                </div>
                <?php if($logo): ?>
                <img src="<?=esc_url($logo)?>" style="margin-top:8px;max-height:60px;max-width:200px;border:1px solid var(--border);border-radius:6px;padding:4px">
                <?php endif; ?>
            </div>
            <div class="bs-form-group bs-span2">
                <label>Receipt Footer Message</label>
                <input type="text" name="bookshop_receipt_footer" class="bs-input bs-setting"
                    value="<?=esc_attr($footer)?>">
            </div>
        </div>
    </div>

    <!-- Financial -->
    <div class="bs-card" style="max-width:760px;margin-bottom:20px">
        <h3 style="font-family:'Playfair Display',serif;margin-bottom:16px">💰 Financial Settings</h3>
        <div class="bs-form-grid">
            <div class="bs-form-group">
                <label>Currency Symbol</label>
                <select name="bookshop_currency" class="bs-input bs-setting">
                    <?php foreach(['₦'=>'₦ Naira','$'=>'$ Dollar','£'=>'£ Pound','€'=>'€ Euro','GH₵'=>'GH₵ Cedi','KSh'=>'KSh Shilling'] as $sym=>$lbl)
                        echo "<option value='".esc_attr($sym)."'".selected(get_option('bookshop_currency','₦'),$sym,false).">".esc_html($lbl)."</option>"; ?>
                </select>
            </div>
            <div class="bs-form-group">
                <label>Tax Mode</label>
                <select name="bookshop_tax_mode" class="bs-input bs-setting">
                    <option value="none"<?=selected(get_option('bookshop_tax_mode','none'),'none',false)?>>No Tax</option>
                    <option value="exclusive"<?=selected(get_option('bookshop_tax_mode'),'exclusive',false)?>>VAT Added on Top</option>
                    <option value="inclusive"<?=selected(get_option('bookshop_tax_mode'),'inclusive',false)?>>VAT Included in Price</option>
                </select>
            </div>
            <div class="bs-form-group">
                <label>Tax / VAT Rate (%)</label>
                <input type="number" name="bookshop_tax_rate" class="bs-input bs-setting"
                    step="0.1" min="0" max="100" value="<?=esc_attr(get_option('bookshop_tax_rate',0))?>">
            </div>
            <div class="bs-form-group">
                <label>Tax Label (e.g. VAT, GST)</label>
                <input type="text" name="bookshop_tax_label" class="bs-input bs-setting"
                    value="<?=esc_attr(get_option('bookshop_tax_label','VAT'))?>">
            </div>
        </div>
    </div>

    <!-- Loyalty -->
    <div class="bs-card" style="max-width:760px;margin-bottom:20px">
        <h3 style="font-family:'Playfair Display',serif;margin-bottom:16px">⭐ Loyalty Programme</h3>
        <div class="bs-form-grid">
            <div class="bs-form-group">
                <label>Points Earned per <?=bs_currency()?>100 spent</label>
                <input type="number" name="bookshop_loyalty_rate" class="bs-input bs-setting"
                    min="0" step="0.1" value="<?=esc_attr(get_option('bookshop_loyalty_rate',1))?>">
            </div>
            <div class="bs-form-group">
                <label>Point Value (<?=bs_currency()?> per point)</label>
                <input type="number" name="bookshop_loyalty_value" class="bs-input bs-setting"
                    min="0" step="0.01" value="<?=esc_attr(get_option('bookshop_loyalty_value',10))?>">
            </div>
        </div>
    </div>

    <!-- Operations -->
    <div class="bs-card" style="max-width:760px;margin-bottom:20px">
        <h3 style="font-family:'Playfair Display',serif;margin-bottom:16px">🔧 Operations</h3>
        <div class="bs-form-grid">
            <div class="bs-form-group">
                <label>Max Discount % before Manager Approval</label>
                <input type="number" name="bookshop_manager_discount_threshold" class="bs-input bs-setting"
                    min="0" max="100" value="<?=esc_attr(get_option('bookshop_manager_discount_threshold',20))?>">
            </div>
            <div class="bs-form-group">
                <label>Low Stock Alert Email</label>
                <input type="email" name="bookshop_low_stock_email" class="bs-input bs-setting"
                    value="<?=esc_attr(get_option('bookshop_low_stock_email',get_option('admin_email')))?>">
            </div>
            <div class="bs-form-group bs-span2">
                <label>WhatsApp Business Number (with country code, e.g. 2348012345678)</label>
                <input type="text" name="bookshop_whatsapp" class="bs-input bs-setting"
                    value="<?=esc_attr(get_option('bookshop_whatsapp',''))?>">
            </div>
        </div>
    </div>

    <div style="max-width:760px">
        <div style="display:flex;gap:10px;align-items:center">
            <button class="bs-btn bs-btn-primary" id="bs-save-settings">💾 Save All Settings</button>
            <span id="bs-settings-msg" style="color:#2a7a3b;font-weight:600;display:none">OK Settings saved!</span>
        </div>
    </div>

    <!-- Receipt Preview -->
    <div class="bs-card" style="max-width:760px;margin-top:24px">
        <h3 style="font-family:'Playfair Display',serif;margin-bottom:12px">🖨️ Receipt Preview</h3>
        <p style="color:var(--muted);font-size:.85rem;margin-bottom:14px">This is how your printed receipt will look. Save settings first, then refresh.</p>
        <?php bs_render_receipt_preview(); ?>
    </div>

    <!-- Payment Gateways -->
    <div class="bs-card" style="max-width:760px;margin-bottom:20px">
        <h3 style="font-family:'Playfair Display',serif;margin-bottom:16px">💳 Payment Gateways</h3>
        <p style="font-size:.82rem;color:var(--muted);margin-bottom:14px">Used for online orders and reservations. Get keys from your Paystack or Flutterwave dashboard.</p>
        <div class="bs-form-grid">
            <?php
            $ps_pub         = get_option('bookshop_paystack_public_key','');
            $ps_sec         = get_option('bookshop_paystack_secret_key','');
            $flw_pub        = get_option('bookshop_flutterwave_public_key','');
            $flw_sec        = get_option('bookshop_flutterwave_secret_key','');
            $ps_sec_ph      = $ps_sec  ? '&bull;&bull;&bull;&bull;'.substr($ps_sec,-4)  : 'sk_live_...';
            $flw_sec_ph     = $flw_sec ? '&bull;&bull;&bull;&bull;'.substr($flw_sec,-4) : 'FLWSECK_...';
            $ps_configured  = $ps_pub  ? ' <span style="font-size:.75rem;color:#2a7a3b">&#10003; Configured</span>' : '';
            $flw_configured = $flw_pub ? ' <span style="font-size:.75rem;color:#2a7a3b">&#10003; Configured</span>' : '';
            $ps_sec_badge   = $ps_sec  ? ' <span style="color:#2a7a3b">(saved)</span>' : '';
            $flw_sec_badge  = $flw_sec ? ' <span style="color:#2a7a3b">(saved)</span>' : '';
            ?>
            <div class="bs-form-group bs-span2" style="background:#f0f8f0;border-radius:8px;padding:12px">
                <label style="color:#2a7a3b;font-weight:700">Paystack <?=$ps_configured?></label>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:8px">
                    <div>
                        <label style="font-size:.75rem;color:var(--muted)">Public Key</label>
                        <input type="text" name="bookshop_paystack_public_key" class="bs-input bs-setting"
                            value="<?=esc_attr($ps_pub)?>" placeholder="pk_live_...">
                    </div>
                    <div>
                        <label style="font-size:.75rem;color:var(--muted)">Secret Key <?=$ps_sec_badge?></label>
                        <input type="text" name="bookshop_paystack_secret_key" class="bs-input bs-setting"
                            value="" placeholder="<?=esc_attr($ps_sec_ph)?>"
                            autocomplete="new-password">
                        <small style="font-size:.72rem;color:var(--muted)">Leave blank to keep current</small>
                    </div>
                </div>
            </div>
            <div class="bs-form-group bs-span2" style="background:#fff8f0;border-radius:8px;padding:12px">
                <label style="color:#c8860a;font-weight:700">Flutterwave <?=$flw_configured?></label>
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin-top:8px">
                    <div>
                        <label style="font-size:.75rem;color:var(--muted)">Public Key</label>
                        <input type="text" name="bookshop_flutterwave_public_key" class="bs-input bs-setting"
                            value="<?=esc_attr($flw_pub)?>" placeholder="FLWPUBK_...">
                    </div>
                    <div>
                        <label style="font-size:.75rem;color:var(--muted)">Secret Key <?=$flw_sec_badge?></label>
                        <input type="text" name="bookshop_flutterwave_secret_key" class="bs-input bs-setting"
                            value="" placeholder="<?=esc_attr($flw_sec_ph)?>"
                            autocomplete="new-password">
                        <small style="font-size:.72rem;color:var(--muted)">Leave blank to keep current</small>
                    </div>
                    <div>
                        <label style="font-size:.75rem;color:var(--muted)">Currency</label>
                        <select name="bookshop_flw_currency" class="bs-input bs-setting">
                            <?php foreach(['NGN'=>'NGN — Naira','GHS'=>'GHS — Cedi','KES'=>'KES — Shilling','USD'=>'USD — Dollar','GBP'=>'GBP — Pound'] as $code=>$label)
                                echo "<option value='$code'".selected(get_option('bookshop_flw_currency','NGN'),$code,false).">".esc_html($label)."</option>"; ?>
                        </select>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Backup & Recovery -->
    <div class="bs-card" style="max-width:760px;margin-bottom:20px">
        <h3 style="font-family:'Playfair Display',serif;margin-bottom:16px">💾 Backup & Recovery</h3>

        <!-- Daily backup email -->
        <div class="bs-form-group" style="margin-bottom:14px">
            <label>Daily Backup Email</label>
            <input type="email" name="bookshop_backup_email" class="bs-input bs-setting"
                value="<?=esc_attr(get_option('bookshop_backup_email',get_option('admin_email')))?>"
                style="max-width:360px">
            <small style="color:var(--muted);font-size:.78rem;display:block;margin-top:4px">
                A SQL backup of all bookshop data is emailed daily.<br>
                Last backup: <strong><?=esc_html(get_option('bookshop_last_backup','Never'))?></strong>
            </small>
        </div>

        <!-- Download -->
        <div style="margin-bottom:20px">
            <a href="<?=admin_url('admin-ajax.php?action=bs_download_backup')?>"
               class="bs-btn bs-btn-secondary">⬇ Download Backup Now</a>
        </div>

        <hr style="border:none;border-top:1px solid var(--border);margin:16px 0">

        <!-- Restore -->
        <h4 style="font-family:'Playfair Display',serif;font-size:1rem;margin-bottom:8px">🔄 Restore from Backup</h4>
        <p style="font-size:.82rem;color:var(--muted);margin-bottom:12px">
            Upload a <code>.sql</code> file previously downloaded from this plugin.
            <strong style="color:var(--red)">This will overwrite existing bookshop data.</strong>
        </p>
        <div style="display:flex;gap:10px;align-items:flex-start;flex-wrap:wrap">
            <div style="flex:1;min-width:240px">
                <input type="file" id="bs-restore-file" accept=".sql"
                    style="width:100%;padding:8px;border:1.5px dashed var(--border);border-radius:8px;background:var(--warm);font-size:.85rem;cursor:pointer">
            </div>
            <button class="bs-btn bs-btn-primary" id="bs-restore-btn" style="background:#c0392b;white-space:nowrap">
                🔄 Restore Backup
            </button>
        </div>
        <div id="bs-restore-result" style="margin-top:10px;display:none;font-size:.85rem"></div>
        <p style="font-size:.75rem;color:var(--muted);margin-top:8px">
            Last restore: <strong><?=esc_html(get_option('bookshop_last_restore','Never'))?></strong>
        </p>
    </div>

    <!-- WooCommerce -->
    <div class="bs-card" style="max-width:760px;margin-bottom:20px">
        <h3 style="font-family:'Playfair Display',serif;margin-bottom:12px">🛒 WooCommerce</h3>
        <?php if(bs_woo_active()): ?>
        <p style="color:#2a7a3b;margin-bottom:12px">OK WooCommerce is active. Stock levels sync when books are updated.</p>
        <button class="bs-btn bs-btn-secondary" id="bs-import-woo-btn">Import Products from WooCommerce</button>
        <?php else: ?>
        <p style="color:var(--muted)">WooCommerce is not installed. Install it to enable stock sync.</p>
        <?php endif; ?>
    </div>

    <!-- Advanced Operations -->
    <div class="bs-card" style="max-width:760px;margin-bottom:20px">
        <h3 style="font-family:'Playfair Display',serif;margin-bottom:16px">🔧 Advanced Operations</h3>
        <div class="bs-form-grid">
            <div class="bs-form-group">
                <label>End-of-Day Report Email</label>
                <input type="email" name="bookshop_eod_email" class="bs-input bs-setting"
                    value="<?=esc_attr(get_option('bookshop_eod_email',get_option('admin_email')))?>">
            </div>
            <div class="bs-form-group" style="display:flex;flex-direction:column;justify-content:flex-end">
                <button class="bs-btn bs-btn-secondary" id="bs-send-eod-now" type="button">📧 Send EOD Report Now</button>
                <a href="<?=admin_url('?bookshop_print_report=1&from='.date('Y-m-01').'&to='.date('Y-m-d'))?>" target="_blank"
                   class="bs-btn bs-btn-secondary" style="margin-top:6px;text-align:center">🖨️ Preview Printable Report</a>
            </div>
            <div class="bs-form-group">
                <label>Loyalty Points Expiry (months, 0 = never)</label>
                <input type="number" name="bookshop_loyalty_expiry_months" class="bs-input bs-setting"
                    min="0" value="<?=esc_attr(get_option('bookshop_loyalty_expiry_months',0))?>">
            </div>
            <div class="bs-form-group" style="display:flex;flex-direction:column;justify-content:flex-end">
                <button class="bs-btn bs-btn-secondary" id="bs-run-expiry" type="button">⏰ Run Points Expiry Now</button>
            </div>
            <div class="bs-form-group bs-span2">
                <label>POS IP Whitelist (one IP per line, CIDR supported, empty = allow all)</label>
                <textarea name="bookshop_ip_whitelist" class="bs-input bs-setting" rows="3"
                    placeholder="e.g.&#10;192.168.1.0/24&#10;41.58.100.5"><?=esc_textarea(get_option('bookshop_ip_whitelist',''))?></textarea>
                <small style="color:var(--muted);font-size:.75rem">Your current IP: <strong><?=function_exists('bs_get_client_ip')?esc_html(bs_get_client_ip()):esc_html($_SERVER['REMOTE_ADDR']??'unknown')?></strong></small>
            </div>
        </div>
    </div>

    <!-- Google Sheets -->
    <div class="bs-card" style="max-width:760px;margin-bottom:20px">
        <h3 style="font-family:'Playfair Display',serif;margin-bottom:12px">📊 Google Sheets Sync</h3>
        <p style="font-size:.83rem;color:var(--muted);margin-bottom:12px">
            Create a Google Apps Script Web App to receive sales data automatically.<br>
            <a href="https://developers.google.com/apps-script/guides/web" target="_blank" style="color:var(--amber)">Learn how to set up a Web App →</a>
        </p>
        <div class="bs-form-group" style="margin-bottom:12px">
            <label>Apps Script Web App URL</label>
            <input type="url" name="bookshop_google_sheets_url" class="bs-input bs-setting"
                value="<?=esc_attr(get_option('bookshop_google_sheets_url',''))?>"
                placeholder="https://script.google.com/macros/s/.../exec">
        </div>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
            <button class="bs-btn bs-btn-secondary" id="bs-sync-sheets-now" type="button">🔄 Sync Today to Sheets</button>
            <small style="color:var(--muted)">Last sync: <?=esc_html(get_option('bookshop_last_sheets_sync','Never'))?></small>
        </div>
    </div>

    <!-- Shortcodes -->
    <div class="bs-card" style="max-width:760px;margin-top:4px;margin-bottom:20px">
        <h3 style="font-family:'Playfair Display',serif;margin-bottom:12px">📋 Shortcodes</h3>
        <div style="display:flex;flex-direction:column;gap:8px">
            <div><code style="background:#f5efe4;padding:4px 10px;border-radius:5px">[bookshop_reserve]</code> — Book reservation form</div>
            <div><code style="background:#f5efe4;padding:4px 10px;border-radius:5px">[bookshop_portal]</code> — Customer account portal (login, orders, points, reservations)</div>
            <div><code style="background:#f5efe4;padding:4px 10px;border-radius:5px">[bookshop_catalogue]</code> — Full online book catalogue with cart &amp; checkout</div>
            <div><code style="background:#f5efe4;padding:4px 10px;border-radius:5px">[bookshop_catalogue genre="Fiction" limit="12"]</code> — Filtered catalogue</div>
        </div>
    </div>
    </div>

    <script>
    // WordPress Media Library picker for logo
    jQuery(function($){
        $('#bs-pick-logo').on('click',function(){
            if(typeof wp==='undefined'||!wp.media){alert('Media library not available.');return;}
            var frame=wp.media({title:'Select Logo',button:{text:'Use this image'},multiple:false});
            frame.on('select',function(){
                var att=frame.state().get('selection').first().toJSON();
                $('#bs-logo-url').val(att.url);
            });
            frame.open();
        });
    });
    </script>
    <?php
}

function bs_render_receipt_preview(){
    $shop    = get_option('bookshop_receipt_header', get_bloginfo('name'));
    $tagline = get_option('bookshop_tagline','');
    $address = get_option('bookshop_address','');
    $phone   = get_option('bookshop_phone','');
    $logo    = get_option('bookshop_logo_url','');
    $footer  = get_option('bookshop_receipt_footer','Thank you for shopping with us!');
    $cur     = bs_currency();
    echo '<div style="font-family:monospace;font-size:.82rem;max-width:320px;border:1px dashed #ccc;padding:16px;border-radius:6px;background:#fff;line-height:1.7">';
    if($logo) echo '<div style="text-align:center;margin-bottom:8px"><img src="'.esc_url($logo).'" style="max-height:50px;max-width:160px"></div>';
    echo '<div style="text-align:center;font-weight:700;font-size:1rem">'.esc_html($shop).'</div>';
    if($tagline) echo '<div style="text-align:center;font-size:.78rem;color:#666">'.esc_html($tagline).'</div>';
    if($address) echo '<div style="text-align:center;font-size:.75rem">'.nl2br(esc_html($address)).'</div>';
    if($phone)   echo '<div style="text-align:center;font-size:.75rem">Tel: '.esc_html($phone).'</div>';
    echo '<div style="border-top:1px dashed #999;margin:8px 0"></div>';
    echo '<div style="display:flex;justify-content:space-between"><span>Ref:</span><span>BS-PREVIEW</span></div>';
    echo '<div style="display:flex;justify-content:space-between"><span>Date:</span><span>'.date('d/m/Y H:i').'</span></div>';
    echo '<div style="display:flex;justify-content:space-between"><span>Staff:</span><span>'.esc_html(wp_get_current_user()->display_name).'</span></div>';
    echo '<div style="border-top:1px dashed #999;margin:8px 0"></div>';
    echo '<div style="display:flex;justify-content:space-between"><span>Sample Book × 2</span><span>'.$cur.'1,600.00</span></div>';
    echo '<div style="display:flex;justify-content:space-between"><span>Another Title × 1</span><span>'.$cur.'2,500.00</span></div>';
    echo '<div style="border-top:1px dashed #999;margin:8px 0"></div>';
    echo '<div style="display:flex;justify-content:space-between"><span>Subtotal</span><span>'.$cur.'4,100.00</span></div>';
    echo '<div style="display:flex;justify-content:space-between;font-weight:700;font-size:1rem"><span>TOTAL</span><span>'.$cur.'4,100.00</span></div>';
    echo '<div style="border-top:1px dashed #999;margin:8px 0"></div>';
    echo '<div style="text-align:center;font-size:.75rem">'.esc_html($footer).'</div>';
    echo '<div style="text-align:center;font-size:.7rem;color:#999;margin-top:4px">Powered by Bookshop Manager Pro</div>';
    echo '</div>';
}
