/**
 * GPD Reporting Portal - Main JavaScript
 *
 * Includes:
 * - AppState: global state manager
 * - LoadingBar: slim page-transition progress bar
 * - Form submit loading states
 * - Fetch wrapper with loading
 * - Table action / link loading
 * - Toast, SweetAlert2 helpers
 */

/* ═══════════════════════════════════════════════
   0. CSRF: attach the session token to every POST form and same-origin fetch.
      config/config.php rejects any POST without it.
   ═══════════════════════════════════════════════ */
(function csrfGuard() {
    function token() { return window.CSRF_TOKEN || ''; }

    function addTokenField(form) {
        if (!form || (form.method || '').toLowerCase() !== 'post' || !token()) return;
        var field = form.querySelector('input[name="csrf_token"]');
        if (!field) {
            field = document.createElement('input');
            field.type = 'hidden';
            field.name = 'csrf_token';
            form.appendChild(field);
        }
        field.value = token();
    }

    // Normal submits (capture phase runs before page handlers serialise the form)
    document.addEventListener('submit', function(e) { addTokenField(e.target); }, true);

    // form.submit() skips the submit event, so patch it too
    var nativeSubmit = HTMLFormElement.prototype.submit;
    HTMLFormElement.prototype.submit = function() {
        addTokenField(this);
        return nativeSubmit.call(this);
    };

    // fetch(): add the header on same-origin, state-changing requests
    var nativeFetch = window.fetch;
    if (nativeFetch) {
        window.fetch = function(input, init) {
            init = init || {};
            var method = (init.method || (input && input.method) || 'GET').toUpperCase();
            var url = typeof input === 'string' ? input : (input && input.url) || '';
            var sameOrigin = new URL(url, window.location.href).origin === window.location.origin;
            if (sameOrigin && method !== 'GET' && method !== 'HEAD' && token()) {
                var headers = new Headers(init.headers || (input && input.headers) || {});
                headers.set('X-CSRF-Token', token());
                init = Object.assign({}, init, { headers: headers });
            }
            return nativeFetch.call(this, input, init);
        };
    }
})();

/* Close the header "More" menu on outside click or Escape */
document.addEventListener('click', function(e) {
    document.querySelectorAll('details.nh-more[open]').forEach(function(d) {
        if (!d.contains(e.target)) d.removeAttribute('open');
    });
});
document.addEventListener('keydown', function(e) {
    if (e.key !== 'Escape') return;
    document.querySelectorAll('details.nh-more[open]').forEach(function(d) { d.removeAttribute('open'); });
    var dd = document.getElementById('profileDropdown');
    if (dd) dd.classList.add('hidden');
});

/* ═══════════════════════════════════════════════
   1. GLOBAL STATE MANAGER
   ═══════════════════════════════════════════════ */
window.AppState = (function() {
    var _state = {
        pageLoading:   false,
        formSubmitting: false,
        fetchPending:  0,
        deletePending: false,
    };
    var _listeners = {};

    function set(key, value) {
        var prev = _state[key];
        _state[key] = value;
        if (prev !== value && _listeners[key]) {
            _listeners[key].forEach(function(fn) { try { fn(value, prev); } catch(e) {} });
        }
    }
    function get(key) { return _state[key]; }
    function on(key, fn)  {
        if (!_listeners[key]) _listeners[key] = [];
        _listeners[key].push(fn);
    }
    return { set: set, get: get, on: on };
})();

/* ═══════════════════════════════════════════════
   2. LOADING BAR (slim top progress bar)
   ═══════════════════════════════════════════════ */
