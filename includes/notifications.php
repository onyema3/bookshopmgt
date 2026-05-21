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
    $tax_lbl=get_option('bookshop_tax_label','VAT');
    $rows='';
    foreach($items as $i){
        $rows.="<tr><td style='padding:6px 10px;border-bottom:1px solid #f0e8d8'>{$i->title}</td><td style='padding:6px 10px;border-bottom:1px solid #f0e8d8'>{$i->qty}</td><td style='padding:6px 10px;border-bottom:1px solid #f0e8d8'>".bs_fmt($i->unit_price)."</td><td style='padding:6px 10px;border-bottom:1px solid #f0e8d8'>".bs_fmt($i->line_total)."</td></tr>";
    }

    // Totals block — order matches the on-screen / printed receipt: subtotal,
    // discounts, tax, grand total, then the payment-breakdown rows for
    // tender forms (credit, points, gift card, split). Each line is
    // conditional so a plain cash sale stays clean.
    $totals_html = "<tr><td>Subtotal</td><td style='text-align:right'>".bs_fmt($sale->subtotal)."</td></tr>";
    if($sale->discount>0){
        $totals_html.="<tr><td>Discount</td><td style='text-align:right;color:#c0392b'>-".bs_fmt($sale->discount)."</td></tr>";
    }
    if($sale->promo_discount>0){
        $promo_label = $sale->promo_code ? " (".esc_html($sale->promo_code).")" : "";
        $totals_html.="<tr><td>Promo$promo_label</td><td style='text-align:right;color:#2a7a3b'>-".bs_fmt($sale->promo_discount)."</td></tr>";
    }
    if($sale->tax>0){
        $totals_html.="<tr><td>".esc_html($tax_lbl)."</td><td style='text-align:right'>".bs_fmt($sale->tax)."</td></tr>";
    }
    $totals_html.="<tr><td style='font-weight:bold;font-size:1.1em;border-top:2px solid #1a1208;padding-top:6px'>TOTAL</td><td style='text-align:right;font-weight:bold;font-size:1.1em;border-top:2px solid #1a1208;padding-top:6px'>".bs_fmt($sale->total)."</td></tr>";

    // Payment breakdown (rendered after grand total, like a thermal receipt)
    $breakdown_html = '';
    if($sale->credit_used>0){
        $breakdown_html.="<tr><td style='color:#8a7a65'>Store Credit</td><td style='text-align:right;color:#8a7a65'>-".bs_fmt($sale->credit_used)."</td></tr>";
    }
    if($sale->loyalty_redeemed>0){
        $loy_val = floatval(get_option('bookshop_loyalty_value',10));
        $loy_redeem_val = $sale->loyalty_redeemed * $loy_val;
        $breakdown_html.="<tr><td style='color:#8a7a65'>Points Redeemed</td><td style='text-align:right;color:#8a7a65'>-".bs_fmt($loy_redeem_val)." (".intval($sale->loyalty_redeemed)." pts)</td></tr>";
    }
    if($sale->payment_method==='gift_card'){
        $pay_det = json_decode($sale->payment_details,true);
        $gc_code = $pay_det['gc_code'] ?? '';
        $gc_label = $gc_code ? 'GIFT CARD '.substr($gc_code,-4) : 'Gift Card';
        $breakdown_html.="<tr><td style='color:#8a7a65'>Gift Card</td><td style='text-align:right;color:#8a7a65'>".esc_html($gc_label)."</td></tr>";
    }
    if($sale->payment_method==='split'){
        $pay_det = json_decode($sale->payment_details,true);
        $split_cash = floatval($pay_det['cash'] ?? 0);
        $split_card = floatval($pay_det['card'] ?? 0);
        $breakdown_html.="<tr><td style='color:#8a7a65'>Cash</td><td style='text-align:right;color:#8a7a65'>".bs_fmt($split_cash)."</td></tr>";
        $breakdown_html.="<tr><td style='color:#8a7a65'>Card</td><td style='text-align:right;color:#8a7a65'>".bs_fmt($split_card)."</td></tr>";
    }
    if($breakdown_html){
        $totals_html .= "<tr><td colspan='2' style='padding-top:8px'><div style='border-top:1px dashed #ccc;margin:4px 0'></div></td></tr>".$breakdown_html;
    }

    // Loyalty earned banner under totals
    $loyalty_earned_html = '';
    if($sale->loyalty_earned>0){
        $loyalty_earned_html = "<p style='margin-top:14px;text-align:center;color:#2a7a3b;font-weight:600'>+".intval($sale->loyalty_earned)." loyalty points earned!</p>";
    }

    $payment_method_label = strtoupper(str_replace('_',' ',$sale->payment_method));

    $html="<div style='font-family:Georgia,serif;max-width:520px;margin:auto;background:#fdf8f0;padding:28px;border-radius:10px'>
    <h2 style='color:#1a1208'>$shop</h2>
    <p style='color:#8a7a65'>Receipt — Ref: <strong>{$sale->sale_ref}</strong><br>
    Payment: <strong>".esc_html($payment_method_label)."</strong></p>
    <table style='width:100%;border-collapse:collapse;background:#fff;border-radius:6px;overflow:hidden'>
    <thead><tr style='background:#1a1208;color:#f5d87a'><th style='padding:8px 10px;text-align:left'>Book</th><th style='padding:8px 10px'>Qty</th><th style='padding:8px 10px'>Price</th><th style='padding:8px 10px'>Total</th></tr></thead>
    <tbody>$rows</tbody></table>
    <table style='margin-top:14px;width:100%'>
    $totals_html
    </table>
    $loyalty_earned_html
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
