<?php
/**
 * Multi-location / Branch Management
 */
if(!defined('ABSPATH'))exit;

// ── DB: branches table is created in db.php (bs_install_extra_tables) ─────────

function bs_get_branches($active_only=true){
    global $wpdb;
    $w=$active_only?"WHERE status='active'":'';
    return $wpdb->get_results("SELECT * FROM {$wpdb->prefix}bookshop_branches $w ORDER BY name ASC");
}
function bs_get_branch($id){
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}bookshop_branches WHERE id=%d",$id));
}
function bs_save_branch($data,$id=0){
    global $wpdb;
    $f=[
        'name'    =>sanitize_text_field($data['name']??''),
        'address' =>sanitize_textarea_field($data['address']??''),
        'phone'   =>sanitize_text_field($data['phone']??''),
        'email'   =>sanitize_email($data['email']??''),
        'manager' =>sanitize_text_field($data['manager']??''),
        'status'  =>in_array($data['status']??'active',['active','inactive'])?$data['status']:'active',
    ];
    if($id){$wpdb->update("{$wpdb->prefix}bookshop_branches",$f,['id'=>$id]);return $id;}
    $wpdb->insert("{$wpdb->prefix}bookshop_branches",$f);
    return $wpdb->insert_id;
}

/**
 * Initialize bookshop_branch_stock rows for every active book at a branch.
 *
 * Without this, every product is implicitly at qty=0 for the branch — and
 * the oversell guard in bs_create_sale will (correctly) reject every sale.
 * That's the right behaviour when a brand-new branch genuinely has zero
 * stock, but it's wrong when:
 *
 *   - the shop has been operating with a single global stock_qty and they're
 *     just now turning on multi-branch (the global stock IS that branch's
 *     stock); or
 *   - a manager wants the new branch to start with a known transfer count
 *     and intends to populate it via a stock take.
 *
 * Modes:
 *   - 'copy': INSERT IGNORE branch_stock (branch_id, book_id, stock_qty) —
 *     new branches inherit the current global stock as their starting count.
 *     Existing rows are never overwritten.
 *   - 'zero': INSERT IGNORE at qty=0. Useful when stock will be transferred
 *     in or counted in by hand. (Equivalent to doing nothing in terms of
 *     numbers, but explicit rows let stock-take and reorder logic see the
 *     branch's catalogue.)
 *
 * Returns the number of rows inserted.
 */
function bs_backfill_branch_stock($branch_id, $mode='copy'){
    global $wpdb;
    $branch_id = intval($branch_id);
    if(!$branch_id) return 0;
    if(!bs_get_branch($branch_id)) return 0;

    if($mode === 'zero'){
        $sql = $wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->prefix}bookshop_branch_stock (branch_id, book_id, qty)
             SELECT %d, b.id, 0
             FROM {$wpdb->prefix}bookshop_books b
             WHERE b.status = 'active'",
            $branch_id
        );
    } else {
        $sql = $wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->prefix}bookshop_branch_stock (branch_id, book_id, qty)
             SELECT %d, b.id, b.stock_qty
             FROM {$wpdb->prefix}bookshop_books b
             WHERE b.status = 'active'",
            $branch_id
        );
    }
    $wpdb->query($sql);
    $rows = intval($wpdb->rows_affected);
    bs_audit('branch_stock_backfill','branch',$branch_id,
        "Backfill mode=$mode — $rows books seeded");
    return $rows;
}

// ── Drift detection & reconciliation ──────────────────────────────────────────
// "Drift" means bookshop_books.stock_qty disagrees with the sum of that
// book's per-branch counts. Two common causes after the v4 migration:
//
//   1. Pre-v4 sales decremented only the global stock_qty (branch_id was
//      NULL on those sale rows), so global is *lower* than the branch sum.
//   2. A book was added after some branches were created, and those branches
//      never got a branch_stock row seeded — so the branch sum is *lower*
//      than global by the missing seed amount.
//
// The reconcile flow lets the operator pick which side to trust on a per-book
// basis. The default reconcile direction is "set global to sum-of-branches"
// because once multi-branch is on, the per-branch counts are the authoritative
// source.

