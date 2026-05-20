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

    $rows = [];
    foreach ( $allowed as $b ) {
        $qty = $book_id
            ? intval($wpdb->get_var($wpdb->prepare(
                "SELECT qty FROM {$wpdb->prefix}bookshop_branch_stock WHERE branch_id=%d AND book_id=%d",
                intval($b->id), $book_id)))
            : 0;
        $rows[] = [
            'id'   => intval($b->id),
            'name' => $b->name,
            'qty'  => $qty,
        ];
    }

    $global = 0;
    if ( $book_id ) {
        $book = bs_get_book($book_id);
        if ( $book ) $global = intval($book->stock_qty);
    }

    wp_send_json_success(['branches' => $rows, 'global' => $global]);
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

// ── Bulk operations on books ──────────────────────────────────────────────────
//
// Three endpoints, all gated by manage cap and the admin nonce. Each takes a
// JSON-encoded ids[] in the POST body so we don't blow up the URL with hundreds
// of book ids when the user has selected the whole table.
//
// Audit policy: one row per book (not a single summary row). The price-change
// case in particular is exactly the kind of thing that gets queried later
// ("why is this book priced this way?"), so writing per-book audit lines is
// cheap insurance — a bulk of 200 books costs 200 audit inserts, fine for the
// scale this plugin runs at.
//
// All three endpoints return a {touched, skipped} pair so the JS can confirm
// "Updated 197, 3 skipped (not found)" rather than just "OK." A bulk that
// silently no-ops on bad ids is a bulk that fires twice.

/**
 * Decode and validate the ids payload on a bulk request. Centralised so
 * the three endpoints stay nearly identical — different validations would
 * be a footgun for a bulk operation.
 */
function bs_bulk_decode_ids() {
    $raw = $_POST['ids'] ?? '';
    if ( $raw === '' ) return [];
    // Accept either a JSON array or a comma-separated list (clients in the
    // wild sometimes get this wrong; either way we end up with a clean int
    // array).
    $arr = is_string($raw) ? json_decode( stripslashes($raw), true ) : (array) $raw;
    if ( ! is_array($arr) ) return [];
    $ids = [];
    foreach ( $arr as $id ) {
        $id = intval($id);
        if ( $id > 0 ) $ids[] = $id;
    }
    // De-dup so we don't apply a +10% twice to the same book if the client
    // sent the id twice (it shouldn't, but cheap guard).
    return array_values( array_unique($ids) );
}

// ── Bulk: percentage price change ────────────────────────────────────────────
add_action('wp_ajax_bs_bulk_price_change', function() {
    global $wpdb;
    if ( ! bs_user_can_manage() ) wp_send_json_error('Unauthorized', 403);
    if ( ! bs_verify('bs_admin_nonce') ) wp_send_json_error('Bad nonce', 403);

    $ids = bs_bulk_decode_ids();
    if ( empty($ids) ) wp_send_json_error('No books selected.');

    // Percentage as a float — accept "10", "+10", "-5.5", "-12%". Anything
    // outside (-100, 1000) is rejected as a likely fat-finger error: -100
    // would price everything at zero (or below), and 1000% (=11×) is almost
    // certainly a typo for 10% or 100%. The 1000 cap is generous; tighten if
    // it ever bites.
    $pct_raw = trim( (string) ($_POST['pct'] ?? '') );
    $pct_raw = str_replace('%', '', $pct_raw);
    if ( ! is_numeric($pct_raw) ) wp_send_json_error('Enter a percentage like +10 or -5.');
    $pct = floatval($pct_raw);
    if ( $pct <= -100 || $pct >= 1000 ) {
        wp_send_json_error('Percentage must be between -100 and 1000. Got: ' . $pct);
    }

    $also_cost = ! empty($_POST['also_cost']);
    $rounding  = in_array($_POST['rounding'] ?? 'cent', ['cent','whole'], true)
                 ? $_POST['rounding'] : 'cent';

    $factor = 1 + ($pct / 100);
    $touched = 0;
    $skipped = [];

    // Locking the iteration to per-row updates rather than one big UPDATE
    // statement for two reasons: (a) we want to write a per-book audit line
    // and (b) the rounding mode (whole vs cent) is much cleaner expressed in
    // PHP than in MySQL ROUND/CEIL gymnastics.
    foreach ( $ids as $id ) {
        $book = bs_get_book($id);
        if ( ! $book ) { $skipped[] = $id; continue; }

        $old_sell = floatval($book->sell_price);
        $new_sell = $old_sell * $factor;
        if ( $new_sell < 0 ) $new_sell = 0;
        $new_sell = $rounding === 'whole' ? round($new_sell, 0) : round($new_sell, 2);

        $update = ['sell_price' => $new_sell];
        $audit_extra = '';

        if ( $also_cost ) {
            $old_cost = floatval($book->cost_price);
            $new_cost = $old_cost * $factor;
            if ( $new_cost < 0 ) $new_cost = 0;
            $new_cost = $rounding === 'whole' ? round($new_cost, 0) : round($new_cost, 2);
            $update['cost_price'] = $new_cost;
            $audit_extra = sprintf(' Cost: %s → %s.', bs_fmt($old_cost), bs_fmt($new_cost));
        }

        $wpdb->update( "{$wpdb->prefix}bookshop_books", $update, ['id' => $id] );

        $sign = $pct >= 0 ? '+' : '';
        bs_audit('book_price_bulk', 'book', $id,
            sprintf('Price: %s → %s (%s%s%%).%s',
                bs_fmt($old_sell), bs_fmt($new_sell), $sign, rtrim(rtrim(number_format($pct,2,'.',''),'0'),'.'), $audit_extra
            )
        );
        $touched++;
    }

    wp_send_json_success([
        'touched' => $touched,
        'skipped' => count($skipped),
        'skipped_ids' => $skipped,
        'message' => sprintf('Updated %d book%s%s.',
            $touched,
            $touched === 1 ? '' : 's',
            $skipped ? ' ('.count($skipped).' not found)' : ''
        ),
    ]);
});

