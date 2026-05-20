/* Bookshop Customer Portal JS */
(function($){
    var nonce=BSPortal.nonce;
    var ajax=BSPortal.ajax_url;
    var cur=BSPortal.currency||'₦';

    // ── Modal open/close ─────────────────────────────────────────────────────
    function openPortalModal(){
        var $m=$('#bsp-portal-modal');
        if(!$m.length) return;
        $m.removeAttr('hidden').addClass('is-open');
        $('body').addClass('bsp-modal-open');
        // Focus the first input or button for accessibility
        setTimeout(function(){
            var $f=$m.find('input,button').not('[data-bsp-close]').first();
            if($f.length) $f.focus();
        },50);
    }
    function closePortalModal(){
        var $m=$('#bsp-portal-modal');
        if(!$m.length) return;
        $m.attr('hidden','hidden').removeClass('is-open');
        $('body').removeClass('bsp-modal-open');
    }
    $(document).on('click','#bsp-portal-open',openPortalModal);
    $(document).on('click','[data-bsp-close]',closePortalModal);
    $(document).on('keydown',function(e){
        if(e.key==='Escape' && $('#bsp-portal-modal.is-open').length) closePortalModal();
    });

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
                // Belt-and-suspenders: also set the cookie client-side in case
                // the PHP setcookie() got dropped by a proxy or SSL mismatch.
                if(res.data && res.data.token){
                    var d=new Date(); d.setTime(d.getTime()+8*60*60*1000);
                    document.cookie='bs_portal_token='+res.data.token+
                        '; expires='+d.toUTCString()+
                        '; path=/; SameSite=Lax'+
                        (location.protocol==='https:'?'; Secure':'');
                }
                // Reload with a cache-busting query param so page-cache plugins
                // can't serve the pre-login HTML.
                var sep = location.search ? '&' : '?';
                location.href = location.pathname + location.search + sep + 'bsp=' + Date.now() + location.hash;
            } else {
                showMsg('#bsp-login-msg',res.data||'Not found','error');
            }
        }).fail(function(xhr){
            btn.prop('disabled',false).text('Access My Account');
            showMsg('#bsp-login-msg','Network error ('+xhr.status+'). Please try again.','error');
        });
    });
    // Allow Enter key on identifier field
    $(document).on('keydown','#bsp-identifier',function(e){
        if(e.key==='Enter') $('#bsp-login-btn').click();
    });

    // ── Logout ────────────────────────────────────────────────────────────────
    $(document).on('click','#bsp-logout',function(){
        // Clear cookie client-side first so even if the AJAX call fails, the
        // user gets logged out from the browser's perspective.
        document.cookie='bs_portal_token=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/; SameSite=Lax';
        $.post(ajax,{action:'bs_portal_logout',nonce:nonce},function(){
            window.location.reload();
        }).fail(function(){ window.location.reload(); });
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
