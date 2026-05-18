<?php
/**
 * IP Whitelist for POS access
 */
if(!defined('ABSPATH'))exit;

function bs_get_client_ip(){
    $keys=['HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','REMOTE_ADDR'];
    foreach($keys as $k){
        if(!empty($_SERVER[$k])){
            $ip=trim(explode(',',$_SERVER[$k])[0]);
            if(filter_var($ip,FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return '0.0.0.0';
}

function bs_ip_allowed(){
    $whitelist=get_option('bookshop_ip_whitelist','');
    if(empty(trim($whitelist))) return true; // empty = allow all
    $ips=array_filter(array_map('trim',explode("\n",$whitelist)));
    $client=bs_get_client_ip();
    foreach($ips as $ip){
        // Support CIDR ranges
        if(strpos($ip,'/')!==false){
            list($range,$bits)=explode('/',$ip);
            $mask=-1<<(32-intval($bits));
            if((ip2long($client)&$mask)===(ip2long($range)&$mask)) return true;
        } elseif($ip===$client) return true;
    }
    return false;
}

// Hook into POS template_redirect to enforce whitelist
add_action('template_redirect',function(){
    if(empty($_GET['bookshop_pos'])) return;
    if(!bs_ip_allowed()){
        $ip=bs_get_client_ip();
        wp_die("POS access denied for IP: $ip. Contact your administrator to whitelist this IP.",'Access Denied',['response'=>403]);
    }
},5); // priority 5 — runs before the POS auth check at priority 10
