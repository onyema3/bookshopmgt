<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function bookshop_install() {
    global $wpdb;
    $c = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    // ── Books ─────────────────────────────────────────────────────────────────
    dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}bookshop_books (
        id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        isbn          VARCHAR(20)  DEFAULT '',
        title         VARCHAR(255) NOT NULL,
        author        VARCHAR(255) DEFAULT '',
        genre         VARCHAR(100) DEFAULT '',
        publisher     VARCHAR(255) DEFAULT '',
        publish_year  YEAR         DEFAULT NULL,
        description   TEXT         DEFAULT NULL,
        cost_price    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        sell_price    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        stock_qty     INT          NOT NULL DEFAULT 0,
        low_stock_threshold INT    NOT NULL DEFAULT 5,
        cover_url     VARCHAR(500) DEFAULT '',
        barcode       VARCHAR(100) DEFAULT '',
        location      VARCHAR(100) DEFAULT '',
        status        ENUM('active','inactive') NOT NULL DEFAULT 'active',
        created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY isbn (isbn), KEY title (title(100)), KEY barcode (barcode)
    ) $c;");

    // ── Suppliers ─────────────────────────────────────────────────────────────
    dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}bookshop_suppliers (
        id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        name          VARCHAR(255) NOT NULL,
        contact_name  VARCHAR(255) DEFAULT '',
        email         VARCHAR(255) DEFAULT '',
        phone         VARCHAR(50)  DEFAULT '',
        address       TEXT         DEFAULT NULL,
        notes         TEXT         DEFAULT NULL,
        status        ENUM('active','inactive') NOT NULL DEFAULT 'active',
        created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) $c;");

    // ── Purchase Orders ───────────────────────────────────────────────────────
    dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}bookshop_purchase_orders (
        id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        po_ref        VARCHAR(30)  NOT NULL,
        supplier_id   BIGINT UNSIGNED DEFAULT NULL,
        staff_id      BIGINT UNSIGNED NOT NULL,
        total         DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        status        ENUM('draft','ordered','received','cancelled') NOT NULL DEFAULT 'draft',
        notes         TEXT         DEFAULT NULL,
        ordered_at    DATETIME     DEFAULT NULL,
        received_at   DATETIME     DEFAULT NULL,
        created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id), KEY po_ref (po_ref)
    ) $c;");

    dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}bookshop_po_items (
        id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        po_id         BIGINT UNSIGNED NOT NULL,
        book_id       BIGINT UNSIGNED NOT NULL,
        qty_ordered   INT          NOT NULL DEFAULT 0,
        qty_received  INT          NOT NULL DEFAULT 0,
        unit_cost     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        PRIMARY KEY (id), KEY po_id (po_id)
    ) $c;");

    // ── Customers ─────────────────────────────────────────────────────────────
    dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}bookshop_customers (
        id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        name          VARCHAR(255) NOT NULL,
        email         VARCHAR(255) DEFAULT '',
        phone         VARCHAR(50)  DEFAULT '',
        address       TEXT         DEFAULT NULL,
        birthday      DATE         DEFAULT NULL,
        loyalty_points INT         NOT NULL DEFAULT 0,
        credit_balance DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        notes         TEXT         DEFAULT NULL,
        status        ENUM('active','inactive') NOT NULL DEFAULT 'active',
        created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY email (email), KEY phone (phone)
    ) $c;");

    // ── Sales ─────────────────────────────────────────────────────────────────
    dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}bookshop_sales (
        id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        sale_ref      VARCHAR(30)  NOT NULL,
        staff_id      BIGINT UNSIGNED NOT NULL,
        customer_id   BIGINT UNSIGNED DEFAULT NULL,
        shift_id      BIGINT UNSIGNED DEFAULT NULL,
        subtotal      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        discount      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        promo_discount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        credit_used   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        tax           DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        total         DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        payment_method ENUM('cash','card','transfer','split','credit','other') NOT NULL DEFAULT 'cash',
        payment_details JSON        DEFAULT NULL,
        promo_code    VARCHAR(50)  DEFAULT NULL,
        loyalty_earned INT         NOT NULL DEFAULT 0,
        loyalty_redeemed INT       NOT NULL DEFAULT 0,
        note          TEXT         DEFAULT NULL,
        status        ENUM('completed','voided') NOT NULL DEFAULT 'completed',
        created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY sale_ref (sale_ref), KEY staff_id (staff_id),
        KEY customer_id (customer_id), KEY created_at (created_at)
    ) $c;");

    dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}bookshop_sale_items (
        id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        sale_id       BIGINT UNSIGNED NOT NULL,
        book_id       BIGINT UNSIGNED NOT NULL,
        qty           INT          NOT NULL DEFAULT 1,
        unit_price    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        line_total    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        PRIMARY KEY (id), KEY sale_id (sale_id), KEY book_id (book_id)
    ) $c;");

    // ── Promotions / Discounts ────────────────────────────────────────────────
    dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}bookshop_promotions (
        id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        name          VARCHAR(255) NOT NULL,
        code          VARCHAR(50)  DEFAULT NULL,
        type          ENUM('percent','fixed','buy_x_get_y','bundle') NOT NULL DEFAULT 'percent',
        value         DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        min_purchase  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        buy_qty       INT          DEFAULT NULL,
        get_qty       INT          DEFAULT NULL,
        usage_limit   INT          DEFAULT NULL,
        used_count    INT          NOT NULL DEFAULT 0,
        requires_manager TINYINT(1) NOT NULL DEFAULT 0,
        start_date    DATE         DEFAULT NULL,
        end_date      DATE         DEFAULT NULL,
        status        ENUM('active','inactive') NOT NULL DEFAULT 'active',
        created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id), KEY code (code)
    ) $c;");

    // ── Loyalty Log ───────────────────────────────────────────────────────────
    dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}bookshop_loyalty_log (
        id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        customer_id   BIGINT UNSIGNED NOT NULL,
        sale_id       BIGINT UNSIGNED DEFAULT NULL,
        points        INT          NOT NULL DEFAULT 0,
        type          ENUM('earned','redeemed','adjusted','expired') NOT NULL DEFAULT 'earned',
        note          VARCHAR(255) DEFAULT '',
        created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id), KEY customer_id (customer_id)
    ) $c;");

    // ── Wishlist / Reservations ───────────────────────────────────────────────
    dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}bookshop_reservations (
        id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        customer_name VARCHAR(255) NOT NULL,
        customer_email VARCHAR(255) DEFAULT '',
        customer_phone VARCHAR(50)  DEFAULT '',
        book_id       BIGINT UNSIGNED DEFAULT NULL,
        book_title    VARCHAR(255) DEFAULT '',
        isbn          VARCHAR(20)  DEFAULT '',
        qty           INT          NOT NULL DEFAULT 1,
        notes         TEXT         DEFAULT NULL,
        status        ENUM('pending','notified','fulfilled','cancelled') NOT NULL DEFAULT 'pending',
        created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) $c;");

    // ── Shifts ────────────────────────────────────────────────────────────────
    dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}bookshop_shifts (
        id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        staff_id      BIGINT UNSIGNED NOT NULL,
        opening_cash  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        closing_cash  DECIMAL(10,2) DEFAULT NULL,
        expected_cash DECIMAL(10,2) DEFAULT NULL,
        variance      DECIMAL(10,2) DEFAULT NULL,
        opened_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        closed_at     DATETIME     DEFAULT NULL,
        notes         TEXT         DEFAULT NULL,
        status        ENUM('open','closed') NOT NULL DEFAULT 'open',
        PRIMARY KEY (id), KEY staff_id (staff_id)
    ) $c;");

    // ── Audit Log ─────────────────────────────────────────────────────────────
    dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}bookshop_audit_log (
        id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        staff_id      BIGINT UNSIGNED NOT NULL,
        action        VARCHAR(100) NOT NULL,
        object_type   VARCHAR(50)  DEFAULT '',
        object_id     BIGINT UNSIGNED DEFAULT NULL,
        details       TEXT         DEFAULT NULL,
        created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id), KEY staff_id (staff_id), KEY created_at (created_at)
    ) $c;");

    // ── Roles ─────────────────────────────────────────────────────────────────
    add_role( 'bookshop_staff', 'Bookshop Staff', [
        'read' => true, 'bookshop_pos' => true,
    ]);
    add_role( 'bookshop_manager', 'Bookshop Manager', [
        'read' => true, 'bookshop_pos' => true, 'bookshop_manager' => true,
    ]);

    // Add caps to admin
    $admin = get_role('administrator');
    if ( $admin ) {
        $admin->add_cap('bookshop_pos');
        $admin->add_cap('bookshop_manager');
    }

    update_option('bookshop_version',                    BOOKSHOP_VERSION);
    update_option('bookshop_currency',                   '₦');
    update_option('bookshop_tax_mode',                   'none');
    update_option('bookshop_tax_rate',                   0);
    update_option('bookshop_tax_label',                  'VAT');
    update_option('bookshop_loyalty_rate',               1);
    update_option('bookshop_loyalty_value',              10);
    update_option('bookshop_receipt_header',             get_bloginfo('name'));
    update_option('bookshop_tagline',                    '');
    update_option('bookshop_address',                    '');
    update_option('bookshop_phone',                      '');
    update_option('bookshop_store_email',                get_option('admin_email'));
    update_option('bookshop_logo_url',                   '');
    update_option('bookshop_receipt_footer',             'Thank you for shopping with us!');
    update_option('bookshop_low_stock_email',            get_option('admin_email'));
    update_option('bookshop_whatsapp',                   '');
    update_option('bookshop_manager_discount_threshold', 20);
}