window.LoadingBar = (function() {
    var bar = null;
    var trickleTimer = null;
    var currentPct = 0;

    function _ensure() {
        if (bar) return;
        bar = document.createElement('div');
        bar.id = 'app-loading-bar';
        bar.style.cssText = [
            'position:fixed', 'top:0', 'left:0', 'z-index:99999',
            'height:2px', 'width:0%',
            'background:var(--primary-500, #0ea5e9)',
            'transition:width 0.2s ease, opacity 0.4s ease',
            'pointer-events:none',
            'box-shadow:0 0 6px var(--primary-400, #38bdf8)'
        ].join(';');
        document.documentElement.appendChild(bar);
    }

    function start() {
        _ensure();
        currentPct = 0;
        bar.style.opacity = '1';
        bar.style.width = '0%';
        AppState.set('pageLoading', true);
        clearInterval(trickleTimer);
        // Trickle to 85% while waiting
        trickleTimer = setInterval(function() {
            if (currentPct < 85) {
                currentPct += (85 - currentPct) * 0.12;
                bar.style.width = currentPct + '%';
            }
        }, 200);
    }

    function done() {
        _ensure();
        clearInterval(trickleTimer);
        currentPct = 100;
        bar.style.width = '100%';
        AppState.set('pageLoading', false);
        setTimeout(function() {
            bar.style.opacity = '0';
            setTimeout(function() { bar.style.width = '0%'; }, 400);
        }, 250);
    }

    function inc(amount) {
        _ensure();
        currentPct = Math.min(currentPct + (amount || 10), 94);
        bar.style.width = currentPct + '%';
    }

    return { start: start, done: done, inc: inc };
})();

/* ═══════════════════════════════════════════════
   3. BUTTON / LINK LOADING HELPERS
   ═══════════════════════════════════════════════ */

/**
 * Put a button into a loading state.
 * Returns a restore function to call when done.
 */
function setButtonLoading(btn, loadingText) {
    if (!btn || btn.disabled) return function() {};
    var original = btn.innerHTML;
    var originalDisabled = btn.disabled;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin" style="margin-right:0.35rem;font-size:0.85em;"></i>' + (loadingText || 'Loading…');
    btn.style.opacity = '0.8';
    btn.style.cursor = 'wait';
    return function restore() {
        btn.innerHTML = original;
        btn.disabled = originalDisabled;
        btn.style.opacity = '';
        btn.style.cursor = '';
    };
}

/**
 * Put any element into skeleton/loading state.
 * Returns a restore function.
 */
function setElementLoading(el, minHeight) {
    if (!el) return function() {};
    var original = el.innerHTML;
    var h = minHeight || el.offsetHeight || 40;
    el.innerHTML = '<div class="app-skeleton" style="min-height:' + h + 'px;"></div>';
    return function restore() { el.innerHTML = original; };
}

/* ═══════════════════════════════════════════════
   4. FORM SUBMIT LOADING STATE
   ═══════════════════════════════════════════════ */
function setupFormSubmitLoading() {
    document.addEventListener('submit', function(e) {
        var form = e.target;
        if (!form || form.tagName !== 'FORM') return;
        // Skip forms that opt out
        if (form.dataset.noLoading) return;
        // Skip invalid forms — let browser/validation handle
        if (form.classList.contains('needs-validation') && !form.checkValidity()) return;

        var submitBtn = form.querySelector('[type="submit"]:not([data-no-loading])');
        if (!submitBtn) return;

        var loadingText = submitBtn.dataset.loadingText ||
            form.dataset.loadingText || 'Saving…';

        AppState.set('formSubmitting', true);
        LoadingBar.start();
        setButtonLoading(submitBtn, loadingText);
        // Don't restore — page will navigate away
    }, true);
}

function submitFormWithLoading(formId, loadingText) {
    loadingText = loadingText || 'Processing…';
    var form = document.getElementById(formId);
    if (!form) return false;

    if (!form.checkValidity()) {
        form.reportValidity();
        return false;
    }

    var submitBtn = form.querySelector('button[type="submit"]');
    AppState.set('formSubmitting', true);
    LoadingBar.start();
    if (submitBtn) {
        setButtonLoading(submitBtn, loadingText);
        setTimeout(function() { form.submit(); }, 80);
    } else {
        form.submit();
    }
    return true;
}

/* ═══════════════════════════════════════════════
   5. PAGE TRANSITION LOADING (nav links)
   ═══════════════════════════════════════════════ */
