<?php
/**
 * SMS delivery — BulkSMSNigeria (default), Termii, or Twilio.
 *
 * Why three providers: ₦/Nigeria deliverability is best with a local
 * aggregator (BulkSMSNigeria, Termii) — Twilio works but is significantly
 * more expensive per message to NG numbers and needs a long-code or short-
 * code from. Admin picks one in Settings; no automatic failover (yet).
 *
 * Public API used by other modules:
 *   bs_sms_enabled()                              → bool
 *   bs_sms_normalize_phone($raw, $cc='234')       → '234XXXXXXXXXX' or ''
 *   bs_send_sms($phone, $body, $context='')       → array with ok / provider / error
 *
 * Internal triggers wired up here:
 *   - bs_notify_online_order_status_change() in online-store.php calls
 *     bs_send_sms() after the email send.
 *   - bs_update_reservation in ajax-misc.php calls bs_send_sms() when a
 *     reservation flips to 'notified' (book ready for collection).
 *
 * Settings keys (all in wp_options):
 *   bookshop_sms_enabled                '1' to send, anything else disables
 *   bookshop_sms_provider               'bulksmsnigeria' | 'termii' | 'twilio'
 *   bookshop_sms_sender_id              short alphanumeric sender (≤ 11 chars)
 *   bookshop_sms_default_country        country code for normalisation, e.g. '234'
 *   bookshop_sms_bsn_api_token          BulkSMSNigeria API token (secret)
 *   bookshop_sms_termii_api_key         Termii API key (secret)
 *   bookshop_sms_twilio_sid             Twilio Account SID
 *   bookshop_sms_twilio_token           Twilio auth token (secret)
 *   bookshop_sms_twilio_from            Twilio from-number in E.164 (+1234…)
 *
 * Last failure surfaced on the settings page via:
 *   bookshop_last_sms_error / _at
 *   bookshop_last_sms_sent_at (success timestamp)
 */
if ( ! defined('ABSPATH') ) exit;

// ── Public API ───────────────────────────────────────────────────────────────

function bs_sms_enabled() {
    return get_option('bookshop_sms_enabled') === '1';
}

/**
 * Normalise a phone to a country-prefixed digits-only string ("234XXXXXXXXXX").
 * Strips spaces, dashes, brackets, leading +/00. Replaces leading 0 with the
 * configured default country code. Returns '' if the result doesn't look
 * like a sane mobile number (we treat 8–15 digits as the valid range).
 */
function bs_sms_normalize_phone($raw, $cc = null) {
    if ($cc === null) {
        $cc = (string) get_option('bookshop_sms_default_country', '234');
    }
    $cc = preg_replace('/\D/', '', (string) $cc) ?: '234';

    $digits = preg_replace('/\D/', '', (string) $raw);
    if ($digits === '') return '';

    // 00 prefix → drop it, the rest is already country-coded.
    if (strpos($digits, '00') === 0) {
        $digits = substr($digits, 2);
    }
    // Leading 0 is the local-format Nigerian convention (0810…). Replace it
    // with the country code.
    elseif (strpos($digits, '0') === 0) {
        $digits = $cc . substr($digits, 1);
    }
    // No country code at all (e.g. "810…") — prepend.
    elseif (strlen($digits) <= 10 && strpos($digits, $cc) !== 0) {
        $digits = $cc . $digits;
    }

    if (strlen($digits) < 8 || strlen($digits) > 15) return '';
    return $digits;
}

/**
 * Send an SMS. Returns:
 *   ['ok' => true,  'provider' => 'termii', 'message_id' => '…']
 *   ['ok' => false, 'provider' => 'termii', 'error' => 'human reason']
 * Disabled / misconfigured returns ok=false with a context error so the caller
 * can decide whether to surface it. This function never throws.
 */
function bs_send_sms($phone, $body, $context = '') {
    if ( ! bs_sms_enabled()) {
        return ['ok' => false, 'provider' => '', 'error' => 'SMS disabled'];
    }

    $provider = (string) get_option('bookshop_sms_provider', 'bulksmsnigeria');
    $to       = bs_sms_normalize_phone($phone);
    $body     = trim((string) $body);

    if ($to === '')   return bs_sms_record_failure($provider, 'Invalid recipient phone', $context);
    if ($body === '') return bs_sms_record_failure($provider, 'Empty message body',     $context);

    switch ($provider) {
        case 'termii':         $res = bs_sms_send_termii($to, $body);         break;
        case 'twilio':         $res = bs_sms_send_twilio($to, $body);         break;
        case 'bulksmsnigeria':
        default:               $res = bs_sms_send_bulksmsnigeria($to, $body); break;
    }

    if ( ! empty($res['ok'])) {
        update_option('bookshop_last_sms_sent_at', current_time('mysql'));
        delete_option('bookshop_last_sms_error');
        delete_option('bookshop_last_sms_error_at');
        $res['provider'] = $provider;
        return $res;
    }

    return bs_sms_record_failure($provider, $res['error'] ?? 'Unknown error', $context);
}

