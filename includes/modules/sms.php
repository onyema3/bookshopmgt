<?php
/**
 * SMS notifications — multi-provider abstraction.
 *
 * Three providers ship with the plugin:
 *   - BulkSMSNigeria  (default — best fit for the NG-primary user base)
 *   - Termii          (popular NG/Africa SMS API)
 *   - Twilio          (international fallback / sandbox testing)
 *
 * Public API:
 *   bs_send_sms( $phone, $message, $opts = [] )
 *     -> ['ok' => bool, 'message_id' => string?, 'error' => string?, 'provider' => string]
 *
 *   bs_sms_normalize_phone( $raw, $default_country = '234' )
 *     -> normalised digits-only string (E.164 minus the +).
 *
 * Settings (all in wp_options):
 *   bookshop_sms_enabled            '1' to send, anything else short-circuits
 *   bookshop_sms_provider           bulksmsnigeria | termii | twilio
 *   bookshop_sms_sender_id          shown to recipient (max 11 chars on most NG networks)
 *   bookshop_sms_default_country    digits, no '+', e.g. '234' for Nigeria
 *   bookshop_bulksms_api_token      BulkSMSNigeria API token
 *   bookshop_termii_api_key         Termii API key
 *   bookshop_twilio_account_sid     Twilio Account SID
 *   bookshop_twilio_auth_token      Twilio Auth Token
 *   bookshop_twilio_from_number     Twilio purchased number, E.164 ('+234…')
 *   bookshop_sms_notify_reservation '1' to SMS customer when their reserved book is ready
 *   bookshop_sms_notify_orders      '1' to SMS for ready/completed/cancelled online orders
 *
 * The last failure is captured into bookshop_last_sms_error +
 * bookshop_last_sms_error_at so the settings page can surface it.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// ── Public dispatcher ────────────────────────────────────────────────────────
function bs_send_sms( $phone, $message, $opts = [] ) {
    if ( get_option( 'bookshop_sms_enabled' ) !== '1' ) {
        return [ 'ok' => false, 'error' => 'SMS is disabled in settings.', 'provider' => 'none' ];
    }

    $default_country = (string) get_option( 'bookshop_sms_default_country', '234' );
    $normalised = bs_sms_normalize_phone( $phone, $default_country );
    if ( ! $normalised ) {
        return [ 'ok' => false, 'error' => 'Empty or invalid phone number.', 'provider' => 'none' ];
    }

    $provider = (string) get_option( 'bookshop_sms_provider', 'bulksmsnigeria' );
    $sender   = trim( (string) get_option( 'bookshop_sms_sender_id', '' ) );

    if ( $provider === 'bulksmsnigeria' ) {
        $result = bs_sms_via_bulksmsnigeria( $normalised, $message, $sender );
    } elseif ( $provider === 'termii' ) {
        $result = bs_sms_via_termii( $normalised, $message, $sender );
    } elseif ( $provider === 'twilio' ) {
        $result = bs_sms_via_twilio( $normalised, $message, $sender );
    } else {
        $result = [ 'ok' => false, 'error' => 'Unknown provider: ' . $provider ];
    }

    $result['provider'] = $provider;

    if ( ! empty( $result['error'] ) && empty( $result['ok'] ) ) {
        update_option( 'bookshop_last_sms_error',    $result['error'] );
        update_option( 'bookshop_last_sms_error_at', current_time( 'mysql' ) );
    }

    // Log to messages_queue so the admin can see what fired (and what failed).
    // Skip explicitly when the caller is doing high-volume work like the
    // bulk WhatsApp links generator — there's no caller for that today,
    // but the option is here so we don't have to revisit the helper later.
    if ( empty( $opts['skip_log'] ) ) {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}bookshop_messages_queue", [
            'customer_id' => intval( $opts['customer_id'] ?? 0 ) ?: null,
            'type'        => sanitize_text_field( $opts['type'] ?? 'sms' ),
            'message'     => $message,
            'phone'       => $normalised,
            'email'       => '',
            'status'      => $result['ok'] ? 'sent' : 'failed',
        ] );
    }

    return $result;
}

// ── Phone normalisation ──────────────────────────────────────────────────────
// Returns digits only (no leading +), with the country code prefixed when the
// input looks like a local number. Defaults to NG (234) when not configured.
//   '08012345678'    + 234 → '2348012345678'   (local format → international)
//   '+2348012345678' + 234 → '2348012345678'   (already international)
//   '2348012345678'  + 234 → '2348012345678'   (already international)
//   '8012345678'     + 234 → '2348012345678'   (10 digits, no leading 0)
//   '447911123456'   + 234 → '447911123456'    (other country, leave as-is)
function bs_sms_normalize_phone( $raw, $default_country = '234' ) {
    $digits = preg_replace( '/\D+/', '', (string) $raw );
    if ( $digits === '' ) return '';

    $cc = preg_replace( '/\D+/', '', (string) $default_country );
    if ( $cc === '' ) $cc = '234';

    // Already starts with the configured country code → return as-is.
    if ( strpos( $digits, $cc ) === 0 && strlen( $digits ) > strlen( $cc ) ) {
        return $digits;
    }

    // Local format with leading 0 (e.g. NG '0801…') → strip 0, prepend country.
    if ( $digits[0] === '0' && strlen( $digits ) >= 10 ) {
        return $cc . substr( $digits, 1 );
    }

    // 10 digits with no country code → assume default country (e.g. '8012345678').
    if ( strlen( $digits ) === 10 ) {
        return $cc . $digits;
    }

    // Anything else (foreign country code, short codes, etc.) — return raw digits
    // so the provider can decide what to do with it.
    return $digits;
}

// ── Provider: BulkSMSNigeria ─────────────────────────────────────────────────
// Docs: https://docs.bulksmsnigeria.com
function bs_sms_via_bulksmsnigeria( $phone, $message, $sender = '' ) {
    $token = trim( (string) get_option( 'bookshop_bulksms_api_token', '' ) );
    if ( $token === '' ) return [ 'ok' => false, 'error' => 'BulkSMSNigeria token not configured.' ];

    if ( $sender === '' ) $sender = 'Bookshop';

    $resp = wp_remote_post( 'https://www.bulksmsnigeria.com/api/v1/sms/create', [
        'timeout' => 20,
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'Accept'        => 'application/json',
        ],
        'body' => [
            'api_token' => $token,
            'from'      => $sender,
            'to'        => $phone,
            'body'      => $message,
            // dnd=2 sends to non-DND numbers (default route); 4 also bypasses DND
            // at extra cost. Stick with 2 — admin can patch this later if needed.
            'dnd'       => '2',
        ],
    ] );

    if ( is_wp_error( $resp ) ) return [ 'ok' => false, 'error' => $resp->get_error_message() ];

    $code = (int) wp_remote_retrieve_response_code( $resp );
    $body = json_decode( wp_remote_retrieve_body( $resp ), true );

    if ( $code >= 200 && $code < 300 && ! empty( $body['data']['message_id'] ) ) {
        return [ 'ok' => true, 'message_id' => (string) $body['data']['message_id'] ];
    }

    $err = $body['error']
        ?? ( $body['data']['message'] ?? null )
        ?? ( $body['message'] ?? 'HTTP ' . $code );
    if ( is_array( $err ) ) $err = json_encode( $err );
    return [ 'ok' => false, 'error' => 'BulkSMSNigeria: ' . $err ];
}

// ── Provider: Termii ─────────────────────────────────────────────────────────
// Docs: https://developer.termii.com
function bs_sms_via_termii( $phone, $message, $sender = '' ) {
    $key = trim( (string) get_option( 'bookshop_termii_api_key', '' ) );
    if ( $key === '' ) return [ 'ok' => false, 'error' => 'Termii API key not configured.' ];

    if ( $sender === '' ) $sender = 'N-Alert'; // Termii's default approved sender

    $resp = wp_remote_post( 'https://api.ng.termii.com/api/sms/send', [
        'timeout' => 20,
        'headers' => [ 'Content-Type' => 'application/json' ],
        'body'    => wp_json_encode( [
            'api_key' => $key,
            'to'      => $phone,
            'from'    => $sender,
            'sms'     => $message,
            'type'    => 'plain',
            'channel' => 'generic',
        ] ),
    ] );

    if ( is_wp_error( $resp ) ) return [ 'ok' => false, 'error' => $resp->get_error_message() ];

    $code = (int) wp_remote_retrieve_response_code( $resp );
    $body = json_decode( wp_remote_retrieve_body( $resp ), true );

    if ( $code >= 200 && $code < 300 && ! empty( $body['message_id'] ) ) {
        return [ 'ok' => true, 'message_id' => (string) $body['message_id'] ];
    }

    $err = $body['message'] ?? 'HTTP ' . $code;
    if ( is_array( $err ) ) $err = json_encode( $err );
    return [ 'ok' => false, 'error' => 'Termii: ' . $err ];
}

// ── Provider: Twilio ─────────────────────────────────────────────────────────
// Docs: https://www.twilio.com/docs/sms/api
function bs_sms_via_twilio( $phone, $message, $sender = '' ) {
    $sid   = trim( (string) get_option( 'bookshop_twilio_account_sid', '' ) );
    $token = trim( (string) get_option( 'bookshop_twilio_auth_token', '' ) );
    if ( $sid === '' || $token === '' ) {
        return [ 'ok' => false, 'error' => 'Twilio credentials not configured.' ];
    }
    // Sender ID for Twilio is the purchased "from" number (or alphanumeric ID
    // in regions that allow it). Falls back to a dedicated option since the
    // shared sender_id field can't carry a +-prefixed phone reliably.
    if ( $sender === '' ) {
        $sender = trim( (string) get_option( 'bookshop_twilio_from_number', '' ) );
    }
    if ( $sender === '' ) {
        return [ 'ok' => false, 'error' => 'Twilio "from" number not configured.' ];
    }

    // Ensure E.164 on the destination — Twilio is strict about this.
    $to = ( substr( $phone, 0, 1 ) === '+' ) ? $phone : '+' . $phone;

    $resp = wp_remote_post(
        'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode( $sid ) . '/Messages.json',
        [
            'timeout' => 20,
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode( $sid . ':' . $token ),
            ],
            'body' => [
                'From' => $sender,
                'To'   => $to,
                'Body' => $message,
            ],
        ]
    );

    if ( is_wp_error( $resp ) ) return [ 'ok' => false, 'error' => $resp->get_error_message() ];

    $code = (int) wp_remote_retrieve_response_code( $resp );
    $body = json_decode( wp_remote_retrieve_body( $resp ), true );

    if ( $code >= 200 && $code < 300 && ! empty( $body['sid'] ) ) {
        return [ 'ok' => true, 'message_id' => (string) $body['sid'] ];
    }

    $err = $body['message'] ?? 'HTTP ' . $code;
    if ( is_array( $err ) ) $err = json_encode( $err );
    return [ 'ok' => false, 'error' => 'Twilio: ' . $err ];
}

// ── Notification: reservation ready ──────────────────────────────────────────
// Sends an SMS when a manager flips a reservation status to 'notified'. The
// existing email path remains via bs_send_reservation_notification() — this
// adds the SMS leg, gated by the bookshop_sms_notify_reservation flag.
function bs_send_reservation_ready_sms( $reservation ) {
    if ( get_option( 'bookshop_sms_notify_reservation' ) !== '1' ) return false;

    $phone = is_object( $reservation ) ? ( $reservation->customer_phone ?? '' ) : ( $reservation['customer_phone'] ?? $reservation['phone'] ?? '' );
    if ( ! $phone ) return false;

    $shop  = get_option( 'bookshop_receipt_header', get_bloginfo( 'name' ) );
    $name  = is_object( $reservation ) ? ( $reservation->customer_name ?? '' ) : ( $reservation['customer_name'] ?? $reservation['name'] ?? '' );
    $title = is_object( $reservation ) ? ( $reservation->book_title ?? '' )    : ( $reservation['book_title'] ?? '' );
    $first = $name ? strtok( $name, ' ' ) : 'there';

    $msg = sprintf(
        'Hi %s, good news — "%s" is now available for collection at %s. Thank you!',
        $first,
        $title ?: 'your reserved book',
        $shop
    );

    $cid_raw = is_object( $reservation ) ? ( $reservation->customer_id ?? 0 ) : ( $reservation['customer_id'] ?? 0 );
    $res = bs_send_sms( $phone, $msg, [
        'type'        => 'reservation_ready',
        'customer_id' => intval( $cid_raw ),
    ] );
    return ! empty( $res['ok'] );
}

// ── Notification: online order status change ────────────────────────────────
// Tagged onto the existing email notification path so SMS goes out for the
// few transitions where customers actually want a heads-up: ready (their
// pickup is waiting) and cancelled (so they don't keep checking the email).
// 'completed' is excluded — they'll know the order completed from picking
// it up. 'pending'/'paid'/'processing' are too noisy for SMS.
function bs_send_online_order_sms( $order, $status ) {
    if ( get_option( 'bookshop_sms_notify_orders' ) !== '1' ) return false;
    if ( ! in_array( $status, [ 'ready', 'cancelled' ], true ) ) return false;

    $phone = is_object( $order ) ? $order->customer_phone : ( $order['customer_phone'] ?? '' );
    if ( ! $phone ) return false;

    $shop  = get_option( 'bookshop_receipt_header', get_bloginfo( 'name' ) );
    $ref   = is_object( $order ) ? $order->ref           : ( $order['ref'] ?? '' );
    $name  = is_object( $order ) ? $order->customer_name : ( $order['customer_name'] ?? '' );
    $type  = is_object( $order ) ? $order->type          : ( $order['type'] ?? 'pickup' );
    $first = $name ? strtok( $name, ' ' ) : 'there';

    if ( $status === 'ready' ) {
        $msg = sprintf(
            'Hi %s, your %s order %s is ready for %s at %s. See you soon!',
            $first, $shop, $ref, ($type === 'delivery' ? 'delivery' : 'collection'), $shop
        );
    } else { // cancelled
        $msg = sprintf(
            'Hi %s, your %s order %s has been cancelled. Reply or contact us if this is unexpected.',
            $first, $shop, $ref
        );
    }

    $cid = is_object( $order ) ? intval( $order->customer_id ?? 0 ) : intval( $order['customer_id'] ?? 0 );
    $res = bs_send_sms( $phone, $msg, [
        'type'        => 'order_' . $status,
        'customer_id' => $cid,
    ] );
    return ! empty( $res['ok'] );
}

// ── AJAX: send a test SMS so admins can verify config ───────────────────────
add_action( 'wp_ajax_bs_sms_test', function () {
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized', 403 );
    $phone = sanitize_text_field( $_POST['phone'] ?? '' );
    if ( ! $phone ) wp_send_json_error( 'Phone number required.' );

    $shop = get_option( 'bookshop_receipt_header', get_bloginfo( 'name' ) );
    $msg  = '[' . $shop . '] SMS test ' . wp_date( 'd M H:i' ) . '. Your provider is working.';

    delete_option( 'bookshop_last_sms_error' );
    delete_option( 'bookshop_last_sms_error_at' );

    $res = bs_send_sms( $phone, $msg, [ 'type' => 'sms_test' ] );

    if ( ! empty( $res['ok'] ) ) {
        $normalised = bs_sms_normalize_phone( $phone, get_option( 'bookshop_sms_default_country', '234' ) );
        wp_send_json_success( [
            'message'    => 'Test SMS sent to ' . $normalised . ' via ' . $res['provider'] . '. Check the recipient phone.',
            'message_id' => $res['message_id'] ?? '',
        ] );
    }
    wp_send_json_error( $res['error'] ?? 'Send failed for an unknown reason.' );
} );
