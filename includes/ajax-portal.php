<?php
/**
 * Customer Portal AJAX Handlers
 */
if(!defined('ABSPATH'))exit;

// ── Helper: set the portal-token cookie with maximum cross-host reliability ──
// Uses path=/ explicitly (avoids COOKIEPATH issues on subdir installs),
// SameSite=Lax (modern browser requirement), and detects HTTPS from home_url
// rather than is_ssl() to avoid mismatches behind reverse proxies.
function bs_portal_set_cookie($token, $expires){
    $secure = (parse_url(home_url(), PHP_URL_SCHEME) === 'https');
    if (PHP_VERSION_ID >= 70300) {
        setcookie('bs_portal_token', $token, [
            'expires'  => $expires,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $secure,
            'httponly' => false, // false so JS can also read/clear it as a fallback
            'samesite' => 'Lax',
        ]);
    } else {
        // PHP <7.3 fallback — use the path hack to set SameSite
        setcookie('bs_portal_token', $token, $expires, '/; SameSite=Lax', '', $secure, false);
    }
}

// ── Helper: read the auth token from the request ──────────────────────────────
// Checks POST['bsp_token'] first (so JS can pass it explicitly when cookies are
// unreliable), falls back to the cookie. This makes auth fully cookie-independent.
function bs_portal_get_request_token(){
    if (!empty($_POST['bsp_token'])) return sanitize_text_field($_POST['bsp_token']);
    if (!empty($_GET['bsp_token']))  return sanitize_text_field($_GET['bsp_token']);
    return $_COOKIE['bs_portal_token'] ?? '';
}

// ── Helper: resolve the logged-in customer id from the request token ──────────
function bs_portal_current_customer_id(){
    $token = bs_portal_get_request_token();
    return $token ? intval(get_transient('bs_portal_customer_'.$token)) : 0;
}

// ── OTP login — two-step: request_otp then verify_otp ─────────────────────────
//
// Why two steps: the previous one-step login let anyone who knew a customer's
// phone or email read their full purchase history, points, and reservations.
// Now possession of the contact channel is required.
//
// The helpers below are deliberately namespaced bs_portal_otp_* so PR D
// (staff/admin 2FA) can reuse them without dragging the portal-specific
// session/cookie code along — only generate / hash-verify / throttle / mask
// are reusable. The send-by-channel and find-customer parts are portal-only.

/**
 * Generate a 6-digit numeric code, leading zeros allowed.
 * 1M-space, paired with the 5-attempts-per-session cap and the 5-per-hour
 * request cap that gives an attacker ≤25 guesses/hr ≈ 0.0025% odds. Plenty.
 */