function bs_sms_record_failure($provider, $error, $context = '') {
    $tag = $context ? "[$context] " : '';
    update_option('bookshop_last_sms_error',    $tag . $error);
    update_option('bookshop_last_sms_error_at', current_time('mysql'));
    return ['ok' => false, 'provider' => $provider, 'error' => $error];
}

// ── Provider implementations ─────────────────────────────────────────────────

/**
 * BulkSMSNigeria — https://www.bulksmsnigeria.com/api
 * v2 endpoint; supports `dnd=2` to attempt delivery on DND-blocked numbers
 * via the corporate route (recommended for transactional messages).
 */
function bs_sms_send_bulksmsnigeria($to, $body) {
    $token = trim((string) get_option('bookshop_sms_bsn_api_token', ''));
    $from  = bs_sms_sender_id_or('Bookshop');
    if ( ! $token) return ['ok' => false, 'error' => 'BulkSMSNigeria API token not configured'];

    $resp = wp_remote_post('https://www.bulksmsnigeria.com/api/v2/sms', [
        'timeout' => 20,
        'body'    => [
            'api_token' => $token,
            'from'      => $from,
            'to'        => $to,
            'body'      => $body,
            'dnd'       => 2, // route around DND for transactional messages
        ],
    ]);

    if (is_wp_error($resp)) return ['ok' => false, 'error' => $resp->get_error_message()];

    $code = (int) wp_remote_retrieve_response_code($resp);
    $raw  = wp_remote_retrieve_body($resp);
    $data = json_decode($raw, true);

    if ($code >= 200 && $code < 300 && is_array($data)) {
        $msg_id = $data['data']['message_id'] ?? ($data['message_id'] ?? '');
        return ['ok' => true, 'message_id' => $msg_id];
    }
    $err = $data['error']['message'] ?? ($data['message'] ?? "HTTP $code");
    return ['ok' => false, 'error' => $err];
}

/**
 * Termii — https://developer.termii.com/sms-overview
 * `channel=generic` is the standard route; `dnd` and `whatsapp` are alternatives
 * the admin can switch to later by editing this provider call.
 */
function bs_sms_send_termii($to, $body) {
    $key  = trim((string) get_option('bookshop_sms_termii_api_key', ''));
    $from = bs_sms_sender_id_or('N-Alert');
    if ( ! $key) return ['ok' => false, 'error' => 'Termii API key not configured'];

    $resp = wp_remote_post('https://api.ng.termii.com/api/sms/send', [
        'timeout' => 20,
        'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
        'body'    => wp_json_encode([
            'to'      => $to,
            'from'    => $from,
            'sms'     => $body,
            'type'    => 'plain',
            'channel' => 'generic',
            'api_key' => $key,
        ]),
    ]);

    if (is_wp_error($resp)) return ['ok' => false, 'error' => $resp->get_error_message()];

    $code = (int) wp_remote_retrieve_response_code($resp);
    $raw  = wp_remote_retrieve_body($resp);
    $data = json_decode($raw, true);

    if ($code >= 200 && $code < 300 && is_array($data) && ! empty($data['message_id'])) {
        return ['ok' => true, 'message_id' => $data['message_id']];
    }
    $err = $data['message'] ?? "HTTP $code — " . substr($raw, 0, 200);
    return ['ok' => false, 'error' => $err];
}

/**
 * Twilio — https://www.twilio.com/docs/sms/api/message-resource
 * Basic auth uses Account SID + Auth Token. The from must be a Twilio number
 * in E.164 form (+1234567890); we send To as +<digits>.
 */
