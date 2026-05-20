<?php
/**
 * SMTP delivery for transactional and bulk email.
 *
 * Without this, wp_mail() goes through PHP's mail() — which on most shared
 * hosting fails SPF/DKIM checks and lands in spam (or never leaves the
 * outbound queue at all). Configuring SMTP via the host's outbound relay,
 * SendGrid, Postmark, Amazon SES, etc. is what actually gets bookshop email
 * (portal logins, order updates, EOD report, marketing) into customer inboxes.
 *
 * Settings keys (all in wp_options):
 *   bookshop_smtp_enabled        '1' to apply, anything else falls back to PHP mail()
 *   bookshop_smtp_host           e.g. smtp.gmail.com
 *   bookshop_smtp_port           587 / 465 / 25
 *   bookshop_smtp_encryption     tls | ssl | none
 *   bookshop_smtp_auth           '1' if username/password required (almost always)
 *   bookshop_smtp_username       login name
 *   bookshop_smtp_password       login password / app password
 *   bookshop_smtp_from_email     overrides PHPMailer From if set
 *   bookshop_smtp_from_name      overrides PHPMailer FromName if set
 *
 * The last failure (if any) is captured into bookshop_last_smtp_error +
 * bookshop_last_smtp_error_at so the settings page can surface it instead of
 * the user staring at a button that silently does nothing.
 */
if ( ! defined('ABSPATH') ) exit;

// ── Apply SMTP config to PHPMailer on every wp_mail() call ───────────────────
add_action('phpmailer_init', function ($phpmailer) {
    if (get_option('bookshop_smtp_enabled') !== '1') return;

    $host = trim((string) get_option('bookshop_smtp_host', ''));
    if (!$host) return; // nothing to do

    $phpmailer->isSMTP();
    $phpmailer->Host = $host;
    $phpmailer->Port = (int) get_option('bookshop_smtp_port', 587);

    $encryption = (string) get_option('bookshop_smtp_encryption', 'tls');
    if ($encryption === 'tls') {
        $phpmailer->SMTPSecure = 'tls';
    } elseif ($encryption === 'ssl') {
        $phpmailer->SMTPSecure = 'ssl';
    } else {
        $phpmailer->SMTPSecure = '';
        $phpmailer->SMTPAutoTLS = false;
    }

    $auth = get_option('bookshop_smtp_auth', '1') === '1';
    $phpmailer->SMTPAuth = $auth;
    if ($auth) {
        $phpmailer->Username = (string) get_option('bookshop_smtp_username', '');
        $phpmailer->Password = (string) get_option('bookshop_smtp_password', '');
    }

    // Optional From overrides — only apply when set, so existing per-message
    // From headers (e.g. the bulk-email "From: $from_name <store_email>" line)
    // still win when the admin has chosen to set them per-feature.
    $from_email = trim((string) get_option('bookshop_smtp_from_email', ''));
    $from_name  = trim((string) get_option('bookshop_smtp_from_name',  ''));
    if ($from_email) {
        $phpmailer->From = $from_email;
    }
    if ($from_name) {
        $phpmailer->FromName = $from_name;
    }
});

// ── Capture the last delivery failure so the admin can debug ─────────────────
add_action('wp_mail_failed', function ($wp_error) {
    if ($wp_error instanceof WP_Error) {
        update_option('bookshop_last_smtp_error',    $wp_error->get_error_message());
        update_option('bookshop_last_smtp_error_at', current_time('mysql'));
    }
});

// ── AJAX: send a test email so the admin can verify config without guessing ──
add_action('wp_ajax_bs_smtp_test', function () {
    if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized', 403);

    $to = sanitize_email( $_POST['to'] ?? get_option('admin_email') );
    if (!is_email($to)) wp_send_json_error('Invalid email address.');

    $shop = get_option('bookshop_receipt_header', get_bloginfo('name'));
    $subject = "[$shop] SMTP test — " . wp_date('d M Y H:i');
    $body =
        "<p>Hello,</p>" .
        "<p>If you're reading this in your inbox, your SMTP configuration is working.</p>" .
        "<p style=\"color:#8a7a65;font-size:.85em\">Sent from " . esc_html($shop) . " on " . wp_date('d M Y H:i') . ".</p>";

    // Clear any previous error so a successful test reflects current state.
    delete_option('bookshop_last_smtp_error');
    delete_option('bookshop_last_smtp_error_at');

    $sent = wp_mail($to, $subject, $body, ['Content-Type: text/html; charset=UTF-8']);

    if ($sent) {
        wp_send_json_success([
            'message' => 'Test email sent to ' . $to . '. Check the inbox (and the spam folder, just in case).',
        ]);
    }

    $err = get_option('bookshop_last_smtp_error', 'wp_mail returned false but did not raise an error. Check PHP error log on the host.');
    wp_send_json_error('Send failed: ' . $err);
});
