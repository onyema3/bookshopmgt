<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ── POS: Search books ─────────────────────────────────────────────────────────
add_action('wp_ajax_bs_search_books', function() {
    if ( !bs_user_can_pos() ) wp_send_json_error('Unauthorized', 403);
    $q = sanitize_text_field( $_GET['q'] ?? '' );

    // Resolve the active branch from the same source the sale flow trusts:
    // open shift first (authoritative once a shift is running), session's
    // active branch as a fallback. Returning per-branch stock here keeps the
    // POS catalogue badges honest — a cashier should see "0 in stock" for a
    // book that's only at another branch, not the global aggregate.
    $uid    = get_current_user_id();
    $shift  = function_exists('bs_get_open_shift') ? bs_get_open_shift($uid) : null;
    $branch = $shift && $shift->branch_id
        ? intval($shift->branch_id)
        : ( function_exists('bs_get_active_branch_id') ? bs_get_active_branch_id($uid) : 0 );

    $books = bs_get_books([
        'search'    => $q,
        'status'    => 'active',
        'limit'     => 30,
        'branch_id' => $branch,
    ]);
    // Always return an array (never null)
    wp_send_json_success( is_array($books) ? array_values($books) : [] );
});

// ── Admin: ISBN lookup ────────────────────────────────────────────────────────
add_action('wp_ajax_bs_lookup_isbn', function() {
    if ( !bs_user_can_manage() ) wp_send_json_error('Unauthorized', 403);
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
    if ( !bs_user_can_manage() ) {
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

    // ── Per-branch stock (optional payload) ──────────────────────────────
    // The Add/Edit Book modal can post `branch_stock[branch_id]=qty` for any
    // branches the current user is allowed to operate from. We deliberately
    // gate the write with bs_user_branches() so a manager pinned to one
    // location can't overwrite another branch's count by editing the form
    // payload. CSV / REST / Woo importers don't post this key, so they're
    // unaffected.
    if ( isset($_POST['branch_stock']) && is_array($_POST['branch_stock']) ) {
        $allowed = function_exists('bs_user_branches') ? bs_user_branches() : [];
        $allowed_ids = array_map(function($b){ return intval($b->id); }, $allowed);
        $touched = false;
        foreach ( $_POST['branch_stock'] as $branch_id => $qty ) {
            $branch_id = intval($branch_id);
            if ( !$branch_id || !in_array($branch_id, $allowed_ids, true) ) continue;
            $qty = max(0, intval($qty));
            bs_set_branch_stock($branch_id, $res, $qty);
            $touched = true;
        }
        // Once any per-branch row exists for this book, re-derive the global
        // stock_qty from the branch sum so the unscoped listing and any
        // legacy reports that still read bookshop_books.stock_qty stay in
        // sync. We only do this when the book actually has branch rows —
        // shops that haven't started using branches keep the manual qty
        // they typed in #bs-f-stock.
        if ( $touched ) {
            $sum = $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(qty),0) FROM {$wpdb->prefix}bookshop_branch_stock WHERE book_id=%d",
                $res));
            if ( $sum !== null ) {
                $wpdb->update(
                    "{$wpdb->prefix}bookshop_books",
                    ['stock_qty' => intval($sum)],
                    ['id' => intval($res)]
                );
            }
        }
    }

    do_action('bs_after_stock_change', $res);
    wp_send_json_success(['id' => $res]);
});

// ── Admin: List branches + per-book qty for the Add/Edit Book modal ──────────
// Returns only branches the current user is allowed to operate from
// (bs_user_branches), so non-admin managers see — and can write to — only
// their own branch. When book_id is 0/missing the qty is 0 for every row.
add_action('wp_ajax_bs_get_book_branches', function() {
    if ( !bs_user_can_manage() ) wp_send_json_error('Unauthorized', 403);
    global $wpdb;
    $book_id = intval( $_GET['book_id'] ?? 0 );

    $allowed = function_exists('bs_user_branches') ? bs_user_branches() : [];
    if ( empty($allowed) ) {
        wp_send_json_success(['branches' => [], 'global' => 0]);
        return;
    }

    // Per-row threshold lets the JS colour low/out-of-stock inputs without
    // needing a second round-trip. We pull it once from the book row (it's
    // a property of the catalogue entry, not the per-branch stock row) and
    // attach it to every branch row in the response. For brand-new books
    // (book_id=0) we use the same default the form picks, 5, so the JS can
    // still warn when a manager seeds a row at 0 or 1.
    $threshold = 5;
    if ( $book_id ) {
        $t = $wpdb->get_var($wpdb->prepare(
            "SELECT low_stock_threshold FROM {$wpdb->prefix}bookshop_books WHERE id=%d",
            $book_id));
        if ( $t !== null ) $threshold = max(0, intval($t));
    }

    $rows = [];
    foreach ( $allowed as $b ) {
        $qty = $book_id
            ? intval($wpdb->get_var($wpdb->prepare(
                "SELECT qty FROM {$wpdb->prefix}bookshop_branch_stock WHERE branch_id=%d AND book_id=%d",
                intval($b->id), $book_id)))
            : 0;
        $rows[] = [
            'id'        => intval($b->id),
            'name'      => $b->name,
            'qty'       => $qty,
            'threshold' => $threshold,
        ];
    }

    $global = 0;
    if ( $book_id ) {
        $book = bs_get_book($book_id);
        if ( $book ) $global = intval($book->stock_qty);
    }

    wp_send_json_success([
        'branches'  => $rows,
        'global'    => $global,
        'threshold' => $threshold,
    ]);
});

// ── Admin: Get single book ────────────────────────────────────────────────────
add_action('wp_ajax_bs_get_book', function() {
    if ( !bs_user_can_manage() ) wp_send_json_error('Unauthorized', 403);
    $b = bs_get_book( intval($_GET['id'] ?? 0) );
    $b ? wp_send_json_success($b) : wp_send_json_error('Not found');
});

// ── Admin: Delete (archive) book ─────────────────────────────────────────────
add_action('wp_ajax_bs_delete_book', function() {
    if ( !bs_user_can_manage() ) wp_send_json_error('Unauthorized', 403);
    bs_delete_book( intval($_POST['id'] ?? 0) );
    wp_send_json_success();
});

// ── Admin: Adjust stock ───────────────────────────────────────────────────────
add_action('wp_ajax_bs_adjust_stock', function() {
    if ( !bs_user_can_manage() ) wp_send_json_error('Unauthorized', 403);
    $id  = intval( $_POST['id']  ?? 0 );
    $qty = intval( $_POST['qty'] ?? 0 );
    bs_adjust_stock($id, $qty, 'Manual adjustment');
    do_action('bs_after_stock_change', $id);
    wp_send_json_success();
});

// ── Admin: CSV import ─────────────────────────────────────────────────────────
add_action('wp_ajax_bs_import_csv', function() {
    if ( !bs_user_can_manage() ) wp_send_json_error('Unauthorized', 403);
    if ( empty($_FILES['csv']['tmp_name']) ) wp_send_json_error('No file uploaded');
    $res = bs_import_books_csv( $_FILES['csv']['tmp_name'] );
    wp_send_json_success($res);
});

// ── Admin: Import from WooCommerce ────────────────────────────────────────────
add_action('wp_ajax_bs_import_woo', function() {
    if ( !bs_user_can_manage() ) wp_send_json_error('Unauthorized', 403);
    wp_send_json_success( bs_import_from_woocommerce() );
});
