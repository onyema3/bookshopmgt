<?php
/**
 * REST API — exposes books, stock, sales as JSON endpoints
 * Base: /wp-json/bookshop/v1/
 */
if(!defined('ABSPATH'))exit;

add_action('rest_api_init',function(){
    $ns='bookshop/v1';

    // ── Books ──────────────────────────────────────────────────────────────────
    register_rest_route($ns,'/books',['methods'=>'GET','callback'=>'bs_api_get_books','permission_callback'=>'bs_api_auth']);
    register_rest_route($ns,'/books/(?P<id>\d+)',['methods'=>'GET','callback'=>'bs_api_get_book','permission_callback'=>'bs_api_auth']);
    register_rest_route($ns,'/books',['methods'=>'POST','callback'=>'bs_api_create_book','permission_callback'=>'bs_api_admin_auth']);
    register_rest_route($ns,'/books/(?P<id>\d+)',['methods'=>'PUT','callback'=>'bs_api_update_book','permission_callback'=>'bs_api_admin_auth']);

    // ── Stock ──────────────────────────────────────────────────────────────────
    register_rest_route($ns,'/stock/(?P<id>\d+)',['methods'=>'PATCH','callback'=>'bs_api_update_stock','permission_callback'=>'bs_api_admin_auth']);
    register_rest_route($ns,'/stock/low',['methods'=>'GET','callback'=>'bs_api_low_stock','permission_callback'=>'bs_api_auth']);

    // ── Sales ──────────────────────────────────────────────────────────────────
    register_rest_route($ns,'/sales',['methods'=>'GET','callback'=>'bs_api_get_sales','permission_callback'=>'bs_api_admin_auth']);
    register_rest_route($ns,'/sales/(?P<id>\d+)',['methods'=>'GET','callback'=>'bs_api_get_sale','permission_callback'=>'bs_api_admin_auth']);

    // ── Customers ──────────────────────────────────────────────────────────────
    register_rest_route($ns,'/customers',['methods'=>'GET','callback'=>'bs_api_get_customers','permission_callback'=>'bs_api_admin_auth']);
    register_rest_route($ns,'/customers/(?P<id>\d+)',['methods'=>'GET','callback'=>'bs_api_get_customer_full','permission_callback'=>'bs_api_admin_auth']);

    // ── Reports ────────────────────────────────────────────────────────────────
    register_rest_route($ns,'/reports/summary',['methods'=>'GET','callback'=>'bs_api_report_summary','permission_callback'=>'bs_api_admin_auth']);
    register_rest_route($ns,'/reports/top-books',['methods'=>'GET','callback'=>'bs_api_top_books','permission_callback'=>'bs_api_admin_auth']);

    // ── Webhooks ────────────────────────────────────────────────────────────────
    register_rest_route($ns,'/webhooks',['methods'=>'GET','callback'=>'bs_api_get_webhooks','permission_callback'=>'bs_api_admin_auth']);
    register_rest_route($ns,'/webhooks',['methods'=>'POST','callback'=>'bs_api_save_webhook','permission_callback'=>'bs_api_admin_auth']);
    register_rest_route($ns,'/webhooks/(?P<id>\d+)',['methods'=>'DELETE','callback'=>'bs_api_delete_webhook','permission_callback'=>'bs_api_admin_auth']);

    // ── Online Orders ───────────────────────────────────────────────────────────
    register_rest_route($ns,'/online-orders',['methods'=>'GET','callback'=>'bs_api_get_online_orders','permission_callback'=>'bs_api_admin_auth']);
    register_rest_route($ns,'/online-orders/(?P<id>\d+)',['methods'=>'GET','callback'=>'bs_api_get_online_order','permission_callback'=>'bs_api_admin_auth']);
    register_rest_route($ns,'/online-orders/(?P<id>\d+)',['methods'=>'PATCH','callback'=>'bs_api_update_online_order','permission_callback'=>'bs_api_admin_auth']);
    register_rest_route($ns,'/online-orders/(?P<id>\d+)/payment',['methods'=>'POST','callback'=>'bs_api_record_online_order_payment','permission_callback'=>'bs_api_admin_auth']);
});

// ── Auth ───────────────────────────────────────────────────────────────────────
function bs_api_auth($request){
    $key=$request->get_header('X-Bookshop-Key')?:($request->get_param('api_key')?:'');
    $stored=get_option('bookshop_api_key','');
    return $stored&&hash_equals($stored,$key);
}
function bs_api_admin_auth($request){
    return bs_api_auth($request)||current_user_can('manage_options');
}