/**
 * List books whose global stock_qty disagrees with the sum of their per-branch
 * counts. Returns each row with the global value, the branch sum, the delta,
 * and the count of branches that have a row for the book (so the UI can flag
 * books that are missing seed rows at one or more branches).
 *
 * Pass $book_id to scope to one book, or 0 for the full drift report.
 * Inactive books are excluded — drift on archived inventory isn't actionable.
 */
function bs_get_stock_drift($book_id = 0, $limit = 500) {
    global $wpdb;
    $branch_count = intval($wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->prefix}bookshop_branches WHERE status='active'"
    ));
    $extra_where = '';
    $params      = [];
    if ($book_id) {
        $extra_where = ' AND b.id = %d';
        $params[]    = intval($book_id);
    }
    $params[] = intval($limit);
    $sql = "SELECT b.id, b.title, b.author, b.isbn, b.stock_qty AS global_qty,
                   COALESCE(s.branch_sum, 0)    AS branch_sum,
                   COALESCE(s.branches_with_row, 0) AS branches_with_row,
                   $branch_count                 AS active_branches
            FROM {$wpdb->prefix}bookshop_books b
            LEFT JOIN (
                SELECT bst.book_id,
                       SUM(bst.qty) AS branch_sum,
                       COUNT(*)     AS branches_with_row
                FROM {$wpdb->prefix}bookshop_branch_stock bst
                JOIN {$wpdb->prefix}bookshop_branches br
                  ON br.id = bst.branch_id AND br.status = 'active'
                GROUP BY bst.book_id
            ) s ON s.book_id = b.id
            WHERE b.status = 'active'
              AND b.stock_qty <> COALESCE(s.branch_sum, 0)
              $extra_where
            ORDER BY ABS(b.stock_qty - COALESCE(s.branch_sum, 0)) DESC,
                     b.title ASC
            LIMIT %d";
    return $wpdb->get_results($wpdb->prepare($sql, $params)) ?: [];
}

/**
 * Reconcile a book's stock so the global stock_qty and per-branch sum agree.
 *
 * Two directions, picked by the caller:
 *
 *   - 'branches_to_global' (default): set bookshop_books.stock_qty to the
 *     SUM of active branch_stock rows. This is the right call when the
 *     drift comes from pre-v4 sales (which only decremented the global
 *     counter) — branches are authoritative, global is wrong.
 *
 *   - 'global_to_branches': distribute the global stock_qty proportionally
 *     across the active branches that already have a row, leaving the
 *     global counter unchanged. Use this when drift comes from a missing
 *     seed: the global figure represents real stock that's physically at
 *     one of the branches, and we want to push it down without losing
 *     count. If no branch has a row yet, we abort — picking one
 *     arbitrarily would cause exactly the kind of silent move this
 *     feature is designed to prevent.
 *
 * Returns ['ok'=>true, 'old'=>..., 'new'=>..., 'delta'=>..., 'direction'=>...]
 * on success, ['ok'=>true, 'no_change'=>true, ...] when nothing needed to
 * happen, or ['error'=>'…'] on failure.
 */
