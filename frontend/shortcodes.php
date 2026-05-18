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



// ── Customer order tracking [bookshop_track] ──────────────────────────────────
//
// Public, no-login shortcode. A customer pastes their order ref + the email
// they used at checkout, and gets the current status, items, total, and a
// timeline drawn from the audit log.
//
// Anti-enumeration: we require *both* ref and email to match. A wrong ref OR
// a wrong email gets the same generic "not found" — never "ref ok, email
// wrong" — so a passer-by can't sweep refs to learn whose orders exist.
//
// The shortcode also pre-fills from URL params (?bookshop_track=REF&email=...)
// so the link in the status email lands on a populated form. The actual
// lookup still requires both fields; the URL just saves typing.

add_shortcode('bookshop_track','bs_track_shortcode');
function bs_track_shortcode($atts){
    $a = shortcode_atts(['title' => 'Track Your Order'], $atts);
    $shop = get_option('bookshop_receipt_header', get_bloginfo('name'));
    // URL pre-fill — purely cosmetic, the AJAX call still validates.
    $pre_ref   = isset($_GET['bookshop_track']) ? sanitize_text_field(wp_unslash($_GET['bookshop_track'])) : '';
    $pre_email = isset($_GET['email'])          ? sanitize_email(wp_unslash($_GET['email']))               : '';
    ob_start(); ?>
    <div class="bs-track-wrap" id="bs-track" style="max-width:560px;margin:0 auto;font-family:'DM Sans',sans-serif">
        <h3 style="font-family:'Playfair Display',serif;font-size:1.5rem;margin-bottom:6px"><?=esc_html($a['title'])?> &mdash; <?=esc_html($shop)?></h3>
        <p style="color:#8a7a65;font-size:.88rem;margin-bottom:18px">
            Enter your order reference and the email you used at checkout to see the latest status.
        </p>
        <div id="bs-track-form" style="display:grid;grid-template-columns:1fr 1fr;gap:12px;align-items:end">
            <div>
                <label style="font-size:.78rem;font-weight:600;display:block;margin-bottom:4px;color:#5b3e0a">Order Reference *</label>
                <input type="text" id="bst-ref" placeholder="OD-XXXXXXXX" value="<?=esc_attr($pre_ref)?>"
                    style="width:100%;padding:9px 12px;border:1.5px solid #e0d4c0;border-radius:8px;font-size:.92rem;font-family:Menlo,Consolas,monospace;text-transform:uppercase">
            </div>
            <div>
                <label style="font-size:.78rem;font-weight:600;display:block;margin-bottom:4px;color:#5b3e0a">Email *</label>
                <input type="email" id="bst-email" placeholder="you@example.com" value="<?=esc_attr($pre_email)?>"
                    style="width:100%;padding:9px 12px;border:1.5px solid #e0d4c0;border-radius:8px;font-size:.92rem">
            </div>
            <div style="grid-column:1/-1">
                <button id="bst-lookup-btn" type="button"
                    style="width:100%;padding:11px;background:#1a1208;color:#f5d87a;border:none;border-radius:8px;cursor:pointer;font-weight:600;font-size:.92rem;transition:.15s">
                    Look up order
                </button>
            </div>
        </div>
        <div id="bst-msg" style="margin-top:12px;display:none;padding:10px 14px;border-radius:8px;font-size:.86rem"></div>
        <div id="bst-result" style="margin-top:18px"></div>
    </div>
    <script>
    (function(){
        if(window.__bsTrackBound) return; window.__bsTrackBound = true;
        var ajax = <?=wp_json_encode(admin_url('admin-ajax.php'))?>;
        var currency = <?=wp_json_encode(bs_currency())?>;
        function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g, function(c){
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
        });}
        function fmt(n){ return currency + parseFloat(n||0).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g,','); }
        // Status → colour map matches the email template so the page looks
        // like a continuation of the email.
        var STATUS_COLOURS = {
            pending:    {fg:'#856404', bg:'#fff3cd'},
            paid:       {fg:'#004085', bg:'#cce5ff'},
            processing: {fg:'#5d4a00', bg:'#fff3cd'},
            ready:      {fg:'#2a7a3b', bg:'#d4edda'},
            completed:  {fg:'#2a7a3b', bg:'#d4edda'},
            cancelled:  {fg:'#c0392b', bg:'#f8d7da'}
        };
        function renderResult(d){
            var s = (d.status||'').toLowerCase();
            var c = STATUS_COLOURS[s] || {fg:'#666', bg:'#eee'};
            var html = ''
                + '<div style="background:#fdf8f0;border:1.5px solid #e0d4c0;border-radius:10px;padding:18px">'
                +   '<div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:8px;margin-bottom:12px">'
                +     '<div>'
                +       '<div style="font-size:.78rem;color:#8a7a65;letter-spacing:.04em;text-transform:uppercase">Order ref</div>'
                +       '<div style="font-family:Menlo,Consolas,monospace;font-weight:700;font-size:1.05rem;color:#1a1208">'+esc(d.ref)+'</div>'
                +     '</div>'
                +     '<div style="display:inline-block;padding:6px 14px;border-radius:20px;background:'+c.bg+';color:'+c.fg+';font-weight:700;font-size:.85rem;text-transform:uppercase;letter-spacing:.04em">'
                +       esc(d.status||'unknown')
                +     '</div>'
                +   '</div>'
                +   '<table style="width:100%;border-collapse:collapse;background:#fff;border-radius:6px;overflow:hidden;font-size:.88rem">'
                +     '<thead><tr style="background:#1a1208;color:#f5d87a">'
                +       '<th style="padding:8px 10px;text-align:left">Book</th>'
                +       '<th style="padding:8px 10px;text-align:center">Qty</th>'
                +       '<th style="padding:8px 10px;text-align:right">Total</th>'
                +     '</tr></thead><tbody>';
            (d.items||[]).forEach(function(i){
                var line = (parseFloat(i.price)||0) * (parseInt(i.qty,10)||0);
                html += '<tr>'
                     + '<td style="padding:8px 10px;border-bottom:1px solid #f0e8d8">'+esc(i.title||'Item')+'</td>'
                     + '<td style="padding:8px 10px;border-bottom:1px solid #f0e8d8;text-align:center">'+(parseInt(i.qty,10)||0)+'</td>'
                     + '<td style="padding:8px 10px;border-bottom:1px solid #f0e8d8;text-align:right">'+fmt(line)+'</td>'
                     + '</tr>';
            });
            if(!(d.items||[]).length){
                html += '<tr><td colspan="3" style="padding:10px;color:#8a7a65;text-align:center">(items unavailable)</td></tr>';
            }
            html += '</tbody></table>'
                +   '<table style="margin-top:10px;width:100%;font-size:.92rem">'
                +     '<tr><td style="font-weight:700">TOTAL</td>'
                +     '<td style="text-align:right;font-weight:700">'+fmt(d.total)+'</td></tr>'
                +     '<tr><td style="color:#8a7a65;font-size:.82rem">Type</td>'
                +     '<td style="text-align:right;color:#8a7a65;font-size:.82rem">'+esc((d.type||'').charAt(0).toUpperCase()+(d.type||'').slice(1))+'</td></tr>'
                +   '</table>';
            // Timeline: each row is a single status transition pulled from
            // the audit log. We render newest-first because that matches
            // how the email lands ("your latest update is …").
            if((d.timeline||[]).length){
                html += '<h4 style="font-family:\'Playfair Display\',serif;margin:18px 0 8px;font-size:1rem">Timeline</h4>'
                     +  '<div style="background:#fff;border:1px solid #e0d4c0;border-radius:6px;overflow:hidden">';
                d.timeline.forEach(function(ev,idx){
                    var sub = STATUS_COLOURS[(ev.to||'').toLowerCase()] || {fg:'#666', bg:'#eee'};
                    html += '<div style="display:flex;align-items:center;gap:10px;padding:10px 14px;'
                         +    (idx ? 'border-top:1px solid #f0e8d8;' : '')
                         + '">'
                         +   '<span style="display:inline-block;padding:3px 10px;border-radius:14px;background:'+sub.bg+';color:'+sub.fg+';font-weight:600;font-size:.78rem;text-transform:uppercase;letter-spacing:.03em;min-width:90px;text-align:center">'+esc(ev.to||'?')+'</span>'
                         +   '<span style="flex:1;font-size:.85rem;color:#5b3e0a">'
                         +     (ev.from ? 'changed from <strong>'+esc(ev.from)+'</strong>' : 'order received')
                         +   '</span>'
                         +   '<span style="font-size:.78rem;color:#8a7a65;white-space:nowrap">'+esc(ev.when)+'</span>'
                         + '</div>';
                });
                html += '</div>';
            }
            html += '</div>';
            document.getElementById('bst-result').innerHTML = html;
        }
        function showMsg(text, kind){
            var el = document.getElementById('bst-msg');
            el.style.display = 'block';
            el.style.background = kind==='error' ? '#f8d7da' : '#d4edda';
            el.style.color      = kind==='error' ? '#c0392b' : '#2a7a3b';
            el.textContent = text;
        }
        function lookup(){
            var ref   = (document.getElementById('bst-ref').value   || '').trim().toUpperCase();
            var email = (document.getElementById('bst-email').value || '').trim();
            if(!ref || !email){ showMsg('Please fill in both fields.', 'error'); return; }
            var btn = document.getElementById('bst-lookup-btn');
            btn.disabled = true; btn.textContent = 'Looking up…';
            document.getElementById('bst-msg').style.display = 'none';
            document.getElementById('bst-result').innerHTML = '';
            var fd = new FormData();
            fd.append('action', 'bs_track_online_order');
            fd.append('ref', ref);
            fd.append('email', email);
            fetch(ajax, {method:'POST', body:fd, credentials:'same-origin'})
                .then(function(r){ return r.json(); })
                .then(function(res){
                    btn.disabled = false; btn.textContent = 'Look up order';
                    if(res && res.success && res.data){
                        renderResult(res.data);
                    } else {
                        // We deliberately use a generic message here even
                        // when the server distinguishes ref-wrong from
                        // email-wrong, to avoid leaking which one matched.
                        showMsg(
                            (res && res.data) ? String(res.data) :
                            'No order matches that reference and email. Double-check both fields and try again.',
                            'error'
                        );
                    }
                })
                .catch(function(){
                    btn.disabled = false; btn.textContent = 'Look up order';
                    showMsg('Lookup failed. Please try again in a moment.', 'error');
                });
        }
        document.getElementById('bst-lookup-btn').addEventListener('click', lookup);
        // Allow Enter from either input to submit.
        ['bst-ref','bst-email'].forEach(function(id){
            document.getElementById(id).addEventListener('keydown', function(e){
                if(e.key === 'Enter'){ e.preventDefault(); lookup(); }
            });
        });
        // Auto-submit when both pre-fill values are present (clicked from
        // the email link). We don't auto-submit with only one field, since
        // the user might want to type the missing one first.
        if(<?=$pre_ref?'true':'false'?> && <?=$pre_email?'true':'false'?>){
            setTimeout(lookup, 50);
        }
    })();
    </script>
    <?php
    return ob_get_clean();
}

