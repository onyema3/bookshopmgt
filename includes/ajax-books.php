<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ── POS: Search books ─────────────────────────────────────────────────────────
add_action('wp_ajax_bs_search_books', function() {
    if ( !bs_user_can_pos() ) wp_send_json_error('Unauthorized', 403);
    $q     = sanitize_text_field( $_GET['q'] ?? '' );
    $books = bs_get_books(['search' => $q, 'status' => 'active', 'limit' => 30]);
    // Always return an array (never null)
    wp_send_json_success( is_array($books) ? array_values($books) : [] );
});

// ── Admin: ISBN lookup ────────────────────────────────────────────────────────
add_action('wp_ajax_bs_lookup_isbn', function() {
    if ( !current_user_can('manage_options') ) wp_send_json_error('Unauthorized', 403);
    $raw  = sanitize_text_field( $_GET['isbn'] ?? '' );
    $isbn = preg_replace('/[^0-9Xx]/', '', $raw);
    $isbn = strtoupper(trim($isbn));
    if ( empty($isbn) ) wp_send_json_error('No ISBN provided');

    // Try Google Books first
    $data = bs_lookup_isbn_api($isbn);
    if ( $data && !empty($data['title']) ) {
        wp_send_json_success($data);
        return;
    }
    // Fallback: Open Library
    $data = bs_lookup_isbn_openlibrary($isbn);
    if ( $data && !empty($data['title']) ) {
        wp_send_json_success($data);
        return;
    }
    wp_send_json_error('Book not found. Please fill in details manually.');
});

// ── Admin: Save book (insert or update) ──────────────────────────────────────
add_action('wp_ajax_bs_save_book', function() {
    global $wpdb;
    if ( !current_user_can('manage_options') ) {
        wp_send_json_error('Unauthorized', 403);
    }

    $id  = intval( $_POST['id'] ?? 0 );
    $res = bs_save_book( $_POST, $id );

    if ( $res === false ) {
        // Return the actual MySQL error so the user/dev can see what went wrong
        $db_error = $wpdb->last_error ?: 'Unknown database error';
        wp_send_json_error( 'Could not save book. DB says: ' . $db_error );
        return;
    }

    do_action('bs_after_stock_change', $res);
    wp_send_json_success(['id' => $res]);
});

// ── Admin: Get single book ────────────────────────────────────────────────────
add_action('wp_ajax_bs_get_book', function() {
    if ( !current_user_can('manage_options') ) wp_send_json_error('Unauthorized', 403);
    $b = bs_get_book( intval($_GET['id'] ?? 0) );
    $b ? wp_send_json_success($b) : wp_send_json_error('Not found');
});

// ── Admin: Delete (archive) book ─────────────────────────────────────────────
add_action('wp_ajax_bs_delete_book', function() {
    if ( !current_user_can('manage_options') ) wp_send_json_error('Unauthorized', 403);
    bs_delete_book( intval($_POST['id'] ?? 0) );
    wp_send_json_success();
});

// ── Admin: Adjust stock ───────────────────────────────────────────────────────
add_action('wp_ajax_bs_adjust_stock', function() {
    if ( !current_user_can('manage_options') ) wp_send_json_error('Unauthorized', 403);
    $id  = intval( $_POST['id']  ?? 0 );
    $qty = intval( $_POST['qty'] ?? 0 );
    bs_adjust_stock($id, $qty, 'Manual adjustment');
    do_action('bs_after_stock_change', $id);
    wp_send_json_success();
});

// ── Admin: CSV import ─────────────────────────────────────────────────────────
add_action('wp_ajax_bs_import_csv', function() {
    if ( !current_user_can('manage_options') ) wp_send_json_error('Unauthorized', 403);
    if ( empty($_FILES['csv']['tmp_name']) ) wp_send_json_error('No file uploaded');
    $res = bs_import_books_csv( $_FILES['csv']['tmp_name'] );
    wp_send_json_success($res);
});

// ── Admin: Import from WooCommerce ────────────────────────────────────────────
add_action('wp_ajax_bs_import_woo', function() {
    if ( !current_user_can('manage_options') ) wp_send_json_error('Unauthorized', 403);
    wp_send_json_success( bs_import_from_woocommerce() );
});
