<?php
require_once '../config/config.php';
require_once '../includes/auth.php';
require_once '../includes/audit.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Get user data
$auth = new Auth();
$currentUser = $auth->getUser($_SESSION['user_id']);

// Check if user is admin
if (!isset($currentUser['role']) || $currentUser['role'] !== 'admin') {
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

// Handle user approval/deactivation actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['user_id'], $_POST['action'])) {
    try {
        if (!isset($_POST['csrf_token'])) {
            throw new Exception('Missing CSRF token');
        }
        Security::verifyCSRFToken($_POST['csrf_token']);

        $targetUserId = (int)$_POST['user_id'];
        $action = $_POST['action'];
        if ($targetUserId <= 0) {
            throw new Exception('Invalid user selected');
        }

        if ($targetUserId === (int)$_SESSION['user_id']) {
            throw new Exception('You cannot change your own approval status');
        }

        if (!in_array($action, ['approve', 'deactivate'], true)) {
            throw new Exception('Invalid action');
        }

        $db = new Database();
        $conn = $db->getConnection();
        $newStatus = $action === 'approve' ? 1 : 0;
        $stmt = $conn->prepare("UPDATE users SET active = ? WHERE id = ?");
        $stmt->execute([$newStatus, $targetUserId]);

        // Fetch target user name for audit
        $uStmt = $conn->prepare("SELECT name, username FROM users WHERE id = ?");
        $uStmt->execute([$targetUserId]);
        $tu = $uStmt->fetch(PDO::FETCH_ASSOC);
        $tuName = $tu ? ($tu['name'] ?: $tu['username']) : "#$targetUserId";
        Audit::log(
            $action === 'approve' ? 'approve_user' : 'deactivate_user',
            'user', $targetUserId,
            $action === 'approve' ? "Approved user $tuName" : "Moved user $tuName to pending"
        );

        $_SESSION['success'] = $action === 'approve' ? 'User approved successfully' : 'User moved to pending successfully';
    } catch (Exception $e) {
        $_SESSION['error'] = $e->getMessage();
    }

    $query = $_GET;
    header('Location: users.php' . (!empty($query) ? '?' . http_build_query($query) : ''));
    exit;
}

// Handle bulk approve
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'], $_POST['bulk_ids'])) {
    try {
        if (!isset($_POST['csrf_token'])) throw new Exception('Missing CSRF token');
        Security::verifyCSRFToken($_POST['csrf_token']);

        $bulkAction = $_POST['bulk_action'];
        if (!in_array($bulkAction, ['bulk_approve', 'bulk_deactivate'], true)) {
            throw new Exception('Invalid bulk action');
        }
        $rawIds = (array)$_POST['bulk_ids'];
        $ids = array_filter(array_map('intval', $rawIds), fn($id) => $id > 0 && $id !== (int)$_SESSION['user_id']);
        if (empty($ids)) throw new Exception('No valid users selected');

        $db   = new Database();
        $conn = $db->getConnection();
        $newStatus = $bulkAction === 'bulk_approve' ? 1 : 0;
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $conn->prepare("UPDATE users SET active = ? WHERE id IN ($placeholders)")
             ->execute(array_merge([$newStatus], $ids));

        $verb = $bulkAction === 'bulk_approve' ? 'Bulk approved' : 'Bulk deactivated';
        Audit::log($bulkAction, 'user', null, "$verb " . count($ids) . ' user(s): IDs ' . implode(', ', $ids));

        $_SESSION['success'] = $verb . ' ' . count($ids) . ' user' . (count($ids) !== 1 ? 's' : '') . ' successfully.';
    } catch (Exception $e) {
        $_SESSION['error'] = $e->getMessage();
    }
    $query = $_GET;
    header('Location: users.php' . (!empty($query) ? '?' . http_build_query($query) : ''));
    exit;
}

// Get filters from query parameters
$search = $_GET['search'] ?? '';
$roleFilter = $_GET['role'] ?? '';
$regionFilter = $_GET['region'] ?? '';
$statusFilter = isset($_GET['status']) && $_GET['status'] !== '' ? ($_GET['status'] === '1' ? 1 : 0) : '';

// Pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$itemsPerPage = ITEMS_PER_PAGE;
$offset = ($page - 1) * $itemsPerPage;

// Build query
$db = new Database();
$conn = $db->getConnection();

$where = [];
$params = [];

if ($search) {
    $where[] = "(username LIKE ? OR name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($roleFilter) {
    $where[] = "role = ?";
    $params[] = $roleFilter;
}

if ($regionFilter) {
    $where[] = "region = ?";
    $params[] = $regionFilter;
}

if ($statusFilter !== '') {
    $where[] = "active = ?";
    $params[] = $statusFilter;
}

$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Get total count for pagination
$countStmt = $conn->prepare("SELECT COUNT(*) as total FROM users $whereClause");
$countStmt->execute($params);
$totalItems = $countStmt->fetch()['total'];
$totalPages = ceil($totalItems / $itemsPerPage);

// Filtered status summary
$summaryStmt = $conn->prepare("SELECT
    COUNT(*) AS total_users,
    SUM(CASE WHEN active = 1 THEN 1 ELSE 0 END) AS active_users,
    SUM(CASE WHEN active = 0 THEN 1 ELSE 0 END) AS pending_users
    FROM users $whereClause");
$summaryStmt->execute($params);
$userSummary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: ['total_users' => 0, 'active_users' => 0, 'pending_users' => 0];

// Get users with pagination
$sql = "SELECT * FROM users $whereClause ORDER BY created_at DESC LIMIT ?, ?";
$stmt = $conn->prepare($sql);
$queryParams = array_merge($params, [(int)$offset, (int)$itemsPerPage]);
$stmt->execute($queryParams);
$users = $stmt->fetchAll();

// Include header
require_once '../layouts/header.php';
?>

<style>
.um-page { max-width: 1100px; margin: 0 auto; padding: 2rem 1.25rem 3rem; }
.um-topbar { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:1rem; margin-bottom:1.5rem; }
.um-topbar h1 { font-size:1.125rem; font-weight:700; color:var(--neutral-900); margin:0; }
.um-stats { display:grid; grid-template-columns:repeat(3,1fr); gap:1px; background:var(--neutral-200); border:1px solid var(--neutral-200); border-radius:var(--radius-lg); overflow:hidden; margin-bottom:1.25rem; }
.um-stat { background:#fff; padding:1rem 1.25rem; }
.um-stat-label { font-size:0.75rem; color:var(--neutral-500); margin-bottom:0.2rem; }
.um-stat-value { font-size:1.5rem; font-weight:700; color:var(--neutral-900); }
.um-filter { border:1px solid var(--neutral-200); border-radius:var(--radius-lg); background:#fff; margin-bottom:1.25rem; overflow:hidden; }
.um-filter-head { padding:0.85rem 1.25rem; border-bottom:1px solid var(--neutral-200); display:flex; align-items:center; justify-content:space-between; }
.um-filter-head h2 { font-size:0.875rem; font-weight:600; color:var(--neutral-900); margin:0; text-transform:uppercase; letter-spacing:0.04em; }
.um-filter-body { padding:1.25rem; }
.um-filter-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:0.875rem; }
@media(max-width:768px){ .um-filter-grid { grid-template-columns:repeat(2,1fr); } }
@media(max-width:480px){ .um-filter-grid { grid-template-columns:1fr; } }
/* Bulk action bar */
.um-bulk-bar { display:none; align-items:center; gap:0.75rem; padding:0.65rem 1.25rem; background:var(--primary-50); border-bottom:1px solid var(--primary-200); flex-wrap:wrap; }
.um-bulk-bar.visible { display:flex; }
.um-bulk-bar span { font-size:0.8125rem; font-weight:600; color:var(--primary-800); flex:1; }
/* Empty state */
.um-empty { padding:3rem 1.5rem; text-align:center; }
.um-empty i { font-size:2.5rem; color:var(--neutral-300); margin-bottom:0.75rem; display:block; }
.um-empty p { font-size:0.875rem; color:var(--neutral-500); margin:0 0 1rem; }
</style>

<div class="um-page">
    <div class="um-topbar">
        <h1>User Management</h1>
        <div style="display:flex;gap:0.5rem;flex-wrap:wrap;">
            <a href="audit_log.php" class="modern-button modern-button-secondary" style="font-size:0.8125rem;"><i class="fas fa-history modern-button-icon"></i> Audit Log</a>
            <a href="add-user.php" class="modern-button modern-button-primary" style="font-size:0.8125rem;"><i class="fas fa-user-plus modern-button-icon"></i> Add User</a>
        </div>
    </div>

    <div class="um-stats">
        <div class="um-stat"><div class="um-stat-label">Total Users</div><div class="um-stat-value"><?php echo number_format((int)$userSummary['total_users']); ?></div></div>
        <div class="um-stat"><div class="um-stat-label">Active</div><div class="um-stat-value"><?php echo number_format((int)$userSummary['active_users']); ?></div></div>
        <div class="um-stat"><div class="um-stat-label">Pending Approval</div><div class="um-stat-value"><?php echo number_format((int)$userSummary['pending_users']); ?></div></div>
    </div>

    <div class="um-filter">
        <div class="um-filter-head"><h2>Filters</h2></div>
        <div class="um-filter-body">

            <form method="GET" id="filterForm" class="um-filter-grid">
                <div class="modern-form-group">
                    <label for="search" class="modern-form-label">Search</label>
                    <input type="text" name="search" id="search" value="<?php echo htmlspecialchars($search); ?>"
                           class="modern-form-input"
                           placeholder="Search by username or name...">
                </div>
                <div class="modern-form-group">
                    <label for="role" class="modern-form-label">Role</label>
                    <select name="role" id="role" class="modern-form-input">
                        <option value="">All Roles</option>
                        <option value="admin" <?php echo $roleFilter === 'admin' ? 'selected' : ''; ?>>Admin</option>
                        <option value="admin-manager" <?php echo $roleFilter === 'admin-manager' ? 'selected' : ''; ?>>Admin Manager</option>
                        <option value="user" <?php echo $roleFilter === 'user' ? 'selected' : ''; ?>>RZM</option>
                    </select>
                </div>
                <div class="modern-form-group">
                    <label for="region" class="modern-form-label">Region</label>
                    <select name="region" id="region" class="modern-form-input">
                        <option value="">All Regions</option>
                        <?php foreach ($REGIONS_ZONES as $region => $zones): ?>
                            <option value="<?php echo htmlspecialchars($region); ?>" <?php echo $regionFilter === $region ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($region); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="modern-form-group">
                    <label for="status" class="modern-form-label">Status</label>
                    <select name="status" id="status" class="modern-form-input">
                        <option value="">All Status</option>
                        <option value="1" <?php echo $statusFilter === 1 ? 'selected' : ''; ?>>Active</option>
                        <option value="0" <?php echo $statusFilter === 0 ? 'selected' : ''; ?>>Pending</option>
                    </select>
                </div>
                <div style="display:flex;align-items:flex-end;gap:0.5rem;">
                    <button type="submit" class="modern-button modern-button-primary" style="font-size:0.8125rem;"><i class="fas fa-search modern-button-icon"></i> Filter</button>
                    <a href="users.php" class="modern-button modern-button-secondary" style="font-size:0.8125rem;"><i class="fas fa-times modern-button-icon"></i> Clear</a>
                </div>
            </form>

        </div>
    </div>

    <div class="um-filter">
        <!-- Bulk action form wraps the table -->
        <form method="POST" id="bulkForm">
            <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
            <input type="hidden" name="bulk_action" id="bulk_action_input" value="">

            <!-- Bulk action bar (shown when rows are checked) -->
            <div class="um-bulk-bar" id="bulkBar">
                <span id="bulkCount">0 selected</span>
                <button type="button" onclick="submitBulk('bulk_approve')" class="modern-button modern-button-primary" style="font-size:0.8rem;">
                    <i class="fas fa-user-check modern-button-icon"></i> Approve Selected
                </button>
                <button type="button" onclick="submitBulk('bulk_deactivate')" class="modern-button" style="font-size:0.8rem;background:var(--warning-600);color:#fff;border-color:var(--warning-600);">
                    <i class="fas fa-user-times modern-button-icon"></i> Move to Pending
                </button>
                <button type="button" onclick="clearSelection()" class="modern-button modern-button-secondary" style="font-size:0.8rem;">Cancel</button>
            </div>

        <div style="overflow-x:auto;">
            <table class="modern-table">
                <thead>
                    <tr>
                        <th style="width:2.5rem;"><input type="checkbox" id="selectAll" title="Select all" style="cursor:pointer;"></th>
                        <th>Username</th>
                        <th>Name</th>
                        <th>Role</th>
                        <th>Region / Zone</th>
                        <th>Status</th>
                        <th>Joined</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($users)): ?>
                    <tr>
                        <td colspan="8">
                            <div class="um-empty">
                                <i class="fas fa-users"></i>
                                <?php if ($search || $roleFilter || $regionFilter || $statusFilter !== ''): ?>
                                <p>No users match your current filters.</p>
                                <a href="users.php" class="modern-button modern-button-secondary" style="font-size:0.8125rem;"><i class="fas fa-times modern-button-icon"></i> Clear Filters</a>
                                <?php else: ?>
                                <p>No users yet. Add the first user to get started.</p>
                                <a href="add-user.php" class="modern-button modern-button-primary" style="font-size:0.8125rem;"><i class="fas fa-user-plus modern-button-icon"></i> Add User</a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($users as $row): ?>
                    <tr>
                        <td><input type="checkbox" name="bulk_ids[]" value="<?php echo (int)$row['id']; ?>" class="row-cb" style="cursor:pointer;"
                            <?php echo (int)$row['id'] === (int)$_SESSION['user_id'] ? 'disabled title="Cannot select yourself"' : ''; ?>>
                        </td>
                        <td><div class="font-medium"><?php echo htmlspecialchars($row['username']); ?></div></td>
                        <td><?php echo htmlspecialchars((string)($row['name'] ?? '')); ?></td>
                        <td>
                            <span class="modern-badge <?php echo $row['role'] === 'admin' ? 'modern-badge-primary' : ($row['role'] === 'admin-manager' ? 'modern-badge-warning' : 'modern-badge-success'); ?>">
                                <?php echo $row['role'] === 'user' ? 'RZM' : htmlspecialchars(ucwords(str_replace('-', ' ', $row['role']))); ?>
                            </span>
                        </td>
                        <td>
                            <?php echo htmlspecialchars((string)($row['region'] ?? '')); ?>
                            <?php if (!empty($row['zone'])): ?>
                            <span style="display:block;font-size:0.72rem;color:var(--neutral-400);"><?php echo htmlspecialchars($row['zone']); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="modern-badge <?php echo isset($row['active']) && $row['active'] ? 'modern-badge-success' : 'modern-badge-warning'; ?>">
                                <?php echo isset($row['active']) && $row['active'] ? 'Active' : 'Pending'; ?>
                            </span>
                        </td>
                        <td><?php echo date('M d, Y', strtotime($row['created_at'])); ?></td>
                        <td class="text-right" style="white-space:nowrap;">
                            <?php if ((int)$row['active'] === 0): ?>
                            <form method="POST" class="inline-block mr-2" style="display:inline;">
                                <input type="hidden" name="user_id" value="<?php echo (int)$row['id']; ?>">
                                <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
                                <input type="hidden" name="action" value="approve">
                                <button type="submit" class="modern-button modern-button-primary" style="font-size:0.75rem;padding:0.25rem 0.6rem;" title="Approve User">
                                    <i class="fas fa-user-check"></i> Approve
                                </button>
                            </form>
                            <?php else: ?>
                            <form method="POST" class="inline-block mr-2" style="display:inline;">
                                <input type="hidden" name="user_id" value="<?php echo (int)$row['id']; ?>">
                                <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
                                <input type="hidden" name="action" value="deactivate">
                                <button type="submit" class="modern-button" style="font-size:0.75rem;padding:0.25rem 0.6rem;background:var(--warning-100);color:var(--warning-700);border-color:var(--warning-300);" title="Move to Pending"<?php echo (int)$row['id'] === (int)$_SESSION['user_id'] ? ' disabled' : ''; ?>>
                                    <i class="fas fa-user-times"></i>
                                </button>
                            </form>
                            <?php endif; ?>
                            <a href="edit-user.php?id=<?php echo $row['id']; ?>" class="modern-button modern-button-secondary" style="font-size:0.75rem;padding:0.25rem 0.6rem;" title="Edit">
                                <i class="fas fa-edit"></i>
                            </a>
                            <button onclick="deleteUser(<?php echo $row['id']; ?>)" class="modern-button modern-button-danger" style="font-size:0.75rem;padding:0.25rem 0.6rem;" title="Delete"
                                <?php echo (int)$row['id'] === (int)$_SESSION['user_id'] ? 'disabled' : ''; ?>>
                                <i class="fas fa-trash"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        </form>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                    <div class="mt-6 flex justify-between items-center">
                        <div class="text-sm text-gray-700">
                            Showing <span class="font-medium"><?php echo $offset + 1; ?></span> to
                            <span class="font-medium"><?php echo min($offset + $itemsPerPage, $totalItems); ?></span> of
                            <span class="font-medium"><?php echo $totalItems; ?></span> results
                        </div>
                        <nav class="flex items-center space-x-1" aria-label="Pagination">
                            <?php
                            // Previous page link
                            $prevParams = array_merge($_GET, ['page' => $page - 1]);
                            $nextParams = array_merge($_GET, ['page' => $page + 1]);
                            ?>
                            <?php if ($page > 1): ?>
                                <a href="?<?php echo http_build_query($prevParams); ?>"
                                   class="modern-button modern-button-secondary">
                                    <i class="fas fa-chevron-left text-xs mr-1"></i> Previous
                                </a>
                            <?php endif; ?>

                            <div class="hidden sm:flex space-x-1">
                                <?php
                                // Page numbers
                                $start = max(1, min($page - 2, $totalPages - 4));
                                $end = min($totalPages, max(5, $page + 2));

                                for ($i = $start; $i <= $end; $i++):
                                    $params = array_merge($_GET, ['page' => $i]);
                                ?>
                                    <a href="?<?php echo http_build_query($params); ?>"
                                       class="inline-flex items-center justify-center w-8 h-8 rounded-md border <?php echo $i === $page ? 'border-primary-500 bg-primary-50 text-primary-600' : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50'; ?>">
                                        <?php echo $i; ?>
                                    </a>
                                <?php endfor; ?>
                            </div>

                            <div class="sm:hidden flex items-center px-4 text-sm text-gray-700">
                                Page <?php echo $page; ?> of <?php echo $totalPages; ?>
                            </div>

                            <?php if ($page < $totalPages): ?>
                                <a href="?<?php echo http_build_query($nextParams); ?>"
                                   class="modern-button modern-button-secondary">
                                    Next <i class="fas fa-chevron-right text-xs ml-1"></i>
                                </a>
                            <?php endif; ?>
                        </nav>
                    </div>
                <?php endif; ?>
                
                <script>
                    document.addEventListener('DOMContentLoaded', function() {
                        // ── Bulk selection logic ──
                        const selectAll = document.getElementById('selectAll');
                        const bulkBar  = document.getElementById('bulkBar');
                        const bulkCount = document.getElementById('bulkCount');

                        function updateBulkBar() {
                            const checked = document.querySelectorAll('.row-cb:checked');
                            if (checked.length > 0) {
                                bulkBar.classList.add('visible');
                                bulkCount.textContent = checked.length + ' user' + (checked.length !== 1 ? 's' : '') + ' selected';
                            } else {
                                bulkBar.classList.remove('visible');
                            }
                            if (selectAll) {
                                const all = document.querySelectorAll('.row-cb:not(:disabled)');
                                selectAll.checked = all.length > 0 && checked.length === all.length;
                                selectAll.indeterminate = checked.length > 0 && checked.length < all.length;
                            }
                        }

                        if (selectAll) {
                            selectAll.addEventListener('change', function() {
                                document.querySelectorAll('.row-cb:not(:disabled)').forEach(cb => cb.checked = this.checked);
                                updateBulkBar();
                            });
                        }
                        document.querySelectorAll('.row-cb').forEach(cb => cb.addEventListener('change', updateBulkBar));

                        window.submitBulk = function(action) {
                            const checked = document.querySelectorAll('.row-cb:checked');
                            if (checked.length === 0) { showToast('No users selected.', 'error'); return; }
                            const verb = action === 'bulk_approve' ? 'approve' : 'move to pending';
                            Swal.fire({
                                title: 'Bulk ' + (action === 'bulk_approve' ? 'Approve' : 'Deactivate'),
                                text: 'Are you sure you want to ' + verb + ' ' + checked.length + ' user' + (checked.length !== 1 ? 's' : '') + '?',
                                icon: 'warning',
                                showCancelButton: true,
                                confirmButtonColor: action === 'bulk_approve' ? 'var(--success-600)' : 'var(--warning-600)',
                                confirmButtonText: 'Yes, proceed'
                            }).then(result => {
                                if (result.isConfirmed) {
                                    document.getElementById('bulk_action_input').value = action;
                                    document.getElementById('bulkForm').submit();
                                }
                            });
                        };

                        window.clearSelection = function() {
                            document.querySelectorAll('.row-cb').forEach(cb => cb.checked = false);
                            if (selectAll) selectAll.checked = false;
                            updateBulkBar();
                        };

                        // ── Delete user ──
                        window.deleteUser = function(userId) {
                            Swal.fire({
                                title: 'Are you sure?',
                                text: "This will delete the user and all their data. This action cannot be undone!",
                                icon: 'warning',
                                showCancelButton: true,
                                confirmButtonColor: 'var(--danger-600)',
                                cancelButtonColor: 'var(--gray-500)',
                                confirmButtonText: 'Yes, delete user',
                                cancelButtonText: 'Cancel'
                            }).then((result) => {
                                if (result.isConfirmed) {
                                    // Create a form and submit it
                                    const form = document.createElement('form');
                                    form.method = 'POST';
                                    form.action = 'delete-user.php';
                                    form.style.display = 'none';

                                    const userIdInput = document.createElement('input');
                                    userIdInput.type = 'hidden';
                                    userIdInput.name = 'user_id';
                                    userIdInput.value = userId;

                                    const csrfInput = document.createElement('input');
                                    csrfInput.type = 'hidden';
                                    csrfInput.name = 'csrf_token';
                                    csrfInput.value = '<?php echo Security::generateCSRFToken(); ?>';

                                    form.appendChild(userIdInput);
                                    form.appendChild(csrfInput);
                                    document.body.appendChild(form);

                                    form.submit();
                                }
                            });
                        };
                        
                        // Show success/error messages with Toastr
                        <?php if (isset($_SESSION['success'])): ?>
                            showToast('<?php echo addslashes($_SESSION['success']); ?>', 'success');
                            <?php unset($_SESSION['success']); ?>
                        <?php endif; ?>
                        
                        <?php if (isset($_SESSION['error'])): ?>
                            showToast('<?php echo addslashes($_SESSION['error']); ?>', 'error');
                            <?php unset($_SESSION['error']); ?>
                        <?php endif; ?>
                    });
                </script>
    </div>
</div>

<?php
// Close main tag from header
echo '</main></body></html>';
?>