function setupPageTransitionLoading() {
    document.addEventListener('click', function(e) {
        var link = e.target.closest('a[href]');
        if (!link) return;
        var href = link.getAttribute('href');
        // Skip: empty, hash, JS, external, new tab, data-no-loading, download
        if (!href || href === '#' || href.startsWith('javascript') ||
            href.startsWith('mailto') || href.startsWith('tel') ||
            link.target === '_blank' || link.dataset.noLoading !== undefined ||
            link.hasAttribute('download')) return;
        // Skip external domains
        try {
            var url = new URL(href, window.location.href);
            if (url.hostname !== window.location.hostname) return;
        } catch(err) { return; }

        LoadingBar.start();

        // Add visual spinner to the clicked link if it's an action
        if (link.classList.contains('modern-button') || link.classList.contains('modern-table-action') ||
            link.classList.contains('db-ql') || link.dataset.linkLoading !== undefined) {
            var icon = link.querySelector('i.fas:first-child');
            if (icon) {
                var origClass = icon.className;
                icon.className = 'fas fa-spinner fa-spin';
                window.addEventListener('pageshow', function() { icon.className = origClass; });
            }
        }
    });

    // Show bar done on pageshow (back-forward cache)
    window.addEventListener('pageshow', function() {
        LoadingBar.done();
        AppState.set('pageLoading', false);
        AppState.set('formSubmitting', false);
    });
}

/* ═══════════════════════════════════════════════
   6. FETCH WRAPPER WITH LOADING STATE
   ═══════════════════════════════════════════════ */
window.appFetch = function(url, options, uiOptions) {
    options = options || {};
    uiOptions = uiOptions || {};
    var triggerBtn = uiOptions.button || null;
    var loadingText = uiOptions.loadingText || 'Loading…';
    var restore = triggerBtn ? setButtonLoading(triggerBtn, loadingText) : function() {};

    AppState.set('fetchPending', AppState.get('fetchPending') + 1);
    if (AppState.get('fetchPending') === 1) LoadingBar.start();

    return fetch(url, options)
        .then(function(r) {
            AppState.set('fetchPending', Math.max(0, AppState.get('fetchPending') - 1));
            if (AppState.get('fetchPending') === 0) LoadingBar.done();
            restore();
            return r;
        })
        .catch(function(err) {
            AppState.set('fetchPending', Math.max(0, AppState.get('fetchPending') - 1));
            if (AppState.get('fetchPending') === 0) LoadingBar.done();
            restore();
            throw err;
        });
};

/* ═══════════════════════════════════════════════
   7. SKELETON SCREEN STYLES (injected once)
   ═══════════════════════════════════════════════ */
(function injectSkeletonStyles() {
    if (document.getElementById('app-skeleton-styles')) return;
    var style = document.createElement('style');
    style.id = 'app-skeleton-styles';
    style.textContent = [
        '@keyframes app-shimmer {',
        '  0%   { background-position: -400px 0; }',
        '  100% { background-position:  400px 0; }',
        '}',
        '.app-skeleton {',
        '  border-radius: 4px;',
        '  background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);',
        '  background-size: 800px 100%;',
        '  animation: app-shimmer 1.4s infinite linear;',
        '  width: 100%;',
        '}',
        '.app-skeleton-row { display:flex; gap:0.75rem; align-items:center; padding:0.65rem 0; }',
        '.app-skeleton-cell { border-radius:4px; height:0.9rem;',
        '  background:linear-gradient(90deg,#f0f0f0 25%,#e0e0e0 50%,#f0f0f0 75%);',
        '  background-size:800px 100%; animation:app-shimmer 1.4s infinite linear; }',
    ].join('\n');
    document.head.appendChild(style);
})();

/* ═══════════════════════════════════════════════
   8. DOM READY BOOTSTRAP
   ═══════════════════════════════════════════════ */
document.addEventListener('DOMContentLoaded', function() {
    // Finish the preloader loading bar
    LoadingBar.done();

    // Boot all state-managed features
    setupFormSubmitLoading();
    setupPageTransitionLoading();

    // Initialize any flatpickr date pickers
    initializeDatePickers();

    // Add event listeners for form submissions (validation)
    setupFormValidation();

    // Check for KingsChat link success message
    try {
        if (sessionStorage.getItem('kc_link_success') === '1') {
            sessionStorage.removeItem('kc_link_success');
            if (typeof showToast === 'function') {
                setTimeout(function() {
                    showToast('KingsChat account linked successfully!', 'success', 5000);
                }, 500);
            }
        }
    } catch (e) {
        console.error('Error checking KC link success:', e);
    }
});

