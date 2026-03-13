<?php
require_once '../../config/config.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

// Ensure user is logged in
Auth::requireLogin();

// Get user data
$auth = new Auth();
$user = $auth->getUser($_SESSION['user_id']);
$_SESSION['user_data'] = $user;
$isAdmin = Auth::isAdmin();
$isAdminManager = Auth::isAdminManager();

// Get report ID
$reportId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$reportId) {
    header('Location: list.php');
    exit;
}

// Database connection
$db = new Database();
$conn = $db->getConnection();

// Get report data with user information
$reportQuery = "SELECT r.*, u.name, u.region, u.zone 
                FROM reports r 
                LEFT JOIN users u ON r.user_id = u.id 
                WHERE r.id = ?";
$stmt = $conn->prepare($reportQuery);
$stmt->execute([$reportId]);
$report = $stmt->fetch();

// Check if report exists and user has access
if (
    !$report ||
    (!$isAdmin && !$isAdminManager && $report['user_id'] != $_SESSION['user_id']) ||
    ($isAdminManager && ($report['region'] ?? '') !== ($user['region'] ?? ''))
) {
    header('Location: list.php');
    exit;
}

// Get report images
$imagesQuery = "SELECT * FROM report_images WHERE report_id = ?";
$stmt = $conn->prepare($imagesQuery);
$stmt->execute([$reportId]);
$images = $stmt->fetchAll();

// Include header
require_once '../../layouts/header.php';
?>

