<?php
require_once '../../config/config.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../includes/audit.php';

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
            distribution_digital = :distribution_digital,
            distribution_physical = :distribution_physical,
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
            crusades = :crusades,
            testimonies_received = :testimonies_received,
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

        $distributionDigital = safeIntVal($_POST['distribution_digital'] ?? '', $report['distribution_digital'] ?? 0);
        $distributionPhysical = safeIntVal($_POST['distribution_physical'] ?? '', $report['distribution_physical'] ?? 0);
        $totalDistribution = $distributionDigital + $distributionPhysical;

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
            ':total_distribution' => $totalDistribution,
            ':distribution_digital' => $distributionDigital,
            ':distribution_physical' => $distributionPhysical,
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
            ':crusades' => safeIntVal($_POST['crusades'] ?? '', $report['crusades'] ?? 0),
            ':testimonies_received' => safeIntVal($_POST['testimonies_received'] ?? '', $report['testimonies_received'] ?? 0),
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
        Audit::log('edit_report', 'report', $reportId, 'Edited report #' . $reportId . ' (' . date('F Y', strtotime($dateString)) . ')');
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

$reportMonthLabel = date('F Y', strtotime($report['report_month']));
$reportYearVal    = date('Y', strtotime($report['report_month']));
$reportMonthVal   = date('m', strtotime($report['report_month']));
?>