function bs_reconcile_book_stock($book_id, $direction = 'branches_to_global') {
    global $wpdb;
    $book_id = intval($book_id);
    if (!$book_id) return ['error' => 'Missing book_id'];
    $book = bs_get_book($book_id);
    if (!$book) return ['error' => 'Book not found'];
    if (!in_array($direction, ['branches_to_global','global_to_branches'], true)) {
        return ['error' => 'Invalid direction'];
    }

    if ($direction === 'global_to_branches') {
        return bs_reconcile_global_to_branches($book);
    }

    // ── branches_to_global ────────────────────────────────────────────────
    // Sum across active branches only — drift coming from an inactive
    // (closed) location shouldn't be silently rolled into the global counter.
    $sum = intval($wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(SUM(bst.qty), 0)
         FROM {$wpdb->prefix}bookshop_branch_stock bst
         JOIN {$wpdb->prefix}bookshop_branches br
           ON br.id = bst.branch_id AND br.status = 'active'
         WHERE bst.book_id = %d",
        $book_id
    )));
    $old = intval($book->stock_qty);
    if ($old === $sum) {
        return [
            'ok'=>true,'old'=>$old,'new'=>$sum,'delta'=>0,
            'no_change'=>true,'direction'=>$direction,
        ];
    }
    $wpdb->update(
        "{$wpdb->prefix}bookshop_books",
        ['stock_qty' => $sum],
        ['id'        => $book_id]
    );
    bs_audit(
        'stock_reconciled', 'book', $book_id,
        "{$book->title}: global stock_qty $old → $sum (sum across active branches)"
    );
    return ['ok'=>true,'old'=>$old,'new'=>$sum,'delta'=>$sum-$old,'direction'=>$direction];
}

/**
 * Push the global stock_qty down to the active branches in proportion to
 * their existing counts. Helper for bs_reconcile_book_stock; not meant to
 * be called directly — go through bs_reconcile_book_stock so the policy
 * checks above are applied uniformly.
 *
 * Distribution rules:
 *   - Each branch's new qty = floor(global * branch_share / sum_of_shares).
 *   - When branch_sum == 0 (every branch has a row but they're all empty),
 *     fall back to an even split.
 *   - Rounding remainder is handed out one unit at a time to the branches
 *     with the highest existing share, then alphabetically by name as a
 *     tiebreaker — so the same input always produces the same output.
 *   - If no branch has a row yet, return an error. Picking one arbitrarily
 *     would silently move physical stock to whatever branch sorted first,
 *     which is exactly the failure mode this feature is supposed to fix.
 */