function bs_portal_otp_generate_code(){
    return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

/**
 * Hash an OTP for storage. We deliberately don't use wp_hash_password (bcrypt)
 * because that's optimised for ≥8-char passwords reused across logins; for
 * a 6-digit code that lives ≤10 minutes, HMAC-SHA256 keyed on wp_salt() is
 * just as constant-time-comparable via hash_equals() and ~1000× cheaper.
 */
function bs_portal_otp_hash($code){
    return hash_hmac('sha256', (string) $code, wp_salt('auth'));
}

/**
 * Per-account throttle for OTP requests. Two limits, both enforced server-side
 * (the JS cooldown is purely cosmetic — assume the attacker drives the API
 * directly):
 *   - 30 seconds between requests for the same account (anti-spam: an
 *     attacker who knows the victim's email can't endlessly text/email them)
 *   - 5 requests per hour for the same account (rate limit)
 * Returns ['ok'=>true] or ['ok'=>false, 'error'=>str].
 */
function bs_portal_otp_throttle_check($customer_id){
    $cd_key = 'bs_portal_otp_cooldown_'.intval($customer_id);
    $ct_key = 'bs_portal_otp_count_'.intval($customer_id);

    if(get_transient($cd_key)){
        return ['ok' => false, 'error' => 'Please wait a few seconds before requesting another code.'];
    }
    $count = intval(get_transient($ct_key));
    if($count >= 5){
        return ['ok' => false, 'error' => 'Too many sign-in attempts in the past hour. Please try again later or ask staff for help.'];
    }
    // Record this request *before* sending — even if the send itself fails,
    // we still throttle. Otherwise an attacker could trigger unlimited sends
    // by intentionally triggering provider failures.
    set_transient($cd_key, 1, 30);
    set_transient($ct_key, $count + 1, HOUR_IN_SECONDS);
    return ['ok' => true];
}

/**
 * Mask an email so the OTP entry screen can confirm where the code went
 * without disclosing the full address (in case the user is on a shared
 * device, or another customer is shoulder-surfing).
 *   "john.doe@example.com" -> "j*******@example.com"
 */
function bs_portal_mask_email($email){
    $email = trim((string) $email);
    if(strpos($email, '@') === false) return '';
    list($local, $domain) = explode('@', $email, 2);
    $first = mb_substr($local, 0, 1);
    return $first . str_repeat('*', max(3, mb_strlen($local) - 1)) . '@' . $domain;
}

/**
 * Mask a phone — keep last 4 digits.
 *   "+2348012345678" -> "*******5678"
 */
function bs_portal_mask_phone($phone){
    $digits = preg_replace('/\D+/', '', (string) $phone);
    if(strlen($digits) < 4) return '';
    $tail    = substr($digits, -4);
    $headLen = max(0, strlen($digits) - 4);
    return str_repeat('*', min(7, $headLen)) . $tail;
}

/**
 * Email body for the OTP. Mirrors the cream/gold palette used by the rest of
 * the customer-facing emails (order-status, bulk messaging) so it doesn't
 * look like a separate system. Plain-text fallback is provided via
 * Content-Type negotiation done by wp_mail's HTML headers.
 */
function bs_portal_send_otp_email($customer, $code){
    if(empty($customer->email) || !is_email($customer->email)) return false;
    $shop = get_option('bookshop_receipt_header', get_bloginfo('name'));
    $name = $customer->name ?: 'there';

    $subject = "[$shop] Your sign-in code: $code";
    $html = "<div style=\"font-family:Georgia,serif;max-width:480px;margin:auto;background:#fdf8f0;padding:32px;border-radius:10px;color:#1a1208\">
        <h2 style=\"margin:0 0 4px;font-family:'Playfair Display',Georgia,serif\">".esc_html($shop)."</h2>
        <p style='margin:0 0 18px;color:#8a7a65;font-size:.9em'>Sign-in code for your account</p>
        <p style='margin:0 0 8px'>Hello ".esc_html($name).",</p>
        <p style='margin:0 0 12px'>Use the code below to sign in to your account:</p>
        <div style=\"font-size:2.2em;font-weight:700;letter-spacing:.18em;text-align:center;background:#fff;border:2px solid #f5d87a;border-radius:8px;padding:18px;margin:18px 0;font-family:Menlo,Consolas,monospace;color:#1a1208\">".esc_html($code)."</div>
        <p style='margin:0 0 10px;font-size:.86em;color:#5d4a00'>This code expires in 10 minutes.</p>
        <p style='margin:0;font-size:.82em;color:#8a7a65'>If you didn't try to sign in, you can ignore this email — no action is needed and your account is safe.</p>
    </div>";

    return wp_mail(
        $customer->email,
        $subject,
        $html,
        ['Content-Type: text/html; charset=UTF-8']
    );
}

/**
 * SMS body for the OTP. Kept short — providers split at 160 chars but the
 * sender ID + retry overhead means short messages cost less and arrive faster.
 * Returns the bs_send_sms result array so the caller can surface the provider
 * error verbatim if delivery fails.
 */
function bs_portal_send_otp_sms($customer, $code){
    if(empty($customer->phone)) return ['ok' => false, 'error' => 'No phone on file'];
    if(!function_exists('bs_send_sms') || !function_exists('bs_sms_enabled') || !bs_sms_enabled()){
        return ['ok' => false, 'error' => 'SMS not configured'];
    }
    $shop = get_option('bookshop_receipt_header', get_bloginfo('name'));
    $body = "[$shop] Your sign-in code is $code. Expires in 10 minutes.";
    return bs_send_sms($customer->phone, $body, 'portal_otp');
}

/**
 * Decide which channel to send the OTP through, based on what the user typed
 * and what's available. Single-channel design — no per-login picker — so the
 * UX stays one-click.
 *
 *   identifier looks like an email → email channel
 *   identifier looks like a phone:
 *     - SMS enabled: SMS
 *     - else customer has email on file: email (with a note in the response)
 *     - else: error (account exists but we have no way to reach you)
 *
 * Returns ['channel'=>'email'|'sms', 'destination'=>masked, 'note'=>optional]
 * or ['error'=>str].
 */
function bs_portal_otp_pick_channel($customer, $identifier){
    $is_email = (strpos($identifier, '@') !== false);
    $sms_on   = function_exists('bs_sms_enabled') && bs_sms_enabled();

    if($is_email){
        if(empty($customer->email)) return ['error' => 'This account has no email on file. Please sign in with your phone number instead.'];
        return ['channel' => 'email', 'destination' => bs_portal_mask_email($customer->email)];
    }

    // Identifier looks like a phone.
    if($sms_on && !empty($customer->phone)){
        return ['channel' => 'sms', 'destination' => bs_portal_mask_phone($customer->phone)];
    }
    if(!empty($customer->email)){
        return [
            'channel'     => 'email',
            'destination' => bs_portal_mask_email($customer->email),
            'note'        => 'SMS isn\'t set up here yet, so we sent the code to your email instead.',
        ];
    }
    return ['error' => 'This account has no email or SMS-capable phone on file. Please ask staff to update your contact details.'];
}

// ── Step 1: request OTP ───────────────────────────────────────────────────────
add_action('wp_ajax_nopriv_bs_portal_request_otp','bs_portal_request_otp_handler');
add_action('wp_ajax_bs_portal_request_otp','bs_portal_request_otp_handler');
function bs_portal_request_otp_handler(){
    if(!check_ajax_referer('bs_portal_nonce','nonce',false)) wp_send_json_error('Invalid request');
    global $wpdb;

    $identifier = trim(sanitize_text_field($_POST['identifier'] ?? ''));
    if($identifier === '') wp_send_json_error('Please enter your phone or email');

    // Same lookup as the previous one-step login: phone (exact) or email
    // (case-insensitive). The active-status filter prevents archived
    // customers from logging in.
    $customer = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}bookshop_customers
         WHERE status='active' AND (phone=%s OR email=%s OR LOWER(email)=LOWER(%s)) LIMIT 1",
        $identifier, $identifier, $identifier
    ));
    if(!$customer){
        // Tradeoff: we could return a generic "if your account exists, we
        // sent a code" to prevent enumeration. For a single-shop bookshop
        // where staff register customers in-store, the user-friendliness
        // of a clear "we don't know that contact" wins — staff can fix it
        // on the spot. Reconsider if we ever support self-signup.
        wp_send_json_error('No account found with that phone or email. Please ask staff to register you in-store.');
    }

    $pick = bs_portal_otp_pick_channel($customer, $identifier);
    if(isset($pick['error'])) wp_send_json_error($pick['error']);

    $throttle = bs_portal_otp_throttle_check(intval($customer->id));
    if(!$throttle['ok']) wp_send_json_error($throttle['error']);

    // Generate code and store the hashed-and-context version. The customer_id
    // is the only piece tying the OTP back to a real account on verify, so
    // possessing the otp_id alone is useless without the code.
    $code = bs_portal_otp_generate_code();

    // Invalidate any previous outstanding OTP for this customer — single-OTP-
    // at-a-time means an attacker who triggered an OTP send can't ride on a
    // forgotten code from earlier in the day.
    $prev_otp_id = get_transient('bs_portal_latest_otp_'.$customer->id);
    if($prev_otp_id) delete_transient('bs_portal_otp_'.$prev_otp_id);

    $otp_id = bin2hex(random_bytes(16));
    $payload = [
        'customer_id' => intval($customer->id),
        'hash'        => bs_portal_otp_hash($code),
        'channel'     => $pick['channel'],
        'destination' => $pick['destination'],
        'attempts'    => 0,
        // Tracked separately from the transient TTL because each verify
        // refreshes the transient (we update attempts++) and we don't want
        // failed-but-fast guessing to extend the validity window.
        'expires_at'  => time() + 10 * MINUTE_IN_SECONDS,
    ];
    set_transient('bs_portal_otp_'.$otp_id, $payload, 10 * MINUTE_IN_SECONDS);
    set_transient('bs_portal_latest_otp_'.$customer->id, $otp_id, 10 * MINUTE_IN_SECONDS);

    // Deliver the code. On send failure we delete the OTP so the user can
    // retry without burning attempts on a code that never reached them.
    if($pick['channel'] === 'email'){
        $sent = bs_portal_send_otp_email($customer, $code);
        if(!$sent){
            delete_transient('bs_portal_otp_'.$otp_id);
            delete_transient('bs_portal_latest_otp_'.$customer->id);
            wp_send_json_error('Could not send the code by email. Please try again or ask staff for help.');
        }
    } else {
        $res = bs_portal_send_otp_sms($customer, $code);
        if(empty($res['ok'])){
            delete_transient('bs_portal_otp_'.$otp_id);
            delete_transient('bs_portal_latest_otp_'.$customer->id);
            // The SMS module already logs the provider error to
            // bookshop_last_sms_error for the admin; show the customer
            // a friendlier message. If they have email on file, suggest it.
            $msg = 'Could not send the code by SMS.';
            if(!empty($customer->email)){
                $msg .= ' Try signing in with your email address instead.';
            } else {
                $msg .= ' Please ask staff for help.';
            }
            wp_send_json_error($msg);
        }
    }

    wp_send_json_success([
        'otp_id'      => $otp_id,
        'channel'     => $pick['channel'],
        'destination' => $pick['destination'],
        'note'        => $pick['note'] ?? '',
        'expires_in'  => 10 * MINUTE_IN_SECONDS,
    ]);
}

