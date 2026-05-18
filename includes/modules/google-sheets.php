<?php
/**
 * Google Sheets Sync — pushes daily sales to a Google Sheet via Apps Script
 * Setup: Create a Google Apps Script Web App that accepts POST JSON, paste its URL in Settings
 */
if(!defined('ABSPATH'))exit;

function bs_sync_to_google_sheets($date=''){
    $url=get_option('bookshop_google_sheets_url','');
    if(!$url) return['error'=>'Google Sheets URL not configured'];
    if(!$date) $date=date('Y-m-d');

    $sales=bs_get_sales(['from'=>$date,'to'=>$date,'limit'=>1000]);
    $rows=[];
    foreach($sales as $s){
        $items=bs_get_sale_items($s->id);
        foreach($items as $item){
            $rows[]=[
                'date'    =>wp_date('d/m/Y',strtotime($s->created_at)),
                'time'    =>wp_date('H:i',strtotime($s->created_at)),
                'ref'     =>$s->sale_ref,
                'staff'   =>$s->staff_name,
                'customer'=>$s->customer_name??'Walk-in',
                'payment' =>$s->payment_method,
                'title'   =>$item->title,
                'author'  =>$item->author,
                'isbn'    =>$item->isbn,
                'qty'     =>intval($item->qty),
                'price'   =>floatval($item->unit_price),
                'total'   =>floatval($item->line_total),
                'sale_total'=>floatval($s->total),
            ];
        }
    }

    $payload=json_encode([
        'shop'  =>get_option('bookshop_receipt_header',get_bloginfo('name')),
        'date'  =>$date,
        'rows'  =>$rows,
        'synced'=>current_time('c'),
    ]);

    $res=wp_remote_post($url,[
        'timeout'=>15,
        'headers'=>['Content-Type'=>'application/json'],
        'body'   =>$payload,
    ]);

    if(is_wp_error($res)) return['error'=>$res->get_error_message()];
    update_option('bookshop_last_sheets_sync',current_time('mysql'));
    return['success'=>true,'rows'=>count($rows),'date'=>$date];
}

// Auto-sync daily
add_action('bookshop_daily_tasks','bs_auto_sync_sheets');
function bs_auto_sync_sheets(){
    if(get_option('bookshop_google_sheets_url')) bs_sync_to_google_sheets(date('Y-m-d',strtotime('-1 day')));
}

// Manual AJAX sync
add_action('wp_ajax_bs_sync_sheets',function(){
    if(!bs_user_can_manage()) wp_send_json_error('Unauthorized');
    $date=sanitize_text_field($_POST['date']??date('Y-m-d'));
    $res=bs_sync_to_google_sheets($date);
    isset($res['error'])?wp_send_json_error($res['error']):wp_send_json_success($res);
});