function bs_reconcile_global_to_branches($book) {
    global $wpdb;
    $book_id = intval($book->id);
    $global  = intval($book->stock_qty);

    $branches = $wpdb->get_results($wpdb->prepare(
        "SELECT br.id, br.name, COALESCE(bst.qty, 0) AS qty
         FROM {$wpdb->prefix}bookshop_branches br
         LEFT JOIN {$wpdb->prefix}bookshop_branch_stock bst
                ON bst.branch_id = br.id AND bst.book_id = %d
         WHERE br.status = 'active'
         ORDER BY br.name ASC",
        $book_id
    ));
    if (empty($branches)) {
        return ['error' => 'No active branches'];
    }
    // Determine which active branches actually have a branch_stock row for
    // this book. We won't invent rows here — for global→branches, missing
    // seed rows are exactly the failure mode this feature is supposed to
    // surface, so silently creating them would defeat the purpose.
    $with_row = [];
    $ids = array_map(function($b){ return intval($b->id); }, $branches);
    if ($ids) {
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $params = array_merge([$book_id], $ids);
        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT branch_id FROM {$wpdb->prefix}bookshop_branch_stock
             WHERE book_id=%d AND branch_id IN ($placeholders)",
            $params
        ));
        $seeded = array_flip(array_map('intval', $rows));
        foreach ($branches as $b) {
            if (isset($seeded[intval($b->id)])) $with_row[] = $b;
        }
    }
    if (empty($with_row)) {
        return ['error' => 'No branch has a row for this book yet — re-seed branches first, then reconcile.'];
    }

    $current_sum = 0;
    foreach ($with_row as $b) $current_sum += intval($b->qty);

    // Compute the target per-branch quantities. floor() on the proportional
    // share, then hand out the remainder so the totals match exactly.
    $n = count($with_row);
    $target = [];
    if ($current_sum > 0) {
        $assigned = 0;
        // Pair each branch with its raw share so we can break ties on share,
        // then on name (already sorted ASC), deterministically.
        $shares = [];
        foreach ($with_row as $idx => $b) {
            $share = $global * intval($b->qty) / $current_sum;
            $base  = (int) floor($share);
            $frac  = $share - $base;
            $target[$idx] = $base;
            $shares[$idx] = $frac;
            $assigned += $base;
        }
        $remainder = $global - $assigned;
        if ($remainder > 0) {
            // Largest fractional remainder wins; ties broken by current qty
            // descending, then by branch name ascending (already the array
            // order). uasort preserves keys so $target[$idx] still aligns.
            $order = array_keys($shares);
            usort($order, function($a, $b) use ($shares, $with_row) {
                if ($shares[$a] !== $shares[$b]) return $shares[$b] <=> $shares[$a];
                $qa = intval($with_row[$a]->qty);
                $qb = intval($with_row[$b]->qty);
                if ($qa !== $qb) return $qb <=> $qa;
                return strcasecmp($with_row[$a]->name, $with_row[$b]->name);
            });
            foreach ($order as $idx) {
                if ($remainder <= 0) break;
                $target[$idx]++;
                $remainder--;
            }
        }
    } else {
        // Every seeded branch is empty — even split. Remainder goes to the
        // alphabetically-first branches so ties resolve deterministically.
        $base = intdiv($global, $n);
        $rem  = $global - ($base * $n);
        foreach ($with_row as $idx => $b) {
            $target[$idx] = $base + ($idx < $rem ? 1 : 0);
        }
    }

    // Did anything actually change? If the proposed target matches what's
    // there now, skip the writes (and the audit row).
    $any_change = false;
    foreach ($with_row as $idx => $b) {
        if ($target[$idx] !== intval($b->qty)) { $any_change = true; break; }
    }
    if (!$any_change) {
        return [
            'ok'=>true,'old'=>$current_sum,'new'=>$current_sum,'delta'=>0,
            'no_change'=>true,'direction'=>'global_to_branches',
        ];
    }

    // Apply the writes inside a transaction so a mid-loop failure can't
    // leave half the branches updated. Each individual UPDATE clamps at
    // zero defensively — target should always be >= 0 by construction.
    $wpdb->query('START TRANSACTION');
    foreach ($with_row as $idx => $b) {
        $new_qty = max(0, intval($target[$idx]));
        if ($new_qty === intval($b->qty)) continue;
        $wpdb->update(
            "{$wpdb->prefix}bookshop_branch_stock",
            ['qty' => $new_qty],
            ['branch_id' => intval($b->id), 'book_id' => $book_id]
        );
    }
    $wpdb->query('COMMIT');

    // Build a compact human-readable diff for the audit log so the change
    // is reversible by reading a single row.
    $parts = [];
    foreach ($with_row as $idx => $b) {
        $parts[] = $b->name.': '.intval($b->qty).'→'.intval($target[$idx]);
    }
    bs_audit(
        'stock_reconciled', 'book', $book_id,
        "{$book->title}: distributed global=$global across branches (".implode(', ', $parts).")"
    );

    return [
        'ok'        => true,
        'old'       => $current_sum,
        'new'       => $global,
        'delta'     => $global - $current_sum,
        'direction' => 'global_to_branches',
    ];
}

/**
 * Reconcile every drifted book in one shot. Returns a summary with per-book
 * results so the UI can show what changed. Stops at $max books to keep the
 * response bounded — the usual case is a handful of pre-v4 sales, not thousands.
 *
 * Direction is forwarded to bs_reconcile_book_stock(). For 'global_to_branches'
 * we skip rather than abort if a particular book has no seeded branches,
 * because the bulk action shouldn't fail wholesale on one bad row — the
 * skipped books stay in the drift report and can be re-seeded individually.
 */
