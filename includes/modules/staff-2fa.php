<?php
/**
 * Two-factor authentication for WordPress logins.
 *
 * Adds an OTP challenge step between password validation and auth-cookie issue
 * for any WP user who's opted in. Reuses the OTP code generation / hashing /
 * masking helpers from the customer portal (PR C) — both flows want the same
 * primitives:
 *   - 6-digit code, leading zeros allowed
 *   - HMAC-SHA256 storage (not bcrypt — see ajax-portal.php for rationale)
 *   - email-or-SMS delivery
 *   - 5-attempts-per-session, 10-minute expiry, hash_equals comparison
 *
 * What this PR deliberately does NOT add:
 *   - TOTP / authenticator-app support (out of scope; a future PR can add a
 *     third channel keyed off this module's pick-channel logic)
 *   - Per-device trust list management UI (one trust cookie is enough for v1;
 *     admins can disable a user's 2FA from the user-edit page if their
 *     trusted device is lost)
 *   - Backup / recovery codes (admins recover locked-out users via the user-
 *     edit page or wp-cli, which is acceptable for a small bookshop)
 *
 * Storage:
 *   user_meta bs_2fa_enabled    '1' if 2FA is required at login
 *   user_meta bs_2fa_method     'email' (default) or 'sms'
 *   user_meta bs_2fa_phone      digits for SMS delivery (only used when
 *                               method='sms'; doesn't replace WP's phone field)
 *
 * Cookies:
 *   bs_2fa_trust                "<uid>|<expires>|<hmac>" — set after a
 *                               successful challenge if the user ticked
 *                               "trust this device"; checked first on next
 *                               login to skip the OTP step
 *
 *   bs_2fa_pending              transient key — set by the authenticate
 *                               filter, consumed by the challenge page
 */
if ( ! defined('ABSPATH') ) exit;

// Trust cookie lifetime. 30 days matches what most consumer-facing 2FA UIs
// offer; shorter would be safer but more annoying for daily POS staff.
if ( ! defined('BS_2FA_TRUST_DAYS') ) define('BS_2FA_TRUST_DAYS', 30);

// ── Capability gate: only show the 2FA section to users who actually log in ──
// Customer-portal users don't go through wp-login.php; gating by POS/manage
// caps keeps the section invisible for them. Note: we can't gate ALL behaviour
// by this — wp_authenticate_user fires before caps are checked, so login
// interception has its own per-user check below.
function bs_2fa_user_eligible($user_id){
    return user_can($user_id, 'bookshop_pos') || user_can($user_id, 'manage_options');
}

function bs_2fa_enabled_for($user_id){
    return get_user_meta($user_id, 'bs_2fa_enabled', true) === '1';
}

// ── Channel selection ────────────────────────────────────────────────────────
// Mirrors bs_portal_otp_pick_channel from ajax-portal.php conceptually but
// keyed off user_meta rather than the typed identifier. Returns the same
// shape: ['channel' => 'email'|'sms', 'destination' => masked, 'note' => str?]
// or ['error' => str].
function bs_2fa_pick_channel($user){
    $method = get_user_meta($user->ID, 'bs_2fa_method', true) ?: 'email';
    $phone  = trim((string) get_user_meta($user->ID, 'bs_2fa_phone', true));

    if ($method === 'sms') {
        $sms_on = function_exists('bs_sms_enabled') && bs_sms_enabled();
        if ($sms_on && $phone !== '') {
            return [
                'channel'     => 'sms',
                'destination' => bs_portal_mask_phone($phone),
                'phone'       => $phone,
            ];
        }
        // SMS misconfigured at site or user level. Fall back to email rather
        // than locking the user out — they explicitly opted into 2FA, so
        // delivering by email is better than refusing the login.
        if ( ! empty($user->user_email) && is_email($user->user_email)) {
            return [
                'channel'     => 'email',
                'destination' => bs_portal_mask_email($user->user_email),
                'note'        => $sms_on
                    ? 'No phone configured for 2FA — sent to your email instead.'
                    : 'SMS not configured site-wide — sent to your email instead.',
            ];
        }
        return ['error' => 'No way to deliver the sign-in code. Ask the site admin to update your contact details.'];
    }

    // Email method (default).
    if ( empty($user->user_email) || ! is_email($user->user_email)) {
        return ['error' => 'No email on file for this account. Ask the site admin to set one before enabling 2FA.'];
    }
    return [
        'channel'     => 'email',
        'destination' => bs_portal_mask_email($user->user_email),
    ];
}

