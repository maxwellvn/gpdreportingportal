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
/* Report form (create + edit share this block; candidates for the shared theme) */
.cr-layout { display: grid; grid-template-columns: minmax(0, 1fr) 17rem; gap: var(--spacing-8); align-items: start; }
.cr-aside { position: sticky; top: calc(var(--header-height) + var(--spacing-6)); }
@media (max-width: 1023px) { .cr-layout { grid-template-columns: minmax(0, 1fr); } .cr-aside { position: static; } }
.cr-aside h2 { font-size: var(--text-sm); font-weight: 500; color: var(--neutral-900); margin: var(--spacing-5) 0 var(--spacing-1); }
.cr-aside h2:first-of-type { margin-top: var(--spacing-2); }
.cr-aside p { font-size: var(--text-sm); color: var(--neutral-700); margin: 0; }

/* Stepper: buttons on top of the shared .stepper/.stepper-item */
.cr-steps { list-style: none; padding: 0; }
.cr-steps .cr-step { background: none; border: 0; padding: 0 0 0.25rem; font-family: inherit; text-align: left; cursor: pointer; }
.cr-step-num { font-family: var(--font-mono); font-size: 0.6875rem; letter-spacing: 0.04em; margin-right: 0.25rem; }
@media (max-width: 600px) {
  .cr-steps { gap: 0.25rem; margin-bottom: var(--spacing-6); }
  .cr-steps .cr-step { min-height: 1.5rem; }
  .cr-step-text { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
}

/* Sections */
.cr-section { margin-bottom: var(--spacing-6); }
.cr-wizard .cr-section { display: none; }
.cr-wizard .cr-section.visible { display: block; }
.cr-section-title { display: flex; align-items: baseline; gap: var(--spacing-3); margin: 0; font-size: var(--text-base); font-weight: 500; color: var(--neutral-900); }
.cr-section .form-grid { gap: 0 var(--spacing-5); }
.cr-section .panel-body > .field:last-child { margin-bottom: 0; }
.cr-input.is-error { border-color: var(--danger-500); }
.cr-input[readonly] { background: var(--mist); }
.cr-compare-hint { margin-top: 0.375rem; }
.cr-compare-hint:empty { display: none; }
.cr-compare-chip { display: inline-flex; align-items: center; gap: 0.25rem; padding: 0.125rem 0.5rem; border-radius: var(--radius-full); font-size: var(--text-xs); font-weight: 500; font-variant-numeric: tabular-nums; }
.cr-compare-chip.up { background: var(--success-50); color: var(--success-800); }
.cr-compare-chip.down { background: var(--danger-50); color: var(--danger-800); }
.cr-compare-chip.same { background: var(--neutral-100); color: var(--neutral-700); }
.cr-toggle { display: flex; align-items: center; gap: var(--spacing-2); margin-bottom: var(--spacing-4); font-size: var(--text-sm); color: var(--neutral-700); cursor: pointer; }
.cr-draft-bar { flex-wrap: wrap; align-items: center; }
.cr-draft-bar > div:first-of-type { flex: 1 1 12rem; }
.cr-draft-actions { display: flex; gap: var(--spacing-2); }

/* Uploads */
.cr-upload-zone { padding: var(--spacing-5); text-align: center; cursor: pointer; background: var(--mist); border: 1px dashed var(--neutral-300); border-radius: var(--radius-lg); transition: border-color var(--dur) var(--ease-out); }
.cr-upload-zone.drag-over, .cr-upload-zone.has-file { border-color: var(--neutral-900); border-style: solid; }
@media (hover: hover) and (pointer: fine) { .cr-upload-zone:hover { border-color: var(--neutral-500); } }
.cr-upload-zone input[type=file] { display: none; }
.cr-upload-icon { color: var(--neutral-600); margin-bottom: var(--spacing-2); }
.cr-upload-label { font-size: var(--text-sm); font-weight: 500; color: var(--neutral-900); }
.cr-upload-sub { margin-top: 0.125rem; font-size: var(--text-xs); color: var(--neutral-600); }
.cr-file-list { display: grid; gap: var(--spacing-2); margin-top: var(--spacing-2); }
.cr-file-list:empty { display: none; }
.cr-file-item { display: flex; align-items: center; gap: var(--spacing-2); min-width: 0; padding: 0.5rem 0.75rem; border: 1px solid var(--neutral-200); border-radius: var(--radius-md); font-size: var(--text-sm); color: var(--neutral-800); }
.cr-file-item i { color: var(--neutral-600); }
.cr-file-name { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.cr-file-size { margin-left: auto; flex-shrink: 0; font-size: var(--text-xs); color: var(--neutral-500); font-variant-numeric: tabular-nums; }
.cr-file-remove { display: grid; place-items: center; width: 1.75rem; height: 1.75rem; flex-shrink: 0; border: 0; border-radius: var(--radius-full); background: none; color: var(--neutral-600); cursor: pointer; }
.cr-img-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(6rem, 1fr)); gap: var(--spacing-2); margin-top: var(--spacing-3); }
.cr-img-grid:empty { display: none; }
.cr-img-thumb, .cr-existing-img { position: relative; aspect-ratio: 1; overflow: hidden; border-radius: var(--radius-md); background: var(--neutral-100); }
.cr-img-thumb img, .cr-existing-img img { display: block; width: 100%; height: 100%; object-fit: cover; outline: 1px solid color-mix(in srgb, var(--ink) 10%, transparent); outline-offset: -1px; border-radius: inherit; }
.cr-img-remove { position: absolute; top: 0.25rem; right: 0.25rem; display: grid; place-items: center; width: 1.75rem; height: 1.75rem; border: 0; border-radius: var(--radius-full); background: color-mix(in srgb, var(--ink) 70%, transparent); color: white; font-size: var(--text-xs); cursor: pointer; }