function bs_reconcile_all_drift($max = 500, $direction = 'branches_to_global') {
    if (!in_array($direction, ['branches_to_global','global_to_branches'], true)) {
        $direction = 'branches_to_global';
    }
    $drift   = bs_get_stock_drift(0, $max);
    $results = [];
    $skipped = [];
    $changed = 0;
    foreach ($drift as $row) {
        $r = bs_reconcile_book_stock($row->id, $direction);
        if (!empty($r['ok']) && empty($r['no_change'])) {
            $changed++;
            $results[] = [
                'book_id' => intval($row->id),
                'title'   => $row->title,
                'old'     => $r['old'],
                'new'     => $r['new'],
                'delta'   => $r['delta'],
            ];
        } elseif (!empty($r['error'])) {
            $skipped[] = ['book_id'=>intval($row->id),'title'=>$row->title,'reason'=>$r['error']];
        }
    }
    bs_audit('stock_reconciled_bulk', 'system', 0,
        "Reconciled $changed books (direction=$direction"
        .(count($skipped) ? ', '.count($skipped).' skipped' : '').')');
    return [
        'changed'   => $changed,
        'results'   => $results,
        'skipped'   => $skipped,
        'direction' => $direction,
    ];
}

/**
 * Count how many active books don't yet have a row in bookshop_branch_stock
 * for a given branch. Used by the branch-edit UI to decide whether the
 * "re-seed missing books" prompt is worth showing.
 *
 * Returns the count. A non-zero return means a book added after the branch
 * was created (or skipped during initial backfill) is currently invisible to
 * stock-take and reorder logic for this branch, and will be rejected by the
 * oversell guard the moment a cashier tries to sell it from this location.
 */
function bs_count_missing_branch_stock_rows($branch_id) {
    global $wpdb;
    $branch_id = intval($branch_id);
    if (!$branch_id) return 0;
    return intval($wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}bookshop_books b
         WHERE b.status = 'active'
           AND NOT EXISTS (
               SELECT 1 FROM {$wpdb->prefix}bookshop_branch_stock bst
               WHERE bst.book_id = b.id AND bst.branch_id = %d
           )",
        $branch_id
    )));
}

// ── User ↔ Branch assignment ──────────────────────────────────────────────────
// A user has at most one "home" branch (user_meta bookshop_branch_id).
// Managers and admins are allowed to operate from any active branch.
// At runtime, the currently-selected branch lives in user_meta
// bookshop_active_branch_id and is also reflected on the open shift.

const BS_USER_HOME_BRANCH_META   = 'bookshop_branch_id';
const BS_USER_ACTIVE_BRANCH_META = 'bookshop_active_branch_id';

function bs_get_user_branch($uid=0){
    if(!$uid) $uid=get_current_user_id();
    if(!$uid) return 0;
    return intval(get_user_meta($uid,BS_USER_HOME_BRANCH_META,true));
}

function bs_set_user_branch($uid,$branch_id){
    $uid=intval($uid); $branch_id=intval($branch_id);
    if(!$uid) return false;
    if($branch_id===0){
        delete_user_meta($uid,BS_USER_HOME_BRANCH_META);
        bs_audit('user_branch_cleared','user',$uid,"Home branch cleared");
        return true;
    }
    if(!bs_get_branch($branch_id)) return false;
    update_user_meta($uid,BS_USER_HOME_BRANCH_META,$branch_id);
    bs_audit('user_branch_set','user',$uid,"Home branch set to $branch_id");
    return true;
}

// Branches this user is allowed to operate from.
// - Admin / manager: every active branch.
// - Staff: their assigned home branch only (if set).
function bs_user_branches($uid=0){
    if(!$uid) $uid=get_current_user_id();
    if(!$uid) return [];
    if(bs_user_can_manage($uid)) return bs_get_branches(true);
    $home=bs_get_user_branch($uid);
    if(!$home) return [];
    $b=bs_get_branch($home);
    return ($b && $b->status==='active') ? [$b] : [];
}

/**
 * Branches the user is allowed to *view reports for*. Stricter than
 * bs_user_branches() (which governs the POS picker).
 *
 * - Global admin (manage_options): every active branch.
 * - Any other user with a home branch assigned: only that home branch,
 *   even if they hold the bookshop_manager role.
 * - Non-admin with no home branch: falls back to bs_user_branches().
 *
 * The point of the stricter rule is that without it, a bookshop_manager
 * scoped to one location could hand-craft ?branch=N on the reports URL
 * and see another branch's revenue.
 */
