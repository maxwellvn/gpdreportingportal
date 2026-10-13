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

$monthLabel = date('F Y', strtotime($report['report_month']));
$canEdit = $isAdmin || $isAdminManager || $report['user_id'] == $_SESSION['user_id'];
$rate = $report['total_copies'] > 0 ? round(($report['total_distribution'] / $report['total_copies']) * 100, 1) : 0;
$wasEdited = !empty($report['updated_at']) && strtotime($report['updated_at']) > strtotime($report['created_at']) + 60;
$groups = [
    'Period & copies' => [
        'Report month' => $monthLabel,
        'Total copies sponsored' => $report['total_copies'],
    ],
    'Alerts & language missions' => [
        'Wonder Alerts Received' => $report['wonder_alerts'],
        'Kids Alerts' => $report['kids_alerts'],
        'Language Missions' => $report['language_missions'],
        'Penetrating with Truth' => $report['penetrating_truth'],
        'Penetrating with Languages' => $report['penetrating_languages'],
    ],
    'Campaigns & programs' => [
        'Youth Aglow' => $report['youth_aglow'],
        'Every Minister Campaign' => $report['minister_campaign'],
        'Say Yes to Kids' => $report['say_yes_kids'],
        'No One Left Behind' => $report['no_one_left'],
        'Teevolution' => $report['teevolution'],
        'Subscriptions' => $report['subscriptions'],
        'Partners Prayer Programs' => $report['prayer_programs'],
        'Partners Programs' => $report['partner_programs'],
    ],
    'Distribution & growth' => [
        'Distribution - Digital' => $report['distribution_digital'] ?? 0,
        'Distribution - Physical' => $report['distribution_physical'] ?? 0,
        'Total Copies Distributed' => $report['total_distribution'],
        'Souls Won' => $report['souls_won'],
        'Rhapsody Outreaches' => $report['rhapsody_outreaches'],
        'Rhapsody Cells' => $report['rhapsody_cells'],
        'New Churches' => $report['new_churches'],
        'New Partners' => $report['new_partners'],
        'Lingual Cells' => $report['lingual_cells'],
        'Language Churches' => $report['language_churches'],
        'Languages Sponsored' => $report['languages_sponsored'],
        'Distribution Centers' => $report['distribution_centers'],
        'External Ministers' => $report['external_ministers'],
        'Crusades' => $report['crusades'] ?? 0,
        'Testimonies Received' => $report['testimonies_received'] ?? 0,
    ],
    'Say Yes to Kids impact' => [
        'Outreaches Held' => $report['syk_outreaches'],
        'Schools Reached' => $report['syk_elementary_schools'],
        'Orphanages Reached' => $report['syk_orphanages'],
        'Children Reached' => $report['syk_children_reached'],
        'Children Saved' => $report['syk_souls_won'],
        'Children Retained' => $report['syk_souls_retained'],
        'RORK Clubs' => $report['syk_rork_clubs'],
    ],
];
$docs = array_filter([
    'Testimonies document' => $report['testimonies_file'] ?? '',
    'Innovations document' => $report['innovations_file'] ?? '',
]);
?>

