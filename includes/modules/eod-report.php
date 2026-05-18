<?php
/**
 * End-of-Day Report — auto-emailed each day
 */
if(!defined('ABSPATH'))exit;

function bs_generate_eod_report($date=''){
    if(!$date) $date=date('Y-m-d');
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
        <tr><td colspan='2' style='background:#c8860a;color:#fff;padding:8px 12px;font-weight:700'>Today's Summary</td></tr>
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
        <tr><td colspan='4' style='background:#1a1208;color:#f5d87a;padding:8px 12px;font-weight:700'>Top 5 Books Today</td></tr>
        <tr style='background:#e0d4c0'><th style='padding:6px 12px'>#</th><th style='padding:6px 12px;text-align:left'>Title</th><th style='padding:6px 12px'>Units</th><th style='padding:6px 12px;text-align:right'>Revenue</th></tr>
        $top_rows</table>":'')."
    <p style='text-align:center;color:#8a7a65;font-size:.8rem;margin-top:20px'>Generated ".current_time('d M Y H:i')." — Bookshop Manager Pro</p>
    </div></body></html>";
    return $html;
}

function bs_send_eod_report(){
    $email=get_option('bookshop_eod_email',get_option('admin_email'));
    $shop =get_option('bookshop_receipt_header',get_bloginfo('name'));
    $html =bs_generate_eod_report();
    wp_mail($email,"[$shop] End of Day Report — ".wp_date('d M Y'),$html,['Content-Type: text/html; charset=UTF-8']);
    update_option('bookshop_last_eod',current_time('mysql'));
}
add_action('bookshop_daily_tasks','bs_send_eod_report');

// Manual trigger
add_action('wp_ajax_bs_send_eod_now',function(){
    if(!bs_user_can_manage()) wp_send_json_error('Unauthorized');
    bs_send_eod_report();
    wp_send_json_success(['message'=>'End-of-day report sent to '.get_option('bookshop_eod_email',get_option('admin_email'))]);
});

// Preview
add_action('wp_ajax_bs_preview_eod',function(){
    if(!bs_user_can_manage()) wp_die('Unauthorized');
    echo bs_generate_eod_report(); exit;
});