// ── Bulk: rename genre ───────────────────────────────────────────────────────
// Two modes:
//   - ids[] supplied → rename genre on those specific books
//   - ids[] empty + from_genre supplied → rename every active book whose
//     genre exactly matches `from_genre` to `to_genre`. This is the
//     "I made a typo when I created the genre" case where the user wants
//     the change to be table-wide rather than picking 47 rows manually.
add_action('wp_ajax_bs_bulk_rename_genre', function() {
    global $wpdb;
    if ( ! bs_user_can_manage() ) wp_send_json_error('Unauthorized', 403);
    if ( ! bs_verify('bs_admin_nonce') ) wp_send_json_error('Bad nonce', 403);

    $to = sanitize_text_field( $_POST['to_genre'] ?? '' );
    // Allow empty target — "clear genre on these books" is a valid request.
    // Length cap matches the column definition.
    if ( mb_strlen($to) > 100 ) wp_send_json_error('Genre name must be 100 characters or fewer.');

    $ids = bs_bulk_decode_ids();
    $from = sanitize_text_field( $_POST['from_genre'] ?? '' );

    if ( empty($ids) && $from === '' ) {
        wp_send_json_error('Either select rows or specify the source genre to rename.');
    }

    $touched = 0;
    $skipped = [];

    if ( ! empty($ids) ) {
        foreach ( $ids as $id ) {
            $book = bs_get_book($id);
            if ( ! $book ) { $skipped[] = $id; continue; }
            if ( $book->genre === $to ) continue; // already matches, skip silently
            $wpdb->update("{$wpdb->prefix}bookshop_books", ['genre' => $to], ['id' => $id]);
            bs_audit('book_genre_renamed', 'book', $id,
                sprintf('Genre: %s → %s', $book->genre ?: '(none)', $to ?: '(none)'));
            $touched++;
        }
    } else {
        // Whole-genre rename. Pull matching ids first so we can write
        // per-book audit lines (matches the per-row policy used elsewhere).
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}bookshop_books
             WHERE status='active' AND genre=%s",
            $from
        ));
        foreach ( $rows as $r ) {
            $wpdb->update("{$wpdb->prefix}bookshop_books", ['genre' => $to], ['id' => $r->id]);
            bs_audit('book_genre_renamed', 'book', $r->id,
                sprintf('Genre: %s → %s (whole-genre rename)', $from ?: '(none)', $to ?: '(none)'));
            $touched++;
        }
    }

    wp_send_json_success([
        'touched' => $touched,
        'skipped' => count($skipped),
        'message' => $touched === 0
            ? 'No books matched.'
            : sprintf('Renamed genre on %d book%s.', $touched, $touched === 1 ? '' : 's'),
    ]);
});

// ── Bulk: archive / un-archive ───────────────────────────────────────────────
// Symmetric — same endpoint, status param picks the direction. Gives admins
// a way to bulk-restore an accidental archive without a one-off endpoint.
add_action('wp_ajax_bs_bulk_set_status', function() {
    global $wpdb;
    if ( ! bs_user_can_manage() ) wp_send_json_error('Unauthorized', 403);
    if ( ! bs_verify('bs_admin_nonce') ) wp_send_json_error('Bad nonce', 403);

    $status = ($_POST['status'] ?? 'inactive') === 'active' ? 'active' : 'inactive';
    $ids    = bs_bulk_decode_ids();
    if ( empty($ids) ) wp_send_json_error('No books selected.');

    $touched = 0;
    $skipped = [];
    foreach ( $ids as $id ) {
        $book = bs_get_book($id);
        if ( ! $book ) { $skipped[] = $id; continue; }
        if ( $book->status === $status ) continue; // already in target state
        $wpdb->update("{$wpdb->prefix}bookshop_books", ['status' => $status], ['id' => $id]);
        bs_audit(
            $status === 'inactive' ? 'book_bulk_archived' : 'book_bulk_restored',
            'book', $id,
            sprintf('Status: %s → %s', $book->status, $status)
        );
        $touched++;
    }

    wp_send_json_success([
        'touched' => $touched,
        'skipped' => count($skipped),
        'message' => sprintf('%s %d book%s.',
            $status === 'inactive' ? 'Archived' : 'Restored',
            $touched,
            $touched === 1 ? '' : 's'
        ),
    ]);
});
