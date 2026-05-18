<?php
/**
 * Online Book Catalogue, Click & Collect, Online Orders
 */
if(!defined('ABSPATH'))exit;

// ── Online Orders table helpers ───────────────────────────────────────────────
function bs_create_online_order($data){
    global $wpdb;
    $ref='OD-'.strtoupper(substr(md5(uniqid('',true)),0,8));
    $wpdb->insert("{$wpdb->prefix}bookshop_online_orders",[
        'ref'            =>$ref,
        'customer_name'  =>sanitize_text_field($data['name']??''),
        'customer_email' =>sanitize_email($data['email']??''),
        'customer_phone' =>sanitize_text_field($data['phone']??''),
        'customer_address'=>sanitize_textarea_field($data['address']??''),
        'items_data'     =>json_encode($data['items']??[]),
        'subtotal'       =>floatval($data['subtotal']??0),
        'total'          =>floatval($data['total']??0),
        'type'           =>in_array($data['type']??'pickup',['pickup','delivery'])?$data['type']:'pickup',
        'notes'          =>sanitize_textarea_field($data['notes']??''),
        'status'         =>'pending',
    ]);
    $id=$wpdb->insert_id;
    bs_audit('online_order_created','online_order',$id,"Order $ref created");
    // Notify admin
    $shop=get_option('bookshop_receipt_header',get_bloginfo('name'));
    $items=json_decode($wpdb->get_var($wpdb->prepare("SELECT items_data FROM {$wpdb->prefix}bookshop_online_orders WHERE id=%d",$id)),true);
    $item_lines=implode("\n",array_map(function($i){return "- {$i['title']} × {$i['qty']} = ".bs_fmt($i['price']*$i['qty']);},$items??[]));
    wp_mail(get_option('admin_email'),"[$shop] New Online Order — $ref",
        "Customer: {$data['name']}\nEmail: {$data['email']}\nPhone: {$data['phone']}\nType: {$data['type']}\n\n$item_lines\n\nTotal: ".bs_fmt($data['total']),
        ['Content-Type: text/plain; charset=UTF-8']);
    return['id'=>$id,'ref'=>$ref];
}
function bs_get_online_orders($args=[]){
    global $wpdb;
    $a=wp_parse_args($args,['status'=>'','limit'=>50,'offset'=>0]);
    $w=['1=1'];$p=[];
    if($a['status']){$w[]='status=%s';$p[]=$a['status'];}
    $sql="SELECT * FROM {$wpdb->prefix}bookshop_online_orders WHERE ".implode(' AND ',$w)." ORDER BY created_at DESC LIMIT %d OFFSET %d";
    $p[]=$a['limit'];$p[]=$a['offset'];
    return $wpdb->get_results($wpdb->prepare($sql,$p));
}
function bs_update_online_order_status($id,$status){
    global $wpdb;
    $wpdb->update("{$wpdb->prefix}bookshop_online_orders",['status'=>sanitize_text_field($status)],['id'=>intval($id)]);
    bs_audit('online_order_status','online_order',$id,"Status: $status");
}