// ── Handlers ───────────────────────────────────────────────────────────────────
function bs_api_get_books($req){
    $books=bs_get_books(['search'=>$req->get_param('search')??'','limit'=>intval($req->get_param('limit')?:50),'offset'=>intval($req->get_param('offset')?:0),'status'=>$req->get_param('status')?:'active']);
    return new WP_REST_Response(['data'=>$books,'count'=>count($books)],200);
}
function bs_api_get_book($req){
    $b=bs_get_book(intval($req['id']));
    return $b?new WP_REST_Response($b,200):new WP_Error('not_found','Book not found',['status'=>404]);
}
function bs_api_create_book($req){
    $id=bs_save_book($req->get_params());
    return $id?new WP_REST_Response(['id'=>$id],201):new WP_Error('save_failed','Could not save book',['status'=>400]);
}
function bs_api_update_book($req){
    $id=bs_save_book($req->get_params(),intval($req['id']));
    return $id?new WP_REST_Response(['id'=>$id],200):new WP_Error('save_failed','Could not update book',['status'=>400]);
}
function bs_api_update_stock($req){
    $ok=bs_adjust_stock(intval($req['id']),intval($req->get_param('qty')?:0),'API update');
    return $ok?new WP_REST_Response(['updated'=>true],200):new WP_Error('not_found','Book not found',['status'=>404]);
}
function bs_api_low_stock($req){
    $books=bs_get_books(['low_stock'=>true,'limit'=>100]);
    return new WP_REST_Response(['data'=>$books],200);
}
function bs_api_get_sales($req){
    $sales=bs_get_sales(['from'=>$req->get_param('from')?:'','to'=>$req->get_param('to')?:'','limit'=>intval($req->get_param('limit')?:50),'offset'=>intval($req->get_param('offset')?:0)]);
    return new WP_REST_Response(['data'=>$sales],200);
}
function bs_api_get_sale($req){
    $sale=bs_get_sale(intval($req['id']));
    if(!$sale) return new WP_Error('not_found','Sale not found',['status'=>404]);
    $sale->items=bs_get_sale_items($sale->id);
    return new WP_REST_Response($sale,200);
}
function bs_api_get_customers($req){
    $c=bs_get_customers(['search'=>$req->get_param('search')?:'','limit'=>intval($req->get_param('limit')?:50),'offset'=>intval($req->get_param('offset')?:0)]);
    return new WP_REST_Response(['data'=>$c],200);
}
function bs_api_get_customer_full($req){
    $c=bs_get_customer(intval($req['id']));
    if(!$c) return new WP_Error('not_found','Customer not found',['status'=>404]);
    $c->tier=bs_get_customer_tier($c->id);
    $c->recent_sales=bs_get_sales(['customer_id'=>$c->id,'limit'=>10]);
    return new WP_REST_Response($c,200);
}
function bs_api_report_summary($req){
    $sum=bs_report_summary($req->get_param('from')?:'',$req->get_param('to')?:'');
    $profit=bs_report_profit($req->get_param('from')?:'',$req->get_param('to')?:'');
    return new WP_REST_Response(array_merge((array)$sum,(array)$profit),200);
}
function bs_api_top_books($req){
    $top=bs_report_top_books($req->get_param('from')?:'',$req->get_param('to')?:'',intval($req->get_param('limit')?:10));
    return new WP_REST_Response(['data'=>$top],200);
}

// ── Webhooks ───────────────────────────────────────────────────────────────────
function bs_api_get_webhooks($req){
    global $wpdb;
    return new WP_REST_Response($wpdb->get_results("SELECT * FROM {$wpdb->prefix}bookshop_webhooks ORDER BY created_at DESC"),200);
}
function bs_api_save_webhook($req){
    global $wpdb;
    $wpdb->insert("{$wpdb->prefix}bookshop_webhooks",[
        'url'   =>esc_url_raw($req->get_param('url')?:''),
        'event' =>sanitize_text_field($req->get_param('event')?:'sale.completed'),
        'secret'=>sanitize_text_field($req->get_param('secret')?:''),
        'status'=>'active',
    ]);
    return new WP_REST_Response(['id'=>$wpdb->insert_id],201);
}
function bs_api_delete_webhook($req){
    global $wpdb;
    $wpdb->delete("{$wpdb->prefix}bookshop_webhooks",['id'=>intval($req['id'])]);
    return new WP_REST_Response(['deleted'=>true],200);
}

