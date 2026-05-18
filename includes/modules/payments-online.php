<?php
/**
 * Online Payment Integration — Paystack & Flutterwave
 * Used for reservation deposits and online orders
 */
if(!defined('ABSPATH'))exit;

// ── Paystack ──────────────────────────────────────────────────────────────────
function bs_paystack_init_transaction($amount_kobo,$email,$metadata=[],$callback_url=''){
    $key=get_option('bookshop_paystack_secret_key','');
    if(!$key) return['error'=>'Paystack secret key not configured'];
    if(!$callback_url) $callback_url=home_url('/?bookshop_payment=paystack');
    $res=wp_remote_post('https://api.paystack.co/transaction/initialize',[
        'timeout'=>15,
        'headers'=>['Authorization'=>'Bearer '.$key,'Content-Type'=>'application/json'],
        'body'   =>json_encode([
            'email'       =>$email,
            'amount'      =>intval($amount_kobo), // in kobo (NGN) or pesewas (GHS)
            'callback_url'=>$callback_url,
            'metadata'    =>$metadata,
        ]),
    ]);
    if(is_wp_error($res)) return['error'=>$res->get_error_message()];
    $body=json_decode(wp_remote_retrieve_body($res),true);
    return $body['status']?['auth_url'=>$body['data']['authorization_url'],'ref'=>$body['data']['reference']]:['error'=>$body['message']??'Init failed'];
}
function bs_paystack_verify($reference){
    $key=get_option('bookshop_paystack_secret_key','');
    if(!$key) return false;
    $res=wp_remote_get("https://api.paystack.co/transaction/verify/".rawurlencode($reference),[
        'timeout'=>15,
        'headers'=>['Authorization'=>'Bearer '.$key],
    ]);
    if(is_wp_error($res)) return false;
    $body=json_decode(wp_remote_retrieve_body($res),true);
    return ($body['status']&&$body['data']['status']==='success')?$body['data']:false;
}

// ── Flutterwave ───────────────────────────────────────────────────────────────
function bs_flutterwave_init_transaction($amount,$email,$name,$phone,$metadata=[],$callback_url=''){
    $key=get_option('bookshop_flutterwave_secret_key','');
    if(!$key) return['error'=>'Flutterwave secret key not configured'];
    if(!$callback_url) $callback_url=home_url('/?bookshop_payment=flutterwave');
    $tx_ref='BS-FLW-'.time().'-'.rand(100,999);
    $res=wp_remote_post('https://api.flutterwave.com/v3/payments',[
        'timeout'=>15,
        'headers'=>['Authorization'=>'Bearer '.$key,'Content-Type'=>'application/json'],
        'body'   =>json_encode([
            'tx_ref'      =>$tx_ref,
            'amount'      =>floatval($amount),
            'currency'    =>get_option('bookshop_flw_currency','NGN'),
            'redirect_url'=>$callback_url,
            'customer'    =>['email'=>$email,'name'=>$name,'phonenumber'=>$phone],
            'meta'        =>$metadata,
            'customizations'=>['title'=>get_option('bookshop_receipt_header',get_bloginfo('name'))],
        ]),
    ]);
    if(is_wp_error($res)) return['error'=>$res->get_error_message()];
    $body=json_decode(wp_remote_retrieve_body($res),true);
    return ($body['status']==='success')?['auth_url'=>$body['data']['link'],'ref'=>$tx_ref]:['error'=>$body['message']??'Init failed'];
}
function bs_flutterwave_verify($transaction_id){
    $key=get_option('bookshop_flutterwave_secret_key','');
    if(!$key) return false;
    $res=wp_remote_get("https://api.flutterwave.com/v3/transactions/".intval($transaction_id)."/verify",[
        'timeout'=>15,'headers'=>['Authorization'=>'Bearer '.$key],
    ]);
    if(is_wp_error($res)) return false;
    $body=json_decode(wp_remote_retrieve_body($res),true);
    return ($body['status']==='success'&&$body['data']['status']==='successful')?$body['data']:false;
}

// ── Payment callback handler ──────────────────────────────────────────────────
add_action('init',function(){
    if(empty($_GET['bookshop_payment'])) return;
    $gateway=sanitize_text_field($_GET['bookshop_payment']);
    if($gateway==='paystack'){
        $ref=sanitize_text_field($_GET['reference']??'');
        if(!$ref) return;
        $data=bs_paystack_verify($ref);
        if($data){
            bs_handle_online_payment_success($ref,$data['amount']/100,'paystack',$data['metadata']??[]);
        }
        wp_redirect(home_url('/?bookshop_order_status='.($data?'success':'failed').'&ref='.urlencode($ref)));
        exit;
    }
    if($gateway==='flutterwave'){
        $tx_id=intval($_GET['transaction_id']??0);
        $status=sanitize_text_field($_GET['status']??'');
        if($status==='successful'&&$tx_id){
            $data=bs_flutterwave_verify($tx_id);
            if($data) bs_handle_online_payment_success($data['tx_ref'],$data['amount'],'flutterwave',$data['meta']??[]);
        }
        $ref=sanitize_text_field($_GET['tx_ref']??'');
        wp_redirect(home_url('/?bookshop_order_status='.($status==='successful'?'success':'failed').'&ref='.urlencode($ref)));
        exit;
    }
});

function bs_handle_online_payment_success($ref,$amount,$gateway,$meta){
    global $wpdb;
    // Update reservation or online order status
    $type=$meta['type']??'reservation';
    $object_id=intval($meta['object_id']??0);
    if($type==='reservation'&&$object_id){
        // Idempotency — only update if the same payment ref hasn't already been recorded.
        $existing_ref=$wpdb->get_var($wpdb->prepare(
            "SELECT payment_ref FROM {$wpdb->prefix}bookshop_reservations WHERE id=%d",$object_id));
        if($existing_ref===$ref) return; // already processed
        $wpdb->update("{$wpdb->prefix}bookshop_reservations",['status'=>'notified','payment_ref'=>$ref,'payment_amount'=>$amount,'payment_gateway'=>$gateway],['id'=>$object_id]);
        bs_audit('online_payment','payment',$object_id,"$gateway reservation payment: $ref — ".bs_fmt($amount));
        // Notify admin
        $shop=get_option('bookshop_receipt_header',get_bloginfo('name'));
        wp_mail(get_option('admin_email'),"[$shop] Reservation Payment Received","Ref: $ref | Amount: ".bs_fmt($amount)." via $gateway");
    } elseif($type==='online_order'&&$object_id){
        // Idempotency — skip if same payment ref already recorded.
        $existing=$wpdb->get_row($wpdb->prepare(
            "SELECT status,payment_ref FROM {$wpdb->prefix}bookshop_online_orders WHERE id=%d",$object_id));
        if(!$existing) return;
        if($existing->payment_ref===$ref){
            return; // already processed
        }
        $wpdb->update("{$wpdb->prefix}bookshop_online_orders",
            ['status'=>'paid','payment_ref'=>$ref,'payment_amount'=>$amount,'payment_gateway'=>$gateway],
            ['id'=>$object_id]
        );
        bs_audit('online_payment','payment',$object_id,"$gateway online_order payment: $ref — ".bs_fmt($amount));
    }
}