function bs_user_report_branches($uid=0){
    if(!$uid) $uid=get_current_user_id();
    if(!$uid) return [];
    if(bs_user_is_admin($uid)) return bs_get_branches(true);
    $home=bs_get_user_branch($uid);
    if($home){
        $b=bs_get_branch($home);
        return ($b && $b->status==='active') ? [$b] : [];
    }
    return bs_user_branches($uid);
}

/**
 * Validate a requested branch_id against what the user is allowed to see in
 * reports. Returns the branch_id unchanged when valid, 0 when the user is
 * unrestricted, or false when access should be denied.
 *
 * Used by the reports page (which silently coerces) and by the export
 * endpoints (which reject with wp_die) to keep that one policy in one place.
 */
function bs_validate_report_branch($branch_id, $uid=0){
    $branch_id = intval($branch_id);
    if(!$uid) $uid = get_current_user_id();
    $allowed = bs_user_report_branches($uid);
    // Admin with no branches yet — let everything through.
    if(empty($allowed) && bs_user_is_admin($uid)) return $branch_id;
    // No allowed branches and not admin — deny.
    if(empty($allowed)) return false;
    if(!$branch_id){
        // No branch requested. Admins may see "all branches"; everyone else
        // is forced to their (single) allowed branch so they can never see
        // an unscoped aggregate that includes other locations.
        return bs_user_is_admin($uid) ? 0 : intval($allowed[0]->id);
    }
    foreach($allowed as $b){
        if(intval($b->id) === $branch_id) return $branch_id;
    }
    return false;
}

function bs_get_active_branch_id($uid=0){
    if(!$uid) $uid=get_current_user_id();
    if(!$uid) return 0;
    return intval(get_user_meta($uid,BS_USER_ACTIVE_BRANCH_META,true));
}

// Set the active branch for the current session. Returns true on success,
// or a string error code: 'no_user' / 'invalid_branch' / 'forbidden'.
function bs_set_active_branch_id($uid,$branch_id){
    $uid=intval($uid); $branch_id=intval($branch_id);
    if(!$uid) return 'no_user';
    if($branch_id===0){
        delete_user_meta($uid,BS_USER_ACTIVE_BRANCH_META);
        return true;
    }
    $branch=bs_get_branch($branch_id);
    if(!$branch || $branch->status!=='active') return 'invalid_branch';
    $allowed=bs_user_branches($uid);
    $ok=false;
    foreach($allowed as $b){ if(intval($b->id)===$branch_id){ $ok=true; break; } }
    if(!$ok) return 'forbidden';
    update_user_meta($uid,BS_USER_ACTIVE_BRANCH_META,$branch_id);
    return true;
}

