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

<?php
$complianceRate = $totalZones > 0 ? ($compliantCount / $totalZones) * 100 : 0;
$hasFilters = $filterRegion || $filterYear || $filterStatus;
?>

<nav class="breadcrumb" aria-label="Breadcrumb"><a href="dashboard.php">Statistics</a><span class="sep" aria-hidden="true">›</span><span aria-current="page">Zone compliance</span></nav>
<div class="page-head">
    <div>
        <h1 class="page-title">Zone compliance</h1>
        <p class="page-sub">Which zones are submitting reports and which need a follow-up.</p>
    </div>
</div>

<section class="section" aria-label="Compliance summary">
    <div class="stat-grid">
        <div class="stat"><div class="stat-label">Compliant zones</div><div class="stat-value"><?php echo number_format($compliantCount); ?></div><div class="stat-note">At least one report</div></div>
        <div class="stat"><div class="stat-label">Missing reports</div><div class="stat-value"><?php echo number_format($nonCompliantCount); ?></div><div class="stat-note">No reports yet</div></div>
        <div class="stat"><div class="stat-label">Compliance rate</div><div class="stat-value"><?php echo number_format($complianceRate, 1); ?>%</div><div class="stat-note"><?php echo number_format($compliantCount); ?> of <?php echo number_format($totalZones); ?> zones</div></div>
    </div>
</section>

<form method="GET" class="toolbar">
    <div class="field">
        <label for="region" class="field-label">Region</label>
        <select id="region" name="region" class="input">
            <option value="">All regions</option>
            <?php foreach ($regions as $region): ?>
            <option value="<?php echo htmlspecialchars($region); ?>" <?php echo $filterRegion === $region ? 'selected' : ''; ?>><?php echo htmlspecialchars($region); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field">
        <label for="year" class="field-label">Year</label>
        <select id="year" name="year" class="input">
            <option value="">All years</option>
            <?php foreach ($years as $year): ?>
            <option value="<?php echo htmlspecialchars($year); ?>" <?php echo $filterYear == $year ? 'selected' : ''; ?>><?php echo htmlspecialchars($year); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field">
        <label for="status" class="field-label">Status</label>
        <select id="status" name="status" class="input">
            <option value="">All zones</option>
            <option value="compliant" <?php echo $filterStatus === 'compliant' ? 'selected' : ''; ?>>Compliant</option>
            <option value="non_compliant" <?php echo $filterStatus === 'non_compliant' ? 'selected' : ''; ?>>Missing reports</option>
        </select>
    </div>
    <div class="toolbar-actions">
        <button type="submit" class="btn btn-primary">Apply</button>
        <?php if ($hasFilters): ?><a href="?" class="btn btn-ghost">Clear</a><?php endif; ?>
    </div>
</form>

<div class="table-wrap">
    <table class="data-table">
        <thead><tr>
            <th scope="col">Zone</th><th scope="col">Region</th><th scope="col">Status</th><th scope="col" class="num">Reports</th><th scope="col" class="num">Months submitted</th><th scope="col">Last submission</th>
        </tr></thead>
        <tbody>
            <?php if (empty($zones)): ?>
            <tr>
                <td colspan="6">
                    <div class="empty-state">
                        <div class="empty-state-icon"><i class="fas fa-map-location-dot" aria-hidden="true"></i></div>
                        <?php if ($hasFilters): ?>
                        <div class="empty-state-title">No zones match these filters</div>
                        <a href="?" class="btn btn-secondary">Clear filters</a>
                        <?php else: ?>
                        <div class="empty-state-title">No zones yet</div>
                        <div class="empty-state-text">Add people with zones to start tracking compliance.</div>
                        <a href="users.php" class="btn btn-primary">Go to People</a>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php else: ?>
            <?php foreach ($zones as $zone):
                $isCompliant = ($zone['total_reports'] ?? 0) > 0;
            ?>
            <tr>
                <td style="font-weight:500;color:var(--neutral-900);"><?php echo htmlspecialchars($zone['zone']); ?></td>
                <td class="muted"><?php echo htmlspecialchars($zone['region']); ?></td>
                <td><?php if ($isCompliant): ?><span class="badge badge-success">Compliant</span><?php else: ?><span class="badge badge-warning">Missing reports</span><?php endif; ?></td>
                <td class="num"><?php echo number_format($zone['total_reports'] ?? 0); ?></td>
                <td class="num"><?php echo number_format($zone['months_active'] ?? 0); ?></td>
                <td class="num muted" style="white-space:nowrap;"><?php echo $zone['last_submission'] ? date('M j, Y', strtotime($zone['last_submission'])) : 'Never'; ?></td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require_once '../layouts/footer.php'; ?>
