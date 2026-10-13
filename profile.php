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
            $role = $_POST['role'] ?? '';
            $region = trim($_POST['region'] ?? '');
            $zone = trim($_POST['zone'] ?? '');
            if (!in_array($role, [ROLE_ADMIN, ROLE_ADMIN_MANAGER, ROLE_RZM], true)) {
                throw new Exception("Invalid role");
            }
            
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

<?php
$roleLabels = ['admin' => 'Administrator', 'admin-manager' => 'Admin Manager', ROLE_RZM => 'RZM'];
$roleLabel  = $roleLabels[$user['role'] ?? ''] ?? ucfirst((string)($user['role'] ?? ''));
$flashError = $_SESSION['error'] ?? null;
unset($_SESSION['error']);
$csrfField  = '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
require_once __DIR__ . '/includes/kc_link.php';
$isKcLinked = kc_is_user_linked((int)$_SESSION['user_id']);
$kcUserId = $isKcLinked ? kc_get_kc_by_user_id((int)$_SESSION['user_id']) : null;
?>

<style>
/* Profile: two-column settings layout (page-local). */
.pf-grid { display: grid; gap: var(--spacing-6); grid-template-columns: minmax(0, 1fr); align-items: start; }
@media (min-width: 900px) { .pf-grid { grid-template-columns: 18rem minmax(0, 1fr); } .pf-identity { position: sticky; top: calc(var(--header-height) + var(--spacing-6)); } }
.pf-avatar { width: 3.5rem; height: 3.5rem; border-radius: var(--radius-full); background: var(--mist); color: var(--ink); display: grid; place-items: center; font-size: var(--text-lg); font-weight: 500; margin-bottom: var(--spacing-4); }
.pf-name { font-size: var(--text-lg); font-weight: 500; color: var(--ink); overflow-wrap: anywhere; }
.pf-panels { display: flex; flex-direction: column; gap: var(--spacing-6); }
.pf-panels .form-actions { margin-top: var(--spacing-6); }
.pf-sub { font-size: var(--text-sm); font-weight: 500; color: var(--ink); margin: var(--spacing-6) 0 var(--spacing-4); padding-top: var(--spacing-6); border-top: 1px solid var(--hairline); }
.pf-kc { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: var(--spacing-4); }
.pf-kc-status { display: flex; align-items: center; gap: var(--spacing-3); }
.pf-kc-status img { width: 2rem; height: 2rem; object-fit: contain; border-radius: var(--radius); border: 1px solid var(--hairline); padding: 0.2rem; background: #fff; }
</style>

<nav class="breadcrumb" aria-label="Breadcrumb">
    <a href="<?php echo BASE_URL; ?>/dashboard.php">Home</a><span class="sep">/</span><span>My account</span>
</nav>
<div class="page-head">
    <div>
        <div class="page-eyebrow">Settings</div>
        <h1 class="page-title">My account</h1>
        <p class="page-sub">Your profile, password and KingsChat sign-in.</p>
    </div>
</div>

<?php if (isset($_SESSION['success'])): ?>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        showAlert('Success', '<?php echo addslashes($_SESSION['success']); ?>', 'success');
    });
</script>
<?php unset($_SESSION['success']); ?>
<?php endif; ?>