<style>
/* reuse cr-* from create.php — defined here for self-containment */
.cr-page { max-width: 52rem; margin: 0 auto; padding: 1.5rem 1rem 3rem; }
.cr-breadcrumb { display:flex; align-items:center; gap:0.35rem; font-size:0.78rem; color:var(--neutral-400); margin-bottom:1.25rem; flex-wrap:wrap; }
.cr-breadcrumb a { color:var(--neutral-500); text-decoration:none; }
.cr-breadcrumb a:hover { color:var(--primary-600); }
.cr-breadcrumb .sep { color:var(--neutral-300); }
.cr-head { margin-bottom:1.5rem; }
.cr-head h1 { font-size:1.25rem; font-weight:700; color:var(--neutral-900); margin:0 0 0.25rem; }
.cr-head p { font-size:0.8125rem; color:var(--neutral-500); margin:0; }
.cr-section { border:1px solid var(--neutral-200); border-radius:var(--radius-lg); background:#fff; overflow:hidden; margin-bottom:1.25rem; }
.cr-section-head { display:flex; align-items:center; gap:0.6rem; padding:0.75rem 1.1rem; background:var(--neutral-50); border-bottom:1px solid var(--neutral-100); }
.cr-section-head h3 { font-size:0.875rem; font-weight:600; color:var(--neutral-800); margin:0; flex:1; }
.cr-section-icon { width:1.75rem; height:1.75rem; border-radius:var(--radius-sm); background:var(--primary-100); color:var(--primary-600); display:flex; align-items:center; justify-content:center; font-size:0.75rem; flex-shrink:0; }
.cr-section-body { padding:1.1rem; }
.cr-grid { display:grid; grid-template-columns:1fr 1fr; gap:1rem; }
@media(max-width:540px) { .cr-grid { grid-template-columns:1fr; } }
.cr-grid-3 { grid-template-columns:repeat(3,1fr); }
@media(max-width:680px) { .cr-grid-3 { grid-template-columns:1fr 1fr; } }
@media(max-width:400px) { .cr-grid-3 { grid-template-columns:1fr; } }
.cr-field { display:flex; flex-direction:column; gap:0.2rem; }
.cr-label { font-size:0.78rem; font-weight:600; color:var(--neutral-700); }
.cr-hint { font-size:0.72rem; color:var(--neutral-400); margin:0; line-height:1.4; }
.cr-input { width:100%; padding:0.45rem 0.65rem; font-size:0.8125rem; border:1px solid var(--neutral-200); border-radius:var(--radius-md); background:#fff; color:var(--neutral-800); outline:none; transition:border-color 0.15s, box-shadow 0.15s; }
.cr-input:focus { border-color:var(--primary-400); box-shadow:0 0 0 3px rgba(14,165,233,0.1); }
.cr-upload-zone { border:2px dashed var(--neutral-200); border-radius:var(--radius-lg); padding:1.1rem; text-align:center; cursor:pointer; transition:border-color 0.15s,background 0.15s; background:var(--neutral-50); }
.cr-upload-zone:hover,.cr-upload-zone.drag-over { border-color:var(--primary-400); background:var(--primary-50); }
.cr-upload-zone input[type=file] { display:none; }
.cr-upload-icon { font-size:1.3rem; color:var(--neutral-300); margin-bottom:0.3rem; }
.cr-upload-label { font-size:0.8rem; font-weight:600; color:var(--neutral-700); }
.cr-upload-sub { font-size:0.72rem; color:var(--neutral-400); margin-top:0.1rem; }
.cr-file-list { margin-top:0.6rem; display:flex; flex-direction:column; gap:0.4rem; }
.cr-file-item { display:flex; align-items:center; gap:0.5rem; background:#fff; border:1px solid var(--neutral-200); border-radius:var(--radius-sm); padding:0.4rem 0.6rem; font-size:0.78rem; color:var(--neutral-700); }
.cr-file-item i { color:var(--primary-400); }
.cr-file-item .cr-file-size { margin-left:auto; color:var(--neutral-400); font-size:0.7rem; }
.cr-file-item .cr-file-remove { color:var(--danger-400); cursor:pointer; padding:0 0.2rem; background:none; border:none; font-size:0.75rem; }
.cr-img-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:0.5rem; margin-top:0.75rem; }
@media(max-width:500px) { .cr-img-grid { grid-template-columns:repeat(3,1fr); } }
.cr-img-thumb { position:relative; aspect-ratio:1; border-radius:var(--radius-md); overflow:hidden; background:var(--neutral-100); }
.cr-img-thumb img { width:100%; height:100%; object-fit:cover; display:block; }
.cr-img-thumb .cr-img-remove { position:absolute; top:0.2rem; right:0.2rem; width:1.2rem; height:1.2rem; background:rgba(0,0,0,0.55); border-radius:50%; color:#fff; font-size:0.6rem; display:flex; align-items:center; justify-content:center; cursor:pointer; border:none; }
.cr-img-thumb .cr-img-delete-badge { position:absolute; bottom:0; left:0; right:0; background:rgba(220,38,38,0.85); color:#fff; font-size:0.65rem; font-weight:600; text-align:center; padding:0.15rem 0; }
.cr-existing-img { position:relative; aspect-ratio:1; border-radius:var(--radius-md); overflow:hidden; background:var(--neutral-100); }
.cr-existing-img img { width:100%; height:100%; object-fit:cover; display:block; }
.cr-existing-img label { position:absolute; inset:0; display:flex; flex-direction:column; align-items:center; justify-content:center; background:rgba(0,0,0,0); transition:background 0.15s; cursor:pointer; }
.cr-existing-img:hover label, .cr-existing-img.marked label { background:rgba(220,38,38,0.55); }
.cr-existing-img label span { color:#fff; font-size:0.7rem; font-weight:700; display:none; }
.cr-existing-img:hover label span, .cr-existing-img.marked label span { display:block; }
.cr-existing-img.marked .cr-del-check { display:block; }
.cr-del-check { display:none; position:absolute; top:0.3rem; right:0.3rem; width:1.1rem; height:1.1rem; background:var(--danger-500); border-radius:50%; color:#fff; font-size:0.6rem; display:flex; align-items:center; justify-content:center; pointer-events:none; }
</style>

<div class="cr-page">

    <!-- Breadcrumb -->
    <div class="cr-breadcrumb">
        <a href="<?php echo BASE_URL; ?>/dashboard.php"><i class="fas fa-home" style="font-size:0.7rem;"></i> Dashboard</a>
        <span class="sep">/</span>
        <a href="list.php">My Reports</a>
        <span class="sep">/</span>
        <a href="view.php?id=<?php echo $reportId; ?>"><?php echo $reportMonthLabel; ?></a>
        <span class="sep">/</span>
        <span>Edit</span>
    </div>

    <div class="cr-head">
        <h1><i class="fas fa-edit" style="color:var(--primary-500);margin-right:0.4rem;font-size:1rem;"></i>Edit Report — <?php echo $reportMonthLabel; ?></h1>
        <p>Update the numbers for this report. Changes are saved immediately when you click <strong>Save Changes</strong>.</p>
    </div>

    <?php if (isset($_SESSION['error'])): ?>
    <script>document.addEventListener('DOMContentLoaded',function(){showToast('<?php echo addslashes($_SESSION['error']); ?>','error',6000);});</script>
    <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" id="edit-form" novalidate>
        <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">

        <!-- ── Section 1: Period & Total Copies ── -->
        <div class="cr-section">
            <div class="cr-section-head">
                <div class="cr-section-icon"><i class="fas fa-calendar-alt"></i></div>
                <h3>Reporting Period &amp; Total Copies</h3>
            </div>
            <div class="cr-section-body">
                <?php
                $months = ['01'=>'January','02'=>'February','03'=>'March','04'=>'April','05'=>'May','06'=>'June','07'=>'July','08'=>'August','09'=>'September','10'=>'October','11'=>'November','12'=>'December'];
                $cy = (int)date('Y');
                ?>
                <div class="cr-grid" style="margin-bottom:1rem;">
                    <div class="cr-field">
                        <label class="cr-label" for="report_year">Year</label>
                        <select id="report_year" name="report_year" class="cr-input" required>
                            <?php for ($y=$cy; $y>=$cy-5; $y--): ?>
                            <option value="<?php echo $y; ?>" <?php echo $y==(int)$reportYearVal?'selected':''; ?>><?php echo $y; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="cr-field">
                        <label class="cr-label" for="report_month">Month</label>
                        <select id="report_month" name="report_month" class="cr-input" required>
                            <?php foreach ($months as $v => $l): ?>
                            <option value="<?php echo $v; ?>" <?php echo $v===$reportMonthVal?'selected':''; ?>><?php echo $l; ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="cr-hint">Cannot be set to a future month.</p>
                    </div>
                </div>
                <div class="cr-field">
                    <label class="cr-label" for="total_copies">Total Copies Sponsored</label>
                    <input type="number" id="total_copies" name="total_copies" class="cr-input"
                           placeholder="0" min="0" value="<?php echo (int)$report['total_copies']; ?>">
                    <p class="cr-hint">The total number of Rhapsody copies your zone sponsored this month across all programs.</p>
                </div>
            </div>
        </div>

        <!-- ── Section 2: Alerts & Languages ── -->
        <div class="cr-section">
            <div class="cr-section-head">
                <div class="cr-section-icon"><i class="fas fa-bell"></i></div>
                <h3>Alerts &amp; Language Missions</h3>
            </div>
            <div class="cr-section-body">
                <div class="cr-grid">
                    <?php
                    $s2 = [
                        'wonder_alerts'         => ['Wonder Alerts Received',     'Number of Wonder Alert messages received and distributed this month.'],
                        'kids_alerts'           => ['Kids Alerts',                'Number of Kids Alert messages received and distributed.'],
                        'language_missions'     => ['Language Missions',          'Number of language mission activities conducted.'],
                        'penetrating_truth'     => ['Penetrating with Truth',     'Copies of Penetrating Truth publications distributed.'],
                        'penetrating_languages' => ['Penetrating with Languages', 'Language-specific outreach copies distributed.'],
                    ];
                    foreach ($s2 as $name => [$label, $hint]):
                    ?>
                    <div class="cr-field">
                        <label class="cr-label" for="<?php echo $name; ?>"><?php echo $label; ?></label>
                        <input type="number" id="<?php echo $name; ?>" name="<?php echo $name; ?>"
                               class="cr-input" placeholder="0" min="0"
                               value="<?php echo (int)$report[$name]; ?>">
                        <p class="cr-hint"><?php echo $hint; ?></p>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- ── Section 3: Campaigns & Programs ── -->
        <div class="cr-section">
            <div class="cr-section-head">
                <div class="cr-section-icon"><i class="fas fa-bullhorn"></i></div>
                <h3>Campaigns &amp; Programs</h3>
            </div>
            <div class="cr-section-body">
                <div class="cr-grid">
                    <?php
                    $s3 = [
                        'youth_aglow'       => ['Youth Aglow',              'Youth Aglow outreach activities or copies distributed.'],
                        'minister_campaign' => ['Every Minister Campaign',  'Copies distributed through the Every Minister Campaign.'],
                        'say_yes_kids'      => ['Say Yes to Kids',          'Copies distributed to children under the Say Yes to Kids program.'],
                        'no_one_left'       => ['No One Left Behind',       'Activities ensuring no community is unreached.'],
                        'teevolution'       => ['Teevolution',              'Copies or activities relating to the Teevolution media outreach.'],
                        'subscriptions'     => ['Subscriptions',            'Total active or new Rhapsody subscription sign-ups this month.'],
                        'prayer_programs'   => ['Partners Prayer Programs', 'Formal prayer programs organized with or for partners.'],
                        'partner_programs'  => ['Partners Programs',        'Total partner engagement events or programs held this month.'],
                    ];
                    foreach ($s3 as $name => [$label, $hint]):
                    ?>
                    <div class="cr-field">
                        <label class="cr-label" for="<?php echo $name; ?>"><?php echo $label; ?></label>
                        <input type="number" id="<?php echo $name; ?>" name="<?php echo $name; ?>"
                               class="cr-input" placeholder="0" min="0"
                               value="<?php echo (int)$report[$name]; ?>">
                        <p class="cr-hint"><?php echo $hint; ?></p>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- ── Section 4: Distribution & Growth ── -->
        <div class="cr-section">
            <div class="cr-section-head">
                <div class="cr-section-icon"><i class="fas fa-chart-line"></i></div>
                <h3>Distribution &amp; Growth Metrics</h3>
            </div>
            <div class="cr-section-body">
                <div class="cr-grid">
                    <?php
                    $s4 = [
                        'total_distribution'   => ['Total Copies Distributed',  'Combined total copies distributed across digital and physical channels.'],
                        'distribution_digital' => ['Distribution - Digital',    'Copies distributed through digital channels and platforms.'],
                        'distribution_physical'=> ['Distribution - Physical',   'Copies physically handed out or delivered to recipients.'],
                        'souls_won'            => ['Souls Won',                 'People who made salvation decisions through outreach.'],
                        'rhapsody_outreaches'  => ['Rhapsody Outreaches',       'Organised outreach events where Rhapsody was the focus.'],
                        'rhapsody_cells'       => ['Rhapsody Cells',            'Active Rhapsody cell groups in your zone.'],
                        'new_churches'         => ['New Churches',              'New churches or fellowships established this month.'],
                        'new_partners'         => ['New Partners',              'New individuals who signed up as Rhapsody partners.'],
                        'lingual_cells'        => ['Lingual Cells',             'Cell groups meeting in a language other than English.'],
                        'language_churches'    => ['Language Churches',         'Churches primarily operating in a non-English language.'],
                        'languages_sponsored'  => ['Languages Sponsored',       'Distinct languages with Rhapsody copies produced/distributed.'],
                        'distribution_centers' => ['Distribution Centers',      'Active Rhapsody distribution points or depots in your zone.'],
                        'external_ministers'   => ['External Ministers',        'Ministers from outside your zone who supported outreach.'],
                        'crusades'             => ['Crusades',                  'Crusades held or supported this month.'],
                        'testimonies_received' => ['Testimonies Received',       'Number of testimonies received this month. This does not replace the written testimonies document upload.'],
                    ];
                    foreach ($s4 as $name => [$label, $hint]):
                    $isCalculatedDistribution = $name === 'total_distribution';
                    ?>
                    <div class="cr-field">
                        <label class="cr-label" for="<?php echo $name; ?>"><?php echo $label; ?></label>
                        <input type="number" id="<?php echo $name; ?>" name="<?php echo $name; ?>"
                               class="cr-input" placeholder="0" min="0" <?php echo $isCalculatedDistribution ? 'readonly' : ''; ?>
                               value="<?php echo (int)$report[$name]; ?>">
                        <p class="cr-hint"><?php echo $hint; ?></p>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- ── Section 5: Say Yes to Kids ── -->
        <div class="cr-section">
            <div class="cr-section-head">
                <div class="cr-section-icon"><i class="fas fa-child"></i></div>
                <h3>Say Yes to Kids — Detailed Impact</h3>
            </div>
            <div class="cr-section-body">
                <div class="cr-grid cr-grid-3">
                    <?php
                    $s5 = [
                        'syk_outreaches'         => ['Outreaches Held',     'Say Yes to Kids outreach events conducted this month.'],
                        'syk_elementary_schools' => ['Schools Reached',     'Primary/elementary schools visited or engaged.'],
                        'syk_orphanages'         => ['Orphanages Reached',  'Orphanages or children\'s homes visited.'],
                        'syk_children_reached'   => ['Children Reached',    'Total children who received materials or participated.'],
                        'syk_souls_won'          => ['Children Saved',      'Children who made salvation decisions.'],
                        'syk_souls_retained'     => ['Children Retained',   'Saved children being followed up and retained in fellowship.'],
                        'syk_rork_clubs'         => ['RORK Clubs',          'Rhapsody of Realities for Kids clubs currently active.'],
                    ];
                    foreach ($s5 as $name => [$label, $hint]):
                    ?>
                    <div class="cr-field">
                        <label class="cr-label" for="<?php echo $name; ?>"><?php echo $label; ?></label>
                        <input type="number" id="<?php echo $name; ?>" name="<?php echo $name; ?>"
                               class="cr-input" placeholder="0" min="0"
                               value="<?php echo (int)$report[$name]; ?>">
                        <p class="cr-hint"><?php echo $hint; ?></p>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- ── Section 6: Files & Images ── -->
        <div class="cr-section">
            <div class="cr-section-head">
                <div class="cr-section-icon"><i class="fas fa-paperclip"></i></div>
                <h3>Supporting Documents &amp; Images</h3>
            </div>
            <div class="cr-section-body">
                <p style="font-size:0.8rem;color:var(--neutral-500);margin:0 0 1rem;">Upload a new file to replace an existing one, or leave blank to keep the current file.</p>

                <div class="cr-grid" style="margin-bottom:1.25rem;">
                    <!-- Testimonies -->
                    <div class="cr-field">
                        <label class="cr-label">Testimonies Document</label>
                        <?php if ($report['testimonies_file']): ?>
                        <div class="cr-file-item" style="margin-bottom:0.4rem;">
                            <i class="fas fa-file-alt"></i>
                            <a href="<?php echo BASE_URL; ?>/uploads/documents/<?php echo htmlspecialchars($report['testimonies_file']); ?>"
                               target="_blank" style="color:var(--primary-600);text-decoration:none;font-size:0.78rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:12rem;">
                                <?php echo htmlspecialchars($report['testimonies_file']); ?>
                            </a>
                            <span style="margin-left:auto;font-size:0.68rem;color:var(--neutral-400);">current</span>
                        </div>
                        <?php endif; ?>
                        <div class="cr-upload-zone" id="zone-testimonies-edit" onclick="document.getElementById('testimonies_file').click()">
                            <input type="file" id="testimonies_file" name="testimonies_file" accept=".pdf,.doc,.docx">
                            <div class="cr-upload-icon"><i class="fas fa-file-alt"></i></div>
                            <div class="cr-upload-label"><?php echo $report['testimonies_file'] ? 'Click to replace' : 'Click to upload'; ?></div>
                            <div class="cr-upload-sub">PDF, DOC, DOCX — max 10 MB</div>
                        </div>
                        <div class="cr-file-list" id="list-testimonies"></div>
                    </div>
                    <!-- Innovations -->
                    <div class="cr-field">
                        <label class="cr-label">Innovations Document</label>
                        <?php if ($report['innovations_file']): ?>
                        <div class="cr-file-item" style="margin-bottom:0.4rem;">
                            <i class="fas fa-lightbulb"></i>
                            <a href="<?php echo BASE_URL; ?>/uploads/documents/<?php echo htmlspecialchars($report['innovations_file']); ?>"
                               target="_blank" style="color:var(--primary-600);text-decoration:none;font-size:0.78rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:12rem;">
                                <?php echo htmlspecialchars($report['innovations_file']); ?>
                            </a>
                            <span style="margin-left:auto;font-size:0.68rem;color:var(--neutral-400);">current</span>
                        </div>
                        <?php endif; ?>
                        <div class="cr-upload-zone" id="zone-innovations-edit" onclick="document.getElementById('innovations_file').click()">
                            <input type="file" id="innovations_file" name="innovations_file" accept=".pdf,.doc,.docx">
                            <div class="cr-upload-icon"><i class="fas fa-lightbulb"></i></div>
                            <div class="cr-upload-label"><?php echo $report['innovations_file'] ? 'Click to replace' : 'Click to upload'; ?></div>
                            <div class="cr-upload-sub">PDF, DOC, DOCX — max 10 MB</div>
                        </div>
                        <div class="cr-file-list" id="list-innovations"></div>
                    </div>
                </div>

                <!-- Existing images -->
                <?php if ($images): ?>
                <div style="margin-bottom:1rem;">
                    <p class="cr-label" style="margin-bottom:0.5rem;">Current Images <span style="font-weight:400;color:var(--neutral-400);font-size:0.7rem;">— click an image to mark it for deletion</span></p>
                    <div class="cr-img-grid">
                        <?php foreach ($images as $img): ?>
                        <div class="cr-existing-img" onclick="toggleDeleteImg(this)">
                            <img src="<?php echo BASE_URL; ?>/uploads/pictures/<?php echo htmlspecialchars($img['image_path']); ?>" alt="">
                            <label><span><i class="fas fa-trash-alt"></i> Remove</span></label>
                            <input type="checkbox" name="delete_images[]" value="<?php echo (int)$img['id']; ?>"
                                   style="display:none;" class="del-img-cb">
                            <div class="cr-del-check"><i class="fas fa-times"></i></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- New images -->
                <div class="cr-field">
                    <label class="cr-label">Add New Images <span style="font-weight:400;color:var(--neutral-400);font-size:0.7rem;">(optional)</span></label>
                    <div class="cr-upload-zone" id="zone-images" onclick="document.getElementById('report_images').click()">
                        <input type="file" id="report_images" name="report_images[]" multiple accept="image/*">
                        <div class="cr-upload-icon"><i class="fas fa-images"></i></div>
                        <div class="cr-upload-label">Click or drag &amp; drop images here</div>
                        <div class="cr-upload-sub">JPG, PNG, GIF — up to 10 images</div>
                    </div>
                    <div class="cr-img-grid" id="img-preview"></div>
                </div>

                <!-- Submit -->
                <div style="border-top:1px solid var(--neutral-100);margin-top:1.5rem;padding-top:1.25rem;display:flex;gap:0.75rem;align-items:center;flex-wrap:wrap;">
                    <button type="submit" id="submit-report-btn" class="modern-button modern-button-primary"
                            data-loading-text="Saving changes…" style="min-width:10rem;">
                        <i class="fas fa-save modern-button-icon"></i> Save Changes
                    </button>
                    <a href="view.php?id=<?php echo $reportId; ?>" class="modern-button modern-button-secondary" style="font-size:0.8125rem;">
                        Cancel
                    </a>
                </div>
            </div>
        </div>

    </form>
</div>

<script>
(function() {

function validateReportDate() {
    var m = parseInt(document.getElementById('report_month').value, 10);
    var y = parseInt(document.getElementById('report_year').value, 10);
    var now = new Date();
    if (y > now.getFullYear() || (y === now.getFullYear() && m > now.getMonth() + 1)) {
        showToast('You cannot select a future month.', 'error');
        document.getElementById('report_month').value = String(now.getMonth()+1).padStart(2,'0');
        document.getElementById('report_year').value  = now.getFullYear();
        return false;
    }
    return true;
}

function updateTotalDistribution() {
    var digital = document.getElementById('distribution_digital');
    var physical = document.getElementById('distribution_physical');
    var total = document.getElementById('total_distribution');
    if (!digital || !physical || !total) return;
    total.value = (parseInt(digital.value, 10) || 0) + (parseInt(physical.value, 10) || 0);
}

function setupUploadZone(zoneId, inputId, listId) {
    var zone  = document.getElementById(zoneId);
    var input = document.getElementById(inputId);
    var list  = document.getElementById(listId);
    if (!zone || !input || !list) return;
    function fmtSize(b) { return b<1048576?(b/1024).toFixed(1)+' KB':(b/1048576).toFixed(1)+' MB'; }
    function renderFile(file) {
        list.innerHTML = '';
        var ext = (file.name.split('.').pop()||'').toLowerCase();
        var icon = ext==='pdf'?'fa-file-pdf':'fa-file-word';
        var item = document.createElement('div'); item.className='cr-file-item';
        item.innerHTML='<i class="fas '+icon+'"></i><span>'+file.name+'</span>'
            +'<span class="cr-file-size">'+fmtSize(file.size)+'</span>'
            +'<button type="button" class="cr-file-remove" title="Remove"><i class="fas fa-times"></i></button>';
        item.querySelector('.cr-file-remove').addEventListener('click',function(){ input.value=''; list.innerHTML=''; zone.style.borderColor=''; });
        list.appendChild(item);
        zone.style.borderColor='var(--success-400)';
    }
    input.addEventListener('change', function(){ if(this.files&&this.files[0]) renderFile(this.files[0]); });
    zone.addEventListener('dragover',function(e){e.preventDefault();zone.classList.add('drag-over');});
    zone.addEventListener('dragleave',function(){zone.classList.remove('drag-over');});
    zone.addEventListener('drop',function(e){
        e.preventDefault();zone.classList.remove('drag-over');
        var dt=e.dataTransfer;
        if(dt&&dt.files&&dt.files[0]){try{var t=new DataTransfer();t.items.add(dt.files[0]);input.files=t.files;renderFile(dt.files[0]);}catch(err){}}
    });
}

function setupImageUpload() {
    var zone=document.getElementById('zone-images');
    var input=document.getElementById('report_images');
    var preview=document.getElementById('img-preview');
    if(!zone||!input||!preview) return;
    var imageFiles=[];
    function renderPreviews(){
        preview.innerHTML='';
        imageFiles.forEach(function(file,idx){
            var thumb=document.createElement('div');thumb.className='cr-img-thumb';
            var reader=new FileReader();
            reader.onload=function(e){
                thumb.innerHTML='<img src="'+e.target.result+'" alt="">'
                    +'<button type="button" class="cr-img-remove" data-idx="'+idx+'" title="Remove"><i class="fas fa-times"></i></button>';
                thumb.querySelector('.cr-img-remove').addEventListener('click',function(){
                    imageFiles.splice(parseInt(this.dataset.idx,10),1);rebuildInput();renderPreviews();
                });
            };
            reader.readAsDataURL(file);
            preview.appendChild(thumb);
        });
    }
    function rebuildInput(){try{var dt=new DataTransfer();imageFiles.forEach(function(f){dt.items.add(f);});input.files=dt.files;}catch(e){}}
    function addFiles(files){
        Array.from(files).forEach(function(f){if(f.type.startsWith('image/')&&imageFiles.length<10)imageFiles.push(f);});
        rebuildInput();renderPreviews();
        if(files.length) showToast(imageFiles.length+' image'+(imageFiles.length!==1?'s':'')+' selected','success');
    }
    input.addEventListener('change',function(){addFiles(this.files);});
    zone.addEventListener('dragover',function(e){e.preventDefault();zone.classList.add('drag-over');});
    zone.addEventListener('dragleave',function(){zone.classList.remove('drag-over');});
    zone.addEventListener('drop',function(e){e.preventDefault();zone.classList.remove('drag-over');if(e.dataTransfer&&e.dataTransfer.files)addFiles(e.dataTransfer.files);});
}

window.toggleDeleteImg = function(el) {
    el.classList.toggle('marked');
    var cb = el.querySelector('.del-img-cb');
    if (cb) cb.checked = el.classList.contains('marked');
};

document.addEventListener('DOMContentLoaded', function() {
    var mo = document.getElementById('report_month');
    var yo = document.getElementById('report_year');
    if (mo) mo.addEventListener('change', validateReportDate);
    if (yo) yo.addEventListener('change', validateReportDate);

    setupUploadZone('zone-testimonies-edit', 'testimonies_file', 'list-testimonies');
    setupUploadZone('zone-innovations-edit',  'innovations_file',  'list-innovations');
    setupImageUpload();

    ['distribution_digital', 'distribution_physical'].forEach(function(id) {
        var input = document.getElementById(id);
        if (input) input.addEventListener('input', updateTotalDistribution);
    });
    updateTotalDistribution();

    var form = document.getElementById('edit-form');
    form.addEventListener('submit', function(e) {
        if (!validateReportDate()) { e.preventDefault(); return; }
        updateTotalDistribution();
        var toDelete = document.querySelectorAll('input[name="delete_images[]"]:checked').length;
        if (toDelete > 0) {
            e.preventDefault();
            Swal.fire({
                title: 'Delete ' + toDelete + ' image' + (toDelete > 1 ? 's' : '') + '?',
                text: 'This cannot be undone.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: 'var(--danger-600)',
                confirmButtonText: 'Yes, delete',
                cancelButtonText: 'Cancel'
            }).then(function(result) {
                if (result.isConfirmed) form.submit();
            });
        }
    });
});

})();
</script>

<?php require_once '../../layouts/footer.php'; ?>
