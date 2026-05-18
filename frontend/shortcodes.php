<?php
if(!defined('ABSPATH'))exit;

add_shortcode('bookshop_reserve','bs_reservation_shortcode');
function bs_reservation_shortcode(){
    $shop=get_option('bookshop_receipt_header',get_bloginfo('name'));
    ob_start(); ?>
    <div class="bs-reserve-wrap" style="max-width:520px;font-family:'DM Sans',sans-serif">
        <h3 style="font-family:'Playfair Display',serif;margin-bottom:16px">📖 Reserve a Book — <?=esc_html($shop)?></h3>
        <div id="bs-reserve-form">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                <div><label style="font-size:.8rem;font-weight:600;display:block;margin-bottom:4px">Your Name *</label>
                    <input type="text" id="bsr-name" style="width:100%;padding:8px 12px;border:1.5px solid #e0d4c0;border-radius:8px;font-size:.9rem"></div>
                <div><label style="font-size:.8rem;font-weight:600;display:block;margin-bottom:4px">Phone *</label>
                    <input type="tel" id="bsr-phone" style="width:100%;padding:8px 12px;border:1.5px solid #e0d4c0;border-radius:8px;font-size:.9rem"></div>
                <div><label style="font-size:.8rem;font-weight:600;display:block;margin-bottom:4px">Email</label>
                    <input type="email" id="bsr-email" style="width:100%;padding:8px 12px;border:1.5px solid #e0d4c0;border-radius:8px;font-size:.9rem"></div>
                <div><label style="font-size:.8rem;font-weight:600;display:block;margin-bottom:4px">Quantity</label>
                    <input type="number" id="bsr-qty" value="1" min="1" style="width:100%;padding:8px 12px;border:1.5px solid #e0d4c0;border-radius:8px;font-size:.9rem"></div>
                <div style="grid-column:1/-1"><label style="font-size:.8rem;font-weight:600;display:block;margin-bottom:4px">Book Title *</label>
                    <input type="text" id="bsr-title" style="width:100%;padding:8px 12px;border:1.5px solid #e0d4c0;border-radius:8px;font-size:.9rem"></div>
                <div style="grid-column:1/-1"><label style="font-size:.8rem;font-weight:600;display:block;margin-bottom:4px">ISBN (if known)</label>
                    <input type="text" id="bsr-isbn" style="width:100%;padding:8px 12px;border:1.5px solid #e0d4c0;border-radius:8px;font-size:.9rem"></div>
                <div style="grid-column:1/-1"><label style="font-size:.8rem;font-weight:600;display:block;margin-bottom:4px">Notes</label>
                    <textarea id="bsr-notes" rows="2" style="width:100%;padding:8px 12px;border:1.5px solid #e0d4c0;border-radius:8px;font-size:.9rem"></textarea></div>
            </div>
            <button id="bsr-submit" style="margin-top:16px;width:100%;padding:12px;background:#1a1208;color:#f5d87a;border:none;border-radius:8px;font-size:1rem;cursor:pointer;font-family:'Playfair Display',serif">
                Submit Reservation
            </button>
            <div id="bsr-msg" style="margin-top:12px;display:none"></div>
        </div>
    </div>
    <script>
    document.getElementById('bsr-submit').addEventListener('click',function(){
        var name=document.getElementById('bsr-name').value.trim();
        var phone=document.getElementById('bsr-phone').value.trim();
        var title=document.getElementById('bsr-title').value.trim();
        if(!name||!phone||!title){
            document.getElementById('bsr-msg').style.display='block';
            document.getElementById('bsr-msg').innerHTML='<p style="color:#c0392b">Please fill in all required fields.</p>';
            return;
        }
        this.disabled=true; this.textContent='Submitting...';
        var data=new URLSearchParams({
            action:'bs_add_reservation',
            name:name,phone:phone,
            email:document.getElementById('bsr-email').value,
            book_title:title,
            isbn:document.getElementById('bsr-isbn').value,
            qty:document.getElementById('bsr-qty').value,
            notes:document.getElementById('bsr-notes').value,
        });
        fetch('<?=admin_url('admin-ajax.php')?>',{method:'POST',body:data})
          .then(r=>r.json()).then(d=>{
            var msg=document.getElementById('bsr-msg');
            msg.style.display='block';
            if(d.success){
                msg.innerHTML='<p style="color:#2a7a3b;font-weight:600">OK Your reservation has been submitted! We\'ll contact you when the book is available.</p>';
                document.getElementById('bs-reserve-form').querySelectorAll('input,textarea').forEach(el=>el.value='');
            } else {
                msg.innerHTML='<p style="color:#c0392b">Something went wrong. Please try again.</p>';
            }
            document.getElementById('bsr-submit').disabled=false;
            document.getElementById('bsr-submit').textContent='Submit Reservation';
        });
    });
    </script>
    <?php return ob_get_clean();
}
add_action('wp_ajax_nopriv_bs_add_reservation','wp_ajax_bs_add_reservation');
