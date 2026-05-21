<?php
if(!defined('ABSPATH'))exit;

add_action('template_redirect',function(){
    if(empty($_GET['bookshop_pos'])) return;
    // If logged in but cannot use POS, deny.
    if(is_user_logged_in() && !bs_user_can_pos()){
        wp_die('You do not have permission to use the POS.','Access Denied',['response'=>403]);
    }
    // Render POS — for not-logged-in users, the PIN screen is rendered inside.
    bs_render_pos();
    exit;
});

// ── Reprint route — admin re-printing a past sale ───────────────────────────
// Hits ?bookshop_print_receipt=1&sale_id=N. Renders an isolated, printable
// receipt page using the same pos-print.css the live POS print window uses,
// so a duplicate receipt looks identical to the original. Auto-prints on
// load so the cashier just clicks the link.
//
// Capability: bs_user_can_manage. Pure POS staff don't need to bypass the
// regular post-sale print path — if they want a duplicate at the till
// they can void+resell, which is the existing audit-friendly path. Manager+
// reprint is for the "customer came back next week" case where voiding
// would distort yesterday's reports.
add_action('template_redirect', function(){
    if (empty($_GET['bookshop_print_receipt'])) return;
    if (!bs_user_can_manage()) wp_die('Unauthorized', 'Access Denied', ['response' => 403]);
    $sale_id = intval($_GET['sale_id'] ?? 0);
    if (!$sale_id) wp_die('Missing sale_id', 'Bad Request', ['response' => 400]);
    bs_render_printable_receipt($sale_id);
    exit;
});

function bs_render_pos(){
    $user   =wp_get_current_user();
    $nonce  =wp_create_nonce('bs_pos_nonce');
    $ajax   =admin_url('admin-ajax.php');
    $shop   =get_option('bookshop_receipt_header',get_bloginfo('name'));
    $tagline=get_option('bookshop_tagline','');
    $address=get_option('bookshop_address','');
    $phone  =get_option('bookshop_phone','');
    $logo   =get_option('bookshop_logo_url','');
    $footer =get_option('bookshop_receipt_footer','Thank you for shopping with us!');
    $cur    =bs_currency();
    $wa     =get_option('bookshop_whatsapp','');
    $loy_val=floatval(get_option('bookshop_loyalty_value',10));
    $is_mgr =bs_user_can_manage();
    $mgr_thr=intval(get_option('bookshop_manager_discount_threshold',20));
    $shift  =bs_get_open_shift($user->ID);

    // ── Resolve the active branch for this session ────────────────────────────
    // Branches the user is allowed to operate from (every active branch for
    // managers/admin, the assigned home branch for regular staff).
    $allowed_branches = is_user_logged_in() ? bs_user_branches($user->ID) : [];

    // Currently selected branch from user_meta. If we have a single allowed
    // branch and nothing is selected yet, auto-select it so single-location
    // staff don't see a picker on every login.
    $active_branch_id = is_user_logged_in() ? bs_get_active_branch_id($user->ID) : 0;
    if ( !$active_branch_id && count($allowed_branches) === 1 ) {
        $auto = intval($allowed_branches[0]->id);
        if ( bs_set_active_branch_id($user->ID, $auto) === true ) {
            $active_branch_id = $auto;
        }
    }
    // If the user has an open shift, the shift's branch is authoritative —
    // keep the session in sync so they can't accidentally drift.
    if ( $shift && intval($shift->branch_id) && intval($shift->branch_id) !== $active_branch_id ) {
        bs_set_active_branch_id($user->ID, intval($shift->branch_id));
        $active_branch_id = intval($shift->branch_id);
    }
    $active_branch = $active_branch_id ? bs_get_branch($active_branch_id) : null;
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=esc_html($shop)?> — POS</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;700&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
:root{--ink:#1a1208;--paper:#fdf8f0;--warm:#f5efe4;--border:#e0d4c0;
  --amber:#c8860a;--amber-l:#f5d87a;--amber-d:#8a5c00;
  --green:#2a7a3b;--red:#c0392b;--blue:#1565c0;--muted:#8a7a65;
  --shadow:0 2px 12px rgba(0,0,0,.08);--r:10px;
  --fh:'Playfair Display',serif;--fb:'DM Sans',sans-serif;}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--fb);background:var(--paper);color:var(--ink);height:100vh;overflow:hidden}
.pos{display:grid;grid-template-columns:1fr 400px;grid-template-rows:54px 1fr;height:100vh}