// ── Branch stock (separate stock per branch) ──────────────────────────────────
function bs_get_branch_stock($branch_id,$book_id=0){
    global $wpdb;
    if($book_id){
        $row=$wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bookshop_branch_stock WHERE branch_id=%d AND book_id=%d",
            $branch_id,$book_id));
        return $row?intval($row->qty):0;
    }
    return $wpdb->get_results($wpdb->prepare(
        "SELECT bs.*, b.title, b.author, b.isbn, b.sell_price, b.cost_price, b.low_stock_threshold
         FROM {$wpdb->prefix}bookshop_branch_stock bs
         JOIN {$wpdb->prefix}bookshop_books b ON b.id=bs.book_id
         WHERE bs.branch_id=%d ORDER BY b.title ASC",
        $branch_id));
}
function bs_set_branch_stock($branch_id,$book_id,$qty){
    global $wpdb;
    $exists=$wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}bookshop_branch_stock WHERE branch_id=%d AND book_id=%d",
        $branch_id,$book_id));
    $before = $exists
        ? intval($wpdb->get_var($wpdb->prepare(
            "SELECT qty FROM {$wpdb->prefix}bookshop_branch_stock WHERE branch_id=%d AND book_id=%d",
            $branch_id,$book_id)))
        : 0;
    if($exists){
        $wpdb->update("{$wpdb->prefix}bookshop_branch_stock",['qty'=>max(0,intval($qty))],['branch_id'=>$branch_id,'book_id'=>$book_id]);
    } else {
        $wpdb->insert("{$wpdb->prefix}bookshop_branch_stock",['branch_id'=>$branch_id,'book_id'=>$book_id,'qty'=>max(0,intval($qty))]);
    }
    // object_type='book' so the per-book activity panel on the breakdown
    // modal picks this up. (Earlier the type was 'branch_stock' with
    // object_id=$book_id, which was a confusing combo and made the panel
    // need a special case to find these rows.)
    bs_audit('branch_stock_set','book',$book_id,
        "Branch $branch_id: $before → ".max(0,intval($qty)).' (manual set or stock take)');
}

/**
 * Apply a delta (positive or negative) to per-branch stock.
 *
 * Used by sale / void / refund flows where the global bookshop_books.stock_qty
 * is also being adjusted. Idempotently inserts a row at qty=0 first if the
 * branch hasn't tracked this book yet, then performs a single UPDATE that
 * clamps at zero so we never go negative.
 *
 * Returns true on success, false if the inputs are invalid. Silently no-ops
 * when $branch_id is 0 so callers can pass through historical sales whose
 * branch_id is NULL without special-casing.
 *
 * The optional $context string is appended to the audit row so a reader of
 * the per-book activity panel can see *why* a delta happened ("void of sale
 * BS-123ABC", "refund RF-XYZ", etc.) rather than a bare number. Pass an
 * empty string when no useful context is available — the audit row will
 * still be written so the change is traceable.
 */
function bs_adjust_branch_stock($branch_id,$book_id,$delta,$context=''){
    global $wpdb;
    $branch_id=intval($branch_id); $book_id=intval($book_id); $delta=intval($delta);
    if(!$branch_id||!$book_id||$delta===0) return false;
    // Ensure a row exists so the UPDATE below has something to touch.
    $wpdb->query($wpdb->prepare(
        "INSERT IGNORE INTO {$wpdb->prefix}bookshop_branch_stock (branch_id,book_id,qty) VALUES (%d,%d,0)",
        $branch_id,$book_id));
    // Read-back so the audit row can record the actual before/after rather
    // than just the requested delta — the GREATEST(0,…) clamp can mean a
    // -10 delta only moves stock by -3 if the row was already at 3.
    $before = intval($wpdb->get_var($wpdb->prepare(
        "SELECT qty FROM {$wpdb->prefix}bookshop_branch_stock WHERE branch_id=%d AND book_id=%d",
        $branch_id,$book_id)));
    $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->prefix}bookshop_branch_stock
            SET qty = GREATEST(0, qty + %d)
          WHERE branch_id=%d AND book_id=%d",
        $delta,$branch_id,$book_id));
    $after = intval($wpdb->get_var($wpdb->prepare(
        "SELECT qty FROM {$wpdb->prefix}bookshop_branch_stock WHERE branch_id=%d AND book_id=%d",
        $branch_id,$book_id)));
    if($before !== $after){
        $detail = "Branch $branch_id: $before → $after (Δ".($after-$before>=0?'+':'').($after-$before).')';
        if($context !== '') $detail .= " — $context";
        bs_audit('branch_stock_adjusted','book',$book_id,$detail);
    }
    return true;
}
function bs_transfer_stock($from_branch,$to_branch,$book_id,$qty){
    global $wpdb;
    $qty=intval($qty);
    $from_stock=bs_get_branch_stock($from_branch,$book_id);
    if($from_stock<$qty) return ['error'=>'Insufficient stock at source branch'];
    bs_set_branch_stock($from_branch,$book_id,$from_stock-$qty);
    $to_stock=bs_get_branch_stock($to_branch,$book_id);
    bs_set_branch_stock($to_branch,$book_id,$to_stock+$qty);
    // Log the transfer
    $wpdb->insert("{$wpdb->prefix}bookshop_stock_transfers",[
        'from_branch_id'=>$from_branch,'to_branch_id'=>$to_branch,
        'book_id'=>$book_id,'qty'=>$qty,'staff_id'=>get_current_user_id(),
        'status'=>'completed',
    ]);
    bs_audit('stock_transfer','book',$book_id,"Transfer $qty from branch $from_branch to $to_branch");
    return ['success'=>true,'transfer_id'=>$wpdb->insert_id];
}