// ── Step 2: verify OTP ────────────────────────────────────────────────────────
add_action('wp_ajax_nopriv_bs_portal_verify_otp','bs_portal_verify_otp_handler');
add_action('wp_ajax_bs_portal_verify_otp','bs_portal_verify_otp_handler');
function bs_portal_verify_otp_handler(){
    if(!check_ajax_referer('bs_portal_nonce','nonce',false)) wp_send_json_error('Invalid request');

    $otp_id = sanitize_text_field($_POST['otp_id'] ?? '');
    $code   = preg_replace('/\D+/', '', (string) ($_POST['code'] ?? ''));
    if($otp_id === '' || $code === '') wp_send_json_error('Please enter the 6-digit code from your email or SMS.');

    $key  = 'bs_portal_otp_'.$otp_id;
    $sess = get_transient($key);
    if(!is_array($sess)){
        wp_send_json_error('This sign-in code has expired. Please request a new one.');
    }
    if(time() > intval($sess['expires_at'] ?? 0)){
        delete_transient($key);
        wp_send_json_error('This sign-in code has expired. Please request a new one.');
    }
    if(intval($sess['attempts']) >= 5){
        delete_transient($key);
        wp_send_json_error('Too many incorrect attempts. Please request a new code.');
    }

    // Increment attempts BEFORE comparing — if the verify path crashes for any
    // reason, the attempt still counts. A retry-on-wrong-guess attacker can
    // never get more than 5 cracks per OTP.
    $sess['attempts'] = intval($sess['attempts']) + 1;
    set_transient($key, $sess, 10 * MINUTE_IN_SECONDS);

    if(!hash_equals($sess['hash'], bs_portal_otp_hash($code))){
        $remaining = max(0, 5 - $sess['attempts']);
        wp_send_json_error('Incorrect code. ' . ($remaining > 0
            ? $remaining . ' attempt' . ($remaining === 1 ? '' : 's') . ' remaining.'
            : 'Please request a new code.'));
    }

    // Verified. Clean up and issue a portal session — same shape as the old
    // one-step login so the JS swap-into-dashboard flow keeps working.
    delete_transient($key);
    delete_transient('bs_portal_latest_otp_'.$sess['customer_id']);

    $customer = bs_get_customer(intval($sess['customer_id']));
    if(!$customer) wp_send_json_error('Account not found. Please ask staff for help.');

    $token = bin2hex(random_bytes(16));
    set_transient('bs_portal_customer_'.$token, $customer->id, HOUR_IN_SECONDS * 8);
    bs_portal_set_cookie($token, time() + HOUR_IN_SECONDS * 8);

    ob_start();
    bs_render_portal_dashboard($customer);
    $dashboard_html = ob_get_clean();

    wp_send_json_success([
        'name'  => $customer->name,
        'id'    => $customer->id,
        'token' => $token,
        'html'  => $dashboard_html,
    ]);
}

