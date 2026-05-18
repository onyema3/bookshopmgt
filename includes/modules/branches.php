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
    if($exists){
        $wpdb->update("{$wpdb->prefix}bookshop_branch_stock",['qty'=>max(0,intval($qty))],['branch_id'=>$branch_id,'book_id'=>$book_id]);
    } else {
        $wpdb->insert("{$wpdb->prefix}bookshop_branch_stock",['branch_id'=>$branch_id,'book_id'=>$book_id,'qty'=>max(0,intval($qty))]);
    }
    bs_audit('branch_stock_set','branch_stock',$book_id,"Branch $branch_id: book $book_id qty=".intval($qty));
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
 */
function bs_adjust_branch_stock($branch_id,$book_id,$delta){
    global $wpdb;
    $branch_id=intval($branch_id); $book_id=intval($book_id); $delta=intval($delta);
    if(!$branch_id||!$book_id||$delta===0) return false;
    // Ensure a row exists so the UPDATE below has something to touch.
    $wpdb->query($wpdb->prepare(
        "INSERT IGNORE INTO {$wpdb->prefix}bookshop_branch_stock (branch_id,book_id,qty) VALUES (%d,%d,0)",
        $branch_id,$book_id));
    $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->prefix}bookshop_branch_stock
            SET qty = GREATEST(0, qty + %d)
          WHERE branch_id=%d AND book_id=%d",
        $delta,$branch_id,$book_id));
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
