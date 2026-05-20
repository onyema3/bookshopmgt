<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function bs_get_customers( $args=[] ) {
    global $wpdb;
    $a = wp_parse_args($args,['search'=>'','limit'=>50,'offset'=>0,'status'=>'active']);
    $where=['1=1']; $p=[];
    if ($a['status']) { $where[]='status=%s'; $p[]=$a['status']; }
    if ($a['search']) {
        $s='%'.$wpdb->esc_like($a['search']).'%';
        $where[]='(name LIKE %s OR email LIKE %s OR phone LIKE %s)';
        $p[]=$s; $p[]=$s; $p[]=$s;
    }
    $sql="SELECT * FROM {$wpdb->prefix}bookshop_customers WHERE ".implode(' AND ',$where)." ORDER BY name ASC LIMIT %d OFFSET %d";
    $p[]=$a['limit']; $p[]=$a['offset'];
    return $wpdb->get_results($wpdb->prepare($sql,$p));
}

function bs_get_customer( $id ) {
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}bookshop_customers WHERE id=%d",$id));
}

function bs_search_customer( $q ) {
    global $wpdb;
    $s='%'.$wpdb->esc_like($q).'%';
    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}bookshop_customers WHERE status='active' AND (name LIKE %s OR phone LIKE %s OR email LIKE %s) LIMIT 10",
        $s,$s,$s
    ));
}

function bs_save_customer( $data, $id=0 ) {
    global $wpdb;
    $fields=[
        'name'    => sanitize_text_field($data['name']??''),
        'email'   => sanitize_email($data['email']??''),
        'phone'   => sanitize_text_field($data['phone']??''),
        'address' => sanitize_textarea_field($data['address']??''),
        'birthday'=> !empty($data['birthday']) ? sanitize_text_field($data['birthday']) : null,
        'notes'   => sanitize_textarea_field($data['notes']??''),
        'status'  => in_array($data['status']??'active',['active','inactive'])?$data['status']:'active',
    ];
    if ($id) { $wpdb->update("{$wpdb->prefix}bookshop_customers",$fields,['id'=>$id]); return $id; }
    $wpdb->insert("{$wpdb->prefix}bookshop_customers",$fields);
    return $wpdb->insert_id;
}

/**
 * Find or create a customer record from contact details (used by online orders,
 * reservations, and any other surface where we capture name/email/phone but
 * the customer record may not yet exist).
 *
 * Lookup precedence: email exact match, then phone exact match.
 * On match, blank fields on the existing record are filled in from the new
 * data without overwriting any non-empty value the manager may have curated.
 * On miss, a new active customer is created.
 *
 * Returns the customer id, or 0 when both email and phone are missing.
 */
function bs_get_or_create_customer_from_contact( $name, $email, $phone, $address='', $note='' ) {
    global $wpdb;
    $email = sanitize_email( $email );
    $phone = sanitize_text_field( trim( (string) $phone ) );
    $name  = trim( (string) $name );

    if ( ! $email && ! $phone ) return 0;

    $cid = 0;
    if ( $email ) {
        $cid = intval( $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}bookshop_customers WHERE email=%s LIMIT 1",
            $email ) ) );
    }
    if ( ! $cid && $phone ) {
        $cid = intval( $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}bookshop_customers WHERE phone=%s LIMIT 1",
            $phone ) ) );
    }

    if ( $cid ) {
        // Backfill blank fields without overwriting curated data.
        $existing = bs_get_customer( $cid );
        if ( $existing ) {
            $update = [];
            if ( empty( $existing->name )    && $name )    $update['name']    = $name;
            if ( empty( $existing->email )   && $email )   $update['email']   = $email;
            if ( empty( $existing->phone )   && $phone )   $update['phone']   = $phone;
            if ( empty( $existing->address ) && $address ) $update['address'] = sanitize_textarea_field( $address );
            if ( ! empty( $update ) ) {
                $wpdb->update( "{$wpdb->prefix}bookshop_customers", $update, [ 'id' => $cid ] );
            }
        }
        return $cid;
    }

    // No existing match — create a new customer.
    return bs_save_customer( [
        'name'    => $name ?: ( $email ?: $phone ),
        'email'   => $email,
        'phone'   => $phone,
        'address' => $address,
        'notes'   => $note,
        'status'  => 'active',
    ] );
}

function bs_add_customer_credit( $id, $amount, $note='' ) {
    global $wpdb;
    $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->prefix}bookshop_customers SET credit_balance=credit_balance+%s WHERE id=%d",
        floatval($amount), $id
    ));
    bs_audit('credit_added','customer',$id,"Added ".bs_fmt($amount).". $note");
}

function bs_get_customer_history( $customer_id ) {
    return bs_get_sales(['customer_id'=>$customer_id,'limit'=>200]);
}