// ── Logout ────────────────────────────────────────────────────────────────────
add_action('wp_ajax_nopriv_bs_portal_logout','bs_portal_logout_handler');
add_action('wp_ajax_bs_portal_logout','bs_portal_logout_handler');
function bs_portal_logout_handler(){
    $token = bs_portal_get_request_token();
    if($token) delete_transient('bs_portal_customer_'.$token);
    bs_portal_set_cookie('', time()-3600);
    wp_send_json_success();
}

// ── Get customer data (AJAX refresh) ─────────────────────────────────────────
add_action('wp_ajax_nopriv_bs_portal_get_data','bs_portal_get_data_handler');
add_action('wp_ajax_bs_portal_get_data','bs_portal_get_data_handler');
function bs_portal_get_data_handler(){
    if(!check_ajax_referer('bs_portal_nonce','nonce',false)) wp_send_json_error('Invalid request');
    $cid = bs_portal_current_customer_id();
    if(!$cid) wp_send_json_error('Not logged in');
    global $wpdb;
    $customer=bs_get_customer($cid);
    if(!$customer) wp_send_json_error('Account not found');
    $tier=bs_get_customer_tier($cid);
    $loy_val=floatval(get_option('bookshop_loyalty_value',10));
    $sales=bs_get_sales(['customer_id'=>$cid,'limit'=>50]);
    $reservations=$wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}bookshop_reservations
         WHERE customer_email=%s OR customer_phone=%s
         ORDER BY created_at DESC LIMIT 20",
        $customer->email,$customer->phone
    ));
    $loy_log=bs_get_loyalty_log($cid);
    $total_spent=floatval($wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(SUM(total),0) FROM {$wpdb->prefix}bookshop_sales
         WHERE customer_id=%d AND status='completed'",$cid
    )));
    $tiers=bs_get_tiers();
    $tier_keys=array_keys($tiers);
    $current_idx=array_search($tier['key'],$tier_keys);
    $next_tier=isset($tier_keys[$current_idx+1])?$tiers[$tier_keys[$current_idx+1]]:null;
    $progress=0;
    if($next_tier){
        $range=$next_tier['min_spend']-$tier['min_spend'];
        $done=$total_spent-$tier['min_spend'];
        $progress=$range>0?min(100,round(($done/$range)*100)):100;
    }
    // Enrich sales with items
    $sales_enriched=[];
    foreach($sales as $s){
        $s->items=bs_get_sale_items($s->id);
        $sales_enriched[]=$s;
    }
    wp_send_json_success([
        'customer'     =>$customer,
        'tier'         =>$tier,
        'total_spent'  =>$total_spent,
        'next_tier'    =>$next_tier,
        'tier_progress'=>$progress,
        'loyalty_value'=>$loy_val,
        'sales'        =>$sales_enriched,
        'reservations' =>$reservations,
        'loyalty_log'  =>$loy_log,
        'currency'     =>bs_currency(),
    ]);
}

