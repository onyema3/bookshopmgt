/* Bookshop Customer Portal JS */
(function($){
    var nonce=BSPortal.nonce;
    var ajax=BSPortal.ajax_url;
    var cur=BSPortal.currency||'₦';

    // ── Tabs ────────────────────────────────────────────────────────────────
    $(document).on('click','.bsp-tab',function(){
        var tab=$(this).data('tab');
        $(this).addClass('active').siblings('.bsp-tab').removeClass('active');
        $('.bsp-tab-content').hide();
        $('#'+tab).show();
    });

    // ── Login ────────────────────────────────────────────────────────────────
    $(document).on('click','#bsp-login-btn',function(){
        var id=$('#bsp-identifier').val().trim();
        if(!id){showMsg('#bsp-login-msg','Please enter your phone or email','error');return;}
        var btn=$(this).prop('disabled',true).text('Checking…');
        $.post(ajax,{action:'bs_portal_login',nonce:nonce,identifier:id},function(res){
            btn.prop('disabled',false).text('Access My Account');
            if(res.success){
                // Reload page to show dashboard
                window.location.reload();
            } else {
                showMsg('#bsp-login-msg',res.data||'Not found','error');
            }
        });
    });
    // Allow Enter key on identifier field
    $(document).on('keydown','#bsp-identifier',function(e){
        if(e.key==='Enter') $('#bsp-login-btn').click();
    });

    // ── Logout ────────────────────────────────────────────────────────────────
    $(document).on('click','#bsp-logout',function(){
        $.post(ajax,{action:'bs_portal_logout',nonce:nonce},function(){
            window.location.reload();
        });
    });

    // ── Save Profile ──────────────────────────────────────────────────────────
    $(document).on('click','#bsp-save-profile',function(){
        var btn=$(this).prop('disabled',true).text('Saving…');
        $.post(ajax,{
            action:'bs_portal_update_profile',nonce:nonce,
            name:$('#bsp-p-name').val(),
            email:$('#bsp-p-email').val(),
            phone:$('#bsp-p-phone').val(),
            address:$('#bsp-p-address').val(),
            birthday:$('#bsp-p-birthday').val(),
        },function(res){
            btn.prop('disabled',false).text('Save Changes');
            showMsg('#bsp-profile-msg',res.success?res.data.message:(res.data||'Error saving'),res.success?'success':'error');
        });
    });

    // ── Submit Reservation ────────────────────────────────────────────────────
    $(document).on('click','#bsp-submit-reservation',function(){
        var title=$('#bsp-res-title').val().trim();
        if(!title){showMsg('#bsp-res-msg','Book title is required','error');return;}
        var btn=$(this).prop('disabled',true).text('Submitting…');
        $.post(ajax,{
            action:'bs_portal_reserve',nonce:nonce,
            title:title,
            isbn:$('#bsp-res-isbn').val(),
            qty:$('#bsp-res-qty').val()||1,
            notes:$('#bsp-res-notes').val(),
        },function(res){
            btn.prop('disabled',false).text('Submit Reservation');
            showMsg('#bsp-res-msg',res.success?res.data.message:(res.data||'Error'),'success');
            if(res.success){$('#bsp-res-title,#bsp-res-isbn,#bsp-res-notes').val('');$('#bsp-res-qty').val(1);}
        });
    });

    // ── Helpers ────────────────────────────────────────────────────────────────
    function showMsg(selector,msg,type){
        $(selector).removeClass('success error').addClass(type).text(msg).show();
        setTimeout(function(){$(selector).fadeOut();},4000);
    }
    function fmt(n){return parseFloat(n||0).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g,',');}

})(jQuery);
