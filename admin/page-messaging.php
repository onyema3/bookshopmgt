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

        <!-- Segment -->
        <div class="bs-card">
            <h3 style="font-family:'Playfair Display',serif;margin-bottom:14px">1. Select Recipients</h3>
            <div class="bs-form-group" style="margin-bottom:10px">
                <label>Filter by Genre (leave blank for all)</label>
                <select id="msg-genre" class="bs-input">
                    <option value="">All Customers</option>
                    <?php foreach($genres as $g) echo "<option value='".esc_attr($g)."'>".esc_html($g)."</option>"; ?>
                </select>
            </div>
            <div class="bs-form-group" style="margin-bottom:10px">
                <label>Active in last N days</label>
                <select id="msg-days" class="bs-input">
                    <option value="30">30 days</option>
                    <option value="90">90 days</option>
                    <option value="180" selected>6 months</option>
                    <option value="365">1 year</option>
                    <option value="9999">All time</option>
                </select>
            </div>
            <div class="bs-form-group" style="margin-bottom:12px">
                <label>Min. spend (<?=bs_currency()?>)</label>
                <input type="number" id="msg-min-spend" class="bs-input" value="0" min="0">
            </div>
            <button class="bs-btn bs-btn-secondary" id="bs-load-segment">Load Recipients</button>
            <div id="msg-segment-result" style="margin-top:12px;font-size:.85rem;color:var(--muted)"></div>
            <div id="msg-recipient-list" style="max-height:200px;overflow-y:auto;margin-top:8px;font-size:.8rem"></div>
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
                <textarea id="msg-body" class="bs-input" rows="6" placeholder="Hello {first_name},&#10;&#10;We have exciting news for you…"></textarea>
            </div>
            <div style="display:flex;gap:8px">
                <button class="bs-btn bs-btn-primary" id="bs-send-msg">Send Message</button>
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
