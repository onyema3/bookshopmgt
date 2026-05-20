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

    // ── Login (two-step OTP) ─────────────────────────────────────────────────
    //
    // Step 1: identifier (phone/email) -> server sends a 6-digit code by email
    //         or SMS, returns an opaque otp_id.
    // Step 2: user enters the code, server verifies and returns the dashboard
    //         HTML + token. Same swap-modal-without-reload flow as before.
    //
    // We hold the typed identifier in lastIdentifier across step 2 so the
    // resend button can re-trigger step 1 without making the user re-type it.
    var lastIdentifier = '';
    var resendTimer    = null;

    function showStep1(){
        $('#bsp-login-otp').hide();
        $('#bsp-login').show();
        $('#bsp-otp-msg,#bsp-login-msg').hide();
        $('#bsp-otp-code').val('');
        $('#bsp-otp-id').val('');
        clearResendCooldown();
        // Refocus the identifier so a single keystroke continues the flow.
        setTimeout(function(){ $('#bsp-identifier').focus(); }, 30);
    }

    function showStep2(data){
        $('#bsp-login').hide();
        $('#bsp-login-otp').show();
        $('#bsp-otp-msg,#bsp-login-msg').hide();
        $('#bsp-otp-id').val(data.otp_id || '');

        // Confirm where the code went without disclosing the full address —
        // server already masked it, we just render. The channel word is for
        // tone (people parse "email" / "SMS" faster than a masked address).
        var label = data.channel === 'sms' ? 'SMS' : 'email';
        $('#bsp-otp-destination').text('Code sent by ' + label + ' to ' + (data.destination || ''));

        // Optional fallback note (e.g. typed phone but SMS not configured).
        if(data.note){
            $('#bsp-otp-note').text(data.note).show();
        } else {
            $('#bsp-otp-note').hide().text('');
        }

        // 30-second cooldown matches the server-side per-account throttle, so
        // tapping resend at 5 seconds in won't even reach the network.
        startResendCooldown(30);
        // Focus the code input. On iOS/Android with autocomplete=one-time-code
        // and the SMS arriving, the keyboard's autofill suggestion fires here.
        setTimeout(function(){ $('#bsp-otp-code').focus(); }, 30);
    }

    function startResendCooldown(seconds){
        clearResendCooldown();
        var $link = $('#bsp-otp-resend');
        var remaining = seconds;
        $link.css({pointerEvents:'none', opacity:.5})
             .text('Resend in ' + remaining + 's');
        resendTimer = setInterval(function(){
            remaining--;
            if(remaining <= 0){
                clearResendCooldown();
                return;
            }
            $link.text('Resend in ' + remaining + 's');
        }, 1000);
    }
    function clearResendCooldown(){
        if(resendTimer){ clearInterval(resendTimer); resendTimer = null; }
        $('#bsp-otp-resend').css({pointerEvents:'', opacity:''}).text('Resend code');
    }

    function requestOtp(identifier, onDone){
        $.post(ajax, {action:'bs_portal_request_otp', nonce:nonce, identifier:identifier})
         .done(function(res){
            if(res && res.success && res.data && res.data.otp_id){
                lastIdentifier = identifier;
                onDone(null, res.data);
            } else {
                onDone((res && res.data) ? res.data : 'Could not send code', null);
            }
         })
         .fail(function(xhr){
            onDone('Network error (' + xhr.status + '). Please try again.', null);
         });
    }

    // Step 1 submit
    $(document).on('click','#bsp-request-otp-btn',function(){
        var id = $('#bsp-identifier').val().trim();
        if(!id){ showMsg('#bsp-login-msg','Please enter your phone or email','error'); return; }
        var btn = $(this).prop('disabled', true).text('Sending code…');
        requestOtp(id, function(err, data){
            btn.prop('disabled', false).text('Send sign-in code');
            if(err){ showMsg('#bsp-login-msg', err, 'error'); return; }
            showStep2(data);
        });
    });
    $(document).on('keydown','#bsp-identifier',function(e){
        if(e.key === 'Enter') $('#bsp-request-otp-btn').click();
    });

    // Step 2 submit
    function submitVerify(){
        var otpId = $('#bsp-otp-id').val();
        var code  = $('#bsp-otp-code').val().replace(/\D+/g,'');
        if(!otpId){ showMsg('#bsp-otp-msg','Please request a new code', 'error'); showStep1(); return; }
        if(code.length !== 6){ showMsg('#bsp-otp-msg','Enter the 6-digit code', 'error'); return; }

        var btn = $('#bsp-verify-otp-btn').prop('disabled', true).text('Verifying…');
        $.post(ajax, {action:'bs_portal_verify_otp', nonce:nonce, otp_id:otpId, code:code})
         .done(function(res){
            btn.prop('disabled', false).text('Verify & sign in');
            if(res && res.success && res.data && res.data.token){
                setSession(res.data.token, res.data.name);
                $('#bsp-portal-open').text(res.data.name);
                if(res.data.html){ $('#bs-portal').html(res.data.html); }
                clearResendCooldown();
                lastIdentifier = '';
                return;
            }
            showMsg('#bsp-otp-msg', (res && res.data) ? res.data : 'Verification failed', 'error');
            // Server returned an error — clear the input so the user can retype
            // rather than appending to a wrong code on the next keypress.
            $('#bsp-otp-code').val('').focus();
         })
         .fail(function(xhr){
            btn.prop('disabled', false).text('Verify & sign in');
            showMsg('#bsp-otp-msg','Network error (' + xhr.status + '). Please try again.', 'error');
         });
    }
    $(document).on('click','#bsp-verify-otp-btn', submitVerify);
    $(document).on('keydown','#bsp-otp-code',function(e){
        if(e.key === 'Enter') submitVerify();
    });
    // Auto-submit when 6 digits are present. The user will almost always have
    // pasted from the SMS or autofilled — making them tap "Verify" after that
    // adds a step for no reason. Still works fine for typed entry because the
    // 6th keystroke triggers it. Strip non-digits first so paste of "123 456"
    // also works.
    $(document).on('input','#bsp-otp-code',function(){
        var clean = $(this).val().replace(/\D+/g,'').slice(0,6);
        if(clean !== $(this).val()) $(this).val(clean);
        if(clean.length === 6) submitVerify();
    });

    // Resend — re-run step 1 with the remembered identifier. The 30s server-
    // side cooldown will reject early clicks even if the JS timer somehow
    // glitches; this is just a UX hint.
    $(document).on('click','#bsp-otp-resend',function(e){
        e.preventDefault();
        if(!lastIdentifier){ showStep1(); return; }
        // No-op if cooldown is still active (pointer-events:none above).
        if($(this).css('pointer-events') === 'none') return;
        var $link = $(this).text('Sending…');
        requestOtp(lastIdentifier, function(err, data){
            if(err){
                showMsg('#bsp-otp-msg', err, 'error');
                clearResendCooldown(); // re-enable so user can try again
                return;
            }
            // Update otp_id and destination — the server may have routed
            // the resend to a different channel if config changed.
            $('#bsp-otp-id').val(data.otp_id || '');
            var label = data.channel === 'sms' ? 'SMS' : 'email';
            $('#bsp-otp-destination').text('Code sent by ' + label + ' to ' + (data.destination || ''));
            if(data.note){ $('#bsp-otp-note').text(data.note).show(); } else { $('#bsp-otp-note').hide(); }
            showMsg('#bsp-otp-msg','New code sent.','success');
            $('#bsp-otp-code').val('').focus();
            startResendCooldown(30);
        });
    });

    // Back link — discard the in-flight OTP locally (server keeps it valid
    // until expiry; that's fine — the user explicitly chose to start over).
    $(document).on('click','#bsp-otp-back',function(e){
        e.preventDefault();
        showStep1();
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
