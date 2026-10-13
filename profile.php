<?php
require_once 'config/config.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

// Ensure user is logged in
@error_log('[PROFILE] Page load - Session ID: ' . session_id() . ', User ID: ' . (isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 'not set') . ', Last activity: ' . (isset($_SESSION['last_activity']) ? date('Y-m-d H:i:s', $_SESSION['last_activity']) : 'not set'));
Auth::requireLogin();
@error_log('[PROFILE] After requireLogin - still logged in');

// Clear KC linking flag if set (user successfully accessed profile after linking)
if (isset($_SESSION['kc_linking_in_progress'])) {
    @error_log('[PROFILE] Clearing kc_linking_in_progress flag');
    unset($_SESSION['kc_linking_in_progress']);
}

// Get user data
$auth = new Auth();
$user = $auth->getUser($_SESSION['user_id']);

// Database connection
$db = new Database();
$conn = $db->getConnection();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Start transaction
        $conn->beginTransaction();
        
        // Update user information
        $name = trim($_POST['name']);
        $username = trim($_POST['username']);
        
        // Validate inputs
        if (empty($name) || empty($username)) {
            throw new Exception("All fields are required");
        }
        
        // Check if username already exists (excluding current user)
        $checkStmt = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
        $checkStmt->execute([$username, $_SESSION['user_id']]);
        if ($checkStmt->rowCount() > 0) {
            throw new Exception("Username already exists");
        }
        
        // Update user data
        $updateStmt = $conn->prepare("UPDATE users SET name = ?, username = ? WHERE id = ?");
        $updateStmt->execute([$name, $username, $_SESSION['user_id']]);
        
        // Handle password change if provided
        if (!empty($_POST['new_password'])) {
            $currentPassword = $_POST['current_password'];
            $newPassword = $_POST['new_password'];
            $confirmPassword = $_POST['confirm_password'];
            
            // Verify current password
            if (!password_verify($currentPassword, $user['password'])) {
                throw new Exception("Current password is incorrect");
            }
            
            // Validate new password
            if (strlen($newPassword) < 8) {
                throw new Exception("New password must be at least 8 characters long");
            }
            
            if ($newPassword !== $confirmPassword) {
                throw new Exception("New passwords do not match");
            }
            
            // Update password
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            $passwordStmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
            $passwordStmt->execute([$hashedPassword, $_SESSION['user_id']]);
        }
        
        // If user is admin, they can update role, region, and zone
        if (Auth::isAdmin() && isset($_POST['update_role'])) {
            $role = $_POST['role'];
            $region = $_POST['region'];
            $zone = $_POST['zone'];
            
            $roleStmt = $conn->prepare("UPDATE users SET role = ?, region = ?, zone = ? WHERE id = ?");
            $roleStmt->execute([$role, $region, $zone, $_SESSION['user_id']]);
        }
        
        // Commit transaction
        $conn->commit();
        
        // Set success message
        $_SESSION['success'] = "Profile updated successfully";
        
        // Refresh user data
        $user = $auth->getUser($_SESSION['user_id']);
        
    } catch (Exception $e) {
        // Rollback transaction
        $conn->rollBack();
        
        // Set error message
        $_SESSION['error'] = $e->getMessage();
    }
}


// Include header
require_once 'layouts/header.php';
?>

