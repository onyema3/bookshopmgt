<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function bs_get_books( $args = [] ) {
    global $wpdb;
    $a = wp_parse_args($args, [
        'search'   => '',
        'status'   => 'active',
        'genre'    => '',
        'limit'    => 50,
        'offset'   => 0,
        'low_stock'=> false,
        'orderby'  => 'title',
        'order'    => 'ASC',
        'branch_id'=> 0,
    ]);
    $where = ['1=1']; $p = [];
    if ( $a['status'] !== '' && $a['status'] !== null ) {
        $where[] = 'b.status = %s'; $p[] = $a['status'];
    }
    if ( $a['genre'] ) {
        $where[] = 'b.genre = %s'; $p[] = $a['genre'];
    }
    if ( $a['low_stock'] ) {
        // Low-stock filter still operates on the listing's effective stock,
        // which is the branch column when scoped or the global column otherwise.
        if ( intval($a['branch_id']) > 0 ) {
            $where[] = 'COALESCE(bst.qty,0) <= b.low_stock_threshold AND COALESCE(bst.qty,0) >= 0';
        } else {
            $where[] = 'b.stock_qty <= b.low_stock_threshold AND b.stock_qty >= 0';
        }
    }
    if ( $a['search'] ) {
        $s = '%' . $wpdb->esc_like( trim($a['search']) ) . '%';
        $where[] = '(b.title LIKE %s OR b.author LIKE %s OR b.isbn LIKE %s OR b.barcode LIKE %s)';
        $p[] = $s; $p[] = $s; $p[] = $s; $p[] = $s;
    }
    $ob_col = in_array($a['orderby'], ['title','author','stock_qty','sell_price','created_at'])
        ? $a['orderby'] : 'title';
    // When scoped to a branch, "stock_qty" sorting should follow the branch
    // count, not the global column.
    if ( $ob_col === 'stock_qty' && intval($a['branch_id']) > 0 ) {
        $ob = 'COALESCE(bst.qty,0)';
    } else {
        $ob = 'b.' . $ob_col;
    }
    $od = $a['order'] === 'DESC' ? 'DESC' : 'ASC';

    // Per-branch listing: LEFT JOIN bookshop_branch_stock and rewrite stock_qty
    // in the SELECT so every consumer (POS catalogue, search results, REST,
    // CSV export) gets the branch's view of stock without a second query.
    // Books with no row at this branch surface as qty=0, which is what the
    // oversell guard expects.
    if ( intval($a['branch_id']) > 0 ) {
        array_unshift($p, intval($a['branch_id']));
        $select = "b.*, COALESCE(bst.qty, 0) AS stock_qty";
        $from   = "{$wpdb->prefix}bookshop_books b
                   LEFT JOIN {$wpdb->prefix}bookshop_branch_stock bst
                          ON bst.book_id = b.id AND bst.branch_id = %d";
    } else {
        $select = "b.*";
        $from   = "{$wpdb->prefix}bookshop_books b";
    }

    $p[] = intval($a['limit']);
    $p[] = intval($a['offset']);
    $sql = "SELECT $select FROM $from
            WHERE " . implode(' AND ', $where) . "
            ORDER BY $ob $od
            LIMIT %d OFFSET %d";
    return $wpdb->get_results( $wpdb->prepare($sql, $p) ) ?: [];
}

function bs_get_book( $id ) {
    global $wpdb;
    return $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM {$wpdb->prefix}bookshop_books WHERE id = %d", intval($id))
    );
}

function bs_get_book_by_isbn( $isbn ) {
    global $wpdb;
    return $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bookshop_books WHERE isbn = %s OR barcode = %s",
            $isbn, $isbn
        )
    );
}

function bs_save_book( $data, $id = 0 ) {
    global $wpdb;

    $title = sanitize_text_field( $data['title'] ?? '' );
    if ( empty($title) ) {
        error_log('bs_save_book: title is empty');
        return false;
    }

    // publish_year: only store if a valid 4-digit year, otherwise NULL
    $year_raw = intval( $data['publish_year'] ?? 0 );
    $pub_year = ( $year_raw >= 1000 && $year_raw <= 2099 ) ? $year_raw : null;

    $fields = [
        'isbn'                => sanitize_text_field( $data['isbn']               ?? '' ),
        'title'               => $title,
        'author'              => sanitize_text_field( $data['author']              ?? '' ),
        'genre'               => sanitize_text_field( $data['genre']               ?? '' ),
        'publisher'           => sanitize_text_field( $data['publisher']           ?? '' ),
        'publish_year'        => $pub_year,
        'description'         => sanitize_textarea_field( $data['description']    ?? '' ),
        'cost_price'          => floatval( $data['cost_price']                     ?? 0 ),
        'sell_price'          => floatval( $data['sell_price']                     ?? 0 ),
        'stock_qty'           => intval(   $data['stock_qty']                      ?? 0 ),
        'low_stock_threshold' => max( 1, intval( $data['low_stock_threshold']      ?? 5 ) ),
        'cover_url'           => sanitize_text_field( $data['cover_url']           ?? '' ),
        'barcode'             => sanitize_text_field( $data['barcode']             ?? '' ),
        'location'            => sanitize_text_field( $data['location']            ?? '' ),
        'status'              => in_array( $data['status'] ?? 'active', ['active','inactive'] )
                                    ? $data['status'] : 'active',
    ];

    if ( $id ) {
        // update() returns int (rows affected) or false on DB error.
        // 0 means no rows changed (data was identical) — that is still a success.
        $result = $wpdb->update(
            "{$wpdb->prefix}bookshop_books",
            $fields,
            ['id' => intval($id)]
        );
        if ( $result === false ) {
            error_log('bs_save_book update error: ' . $wpdb->last_error);
            return false;
        }
        bs_audit('book_updated', 'book', $id, "Updated: $title");
        return intval($id);
    }

    $result = $wpdb->insert( "{$wpdb->prefix}bookshop_books", $fields );
    if ( $result === false ) {
        error_log('bs_save_book insert error: ' . $wpdb->last_error);
        return false;
    }
    $new_id = $wpdb->insert_id;
    bs_audit('book_created', 'book', $new_id, "Created: $title");
    return $new_id;
}

