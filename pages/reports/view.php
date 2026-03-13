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

<div class="modern-container">
    <?php if (isset($_SESSION['success'])): ?>
        <div class="max-w-4xl mx-auto mb-6">
            <div class="bg-success-100 border-l-4 border-success-500 text-success-700 p-4 rounded" role="alert">
                <p class="font-bold">Success!</p>
                <p><?php echo $_SESSION['success']; ?></p>
            </div>
        </div>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>

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
                <a href="edit.php?id=<?php echo $reportId; ?>" class="modern-button modern-button-primary">
                    <i class="fas fa-edit modern-button-icon"></i> Edit Report
                </a>
                <?php endif; ?>
                <a href="../../includes/export_report.php?id=<?php echo $reportId; ?>" class="modern-button modern-button-secondary">
                    <i class="fas fa-file-csv modern-button-icon"></i> Export CSV
                </a>
            </div>
        </div>
    </div>

    <!-- Report Header -->
    <div class="modern-card mb-6">
        <div class="modern-card-header">
            <h2 class="modern-card-title">Report Information</h2>
        </div>
        <div class="modern-card-body">
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
        <div class="modern-card">
            <div class="modern-card-header">
                <h2 class="modern-card-title">Total Copies Report</h2>
            </div>
            <div class="modern-card-body">
                <div class="space-y-4">
                    <div class="flex justify-between items-center">
                        <span class="text-gray-600">Total Copies Sponsored</span>
                        <span class="font-semibold text-xl text-primary-600"><?php echo number_format($report['total_copies']); ?></span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-gray-600">Total Copies Distributed</span>
                        <span class="font-semibold text-xl text-success-600"><?php echo number_format($report['total_distribution']); ?></span>
                    </div>
                    <div class="mt-4 pt-4 border-t border-gray-200">
                        <div class="text-sm text-gray-500">Distribution Rate</div>
                        <div class="mt-1 flex justify-between items-center">
                            <div class="text-lg font-semibold text-primary-600">
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
        <div class="modern-card">
            <div class="modern-card-header">
                <h2 class="modern-card-title">Strategic Income Alerts</h2>
            </div>
            <div class="modern-card-body">
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
    <div class="modern-card mb-6">
        <div class="modern-card-header">
            <h2 class="modern-card-title">Distribution Report on Sub Campaigns</h2>
        </div>
        <div class="modern-card-body">
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
    <div class="modern-card mb-6">
        <div class="modern-card-header">
            <h2 class="modern-card-title">Program Report</h2>
        </div>
        <div class="modern-card-body">
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
    <div class="modern-card mb-6">
        <div class="modern-card-header">
            <h2 class="modern-card-title">Reach and Impact Report</h2>
        </div>
        <div class="modern-card-body">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <div class="bg-gray-50 rounded p-4">
                    <h4 class="text-sm font-medium text-gray-500">Total Copies Distributed</h4>
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

    <!-- Say Yes to Kids Impact -->
    <div class="modern-card mb-6">
        <div class="modern-card-header">
            <h2 class="modern-card-title">Say Yes to Kids Impact</h2>
        </div>
        <div class="modern-card-body">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <div class="bg-gray-50 rounded p-4">
                    <h4 class="text-sm font-medium text-gray-500">Number of Outreaches</h4>
                    <p class="text-xl font-semibold text-gray-900"><?php echo number_format($report['syk_outreaches']); ?></p>
                </div>
                <div class="bg-gray-50 rounded p-4">
                    <h4 class="text-sm font-medium text-gray-500">Elementary Schools Reached</h4>
                    <p class="text-xl font-semibold text-gray-900"><?php echo number_format($report['syk_elementary_schools']); ?></p>
                </div>
                <div class="bg-gray-50 rounded p-4">
                    <h4 class="text-sm font-medium text-gray-500">Orphanages Reached</h4>
                    <p class="text-xl font-semibold text-gray-900"><?php echo number_format($report['syk_orphanages']); ?></p>
                </div>
                <div class="bg-gray-50 rounded p-4">
                    <h4 class="text-sm font-medium text-gray-500">Number of Children Reached</h4>
                    <p class="text-xl font-semibold text-gray-900"><?php echo number_format($report['syk_children_reached']); ?></p>
                </div>
                <div class="bg-gray-50 rounded p-4">
                    <h4 class="text-sm font-medium text-gray-500">Souls (Children) Won</h4>
                    <p class="text-xl font-semibold text-gray-900"><?php echo number_format($report['syk_souls_won']); ?></p>
                </div>
                <div class="bg-gray-50 rounded p-4">
                    <h4 class="text-sm font-medium text-gray-500">Souls Retained</h4>
                    <p class="text-xl font-semibold text-gray-900"><?php echo number_format($report['syk_souls_retained']); ?></p>
                </div>
                <div class="bg-gray-50 rounded p-4">
                    <h4 class="text-sm font-medium text-gray-500">RORK Clubs</h4>
                    <p class="text-xl font-semibold text-gray-900"><?php echo number_format($report['syk_rork_clubs']); ?></p>
                </div>
            </div>
        </div>
    </div>

    <!-- Attachments -->
    <div class="modern-card mb-6">
        <div class="modern-card-header">
            <h2 class="modern-card-title">Attachments</h2>
        </div>
        <div class="modern-card-body">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <?php if ($report['testimonies_file']): ?>
                <div class="bg-gray-50 rounded p-4">
                    <h4 class="text-sm font-medium text-gray-500 mb-2">Testimonies Document</h4>
                     <a href="../../uploads/documents/<?php echo htmlspecialchars($report['testimonies_file']); ?>" 
                       class="modern-button modern-button-secondary"
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
                       class="modern-button modern-button-secondary"
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
    <div class="modern-card">
        <div class="modern-card-header">
            <h2 class="modern-card-title">Pictures Gallery</h2>
        </div>
        <div class="modern-card-body">
            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                <?php foreach ($images as $image): ?>
                <div class="relative aspect-w-1 aspect-h-1">
                    <img src="../../uploads/pictures/<?php echo $image['image_path']; ?>" 
                         alt="Report Image" 
                         class="object-cover rounded-lg shadow-sm hover:opacity-75 transition-opacity cursor-pointer w-full h-full"
                         onclick="viewImage('../../uploads/pictures/<?php echo $image['image_path']; ?>')">
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Show success/error messages with Toastr
            <?php if (isset($_SESSION['success'])): ?>
                showToast('<?php echo addslashes($_SESSION['success']); ?>', 'success');
                <?php unset($_SESSION['success']); ?>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['error'])): ?>
                showToast('<?php echo addslashes($_SESSION['error']); ?>', 'error');
                <?php unset($_SESSION['error']); ?>
            <?php endif; ?>
            
            // Function to view image in a modal
            window.viewImage = function(imageSrc) {
                Swal.fire({
                    imageUrl: imageSrc,
                    imageAlt: 'Report Image',
                    width: 'auto',
                    padding: '1em',
                    showConfirmButton: false,
                    showCloseButton: true,
                    background: '#fff',
                    customClass: {
                        image: 'max-h-[80vh] max-w-full'
                    }
                });
            };
        });
    </script>
</div>

</main>
</body>
</html>