// ── Send code via the chosen channel ─────────────────────────────────────────
// Returns true on dispatch success, false (or array on SMS error) otherwise.
// Caller is responsible for surfacing failure to the user.
function bs_2fa_send_code($user, $code, $pick){
    $shop = get_option('bookshop_receipt_header', get_bloginfo('name'));
    $name = $user->display_name ?: $user->user_login;

    if ($pick['channel'] === 'sms') {
        if ( ! function_exists('bs_send_sms')) return false;
        $body = "[$shop] Admin sign-in code: $code. Expires in 10 minutes. If this wasn't you, change your password immediately.";
        $res  = bs_send_sms($pick['phone'], $body, 'staff_2fa');
        return ! empty($res['ok']);
    }

    // Email. Same palette as the portal OTP email, but the wording reflects
    // that this is an admin/staff sign-in (not a customer login) — important
    // because if the user gets one of these unexpectedly, that's a strong
    // signal someone's trying to hijack their staff account.
    $subject = "[$shop] Admin sign-in code: $code";
    $html = "<div style=\"font-family:Georgia,serif;max-width:480px;margin:auto;background:#fdf8f0;padding:32px;border-radius:10px;color:#1a1208\">
        <h2 style=\"margin:0 0 4px;font-family:'Playfair Display',Georgia,serif\">".esc_html($shop)."</h2>
        <p style='margin:0 0 18px;color:#8a7a65;font-size:.9em'>Admin sign-in two-factor code</p>
        <p style='margin:0 0 8px'>Hello ".esc_html($name).",</p>
        <p style='margin:0 0 12px'>Use the code below to finish signing in to the admin panel:</p>
        <div style=\"font-size:2.2em;font-weight:700;letter-spacing:.18em;text-align:center;background:#fff;border:2px solid #f5d87a;border-radius:8px;padding:18px;margin:18px 0;font-family:Menlo,Consolas,monospace;color:#1a1208\">".esc_html($code)."</div>
        <p style='margin:0 0 10px;font-size:.86em;color:#5d4a00'>This code expires in 10 minutes.</p>
        <p style='margin:0;font-size:.82em;color:#c0392b'><strong>If you didn't try to sign in, your password may have been compromised — change it immediately.</strong></p>
    </div>";

    return wp_mail($user->user_email, $subject, $html, ['Content-Type: text/html; charset=UTF-8']);
}

// ── Trust-this-device cookie ─────────────────────────────────────────────────
// Signed HMAC cookie so the server can verify the user's "remember this
// device for 30 days" choice without server-side state per device. The
// signature includes the user_id and the expiry, so swapping either
// invalidates the cookie. We use wp_salt('auth') as the signing key to
// inherit the same secret rotation policy as WP's auth cookies — when an
// admin rotates salts, every trust cookie is invalidated for free.

function bs_2fa_trust_cookie_value($user_id, $expires){
    $sig = hash_hmac('sha256', $user_id . '|' . $expires, wp_salt('auth') . '|bs_2fa_trust');
    return $user_id . '|' . $expires . '|' . $sig;
}

function bs_2fa_set_trust_cookie($user_id){
    $expires = time() + BS_2FA_TRUST_DAYS * DAY_IN_SECONDS;
    $value   = bs_2fa_trust_cookie_value($user_id, $expires);
    $secure  = (parse_url(home_url(), PHP_URL_SCHEME) === 'https');
    if (PHP_VERSION_ID >= 70300) {
        setcookie('bs_2fa_trust', $value, [
            'expires'  => $expires,
            'path'     => COOKIEPATH ?: '/',
            'domain'   => COOKIE_DOMAIN ?: '',
            'secure'   => $secure,
            'httponly' => true, // unlike the portal token, no JS needs this
            'samesite' => 'Lax',
        ]);
    } else {
        setcookie('bs_2fa_trust', $value, $expires, COOKIEPATH ?: '/', COOKIE_DOMAIN ?: '', $secure, true);
    }
}

