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
.cr-review .kv { font-size: var(--text-sm); }
.cr-review dd.is-empty { color: var(--neutral-500); font-weight: 400; }
/* Edit-only: section index + existing-image delete toggle */
.cr-index { display: grid; gap: 0.125rem; margin: var(--spacing-2) 0 0; padding: 0; list-style: none; }
.cr-index a { display: flex; gap: var(--spacing-2); padding: 0.375rem 0; font-size: var(--text-sm); color: var(--neutral-700); }
.cr-index a .meta { line-height: inherit; }
@media (hover: hover) and (pointer: fine) { .cr-index a:hover { color: var(--neutral-900); } }
.cr-section { scroll-margin-top: calc(var(--header-height) + var(--spacing-4)); }
.cr-existing-img { cursor: pointer; }
.cr-existing-img .cr-del-label { position: absolute; inset: auto 0 0 0; padding: 0.25rem; font-size: var(--text-xs); font-weight: 500; text-align: center; color: white; background: color-mix(in srgb, var(--ink) 70%, transparent); opacity: 0; transition: opacity var(--dur-fast) var(--ease-out); }
.cr-existing-img.marked .cr-del-label { opacity: 1; background: var(--danger-600); }
.cr-existing-img.marked img { opacity: 0.45; }
.cr-existing-img:focus-visible .cr-del-label { opacity: 1; }
@media (hover: hover) and (pointer: fine) { .cr-existing-img:hover .cr-del-label { opacity: 1; } }
.cr-current-file { margin-bottom: var(--spacing-2); }
.cr-current-file a { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
</style>

<?php
$prefill = [];
foreach ($report as $k => $v) { $prefill[$k] = is_numeric($v) ? (int)$v : 0; }
$renderNumField = function (string $name, string $label, string $hint, bool $readonly = false) use ($prefill) {
    $n = htmlspecialchars($name, ENT_QUOTES);
    $v = (int)($prefill[$name] ?? 0);
    ?>
    <div class="field cr-field">
        <label class="field-label" for="<?php echo $n; ?>"><?php echo htmlspecialchars($label); ?></label>
        <input type="number" id="<?php echo $n; ?>" name="<?php echo $n; ?>" class="input cr-input"
               placeholder="0" min="0" inputmode="numeric" <?php echo $readonly ? 'readonly' : ''; ?>
               value="<?php echo $v; ?>" aria-describedby="hint-<?php echo $n; ?>">
        <p class="field-help" id="hint-<?php echo $n; ?>"><?php echo htmlspecialchars($hint); ?></p>
    </div>
    <?php
};
$sections = ['period' => 'Reporting period & total copies', 'alerts' => 'Alerts & language missions', 'campaigns' => 'Campaigns & programs', 'distribution' => 'Distribution & growth', 'syk' => 'Say Yes to Kids impact', 'files' => 'Documents & images'];
$secNo = function (string $key) use ($sections) { return sprintf('%02d', array_search($key, array_keys($sections), true) + 1); };
?>

<nav class="breadcrumb" aria-label="Breadcrumb">
    <a href="<?php echo BASE_URL; ?>/dashboard.php">Dashboard</a>
    <span class="sep">/</span>
    <a href="list.php">My reports</a>
    <span class="sep">/</span>
    <a href="view.php?id=<?php echo $reportId; ?>"><?php echo htmlspecialchars($reportMonthLabel); ?></a>
    <span class="sep">/</span>
    <span>Edit</span>
</nav>

<div class="page-head">
    <div>
        <p class="page-eyebrow">Report #<?php echo $reportId; ?> · <?php echo htmlspecialchars($report['username'] ?? ''); ?></p>
        <h1 class="page-title">Edit <?php echo htmlspecialchars($reportMonthLabel); ?> report</h1>
        <p class="page-sub">Change any value below. Nothing is saved until you press Save changes.</p>
    </div>
</div>

<div class="cr-layout">
<div class="cr-main">

    <?php if (isset($_SESSION['error'])): ?>
    <div class="alert alert-danger" role="alert">
        <i class="fas fa-circle-exclamation" aria-hidden="true"></i>
        <div><div class="alert-title">Changes were not saved</div><?php echo htmlspecialchars($_SESSION['error']); ?></div>
    </div>
    <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" id="edit-form" novalidate>
        <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">

        <section class="panel cr-section" id="sec-period">
            <div class="panel-head"><h2 class="cr-section-title"><span class="meta"><?php echo $secNo('period'); ?></span><?php echo htmlspecialchars($sections['period']); ?></h2></div>
            <div class="panel-body">
                <?php
                $months = ['01'=>'January','02'=>'February','03'=>'March','04'=>'April','05'=>'May','06'=>'June','07'=>'July','08'=>'August','09'=>'September','10'=>'October','11'=>'November','12'=>'December'];
                $cy = (int)date('Y');
                ?>
                <div class="form-grid">
                    <div class="field cr-field">
                        <label class="field-label" for="report_year">Year</label>
                        <select id="report_year" name="report_year" class="input cr-input" required>
                            <?php for ($y=$cy; $y>=$cy-5; $y--): ?>
                            <option value="<?php echo $y; ?>" <?php echo $y==(int)$reportYearVal?'selected':''; ?>><?php echo $y; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="field cr-field">
                        <label class="field-label" for="report_month">Month</label>
                        <select id="report_month" name="report_month" class="input cr-input" required aria-describedby="hint-report_month">
                            <?php foreach ($months as $v => $l): ?>
                            <option value="<?php echo sprintf("%02d", $v); ?>" <?php echo (string)$v===$reportMonthVal?'selected':''; ?>><?php echo $l; ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="field-help" id="hint-report_month">Cannot be set to a future month.</p>
                    </div>
                </div>
                <?php $renderNumField('total_copies', 'Total copies sponsored', 'Rhapsody copies your zone sponsored this month across all programs.'); ?>
            </div>
        </section>

        <section class="panel cr-section" id="sec-alerts">
            <div class="panel-head"><h2 class="cr-section-title"><span class="meta"><?php echo $secNo('alerts'); ?></span><?php echo htmlspecialchars($sections['alerts']); ?></h2></div>
            <div class="panel-body"><div class="form-grid">
                <?php
                $renderNumField('wonder_alerts', 'Wonder Alerts Received', 'Wonder Alert messages received and distributed this month.');
                $renderNumField('kids_alerts', 'Kids Alerts', 'Kids Alert messages received and distributed.');
                $renderNumField('language_missions', 'Language Missions', 'Language mission activities conducted.');
                $renderNumField('penetrating_truth', 'Penetrating with Truth', 'Copies of Penetrating Truth publications distributed.');
                $renderNumField('penetrating_languages', 'Penetrating with Languages', 'Language-specific outreach copies distributed.');
                ?>
            </div></div>
        </section>

        <section class="panel cr-section" id="sec-campaigns">
            <div class="panel-head"><h2 class="cr-section-title"><span class="meta"><?php echo $secNo('campaigns'); ?></span><?php echo htmlspecialchars($sections['campaigns']); ?></h2></div>
            <div class="panel-body"><div class="form-grid">
                <?php
                $renderNumField('youth_aglow', 'Youth Aglow', 'Youth Aglow outreach activities or copies distributed.');
                $renderNumField('minister_campaign', 'Every Minister Campaign', 'Copies distributed through the Every Minister Campaign.');
                $renderNumField('say_yes_kids', 'Say Yes to Kids', 'Copies distributed to children under Say Yes to Kids.');
                $renderNumField('no_one_left', 'No One Left Behind', 'Activities aimed at unreached communities.');
                $renderNumField('teevolution', 'Teevolution', 'Copies or activities for the Teevolution media outreach.');
                $renderNumField('subscriptions', 'Subscriptions', 'Active or new Rhapsody subscription sign-ups this month.');
                $renderNumField('prayer_programs', 'Partners Prayer Programs', 'Formal prayer programs organized with or for partners.');
                $renderNumField('partner_programs', 'Partners Programs', 'Partner engagement events or programs held this month.');
                ?>
            </div></div>
        </section>

        <section class="panel cr-section" id="sec-distribution">
            <div class="panel-head"><h2 class="cr-section-title"><span class="meta"><?php echo $secNo('distribution'); ?></span><?php echo htmlspecialchars($sections['distribution']); ?></h2></div>
            <div class="panel-body"><div class="form-grid">
                <?php
                $renderNumField('distribution_digital', 'Distribution - Digital', 'Copies distributed through digital channels and platforms.');
                $renderNumField('distribution_physical', 'Distribution - Physical', 'Copies physically handed out or delivered to recipients.');
                $renderNumField('total_distribution', 'Total Copies Distributed', 'Calculated for you: digital + physical.', true);
                $renderNumField('souls_won', 'Souls Won', 'People who made salvation decisions through outreach.');
                $renderNumField('rhapsody_outreaches', 'Rhapsody Outreaches', 'Organised outreach events where Rhapsody was the focus.');
                $renderNumField('rhapsody_cells', 'Rhapsody Cells', 'Active Rhapsody cell groups in your zone.');
                $renderNumField('new_churches', 'New Churches', 'New churches or fellowships established this month.');
                $renderNumField('new_partners', 'New Partners', 'People who signed up as Rhapsody partners.');
                $renderNumField('lingual_cells', 'Lingual Cells', 'Cell groups meeting in a language other than English.');
                $renderNumField('language_churches', 'Language Churches', 'Churches mainly operating in a non-English language.');
                $renderNumField('languages_sponsored', 'Languages Sponsored', 'Distinct languages with Rhapsody copies produced or distributed.');
                $renderNumField('distribution_centers', 'Distribution Centers', 'Active Rhapsody distribution points or depots in your zone.');
                $renderNumField('external_ministers', 'External Ministers', 'Ministers from outside your zone who supported outreach.');
                $renderNumField('crusades', 'Crusades', 'Crusades held or supported this month.');
                $renderNumField('testimonies_received', 'Testimonies Received', 'Testimonies received this month. The written document is uploaded separately below.');
                ?>
            </div></div>
        </section>

        <section class="panel cr-section" id="sec-syk">
            <div class="panel-head"><h2 class="cr-section-title"><span class="meta"><?php echo $secNo('syk'); ?></span><?php echo htmlspecialchars($sections['syk']); ?></h2></div>
            <div class="panel-body"><div class="form-grid">
                <?php
                $renderNumField('syk_outreaches', 'Outreaches Held', 'Say Yes to Kids outreach events this month.');
                $renderNumField('syk_elementary_schools', 'Schools Reached', 'Primary/elementary schools visited or engaged.');
                $renderNumField('syk_orphanages', 'Orphanages Reached', "Orphanages or children's homes visited.");
                $renderNumField('syk_children_reached', 'Children Reached', 'Children who received materials or took part.');
                $renderNumField('syk_souls_won', 'Children Saved', 'Children who made salvation decisions.');
                $renderNumField('syk_souls_retained', 'Children Retained', 'Saved children being followed up and retained.');
                $renderNumField('syk_rork_clubs', 'RORK Clubs', 'Rhapsody of Realities for Kids clubs currently active.');
                ?>
            </div></div>
        </section>

        <section class="panel cr-section" id="sec-files">
            <div class="panel-head"><h2 class="cr-section-title"><span class="meta"><?php echo $secNo('files'); ?></span><?php echo htmlspecialchars($sections['files']); ?></h2></div>
            <div class="panel-body">
                <p class="muted" style="margin:0 0 var(--spacing-5);">Upload a new file to replace the current one, or leave it empty to keep it.</p>
                <div class="form-grid">
                    <?php foreach ([['testimonies', 'Testimonies document', 'fa-file-lines'], ['innovations', 'Innovations document', 'fa-lightbulb']] as [$key, $label, $icon]):
                        $current = $report[$key . '_file'] ?? ''; ?>
                    <div class="field cr-field">
                        <label class="field-label" for="<?php echo $key; ?>_file"><?php echo $label; ?></label>
                        <?php if ($current): ?>
                        <div class="cr-file-item cr-current-file">
                            <i class="fas <?php echo $icon; ?>" aria-hidden="true"></i>
                            <a class="link" href="<?php echo BASE_URL; ?>/uploads/documents/<?php echo htmlspecialchars(rawurlencode($current)); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars($current); ?></a>
                            <span class="cr-file-size meta">Current</span>
                        </div>
                        <?php endif; ?>
                        <div class="cr-upload-zone" id="zone-<?php echo $key; ?>-edit" role="button" tabindex="0" onclick="document.getElementById('<?php echo $key; ?>_file').click()">
                            <input type="file" id="<?php echo $key; ?>_file" name="<?php echo $key; ?>_file" accept=".pdf,.doc,.docx">
                            <div class="cr-upload-icon"><i class="fas <?php echo $icon; ?>" aria-hidden="true"></i></div>
                            <div class="cr-upload-label"><?php echo $current ? 'Choose a replacement' : 'Choose a file'; ?></div>
                            <div class="cr-upload-sub">PDF, DOC, DOCX · max 10 MB</div>
                        </div>
                        <div class="cr-file-list" id="list-<?php echo $key; ?>"></div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <?php if ($images): ?>
                <div class="field">
                    <p class="field-label">Current images <span class="field-optional">· select an image to mark it for removal</span></p>
                    <div class="cr-img-grid">
                        <?php foreach ($images as $img): ?>
                        <div class="cr-existing-img" role="checkbox" aria-checked="false" aria-label="Remove this image" tabindex="0"
                             onclick="toggleDeleteImg(this)" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();toggleDeleteImg(this);}">
                            <img src="<?php echo BASE_URL; ?>/uploads/pictures/<?php echo htmlspecialchars(rawurlencode($img['image_path'])); ?>" alt="" loading="lazy">
                            <input type="checkbox" name="delete_images[]" value="<?php echo (int)$img['id']; ?>" style="display:none;" class="del-img-cb">
                            <span class="cr-del-label">Remove</span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <div class="field cr-field" style="margin-bottom:0;">
                    <label class="field-label" for="report_images">Add images <span class="field-optional">(up to 10)</span></label>
                    <div class="cr-upload-zone" id="zone-images" role="button" tabindex="0" onclick="document.getElementById('report_images').click()">
                        <input type="file" id="report_images" name="report_images[]" multiple accept="image/*">
                        <div class="cr-upload-icon"><i class="fas fa-images" aria-hidden="true"></i></div>
                        <div class="cr-upload-label">Choose or drop images</div>
                        <div class="cr-upload-sub">JPG, PNG, GIF</div>
                    </div>
                    <div class="cr-img-grid" id="img-preview"></div>
                </div>
            </div>
        </section>

        <div class="cr-actions">
            <a href="view.php?id=<?php echo $reportId; ?>" class="btn btn-secondary">Cancel</a>
            <span class="meta cr-actions-status" id="edit-status" aria-live="polite"></span>
            <div class="cr-actions-right">
                <button type="submit" id="submit-report-btn" class="btn btn-primary" data-loading-text="Saving changes…">
                    <i class="fas fa-check modern-button-icon" aria-hidden="true"></i> Save changes
                </button>
            </div>
        </div>
    </form>
