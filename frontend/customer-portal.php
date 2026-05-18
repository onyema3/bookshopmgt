<?php
/**
 * Customer Portal — Frontend shortcode [bookshop_portal]
 * Customers log in with phone/email, view history, points, reservations
 */
if(!defined('ABSPATH'))exit;

add_shortcode('bookshop_portal','bs_portal_shortcode');

function bs_portal_shortcode($atts){
    $a=shortcode_atts(['title'=>'My Account'],$atts);
    wp_enqueue_style('bs-portal',BOOKSHOP_URL.'assets/css/portal.css',[],BOOKSHOP_VERSION);
    wp_enqueue_script('bs-portal',BOOKSHOP_URL.'assets/js/portal.js',['jquery'],BOOKSHOP_VERSION,true);
    wp_localize_script('bs-portal','BSPortal',[
        'ajax_url' =>admin_url('admin-ajax.php'),
        'currency' =>bs_currency(),
        'nonce'    =>wp_create_nonce('bs_portal_nonce'),
    ]);

    // Check if customer already has a session
    $cid=intval(get_transient('bs_portal_customer_'.session_id()));

    ob_start();
    echo '<div class="bs-portal-wrap" id="bs-portal">';

    if($cid && ($customer=bs_get_customer($cid))){
        bs_render_portal_dashboard($customer);
    } else {
        bs_render_portal_login($a['title']);
    }

    echo '</div>';
    return ob_get_clean();
}

// ── Start session at plugins_loaded before ANY output ─────────────────────────
add_action('plugins_loaded',function(){
    if(!is_admin() && !wp_doing_ajax() && !session_id() && !headers_sent()){
        @session_start();
    }
},1);

// ── Login screen ──────────────────────────────────────────────────────────────
function bs_render_portal_login($title){
    ?>
    <div class="bsp-login-wrap" id="bsp-login">
        <div class="bsp-login-card">
            <div class="bsp-login-icon">📚</div>
            <h2 class="bsp-login-title"><?=esc_html($title)?></h2>
            <p class="bsp-login-sub">Enter your phone number or email to access your account</p>
            <div class="bsp-field">
                <label>Phone or Email</label>
                <input type="text" id="bsp-identifier" placeholder="e.g. 08012345678 or you@email.com" autocomplete="tel">
            </div>
            <button class="bsp-btn bsp-btn-primary" id="bsp-login-btn">Access My Account</button>
            <div id="bsp-login-msg" class="bsp-msg" style="display:none"></div>
            <p class="bsp-login-note">Don't have an account? Ask staff to register you in-store.</p>
        </div>
    </div>
    <?php
}

