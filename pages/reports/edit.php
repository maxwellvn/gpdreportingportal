<?php
require_once '../../config/config.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

// Ensure user is logged in
Auth::requireLogin();

// Get report ID
$reportId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$reportId) {
    header('Location: list.php');
    exit;
}

// Database connection
$db = new Database();
$conn = $db->getConnection();
$auth = new Auth();

// Get report data with user information
$reportQuery = "SELECT r.*, u.username, u.region, u.zone
                FROM reports r
                LEFT JOIN users u ON r.user_id = u.id
                WHERE r.id = ?";
$stmt = $conn->prepare($reportQuery);
$stmt->execute([$reportId]);
$report = $stmt->fetch();
$currentUser = $auth->getUser($_SESSION['user_id']);
$_SESSION['user_data'] = $currentUser;
$isAdmin = Auth::isAdmin();
$isAdminManager = Auth::isAdminManager();

// Check if report exists and user has permission to edit
if (
    !$report ||
    (!$isAdmin && !$isAdminManager && $report['user_id'] != $_SESSION['user_id']) ||
    ($isAdminManager && ($report['region'] ?? '') !== ($currentUser['region'] ?? ''))
) {
    $_SESSION['error'] = "You don't have permission to edit this report.";
    header('Location: list.php');
    exit;
}