function bs_sms_send_twilio($to, $body) {
    $sid   = trim((string) get_option('bookshop_sms_twilio_sid', ''));
    $token = trim((string) get_option('bookshop_sms_twilio_token', ''));
    $from  = trim((string) get_option('bookshop_sms_twilio_from', ''));
    if ( ! $sid || ! $token) return ['ok' => false, 'error' => 'Twilio SID/token not configured'];
    if ( ! $from)            return ['ok' => false, 'error' => 'Twilio from-number not configured'];

    $resp = wp_remote_post(
        "https://api.twilio.com/2010-04-01/Accounts/" . rawurlencode($sid) . "/Messages.json",
        [
            'timeout' => 20,
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode("$sid:$token"),
            ],
            'body'    => [
                'To'   => '+' . $to,
                'From' => $from,
                'Body' => $body,
            ],
        ]
    );

    if (is_wp_error($resp)) return ['ok' => false, 'error' => $resp->get_error_message()];

    $code = (int) wp_remote_retrieve_response_code($resp);
    $raw  = wp_remote_retrieve_body($resp);
    $data = json_decode($raw, true);

    if ($code >= 200 && $code < 300 && is_array($data) && ! empty($data['sid'])) {
        return ['ok' => true, 'message_id' => $data['sid']];
    }
    $err = $data['message'] ?? "HTTP $code";
    return ['ok' => false, 'error' => $err];
}

// ── Helpers ──────────────────────────────────────────────────────────────────

/**
 * Sender ID rules:
 *   - BulkSMSNigeria & Termii: 11 alphanumeric chars max
 *   - Twilio: ignored (uses the configured from-number) but kept for parity
 * Falls back to a safe default when the admin hasn't set one.
 */
function bs_sms_sender_id_or($default) {
    $sender = trim((string) get_option('bookshop_sms_sender_id', ''));
    if ($sender === '') $sender = $default;
    // Strip anything outside [A-Za-z0-9 ] and clamp to 11 chars to satisfy
    // both BulkSMSNigeria's and Termii's sender-ID limits.
    $sender = preg_replace('/[^A-Za-z0-9 ]/', '', $sender);
    return substr($sender, 0, 11) ?: $default;
}

// ── AJAX: Test SMS so the admin can verify config without guessing ───────────
add_action('wp_ajax_bs_sms_test', function () {
    if ( ! current_user_can('manage_options')) wp_send_json_error('Unauthorized', 403);

    $to = sanitize_text_field($_POST['to'] ?? '');
    if ($to === '') wp_send_json_error('Enter a phone number first.');

    if ( ! bs_sms_enabled()) {
        wp_send_json_error('SMS is disabled. Tick "Send SMS for…" and save settings first.');
    }

    $shop = get_option('bookshop_receipt_header', get_bloginfo('name'));
    $body = "[$shop] Test SMS — " . wp_date('d M H:i') . ". If you got this, your SMS provider is working.";

    $res = bs_send_sms($to, $body, 'test');
    if ( ! empty($res['ok'])) {
        wp_send_json_success([
            'message' => 'Test SMS sent via ' . esc_html($res['provider']) . '. Reference: ' . esc_html($res['message_id'] ?: '(no id)'),
        ]);
    }
    wp_send_json_error('Send failed: ' . ($res['error'] ?? 'unknown error'));
});

