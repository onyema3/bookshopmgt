<?php
/**
 * Bulk Customer Messaging — Email & WhatsApp
 */
if(!defined('ABSPATH'))exit;

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
        $headers=['Content-Type: text/html; charset=UTF-8',"From: $from_name <".get_option('bookshop_store_email',get_option('admin_email')).">"];
        $ok=wp_mail($c->email,$subject,nl2br($personalised),$headers);
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
    if(!current_user_can('manage_options')) wp_send_json_error('Unauthorized',403);
    $ids  =json_decode(stripslashes($_POST['customer_ids']??'[]'),true);
    $subj =sanitize_text_field($_POST['subject']??'');
    $body =sanitize_textarea_field($_POST['body']??'');
    if(empty($ids)||!$subj||!$body) wp_send_json_error('Missing fields');
    $res=bs_send_bulk_email($ids,$subj,$body);
    wp_send_json_success($res);
});

// ── AJAX: Get WhatsApp links ──────────────────────────────────────────────────
add_action('wp_ajax_bs_get_whatsapp_links',function(){
    if(!current_user_can('manage_options')) wp_send_json_error('Unauthorized',403);
    $ids =json_decode(stripslashes($_POST['customer_ids']??'[]'),true);
    $msg =sanitize_textarea_field($_POST['message']??'');
    if(empty($ids)||!$msg) wp_send_json_error('Missing fields');
    $links=bs_bulk_whatsapp_links($ids,$msg);
    wp_send_json_success($links);
});

// ── AJAX: Get customer segments ───────────────────────────────────────────────
add_action('wp_ajax_bs_get_customer_segment',function(){
    if(!current_user_can('manage_options')) wp_send_json_error('Unauthorized',403);
    $genre=sanitize_text_field($_GET['genre']??'');
    $days =intval($_GET['days']??180);
    $spend=floatval($_GET['min_spend']??0);
    $customers=bs_segment_customers($genre,$days,$spend);
    wp_send_json_success($customers);
});
