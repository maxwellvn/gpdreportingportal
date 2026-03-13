<?php
require_once '../../config/config.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Get user data
$auth = new Auth();
$user = $auth->getUser($_SESSION['user_id']);
$_SESSION['user_data'] = $user;

if (Auth::isAdminManager()) {
    $_SESSION['error'] = "Admin Managers can view and edit regional reports but cannot submit new reports.";
    header('Location: list.php');
    exit;
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $db = new Database();
    $conn = $db->getConnection();
    $hasTransaction = false;

    try {
        // CSRF validation
        if (!isset($_POST['csrf_token'])) {
            throw new Exception('Missing CSRF token');
        }
        Security::verifyCSRFToken($_POST['csrf_token']);
        // Validate and format the date
        $reportYear = $_POST['report_year'] ?? date('Y');
        $reportMonth = $_POST['report_month'] ?? date('m');

        // Ensure we have valid numeric values
        if (!is_numeric($reportYear) || !is_numeric($reportMonth)) {
            throw new Exception("Invalid date format. Please select a valid month and year.");
        }

        // Create a properly formatted date string for comparison
        $reportMonthFormatted = sprintf('%04d-%02d', (int)$reportYear, (int)$reportMonth);
        $currentMonth = date('Y-m');

        if ($reportMonthFormatted > $currentMonth) {
            throw new Exception("You cannot submit reports for future months.");
        }

        // Check if a report already exists for this month
        $checkQuery = "SELECT id FROM reports WHERE user_id = :user_id AND DATE_FORMAT(report_month, '%Y-%m') = :report_month";
        $stmt = $conn->prepare($checkQuery);
        $stmt->execute([
            ':user_id' => $_SESSION['user_id'],
            ':report_month' => $reportMonthFormatted
        ]);

        if ($stmt->fetch()) {
            $dupeDate = DateTime::createFromFormat('Y-m', $reportYear . '-' . $reportMonth);
            $dupeLabel = $dupeDate ? $dupeDate->format('F Y') : ($reportYear . '-' . $reportMonth);
            throw new Exception("A report for " . $dupeLabel . " has already been submitted.");
        }

        // Start transaction before any data modification
        $conn->beginTransaction();
        $hasTransaction = true;

        // Insert report data
        $reportQuery = "INSERT INTO reports (
            user_id, report_month, total_copies,
            wonder_alerts, kids_alerts, language_missions, penetrating_truth,
            penetrating_languages, youth_aglow, minister_campaign, say_yes_kids,
            no_one_left, teevolution, subscriptions, prayer_programs,
            partner_programs, total_distribution, souls_won, rhapsody_outreaches,
            rhapsody_cells, new_churches, new_partners, lingual_cells,
            language_churches, languages_sponsored, distribution_centers,
            external_ministers, testimonies_file, innovations_file,
            syk_outreaches, syk_elementary_schools, syk_orphanages,
            syk_children_reached, syk_souls_won, syk_souls_retained, syk_rork_clubs
        ) VALUES (
            :user_id, :report_month, :total_copies,
            :wonder_alerts, :kids_alerts, :language_missions, :penetrating_truth,
            :penetrating_languages, :youth_aglow, :minister_campaign, :say_yes_kids,
            :no_one_left, :teevolution, :subscriptions, :prayer_programs,
            :partner_programs, :total_distribution, :souls_won, :rhapsody_outreaches,
            :rhapsody_cells, :new_churches, :new_partners, :lingual_cells,
            :language_churches, :languages_sponsored, :distribution_centers,
            :external_ministers, :testimonies_file, :innovations_file,
            :syk_outreaches, :syk_elementary_schools, :syk_orphanages,
            :syk_children_reached, :syk_souls_won, :syk_souls_retained, :syk_rork_clubs
        )";

        $stmt = $conn->prepare($reportQuery);

        // Handle file uploads for testimonies and innovations
        $testimonies_file = '';
        $innovations_file = '';

        if (isset($_FILES['testimonies_file']) && $_FILES['testimonies_file']['error'] === UPLOAD_ERR_OK) {
            $testimonies_file = uploadFile($_FILES['testimonies_file'], '../../uploads/documents/', ['pdf','doc','docx']);
        }

        if (isset($_FILES['innovations_file']) && $_FILES['innovations_file']['error'] === UPLOAD_ERR_OK) {
            $innovations_file = uploadFile($_FILES['innovations_file'], '../../uploads/documents/', ['pdf','doc','docx']);
        }

        // Function to safely convert form values to integers
        function safeIntVal($value) {
            if ($value === null) {
                return 0;
            }

            if (is_string($value)) {
                $value = trim(str_replace([',', ' '], '', $value));
            }

            if ($value === '' || !is_numeric($value)) {
                return 0;
            }
            return (int)$value;
        }

        // Bind all the parameters
        $params = [
            ':user_id' => $_SESSION['user_id'],
            ':report_month' => sprintf('%04d-%02d-01', (int)$reportYear, (int)$reportMonth),
            ':total_copies' => safeIntVal($_POST['total_copies'] ?? ''),
            ':wonder_alerts' => safeIntVal($_POST['wonder_alerts'] ?? ''),
            ':kids_alerts' => safeIntVal($_POST['kids_alerts'] ?? ''),
            ':language_missions' => safeIntVal($_POST['language_missions'] ?? ''),
            ':penetrating_truth' => safeIntVal($_POST['penetrating_truth'] ?? ''),
            ':penetrating_languages' => safeIntVal($_POST['penetrating_languages'] ?? ''),
            ':youth_aglow' => safeIntVal($_POST['youth_aglow'] ?? ''),
            ':minister_campaign' => safeIntVal($_POST['minister_campaign'] ?? ''),
            ':say_yes_kids' => safeIntVal($_POST['say_yes_kids'] ?? ''),
            ':no_one_left' => safeIntVal($_POST['no_one_left'] ?? ''),
            ':teevolution' => safeIntVal($_POST['teevolution'] ?? ''),
            ':subscriptions' => safeIntVal($_POST['subscriptions'] ?? ''),
            ':prayer_programs' => safeIntVal($_POST['prayer_programs'] ?? ''),
            ':partner_programs' => safeIntVal($_POST['partner_programs'] ?? ''),
            ':total_distribution' => safeIntVal($_POST['total_distribution'] ?? ''),
            ':souls_won' => safeIntVal($_POST['souls_won'] ?? ''),
            ':rhapsody_outreaches' => safeIntVal($_POST['rhapsody_outreaches'] ?? ''),
            ':rhapsody_cells' => safeIntVal($_POST['rhapsody_cells'] ?? ''),
            ':new_churches' => safeIntVal($_POST['new_churches'] ?? ''),
            ':new_partners' => safeIntVal($_POST['new_partners'] ?? ''),
            ':lingual_cells' => safeIntVal($_POST['lingual_cells'] ?? ''),
            ':language_churches' => safeIntVal($_POST['language_churches'] ?? ''),
            ':languages_sponsored' => safeIntVal($_POST['languages_sponsored'] ?? ''),
            ':distribution_centers' => safeIntVal($_POST['distribution_centers'] ?? ''),
            ':external_ministers' => safeIntVal($_POST['external_ministers'] ?? ''),
            ':testimonies_file' => $testimonies_file,
            ':innovations_file' => $innovations_file,
            ':syk_outreaches' => safeIntVal($_POST['syk_outreaches'] ?? ''),
            ':syk_elementary_schools' => safeIntVal($_POST['syk_elementary_schools'] ?? ''),
            ':syk_orphanages' => safeIntVal($_POST['syk_orphanages'] ?? ''),
            ':syk_children_reached' => safeIntVal($_POST['syk_children_reached'] ?? ''),
            ':syk_souls_won' => safeIntVal($_POST['syk_souls_won'] ?? ''),
            ':syk_souls_retained' => safeIntVal($_POST['syk_souls_retained'] ?? ''),
            ':syk_rork_clubs' => safeIntVal($_POST['syk_rork_clubs'] ?? '')
        ];

        $stmt->execute($params);
        $reportId = $conn->lastInsertId();

        // Handle image uploads
        if (isset($_FILES['report_images'])) {
            $uploadDir = '../../uploads/pictures/';

            // Create directories if they don't exist
            if (!file_exists($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            // Process each uploaded image using secure helper
            $uploadedFiles = reArrayFiles($_FILES['report_images']);
            foreach ($uploadedFiles as $file) {
                if ($file['error'] === UPLOAD_ERR_OK) {
                    $filename = uploadFile($file, $uploadDir, ['jpg','jpeg','png','gif']);
                    if ($filename) {
                        $imageQuery = "INSERT INTO report_images (report_id, image_path) VALUES (:report_id, :image_path)";
                        $imageStmt = $conn->prepare($imageQuery);
                        $imageStmt->execute([
                            ':report_id' => $reportId,
                            ':image_path' => $filename
                        ]);
                    }
                }
            }
        }

        $conn->commit();
        $_SESSION['success'] = "Report for " . date('F Y', strtotime($reportYear . '-' . $reportMonth . '-01')) . " has been created successfully!";
        header('Location: view.php?id=' . $reportId);
        exit;

    } catch (PDOException $e) {
        // Only rollback if a transaction was started
        if ($hasTransaction && $conn->inTransaction()) {
            $conn->rollBack();
        }
        if (($e->getCode() === '23000') || (strpos($e->getMessage(), 'uniq_reports_user_month') !== false)) {
            $_SESSION['error'] = "A report for this month has already been submitted for this RZM.";
        } else {
            $_SESSION['error'] = "Failed to submit report: " . $e->getMessage();
        }
        error_log("Report Creation PDO Error: " . $e->getMessage());
    } catch (Exception $e) {
        // Only rollback if a transaction was started
        if ($hasTransaction && $conn->inTransaction()) {
            $conn->rollBack();
        }
        $_SESSION['error'] = $e->getMessage();

        // Log the error for debugging
        error_log("Report Creation Error: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
    }
}

// Include header
require_once '../../layouts/header.php';
?>

<div class="modern-container">
    <div class="max-w-4xl mx-auto">
        <div class="flex items-center mb-6">
            <a href="list.php" class="mr-4 text-gray-500 hover:text-gray-700">
                <i class="fas fa-arrow-left"></i>
            </a>
            <h1 class="text-2xl font-bold text-gray-900">Submit New Report</h1>
        </div>

        <?php if (isset($_SESSION['error'])): ?>
            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    showToast('<?php echo addslashes($_SESSION['error']); ?>', 'error', 5000);
                });
            </script>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['success'])): ?>
            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    showAlert('Success', '<?php echo addslashes($_SESSION['success']); ?>', 'success');
                });
            </script>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data" class="modern-card space-y-8 needs-validation" novalidate>
            <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
            <!-- Report Month -->
            <div class="modern-card-header">
                <h2 class="modern-card-title">Submit Monthly Report</h2>
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
                                    $currentYear = (int)date('Y');
                                    $minYear = $currentYear - 5;
                                    for ($year = $currentYear; $year >= $minYear; $year--) {
                                        $selected = ($year === $currentYear) ? 'selected' : '';
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
                                    $currentMonth = date('m');
                                    foreach ($months as $value => $label) {
                                        $selected = ($value === $currentMonth) ? 'selected' : '';
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
                                       class="modern-form-input"
                                       placeholder="Enter number of wonder alerts received">
                            </div>
                            <div class="modern-form-group">
                                <label for="kids_alerts" class="modern-form-label">Kids Alerts</label>
                                <input type="number"
                                       id="kids_alerts"
                                       name="kids_alerts"
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
                                       class="modern-form-input"
                                       placeholder="Enter language missions">
                            </div>
                            <div class="modern-form-group">
                                <label for="penetrating_truth" class="modern-form-label">Penetrating with Truth</label>
                                <input type="number"
                                       id="penetrating_truth"
                                       name="penetrating_truth"
                                       class="modern-form-input"
                                       placeholder="Enter penetrating with truth">
                            </div>
                            <div class="modern-form-group">
                                <label for="penetrating_languages" class="modern-form-label">Penetrating with Languages</label>
                                <input type="number"
                                       id="penetrating_languages"
                                       name="penetrating_languages"
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
                                       class="modern-form-input"
                                       placeholder="Enter youth aglow">
                            </div>
                            <div class="modern-form-group">
                                <label for="minister_campaign" class="modern-form-label">Every Minister Campaign</label>
                                <input type="number"
                                       id="minister_campaign"
                                       name="minister_campaign"
                                       class="modern-form-input"
                                       placeholder="Enter minister campaign">
                            </div>
                            <div class="modern-form-group">
                                <label for="say_yes_kids" class="modern-form-label">Say Yes to Kids</label>
                                <input type="number"
                                       id="say_yes_kids"
                                       name="say_yes_kids"
                                       class="modern-form-input"
                                       placeholder="Enter say yes to kids">
                            </div>
                            <div class="modern-form-group">
                                <label for="no_one_left" class="modern-form-label">No One Left Behind</label>
                                <input type="number"
                                       id="no_one_left"
                                       name="no_one_left"
                                       class="modern-form-input"
                                       placeholder="Enter no one left behind">
                            </div>
                            <div class="modern-form-group">
                                <label for="teevolution" class="modern-form-label">Teevolution</label>
                                <input type="number"
                                       id="teevolution"
                                       name="teevolution"
                                       class="modern-form-input"
                                       placeholder="Enter teevolution">
                            </div>
                            <div class="modern-form-group">
                                <label for="subscriptions" class="modern-form-label">Subscriptions</label>
                                <input type="number"
                                       id="subscriptions"
                                       name="subscriptions"
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
                                       class="modern-form-input"
                                       placeholder="Enter prayer programs">
                            </div>
                            <div class="modern-form-group">
                                <label for="partner_programs" class="modern-form-label">Partners Programs</label>
                                <input type="number"
                                       id="partner_programs"
                                       name="partner_programs"
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
                                       class="modern-form-input"
                                       placeholder="Enter total distribution">
                            </div>
                            <div class="modern-form-group">
                                <label for="souls_won" class="modern-form-label">Souls Won</label>
                                <input type="number"
                                       id="souls_won"
                                       name="souls_won"
                                       class="modern-form-input"
                                       placeholder="Enter souls won">
                            </div>
                            <div class="modern-form-group">
                                <label for="rhapsody_outreaches" class="modern-form-label">Rhapsody Outreaches</label>
                                <input type="number"
                                       id="rhapsody_outreaches"
                                       name="rhapsody_outreaches"
                                       class="modern-form-input"
                                       placeholder="Enter rhapsody outreaches">
                            </div>
                            <div class="modern-form-group">
                                <label for="rhapsody_cells" class="modern-form-label">Rhapsody Cells</label>
                                <input type="number"
                                       id="rhapsody_cells"
                                       name="rhapsody_cells"
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
                                       class="modern-form-input"
                                       placeholder="Enter new churches">
                            </div>
                            <div class="modern-form-group">
                                <label for="new_partners" class="modern-form-label">New Partners</label>
                                <input type="number"
                                       id="new_partners"
                                       name="new_partners"
                                       class="modern-form-input"
                                       placeholder="Enter new partners">
                            </div>
                            <div class="modern-form-group">
                                <label for="lingual_cells" class="modern-form-label">Lingual Cells</label>
                                <input type="number"
                                       id="lingual_cells"
                                       name="lingual_cells"
                                       class="modern-form-input"
                                       placeholder="Enter lingual cells">
                            </div>
                            <div class="modern-form-group">
                                <label for="language_churches" class="modern-form-label">Language Churches</label>
                                <input type="number"
                                       id="language_churches"
                                       name="language_churches"
                                       class="modern-form-input"
                                       placeholder="Enter language churches">
                            </div>
                            <div class="modern-form-group">
                                <label for="languages_sponsored" class="modern-form-label">Languages Sponsored</label>
                                <input type="number"
                                       id="languages_sponsored"
                                       name="languages_sponsored"
                                       class="modern-form-input"
                                       placeholder="Enter languages sponsored">
                            </div>
                            <div class="modern-form-group">
                                <label for="distribution_centers" class="modern-form-label">Distribution Centers</label>
                                <input type="number"
                                       id="distribution_centers"
                                       name="distribution_centers"
                                       class="modern-form-input"
                                       placeholder="Enter distribution centers">
                            </div>
                            <div class="modern-form-group">
                                <label for="external_ministers" class="modern-form-label">External Ministers</label>
                                <input type="number"
                                       id="external_ministers"
                                       name="external_ministers"
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
                                       class="modern-form-input"
                                       placeholder="Enter number of outreaches">
                            </div>
                            <div class="modern-form-group">
                                <label for="syk_elementary_schools" class="modern-form-label">Elementary Schools Reached</label>
                                <input type="number"
                                       id="syk_elementary_schools"
                                       name="syk_elementary_schools"
                                       class="modern-form-input"
                                       placeholder="Enter number of schools">
                            </div>
                            <div class="modern-form-group">
                                <label for="syk_orphanages" class="modern-form-label">Orphanages Reached</label>
                                <input type="number"
                                       id="syk_orphanages"
                                       name="syk_orphanages"
                                       class="modern-form-input"
                                       placeholder="Enter number of orphanages">
                            </div>
                            <div class="modern-form-group">
                                <label for="syk_children_reached" class="modern-form-label">Number of Children Reached</label>
                                <input type="number"
                                       id="syk_children_reached"
                                       name="syk_children_reached"
                                       class="modern-form-input"
                                       placeholder="Enter number of children reached">
                            </div>
                            <div class="modern-form-group">
                                <label for="syk_souls_won" class="modern-form-label">Souls (Children) Won</label>
                                <input type="number"
                                       id="syk_souls_won"
                                       name="syk_souls_won"
                                       class="modern-form-input"
                                       placeholder="Enter number of souls won">
                            </div>
                            <div class="modern-form-group">
                                <label for="syk_souls_retained" class="modern-form-label">Souls Retained</label>
                                <input type="number"
                                       id="syk_souls_retained"
                                       name="syk_souls_retained"
                                       class="modern-form-input"
                                       placeholder="Enter number of souls retained">
                            </div>
                            <div class="modern-form-group">
                                <label for="syk_rork_clubs" class="modern-form-label">RORK Clubs</label>
                                <input type="number"
                                       id="syk_rork_clubs"
                                       name="syk_rork_clubs"
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
                                <p class="modern-form-help">Upload a document with testimonies (PDF, DOC, DOCX)</p>
                            </div>
                            <div class="modern-form-group">
                                <label for="innovations_file" class="modern-form-label">Innovations File</label>
                                <input type="file"
                                       id="innovations_file"
                                       name="innovations_file"
                                       class="modern-form-file">
                                <p class="modern-form-help">Upload a document with innovations (PDF, DOC, DOCX)</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Image Uploads -->
                <div class="modern-section">
                    <h3 class="modern-section-title">Report Images</h3>
                    <div class="modern-section-body">
                        <div class="grid grid-cols-1 gap-6">
                            <div class="modern-form-group">
                                <label for="report_images" class="modern-form-label">Report Images</label>
                                <input type="file"
                                       id="report_images"
                                       name="report_images[]"
                                       multiple
                                       class="modern-form-file"
                                       accept="image/*">
                                <p class="modern-form-help">Upload images from your outreaches (JPG, PNG, GIF)</p>
                            </div>
                            <div id="image-preview" class="mt-4"></div>
                        </div>
                    </div>
                </div>

                <!-- Submit Button -->
                <div class="modern-card-footer">
                    <button type="submit"
                            id="submit-report-btn"
                            class="modern-button modern-button-primary">
                        <i class="fas fa-paper-plane modern-button-icon"></i> Submit Report
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
            // Show loading state
            const submitBtn = document.getElementById('submit-report-btn');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Submitting...';
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