// ── Settings card render — called from admin/page-settings.php ───────────────
function bs_sms_render_settings_card() {
    if ( ! current_user_can('manage_options')) return;

    $enabled  = get_option('bookshop_sms_enabled', '0');
    $provider = get_option('bookshop_sms_provider', 'bulksmsnigeria');
    $sender   = get_option('bookshop_sms_sender_id', '');
    $cc       = get_option('bookshop_sms_default_country', '234');

    $bsn_token   = get_option('bookshop_sms_bsn_api_token', '');
    $termii_key  = get_option('bookshop_sms_termii_api_key', '');
    $twilio_sid  = get_option('bookshop_sms_twilio_sid', '');
    $twilio_tok  = get_option('bookshop_sms_twilio_token', '');
    $twilio_from = get_option('bookshop_sms_twilio_from', '');

    $err    = get_option('bookshop_last_sms_error', '');
    $err_at = get_option('bookshop_last_sms_error_at', '');
    $sent   = get_option('bookshop_last_sms_sent_at', '');
    ?>
    <div class="bs-card" style="max-width:760px;margin-bottom:20px">
        <h3 style="font-family:'Playfair Display',serif;margin-bottom:8px">📱 SMS Delivery</h3>
        <p style="font-size:.83rem;color:var(--muted);margin-bottom:14px">
            Used for online-order status updates and reservation-ready notifications.
            Pick one provider — BulkSMSNigeria and Termii are local aggregators with much
            cheaper ₦ rates than Twilio. Twilio is fine if you already have an account.
        </p>
        <div class="bs-form-grid">
            <div class="bs-form-group bs-span2">
                <label style="display:flex;align-items:center;gap:10px;cursor:pointer">
                    <input type="hidden" name="bookshop_sms_enabled" value="0">
                    <input type="checkbox" name="bookshop_sms_enabled" class="bs-setting" value="1"
                        <?=checked('1', $enabled, false)?>>
                    <span>Send SMS for transactional events (order status, reservation ready)</span>
                </label>
            </div>
            <div class="bs-form-group">
                <label>Provider</label>
                <select name="bookshop_sms_provider" class="bs-input bs-setting">
                    <option value="bulksmsnigeria" <?=$provider==='bulksmsnigeria'?'selected':''?>>BulkSMSNigeria</option>
                    <option value="termii"         <?=$provider==='termii'        ?'selected':''?>>Termii</option>
                    <option value="twilio"         <?=$provider==='twilio'        ?'selected':''?>>Twilio</option>
                </select>
            </div>
            <div class="bs-form-group">
                <label>Sender ID <small style="color:var(--muted);font-weight:normal">(≤ 11 chars, BSN/Termii only)</small></label>
                <input type="text" name="bookshop_sms_sender_id" class="bs-input bs-setting"
                    value="<?=esc_attr($sender)?>" maxlength="11" placeholder="Bookshop">
            </div>
            <div class="bs-form-group">
                <label>Default Country Code <small style="color:var(--muted);font-weight:normal">(for 0810… normalisation)</small></label>
                <input type="text" name="bookshop_sms_default_country" class="bs-input bs-setting"
                    value="<?=esc_attr($cc)?>" placeholder="234">
            </div>

            <!-- BulkSMSNigeria -->
            <div class="bs-form-group bs-span2" style="background:#f0f8f0;border-radius:8px;padding:12px">
                <label style="color:#2a7a3b;font-weight:700">
                    BulkSMSNigeria
                    <?=$bsn_token ? '<span style="font-size:.75rem;color:#2a7a3b">&#10003; Configured</span>' : ''?>
                </label>
                <div style="margin-top:6px">
                    <label style="font-size:.75rem;color:var(--muted)">API Token <?=$bsn_token ? '<span style="color:#2a7a3b">(saved)</span>' : ''?></label>
                    <input type="text" name="bookshop_sms_bsn_api_token" class="bs-input bs-setting"
                        value="" placeholder="<?=$bsn_token ? '&bull;&bull;&bull;&bull;'.esc_attr(substr($bsn_token,-4)) : 'paste your API token'?>"
                        autocomplete="new-password">
                    <small style="font-size:.72rem;color:var(--muted)">Get your token from
                        <a href="https://www.bulksmsnigeria.com/dashboard/api" target="_blank">bulksmsnigeria.com → API</a>.
                        Leave blank to keep the current value.</small>
                </div>
            </div>

            <!-- Termii -->
            <div class="bs-form-group bs-span2" style="background:#fff8f0;border-radius:8px;padding:12px">
                <label style="color:#c8860a;font-weight:700">
                    Termii
                    <?=$termii_key ? '<span style="font-size:.75rem;color:#2a7a3b">&#10003; Configured</span>' : ''?>
                </label>
                <div style="margin-top:6px">
                    <label style="font-size:.75rem;color:var(--muted)">API Key <?=$termii_key ? '<span style="color:#2a7a3b">(saved)</span>' : ''?></label>
                    <input type="text" name="bookshop_sms_termii_api_key" class="bs-input bs-setting"
                        value="" placeholder="<?=$termii_key ? '&bull;&bull;&bull;&bull;'.esc_attr(substr($termii_key,-4)) : 'TLeqe…'?>"
                        autocomplete="new-password">
                    <small style="font-size:.72rem;color:var(--muted)">Get your key from
                        <a href="https://accounts.termii.com/#/api-keys" target="_blank">accounts.termii.com → API Keys</a>.
                        Sender IDs must be pre-registered with Termii — until approved, use <code>N-Alert</code>.
                        Leave blank to keep the current value.</small>
                </div>
            </div>

            <!-- Twilio -->
            <div class="bs-form-group bs-span2" style="background:#f0f4f8;border-radius:8px;padding:12px">
                <label style="color:#0d4a6e;font-weight:700">
                    Twilio
                    <?=($twilio_sid && $twilio_tok) ? '<span style="font-size:.75rem;color:#2a7a3b">&#10003; Configured</span>' : ''?>
                </label>
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin-top:6px">
                    <div>
                        <label style="font-size:.75rem;color:var(--muted)">Account SID</label>
                        <input type="text" name="bookshop_sms_twilio_sid" class="bs-input bs-setting"
                            value="<?=esc_attr($twilio_sid)?>" placeholder="AC…" autocomplete="off">
                    </div>
                    <div>
                        <label style="font-size:.75rem;color:var(--muted)">Auth Token <?=$twilio_tok ? '<span style="color:#2a7a3b">(saved)</span>' : ''?></label>
                        <input type="text" name="bookshop_sms_twilio_token" class="bs-input bs-setting"
                            value="" placeholder="<?=$twilio_tok ? '&bull;&bull;&bull;&bull;'.esc_attr(substr($twilio_tok,-4)) : 'auth token'?>"
                            autocomplete="new-password">
                    </div>
                    <div>
                        <label style="font-size:.75rem;color:var(--muted)">From Number</label>
                        <input type="text" name="bookshop_sms_twilio_from" class="bs-input bs-setting"
                            value="<?=esc_attr($twilio_from)?>" placeholder="+1234567890">
                        <small style="font-size:.72rem;color:var(--muted)">E.164 format with leading +</small>
                    </div>
                </div>
            </div>

            <div class="bs-form-group bs-span2">
                <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
                    <input type="text" id="bs-sms-test-to" class="bs-input"
                        style="flex:1;min-width:200px"
                        placeholder="recipient phone (e.g. 08012345678)">
                    <button class="bs-btn bs-btn-secondary" id="bs-sms-test" type="button">📲 Send Test SMS</button>
                </div>
                <small style="color:var(--muted);font-size:.75rem">
                    Save settings first, then send a test. The provider's response is shown below
                    so you can see what they actually said.
                </small>
                <?php if ($err): ?>
                <div style="margin-top:8px;padding:8px 10px;background:#f8d7da;color:#721c24;border-radius:6px;font-size:.78rem">
                    <strong>Last failure</strong> (<?=esc_html($err_at)?>): <?=esc_html($err)?>
                </div>
                <?php endif; ?>
                <?php if ($sent): ?>
                <div style="margin-top:6px;font-size:.75rem;color:var(--muted)">
                    Last successful send: <strong><?=esc_html($sent)?></strong>
                </div>
                <?php endif; ?>
                <div id="bs-sms-test-result" style="margin-top:8px;font-size:.83rem"></div>
            </div>
        </div>
    </div>
    <?php
}

