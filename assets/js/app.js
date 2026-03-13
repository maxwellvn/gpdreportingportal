/**
 * GPD Reporting Portal - Main JavaScript
 * 
 * This file contains the initialization and configuration for:
 * - Toastify (toast notifications)
 * - SweetAlert2 (modals and alerts)
 * - Select2 (enhanced select boxes)
 * - Other utility functions
 */

document.addEventListener('DOMContentLoaded', function() {
    // Initialize Select2 on all select elements with the 'select2' class
    if (typeof $ !== 'undefined' && typeof $.fn !== 'undefined' && typeof $.fn.select2 !== 'undefined') {
        $('.select2').select2({
            theme: 'classic',
            width: '100%',
            placeholder: 'Select an option',
            allowClear: true
        });
    }

    // Initialize any flatpickr date pickers
    initializeDatePickers();

    // Add event listeners for form submissions
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
    var callbackUrl = (window.BASE_URL || '') + '/kc_callback.php' + (returnTo ? ('?return_to=' + encodeURIComponent(returnTo)) : '');
    if (mode === 'link') {
        callbackUrl += (callbackUrl.indexOf('?') >= 0 ? '&' : '?') + 'mode=link';
    }
    var params = {
        client_id: clientId,
        scopes: JSON.stringify(scopes),
        redirect_uri: callbackUrl,
        response_type: 'token',
        post_redirect: true
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
        if (!e || !e.data || typeof e.data !== 'object') return;
        if (e.data.type !== 'KC_AUTH') return;
        var kcUserId = e.data.kcUserId || '';
        var mode = e.data.mode || 'login';
        var error = e.data.error || '';
        var returnTo = e.data.returnTo || (window.BASE_URL + '/dashboard.php');
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
            fetch((window.BASE_URL || '') + '/session_ping.php', {
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

        // If user is logged in or explicitly linking, link account via API
        // This is a fallback for when the popup couldn't link directly
        if (mode === 'link' || (typeof window.IS_LOGGED_IN !== 'undefined' && window.IS_LOGGED_IN === true)) {
            console.log('[KC_AUTH] Attempting to link account via API...');
            var body = 'kc_user_id=' + encodeURIComponent(kcUserId);
            fetch((window.BASE_URL || '') + '/kc_link_account.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                credentials: 'same-origin',
                body: body
            }).then(function(r){
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
 * Delete confirmation helper
 * 
 * @param {string} url - The URL to redirect to after confirmation
 * @param {string} itemName - The name of the item being deleted
 */
function confirmDelete(url, itemName = 'item') {
    showConfirmDialog(
        'Confirm Deletion',
        `Are you sure you want to delete this ${itemName}? This action cannot be undone.`,
        'warning',
        'Yes, delete it',
        function() {
            window.location.href = url;
        }
    );
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