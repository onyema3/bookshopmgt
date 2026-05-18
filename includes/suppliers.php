<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ── Suppliers ─────────────────────────────────────────────────────────────────
function bs_get_suppliers( $status='active' ) {
    global $wpdb;
    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}bookshop_suppliers WHERE status=%s ORDER BY name ASC", $status
    ));
}
function bs_get_supplier($id){global $wpdb;return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}bookshop_suppliers WHERE id=%d",$id));}
function bs_save_supplier($data,$id=0){
    global $wpdb;
    $f=['name'=>sanitize_text_field($data['name']??''),'contact_name'=>sanitize_text_field($data['contact_name']??''),
        'email'=>sanitize_email($data['email']??''),'phone'=>sanitize_text_field($data['phone']??''),
        'address'=>sanitize_textarea_field($data['address']??''),'notes'=>sanitize_textarea_field($data['notes']??''),
        'status'=>'active'];
    if($id){$wpdb->update("{$wpdb->prefix}bookshop_suppliers",$f,['id'=>$id]);return $id;}
    $wpdb->insert("{$wpdb->prefix}bookshop_suppliers",$f);return $wpdb->insert_id;
}

// ── Purchase Orders ───────────────────────────────────────────────────────────
function bs_get_purchase_orders($args=[]) {
    global $wpdb;
    $a=wp_parse_args($args,['limit'=>50,'offset'=>0,'status'=>'']);
    $where=['1=1'];$p=[];
    if($a['status']){$where[]='po.status=%s';$p[]=$a['status'];}
    $sql="SELECT po.*,s.name AS supplier_name,u.display_name AS staff_name
          FROM {$wpdb->prefix}bookshop_purchase_orders po
          LEFT JOIN {$wpdb->prefix}bookshop_suppliers s ON s.id=po.supplier_id
          LEFT JOIN {$wpdb->users} u ON u.ID=po.staff_id
          WHERE ".implode(' AND ',$where)." ORDER BY po.created_at DESC LIMIT %d OFFSET %d";
    $p[]=$a['limit'];$p[]=$a['offset'];
    return $wpdb->get_results($wpdb->prepare($sql,$p));
}
function bs_get_po($id){
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare(
        "SELECT po.*,s.name AS supplier_name FROM {$wpdb->prefix}bookshop_purchase_orders po
         LEFT JOIN {$wpdb->prefix}bookshop_suppliers s ON s.id=po.supplier_id WHERE po.id=%d",$id));
}
function bs_get_po_items($po_id){
    global $wpdb;
    return $wpdb->get_results($wpdb->prepare(
        "SELECT pi.*,b.title,b.isbn FROM {$wpdb->prefix}bookshop_po_items pi
         LEFT JOIN {$wpdb->prefix}bookshop_books b ON b.id=pi.book_id WHERE pi.po_id=%d",$po_id));
}
function bs_create_po($supplier_id,$items,$staff_id,$notes=''){
    global $wpdb;
    $ref=bs_gen_ref('PO');
    $total=array_sum(array_map(function($i){ return floatval($i['cost'])*intval($i['qty']); },$items));
    $wpdb->insert("{$wpdb->prefix}bookshop_purchase_orders",[
        'po_ref'=>$ref,'supplier_id'=>$supplier_id?:null,'staff_id'=>$staff_id,
        'total'=>$total,'notes'=>sanitize_textarea_field($notes),'status'=>'draft',
    ]);
    $po_id=$wpdb->insert_id;
    foreach($items as $item){
        $wpdb->insert("{$wpdb->prefix}bookshop_po_items",[
            'po_id'=>$po_id,'book_id'=>intval($item['book_id']),
            'qty_ordered'=>intval($item['qty']),'qty_received'=>0,'unit_cost'=>floatval($item['cost']),
        ]);
    }
    bs_audit('po_created','purchase_order',$po_id,"PO $ref created");
    return $po_id;
}
function bs_receive_po($po_id,$received_items){
    global $wpdb;
    foreach($received_items as $item_id=>$qty_rec){
        $item=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}bookshop_po_items WHERE id=%d",$item_id));
        if(!$item)continue;
        $qty=min(intval($qty_rec),$item->qty_ordered);
        $wpdb->update("{$wpdb->prefix}bookshop_po_items",['qty_received'=>$qty],['id'=>$item_id]);
        if($qty>0){
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->prefix}bookshop_books SET stock_qty=stock_qty+%d, cost_price=%s WHERE id=%d",
                $qty,$item->unit_cost,$item->book_id
            ));
        }
    }
    $wpdb->update("{$wpdb->prefix}bookshop_purchase_orders",[
        'status'=>'received','received_at'=>current_time('mysql'),
    ],['id'=>$po_id]);
    bs_audit('po_received','purchase_order',$po_id,"PO received");
}