// ── Reservation-ready trigger ─────────────────────────────────────────────────
// Fired from ajax-misc.php's bs_update_reservation handler when a reservation
// flips to 'notified'. Kept here (rather than inlined in ajax-misc) so the SMS
// copy lives next to the rest of the SMS code.
function bs_send_reservation_ready_sms($reservation) {
    if ( ! is_object($reservation)) return;
    if (empty($reservation->customer_phone)) return;
    if ( ! bs_sms_enabled()) return;

    $shop  = get_option('bookshop_receipt_header', get_bloginfo('name'));
    $title = $reservation->book_title ?: 'your reserved book';
    $name  = $reservation->customer_name ?: 'Hello';

    $body = sprintf(
        "Hi %s, %s here — \"%s\" is ready for collection. Drop in any time during opening hours.",
        $name,
        $shop,
        $title
    );

    bs_send_sms($reservation->customer_phone, $body, 'reservation_ready');
}

// ── Online-order status copy ──────────────────────────────────────────────────
// Called from bs_notify_online_order_status_change() in online-store.php.
// Returns the SMS body for a given status, or '' to suppress (we don't SMS
// for 'pending' since the customer just placed the order — they know).
function bs_sms_online_order_body($order, $status) {
    $shop = get_option('bookshop_receipt_header', get_bloginfo('name'));
    $ref  = $order->ref;
    $name = $order->customer_name ?: 'Hello';
    $type = ($order->type === 'delivery') ? 'delivery' : 'collection';

    switch ($status) {
        case 'paid':
            return "$shop: payment received for order $ref. We'll start preparing it shortly.";
        case 'processing':
            return "$shop: order $ref is being prepared. We'll text again when it's ready for $type.";
        case 'ready':
            return "$shop: order $ref is ready for $type. Reply to this thread or call us if you need anything.";
        case 'completed':
            return "$shop: order $ref is complete — thank you for shopping with us, $name.";
        case 'cancelled':
            return "$shop: order $ref has been cancelled. Reply or call us if this is unexpected.";
        case 'pending':
        default:
            return ''; // no-op
    }
}