// ── Get full dashboard HTML (used after page refresh when cookie was lost) ──
add_action('wp_ajax_nopriv_bs_portal_get_dashboard_html','bs_portal_get_dashboard_html_handler');
add_action('wp_ajax_bs_portal_get_dashboard_html','bs_portal_get_dashboard_html_handler');
function bs_portal_get_dashboard_html_handler(){
    if(!check_ajax_referer('bs_portal_nonce','nonce',false)) wp_send_json_error('Invalid request');
    $cid = bs_portal_current_customer_id();
    if(!$cid) wp_send_json_error('Not logged in');
    $customer = bs_get_customer($cid);
    if(!$customer) wp_send_json_error('Account not found');
    ob_start();
    bs_render_portal_dashboard($customer);
    $html = ob_get_clean();
    wp_send_json_success([
        'name' => $customer->name,
        'html' => $html,
    ]);
}

// ── Update profile ─────────────────────────────────────────────────────────────
add_action('wp_ajax_nopriv_bs_portal_update_profile','bs_portal_update_profile_handler');
add_action('wp_ajax_bs_portal_update_profile','bs_portal_update_profile_handler');
function bs_portal_update_profile_handler(){
    if(!check_ajax_referer('bs_portal_nonce','nonce',false)) wp_send_json_error('Invalid request');
    $cid = bs_portal_current_customer_id();
    if(!$cid) wp_send_json_error('Not logged in');
    $name=sanitize_text_field($_POST['name']??'');
    if(empty($name)) wp_send_json_error('Name is required');
    bs_save_customer([
        'name'    =>$name,
        'email'   =>sanitize_email($_POST['email']??''),
        'phone'   =>sanitize_text_field($_POST['phone']??''),
        'address' =>sanitize_textarea_field($_POST['address']??''),
        'birthday'=>sanitize_text_field($_POST['birthday']??''),
        'status'  =>'active',
    ],$cid);
    wp_send_json_success(['message'=>'Profile updated successfully!']);
}