// ── Auto-repair: ensure tables exist on every request ────────────────────────
// This handles the case where the plugin is updated without deactivate/reactivate.
function bs_ensure_tables() {
    global $wpdb;
    // Quick check — if the books table missing, reinstall
    $exists = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}bookshop_books'");
    if ( !$exists ) {
        bookshop_install();
    }
}
add_action('admin_init',     'bs_ensure_tables');
add_action('wp_ajax_bs_search_books',   'bs_ensure_tables', 1);
add_action('wp_ajax_bs_save_book',      'bs_ensure_tables', 1);
add_action('wp_ajax_bs_get_book',       'bs_ensure_tables', 1);

// ── Extra tables for new modules ──────────────────────────────────────────────
function bs_install_extra_tables(){
    global $wpdb;
    $c=$wpdb->get_charset_collate();
    require_once ABSPATH.'wp-admin/includes/upgrade.php';

    dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}bookshop_branches (
        id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        name        VARCHAR(255) NOT NULL,
        address     TEXT DEFAULT NULL,
        phone       VARCHAR(50) DEFAULT '',
        email       VARCHAR(255) DEFAULT '',
        manager     VARCHAR(255) DEFAULT '',
        status      ENUM('active','inactive') NOT NULL DEFAULT 'active',
        created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) $c;");

    dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}bookshop_branch_stock (
        id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        branch_id   BIGINT UNSIGNED NOT NULL,
        book_id     BIGINT UNSIGNED NOT NULL,
        qty         INT NOT NULL DEFAULT 0,
        PRIMARY KEY (id),
        UNIQUE KEY branch_book (branch_id,book_id)
    ) $c;");

    dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}bookshop_stock_transfers (
        id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        from_branch_id  BIGINT UNSIGNED NOT NULL,
        to_branch_id    BIGINT UNSIGNED NOT NULL,
        book_id         BIGINT UNSIGNED NOT NULL,
        qty             INT NOT NULL DEFAULT 0,
        staff_id        BIGINT UNSIGNED NOT NULL,
        status          ENUM('pending','completed','cancelled') NOT NULL DEFAULT 'completed',
        created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) $c;");

    dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}bookshop_stock_takes (
        id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        branch_id    BIGINT UNSIGNED NOT NULL,
        staff_id     BIGINT UNSIGNED NOT NULL,
        status       ENUM('in_progress','completed','cancelled') NOT NULL DEFAULT 'in_progress',
        completed_at DATETIME DEFAULT NULL,
        created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) $c;");

    dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}bookshop_stock_take_items (
        id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        take_id      BIGINT UNSIGNED NOT NULL,
        book_id      BIGINT UNSIGNED NOT NULL,
        expected_qty INT NOT NULL DEFAULT 0,
        counted_qty  INT NOT NULL DEFAULT 0,
        variance     INT NOT NULL DEFAULT 0,
        PRIMARY KEY (id), KEY take_id (take_id)
    ) $c;");

    dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}bookshop_held_sales (
        id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        ref           VARCHAR(30) NOT NULL,
        staff_id      BIGINT UNSIGNED NOT NULL,
        cart_data     LONGTEXT NOT NULL,
        customer_id   BIGINT UNSIGNED DEFAULT NULL,
        customer_data TEXT DEFAULT NULL,
        note          VARCHAR(255) DEFAULT '',
        status        ENUM('held','recalled','cancelled') NOT NULL DEFAULT 'held',
        created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id), KEY staff_id (staff_id), KEY status (status)
    ) $c;");

    dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}bookshop_online_orders (
        id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        ref              VARCHAR(30) NOT NULL,
        customer_name    VARCHAR(255) NOT NULL,
        customer_email   VARCHAR(255) DEFAULT '',
        customer_phone   VARCHAR(50) DEFAULT '',
        customer_address TEXT DEFAULT NULL,
        items_data       LONGTEXT NOT NULL,
        subtotal         DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        total            DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        type             ENUM('pickup','delivery') NOT NULL DEFAULT 'pickup',
        notes            TEXT DEFAULT NULL,
        status           ENUM('pending','paid','processing','ready','completed','cancelled') NOT NULL DEFAULT 'pending',
        payment_ref      VARCHAR(100) DEFAULT '',
        payment_amount   DECIMAL(10,2) DEFAULT NULL,
        payment_gateway  VARCHAR(50) DEFAULT '',
        linked_sale_id   BIGINT UNSIGNED DEFAULT NULL,
        created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id), KEY ref (ref), KEY status (status)
    ) $c;");

    dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}bookshop_messages_queue (
        id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        customer_id BIGINT UNSIGNED DEFAULT NULL,
        type        VARCHAR(50) NOT NULL DEFAULT 'bulk_email',
        message     TEXT DEFAULT NULL,
        phone       VARCHAR(50) DEFAULT '',
        email       VARCHAR(255) DEFAULT '',
        status      ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
        created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id), KEY customer_id (customer_id), KEY status (status)
    ) $c;");

    dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}bookshop_webhooks (
        id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        url        VARCHAR(500) NOT NULL,
        event      VARCHAR(100) NOT NULL DEFAULT 'sale.completed',
        secret     VARCHAR(255) DEFAULT '',
        status     ENUM('active','inactive') NOT NULL DEFAULT 'active',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) $c;");

    // Add tier column to customers if missing
    $cols=$wpdb->get_col("SHOW COLUMNS FROM {$wpdb->prefix}bookshop_customers",0);
    if(!in_array('tier',$cols)){
        $wpdb->query("ALTER TABLE {$wpdb->prefix}bookshop_customers ADD COLUMN tier VARCHAR(20) DEFAULT 'bronze' AFTER credit_balance");
    }
    // Add payment_ref to reservations if missing
    if(!$wpdb->get_var("SHOW COLUMNS FROM {$wpdb->prefix}bookshop_reservations LIKE 'payment_ref'")){
        $wpdb->query("ALTER TABLE {$wpdb->prefix}bookshop_reservations ADD COLUMN payment_ref VARCHAR(100) DEFAULT '' AFTER status, ADD COLUMN payment_amount DECIMAL(10,2) DEFAULT NULL, ADD COLUMN payment_gateway VARCHAR(50) DEFAULT ''");
    }
    // Add linked_sale_id to online_orders for completion idempotency
    if(!$wpdb->get_var("SHOW COLUMNS FROM {$wpdb->prefix}bookshop_online_orders LIKE 'linked_sale_id'")){
        $wpdb->query("ALTER TABLE {$wpdb->prefix}bookshop_online_orders ADD COLUMN linked_sale_id BIGINT UNSIGNED DEFAULT NULL AFTER payment_gateway");
    }

    // New settings defaults
    $defaults=[
        'bookshop_paystack_public_key'    =>'',
        'bookshop_paystack_secret_key'    =>'',
        'bookshop_flutterwave_public_key' =>'',
        'bookshop_flutterwave_secret_key' =>'',
        'bookshop_flw_currency'           =>'NGN',
        'bookshop_backup_email'           =>get_option('admin_email'),
        'bookshop_api_key'                =>'',
        'bookshop_google_sheets_url'      =>'',
    ];
    foreach($defaults as $k=>$v){
        if(get_option($k)===false) update_option($k,$v);
    }
}
add_action('admin_init','bs_install_extra_tables');

