<?php
/**
 * Bulk Customer Messaging — Email & WhatsApp
 */
if(!defined('ABSPATH'))exit;

// ── HTML wrapper for branded bulk emails ──────────────────────────────────────
// Mirrors the palette used by the order-status email in online-store.php so
// every customer-facing email looks like it came from the same shop.
function bs_render_bulk_email_html($personalised_body,$subject=''){
    $shop    = get_option('bookshop_receipt_header', get_bloginfo('name'));
    $tagline = get_option('bookshop_tagline', '');
    $logo    = get_option('bookshop_logo_url', '');
    $address = get_option('bookshop_address', '');
    $phone   = get_option('bookshop_phone', '');
    $email   = get_option('bookshop_store_email', get_option('admin_email'));

    // Plain-text body → safe HTML. esc_html first so admins can't inject markup;
    // wpautop turns blank-line-separated paragraphs into <p>…</p> and single
    // newlines into <br>, which is what most admins will expect when typing
    // into a textarea.
    $body_html = wpautop( esc_html( $personalised_body ) );

    $logo_html = $logo
        ? '<img src="'.esc_url($logo).'" alt="'.esc_attr($shop).'" style="max-height:56px;margin-bottom:8px">'
        : '<div style="font-family:\'Playfair Display\',Georgia,serif;font-size:1.7em;font-weight:700;color:#1a1208;letter-spacing:.02em">'.esc_html($shop).'</div>';

    $tagline_html = $tagline
        ? '<div style="margin-top:4px;color:#8a7a65;font-size:.85em;font-style:italic">'.esc_html($tagline).'</div>'
        : '';

    $heading_html = $subject
        ? '<h2 style="margin:0 0 14px;font-family:\'Playfair Display\',Georgia,serif;font-size:1.25em;color:#1a1208">'.esc_html($subject).'</h2>'
        : '';

    $contact_lines = [];
    if ($address) $contact_lines[] = esc_html($address);
    if ($phone)   $contact_lines[] = '📞 '.esc_html($phone);
    if ($email)   $contact_lines[] = '✉ '.esc_html($email);
    $contact_html = $contact_lines
        ? '<div style="margin-bottom:6px;line-height:1.5">'.implode(' &middot; ',$contact_lines).'</div>'
        : '';

    return '
    <div style="background:#f5ede0;padding:24px 12px;font-family:Georgia,serif">
      <div style="max-width:600px;margin:auto;background:#fdf8f0;border-radius:12px;overflow:hidden;color:#1a1208;box-shadow:0 4px 18px rgba(26,18,8,.08)">
        <div style="background:#1a1208;color:#f5d87a;padding:24px 28px;text-align:center">
          '.$logo_html.$tagline_html.'
        </div>
        <div style="padding:28px">
          '.$heading_html.'
          <div style="background:#fff;border-radius:8px;padding:22px 24px;line-height:1.65;font-size:.96em;color:#2a1f10">
            '.$body_html.'
          </div>
        </div>
        <div style="background:#1a1208;color:#bfae8d;padding:20px 28px;text-align:center;font-size:.78em;line-height:1.5">
          '.$contact_html.'
          <div style="margin-top:8px;color:#8a7a65">— '.esc_html($shop).'</div>
        </div>
      </div>
    </div>';
}

// ── Bulk Email ────────────────────────────────────────────────────────────────
function bs_send_bulk_email($customer_ids,$subject,$body,$from_name=''){
    if(!$from_name) $from_name=get_option('bookshop_receipt_header',get_bloginfo('name'));
    $sent=0;$failed=0;
    foreach($customer_ids as $id){
        $c=bs_get_customer(intval($id));
        if(!$c||!$c->email) continue;
        $personalised=str_replace(
            ['{name}','{first_name}','{points}'],
            [$c->name,explode(' ',$c->name)[0],$c->loyalty_points],
            $body
        );
        $html = bs_render_bulk_email_html($personalised,$subject);
        $headers=['Content-Type: text/html; charset=UTF-8',"From: $from_name <".get_option('bookshop_store_email',get_option('admin_email')).">"];
        $ok=wp_mail($c->email,$subject,$html,$headers);
        $ok?$sent++:$failed++;
        // Log
        global $wpdb;
        $wpdb->insert("{$wpdb->prefix}bookshop_messages_queue",[
            'customer_id'=>$c->id,'type'=>'bulk_email','message'=>$personalised,
            'phone'=>$c->phone,'email'=>$c->email,'status'=>$ok?'sent':'failed',
        ]);
    }
    return['sent'=>$sent,'failed'=>$failed];
}

