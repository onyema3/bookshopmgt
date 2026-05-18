<?php
/**
 * Stock-drift weekly digest.
 *
 * Drift is currently only surfaced when an admin happens to look at the Drift
 * tab on the reports page. This module sends a weekly email summarising
 * everything bs_get_stock_drift() considers actionable, so problems are
 * pushed to the admin instead of waiting to be pulled.
 *
 * Cron-friendly: bs_get_stock_drift() is bounded (LIMIT 500 by default) and
 * does no per-row work, so this hook is safe to run unattended.
 */
if(!defined('ABSPATH'))exit;

// ── Cron: weekly schedule ─────────────────────────────────────────────────────
// We add our own 'weekly' schedule rather than reusing 'bookshop_daily_tasks'
// because daily would be too noisy for a problem that's usually a long-tail
// trickle from pre-v4 sales — once a week is enough to catch trends without
// training the admin to ignore the email.
add_filter('cron_schedules',function($schedules){
    if(!isset($schedules['bookshop_weekly'])){
        $schedules['bookshop_weekly']=[
            'interval'=>WEEK_IN_SECONDS,
            'display' =>__('Once Weekly (Bookshop)','bookshop'),
        ];
    }
    return $schedules;
});

if(!wp_next_scheduled('bookshop_weekly_drift_digest')){
    // First run scheduled an hour out so plugin activation/upgrade doesn't
    // immediately fire an email before the admin has had a chance to set
    // a recipient or disable the feature.
    wp_schedule_event(time()+HOUR_IN_SECONDS,'bookshop_weekly','bookshop_weekly_drift_digest');
}
add_action('bookshop_weekly_drift_digest','bs_send_drift_digest');

// Cleanup on plugin deactivation. The main plugin file already registers a
// deactivation hook for the daily cron; we register our own here so the
// module stays self-contained and an admin can drop this file without
// leaving an orphan event in WP-Cron.
function bs_drift_digest_clear_cron(){
    wp_clear_scheduled_hook('bookshop_weekly_drift_digest');
}
register_deactivation_hook(BOOKSHOP_DIR.'bookshop.php','bs_drift_digest_clear_cron');

/**
 * Build the digest body. Returns ['html'=>..., 'count'=>N, 'rows'=>[...]] or
 * null when there's nothing to send. Caller decides whether 'no drift' should
 * still go out — by default we suppress it (see bs_send_drift_digest).
 */
function bs_build_drift_digest(){
    if(!function_exists('bs_get_stock_drift')) return null;
    $rows = bs_get_stock_drift(0, 500);
    $count = count($rows);
    if(!$count){
        return ['html'=>'','count'=>0,'rows'=>[]];
    }

    $shop = get_option('bookshop_receipt_header', get_bloginfo('name'));
    $admin_url = admin_url('admin.php?page=bookshop-reports#rpt-drift');

    // Bucket rows so the admin scans the most-impactful ones first. Top 20
    // shown inline, the remainder summarised as a count — keeps the email
    // well under any provider's size cap and stops it being a wall of text.
    $shown = array_slice($rows, 0, 20);
    $hidden = $count - count($shown);

    $row_html = '';
    foreach($shown as $r){
        $delta = intval($r->branch_sum) - intval($r->global_qty);
        $delta_str = ($delta>0?'+':'').$delta;
        $delta_color = $delta>0 ? '#2a7a3b' : '#c0392b';
        $coverage = intval($r->branches_with_row).'/'.intval($r->active_branches);
        $row_html .= "<tr>"
            ."<td style='padding:6px 12px'>".esc_html($r->title)."</td>"
            ."<td style='padding:6px 12px;color:#666;font-size:.85em'>".esc_html($r->author)."</td>"
            ."<td style='padding:6px 12px;text-align:right'>".intval($r->global_qty)."</td>"
            ."<td style='padding:6px 12px;text-align:right'>".intval($r->branch_sum)."</td>"
            ."<td style='padding:6px 12px;text-align:right;color:$delta_color;font-weight:600'>".$delta_str."</td>"
            ."<td style='padding:6px 12px;text-align:center;color:#666'>".$coverage."</td>"
            ."</tr>";
    }
    $hidden_row = $hidden>0
        ? "<tr><td colspan='6' style='padding:8px 12px;text-align:center;color:#888;font-style:italic'>"
            ."… and $hidden more drifted book".($hidden===1?'':'s').". "
            ."<a href='".esc_url($admin_url)."' style='color:#c8860a'>Open the Drift tab</a> to see all of them."
            ."</td></tr>"
        : '';

    $html = "<!DOCTYPE html><html><head><meta charset='UTF-8'></head>"
        ."<body style='font-family:Georgia,serif;max-width:720px;margin:0 auto;background:#fdf8f0;padding:0'>"
        ."<div style='background:#1a1208;color:#f5d87a;padding:20px;text-align:center'>"
        ."<h1 style='font-size:1.3rem;margin:0'>".esc_html($shop)."</h1>"
        ."<p style='margin:4px 0 0;color:#ccc;font-size:.9rem'>Weekly Stock Drift Digest — "
        .wp_date('l, d F Y').'</p>'
        ."</div>"
        ."<div style='padding:20px'>"
        ."<p style='font-size:1rem'><strong>$count</strong> book".($count===1?'':'s')." currently show drift "
        ."between the global <code>stock_qty</code> and the per-branch sum.</p>"
        ."<p style='color:#666;font-size:.88rem;margin-top:0'>"
        ."Most common cause is pre-v4 sales (which only decremented the global counter), or books "
        ."that were never seeded at one or more branches. Reconciling sets the global stock to the "
        ."per-branch sum and is logged to the audit trail."
        ."</p>"
        ."<table style='width:100%;border-collapse:collapse;margin-top:16px;background:#fff;border:1px solid #e0d4c0'>"
        ."<thead><tr style='background:#e0d4c0'>"
        ."<th style='padding:8px 12px;text-align:left'>Title</th>"
        ."<th style='padding:8px 12px;text-align:left'>Author</th>"
        ."<th style='padding:8px 12px;text-align:right'>Global</th>"
        ."<th style='padding:8px 12px;text-align:right'>Branch&nbsp;Sum</th>"
        ."<th style='padding:8px 12px;text-align:right'>&Delta;</th>"
        ."<th style='padding:8px 12px;text-align:center'>Coverage</th>"
        ."</tr></thead>"
        ."<tbody>$row_html$hidden_row</tbody>"
        ."</table>"
        ."<p style='text-align:center;margin-top:20px'>"
        ."<a href='".esc_url($admin_url)."' style='display:inline-block;padding:10px 24px;background:#c8860a;color:#fff;text-decoration:none;border-radius:6px;font-weight:600'>"
        ."Open Drift tab"
        ."</a>"
        ."</p>"
        ."<p style='text-align:center;color:#8a7a65;font-size:.78rem;margin-top:24px'>"
        ."Sent ".current_time('d M Y H:i')." — Bookshop Manager Pro. "
        ."<br>To stop these, clear the <em>Drift Digest Email</em> field under Settings &rarr; Advanced Operations."
        ."</p>"
        ."</div></body></html>";

    return ['html'=>$html,'count'=>$count,'rows'=>$rows];
}