// KingsChat popup login/link utility
window.kcOpenPopup = function(options) {
    options = options || {};
    var mode = options.mode || 'login';
    var returnTo = options.returnTo || window.location.href;
    var clientId = 'com.kingschat';
    var scopes = ['conference_calls'];
    var baseUrl = window.BASE_URL || '';
    if (!baseUrl) {
        var currentPath = window.location.pathname || '/';
        var basePath = currentPath.replace(/\/[^/]*$/, '');
        baseUrl = window.location.origin + (basePath === '/' ? '' : basePath);
    }
    var callbackUrl = baseUrl + '/kc_callback.php' + (returnTo ? ('?return_to=' + encodeURIComponent(returnTo)) : '');
    if (mode === 'link') {
        callbackUrl += (callbackUrl.indexOf('?') >= 0 ? '&' : '?') + 'mode=link';
    }
    var params = {
        client_id: clientId,
        scopes: JSON.stringify(scopes),
        redirect_uri: callbackUrl,
        response_type: 'token',
        post_redirect: true,
        state: mode
    };
    var queryString = Object.keys(params)
        .map(function(key){ return encodeURIComponent(key) + '=' + encodeURIComponent(params[key]); })
        .join('&');
    var loginUrl = 'https://accounts.kingsch.at/?' + queryString;
    var w = 480, h = 640;
    var y = window.top.outerHeight / 2 + window.top.screenY - ( h / 2);
    var x = window.top.outerWidth / 2 + window.top.screenX - ( w / 2);
    window.open(loginUrl, 'kingschat_oauth', 'toolbar=no, location=no, directories=no, status=no, menubar=no, scrollbars=yes, resizable=yes, copyhistory=no, width=' + w + ', height=' + h + ', top=' + y + ', left=' + x);
};