// ── Fire webhooks on sale ──────────────────────────────────────────────────────
function bs_fire_webhooks($event,$payload){
    global $wpdb;
    $hooks=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}bookshop_webhooks WHERE status='active' AND (event=%s OR event='*')",$event));
    foreach($hooks as $hook){
        $body=json_encode(['event'=>$event,'data'=>$payload,'timestamp'=>time()]);
        $sig=$hook->secret?hash_hmac('sha256',$body,$hook->secret):'';
        wp_remote_post($hook->url,[
            'timeout'=>5,'blocking'=>false,
            'headers'=>['Content-Type'=>'application/json','X-Bookshop-Signature'=>$sig],
            'body'   =>$body,
        ]);
    }
}
add_action('bs_sale_completed',function($sale_id){
    $sale=bs_get_sale($sale_id);
    if($sale) bs_fire_webhooks('sale.completed',(array)$sale);
});
add_action('bs_after_stock_change',function($book_id){
    $book=bs_get_book($book_id);
    if($book&&$book->stock_qty<=$book->low_stock_threshold) bs_fire_webhooks('stock.low',(array)$book);
});


// ── Online-order endpoint handlers ─────────────────────────────────────────────
function bs_api_get_online_orders($req){
    $args=[
        'status'=>sanitize_text_field($req->get_param('status')??''),
        'limit' =>min(200,max(1,intval($req->get_param('limit')??50))),
        'offset'=>max(0,intval($req->get_param('offset')??0)),
    ];
    return rest_ensure_response(bs_get_online_orders($args));
}

function bs_api_get_online_order($req){
    global $wpdb;
    $id=intval($req['id']);
    $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}bookshop_online_orders WHERE id=%d",$id));
    if(!$row) return new WP_Error('not_found','Online order not found',['status'=>404]);
    $row->items=json_decode($row->items_data,true);
    return rest_ensure_response($row);
}

function bs_api_update_online_order($req){
    $id=intval($req['id']);
    $params=$req->get_json_params();
    if(!is_array($params)) $params=$req->get_params();
    $status=isset($params['status'])?sanitize_text_field($params['status']):'';
    if(!$status){
        return new WP_Error('missing_status','status field required',['status'=>400]);
    }
    $res=bs_update_online_order_status($id,$status);
    if(is_array($res) && isset($res['error'])){
        return new WP_Error('update_failed',$res['error'],['status'=>400]);
    }
    return rest_ensure_response($res);
}

function bs_api_record_online_order_payment($req){
    global $wpdb;
    $id=intval($req['id']);
    $params=$req->get_json_params();
    if(!is_array($params)) $params=$req->get_params();
    $payment_ref    =sanitize_text_field($params['payment_ref']??'');
    $payment_amount =isset($params['payment_amount'])?floatval($params['payment_amount']):null;
    $payment_gateway=sanitize_text_field($params['payment_gateway']??'manual');
    $mark_completed =!empty($params['complete']);
    if(!$payment_ref){
        return new WP_Error('missing_ref','payment_ref required',['status'=>400]);
    }
    $order=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}bookshop_online_orders WHERE id=%d",$id));
    if(!$order) return new WP_Error('not_found','Online order not found',['status'=>404]);
    // Idempotency: if same payment_ref already recorded, just return current state.
    if($order->payment_ref===$payment_ref){
        return rest_ensure_response(['ok'=>true,'no_change'=>true,'order_id'=>$id]);
    }
    $wpdb->update("{$wpdb->prefix}bookshop_online_orders",[
        'status'         =>'paid',
        'payment_ref'    =>$payment_ref,
        'payment_amount' =>$payment_amount===null?$order->total:$payment_amount,
        'payment_gateway'=>$payment_gateway,
    ],['id'=>$id]);
    bs_audit('online_payment','payment',$id,"$payment_gateway online_order payment via API: $payment_ref");
    if($mark_completed){
        bs_update_online_order_status($id,'completed');
    }
    return rest_ensure_response(['ok'=>true,'order_id'=>$id,'status'=>$mark_completed?'completed':'paid']);
}
