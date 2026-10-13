<?php
require_once '../config/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_data'])) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Check if user is admin
if (!isset($_SESSION['user_data']['role']) || $_SESSION['user_data']['role'] !== 'admin') {
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

// Database connection
$db = new Database();
$conn = $db->getConnection();

// Get filter parameters
$filterRegion = isset($_GET['region']) ? $_GET['region'] : '';
$filterYear = isset($_GET['year']) ? $_GET['year'] : '';
$filterStatus = isset($_GET['status']) ? $_GET['status'] : '';

// Get zone compliance data
$query = "SELECT 
    u.zone,
    u.region,
    COUNT(DISTINCT r.id) as total_reports,
    COUNT(DISTINCT DATE_FORMAT(r.report_month, '%Y-%m')) as months_active,
    MAX(r.created_at) as last_submission
    FROM users u
    LEFT JOIN reports r ON u.id = r.user_id";

$whereConditions = ["u.zone IS NOT NULL"];
$params = [];

if (!empty($filterRegion)) {
    $whereConditions[] = "u.region = :region";
    $params[':region'] = $filterRegion;
}

if (!empty($filterYear)) {
    $whereConditions[] = "YEAR(r.report_month) = :year";
    $params[':year'] = $filterYear;
}

$query .= " WHERE " . implode(" AND ", $whereConditions);
$query .= " GROUP BY u.zone, u.region ORDER BY (CASE WHEN COUNT(r.id) > 0 THEN 0 ELSE 1 END), u.region, u.zone";

$stmt = $conn->prepare($query);
$stmt->execute($params);
$zones = $stmt->fetchAll();

// Filter by compliance status if specified
if (!empty($filterStatus)) {
    $zones = array_filter($zones, function($zone) use ($filterStatus) {
        $isCompliant = ($zone['total_reports'] ?? 0) > 0;
        return ($filterStatus === 'compliant' && $isCompliant) || 
               ($filterStatus === 'non_compliant' && !$isCompliant);
    });
}

// Get filter options
$regionsQuery = "SELECT DISTINCT region FROM users WHERE region IS NOT NULL ORDER BY region";
$regions = $conn->query($regionsQuery)->fetchAll(PDO::FETCH_COLUMN);

$yearsQuery = "SELECT DISTINCT YEAR(report_month) as year FROM reports ORDER BY year DESC";
$years = $conn->query($yearsQuery)->fetchAll(PDO::FETCH_COLUMN);

// Calculate statistics
$totalZones = count($zones);
$compliantZones = array_filter($zones, function($zone) {
    return ($zone['total_reports'] ?? 0) > 0;
});
$compliantCount = count($compliantZones);
$nonCompliantCount = $totalZones - $compliantCount;

require_once '../layouts/header.php';
?>

<style>
.zc-page { max-width: 1100px; margin: 0 auto; padding: 2rem 1.25rem 3rem; }
.zc-topbar { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:1rem; margin-bottom:1.5rem; }
.zc-topbar-left h1 { font-size:1.125rem; font-weight:700; color:var(--neutral-900); margin:0 0 0.15rem; }
.zc-topbar-left p { font-size:0.85rem; color:var(--neutral-500); margin:0; }
.zc-stats { display:grid; grid-template-columns:repeat(3,1fr); gap:1px; background:var(--neutral-200); border:1px solid var(--neutral-200); border-radius:var(--radius-lg); overflow:hidden; margin-bottom:1.25rem; }
.zc-stat { background:#fff; padding:1rem 1.25rem; }
.zc-stat-label { font-size:0.75rem; color:var(--neutral-500); margin-bottom:0.2rem; }
.zc-stat-value { font-size:1.5rem; font-weight:700; }
.zc-section { border:1px solid var(--neutral-200); border-radius:var(--radius-lg); background:#fff; margin-bottom:1.25rem; overflow:hidden; }
.zc-section-head { padding:0.85rem 1.25rem; border-bottom:1px solid var(--neutral-200); display:flex; align-items:center; justify-content:space-between; }
.zc-section-head h2 { font-size:0.875rem; font-weight:600; color:var(--neutral-900); margin:0; text-transform:uppercase; letter-spacing:0.04em; }
.zc-section-body { padding:1.25rem; }
.zc-filter-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:0.875rem; }
@media(max-width:768px){ .zc-filter-grid { grid-template-columns:repeat(2,1fr); } }
@media(max-width:480px){ .zc-filter-grid { grid-template-columns:1fr; } }
.zc-table { width:100%; border-collapse:collapse; font-size:0.85rem; }
.zc-table th { padding:0.65rem 1rem; text-align:left; font-size:0.7rem; font-weight:600; color:var(--neutral-500); text-transform:uppercase; letter-spacing:0.04em; border-bottom:1px solid var(--neutral-200); }
.zc-table td { padding:0.75rem 1rem; border-bottom:1px solid var(--neutral-100); color:var(--neutral-800); }
.zc-table tr:last-child td { border-bottom:none; }
.zc-table tbody tr:hover td { background:var(--neutral-50); }
</style>

<div class="zc-page">
    <div class="zc-topbar">
        <div class="zc-topbar-left">
            <h1>Zone Compliance</h1>
            <p>Monitor which zones are submitting reports and which need attention</p>
        </div>
        <a href="dashboard.php" class="modern-button modern-button-secondary" style="font-size:0.8125rem;"><i class="fas fa-arrow-left modern-button-icon"></i> Back to Dashboard</a>
    </div>

    <div class="zc-stats">
        <div class="zc-stat"><div class="zc-stat-label">Total Zones</div><div class="zc-stat-value" style="color:var(--neutral-900);"><?php echo $totalZones; ?></div></div>
        <div class="zc-stat"><div class="zc-stat-label">Compliant Zones</div><div class="zc-stat-value" style="color:var(--success-700);"><?php echo $compliantCount; ?></div></div>
        <div class="zc-stat"><div class="zc-stat-label">Non-Compliant Zones</div><div class="zc-stat-value" style="color:var(--danger-600);"><?php echo $nonCompliantCount; ?></div></div>
    </div>

    <div class="zc-section">
        <div class="zc-section-head"><h2>Filters</h2></div>
        <div class="zc-section-body">
            <form method="GET" class="zc-filter-grid">
                <div class="modern-form-group">
                    <label for="region" class="modern-form-label">Region</label>
                    <select id="region" name="region" class="modern-form-input">
                        <option value="">All Regions</option>
                        <?php foreach ($regions as $region): ?>
                        <option value="<?php echo htmlspecialchars($region); ?>" <?php echo $filterRegion === $region ? 'selected' : ''; ?>><?php echo htmlspecialchars($region); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="modern-form-group">
                    <label for="year" class="modern-form-label">Year</label>
                    <select id="year" name="year" class="modern-form-input">
                        <option value="">All Years</option>
                        <?php foreach ($years as $year): ?>
                        <option value="<?php echo htmlspecialchars($year); ?>" <?php echo $filterYear == $year ? 'selected' : ''; ?>><?php echo htmlspecialchars($year); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="modern-form-group">
                    <label for="status" class="modern-form-label">Status</label>
                    <select id="status" name="status" class="modern-form-input">
                        <option value="">All Zones</option>
                        <option value="compliant" <?php echo $filterStatus === 'compliant' ? 'selected' : ''; ?>>Compliant</option>
                        <option value="non_compliant" <?php echo $filterStatus === 'non_compliant' ? 'selected' : ''; ?>>Non-Compliant</option>
                    </select>
                </div>
                <div style="display:flex;align-items:flex-end;gap:0.5rem;">
                    <button type="submit" class="modern-button modern-button-primary" style="font-size:0.8125rem;">Apply</button>
                    <a href="?" class="modern-button modern-button-secondary" style="font-size:0.8125rem;">Clear</a>
                </div>
            </form>
        </div>
    </div>

    <div class="zc-section">
        <div class="zc-section-head"><h2>Zone Details</h2></div>
        <div style="overflow-x:auto;">
            <table class="zc-table">
                <thead><tr>
                    <th>Zone</th><th>Region</th><th>Status</th><th>Total Reports</th><th>Months Submitted</th><th>Last Submission</th>
                </tr></thead>
                <tbody>
                    <?php if (empty($zones)): ?>
                    <tr>
                        <td colspan="6">
                            <div style="padding:3rem 1.5rem;text-align:center;">
                                <i class="fas fa-map-marker-alt" style="font-size:2rem;color:var(--neutral-300);display:block;margin-bottom:0.75rem;"></i>
                                <?php if ($filterRegion || $filterYear || $filterStatus): ?>
                                <p style="font-size:0.875rem;color:var(--neutral-500);margin:0 0 1rem;">No zones match the selected filters.</p>
                                <a href="?" class="modern-button modern-button-secondary" style="font-size:0.8125rem;"><i class="fas fa-times modern-button-icon"></i> Clear Filters</a>
                                <?php else: ?>
                                <p style="font-size:0.875rem;color:var(--neutral-500);margin:0 0 1rem;">No zones found. Add users with zones to start tracking compliance.</p>
                                <a href="users.php" class="modern-button modern-button-primary" style="font-size:0.8125rem;"><i class="fas fa-user-plus modern-button-icon"></i> Manage Users</a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($zones as $zone):
                        $isCompliant = ($zone['total_reports'] ?? 0) > 0;
                    ?>
                    <tr>
                        <td style="font-weight:500;"><?php echo htmlspecialchars($zone['zone']); ?></td>
                        <td><?php echo htmlspecialchars($zone['region']); ?></td>
                        <td><?php if ($isCompliant): ?><span class="modern-badge modern-badge-success">Compliant</span><?php else: ?><span class="modern-badge modern-badge-danger">Non-Compliant</span><?php endif; ?></td>
                        <td><?php echo number_format($zone['total_reports'] ?? 0); ?></td>
                        <td><?php echo number_format($zone['months_active'] ?? 0); ?></td>
                        <td style="color:var(--neutral-500);"><?php echo $zone['last_submission'] ? date('M j, Y', strtotime($zone['last_submission'])) : '<span style="color:var(--danger-500);">Never</span>'; ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once '../layouts/footer.php'; ?>