<style>
.pf-page { max-width: 900px; margin: 0 auto; padding: 2rem 1.25rem 3rem; }
.pf-title { font-size: 1.125rem; font-weight: 700; color: var(--neutral-900); margin: 0 0 1.5rem; }
.pf-grid { display: grid; grid-template-columns: 1fr 280px; gap: 1.25rem; }
@media(max-width:768px){ .pf-grid { grid-template-columns: 1fr; } }
.pf-card { border: 1px solid var(--neutral-200); border-radius: var(--radius-lg); background: #fff; overflow: hidden; margin-bottom: 1.25rem; }
.pf-card:last-child { margin-bottom: 0; }
.pf-card-head { padding: 0.85rem 1.25rem; border-bottom: 1px solid var(--neutral-200); display: flex; align-items: center; justify-content: space-between; }
.pf-card-head h2 { font-size: 0.875rem; font-weight: 600; color: var(--neutral-900); margin: 0; text-transform: uppercase; letter-spacing: 0.04em; }
.pf-card-body { padding: 1.25rem; }
.pf-info-row { display: flex; justify-content: space-between; align-items: center; padding: 0.6rem 0; border-bottom: 1px solid var(--neutral-100); font-size: 0.85rem; }
.pf-info-row:last-child { border-bottom: none; }
.pf-info-label { color: var(--neutral-500); }
.pf-info-value { font-weight: 500; color: var(--neutral-900); }
.pf-avatar { width: 2.5rem; height: 2.5rem; border-radius: 50%; background: var(--primary-100); color: var(--primary-600); display: flex; align-items: center; justify-content: center; font-size: 1rem; font-weight: 600; }
.pf-admin-divider { border-top: 1px solid var(--neutral-200); margin: 1.25rem 0 1rem; padding-top: 1rem; }
.pf-admin-divider h3 { font-size: 0.75rem; font-weight: 600; color: var(--neutral-500); text-transform: uppercase; letter-spacing: 0.04em; margin: 0 0 1rem; }
.pf-kc-row { display: flex; align-items: flex-start; gap: 0.875rem; }
.pf-kc-icon { width: 2.25rem; height: 2.25rem; border-radius: var(--radius-md); border: 1px solid var(--neutral-200); display: flex; align-items: center; justify-content: center; font-size: 0.875rem; flex-shrink: 0; }
.pf-kc-icon.connected { background: var(--success-50); color: var(--success-600); border-color: var(--success-200); }
.pf-kc-icon.pending { background: var(--warning-50); color: var(--warning-600); border-color: var(--warning-200); }
</style>

<div class="pf-page">
    <h1 class="pf-title">My Profile</h1>
        
        <?php if (isset($_SESSION['error'])): ?>
            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    showToast('<?php echo addslashes($_SESSION['error']); ?>', 'error', 5000);
                });
            </script>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['success'])): ?>
            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    showAlert('Success', '<?php echo addslashes($_SESSION['success']); ?>', 'success');
                });
            </script>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>
        
    <div class="pf-grid">
        <div>
            <div class="pf-card">
                <div class="pf-card-head"><h2>Profile Information</h2></div>
                <div class="pf-card-body">
                        <form method="POST" class="space-y-6 needs-validation" novalidate>
                            <div class="modern-form-group">
                                <label for="name" class="modern-form-label">Full Name</label>
                                <input type="text" id="name" name="name" class="modern-form-input" value="<?php echo htmlspecialchars($user['name'] ?? ''); ?>" required>
                            </div>
                            

                            
                            <div class="modern-form-group">
                                <label for="username" class="modern-form-label">Username</label>
                                <input type="text" id="username" name="username" class="modern-form-input" value="<?php echo htmlspecialchars($user['username'] ?? ''); ?>" required>
                            </div>
                            
                            <?php if (Auth::isAdmin()): ?>
                            <div class="pt-4 border-t border-gray-200">
                                <h3 class="text-lg font-medium text-gray-900 mb-4">Administrative Information</h3>
                                <input type="hidden" name="update_role" value="1">
                                
                                <div class="modern-form-group">
                                    <label for="role" class="modern-form-label">Role</label>
                                    <select id="role" name="role" class="modern-form-input">
                                        <option value="admin" <?php echo ($user['role'] ?? '') === 'admin' ? 'selected' : ''; ?>>Admin</option>
                                        <option value="user" <?php echo ($user['role'] ?? '') === 'user' ? 'selected' : ''; ?>>RZM</option>
                                    </select>
                                </div>
                                
                                <div class="modern-form-group">
                                    <label for="region" class="modern-form-label">Region</label>
                                    <select id="region" name="region" class="modern-form-input">
                                        <option value="">Select Region</option>
                                        <?php foreach ($REGIONS_ZONES as $region => $zones): ?>
                                            <option value="<?php echo htmlspecialchars($region); ?>" <?php echo ($user['region'] ?? '') === $region ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($region); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="modern-form-group">
                                    <label for="zone" class="modern-form-label">Zone</label>
                                    <select id="zone" name="zone" class="modern-form-input">
                                        <option value="">Select Zone</option>
                                        <?php 
                                        if (!empty($user['region']) && isset($REGIONS_ZONES[$user['region']])) {
                                            foreach ($REGIONS_ZONES[$user['region']] as $zone) {
                                                $selected = $user['zone'] === $zone ? 'selected' : '';
                                                echo "<option value=\"" . htmlspecialchars($zone) . "\" $selected>" . htmlspecialchars($zone) . "</option>";
                                            }
                                        }
                                        ?>
                                    </select>
                                </div>
                            </div>
                            <?php else: ?>
                            <div class="pt-4 border-t border-gray-200">
                                <h3 class="text-lg font-medium text-gray-900 mb-4">Administrative Information</h3>
                                
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Role</label>
                                        <div class="text-gray-900 font-medium"><?php echo $user['role'] === 'user' ? 'RZM' : ucfirst(htmlspecialchars($user['role'])); ?></div>
                                    </div>
                                    
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Region</label>
                                        <div class="text-gray-900 font-medium"><?php echo htmlspecialchars($user['region'] ?: 'Not assigned'); ?></div>
                                    </div>
                                    
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Zone</label>
                                        <div class="text-gray-900 font-medium"><?php echo htmlspecialchars($user['zone'] ?: 'Not assigned'); ?></div>
                                    </div>
                                </div>
                                <p class="mt-2 text-sm text-gray-500">Contact an administrator to update these fields.</p>
                            </div>
                            <?php endif; ?>
                            
                        <div style="margin-top:1.25rem;padding-top:1rem;border-top:1px solid var(--neutral-200);">
                            <button type="submit" class="modern-button modern-button-primary" style="font-size:0.8125rem;" data-loading-text="Saving…"><i class="fas fa-save modern-button-icon"></i> Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="pf-card">
                <div class="pf-card-head"><h2>Change Password</h2></div>
                <div class="pf-card-body">
                        <form method="POST" class="space-y-6 needs-validation" novalidate>
                            <!-- Keep the profile fields but hidden -->
                            <input type="hidden" name="name" value="<?php echo htmlspecialchars($user['name'] ?? ''); ?>">
                            <input type="hidden" name="username" value="<?php echo htmlspecialchars($user['username'] ?? ''); ?>">
                            
                            <div class="modern-form-group">
                                <label for="current_password" class="modern-form-label">Current Password</label>
                                <input type="password" id="current_password" name="current_password" class="modern-form-input" required>
                            </div>
                            
                            <div class="modern-form-group">
                                <label for="new_password" class="modern-form-label">New Password</label>
                                <input type="password" id="new_password" name="new_password" class="modern-form-input" required minlength="8">
                                <p class="modern-form-help">Password must be at least 8 characters long</p>
                            </div>
                            
                            <div class="modern-form-group">
                                <label for="confirm_password" class="modern-form-label">Confirm New Password</label>
                                <input type="password" id="confirm_password" name="confirm_password" class="modern-form-input" required>
                            </div>
                            
                    <div style="margin-top:1.25rem;padding-top:1rem;border-top:1px solid var(--neutral-200);">
                        <button type="submit" class="modern-button modern-button-primary" style="font-size:0.8125rem;" data-loading-text="Updating…"><i class="fas fa-key modern-button-icon"></i> Update Password</button>
                    </div>
                </form>
                </div>
            </div>
        </div>

        <!-- Sidebar -->
        <div>
            <div class="pf-card">
                <div class="pf-card-head"><h2>Account</h2></div>
                <div class="pf-card-body">
                    <div style="display:flex;align-items:center;gap:0.75rem;margin-bottom:1rem;">
                        <div class="pf-avatar"><?php echo strtoupper(substr($user['name'] ?? 'U', 0, 1)); ?></div>
                        <div>
                            <div style="font-size:0.9rem;font-weight:600;color:var(--neutral-900);"><?php echo htmlspecialchars($user['name'] ?? ''); ?></div>
                            <div style="font-size:0.8rem;color:var(--neutral-500);">@<?php echo htmlspecialchars($user['username'] ?? ''); ?></div>
                        </div>
                    </div>
                    <div class="pf-info-row"><span class="pf-info-label">Role</span><span class="pf-info-value"><?php echo $user['role'] === 'user' ? 'RZM' : ucfirst(htmlspecialchars($user['role'])); ?></span></div>
                    <div class="pf-info-row"><span class="pf-info-label">Status</span><span class="modern-badge <?php echo $user['active'] ? 'modern-badge-success' : 'modern-badge-warning'; ?>"><?php echo $user['active'] ? 'Active' : 'Pending'; ?></span></div>
                    <div class="pf-info-row"><span class="pf-info-label">Member Since</span><span class="pf-info-value"><?php echo date('M j, Y', strtotime($user['created_at'])); ?></span></div>
                </div>
            </div>

            <!-- KingsChat Integration -->
            <?php
            require_once __DIR__ . '/includes/kc_link.php';
            $isKcLinked = kc_is_user_linked((int)$_SESSION['user_id']);
            $kcUserId = $isKcLinked ? kc_get_kc_by_user_id((int)$_SESSION['user_id']) : null;
            ?>
            <div class="pf-card">
                <div class="pf-card-head"><h2>KingsChat</h2></div>
                <div class="pf-card-body">
                    <div class="pf-kc-row">
                        <div class="pf-kc-icon <?php echo $isKcLinked ? 'connected' : 'pending'; ?>">
                            <i class="fas <?php echo $isKcLinked ? 'fa-check' : 'fa-exclamation'; ?>"></i>
                        </div>
                        <div style="flex:1;">
                            <div style="font-size:0.875rem;font-weight:600;color:var(--neutral-900);"><?php echo $isKcLinked ? 'Connected' : 'Not Connected'; ?></div>
                            <?php if ($isKcLinked): ?>
                            <div style="font-size:0.8rem;color:var(--neutral-500);margin-top:0.15rem;">KingsChat account is linked</div>
                            <?php if ($kcUserId): ?><div style="font-size:0.75rem;color:var(--neutral-400);font-family:monospace;margin-top:0.25rem;">ID: <?php echo htmlspecialchars($kcUserId); ?></div><?php endif; ?>
                            <button onclick="unlinkKingsChat()" style="margin-top:0.6rem;font-size:0.8rem;color:var(--danger-600);background:none;border:none;cursor:pointer;padding:0;"><i class="fas fa-unlink" style="margin-right:0.3rem;"></i>Unlink</button>
                            <?php else: ?>
                            <div style="font-size:0.8rem;color:var(--neutral-500);margin-top:0.15rem;">Link for quick login &amp; notifications</div>
                            <button onclick="linkKingsChat()" class="modern-button modern-button-primary" style="margin-top:0.6rem;font-size:0.8rem;"><i class="fas fa-link modern-button-icon"></i>Link KingsChat</button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// KingsChat linking functions
