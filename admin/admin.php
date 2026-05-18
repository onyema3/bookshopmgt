<?php
if(!defined('ABSPATH'))exit;

add_action('admin_menu',function(){
    // Use 'bookshop_manager' capability so both administrators (granted on activation)
    // and the Bookshop Manager role can access these pages. Settings remains admin-only.
    $cap_mgr = 'bookshop_manager';
    $cap_admin = 'manage_options';
    add_menu_page('Bookshop','Bookshop',$cap_mgr,'bookshop','bs_page_books','dashicons-book-alt',25);
    add_submenu_page('bookshop','Books','📚 Books',$cap_mgr,'bookshop','bs_page_books');
    add_submenu_page('bookshop','Sales','💳 Sales',$cap_mgr,'bookshop-sales','bs_page_sales');
    add_submenu_page('bookshop','Customers','👥 Customers',$cap_mgr,'bookshop-customers','bs_page_customers');
    add_submenu_page('bookshop','Suppliers','🚚 Suppliers',$cap_mgr,'bookshop-suppliers','bs_page_suppliers');
    add_submenu_page('bookshop','Promotions','🏷️ Promotions',$cap_mgr,'bookshop-promotions','bs_page_promotions');
    add_submenu_page('bookshop','Branches','🏪 Branches',$cap_mgr,'bookshop-branches','bs_page_branches');
    add_submenu_page('bookshop','Messaging','📣 Messaging',$cap_mgr,'bookshop-messaging','bs_page_messaging');
    add_submenu_page('bookshop','Online Orders','🛒 Online & API',$cap_mgr,'bookshop-online','bs_page_online_orders');
    add_submenu_page('bookshop','Reports','📊 Reports',$cap_mgr,'bookshop-reports','bs_page_reports');
    add_submenu_page('bookshop','Staff','👤 Staff',$cap_mgr,'bookshop-staff','bs_page_staff');
    // Settings is sensitive (API keys, payment secrets) — keep admin-only
    add_submenu_page('bookshop','Settings','⚙️ Settings',$cap_admin,'bookshop-settings','bs_page_settings');
    add_submenu_page('bookshop','Open POS','🖥️ Open POS','bookshop_pos','bookshop-pos-link','bs_pos_redirect');
});

function bs_pos_redirect(){
    // Redirect happens via admin_init to avoid "headers already sent"
    // The menu callback just outputs a meta refresh as fallback
    echo '<script>window.location.href="'.esc_url(home_url('/?bookshop_pos=1')).'";</script>';
    echo '<noscript><meta http-equiv="refresh" content="0;url='.esc_url(home_url('/?bookshop_pos=1')).'"></noscript>';
    echo '<p>Opening POS... <a href="'.esc_url(home_url('/?bookshop_pos=1')).'">Click here if not redirected</a></p>';
}

// Redirect to POS before any output when clicking the menu link
add_action('admin_init', function(){
    if(!empty($_GET['page']) && $_GET['page']==='bookshop-pos-link' && !headers_sent()){
        wp_safe_redirect(home_url('/?bookshop_pos=1'));
        exit;
    }
});

add_action('admin_enqueue_scripts',function($hook){
    if(strpos($hook,'bookshop')===false) return;
    wp_enqueue_style('bs-admin',BOOKSHOP_URL.'assets/css/admin.css',[],BOOKSHOP_VERSION);
    wp_enqueue_style('bs-admin-fonts','https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;700&family=DM+Sans:wght@300;400;500;600&display=swap',[]);
    wp_enqueue_script('chart-js','https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js',[],null,true);
    wp_enqueue_script('bs-admin',BOOKSHOP_URL.'assets/js/admin.js',['jquery','chart-js'],BOOKSHOP_VERSION,true);
    wp_localize_script('bs-admin','BSAdmin',[
        'ajax_url'   =>admin_url('admin-ajax.php'),
        'nonce'      =>wp_create_nonce('bs_admin_nonce'),
        'currency'   =>bs_currency(),
        'export_url' =>admin_url('admin-ajax.php?action=bs_export_sales_csv'),
        'rest_url'   =>esc_url(home_url('/wp-json/bookshop/v1/')),
        'api_key'    =>get_option('bookshop_api_key',''),
        // Used by the JS to decide whether to expose admin-only actions like
        // the reconcile button on the per-book breakdown modal. The actual
        // authorization check happens server-side; this is just so we don't
        // render a button the user couldn't use anyway.
        'is_admin'   =>current_user_can('manage_options'),
    ]);
    // Media uploader for logo picker
    if(function_exists('wp_enqueue_media')) wp_enqueue_media();
});

// Shared modal helper
function bs_modal($id,$title,$body,$footer='',$size='md'){
    $w=$size==='lg'?'860px':'640px';
    echo "<div id='$id' class='bs-modal' style='display:none'><div class='bs-modal-box' style='max-width:$w'>
    <div class='bs-modal-header'><h2>$title</h2><button class='bs-modal-close'>✕</button></div>
    <div class='bs-modal-body'>$body</div>";
    if($footer) echo "<div class='bs-modal-footer'>$footer</div>";
    echo "</div></div>";
}

function bs_stat($val,$lbl,$accent=false){
    $cls=$accent?'bs-stat bs-stat-accent':'bs-stat';
    echo "<div class='$cls'><span class='bs-stat-val'>$val</span><span class='bs-stat-lbl'>$lbl</span></div>";
}
