<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ini_set('error_log', 'error.log');

require_once 'config/config.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Get user data
$auth = new Auth();
$user = $auth->getUser($_SESSION['user_id']);
if (!$user) {
    // Handle error - redirect to login if user data cannot be retrieved
    session_destroy();
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
// Store minimal, sanitized user data in session
$_SESSION['user_data'] = [
    'id' => (int)$user['id'],
    'username' => htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8'),
    'name' => htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8'),
    'role' => $user['role'],
    'region' => htmlspecialchars((string)($user['region'] ?? ''), ENT_QUOTES, 'UTF-8'),
    'zone' => htmlspecialchars((string)($user['zone'] ?? ''), ENT_QUOTES, 'UTF-8')
];

// Get reports count and recent reports
$db = new Database();
$conn = $db->getConnection();

// Initialize variables
$reportsCount = 0;
$recentReports = [];
$monthlyTotal = 0;
$totalCopies = 0;
$totalDistributions = 0;
$totalSoulsWon = 0;
$totalNewPartners = 0;
$totalRhapsodyCells = 0;
$totalNewChurches = 0;

try {
    $currentYear = date('Y');

    // Get reports count
    if (isset($user['role']) && $user['role'] === 'admin') {
        $reportsQuery = "SELECT COUNT(*) as count FROM reports WHERE YEAR(report_month) = ?";
        $stmt = $conn->prepare($reportsQuery);
        $stmt->execute([$currentYear]);
    } else {
        $reportsQuery = "SELECT COUNT(*) as count FROM reports WHERE user_id = ? AND YEAR(report_month) = ?";
        $stmt = $conn->prepare($reportsQuery);
        $stmt->execute([$_SESSION['user_id'], $currentYear]);
    }
    $result = $stmt->fetch();
    $reportsCount = $result ? ($result['count'] ?? 0) : 0;

    // Get total copies, distributions, and souls won
    if (isset($user['role']) && $user['role'] === 'admin') {
        $stmt = $conn->prepare("
            SELECT 
                COALESCE(SUM(total_copies), 0) as total_copies,
                COALESCE(SUM(total_distribution), 0) as total_distributions,
                COALESCE(SUM(souls_won), 0) as total_souls,
                COALESCE(SUM(new_partners), 0) as total_new_partners,
                COALESCE(SUM(rhapsody_cells), 0) as total_rhapsody_cells,
                COALESCE(SUM(new_churches), 0) as total_new_churches
            FROM reports 
            WHERE YEAR(report_month) = ?
        ");
        $stmt->execute([$currentYear]);
    } else {
        $stmt = $conn->prepare("
            SELECT 
                COALESCE(SUM(total_copies), 0) as total_copies,
                COALESCE(SUM(total_distribution), 0) as total_distributions,
                COALESCE(SUM(souls_won), 0) as total_souls,
                COALESCE(SUM(new_partners), 0) as total_new_partners,
                COALESCE(SUM(rhapsody_cells), 0) as total_rhapsody_cells,
                COALESCE(SUM(new_churches), 0) as total_new_churches
            FROM reports 
            WHERE user_id = ? AND YEAR(report_month) = ?
        ");
        $stmt->execute([$_SESSION['user_id'], $currentYear]);
    }
    $result = $stmt->fetch();
    $totalCopies = $result ? ($result['total_copies'] ?? 0) : 0;
    $totalDistributions = $result ? ($result['total_distributions'] ?? 0) : 0;
    $totalSoulsWon = $result ? ($result['total_souls'] ?? 0) : 0;
    $totalNewPartners = $result ? ($result['total_new_partners'] ?? 0) : 0;
    $totalRhapsodyCells = $result ? ($result['total_rhapsody_cells'] ?? 0) : 0;
    $totalNewChurches = $result ? ($result['total_new_churches'] ?? 0) : 0;

    // Get recent reports - Filtered by current year as well
    if (isset($user['role']) && $user['role'] === 'admin') {
        $recentReportsQuery = "SELECT r.*, COALESCE(u.name, 'Unknown') as name, u.zone 
                              FROM reports r 
                              LEFT JOIN users u ON r.user_id = u.id 
                              WHERE YEAR(r.report_month) = ?
                              ORDER BY r.created_at DESC LIMIT 5";
        $stmt = $conn->prepare($recentReportsQuery);
        $stmt->execute([$currentYear]);
    } else {
        $recentReportsQuery = "SELECT r.*, COALESCE(u.name, 'Unknown') as name, u.zone 
                              FROM reports r 
                              LEFT JOIN users u ON r.user_id = u.id 
                              WHERE r.user_id = ? AND YEAR(r.report_month) = ?
                              ORDER BY r.created_at DESC LIMIT 5";
        $stmt = $conn->prepare($recentReportsQuery);
        $stmt->execute([$_SESSION['user_id'], $currentYear]);
    }
    $recentReports = $stmt->fetchAll() ?: [];

    // Get monthly total
    if (isset($user['role']) && $user['role'] === 'admin') {
        $monthlyQuery = "SELECT COALESCE(SUM(total_copies), 0) as total FROM reports WHERE MONTH(report_month) = MONTH(CURRENT_DATE()) AND YEAR(report_month) = YEAR(CURRENT_DATE())";
        $stmt = $conn->query($monthlyQuery);
    } else {
        $monthlyQuery = "SELECT COALESCE(SUM(total_copies), 0) as total FROM reports WHERE user_id = ? AND MONTH(report_month) = MONTH(CURRENT_DATE()) AND YEAR(report_month) = YEAR(CURRENT_DATE())";
        $stmt = $conn->prepare($monthlyQuery);
        $stmt->execute([$_SESSION['user_id']]);
    }
    $result = $stmt->fetch();
    $monthlyTotal = $result ? ($result['total'] ?? 0) : 0;

} catch (PDOException $e) {
    error_log("Dashboard query error: " . $e->getMessage());
    // Set default values in case of error
    $reportsCount = 0;
    $totalCopies = 0;
    $totalDistributions = 0;
    $totalSoulsWon = 0;
    $totalNewPartners = 0;
    $totalRhapsodyCells = 0;
    $totalNewChurches = 0;
    $recentReports = [];
    $monthlyTotal = 0;
}

// Include header
require_once 'layouts/header.php';
?>

<!-- Main Content -->
<div class="modern-container">
    <!-- Welcome Section -->
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900 mb-2">Welcome, <?php echo htmlspecialchars($user['name'] ?? $user['username']); ?>!</h1>
        <p class="text-gray-600 mb-1">
            <i class="fas fa-map-marker-alt mr-1"></i> 
            <?php echo htmlspecialchars($user['zone'] ?? 'Not Set'); ?> 
            <span class="text-gray-500">(<?php echo htmlspecialchars($user['region'] ?? 'Not Set'); ?>)</span>
        </p>
        <p class="text-gray-600">
            <i class="fas fa-calendar-alt mr-1"></i> 
            Reporting Period: <span class="font-semibold"><?php echo date('Y'); ?></span>
        </p>
    </div>

    <!-- Stats Grid -->
    <div class="modern-grid modern-grid-cols-1 modern-grid-cols-md-3 mb-8">
        <!-- Total Reports Card -->
        <div class="modern-card">
            <div class="modern-stats-card">
                <div class="modern-stats-icon" style="background-color: var(--primary-600);">
                    <i class="fas fa-file-alt"></i>
                </div>
                <div class="modern-stats-content">
                    <div class="modern-stats-label">Total Reports</div>
                    <div class="modern-stats-value"><?php echo $reportsCount; ?></div>
                    <div class="modern-stats-footer">
                        <a href="pages/reports/list.php">
                            View all reports <i class="fas fa-arrow-right ml-1"></i>
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Total Copies -->
        <div class="modern-card">
            <div class="modern-stats-card">
                <div class="modern-stats-icon" style="background-color: var(--primary-800);">
                    <i class="fas fa-copy"></i>
                </div>
                <div class="modern-stats-content">
                    <div class="modern-stats-label">Total Copies Sponsored</div>
                    <div class="modern-stats-value"><?php echo number_format($totalCopies); ?></div>
                </div>
            </div>
        </div>

        <!-- Total Distributions -->
        <div class="modern-card">
            <div class="modern-stats-card">
                <div class="modern-stats-icon" style="background-color: var(--primary-700);">
                    <i class="fas fa-truck"></i>
                </div>
                <div class="modern-stats-content">
                    <div class="modern-stats-label">Total Distributions</div>
                    <div class="modern-stats-value"><?php echo number_format($totalDistributions); ?></div>
                </div>
            </div>
        </div>

        <!-- Total Souls Won -->
        <div class="modern-card">
            <div class="modern-stats-card">
                <div class="modern-stats-icon" style="background-color: var(--danger-500);">
                    <i class="fas fa-heart"></i>
                </div>
                <div class="modern-stats-content">
                    <div class="modern-stats-label">Total Souls Won</div>
                    <div class="modern-stats-value"><?php echo number_format($totalSoulsWon); ?></div>
                </div>
            </div>
        </div>

        <!-- This Month's Copies -->
        <div class="modern-card">
            <div class="modern-stats-card">
                <div class="modern-stats-icon" style="background-color: var(--success-500);">
                    <i class="fas fa-book"></i>
                </div>
                <div class="modern-stats-content">
                    <div class="modern-stats-label">Copies Sponsored This Month</div>
                    <div class="modern-stats-value"><?php echo number_format($monthlyTotal); ?></div>
                </div>
            </div>
        </div>

        <!-- Total New Partners -->
        <div class="modern-card">
            <div class="modern-stats-card">
                <div class="modern-stats-icon" style="background-color: var(--info-500);">
                    <i class="fas fa-handshake"></i>
                </div>
                <div class="modern-stats-content">
                    <div class="modern-stats-label">Total New Partners</div>
                    <div class="modern-stats-value"><?php echo number_format($totalNewPartners); ?></div>
                </div>
            </div>
        </div>

        <!-- Total Rhapsody Cells -->
        <div class="modern-card">
            <div class="modern-stats-card">
                <div class="modern-stats-icon" style="background-color: var(--warning-500);">
                    <i class="fas fa-th-large"></i>
                </div>
                <div class="modern-stats-content">
                    <div class="modern-stats-label">Total Rhapsody Cells</div>
                    <div class="modern-stats-value"><?php echo number_format($totalRhapsodyCells); ?></div>
                </div>
            </div>
        </div>

        <!-- Total New Churches -->
        <div class="modern-card">
            <div class="modern-stats-card">
                <div class="modern-stats-icon" style="background-color: var(--success-600);">
                    <i class="fas fa-church"></i>
                </div>
                <div class="modern-stats-content">
                    <div class="modern-stats-label">Total New Churches</div>
                    <div class="modern-stats-value"><?php echo number_format($totalNewChurches); ?></div>
                </div>
            </div>
        </div>

        <?php if ($user['role'] === 'admin'): ?>
        <!-- Total Users Card -->
        <div class="modern-card">
            <div class="modern-stats-card">
                <div class="modern-stats-icon" style="background-color: var(--primary-700);">
                    <i class="fas fa-users"></i>
                </div>
                <div class="modern-stats-content">
                    <div class="modern-stats-label">Total Users</div>
                    <div class="modern-stats-value">
                        <?php
                            try {
                                $stmt = $conn->query("SELECT COUNT(*) as count FROM users");
                                echo $stmt->fetch()['count'];
                            } catch (PDOException $e) {
                                echo '0';
                            }
                        ?>
                    </div>
                    <div class="modern-stats-footer">
                        <a href="admin/users.php">
                            Manage users <i class="fas fa-arrow-right ml-1"></i>
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Quick Actions -->
    <div class="modern-card mb-8">
        <div class="modern-card-header">
            <h2 class="modern-card-title">Quick Actions</h2>
        </div>
        <div class="modern-card-body">
            <div class="flex flex-wrap gap-3">
                <a href="pages/reports/create.php" class="modern-button modern-button-primary">
                    <i class="fas fa-plus modern-button-icon"></i> Submit Report
                </a>
                <a href="pages/reports/list.php" class="modern-button modern-button-secondary">
                    <i class="fas fa-list modern-button-icon"></i> View All Reports
                </a>
                <?php if ($user['role'] === 'admin'): ?>
                <a href="admin/dashboard.php" class="modern-button modern-button-secondary">
                    <i class="fas fa-chart-bar modern-button-icon"></i> Analytics Dashboard
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Recent Reports -->
    <div class="modern-card">
        <div class="modern-card-header">
            <h2 class="modern-card-title">Recent Reports</h2>
            <a href="pages/reports/create.php" class="modern-button modern-button-primary">
                <i class="fas fa-plus modern-button-icon"></i> Submit Report
            </a>
        </div>
        <div class="modern-table-container">
            <table class="modern-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Total Copies</th>
                        <?php if ($user['role'] === 'admin'): ?>
                        <th>Submitted By</th>
                        <?php endif; ?>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recentReports)): ?>
                    <tr>
                        <td colspan="<?php echo $user['role'] === 'admin' ? '4' : '3'; ?>" class="text-center py-6 text-gray-500">
                            No reports found. <a href="pages/reports/create.php" class="modern-table-action">Submit your first report</a>
                        </td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($recentReports as $report): ?>
                        <tr>
                            <td>
                                <?php echo date('M j, Y', strtotime($report['created_at'])); ?>
                            </td>
                            <td>
                                <?php echo number_format($report['total_copies']); ?>
                            </td>
                            <?php if ($user['role'] === 'admin'): ?>
                            <td>
                                <div class="font-medium"><?php echo htmlspecialchars($report['name']); ?></div>
                                <div class="text-gray-500 text-xs"><?php echo htmlspecialchars($report['zone']); ?></div>
                            </td>
                            <?php endif; ?>
                            <td class="text-right">
                                <a href="pages/reports/view.php?id=<?php echo $report['id']; ?>" class="modern-table-action">View</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Show welcome message with SweetAlert2 for first-time users
        <?php if (isset($_SESSION['first_login']) && $_SESSION['first_login']): ?>
            Swal.fire({
                title: 'Welcome to the Reporting Portal!',
                text: 'Thank you for joining. You can now submit and manage your reports.',
                icon: 'success',
                confirmButtonColor: 'var(--primary-600)',
                confirmButtonText: 'Get Started'
            });
            <?php unset($_SESSION['first_login']); ?>
        <?php endif; ?>
        
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

</main>
</body>
</html>