/* Sticky action bar */
.cr-actions { position: sticky; bottom: 0; z-index: 10; display: flex; align-items: center; gap: var(--spacing-3); padding: var(--spacing-3) 0; background: white; border-top: 1px solid var(--neutral-200); }
.cr-actions-status { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.cr-actions-right { display: flex; gap: var(--spacing-2); margin-left: auto; }

/* Review step */
.cr-review .panel { margin-bottom: var(--spacing-4); }
.cr-review .panel-head { padding-top: var(--spacing-3); padding-bottom: var(--spacing-3); }
.cr-review .panel-body { padding-top: var(--spacing-1); padding-bottom: var(--spacing-1); }
.cr-review .kv { font-size: var(--text-sm); grid-template-columns: minmax(0, 1fr) auto; }
.cr-review .kv dd { padding-left: var(--spacing-4); }
.cr-review dd.is-empty { color: var(--neutral-500); font-weight: 400; }
</style>

<?php
// ponytail: one renderer for every numeric field instead of four copy-pasted loops
$renderNumField = function (string $name, string $label, string $hint, bool $readonly = false) use ($prefill) {
    $n = htmlspecialchars($name, ENT_QUOTES);
    $v = (int)($prefill[$name] ?? 0);
    ?>
    <div class="field cr-field">
        <label class="field-label" for="<?php echo $n; ?>"><?php echo htmlspecialchars($label); ?></label>
        <input type="number" id="<?php echo $n; ?>" name="<?php echo $n; ?>" class="input cr-input"
               placeholder="0" min="0" inputmode="numeric" <?php echo $readonly ? 'readonly' : ''; ?>
               value="<?php echo $v; ?>" data-field="<?php echo $n; ?>" data-last="<?php echo $v; ?>"
               aria-describedby="hint-<?php echo $n; ?>">
        <p class="field-help" id="hint-<?php echo $n; ?>"><?php echo htmlspecialchars($hint); ?></p>
        <div class="cr-compare-hint" data-compare-for="<?php echo $n; ?>"></div>
    </div>
    <?php
};
$stepTitles = ['Period & copies', 'Alerts & languages', 'Campaigns & programs', 'Distribution & growth', 'Say Yes to Kids', 'Files', 'Review & submit'];
?>

<nav class="breadcrumb" aria-label="Breadcrumb">
    <a href="<?php echo BASE_URL; ?>/dashboard.php">Dashboard</a>
    <span class="sep">/</span>
    <a href="list.php">My reports</a>
    <span class="sep">/</span>
    <span>Submit report</span>
</nav>

<div class="page-head">
    <div>
        <p class="page-eyebrow" id="cr-step-counter" aria-live="polite">Step 1 of <?php echo count($stepTitles); ?> · <?php echo htmlspecialchars($stepTitles[0]); ?></p>
        <h1 class="page-title">Submit monthly report</h1>
        <p class="page-sub">Enter your activity numbers for the month. Blank fields count as 0. You'll see everything on one page before you submit.</p>
    </div>
</div>

<div class="cr-layout">
<div class="cr-main">

    <?php if (isset($_SESSION['error'])): $submitError = true; ?>
    <div class="alert alert-danger" role="alert">
        <i class="fas fa-circle-exclamation" aria-hidden="true"></i>
        <div><div class="alert-title">The report was not submitted</div><?php echo htmlspecialchars($_SESSION['error']); ?></div>
    </div>
    <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <!-- Draft restore banner (shown by JS if draft found) -->
    <div class="alert alert-warning cr-draft-bar" id="cr-draft-bar" role="status" style="display:none;">
        <i class="fas fa-clock" aria-hidden="true"></i>
        <div>
            <div class="alert-title">Draft found</div>
            <span id="cr-draft-when">You have an unsaved draft from a previous session.</span>
        </div>
        <div class="cr-draft-actions">
            <button type="button" class="btn btn-sm btn-secondary" onclick="crRestoreDraft()">Restore draft</button>
            <button type="button" class="btn btn-sm btn-ghost" onclick="crDiscardDraft()">Discard</button>
        </div>
    </div>

    <!-- Progress steps -->
    <ol class="stepper cr-steps" id="cr-steps">
        <?php foreach ($stepTitles as $i => $t): ?>
        <li class="stepper-item cr-step<?php echo $i === 0 ? ' active' : ''; ?>" data-step="<?php echo $i; ?>" onclick="crGoStep(<?php echo $i; ?>)"
            role="button" tabindex="0" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();crGoStep(<?php echo $i; ?>);}">
            <span class="cr-step-text"><span class="cr-step-num"><?php echo sprintf('%02d', $i + 1); ?></span><span class="cr-step-label"><?php echo htmlspecialchars($t); ?></span></span>
        </li>
        <?php endforeach; ?>
    </ol>

    <?php if ($lastReportLabel): ?>
    <label class="cr-toggle" id="cr-compare-bar">
        <input type="checkbox" id="cr-compare-toggle">
        Compare with my <?php echo htmlspecialchars($lastReportLabel); ?> report
    </label>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" id="cr-form" class="cr-wizard" novalidate>
        <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">

        <!-- ── STEP 0: Period & Total Copies ── -->
        <section class="panel cr-section visible" data-section="0">
            <div class="panel-head"><h2 class="cr-section-title"><span class="meta">01</span>Reporting period &amp; total copies</h2></div>
            <div class="panel-body">
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
                <div class="form-grid">
                    <div class="field cr-field">
                        <label class="field-label" for="report_year">Year</label>
                        <select id="report_year" name="report_year" class="input cr-input" required>
                            <?php $cy = (int)date('Y'); for ($y=$cy; $y>=$cy-5; $y--): ?>
                            <option value="<?php echo $y; ?>" <?php echo $y===$preYear?'selected':''; ?>><?php echo $y; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="field cr-field">
                        <label class="field-label" for="report_month">Month</label>
                        <select id="report_month" name="report_month" class="input cr-input" required aria-describedby="hint-report_month">
                            <?php foreach ($months as $v => $l): ?>
                            <option value="<?php echo sprintf("%02d", $v); ?>" <?php echo (string)$v===$preMonthNum?'selected':''; ?>><?php echo $l; ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="field-help" id="hint-report_month">Current or past months only.</p>
                    </div>
                </div>
                <div class="field cr-field">
                    <label class="field-label" for="total_copies">Total copies sponsored <span class="field-optional">(required)</span></label>
                    <input type="number" id="total_copies" name="total_copies" class="input cr-input"
                           placeholder="e.g. 500" min="0" inputmode="numeric"
                           value="<?php echo (int)$prefill['total_copies']; ?>"
                           data-field="total_copies" data-last="<?php echo (int)$prefill['total_copies']; ?>"
                           aria-describedby="hint-total_copies err-total_copies">
                    <p class="field-help" id="hint-total_copies">Rhapsody copies your zone sponsored this month across all programs.</p>
                    <div class="cr-compare-hint" data-compare-for="total_copies"></div>
                    <p class="field-error cr-error-msg" id="err-total_copies" style="display:none;">Enter the number of copies sponsored (0 or more).</p>
                </div>
            </div>
        </section>

        <!-- ── STEP 1: Alerts & Languages ── -->
        <section class="panel cr-section" data-section="1">
            <div class="panel-head"><h2 class="cr-section-title"><span class="meta">02</span>Alerts &amp; language missions</h2></div>
            <div class="panel-body"><div class="form-grid">
                <?php
                $renderNumField('wonder_alerts', 'Wonder Alerts Received', 'Wonder Alert messages received and distributed in your zone this month.');
                $renderNumField('kids_alerts', 'Kids Alerts', 'Kids Alert messages received and distributed.');
                $renderNumField('language_missions', 'Language Missions', 'Language mission activities that reached communities in their native language.');
                $renderNumField('penetrating_truth', 'Penetrating with Truth', 'Copies of Penetrating Truth publications distributed.');
                $renderNumField('penetrating_languages', 'Penetrating with Languages', 'Language-specific outreach copies distributed under Penetrating with Languages.');
                ?>
            </div></div>
        </section>

        <!-- ── STEP 2: Campaigns & Programs ── -->
        <section class="panel cr-section" data-section="2">
            <div class="panel-head"><h2 class="cr-section-title"><span class="meta">03</span>Campaigns &amp; programs</h2></div>
            <div class="panel-body"><div class="form-grid">
                <?php
                $renderNumField('youth_aglow', 'Youth Aglow', 'Youth Aglow outreach activities or copies distributed.');
                $renderNumField('minister_campaign', 'Every Minister Campaign', 'Copies distributed through the Every Minister Campaign.');
                $renderNumField('say_yes_kids', 'Say Yes to Kids', 'Copies distributed to or for children under Say Yes to Kids.');
                $renderNumField('no_one_left', 'No One Left Behind', 'Activities or copies aimed at unreached communities.');
                $renderNumField('teevolution', 'Teevolution', 'Copies or activities for the Teevolution media/TV outreach.');
                $renderNumField('subscriptions', 'Subscriptions', 'Active or new Rhapsody subscription sign-ups this month.');
                $renderNumField('prayer_programs', 'Partners Prayer Programs', 'Formal prayer programs organized with or for partners.');
                $renderNumField('partner_programs', 'Partners Programs', 'Partner engagement events or programs held this month.');
                ?>
            </div></div>
        </section>

        <!-- ── STEP 3: Distribution & Growth ── -->
        <section class="panel cr-section" data-section="3">
            <div class="panel-head"><h2 class="cr-section-title"><span class="meta">04</span>Distribution &amp; growth</h2></div>
            <div class="panel-body"><div class="form-grid">
                <?php
                $renderNumField('distribution_digital', 'Distribution - Digital', 'Copies distributed through digital channels and platforms.');
                $renderNumField('distribution_physical', 'Distribution - Physical', 'Copies physically handed out or delivered to recipients.');
                $renderNumField('total_distribution', 'Total Copies Distributed', 'Calculated for you: digital + physical.', true);
                $renderNumField('souls_won', 'Souls Won', 'People who made salvation decisions through your outreach.');
                $renderNumField('rhapsody_outreaches', 'Rhapsody Outreaches', 'Organised outreach events where Rhapsody was the focus.');
                $renderNumField('rhapsody_cells', 'Rhapsody Cells', 'Active Rhapsody cell groups in your zone.');
                $renderNumField('new_churches', 'New Churches', 'New churches or fellowships established this month.');
                $renderNumField('new_partners', 'New Partners', 'People who signed up as Rhapsody partners this month.');
                $renderNumField('lingual_cells', 'Lingual Cells', 'Cell groups meeting in a language other than English.');
                $renderNumField('language_churches', 'Language Churches', 'Churches or fellowships that mainly operate in a non-English language.');
                $renderNumField('languages_sponsored', 'Languages Sponsored', 'Distinct languages with Rhapsody copies produced or distributed in your zone.');
                $renderNumField('distribution_centers', 'Distribution Centers', 'Active Rhapsody distribution points or depots in your zone.');
                $renderNumField('external_ministers', 'External Ministers', 'Ministers from outside your zone who supported outreach this month.');
                $renderNumField('crusades', 'Crusades', 'Crusades held or supported this month.');
                $renderNumField('testimonies_received', 'Testimonies Received', 'Testimonies received this month. Upload the written document in step 6.');
                ?>
            </div></div>
        </section>

        <!-- ── STEP 4: Say Yes to Kids ── -->
        <section class="panel cr-section" data-section="4">
            <div class="panel-head"><h2 class="cr-section-title"><span class="meta">05</span>Say Yes to Kids impact</h2></div>
            <div class="panel-body"><div class="form-grid">
                <?php
                $renderNumField('syk_outreaches', 'Outreaches Held', 'Say Yes to Kids outreach events this month.');
                $renderNumField('syk_elementary_schools', 'Schools Reached', 'Primary/elementary schools visited or engaged.');
                $renderNumField('syk_orphanages', 'Orphanages Reached', "Orphanages or children's homes visited.");
                $renderNumField('syk_children_reached', 'Children Reached', 'Children who received materials or took part.');
                $renderNumField('syk_souls_won', 'Children Saved', 'Children who made salvation decisions.');
                $renderNumField('syk_souls_retained', 'Children Retained', 'Of those saved, how many are followed up and retained.');
                $renderNumField('syk_rork_clubs', 'RORK Clubs', 'Rhapsody of Realities for Kids clubs currently active.');
                ?>
            </div></div>
        </section>

        <!-- ── STEP 5: Files ── -->
        <section class="panel cr-section" data-section="5">
            <div class="panel-head"><h2 class="cr-section-title"><span class="meta">06</span>Documents &amp; images</h2></div>
            <div class="panel-body">
                <p class="muted" style="margin:0 0 var(--spacing-5);">All uploads are optional. Documents: PDF, DOC or DOCX. Images: JPG, PNG or GIF.</p>
                <div class="form-grid">
                    <div class="field cr-field">
                        <label class="field-label" for="testimonies_file">Testimonies document</label>
                        <div class="cr-upload-zone" id="zone-testimonies" role="button" tabindex="0" onclick="document.getElementById('testimonies_file').click()">
                            <input type="file" id="testimonies_file" name="testimonies_file" accept=".pdf,.doc,.docx">
                            <div class="cr-upload-icon"><i class="fas fa-file-lines" aria-hidden="true"></i></div>
                            <div class="cr-upload-label">Choose a file</div>
                            <div class="cr-upload-sub">PDF, DOC, DOCX · max 10 MB</div>
                        </div>
                        <div class="cr-file-list" id="list-testimonies"></div>
                        <div class="cr-upload-progress" id="prog-testimonies" hidden><div class="cr-upload-progress-bar" id="progbar-testimonies"></div></div>
                        <p class="field-help">Stories of lives changed through your outreach this month.</p>
                    </div>
                    <div class="field cr-field">
                        <label class="field-label" for="innovations_file">Innovations document</label>
                        <div class="cr-upload-zone" id="zone-innovations" role="button" tabindex="0" onclick="document.getElementById('innovations_file').click()">
                            <input type="file" id="innovations_file" name="innovations_file" accept=".pdf,.doc,.docx">
                            <div class="cr-upload-icon"><i class="fas fa-lightbulb" aria-hidden="true"></i></div>
                            <div class="cr-upload-label">Choose a file</div>
                            <div class="cr-upload-sub">PDF, DOC, DOCX · max 10 MB</div>
                        </div>
                        <div class="cr-file-list" id="list-innovations"></div>
                        <div class="cr-upload-progress" id="prog-innovations" hidden><div class="cr-upload-progress-bar" id="progbar-innovations"></div></div>
                        <p class="field-help">New methods your zone tried that others could learn from.</p>
                    </div>
                </div>

                <div class="field cr-field" style="margin-bottom:0;">
                    <label class="field-label" for="report_images">Report images <span class="field-optional">(up to 10)</span></label>
                    <div class="cr-upload-zone" id="zone-images" role="button" tabindex="0" onclick="document.getElementById('report_images').click()">
                        <input type="file" id="report_images" name="report_images[]" multiple accept="image/*">
                        <div class="cr-upload-icon"><i class="fas fa-images" aria-hidden="true"></i></div>
                        <div class="cr-upload-label">Choose or drop images</div>
                        <div class="cr-upload-sub">JPG, PNG, GIF</div>
                    </div>
                    <div class="cr-img-grid" id="img-preview"></div>
                    <p class="field-help">Photos from outreaches, events or distribution.</p>
                </div>
            </div>
        </section>

        <!-- ── STEP 6: Review (built client-side from the inputs above) ── -->
        <div class="cr-section" data-section="6">
            <div class="cr-review" id="cr-review"></div>
            <div class="alert alert-info">
                <i class="fas fa-circle-info" aria-hidden="true"></i>
                <div>Once submitted, the report is recorded for the selected month. You can still edit it later from <a class="link" href="list.php">My reports</a>.</div>
            </div>
        </div>

        <!-- Sticky step actions -->
        <div class="cr-actions" id="cr-nav">
            <button type="button" class="btn btn-secondary" id="cr-prev" onclick="crPrev()" style="display:none;">
                <i class="fas fa-arrow-left modern-button-icon" aria-hidden="true"></i> Back
            </button>
            <span class="meta cr-actions-status" id="cr-save-status" aria-live="polite"></span>
            <div class="cr-actions-right">
                <button type="button" class="btn btn-primary" id="cr-next" onclick="crNext()">
                    Next <i class="fas fa-arrow-right modern-button-icon" aria-hidden="true"></i>
                </button>
                <button type="submit" id="submit-report-btn" class="btn btn-primary"
                        data-loading-text="Submitting report…" style="display:none;">
                    <i class="fas fa-paper-plane modern-button-icon" aria-hidden="true"></i> Submit report
                </button>
            </div>
        </div>
    </form>
