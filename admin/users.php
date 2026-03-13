<?php
require_once '../config/config.php';
require_once '../includes/auth.php';

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

        $_SESSION['success'] = $action === 'approve' ? 'User approved successfully' : 'User moved to pending successfully';
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

<div class="modern-container">
    <div class="modern-card">
        <div class="modern-card-header">
            <h2 class="modern-card-title">User Management</h2>
            <a href="add-user.php" class="modern-button modern-button-primary">
                <i class="fas fa-user-plus modern-button-icon"></i> Add User
            </a>
        </div>
        <div class="modern-card-body">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                <div class="modern-stats-card">
                    <div class="modern-stats-icon" style="background-color: var(--primary-600);">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="modern-stats-content">
                        <div class="modern-stats-label">Filtered Users</div>
                        <div class="modern-stats-value"><?php echo number_format((int)$userSummary['total_users']); ?></div>
                    </div>
                </div>
                <div class="modern-stats-card">
                    <div class="modern-stats-icon" style="background-color: var(--success-600);">
                        <i class="fas fa-user-check"></i>
                    </div>
                    <div class="modern-stats-content">
                        <div class="modern-stats-label">Active</div>
                        <div class="modern-stats-value"><?php echo number_format((int)$userSummary['active_users']); ?></div>
                    </div>
                </div>
                <div class="modern-stats-card">
                    <div class="modern-stats-icon" style="background-color: var(--warning-500);">
                        <i class="fas fa-user-clock"></i>
                    </div>
                    <div class="modern-stats-content">
                        <div class="modern-stats-label">Pending Approval</div>
                        <div class="modern-stats-value"><?php echo number_format((int)$userSummary['pending_users']); ?></div>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <form method="GET" id="filterForm" class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                <div class="modern-form-group">
                    <label for="search" class="modern-form-label">Search</label>
                    <input type="text" name="search" id="search" value="<?php echo htmlspecialchars($search); ?>"
                           class="modern-form-input"
                           placeholder="Search by username or name...">
                </div>
                <div class="modern-form-group">
                    <label for="role" class="modern-form-label">Role</label>
                    <select name="role" id="role" class="modern-form-input select2">
                        <option value="">All Roles</option>
                        <option value="admin" <?php echo $roleFilter === 'admin' ? 'selected' : ''; ?>>Admin</option>
                        <option value="admin-manager" <?php echo $roleFilter === 'admin-manager' ? 'selected' : ''; ?>>Admin Manager</option>
                        <option value="user" <?php echo $roleFilter === 'user' ? 'selected' : ''; ?>>User</option>
                    </select>
                </div>
                <div class="modern-form-group">
                    <label for="region" class="modern-form-label">Region</label>
                    <select name="region" id="region" class="modern-form-input select2">
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
                    <select name="status" id="status" class="modern-form-input select2">
                        <option value="">All Status</option>
                        <option value="1" <?php echo $statusFilter === 1 ? 'selected' : ''; ?>>Active</option>
                        <option value="0" <?php echo $statusFilter === 0 ? 'selected' : ''; ?>>Pending</option>
                    </select>
                </div>
                <div class="md:col-span-4 flex justify-end space-x-3">
                    <button type="submit" class="modern-button modern-button-primary">
                        <i class="fas fa-search modern-button-icon"></i> Filter
                    </button>
                    <a href="users.php" class="modern-button modern-button-secondary">
                        <i class="fas fa-times modern-button-icon"></i> Clear
                    </a>
                </div>
            </form>

            <div class="mt-4">
                <div class="modern-table-container">
                    <table class="modern-table">
                        <thead>
                            <tr>
                                <th>Username</th>
                                <th>Name</th>
                                <th>Role</th>
                                <th>Region</th>
                                <th>Zone</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $row): ?>
                                <tr>
                                    <td>
                                        <div class="font-medium">
                                            <?php echo htmlspecialchars($row['username']); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars((string)($row['name'] ?? '')); ?>
                                    </td>
                                    <td>
                                        <span class="modern-badge <?php echo $row['role'] === 'admin' ? 'modern-badge-primary' : ($row['role'] === 'admin-manager' ? 'modern-badge-warning' : 'modern-badge-success'); ?>">
                                            <?php echo htmlspecialchars(ucwords(str_replace('-', ' ', $row['role']))); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars((string)($row['region'] ?? '')); ?>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars((string)($row['zone'] ?? '')); ?>
                                    </td>
                                    <td>
                                        <span class="modern-badge <?php echo isset($row['active']) && $row['active'] ? 'modern-badge-success' : 'modern-badge-warning'; ?>">
                                            <?php echo isset($row['active']) && $row['active'] ? 'Active' : 'Pending'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php echo date('M d, Y', strtotime($row['created_at'])); ?>
                                    </td>
                                    <td class="text-right">
                                        <?php if ((int)$row['active'] === 0): ?>
                                        <form method="POST" class="inline-block mr-3">
                                            <input type="hidden" name="user_id" value="<?php echo (int)$row['id']; ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
                                            <input type="hidden" name="action" value="approve">
                                            <button type="submit" class="text-success-600 hover:text-success-800" title="Approve User">
                                                <i class="fas fa-user-check"></i>
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                        <a href="edit-user.php?id=<?php echo $row['id']; ?>" class="text-primary-600 hover:text-primary-800 mr-3">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <button onclick="deleteUser(<?php echo $row['id']; ?>)" class="text-danger-600 hover:text-danger-800">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($users)): ?>
                                <tr>
                                    <td colspan="8" class="text-center py-6">
                                        <div class="flex flex-col items-center justify-center">
                                            <i class="fas fa-users text-4xl text-gray-300 mb-4"></i>
                                            <p class="text-gray-500">No users found matching your criteria.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

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
                
                <!-- Add JavaScript for Select2, SweetAlert2, and Toastr -->
                <script>
                    document.addEventListener('DOMContentLoaded', function() {
                        // Initialize Select2 for all select elements
                        $('.select2').select2({
                            theme: 'default',
                            width: '100%',
                            placeholder: 'Select an option',
                            allowClear: true
                        });
                        
                        // Function to delete user with confirmation
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
    </div>
</div>

<!-- Script moved to the main JavaScript section above -->
</script>

<?php
// Close main tag from header
echo '</main></body></html>';
?>
