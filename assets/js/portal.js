/* Bookshop Customer Portal JS */
(function($){
    var nonce=BSPortal.nonce;
    var ajax=BSPortal.ajax_url;
    var cur=BSPortal.currency||'₦';

    // ── Token storage (cookie + localStorage) ────────────────────────────────
    // Cookies are unreliable on some hosts (SSL mismatch, page caching, proxy
    // stripping); localStorage and explicit POST tokens make auth bulletproof.
    function lsGet(k){ try{ return localStorage.getItem(k)||''; }catch(e){ return ''; } }
    function lsSet(k,v){ try{ localStorage.setItem(k,v); }catch(e){} }
    function lsDel(k){ try{ localStorage.removeItem(k); }catch(e){} }

    function getToken(){ return lsGet('bs_portal_token'); }
    function getName(){ return lsGet('bs_portal_name'); }

    function setSession(token, name){
        lsSet('bs_portal_token', token);
        lsSet('bs_portal_name', name);
        // Also set a cookie as a server-side hint for shortcode rendering
        var d=new Date(); d.setTime(d.getTime()+8*60*60*1000);
        document.cookie='bs_portal_token='+token+
            '; expires='+d.toUTCString()+
            '; path=/; SameSite=Lax'+
            (location.protocol==='https:'?'; Secure':'');
    }

    function clearSession(){
        lsDel('bs_portal_token');
        lsDel('bs_portal_name');
        document.cookie='bs_portal_token=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/; SameSite=Lax';
    }

    // POST helper that automatically includes the auth token
    function authPost(action, data){
        var payload = $.extend({action:action, nonce:nonce, bsp_token:getToken()}, data||{});
        return $.post(ajax, payload);
    }

    // ── Modal open/close ─────────────────────────────────────────────────────
    function openPortalModal(){
        var $m=$('#bsp-portal-modal');
        if(!$m.length) return;
        $m.removeAttr('hidden').addClass('is-open');
        $('body').addClass('bsp-modal-open');
        // If we have a stored token but the modal still shows the login form
        // (e.g. user just refreshed the page on a host where the cookie didn't
        // persist server-side), ask the server for the dashboard HTML and swap.
        if (getToken() && $('#bsp-login').length) {
            authPost('bs_portal_get_dashboard_html').done(function(res){
                if (res.success && res.data && res.data.html) {
                    $('#bs-portal').html(res.data.html);
                    $('#bsp-portal-open').text(res.data.name);
                } else {
                    // Token is stale (server transient expired) — clear local copy
                    clearSession();
                    $('#bsp-portal-open').text('Access My Account');
                }
            });
        }
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
            if(res.success && res.data && res.data.token){
                // Persist session locally
                setSession(res.data.token, res.data.name);
                // Update the trigger button text immediately
                $('#bsp-portal-open').text(res.data.name);
                // Swap modal contents to the dashboard HTML the server rendered.
                // No page reload — bypasses page caching and cookie-not-persisting issues entirely.
                if (res.data.html) {
                    $('#bs-portal').html(res.data.html);
                }
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
        // Capture the token BEFORE clearing localStorage — the server needs it
        // to know which transient to invalidate. authPost() reads from
        // localStorage, so clearing first would send an empty token and the
        // session would persist server-side.
        var token = getToken();
        $.post(ajax,{action:'bs_portal_logout', nonce:nonce, bsp_token:token}).always(function(){
            // Now clear local session
            clearSession();
            // Reload with cache-bust to defeat page caches that might still
            // have the logged-in HTML (with the customer's name on the button).
            var sep = location.search ? '&' : '?';
            location.href = location.pathname + location.search + sep + 'bsp_out=' + Date.now() + location.hash;
        });
    });

    // ── Save Profile ──────────────────────────────────────────────────────────
    $(document).on('click','#bsp-save-profile',function(){
        var btn=$(this).prop('disabled',true).text('Saving…');
        authPost('bs_portal_update_profile',{
            name:$('#bsp-p-name').val(),
            email:$('#bsp-p-email').val(),
            phone:$('#bsp-p-phone').val(),
            address:$('#bsp-p-address').val(),
            birthday:$('#bsp-p-birthday').val(),
        }).done(function(res){
            btn.prop('disabled',false).text('Save Changes');
            showMsg('#bsp-profile-msg',res.success?res.data.message:(res.data||'Error saving'),res.success?'success':'error');
        }).fail(function(xhr){
            btn.prop('disabled',false).text('Save Changes');
            showMsg('#bsp-profile-msg','Network error ('+xhr.status+')','error');
        });
    });

    // ── Submit Reservation ────────────────────────────────────────────────────
    $(document).on('click','#bsp-submit-reservation',function(){
        var title=$('#bsp-res-title').val().trim();
        if(!title){showMsg('#bsp-res-msg','Book title is required','error');return;}
        var btn=$(this).prop('disabled',true).text('Submitting…');
        authPost('bs_portal_reserve',{
            title:title,
            isbn:$('#bsp-res-isbn').val(),
            qty:$('#bsp-res-qty').val()||1,
            notes:$('#bsp-res-notes').val(),
        }).done(function(res){
            btn.prop('disabled',false).text('Submit Reservation');
            showMsg('#bsp-res-msg',res.success?res.data.message:(res.data||'Error'),'success');
            if(res.success){$('#bsp-res-title,#bsp-res-isbn,#bsp-res-notes').val('');$('#bsp-res-qty').val(1);}
        });
    });

    // ── Hydrate trigger button on page load ─────────────────────────────────
    // If localStorage says we're logged in, update the button text immediately
    // so the user doesn't see "Access My Account" briefly before it changes.
    $(function(){
        var name = getName();
        if (name && $('#bsp-portal-open').length) {
            $('#bsp-portal-open').text(name);
        }
    });

    // ── Helpers ────────────────────────────────────────────────────────────────
    function showMsg(selector,msg,type){
        $(selector).removeClass('success error').addClass(type).text(msg).show();
        setTimeout(function(){$(selector).fadeOut();},4000);
    }
    function fmt(n){return parseFloat(n||0).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g,',');}

})(jQuery);