<div class="pf-grid">
    <!-- Identity -->
    <aside class="panel pf-identity" aria-label="Account summary">
        <div class="panel-body">
            <div class="pf-avatar" aria-hidden="true"><?php echo htmlspecialchars(strtoupper(mb_substr($user['name'] ?? 'U', 0, 1))); ?></div>
            <div class="pf-name"><?php echo htmlspecialchars($user['name'] ?? ''); ?></div>
            <div class="muted" style="font-size:var(--text-sm);">@<?php echo htmlspecialchars($user['username'] ?? ''); ?></div>
            <div style="margin-top:var(--spacing-3);display:flex;gap:var(--spacing-2);flex-wrap:wrap;">
                <span class="badge badge-info no-dot"><?php echo htmlspecialchars($roleLabel); ?></span>
                <span class="badge <?php echo $user['active'] ? 'badge-success' : 'badge-warning'; ?>"><?php echo $user['active'] ? 'Active' : 'Pending'; ?></span>
            </div>
            <dl class="kv" style="margin-top:var(--spacing-5);">
                <dt>Region</dt><dd><?php echo htmlspecialchars($user['region'] ?: 'Not assigned'); ?></dd>
                <dt>Zone</dt><dd><?php echo htmlspecialchars($user['zone'] ?: 'Not assigned'); ?></dd>
                <dt>Member since</dt><dd><?php echo htmlspecialchars(date('M j, Y', strtotime($user['created_at']))); ?></dd>
            </dl>
            <?php if (!Auth::isAdmin()): ?>
            <p class="field-help" style="margin-top:var(--spacing-4);">Contact an administrator to change your role, region or zone.</p>
            <?php endif; ?>
        </div>
    </aside>

    <div class="pf-panels">
        <?php if ($flashError): ?>
        <div class="alert alert-danger" role="alert" style="margin-bottom:0;">
            <i class="fas fa-circle-exclamation" aria-hidden="true"></i>
            <div><div class="alert-title">Changes not saved</div><?php echo htmlspecialchars($flashError); ?></div>
        </div>
        <?php endif; ?>

        <!-- Profile details -->
        <section class="panel" aria-labelledby="pf-details">
            <div class="panel-head"><h2 id="pf-details" class="section-title">Profile details</h2></div>
            <div class="panel-body">
                <form method="POST" class="needs-validation" novalidate>
                    <?php echo $csrfField; ?>
                    <div class="form-grid">
                        <div class="field">
                            <label for="name" class="field-label">Full name</label>
                            <input type="text" id="name" name="name" class="input" value="<?php echo htmlspecialchars($user['name'] ?? ''); ?>" required autocomplete="name">
                        </div>
                        <div class="field">
                            <label for="username" class="field-label">Username</label>
                            <input type="text" id="username" name="username" class="input" value="<?php echo htmlspecialchars($user['username'] ?? ''); ?>" required autocomplete="username">
                        </div>
                    </div>

                    <?php if (Auth::isAdmin()): ?>
                    <h3 class="pf-sub">Role &amp; assignment</h3>
                    <input type="hidden" name="update_role" value="1">
                    <div class="form-grid">
                        <div class="field">
                            <label for="role" class="field-label">Role</label>
                            <select id="role" name="role" class="input">
                                <option value="admin" <?php echo ($user['role'] ?? '') === 'admin' ? 'selected' : ''; ?>>Admin</option>
                                <option value="admin-manager" <?php echo ($user['role'] ?? '') === 'admin-manager' ? 'selected' : ''; ?>>Admin Manager</option>
                                <option value="rzm" <?php echo ($user['role'] ?? '') === ROLE_RZM ? 'selected' : ''; ?>>RZM</option>
                            </select>
                        </div>
                        <div class="field">
                            <label for="region" class="field-label">Region</label>
                            <select id="region" name="region" class="input">
                                <option value="">Select region</option>
                                <?php foreach ($REGIONS_ZONES as $region => $zones): ?>
                                <option value="<?php echo htmlspecialchars($region); ?>" <?php echo ($user['region'] ?? '') === $region ? 'selected' : ''; ?>><?php echo htmlspecialchars($region); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label for="zone" class="field-label">Zone</label>
                            <select id="zone" name="zone" class="input">
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
                    <?php endif; ?>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary" data-loading-text="Saving…">Save changes</button>
                    </div>
                </form>
            </div>
        </section>

        <!-- Change password -->
        <section class="panel" aria-labelledby="pf-password">
            <div class="panel-head"><h2 id="pf-password" class="section-title">Change password</h2></div>
            <div class="panel-body">
                <form method="POST" class="needs-validation" novalidate>
                    <?php echo $csrfField; ?>
                    <input type="hidden" name="name" value="<?php echo htmlspecialchars($user['name'] ?? ''); ?>">
                    <input type="hidden" name="username" value="<?php echo htmlspecialchars($user['username'] ?? ''); ?>">
                    <div class="field">
                        <label for="current_password" class="field-label">Current password</label>
                        <input type="password" id="current_password" name="current_password" class="input" required autocomplete="current-password">
                    </div>
                    <div class="form-grid">
                        <div class="field">
                            <label for="new_password" class="field-label">New password</label>
                            <input type="password" id="new_password" name="new_password" class="input" required minlength="8" autocomplete="new-password" aria-describedby="new_password_help">
                            <p class="field-help" id="new_password_help">At least 8 characters.</p>
                        </div>
                        <div class="field">
                            <label for="confirm_password" class="field-label">Confirm new password</label>
                            <input type="password" id="confirm_password" name="confirm_password" class="input" required autocomplete="new-password">
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-secondary" data-loading-text="Updating…">Update password</button>
                    </div>
                </form>
            </div>
        </section>

        <!-- KingsChat -->
        <section class="panel" aria-labelledby="pf-kc">
            <div class="panel-head"><h2 id="pf-kc" class="section-title">KingsChat</h2></div>
            <div class="panel-body pf-kc">
                <div class="pf-kc-status">
                    <img src="<?php echo BASE_URL; ?>/assets/images/kingschat-logo.png" alt="">
                    <div>
                        <span class="badge <?php echo $isKcLinked ? 'badge-success' : 'badge-warning'; ?>"><?php echo $isKcLinked ? 'Connected' : 'Not connected'; ?></span>
                        <div class="muted" style="font-size:var(--text-sm);margin-top:var(--spacing-1);"><?php echo $isKcLinked ? 'You can sign in with KingsChat.' : 'Link your account for one-tap sign-in.'; ?></div>
                        <?php if ($isKcLinked && $kcUserId): ?><div class="meta" style="margin-top:var(--spacing-1);overflow-wrap:anywhere;">ID <?php echo htmlspecialchars($kcUserId); ?></div><?php endif; ?>
                    </div>
                </div>
                <?php if ($isKcLinked): ?>
                <button type="button" onclick="unlinkKingsChat()" class="btn btn-danger btn-sm"><i class="fas fa-unlink" aria-hidden="true"></i> Unlink</button>
                <?php else: ?>
                <button type="button" onclick="linkKingsChat()" class="btn btn-secondary btn-sm"><i class="fas fa-link" aria-hidden="true"></i> Link KingsChat</button>
                <?php endif; ?>
            </div>
        </section>
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
        confirmButtonColor: 'var(--danger-600)',
        cancelButtonColor: 'var(--neutral-500)'
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