function linkKingsChat() {
    // Generate a one-time link token
    fetch('<?php echo BASE_URL; ?>/kc_prepare_link.php', {
        method: 'POST',
        credentials: 'same-origin'
    })
    .then(function(r){ return r.json(); })
    .then(function(resp){
        if (resp && resp.ok && resp.token) {
            kcOpenPopup({
                mode: 'link',
                returnTo: '<?php echo BASE_URL; ?>/profile.php?lt=' + encodeURIComponent(resp.token)
            });
        } else {
            kcOpenPopup({
                mode: 'link',
                returnTo: '<?php echo BASE_URL; ?>/profile.php'
            });
        }
    }).catch(function(){
        kcOpenPopup({
            mode: 'link',
            returnTo: '<?php echo BASE_URL; ?>/profile.php'
        });
    });
}

function unlinkKingsChat() {
    Swal.fire({
        title: 'Unlink KingsChat?',
        text: 'Are you sure you want to unlink your KingsChat account? You will need to link it again to use KingsChat login.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, unlink it',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#dc2626',
        cancelButtonColor: '#6b7280'
    }).then((result) => {
        if (result.isConfirmed) {
            console.log('[KC_UNLINK] Sending unlink request...');
            fetch('<?php echo BASE_URL; ?>/kc_unlink_account.php', {
                method: 'POST',
                credentials: 'same-origin'
            })
            .then(function(r){
                console.log('[KC_UNLINK] Response status:', r.status);
                if (!r.ok) {
                    throw new Error('HTTP error ' + r.status);
                }
                return r.json();
            })
            .then(function(resp){
                console.log('[KC_UNLINK] Response:', resp);
                if (resp && resp.ok) {
                    showToast('KingsChat account unlinked successfully', 'success');
                    setTimeout(function() {
                        window.location.reload();
                    }, 1500);
                } else {
                    var errorMsg = resp.message || resp.error || 'Unknown error';
                    console.error('[KC_UNLINK] Failed:', errorMsg);
                    showToast('Failed to unlink: ' + errorMsg, 'error', 5000);
                }
            })
            .catch(function(err){
                console.error('[KC_UNLINK] Request failed:', err);
                showToast('Failed to unlink account. Please check console for details.', 'error', 5000);
            });
        }
    });
}