<style>
.rv-head-meta { display: flex; flex-wrap: wrap; align-items: center; gap: var(--spacing-2); margin-top: var(--spacing-3); }
.rv-stats { margin-bottom: var(--spacing-8); grid-template-columns: repeat(4, 1fr); }
@media (max-width: 720px) { .rv-stats { grid-template-columns: repeat(2, 1fr); } }
.rv-groups { columns: 2 22rem; column-gap: var(--spacing-6); margin-bottom: var(--spacing-8); }
.rv-groups .panel { break-inside: avoid; margin-bottom: var(--spacing-6); }
.rv-groups .kv { grid-template-columns: minmax(0, 1fr) auto; }
.rv-groups .kv dd { padding-left: var(--spacing-4); }
.rv-groups .panel-body { padding-top: var(--spacing-1); padding-bottom: var(--spacing-1); }
.rv-groups .kv { font-size: var(--text-sm); }
.rv-doc { display: flex; align-items: center; gap: var(--spacing-3); padding: var(--spacing-4) var(--spacing-6); border-bottom: 1px solid var(--neutral-200); }
.rv-doc:last-child { border-bottom: 0; }
.rv-doc i { color: var(--neutral-600); }
.rv-doc-name { flex: 1; min-width: 0; }
.rv-doc-name .meta { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; text-transform: none; }
.rv-gallery { display: grid; grid-template-columns: repeat(auto-fill, minmax(9rem, 1fr)); gap: var(--spacing-3); }
.rv-thumb { display: block; padding: 0; border: 0; background: var(--neutral-100); border-radius: var(--radius-md); overflow: hidden; aspect-ratio: 4 / 3; cursor: zoom-in; }
.rv-thumb img { display: block; width: 100%; height: 100%; object-fit: cover; border-radius: inherit; outline: 1px solid color-mix(in srgb, var(--ink) 10%, transparent); outline-offset: -1px; transition: transform var(--dur) var(--ease-out); }
@media (hover: hover) and (pointer: fine) { .rv-thumb:hover img { transform: scale(1.02); } }
@media (prefers-reduced-motion: reduce) { .rv-thumb img { transition: none; } }
.rv-lightbox-img { max-height: 80vh; max-width: 100%; width: auto; }
@media print {
  .modern-header, .action-bar, .page-actions, .breadcrumb, .alert { display: none !important; }
  .modern-main { margin-top: 0; padding-top: 0; }
  .panel { break-inside: avoid; }
}
</style>

<?php if (isset($_SESSION['success'])): ?>
<div class="alert alert-success" role="status">
    <i class="fas fa-circle-check" aria-hidden="true"></i>
    <div><div class="alert-title"><?php echo isset($_GET['submitted']) ? 'Report submitted' : 'Saved'; ?></div><?php echo htmlspecialchars($_SESSION['success']); ?></div>
</div>
<?php unset($_SESSION['success']); endif; ?>
<?php if (isset($_SESSION['error'])): ?>
<div class="alert alert-danger" role="alert">
    <i class="fas fa-circle-exclamation" aria-hidden="true"></i>
    <div><?php echo htmlspecialchars($_SESSION['error']); ?></div>
</div>
<?php unset($_SESSION['error']); endif; ?>

<nav class="breadcrumb" aria-label="Breadcrumb">
    <a href="<?php echo BASE_URL; ?>/dashboard.php">Dashboard</a>
    <span class="sep">/</span>
    <a href="list.php">Reports</a>
    <span class="sep">/</span>
    <span><?php echo htmlspecialchars($monthLabel); ?></span>
</nav>

<div class="page-head">
    <div>
        <p class="page-eyebrow">Report #<?php echo $reportId; ?></p>
        <h1 class="page-title"><?php echo htmlspecialchars($monthLabel); ?></h1>
        <p class="page-sub">
            <?php echo htmlspecialchars($report['name'] ?? ''); ?>
            <?php if (!empty($report['zone']) || !empty($report['region'])): ?>
            · <?php echo htmlspecialchars(trim(($report['zone'] ?? '') . ', ' . ($report['region'] ?? ''), ', ')); ?>
            <?php endif; ?>
        </p>
        <div class="rv-head-meta">
            <span class="badge badge-success">Submitted</span>
            <span class="meta">Submitted <?php echo date('M j, Y', strtotime($report['created_at'])); ?></span>
            <?php if ($wasEdited): ?><span class="meta">· Edited <?php echo date('M j, Y', strtotime($report['updated_at'])); ?></span><?php endif; ?>
        </div>
    </div>
    <div class="page-actions">
        <a href="../../includes/export_report.php?id=<?php echo $reportId; ?>" class="btn btn-secondary"><i class="fas fa-file-csv modern-button-icon" aria-hidden="true"></i> Export CSV</a>
        <button type="button" class="btn btn-secondary" onclick="window.print()"><i class="fas fa-file-pdf modern-button-icon" aria-hidden="true"></i> PDF</button>
        <?php if ($canEdit): ?>
        <a href="edit.php?id=<?php echo $reportId; ?>" class="btn btn-primary"><i class="fas fa-pen modern-button-icon" aria-hidden="true"></i> Edit</a>
        <?php endif; ?>
    </div>
