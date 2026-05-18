<?php
if(!defined('ABSPATH'))exit;

function bs_check_low_stock_alerts(){
    global $wpdb;
    $books=$wpdb->get_results("SELECT * FROM {$wpdb->prefix}bookshop_books WHERE status='active' AND stock_qty<=low_stock_threshold AND stock_qty>=0");
    if(empty($books)) return;
    $email=get_option('bookshop_low_stock_email',get_option('admin_email'));
    $shop=get_option('bookshop_receipt_header',get_bloginfo('name'));
    $rows='';
    foreach($books as $b){
        $rows.="<tr><td style='padding:6px 10px;border-bottom:1px solid #eee'>{$b->title}</td><td style='padding:6px 10px;border-bottom:1px solid #eee'>{$b->author}</td><td style='padding:6px 10px;border-bottom:1px solid #eee;color:#c0392b;font-weight:bold'>{$b->stock_qty}</td><td style='padding:6px 10px;border-bottom:1px solid #eee'>{$b->low_stock_threshold}</td></tr>";
    }
    $html="<h2>Low Stock Alert — $shop</h2><p>The following books are at or below their low stock threshold:</p>
    <table style='border-collapse:collapse;width:100%'><thead><tr style='background:#1a1208;color:#fff'><th style='padding:8px 10px'>Title</th><th style='padding:8px 10px'>Author</th><th style='padding:8px 10px'>Stock</th><th style='padding:8px 10px'>Threshold</th></tr></thead><tbody>$rows</tbody></table>
    <p style='margin-top:16px;font-size:.85em;color:#888'>Sent by Bookshop Manager Pro</p>";
    wp_mail($email,"[$shop] Low Stock Alert — ".count($books)." book(s)",$html,['Content-Type: text/html; charset=UTF-8']);
}

function bs_send_whatsapp_receipt($phone,$sale_ref,$total,$items){
    $wa=get_option('bookshop_whatsapp','');
    if(!$wa) return;
    $shop=get_option('bookshop_receipt_header',get_bloginfo('name'));
    $lines="*$shop — Receipt*\nRef: $sale_ref\n\n";
    foreach($items as $i) $lines.="- {$i->title} × {$i->qty} — ".bs_fmt($i->line_total)."\n";
    $lines.="\n*Total: ".bs_fmt($total)."*\nThank you for shopping with us!";
    $url="https://api.whatsapp.com/send?phone=".rawurlencode($phone)."&text=".rawurlencode($lines);
    return $url; // open in browser/popup
}

function bs_send_email_receipt($email,$sale,$items){
    if(!$email) return false;
    $shop=get_option('bookshop_receipt_header',get_bloginfo('name'));
    $rows='';
    foreach($items as $i){
        $rows.="<tr><td style='padding:6px 10px;border-bottom:1px solid #f0e8d8'>{$i->title}</td><td style='padding:6px 10px;border-bottom:1px solid #f0e8d8'>{$i->qty}</td><td style='padding:6px 10px;border-bottom:1px solid #f0e8d8'>".bs_fmt($i->unit_price)."</td><td style='padding:6px 10px;border-bottom:1px solid #f0e8d8'>".bs_fmt($i->line_total)."</td></tr>";
    }
    $html="<div style='font-family:Georgia,serif;max-width:520px;margin:auto;background:#fdf8f0;padding:28px;border-radius:10px'>
    <h2 style='color:#1a1208'>$shop</h2>
    <p style='color:#8a7a65'>Receipt — Ref: <strong>{$sale->sale_ref}</strong></p>
    <table style='width:100%;border-collapse:collapse;background:#fff;border-radius:6px;overflow:hidden'>
    <thead><tr style='background:#1a1208;color:#f5d87a'><th style='padding:8px 10px;text-align:left'>Book</th><th style='padding:8px 10px'>Qty</th><th style='padding:8px 10px'>Price</th><th style='padding:8px 10px'>Total</th></tr></thead>
    <tbody>$rows</tbody></table>
    <table style='margin-top:14px;width:100%'>
    ".($sale->discount>0?"<tr><td>Discount</td><td style='text-align:right'>-".bs_fmt($sale->discount)."</td></tr>":'')."
    ".($sale->tax>0?"<tr><td>Tax</td><td style='text-align:right'>".bs_fmt($sale->tax)."</td></tr>":'')."
    <tr><td style='font-weight:bold;font-size:1.1em'>TOTAL</td><td style='text-align:right;font-weight:bold;font-size:1.1em'>".bs_fmt($sale->total)."</td></tr>
    </table>
    <p style='margin-top:20px;color:#8a7a65;font-size:.85em'>Thank you for shopping with us! — $shop</p>
    </div>";
    return wp_mail($email,"Your receipt from $shop",$html,['Content-Type: text/html; charset=UTF-8']);
}

function bs_send_reservation_notification($reservation){
    $email=get_option('admin_email');
    $shop=get_option('bookshop_receipt_header',get_bloginfo('name'));
    $html="<h2>New Book Reservation — $shop</h2>
    <p><strong>Customer:</strong> {$reservation['name']}<br>
    <strong>Phone:</strong> {$reservation['phone']}<br>
    <strong>Email:</strong> {$reservation['email']}<br>
    <strong>Book:</strong> {$reservation['book_title']}<br>
    <strong>ISBN:</strong> {$reservation['isbn']}<br>
    <strong>Qty:</strong> {$reservation['qty']}</p>";
    wp_mail($email,"[$shop] New Book Reservation",$html,['Content-Type: text/html; charset=UTF-8']);
}
