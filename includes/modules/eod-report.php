<?php
/**
 * End-of-Day Report — auto-emailed each day for the *previous* trading day,
 * at a time the admin chooses (default 7am, in the WordPress timezone).
 *
 * Why not the simpler `bookshop_daily_tasks` hook?
 *   1. WP-Cron is request-driven, so a "daily" event will fire at whatever
 *      moment the first qualifying request happens to land. With the old
 *      always-today logic that meant a midnight-ish cron was reporting on a
 *      day that hadn't ended yet (10pm-11pm sales were missing).
 *   2. There was no way to pin delivery to "after we close" — figures
 *      arrived halfway through the day and were obsolete by morning.
 *
 * The new design:
 *   - We register an **hourly** schedule (bookshop_hourly).
 *   - On every tick, bs_eod_check_and_send() asks: has the configured send
 *     hour passed today, and have we not yet sent yesterday's report?
 *     If yes → send. The next tick that would also qualify exits early
 *     because bookshop_last_eod_date now matches yesterday.
 *   - If WP-Cron misses a day, the next hourly tick the day after still
 *     sends *yesterday's* report. We don't try to backfill old days; the
 *     admin can hit the "Send EOD Report Now" button for those.
 *   - Admin can disable by clearing the recipient email field.
 */
if(!defined('ABSPATH'))exit;

// ── HTML report (unchanged shape; only signature changed to accept a date) ──
function bs_generate_eod_report($date=''){
    if(!$date) $date = wp_date('Y-m-d', strtotime('-1 day'));
    $sum   =bs_report_summary($date,$date);
    $staff =bs_report_staff($date,$date);
    $pay   =bs_report_payment_methods($date,$date);
    $top   =bs_report_top_books($date,$date,5);
    $profit=bs_report_profit($date,$date);
    $shop  =get_option('bookshop_receipt_header',get_bloginfo('name'));
    $cur   =bs_currency();

    $staff_rows='';
    foreach($staff as $s){
        $staff_rows.="<tr><td style='padding:6px 12px'>{$s->staff_name}</td><td style='padding:6px 12px;text-align:center'>{$s->sales_count}</td><td style='padding:6px 12px;text-align:right'>".bs_fmt($s->revenue)."</td></tr>";
    }
    $pay_rows='';
    foreach($pay as $p){
        $pay_rows.="<tr><td style='padding:6px 12px'>".ucfirst($p->payment_method)."</td><td style='padding:6px 12px;text-align:center'>{$p->count}</td><td style='padding:6px 12px;text-align:right'>".bs_fmt($p->revenue)."</td></tr>";
    }
    $top_rows='';
    foreach($top as $i=>$b){
        $top_rows.="<tr><td style='padding:6px 12px'>".($i+1)."</td><td style='padding:6px 12px'>{$b->title}</td><td style='padding:6px 12px;text-align:center'>{$b->units_sold}</td><td style='padding:6px 12px;text-align:right'>".bs_fmt($b->revenue)."</td></tr>";
    }

    $html="<!DOCTYPE html><html><head><meta charset='UTF-8'></head><body style='font-family:Georgia,serif;max-width:640px;margin:0 auto;background:#fdf8f0;padding:0'>
    <div style='background:#1a1208;color:#f5d87a;padding:24px;text-align:center'>
        <h1 style='font-size:1.4rem;margin:0'>$shop</h1>
        <p style='margin:4px 0 0;color:#ccc;font-size:.9rem'>End of Day Report — ".wp_date('l, d F Y',strtotime($date))."</p>
    </div>
    <div style='padding:24px'>
    <table style='width:100%;border-collapse:collapse;margin-bottom:20px'>
        <tr><td colspan='2' style='background:#c8860a;color:#fff;padding:8px 12px;font-weight:700'>Summary</td></tr>
        <tr style='background:#fff'><td style='padding:10px 12px'>Total Revenue</td><td style='padding:10px 12px;text-align:right;font-size:1.2rem;font-weight:700;color:#1a1208'>".bs_fmt($sum->revenue??0)."</td></tr>
        <tr style='background:#f5efe4'><td style='padding:8px 12px'>Transactions</td><td style='padding:8px 12px;text-align:right;font-weight:600'>".intval($sum->sales_count??0)."</td></tr>
        <tr style='background:#fff'><td style='padding:8px 12px'>Gross Profit</td><td style='padding:8px 12px;text-align:right;font-weight:600;color:#2a7a3b'>".bs_fmt($profit->gross_profit??0)."</td></tr>
        <tr style='background:#f5efe4'><td style='padding:8px 12px'>Discounts Given</td><td style='padding:8px 12px;text-align:right'>".bs_fmt($sum->discounts??0)."</td></tr>
        <tr style='background:#fff'><td style='padding:8px 12px'>Avg. Sale Value</td><td style='padding:8px 12px;text-align:right'>".bs_fmt($sum->sales_count?($sum->revenue/$sum->sales_count):0)."</td></tr>
    </table>
    ".($staff_rows?"<table style='width:100%;border-collapse:collapse;margin-bottom:20px'>
        <tr><td colspan='3' style='background:#1a1208;color:#f5d87a;padding:8px 12px;font-weight:700'>Staff Performance</td></tr>
        <tr style='background:#e0d4c0'><th style='padding:6px 12px;text-align:left'>Staff</th><th style='padding:6px 12px'>Sales</th><th style='padding:6px 12px;text-align:right'>Revenue</th></tr>
        $staff_rows</table>":'')."
    ".($pay_rows?"<table style='width:100%;border-collapse:collapse;margin-bottom:20px'>
        <tr><td colspan='3' style='background:#1a1208;color:#f5d87a;padding:8px 12px;font-weight:700'>Payment Methods</td></tr>
        <tr style='background:#e0d4c0'><th style='padding:6px 12px;text-align:left'>Method</th><th style='padding:6px 12px'>Count</th><th style='padding:6px 12px;text-align:right'>Revenue</th></tr>
        $pay_rows</table>":'')."
    ".($top_rows?"<table style='width:100%;border-collapse:collapse;margin-bottom:20px'>
        <tr><td colspan='4' style='background:#1a1208;color:#f5d87a;padding:8px 12px;font-weight:700'>Top 5 Books</td></tr>
        <tr style='background:#e0d4c0'><th style='padding:6px 12px'>#</th><th style='padding:6px 12px;text-align:left'>Title</th><th style='padding:6px 12px'>Units</th><th style='padding:6px 12px;text-align:right'>Revenue</th></tr>
        $top_rows</table>":'')."
    <p style='text-align:center;color:#8a7a65;font-size:.8rem;margin-top:20px'>Generated ".wp_date('d M Y H:i')." — Bookshop Manager Pro</p>
    </div></body></html>";
    return $html;
}