// Listen for KingsChat auth completion from popup
window.addEventListener('message', function(e) {
    try {
        // Only trust messages from our own kc_callback.php popup
        if (!e || e.origin !== window.location.origin) return;
        if (!e.data || typeof e.data !== 'object') return;
        if (e.data.type !== 'KC_AUTH') return;
        var kcUserId = e.data.kcUserId || '';
        var mode = e.data.mode || 'login';
        var error = e.data.error || '';
        var returnTo = e.data.returnTo || '';
        if (!returnTo) {
            try {
                returnTo = sessionStorage.getItem('kc_login_return_to') || '';
            } catch (e) {}
        }
        if (!returnTo) {
            returnTo = (window.BASE_URL + '/dashboard.php');
        }
        console.log('[KC_AUTH] Received message:', { kcUserId: kcUserId, mode: mode, error: error, returnTo: returnTo });

        // Handle errors from the callback
        if (error) {
            console.error('[KC_AUTH] Error from callback:', error);
            if (error === 'token_expired') {
                if (typeof showToast === 'function') {
                    showToast('Link token expired. Please try again.', 'error', 5000);
                }
            } else if (error === 'link_failed') {
                if (typeof showToast === 'function') {
                    showToast('Failed to link account. Please try again.', 'error', 5000);
                }
            }
            // Don't return, let it continue to potentially link
            if (!kcUserId) {
                return; // Exit if we have error and no user ID
            }
        }

        if (!kcUserId) {
            console.error('[KC_AUTH] No KC user ID received');
            if (typeof showToast === 'function') {
                showToast('No KingsChat user ID received', 'error');
            }
            return;
        }

        // If mode is 'linked', the popup already handled the linking successfully
        // Ping session then reload, same as unlink
        if (mode === 'linked') {
            console.log('[KC_AUTH] Account already linked by popup, showing success...');
            if (typeof showToast === 'function') {
                showToast('KingsChat account linked successfully', 'success');
            }
            // Ping session to update last_activity, then reload
            appFetch((window.BASE_URL || '') + '/session_ping.php', {
                method: 'GET',
                credentials: 'same-origin'
            }).finally(function() {
                // Reload after 1.5 seconds (same as unlink)
                setTimeout(function() {
                    window.location.reload();
                }, 1500);
            });
            return;
        }

        // Popup already authenticated the portal session for a mapped KingsChat user.
        if (mode === 'login') {
            console.log('[KC_AUTH] Login completed in popup, redirecting to app...');
            try { sessionStorage.removeItem('kc_login_return_to'); } catch (e) {}
            window.location.href = returnTo;
            return;
        }

        // If user is logged in or explicitly linking, link account via API
        // This is a fallback for when the popup couldn't link directly
        if (mode === 'link' || (typeof window.IS_LOGGED_IN !== 'undefined' && window.IS_LOGGED_IN === true)) {
            console.log('[KC_AUTH] Attempting to link account via API...');
            var body = 'kc_user_id=' + encodeURIComponent(kcUserId);
            appFetch((window.BASE_URL || '') + '/kc_link_account.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                credentials: 'same-origin',
                body: body
            }, { loadingText: 'Linking…' }).then(function(r){
                console.log('[KC_AUTH] Link response status:', r.status);
                return r.json();
            }).then(function(resp){
                console.log('[KC_AUTH] Link response:', resp);
                if (resp && resp.ok) {
                    // Store success message in sessionStorage before reload
                    try {
                        sessionStorage.setItem('kc_link_success', '1');
                    } catch (e) {}
                    // If already logged in, prefer reloading the current page to avoid base URL mismatches
                    if (typeof window.IS_LOGGED_IN !== 'undefined' && window.IS_LOGGED_IN === true) {
                        try {
                            var path2 = window.location.pathname || '';
                            if (path2.indexOf('login.php') !== -1) {
                                window.location.href = (window.BASE_URL || '') + '/dashboard.php';
                            } else {
                                window.location.reload();
                            }
                        } catch (e) { window.location.reload(); }
                    } else {
                        window.location.href = returnTo;
                    }
                } else {
                    console.error('[KC_AUTH] Link failed:', resp && resp.error);
                    if (typeof showToast === 'function') {
                        showToast('Failed to link KingsChat account: ' + (resp && resp.message || resp.error || 'Unknown error'), 'error', 5000);
                    }
                    // Still reload to show error state
                    setTimeout(function() {
                        try { window.location.reload(); } catch (e) { window.location.href = (window.BASE_URL || '') + '/dashboard.php'; }
                    }, 2000);
                }
            }).catch(function(err){
                console.error('[KC_AUTH] Link request failed:', err);
                if (typeof showToast === 'function') {
                    showToast('Failed to link KingsChat account. Please try again.', 'error', 5000);
                }
                setTimeout(function() {
                    try { window.location.reload(); } catch (e) { window.location.href = (window.BASE_URL || '') + '/dashboard.php'; }
                }, 2000);
            });
        } else {
            // Not logged in and no mapping: go to register
            console.log('[KC_AUTH] No existing link, redirecting to register');
            window.location.href = (window.BASE_URL || '') + '/register.php';
        }
    } catch (err) {
        console.error('[KC_AUTH] Error handling message:', err);
    }
});

/**
 * Show a toast notification
 * 
 * @param {string} message - The message to display
 * @param {string} type - The type of toast (success, error, info, warning)
 * @param {number} duration - Duration in milliseconds
 */
function showToast(message, type = 'success', duration = 3000) {
    const backgroundColor = {
        'success': 'linear-gradient(to right, #00b09b, #96c93d)',
        'error': 'linear-gradient(to right, #ff5f6d, #ffc371)',
        'info': 'linear-gradient(to right, #2193b0, #6dd5ed)',
        'warning': 'linear-gradient(to right, #f2994a, #f2c94c)'
    };
    
    Toastify({
        text: message,
        duration: duration,
        close: true,
        gravity: "top",
        position: "right",
        backgroundColor: backgroundColor[type],
        stopOnFocus: true
    }).showToast();
}

/**
 * Show a SweetAlert2 confirmation dialog
 * 
 * @param {string} title - The title of the dialog
 * @param {string} text - The text of the dialog
 * @param {string} icon - The icon to display (success, error, warning, info, question)
 * @param {string} confirmButtonText - The text for the confirm button
 * @param {Function} onConfirm - The function to call when confirmed
 */
