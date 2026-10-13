<?php
require_once '../../config/config.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../includes/audit.php';

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
        if ($_POST === [] && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            throw new Exception('The submission was larger than the server upload limit. Please keep each file under 10 MB and try again.');
        }

        // CSRF validation
        if (!isset($_POST['csrf_token'])) {
            throw new Exception('Missing CSRF token');
        }
        Security::verifyCSRFToken($_POST['csrf_token']);
        // Validate and format the date
        $reportYear = $_POST['report_year'] ?? date('Y');
        $reportMonth = $_POST['report_month'] ?? date('m');

        // Ensure we have valid whole-number values in the supported range.
        if (!preg_match('/^\d{4}$/', (string)$reportYear) ||
            !preg_match('/^(0?[1-9]|1[0-2])$/', (string)$reportMonth)) {
            throw new Exception("Invalid date format. Please select a valid month and year.");
        }

        // Create a properly formatted date string for comparison
        $reportMonthFormatted = sprintf('%04d-%02d', (int)$reportYear, (int)$reportMonth);
        $latestAllowedMonth = getLatestAllowedReportMonth();

        if ($reportMonthFormatted > $latestAllowedMonth) {
            throw new Exception("You cannot submit reports for future months.");
        }

        // Serialize submissions per user so simultaneous requests cannot both
        // pass the duplicate check when historical duplicates prevent a unique index.
        $conn->beginTransaction();
        $hasTransaction = true;
        $userLock = $conn->prepare('SELECT id FROM users WHERE id = :user_id FOR UPDATE');
        $userLock->execute([':user_id' => $_SESSION['user_id']]);
        if (!$userLock->fetch()) {
            throw new Exception('Your user account could not be found. Please sign in again.');
        }

        // Check if a report already exists for this month while holding the user lock.
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

        // Insert report data
        $reportQuery = "INSERT INTO reports (
            user_id, report_month, total_copies,
            wonder_alerts, kids_alerts, language_missions, penetrating_truth,
            penetrating_languages, youth_aglow, minister_campaign, say_yes_kids,
            no_one_left, teevolution, subscriptions, prayer_programs,
            partner_programs, total_distribution, distribution_digital, distribution_physical,
            souls_won, rhapsody_outreaches,
            rhapsody_cells, new_churches, new_partners, lingual_cells,
            language_churches, languages_sponsored, distribution_centers,
            external_ministers, crusades, testimonies_received, testimonies_file, innovations_file,
            syk_outreaches, syk_elementary_schools, syk_orphanages,
            syk_children_reached, syk_souls_won, syk_souls_retained, syk_rork_clubs
        ) VALUES (
            :user_id, :report_month, :total_copies,
            :wonder_alerts, :kids_alerts, :language_missions, :penetrating_truth,
            :penetrating_languages, :youth_aglow, :minister_campaign, :say_yes_kids,
            :no_one_left, :teevolution, :subscriptions, :prayer_programs,
            :partner_programs, :total_distribution, :distribution_digital, :distribution_physical,
            :souls_won, :rhapsody_outreaches,
            :rhapsody_cells, :new_churches, :new_partners, :lingual_cells,
            :language_churches, :languages_sponsored, :distribution_centers,
            :external_ministers, :crusades, :testimonies_received, :testimonies_file, :innovations_file,
            :syk_outreaches, :syk_elementary_schools, :syk_orphanages,
            :syk_children_reached, :syk_souls_won, :syk_souls_retained, :syk_rork_clubs
        )";

        $stmt = $conn->prepare($reportQuery);

        // Handle file uploads for testimonies and innovations
        $testimonies_file = '';
        $innovations_file = '';

        if (isset($_FILES['testimonies_file']) && $_FILES['testimonies_file']['error'] !== UPLOAD_ERR_NO_FILE) {
            $testimonies_file = uploadFile($_FILES['testimonies_file'], '../../uploads/documents/', ['pdf','doc','docx']);
        }

        if (isset($_FILES['innovations_file']) && $_FILES['innovations_file']['error'] !== UPLOAD_ERR_NO_FILE) {
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

        $distributionDigital = safeIntVal($_POST['distribution_digital'] ?? '');
        $distributionPhysical = safeIntVal($_POST['distribution_physical'] ?? '');
        $totalDistribution = $distributionDigital + $distributionPhysical;

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
            ':total_distribution' => $totalDistribution,
            ':distribution_digital' => $distributionDigital,
            ':distribution_physical' => $distributionPhysical,
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
            ':crusades' => safeIntVal($_POST['crusades'] ?? ''),
            ':testimonies_received' => safeIntVal($_POST['testimonies_received'] ?? ''),
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
                if ($file['error'] === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
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

        $conn->commit();
        Audit::log('submit_report', 'report', (int)$reportId,
            'Submitted report #' . $reportId . ' for ' . date('F Y', strtotime($reportYear . '-' . $reportMonth . '-01'))
        );
        $_SESSION['success'] = "Report for " . date('F Y', strtotime($reportYear . '-' . $reportMonth . '-01')) . " was submitted successfully.";
        header('Location: view.php?id=' . $reportId . '&submitted=1');
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

// ── Fetch last submitted report for pre-fill & comparison ──
$db2 = new Database();
$conn2 = $db2->getConnection();
$lastReport = null;
try {
    $stmt2 = $conn2->prepare(
        "SELECT * FROM reports WHERE user_id = ? ORDER BY report_month DESC LIMIT 1"
    );
    $stmt2->execute([$_SESSION['user_id']]);
    $lastReport = $stmt2->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) { /* non-fatal */ }

// Pre-fill: use last report values as defaults (only numeric fields)
$prefill = [];
$compareFields = [
    'total_copies','wonder_alerts','kids_alerts','language_missions',
    'penetrating_truth','penetrating_languages','youth_aglow','minister_campaign',
    'say_yes_kids','no_one_left','teevolution','subscriptions',
    'prayer_programs','partner_programs','total_distribution','distribution_digital','distribution_physical','souls_won',
    'rhapsody_outreaches','rhapsody_cells','new_churches','new_partners',
    'lingual_cells','language_churches','languages_sponsored','distribution_centers',
    'external_ministers','crusades','testimonies_received','syk_outreaches','syk_elementary_schools','syk_orphanages',
    'syk_children_reached','syk_souls_won','syk_souls_retained','syk_rork_clubs',
];
foreach ($compareFields as $f) {
    // Populate prefill from last report so data-last attributes carry real values for comparison chips
    $prefill[$f] = ($lastReport && isset($lastReport[$f])) ? (int)$lastReport[$f] : 0;
}
$lastReportLabel = $lastReport
    ? date('F Y', strtotime($lastReport['report_month']))
    : null;

// Include header
require_once '../../layouts/header.php';
?>

<style>
/* ── Create Report Page ── */
.cr-page { max-width: 52rem; margin: 0 auto; padding: 1.5rem 1rem 3rem; }
.cr-breadcrumb { display:flex; align-items:center; gap:0.35rem; font-size:0.78rem; color:var(--neutral-400); margin-bottom:1.25rem; flex-wrap:wrap; }
.cr-breadcrumb a { color:var(--neutral-500); text-decoration:none; }
.cr-breadcrumb a:hover { color:var(--primary-600); }
.cr-breadcrumb .sep { color:var(--neutral-300); }
.cr-head { margin-bottom:1.5rem; }
.cr-head h1 { font-size:1.25rem; font-weight:700; color:var(--neutral-900); margin:0 0 0.25rem; }
.cr-head p { font-size:0.8125rem; color:var(--neutral-500); margin:0; }

/* Progress steps */
.cr-steps { display:flex; align-items:center; gap:0; margin-bottom:2rem; background:#fff; border:1px solid var(--neutral-200); border-radius:var(--radius-lg); overflow:hidden; }
.cr-step { flex:1; display:flex; flex-direction:column; align-items:center; padding:0.65rem 0.5rem; font-size:0.72rem; color:var(--neutral-400); cursor:pointer; border-right:1px solid var(--neutral-200); transition:background 0.15s; position:relative; }
.cr-step:last-child { border-right:none; }
.cr-step.active { background:var(--primary-50); color:var(--primary-700); font-weight:600; }
.cr-step.done { background:var(--success-50); color:var(--success-700); }
.cr-step.done .cr-step-num::after { content:'✓'; }
.cr-step-num { width:1.4rem; height:1.4rem; border-radius:50%; background:var(--neutral-100); color:var(--neutral-500); font-size:0.7rem; font-weight:700; display:flex; align-items:center; justify-content:center; margin-bottom:0.2rem; }
.cr-step.active .cr-step-num { background:var(--primary-500); color:#fff; }
.cr-step.done .cr-step-num { background:var(--success-500); color:#fff; font-size:0; }
.cr-step-label { text-align:center; line-height:1.3; }
@media(max-width:600px) { .cr-step-label { display:none; } .cr-step { padding:0.5rem 0.25rem; } }

/* Draft banner */
.cr-draft-bar { display:flex; align-items:center; gap:0.6rem; background:var(--warning-50); border:1px solid var(--warning-200); border-radius:var(--radius-md); padding:0.5rem 0.85rem; font-size:0.78rem; color:var(--warning-800); margin-bottom:1rem; }
.cr-draft-bar i { color:var(--warning-500); flex-shrink:0; }
.cr-draft-bar button { margin-left:auto; font-size:0.75rem; background:none; border:none; color:var(--warning-700); cursor:pointer; text-decoration:underline; padding:0; }

/* Compare bar */
.cr-compare-bar { display:flex; align-items:center; gap:0.6rem; background:var(--primary-50); border:1px solid var(--primary-200); border-radius:var(--radius-md); padding:0.5rem 0.85rem; font-size:0.78rem; color:var(--primary-800); margin-bottom:1rem; }
.cr-compare-bar label { display:flex; align-items:center; gap:0.45rem; cursor:pointer; user-select:none; }
.cr-compare-bar input[type=checkbox] { width:1rem; height:1rem; accent-color:var(--primary-500); cursor:pointer; }

/* Form sections */
.cr-section { border:1px solid var(--neutral-200); border-radius:var(--radius-lg); background:#fff; overflow:hidden; margin-bottom:1.25rem; display:none; }
.cr-section.visible { display:block; }
.cr-section-head { display:flex; align-items:center; gap:0.6rem; padding:0.75rem 1.1rem; background:var(--neutral-50); border-bottom:1px solid var(--neutral-100); }
.cr-section-head h3 { font-size:0.875rem; font-weight:600; color:var(--neutral-800); margin:0; flex:1; }
.cr-section-head .cr-section-icon { width:1.75rem; height:1.75rem; border-radius:var(--radius-sm); background:var(--primary-100); color:var(--primary-600); display:flex; align-items:center; justify-content:center; font-size:0.75rem; flex-shrink:0; }
.cr-section-body { padding:1.1rem; }
.cr-grid { display:grid; grid-template-columns:1fr 1fr; gap:1rem; }
@media(max-width:540px) { .cr-grid { grid-template-columns:1fr; } }
.cr-grid-3 { grid-template-columns:repeat(3,1fr); }
@media(max-width:680px) { .cr-grid-3 { grid-template-columns:1fr 1fr; } }
@media(max-width:400px) { .cr-grid-3 { grid-template-columns:1fr; } }

/* Field group */
.cr-field { display:flex; flex-direction:column; gap:0.2rem; }
.cr-label { font-size:0.78rem; font-weight:600; color:var(--neutral-700); display:flex; align-items:center; gap:0.35rem; }
.cr-hint { font-size:0.72rem; color:var(--neutral-400); margin:0; line-height:1.4; }
.cr-input-wrap { position:relative; }
.cr-input { width:100%; padding:0.45rem 0.65rem; font-size:0.8125rem; border:1px solid var(--neutral-200); border-radius:var(--radius-md); background:#fff; color:var(--neutral-800); outline:none; transition:border-color 0.15s, box-shadow 0.15s; }
.cr-input:focus { border-color:var(--primary-400); box-shadow:0 0 0 3px rgba(14,165,233,0.1); }
.cr-input.is-error { border-color:var(--danger-400); box-shadow:0 0 0 3px rgba(220,38,38,0.08); }
.cr-input.is-ok { border-color:var(--success-400); }
.cr-error-msg { font-size:0.71rem; color:var(--danger-600); margin:0; display:none; }
.cr-compare-chip { display:inline-flex; align-items:center; gap:0.25rem; font-size:0.68rem; padding:0.1rem 0.45rem; border-radius:9999px; font-weight:600; margin-top:0.2rem; }
.cr-compare-chip.up   { background:var(--success-50); color:var(--success-700); }
.cr-compare-chip.down { background:var(--danger-50);  color:var(--danger-700); }
.cr-compare-chip.same { background:var(--neutral-100); color:var(--neutral-500); }
.cr-compare-hint { font-size:0.7rem; color:var(--neutral-400); margin-top:0.15rem; display:none; }
.cr-compare-on .cr-compare-hint { display:block; }

/* File upload zones */
.cr-upload-zone { border:2px dashed var(--neutral-200); border-radius:var(--radius-lg); padding:1.25rem; text-align:center; cursor:pointer; transition:border-color 0.15s, background 0.15s; background:var(--neutral-50); }
.cr-upload-zone:hover, .cr-upload-zone.drag-over { border-color:var(--primary-400); background:var(--primary-50); }
.cr-upload-zone input[type=file] { display:none; }
.cr-upload-icon { font-size:1.4rem; color:var(--neutral-300); margin-bottom:0.4rem; }
.cr-upload-label { font-size:0.8rem; font-weight:600; color:var(--neutral-700); }
.cr-upload-sub { font-size:0.72rem; color:var(--neutral-400); margin-top:0.15rem; }
.cr-file-list { margin-top:0.75rem; display:flex; flex-direction:column; gap:0.4rem; }
.cr-file-item { display:flex; align-items:center; gap:0.5rem; background:#fff; border:1px solid var(--neutral-200); border-radius:var(--radius-sm); padding:0.4rem 0.6rem; font-size:0.78rem; color:var(--neutral-700); }
.cr-file-item i { color:var(--primary-400); }
.cr-file-item .cr-file-size { margin-left:auto; color:var(--neutral-400); font-size:0.7rem; }
.cr-file-item .cr-file-remove { color:var(--danger-400); cursor:pointer; padding:0 0.2rem; background:none; border:none; font-size:0.75rem; }
.cr-img-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:0.5rem; margin-top:0.75rem; }
@media(max-width:500px) { .cr-img-grid { grid-template-columns:repeat(3,1fr); } }
.cr-img-thumb { position:relative; aspect-ratio:1; border-radius:var(--radius-md); overflow:hidden; background:var(--neutral-100); }
.cr-img-thumb img { width:100%; height:100%; object-fit:cover; display:block; }
.cr-img-thumb .cr-img-remove { position:absolute; top:0.2rem; right:0.2rem; width:1.2rem; height:1.2rem; background:rgba(0,0,0,0.55); border-radius:50%; color:#fff; font-size:0.6rem; display:flex; align-items:center; justify-content:center; cursor:pointer; border:none; }
.cr-upload-progress { height:3px; background:var(--neutral-200); border-radius:2px; overflow:hidden; margin-top:0.4rem; display:none; }
.cr-upload-progress-bar { height:100%; background:var(--primary-500); transition:width 0.3s; width:0%; }

/* Nav buttons */
.cr-nav { display:flex; align-items:center; justify-content:space-between; padding:1rem 0 0; gap:0.5rem; }
.cr-nav-left { display:flex; gap:0.5rem; }

/* Prefill notice */
.cr-prefill-notice { display:flex; align-items:center; gap:0.5rem; font-size:0.75rem; color:var(--primary-700); background:var(--primary-50); border:1px solid var(--primary-200); border-radius:var(--radius-sm); padding:0.4rem 0.7rem; margin-bottom:1rem; }
</style>

<div class="cr-page">

    <!-- Breadcrumb -->
    <div class="cr-breadcrumb">
        <a href="<?php echo BASE_URL; ?>/dashboard.php"><i class="fas fa-home" style="font-size:0.7rem;"></i> Dashboard</a>
        <span class="sep">/</span>
        <a href="list.php">My Reports</a>
        <span class="sep">/</span>
        <span>Submit Report</span>
    </div>

    <div class="cr-head">
        <h1><i class="fas fa-paper-plane" style="color:var(--primary-500);margin-right:0.4rem;font-size:1rem;"></i>Submit Monthly Report</h1>
        <p>Fill in your activity numbers for the selected month. All fields default to 0 if left blank.</p>
    </div>

    <!-- Draft restore banner (shown by JS if draft found) -->
    <div class="cr-draft-bar" id="cr-draft-bar" style="display:none;">
        <i class="fas fa-clock"></i>
        <span>You have an unsaved draft from a previous session.</span>
        <button type="button" onclick="crRestoreDraft()">Restore Draft</button>
        <button type="button" onclick="crDiscardDraft()" style="margin-left:0.5rem;color:var(--neutral-400);">Discard</button>
    </div>

    <?php if ($lastReportLabel): ?>
    <!-- Compare toggle -->
    <div class="cr-compare-bar" id="cr-compare-bar">
        <i class="fas fa-balance-scale"></i>
        <label>
            <input type="checkbox" id="cr-compare-toggle">
            Show comparison with <strong style="margin-left:0.2rem;"><?php echo $lastReportLabel; ?></strong> values
        </label>
    </div>
    <?php endif; ?>

    <!-- Progress steps -->
    <div class="cr-steps" id="cr-steps">
        <div class="cr-step active" data-step="0" onclick="crGoStep(0)">
            <div class="cr-step-num">1</div>
            <div class="cr-step-label">Period &amp; Copies</div>
        </div>
        <div class="cr-step" data-step="1" onclick="crGoStep(1)">
            <div class="cr-step-num">2</div>
            <div class="cr-step-label">Alerts &amp; Languages</div>
        </div>
        <div class="cr-step" data-step="2" onclick="crGoStep(2)">
            <div class="cr-step-num">3</div>
            <div class="cr-step-label">Campaigns &amp; Programs</div>
        </div>
        <div class="cr-step" data-step="3" onclick="crGoStep(3)">
            <div class="cr-step-num">4</div>
            <div class="cr-step-label">Distribution &amp; Growth</div>
        </div>
        <div class="cr-step" data-step="4" onclick="crGoStep(4)">
            <div class="cr-step-num">5</div>
            <div class="cr-step-label">Say Yes to Kids</div>
        </div>
        <div class="cr-step" data-step="5" onclick="crGoStep(5)">
            <div class="cr-step-num">6</div>
            <div class="cr-step-label">Files &amp; Submit</div>
        </div>
    </div>

    <?php if (isset($_SESSION['error'])): ?>
    <script>document.addEventListener('DOMContentLoaded',function(){showToast('<?php echo addslashes($_SESSION['error']); ?>','error',6000);});</script>
    <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" id="cr-form" novalidate>
        <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">

        <!-- ── STEP 0: Period & Total Copies ── -->
        <div class="cr-section visible" data-section="0">
            <div class="cr-section-head">
                <div class="cr-section-icon"><i class="fas fa-calendar-alt"></i></div>
                <h3>Reporting Period &amp; Total Copies</h3>
            </div>
            <div class="cr-section-body">
                <?php
                $preMonth = isset($_GET['month']) ? preg_replace('/[^0-9\-]/', '', $_GET['month']) : '';
                $preYear  = (int)date('Y');
                $preMonthNum = date('m');
                if ($preMonth && preg_match('/^(\d{4})-(\d{2})$/', $preMonth, $pm)) {
                    $preYear     = (int)$pm[1];
                    $preMonthNum = $pm[2];
                }
                $months = ['01'=>'January','02'=>'February','03'=>'March','04'=>'April','05'=>'May','06'=>'June','07'=>'July','08'=>'August','09'=>'September','10'=>'October','11'=>'November','12'=>'December'];
                ?>
                <div class="cr-grid" style="margin-bottom:1rem;">
                    <div class="cr-field">
                        <label class="cr-label" for="report_year">Year</label>
                        <select id="report_year" name="report_year" class="cr-input" required>
                            <?php $cy = (int)date('Y'); for ($y=$cy; $y>=$cy-5; $y--): ?>
                            <option value="<?php echo $y; ?>" <?php echo $y===$preYear?'selected':''; ?>><?php echo $y; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="cr-field">
                        <label class="cr-label" for="report_month">Month</label>
                        <select id="report_month" name="report_month" class="cr-input" required>
                            <?php foreach ($months as $v => $l): ?>
                            <option value="<?php echo $v; ?>" <?php echo $v===$preMonthNum?'selected':''; ?>><?php echo $l; ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="cr-hint">You can only submit reports for past or current months — not future ones.</p>
                    </div>
                </div>
                <div class="cr-field">
                    <label class="cr-label" for="total_copies">
                        Total Copies Sponsored
                        <span style="font-weight:400;color:var(--neutral-400);font-size:0.7rem;">(required)</span>
                    </label>
                    <div class="cr-input-wrap">
                        <input type="number" id="total_copies" name="total_copies" class="cr-input"
                               placeholder="e.g. 500" min="0"
                               value="<?php echo (int)$prefill['total_copies']; ?>"
                               data-field="total_copies" data-last="<?php echo (int)$prefill['total_copies']; ?>">
                    </div>
                    <p class="cr-hint">The total number of Rhapsody copies your zone sponsored this month across all programs.</p>
                    <div class="cr-compare-hint" data-compare-for="total_copies"></div>
                    <p class="cr-error-msg" id="err-total_copies">Please enter the number of copies sponsored (0 or more).</p>
                </div>
            </div>
        </div>

        <!-- ── STEP 1: Alerts & Languages ── -->
        <div class="cr-section" data-section="1">
            <div class="cr-section-head">
                <div class="cr-section-icon"><i class="fas fa-bell"></i></div>
                <h3>Alerts &amp; Language Missions</h3>
            </div>
            <div class="cr-section-body">
                <div class="cr-grid">
                    <?php
                    $alertLangFields = [
                        'wonder_alerts'          => ['Wonder Alerts Received',      'Number of Wonder Alert messages received and distributed in your zone this month.'],
                        'kids_alerts'            => ['Kids Alerts',                 'Number of Kids Alert messages received and distributed.'],
                        'language_missions'      => ['Language Missions',           'Number of language mission activities conducted — reaching communities in their native language.'],
                        'penetrating_truth'      => ['Penetrating with Truth',      'Copies of Penetrating Truth publications distributed.'],
                        'penetrating_languages'  => ['Penetrating with Languages',  'Language-specific outreach copies distributed under Penetrating with Languages.'],
                    ];
                    foreach ($alertLangFields as $name => [$label, $hint]):
                    ?>
                    <div class="cr-field">
                        <label class="cr-label" for="<?php echo $name; ?>"><?php echo $label; ?></label>
                        <input type="number" id="<?php echo $name; ?>" name="<?php echo $name; ?>"
                               class="cr-input" placeholder="0" min="0"
                               value="<?php echo (int)$prefill[$name]; ?>"
                               data-field="<?php echo $name; ?>" data-last="<?php echo (int)$prefill[$name]; ?>">
                        <p class="cr-hint"><?php echo $hint; ?></p>
                        <div class="cr-compare-hint" data-compare-for="<?php echo $name; ?>"></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- ── STEP 2: Campaigns & Programs ── -->
        <div class="cr-section" data-section="2">
            <div class="cr-section-head">
                <div class="cr-section-icon"><i class="fas fa-bullhorn"></i></div>
                <h3>Campaigns &amp; Programs</h3>
            </div>
            <div class="cr-section-body">
                <div class="cr-grid">
                    <?php
                    $campaignFields = [
                        'youth_aglow'       => ['Youth Aglow',              'Number of Youth Aglow outreach activities or copies distributed.'],
                        'minister_campaign' => ['Every Minister Campaign',  'Copies distributed through the Every Minister Campaign initiative.'],
                        'say_yes_kids'      => ['Say Yes to Kids',          'Copies specifically distributed to or for children under the Say Yes to Kids program.'],
                        'no_one_left'       => ['No One Left Behind',       'Activities or copies directed at ensuring no community is unreached.'],
                        'teevolution'       => ['Teevolution',              'Copies or activities relating to the Teevolution media/TV outreach program.'],
                        'subscriptions'     => ['Subscriptions',            'Total active or new Rhapsody subscription sign-ups this month.'],
                        'prayer_programs'   => ['Partners Prayer Programs', 'Number of formal prayer programs organized with or for partners.'],
                        'partner_programs'  => ['Partners Programs',        'Total partner engagement events or programs held this month.'],
                    ];
                    foreach ($campaignFields as $name => [$label, $hint]):
                    ?>
                    <div class="cr-field">
                        <label class="cr-label" for="<?php echo $name; ?>"><?php echo $label; ?></label>
                        <input type="number" id="<?php echo $name; ?>" name="<?php echo $name; ?>"
                               class="cr-input" placeholder="0" min="0"
                               value="<?php echo (int)$prefill[$name]; ?>"
                               data-field="<?php echo $name; ?>" data-last="<?php echo (int)$prefill[$name]; ?>">
                        <p class="cr-hint"><?php echo $hint; ?></p>
                        <div class="cr-compare-hint" data-compare-for="<?php echo $name; ?>"></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- ── STEP 3: Distribution & Growth ── -->
        <div class="cr-section" data-section="3">
            <div class="cr-section-head">
                <div class="cr-section-icon"><i class="fas fa-chart-line"></i></div>
                <h3>Distribution &amp; Growth Metrics</h3>
            </div>
            <div class="cr-section-body">
                <div class="cr-grid">
                    <?php
                    $distFields = [
                        'total_distribution'  => ['Total Copies Distributed',  'Combined total copies distributed across digital and physical channels.'],
                        'distribution_digital'=> ['Distribution - Digital',    'Copies distributed through digital channels and platforms.'],
                        'distribution_physical'=> ['Distribution - Physical',  'Copies physically handed out or delivered to recipients.'],
                        'souls_won'           => ['Souls Won',                 'Number of people who made salvation decisions through your outreach activities.'],
                        'rhapsody_outreaches' => ['Rhapsody Outreaches',       'Number of organised outreach events where Rhapsody was the primary focus.'],
                        'rhapsody_cells'      => ['Rhapsody Cells',            'Number of active Rhapsody cell groups currently running in your zone.'],
                        'new_churches'        => ['New Churches',              'Number of new churches or fellowships established this month.'],
                        'new_partners'        => ['New Partners',              'Number of new individuals who signed up as Rhapsody partners.'],
                        'lingual_cells'       => ['Lingual Cells',            'Cell groups conducting meetings in a language other than English.'],
                        'language_churches'   => ['Language Churches',        'Churches or fellowships that primarily operate in a non-English language.'],
                        'languages_sponsored' => ['Languages Sponsored',      'How many distinct languages had Rhapsody copies produced/distributed in your zone.'],
                        'distribution_centers'=> ['Distribution Centers',     'Number of active Rhapsody distribution points or depots in your zone.'],
                        'external_ministers'  => ['External Ministers',       'Ministers from outside your zone who participated in or supported outreach this month.'],
                        'crusades'            => ['Crusades',                 'Number of crusades held or supported this month.'],
                        'testimonies_received'=> ['Testimonies Received',      'Number of testimonies received this month. You can still upload the written testimonies document later.'],
                    ];
                    foreach ($distFields as $name => [$label, $hint]):
                    $isCalculatedDistribution = $name === 'total_distribution';
                    ?>
                    <div class="cr-field">
                        <label class="cr-label" for="<?php echo $name; ?>"><?php echo $label; ?></label>
                        <input type="number" id="<?php echo $name; ?>" name="<?php echo $name; ?>"
                               class="cr-input" placeholder="0" min="0" <?php echo $isCalculatedDistribution ? 'readonly' : ''; ?>
                               value="<?php echo (int)$prefill[$name]; ?>"
                               data-field="<?php echo $name; ?>" data-last="<?php echo (int)$prefill[$name]; ?>">
                        <p class="cr-hint"><?php echo $hint; ?></p>
                        <div class="cr-compare-hint" data-compare-for="<?php echo $name; ?>"></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- ── STEP 4: Say Yes to Kids ── -->
        <div class="cr-section" data-section="4">
            <div class="cr-section-head">
                <div class="cr-section-icon"><i class="fas fa-child"></i></div>
                <h3>Say Yes to Kids — Detailed Impact</h3>
            </div>
            <div class="cr-section-body">
                <div class="cr-grid cr-grid-3">
                    <?php
                    $sykFields = [
                        'syk_outreaches'         => ['Outreaches Held',        'Number of Say Yes to Kids outreach events conducted this month.'],
                        'syk_elementary_schools' => ['Schools Reached',        'Number of primary/elementary schools visited or engaged.'],
                        'syk_orphanages'         => ['Orphanages Reached',     'Number of orphanages or children\'s homes visited.'],
                        'syk_children_reached'   => ['Children Reached',       'Total number of children who received materials or participated.'],
                        'syk_souls_won'          => ['Children Saved',         'Number of children who made salvation decisions.'],
                        'syk_souls_retained'     => ['Children Retained',      'Of those saved, how many are being followed up and retained in fellowship.'],
                        'syk_rork_clubs'         => ['RORK Clubs',             'Number of Rhapsody of Realities for Kids clubs currently active.'],
                    ];
                    foreach ($sykFields as $name => [$label, $hint]):
                    ?>
                    <div class="cr-field">
                        <label class="cr-label" for="<?php echo $name; ?>"><?php echo $label; ?></label>
                        <input type="number" id="<?php echo $name; ?>" name="<?php echo $name; ?>"
                               class="cr-input" placeholder="0" min="0"
                               value="<?php echo (int)$prefill[$name]; ?>"
                               data-field="<?php echo $name; ?>" data-last="<?php echo (int)$prefill[$name]; ?>">
                        <p class="cr-hint"><?php echo $hint; ?></p>
                        <div class="cr-compare-hint" data-compare-for="<?php echo $name; ?>"></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- ── STEP 5: Files & Submit ── -->
        <div class="cr-section" data-section="5">
            <div class="cr-section-head">
                <div class="cr-section-icon"><i class="fas fa-paperclip"></i></div>
                <h3>Supporting Documents &amp; Images</h3>
            </div>
            <div class="cr-section-body">
                <p style="font-size:0.8rem;color:var(--neutral-500);margin:0 0 1rem;">All file uploads are optional. Documents must be PDF, DOC, or DOCX. Images must be JPG, PNG, or GIF.</p>
                <div class="cr-grid" style="margin-bottom:1.25rem;">
                    <!-- Testimonies -->
                    <div class="cr-field">
                        <label class="cr-label">Testimonies Document</label>
                        <div class="cr-upload-zone" id="zone-testimonies" onclick="document.getElementById('testimonies_file').click()">
                            <input type="file" id="testimonies_file" name="testimonies_file" accept=".pdf,.doc,.docx">
                            <div class="cr-upload-icon"><i class="fas fa-file-alt"></i></div>
                            <div class="cr-upload-label">Click to upload</div>
                            <div class="cr-upload-sub">PDF, DOC, DOCX — max 10 MB</div>
                        </div>
                        <div class="cr-file-list" id="list-testimonies"></div>
                        <div class="cr-upload-progress" id="prog-testimonies"><div class="cr-upload-progress-bar" id="progbar-testimonies"></div></div>
                        <p class="cr-hint" style="margin-top:0.4rem;">Share stories of lives changed through your outreach this month.</p>
                    </div>
                    <!-- Innovations -->
                    <div class="cr-field">
                        <label class="cr-label">Innovations Document</label>
                        <div class="cr-upload-zone" id="zone-innovations" onclick="document.getElementById('innovations_file').click()">
                            <input type="file" id="innovations_file" name="innovations_file" accept=".pdf,.doc,.docx">
                            <div class="cr-upload-icon"><i class="fas fa-lightbulb"></i></div>
                            <div class="cr-upload-label">Click to upload</div>
                            <div class="cr-upload-sub">PDF, DOC, DOCX — max 10 MB</div>
                        </div>
                        <div class="cr-file-list" id="list-innovations"></div>
                        <div class="cr-upload-progress" id="prog-innovations"><div class="cr-upload-progress-bar" id="progbar-innovations"></div></div>
                        <p class="cr-hint" style="margin-top:0.4rem;">New methods or ideas your zone tried this month that others could learn from.</p>
                    </div>
                </div>

                <!-- Report Images -->
                <div class="cr-field">
                    <label class="cr-label">Report Images <span style="font-weight:400;color:var(--neutral-400);font-size:0.7rem;">(optional, multiple)</span></label>
                    <div class="cr-upload-zone" id="zone-images" onclick="document.getElementById('report_images').click()">
                        <input type="file" id="report_images" name="report_images[]" multiple accept="image/*">
                        <div class="cr-upload-icon"><i class="fas fa-images"></i></div>
                        <div class="cr-upload-label">Click or drag &amp; drop images here</div>
                        <div class="cr-upload-sub">JPG, PNG, GIF — up to 10 images</div>
                    </div>
                    <div class="cr-img-grid" id="img-preview"></div>
                    <p class="cr-hint" style="margin-top:0.4rem;">Photos from outreaches, events, or distribution activities. These help tell the story of your month's work.</p>
                </div>

                <!-- Submit -->
                <div style="border-top:1px solid var(--neutral-100);margin-top:1.5rem;padding-top:1.25rem;">
                    <div style="background:var(--neutral-50);border:1px solid var(--neutral-200);border-radius:var(--radius-md);padding:0.75rem 1rem;font-size:0.78rem;color:var(--neutral-600);margin-bottom:1rem;">
                        <i class="fas fa-info-circle" style="color:var(--primary-400);margin-right:0.35rem;"></i>
                        Once submitted, your report will be recorded. You can edit it later if needed from <a href="list.php" style="color:var(--primary-600);">My Reports</a>.
                    </div>
                    <button type="submit" id="submit-report-btn" class="modern-button modern-button-primary"
                            data-loading-text="Submitting report…" style="min-width:10rem;">
                        <i class="fas fa-paper-plane modern-button-icon"></i> Submit Report
                    </button>
                </div>
            </div>
        </div>

        <!-- Step navigation buttons -->
        <div class="cr-nav" id="cr-nav">
            <div class="cr-nav-left">
                <button type="button" class="modern-button modern-button-secondary" id="cr-prev" onclick="crPrev()" style="display:none;font-size:0.8125rem;">
                    <i class="fas fa-arrow-left modern-button-icon"></i> Back
                </button>
            </div>
            <div style="display:flex;gap:0.5rem;align-items:center;">
                <span id="cr-step-counter" style="font-size:0.75rem;color:var(--neutral-400);"></span>
                <button type="button" class="modern-button modern-button-primary" id="cr-next" onclick="crNext()" style="font-size:0.8125rem;">
                    Next <i class="fas fa-arrow-right modern-button-icon" style="margin-left:0.25rem;margin-right:0;"></i>
                </button>
            </div>
        </div>

    </form>
</div>
<script>
(function() {
var DRAFT_KEY = 'cr_draft_<?php echo (int)$_SESSION['user_id']; ?>';
var TOTAL_STEPS = 6;
var currentStep = 0;

/* ── Step navigation ── */
function crGoStep(n) {
    if (n < 0 || n >= TOTAL_STEPS) return;
    // Mark all prior steps done, current active
    var steps = document.querySelectorAll('.cr-step');
    steps.forEach(function(s, i) {
        s.classList.toggle('done',   i < n);
        s.classList.toggle('active', i === n);
        if (i > n) { s.classList.remove('done'); s.classList.remove('active'); }
    });
    // Show/hide sections
    document.querySelectorAll('.cr-section').forEach(function(sec) {
        var idx = parseInt(sec.dataset.section, 10);
        sec.classList.toggle('visible', idx === n);
    });
    currentStep = n;
    updateNav();
    window.scrollTo({ top: 0, behavior: 'smooth' });
    crSaveDraft();
}
window.crGoStep = crGoStep;

function updateNav() {
    var prev = document.getElementById('cr-prev');
    var next = document.getElementById('cr-next');
    var counter = document.getElementById('cr-step-counter');
    prev.style.display = currentStep === 0 ? 'none' : '';
    if (currentStep === TOTAL_STEPS - 1) {
        next.style.display = 'none';
    } else {
        next.style.display = '';
        next.innerHTML = 'Next <i class="fas fa-arrow-right modern-button-icon" style="margin-left:0.25rem;margin-right:0;"></i>';
    }
    if (counter) counter.textContent = 'Step ' + (currentStep + 1) + ' of ' + TOTAL_STEPS;
}

function crNext() {
    if (currentStep < TOTAL_STEPS - 1) crGoStep(currentStep + 1);
}
function crPrev() {
    if (currentStep > 0) crGoStep(currentStep - 1);
}
window.crNext = crNext;
window.crPrev = crPrev;

/* ── Draft autosave ── */
var _draftSaveTimer = null;
function crSaveDraft() {
    try {
        var form = document.getElementById('cr-form');
        if (!form) return;
        var data = { step: currentStep };
        var inputs = form.querySelectorAll('input[name]:not([type=file]):not([name=csrf_token]), select[name]');
        inputs.forEach(function(el) { data[el.name] = el.value; });
        var json = JSON.stringify(data);
        localStorage.setItem(DRAFT_KEY, json);
        // Debounce server sync (every 5 s of inactivity)
        clearTimeout(_draftSaveTimer);
        _draftSaveTimer = setTimeout(function() { crSaveDraftServer(json, data['report_month'] || ''); }, 5000);
    } catch(e) {}
}

function crSaveDraftServer(json, month) {
    try {
        var base = '<?php echo rtrim(BASE_URL, "/"); ?>';
        fetch(base + '/api/draft.php?action=save', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ draft_data: json, report_month: month })
        }).catch(function() {});
    } catch(e) {}
}

function crDeleteDraftServer() {
    try {
        var base = '<?php echo rtrim(BASE_URL, "/"); ?>';
        fetch(base + '/api/draft.php?action=delete', { method: 'POST' }).catch(function() {});
    } catch(e) {}
}

function crRestoreDraft() {
    try {
        var raw = localStorage.getItem(DRAFT_KEY);
        if (!raw) return;
        var data = JSON.parse(raw);
        var form = document.getElementById('cr-form');
        Object.keys(data).forEach(function(key) {
            if (key === 'step') return;
            var el = form.querySelector('[name="' + key + '"]');
            if (el) el.value = data[key];
        });
        document.getElementById('cr-draft-bar').style.display = 'none';
        crGoStep(data.step || 0);
        updateCompareHints();
        showToast('Draft restored!', 'success');
    } catch(e) {}
}
function crDiscardDraft() {
    try { localStorage.removeItem(DRAFT_KEY); } catch(e) {}
    crDeleteDraftServer();
    document.getElementById('cr-draft-bar').style.display = 'none';
}
window.crRestoreDraft = crRestoreDraft;
window.crDiscardDraft = crDiscardDraft;

/* ── Compare with last month ── */
function updateCompareHints() {
    var on = document.getElementById('cr-compare-toggle');
    var active = on && on.checked;
    document.getElementById('cr-form').classList.toggle('cr-compare-on', !!active);
    document.querySelectorAll('[data-compare-for]').forEach(function(hint) {
        var fieldName = hint.dataset.compareFor;
        var input = document.querySelector('[data-field="' + fieldName + '"]');
        if (!input) return;
        var current = parseInt(input.value, 10) || 0;
        var last    = parseInt(input.dataset.last, 10) || 0;
        if (!active) { hint.innerHTML = ''; return; }
        var diff = current - last;
        var pct  = last > 0 ? Math.round((diff / last) * 100) : null;
        var pctStr = pct !== null ? ' (' + (diff >= 0 ? '+' : '') + pct + '%)' : '';
        var cls, icon, txt;
        if (diff > 0)      { cls='up';   icon='▲'; txt = 'Up ' + diff + ' from last month' + pctStr; }
        else if (diff < 0) { cls='down'; icon='▼'; txt = 'Down ' + Math.abs(diff) + ' from last month' + pctStr; }
        else               { cls='same'; icon='='; txt = 'Same as last month (was ' + last + ')'; }
        hint.innerHTML = '<span class="cr-compare-chip ' + cls + '">' + icon + ' ' + txt + '</span>';
    });
}

/* ── File upload UI ── */
function setupUploadZone(zoneId, inputId, listId) {
    var zone  = document.getElementById(zoneId);
    var input = document.getElementById(inputId);
    var list  = document.getElementById(listId);
    if (!zone || !input || !list) return;

    function fmtSize(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1048576) return (bytes/1024).toFixed(1) + ' KB';
        return (bytes/1048576).toFixed(1) + ' MB';
    }

    function renderFile(file) {
        list.innerHTML = '';
        var item = document.createElement('div');
        item.className = 'cr-file-item';
        var ext = (file.name.split('.').pop() || '').toLowerCase();
        var icon = ext === 'pdf' ? 'fa-file-pdf' : 'fa-file-word';
        item.innerHTML = '<i class="fas ' + icon + '"></i><span>' + file.name + '</span>'
            + '<span class="cr-file-size">' + fmtSize(file.size) + '</span>'
            + '<button type="button" class="cr-file-remove" title="Remove"><i class="fas fa-times"></i></button>';
        item.querySelector('.cr-file-remove').addEventListener('click', function() {
            input.value = '';
            list.innerHTML = '';
            zone.style.borderColor = '';
        });
        list.appendChild(item);
        zone.style.borderColor = 'var(--success-400)';
    }

    input.addEventListener('change', function() {
        if (this.files && this.files[0]) renderFile(this.files[0]);
    });
    zone.addEventListener('dragover', function(e) { e.preventDefault(); zone.classList.add('drag-over'); });
    zone.addEventListener('dragleave', function() { zone.classList.remove('drag-over'); });
    zone.addEventListener('drop', function(e) {
        e.preventDefault(); zone.classList.remove('drag-over');
        var dt = e.dataTransfer;
        if (dt && dt.files && dt.files[0]) {
            // Transfer to the hidden input via DataTransfer trick
            try {
                var transfer = new DataTransfer();
                transfer.items.add(dt.files[0]);
                input.files = transfer.files;
                renderFile(dt.files[0]);
            } catch(err) {}
        }
    });
}

/* ── Image upload + preview ── */
function setupImageUpload() {
    var zone    = document.getElementById('zone-images');
    var input   = document.getElementById('report_images');
    var preview = document.getElementById('img-preview');
    if (!zone || !input || !preview) return;
    var imageFiles = [];

    function renderPreviews() {
        preview.innerHTML = '';
        imageFiles.forEach(function(file, idx) {
            var thumb = document.createElement('div');
            thumb.className = 'cr-img-thumb';
            var reader = new FileReader();
            reader.onload = function(e) {
                thumb.innerHTML = '<img src="' + e.target.result + '" alt="">'
                    + '<button type="button" class="cr-img-remove" data-idx="' + idx + '" title="Remove"><i class="fas fa-times"></i></button>';
                thumb.querySelector('.cr-img-remove').addEventListener('click', function() {
                    imageFiles.splice(parseInt(this.dataset.idx,10), 1);
                    rebuildFileInput();
                    renderPreviews();
                });
            };
            reader.readAsDataURL(file);
            preview.appendChild(thumb);
        });
    }

    function rebuildFileInput() {
        try {
            var dt = new DataTransfer();
            imageFiles.forEach(function(f) { dt.items.add(f); });
            input.files = dt.files;
        } catch(e) {}
    }

    function addFiles(files) {
        var arr = Array.from(files);
        arr.forEach(function(f) {
            if (f.type.startsWith('image/') && imageFiles.length < 10) imageFiles.push(f);
        });
        rebuildFileInput();
        renderPreviews();
        if (arr.length) showToast(imageFiles.length + ' image' + (imageFiles.length !== 1 ? 's' : '') + ' selected', 'success');
    }

    input.addEventListener('change', function() { addFiles(this.files); });
    zone.addEventListener('dragover', function(e) { e.preventDefault(); zone.classList.add('drag-over'); });
    zone.addEventListener('dragleave', function() { zone.classList.remove('drag-over'); });
    zone.addEventListener('drop', function(e) {
        e.preventDefault(); zone.classList.remove('drag-over');
        if (e.dataTransfer && e.dataTransfer.files) addFiles(e.dataTransfer.files);
    });
}

/* ── Date validation ── */
function validateReportDate() {
    var m = parseInt(document.getElementById('report_month').value, 10);
    var y = parseInt(document.getElementById('report_year').value, 10);
    var now = new Date();
    if (y > now.getFullYear() || (y === now.getFullYear() && m > now.getMonth() + 1)) {
        showToast('You cannot select a future month — reset to current month.', 'error');
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

/* ── Form submit validation ── */
function setupFormSubmit() {
    var form = document.getElementById('cr-form');
    if (!form) return;
    form.addEventListener('submit', function(e) {
        if (!validateReportDate()) { e.preventDefault(); return; }
        updateTotalDistribution();
        var totalCopies = document.getElementById('total_copies');
        if (totalCopies && (totalCopies.value === '' || parseInt(totalCopies.value,10) < 0)) {
            e.preventDefault();
            crGoStep(0);
            totalCopies.classList.add('is-error');
            var errEl = document.getElementById('err-total_copies');
            if (errEl) errEl.style.display = 'block';
            showToast('Total Copies Sponsored is required on Step 1.', 'error');
            setTimeout(function() { totalCopies.focus(); }, 200);
            return;
        }
        crSaveDraft(); // Save draft before navigation
        // Clear draft on successful submit (set flag)
        try { sessionStorage.setItem('cr_submitting', '1'); } catch(e2) {}
    });
}

/* ── Boot ── */
document.addEventListener('DOMContentLoaded', function() {
    crGoStep(0);

    // Clear draft after successful submit redirect
    try {
        if (sessionStorage.getItem('cr_submitting') === '1') {
            sessionStorage.removeItem('cr_submitting');
            localStorage.removeItem(DRAFT_KEY);
            crDeleteDraftServer();
        }
    } catch(e) {}

    // Check for saved draft (localStorage first, then server fallback)
    try {
        var raw = localStorage.getItem(DRAFT_KEY);
        if (raw) {
            var data = JSON.parse(raw);
            var hasData = Object.keys(data).some(function(k) { return k !== 'step' && data[k] !== '' && data[k] !== '0' && data[k] !== 0; });
            if (hasData) document.getElementById('cr-draft-bar').style.display = 'flex';
        } else {
            // Try server draft
            var base = '<?php echo rtrim(BASE_URL, "/"); ?>';
            fetch(base + '/api/draft.php?action=load')
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    if (res.ok && res.draft_data) {
                        try {
                            localStorage.setItem(DRAFT_KEY, res.draft_data);
                            var d = JSON.parse(res.draft_data);
                            var has = Object.keys(d).some(function(k) { return k !== 'step' && d[k] !== '' && d[k] !== '0' && d[k] !== 0; });
                            if (has) document.getElementById('cr-draft-bar').style.display = 'flex';
                        } catch(pe) {}
                    }
                }).catch(function() {});
        }
    } catch(err) {}

    // Autosave on input change
    document.getElementById('cr-form').addEventListener('input', function(e) {
        if (e.target && (e.target.name === 'distribution_digital' || e.target.name === 'distribution_physical')) {
            updateTotalDistribution();
        }
        crSaveDraft();
        updateCompareHints();
    });

    // Compare toggle
    var toggle = document.getElementById('cr-compare-toggle');
    if (toggle) toggle.addEventListener('change', updateCompareHints);

    // Date selects
    var mo = document.getElementById('report_month');
    var yo = document.getElementById('report_year');
    if (mo) mo.addEventListener('change', validateReportDate);
    if (yo) yo.addEventListener('change', validateReportDate);

    // Inline error clearing
    document.querySelectorAll('.cr-input').forEach(function(inp) {
        inp.addEventListener('input', function() {
            this.classList.remove('is-error');
            var errEl = document.getElementById('err-' + this.name);
            if (errEl) errEl.style.display = 'none';
            if (this.value !== '' && parseInt(this.value, 10) >= 0) this.classList.add('is-ok');
        });
    });

    updateTotalDistribution();

    // Upload zones
    setupUploadZone('zone-testimonies', 'testimonies_file', 'list-testimonies');
    setupUploadZone('zone-innovations',  'innovations_file',  'list-innovations');
    setupImageUpload();
    setupFormSubmit();
});

})();
</script>