/**
 * Send the digest to the configured recipient.
 *
 * Behaviour:
 *   - Empty recipient → silently skip. Lets an admin disable the digest
 *     by clearing the field rather than having to wp_clear_scheduled_hook.
 *   - No drift → record last-run timestamp but skip the email. The point
 *     of the digest is to flag problems; weekly "everything's fine" mail
 *     is the kind of thing people filter out.
 *
 * Returns one of: 'sent' | 'no_recipient' | 'no_drift' | 'send_failed'.
 */
function bs_send_drift_digest(){
    $email = trim((string) get_option('bookshop_drift_digest_email', ''));
    update_option('bookshop_last_drift_digest_check', current_time('mysql'));

    if(!$email){
        return 'no_recipient';
    }
    if(!is_email($email)){
        return 'no_recipient';
    }

    $digest = bs_build_drift_digest();
    if(!$digest || $digest['count']===0){
        return 'no_drift';
    }

    $shop = get_option('bookshop_receipt_header', get_bloginfo('name'));
    $count = $digest['count'];
    $subject = "[$shop] Stock drift digest — $count book".($count===1?'':'s')." need attention";

    $sent = wp_mail($email, $subject, $digest['html'],
        ['Content-Type: text/html; charset=UTF-8']);

    if($sent){
        update_option('bookshop_last_drift_digest_sent', current_time('mysql'));
        update_option('bookshop_last_drift_digest_count', $count);
        return 'sent';
    }
    return 'send_failed';
}

// ── Manual trigger ────────────────────────────────────────────────────────────
// Admin-only because the digest exposes cross-branch sums that managers
// scoped to a single location aren't supposed to see — same policy as the
// drift tab and reconcile endpoints.
add_action('wp_ajax_bs_send_drift_digest_now',function(){
    if(!current_user_can('manage_options')) wp_send_json_error('Unauthorized',403);
    if(!bs_verify('bs_admin_nonce'))         wp_send_json_error('Bad nonce');
    $res = bs_send_drift_digest();
    $messages = [
        'sent'         => 'Drift digest sent to '.get_option('bookshop_drift_digest_email'),
        'no_recipient' => 'No recipient configured. Set "Drift Digest Email" under Advanced Operations first.',
        'no_drift'     => 'No drift detected — nothing to send.',
        'send_failed'  => 'wp_mail() returned false. Check your email configuration.',
    ];
    $msg = $messages[$res] ?? 'Unknown result';
    if($res==='sent' || $res==='no_drift'){
        wp_send_json_success(['result'=>$res,'message'=>$msg]);
    }
    wp_send_json_error($msg);
});