// ── Send the report for a specific date ─────────────────────────────────────
// Returns true on successful wp_mail dispatch (delivery is up to SMTP/host).
function bs_send_eod_report( $date = '' ){
    $email = trim((string) get_option('bookshop_eod_email', get_option('admin_email')));
    if (!$email || !is_email($email)) return false; // disabled or misconfigured

    if (!$date) $date = wp_date('Y-m-d', strtotime('-1 day'));
    $shop = get_option('bookshop_receipt_header', get_bloginfo('name'));
    $html = bs_generate_eod_report($date);

    $sent = wp_mail(
        $email,
        "[$shop] End-of-Day Report — " . wp_date('d M Y', strtotime($date)),
        $html,
        ['Content-Type: text/html; charset=UTF-8']
    );

    if ($sent) {
        update_option('bookshop_last_eod',      current_time('mysql'));
        update_option('bookshop_last_eod_date', $date);
    }
    return $sent;
}

// ── Hourly check — fires once per hour, sends at most once per day ─────────
add_action('bookshop_hourly', 'bs_eod_check_and_send');
function bs_eod_check_and_send(){
    // Use string casts so a stored "" or "0" both resolve to a valid hour.
    $send_hour = (int) get_option('bookshop_eod_send_hour', 7);
    if ($send_hour < 0 || $send_hour > 23) $send_hour = 7;

    $now_hour = (int) wp_date('G');
    if ($now_hour < $send_hour) return; // not yet today

    // The report covers yesterday. If we already sent that report, skip.
    $target_date    = wp_date('Y-m-d', strtotime('-1 day'));
    $last_sent_date = (string) get_option('bookshop_last_eod_date', '');
    if ($last_sent_date === $target_date) return;

    bs_send_eod_report($target_date);
}

// ── Manual trigger from the settings page ────────────────────────────────────
add_action('wp_ajax_bs_send_eod_now',function(){
    if(!bs_user_can_manage()) wp_send_json_error('Unauthorized');
    // Manual sends are explicit, so a missing recipient should surface as an
    // error rather than silently doing nothing.
    $email = trim((string) get_option('bookshop_eod_email', get_option('admin_email')));
    if (!$email || !is_email($email)) {
        wp_send_json_error('No EOD recipient configured. Set the End-of-Day Report Email above first.');
    }
    $sent = bs_send_eod_report();
    if ($sent) {
        wp_send_json_success(['message' => 'End-of-day report sent to ' . $email . '.']);
    }
    wp_send_json_error('wp_mail returned false. Check the SMTP panel for details.');
});

// ── Preview the report HTML in the browser ──────────────────────────────────
add_action('wp_ajax_bs_preview_eod',function(){
    if(!bs_user_can_manage()) wp_die('Unauthorized');
    echo bs_generate_eod_report(); exit;
});
