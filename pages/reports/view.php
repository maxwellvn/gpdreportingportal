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

<style>
.rv-page { max-width: 860px; margin: 0 auto; padding: 2rem 1.25rem 3rem; }
.rv-topbar { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.75rem; }
.rv-topbar-left { display: flex; align-items: center; gap: 0.75rem; }
.rv-back { display: inline-flex; align-items: center; justify-content: center; width: 2rem; height: 2rem; border: 1px solid var(--neutral-200); border-radius: var(--radius-md); color: var(--neutral-500); text-decoration: none; font-size: 0.8rem; transition: border-color 0.15s; }
.rv-back:hover { border-color: var(--neutral-400); color: var(--neutral-700); }
.rv-topbar-left h1 { font-size: 1.125rem; font-weight: 700; color: var(--neutral-900); margin: 0; }
.rv-topbar-left p { font-size: 0.8rem; color: var(--neutral-500); margin: 0; }
.rv-topbar-actions { display: flex; gap: 0.5rem; flex-wrap: wrap; }
.rv-section { border: 1px solid var(--neutral-200); border-radius: var(--radius-lg); background: #fff; margin-bottom: 1.25rem; overflow: hidden; }
.rv-section-head { padding: 0.85rem 1.25rem; border-bottom: 1px solid var(--neutral-200); }
.rv-section-head h2 { font-size: 0.875rem; font-weight: 600; color: var(--neutral-900); margin: 0; text-transform: uppercase; letter-spacing: 0.04em; }
.rv-section-body { padding: 1.25rem; }
.rv-meta-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1.25rem; }
@media(max-width:600px){ .rv-meta-grid { grid-template-columns: repeat(2,1fr); } }
.rv-meta-label { font-size: 0.75rem; color: var(--neutral-500); margin-bottom: 0.25rem; }
.rv-meta-value { font-size: 0.9375rem; font-weight: 600; color: var(--neutral-900); }
.rv-metric-grid { display: grid; grid-template-columns: repeat(2,1fr); gap: 1px; background: var(--neutral-200); }
.rv-metric-grid.cols-3 { grid-template-columns: repeat(3,1fr); }
.rv-metric-grid.cols-4 { grid-template-columns: repeat(4,1fr); }
@media(max-width:640px){ .rv-metric-grid.cols-3,.rv-metric-grid.cols-4 { grid-template-columns: repeat(2,1fr); } }
.rv-metric { background:#fff; padding: 1rem 1.1rem; }
.rv-metric-label { font-size: 0.75rem; color: var(--neutral-500); margin-bottom: 0.3rem; }
.rv-metric-value { font-size: 1.1rem; font-weight: 700; color: var(--neutral-900); }
.rv-metric-value.accent { color: var(--primary-600); }
.rv-attachment { display: flex; align-items: center; gap: 1rem; padding: 0.85rem 1.25rem; border-bottom: 1px solid var(--neutral-200); }
.rv-attachment:last-child { border-bottom: none; }
.rv-attachment-icon { width: 2rem; height: 2rem; border-radius: var(--radius-md); background: var(--neutral-100); border: 1px solid var(--neutral-200); display: flex; align-items: center; justify-content: center; color: var(--neutral-500); font-size: 0.85rem; flex-shrink: 0; }
.rv-attachment-name { font-size: 0.85rem; font-weight: 500; color: var(--neutral-900); }
.rv-gallery { display: grid; grid-template-columns: repeat(4,1fr); gap: 0.75rem; }
@media(max-width:640px){ .rv-gallery { grid-template-columns: repeat(2,1fr); } }
.rv-gallery-img { aspect-ratio:1; object-fit:cover; border-radius:var(--radius-md); border:1px solid var(--neutral-200); cursor:pointer; width:100%; transition:opacity 0.15s; }
.rv-gallery-img:hover { opacity:0.8; }
.rv-success { display:flex; align-items:flex-start; gap:0.75rem; margin-bottom:1.25rem; padding:0.9rem 1rem; border:1px solid var(--success-200); border-radius:var(--radius-md); background:var(--success-50); color:var(--success-700); }
.rv-success strong { display:block; font-size:0.875rem; margin-bottom:0.15rem; }
.rv-success span { display:block; font-size:0.78rem; }
</style>

<div class="rv-page">
    <?php if (isset($_SESSION['success'])): ?>
    <div class="rv-success" role="status" aria-live="polite">
        <i class="fas fa-check-circle" aria-hidden="true"></i>
        <div>
            <strong>Report submitted</strong>
            <span><?php echo htmlspecialchars($_SESSION['success']); ?></span>
        </div>
    </div>
    <?php endif; ?>

    <!-- Top bar -->
    <div class="rv-topbar">
        <div class="rv-topbar-left">
            <a href="list.php" class="rv-back"><i class="fas fa-arrow-left"></i></a>
            <div>
                <h1>Report #<?php echo $reportId; ?> &mdash; <?php echo date('F Y', strtotime($report['report_month'])); ?></h1>
                <p>Submitted <?php echo date('F j, Y', strtotime($report['created_at'])); ?></p>
            </div>
        </div>
        <div class="rv-topbar-actions">
            <?php if ($isAdmin || $isAdminManager || $report['user_id'] == $_SESSION['user_id']): ?>
            <a href="edit.php?id=<?php echo $reportId; ?>" class="modern-button modern-button-primary" style="font-size:0.8125rem;"><i class="fas fa-edit modern-button-icon"></i> Edit</a>
            <?php endif; ?>
            <a href="../../includes/export_report.php?id=<?php echo $reportId; ?>" class="modern-button modern-button-secondary" style="font-size:0.8125rem;"><i class="fas fa-file-csv modern-button-icon"></i> Export CSV</a>
        </div>
    </div>

    <!-- Report meta -->
    <div class="rv-section">
        <div class="rv-section-head"><h2>Report Information</h2></div>
        <div class="rv-section-body">
            <div class="rv-meta-grid">
                <div><div class="rv-meta-label">Submitted By</div><div class="rv-meta-value"><?php echo htmlspecialchars($report['name']); ?></div></div>
                <div><div class="rv-meta-label">Region</div><div class="rv-meta-value"><?php echo htmlspecialchars($report['region']); ?></div></div>
                <div><div class="rv-meta-label">Zone</div><div class="rv-meta-value"><?php echo htmlspecialchars($report['zone']); ?></div></div>
                <div><div class="rv-meta-label">Report Month</div><div class="rv-meta-value"><?php echo date('F Y', strtotime($report['report_month'])); ?></div></div>
                <div><div class="rv-meta-label">Submitted On</div><div class="rv-meta-value"><?php echo date('F j, Y', strtotime($report['created_at'])); ?></div></div>
                <?php
                $rate = $report['total_copies'] > 0 ? round(($report['total_distribution'] / $report['total_copies']) * 100, 1) : 0;
                ?>
                <div><div class="rv-meta-label">Distribution Rate</div><div class="rv-meta-value" style="color:var(--primary-600);"><?php echo $rate; ?>%</div></div>
            </div>
        </div>
    </div>

    <!-- Copies + Alerts -->
    <div class="rv-section">
        <div class="rv-section-head"><h2>Copies &amp; Alerts</h2></div>
        <div class="rv-metric-grid cols-3" style="border:none;">
            <div class="rv-metric" style="border-right:1px solid var(--neutral-200);border-bottom:1px solid var(--neutral-200);"><div class="rv-metric-label">Copies Sponsored</div><div class="rv-metric-value accent"><?php echo number_format($report['total_copies']); ?></div></div>
            <div class="rv-metric" style="border-right:1px solid var(--neutral-200);border-bottom:1px solid var(--neutral-200);"><div class="rv-metric-label">Copies Distributed</div><div class="rv-metric-value"><?php echo number_format($report['total_distribution']); ?></div></div>
            <div class="rv-metric" style="border-bottom:1px solid var(--neutral-200);"><div class="rv-metric-label">Distribution - Digital</div><div class="rv-metric-value"><?php echo number_format($report['distribution_digital'] ?? 0); ?></div></div>
            <div class="rv-metric" style="border-right:1px solid var(--neutral-200);border-bottom:1px solid var(--neutral-200);"><div class="rv-metric-label">Distribution - Physical</div><div class="rv-metric-value"><?php echo number_format($report['distribution_physical'] ?? 0); ?></div></div>
            <div class="rv-metric" style="border-right:1px solid var(--neutral-200);border-bottom:1px solid var(--neutral-200);"><div class="rv-metric-label">Wonder Alerts</div><div class="rv-metric-value"><?php echo number_format($report['wonder_alerts']); ?></div></div>
            <div class="rv-metric" style="border-bottom:1px solid var(--neutral-200);"><div class="rv-metric-label">Say Yes to Kids Alerts</div><div class="rv-metric-value"><?php echo number_format($report['kids_alerts']); ?></div></div>
            <div class="rv-metric" style="border-right:1px solid var(--neutral-200);"><div class="rv-metric-label">Language Redemption Missions</div><div class="rv-metric-value"><?php echo number_format($report['language_missions']); ?></div></div>
            <div class="rv-metric" style="border-right:1px solid var(--neutral-200);"><div class="rv-metric-label">Partners Prayer Programs</div><div class="rv-metric-value"><?php echo number_format($report['prayer_programs']); ?></div></div>
            <div class="rv-metric"><div class="rv-metric-label">Testimonies Received</div><div class="rv-metric-value"><?php echo number_format($report['testimonies_received'] ?? 0); ?></div></div>
        </div>
    </div>

    <!-- Sub Campaigns -->
    <div class="rv-section">
        <div class="rv-section-head"><h2>Sub Campaigns</h2></div>
        <div class="rv-metric-grid cols-4" style="border:none;">
            <?php
            $campaigns = [
                'Penetrating with Truth' => $report['penetrating_truth'],
                'Penetrating with Languages' => $report['penetrating_languages'],
                'Youth Aglow' => $report['youth_aglow'],
                'Every Minister Campaign' => $report['minister_campaign'],
                'Say Yes to Kids' => $report['say_yes_kids'],
                'No One Left Behind' => $report['no_one_left'],
                'Teevolution' => $report['teevolution'],
                'Subscriptions' => $report['subscriptions'],
                'Partners Programs' => $report['partner_programs'],
            ];
            $ci = 0; $total = count($campaigns);
            foreach ($campaigns as $label => $val):
                $ci++;
                $col = $ci % 4; $isLast4 = $ci > $total - ($total % 4 ?: 4);
                $br = !$isLast4 ? 'border-bottom:1px solid var(--neutral-200);' : '';
                $br .= ($col !== 0) ? 'border-right:1px solid var(--neutral-200);' : '';
            ?>
            <div class="rv-metric" style="<?php echo $br; ?>"><div class="rv-metric-label"><?php echo $label; ?></div><div class="rv-metric-value"><?php echo number_format($val); ?></div></div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Reach & Impact -->
    <div class="rv-section">
        <div class="rv-section-head"><h2>Reach &amp; Impact</h2></div>
        <div class="rv-metric-grid cols-3" style="border:none;">
            <?php
            $impact = [
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
            ];
            $ci = 0; $total = count($impact);
            foreach ($impact as $label => $val):
                $ci++;
                $col = $ci % 3; $isLast3 = $ci > $total - ($total % 3 ?: 3);
                $br = !$isLast3 ? 'border-bottom:1px solid var(--neutral-200);' : '';
                $br .= ($col !== 0) ? 'border-right:1px solid var(--neutral-200);' : '';
            ?>
            <div class="rv-metric" style="<?php echo $br; ?>"><div class="rv-metric-label"><?php echo $label; ?></div><div class="rv-metric-value"><?php echo number_format($val); ?></div></div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Say Yes to Kids -->
    <div class="rv-section">
        <div class="rv-section-head"><h2>Say Yes to Kids Impact</h2></div>
        <div class="rv-metric-grid cols-3" style="border:none;">
            <?php
            $syk = [
                'Outreaches' => $report['syk_outreaches'],
                'Elementary Schools Reached' => $report['syk_elementary_schools'],
                'Orphanages Reached' => $report['syk_orphanages'],
                'Children Reached' => $report['syk_children_reached'],
                'Souls (Children) Won' => $report['syk_souls_won'],
                'Souls Retained' => $report['syk_souls_retained'],
                'RORK Clubs' => $report['syk_rork_clubs'],
            ];
            $ci = 0; $total = count($syk);
            foreach ($syk as $label => $val):
                $ci++;
                $col = $ci % 3; $isLast3 = $ci > $total - ($total % 3 ?: 3);
                $br = !$isLast3 ? 'border-bottom:1px solid var(--neutral-200);' : '';
                $br .= ($col !== 0) ? 'border-right:1px solid var(--neutral-200);' : '';
            ?>
            <div class="rv-metric" style="<?php echo $br; ?>"><div class="rv-metric-label"><?php echo $label; ?></div><div class="rv-metric-value"><?php echo number_format($val); ?></div></div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Attachments -->
    <?php if ($report['testimonies_file'] || $report['innovations_file']): ?>
    <div class="rv-section">
        <div class="rv-section-head"><h2>Attachments</h2></div>
        <?php if ($report['testimonies_file']): ?>
        <div class="rv-attachment">
            <div class="rv-attachment-icon"><i class="fas fa-file-alt"></i></div>
            <div style="flex:1;"><div class="rv-attachment-name">Testimonies Document</div></div>
            <a href="../../uploads/documents/<?php echo htmlspecialchars($report['testimonies_file']); ?>" class="modern-button modern-button-secondary" style="font-size:0.8rem;" download><i class="fas fa-download modern-button-icon"></i> Download</a>
        </div>
        <?php endif; ?>
        <?php if ($report['innovations_file']): ?>
        <div class="rv-attachment">
            <div class="rv-attachment-icon"><i class="fas fa-lightbulb"></i></div>
            <div style="flex:1;"><div class="rv-attachment-name">Innovations Document</div></div>
            <a href="../../uploads/documents/<?php echo htmlspecialchars($report['innovations_file']); ?>" class="modern-button modern-button-secondary" style="font-size:0.8rem;" download><i class="fas fa-download modern-button-icon"></i> Download</a>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Gallery -->
    <?php if (!empty($images)): ?>
    <div class="rv-section">
        <div class="rv-section-head"><h2>Pictures Gallery</h2></div>
        <div class="rv-section-body">
            <div class="rv-gallery">
                <?php foreach ($images as $image): ?>
                <img src="../../uploads/pictures/<?php echo htmlspecialchars($image['image_path']); ?>"
                     alt="Report Image" class="rv-gallery-img"
                     onclick="viewImage('../../uploads/pictures/<?php echo htmlspecialchars($image['image_path']); ?>')">
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
