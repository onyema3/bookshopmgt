<?php
/**
 * Progressive Web App — manifest, service worker, install prompt
 */
if(!defined('ABSPATH'))exit;

// ── Manifest ──────────────────────────────────────────────────────────────────
add_action('wp_head',function(){
    if(empty($_GET['bookshop_pos'])) return;
    $shop=get_option('bookshop_receipt_header',get_bloginfo('name'));
    $logo=get_option('bookshop_logo_url','');
    echo '<link rel="manifest" href="'.home_url('/?bookshop_manifest=1').'">';
    echo '<meta name="theme-color" content="#1a1208">';
    echo '<meta name="apple-mobile-web-app-capable" content="yes">';
    echo '<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">';
    echo '<meta name="apple-mobile-web-app-title" content="'.esc_attr($shop).' POS">';
    if($logo) echo '<link rel="apple-touch-icon" href="'.esc_url($logo).'">';
});

// ── Manifest JSON endpoint ────────────────────────────────────────────────────
add_action('init',function(){
    if(empty($_GET['bookshop_manifest'])) return;
    $shop=get_option('bookshop_receipt_header',get_bloginfo('name'));
    $logo=get_option('bookshop_logo_url','');
    $icons=[];
    if($logo){
        $icons=[
            ['src'=>$logo,'sizes'=>'192x192','type'=>'image/png','purpose'=>'any maskable'],
            ['src'=>$logo,'sizes'=>'512x512','type'=>'image/png','purpose'=>'any maskable'],
        ];
    }
    $manifest=[
        'name'            =>$shop.' POS',
        'short_name'      =>$shop,
        'description'     =>'Point of Sale for '.$shop,
        'start_url'       =>home_url('/?bookshop_pos=1'),
        'display'         =>'standalone',
        'background_color'=>'#fdf8f0',
        'theme_color'     =>'#1a1208',
        'orientation'     =>'portrait-primary',
        'icons'           =>$icons,
        'categories'      =>['business','productivity'],
        'shortcuts'       =>[
            ['name'=>'Open POS','url'=>home_url('/?bookshop_pos=1'),'description'=>'Launch the POS terminal'],
        ],
    ];
    header('Content-Type: application/manifest+json');
    echo json_encode($manifest,JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
    exit;
},5);

// ── Service Worker endpoint ───────────────────────────────────────────────────
add_action('init',function(){
    if(empty($_GET['bookshop_sw'])) return;
    header('Content-Type: application/javascript');
    $pos_url=home_url('/?bookshop_pos=1');
    $ajax_url=admin_url('admin-ajax.php');
    echo "
const CACHE='bookshop-pos-v1';
const OFFLINE_URLS=[
    '$pos_url',
    '".BOOKSHOP_URL."assets/css/admin.css',
];

self.addEventListener('install',e=>{
    e.waitUntil(
        caches.open(CACHE).then(cache=>cache.addAll(OFFLINE_URLS)).then(()=>self.skipWaiting())
    );
});
self.addEventListener('activate',e=>{
    e.waitUntil(caches.keys().then(keys=>Promise.all(keys.filter(k=>k!==CACHE).map(k=>caches.delete(k)))).then(()=>self.clients.claim()));
});
self.addEventListener('fetch',e=>{
    const url=new URL(e.request.url);
    // Always go network for AJAX (sales/search) — only cache static POS page
    if(url.pathname.includes('admin-ajax.php')){
        e.respondWith(fetch(e.request).catch(()=>new Response(JSON.stringify({success:false,data:'Offline — please reconnect'}),{headers:{'Content-Type':'application/json'}})));
        return;
    }
    e.respondWith(
        caches.match(e.request).then(cached=>{
            if(cached) return cached;
            return fetch(e.request).then(resp=>{
                if(resp.ok){
                    const clone=resp.clone();
                    caches.open(CACHE).then(c=>c.put(e.request,clone));
                }
                return resp;
            }).catch(()=>caches.match('$pos_url'));
        })
    );
});
";
    exit;
},5);

// ── Register service worker on POS page ───────────────────────────────────────
add_action('wp_footer',function(){
    if(empty($_GET['bookshop_pos'])) return;
    $sw_url=home_url('/?bookshop_sw=1');
    echo "<script>
if('serviceWorker' in navigator){
    navigator.serviceWorker.register('$sw_url',{scope:'/'})
        .then(r=>console.log('Bookshop SW registered'))
        .catch(e=>console.warn('SW registration failed:',e));
}
// PWA install prompt
let deferredPrompt;
window.addEventListener('beforeinstallprompt',e=>{
    e.preventDefault(); deferredPrompt=e;
    const btn=document.createElement('button');
    btn.textContent='📱 Install POS App';
    btn.style.cssText='position:fixed;bottom:80px;left:16px;background:#c8860a;color:#fff;border:none;padding:10px 16px;border-radius:8px;font-weight:700;cursor:pointer;z-index:9999;font-size:.85rem;box-shadow:0 2px 12px rgba(0,0,0,.3)';
    btn.onclick=()=>{deferredPrompt.prompt();deferredPrompt.userChoice.then(()=>btn.remove());};
    document.body.appendChild(btn);
});
</script>";
});
