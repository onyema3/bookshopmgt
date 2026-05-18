<?php
if(!defined('ABSPATH'))exit;

function bs_page_staff(){
    $staff=get_users(['role__in'=>['bookshop_staff','bookshop_manager','administrator'],'orderby'=>'display_name']);
    $audit=bs_get_audit_log(['limit'=>100]);
    ?>
    <div class="wrap bs-wrap">
    <div class="bs-header"><h1>👤 Staff Management</h1></div>

    <div class="bs-tabs">
        <button class="bs-tab active" data-tab="staff-list-tab">Staff</button>
        <button class="bs-tab" data-tab="audit-tab">Audit Log</button>
    </div>

    <div id="staff-list-tab" class="bs-tab-content">
    <table class="bs-table">
        <thead><tr><th>Name</th><th>Username</th><th>Email</th><th>Role</th><th>POS PIN</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach($staff as $u):
            $roles=array_intersect($u->roles,['bookshop_staff','bookshop_manager','administrator']);
            $has_pin=!empty(get_user_meta($u->ID,'bookshop_pin',true));
        ?>
        <tr>
            <td><strong><?=esc_html($u->display_name)?></strong></td>
            <td><?=esc_html($u->user_login)?></td>
            <td><?=esc_html($u->user_email)?></td>
            <td><?=esc_html(implode(', ',$roles))?></td>
            <td><?=$has_pin?'<span class="bs-badge bs-badge-active">Set</span>':'<span class="bs-badge bs-badge-inactive">Not set</span>'?></td>
            <td>
                <button class="bs-btn bs-btn-secondary bs-set-pin" data-id="<?=esc_attr($u->ID)?>" data-name="<?=esc_attr($u->display_name)?>" style="font-size:.78rem;padding:4px 10px">Set PIN</button>
                <a href="<?=get_edit_user_link($u->ID)?>" class="bs-btn bs-btn-secondary" style="font-size:.78rem;padding:4px 10px">Edit User</a>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p style="margin-top:16px;font-size:.85rem;color:#888">
        To add POS staff: <a href="<?=admin_url('user-new.php')?>">Create a new user</a> and assign the <strong>Bookshop Staff</strong> or <strong>Bookshop Manager</strong> role. Managers can approve high discounts and void sales.
    </p>
    </div>

    <div id="audit-tab" class="bs-tab-content" style="display:none">
    <table class="bs-table">
        <thead><tr><th>Time</th><th>Staff</th><th>Action</th><th>Object</th><th>Details</th></tr></thead>
        <tbody>
        <?php foreach($audit as $a): ?>
        <tr>
            <td><?=esc_html(wp_date('d M Y H:i',strtotime($a->created_at)))?></td>
            <td><?=esc_html($a->staff_name)?></td>
            <td><code><?=esc_html($a->action)?></code></td>
            <td><?=$a->object_type?esc_html($a->object_type).' #'.$a->object_id:'-'?></td>
            <td><?=esc_html($a->details)?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    </div>

    <?php
    ob_start(); ?>
    <p>Set a 4–8 digit PIN for <strong id="bs-pin-staff-name"></strong>. Staff can use this to log in at the POS without entering their full password.</p>
    <input type="hidden" id="bs-pin-user-id">
    <div class="bs-form-group" style="margin-top:16px">
        <label>PIN (4–8 digits)</label>
        <input type="password" id="bs-pin-input" class="bs-input" maxlength="8" inputmode="numeric" pattern="[0-9]*" placeholder="····">
    </div>
    <?php $body=ob_get_clean();
    bs_modal('bs-pin-modal','Set POS PIN',$body,
        "<button class='bs-btn bs-btn-secondary bs-modal-close'>Cancel</button><button class='bs-btn bs-btn-primary' id='bs-save-pin'>Save PIN</button>");
    ?>
<?php
}