// ── Submit reservation from portal ────────────────────────────────────────────
add_action('wp_ajax_nopriv_bs_portal_reserve','bs_portal_reserve_handler');
add_action('wp_ajax_bs_portal_reserve','bs_portal_reserve_handler');
function bs_portal_reserve_handler(){
    if(!check_ajax_referer('bs_portal_nonce','nonce',false)) wp_send_json_error('Invalid request');
    $cid = bs_portal_current_customer_id();
    if(!$cid) wp_send_json_error('Not logged in');
    $customer=bs_get_customer($cid);
    if(!$customer) wp_send_json_error('Account not found');
    $title=sanitize_text_field($_POST['title']??'');
    if(empty($title)) wp_send_json_error('Book title is required');
    global $wpdb;
    $wpdb->insert("{$wpdb->prefix}bookshop_reservations",[
        'customer_name' =>$customer->name,
        'customer_email'=>$customer->email,
        'customer_phone'=>$customer->phone,
        'book_title'    =>$title,
        'isbn'          =>sanitize_text_field($_POST['isbn']??''),
        'qty'           =>intval($_POST['qty']??1),
        'notes'         =>sanitize_textarea_field($_POST['notes']??''),
        'status'        =>'pending',
    ]);
    bs_send_reservation_notification([
        'name'=>$customer->name,'email'=>$customer->email,'phone'=>$customer->phone,
        'book_title'=>$title,'isbn'=>$_POST['isbn']??'','qty'=>$_POST['qty']??1,
    ]);
    wp_send_json_success(['message'=>'Reservation submitted! We\'ll contact you when your book is ready.']);
}