/* Topbar */
.topbar{grid-column:1/-1;background:var(--ink);color:#fff;display:flex;align-items:center;gap:12px;padding:0 16px}
.topbar h1{font-family:var(--fh);font-size:1.2rem;color:var(--amber-l);margin-right:auto}
.topbar-info{font-size:.78rem;color:#aaa}
.topbar-info strong{color:#fff}
.topbar-btn{background:none;border:1px solid #555;color:#ccc;padding:4px 10px;border-radius:20px;cursor:pointer;font-size:.75rem;font-family:var(--fb);transition:.15s}
.topbar-btn:hover{background:#333;color:#fff}
.topbar-btn.danger{border-color:#c0392b;color:#e57373}
.branch-pill{display:inline-flex;align-items:center;gap:6px;background:rgba(245,216,122,.15);border:1px solid var(--amber-d);color:var(--amber-l);padding:3px 10px;border-radius:20px;font-size:.78rem}
.branch-pill strong{color:#fff;font-weight:600}
.branch-switch-btn{background:none;border:none;color:var(--amber-l);font-size:.7rem;text-decoration:underline;cursor:pointer;padding:0;font-family:var(--fb);margin-left:2px}
.branch-switch-btn:hover{color:#fff}
.branch-list{display:grid;gap:8px;max-height:50vh;overflow-y:auto}
.branch-option{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:12px 14px;border:1.5px solid var(--border);border-radius:8px;background:#fff;cursor:pointer;text-align:left;font-family:var(--fb);transition:.15s}
.branch-option:hover{border-color:var(--amber);background:var(--warm)}
.branch-option.active{border-color:var(--amber);background:var(--amber-l)}
.branch-option-meta{font-size:.75rem;color:var(--muted);margin-top:2px}

/* Catalog */
.catalog{background:var(--warm);display:flex;flex-direction:column;overflow:hidden;border-right:1px solid var(--border)}
.search-bar{padding:10px 12px;background:#fff;border-bottom:1px solid var(--border);display:flex;gap:8px}
.search-bar input{flex:1;padding:9px 13px;border:1.5px solid var(--border);border-radius:var(--r);font-family:var(--fb);font-size:.9rem;background:var(--paper)}
.search-bar input:focus{outline:none;border-color:var(--amber)}
.scan-btn{padding:8px 12px;background:var(--ink);color:var(--amber-l);border:none;border-radius:var(--r);cursor:pointer;font-size:.85rem}
.books-grid{flex:1;overflow-y:auto;padding:10px;display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:8px;align-content:start}
.book-card{background:#fff;border:1.5px solid var(--border);border-radius:var(--r);padding:8px;cursor:pointer;transition:.15s;position:relative;user-select:none}
.book-card:hover{border-color:var(--amber);box-shadow:var(--shadow);transform:translateY(-1px)}
.book-card.out-stock{opacity:.4;cursor:not-allowed}
.book-cover{width:100%;height:110px;border-radius:6px;background:var(--warm);display:flex;align-items:center;justify-content:center;font-size:2.2rem;margin-bottom:7px;overflow:hidden}
.book-cover img{width:100%;height:100%;object-fit:cover}
.book-title{font-size:.75rem;font-weight:600;line-height:1.3;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;margin-bottom:2px}
.book-author{font-size:.68rem;color:var(--muted);margin-bottom:4px}
.book-price{font-size:.85rem;font-weight:700;color:var(--amber-d)}
.book-stock-badge{position:absolute;top:5px;right:5px;font-size:.62rem;background:var(--green);color:#fff;border-radius:20px;padding:1px 5px}
.book-stock-badge.low{background:#e67e22}
.book-stock-badge.out{background:var(--red)}
.pos-empty{grid-column:1/-1;text-align:center;padding:48px 20px;color:var(--muted)}
.pos-empty span{font-size:2.5rem;display:block;margin-bottom:8px}

/* Cart */
/* The cart panel itself scrolls when its content exceeds the viewport, so
   the checkout button is always reachable. The items list also has its own
   max-height + scroll so a long cart doesn't push everything else off
   screen; the cart-footer keeps its natural height. */
.cart{background:#fff;display:flex;flex-direction:column;border-left:1px solid var(--border);overflow-y:auto;min-height:0}
.cart-header{padding:12px 14px;border-bottom:1px solid var(--border);background:var(--warm);display:flex;align-items:center;justify-content:space-between;flex:0 0 auto;position:sticky;top:0;z-index:5}
.cart-header h2{font-family:var(--fh);font-size:1rem}
.customer-bar{padding:8px 12px;border-bottom:1px solid var(--border);background:#fffbf0;display:flex;align-items:center;gap:6px;flex:0 0 auto}
.customer-bar input{flex:1;padding:5px 10px;border:1px solid var(--border);border-radius:6px;font-size:.8rem;font-family:var(--fb)}
.customer-bar .cust-name{font-size:.8rem;font-weight:600;color:var(--amber-d)}
.cart-items{flex:0 0 auto;overflow-y:auto;padding:6px;max-height:38vh;min-height:80px}
.cart-item{display:flex;gap:6px;align-items:flex-start;padding:8px 6px;border-bottom:1px solid #f5efe4}
.ci-info{flex:1;min-width:0}
.ci-title{font-size:.8rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ci-price{font-size:.73rem;color:var(--muted);margin-bottom:3px}
.ci-controls{display:flex;align-items:center;gap:3px}
.qty-btn{width:22px;height:22px;border:1px solid var(--border);background:var(--warm);border-radius:4px;cursor:pointer;font-size:.85rem;display:flex;align-items:center;justify-content:center}
.qty-btn:hover{background:var(--amber-l)}
.qty-val{width:28px;text-align:center;font-size:.82rem;font-weight:600}
.ci-total{font-size:.8rem;font-weight:700;white-space:nowrap}
.ci-remove{background:none;border:none;cursor:pointer;color:#ddd;font-size:.95rem;padding:1px 4px;margin-left:auto}
.ci-remove:hover{color:var(--red)}
.cart-empty-msg{padding:32px;text-align:center;color:var(--muted);font-size:.85rem}

/* Footer */
.cart-footer{border-top:1px solid var(--border);padding:10px 14px;background:var(--warm)}
.totals-row{display:flex;justify-content:space-between;font-size:.83rem;padding:2px 0}
.totals-row.grand{font-size:1.05rem;font-weight:700;padding-top:6px;border-top:1.5px solid var(--border);margin-top:4px}
.promo-row{display:flex;gap:6px;margin:6px 0}
.promo-row input{flex:1;padding:6px 10px;border:1px solid var(--border);border-radius:6px;font-size:.8rem;font-family:var(--fb);text-transform:uppercase}
.promo-row button{padding:5px 10px;border:1px solid var(--border);background:#fff;border-radius:6px;font-size:.78rem;cursor:pointer}
.disc-row{display:flex;align-items:center;gap:6px;margin:4px 0}
.disc-row label{font-size:.78rem;white-space:nowrap}
.disc-row input{flex:1;padding:5px 10px;border:1px solid var(--border);border-radius:6px;font-size:.82rem}
.pay-btns{display:grid;grid-template-columns:1fr 1fr;gap:5px;margin:8px 0}
.pay-btn{padding:6px 4px;border:1.5px solid var(--border);background:#fff;border-radius:6px;font-size:.75rem;cursor:pointer;transition:.15s;font-family:var(--fb);text-align:center}
.pay-btn.active{border-color:var(--amber);background:var(--amber-l);font-weight:700}
.split-inputs{display:none;gap:5px;margin:4px 0}
.split-inputs input{flex:1;padding:5px 8px;border:1px solid var(--border);border-radius:6px;font-size:.78rem;width:50%}
.loyalty-row{display:flex;align-items:center;gap:6px;margin:4px 0;font-size:.78rem}
.loyalty-row input{width:70px;padding:4px 8px;border:1px solid var(--border);border-radius:6px;font-size:.8rem}
.note-input{width:100%;padding:5px 8px;border:1px solid var(--border);border-radius:6px;font-size:.78rem;font-family:var(--fb);resize:none;margin:4px 0}
.checkout-btn{width:100%;padding:13px;background:var(--ink);color:var(--amber-l);border:none;border-radius:var(--r);font-family:var(--fh);font-size:1rem;cursor:pointer;margin-top:6px;transition:.2s}
.checkout-btn:hover{background:var(--amber-d);color:#fff}
.checkout-btn:disabled{background:#ccc;color:#999;cursor:not-allowed}

/* Modals */
.modal-bg{position:fixed;inset:0;background:rgba(0,0,0,.5);display:flex;align-items:center;justify-content:center;z-index:9999;overflow-y:auto;padding:20px 0}
.modal-box{background:#fff;border-radius:12px;max-width:480px;width:92%;max-height:92vh;box-shadow:0 20px 60px rgba(0,0,0,.2);overflow:hidden;display:flex;flex-direction:column}
.modal-hdr{display:flex;align-items:center;justify-content:space-between;padding:14px 20px;border-bottom:1px solid var(--border);background:var(--warm);flex-shrink:0}
.modal-hdr h3{font-family:var(--fh);font-size:1.1rem}
.modal-close{background:none;border:none;font-size:1.1rem;cursor:pointer;color:var(--muted)}
.modal-body{padding:20px;overflow-y:auto;flex:1;min-height:0}
.modal-foot{padding:12px 20px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:8px;flex-shrink:0}
.m-btn{padding:8px 18px;border-radius:8px;border:none;cursor:pointer;font-family:var(--fb);font-weight:600;font-size:.88rem}
.m-btn-sec{background:var(--warm);border:1.5px solid var(--border)!important}
.m-btn-pri{background:var(--ink);color:var(--amber-l)}

/* Receipt */
.receipt-box{text-align:center}
.receipt-box h2{font-family:var(--fh);color:var(--green)}
.receipt-ref{font-size:.78rem;color:var(--muted);margin:4px 0 12px}
.receipt-total{font-size:2rem;font-weight:700;margin:8px 0}
.receipt-items{text-align:left;background:var(--warm);border-radius:8px;padding:10px;margin:10px 0;max-height:160px;overflow-y:auto}
.ri{display:flex;justify-content:space-between;font-size:.8rem;padding:3px 0;border-bottom:1px dashed #ddd}
.receipt-actions{display:flex;gap:8px;margin-top:14px}
.receipt-actions button{flex:1;padding:9px;border-radius:8px;border:none;cursor:pointer;font-family:var(--fb);font-weight:600}
.btn-wa{background:#25D366;color:#fff}
.btn-email{background:#1565c0;color:#fff}
.btn-print{background:var(--warm);border:1.5px solid var(--border)!important}
.btn-new{background:var(--ink);color:var(--amber-l)}

/* Shift banner */
.shift-banner{grid-column:1/-1;background:#fff8e1;border-bottom:1px solid #ffe082;text-align:center;padding:6px;font-size:.8rem;display:none}

/* Autocomplete */
.bs-autocomplete{position:absolute;background:#fff;border:1px solid var(--border);border-radius:8px;box-shadow:var(--shadow);z-index:200;max-height:220px;overflow-y:auto;width:280px}
.bs-ac-item{padding:8px 12px;cursor:pointer;font-size:.83rem;border-bottom:1px solid #f0e8d8}
.bs-ac-item:hover{background:var(--warm)}
.bs-ac-item strong{display:block}
.bs-ac-item span{font-size:.75rem;color:var(--muted)}

/* PIN screen */
.pin-screen{position:fixed;inset:0;background:var(--ink);display:flex;align-items:center;justify-content:center;z-index:99999;flex-direction:column;gap:20px}
.pin-screen h2{font-family:var(--fh);color:var(--amber-l);font-size:1.8rem}
.pin-display{display:flex;gap:10px}
.pin-dot{width:16px;height:16px;border-radius:50%;background:#333;border:2px solid #555;transition:.2s}
.pin-dot.filled{background:var(--amber-l)}
.pin-pad{display:grid;grid-template-columns:repeat(3,72px);gap:10px}
.pin-key{width:72px;height:72px;background:#1e1e1e;border:1px solid #333;color:#fff;border-radius:12px;font-size:1.4rem;cursor:pointer;font-family:var(--fb);transition:.15s;display:flex;align-items:center;justify-content:center}
.pin-key:hover{background:#2a2a2a;border-color:var(--amber)}
.pin-key.del{font-size:1rem;color:#aaa}
.pin-key.ok{background:var(--amber-d);color:#fff;font-weight:700;font-size:1rem}
.pin-key.ok:hover{background:var(--amber);color:var(--ink)}
.pin-error{color:#e57373;font-size:.85rem;min-height:20px}
.pin-alt{color:#aaa;font-size:.8rem;cursor:pointer;text-decoration:underline;margin-top:6px}

::-webkit-scrollbar{width:4px}::-webkit-scrollbar-thumb{background:#ccc;border-radius:10px}
/* Printing is handled by opening a new window with just the receipt HTML
   (see printReceipt() below). That avoids the multi-page clipping that
   happened when we used position:fixed on an in-page #receipt-print-frame. */
</style>
</head>
<body>
<?php // ── PIN Login Screen ──────────────────────────────────────────────
if(!is_user_logged_in()): ?>
<div class="pin-screen" id="pin-screen">
    <h2>📚 <?=esc_html($shop)?></h2>
    <p style="color:#aaa">Enter your PIN to sign in</p>
    <div class="pin-display" id="pin-display">
        <?php for($i=0;$i<8;$i++) echo '<div class="pin-dot" id="pd-'.$i.'" data-idx="'.$i.'" style="display:'.($i<4?'block':'none').'"></div>'; ?>
    </div>
    <div class="pin-pad">
        <?php
        $keys = [1,2,3,4,5,6,7,8,9,'OK',0,'&#9003;'];
        foreach($keys as $k):
            $is_ok = ($k === 'OK');
            $is_del = ($k === '&#9003;');
            $pk_label = ($k === '') ? '' : (string)$k;
            $pk_val   = $is_ok ? 'OK' : ($is_del ? 'Del' : (string)$k);
            $pk_class = 'pin-key';
            if($is_ok)  $pk_class .= ' ok';
            if($is_del) $pk_class .= ' del';
        ?>
        <button class="<?=$pk_class?>" onclick="pinKey('<?=esc_attr($pk_val)?>')"><?=$pk_label?></button>
        <?php endforeach; ?>
    </div>
    <div class="pin-error" id="pin-error"></div>
    <a class="pin-alt" href="<?=wp_login_url(home_url('/?bookshop_pos=1'))?>">Sign in with password instead</a>
</div>
<?php endif; ?>

<div class="pos" id="pos-app">
  <header class="topbar">
    <h1>📚 <?=esc_html($shop)?> POS</h1>
    <span class="topbar-info">Staff: <strong id="pos-staff-name"><?=esc_html($user->display_name)?></strong></span>
    <span class="branch-pill" id="branch-pill" style="<?=$active_branch?'':'display:none'?>">
        🏪 <strong id="branch-pill-name"><?=$active_branch?esc_html($active_branch->name):''?></strong>
        <?php $can_switch = ($is_mgr || count($allowed_branches) > 1); ?>
        <?php if($can_switch): ?>
        <button class="branch-switch-btn" id="btn-switch-branch" title="Switch branch">Switch</button>
        <?php endif; ?>
    </span>
    <span class="topbar-info" id="pos-shift-info"></span>
    <button class="topbar-btn" id="btn-held-sales">📋 Held (<span id="held-count">0</span>)</button>
    <button class="topbar-btn" id="btn-park-sale" title="Park current sale [F2]">⏸ Park</button>
    <?php $shift_open_style = $shift ? 'display:none' : ''; ?>
    <?php $shift_close_style = $shift ? '' : 'display:none'; ?>
    <button class="topbar-btn" id="btn-open-shift" style="<?=$shift_open_style?>">Open Shift</button>
    <button class="topbar-btn" id="btn-close-shift" style="<?=$shift_close_style?>">Close Shift</button>
    <?php if(current_user_can('manage_options')): ?>
    <a href="<?=admin_url('admin.php?page=bookshop')?>" target="_blank" class="topbar-btn">Admin</a>
    <?php endif; ?>
    <a href="<?=wp_logout_url(home_url())?>" class="topbar-btn danger" onclick="return confirm('Log out?')">Log Out</a>
  </header>

  <section class="catalog">
    <div class="search-bar">
        <input type="text" id="pos-search" placeholder="🔍 Search title, author or ISBN..." autofocus>
    </div>
    <div class="books-grid" id="pos-grid">
        <div class="pos-empty"><span>🔍</span>Search for books to add to the cart</div>
    </div>
  </section>

  <aside class="cart">
    <div class="cart-header">
        <h2>🛒 Sale</h2>
        <button class="topbar-btn" onclick="clearCart()" style="font-size:.72rem">Clear</button>
    </div>

    <div class="customer-bar">
        <div style="position:relative;flex:1">
            <input type="text" id="cust-search" placeholder="👤 Search or add customer..." autocomplete="off">
            <div class="bs-autocomplete" id="cust-ac" style="display:none"></div>
        </div>
        <button class="topbar-btn" id="btn-quick-cust" title="Quick add">+</button>
        <button class="topbar-btn" id="btn-clear-cust" style="display:none">✕</button>
    </div>
    <div id="cust-loyalty-info" style="display:none;background:#fffbf0;padding:6px 12px;font-size:.75rem;border-bottom:1px solid var(--border)"></div>

    <div class="cart-items" id="cart-items">
        <div class="cart-empty-msg" id="cart-empty-msg">Cart is empty</div>
    </div>

    <div class="cart-footer">
        <!-- Totals -->
        <div class="totals-row"><span>Subtotal</span><span id="t-subtotal"><?=$cur?>0.00</span></div>
        <div class="totals-row" id="promo-disc-row" style="display:none;color:var(--green)"><span>Promo: <span id="promo-label"></span></span><span id="t-promo-disc"></span></div>
        <div class="totals-row" id="disc-row" style="display:none;color:var(--red)"><span>Discount</span><span id="t-disc"></span></div>
        <div class="totals-row" id="loyalty-row-display" style="display:none;color:var(--blue)"><span>Loyalty Redeem</span><span id="t-loyalty-val"></span></div>
        <div class="totals-row" id="credit-row-display" style="display:none;color:var(--blue)"><span>Credit Used</span><span id="t-credit-val"></span></div>
        <div class="totals-row" id="tax-row" style="display:none"><span>Tax</span><span id="t-tax"></span></div>
        <div class="totals-row grand"><span>TOTAL</span><span id="t-total"><?=$cur?>0.00</span></div>

        <!-- Promo code -->
        <div class="promo-row">
            <input type="text" id="promo-code" placeholder="Promo / Coupon code">
            <button id="btn-apply-promo">Apply</button>
            <button id="btn-clear-promo" style="display:none;color:var(--red)">✕</button>
        </div>

        <!-- Discount + loyalty redeem + credit -->
        <div class="disc-row">
            <label><?=$cur?> Disc</label>
            <input type="number" id="manual-disc" min="0" step="0.01" value="0">
            <label>Redeem pts</label>
            <input type="number" id="loyalty-redeem" min="0" step="1" value="0">
        </div>
        <div class="disc-row" id="credit-use-row" style="display:none">
            <label>💰 Use Credit <?=$cur?></label>
            <input type="number" id="credit-use" min="0" step="0.01" value="0" style="flex:1" placeholder="0.00">
            <span style="font-size:.72rem;color:var(--muted)" id="credit-avail-label"></span>
        </div>

        <!-- Payment method -->
        <div class="pay-btns">
            <button class="pay-btn active" data-method="cash">💵 Cash</button>
            <button class="pay-btn" data-method="card">💳 Card</button>
            <button class="pay-btn" data-method="transfer">📲 Transfer</button>
            <button class="pay-btn" data-method="split">⚡ Split</button>
            <button class="pay-btn" data-method="gift_card">🎁 Gift Card</button>
        </div>

        <!-- Gift card input (shown when gift_card payment selected) -->
        <div id="gc-redeem-row" style="display:none;margin:6px 0">
            <div style="display:flex;gap:5px;align-items:center">
                <input type="text" id="gc-code-input" placeholder="Enter gift card code..." style="flex:1;padding:7px 10px;border:1.5px solid var(--border);border-radius:6px;font-size:.82rem;text-transform:uppercase;font-family:var(--fb)">
                <button type="button" id="btn-gc-check" style="padding:6px 10px;border:1px solid var(--border);background:var(--warm);border-radius:6px;font-size:.78rem;cursor:pointer;font-family:var(--fb)">Check</button>
            </div>
            <div id="gc-balance-info" style="display:none;font-size:.78rem;margin-top:4px;padding:5px 8px;border-radius:6px;background:#e8f8e8;color:#27ae60;font-weight:600"></div>
            <div id="gc-error-info" style="display:none;font-size:.78rem;margin-top:4px;padding:5px 8px;border-radius:6px;background:#fde8e8;color:#c0392b"></div>
        </div>

        <!-- Split payment amounts -->
        <div class="split-inputs" id="split-inputs" style="display:none">
            <input type="number" id="split-cash" placeholder="<?=$cur?> Cash" step="0.01" min="0">
            <input type="number" id="split-card" placeholder="<?=$cur?> Card" step="0.01" min="0">
        </div>

        <!-- Amount tendered (cash only) -->
        <div id="tendered-row" style="display:flex;flex-direction:column;gap:5px;margin:6px 0">
            <div style="display:flex;align-items:center;gap:6px">
                <label style="font-size:.78rem;white-space:nowrap;font-weight:600">Tendered <?=$cur?></label>
                <input type="number" id="tendered" min="0" step="0.01" placeholder="0.00"
                    style="flex:1;padding:7px 10px;border:1.5px solid var(--amber);border-radius:6px;font-size:.9rem;font-weight:700;background:#fffbf0">
            </div>
            <div style="display:flex;gap:5px">
                <?php foreach([500,1000,2000,5000,10000] as $q): ?>
                <button class="quick-tender" data-val="<?=$q?>"
                    style="flex:1;padding:5px 2px;border:1px solid var(--border);background:var(--warm);border-radius:5px;font-size:.7rem;cursor:pointer;font-weight:600">
                    <?=number_format($q)?>
                </button>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- VAT row (shown when tax mode is enabled) -->
        <div class="totals-row" id="tax-display-row" style="display:none;color:#555;font-size:.82rem">
            <span id="t-tax-label">VAT</span><span id="t-tax-display"></span>
        </div>

        <!-- Change due -->
        <div class="totals-row" id="change-row" style="display:none;font-weight:700;font-size:.92rem;padding:6px 0;border-top:1.5px dashed var(--border);margin-top:2px">
            <span>Change</span><span id="t-change" style="font-size:1rem"></span>
        </div>

        <textarea class="note-input" id="pos-note" rows="1" placeholder="Note..."></textarea>
        <button class="checkout-btn" id="btn-checkout" disabled>Complete Sale</button>
    </div>
  </aside>
</div>

<!-- Shift Open Modal -->
<div class="modal-bg" id="shift-open-modal" style="display:none">
    <div class="modal-box">
        <div class="modal-hdr"><h3>Open Shift</h3></div>
        <div class="modal-body">
            <label style="display:block;margin-bottom:6px;font-size:.85rem;font-weight:600">Opening Cash in Drawer (<?=$cur?>)</label>
            <input type="number" id="opening-cash" class="bs-input" style="width:100%;padding:10px;border:1.5px solid var(--border);border-radius:8px;font-size:1rem" min="0" step="0.01" value="0">
        </div>
        <div class="modal-foot"><button class="m-btn m-btn-pri" id="confirm-open-shift">Open Shift</button></div>
    </div>
</div>

<!-- Shift Close Modal -->
<div class="modal-bg" id="shift-close-modal" style="display:none">
    <div class="modal-box">
        <div class="modal-hdr"><h3>Close Shift</h3></div>
        <div class="modal-body">
            <?php $shift_id_val = $shift ? intval($shift->id) : 0; ?>
            <input type="hidden" id="shift-id-input" value="<?=$shift_id_val?>">
            <label style="display:block;margin-bottom:6px;font-size:.85rem;font-weight:600">Closing Cash Count (<?=$cur?>)</label>
            <input type="number" id="closing-cash" style="width:100%;padding:10px;border:1.5px solid var(--border);border-radius:8px;font-size:1rem;margin-bottom:10px" min="0" step="0.01">
            <textarea id="shift-notes" rows="2" placeholder="Notes..." style="width:100%;padding:8px;border:1px solid var(--border);border-radius:6px;font-family:var(--fb)"></textarea>
            <div id="shift-variance-preview" style="margin-top:10px;font-size:.9rem"></div>
        </div>
        <div class="modal-foot"><button class="m-btn m-btn-sec" onclick="document.getElementById('shift-close-modal').style.display='none'">Cancel</button><button class="m-btn m-btn-pri" id="confirm-close-shift">Close Shift</button></div>
    </div>
</div>

<!-- Quick Add Customer Modal -->
<div class="modal-bg" id="quick-cust-modal" style="display:none">
    <div class="modal-box">
        <div class="modal-hdr"><h3>Quick Add Customer</h3><button class="modal-close" onclick="document.getElementById('quick-cust-modal').style.display='none'">✕</button></div>
        <div class="modal-body" style="display:grid;gap:10px">
            <input type="text" id="qc-name" placeholder="Full Name *" style="width:100%;padding:9px;border:1.5px solid var(--border);border-radius:8px;font-family:var(--fb)">
            <input type="tel"  id="qc-phone" placeholder="Phone *" style="width:100%;padding:9px;border:1.5px solid var(--border);border-radius:8px;font-family:var(--fb)">
            <input type="email" id="qc-email" placeholder="Email" style="width:100%;padding:9px;border:1.5px solid var(--border);border-radius:8px;font-family:var(--fb)">
        </div>
        <div class="modal-foot"><button class="m-btn m-btn-sec" onclick="document.getElementById('quick-cust-modal').style.display='none'">Cancel</button><button class="m-btn m-btn-pri" id="save-quick-cust">Add Customer</button></div>
    </div>
</div>

<!-- Manager Approval Modal -->
<div class="modal-bg" id="mgr-modal" style="display:none">
    <div class="modal-box">
        <div class="modal-hdr"><h3>🔒 Manager Approval Required</h3></div>
        <div class="modal-body">
            <p style="margin-bottom:12px">This discount requires manager approval. Enter the manager PIN:</p>
            <input type="password" id="mgr-pin" placeholder="Manager PIN" style="width:100%;padding:10px;border:1.5px solid var(--border);border-radius:8px;font-size:1rem;letter-spacing:.2em" inputmode="numeric">
            <div id="mgr-pin-error" style="color:var(--red);font-size:.82rem;margin-top:6px"></div>
        </div>
        <div class="modal-foot"><button class="m-btn m-btn-sec" onclick="document.getElementById('mgr-modal').style.display='none'">Cancel</button><button class="m-btn m-btn-pri" id="confirm-mgr-pin">Approve</button></div>
    </div>
</div>

<!-- Held Sales Modal -->
<div class="modal-bg" id="held-sales-modal" style="display:none">
    <div class="modal-box" style="max-width:480px">
        <div class="modal-hdr"><h3>📋 Parked Sales</h3><button class="modal-close" onclick="document.getElementById('held-sales-modal').style.display='none'">✕</button></div>
        <div class="modal-body" id="held-sales-body" style="padding:0">
            <div style="padding:20px;color:var(--muted);text-align:center" id="held-empty-msg">No parked sales</div>
        </div>
    </div>
</div>

<!-- Branch Picker Modal -->
<div class="modal-bg" id="branch-modal" style="<?=$active_branch ? 'display:none' : 'display:flex'?>">
    <div class="modal-box" style="max-width:480px">
        <div class="modal-hdr">
            <h3 id="branch-modal-title"><?=$active_branch ? 'Switch Branch' : 'Select Your Branch'?></h3>
            <button class="modal-close" id="branch-modal-close" style="<?=$active_branch ? '' : 'display:none'?>" onclick="document.getElementById('branch-modal').style.display='none'">✕</button>
        </div>
        <div class="modal-body">
            <?php if(empty($allowed_branches)): ?>
                <?php $any_branches = bs_get_branches(false); ?>
                <p style="color:var(--red);margin-bottom:8px">⚠️ You are not assigned to any active branch.</p>
                <?php if(empty($any_branches) && current_user_can('manage_options')): ?>
                <p style="font-size:.85rem;color:var(--muted);margin-bottom:14px">
                    No branches exist yet. <a href="<?=admin_url('admin.php?page=bookshop-branches')?>" target="_blank" style="color:var(--amber-d)">Create one in the admin area</a> and refresh.
                </p>
                <?php elseif($is_mgr): ?>
                <p style="font-size:.85rem;color:var(--muted);margin-bottom:14px">
                    All branches are inactive. Activate one from the <a href="<?=admin_url('admin.php?page=bookshop-branches')?>" target="_blank" style="color:var(--amber-d)">Branches admin page</a> and refresh.
                </p>
                <?php else: ?>
                <p style="font-size:.85rem;color:var(--muted);margin-bottom:14px">Ask a manager to assign you a home branch from the Staff page in the admin area, then refresh.</p>
                <?php endif; ?>
                <div style="display:flex;gap:8px">
                    <a href="<?=wp_logout_url(home_url('/?bookshop_pos=1'))?>" class="m-btn m-btn-sec" style="text-decoration:none;text-align:center;flex:1">Log Out</a>
                </div>
            <?php else: ?>
                <p style="font-size:.85rem;color:var(--muted);margin-bottom:14px">
                    <?php if($is_mgr): ?>
                        Choose which branch you are operating from. All sales, shifts, and parked sales will be recorded against this branch.
                    <?php else: ?>
                        Confirm the branch you are working from today.
                    <?php endif; ?>
                </p>
                <div class="branch-list">
                    <?php foreach($allowed_branches as $b): ?>
                    <button class="branch-option" data-branch-id="<?=esc_attr($b->id)?>" data-branch-name="<?=esc_attr($b->name)?>">
                        <div>
                            <div style="font-weight:600"><?=esc_html($b->name)?></div>
                            <?php if($b->address): ?>
                            <div class="branch-option-meta"><?=esc_html($b->address)?></div>
                            <?php endif; ?>
                        </div>
                        <span style="color:var(--amber-d);font-weight:700">→</span>
                    </button>
                    <?php endforeach; ?>
                </div>
                <div id="branch-error" style="color:var(--red);font-size:.82rem;margin-top:10px;min-height:18px"></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Receipt Modal -->
<div class="modal-bg" id="receipt-modal" style="display:none">
    <div class="modal-box" style="max-width:420px">
        <div class="modal-hdr">
            <h3>OK Sale Complete</h3>
            <button class="modal-close" onclick="newSale()">✕</button>
        </div>
        <div class="modal-body" style="padding:0;display:flex;flex-direction:column">
            <!-- Printable receipt -->
            <div id="printable-receipt" style="padding:20px;overflow-y:auto;flex:1;min-height:0">
                <?php if($logo=get_option('bookshop_logo_url','')): ?>
                <div style="text-align:center;margin-bottom:10px">
                    <img src="<?=esc_url($logo)?>" style="max-height:60px;max-width:180px" alt="logo">
                </div>
                <?php endif; ?>
                <div style="text-align:center;margin-bottom:4px">
                    <div style="font-family:var(--fh);font-size:1.1rem;font-weight:700"><?=esc_html(get_option('bookshop_receipt_header',get_bloginfo('name')))?></div>
                    <?php if($tl=get_option('bookshop_tagline','')): ?><div style="font-size:.78rem;color:var(--muted)"><?=esc_html($tl)?></div><?php endif; ?>
                    <?php if($addr=get_option('bookshop_address','')): ?><div style="font-size:.75rem;margin-top:3px"><?=nl2br(esc_html($addr))?></div><?php endif; ?>
                    <?php if($ph=get_option('bookshop_phone','')): ?><div style="font-size:.75rem">Tel: <?=esc_html($ph)?></div><?php endif; ?>
                </div>
                <div style="border-top:1px dashed #ccc;margin:10px 0"></div>
                <div style="font-size:.8rem;display:flex;flex-direction:column;gap:2px">
                    <div style="display:flex;justify-content:space-between"><span>Ref:</span><strong id="r-ref">—</strong></div>
                    <div style="display:flex;justify-content:space-between"><span>Date:</span><span id="r-date">—</span></div>
                    <div style="display:flex;justify-content:space-between"><span>Staff:</span><span><?=esc_html($user->display_name)?></span></div>
                    <div style="display:flex;justify-content:space-between" id="r-customer-row"><span>Customer:</span><span id="r-customer">Walk-in</span></div>
                    <div style="display:flex;justify-content:space-between"><span>Payment:</span><span id="r-payment">—</span></div>
                </div>
                <div style="border-top:1px dashed #ccc;margin:10px 0"></div>
                <div id="r-items" style="font-size:.8rem"></div>
                <div style="border-top:1px dashed #ccc;margin:10px 0"></div>
                <div style="font-size:.82rem">
                    <div style="display:flex;justify-content:space-between"><span>Subtotal</span><span id="r-subtotal">—</span></div>
                    <div style="display:flex;justify-content:space-between;color:var(--red)" id="r-disc-row"><span>Discount</span><span id="r-disc">—</span></div>
                    <div style="display:flex;justify-content:space-between;color:var(--green)" id="r-promo-row"><span>Promo</span><span id="r-promo">—</span></div>
                    <div style="display:flex;justify-content:space-between" id="r-tax-row"><span id="r-tax-label-display"><?=esc_html(get_option('bookshop_tax_label','VAT'))?></span><span id="r-tax">—</span></div>
                    <div style="display:flex;justify-content:space-between;font-size:1.1rem;font-weight:700;margin-top:6px;padding-top:6px;border-top:1.5px solid #333">
                        <span>TOTAL</span><span id="r-total">—</span>
                    </div>
                    <div style="display:flex;justify-content:space-between;color:var(--muted)" id="r-credit-row">
                        <span>Store Credit</span><span id="r-credit">—</span>
                    </div>
                    <div style="display:flex;justify-content:space-between;color:var(--muted)" id="r-loyalty-redeem-row">
                        <span>Points Redeemed</span><span id="r-loyalty-redeem">—</span>
                    </div>
                    <div style="display:flex;justify-content:space-between;color:var(--muted)" id="r-gc-row">
                        <span>Gift Card</span><span id="r-gc">—</span>
                    </div>
                    <div style="display:flex;justify-content:space-between;color:var(--muted)" id="r-split-row">
                        <span>Split</span><span id="r-split">—</span>
                    </div>
                    <div style="display:flex;justify-content:space-between;color:var(--muted)" id="r-tendered-row">
                        <span>Tendered</span><span id="r-tendered">—</span>
                    </div>
                    <div style="display:flex;justify-content:space-between;color:var(--green);font-weight:600" id="r-change-row">
                        <span>Change</span><span id="r-change">—</span>
                    </div>
                </div>
                <div style="border-top:1px dashed #ccc;margin:10px 0"></div>
                <div id="r-loyalty" style="font-size:.78rem;color:var(--green);text-align:center"></div>
                <div style="text-align:center;font-size:.78rem;margin-top:6px;font-style:italic">
                    <?=esc_html(get_option('bookshop_receipt_footer','Thank you for shopping with us!'))?>
                </div>
                <div style="text-align:center;font-size:.7rem;color:#bbb;margin-top:4px">
                    Powered by Bookshop Manager Pro
                </div>
            </div>

            <!-- Action buttons (hidden on print) -->
            <div class="no-print" style="padding:12px 20px;border-top:1px solid var(--border);background:var(--warm);flex-shrink:0">
                <div style="display:flex;gap:6px;margin-bottom:8px">
                    <input type="email" id="r-email" placeholder="Email receipt to customer..."
                        style="flex:1;padding:7px 10px;border:1px solid var(--border);border-radius:6px;font-size:.82rem;font-family:var(--fb)">
                    <button onclick="sendEmailReceipt()"
                        style="padding:7px 12px;background:var(--blue);color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:.8rem">Send</button>
                </div>
                <div style="display:flex;gap:6px">
                    <?php if($wa): ?>
                    <button class="m-btn" id="btn-wa-receipt"
                        style="flex:1;background:#25D366;color:#fff;padding:9px;border-radius:8px;border:none;cursor:pointer;font-weight:600">📱 WhatsApp</button>
                    <?php endif; ?>
                    <button onclick="printReceipt()"
                        style="flex:1;background:var(--warm);border:1.5px solid var(--border);padding:9px;border-radius:8px;cursor:pointer;font-weight:600">🖨️ Print</button>
                    <button onclick="newSale()"
                        style="flex:1;background:var(--ink);color:var(--amber-l);border:none;padding:9px;border-radius:8px;cursor:pointer;font-family:var(--fh);font-weight:600">New Sale</button>
                </div>
                <!-- Pair Bluetooth printer — hidden by default; the JS shows it
                     only when the browser supports Web Bluetooth (Chrome/Edge
                     desktop, Chrome Android). On supported browsers, pairing
                     once changes the Print button's behaviour from "open OS
                     print dialog" to "send ESC/POS bytes directly," which is
                     dramatically faster on cheap thermal printers. -->
                <div id="bs-printer-pair-row" style="display:none;margin-top:6px;display:flex;gap:6px;align-items:center;font-size:.78rem">
                    <button id="btn-pair-printer" type="button"
                        style="background:#fff;border:1.5px solid var(--border);padding:6px 12px;border-radius:6px;cursor:pointer;font-family:var(--fb)">
                        🔗 Pair Bluetooth printer
                    </button>
                    <span id="bs-printer-status" style="color:var(--muted);flex:1"></span>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function(){
const AJAX='<?=esc_js($ajax)?>';
const NONCE='<?=esc_js($nonce)?>';
const CUR='<?=esc_js($cur)?>';
const WA='<?=esc_js($wa)?>';
const SHOP='<?=esc_js($shop)?>';
const RECEIPT_FOOTER='<?=esc_js($footer)?>';
const LOY_VAL=<?=floatval($loy_val)?>;
<?php $is_mgr_js = $is_mgr ? 'true' : 'false'; ?>
const IS_MGR=<?=$is_mgr_js?>;
const MGR_THR=<?=intval($mgr_thr)?>;
const TAX_MODE='<?=esc_js(get_option('bookshop_tax_mode','none'))?>';
const TAX_RATE=<?=floatval(get_option('bookshop_tax_rate',0))/100?>;
const TAX_LABEL='<?=esc_js(get_option('bookshop_tax_label','VAT'))?>';

// Receipt paper width in mm. 80 is the thermal-printer default; 58 is for
// portable printers. Read from a wp_option so a shop can switch by setting
// it via wp-cli or DB without waiting for a settings UI:
//     wp option update bookshop_receipt_paper_width 58
// The CSS file flips layout based on body[data-paper-width] and the
// ESC/POS bridge uses this number for column widths.
const PAPER_WIDTH=<?=intval(get_option('bookshop_receipt_paper_width',80))===58?58:80?>;
const PRINT_CSS_URL='<?=esc_url(BOOKSHOP_URL.'assets/css/pos-print.css?v='.BOOKSHOP_VERSION)?>';
const SHOP_ADDRESS='<?=esc_js($address)?>';
const SHOP_PHONE='<?=esc_js($phone)?>';
const STAFF_NAME='<?=esc_js($user->display_name)?>';

// FIX: all IDs stored as numbers for consistent === comparison
let cart=[], customer=null, payment='cash', promoDisc=0, promoCode='', promoName='';
let searchTimer, custTimer, currentSaleId=0, currentSaleCart=[];
// Stashed copy of the last sale's server response. Used by the Bluetooth
// print bridge to pick up loyalty_earned + other server-computed fields
// that aren't in the on-screen modal markup. Cleared on newSale().
let lastReceiptData=null;
<?php $shift_id_init = $shift ? intval($shift->id) : 0; ?>
let shiftId=<?=$shift_id_init?>;
let currentTotal=0;

// ── Branch session ─────────────────────────────────────────────
let currentBranchId=<?=intval($active_branch_id)?>;
const HAS_OPEN_SHIFT=<?=$shift?'true':'false'?>;
function showBranchModal(forSwitch){
    if(HAS_OPEN_SHIFT && forSwitch){
        alert('Close your current shift before switching branches.');
        return;
    }
    const modal=document.getElementById('branch-modal');
    if(!modal) return;
    document.getElementById('branch-modal-title').textContent =
        forSwitch ? 'Switch Branch' : 'Select Your Branch';
    document.getElementById('branch-modal-close').style.display = forSwitch ? '' : 'none';
    const err=document.getElementById('branch-error');
    if(err) err.textContent='';
    modal.style.display='flex';
}
function hideBranchModal(){
    const m=document.getElementById('branch-modal');
    if(m) m.style.display='none';
}
function applyBranchUI(id,name){
    currentBranchId=parseInt(id)||0;
    const pill=document.getElementById('branch-pill');
    const lbl =document.getElementById('branch-pill-name');
    if(currentBranchId && pill){
        if(lbl) lbl.textContent=name||'';
        pill.style.display='';
    } else if(pill){
        pill.style.display='none';
    }
}
// Wire up branch picker buttons
document.querySelectorAll('.branch-option').forEach(btn=>{
    btn.addEventListener('click',function(){
        const id  =parseInt(this.dataset.branchId);
        const name=this.dataset.branchName;
        const err =document.getElementById('branch-error');
        if(err) err.textContent='';
        this.disabled=true;
        post({action:'bs_set_active_branch',nonce:NONCE,branch_id:id}).then(d=>{
            this.disabled=false;
            if(d && d.success){
                applyBranchUI(id,name);
                hideBranchModal();
                // Re-load held sales for the new branch context
                if(typeof loadHeldSales==='function') loadHeldSales();
            } else {
                const msg=(d && d.data && d.data.message) ? d.data.message
                          : (d && d.data) ? d.data : 'Could not set branch';
                if(err) err.textContent=msg;
                else alert(msg);
            }
        }).catch(()=>{ this.disabled=false; if(err) err.textContent='Network error'; });
    });
});
const switchBtn=document.getElementById('btn-switch-branch');
if(switchBtn) switchBtn.addEventListener('click',()=>showBranchModal(true));

// Helper used by sale/shift error handlers below
function handleNoBranchError(d,msgFallback){
    if(d && d.data && d.data.code==='no_branch'){
        showBranchModal(false);
        return true;
    }
    if(d && d.data && d.data.code==='shift_open'){
        alert(d.data.message || 'Close your current shift before switching branches.');
        return true;
    }
    return false;
}

// ── PIN login (if needed) ──────────────────────────────────
let pinVal='';
let pinSubmitting=false;
window.pinKey=function(k){
    if(pinSubmitting) return;
    if(k==='Del'){pinVal=pinVal.slice(0,-1);}
    else if(k==='OK'){if(pinVal.length>=4) attemptPinLogin(); return;}
    else if(pinVal.length<8) pinVal+=k;
    updatePinDots();
    // Auto-submit at 4 digits for the common 4-digit PIN. Longer PIN holders
    // can keep entering and press OK explicitly (they will still hit auto-submit
    // at 4, so for longer PINs press Del once to keep typing — or just press OK).
    if(pinVal.length===4) attemptPinLogin();
};
function updatePinDots(){
    const dots=document.querySelectorAll('.pin-dot');
    const showCount=Math.max(4,Math.min(8,pinVal.length||4));
    dots.forEach((d,i)=>{
        d.style.display = (i<showCount) ? 'block' : 'none';
        d.classList.toggle('filled', i<pinVal.length);
    });
}
function attemptPinLogin(){
    if(pinVal.length<4||pinSubmitting) return;
    pinSubmitting=true;
    document.getElementById('pin-error').textContent='';
    post({action:'bs_pin_login',pin:pinVal}).then(d=>{
        pinSubmitting=false;
        if(d&&d.success){
            // Reload so PHP renders POS in fully-authenticated context
            // (current user, shift, capabilities, etc.).
            window.location.reload();
        } else {
            document.getElementById('pin-error').textContent=(d&&d.data)?d.data:'Incorrect PIN';
            setTimeout(()=>{pinVal='';updatePinDots();document.getElementById('pin-error').textContent='';},1200);
        }
    }).catch(()=>{
        pinSubmitting=false;
        document.getElementById('pin-error').textContent='Network error — try again';
        setTimeout(()=>{pinVal='';updatePinDots();},1200);
    });
}

// ── Search ──────────────────────────────────────────────────
document.getElementById('pos-search').addEventListener('input',function(){
    clearTimeout(searchTimer);
    const q=this.value.trim();
    if(!q){renderGrid([]);return;}
    searchTimer=setTimeout(()=>fetchBooks(q),220);
});

function fetchBooks(q){
    get({action:'bs_search_books',q})
      .then(function(d){
          if(d.success){
              renderGrid(Array.isArray(d.data) ? d.data : []);
          } else {
              // Show the error message so we know what's wrong
              document.getElementById('pos-grid').innerHTML =
                '<div class="pos-empty"><span>⚠️</span>Search error: '
                + escH(d.data||'Unknown error') + '<br>'
                + '<small>Try refreshing the page.</small></div>';
          }
      })
      .catch(function(err){
          document.getElementById('pos-grid').innerHTML =
            '<div class="pos-empty"><span>⚠️</span>Network error. Check connection.</div>';
      });
}

function renderGrid(books){
    const g=document.getElementById('pos-grid');
    if(!books.length){g.innerHTML='<div class="pos-empty"><span>📭</span>No books found</div>';return;}
    g.innerHTML=books.map(b=>{
        const out=parseInt(b.stock_qty)<1;
        const low=parseInt(b.stock_qty)>0&&parseInt(b.stock_qty)<=parseInt(b.low_stock_threshold);
        const bc=out?'book-card out-stock':'book-card';
        const sb=out?'class="book-stock-badge out"':low?'class="book-stock-badge low"':'class="book-stock-badge"';
        // FIX: use data attributes instead of inline JS args to avoid escaping/type issues
        return`<div class="${bc}" data-id="${b.id}" data-title="${escAttr(b.title)}" data-author="${escAttr(b.author)}" data-price="${b.sell_price}" data-stock="${b.stock_qty}">
            <div class="book-cover">${b.cover_url?`<img src="${b.cover_url}" alt="">`:' 📖'}</div>
            <span ${sb}>${b.stock_qty}</span>
            <div class="book-title">${escH(b.title)}</div>
            <div class="book-author">${escH(b.author)}</div>
            <div class="book-price">${CUR}${fmt(b.sell_price)}</div>
        </div>`;
    }).join('');

    // FIX: bind click via event delegation on grid instead of inline onclick
    g.querySelectorAll('.book-card:not(.out-stock)').forEach(card=>{
        card.addEventListener('click',function(){
            addToCart(
                parseInt(this.dataset.id),
                this.dataset.title,
                this.dataset.author,
                parseFloat(this.dataset.price),
                parseInt(this.dataset.stock)
            );
        });
    });
}

// ── Cart ────────────────────────────────────────────────────
// FIX: IDs always stored/compared as integers
window.addToCart=function(id,title,author,price,stock){
    id=parseInt(id); stock=parseInt(stock); price=parseFloat(price);
    if(stock<1) return;
    const ex=cart.find(c=>c.id===id);
    if(ex){
        if(ex.qty<ex.stock) ex.qty++;
        // flash the card to give feedback even if already at max
        const card=document.querySelector(`.book-card[data-id="${id}"]`);
        if(card){card.style.borderColor='var(--amber)';setTimeout(()=>card.style.borderColor='',300);}
    } else {
        cart.push({id,title,author,price,qty:1,stock});
    }
    renderCart();
    updateTotals();
};

// FIX: changeQty uses integer comparison, called via data-attr buttons
window.changeQty=function(id,delta){
    id=parseInt(id);
    const it=cart.find(c=>c.id===id);
    if(!it) return;
    it.qty+=delta;
    if(it.qty<=0) cart=cart.filter(c=>c.id!==id);
    else if(it.qty>it.stock) it.qty=it.stock;
    renderCart();
    updateTotals();
};
window.removeItem=function(id){
    id=parseInt(id);
    cart=cart.filter(c=>c.id!==id);
    renderCart();
    updateTotals();
};
window.clearCart=function(){
    cart=[];promoDisc=0;promoCode='';promoName='';
    document.getElementById('promo-code').value='';
    document.getElementById('manual-disc').value=0;
    document.getElementById('loyalty-redeem').value=0;
    document.getElementById('tendered').value='';
    renderCart();updateTotals();
};

// FIX: renderCart no longer uses innerHTML in a way that destroys the empty-msg node.
// We keep empty-msg outside the dynamic list and toggle it with CSS.
function renderCart(){
    const container=document.getElementById('cart-items');
    const emptyMsg =document.getElementById('cart-empty-msg');
    document.getElementById('btn-checkout').disabled=cart.length===0;

    // Remove all previous item rows (but NOT the empty-msg node)
    container.querySelectorAll('.cart-item').forEach(n=>n.remove());

    if(!cart.length){
        emptyMsg.style.display='block';
        return;
    }
    emptyMsg.style.display='none';

    // Build and append each item individually so the DOM stays clean
    cart.forEach(it=>{
        const row=document.createElement('div');
        row.className='cart-item';
        row.innerHTML=`
            <div class="ci-info">
                <div class="ci-title">${escH(it.title)}</div>
                <div class="ci-price">${CUR}${fmt(it.price)} each</div>
                <div class="ci-controls">
                    <button class="qty-btn" data-id="${it.id}" data-delta="-1">−</button>
                    <span class="qty-val">${it.qty}</span>
                    <button class="qty-btn" data-id="${it.id}" data-delta="1">+</button>
                </div>
            </div>
            <div style="display:flex;flex-direction:column;align-items:flex-end;gap:4px">
                <div class="ci-total">${CUR}${fmt(it.price*it.qty)}</div>
                <button class="ci-remove" data-id="${it.id}">✕</button>
            </div>`;

        // FIX: bind events directly on the freshly created nodes, no inline JS
        row.querySelectorAll('.qty-btn').forEach(btn=>{
            btn.addEventListener('click',function(e){
                e.stopPropagation();
                changeQty(parseInt(this.dataset.id), parseInt(this.dataset.delta));
            });
        });
        row.querySelector('.ci-remove').addEventListener('click',function(e){
            e.stopPropagation();
            removeItem(parseInt(this.dataset.id));
        });

        container.appendChild(row);
    });
}

function calcTotals(){
    const sub=cart.reduce((s,c)=>s+c.price*c.qty,0);
    const manDisc=parseFloat(document.getElementById('manual-disc').value)||0;
    const redeemPts=parseInt(document.getElementById('loyalty-redeem').value)||0;
    const redeemVal=redeemPts*LOY_VAL;
    const creditUsed=parseFloat(document.getElementById('credit-use').value)||0;
    const afterDisc=Math.max(0,sub-manDisc-promoDisc-redeemVal-creditUsed);

    let tax=0, taxBase=0, subtotalDisplay=sub;
    if(TAX_MODE==='exclusive'&&TAX_RATE>0){
        // Tax added on top of discounted price
        taxBase=afterDisc;
        tax=Math.round(taxBase*TAX_RATE*100)/100;
    } else if(TAX_MODE==='inclusive'&&TAX_RATE>0){
        // Tax already inside the price — extract it
        taxBase=afterDisc;
        tax=Math.round((taxBase-(taxBase/(1+TAX_RATE)))*100)/100;
    }
    const total=Math.max(0,afterDisc+(TAX_MODE==='exclusive'?tax:0));
    return{sub,manDisc,redeemVal,creditUsed,afterDisc,tax,total,subtotalDisplay};
}

function updateTotals(){
    const {sub,manDisc,redeemVal,creditUsed,tax,total}=calcTotals();
    currentTotal=total;
    setText('t-subtotal',CUR+fmt(sub));
    setText('t-total',CUR+fmt(total));
    show('promo-disc-row',promoDisc>0);
    if(promoDisc>0){setText('t-promo-disc','-'+CUR+fmt(promoDisc));setText('promo-label',promoName);}
    show('disc-row',manDisc>0);
    if(manDisc>0) setText('t-disc','-'+CUR+fmt(manDisc));
    show('loyalty-row-display',redeemVal>0);
    if(redeemVal>0) setText('t-loyalty-val','-'+CUR+fmt(redeemVal));
    show('credit-row-display',creditUsed>0);
    if(creditUsed>0) setText('t-credit-val','-'+CUR+fmt(creditUsed));
    // Show VAT row on POS
    const taxRow=document.getElementById('tax-display-row');
    if(taxRow){
        taxRow.style.display=tax>0?'flex':'none';
        setText('t-tax-display',CUR+fmt(tax));
        setText('t-tax-label',TAX_LABEL+(TAX_MODE==='inclusive'?' (incl.)':''));
    }
    updateChange();
}

// ── Amount Tendered / Change ──────────────────────────────────
function updateChange(){
    const tendered=parseFloat(document.getElementById('tendered').value)||0;
    const changeRow=document.getElementById('change-row');
    const changeEl=document.getElementById('t-change');
    if(payment==='cash'&&tendered>0){
        const change=tendered-currentTotal;
        changeRow.style.display='flex';
        changeEl.textContent=(change>=0?'Give back: ':'Still owed: ')+CUR+fmt(Math.abs(change));
        changeEl.style.color=change>=0?'var(--green)':'var(--red)';
    } else {
        changeRow.style.display='none';
    }
}
document.getElementById('tendered').addEventListener('input',updateChange);

document.getElementById('manual-disc').addEventListener('input',updateTotals);
document.getElementById('loyalty-redeem').addEventListener('input',updateTotals);
document.getElementById('credit-use').addEventListener('input',updateTotals);

// ── Payment method toggle ────────────────────────────────────────────
document.querySelectorAll('.pay-btn').forEach(b=>{
    b.addEventListener('click',function(){
        document.querySelectorAll('.pay-btn').forEach(x=>x.classList.remove('active'));
        this.classList.add('active');
        payment=this.dataset.method;
        document.getElementById('split-inputs').style.display=payment==='split'?'flex':'none';
        // Show tendered row only for cash payment
        document.getElementById('tendered-row').style.display=payment==='cash'?'flex':'none';
        // Show gift card row only for gift_card payment
        document.getElementById('gc-redeem-row').style.display=payment==='gift_card'?'block':'none';
        if(payment!=='gift_card'){
            document.getElementById('gc-balance-info').style.display='none';
            document.getElementById('gc-error-info').style.display='none';
        }
        if(payment!=='cash'){
            document.getElementById('change-row').style.display='none';
            document.getElementById('tendered').value='';
        }
        updateChange();
    });
});

// ── Gift Card Balance Check at POS ───────────────────────────────────
let gcVerifiedCode='', gcVerifiedBalance=0;
document.getElementById('btn-gc-check').addEventListener('click',function(){
    const code=document.getElementById('gc-code-input').value.trim();
    if(!code){alert('Enter a gift card code');return;}
    const btn=this;btn.textContent='...';btn.disabled=true;
    post({action:'bs_pos_check_gc',nonce:NONCE,code}).then(d=>{
        btn.textContent='Check';btn.disabled=false;
        const info=document.getElementById('gc-balance-info');
        const err=document.getElementById('gc-error-info');
        if(d.success){
            gcVerifiedCode=d.data.code;
            gcVerifiedBalance=parseFloat(d.data.balance);
            info.textContent='✓ Balance: '+CUR+fmt(gcVerifiedBalance)+(d.data.expires_at?' | Expires: '+d.data.expires_at:'');
            info.style.display='block';
            err.style.display='none';
        } else {
            gcVerifiedCode='';gcVerifiedBalance=0;
            err.textContent=typeof d.data==='string'?d.data:(d.data?.message||'Invalid card');
            err.style.display='block';
            info.style.display='none';
        }
    });
});

// ── Quick-tender buttons ─────────────────────────────────────────────
document.querySelectorAll('.quick-tender').forEach(btn=>{
    btn.addEventListener('click',function(){
        document.getElementById('tendered').value=this.dataset.val;
        updateChange();
    });
});


// ── Customer search ─────────────────────────────────────────
document.getElementById('cust-search').addEventListener('input',function(){
    clearTimeout(custTimer);
    const q=this.value.trim();
    if(q.length<2){closeAC();return;}
    custTimer=setTimeout(()=>searchCustomers(q),280);
});
function searchCustomers(q){
    get({action:'bs_search_customers',q}).then(d=>{
        if(!d.success||!d.data.length){closeAC();return;}
        const ac=document.getElementById('cust-ac');
        ac.innerHTML=d.data.map(c=>`<div class="bs-ac-item" onclick="selectCustomer(${c.id},'${esc(c.name)}',${c.loyalty_points},${c.credit_balance})"><strong>${escH(c.name)}</strong><span>${escH(c.phone)} - ${escH(c.email)}</span></div>`).join('');
        ac.style.display='block';
    });
}
window.selectCustomer=function(id,name,pts,credit){
    customer={id,name,loyalty_points:pts,credit_balance:credit};
    document.getElementById('cust-search').value=name;
    closeAC();
    document.getElementById('btn-clear-cust').style.display='';
    const lv=LOY_VAL;
    document.getElementById('cust-loyalty-info').style.display='block';
    document.getElementById('cust-loyalty-info').innerHTML=`⭐ <strong>${pts}</strong> pts (${CUR}${fmt(pts*lv)} value) &nbsp;|&nbsp; 💰 Credit: <strong>${CUR}${fmt(credit)}</strong>`;
    // Show credit input if customer has credit balance
    const creditRow=document.getElementById('credit-use-row');
    if(credit>0){
        creditRow.style.display='flex';
        document.getElementById('credit-avail-label').textContent='(avail: '+CUR+fmt(credit)+')';
        document.getElementById('credit-use').max=credit;
    } else {
        creditRow.style.display='none';
    }
    document.getElementById('credit-use').value=0;
    updateTotals();
};
document.getElementById('btn-clear-cust').addEventListener('click',()=>{
    customer=null;document.getElementById('cust-search').value='';
    document.getElementById('cust-loyalty-info').style.display='none';
    document.getElementById('btn-clear-cust').style.display='none';
    document.getElementById('loyalty-redeem').value=0;
    document.getElementById('credit-use').value=0;
    document.getElementById('credit-use-row').style.display='none';
    updateTotals();
});
function closeAC(){document.getElementById('cust-ac').style.display='none';}
document.addEventListener('click',e=>{if(!e.target.closest('#cust-search')&&!e.target.closest('#cust-ac'))closeAC();});

// Quick add customer
document.getElementById('btn-quick-cust').addEventListener('click',()=>document.getElementById('quick-cust-modal').style.display='flex');
document.getElementById('save-quick-cust').addEventListener('click',function(){
    const name=document.getElementById('qc-name').value.trim();
    const phone=document.getElementById('qc-phone').value.trim();
    const email=document.getElementById('qc-email').value.trim();
    if(!name){alert('Name is required');return;}
    if(!phone){alert('Phone number is required');return;}
    const btn=this;
    btn.disabled=true; btn.textContent='Saving...';
    // Use fetch directly so we control headers precisely
    fetch(AJAX,{
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:new URLSearchParams({action:'bs_quick_add_customer',nonce:NONCE,name,phone,email})
    })
    .then(r=>r.json())
    .then(d=>{
        btn.disabled=false; btn.textContent='Add Customer';
        if(d.success&&d.data){
            document.getElementById('quick-cust-modal').style.display='none';
            // Clear form for next use
            document.getElementById('qc-name').value='';
            document.getElementById('qc-phone').value='';
            document.getElementById('qc-email').value='';
            selectCustomer(parseInt(d.data.id),d.data.name,
                parseFloat(d.data.loyalty_points||0),
                parseFloat(d.data.credit_balance||0));
        } else {
            alert('Could not add customer: '+(d.data||'Please try again'));
        }
    })
    .catch(err=>{
        btn.disabled=false; btn.textContent='Add Customer';
        alert('Network error. Please check connection and try again.');
        console.error('Quick add customer error:',err);
    });
});

// ── Promo Code ──────────────────────────────────────────────
document.getElementById('btn-apply-promo').addEventListener('click',()=>{
    const code=document.getElementById('promo-code').value.trim().toUpperCase();
    if(!code)return;
    const sub=cart.reduce((s,c)=>s+c.price*c.qty,0);
    get({action:'bs_validate_promo',code,subtotal:sub}).then(d=>{
        if(d.success){
            promoDisc=parseFloat(d.data.discount);
            promoCode=code; promoName=d.data.promo.name;
            document.getElementById('btn-clear-promo').style.display='';
            updateTotals();
        } else {
            if(d.data?.code==='manager_required')alert(d.data.message);
            else alert(d.data||'Invalid promo code');
        }
    });
});
document.getElementById('btn-clear-promo').addEventListener('click',()=>{
    promoDisc=0;promoCode='';promoName='';
    document.getElementById('promo-code').value='';
    document.getElementById('btn-clear-promo').style.display='none';
    updateTotals();
});

// ── Checkout ─────────────────────────────────────────────────
document.getElementById('btn-checkout').addEventListener('click',function(){
    if(!cart.length)return;
    const sub=cart.reduce((s,c)=>s+c.price*c.qty,0);
    const manDisc=parseFloat(document.getElementById('manual-disc').value)||0;
    const discPct=sub>0?(manDisc/sub)*100:0;
    if(discPct>MGR_THR&&!IS_MGR){showMgrModal();return;}
    submitSale();
});

function showMgrModal(){document.getElementById('mgr-modal').style.display='flex';}
document.getElementById('confirm-mgr-pin').addEventListener('click',()=>{
    const pin=document.getElementById('mgr-pin').value;
    post({action:'bs_pin_login',pin}).then(d=>{
        if(d.success&&d.data){
            // verify manager role (server returns success only if user has POS cap)
            document.getElementById('mgr-modal').style.display='none';
            document.getElementById('mgr-pin').value='';
            submitSale();
        } else {
            document.getElementById('mgr-pin-error').textContent='Invalid PIN';
        }
    });
});

function submitSale(){
    const btn=document.getElementById('btn-checkout');
    btn.disabled=true;btn.textContent='Processing...';
    const manDisc=parseFloat(document.getElementById('manual-disc').value)||0;
    const redeemPts=parseInt(document.getElementById('loyalty-redeem').value)||0;
    const creditUsed=parseFloat(document.getElementById('credit-use').value)||0;
    const note=document.getElementById('pos-note').value;
    let payDet={};
    if(payment==='split'){payDet={cash:parseFloat(document.getElementById('split-cash').value)||0,card:parseFloat(document.getElementById('split-card').value)||0};}
    if(payment==='gift_card'){
        const gcCode=document.getElementById('gc-code-input').value.trim();
        if(!gcCode){btn.disabled=false;btn.textContent='Complete Sale';alert('Enter a gift card code');return;}
        payDet={gc_code:gcCode};
    }

    post({action:'bs_submit_sale',nonce:NONCE,
        cart:JSON.stringify(cart),payment,payment_details:JSON.stringify(payDet),
        discount:manDisc,promo_code:promoCode,
        customer_id:customer?.id||0,
        credit_used:creditUsed,loyalty_redeem:redeemPts,note
    }).then(d=>{
        btn.disabled=false;btn.textContent='Complete Sale';
        if(d.success){
            currentSaleId=d.data.sale_id;
            currentSaleCart=[...cart];
            showReceipt(d.data);
        } else {
            if(d.data?.code==='manager_required'){showMgrModal();}
            else if(handleNoBranchError(d)){/* picker shown */}
            else if(d.data?.code==='insufficient_stock' || d.data?.code==='stock_race'){
                alert(d.data.message);
            }
            else if(d.data?.code==='gc_insufficient'){
                // Gift card balance insufficient — show clear message
                alert(d.data.message);
                document.getElementById('gc-error-info').textContent=d.data.message;
                document.getElementById('gc-error-info').style.display='block';
                document.getElementById('gc-balance-info').style.display='none';
            }
            else alert('Error: '+(typeof d.data==='string'?d.data:d.data?.message||'Unknown error'));
        }
    });
}

// ── Receipt ───────────────────────────────────────────────────
function showReceipt(data){
    // Stash for the Bluetooth print path — loyalty_earned and other
    // server-computed fields aren't in the modal DOM but the bridge wants
    // them in the receipt payload.
    lastReceiptData = data;

    const {sub,manDisc,redeemVal,tax}=calcTotals();
    const tendered=parseFloat(document.getElementById('tendered').value)||0;
    const change=tendered>0?tendered-currentTotal:0;

    // Fill receipt fields
    setText('r-ref', data.ref);
    setText('r-date', new Date().toLocaleString('en-GB',{day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'}));
    setText('r-total', CUR+fmt(data.total));
    setText('r-subtotal', CUR+fmt(sub));
    setText('r-payment', payment.toUpperCase());
    setText('r-customer', customer?customer.name:'Walk-in');

    // Discount rows
    show('r-disc-row', manDisc>0);
    if(manDisc>0) setText('r-disc', '-'+CUR+fmt(manDisc));
    show('r-promo-row', promoDisc>0);
    if(promoDisc>0){ setText('r-promo', '-'+CUR+fmt(promoDisc)+' ('+promoName+')'); }
    // Tax — use JS-computed tax so receipt matches what customer saw on screen
    const displayTax = tax > 0 ? tax : (parseFloat(data.tax)||0);
    show('r-tax-row', displayTax>0);
    if(displayTax>0){
        setText('r-tax', CUR+fmt(displayTax));
        // Update the tax label element on the receipt
        const taxLabelEl=document.getElementById('r-tax-label-display');
        if(taxLabelEl) taxLabelEl.textContent=TAX_LABEL+(TAX_MODE==='inclusive'?' (incl.)':'');
    }

    // Tendered / Change (cash only)
    show('r-tendered-row', payment==='cash' && tendered>0);
    show('r-change-row',   payment==='cash' && tendered>0);
    if(tendered>0){ setText('r-tendered', CUR+fmt(tendered)); setText('r-change', CUR+fmt(Math.abs(change))); }

    // Store credit used
    const creditUsed=parseFloat(data.credit_used)||0;
    show('r-credit-row', creditUsed>0);
    if(creditUsed>0) setText('r-credit', '-'+CUR+fmt(creditUsed));

    // Loyalty points redeemed
    const loyRedeemed=parseInt(data.loyalty_redeemed)||0;
    const loyRedVal=loyRedeemed*LOY_VAL;
    show('r-loyalty-redeem-row', loyRedeemed>0);
    if(loyRedeemed>0) setText('r-loyalty-redeem', '-'+CUR+fmt(loyRedVal)+' ('+loyRedeemed+' pts)');

    // Gift card
    const gcRedeemed=parseFloat(data.gc_redeemed)||0;
    const gcCode=data.gc_code||'';
    show('r-gc-row', gcRedeemed>0);
    if(gcRedeemed>0) setText('r-gc', '-'+CUR+fmt(gcRedeemed)+(gcCode?' ('+gcCode.slice(-4)+')':''));

    // Split payment breakdown
    const isSplit=payment==='split';
    show('r-split-row', isSplit);
    if(isSplit){
        const splitCash=parseFloat(document.getElementById('split-cash').value)||0;
        const splitCard=parseFloat(document.getElementById('split-card').value)||0;
        setText('r-split', 'Cash: '+CUR+fmt(splitCash)+' | Card: '+CUR+fmt(splitCard));
    }

    // Items table
    document.getElementById('r-items').innerHTML = currentSaleCart.map(c=>`
        <div style="display:flex;justify-content:space-between;padding:3px 0;border-bottom:1px dotted #eee;font-size:.8rem">
            <span style="flex:1;margin-right:8px">${escH(c.title)}<br><span style="color:#999;font-size:.72rem">${escH(c.author||'')} × ${c.qty} @ ${CUR}${fmt(c.price)}</span></span>
            <span style="white-space:nowrap;font-weight:600">${CUR}${fmt(c.price*c.qty)}</span>
        </div>`).join('');

    // Loyalty
    const loyEl=document.getElementById('r-loyalty');
    loyEl.textContent = data.loyalty_earned>0 ? '⭐ +'+data.loyalty_earned+' loyalty points earned!' : '';

    // Email pre-fill
    if(customer?.email) document.getElementById('r-email').value=customer.email;

    // WhatsApp button
    const waBtn=document.getElementById('btn-wa-receipt');
    if(waBtn){
        waBtn.onclick=()=>{
            // Get phone: customer on file, or ask
            let phone=(customer?.phone||'').replace(/[^0-9]/g,'');
            if(!phone){
                const input=prompt('Enter customer WhatsApp number\n(include country code, digits only, e.g. 2348012345678):','');
                if(!input) return;
                phone=input.replace(/[^0-9]/g,'');
            }
            if(!phone){alert('No phone number entered.');return;}

            // Build a clean readable message
            const taxLine=data.tax>0?'\n'+TAX_LABEL+': '+CUR+fmt(data.tax):'';
            const discLine=(data.discount>0||data.promo_discount>0)?
                '\nDiscount: -'+CUR+fmt((parseFloat(data.discount)||0)+(parseFloat(data.promo_discount)||0)):'';
            const itemLines=currentSaleCart.map(c=>
                '  - '+c.title+' × '+c.qty+' = '+CUR+fmt(c.price*c.qty)
            ).join('\n');

            const msg=[
                '🏪 *'+SHOP+'*',
                '━━━━━━━━━━━━━━━━',
                '📄 Receipt: *'+data.ref+'*',
                '📅 '+new Date().toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'}),
                '',
                itemLines,
                '━━━━━━━━━━━━━━━━',
                'Subtotal: '+CUR+fmt(currentSaleCart.reduce((s,c)=>s+c.price*c.qty,0)),
                discLine,
                taxLine,
                '*TOTAL: '+CUR+fmt(data.total)+'*',
                '',
                RECEIPT_FOOTER,
            ].filter(l=>l!==undefined&&l!==null&&!(typeof l==='string'&&l===''&&l!==itemLines)).join('\n');

            // wa.me is the correct universal WhatsApp link
            const url='https://wa.me/'+phone+'?text='+encodeURIComponent(msg);
            window.open(url,'_blank');
        };
    }

    document.getElementById('receipt-modal').style.display='flex';
}

// ── Print Receipt ──────────────────────────────────────────────
//
// Two paths, picked at click time:
//   1. Web Bluetooth ESC/POS bridge — preferred when paired. Encodes the
//      receipt into ESC/POS bytes and writes them directly to the printer
//      over BLE. Sub-second on Android, no print dialog, no paper-size
//      gymnastics.
//   2. Print window — universal fallback. Opens an isolated window with
//      just the receipt HTML and links to assets/css/pos-print.css. Works
//      on every browser, including Safari and Firefox where Web Bluetooth
//      isn't available, and on every printer including USB / network
//      thermals that the OS already knows about.
//
// We try (1), and on any failure (provider rejection, characteristic
// dropped between pairing and print, encoding error) fall back to (2)
// rather than leaving the user staring at a stuck dialog.
window.printReceipt = function () {
    var bridge = window.BSESCPOS;
    if (bridge && bridge.isPaired()) {
        try {
            bridge.print(collectReceiptForBridge()).then(function () {
                // No UI flash needed — the printer's own buzzer/cut is the
                // confirmation. We do refresh the status line so the cashier
                // can see the last-printed timestamp at a glance.
                setPrinterStatus('✓ Sent to ' + (bridge.deviceName() || 'printer'));
            }).catch(function (err) {
                console.warn('Bluetooth print failed, falling back to print dialog:', err);
                setPrinterStatus('Bluetooth print failed: ' + (err && err.message ? err.message : 'unknown error') + '. Used the print dialog instead.', true);
                printViaWindow();
            });
            return;
        } catch (e) {
            console.warn('Bluetooth bridge threw, falling back:', e);
        }
    }
    printViaWindow();
};

// Build the structured receipt object the bridge wants. Most fields are
// already in JS state (currentSaleCart, customer, payment, etc.); we just
// reformat them and pre-format currency so the bridge doesn't have to
// know about NGN vs other locales.
function collectReceiptForBridge() {
    var totals = calcTotals();
    var tendered = parseFloat(document.getElementById('tendered').value) || 0;
    var change = tendered > 0 ? tendered - currentTotal : 0;
    return {
        paperWidth:    PAPER_WIDTH,
        shop:          SHOP,
        address:       SHOP_ADDRESS,
        phone:         SHOP_PHONE,
        ref:           document.getElementById('r-ref').textContent || '',
        date:          document.getElementById('r-date').textContent || '',
        staff:         STAFF_NAME,
        customer:      customer ? customer.name : 'Walk-in',
        payment:       payment.toUpperCase(),
        items: currentSaleCart.map(function (c) {
            return {
                title: c.title,
                qty:   c.qty,
                price: CUR + fmt(c.price),
                total: CUR + fmt(c.price * c.qty),
            };
        }),
        subtotal:      CUR + fmt(totals.sub),
        discount:      totals.manDisc > 0 ? CUR + fmt(totals.manDisc) : '',
        promo:         promoDisc > 0       ? CUR + fmt(promoDisc) : '',
        tax:           totals.tax > 0      ? CUR + fmt(totals.tax) : '',
        taxLabel:      TAX_LABEL + (TAX_MODE === 'inclusive' ? ' (incl)' : ''),
        total:         CUR + fmt(currentTotal),
        tendered:      (payment === 'cash' && tendered > 0) ? CUR + fmt(tendered) : '',
        change:        (payment === 'cash' && tendered > 0) ? CUR + fmt(Math.abs(change)) : '',
        loyaltyEarned: lastReceiptData && lastReceiptData.loyalty_earned ? lastReceiptData.loyalty_earned : 0,
        footer:        RECEIPT_FOOTER,
    };
}

// Print-window fallback. Replaces the previous inline-CSS approach with a
// <link> to assets/css/pos-print.css so designers can iterate on print
// styling without touching JS, and so reprint (?bookshop_print_receipt=1)
// shares the exact same stylesheet.
function printViaWindow() {
    var content = document.getElementById('printable-receipt').innerHTML;
    var w = window.open('', 'bs_receipt_print', 'width=420,height=640');
    if (!w) { alert('Please allow pop-ups to print the receipt.'); return; }
    w.document.open();
    w.document.write(
        '<!DOCTYPE html><html><head><meta charset="UTF-8">' +
        '<title>Receipt</title>' +
        '<link rel="stylesheet" href="' + PRINT_CSS_URL + '">' +
        '</head><body data-paper-width="' + PAPER_WIDTH + '">' +
        '<div id="printable-receipt">' + content + '</div>' +
        '</body></html>'
    );
    w.document.close();
    var doPrint = function () {
        try { w.focus(); w.print(); } catch (e) {}
        // Brief delay so the print dialog has a beat to render before
        // we close the window from under it.
        setTimeout(function () { try { w.close(); } catch (e) {} }, 500);
    };
    if (w.document.readyState === 'complete') {
        // Wait for the linked stylesheet to load before printing — without
        // this, Safari sometimes prints before the CSS applies and you
        // get the unstyled receipt. Fall back to an absolute timeout.
        setTimeout(doPrint, 250);
    } else {
        w.addEventListener('load', doPrint);
        setTimeout(doPrint, 1200); // hard fallback
    }
}

// ── Bluetooth pairing UI ─────────────────────────────────────────────────
// Show the Pair button only when Web Bluetooth is supported in this
// browser. The status line below it tracks pairing state — gives the
// cashier feedback that "yes, you're connected" without an extra click.
function setPrinterStatus(msg, isError) {
    var el = document.getElementById('bs-printer-status');
    if (!el) return;
    el.textContent = msg || '';
    el.style.color = isError ? '#c0392b' : 'var(--muted)';
}

function refreshPairButton() {
    var btn = document.getElementById('btn-pair-printer');
    var row = document.getElementById('bs-printer-pair-row');
    if (!btn || !row) return;
    var bridge = window.BSESCPOS;
    if (!bridge || !bridge.isSupported()) {
        row.style.display = 'none';
        return;
    }
    row.style.display = 'flex';
    if (bridge.isPaired()) {
        btn.textContent = '✓ Paired: ' + (bridge.deviceName() || 'printer');
        btn.title = 'Click to disconnect this printer';
        setPrinterStatus('Print sends straight to the printer (no dialog)');
    } else {
        btn.textContent = '🔗 Pair Bluetooth printer';
        btn.title = 'Pair a thermal printer for one-tap printing (Chrome/Edge only)';
        setPrinterStatus('');
    }
}

document.addEventListener('click', function (e) {
    if (e.target && e.target.id === 'btn-pair-printer') {
        var bridge = window.BSESCPOS;
        if (!bridge) return;
        var btn = e.target;
        if (bridge.isPaired()) {
            // Second click = disconnect. Confirm so an accidental double-tap
            // doesn't drop the connection mid-shift.
            if (!confirm('Disconnect this printer?')) return;
            bridge.disconnect();
            refreshPairButton();
            return;
        }
        btn.disabled = true;
        btn.textContent = 'Pairing…';
        bridge.pair().then(function (info) {
            setPrinterStatus('Connected to ' + (info.name || 'printer') + '. Print sends directly from now on.');
            refreshPairButton();
        }).catch(function (err) {
            // NotFoundError is just "user cancelled the picker" — not an error.
            if (err && err.name === 'NotFoundError') {
                refreshPairButton();
                return;
            }
            setPrinterStatus('Pairing failed: ' + (err && err.message ? err.message : 'unknown error'), true);
            refreshPairButton();
        }).finally(function () {
            btn.disabled = false;
        });
    }
});

// Hook the bridge's connect/disconnect events so the button + status line
// reflect auto-reconnect on page load and accidental disconnects mid-shift.
if (window.BSESCPOS) {
    BSESCPOS.on('connect',    refreshPairButton);
    BSESCPOS.on('disconnect', refreshPairButton);
    refreshPairButton();
}
window.sendEmailReceipt=function(){
    const email=document.getElementById('r-email').value.trim();
    if(!email)return;
    post({action:'bs_send_receipt_email',sale_id:currentSaleId,email}).then(d=>{
        alert(d.success?'Receipt sent!':'Failed to send receipt.');
    });
};
window.newSale=function(){
    document.getElementById('receipt-modal').style.display='none';
    cart=[];customer=null;promoDisc=0;promoCode='';promoName='';currentTotal=0;
    lastReceiptData=null;
    // Reset all inputs
    document.getElementById('cust-search').value='';
    document.getElementById('cust-loyalty-info').style.display='none';
    document.getElementById('btn-clear-cust').style.display='none';
    document.getElementById('promo-code').value='';
    document.getElementById('btn-clear-promo').style.display='none';
    document.getElementById('manual-disc').value=0;
    document.getElementById('loyalty-redeem').value=0;
    document.getElementById('credit-use').value=0;
    document.getElementById('credit-use-row').style.display='none';
    document.getElementById('tendered').value='';
    document.getElementById('change-row').style.display='none';
    document.getElementById('pos-note').value='';
    document.getElementById('pos-search').value='';
    // Reset payment to cash
    payment='cash';
    document.querySelectorAll('.pay-btn').forEach(x=>x.classList.remove('active'));
    document.querySelector('.pay-btn[data-method="cash"]').classList.add('active');
    document.getElementById('split-inputs').style.display='none';
    document.getElementById('tendered-row').style.display='flex';
    // Reset gift card fields
    document.getElementById('gc-redeem-row').style.display='none';
    document.getElementById('gc-code-input').value='';
    document.getElementById('gc-balance-info').style.display='none';
    document.getElementById('gc-error-info').style.display='none';
    gcVerifiedCode='';gcVerifiedBalance=0;
    renderGrid([]);renderCart();updateTotals();
    document.getElementById('pos-search').focus();
};

// ── Shift ─────────────────────────────────────────────────────
document.getElementById('btn-open-shift').addEventListener('click',function(){
    if(!currentBranchId){ showBranchModal(false); return; }
    document.getElementById('shift-open-modal').style.display='flex';
});
document.getElementById('confirm-open-shift').addEventListener('click',()=>{
    const cash=parseFloat(document.getElementById('opening-cash').value)||0;
    post({action:'bs_open_shift',nonce:NONCE,opening_cash:cash}).then(d=>{
        if(d.success){shiftId=d.data.shift_id;document.getElementById('shift-id-input').value=shiftId;
            document.getElementById('shift-open-modal').style.display='none';
            document.getElementById('btn-open-shift').style.display='none';
            document.getElementById('btn-close-shift').style.display='';
            document.getElementById('pos-shift-info').textContent='Shift open | Opening: '+CUR+fmt(cash);
        } else {
            if(handleNoBranchError(d)){
                document.getElementById('shift-open-modal').style.display='none';
            } else {
                alert('Error: '+(typeof d.data==='string'?d.data:d.data?.message||'Could not open shift'));
            }
        }
    });
});
document.getElementById('btn-close-shift').addEventListener('click',()=>document.getElementById('shift-close-modal').style.display='flex');
document.getElementById('closing-cash').addEventListener('input',function(){
    // Preview variance (approximate)
    document.getElementById('shift-variance-preview').textContent='';
});
document.getElementById('confirm-close-shift').addEventListener('click',()=>{
    const closing=parseFloat(document.getElementById('closing-cash').value)||0;
    const notes=document.getElementById('shift-notes').value;
    post({action:'bs_close_shift',nonce:NONCE,shift_id:shiftId,closing_cash:closing,notes}).then(d=>{
        if(d.success){
            const v=d.data;
            alert(`Shift closed!\nExpected: ${CUR}${fmt(v.expected)}\nActual: ${CUR}${fmt(v.closing)}\nVariance: ${CUR}${fmt(v.variance)}`);
            document.getElementById('shift-close-modal').style.display='none';
            document.getElementById('btn-close-shift').style.display='none';
            document.getElementById('btn-open-shift').style.display='';
            document.getElementById('pos-shift-info').textContent='';
            shiftId=0;
        }
    });
});

// ── Utils ─────────────────────────────────────────────────────
function fmt(n){return parseFloat(n).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g,',');}
function escH(s){const d=document.createElement('div');d.textContent=s||'';return d.innerHTML;}
function escAttr(s){return(s||'').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/'/g,'&#39;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
function esc(s){return(s||'').replace(/'/g,"\\'").replace(/\\/g,'\\\\');}
function setText(id,v){const e=document.getElementById(id);if(e)e.textContent=v;}
function show(id,v){const e=document.getElementById(id);if(e)e.style.display=v?'':'none';}
function get(p){return fetch(AJAX+'?'+new URLSearchParams(p)).then(r=>r.json());}
function post(p){return fetch(AJAX,{method:'POST',body:new URLSearchParams(p)}).then(r=>r.json());}

// ── Held / Parked Sales ────────────────────────────────────────────────────────
function loadHeldSales(){
    get({action:'bs_get_held_sales'}).then(function(d){
        if(!d.success) return;
        var sales=d.data||[];
        setText('held-count',sales.length);
        var body=document.getElementById('held-sales-body');
        var emptyMsg=document.getElementById('held-empty-msg');
        body.querySelectorAll('.held-sale-row').forEach(function(r){r.remove();});
        if(!sales.length){emptyMsg.style.display='block';return;}
        emptyMsg.style.display='none';
        sales.forEach(function(s){
            var row=document.createElement('div');
            row.className='held-sale-row';
            row.style.cssText='display:flex;align-items:center;gap:10px;padding:12px 16px;border-bottom:1px solid var(--border)';
            row.innerHTML='<div style="flex:1"><div style="font-weight:600;font-size:.88rem">'+escH(s.ref)+'</div>'
                +'<div style="font-size:.78rem;color:var(--muted)">'+s.item_count+' items &mdash; '+CUR+fmt(s.subtotal)+'</div>'
                +(s.note?'<div style="font-size:.75rem;color:var(--muted)">'+escH(s.note)+'</div>':'')
                +'</div>'
                +'<button onclick="recallHeld('+s.id+')" style="padding:6px 12px;background:var(--ink);color:var(--amber-l);border:none;border-radius:6px;cursor:pointer;font-size:.78rem">Recall</button>'
                +'<button onclick="deleteHeld('+s.id+')" style="padding:6px 10px;background:none;border:1px solid var(--border);border-radius:6px;cursor:pointer;font-size:.78rem;color:var(--red)">✕</button>';
            body.appendChild(row);
        });
    });
}
window.recallHeld=function(id){
    post({action:'bs_recall_held_sale',id:id}).then(function(d){
        if(!d.success){alert('Could not recall sale');return;}
        var data=d.data;
        // Restore cart
        cart=data.cart||[];
        // Restore customer
        if(data.customer){
            customer=data.customer;
            document.getElementById('cust-search').value=customer.name||'';
            document.getElementById('btn-clear-cust').style.display='';
        }
        document.getElementById('held-sales-modal').style.display='none';
        renderCart();updateTotals();loadHeldSales();
    });
};
window.deleteHeld=function(id){
    if(!confirm('Cancel this parked sale?')) return;
    post({action:'bs_delete_held_sale',id:id}).then(function(){loadHeldSales();});
};

document.getElementById('btn-held-sales').addEventListener('click',function(){
    loadHeldSales();
    document.getElementById('held-sales-modal').style.display='flex';
});
document.getElementById('btn-park-sale').addEventListener('click',function(){
    if(!cart.length){alert('Cart is empty — nothing to park.');return;}
    var note=prompt('Add a note for this parked sale (optional):')||'';
    post({action:'bs_park_sale',nonce:NONCE,cart:JSON.stringify(cart),customer:JSON.stringify(customer),note:note}).then(function(d){
        if(d.success){
            var ref=d.data.ref;
            cart=[];customer=null;promoDisc=0;promoCode='';promoName='';
            document.getElementById('cust-search').value='';
            document.getElementById('cust-loyalty-info').style.display='none';
            document.getElementById('btn-clear-cust').style.display='none';
            renderCart();updateTotals();loadHeldSales();
            // Brief confirmation
            var msg=document.createElement('div');
            msg.style.cssText='position:fixed;top:64px;left:50%;transform:translateX(-50%);background:var(--green);color:#fff;padding:10px 24px;border-radius:8px;font-weight:600;z-index:9999;font-size:.9rem';
            msg.textContent='Sale parked — '+ref;
            document.body.appendChild(msg);
            setTimeout(function(){msg.remove();},2500);
        } else if(handleNoBranchError(d)){
            /* picker shown */
        } else {
            alert('Could not park sale: '+(typeof d.data==='string'?d.data:d.data?.message||'Unknown error'));
        }
    });
});

// ── Keyboard Shortcuts ─────────────────────────────────────────────────────────
document.addEventListener('keydown',function(e){
    // Skip if typing in an input
    if(e.target.tagName==='INPUT'||e.target.tagName==='TEXTAREA'||e.target.tagName==='SELECT') return;
    switch(e.key){
        case 'F2': e.preventDefault(); document.getElementById('btn-park-sale').click(); break;
        case 'F3': e.preventDefault(); document.getElementById('pos-search').focus(); break;
        case 'F4': e.preventDefault(); document.getElementById('btn-checkout').click(); break;
        case 'F5': e.preventDefault(); document.getElementById('btn-held-sales').click(); break;
        case '1':  e.preventDefault(); document.querySelector('.pay-btn[data-method="cash"]').click(); break;
        case '2':  e.preventDefault(); document.querySelector('.pay-btn[data-method="card"]').click(); break;
        case '3':  e.preventDefault(); document.querySelector('.pay-btn[data-method="transfer"]').click(); break;
        case '4':  e.preventDefault(); document.querySelector('.pay-btn[data-method="split"]').click(); break;
        case '5':  e.preventDefault(); document.querySelector('.pay-btn[data-method="gift_card"]').click(); break;
        case 'Escape': window.newSale&&document.getElementById('receipt-modal').style.display==='flex'&&newSale(); break;
    }
});

// Load held count on POS open
loadHeldSales();

<?php if($shift): ?>
document.getElementById('pos-shift-info').textContent='Shift open since <?=esc_js(wp_date('H:i',strtotime($shift->opened_at)))?>';
<?php endif; ?>
})();
</script>
<!-- Web Bluetooth ESC/POS bridge — exposes window.BSESCPOS for the print
     handler above. Loaded last so the script tag's IIFE has a chance to
     register the listeners and (on Chrome 92+) silently auto-reconnect to
     a previously paired printer before the cashier needs it. -->
<script src="<?=esc_url(BOOKSHOP_URL.'assets/js/escpos-bridge.js?v='.BOOKSHOP_VERSION)?>"></script>
</body>
</html>
<?php
}


/**
 * Render a printable receipt for a past sale.
 *
 * Output is an isolated HTML document that links to assets/css/pos-print.css
 * (same stylesheet the live POS print window uses) and auto-fires
 * window.print() on load. Closing the print dialog leaves the user looking
 * at a clean on-screen preview thanks to the @media screen rules in the CSS.
 *
 * Currency / formatting decisions deliberately match what the live receipt
 * shows — same bs_fmt(), same date format, same labels — so a reprint is
 * literally indistinguishable from the original.
 */
function bs_render_printable_receipt($sale_id){
    $sale  = bs_get_sale(intval($sale_id));
    if (!$sale) wp_die('Sale not found', 'Not Found', ['response' => 404]);

    $items    = bs_get_sale_items($sale->id);
    $customer = $sale->customer_id ? bs_get_customer($sale->customer_id) : null;

    $shop     = get_option('bookshop_receipt_header', get_bloginfo('name'));
    $tagline  = get_option('bookshop_tagline', '');
    $address  = get_option('bookshop_address', '');
    $phone    = get_option('bookshop_phone', '');
    $logo     = get_option('bookshop_logo_url', '');
    $footer   = get_option('bookshop_receipt_footer', 'Thank you for shopping with us!');
    $tax_lbl  = get_option('bookshop_tax_label', 'VAT');
    $paper    = intval(get_option('bookshop_receipt_paper_width', 80)) === 58 ? 58 : 80;

    $css_url  = esc_url(BOOKSHOP_URL . 'assets/css/pos-print.css?v=' . BOOKSHOP_VERSION);
    $auto_print = empty($_GET['no_print']); // ?no_print=1 disables auto-fire for proofreading

    // The escapes below are deliberately straightforward — this template is
    // small enough that helper functions would obscure the layout. Every
    // dynamic value passes through esc_html / nl2br(esc_html) / bs_fmt.
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Receipt <?=esc_html($sale->sale_ref)?></title>
<link rel="stylesheet" href="<?=$css_url?>">
</head>
<body data-paper-width="<?=$paper?>">
    <div class="bsr">
        <?php if ($logo): ?>
        <img src="<?=esc_url($logo)?>" alt="">
        <?php endif; ?>

        <div class="bsr-shop"><?=esc_html($shop)?></div>
        <?php if ($tagline): ?><div class="bsr-tagline"><?=esc_html($tagline)?></div><?php endif; ?>
        <?php if ($address): ?><div class="bsr-address"><?=nl2br(esc_html($address))?></div><?php endif; ?>
        <?php if ($phone):   ?><div class="bsr-phone">Tel: <?=esc_html($phone)?></div><?php endif; ?>

        <div style="text-align:center;font-size:9pt;margin-top:4pt;font-weight:700">★ DUPLICATE RECEIPT ★</div>

        <hr class="bsr-divider">
        <div class="bsr-meta">
            <div class="bsr-meta-row"><span>Ref:</span><strong><?=esc_html($sale->sale_ref)?></strong></div>
            <div class="bsr-meta-row"><span>Date:</span><span><?=esc_html(wp_date('d M Y H:i', strtotime($sale->created_at)))?></span></div>
            <?php if (!empty($sale->staff_name)): ?>
            <div class="bsr-meta-row"><span>Staff:</span><span><?=esc_html($sale->staff_name)?></span></div>
            <?php endif; ?>
            <div class="bsr-meta-row"><span>Customer:</span><span><?=esc_html($customer ? $customer->name : 'Walk-in')?></span></div>
            <div class="bsr-meta-row"><span>Payment:</span><span><?=esc_html(strtoupper($sale->payment_method))?></span></div>
        </div>

        <hr class="bsr-divider">
        <div class="bsr-items">
            <?php foreach ($items as $it): ?>
            <div class="bsr-item">
                <div class="bsr-item-line">
                    <div class="bsr-item-title"><?=esc_html($it->title)?></div>
                    <div class="bsr-item-meta"><?=intval($it->qty)?> @ <?=bs_fmt($it->unit_price)?></div>
                </div>
                <div class="bsr-item-amt"><?=bs_fmt($it->line_total)?></div>
            </div>
            <?php endforeach; ?>
        </div>

        <hr class="bsr-divider">
        <div class="bsr-totals">
            <div class="bsr-meta-row"><span>Subtotal</span><span><?=bs_fmt($sale->subtotal)?></span></div>
            <?php if ($sale->discount > 0): ?>
            <div class="bsr-meta-row"><span>Discount</span><span>-<?=bs_fmt($sale->discount)?></span></div>
            <?php endif; ?>
            <?php if ($sale->promo_discount > 0): ?>
            <div class="bsr-meta-row"><span>Promo</span><span>-<?=bs_fmt($sale->promo_discount)?></span></div>
            <?php endif; ?>
            <?php if ($sale->tax > 0): ?>
            <div class="bsr-meta-row"><span><?=esc_html($tax_lbl)?></span><span><?=bs_fmt($sale->tax)?></span></div>
            <?php endif; ?>
            <div class="bsr-meta-row bsr-grand"><span>TOTAL</span><span><?=bs_fmt($sale->total)?></span></div>
        </div>

        <?php
        // Payment breakdown — credit, loyalty redeemed, gift card, split
        $has_breakdown = ($sale->credit_used > 0) || ($sale->loyalty_redeemed > 0)
            || ($sale->payment_method === 'gift_card') || ($sale->payment_method === 'split');
        if ($has_breakdown): ?>
        <hr class="bsr-divider">
        <div class="bsr-totals" style="font-size:9pt">
            <?php if ($sale->credit_used > 0): ?>
            <div class="bsr-meta-row"><span>Store Credit</span><span>-<?=bs_fmt($sale->credit_used)?></span></div>
            <?php endif; ?>
            <?php if ($sale->loyalty_redeemed > 0):
                $loy_val = floatval(get_option('bookshop_loyalty_value', 10));
                $loy_redeem_val = $sale->loyalty_redeemed * $loy_val;
            ?>
            <div class="bsr-meta-row"><span>Points Redeemed</span><span>-<?=bs_fmt($loy_redeem_val)?> (<?=intval($sale->loyalty_redeemed)?> pts)</span></div>
            <?php endif; ?>
            <?php if ($sale->payment_method === 'gift_card'):
                $pay_det = json_decode($sale->payment_details, true);
                $gc_code = $pay_det['gc_code'] ?? '';
            ?>
            <div class="bsr-meta-row"><span>Gift Card</span><span><?=esc_html($gc_code ? '••••'.substr($gc_code, -4) : 'Gift Card')?></span></div>
            <?php endif; ?>
            <?php if ($sale->payment_method === 'split'):
                $pay_det = json_decode($sale->payment_details, true);
                $split_cash = floatval($pay_det['cash'] ?? 0);
                $split_card = floatval($pay_det['card'] ?? 0);
            ?>
            <div class="bsr-meta-row"><span>Cash</span><span><?=bs_fmt($split_cash)?></span></div>
            <div class="bsr-meta-row"><span>Card</span><span><?=bs_fmt($split_card)?></span></div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($sale->loyalty_earned > 0): ?>
        <hr class="bsr-divider">
        <div style="text-align:center;font-size:9pt">+<?=intval($sale->loyalty_earned)?> loyalty points earned</div>
        <?php endif; ?>

        <hr class="bsr-divider">
        <div class="bsr-foot"><?=esc_html($footer)?></div>
        <div style="text-align:center;font-size:8pt;color:#666;margin-top:4pt">Powered by Bookshop Manager Pro</div>

        <div class="bsr-cut">- - - - - - - - - - - - - - -</div>
    </div>

    <?php if ($auto_print): ?>
    <script>
    // Auto-print on load, but wait long enough for the linked stylesheet
    // to apply — Safari occasionally fires window.print() before CSS lays
    // out and you get unstyled output. The hard fallback timeout means
    // even a slow CSS load still triggers eventually.
    window.addEventListener('load', function () {
        setTimeout(function () { try { window.print(); } catch (e) {} }, 400);
    });
    </script>
    <?php endif; ?>
</body>
</html>
    <?php
}