document.addEventListener('DOMContentLoaded', function() {
    // Password validation
    const newPassword = document.getElementById('new_password');
    const confirmPassword = document.getElementById('confirm_password');

    function validatePassword() {
        if (newPassword.value !== confirmPassword.value) {
            confirmPassword.setCustomValidity("Passwords don't match");
        } else {
            confirmPassword.setCustomValidity('');
        }
    }

    if (newPassword && confirmPassword) {
        newPassword.addEventListener('change', validatePassword);
        confirmPassword.addEventListener('keyup', validatePassword);
    }

    // Region-Zone dependency
    const regionSelect = document.getElementById('region');
    const zoneSelect = document.getElementById('zone');

    if (regionSelect && zoneSelect) {
        regionSelect.addEventListener('change', function() {
            const region = this.value;

            // Clear current options
            zoneSelect.innerHTML = '<option value="">Select Zone</option>';

            // If region is selected, populate zones
            if (region) {
                const zones = <?php echo json_encode($REGIONS_ZONES); ?>[region] || [];
                zones.forEach(function(zone) {
                    const option = document.createElement('option');
                    option.value = zone;
                    option.textContent = zone;
                    zoneSelect.appendChild(option);
                });
            }

        });
    }
});
</script>

<?php
// Close main tag from header
echo '</main></body></html>';
?>