// ── Bulk WhatsApp (generates wa.me links for dispatch) ────────────────────────
function bs_bulk_whatsapp_links($customer_ids,$message){
    $links=[];
    foreach($customer_ids as $id){
        $c=bs_get_customer(intval($id));
        if(!$c||!$c->phone) continue;
        $phone=preg_replace('/[^0-9]/','',$c->phone);
        $personalised=str_replace(
            ['{name}','{first_name}','{points}'],
            [$c->name,explode(' ',$c->name)[0],$c->loyalty_points],
            $message
        );
        $links[]=['name'=>$c->name,'phone'=>$phone,'url'=>'https://wa.me/'.$phone.'?text='.rawurlencode($personalised)];
    }
    return $links;
}

// ── Message history ───────────────────────────────────────────────────────────
function bs_get_message_log($args=[]){
    global $wpdb;
    $a=wp_parse_args($args,['limit'=>100,'offset'=>0,'type'=>'']);
    $w=['1=1'];$p=[];
    if($a['type']){$w[]='type=%s';$p[]=$a['type'];}
    $sql="SELECT mq.*,c.name AS customer_name FROM {$wpdb->prefix}bookshop_messages_queue mq
          LEFT JOIN {$wpdb->prefix}bookshop_customers c ON c.id=mq.customer_id
          WHERE ".implode(' AND ',$w)." ORDER BY mq.created_at DESC LIMIT %d OFFSET %d";
    $p[]=$a['limit'];$p[]=$a['offset'];
    return $wpdb->get_results($wpdb->prepare($sql,$p));
}

// ── AJAX: Send bulk email ─────────────────────────────────────────────────────
add_action('wp_ajax_bs_send_bulk_email',function(){
    if(!bs_user_can_manage()) wp_send_json_error('Unauthorized',403);
    $ids  =json_decode(stripslashes($_POST['customer_ids']??'[]'),true);
    $subj =sanitize_text_field($_POST['subject']??'');
    $body =sanitize_textarea_field($_POST['body']??'');
    if(empty($ids)||!$subj||!$body) wp_send_json_error('Missing fields');
    $res=bs_send_bulk_email($ids,$subj,$body);
    wp_send_json_success($res);
});

// ── AJAX: Get WhatsApp links ──────────────────────────────────────────────────
add_action('wp_ajax_bs_get_whatsapp_links',function(){
    if(!bs_user_can_manage()) wp_send_json_error('Unauthorized',403);
    $ids =json_decode(stripslashes($_POST['customer_ids']??'[]'),true);
    $msg =sanitize_textarea_field($_POST['message']??'');
    if(empty($ids)||!$msg) wp_send_json_error('Missing fields');
    $links=bs_bulk_whatsapp_links($ids,$msg);
    wp_send_json_success($links);
});

// ── AJAX: Get customer segments ───────────────────────────────────────────────
add_action('wp_ajax_bs_get_customer_segment',function(){
    if(!bs_user_can_manage()) wp_send_json_error('Unauthorized',403);
    $genre=sanitize_text_field($_GET['genre']??'');
    $days =intval($_GET['days']??180);
    $spend=floatval($_GET['min_spend']??0);
    $customers=bs_segment_customers($genre,$days,$spend);
    wp_send_json_success($customers);
});

// ── AJAX: Preview rendered email body ─────────────────────────────────────────
// Lets the admin see exactly what the branded message will look like before
// firing it out to every recipient.
add_action('wp_ajax_bs_preview_bulk_email',function(){
    if(!bs_user_can_manage()) wp_send_json_error('Unauthorized',403);
    $subj = sanitize_text_field( $_POST['subject'] ?? '' );
    $body = sanitize_textarea_field( $_POST['body'] ?? '' );
    if ( ! $body ) wp_send_json_error( 'Body required' );

    // Substitute placeholders with sample data so the preview shows what a
    // real customer would see.
    $sample = str_replace(
        ['{name}','{first_name}','{points}'],
        ['Jane Doe','Jane','120'],
        $body
    );
    wp_send_json_success([
        'html'    => bs_render_bulk_email_html( $sample, $subj ),
        'subject' => $subj,
    ]);
});
