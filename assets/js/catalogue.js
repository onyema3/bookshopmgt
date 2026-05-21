/* Bookshop Online Catalogue JS */
(function($){
var cart=[];
var orderType='pickup';

// ── Filter ─────────────────────────────────────────────────────────────────
$('#bs-cat-search').on('input',filterCatalogue);
$('#bs-cat-genre').on('change',filterCatalogue);
function filterCatalogue(){
    var q=$('#bs-cat-search').val().toLowerCase();
    var g=$('#bs-cat-genre').val().toLowerCase();
    $('.bs-cat-card').each(function(){
        var title=$(this).data('title')||'';
        var genre=$(this).data('genre')||'';
        var mQ=!q||title.includes(q);
        var mG=!g||genre.toLowerCase()===g;
        $(this).toggleClass('hidden',!(mQ&&mG));
    });
}

// ── Add to cart ─────────────────────────────────────────────────────────────
$(document).on('click','.bs-add-to-cart',function(){
    var id=parseInt($(this).data('id'));
    var title=$(this).data('title');
    var price=parseFloat($(this).data('price'));
    var cover=$(this).data('cover')||'';
    var ex=cart.find(function(c){return c.id===id;});
    if(ex) ex.qty++;
    else cart.push({id:id,title:title,price:price,qty:1,cover:cover});
    renderCart();
    // Pulse animation on fab
    var fab=$('#bs-cart-fab');
    fab.css('transform','scale(1.2)');
    setTimeout(function(){fab.css('transform','');},200);
});

// ── Reserve ─────────────────────────────────────────────────────────────────
$(document).on('click','.bs-reserve-book',function(){
    var title=$(this).data('title');
    var isbn=$(this).data('isbn');
    $('#bsr-title').val(title);
    $('#bsr-isbn').val(isbn);
    // Scroll to reservation form if exists
    var rf=$('#bs-reserve-form');
    if(rf.length) $('html,body').animate({scrollTop:rf.offset().top-60},400);
});

// ── Cart render ─────────────────────────────────────────────────────────────
function renderCart(){
    var cur=BSCatalogue.currency||'₦';
    var total=cart.reduce(function(s,c){return s+c.price*c.qty;},0);
    var html='';
    cart.forEach(function(item){
        html+='<div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid #f0e8d8;font-size:.85rem">';
        html+='<span style="flex:1">'+esc(item.title)+' × '+item.qty+'</span>';
        html+='<span style="font-weight:700;margin:0 8px">'+cur+fmt(item.price*item.qty)+'</span>';
        html+='<button onclick="bsRemoveCartItem('+item.id+')" style="background:none;border:none;cursor:pointer;color:#ccc;font-size:.9rem">✕</button>';
        html+='</div>';
    });
    $('#bs-online-cart-items').html(html||'<p style="color:#999;text-align:center;padding:20px">Cart is empty</p>');
    $('#bs-online-cart-total').html(cart.length?'<strong>Total: '+cur+fmt(total)+'</strong>':'');
    var count=cart.reduce(function(s,c){return s+c.qty;},0);
    $('#bs-cart-count').text(count);
    if(count>0) $('#bs-cart-fab').show();
    else $('#bs-cart-fab').hide();
}

window.bsRemoveCartItem=function(id){
    cart=cart.filter(function(c){return c.id!==id;});
    renderCart();
};

// ── Checkout ─────────────────────────────────────────────────────────────────
window.bsCheckout=function(type){
    if(!cart.length){alert('Your cart is empty.');return;}
    orderType=type;
    $('#bs-order-type').val(type);
    $('#bs-delivery-address-row').toggle(type==='delivery');
    var cur=BSCatalogue.currency||'₦';
    var total=cart.reduce(function(s,c){return s+c.price*c.qty;},0);
    $('#bs-checkout-total').html('<strong>Total: '+cur+fmt(total)+'</strong>');
    $('#bs-checkout-modal').css('display','flex');
};

window.bsPayOnPickup=function(){
    var data=collectCheckoutData();
    if(!data) return;
    data.items=JSON.stringify(cart);
    data.action='bs_submit_online_order';
    $.post(BSCatalogue.ajax_url,data,function(res){
        if(res.success){
            showOrderSuccess(res.data.ref);
        } else {
            $('#bs-checkout-msg').text('Error: '+(res.data||'Please try again'));
        }
    });
};

window.bsPayWithPaystack=function(){
    var data=collectCheckoutData();
    if(!data) return;
    // First create the order, then redirect to Paystack
    var items=JSON.stringify(cart);
    var total=cart.reduce(function(s,c){return s+c.price*c.qty;},0);
    $.post(BSCatalogue.ajax_url,{action:'bs_submit_online_order',items:items,name:data.name,email:data.email,phone:data.phone,address:data.address,notes:data.notes,type:data.type},function(res){
        if(!res.success){$('#bs-checkout-msg').text(res.data||'Error');return;}
        var orderId=res.data.id;
        $.post(BSCatalogue.ajax_url,{action:'bs_init_paystack',amount:total,email:data.email,order_id:orderId},function(r){
            if(r.success&&r.data.auth_url) window.location.href=r.data.auth_url;
            else $('#bs-checkout-msg').text(r.data||'Payment init failed');
        });
    });
};

window.bsPayWithFlutterwave=function(){
    var data=collectCheckoutData();
    if(!data) return;
    var items=JSON.stringify(cart);
    var total=cart.reduce(function(s,c){return s+c.price*c.qty;},0);
    $.post(BSCatalogue.ajax_url,{action:'bs_submit_online_order',items:items,name:data.name,email:data.email,phone:data.phone,address:data.address,notes:data.notes,type:data.type},function(res){
        if(!res.success){$('#bs-checkout-msg').text(res.data||'Error');return;}
        var orderId=res.data.id;
        $.post(BSCatalogue.ajax_url,{action:'bs_init_flutterwave',amount:total,email:data.email,name:data.name,phone:data.phone,order_id:orderId},function(r){
            if(r.success&&r.data.auth_url) window.location.href=r.data.auth_url;
            else $('#bs-checkout-msg').text(r.data||'Payment init failed');
        });
    });
};

function collectCheckoutData(){
    var name=$('#bs-ord-name').val().trim();
    var email=$('#bs-ord-email').val().trim();
    var phone=$('#bs-ord-phone').val().trim();
    if(!name||!email||!phone){$('#bs-checkout-msg').text('Please fill in name, email and phone.');return null;}
    return{name:name,email:email,phone:phone,address:$('#bs-ord-address').val(),notes:$('#bs-ord-notes').val(),type:orderType};
}

window.bsPayWithGiftCard=function(){
    $('#bs-gc-pay-row').toggle();
    $('#bs-gc-order-msg').hide();
    $('#bs-gc-order-code').val('').focus();
};

window.bsRedeemGiftCardForOrder=function(){
    var data=collectCheckoutData();
    if(!data) return;
    var gcCode=$('#bs-gc-order-code').val().trim().toUpperCase();
    if(!gcCode){$('#bs-gc-order-msg').text('Enter a gift card code').css({display:'block',background:'#fde8e8',color:'#c0392b'});return;}
    var total=cart.reduce(function(s,c){return s+c.price*c.qty;},0);
    var items=JSON.stringify(cart);
    var $msg=$('#bs-gc-order-msg');
    $msg.text('Processing...').css({display:'block',background:'#fffbf0',color:'#8a7a65'});

    // Step 1: Create the order
    $.post(BSCatalogue.ajax_url,{action:'bs_submit_online_order',items:items,name:data.name,email:data.email,phone:data.phone,address:data.address,notes:data.notes,type:data.type},function(res){
        if(!res.success){$msg.text(res.data||'Error creating order').css({background:'#fde8e8',color:'#c0392b'});return;}
        var orderId=res.data.id;
        var orderRef=res.data.ref;
        // Step 2: Redeem gift card for the order
        $.post(BSCatalogue.ajax_url,{action:'bs_redeem_gc_for_online_order',gc_code:gcCode,amount:total,order_id:orderId},function(r){
            if(r.success){
                $msg.text('✓ Gift card applied! Remaining balance: '+r.data.new_balance_formatted).css({background:'#e8f8e8',color:'#27ae60'});
                setTimeout(function(){ showOrderSuccess(orderRef); },1500);
            } else {
                $msg.text(r.data||'Gift card redemption failed').css({background:'#fde8e8',color:'#c0392b'});
            }
        });
    });
};

function showOrderSuccess(ref){
    $('#bs-checkout-modal').hide();
    cart=[];renderCart();
    $('<div style="position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;display:flex;align-items:center;justify-content:center"><div style="background:#fff;border-radius:12px;padding:32px;text-align:center;max-width:360px"><div style="font-size:3rem">✅</div><h3 style="font-family:serif;margin:10px 0">Order Received!</h3><p>Ref: <strong>'+esc(ref)+'</strong></p><p style="color:#666;font-size:.88rem;margin-top:8px">We\'ll contact you shortly to confirm.</p><button onclick="this.closest(\'[style]\').remove()" style="margin-top:16px;padding:10px 24px;background:#1a1208;color:#f5d87a;border:none;border-radius:8px;cursor:pointer;font-size:.9rem">Close</button></div></div>').appendTo('body');
}

function fmt(n){return parseFloat(n).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g,',');}
function esc(s){return $('<div>').text(s||'').html();}
})(jQuery);