// ── Shortcode: Book Catalogue ─────────────────────────────────────────────────
add_shortcode('bookshop_catalogue','bs_catalogue_shortcode');
function bs_catalogue_shortcode($atts){
    $a=shortcode_atts(['genre'=>'','limit'=>24,'show_cart'=>'yes'],$atts);
    $books=bs_get_books(['genre'=>$a['genre'],'limit'=>intval($a['limit']),'status'=>'active']);
    $genres=bs_genres();
    $cur=bs_currency();
    ob_start();
    wp_enqueue_style('bs-catalogue',BOOKSHOP_URL.'assets/css/catalogue.css',[],BOOKSHOP_VERSION);
    wp_enqueue_script('bs-catalogue',BOOKSHOP_URL.'assets/js/catalogue.js',['jquery'],BOOKSHOP_VERSION,true);
    wp_localize_script('bs-catalogue','BSCatalogue',['ajax_url'=>admin_url('admin-ajax.php'),'currency'=>$cur,'show_cart'=>$a['show_cart']]);
    ?>
    <div class="bs-catalogue-wrap" id="bs-catalogue">
        <div class="bs-cat-filters">
            <input type="text" id="bs-cat-search" placeholder="🔍 Search books..." class="bs-cat-input">
            <select id="bs-cat-genre" class="bs-cat-select">
                <option value="">All Genres</option>
                <?php foreach($genres as $g) echo "<option value='".esc_attr($g)."'>".esc_html($g)."</option>"; ?>
            </select>
        </div>
        <div class="bs-cat-grid" id="bs-cat-grid">
        <?php foreach($books as $b): ?>
            <div class="bs-cat-card" data-id="<?=esc_attr($b->id)?>" data-title="<?=esc_attr(strtolower($b->title))?>" data-genre="<?=esc_attr($b->genre)?>">
                <div class="bs-cat-cover">
                    <?php if($b->cover_url): ?><img src="<?=esc_url($b->cover_url)?>" alt="<?=esc_attr($b->title)?>"><?php else: ?><span>📖</span><?php endif; ?>
                    <?php if($b->stock_qty<=0): ?><div class="bs-cat-out-badge">Out of Stock</div><?php endif; ?>
                </div>
                <div class="bs-cat-info">
                    <div class="bs-cat-title"><?=esc_html($b->title)?></div>
                    <div class="bs-cat-author"><?=esc_html($b->author)?></div>
                    <div class="bs-cat-genre"><?=esc_html($b->genre)?></div>
                    <div class="bs-cat-price"><?=bs_fmt($b->sell_price)?></div>
                    <div class="bs-cat-actions">
                        <?php if($b->stock_qty>0&&$a['show_cart']==='yes'): ?>
                        <button class="bs-cat-btn bs-add-to-cart" data-id="<?=esc_attr($b->id)?>"
                            data-title="<?=esc_attr($b->title)?>" data-price="<?=esc_attr($b->sell_price)?>"
                            data-cover="<?=esc_attr($b->cover_url)?>">Add to Cart</button>
                        <?php endif; ?>
                        <button class="bs-cat-btn bs-cat-btn-outline bs-reserve-book"
                            data-title="<?=esc_attr($b->title)?>" data-isbn="<?=esc_attr($b->isbn)?>">Reserve</button>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
        <?php if($a['show_cart']==='yes'): ?>
        <!-- Online Cart / Checkout -->
        <div class="bs-online-cart" id="bs-online-cart" style="display:none">
            <div class="bs-online-cart-inner">
                <div class="bs-online-cart-header">
                    <h3>🛒 Your Order</h3>
                    <button onclick="document.getElementById('bs-online-cart').style.display='none'">✕</button>
                </div>
                <div id="bs-online-cart-items"></div>
                <div id="bs-online-cart-total" style="font-weight:700;text-align:right;padding:10px 0;border-top:1px solid #eee"></div>
                <div style="display:flex;gap:8px;margin-top:10px">
                    <button class="bs-cat-btn" style="flex:1" onclick="bsCheckout('pickup')">🏪 Click & Collect</button>
                    <button class="bs-cat-btn" style="flex:1" onclick="bsCheckout('delivery')">🚚 Delivery</button>
                </div>
            </div>
        </div>
        <button class="bs-cart-fab" id="bs-cart-fab" style="display:none" onclick="document.getElementById('bs-online-cart').style.display='block'">
            🛒 <span id="bs-cart-count">0</span>
        </button>
        <?php endif; ?>
    </div>
    <!-- Checkout Modal -->
    <div id="bs-checkout-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;display:flex;align-items:center;justify-content:center">
        <div style="background:#fff;border-radius:12px;padding:24px;max-width:480px;width:94%;max-height:90vh;overflow-y:auto">
            <h3 style="font-family:serif;margin-bottom:16px">Complete Your Order</h3>
            <input type="hidden" id="bs-order-type" value="pickup">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
                <div style="grid-column:1/-1"><input type="text" id="bs-ord-name" placeholder="Full Name *" style="width:100%;padding:9px;border:1.5px solid #e0d4c0;border-radius:8px;font-size:.9rem"></div>
                <div><input type="email" id="bs-ord-email" placeholder="Email *" style="width:100%;padding:9px;border:1.5px solid #e0d4c0;border-radius:8px;font-size:.9rem"></div>
                <div><input type="tel" id="bs-ord-phone" placeholder="Phone *" style="width:100%;padding:9px;border:1.5px solid #e0d4c0;border-radius:8px;font-size:.9rem"></div>
                <div style="grid-column:1/-1" id="bs-delivery-address-row"><textarea id="bs-ord-address" placeholder="Delivery Address" rows="2" style="width:100%;padding:9px;border:1.5px solid #e0d4c0;border-radius:8px;font-size:.9rem"></textarea></div>
                <div style="grid-column:1/-1"><textarea id="bs-ord-notes" placeholder="Notes (optional)" rows="2" style="width:100%;padding:9px;border:1.5px solid #e0d4c0;border-radius:8px;font-size:.9rem"></textarea></div>
            </div>
            <div id="bs-checkout-total" style="font-size:1.2rem;font-weight:700;margin:14px 0;text-align:center"></div>
            <div id="bs-pay-options" style="display:flex;gap:8px;flex-wrap:wrap">
                <?php if(get_option('bookshop_paystack_public_key')): ?>
                <button class="bs-cat-btn" style="flex:1" onclick="bsPayWithPaystack()">Pay with Paystack</button>
                <?php endif; ?>
                <?php if(get_option('bookshop_flutterwave_public_key')): ?>
                <button class="bs-cat-btn" style="flex:1;background:#f5a623" onclick="bsPayWithFlutterwave()">Pay with Flutterwave</button>
                <?php endif; ?>
                <button class="bs-cat-btn" style="flex:1;background:#2a7a3b" onclick="bsPayOnPickup()">Pay on Pickup/Delivery</button>
            </div>
            <div id="bs-checkout-msg" style="margin-top:10px;font-size:.85rem;color:#c0392b"></div>
            <button onclick="document.getElementById('bs-checkout-modal').style.display='none'" style="margin-top:12px;background:none;border:none;cursor:pointer;color:#999;font-size:.82rem">✕ Cancel</button>
        </div>
    </div>
    <?php
    // Inject payment keys safely
    $ps_pub=get_option('bookshop_paystack_public_key','');
    $flw_pub=get_option('bookshop_flutterwave_public_key','');
    echo "<script>var BSPaystack=".json_encode(['public_key'=>$ps_pub,'callback_url'=>home_url('/?bookshop_payment=paystack')]).";";
    echo "var BSFlutterwave=".json_encode(['public_key'=>$flw_pub,'currency'=>get_option('bookshop_flw_currency','NGN'),'callback_url'=>home_url('/?bookshop_payment=flutterwave')]).";";
    echo "var BSOrderStatus=".json_encode(['success'=>home_url('/?bookshop_order=success'),'failed'=>home_url('/?bookshop_order=failed')]).";";
    echo "</script>";
    return ob_get_clean();
}

