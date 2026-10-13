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
/* Page-local: avatar initial, sticky bulk bar, row-action cluster, pagination */
.um-person { display: flex; align-items: center; gap: var(--spacing-3); min-width: 12rem; }
.um-avatar { width: 2rem; height: 2rem; flex-shrink: 0; border-radius: 50%; display: grid; place-items: center; background: var(--mist); color: var(--neutral-800); font-size: var(--text-xs); font-weight: 500; text-transform: uppercase; }
.um-name { display: block; font-weight: 500; color: var(--neutral-900); }
.um-sub { display: block; font-size: var(--text-xs); color: var(--neutral-600); }
.um-bulk-bar { display: none; align-items: center; gap: var(--spacing-2); flex-wrap: wrap; padding: var(--spacing-3) var(--spacing-4); margin-bottom: var(--spacing-3); background: var(--neutral-900); color: #fff; border-radius: var(--radius-lg); }
.um-bulk-bar.visible { display: flex; }
.um-bulk-bar span { flex: 1; font-size: var(--text-sm); font-weight: 500; }
.um-bulk-bar .btn-ghost { color: #fff; }
.um-actions { display: flex; justify-content: flex-end; align-items: center; gap: var(--spacing-1); white-space: nowrap; }
.um-actions .btn-sm { padding: 0 0.625rem; }
.um-pager { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: var(--spacing-3); margin-top: var(--spacing-4); font-size: var(--text-sm); color: var(--neutral-600); }
.um-pager nav { display: flex; gap: var(--spacing-1); flex-wrap: wrap; }
.um-pager .is-current { background: var(--neutral-900); color: #fff; border-color: var(--neutral-900); }
.um-tabs-wrap { overflow-x: auto; }
.tabs .count { margin-left: 0.375rem; font-variant-numeric: tabular-nums; color: var(--neutral-500); }
@media (hover: hover) and (pointer: fine) { .um-bulk-bar .btn-ghost:hover { background: rgba(255, 255, 255, 0.12); } }
</style>

<?php
$roleLabels = ['admin' => 'Administrator', 'admin-manager' => 'Admin Manager', ROLE_RZM => 'RZM'];
$roleBadge  = ['admin' => 'badge-info', 'admin-manager' => 'badge', ROLE_RZM => 'badge'];
$tabBase = $_GET;
unset($tabBase['status'], $tabBase['page']);
$tabHref = function ($status) use ($tabBase) {
    $q = $status === null ? $tabBase : array_merge($tabBase, ['status' => $status]);
    return 'users.php' . (!empty($q) ? '?' . http_build_query($q) : '');
};
$pendingTotal = (int)($navPendingApprovals ?? 0); // computed by layouts/header.php for the nav badge
$hasFilters = $search || $roleFilter || $regionFilter || $statusFilter !== '';
$csrf = Security::generateCSRFToken();
$flashSuccess = $_SESSION['success'] ?? null;
$flashError = $_SESSION['error'] ?? null;
unset($_SESSION['success'], $_SESSION['error']);
?>

<div class="page-head">
    <div>
        <h1 class="page-title">People</h1>
        <p class="page-sub"><span class="num"><?php echo number_format((int)$userSummary['total_users']); ?></span> accounts<?php echo $hasFilters ? ' match these filters' : ''; ?> · <span class="num"><?php echo number_format((int)$userSummary['active_users']); ?></span> active · <span class="num"><?php echo number_format((int)$userSummary['pending_users']); ?></span> awaiting approval</p>
    </div>
    <div class="page-actions">
        <a href="audit_log.php" class="btn btn-ghost"><i class="fas fa-clock-rotate-left" aria-hidden="true"></i> Audit log</a>
        <a href="add-user.php" class="btn btn-primary"><i class="fas fa-plus" aria-hidden="true"></i> Add person</a>
    </div>
</div>

<?php if ($flashSuccess): ?>
<div class="alert alert-success" role="status"><i class="fas fa-check-circle" aria-hidden="true"></i><div><?php echo htmlspecialchars($flashSuccess); ?></div></div>
<?php endif; ?>
<?php if ($flashError): ?>
<div class="alert alert-danger" role="alert"><i class="fas fa-circle-exclamation" aria-hidden="true"></i><div><?php echo htmlspecialchars($flashError); ?></div></div>
<?php endif; ?>

<div class="um-tabs-wrap">
    <nav class="tabs" aria-label="Filter by status">
        <a href="<?php echo htmlspecialchars($tabHref(null)); ?>" class="<?php echo $statusFilter === '' ? 'active' : ''; ?>"<?php echo $statusFilter === '' ? ' aria-current="page"' : ''; ?>>All</a>
        <a href="<?php echo htmlspecialchars($tabHref('0')); ?>" class="<?php echo $statusFilter === 0 ? 'active' : ''; ?>"<?php echo $statusFilter === 0 ? ' aria-current="page"' : ''; ?>>Awaiting approval<span class="count"><?php echo number_format($pendingTotal); ?></span></a>
        <a href="<?php echo htmlspecialchars($tabHref('1')); ?>" class="<?php echo $statusFilter === 1 ? 'active' : ''; ?>"<?php echo $statusFilter === 1 ? ' aria-current="page"' : ''; ?>>Active</a>
    </nav>
</div>

<form method="GET" id="filterForm" class="toolbar" role="search">
    <?php if ($statusFilter !== ''): ?><input type="hidden" name="status" value="<?php echo (int)$statusFilter; ?>"><?php endif; ?>
    <div class="field" style="flex:2 1 14rem;">
        <label for="search" class="field-label">Search</label>
        <input type="search" name="search" id="search" value="<?php echo htmlspecialchars($search); ?>" class="input" placeholder="Name or username">
    </div>
    <div class="field">
        <label for="role" class="field-label">Role</label>
        <select name="role" id="role" class="input">
            <option value="">All roles</option>
            <option value="admin" <?php echo $roleFilter === 'admin' ? 'selected' : ''; ?>>Administrator</option>
            <option value="admin-manager" <?php echo $roleFilter === 'admin-manager' ? 'selected' : ''; ?>>Admin Manager</option>
            <option value="rzm" <?php echo $roleFilter === ROLE_RZM ? 'selected' : ''; ?>>RZM</option>
        </select>
    </div>
    <div class="field">
        <label for="region" class="field-label">Region</label>
        <select name="region" id="region" class="input">
            <option value="">All regions</option>
            <?php foreach ($REGIONS_ZONES as $region => $zones): ?>
            <option value="<?php echo htmlspecialchars($region); ?>" <?php echo $regionFilter === $region ? 'selected' : ''; ?>><?php echo htmlspecialchars($region); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="toolbar-actions">
        <button type="submit" class="btn btn-primary">Filter</button>
        <?php if ($hasFilters): ?><a href="users.php" class="btn btn-ghost">Clear</a><?php endif; ?>
    </div>
</form>

<!-- Bulk action form wraps the table -->
<form method="POST" id="bulkForm">
    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
    <input type="hidden" name="bulk_action" id="bulk_action_input" value="">

    <!-- Bulk action bar (shown when rows are checked) -->
    <div class="um-bulk-bar" id="bulkBar" role="region" aria-label="Bulk actions" aria-live="polite">
        <span id="bulkCount">0 selected</span>
        <button type="button" onclick="submitBulk('bulk_approve')" class="btn btn-secondary btn-sm"><i class="fas fa-user-check" aria-hidden="true"></i> Approve</button>
        <button type="button" onclick="submitBulk('bulk_deactivate')" class="btn btn-secondary btn-sm"><i class="fas fa-user-clock" aria-hidden="true"></i> Move to pending</button>
        <button type="button" onclick="clearSelection()" class="btn btn-ghost btn-sm">Cancel</button>
    </div>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th scope="col" style="width:2.5rem;"><input type="checkbox" id="selectAll" aria-label="Select all on this page"></th>
                    <th scope="col">Person</th>
                    <th scope="col">Role</th>
                    <th scope="col">Region / zone</th>
                    <th scope="col">Status</th>
                    <th scope="col">Joined</th>
                    <th scope="col" style="text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($users)): ?>
                <tr>
                    <td colspan="7">
                        <div class="empty-state">
                            <div class="empty-state-icon"><i class="fas fa-users" aria-hidden="true"></i></div>
                            <?php if ($hasFilters): ?>
                            <div class="empty-state-title">No people match these filters</div>
                            <a href="users.php" class="btn btn-secondary">Clear filters</a>
                            <?php else: ?>
                            <div class="empty-state-title">No people yet</div>
                            <div class="empty-state-text">Add the first person to get started.</div>
                            <a href="add-user.php" class="btn btn-primary">Add person</a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($users as $row):
                    $rid = (int)$row['id'];
                    $isSelf = $rid === (int)$_SESSION['user_id'];
                    $displayName = (string)($row['name'] ?? '') !== '' ? $row['name'] : $row['username'];
                    $isActive = !empty($row['active']);
                ?>
                <tr>
                    <td><input type="checkbox" name="bulk_ids[]" value="<?php echo $rid; ?>" class="row-cb" aria-label="Select <?php echo htmlspecialchars($displayName); ?>"<?php echo $isSelf ? ' disabled title="Cannot select yourself"' : ''; ?>></td>
                    <td>
                        <div class="um-person">
                            <span class="um-avatar" aria-hidden="true"><?php echo htmlspecialchars(mb_substr($displayName, 0, 1)); ?></span>
                            <span><span class="um-name"><?php echo htmlspecialchars($displayName); ?></span><span class="um-sub"><?php echo htmlspecialchars($row['username']); ?></span></span>
                        </div>
                    </td>
                    <td><span class="badge no-dot <?php echo $roleBadge[$row['role']] ?? ''; ?>"><?php echo htmlspecialchars($roleLabels[$row['role']] ?? ucwords(str_replace('-', ' ', $row['role']))); ?></span></td>
                    <td>
                        <?php echo htmlspecialchars((string)($row['region'] ?? '')); ?>
                        <?php if (!empty($row['zone'])): ?><span class="um-sub"><?php echo htmlspecialchars($row['zone']); ?></span><?php endif; ?>
                    </td>
                    <td><span class="badge <?php echo $isActive ? 'badge-success' : 'badge-warning'; ?>"><?php echo $isActive ? 'Active' : 'Awaiting approval'; ?></span></td>
                    <td class="num muted" style="white-space:nowrap;"><?php echo date('M d, Y', strtotime($row['created_at'])); ?></td>
                    <td>
                        <div class="um-actions">
                            <?php if (!$isActive): ?>
                            <button type="submit" form="rowForm<?php echo $rid; ?>" class="btn btn-secondary btn-sm">Approve</button>
                            <?php else: ?>
                            <button type="submit" form="rowForm<?php echo $rid; ?>" class="btn btn-ghost btn-sm" title="Move to pending" aria-label="Move <?php echo htmlspecialchars($displayName); ?> to pending"<?php echo $isSelf ? ' disabled' : ''; ?>><i class="fas fa-user-clock" aria-hidden="true"></i></button>
                            <?php endif; ?>
                            <a href="edit-user.php?id=<?php echo $rid; ?>" class="btn btn-ghost btn-sm" title="Edit" aria-label="Edit <?php echo htmlspecialchars($displayName); ?>"><i class="fas fa-pen" aria-hidden="true"></i></a>
                            <button type="button" onclick="deleteUser(<?php echo $rid; ?>)" class="btn btn-ghost btn-sm" title="Delete" aria-label="Delete <?php echo htmlspecialchars($displayName); ?>"<?php echo $isSelf ? ' disabled' : ''; ?>><i class="fas fa-trash" aria-hidden="true"></i></button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</form>

<?php // Row approve/deactivate forms live outside bulkForm (nested forms are invalid HTML and were merging into it). ?>
<?php foreach ($users as $row): $rid = (int)$row['id']; ?>
<form method="POST" id="rowForm<?php echo $rid; ?>" hidden>
    <input type="hidden" name="user_id" value="<?php echo $rid; ?>">
    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
    <input type="hidden" name="action" value="<?php echo (int)$row['active'] === 0 ? 'approve' : 'deactivate'; ?>">
</form>
<?php endforeach; ?>

<?php if ($totalPages > 1):
    $prevParams = array_merge($_GET, ['page' => $page - 1]);
    $nextParams = array_merge($_GET, ['page' => $page + 1]);
    $start = max(1, min($page - 2, $totalPages - 4));
    $end = min($totalPages, max(5, $page + 2));
?>
<div class="um-pager">
    <div class="num">Showing <?php echo $offset + 1; ?>–<?php echo min($offset + $itemsPerPage, $totalItems); ?> of <?php echo $totalItems; ?></div>
    <nav aria-label="Pagination">
        <?php if ($page > 1): ?><a href="?<?php echo htmlspecialchars(http_build_query($prevParams)); ?>" class="btn btn-secondary btn-sm" aria-label="Previous page"><i class="fas fa-chevron-left" aria-hidden="true"></i></a><?php endif; ?>
        <?php for ($i = $start; $i <= $end; $i++): ?>
        <a href="?<?php echo htmlspecialchars(http_build_query(array_merge($_GET, ['page' => $i]))); ?>" class="btn btn-secondary btn-sm num<?php echo $i === $page ? ' is-current' : ''; ?>"<?php echo $i === $page ? ' aria-current="page"' : ''; ?>><?php echo $i; ?></a>
        <?php endfor; ?>
        <?php if ($page < $totalPages): ?><a href="?<?php echo htmlspecialchars(http_build_query($nextParams)); ?>" class="btn btn-secondary btn-sm" aria-label="Next page"><i class="fas fa-chevron-right" aria-hidden="true"></i></a><?php endif; ?>
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
                title: 'Bulk ' + (action === 'bulk_approve' ? 'approve' : 'move to pending'),
                text: 'Are you sure you want to ' + verb + ' ' + checked.length + ' user' + (checked.length !== 1 ? 's' : '') + '?',
                icon: 'warning',
                showCancelButton: true,
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
                title: 'Delete this person?',
                text: 'This deletes the user and all their data. It cannot be undone.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: 'var(--danger-600)',
                confirmButtonText: 'Delete user',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
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
                    csrfInput.value = '<?php echo $csrf; ?>';

                    form.appendChild(userIdInput);
                    form.appendChild(csrfInput);
                    document.body.appendChild(form);

                    form.submit();
                }
            });
        };
    });
</script>

<?php require_once '../layouts/footer.php'; ?>