// ── Public AJAX: track an online order ────────────────────────────────────────
// No nonce: this is a public lookup form, the credential *is* the
// (ref, email) pair. We intentionally collapse all "not found" cases
// into one generic error so a passer-by can't sweep order refs to
// learn whose orders exist on the system.
add_action('wp_ajax_bs_track_online_order',        'bs_ajax_track_online_order');
add_action('wp_ajax_nopriv_bs_track_online_order', 'bs_ajax_track_online_order');
function bs_ajax_track_online_order(){
    global $wpdb;
    $ref   = sanitize_text_field(wp_unslash($_POST['ref']   ?? ''));
    $email = sanitize_email     (wp_unslash($_POST['email'] ?? ''));
    if(!$ref || !$email || !is_email($email)){
        wp_send_json_error('Please enter a valid order reference and email.');
        return;
    }
    // Match on both fields, case-insensitive on email (the column is utf8 ci
    // by default but we lowercase explicitly to be safe across collations).
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}bookshop_online_orders
         WHERE ref = %s AND LOWER(customer_email) = LOWER(%s)
         LIMIT 1",
        $ref, $email
    ));
    if(!$row){
        // Generic, identical to "no such ref" so the wrong-email vs
        // wrong-ref cases are indistinguishable to a caller.
        wp_send_json_error('No order matches that reference and email.');
        return;
    }
    // Build the timeline from the audit log. bs_audit writes
    //   action      = 'online_order_status'
    //   object_type = 'online_order'
    //   object_id   = order id
    //   details     = "Status: <from> → <to>"  (UTF-8 arrow)
    // We also surface 'online_order_created' as the first row so the
    // timeline starts at "received" instead of jumping in mid-flow.
    $audit = function_exists('bs_get_audit_log') ? bs_get_audit_log([
        'object_type' => 'online_order',
        'object_id'   => intval($row->id),
        'action_in'   => ['online_order_status','online_order_created'],
        'limit'       => 50,
    ]) : [];
    $timeline = [];
    foreach($audit as $a){
        if($a->action === 'online_order_created'){
            // The created event marks "we got your order"; the actual status
            // at that moment is 'pending' (per bs_create_online_order). We
            // surface it as 'pending' so it matches the badge colour the
            // customer first saw in their inbox.
            $timeline[] = [
                'when' => $a->created_at,
                'from' => '',
                'to'   => 'pending',
            ];
            continue;
        }
        // Parse "Status: <from> → <to>". The arrow is a multi-byte UTF-8
        // char; use mb_*-safe explode rather than a fragile regex.
        $parts = [];
        if(strpos($a->details, ' → ') !== false){
            $bits = explode(': ', $a->details, 2);
            if(count($bits) === 2){
                $parts = explode(' → ', $bits[1]);
            }
        }
        $timeline[] = [
            'when' => $a->created_at,
            'from' => isset($parts[0]) ? $parts[0] : '',
            'to'   => isset($parts[1]) ? $parts[1] : ($row->status ?? ''),
        ];
    }
    $items = json_decode($row->items_data, true);
    if(!is_array($items)) $items = [];

    wp_send_json_success([
        'ref'      => $row->ref,
        'status'   => $row->status,
        'type'     => $row->type,
        'total'    => floatval($row->total),
        'items'    => $items,
        'timeline' => $timeline, // newest-first (bs_get_audit_log orders DESC)
    ]);
}

// ── Helper: URL where customers can track an order ───────────────────────────
//
// Returns the configured tracking page URL with the ref/email pre-filled,
// or a sensible fallback if the shop hasn't set one. Used by the status
// email so customers get a one-click link.
//
// Storage: an option `bookshop_track_page_url` holds the absolute URL of
// whatever page hosts the [bookshop_track] shortcode. If unset, we fall
// back to the home URL with the params on the query string — that won't
// display the lookup form, but a page hosting the shortcode will pick up
// the params via $_GET, so as long as the admin renames the option to
// point at the right page everything just works.
function bs_track_url($ref, $email = ''){
    $base = get_option('bookshop_track_page_url', '');
    if(!$base) $base = home_url('/');
    $args = ['bookshop_track' => $ref];
    if($email) $args['email'] = $email;
    return add_query_arg($args, $base);
}