function showConfirmDialog(title, text, icon = 'warning', confirmButtonText = 'Yes, proceed', onConfirm = null) {
    Swal.fire({
        title: title,
        text: text,
        icon: icon,
        showCancelButton: true,
        confirmButtonColor: '#3085d6',
        cancelButtonColor: '#d33',
        confirmButtonText: confirmButtonText
    }).then((result) => {
        if (result.isConfirmed && typeof onConfirm === 'function') {
            onConfirm();
        }
    });
}

/**
 * Show a SweetAlert2 alert
 * 
 * @param {string} title - The title of the alert
 * @param {string} text - The text of the alert
 * @param {string} icon - The icon to display (success, error, warning, info, question)
 */
function showAlert(title, text, icon = 'info') {
    Swal.fire({
        title: title,
        text: text,
        icon: icon,
        confirmButtonColor: '#3085d6'
    });
}

/**
 * Initialize date pickers
 */
function initializeDatePickers() {
    // Initialize flatpickr on all elements with the 'date-picker' class
    if (typeof flatpickr !== 'undefined') {
        flatpickr('.date-picker', {
            dateFormat: 'Y-m-d',
            allowInput: true,
            altInput: true,
            altFormat: 'F j, Y',
            enableTime: false
        });
        
        // Initialize datetime pickers
        flatpickr('.datetime-picker', {
            dateFormat: 'Y-m-d H:i',
            allowInput: true,
            altInput: true,
            altFormat: 'F j, Y at h:i K',
            enableTime: true
        });
    }
}

/**
 * Setup form validation
 */
function setupFormValidation() {
    // Add validation to forms with the 'needs-validation' class
    const forms = document.querySelectorAll('.needs-validation');
    
    Array.from(forms).forEach(form => {
        form.addEventListener('submit', event => {
            if (!form.checkValidity()) {
                event.preventDefault();
                event.stopPropagation();
                
                // Show toast for invalid form
                showToast('Please check the form for errors.', 'error');
                
                // Highlight the first invalid field
                const firstInvalid = form.querySelector(':invalid');
                if (firstInvalid) {
                    firstInvalid.focus();
                }
            }
            
            form.classList.add('was-validated');
        }, false);
    });
}

/**
 * Delete confirmation helper — shows loading bar after confirm
 *
 * @param {string} url - The URL to redirect to after confirmation
 * @param {string} itemName - The name of the item being deleted
 */
function confirmDelete(url, itemName) {
    itemName = itemName || 'item';
    Swal.fire({
        title: 'Confirm Deletion',
        text: 'Are you sure you want to delete this ' + itemName + '? This action cannot be undone.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: 'var(--danger-600, #dc2626)',
        cancelButtonColor: 'var(--neutral-400, #9ca3af)',
        confirmButtonText: '<i class="fas fa-trash" style="margin-right:0.35rem;"></i>Yes, delete it',
        cancelButtonText: 'Cancel',
        showLoaderOnConfirm: true,
        preConfirm: function() {
            AppState.set('deletePending', true);
            LoadingBar.start();
            return new Promise(function(resolve) {
                setTimeout(function() {
                    window.location.href = url;
                    resolve();
                }, 80);
            });
        },
        allowOutsideClick: function() { return !Swal.isLoading(); }
    });
    return false;
}

/**
 * Format a date string
 * 
 * @param {string} dateString - The date string to format
 * @param {boolean} includeTime - Whether to include the time
 * @returns {string} The formatted date string
 */
function formatDate(dateString, includeTime = false) {
    if (!dateString) return '';
    
    const date = new Date(dateString);
    const options = { 
        year: 'numeric', 
        month: 'long', 
        day: 'numeric'
    };
    
    if (includeTime) {
        options.hour = '2-digit';
        options.minute = '2-digit';
    }
    
    return date.toLocaleDateString('en-US', options);
}

/**
 * Copy text to clipboard
 * 
 * @param {string} text - The text to copy
 */
function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(
        function() {
            showToast('Copied to clipboard!', 'success');
        }, 
        function() {
            showToast('Failed to copy text', 'error');
        }
    );
}