</div>

<aside class="cr-aside" aria-label="Help">
    <div class="panel panel-muted"><div class="panel-body">
        <p class="meta">Help</p>
        <h2>What counts as a distribution</h2>
        <p>Copies that reached a person this month. Count copies sent through apps, links or email as digital, and copies handed out or delivered as physical. The total is added up for you. Sponsored copies still in stock are not distributed yet.</p>
        <h2>Deadline</h2>
        <p>Reports are due at the end of each month. You can report for the current month or any past month, never a future one.</p>
        <h2>Drafts</h2>
        <p>Your answers are saved to this device as you type and to your account a few seconds later.</p>
        <p style="margin-top:var(--spacing-4);"><a class="link" href="<?php echo BASE_URL; ?>/help.php">Read the full guide</a></p>
    </div></div>
</aside>
</div>
<script>
(function() {
var DRAFT_KEY = 'cr_draft_<?php echo (int)$_SESSION['user_id']; ?>';
var TOTAL_STEPS = 7;
var REVIEW_STEP = TOTAL_STEPS - 1;
var SUBMIT_FAILED = <?php echo !empty($submitError) ? 'true' : 'false'; ?>;
var currentStep = 0;
var draftReady = false; // ponytail: don't autosave until boot has checked for an older draft
var STEP_TITLES = Array.prototype.map.call(document.querySelectorAll('.cr-step .cr-step-label'), function(el) { return el.textContent; });

function fmtTime(ts) {
    var d = new Date(ts);
    if (isNaN(d)) return '';
    var t = d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    return d.toDateString() === new Date().toDateString() ? t : d.toLocaleDateString([], { month: 'short', day: 'numeric' }) + ', ' + t;
}

/* ── Step navigation ── */
function crGoStep(n) {
    if (n < 0 || n >= TOTAL_STEPS) return;
    // Mark all prior steps done, current active
    var steps = document.querySelectorAll('.cr-step');
    steps.forEach(function(s, i) {
        s.classList.toggle('done',   i < n);
        s.classList.toggle('active', i === n);
        if (i === n) s.setAttribute('aria-current', 'step'); else s.removeAttribute('aria-current');
    });
    if (n === REVIEW_STEP) buildReview();
    // Show/hide sections
    document.querySelectorAll('.cr-section').forEach(function(sec) {
        var idx = parseInt(sec.dataset.section, 10);
        sec.classList.toggle('visible', idx === n);
    });
    currentStep = n;
    updateNav();
    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    window.scrollTo({ top: 0, behavior: reduce ? 'auto' : 'smooth' });
    crSaveDraft();
}
window.crGoStep = crGoStep;

function updateNav() {
    var prev = document.getElementById('cr-prev');
    var next = document.getElementById('cr-next');
    var submit = document.getElementById('submit-report-btn');
    var counter = document.getElementById('cr-step-counter');
    prev.style.display = currentStep === 0 ? 'none' : '';
    next.style.display = currentStep === REVIEW_STEP ? 'none' : '';
    next.innerHTML = currentStep === REVIEW_STEP - 1
        ? 'Review <i class="fas fa-arrow-right modern-button-icon" aria-hidden="true"></i>'
        : 'Next <i class="fas fa-arrow-right modern-button-icon" aria-hidden="true"></i>';
    submit.style.display = currentStep === REVIEW_STEP ? '' : 'none';
    if (counter) counter.textContent = 'Step ' + (currentStep + 1) + ' of ' + TOTAL_STEPS + ' · ' + (STEP_TITLES[currentStep] || '');
}

/* ── Review step: read-only summary of every input, grouped by step ── */
function buildReview() {
    var root = document.getElementById('cr-review');
    if (!root) return;
    root.textContent = '';
    for (var i = 0; i < REVIEW_STEP; i++) {
        var sec = document.querySelector('.cr-section[data-section="' + i + '"]');
        if (!sec) continue;
        var panel = document.createElement('section');
        panel.className = 'panel';
        var head = document.createElement('div');
        head.className = 'panel-head';
        var h = document.createElement('h3');
        h.className = 'cr-section-title';
        h.textContent = sec.querySelector('.cr-section-title').textContent.replace(/^\d+/, '');
        var edit = document.createElement('button');
        edit.type = 'button';
        edit.className = 'btn btn-sm btn-ghost';
        edit.textContent = 'Edit';
        edit.setAttribute('aria-label', 'Edit ' + h.textContent);
        edit.addEventListener('click', crGoStep.bind(null, i));
        head.appendChild(h); head.appendChild(edit);
        var dl = document.createElement('dl');
        dl.className = 'kv';
        sec.querySelectorAll('.cr-field').forEach(function(f) {
            var label = f.querySelector('label');
            var el = f.querySelector('input:not([type=hidden]), select');
            if (!label || !el) return;
            var dt = document.createElement('dt');
            dt.textContent = label.childNodes[0].textContent.trim();
            var dd = document.createElement('dd');
            var val;
            if (el.type === 'file') {
                var names = Array.prototype.map.call(el.files || [], function(file) { return file.name; });
                val = names.length > 1 ? names.length + ' files' : (names[0] || '');
            } else if (el.tagName === 'SELECT') {
                val = el.options[el.selectedIndex] ? el.options[el.selectedIndex].text : '';
            } else {
                val = el.value === '' ? '0' : Number(el.value).toLocaleString();
            }
            if (val === '') { dd.className = 'is-empty'; val = 'None'; }
            dd.textContent = val;
            dl.appendChild(dt); dl.appendChild(dd);
        });
        var body = document.createElement('div');
        body.className = 'panel-body';
        body.appendChild(dl);
        panel.appendChild(head); panel.appendChild(body);
        root.appendChild(panel);
    }
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
    if (!draftReady) return;
    try {
        var form = document.getElementById('cr-form');
        if (!form) return;
        var data = { step: currentStep, savedAt: Date.now() };
        var inputs = form.querySelectorAll('input[name]:not([type=file]):not([name=csrf_token]), select[name]');
        inputs.forEach(function(el) { data[el.name] = el.value; });
        var json = JSON.stringify(data);
        localStorage.setItem(DRAFT_KEY, json);
        var status = document.getElementById('cr-save-status');
        if (status) status.textContent = 'Saved ' + fmtTime(data.savedAt);
        // Debounce server sync (every 5 s of inactivity)
        clearTimeout(_draftSaveTimer);
        _draftSaveTimer = setTimeout(function() { crSaveDraftServer(json, data['report_month'] || ''); }, 5000);
    } catch(e) {}
}

function draftHasData(d) {
    return Object.keys(d).some(function(k) { return k !== 'step' && k !== 'savedAt' && d[k] !== '' && d[k] !== '0' && d[k] !== 0; });
}
function showDraftBar(d, fallbackTs) {
    var when = fmtTime(d.savedAt || (fallbackTs ? String(fallbackTs).replace(' ', 'T') : ''));
    if (when) document.getElementById('cr-draft-when').textContent = 'Draft saved ' + when + '. Restore it to continue where you left off.';
    document.getElementById('cr-draft-bar').style.display = 'flex';
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
            if (key === 'step' || key === 'savedAt') return;
            var el = form.querySelector('[name="' + key + '"]');
            if (el) el.value = data[key];
        });
        document.getElementById('cr-draft-bar').style.display = 'none';
        crGoStep(data.step || 0);
        updateCompareHints();
        showToast('Draft restored', 'success');
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
function zoneKey(input) {
    return function(e) {
        if (e.target !== e.currentTarget || (e.key !== 'Enter' && e.key !== ' ')) return;
        e.preventDefault();
        input.click();
    };
}

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
        item.innerHTML = '<i class="fas ' + icon + '" aria-hidden="true"></i><span class="cr-file-name"></span>'
            + '<span class="cr-file-size">' + fmtSize(file.size) + '</span>'
            + '<button type="button" class="cr-file-remove" aria-label="Remove file"><i class="fas fa-times" aria-hidden="true"></i></button>';
        item.querySelector('.cr-file-name').textContent = file.name;
        item.querySelector('.cr-file-remove').addEventListener('click', function() {
            input.value = '';
            list.innerHTML = '';
            zone.classList.remove('has-file');
        });
        list.appendChild(item);
        zone.classList.add('has-file');
    }

    input.addEventListener('change', function() {
        if (this.files && this.files[0]) renderFile(this.files[0]);
    });
    zone.addEventListener('keydown', zoneKey(input));
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
                    + '<button type="button" class="cr-img-remove" data-idx="' + idx + '" aria-label="Remove image"><i class="fas fa-times" aria-hidden="true"></i></button>';
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
    zone.addEventListener('keydown', zoneKey(input));
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
            showToast('Total copies sponsored is required on step 1.', 'error');
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

    // Clear draft after successful submit redirect (keep it if the submit came back with an error)
    try {
        if (sessionStorage.getItem('cr_submitting') === '1') {
            sessionStorage.removeItem('cr_submitting');
            if (!SUBMIT_FAILED) {
                localStorage.removeItem(DRAFT_KEY);
                crDeleteDraftServer();
            }
        }
    } catch(e) {}

    // Check for saved draft (localStorage first, then server fallback)
    try {
        var raw = localStorage.getItem(DRAFT_KEY);
        if (raw) {
            var data = JSON.parse(raw);
            if (draftHasData(data)) showDraftBar(data);
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
                            if (draftHasData(d)) showDraftBar(d, res.updated_at);
                        } catch(pe) {}
                    }
                }).catch(function() {});
        }
    } catch(err) {}
    draftReady = true;

    // Enter in a field moves to the next step instead of submitting before review
    document.getElementById('cr-form').addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && e.target.tagName === 'INPUT' && currentStep !== REVIEW_STEP) {
            e.preventDefault();
            crNext();
        }
    });

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
<?php require_once '../../layouts/footer.php'; ?>