// Get report images
$imagesQuery = "SELECT * FROM report_images WHERE report_id = ?";
$stmt = $conn->prepare($imagesQuery);
$stmt->execute([$reportId]);
$images = $stmt->fetchAll();

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $conn->beginTransaction();

        // CSRF validation for any modifications
        if (!isset($_POST['csrf_token'])) {
            throw new Exception('Missing CSRF token');
        }
        Security::verifyCSRFToken($_POST['csrf_token']);

        // Update report data
        $updateQuery = "UPDATE reports SET
            report_month = :report_month,
            total_copies = :total_copies,
            wonder_alerts = :wonder_alerts,
            kids_alerts = :kids_alerts,
            language_missions = :language_missions,
            penetrating_truth = :penetrating_truth,
            penetrating_languages = :penetrating_languages,
            youth_aglow = :youth_aglow,
            minister_campaign = :minister_campaign,
            say_yes_kids = :say_yes_kids,
            no_one_left = :no_one_left,
            teevolution = :teevolution,
            subscriptions = :subscriptions,
            prayer_programs = :prayer_programs,
            partner_programs = :partner_programs,
            total_distribution = :total_distribution,
            souls_won = :souls_won,
            rhapsody_outreaches = :rhapsody_outreaches,
            rhapsody_cells = :rhapsody_cells,
            new_churches = :new_churches,
            new_partners = :new_partners,
            lingual_cells = :lingual_cells,
            language_churches = :language_churches,
            languages_sponsored = :languages_sponsored,
            distribution_centers = :distribution_centers,
            external_ministers = :external_ministers,
            syk_outreaches = :syk_outreaches,
            syk_elementary_schools = :syk_elementary_schools,
            syk_orphanages = :syk_orphanages,
            syk_children_reached = :syk_children_reached,
            syk_souls_won = :syk_souls_won,
            syk_souls_retained = :syk_souls_retained,
            syk_rork_clubs = :syk_rork_clubs,
            updated_at = NOW()
            WHERE id = :id" . (!$isAdmin && !$isAdminManager ? " AND user_id = :user_id" : "");

        $stmt = $conn->prepare($updateQuery);

        // Function to safely convert form values to integers
        function safeIntVal($value, $default = 0) {
            if ($value === null) {
                return $default;
            }

            if (is_string($value)) {
                $value = trim(str_replace([',', ' '], '', $value));
            }

            if ($value === '' || !is_numeric($value)) {
                return $default;
            }

            return (int)$value;
        }

        // Validate and format the date
        $reportYear = $_POST['report_year'] ?? date('Y');
        $reportMonth = $_POST['report_month'] ?? date('m');

        // Ensure we have valid numeric values
        if (!is_numeric($reportYear) || !is_numeric($reportMonth)) {
            throw new Exception("Invalid date format. Please select a valid month and year.");
        }

        // Create a properly formatted date string
        $dateString = sprintf('%04d-%02d-01', (int)$reportYear, (int)$reportMonth);

        // Validate that it's not a future date
        $selectedDate = new DateTime($dateString);
        $currentDate = new DateTime();
        $currentDate->setDate($currentDate->format('Y'), $currentDate->format('m'), 1);

        if ($selectedDate > $currentDate) {
            throw new Exception("You cannot select a future month for your report.");
        }

        // Prevent duplicate report month for the same user
        $duplicateCheckQuery = "SELECT id FROM reports
                                WHERE user_id = :user_id
                                  AND DATE_FORMAT(report_month, '%Y-%m') = :report_month
                                  AND id != :id
                                LIMIT 1";
        $duplicateStmt = $conn->prepare($duplicateCheckQuery);
        $duplicateStmt->execute([
            ':user_id' => $report['user_id'],
            ':report_month' => sprintf('%04d-%02d', (int)$reportYear, (int)$reportMonth),
            ':id' => $reportId
        ]);
        if ($duplicateStmt->fetch()) {
            $dupeDate = DateTime::createFromFormat('Y-m', $reportYear . '-' . $reportMonth);
            $dupeLabel = $dupeDate ? $dupeDate->format('F Y') : ($reportYear . '-' . $reportMonth);
            throw new Exception("A report for " . $dupeLabel . " already exists for this RZM.");
        }

        // Bind parameters
        $params = [
            ':id' => $reportId,
            ':report_month' => $dateString,
            ':total_copies' => safeIntVal($_POST['total_copies'] ?? '', $report['total_copies']),
            ':wonder_alerts' => safeIntVal($_POST['wonder_alerts'] ?? '', $report['wonder_alerts']),
            ':kids_alerts' => safeIntVal($_POST['kids_alerts'] ?? '', $report['kids_alerts']),
            ':language_missions' => safeIntVal($_POST['language_missions'] ?? '', $report['language_missions']),
            ':penetrating_truth' => safeIntVal($_POST['penetrating_truth'] ?? '', $report['penetrating_truth']),
            ':penetrating_languages' => safeIntVal($_POST['penetrating_languages'] ?? '', $report['penetrating_languages']),
            ':youth_aglow' => safeIntVal($_POST['youth_aglow'] ?? '', $report['youth_aglow']),
            ':minister_campaign' => safeIntVal($_POST['minister_campaign'] ?? '', $report['minister_campaign']),
            ':say_yes_kids' => safeIntVal($_POST['say_yes_kids'] ?? '', $report['say_yes_kids']),
            ':no_one_left' => safeIntVal($_POST['no_one_left'] ?? '', $report['no_one_left']),
            ':teevolution' => safeIntVal($_POST['teevolution'] ?? '', $report['teevolution']),
            ':subscriptions' => safeIntVal($_POST['subscriptions'] ?? '', $report['subscriptions']),
            ':prayer_programs' => safeIntVal($_POST['prayer_programs'] ?? '', $report['prayer_programs']),
            ':partner_programs' => safeIntVal($_POST['partner_programs'] ?? '', $report['partner_programs']),
            ':total_distribution' => safeIntVal($_POST['total_distribution'] ?? '', $report['total_distribution']),
            ':souls_won' => safeIntVal($_POST['souls_won'] ?? '', $report['souls_won']),
            ':rhapsody_outreaches' => safeIntVal($_POST['rhapsody_outreaches'] ?? '', $report['rhapsody_outreaches']),
            ':rhapsody_cells' => safeIntVal($_POST['rhapsody_cells'] ?? '', $report['rhapsody_cells']),
            ':new_churches' => safeIntVal($_POST['new_churches'] ?? '', $report['new_churches']),
            ':new_partners' => safeIntVal($_POST['new_partners'] ?? '', $report['new_partners']),
            ':lingual_cells' => safeIntVal($_POST['lingual_cells'] ?? '', $report['lingual_cells']),
            ':language_churches' => safeIntVal($_POST['language_churches'] ?? '', $report['language_churches']),
            ':languages_sponsored' => safeIntVal($_POST['languages_sponsored'] ?? '', $report['languages_sponsored']),
            ':distribution_centers' => safeIntVal($_POST['distribution_centers'] ?? '', $report['distribution_centers']),
            ':external_ministers' => safeIntVal($_POST['external_ministers'] ?? '', $report['external_ministers']),
            ':syk_outreaches' => safeIntVal($_POST['syk_outreaches'] ?? '', $report['syk_outreaches']),
            ':syk_elementary_schools' => safeIntVal($_POST['syk_elementary_schools'] ?? '', $report['syk_elementary_schools']),
            ':syk_orphanages' => safeIntVal($_POST['syk_orphanages'] ?? '', $report['syk_orphanages']),
            ':syk_children_reached' => safeIntVal($_POST['syk_children_reached'] ?? '', $report['syk_children_reached']),
            ':syk_souls_won' => safeIntVal($_POST['syk_souls_won'] ?? '', $report['syk_souls_won']),
            ':syk_souls_retained' => safeIntVal($_POST['syk_souls_retained'] ?? '', $report['syk_souls_retained']),
            ':syk_rork_clubs' => safeIntVal($_POST['syk_rork_clubs'] ?? '', $report['syk_rork_clubs'])
        ];

        // Add user_id check for non-admin users
        if (!$isAdmin && !$isAdminManager) {
            $params[':user_id'] = $_SESSION['user_id'];
        }

        $stmt->execute($params);

        // Handle file updates if new files are uploaded
        if (isset($_FILES['testimonies_file']) && $_FILES['testimonies_file']['error'] === UPLOAD_ERR_OK) {
            $testimonies_file = uploadFile($_FILES['testimonies_file'], '../../uploads/documents/');
            if ($testimonies_file) {
                // Delete old file if exists
                if ($report['testimonies_file'] && file_exists('../../uploads/documents/' . $report['testimonies_file'])) {
                    unlink('../../uploads/documents/' . $report['testimonies_file']);
                }
                $stmt = $conn->prepare("UPDATE reports SET testimonies_file = ? WHERE id = ?" . (!$isAdmin && !$isAdminManager ? " AND user_id = ?" : ""));
                $stmt->execute(array_merge([$testimonies_file, $reportId], !$isAdmin && !$isAdminManager ? [$_SESSION['user_id']] : []));
            }
        }

        if (isset($_FILES['innovations_file']) && $_FILES['innovations_file']['error'] === UPLOAD_ERR_OK) {
            $innovations_file = uploadFile($_FILES['innovations_file'], '../../uploads/documents/');
            if ($innovations_file) {
                // Delete old file if exists
                if ($report['innovations_file'] && file_exists('../../uploads/documents/' . $report['innovations_file'])) {
                    unlink('../../uploads/documents/' . $report['innovations_file']);
                }
                $stmt = $conn->prepare("UPDATE reports SET innovations_file = ? WHERE id = ?" . (!$isAdmin && !$isAdminManager ? " AND user_id = ?" : ""));
                $stmt->execute(array_merge([$innovations_file, $reportId], !$isAdmin && !$isAdminManager ? [$_SESSION['user_id']] : []));
            }
        }

        // Handle report images
        if (isset($_FILES['report_images'])) {
            $uploadDir = '../../uploads/pictures/';

            // Create directories if they don't exist
            if (!file_exists($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            // Process each uploaded image
            $uploadedFiles = reArrayFiles($_FILES['report_images']);
            foreach ($uploadedFiles as $file) {
                if ($file['error'] === UPLOAD_ERR_OK) {
                    $filename = uploadFile($file, $uploadDir);
                    if ($filename) {
                        $stmt = $conn->prepare("INSERT INTO report_images (report_id, image_path) VALUES (?, ?)");
                        $stmt->execute([$reportId, $filename]);
                    }
                }
            }
        }

        // Handle image deletions
        if (isset($_POST['delete_images']) && is_array($_POST['delete_images'])) {
            // CSRF token check for destructive action
            if (!isset($_POST['csrf_token'])) {
                throw new Exception('Missing CSRF token');
            }
            Security::verifyCSRFToken($_POST['csrf_token']);
            foreach ($_POST['delete_images'] as $imageId) {
                // Get image path and verify ownership
                $stmt = $conn->prepare("SELECT ri.image_path FROM report_images ri
                                      JOIN reports r ON ri.report_id = r.id
                                      WHERE ri.id = ? AND ri.report_id = ? " .
                                      (!$isAdmin && !$isAdminManager ? "AND r.user_id = ?" : ""));
                $stmt->execute(array_merge([$imageId, $reportId], !$isAdmin && !$isAdminManager ? [$_SESSION['user_id']] : []));
                $image = $stmt->fetch();

                if ($image) {
                    // Delete file from server
                    $imagesDir = realpath(__DIR__ . '/../../uploads/pictures');
                    if ($imagesDir) {
                        $imagePath = $imagesDir . DIRECTORY_SEPARATOR . $image['image_path'];
                        if (file_exists($imagePath)) {
                            @unlink($imagePath);
                        }
                    }

                    // Delete from database
                    $stmt = $conn->prepare("DELETE ri FROM report_images ri
                                          JOIN reports r ON ri.report_id = r.id
                                          WHERE ri.id = ? AND ri.report_id = ? " .
                                          (!$isAdmin && !$isAdminManager ? "AND r.user_id = ?" : ""));
                    $stmt->execute(array_merge([$imageId, $reportId], !$isAdmin && !$isAdminManager ? [$_SESSION['user_id']] : []));
                }
            }
        }

        $conn->commit();
        $_SESSION['success'] = "Report updated successfully";
        header("Location: view.php?id=" . $reportId);
        exit;

    } catch (PDOException $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        if (($e->getCode() === '23000') || (strpos($e->getMessage(), 'uniq_reports_user_month') !== false)) {
            $_SESSION['error'] = "A report for this month already exists for this RZM.";
        } else {
            $_SESSION['error'] = "Error updating report: " . $e->getMessage();
        }
    } catch (Exception $e) {
        $conn->rollBack();
        $_SESSION['error'] = "Error updating report: " . $e->getMessage();
    }
}