</div>

<aside class="cr-aside" aria-label="Sections">
    <div class="panel panel-muted"><div class="panel-body">
        <p class="meta">Sections</p>
        <ol class="cr-index">
            <?php foreach ($sections as $key => $title): ?>
            <li><a href="#sec-<?php echo $key; ?>"><span class="meta"><?php echo $secNo($key); ?></span><?php echo htmlspecialchars($title); ?></a></li>
            <?php endforeach; ?>
        </ol>
        <h2>Distribution</h2>
        <p>Digital + physical copies that reached people this month. The total updates as you type.</p>
    </div></div>
</aside>
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
    function fmtSize(b) { return b<1048576?(b/1024).toFixed(1)+' KB':(b/1048576).toFixed(1)+' MB'; }
    function renderFile(file) {
        list.innerHTML = '';
        var ext = (file.name.split('.').pop()||'').toLowerCase();
        var icon = ext==='pdf'?'fa-file-pdf':'fa-file-word';
        var item = document.createElement('div'); item.className='cr-file-item';
        item.innerHTML='<i class="fas '+icon+'" aria-hidden="true"></i><span class="cr-file-name"></span>'
            +'<span class="cr-file-size">'+fmtSize(file.size)+'</span>'
            +'<button type="button" class="cr-file-remove" aria-label="Remove file"><i class="fas fa-times" aria-hidden="true"></i></button>';
        item.querySelector('.cr-file-name').textContent=file.name;
        item.querySelector('.cr-file-remove').addEventListener('click',function(){ input.value=''; list.innerHTML=''; zone.classList.remove('has-file'); });
        list.appendChild(item);
        zone.classList.add('has-file');
    }
    input.addEventListener('change', function(){ if(this.files&&this.files[0]) renderFile(this.files[0]); });
    zone.addEventListener('keydown', zoneKey(input));
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
                    +'<button type="button" class="cr-img-remove" data-idx="'+idx+'" aria-label="Remove image"><i class="fas fa-times" aria-hidden="true"></i></button>';
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
    zone.addEventListener('keydown', zoneKey(input));
    zone.addEventListener('dragover',function(e){e.preventDefault();zone.classList.add('drag-over');});
    zone.addEventListener('dragleave',function(){zone.classList.remove('drag-over');});
    zone.addEventListener('drop',function(e){e.preventDefault();zone.classList.remove('drag-over');if(e.dataTransfer&&e.dataTransfer.files)addFiles(e.dataTransfer.files);});
}

window.toggleDeleteImg = function(el) {
    el.classList.toggle('marked');
    var cb = el.querySelector('.del-img-cb');
    if (cb) cb.checked = el.classList.contains('marked');
    el.setAttribute('aria-checked', el.classList.contains('marked') ? 'true' : 'false');
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
    form.addEventListener('input', function() { document.getElementById('edit-status').textContent = 'Unsaved changes'; });
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
