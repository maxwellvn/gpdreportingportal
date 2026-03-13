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

<div class="container mx-auto px-4 py-8">
    <!-- Header -->
    <div class="mb-8">
        <h1 class="text-2xl font-bold text-gray-900 mb-2">Zone Compliance</h1>
        <p class="text-gray-600">Monitor which zones are submitting reports and which need attention</p>
    </div>

    <!-- Summary Stats -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
        <div class="bg-white p-6 rounded-lg shadow">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Total Zones</h3>
            <p class="text-3xl font-bold text-blue-600"><?php echo $totalZones; ?></p>
        </div>
        <div class="bg-white p-6 rounded-lg shadow">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Compliant Zones</h3>
            <p class="text-3xl font-bold text-green-600"><?php echo $compliantCount; ?></p>
            <p class="text-sm text-gray-500">Zones with reports</p>
        </div>
        <div class="bg-white p-6 rounded-lg shadow">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Non-Compliant Zones</h3>
            <p class="text-3xl font-bold text-red-600"><?php echo $nonCompliantCount; ?></p>
            <p class="text-sm text-gray-500">Zones without reports</p>
        </div>
    </div>

    <!-- Filters -->
    <div class="bg-white p-6 rounded-lg shadow mb-8">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">Filters</h3>
        <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div>
                <label for="region" class="block text-sm font-medium text-gray-700 mb-1">Region</label>
                <select id="region" name="region" class="w-full border border-gray-300 rounded-md px-3 py-2">
                    <option value="">All Regions</option>
                    <?php foreach ($regions as $region): ?>
                    <option value="<?php echo htmlspecialchars($region); ?>" <?php echo $filterRegion === $region ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($region); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="year" class="block text-sm font-medium text-gray-700 mb-1">Year</label>
                <select id="year" name="year" class="w-full border border-gray-300 rounded-md px-3 py-2">
                    <option value="">All Years</option>
                    <?php foreach ($years as $year): ?>
                    <option value="<?php echo htmlspecialchars($year); ?>" <?php echo $filterYear == $year ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($year); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="status" class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                <select id="status" name="status" class="w-full border border-gray-300 rounded-md px-3 py-2">
                    <option value="">All Zones</option>
                    <option value="compliant" <?php echo $filterStatus === 'compliant' ? 'selected' : ''; ?>>Compliant</option>
                    <option value="non_compliant" <?php echo $filterStatus === 'non_compliant' ? 'selected' : ''; ?>>Non-Compliant</option>
                </select>
            </div>
            <div class="flex items-end space-x-2">
                <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700">
                    Apply Filters
                </button>
                <a href="?" class="bg-gray-300 text-gray-700 px-4 py-2 rounded-md hover:bg-gray-400">
                    Clear
                </a>
            </div>
        </form>
    </div>

    <!-- Zone List -->
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-lg font-semibold text-gray-900">Zone Details</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Zone</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Region</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total Reports</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Months Submitted</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Last Submission</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($zones as $zone): 
                        $isCompliant = ($zone['total_reports'] ?? 0) > 0;
                        $statusClass = $isCompliant ? 'text-green-600 bg-green-100' : 'text-red-600 bg-red-100';
                        $statusText = $isCompliant ? 'Compliant' : 'Non-Compliant';
                    ?>
                    <tr>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                            <?php echo htmlspecialchars($zone['zone']); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                            <?php echo htmlspecialchars($zone['region']); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full <?php echo $statusClass; ?>">
                                <?php echo $statusText; ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                            <?php echo number_format($zone['total_reports'] ?? 0); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                            <?php echo number_format($zone['months_active'] ?? 0); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                            <?php 
                            if ($zone['last_submission']) {
                                echo date('M j, Y', strtotime($zone['last_submission']));
                            } else {
                                echo 'Never';
                            }
                            ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Back to Dashboard -->
    <div class="mt-8">
        <a href="dashboard.php" class="bg-gray-600 text-white px-4 py-2 rounded-md hover:bg-gray-700">
            Back to Dashboard
        </a>
    </div>
</div>

<?php require_once '../layouts/footer.php'; ?>