function bs_2fa_clear_trust_cookie(){
    setcookie('bs_2fa_trust', '', time() - 3600, COOKIEPATH ?: '/', COOKIE_DOMAIN ?: '');
}

function bs_2fa_is_trusted($user_id){
    $cookie = $_COOKIE['bs_2fa_trust'] ?? '';
    if ( ! $cookie) return false;
    $parts = explode('|', $cookie, 3);
    if (count($parts) !== 3) return false;
    list($cookie_uid, $expires, $sig) = $parts;
    if ((int) $cookie_uid !== (int) $user_id)  return false;
    if ((int) $expires    < time())             return false;
    $expected = hash_hmac('sha256', $cookie_uid . '|' . $expires, wp_salt('auth') . '|bs_2fa_trust');
    return hash_equals($expected, $sig);
}

// ── User-profile UI ───────────────────────────────────────────────────────────
// Hook both `show_user_profile` (own profile) and `edit_user_profile` (admin
// editing someone else) so admins can view and override settings — including
// disabling 2FA on a locked-out staff member.
add_action('show_user_profile', 'bs_2fa_render_profile_section');
add_action('edit_user_profile', 'bs_2fa_render_profile_section');
function bs_2fa_render_profile_section($user){
    if ( ! bs_2fa_user_eligible($user->ID)) return;

    $enabled = get_user_meta($user->ID, 'bs_2fa_enabled', true) === '1';
    $method  = get_user_meta($user->ID, 'bs_2fa_method',  true) ?: 'email';
    $phone   = (string) get_user_meta($user->ID, 'bs_2fa_phone', true);

    $sms_on = function_exists('bs_sms_enabled') && bs_sms_enabled();
    ?>
    <h2>Two-Factor Authentication</h2>
    <p style="color:#646970;font-size:13px;max-width:680px">
        When enabled, signing in to <strong>this site's admin/POS</strong> requires a 6-digit
        code sent to your email or phone in addition to your password. This protects
        your account if your password is ever leaked or guessed. Customer portal logins
        already have their own OTP step and aren't affected.
    </p>

    <table class="form-table" role="presentation">
        <tr>
            <th><label for="bs_2fa_enabled">Enable 2FA</label></th>
            <td>
                <label style="display:inline-flex;align-items:center;gap:8px;cursor:pointer">
                    <input type="hidden" name="bs_2fa_enabled" value="0">
                    <input type="checkbox" name="bs_2fa_enabled" id="bs_2fa_enabled" value="1" <?php checked($enabled); ?>>
                    <span>Require a sign-in code at every login</span>
                </label>
                <?php if ($enabled): ?>
                <p class="description" style="color:#2a7a3b;margin-top:6px">
                    &#10003; 2FA is currently active on this account.
                </p>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <th><label for="bs_2fa_method">Delivery method</label></th>
            <td>
                <label style="display:block;margin-bottom:6px">
                    <input type="radio" name="bs_2fa_method" value="email" <?php checked($method, 'email'); ?>>
                    Email <code style="color:#646970"><?=esc_html($user->user_email ?: '(no email on file)')?></code>
                </label>
                <label style="display:block">
                    <input type="radio" name="bs_2fa_method" value="sms" <?php checked($method, 'sms'); ?>
                        <?=$sms_on ? '' : 'disabled'?>>
                    SMS
                    <?php if ( ! $sms_on): ?>
                        <em style="color:#646970;font-size:12px">(SMS isn't configured for this site — see Bookshop &rarr; Settings &rarr; SMS Delivery)</em>
                    <?php endif; ?>
                </label>
            </td>
        </tr>
        <tr>
            <th><label for="bs_2fa_phone">Phone for SMS</label></th>
            <td>
                <input type="tel" name="bs_2fa_phone" id="bs_2fa_phone"
                    value="<?=esc_attr($phone)?>"
                    class="regular-text"
                    placeholder="e.g. 08012345678"
                    <?=$method === 'sms' ? '' : ''?>>
                <p class="description">
                    Used only for 2FA delivery. Doesn't replace any other phone number on your account.
                </p>
            </td>
        </tr>
        <tr>
            <th>Test delivery</th>
            <td>
                <button type="button" class="button" id="bs-2fa-test-btn"
                    data-user-id="<?=esc_attr($user->ID)?>"
                    data-nonce="<?=esc_attr(wp_create_nonce('bs_2fa_test_'.$user->ID))?>">
                    Send a test code
                </button>
                <span id="bs-2fa-test-result" style="margin-left:10px;font-size:13px"></span>
                <p class="description">
                    Recommended before enabling 2FA — saves you from being locked out by
                    a typo'd phone number or a misconfigured email server.
                </p>
            </td>
        </tr>
    </table>

    <script>
    (function($){
        $('#bs-2fa-test-btn').on('click', function(){
            // Send the CURRENT form values (not just what's saved) so the user
            // can validate before they save. The test endpoint reads from
            // $_POST first, falling back to user_meta only if the form fields
            // weren't supplied.
            var $btn = $(this);
            var nonce = $btn.data('nonce');
            var uid   = $btn.data('user-id');
            var method = $('input[name="bs_2fa_method"]:checked').val() || 'email';
            var phone  = $('#bs_2fa_phone').val() || '';
            var $out   = $('#bs-2fa-test-result').text('Sending…').css('color','#646970');
            $btn.prop('disabled', true);
            $.post(ajaxurl, {
                action:    'bs_2fa_test',
                _wpnonce:  nonce,
                user_id:   uid,
                method:    method,
                phone:     phone,
            }).done(function(res){
                $btn.prop('disabled', false);
                if (res && res.success) {
                    $out.text('\u2713 ' + (res.data.message || 'Sent.')).css('color','#2a7a3b');
                } else {
                    $out.text('\u2717 ' + ((res && res.data) ? res.data : 'Send failed.')).css('color','#c0392b');
                }
            }).fail(function(xhr){
                $btn.prop('disabled', false);
                $out.text('\u2717 Network error (' + xhr.status + ')').css('color','#c0392b');
            });
        });
    })(jQuery);
    </script>
    <?php
}

add_action('personal_options_update', 'bs_2fa_save_profile_section');
add_action('edit_user_profile_update', 'bs_2fa_save_profile_section');
function bs_2fa_save_profile_section($user_id){
    if ( ! bs_2fa_user_eligible($user_id)) return;
    // WP enforces edit_user cap on the profile.php save path; double-checking
    // here is belt-and-braces.
    if ( ! current_user_can('edit_user', $user_id)) return;

    $enabled = isset($_POST['bs_2fa_enabled']) && $_POST['bs_2fa_enabled'] === '1' ? '1' : '0';
    $method  = isset($_POST['bs_2fa_method']) && in_array($_POST['bs_2fa_method'], ['email','sms'], true)
             ? $_POST['bs_2fa_method'] : 'email';
    $phone   = isset($_POST['bs_2fa_phone']) ? preg_replace('/[^\d+]/', '', $_POST['bs_2fa_phone']) : '';

    update_user_meta($user_id, 'bs_2fa_enabled', $enabled);
    update_user_meta($user_id, 'bs_2fa_method',  $method);
    update_user_meta($user_id, 'bs_2fa_phone',   $phone);

    // If 2FA was just disabled, drop any trust cookie so a future re-enable
    // starts fresh — otherwise the old cookie would auto-bypass the next
    // challenge. (For self-edit; admin-edit can't clear another user's cookie.)
    if ($enabled !== '1' && get_current_user_id() === intval($user_id)) {
        bs_2fa_clear_trust_cookie();
    }
}

// ── AJAX: send test code ─────────────────────────────────────────────────────
add_action('wp_ajax_bs_2fa_test', 'bs_2fa_test_handler');
function bs_2fa_test_handler(){
    $uid = intval($_POST['user_id'] ?? 0);
    if ( ! $uid) wp_send_json_error('Missing user');
    if ( ! current_user_can('edit_user', $uid)) wp_send_json_error('Unauthorized', 403);
    if ( ! check_ajax_referer('bs_2fa_test_'.$uid, '_wpnonce', false)) wp_send_json_error('Bad nonce');

    $user = get_user_by('id', $uid);
    if ( ! $user) wp_send_json_error('User not found');

    // Build a temporary "pick" from the form values rather than user_meta so
    // an admin can validate a config BEFORE clicking Save. Falls back to
    // saved meta if the field wasn't supplied.
    $method = $_POST['method'] ?? get_user_meta($uid, 'bs_2fa_method', true) ?: 'email';
    $phone  = $_POST['phone']  ?? get_user_meta($uid, 'bs_2fa_phone',  true) ?: '';
    $phone  = preg_replace('/[^\d+]/', '', (string) $phone);

    if ($method === 'sms') {
        if ( ! function_exists('bs_sms_enabled') || ! bs_sms_enabled()) {
            wp_send_json_error('SMS isn\'t configured site-wide — go to Bookshop → Settings → SMS Delivery first.');
        }
        if ($phone === '') wp_send_json_error('Enter a phone number first.');
        $pick = ['channel' => 'sms', 'destination' => bs_portal_mask_phone($phone), 'phone' => $phone];
    } else {
        if (empty($user->user_email)) wp_send_json_error('This account has no email address on file.');
        $pick = ['channel' => 'email', 'destination' => bs_portal_mask_email($user->user_email)];
    }

    $code = bs_portal_otp_generate_code();
    $ok   = bs_2fa_send_code($user, $code, $pick);
    if ($ok) {
        wp_send_json_success([
            'message' => 'Test code sent by ' . ($pick['channel'] === 'sms' ? 'SMS' : 'email')
                       . ' to ' . $pick['destination']
                       . '. (You don\'t need to enter it — this is just a delivery check.)',
        ]);
    }
    wp_send_json_error('Send failed. Check your '.$pick['channel'].' configuration.');
}

// ── Login interception ───────────────────────────────────────────────────────
// Hook `wp_authenticate_user` rather than `authenticate` so we run AFTER WP's
// own password check — no point challenging users who got the password wrong
// (and doing so would let an attacker harvest 2FA codes by spamming bad
// passwords).
//
// At priority 100 we run after WP-core's wp_authenticate_username_password
// (which sits at default priority 20) and after most third-party password
// hooks. Lower numbers (earlier) would risk seeing a $user that hasn't yet
// been confirmed valid.
add_filter('wp_authenticate_user', 'bs_2fa_intercept_login', 100, 2);
function bs_2fa_intercept_login($user, $password){
    if (is_wp_error($user) || ! ($user instanceof WP_User)) return $user;
    if ( ! bs_2fa_enabled_for($user->ID))                   return $user;
    if (bs_2fa_is_trusted($user->ID))                       return $user;

    $pick = bs_2fa_pick_channel($user);
    if (isset($pick['error'])) {
        // Don't lock the user out forever — return the password as-valid so
        // the login completes. The admin should fix their config (or disable
        // 2FA from the user-edit page). Logging this so a sysadmin sees it.
        error_log('[bookshop 2FA] User '.$user->ID.' has 2FA enabled but no working channel: '.$pick['error']);
        return $user;
    }

    // Generate, hash, send. Failures bypass 2FA rather than lock out — same
    // reasoning as above: the goal is "make 2FA-enabled accounts safer when
    // possible," not "brick the login flow when SMTP is down."
    $code = bs_portal_otp_generate_code();
    $ok   = bs_2fa_send_code($user, $code, $pick);
    if ( ! $ok) {
        error_log('[bookshop 2FA] Failed to deliver code to user '.$user->ID.' via '.$pick['channel'].'; allowing login through.');
        return $user;
    }

    // Stash everything the challenge page needs. The token is what links the
    // browser to the pending login — the user_id alone is not enough because
    // we need the OTP hash too.
    $token = bin2hex(random_bytes(16));
    set_transient('bs_2fa_pending_'.$token, [
        'user_id'     => $user->ID,
        'hash'        => bs_portal_otp_hash($code),
        'channel'     => $pick['channel'],
        'destination' => $pick['destination'],
        'note'        => $pick['note'] ?? '',
        'attempts'    => 0,
        'expires_at'  => time() + 10 * MINUTE_IN_SECONDS,
        'redirect_to' => $_REQUEST['redirect_to'] ?? admin_url(),
        'remember'    => ! empty($_REQUEST['rememberme']),
    ], 10 * MINUTE_IN_SECONDS);

    // Redirect to wp-login.php?action=bs_2fa&token=... — login_form_bs_2fa
    // (registered below) renders the challenge form there. This avoids the
    // alternative of sticking JSON in an error message and matching wp-login's
    // theming for free.
    $redirect = add_query_arg([
        'action'        => 'bs_2fa',
        'bs_2fa_token'  => $token,
    ], wp_login_url());
    wp_safe_redirect($redirect);
    exit;
}

// ── Challenge page: handles GET (render form) and POST (verify) ──────────────
add_action('login_form_bs_2fa', 'bs_2fa_challenge_handler');
function bs_2fa_challenge_handler(){
    $token = sanitize_text_field($_REQUEST['bs_2fa_token'] ?? '');
    if ($token === '') {
        wp_safe_redirect(wp_login_url());
        exit;
    }
    $key  = 'bs_2fa_pending_'.$token;
    $sess = get_transient($key);
    if ( ! is_array($sess)) {
        bs_2fa_render_challenge_page($token, null, 'Your sign-in session has expired. Please log in again.');
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        bs_2fa_verify_and_complete($token, $sess);
        exit; // verify_and_complete either redirects (success) or re-renders
    }

    bs_2fa_render_challenge_page($token, $sess);
    exit;
}

function bs_2fa_verify_and_complete($token, $sess){
    $key  = 'bs_2fa_pending_'.$token;
    $code = preg_replace('/\D+/', '', (string) ($_POST['bs_2fa_code'] ?? ''));

    if (time() > intval($sess['expires_at'] ?? 0)) {
        delete_transient($key);
        bs_2fa_render_challenge_page($token, null, 'Your code expired. Please log in again.');
        return;
    }
    if (intval($sess['attempts']) >= 5) {
        delete_transient($key);
        bs_2fa_render_challenge_page($token, null, 'Too many incorrect attempts. Please log in again.');
        return;
    }

    // attempts++ before compare — same reasoning as the portal flow: if the
    // verify path crashes for any reason, the attempt still counts. Capped
    // at 5 cracks per session.
    $sess['attempts'] = intval($sess['attempts']) + 1;
    set_transient($key, $sess, 10 * MINUTE_IN_SECONDS);

    if ( ! hash_equals($sess['hash'], bs_portal_otp_hash($code))) {
        $remaining = max(0, 5 - intval($sess['attempts']));
        $msg = 'Incorrect code. ' . ($remaining > 0
            ? $remaining . ' attempt' . ($remaining === 1 ? '' : 's') . ' remaining.'
            : 'Please log in again.');
        bs_2fa_render_challenge_page($token, $sess, $msg);
        return;
    }

    // Success. Burn the OTP, set the trust cookie if asked, complete login.
    delete_transient($key);

    if ( ! empty($_POST['bs_2fa_trust'])) {
        bs_2fa_set_trust_cookie(intval($sess['user_id']));
    }

    $remember = ! empty($sess['remember']);
    wp_set_auth_cookie(intval($sess['user_id']), $remember);
    do_action('wp_login', get_userdata($sess['user_id'])->user_login, get_userdata($sess['user_id']));

    $redirect = ! empty($sess['redirect_to']) ? $sess['redirect_to'] : admin_url();
    wp_safe_redirect($redirect);
    exit;
}

function bs_2fa_render_challenge_page($token, $sess, $error = ''){
    // We render INSIDE the wp-login.php chrome via login_header / login_footer
    // so the challenge looks like part of WP's login UI rather than a custom
    // page. login_header wants ($title, $message, $wp_error). The third arg
    // appears as a styled banner, which is exactly what we want for errors.
    $wp_error = new WP_Error();
    if ($error) $wp_error->add('bs_2fa_error', $error);

    login_header('Two-factor sign-in', '', $wp_error);

    $destination = $sess['destination'] ?? '';
    $channel     = $sess['channel'] ?? 'email';
    $note        = $sess['note'] ?? '';
    $label       = $channel === 'sms' ? 'SMS' : 'email';
    ?>
    <form name="bs2faform" id="bs2faform"
          action="<?=esc_url(add_query_arg('action', 'bs_2fa', wp_login_url()))?>"
          method="post" autocomplete="off">
        <input type="hidden" name="bs_2fa_token" value="<?=esc_attr($token)?>">

        <p style="margin-bottom:14px;color:#3c434a;font-size:14px">
            <?php if ($sess): ?>
                We sent a 6-digit code by <strong><?=esc_html($label)?></strong>
                to <strong><?=esc_html($destination)?></strong>.
                <?php if ($note): ?><br><em style="color:#646970;font-size:13px"><?=esc_html($note)?></em><?php endif; ?>
            <?php else: ?>
                Please <a href="<?=esc_url(wp_login_url())?>">log in again</a> to receive a new code.
            <?php endif; ?>
        </p>

        <?php if ($sess): ?>
        <p>
            <label for="bs_2fa_code">6-digit code</label>
            <input type="text" name="bs_2fa_code" id="bs_2fa_code"
                class="input"
                inputmode="numeric" pattern="[0-9]*"
                maxlength="6"
                autocomplete="one-time-code"
                placeholder="000000"
                style="font-size:1.4em;letter-spacing:.4em;text-align:center;font-family:Menlo,Consolas,monospace"
                autofocus
                required>
        </p>

        <p class="forgetmenot" style="margin:18px 0">
            <input name="bs_2fa_trust" type="checkbox" id="bs_2fa_trust" value="1">
            <label for="bs_2fa_trust">Trust this device for <?=intval(BS_2FA_TRUST_DAYS)?> days</label>
        </p>

        <p class="submit">
            <input type="submit" name="wp-submit" id="wp-submit"
                class="button button-primary button-large"
                value="Verify &amp; sign in"
                style="float:none;width:100%">
        </p>

        <p style="margin-top:18px;font-size:13px;color:#646970">
            <a href="<?=esc_url(wp_login_url())?>">&larr; Use a different account</a>
            <span style="float:right">
                Didn't get the code?
                <a href="<?=esc_url(wp_login_url())?>">Sign in again</a>
            </span>
        </p>
        <?php endif; ?>
    </form>

    <script>
    // Auto-submit on 6 digits, matching the portal-OTP UX. Strip non-digits
    // first so paste of "123 456" or "123-456" works.
    (function(){
        var input = document.getElementById('bs_2fa_code');
        var form  = document.getElementById('bs2faform');
        if (!input || !form) return;
        input.addEventListener('input', function(){
            var clean = this.value.replace(/\D+/g, '').slice(0, 6);
            if (clean !== this.value) this.value = clean;
            if (clean.length === 6) form.submit();
        });
    })();
    </script>
    <?php
    login_footer();
}


// ── Users list column ────────────────────────────────────────────────────────
// Adds a "2FA" column to wp-admin/users.php so an admin can see who's
// enrolled at a glance — without it, the only way to audit 2FA coverage is
// clicking through every user's profile in turn.
add_filter('manage_users_columns', function ($columns) {
    // Insert before "Posts" if present, else append. Keeps the column near
    // the meaningful identity columns rather than after locales/etc.
    $new = [];
    foreach ($columns as $key => $label) {
        if ($key === 'posts') {
            $new['bs_2fa'] = '2FA';
        }
        $new[$key] = $label;
    }
    if ( ! isset($new['bs_2fa'])) $new['bs_2fa'] = '2FA';
    return $new;
});
add_filter('manage_users_custom_column', function ($val, $col, $user_id) {
    if ($col !== 'bs_2fa') return $val;
    if ( ! bs_2fa_user_eligible($user_id)) {
        return '<span style="color:#999" title="2FA not applicable for this user">—</span>';
    }
    if (bs_2fa_enabled_for($user_id)) {
        $method = get_user_meta($user_id, 'bs_2fa_method', true) ?: 'email';
        $icon   = $method === 'sms' ? '📱' : '✉️';
        return '<span style="color:#2a7a3b" title="2FA enabled via '.esc_attr($method).'">'.$icon.' On</span>';
    }
    return '<span style="color:#c0392b" title="2FA not enabled">— Off</span>';
}, 10, 3);