// ── Dashboard ─────────────────────────────────────────────────────────────────
function bs_render_portal_dashboard($customer){
    global $wpdb;
    $tier     = bs_get_customer_tier($customer->id);
    $loy_val  = floatval(get_option('bookshop_loyalty_value',10));
    $sales    = bs_get_sales(['customer_id'=>$customer->id,'limit'=>50]);
    $reserv   = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}bookshop_reservations WHERE customer_email=%s OR customer_phone=%s ORDER BY created_at DESC LIMIT 20",
        $customer->email, $customer->phone));
    $loy_log  = bs_get_loyalty_log($customer->id);
    $total_spent = floatval($wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(SUM(total),0) FROM {$wpdb->prefix}bookshop_sales WHERE customer_id=%d AND status='completed'",$customer->id)));
    // Next tier
    $tiers = bs_get_tiers();
    $tier_keys = array_keys($tiers);
    $current_idx = array_search($tier['key'],$tier_keys);
    $next_tier = isset($tier_keys[$current_idx+1]) ? $tiers[$tier_keys[$current_idx+1]] : null;
    $progress = 0;
    if($next_tier){
        $range = $next_tier['min_spend'] - $tier['min_spend'];
        $done  = $total_spent - $tier['min_spend'];
        $progress = $range > 0 ? min(100,round(($done/$range)*100)) : 100;
    }
    ?>
    <div class="bsp-dash">
        <!-- Header -->
        <div class="bsp-header">
            <div>
                <div class="bsp-greeting">Welcome back,</div>
                <div class="bsp-name"><?=esc_html($customer->name)?></div>
            </div>
            <button class="bsp-logout" id="bsp-logout">Sign Out</button>
        </div>

        <!-- Tier + Stats -->
        <div class="bsp-hero" style="background:linear-gradient(135deg,<?=esc_attr($tier['color'])?>22,<?=esc_attr($tier['color'])?>11);border:2px solid <?=esc_attr($tier['color'])?>44">
            <div class="bsp-tier-badge" style="background:<?=esc_attr($tier['color'])?>">
                <?=esc_html($tier['icon'])?> <?=esc_html($tier['label'])?>
            </div>
            <div class="bsp-stats-row">
                <div class="bsp-stat">
                    <span class="bsp-stat-val"><?=intval($customer->loyalty_points)?></span>
                    <span class="bsp-stat-lbl">Loyalty Points</span>
                </div>
                <div class="bsp-stat">
                    <span class="bsp-stat-val"><?=bs_fmt($customer->loyalty_points * $loy_val)?></span>
                    <span class="bsp-stat-lbl">Points Value</span>
                </div>
                <div class="bsp-stat">
                    <span class="bsp-stat-val"><?=bs_fmt($total_spent)?></span>
                    <span class="bsp-stat-lbl">Total Spent</span>
                </div>
                <div class="bsp-stat">
                    <span class="bsp-stat-val"><?=count($sales)?></span>
                    <span class="bsp-stat-lbl">Purchases</span>
                </div>
            </div>
            <?php if($next_tier): ?>
            <div class="bsp-tier-progress">
                <div class="bsp-tier-progress-label">
                    <span><?=esc_html($tier['icon'].' '.$tier['label'])?></span>
                    <span><?=esc_html($next_tier['icon'].' '.$next_tier['label'])?> — <?=bs_fmt($next_tier['min_spend'])?></span>
                </div>
                <div class="bsp-progress-bar">
                    <div class="bsp-progress-fill" style="width:<?=$progress?>%;background:<?=esc_attr($tier['color'])?>"></div>
                </div>
                <div class="bsp-tier-progress-note">
                    Spend <?=bs_fmt(max(0,$next_tier['min_spend']-$total_spent))?> more to reach <?=esc_html($next_tier['label'])?> (<?=intval($next_tier['discount'])?>% discount)
                </div>
            </div>
            <?php else: ?>
            <div class="bsp-tier-progress-note" style="text-align:center;margin-top:10px">
                🏆 You've reached our highest tier — enjoy your <?=intval($tier['discount'])?>% member discount!
            </div>
            <?php endif; ?>
        </div>

        <!-- Tabs -->
        <div class="bsp-tabs">
            <button class="bsp-tab active" data-tab="bsp-purchases">Purchases</button>
            <button class="bsp-tab" data-tab="bsp-points">Points History</button>
            <button class="bsp-tab" data-tab="bsp-reservations">Reservations</button>
            <button class="bsp-tab" data-tab="bsp-profile">My Profile</button>
        </div>

        <!-- Purchases -->
        <div id="bsp-purchases" class="bsp-tab-content">
            <?php if(empty($sales)): ?>
            <div class="bsp-empty">📚 No purchases yet. Visit us in-store!</div>
            <?php else: ?>
            <div class="bsp-purchase-list">
            <?php foreach($sales as $s):
                $items = bs_get_sale_items($s->id);
            ?>
            <div class="bsp-purchase-card">
                <div class="bsp-purchase-header">
                    <div>
                        <span class="bsp-purchase-ref"><?=esc_html($s->sale_ref)?></span>
                        <span class="bsp-purchase-date"><?=esc_html(wp_date('d M Y',strtotime($s->created_at)))?></span>
                    </div>
                    <div class="bsp-purchase-total"><?=bs_fmt($s->total)?></div>
                </div>
                <div class="bsp-purchase-items">
                <?php foreach($items as $item): ?>
                    <div class="bsp-purchase-item">
                        <span class="bsp-item-title"><?=esc_html($item->title)?></span>
                        <span class="bsp-item-meta"><?=esc_html($item->author)?> &mdash; ×<?=intval($item->qty)?></span>
                        <span class="bsp-item-price"><?=bs_fmt($item->line_total)?></span>
                    </div>
                <?php endforeach; ?>
                </div>
                <?php if($s->loyalty_earned>0): ?>
                <div class="bsp-purchase-loyalty">⭐ +<?=intval($s->loyalty_earned)?> points earned</div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Points History -->
        <div id="bsp-points" class="bsp-tab-content" style="display:none">
            <div class="bsp-points-summary">
                <div class="bsp-points-big"><?=intval($customer->loyalty_points)?> pts</div>
                <div class="bsp-points-value">Worth <?=bs_fmt($customer->loyalty_points * $loy_val)?></div>
                <p style="font-size:.82rem;color:var(--bs-muted);margin-top:6px">Points can be redeemed in-store at checkout.</p>
            </div>
            <div class="bsp-points-log">
            <?php foreach($loy_log as $entry): ?>
            <?php $pts_cls = ($entry->points >= 0) ? 'bsp-points-earn' : 'bsp-points-spend'; ?>
            <div class="bsp-points-row <?=$pts_cls?>">
                <div>
                    <div class="bsp-points-row-label"><?=esc_html($entry->note)?></div>
                    <div class="bsp-points-row-date"><?=esc_html(wp_date('d M Y',strtotime($entry->created_at)))?></div>
                </div>
                <?php $pts_sign = ($entry->points >= 0) ? '+' : ''; ?>
                <div class="bsp-points-row-val"><?=$pts_sign?><?=intval($entry->points)?> pts</div>
            </div>
            <?php endforeach;
            if(empty($loy_log)) echo '<div class="bsp-empty">No points history yet.</div>';
            ?>
            </div>
        </div>

        <!-- Reservations -->
        <div id="bsp-reservations" class="bsp-tab-content" style="display:none">
            <?php if(empty($reserv)): ?>
            <div class="bsp-empty">No reservations yet.</div>
            <?php else: ?>
            <div class="bsp-reserv-list">
            <?php foreach($reserv as $r): ?>
            <div class="bsp-reserv-card">
                <div class="bsp-reserv-header">
                    <div>
                        <div class="bsp-reserv-title"><?=esc_html($r->book_title)?></div>
                        <?php if($r->isbn): ?><div class="bsp-reserv-isbn">ISBN: <?=esc_html($r->isbn)?></div><?php endif; ?>
                    </div>
                    <span class="bsp-badge bsp-badge-<?=esc_attr($r->status)?>"><?=esc_html(ucfirst($r->status))?></span>
                </div>
                <div class="bsp-reserv-meta">
                    Qty: <?=intval($r->qty)?> &mdash; Requested: <?=esc_html(wp_date('d M Y',strtotime($r->created_at)))?>
                    <?php if($r->status==='notified'): ?>
                    <span class="bsp-reserv-ready">OK Your book is ready for collection!</span>
                    <?php endif; ?>
                </div>
                <?php if(!empty($r->notes)): ?><div class="bsp-reserv-notes"><?=esc_html($r->notes)?></div><?php endif; ?>
            </div>
            <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <!-- Reserve another -->
            <div class="bsp-card" style="margin-top:20px">
                <h4 style="font-family:'Playfair Display',serif;margin-bottom:12px">Reserve a Book</h4>
                <div class="bsp-field"><label>Book Title *</label><input type="text" id="bsp-res-title" class="bsp-input" placeholder="Enter book title..."></div>
                <div class="bsp-field"><label>ISBN (if known)</label><input type="text" id="bsp-res-isbn" class="bsp-input"></div>
                <div class="bsp-field"><label>Quantity</label><input type="number" id="bsp-res-qty" class="bsp-input" value="1" min="1"></div>
                <div class="bsp-field"><label>Notes</label><textarea id="bsp-res-notes" class="bsp-input" rows="2"></textarea></div>
                <button class="bsp-btn bsp-btn-primary" id="bsp-submit-reservation">Submit Reservation</button>
                <div id="bsp-res-msg" class="bsp-msg" style="display:none"></div>
            </div>
        </div>

        <!-- Profile -->
        <div id="bsp-profile" class="bsp-tab-content" style="display:none">
            <div class="bsp-card">
                <h4 style="font-family:'Playfair Display',serif;margin-bottom:14px">My Information</h4>
                <div class="bsp-profile-grid">
                    <div class="bsp-field bsp-span2"><label>Full Name</label><input type="text" id="bsp-p-name" class="bsp-input" value="<?=esc_attr($customer->name)?>"></div>
                    <div class="bsp-field"><label>Phone</label><input type="tel" id="bsp-p-phone" class="bsp-input" value="<?=esc_attr($customer->phone)?>"></div>
                    <div class="bsp-field"><label>Email</label><input type="email" id="bsp-p-email" class="bsp-input" value="<?=esc_attr($customer->email)?>"></div>
                    <div class="bsp-field bsp-span2"><label>Birthday</label><input type="date" id="bsp-p-birthday" class="bsp-input" value="<?=esc_attr($customer->birthday)?>"></div>
                    <div class="bsp-field bsp-span2"><label>Address</label><textarea id="bsp-p-address" class="bsp-input" rows="2"><?=esc_textarea($customer->address)?></textarea></div>
                </div>
                <button class="bsp-btn bsp-btn-primary" id="bsp-save-profile">Save Changes</button>
                <div id="bsp-profile-msg" class="bsp-msg" style="display:none"></div>
            </div>
            <!-- Delete / Privacy -->
            <div class="bsp-card" style="margin-top:16px;border-color:#fecaca">
                <h4 style="font-family:'Playfair Display',serif;margin-bottom:8px;color:#c0392b">Privacy</h4>
                <p style="font-size:.82rem;color:#666;margin-bottom:10px">Your data is stored securely and used only to manage your purchases and loyalty points. We do not sell your information.</p>
                <input type="hidden" id="bsp-customer-id" value="<?=intval($customer->id)?>">
            </div>
        </div>
    </div>
    <?php
}