// Include header
require_once '../../layouts/header.php';
?>

<div class="modern-container">
    <div class="max-w-4xl mx-auto">
        <div class="flex items-center mb-6">
            <a href="view.php?id=<?php echo $reportId; ?>" class="mr-4 text-gray-500 hover:text-gray-700">
                <i class="fas fa-arrow-left"></i>
            </a>
            <h1 class="text-2xl font-bold text-gray-900">Edit Report</h1>
        </div>

        <?php if (isset($_SESSION['error'])): ?>
            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    showToast('<?php echo addslashes($_SESSION['error']); ?>', 'error', 5000);
                });
            </script>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data" class="modern-card space-y-8 needs-validation" novalidate>
            <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
            <!-- Report Month -->
            <div class="modern-card-header">
                <h2 class="modern-card-title">Edit Monthly Report</h2>
            </div>
            
            <div class="modern-card-body">
                <!-- Report Month -->
                <div class="modern-section">
                    <h3 class="modern-section-title">Report Details</h3>
                    <div class="modern-section-body">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="modern-form-group">
                                <label for="report_year" class="modern-form-label">Year</label>
                                <select id="report_year"
                                        name="report_year"
                                        class="modern-form-input select2"
                                        required>
                                    <?php
                                    $reportYear = date('Y', strtotime($report['report_month']));
                                    $currentYear = (int)date('Y');
                                    $minYear = $currentYear - 5;
                                    for ($year = $currentYear; $year >= $minYear; $year--) {
                                        $selected = ($year == $reportYear) ? 'selected' : '';
                                        echo "<option value=\"{$year}\" {$selected}>{$year}</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                            <div class="modern-form-group">
                                <label for="report_month" class="modern-form-label">Month</label>
                                <select id="report_month"
                                        name="report_month"
                                        class="modern-form-input select2"
                                        required>
                                    <?php
                                    $months = [
                                        '01' => 'January',
                                        '02' => 'February',
                                        '03' => 'March',
                                        '04' => 'April',
                                        '05' => 'May',
                                        '06' => 'June',
                                        '07' => 'July',
                                        '08' => 'August',
                                        '09' => 'September',
                                        '10' => 'October',
                                        '11' => 'November',
                                        '12' => 'December'
                                    ];
                                    $reportMonth = date('m', strtotime($report['report_month']));
                                    foreach ($months as $value => $label) {
                                        $selected = ($value === $reportMonth) ? 'selected' : '';
                                        echo "<option value=\"{$value}\" {$selected}>{$label}</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                        </div>
                        <p class="mt-2 text-sm text-gray-500">Select the month and year for this report (cannot be a future month)</p>
                    </div>
                </div>

                <!-- Total Copies -->
                <div class="modern-section">
                    <h3 class="modern-section-title">Total Copies Sponsored</h3>
                    <div class="modern-section-body">
                        <div class="modern-form-group">
                            <label for="total_copies" class="modern-form-label">Total Number of Copies Sponsored</label>
                            <input type="number"
                                   id="total_copies"
                                   name="total_copies"
                                   value="<?php echo $report['total_copies']; ?>"
                                   class="modern-form-input"
                                   placeholder="Enter total copies">
                        </div>
                    </div>
                </div>

                <!-- Monthly Stats -->
                <div class="modern-section">
                    <h3 class="modern-section-title">Monthly Statistics</h3>
                    <div class="modern-section-body">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div class="modern-form-group">
                                <label for="wonder_alerts" class="modern-form-label">Number of Wonder Alerts Received</label>
                                <input type="number"
                                       id="wonder_alerts"
                                       name="wonder_alerts"
                                       value="<?php echo $report['wonder_alerts']; ?>"
                                       class="modern-form-input"
                                       placeholder="Enter number of wonder alerts received">
                            </div>
                            <div class="modern-form-group">
                                <label for="kids_alerts" class="modern-form-label">Kids Alerts</label>
                                <input type="number"
                                       id="kids_alerts"
                                       name="kids_alerts"
                                       value="<?php echo $report['kids_alerts']; ?>"
                                       class="modern-form-input"
                                       placeholder="Enter kids alerts">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Language and Truth -->
                <div class="modern-section">
                    <h3 class="modern-section-title">Languages</h3>
                    <div class="modern-section-body">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div class="modern-form-group">
                                <label for="language_missions" class="modern-form-label">Language Missions</label>
                                <input type="number"
                                       id="language_missions"
                                       name="language_missions"
                                       value="<?php echo $report['language_missions']; ?>"
                                       class="modern-form-input"
                                       placeholder="Enter language missions">
                            </div>
                            <div class="modern-form-group">
                                <label for="penetrating_truth" class="modern-form-label">Penetrating with Truth</label>
                                <input type="number"
                                       id="penetrating_truth"
                                       name="penetrating_truth"
                                       value="<?php echo $report['penetrating_truth']; ?>"
                                       class="modern-form-input"
                                       placeholder="Enter penetrating with truth">
                            </div>
                            <div class="modern-form-group">
                                <label for="penetrating_languages" class="modern-form-label">Penetrating with Languages</label>
                                <input type="number"
                                       id="penetrating_languages"
                                       name="penetrating_languages"
                                       value="<?php echo $report['penetrating_languages']; ?>"
                                       class="modern-form-input"
                                       placeholder="Enter penetrating with languages">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Campaigns -->
                <div class="modern-section">
                    <h3 class="modern-section-title">Campaigns</h3>
                    <div class="modern-section-body">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div class="modern-form-group">
                                <label for="youth_aglow" class="modern-form-label">Youth Aglow</label>
                                <input type="number"
                                       id="youth_aglow"
                                       name="youth_aglow"
                                       value="<?php echo $report['youth_aglow']; ?>"
                                       class="modern-form-input"
                                       placeholder="Enter youth aglow">
                            </div>
                            <div class="modern-form-group">
                                <label for="minister_campaign" class="modern-form-label">Every Minister Campaign</label>
                                <input type="number"
                                       id="minister_campaign"
                                       name="minister_campaign"
                                       value="<?php echo $report['minister_campaign']; ?>"
                                       class="modern-form-input"
                                       placeholder="Enter minister campaign">
                            </div>
                            <div class="modern-form-group">
                                <label for="say_yes_kids" class="modern-form-label">Say Yes to Kids</label>
                                <input type="number"
                                       id="say_yes_kids"
                                       name="say_yes_kids"
                                       value="<?php echo $report['say_yes_kids']; ?>"
                                       class="modern-form-input"
                                       placeholder="Enter say yes to kids">
                            </div>
                            <div class="modern-form-group">
                                <label for="no_one_left" class="modern-form-label">No One Left Behind</label>
                                <input type="number"
                                       id="no_one_left"
                                       name="no_one_left"
                                       value="<?php echo $report['no_one_left']; ?>"
                                       class="modern-form-input"
                                       placeholder="Enter no one left behind">
                            </div>
                            <div class="modern-form-group">
                                <label for="teevolution" class="modern-form-label">Teevolution</label>
                                <input type="number"
                                       id="teevolution"
                                       name="teevolution"
                                       value="<?php echo $report['teevolution']; ?>"
                                       class="modern-form-input"
                                       placeholder="Enter teevolution">
                            </div>
                            <div class="modern-form-group">
                                <label for="subscriptions" class="modern-form-label">Subscriptions</label>
                                <input type="number"
                                       id="subscriptions"
                                       name="subscriptions"
                                       value="<?php echo $report['subscriptions']; ?>"
                                       class="modern-form-input"
                                       placeholder="Enter subscriptions">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Program Report -->
                <div class="modern-section">
                    <h3 class="modern-section-title">Program Report</h3>
                    <div class="modern-section-body">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div class="modern-form-group">
                                <label for="prayer_programs" class="modern-form-label">Partners Prayer Programs</label>
                                <input type="number"
                                       id="prayer_programs"
                                       name="prayer_programs"
                                       value="<?php echo $report['prayer_programs']; ?>"
                                       class="modern-form-input"
                                       placeholder="Enter prayer programs">
                            </div>
                            <div class="modern-form-group">
                                <label for="partner_programs" class="modern-form-label">Partners Programs</label>
                                <input type="number"
                                       id="partner_programs"
                                       name="partner_programs"
                                       value="<?php echo $report['partner_programs']; ?>"
                                       class="modern-form-input"
                                       placeholder="Enter partner programs">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Distribution and Impact -->
                <div class="modern-section">
                    <h3 class="modern-section-title">Distribution and Impact</h3>
                    <div class="modern-section-body">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div class="modern-form-group">
                                <label for="total_distribution" class="modern-form-label">Total Copies Distributed</label>
                                <input type="number"
                                       id="total_distribution"
                                       name="total_distribution"
                                       value="<?php echo $report['total_distribution']; ?>"
                                       class="modern-form-input"
                                       placeholder="Enter total distribution">
                            </div>
                            <div class="modern-form-group">
                                <label for="souls_won" class="modern-form-label">Souls Won</label>
                                <input type="number"
                                       id="souls_won"
                                       name="souls_won"
                                       value="<?php echo $report['souls_won']; ?>"
                                       class="modern-form-input"
                                       placeholder="Enter souls won">
                            </div>
                            <div class="modern-form-group">
                                <label for="rhapsody_outreaches" class="modern-form-label">Rhapsody Outreaches</label>
                                <input type="number"
                                       id="rhapsody_outreaches"
                                       name="rhapsody_outreaches"
                                       value="<?php echo $report['rhapsody_outreaches']; ?>"
                                       class="modern-form-input"
                                       placeholder="Enter rhapsody outreaches">
                            </div>
                            <div class="modern-form-group">
                                <label for="rhapsody_cells" class="modern-form-label">Rhapsody Cells</label>
                                <input type="number"
                                       id="rhapsody_cells"
                                       name="rhapsody_cells"
                                       value="<?php echo $report['rhapsody_cells']; ?>"
                                       class="modern-form-input"
                                       placeholder="Enter rhapsody cells">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Growth Metrics -->
                <div class="modern-section">
                    <h3 class="modern-section-title">Growth Metrics</h3>
                    <div class="modern-section-body">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div class="modern-form-group">
                                <label for="new_churches" class="modern-form-label">New Churches</label>
                                <input type="number"
                                       id="new_churches"
                                       name="new_churches"
                                       value="<?php echo $report['new_churches']; ?>"
                                       class="modern-form-input"
                                       placeholder="Enter new churches">
                            </div>
                            <div class="modern-form-group">
                                <label for="new_partners" class="modern-form-label">New Partners</label>
                                <input type="number"
                                       id="new_partners"
                                       name="new_partners"
                                       value="<?php echo $report['new_partners']; ?>"
                                       class="modern-form-input"
                                       placeholder="Enter new partners">
                            </div>
                            <div class="modern-form-group">
                                <label for="lingual_cells" class="modern-form-label">Lingual Cells</label>
                                <input type="number"
                                       id="lingual_cells"
                                       name="lingual_cells"
                                       value="<?php echo $report['lingual_cells']; ?>"
                                       class="modern-form-input"
                                       placeholder="Enter lingual cells">
                            </div>
                            <div class="modern-form-group">
                                <label for="language_churches" class="modern-form-label">Language Churches</label>
                                <input type="number"
                                       id="language_churches"
                                       name="language_churches"
                                       value="<?php echo $report['language_churches']; ?>"
                                       class="modern-form-input"
                                       placeholder="Enter language churches">
                            </div>
                            <div class="modern-form-group">
                                <label for="languages_sponsored" class="modern-form-label">Languages Sponsored</label>
                                <input type="number"
                                       id="languages_sponsored"
                                       name="languages_sponsored"
                                       value="<?php echo $report['languages_sponsored']; ?>"
                                       class="modern-form-input"
                                       placeholder="Enter languages sponsored">
                            </div>
                            <div class="modern-form-group">
                                <label for="distribution_centers" class="modern-form-label">Distribution Centers</label>
                                <input type="number"
                                       id="distribution_centers"
                                       name="distribution_centers"
                                       value="<?php echo $report['distribution_centers']; ?>"
                                       class="modern-form-input"
                                       placeholder="Enter distribution centers">
                            </div>
                            <div class="modern-form-group">
                                <label for="external_ministers" class="modern-form-label">External Ministers</label>
                                <input type="number"
                                       id="external_ministers"
                                       name="external_ministers"
                                       value="<?php echo $report['external_ministers']; ?>"
                                       class="modern-form-input"
                                       placeholder="Enter external ministers">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Say Yes to Kids Impact -->
                <div class="modern-section">
                    <h3 class="modern-section-title">Say Yes to Kids Impact</h3>
                    <div class="modern-section-body">
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                            <div class="modern-form-group">
                                <label for="syk_outreaches" class="modern-form-label">Number of Outreaches</label>
                                <input type="number"
                                       id="syk_outreaches"
                                       name="syk_outreaches"
                                       value="<?php echo $report['syk_outreaches']; ?>"
                                       class="modern-form-input"
                                       placeholder="Enter number of outreaches">
                            </div>
                            <div class="modern-form-group">
                                <label for="syk_elementary_schools" class="modern-form-label">Elementary Schools Reached</label>
                                <input type="number"
                                       id="syk_elementary_schools"
                                       name="syk_elementary_schools"
                                       value="<?php echo $report['syk_elementary_schools']; ?>"
                                       class="modern-form-input"
                                       placeholder="Enter number of schools">
                            </div>
                            <div class="modern-form-group">
                                <label for="syk_orphanages" class="modern-form-label">Orphanages Reached</label>
                                <input type="number"
                                       id="syk_orphanages"
                                       name="syk_orphanages"
                                       value="<?php echo $report['syk_orphanages']; ?>"
                                       class="modern-form-input"
                                       placeholder="Enter number of orphanages">
                            </div>
                            <div class="modern-form-group">
                                <label for="syk_children_reached" class="modern-form-label">Number of Children Reached</label>
                                <input type="number"
                                       id="syk_children_reached"
                                       name="syk_children_reached"
                                       value="<?php echo $report['syk_children_reached']; ?>"
                                       class="modern-form-input"
                                       placeholder="Enter number of children reached">
                            </div>
                            <div class="modern-form-group">
                                <label for="syk_souls_won" class="modern-form-label">Souls (Children) Won</label>
                                <input type="number"
                                       id="syk_souls_won"
                                       name="syk_souls_won"
                                       value="<?php echo $report['syk_souls_won']; ?>"
                                       class="modern-form-input"
                                       placeholder="Enter number of souls won">
                            </div>
                            <div class="modern-form-group">
                                <label for="syk_souls_retained" class="modern-form-label">Souls Retained</label>
                                <input type="number"
                                       id="syk_souls_retained"
                                       name="syk_souls_retained"
                                       value="<?php echo $report['syk_souls_retained']; ?>"
                                       class="modern-form-input"
                                       placeholder="Enter number of souls retained">
                            </div>
                            <div class="modern-form-group">
                                <label for="syk_rork_clubs" class="modern-form-label">RORK Clubs</label>
                                <input type="number"
                                       id="syk_rork_clubs"
                                       name="syk_rork_clubs"
                                       value="<?php echo $report['syk_rork_clubs']; ?>"
                                       class="modern-form-input"
                                       placeholder="Enter number of RORK clubs">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- File Uploads -->
                <div class="modern-section">
                    <h3 class="modern-section-title">Supporting Documents</h3>
                    <div class="modern-section-body">
                        <div class="grid grid-cols-1 gap-6">
                            <div class="modern-form-group">
                                <label for="testimonies_file" class="modern-form-label">Testimonies File</label>
                                <input type="file"
                                       id="testimonies_file"
                                       name="testimonies_file"
                                       class="modern-form-file">
                                <?php if ($report['testimonies_file']): ?>
                                    <div class="mt-2 flex items-center">
                                        <span class="text-sm text-gray-500 mr-2">Current file:</span>
                                        <a href="../../uploads/documents/<?php echo htmlspecialchars($report['testimonies_file']); ?>" 
                                           class="text-primary-600 hover:text-primary-800 text-sm"
                                           target="_blank">
                                            <?php echo $report['testimonies_file']; ?>
                                        </a>
                                    </div>
                                <?php endif; ?>
                                <p class="modern-form-help">Upload a new file to replace the current one (PDF, DOC, DOCX)</p>
                            </div>
                            <div class="modern-form-group">
                                <label for="innovations_file" class="modern-form-label">Innovations File</label>
                                <input type="file"
                                       id="innovations_file"
                                       name="innovations_file"
                                       class="modern-form-file">
                                <?php if ($report['innovations_file']): ?>
                                    <div class="mt-2 flex items-center">
                                        <span class="text-sm text-gray-500 mr-2">Current file:</span>
                                        <a href="../../uploads/documents/<?php echo htmlspecialchars($report['innovations_file']); ?>" 
                                           class="text-primary-600 hover:text-primary-800 text-sm"
                                           target="_blank">
                                            <?php echo $report['innovations_file']; ?>
                                        </a>
                                    </div>
                                <?php endif; ?>
                                <p class="modern-form-help">Upload a new file to replace the current one (PDF, DOC, DOCX)</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Image Uploads -->
                <div class="modern-section">
                    <h3 class="modern-section-title">Report Images</h3>
                    <div class="modern-section-body">
                        <div class="grid grid-cols-1 gap-6">
                            <!-- Existing Images -->
                            <?php if ($images): ?>
                            <div class="mb-4">
                                <h4 class="text-sm font-medium text-gray-700 mb-3">Current Images</h4>
                                <p class="text-sm text-gray-500 mb-3">Hover over images and check the ones you want to delete</p>
                                <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                                    <?php foreach ($images as $image): ?>
                                    <div class="relative group">
                                        <img src="../../uploads/pictures/<?php echo htmlspecialchars($image['image_path']); ?>"
                                             alt="Report Image"
                                             class="w-full h-32 object-cover rounded-lg shadow-sm">
                                        <div class="absolute inset-0 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity bg-black bg-opacity-50 rounded-lg">
                                            <label class="flex items-center space-x-2 text-white cursor-pointer">
                                            <input type="checkbox"
                                                   name="delete_images[]"
                                                   value="<?php echo $image['id']; ?>"
                                                   class="rounded border-gray-300 text-danger-600 shadow-sm focus:border-danger-300 focus:ring focus:ring-danger-200 focus:ring-opacity-50">
                                                <span>Delete</span>
                                            </label>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; ?>

                            <!-- Upload New Images -->
                            <div class="modern-form-group">
                                <label for="report_images" class="modern-form-label">Add New Images</label>
                                <input type="file"
                                       id="report_images"
                                       name="report_images[]"
                                       multiple
                                       accept="image/*"
                                       class="modern-form-file">
                                <p class="modern-form-help">You can select multiple images to add to this report (JPG, PNG, GIF)</p>
                            </div>
                            <div id="image-preview" class="mt-4"></div>
                        </div>
                    </div>
                </div>

                <!-- Submit Button -->
                <div class="modern-card-footer">
                    <button type="submit" id="submit-report-btn" class="modern-button modern-button-primary">
                        <i class="fas fa-save modern-button-icon"></i> Update Report
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Initialize Select2 for all select elements
    $('.select2').select2({
        theme: 'default',
        width: '100%',
        placeholder: 'Select an option',
        allowClear: true
    });
    
    // Add validation for month and year selection
    const monthSelect = document.getElementById('report_month');
    const yearSelect = document.getElementById('report_year');

    // Function to validate that the selected month is not in the future
    function validateReportDate() {
        const selectedMonth = parseInt(monthSelect.value, 10);
        const selectedYear = parseInt(yearSelect.value, 10);
        const currentDate = new Date();
        const currentYear = currentDate.getFullYear();
        const currentMonth = currentDate.getMonth() + 1; // JavaScript months are 0-indexed

        // Allow current month and past months, but not future months
        if (selectedYear > currentYear || (selectedYear === currentYear && selectedMonth > currentMonth)) {
            showAlert('Invalid Date', 'You cannot select a future month for your report.', 'error');

            // Reset to current month and year
            monthSelect.value = String(currentMonth).padStart(2, '0');
            yearSelect.value = currentYear;
            
            // Update Select2 controls
            $('#report_month').trigger('change');
            $('#report_year').trigger('change');

            return false;
        }
        return true;
    }

    // Add event listeners for change events
    $('#report_month').on('change', validateReportDate);
    $('#report_year').on('change', validateReportDate);

    // Add form validation before submission
    const form = document.querySelector('form');
    form.addEventListener('submit', function(event) {
        if (!form.checkValidity()) {
            event.preventDefault();
            event.stopPropagation();
            
            // Show toast for invalid form
            showToast('Please check the form for errors.', 'error');
            
            // Find the first invalid field and focus it
            const firstInvalid = form.querySelector(':invalid');
            if (firstInvalid) {
                firstInvalid.focus();
            }
        } else if (!validateReportDate()) {
            event.preventDefault();
        } else {
            // Confirm before submitting
            if (document.querySelectorAll('input[name="delete_images[]"]:checked').length > 0) {
                event.preventDefault();
                
                Swal.fire({
                    title: 'Confirm Image Deletion',
                    text: 'You have selected some images to delete. This action cannot be undone. Do you want to continue?',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: 'var(--danger-600)',
                    cancelButtonColor: 'var(--gray-500)',
                    confirmButtonText: 'Yes, delete them',
                    cancelButtonText: 'Cancel'
                }).then((result) => {
                    if (result.isConfirmed) {
                        // Show loading state
                        const submitBtn = document.getElementById('submit-report-btn');
                        submitBtn.disabled = true;
                        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Updating...';
                        
                        // Submit the form
                        form.submit();
                    }
                });
            } else {
                // Show loading state
                const submitBtn = document.getElementById('submit-report-btn');
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Updating...';
            }
        }
        
        form.classList.add('was-validated');
    });

    // Image preview functionality
    const imageInput = document.querySelector('input[name="report_images[]"]');
    const previewContainer = document.getElementById('image-preview');

    imageInput.addEventListener('change', function() {
        previewContainer.innerHTML = ''; // Clear existing previews

        if (this.files && this.files.length > 0) {
            const files = Array.from(this.files);

            // Create preview grid
            const grid = document.createElement('div');
            grid.className = 'grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-4';

            files.forEach(file => {
                if (file.type.startsWith('image/')) {
                    const reader = new FileReader();

                    reader.onload = function(e) {
                        const previewDiv = document.createElement('div');
                        previewDiv.className = 'relative aspect-square bg-gray-100 rounded-lg overflow-hidden';

                        const img = document.createElement('img');
                        img.src = e.target.result;
                        img.className = 'w-full h-full object-cover';

                        previewDiv.appendChild(img);
                        grid.appendChild(previewDiv);
                    };

                    reader.readAsDataURL(file);
                }
            });

            previewContainer.appendChild(grid);
            
            // Show success toast
            showToast(`${files.length} image${files.length > 1 ? 's' : ''} selected for upload`, 'success');
        }
    });
});
</script>

<?php require_once '../../layouts/footer.php'; ?>