<div class="container mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <!-- Back button and title -->
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6 space-y-4 sm:space-y-0">
        <div class="flex items-center">
            <a href="list.php" class="mr-4 text-gray-500 hover:text-gray-700">
                <i class="fas fa-arrow-left"></i>
            </a>
            <h1 class="text-2xl font-bold text-gray-900">Report Details</h1>
        </div>
        <div class="flex flex-col sm:flex-row items-start sm:items-center space-y-2 sm:space-y-0 sm:space-x-4 w-full sm:w-auto">
            <div class="text-sm text-gray-500 w-full sm:w-auto">
                Submitted on <?php echo date('F j, Y', strtotime($report['created_at'])); ?>
            </div>
            <div class="flex flex-wrap gap-2 w-full sm:w-auto">
                <?php if ($isAdmin || $isAdminManager || $report['user_id'] == $_SESSION['user_id']): ?>
                <a href="edit.php?id=<?php echo $reportId; ?>" class="w-full sm:w-auto inline-flex items-center justify-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                    <i class="fas fa-edit mr-2"></i> Edit Report
                </a>
                <?php endif; ?>
                <a href="../../includes/export_report.php?id=<?php echo $reportId; ?>" class="w-full sm:w-auto inline-flex items-center justify-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                    <i class="fas fa-file-csv mr-2"></i> Export CSV
                </a>
            </div>
        </div>
    </div>

    <!-- Report Header -->
    <div class="bg-white rounded-lg shadow-sm mb-6">
        <div class="px-4 sm:px-6 py-4">
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                <div>
                    <h3 class="text-sm font-medium text-gray-500">Submitted By</h3>
                    <p class="mt-1 text-lg font-semibold text-gray-900"><?php echo htmlspecialchars($report['name']); ?></p>
                </div>
                <div>
                    <h3 class="text-sm font-medium text-gray-500">Region</h3>
                    <p class="mt-1 text-lg font-semibold text-gray-900"><?php echo htmlspecialchars($report['region']); ?></p>
                </div>
                <div>
                    <h3 class="text-sm font-medium text-gray-500">Zone</h3>
                    <p class="mt-1 text-lg font-semibold text-gray-900"><?php echo htmlspecialchars($report['zone']); ?></p>
                </div>
            </div>
            <div class="mt-4 pt-4 border-t border-gray-200">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <h3 class="text-sm font-medium text-gray-500">Report Month</h3>
                        <p class="mt-1 text-lg font-semibold text-gray-900"><?php echo date('F Y', strtotime($report['report_month'])); ?></p>
                    </div>
                    <div>
                        <h3 class="text-sm font-medium text-gray-500">Submitted On</h3>
                        <p class="mt-1 text-lg font-semibold text-gray-900"><?php echo date('F j, Y', strtotime($report['created_at'])); ?></p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Metrics -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        <!-- Total Copies Report -->
        <div class="bg-white rounded-lg shadow-sm">
            <div class="px-6 py-4">
                <h2 class="text-lg font-semibold text-gray-900 mb-4">Total Copies Report</h2>
                <div class="space-y-4">
                    <div class="flex justify-between items-center">
                        <span class="text-gray-600">Total Copies</span>
                        <span class="font-semibold text-xl text-blue-600"><?php echo number_format($report['total_copies']); ?></span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-gray-600">Total Distribution</span>
                        <span class="font-semibold text-xl text-green-600"><?php echo number_format($report['total_distribution']); ?></span>
                    </div>
                    <div class="mt-4 pt-4 border-t border-gray-200">
                        <div class="text-sm text-gray-500">Distribution Rate</div>
                        <div class="mt-1 flex justify-between items-center">
                            <div class="text-lg font-semibold text-indigo-600">
                                <?php 
                                    $rate = $report['total_copies'] > 0 
                                        ? round(($report['total_distribution'] / $report['total_copies']) * 100, 1) 
                                        : 0;
                                    echo $rate . '%';
                                ?>
                            </div>
                            <div class="text-sm text-gray-500">
                                of total copies distributed
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Strategic Income Alerts -->
        <div class="bg-white rounded-lg shadow-sm">
            <div class="px-6 py-4">
                <h2 class="text-lg font-semibold text-gray-900 mb-4">Strategic Income Alerts</h2>
                <div class="space-y-4">
                    <div class="flex justify-between items-center">
                        <span class="text-gray-600">Wonder Alerts</span>
                        <span class="font-semibold"><?php echo number_format($report['wonder_alerts']); ?></span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-gray-600">Say Yes to Kids Alerts</span>
                        <span class="font-semibold"><?php echo number_format($report['kids_alerts']); ?></span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-gray-600">Language Redemption Missions</span>
                        <span class="font-semibold"><?php echo number_format($report['language_missions']); ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Sub Campaigns -->
    <div class="bg-white rounded-lg shadow-sm mb-6">
        <div class="px-6 py-4">
            <h2 class="text-lg font-semibold text-gray-900 mb-4">Distribution Report on Sub Campaigns</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                <div class="bg-gray-50 rounded p-4">
                    <h4 class="text-sm font-medium text-gray-500">Penetrating with Truth</h4>
                    <p class="text-xl font-semibold text-gray-900"><?php echo number_format($report['penetrating_truth']); ?></p>
                </div>
                <div class="bg-gray-50 rounded p-4">
                    <h4 class="text-sm font-medium text-gray-500">Penetrating with Languages</h4>
                    <p class="text-xl font-semibold text-gray-900"><?php echo number_format($report['penetrating_languages']); ?></p>
                </div>
                <div class="bg-gray-50 rounded p-4">
                    <h4 class="text-sm font-medium text-gray-500">Youth Aglow</h4>
                    <p class="text-xl font-semibold text-gray-900"><?php echo number_format($report['youth_aglow']); ?></p>
                </div>
                <div class="bg-gray-50 rounded p-4">
                    <h4 class="text-sm font-medium text-gray-500">Every Minister Campaign</h4>
                    <p class="text-xl font-semibold text-gray-900"><?php echo number_format($report['minister_campaign']); ?></p>
                </div>
                <div class="bg-gray-50 rounded p-4">
                    <h4 class="text-sm font-medium text-gray-500">Say Yes to Kids</h4>
                    <p class="text-xl font-semibold text-gray-900"><?php echo number_format($report['say_yes_kids']); ?></p>
                </div>
                <div class="bg-gray-50 rounded p-4">
                    <h4 class="text-sm font-medium text-gray-500">No one left Behind</h4>
                    <p class="text-xl font-semibold text-gray-900"><?php echo number_format($report['no_one_left']); ?></p>
                </div>
                <div class="bg-gray-50 rounded p-4">
                    <h4 class="text-sm font-medium text-gray-500">Teevolution</h4>
                    <p class="text-xl font-semibold text-gray-900"><?php echo number_format($report['teevolution']); ?></p>
                </div>
                <div class="bg-gray-50 rounded p-4">
                    <h4 class="text-sm font-medium text-gray-500">Subscriptions</h4>
                    <p class="text-xl font-semibold text-gray-900"><?php echo number_format($report['subscriptions']); ?></p>
                </div>
            </div>
        </div>
    </div>

    <!-- Program Report -->
    <div class="bg-white rounded-lg shadow-sm mb-6">
        <div class="px-6 py-4">
            <h2 class="text-lg font-semibold text-gray-900 mb-4">Program Report</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="bg-gray-50 rounded p-4">
                    <h4 class="text-sm font-medium text-gray-500">Partners Prayer Programs</h4>
                    <p class="text-xl font-semibold text-gray-900"><?php echo number_format($report['prayer_programs']); ?></p>
                </div>
                <div class="bg-gray-50 rounded p-4">
                    <h4 class="text-sm font-medium text-gray-500">Partners Programs</h4>
                    <p class="text-xl font-semibold text-gray-900"><?php echo number_format($report['partner_programs']); ?></p>
                </div>
            </div>
        </div>
    </div>

    <!-- Reach and Impact -->
    <div class="bg-white rounded-lg shadow-sm mb-6">
        <div class="px-6 py-4">
            <h2 class="text-lg font-semibold text-gray-900 mb-4">Reach and Impact Report</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <div class="bg-gray-50 rounded p-4">
                    <h4 class="text-sm font-medium text-gray-500">Total Distribution</h4>
                    <p class="text-xl font-semibold text-gray-900"><?php echo number_format($report['total_distribution']); ?></p>
                </div>
                <div class="bg-gray-50 rounded p-4">
                    <h4 class="text-sm font-medium text-gray-500">Souls Won</h4>
                    <p class="text-xl font-semibold text-gray-900"><?php echo number_format($report['souls_won']); ?></p>
                </div>
                <div class="bg-gray-50 rounded p-4">
                    <h4 class="text-sm font-medium text-gray-500">Rhapsody Outreaches</h4>
                    <p class="text-xl font-semibold text-gray-900"><?php echo number_format($report['rhapsody_outreaches']); ?></p>
                </div>
                <div class="bg-gray-50 rounded p-4">
                    <h4 class="text-sm font-medium text-gray-500">Rhapsody Cells</h4>
                    <p class="text-xl font-semibold text-gray-900"><?php echo number_format($report['rhapsody_cells']); ?></p>
                </div>
                <div class="bg-gray-50 rounded p-4">
                    <h4 class="text-sm font-medium text-gray-500">New Churches</h4>
                    <p class="text-xl font-semibold text-gray-900"><?php echo number_format($report['new_churches']); ?></p>
                </div>
                <div class="bg-gray-50 rounded p-4">
                    <h4 class="text-sm font-medium text-gray-500">New Partners</h4>
                    <p class="text-xl font-semibold text-gray-900"><?php echo number_format($report['new_partners']); ?></p>
                </div>
                <div class="bg-gray-50 rounded p-4">
                    <h4 class="text-sm font-medium text-gray-500">Lingual Cells</h4>
                    <p class="text-xl font-semibold text-gray-900"><?php echo number_format($report['lingual_cells']); ?></p>
                </div>
                <div class="bg-gray-50 rounded p-4">
                    <h4 class="text-sm font-medium text-gray-500">Language Churches</h4>
                    <p class="text-xl font-semibold text-gray-900"><?php echo number_format($report['language_churches']); ?></p>
                </div>
                <div class="bg-gray-50 rounded p-4">
                    <h4 class="text-sm font-medium text-gray-500">Languages Sponsored</h4>
                    <p class="text-xl font-semibold text-gray-900"><?php echo number_format($report['languages_sponsored']); ?></p>
                </div>
                <div class="bg-gray-50 rounded p-4">
                    <h4 class="text-sm font-medium text-gray-500">Distribution Centers</h4>
                    <p class="text-xl font-semibold text-gray-900"><?php echo number_format($report['distribution_centers']); ?></p>
                </div>
                <div class="bg-gray-50 rounded p-4">
                    <h4 class="text-sm font-medium text-gray-500">External Ministers</h4>
                    <p class="text-xl font-semibold text-gray-900"><?php echo number_format($report['external_ministers']); ?></p>
                </div>
            </div>
        </div>
    </div>

    <!-- Attachments -->
    <div class="bg-white rounded-lg shadow-sm mb-6">
        <div class="px-6 py-4">
            <h2 class="text-lg font-semibold text-gray-900 mb-4">Attachments</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <?php if ($report['testimonies_file']): ?>
                <div class="bg-gray-50 rounded p-4">
                    <h4 class="text-sm font-medium text-gray-500 mb-2">Testimonies Document</h4>
                    <a href="../../uploads/documents/<?php echo htmlspecialchars($report['testimonies_file']); ?>" 
                       class="inline-flex items-center text-blue-600 hover:text-blue-500"
                       download>
                        <i class="fas fa-file-alt mr-2"></i>
                        Download Testimonies
                    </a>
                </div>
                <?php endif; ?>

                <?php if ($report['innovations_file']): ?>
                <div class="bg-gray-50 rounded p-4">
                    <h4 class="text-sm font-medium text-gray-500 mb-2">Innovations Document</h4>
                    <a href="../../uploads/documents/<?php echo htmlspecialchars($report['innovations_file']); ?>" 
                       class="inline-flex items-center text-blue-600 hover:text-blue-500"
                       download>
                        <i class="fas fa-lightbulb mr-2"></i>
                        Download Innovations
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Pictures Gallery -->
    <?php if (!empty($images)): ?>
    <div class="bg-white rounded-lg shadow-sm">
        <div class="px-4 sm:px-6 py-4">
            <h2 class="text-lg font-semibold text-gray-900 mb-4">Pictures Gallery</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                <?php foreach ($images as $image): ?>
                <div class="relative aspect-w-1 aspect-h-1">
                    <img src="../../uploads/pictures/<?php echo $image['image_path']; ?>" 
                         alt="Report Image" 
                         class="object-cover rounded-lg shadow-sm hover:opacity-75 transition-opacity cursor-pointer w-full h-full"
                         onclick="window.open('../../uploads/pictures/<?php echo $image['image_path']; ?>', '_blank')">
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

</main>
</body>
</html>
