<?php
/**
 * Plugin Name: Bookshop Manager Pro
 * Plugin URI:  https://example.com/bookshop-manager-pro
 * Description: Complete bookshop management — inventory, POS, customers, loyalty, suppliers, promotions, reports, WooCommerce sync & more.
 * Version:     2.0.0
 * Author:      Bookshop Manager
 * License:     GPL2
 * Text Domain: bookshop
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'BOOKSHOP_VERSION', '2.0.0' );
define( 'BOOKSHOP_DIR',     plugin_dir_path( __FILE__ ) );
define( 'BOOKSHOP_URL',     plugin_dir_url( __FILE__ ) );

// Core
require_once BOOKSHOP_DIR . 'includes/db.php';
require_once BOOKSHOP_DIR . 'includes/helpers.php';
require_once BOOKSHOP_DIR . 'includes/modules/ip-whitelist.php'; // load early for bs_get_client_ip()
require_once BOOKSHOP_DIR . 'includes/books.php';
require_once BOOKSHOP_DIR . 'includes/sales.php';
require_once BOOKSHOP_DIR . 'includes/customers.php';
require_once BOOKSHOP_DIR . 'includes/suppliers.php';
require_once BOOKSHOP_DIR . 'includes/purchase-orders.php';
require_once BOOKSHOP_DIR . 'includes/promotions.php';
require_once BOOKSHOP_DIR . 'includes/loyalty.php';
require_once BOOKSHOP_DIR . 'includes/shifts.php';
require_once BOOKSHOP_DIR . 'includes/audit.php';
require_once BOOKSHOP_DIR . 'includes/reports.php';
require_once BOOKSHOP_DIR . 'includes/notifications.php';
require_once BOOKSHOP_DIR . 'includes/csv-import.php';
require_once BOOKSHOP_DIR . 'includes/woocommerce.php';
require_once BOOKSHOP_DIR . 'includes/ajax-books.php';
require_once BOOKSHOP_DIR . 'includes/ajax-sales.php';
require_once BOOKSHOP_DIR . 'includes/ajax-customers.php';
require_once BOOKSHOP_DIR . 'includes/ajax-misc.php';

// Admin
require_once BOOKSHOP_DIR . 'admin/admin.php';
require_once BOOKSHOP_DIR . 'admin/page-books.php';
require_once BOOKSHOP_DIR . 'admin/page-sales.php';
require_once BOOKSHOP_DIR . 'admin/page-customers.php';
require_once BOOKSHOP_DIR . 'admin/page-suppliers.php';
require_once BOOKSHOP_DIR . 'admin/page-promotions.php';
require_once BOOKSHOP_DIR . 'admin/page-branches.php';
require_once BOOKSHOP_DIR . 'admin/page-messaging.php';
require_once BOOKSHOP_DIR . 'admin/page-online-orders.php';
require_once BOOKSHOP_DIR . 'admin/page-reports.php';
require_once BOOKSHOP_DIR . 'admin/page-settings.php';
require_once BOOKSHOP_DIR . 'admin/page-staff.php';

// POS & Frontend
require_once BOOKSHOP_DIR . 'pos/pos.php';
require_once BOOKSHOP_DIR . 'frontend/shortcodes.php';

// New modules — v2
require_once BOOKSHOP_DIR . 'includes/modules/branches.php';
require_once BOOKSHOP_DIR . 'includes/modules/held-sales.php';
require_once BOOKSHOP_DIR . 'includes/modules/customer-tiers.php';
require_once BOOKSHOP_DIR . 'includes/modules/messaging.php';
require_once BOOKSHOP_DIR . 'includes/modules/payments-online.php';
require_once BOOKSHOP_DIR . 'includes/modules/rest-api.php';
require_once BOOKSHOP_DIR . 'includes/modules/online-store.php';
require_once BOOKSHOP_DIR . 'includes/modules/backup.php';
require_once BOOKSHOP_DIR . 'includes/modules/smtp.php';
require_once BOOKSHOP_DIR . 'includes/modules/sms.php';
// staff-2fa.php depends on bs_portal_otp_* helpers from ajax-portal.php (loaded
// further down). Load order is fine at runtime since the helper calls happen
// on auth attempts, not at module load.
require_once BOOKSHOP_DIR . 'includes/modules/staff-2fa.php';

// New modules — v3
require_once BOOKSHOP_DIR . 'includes/modules/eod-report.php';
require_once BOOKSHOP_DIR . 'includes/modules/google-sheets.php';
require_once BOOKSHOP_DIR . 'includes/modules/refunds.php';
require_once BOOKSHOP_DIR . 'includes/modules/loyalty-expiry.php';
require_once BOOKSHOP_DIR . 'includes/modules/book-conditions.php';
require_once BOOKSHOP_DIR . 'includes/modules/pwa.php';
require_once BOOKSHOP_DIR . 'includes/modules/pdf-export.php';
require_once BOOKSHOP_DIR . 'includes/modules/bundles.php';
require_once BOOKSHOP_DIR . 'includes/modules/drift-digest.php';

// Portal
require_once BOOKSHOP_DIR . 'includes/ajax-portal.php';
require_once BOOKSHOP_DIR . 'frontend/customer-portal.php';

register_activation_hook( __FILE__, 'bookshop_install' );
register_deactivation_hook( __FILE__, 'bookshop_deactivate' );

function bookshop_deactivate() {
    wp_clear_scheduled_hook('bookshop_daily_tasks');
    wp_clear_scheduled_hook('bookshop_hourly');
}

add_action( 'bookshop_daily_tasks', 'bookshop_run_daily_tasks' );
function bookshop_run_daily_tasks() {
    bookshop_check_low_stock_alerts();
    bs_expire_promotions();
}
if ( ! wp_next_scheduled('bookshop_daily_tasks') ) {
    wp_schedule_event( time(), 'daily', 'bookshop_daily_tasks' );
}

// Custom hourly schedule. Used by the EOD time-of-day check (and any future
// feature that needs sub-daily ticks). WordPress ships an 'hourly' schedule
// out of the box, but registering our own keyed name makes it easy to spot
// in the cron-listing tools admins use to debug missed events.
add_filter('cron_schedules', function ($schedules) {
    if ( ! isset($schedules['bookshop_hourly']) ) {
        $schedules['bookshop_hourly'] = [
            'interval' => HOUR_IN_SECONDS,
            'display'  => 'Bookshop — once per hour',
        ];
    }
    return $schedules;
});
if ( ! wp_next_scheduled('bookshop_hourly') ) {
    wp_schedule_event( time(), 'bookshop_hourly', 'bookshop_hourly' );
}