// ── AJAX: Submit online order ─────────────────────────────────────────────────
add_action('wp_ajax_bs_submit_online_order','bs_ajax_submit_online_order');
add_action('wp_ajax_nopriv_bs_submit_online_order','bs_ajax_submit_online_order');
function bs_ajax_submit_online_order(){
    $items=json_decode(stripslashes($_POST['items']??'[]'),true);
    if(empty($items)){wp_send_json_error('No items');return;}
    $subtotal=array_sum(array_map(function($i){return floatval($i['price'])*intval($i['qty']);},$items));
    $res=bs_create_online_order([
        'name'   =>sanitize_text_field($_POST['name']??''),
        'email'  =>sanitize_email($_POST['email']??''),
        'phone'  =>sanitize_text_field($_POST['phone']??''),
        'address'=>sanitize_textarea_field($_POST['address']??''),
        'notes'  =>sanitize_textarea_field($_POST['notes']??''),
        'type'   =>sanitize_text_field($_POST['type']??'pickup'),
        'items'  =>$items,
        'subtotal'=>$subtotal,
        'total'  =>$subtotal,
    ]);
    wp_send_json_success($res);
}
// ── AJAX: Init Paystack payment ───────────────────────────────────────────────
add_action('wp_ajax_bs_init_paystack','bs_ajax_init_paystack');
add_action('wp_ajax_nopriv_bs_init_paystack','bs_ajax_init_paystack');
function bs_ajax_init_paystack(){
    $amount =floatval($_POST['amount']??0);
    $email  =sanitize_email($_POST['email']??'');
    $order_id=intval($_POST['order_id']??0);
    if(!$amount||!$email){wp_send_json_error('Missing data');return;}
    // Convert to kobo
    $kobo=intval($amount*100);
    $res=bs_paystack_init_transaction($kobo,$email,['type'=>'online_order','object_id'=>$order_id],home_url('/?bookshop_payment=paystack'));
    isset($res['error'])?wp_send_json_error($res['error']):wp_send_json_success($res);
}
// ── AJAX: Init Flutterwave payment ────────────────────────────────────────────
add_action('wp_ajax_bs_init_flutterwave','bs_ajax_init_flutterwave');
add_action('wp_ajax_nopriv_bs_init_flutterwave','bs_ajax_init_flutterwave');
function bs_ajax_init_flutterwave(){
    $amount  =floatval($_POST['amount']??0);
    $email   =sanitize_email($_POST['email']??'');
    $name    =sanitize_text_field($_POST['name']??'');
    $phone   =sanitize_text_field($_POST['phone']??'');
    $order_id=intval($_POST['order_id']??0);
    if(!$amount||!$email){wp_send_json_error('Missing data');return;}
    $res=bs_flutterwave_init_transaction($amount,$email,$name,$phone,['type'=>'online_order','object_id'=>$order_id]);
    isset($res['error'])?wp_send_json_error($res['error']):wp_send_json_success($res);
}
