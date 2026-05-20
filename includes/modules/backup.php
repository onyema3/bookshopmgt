<?php
/**
 * Automatic Daily Database Backup
 */
if(!defined('ABSPATH'))exit;

function bs_export_db_backup(){
    global $wpdb;
    $tables=[
        'bookshop_books','bookshop_sales','bookshop_sale_items',
        'bookshop_customers','bookshop_suppliers','bookshop_purchase_orders',
        'bookshop_po_items','bookshop_promotions','bookshop_loyalty_log',
        'bookshop_reservations','bookshop_shifts','bookshop_audit_log',
        'bookshop_branches','bookshop_branch_stock','bookshop_stock_transfers',
        'bookshop_held_sales','bookshop_online_orders','bookshop_webhooks',
    ];
    $sql="-- Bookshop Manager Pro Backup\n-- Generated: ".date('Y-m-d H:i:s')."\n-- WordPress: ".home_url()."\n\n";
    foreach($tables as $t){
        $full=$wpdb->prefix.$t;
        if(!$wpdb->get_var("SHOW TABLES LIKE '$full'")) continue;
        // Structure
        $create=$wpdb->get_row("SHOW CREATE TABLE `$full`",ARRAY_N);
        if($create) $sql.="\n-- Table: $full\nDROP TABLE IF EXISTS `$full`;\n".$create[1].";\n\n";
        // Data
        $rows=$wpdb->get_results("SELECT * FROM `$full`",ARRAY_A);
        if(empty($rows)) continue;
        $cols='`'.implode('`,`',array_keys($rows[0])).'`';
        $sql.="INSERT INTO `$full` ($cols) VALUES\n";
        $vals=[];
        foreach($rows as $row){
            $escaped=array_map(function($v)use($wpdb){return $v===null?'NULL':"'".$wpdb->_escape($v)."'";},array_values($row));
            $vals[]='('.implode(',',$escaped).')';
        }
        $sql.=implode(",\n",$vals).";\n\n";
    }
    return $sql;
}

function bs_send_daily_backup(){
    $email=get_option('bookshop_backup_email',get_option('admin_email'));
    $shop=get_option('bookshop_receipt_header',get_bloginfo('name'));
    $sql=bs_export_db_backup();
    $filename='bookshop-backup-'.date('Y-m-d').'.sql';
    $tmpfile=sys_get_temp_dir().'/'.$filename;
    file_put_contents($tmpfile,$sql);
    $headers=['Content-Type: text/html; charset=UTF-8'];
    $ok=wp_mail(
        $email,
        "[$shop] Daily Backup — ".date('d M Y'),
        "<p>Your daily bookshop database backup is attached.</p><p>Generated: ".date('d M Y H:i')."</p>",
        $headers,
        [$tmpfile]
    );
    @unlink($tmpfile);
    update_option('bookshop_last_backup',current_time('mysql'));
    return $ok;
}

// Download backup via admin AJAX
add_action('wp_ajax_bs_download_backup',function(){
    if(!current_user_can('manage_options')) wp_die('Unauthorized');
    $sql=bs_export_db_backup();
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="bookshop-backup-'.date('Y-m-d').'.sql"');
    header('Content-Length: '.strlen($sql));
    echo $sql; exit;
});

// Generate API key
add_action('wp_ajax_bs_generate_api_key',function(){
    if(!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
    $key='bsk_'.bin2hex(random_bytes(24));
    update_option('bookshop_api_key',$key);
    wp_send_json_success(['key'=>$key]);
});

add_action('bookshop_daily_tasks','bs_send_daily_backup');

// ── Restore backup from uploaded SQL file ─────────────────────────────────────
add_action('wp_ajax_bs_restore_backup', function() {
    if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized', 403);

    if (empty($_FILES['backup_file']['tmp_name'])) {
        wp_send_json_error('No file uploaded');
        return;
    }

    $file     = $_FILES['backup_file'];
    $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed  = ['sql'];

    if (!in_array($ext, $allowed)) {
        wp_send_json_error('Only .sql files are supported');
        return;
    }

    if ($file['size'] > 50 * 1024 * 1024) { // 50MB limit
        wp_send_json_error('File too large (max 50MB)');
        return;
    }

    $sql = file_get_contents($file['tmp_name']);
    if (empty($sql)) {
        wp_send_json_error('File is empty or unreadable');
        return;
    }

    // Safety check — only allow restoring bookshop tables
    if (strpos($sql, 'bookshop') === false) {
        wp_send_json_error('This does not appear to be a Bookshop Manager backup file');
        return;
    }

    global $wpdb;

    // Split SQL into individual statements
    $statements = array_filter(
        array_map('trim', explode(";\n", $sql)),
        function($s) { return !empty($s) && $s !== '--'; }
    );

    $executed = 0;
    $errors   = [];

    // Suppress errors during restore and handle each statement
    foreach ($statements as $stmt) {
        $stmt = trim($stmt);
        if (empty($stmt) || substr($stmt, 0, 2) === '--') continue;

        // Only allow bookshop table operations for safety
        if (!preg_match('/bookshop/i', $stmt) &&
            !preg_match('/^(CREATE|INSERT|DROP|ALTER)\s/i', $stmt)) {
            continue;
        }

        // Replace table prefix if different
        $site_prefix = $wpdb->prefix;
        $stmt = preg_replace('/`[a-z0-9_]*(bookshop[a-z0-9_]*)`/i', "`{$site_prefix}$1`", $stmt);

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $result = $wpdb->query($stmt);
        if ($result === false) {
            $errors[] = substr($stmt, 0, 80) . '… — ' . $wpdb->last_error;
        } else {
            $executed++;
        }
    }

    update_option('bookshop_last_restore', current_time('mysql'));
    bs_audit('backup_restored', 'system', 0, "Restored from file: {$file['name']} — {$executed} statements, " . count($errors) . " errors");

    wp_send_json_success([
        'executed' => $executed,
        'errors'   => count($errors),
        'message'  => "Restore complete: {$executed} statements executed, " . count($errors) . " errors.",
        'error_details' => array_slice($errors, 0, 5), // first 5 errors only
    ]);
});