function bs_delete_book( $id ) {
    global $wpdb;
    $wpdb->update(
        "{$wpdb->prefix}bookshop_books",
        ['status' => 'inactive'],
        ['id'     => intval($id)]
    );
    bs_audit('book_deleted', 'book', $id, 'Archived book');
}

function bs_adjust_stock( $id, $new_qty, $reason = '' ) {
    global $wpdb;
    $book = bs_get_book($id);
    if ( !$book ) return false;
    $old = $book->stock_qty;
    $wpdb->update(
        "{$wpdb->prefix}bookshop_books",
        ['stock_qty' => max(0, intval($new_qty))],
        ['id'        => intval($id)]
    );
    bs_audit('stock_adjusted', 'book', $id, "Stock: $old → $new_qty. $reason");
    return true;
}

function bs_count_books( $status = 'active' ) {
    global $wpdb;
    return (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}bookshop_books WHERE status = %s",
            $status
        )
    );
}

// ── ISBN Lookup ───────────────────────────────────────────────────────────────

function bs_lookup_isbn_api( $isbn ) {
    $isbn = preg_replace('/[^0-9X]/', '', strtoupper(trim($isbn)));
    if ( empty($isbn) ) return false;
    $url = "https://www.googleapis.com/books/v1/volumes?q=isbn:{$isbn}&maxResults=1";
    $res = wp_remote_get($url, ['timeout' => 10, 'sslverify' => false]);
    if ( is_wp_error($res) ) return false;
    $body = wp_remote_retrieve_body($res);
    $data = json_decode($body, true);
    if ( empty($data['items'][0]['volumeInfo']) ) return false;
    $v = $data['items'][0]['volumeInfo'];
    // Prefer larger cover image
    $cover = $v['imageLinks']['thumbnail']
          ?? $v['imageLinks']['smallThumbnail']
          ?? '';
    // Remove zoom restriction from Google Books cover URL
    $cover = str_replace('&zoom=1', '', $cover);
    $cover = str_replace('zoom=1&', '', $cover);
    return [
        'title'        => $v['title']  ?? '',
        'author'       => implode(', ', $v['authors']    ?? []),
        'publisher'    => $v['publisher'] ?? '',
        'publish_year' => isset($v['publishedDate']) ? substr($v['publishedDate'], 0, 4) : '',
        'description'  => isset($v['description'])   ? substr($v['description'],   0, 800) : '',
        'cover_url'    => $cover,
        'genre'        => implode(', ', $v['categories'] ?? []),
    ];
}

function bs_lookup_isbn_openlibrary( $isbn ) {
    $isbn = preg_replace('/[^0-9X]/', '', strtoupper(trim($isbn)));
    $url  = "https://openlibrary.org/api/books?bibkeys=ISBN:{$isbn}&format=json&jscmd=data";
    $res  = wp_remote_get($url, ['timeout' => 10, 'sslverify' => false]);
    if ( is_wp_error($res) ) return false;
    $data = json_decode(wp_remote_retrieve_body($res), true);
    $key  = "ISBN:{$isbn}";
    if ( empty($data[$key]) ) return false;
    $v = $data[$key];
    $authors    = array_map(function($a){ return isset($a['name']) ? $a['name'] : ''; }, $v['authors']    ?? []);
    $publishers = array_map(function($p){ return isset($p['name']) ? $p['name'] : ''; }, $v['publishers'] ?? []);
    $subjects   = array_map(function($s){ return isset($s['name']) ? $s['name'] : ''; }, array_slice($v['subjects'] ?? [], 0, 3));
    $cover = $v['cover']['large']  ?? $v['cover']['medium'] ?? $v['cover']['small'] ?? '';
    $year  = '';
    if ( isset($v['publish_date']) ) {
        preg_match('/\d{4}/', $v['publish_date'], $m);
        $year = $m[0] ?? '';
    }
    return [
        'title'        => $v['title']  ?? '',
        'author'       => implode(', ', array_filter($authors)),
        'publisher'    => implode(', ', array_filter($publishers)),
        'publish_year' => $year,
        'description'  => is_string($v['notes'] ?? null) ? $v['notes'] : '',
        'cover_url'    => $cover,
        'genre'        => implode(', ', array_filter($subjects)),
    ];
}

// ── CSV Import ────────────────────────────────────────────────────────────────

function bs_import_books_csv( $file_path ) {
    if ( !file_exists($file_path) ) return ['error' => 'File not found'];
    $handle   = fopen($file_path, 'r');
    $header   = fgetcsv($handle);
    $header   = array_map('trim', array_map('strtolower', $header));
    $imported = 0;
    $errors   = [];
    while ( ($row = fgetcsv($handle)) !== false ) {
        if ( count($row) < count($header) ) { $errors[] = "Row skipped: column mismatch"; continue; }
        $data = array_combine($header, $row);
        if ( empty($data['title']) ) { $errors[] = "Row skipped: no title"; continue; }
        $res = bs_save_book($data);
        if ( $res ) $imported++; else $errors[] = "Row skipped: DB error for '{$data['title']}'";
    }
    fclose($handle);
    return ['imported' => $imported, 'errors' => $errors];
}
