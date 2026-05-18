<?php
/**
 * PDF Export — uses browser print-to-PDF for reports and receipts
 * Also provides a clean printable HTML view for any report period
 */
if(!defined('ABSPATH'))exit;

// ── Printable Sales Report ────────────────────────────────────────────────────
add_action('init',function(){
    if(empty($_GET['bookshop_print_report'])) return;
    if(!current_user_can('manage_options')) wp_die('Unauthorized');
    $from=sanitize_text_field($_GET['from']??date('Y-m-01'));
    $to  =sanitize_text_field($_GET['to']??date('Y-m-d'));
    bs_render_printable_report($from,$to);
    exit;
});

function bs_render_printable_report($from,$to){
    $sum   =bs_report_summary($from,$to);
    $top   =bs_report_top_books($from,$to,20);
    $staff =bs_report_staff($from,$to);
    $profit=bs_report_profit($from,$to);
    $daily =bs_report_daily($from,$to);
    $pay   =bs_report_payment_methods($from,$to);
    $shop  =get_option('bookshop_receipt_header',get_bloginfo('name'));
    $cur   =bs_currency();
    $logo  =get_option('bookshop_logo_url','');
    $addr  =get_option('bookshop_address','');
    $from_fmt=wp_date('d M Y',strtotime($from));
    $to_fmt  =wp_date('d M Y',strtotime($to));
    $gross   =floatval($profit->gross_profit??0);
    $rev     =floatval($sum->revenue??0);
    $margin  =$rev>0?round(($gross/$rev)*100,1):0;
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?=esc_html($shop)?> — Report <?=$from_fmt?> to <?=$to_fmt?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:Georgia,serif;font-size:11pt;color:#1a1208;background:#fff;padding:20mm 18mm}
.rp-header{text-align:center;margin-bottom:24px;padding-bottom:16px;border-bottom:2px solid #1a1208}
.rp-logo{max-height:50px;max-width:160px;margin-bottom:10px}
.rp-shop{font-size:1.5rem;font-weight:700}
.rp-period{font-size:.9rem;color:#666;margin-top:4px}
h2{font-size:1.1rem;margin:20px 0 10px;padding-bottom:4px;border-bottom:1px solid #ddd}
table{width:100%;border-collapse:collapse;margin-bottom:20px;page-break-inside:avoid}
th{background:#1a1208;color:#f5d87a;padding:7px 10px;text-align:left;font-size:.85rem}
td{padding:6px 10px;border-bottom:1px solid #eee;font-size:.87rem}
tr:nth-child(even) td{background:#fdf8f0}
.kpi-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:24px}
.kpi{border:1px solid #e0d4c0;border-radius:8px;padding:12px;text-align:center}
.kpi-val{font-size:1.3rem;font-weight:700;font-family:Georgia,serif}
.kpi-lbl{font-size:.75rem;color:#8a7a65;text-transform:uppercase;margin-top:3px}
.kpi.accent{border-color:#c8860a;background:#fffbf0}.kpi.accent .kpi-val{color:#8a5c00}
.rp-footer{text-align:center;font-size:.78rem;color:#aaa;margin-top:24px;padding-top:12px;border-top:1px solid #eee}
@media print{
    body{padding:10mm}
    .no-print{display:none}
    h2{page-break-before:auto}
}
</style>
</head>
<body>
<div class="no-print" style="position:fixed;top:16px;right:16px;display:flex;gap:8px;z-index:999">
<button onclick="window.print()" style="background:#1a1208;color:#f5d87a;border:none;padding:10px 20px;border-radius:8px;cursor:pointer;font-size:.9rem;font-weight:700">🖨️ Print / Save PDF</button>
<button onclick="window.close()" style="background:#e0d4c0;border:none;padding:10px 20px;border-radius:8px;cursor:pointer;font-size:.9rem">✕ Close</button>
</div>
<?php if(!empty($_GET['auto_print'])): ?>
<script>window.addEventListener('load',function(){setTimeout(function(){window.print();},800);});</script>
<?php endif; ?>

<div class="rp-header">
    <?php if($logo): ?><img src="<?=esc_url($logo)?>" class="rp-logo" alt=""><br><?php endif; ?>
    <div class="rp-shop"><?=esc_html($shop)?></div>
    <?php if($addr): ?><div style="font-size:.82rem;color:#666;margin-top:4px"><?=esc_html($addr)?></div><?php endif; ?>
    <div class="rp-period">Sales Report &mdash; <?=$from_fmt?> to <?=$to_fmt?></div>
    <div style="font-size:.78rem;color:#aaa;margin-top:4px">Generated <?=wp_date('d M Y H:i')?></div>
</div>

<div class="kpi-grid">
    <div class="kpi accent"><div class="kpi-val"><?=bs_fmt($rev)?></div><div class="kpi-lbl">Total Revenue</div></div>
    <div class="kpi accent"><div class="kpi-val"><?=bs_fmt($gross)?></div><div class="kpi-lbl">Gross Profit</div></div>
    <div class="kpi"><div class="kpi-val"><?=$margin?>%</div><div class="kpi-lbl">Profit Margin</div></div>
    <div class="kpi"><div class="kpi-val"><?=intval($sum->sales_count??0)?></div><div class="kpi-lbl">Transactions</div></div>
    <div class="kpi"><div class="kpi-val"><?=bs_fmt($sum->sales_count?$rev/$sum->sales_count:0)?></div><div class="kpi-lbl">Avg. Sale</div></div>
    <div class="kpi"><div class="kpi-val"><?=bs_fmt($profit->cogs??0)?></div><div class="kpi-lbl">Cost of Goods</div></div>
    <div class="kpi"><div class="kpi-val"><?=bs_fmt($sum->discounts??0)?></div><div class="kpi-lbl">Discounts Given</div></div>
    <div class="kpi"><div class="kpi-val"><?=bs_fmt($sum->tax??0)?></div><div class="kpi-lbl">Tax Collected</div></div>
</div>

<?php if(!empty($daily)): ?>
<h2>Daily Revenue Breakdown</h2>
<table>
<thead><tr><th>Date</th><th>Transactions</th><th>Revenue</th></tr></thead>
<tbody>
<?php foreach($daily as $d): ?>
<tr><td><?=esc_html(wp_date('D d M Y',strtotime($d->day)))?></td><td><?=intval($d->sales_count)?></td><td><?=bs_fmt($d->revenue)?></td></tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>

<?php if(!empty($top)): ?>
<h2>Top Books by Units Sold</h2>
<table>
<thead><tr><th>#</th><th>Title</th><th>Author</th><th>Genre</th><th>Units</th><th>Revenue</th><th>Profit</th></tr></thead>
<tbody>
<?php foreach($top as $i=>$b): ?>
<tr><td><?=$i+1?></td><td><?=esc_html($b->title)?></td><td><?=esc_html($b->author)?></td><td><?=esc_html($b->genre)?></td><td><?=intval($b->units_sold)?></td><td><?=bs_fmt($b->revenue)?></td><td><?=bs_fmt($b->profit??0)?></td></tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>

<?php if(!empty($staff)): ?>
<h2>Staff Performance</h2>
<table>
<thead><tr><th>Staff Member</th><th>Transactions</th><th>Revenue</th><th>Avg. Sale</th></tr></thead>
<tbody>
<?php foreach($staff as $s): $savg=$s->sales_count?floatval($s->revenue)/$s->sales_count:0; ?>
<tr><td><?=esc_html($s->staff_name)?></td><td><?=intval($s->sales_count)?></td><td><?=bs_fmt($s->revenue)?></td><td><?=bs_fmt($savg)?></td></tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>

<?php if(!empty($pay)): ?>
<h2>Revenue by Payment Method</h2>
<table>
<thead><tr><th>Method</th><th>Transactions</th><th>Revenue</th></tr></thead>
<tbody>
<?php foreach($pay as $p): ?>
<tr><td><?=esc_html(ucfirst($p->payment_method))?></td><td><?=intval($p->count)?></td><td><?=bs_fmt($p->revenue)?></td></tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>

<div class="rp-footer">Bookshop Manager Pro &mdash; <?=esc_html($shop)?></div>
</body>
</html>
    <?php
}

// ── AJAX: Get print report URL ────────────────────────────────────────────────
add_action('wp_ajax_bs_get_print_url',function(){
    if(!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
    $from=sanitize_text_field($_GET['from']??date('Y-m-01'));
    $to  =sanitize_text_field($_GET['to']??date('Y-m-d'));
    $url =home_url("/?bookshop_print_report=1&from=$from&to=$to");
    wp_send_json_success(['url'=>$url]);
});