// ── Tables for newly added modules ────────────────────────────────────────────
function bs_install_v3_tables(){
    global $wpdb;
    $c=$wpdb->get_charset_collate();
    require_once ABSPATH.'wp-admin/includes/upgrade.php';

    dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}bookshop_refunds (
        id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        sale_id   BIGINT UNSIGNED NOT NULL,
        ref       VARCHAR(30) NOT NULL,
        staff_id  BIGINT UNSIGNED NOT NULL,
        amount    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        reason    VARCHAR(255) DEFAULT '',
        restock   TINYINT(1) NOT NULL DEFAULT 1,
        status    ENUM('completed','cancelled') NOT NULL DEFAULT 'completed',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id), KEY sale_id (sale_id)
    ) $c;");

    dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}bookshop_refund_items (
        id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        refund_id  BIGINT UNSIGNED NOT NULL,
        book_id    BIGINT UNSIGNED NOT NULL,
        qty        INT NOT NULL DEFAULT 1,
        unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        line_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        PRIMARY KEY (id), KEY refund_id (refund_id)
    ) $c;");

    dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}bookshop_bundles (
        id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        name        VARCHAR(255) NOT NULL,
        description TEXT DEFAULT NULL,
        price       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        status      ENUM('active','inactive') NOT NULL DEFAULT 'active',
        created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) $c;");

    dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}bookshop_bundle_items (
        id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        bundle_id BIGINT UNSIGNED NOT NULL,
        book_id   BIGINT UNSIGNED NOT NULL,
        qty       INT NOT NULL DEFAULT 1,
        PRIMARY KEY (id), KEY bundle_id (bundle_id)
    ) $c;");

    // New options
    $defaults=[
        'bookshop_eod_email'              =>get_option('admin_email'),
        'bookshop_loyalty_expiry_months'  =>0,
        'bookshop_ip_whitelist'           =>'',
        'bookshop_drift_digest_email'     =>get_option('admin_email'),
    ];
    foreach($defaults as $k=>$v){ if(get_option($k)===false) update_option($k,$v); }

    // Add condition columns to books if missing
    $cols=$wpdb->get_col("SHOW COLUMNS FROM {$wpdb->prefix}bookshop_books",0);
    if(!in_array('condition_type',$cols)){
        $wpdb->query("ALTER TABLE {$wpdb->prefix}bookshop_books
            ADD COLUMN condition_type ENUM('new','used','damaged') NOT NULL DEFAULT 'new',
            ADD COLUMN condition_notes VARCHAR(255) DEFAULT '',
            ADD COLUMN used_price DECIMAL(10,2) DEFAULT NULL,
            ADD COLUMN damaged_price DECIMAL(10,2) DEFAULT NULL");
    }
}
add_action('admin_init','bs_install_v3_tables');

