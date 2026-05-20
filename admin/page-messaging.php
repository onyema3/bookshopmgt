<?php
if(!defined('ABSPATH'))exit;

function bs_page_messaging(){
    $genres=bs_genres();
    $log=bs_get_message_log(['limit'=>50]);
    ?>
    <div class="wrap bs-wrap">
    <div class="bs-header"><h1>📣 Customer Messaging</h1></div>

    <div class="bs-tabs">
        <button class="bs-tab active" data-tab="msg-compose">Compose</button>
        <button class="bs-tab" data-tab="msg-log">Message Log</button>
    </div>

    <div id="msg-compose" class="bs-tab-content">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;max-width:1100px">

        <!-- Recipients -->
        <div class="bs-card">
            <h3 style="font-family:'Playfair Display',serif;margin-bottom:14px">1. Select Recipients</h3>

            <!-- Segment filter (collapsed-style block) -->
            <div style="background:#fdf8f0;border:1px solid #f0e8d8;border-radius:8px;padding:12px;margin-bottom:14px">
                <div style="font-size:.78rem;color:#8a7a65;margin-bottom:8px;text-transform:uppercase;letter-spacing:.04em">📋 Load by segment</div>
                <div class="bs-form-group" style="margin-bottom:8px">
                    <label style="font-size:.82rem">Genre</label>
                    <select id="msg-genre" class="bs-input">
                        <option value="">All genres</option>
                        <?php foreach($genres as $g) echo "<option value='".esc_attr($g)."'>".esc_html($g)."</option>"; ?>
                    </select>
                </div>
                <div style="display:flex;gap:8px;margin-bottom:10px">
                    <div class="bs-form-group" style="flex:1;margin-bottom:0">
                        <label style="font-size:.82rem">Active in last</label>
                        <select id="msg-days" class="bs-input">
                            <option value="30">30 days</option>
                            <option value="90">90 days</option>
                            <option value="180" selected>6 months</option>
                            <option value="365">1 year</option>
                            <option value="9999">All time</option>
                        </select>
                    </div>
                    <div class="bs-form-group" style="flex:1;margin-bottom:0">
                        <label style="font-size:.82rem">Min spend (<?=bs_currency()?>)</label>
                        <input type="number" id="msg-min-spend" class="bs-input" value="0" min="0">
                    </div>
                </div>
                <button class="bs-btn bs-btn-secondary" id="bs-load-segment" style="width:100%">Load Segment</button>
            </div>

            <!-- Manual add via search -->
            <div style="position:relative;margin-bottom:14px">
                <label style="font-size:.78rem;color:#8a7a65;margin-bottom:6px;display:block;text-transform:uppercase;letter-spacing:.04em">🔍 Or add specific customers</label>
                <input type="text" id="msg-customer-search" class="bs-input" placeholder="Search by name, phone, or email…" autocomplete="off">
                <div id="msg-customer-search-results" style="display:none;position:absolute;left:0;right:0;top:100%;z-index:100;background:#fff;border:1px solid #e0d4c0;border-radius:0 0 8px 8px;max-height:240px;overflow-y:auto;box-shadow:0 6px 18px rgba(26,18,8,.12)"></div>
            </div>

            <!-- Selected list (chips) -->
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
                <div id="msg-segment-result" style="font-size:.85rem;color:var(--muted)">No recipients selected.</div>
                <button class="bs-btn-link" id="msg-clear-all" style="display:none;font-size:.78rem">Clear all</button>
            </div>
            <div id="msg-recipient-list" style="max-height:280px;overflow-y:auto;padding:6px;border:1px dashed #e0d4c0;border-radius:8px;background:#fafafa;min-height:60px"></div>
        </div>

        <!-- Compose -->
        <div class="bs-card">
            <h3 style="font-family:'Playfair Display',serif;margin-bottom:14px">2. Compose Message</h3>
            <div class="bs-form-group" style="margin-bottom:10px">
                <label>Channel</label>
                <select id="msg-channel" class="bs-input">
                    <option value="email">📧 Email</option>
                    <option value="whatsapp">📱 WhatsApp Links</option>
                    <option value="both">Both</option>
                </select>
            </div>
            <div id="msg-email-fields">
                <div class="bs-form-group" style="margin-bottom:10px">
                    <label>Email Subject</label>
                    <input type="text" id="msg-subject" class="bs-input" placeholder="e.g. New arrivals at <?=esc_attr(get_option('bookshop_receipt_header'))?>!">
                </div>
            </div>
            <div class="bs-form-group" style="margin-bottom:10px">
                <label>Message Body <small style="color:var(--muted)">(use {name} {first_name} {points} as placeholders)</small></label>
                <textarea id="msg-body" class="bs-input" rows="7" placeholder="Hi {first_name},&#10;&#10;We've just received some new arrivals we think you'll love…&#10;&#10;Drop by anytime — see you soon!"></textarea>
            </div>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                <button class="bs-btn bs-btn-primary" id="bs-send-msg">Send Message</button>
                <button class="bs-btn bs-btn-secondary" id="bs-preview-msg" type="button">👁 Preview</button>
                <span id="msg-send-result" style="font-size:.83rem;line-height:34px"></span>
            </div>
        </div>
    </div>
    <!-- WhatsApp links output -->
    <div id="msg-wa-links" style="display:none;margin-top:20px">
        <div class="bs-card">
            <h3 style="font-family:'Playfair Display',serif;margin-bottom:12px">📱 WhatsApp Links — click each to open</h3>
            <p style="font-size:.82rem;color:var(--muted);margin-bottom:12px">Each link opens a pre-filled WhatsApp chat. Click one at a time.</p>
            <div id="msg-wa-links-body"></div>
        </div>
    </div>
    </div>

    <!-- Preview modal -->
    <div id="msg-preview-modal" style="display:none;position:fixed;inset:0;background:rgba(26,18,8,.55);z-index:9999;padding:30px 16px;overflow-y:auto">
        <div style="max-width:680px;margin:auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 10px 40px rgba(0,0,0,.3)">
            <div style="display:flex;justify-content:space-between;align-items:center;padding:14px 18px;border-bottom:1px solid #f0e8d8;background:#fdf8f0">
                <strong style="font-family:'Playfair Display',serif">Email Preview</strong>
                <button id="msg-preview-close" style="border:0;background:transparent;font-size:1.4em;cursor:pointer;line-height:1;color:#8a7a65">×</button>
            </div>
            <div id="msg-preview-subject" style="padding:10px 18px;background:#f5ede0;font-size:.9em;color:#1a1208"></div>
            <div id="msg-preview-body" style="max-height:60vh;overflow-y:auto"></div>
            <div style="padding:12px 18px;border-top:1px solid #f0e8d8;font-size:.78rem;color:#8a7a65;background:#fdf8f0">
                Placeholders are filled with sample data ({first_name} → Jane).
            </div>
        </div>
    </div>

    <div id="msg-log" class="bs-tab-content" style="display:none">
    <table class="bs-table">
        <thead><tr><th>Date</th><th>Customer</th><th>Type</th><th>Email</th><th>Phone</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach($log as $m): ?>
        <tr>
            <td><?=esc_html(wp_date('d M Y H:i',strtotime($m->created_at)))?></td>
            <td><?=esc_html($m->customer_name??'—')?></td>
            <td><code><?=esc_html($m->type)?></code></td>
            <td><?=esc_html($m->email)?></td>
            <td><?=esc_html($m->phone)?></td>
            <td><span class="bs-badge bs-badge-<?=esc_attr($m->status)?>"><?=$m->status?></span></td>
        </tr>
        <?php endforeach; if(empty($log)) echo '<tr><td colspan="6" style="text-align:center;color:#999;padding:24px">No messages sent yet.</td></tr>'; ?>
        </tbody>
    </table>
    </div>
    </div>
<?php
}