// ── Stock Take ────────────────────────────────────────────────────────────────
function bs_create_stock_take($branch_id,$staff_id){
    global $wpdb;
    $wpdb->insert("{$wpdb->prefix}bookshop_stock_takes",[
        'branch_id'=>intval($branch_id),'staff_id'=>intval($staff_id),'status'=>'in_progress',
    ]);
    return $wpdb->insert_id;
}
function bs_submit_stock_take($take_id,$counts){
    // $counts = [book_id => counted_qty, ...]
    global $wpdb;
    $take=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}bookshop_stock_takes WHERE id=%d",$take_id));
    if(!$take||$take->status!=='in_progress') return false;
    $variances=[];
    foreach($counts as $book_id=>$counted){
        $book_id=intval($book_id);$counted=intval($counted);
        $expected=bs_get_branch_stock($take->branch_id,$book_id);
        $variance=$counted-$expected;
        $wpdb->insert("{$wpdb->prefix}bookshop_stock_take_items",[
            'take_id'=>$take_id,'book_id'=>$book_id,
            'expected_qty'=>$expected,'counted_qty'=>$counted,'variance'=>$variance,
        ]);
        if($variance!==0){
            bs_set_branch_stock($take->branch_id,$book_id,$counted);
            $variances[]=['book_id'=>$book_id,'variance'=>$variance];
        }
    }
    $wpdb->update("{$wpdb->prefix}bookshop_stock_takes",['status'=>'completed','completed_at'=>current_time('mysql')],['id'=>$take_id]);
    bs_audit('stock_take_completed','branch',$take->branch_id,"Take #$take_id: ".count($variances)." variances");
    return $variances;
}

// ── Reorder automation ────────────────────────────────────────────────────────
function bs_check_reorder_points($branch_id=0){
    global $wpdb;
    $w=$branch_id?"AND bs.branch_id=".intval($branch_id):'';
    $books=$wpdb->get_results(
        "SELECT b.*, bs.branch_id, bs.qty AS branch_stock
         FROM {$wpdb->prefix}bookshop_branch_stock bs
         JOIN {$wpdb->prefix}bookshop_books b ON b.id=bs.book_id
         WHERE bs.qty <= b.low_stock_threshold AND b.status='active' $w"
    );
    if(empty($books)) return [];
    // Check if a draft PO already exists for these
    $to_reorder=[];
    foreach($books as $b){
        $existing=$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}bookshop_po_items pi
             JOIN {$wpdb->prefix}bookshop_purchase_orders po ON po.id=pi.po_id
             WHERE pi.book_id=%d AND po.status IN ('draft','ordered')",$b->id));
        if(!$existing) $to_reorder[]=$b;
    }
    return $to_reorder;
}
function bs_auto_create_reorder_po($branch_id=0){
    $books=bs_check_reorder_points($branch_id);
    if(empty($books)) return 0;
    $items=[];
    foreach($books as $b){
        $reorder_qty=max(10,$b->low_stock_threshold*3);
        $items[]=['book_id'=>$b->id,'qty'=>$reorder_qty,'cost'=>$b->cost_price];
    }
    return bs_create_po(0,$items,get_current_user_id(),'Auto-generated reorder PO');
}