// ── v4: per-branch tracking on sales / shifts / held sales ───────────────────
function bs_install_v4_tables(){
    global $wpdb;

    // bookshop_sales.branch_id
    $sales_cols=$wpdb->get_col("SHOW COLUMNS FROM {$wpdb->prefix}bookshop_sales",0);
    if(!in_array('branch_id',$sales_cols)){
        $wpdb->query("ALTER TABLE {$wpdb->prefix}bookshop_sales
            ADD COLUMN branch_id BIGINT UNSIGNED DEFAULT NULL AFTER staff_id,
            ADD KEY branch_id (branch_id)");
    }

    // bookshop_shifts.branch_id
    $shift_cols=$wpdb->get_col("SHOW COLUMNS FROM {$wpdb->prefix}bookshop_shifts",0);
    if(!in_array('branch_id',$shift_cols)){
        $wpdb->query("ALTER TABLE {$wpdb->prefix}bookshop_shifts
            ADD COLUMN branch_id BIGINT UNSIGNED DEFAULT NULL AFTER staff_id,
            ADD KEY branch_id (branch_id)");
    }

    // bookshop_held_sales.branch_id (table only exists after v2 migration ran)
    $held_table=$wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}bookshop_held_sales'");
    if($held_table){
        $held_cols=$wpdb->get_col("SHOW COLUMNS FROM {$wpdb->prefix}bookshop_held_sales",0);
        if(!in_array('branch_id',$held_cols)){
            $wpdb->query("ALTER TABLE {$wpdb->prefix}bookshop_held_sales
                ADD COLUMN branch_id BIGINT UNSIGNED DEFAULT NULL AFTER staff_id,
                ADD KEY branch_id (branch_id)");
        }
    }
}
add_action('admin_init','bs_install_v4_tables');
// Also run on POS-side AJAX entry points so the new columns exist on the
// frontend (admin_init never fires for non-admin users hitting admin-ajax.php).
add_action('wp_ajax_bs_submit_sale',  'bs_install_v4_tables', 1);
add_action('wp_ajax_bs_open_shift',   'bs_install_v4_tables', 1);
add_action('wp_ajax_bs_close_shift',  'bs_install_v4_tables', 1);
add_action('wp_ajax_bs_park_sale',    'bs_install_v4_tables', 1);
add_action('wp_ajax_bs_pin_login',    'bs_install_v4_tables', 1);
add_action('wp_ajax_nopriv_bs_pin_login', 'bs_install_v4_tables', 1);


// ── v5: link online orders to customer records ──────────────────────────────
// Online-order customers were previously stranded in `bookshop_online_orders`,
// invisible to the messaging UI / segment queries / loyalty hooks (all of
// which key off `bookshop_customers.id`). v5 adds the link column and runs a
// one-time backfill so existing orders catch up too.
function bs_install_v5_online_customer_link(){
    global $wpdb;

    $oo_table = $wpdb->prefix . 'bookshop_online_orders';
    if ( ! $wpdb->get_var( "SHOW TABLES LIKE '$oo_table'" ) ) return;

    $cols = $wpdb->get_col( "SHOW COLUMNS FROM `$oo_table`", 0 );
    if ( ! in_array( 'customer_id', $cols, true ) ) {
        $wpdb->query( "ALTER TABLE `$oo_table`
            ADD COLUMN customer_id BIGINT UNSIGNED DEFAULT NULL AFTER customer_address,
            ADD KEY customer_id (customer_id)" );
    }

    // One-time backfill — guarded by an option so it doesn't churn every page load.
    if ( ! get_option( 'bookshop_online_customers_backfilled' ) && function_exists( 'bs_get_or_create_customer_from_contact' ) ) {
        $orders = $wpdb->get_results( "SELECT id, customer_name, customer_email, customer_phone, customer_address
                                       FROM `$oo_table`
                                       WHERE customer_id IS NULL
                                         AND ( customer_email <> '' OR customer_phone <> '' )" );
        foreach ( $orders as $o ) {
            $cid = bs_get_or_create_customer_from_contact(
                $o->customer_name,
                $o->customer_email,
                $o->customer_phone,
                $o->customer_address ?? '',
                'Backfilled from existing online order'
            );
            if ( $cid ) {
                $wpdb->update( $oo_table, [ 'customer_id' => $cid ], [ 'id' => $o->id ] );
            }
        }
        update_option( 'bookshop_online_customers_backfilled', 1 );
    }
}
add_action( 'admin_init', 'bs_install_v5_online_customer_link' );
// Also run on the public order-submission AJAX path so the column exists for
// front-end customers placing their first online order on a freshly upgraded
// site (admin_init never fires for non-admin requests).
add_action( 'wp_ajax_bs_submit_online_order',         'bs_install_v5_online_customer_link', 1 );
add_action( 'wp_ajax_nopriv_bs_submit_online_order',  'bs_install_v5_online_customer_link', 1 );