</div>

<div class="stat-grid rv-stats">
    <div class="stat"><div class="stat-label">Copies sponsored</div><div class="stat-value num"><?php echo number_format((int)$report['total_copies']); ?></div></div>
    <div class="stat"><div class="stat-label">Copies distributed</div><div class="stat-value num"><?php echo number_format((int)$report['total_distribution']); ?></div></div>
    <div class="stat"><div class="stat-label">Distribution rate</div><div class="stat-value num"><?php echo $rate; ?>%</div></div>
    <div class="stat"><div class="stat-label">Souls won</div><div class="stat-value num"><?php echo number_format((int)$report['souls_won']); ?></div></div>
</div>

<div class="rv-groups">
    <?php foreach ($groups as $title => $rows): ?>
    <section class="panel">
        <div class="panel-head"><h2 class="section-title"><?php echo htmlspecialchars($title); ?></h2></div>
        <div class="panel-body">
            <dl class="kv">
                <?php foreach ($rows as $label => $val): ?>
                <dt><?php echo htmlspecialchars($label); ?></dt>
                <dd><?php echo is_numeric($val) ? number_format((int)$val) : htmlspecialchars($val); ?></dd>
                <?php endforeach; ?>
            </dl>
        </div>
    </section>
    <?php endforeach; ?>
</div>

<section class="section">
    <div class="section-head">
        <h2 class="section-title">Testimonies &amp; attachments</h2>
        <span class="section-meta meta"><?php echo number_format((int)($report['testimonies_received'] ?? 0)); ?> testimonies received · <?php echo count($images); ?> images</span>
    </div>
    <?php if ($docs): ?>
    <div class="panel" style="margin-bottom:var(--spacing-6);">
        <?php foreach ($docs as $label => $file): $href = '../../uploads/documents/' . rawurlencode($file); ?>
        <div class="rv-doc">
            <i class="fas <?php echo strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'pdf' ? 'fa-file-pdf' : 'fa-file-lines'; ?>" aria-hidden="true"></i>
            <div class="rv-doc-name"><?php echo htmlspecialchars($label); ?><span class="meta"><?php echo htmlspecialchars($file); ?></span></div>
            <a href="<?php echo htmlspecialchars($href); ?>" class="btn btn-sm btn-ghost" target="_blank" rel="noopener">Open</a>
            <a href="<?php echo htmlspecialchars($href); ?>" class="btn btn-sm btn-secondary" download>Download</a>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($images)): ?>
    <div class="rv-gallery">
        <?php foreach ($images as $i => $image): $src = '../../uploads/pictures/' . rawurlencode($image['image_path']); ?>
        <button type="button" class="rv-thumb" data-src="<?php echo htmlspecialchars($src); ?>" aria-label="Open image <?php echo $i + 1; ?> of <?php echo count($images); ?>">
            <img src="<?php echo htmlspecialchars($src); ?>" alt="" loading="lazy">
        </button>
        <?php endforeach; ?>
    </div>
    <?php elseif (!$docs): ?>
    <div class="panel"><div class="empty-state">
        <div class="empty-state-icon"><i class="fas fa-paperclip" aria-hidden="true"></i></div>
        <div class="empty-state-title">No attachments</div>
        <div class="empty-state-text">This report has no documents or images.</div>
    </div></div>
    <?php endif; ?>
</section>

<script>
document.addEventListener('click', function(e) {
    var btn = e.target.closest && e.target.closest('.rv-thumb');
    if (!btn) return;
    Swal.fire({
        imageUrl: btn.dataset.src,
        imageAlt: 'Report image',
        width: 'auto',
        padding: '1em',
        showConfirmButton: false,
        showCloseButton: true,
        customClass: { image: 'rv-lightbox-img' }
    });
});
</script>

<?php require_once '../../layouts/footer.php'; ?>
