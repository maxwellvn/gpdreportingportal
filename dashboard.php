<?php
require_once 'config/config.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Get user data
$auth = new Auth();
$user = $auth->getUser($_SESSION['user_id']);
if (!$user) {
    // Handle error - redirect to login if user data cannot be retrieved
    session_destroy();
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
// Store minimal, sanitized user data in session
$_SESSION['user_data'] = [
    'id' => (int)$user['id'],
    'username' => htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8'),
    'name' => htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8'),
    'role' => $user['role'],
    'region' => htmlspecialchars((string)($user['region'] ?? ''), ENT_QUOTES, 'UTF-8'),
    'zone' => htmlspecialchars((string)($user['zone'] ?? ''), ENT_QUOTES, 'UTF-8')
];

// Get reports count and recent reports
$db = new Database();
$conn = $db->getConnection();

// Initialize variables
$reportsCount = 0;
$recentReports = [];
$monthlyTotal = 0;
$totalCopies = 0;
$totalDistributions = 0;
$totalSoulsWon = 0;
$totalNewPartners = 0;
$totalRhapsodyCells = 0;
$totalNewChurches = 0;

try {
    $currentYear = date('Y');

    // Get reports count
    if (isset($user['role']) && $user['role'] === 'admin') {
        $reportsQuery = "SELECT COUNT(*) as count FROM reports WHERE YEAR(report_month) = ?";
        $stmt = $conn->prepare($reportsQuery);
        $stmt->execute([$currentYear]);
    } else {
        $reportsQuery = "SELECT COUNT(*) as count FROM reports WHERE user_id = ? AND YEAR(report_month) = ?";
        $stmt = $conn->prepare($reportsQuery);
        $stmt->execute([$_SESSION['user_id'], $currentYear]);
    }
    $result = $stmt->fetch();
    $reportsCount = $result ? ($result['count'] ?? 0) : 0;

    // Get total copies, distributions, and souls won
    if (isset($user['role']) && $user['role'] === 'admin') {
        $stmt = $conn->prepare("
            SELECT 
                COALESCE(SUM(total_copies), 0) as total_copies,
                COALESCE(SUM(total_distribution), 0) as total_distributions,
                COALESCE(SUM(souls_won), 0) as total_souls,
                COALESCE(SUM(new_partners), 0) as total_new_partners,
                COALESCE(SUM(rhapsody_cells), 0) as total_rhapsody_cells,
                COALESCE(SUM(new_churches), 0) as total_new_churches
            FROM reports 
            WHERE YEAR(report_month) = ?
        ");
        $stmt->execute([$currentYear]);
    } else {
        $stmt = $conn->prepare("
            SELECT 
                COALESCE(SUM(total_copies), 0) as total_copies,
                COALESCE(SUM(total_distribution), 0) as total_distributions,
                COALESCE(SUM(souls_won), 0) as total_souls,
                COALESCE(SUM(new_partners), 0) as total_new_partners,
                COALESCE(SUM(rhapsody_cells), 0) as total_rhapsody_cells,
                COALESCE(SUM(new_churches), 0) as total_new_churches
            FROM reports 
            WHERE user_id = ? AND YEAR(report_month) = ?
        ");
        $stmt->execute([$_SESSION['user_id'], $currentYear]);
    }
    $result = $stmt->fetch();
    $totalCopies = $result ? ($result['total_copies'] ?? 0) : 0;
    $totalDistributions = $result ? ($result['total_distributions'] ?? 0) : 0;
    $totalSoulsWon = $result ? ($result['total_souls'] ?? 0) : 0;
    $totalNewPartners = $result ? ($result['total_new_partners'] ?? 0) : 0;
    $totalRhapsodyCells = $result ? ($result['total_rhapsody_cells'] ?? 0) : 0;
    $totalNewChurches = $result ? ($result['total_new_churches'] ?? 0) : 0;

    // Get recent reports - Filtered by current year as well
    if (isset($user['role']) && $user['role'] === 'admin') {
        $recentReportsQuery = "SELECT r.*, COALESCE(u.name, 'Unknown') as name, u.zone 
                              FROM reports r 
                              LEFT JOIN users u ON r.user_id = u.id 
                              WHERE YEAR(r.report_month) = ?
                              ORDER BY r.created_at DESC LIMIT 5";
        $stmt = $conn->prepare($recentReportsQuery);
        $stmt->execute([$currentYear]);
    } else {
        $recentReportsQuery = "SELECT r.*, COALESCE(u.name, 'Unknown') as name, u.zone 
                              FROM reports r 
                              LEFT JOIN users u ON r.user_id = u.id 
                              WHERE r.user_id = ? AND YEAR(r.report_month) = ?
                              ORDER BY r.created_at DESC LIMIT 5";
        $stmt = $conn->prepare($recentReportsQuery);
        $stmt->execute([$_SESSION['user_id'], $currentYear]);
    }
    $recentReports = $stmt->fetchAll() ?: [];

    // Get monthly total
    if (isset($user['role']) && $user['role'] === 'admin') {
        $monthlyQuery = "SELECT COALESCE(SUM(total_copies), 0) as total FROM reports WHERE MONTH(report_month) = MONTH(CURRENT_DATE()) AND YEAR(report_month) = YEAR(CURRENT_DATE())";
        $stmt = $conn->query($monthlyQuery);
    } else {
        $monthlyQuery = "SELECT COALESCE(SUM(total_copies), 0) as total FROM reports WHERE user_id = ? AND MONTH(report_month) = MONTH(CURRENT_DATE()) AND YEAR(report_month) = YEAR(CURRENT_DATE())";
        $stmt = $conn->prepare($monthlyQuery);
        $stmt->execute([$_SESSION['user_id']]);
    }
    $result = $stmt->fetch();
    $monthlyTotal = $result ? ($result['total'] ?? 0) : 0;

} catch (PDOException $e) {
    error_log("Dashboard query error: " . $e->getMessage());
    // Set default values in case of error
    $reportsCount = 0;
    $totalCopies = 0;
    $totalDistributions = 0;
    $totalSoulsWon = 0;
    $totalNewPartners = 0;
    $totalRhapsodyCells = 0;
    $totalNewChurches = 0;
    $recentReports = [];
    $monthlyTotal = 0;
}

// --- Extra stats for non-admin users ---
$lastReportId       = null;
$consistencyStreak  = 0;
$bestMonthLabel     = '';
$bestMonthValue     = 0;
$daysLeftInMonth    = 0;
$overdueCount       = 0;
$totalMonthsExpected = 0;

if (!isset($user['role']) || $user['role'] === ROLE_RZM || $user['role'] === 'admin-manager') {
    try {
        // Last submitted report ID
        $stmt = $conn->prepare("SELECT id FROM reports WHERE user_id = ? ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([$_SESSION['user_id']]);
        $lr = $stmt->fetch();
        $lastReportId = $lr ? (int)$lr['id'] : null;

        // Best month (highest total_copies this year)
        $stmt = $conn->prepare("
            SELECT DATE_FORMAT(report_month,'%M %Y') as label, total_copies
            FROM reports
            WHERE user_id = ? AND YEAR(report_month) = ?
            ORDER BY total_copies DESC LIMIT 1
        ");
        $stmt->execute([$_SESSION['user_id'], $currentYear]);
        $bm = $stmt->fetch();
        if ($bm) { $bestMonthLabel = $bm['label']; $bestMonthValue = (int)$bm['total_copies']; }

        // Consistency streak: consecutive months submitted going backwards from last month
        $streak = 0;
        $check = new DateTime('first day of last month');
        for ($s = 0; $s < 24; $s++) {
            $ym = $check->format('Y-m');
            $stmt = $conn->prepare("SELECT id FROM reports WHERE user_id = ? AND DATE_FORMAT(report_month,'%Y-%m') = ?");
            $stmt->execute([$_SESSION['user_id'], $ym]);
            if ($stmt->fetch()) { $streak++; $check->modify('first day of previous month'); }
            else { break; }
        }
        $consistencyStreak = $streak;

        // Days left in current month
        $lastDayThisMonth = new DateTime('last day of this month');
        $todayObj = new DateTime('today');
        $daysLeftInMonth = max(0, (int)$todayObj->diff($lastDayThisMonth)->days);

    } catch (Exception $e) {
        error_log("Dashboard extra stats error: " . $e->getMessage());
    }
}

// --- Missing months detection (non-admin users only) ---
$missingMonths = [];
$currentMonthSubmitted = true;
$currentMonthDaysLate = 0;

if (!isset($user['role']) || $user['role'] === ROLE_RZM) {
    try {
        // Start from Jan 1 of the current year.
        // Only push forward to registration month if the user registered mid-way through THIS year.
        $startDate = new DateTime('first day of January ' . date('Y'));
        $userCreatedAt = $user['created_at'] ?? null;
        if ($userCreatedAt) {
            $createdDate = new DateTime($userCreatedAt);
            if ($createdDate->format('Y') === date('Y')) {
                // Registered this year — start from their registration month
                $createdFirstOfMonth = new DateTime($createdDate->format('Y-m-01'));
                if ($createdFirstOfMonth > $startDate) {
                    $startDate = $createdFirstOfMonth;
                }
            }
            // Registered in a prior year — always use Jan 1 of current year (no change needed)
        }

        // Fetch all report months already submitted by this user this year
        $stmt = $conn->prepare(
            "SELECT DATE_FORMAT(report_month, '%Y-%m') as ym FROM reports WHERE user_id = ? AND YEAR(report_month) = ?"
        );
        $stmt->execute([$_SESSION['user_id'], date('Y')]);
        $submittedMonths = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'ym');

        // Build list of months from startDate up to current month
        $today = new DateTime('today');
        $currentMonthStart = new DateTime('first day of this month');
        $iterDate = clone $startDate;

        while ($iterDate <= $currentMonthStart) {
            $ym = $iterDate->format('Y-m');
            if (!in_array($ym, $submittedMonths)) {
                // Calculate days late: from last day of that month (or today if current month)
                $lastDayOfMonth = new DateTime($iterDate->format('Y-m-t'));
                if ($lastDayOfMonth > $today) {
                    $lastDayOfMonth = clone $today;
                }
                $daysLate = (int)$today->diff($lastDayOfMonth)->days;
                // If today is past the last day of month, it's that many days late
                if ($today > new DateTime($iterDate->format('Y-m-t'))) {
                    $daysLate = (int)$today->diff(new DateTime($iterDate->format('Y-m-t')))->days;
                } else {
                    $daysLate = 0;
                }

                $missingMonths[] = [
                    'ym'        => $ym,
                    'label'     => $iterDate->format('F Y'),
                    'days_late' => $daysLate,
                    'is_current'=> ($ym === date('Y-m')),
                ];

                if ($ym === date('Y-m')) {
                    $currentMonthSubmitted = false;
                    $currentMonthDaysLate = $daysLate;
                }
            }
            $iterDate->modify('first day of next month');
        }
    } catch (Exception $e) {
        error_log("Missing months detection error: " . $e->getMessage());
    }
}

// Derived counts
$overdueCount = 0;
foreach ($missingMonths as $mm) {
    if ($mm['days_late'] > 0) $overdueCount++;
}
$totalMonthsExpected = $reportsCount + count($missingMonths);

// ── Badge & all-time stats (non-admin) ──────────────────────────────────
$dbBadges      = [];
$allTimeCopies = 0;
$allTimeSouls  = 0;
$allTimePartners = 0;
$allTimeChurches = 0;
$allTimeTotal  = 0;

if (!isset($user['role']) || $user['role'] === ROLE_RZM || $user['role'] === 'admin-manager') {
    try {
        $atsStmt = $conn->prepare("
            SELECT COUNT(*) as total,
                   COALESCE(SUM(total_copies),0)  as copies,
                   COALESCE(SUM(souls_won),0)     as souls,
                   COALESCE(SUM(new_partners),0)  as partners,
                   COALESCE(SUM(new_churches),0)  as churches
            FROM reports WHERE user_id = ?
        ");
        $atsStmt->execute([$_SESSION['user_id']]);
        $ats = $atsStmt->fetch();
        $allTimeTotal    = (int)($ats['total']    ?? 0);
        $allTimeCopies   = (int)($ats['copies']   ?? 0);
        $allTimeSouls    = (int)($ats['souls']    ?? 0);
        $allTimePartners = (int)($ats['partners'] ?? 0);
        $allTimeChurches = (int)($ats['churches'] ?? 0);

        if ($allTimeTotal    >= 1)  $dbBadges[] = ['icon'=>'fa-star',         'color'=>'#f59e0b','label'=>'First Report',    'tip'=>'Submitted your first report'];
        if ($allTimeTotal    >= 6)  $dbBadges[] = ['icon'=>'fa-medal',        'color'=>'#0ea5e9','label'=>'Half Year',       'tip'=>'6 months of reporting'];
        if ($allTimeTotal    >= 12) $dbBadges[] = ['icon'=>'fa-trophy',       'color'=>'#f59e0b','label'=>'Full Year',       'tip'=>'12 months of reporting'];
        if ($consistencyStreak >= 3)  $dbBadges[] = ['icon'=>'fa-fire',       'color'=>'#ef4444','label'=>'On Fire',         'tip'=>'3-month streak'];
        if ($consistencyStreak >= 6)  $dbBadges[] = ['icon'=>'fa-fire-alt',   'color'=>'#dc2626','label'=>'Unstoppable',     'tip'=>'6-month streak'];
        if ($consistencyStreak >= 12) $dbBadges[] = ['icon'=>'fa-crown',      'color'=>'#7c3aed','label'=>'Legendary',       'tip'=>'12-month streak'];
        if ($allTimeSouls    >= 100) $dbBadges[] = ['icon'=>'fa-heart',       'color'=>'#ec4899','label'=>'Soul Winner',     'tip'=>'100+ souls won'];
        if ($allTimeChurches >= 5)   $dbBadges[] = ['icon'=>'fa-church',      'color'=>'#6366f1','label'=>'Church Planter',  'tip'=>'5+ new churches'];
        if ($allTimePartners >= 50)  $dbBadges[] = ['icon'=>'fa-handshake',   'color'=>'#0d9488','label'=>'Partner Builder', 'tip'=>'50+ new partners'];
    } catch (Exception $e) {
        error_log('Dashboard badges error: ' . $e->getMessage());
    }
}

// ── Role-scoped overview data (read-only) ──────────────────────────────
$isAdminRole   = ($user['role'] ?? '') === 'admin';
$isManagerRole = ($user['role'] ?? '') === 'admin-manager';
$isRzmRole     = !$isAdminRole && !$isManagerRole;
$managerRegion = $isManagerRole ? (string)($user['region'] ?? '') : '';
$totalUsers       = 0;
$pendingApprovals = 0;
$rzmTotal         = 0;
$rzmMissingMonth  = 0;

// Admin managers oversee a region: swap their personal totals for region-scoped ones,
// using the same u.region scoping as pages/reports/list.php.
if ($isManagerRole) {
    try {
        $stmt = $conn->prepare("
            SELECT COUNT(*) AS cnt,
                   COALESCE(SUM(r.total_copies), 0)       AS total_copies,
                   COALESCE(SUM(r.total_distribution), 0) AS total_distributions,
                   COALESCE(SUM(r.souls_won), 0)          AS total_souls,
                   COALESCE(SUM(r.new_partners), 0)       AS total_new_partners,
                   COALESCE(SUM(r.rhapsody_cells), 0)     AS total_rhapsody_cells,
                   COALESCE(SUM(r.new_churches), 0)       AS total_new_churches,
                   COALESCE(SUM(CASE WHEN MONTH(r.report_month) = MONTH(CURRENT_DATE()) THEN r.total_copies ELSE 0 END), 0) AS month_copies
            FROM reports r JOIN users u ON r.user_id = u.id
            WHERE u.region = ? AND YEAR(r.report_month) = ?
        ");
        $stmt->execute([$managerRegion, $currentYear]);
        $rs = $stmt->fetch() ?: [];
        $reportsCount       = (int)($rs['cnt'] ?? 0);
        $totalCopies        = (int)($rs['total_copies'] ?? 0);
        $totalDistributions = (int)($rs['total_distributions'] ?? 0);
        $totalSoulsWon      = (int)($rs['total_souls'] ?? 0);
        $totalNewPartners   = (int)($rs['total_new_partners'] ?? 0);
        $totalRhapsodyCells = (int)($rs['total_rhapsody_cells'] ?? 0);
        $totalNewChurches   = (int)($rs['total_new_churches'] ?? 0);
        $monthlyTotal       = (int)($rs['month_copies'] ?? 0);

        $stmt = $conn->prepare("
            SELECT r.*, COALESCE(u.name, 'Unknown') AS name, u.zone
            FROM reports r JOIN users u ON r.user_id = u.id
            WHERE u.region = ? AND YEAR(r.report_month) = ?
            ORDER BY r.created_at DESC LIMIT 5
        ");
        $stmt->execute([$managerRegion, $currentYear]);
        $recentReports = $stmt->fetchAll() ?: [];
    } catch (PDOException $e) {
        error_log('Dashboard region stats error: ' . $e->getMessage());
    }
}

// Who still owes a report this month (all regions for admin, own region for admin manager)
if (!$isRzmRole) {
    try {
        $regionSql = $isManagerRole ? ' AND u.region = ?' : '';
        $params    = $isManagerRole ? [$managerRegion] : [];
        $stmt = $conn->prepare("
            SELECT COUNT(*) AS total,
                   SUM(CASE WHEN NOT EXISTS (
                         SELECT 1 FROM reports r WHERE r.user_id = u.id
                           AND YEAR(r.report_month) = YEAR(CURRENT_DATE())
                           AND MONTH(r.report_month) = MONTH(CURRENT_DATE())
                       ) THEN 1 ELSE 0 END) AS missing
            FROM users u
            WHERE u.role = ? AND u.active = 1" . $regionSql);
        $stmt->execute(array_merge([ROLE_RZM], $params));
        $rc = $stmt->fetch() ?: [];
        $rzmTotal        = (int)($rc['total'] ?? 0);
        $rzmMissingMonth = (int)($rc['missing'] ?? 0);
    } catch (PDOException $e) {
        error_log('Dashboard RZM coverage error: ' . $e->getMessage());
    }
}

if ($isAdminRole) {
    try {
        $uc = $conn->query("SELECT COUNT(*) AS total, SUM(CASE WHEN active = 0 THEN 1 ELSE 0 END) AS pending FROM users")->fetch() ?: [];
        $totalUsers       = (int)($uc['total'] ?? 0);
        $pendingApprovals = (int)($uc['pending'] ?? 0);
    } catch (PDOException $e) {
        error_log('Dashboard user counts error: ' . $e->getMessage());
    }
}

$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$plural = fn(int $n, string $word) => $n . ' ' . $word . ($n === 1 ? '' : 's');
$monthLabel = date('F Y');
$pastOverdue = array_values(array_filter($missingMonths, fn($m) => !$m['is_current'] && $m['days_late'] > 0));

// Include header
require_once 'layouts/header.php';
?>

<style>
/* Dashboard: page-local layout only; everything else comes from the shared components. */
.db-split { display: grid; gap: var(--spacing-6); grid-template-columns: minmax(0, 1fr); align-items: start; }
@media (min-width: 960px) { .db-split { grid-template-columns: minmax(0, 1fr) 20rem; } }
.db-kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); }
@media (min-width: 900px) { .db-kpis { grid-template-columns: repeat(4, minmax(0, 1fr)); } }
@media (max-width: 599px) { .db-kpis .stat { padding: var(--spacing-4); } .db-kpis .stat-value { font-size: var(--text-lg); } }
@media (max-width: 959px) { .db-split > aside { order: -1; } }
.db-recent td:first-child { white-space: nowrap; }
.db-task { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: var(--spacing-4); }
.db-task-title { font-size: var(--text-lg); font-weight: 500; color: var(--ink); margin: var(--spacing-2) 0 var(--spacing-1); }
.db-task-text { color: var(--slate); font-size: var(--text-sm); }
.db-task.is-overdue .db-task-title { color: var(--danger-700); }
.db-rows { list-style: none; margin: 0; padding: 0; }
.db-rows li { display: flex; align-items: center; justify-content: space-between; gap: var(--spacing-3); padding: var(--spacing-3) 0; border-top: 1px solid var(--hairline); font-size: var(--text-sm); }
.db-rows li:first-child { border-top: 0; }
.db-rows .db-row-label { display: flex; align-items: center; gap: var(--spacing-2); color: var(--ink); }
.db-rows li > a, .db-rows li > span:last-child { flex-shrink: 0; white-space: nowrap; }
.db-rows .db-row-label i { color: var(--neutral-600); width: 1rem; text-align: center; }
.db-badges { display: flex; flex-wrap: wrap; gap: var(--spacing-2); }
.db-badges .badge i { color: var(--neutral-600); }
.sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
.db-streak-value { font-size: var(--text-xl); font-weight: 500; line-height: 1; font-variant-numeric: tabular-nums; color: var(--ink); }
</style>

<!-- ── Page head ── -->
<div class="page-head">
    <div>
        <div class="page-eyebrow">
            <?php if ($isAdminRole): ?>Admin &middot; All regions
            <?php elseif ($isManagerRole): ?>Admin Manager &middot; <?php echo $h($managerRegion ?: 'No region assigned'); ?>
            <?php else: ?>RZM &middot; <?php echo $h($user['zone'] ?? 'No zone'); ?><?php if (!empty($user['region'])): ?> &middot; <?php echo $h($user['region']); ?><?php endif; ?>
            <?php endif; ?>
        </div>
        <h1 class="page-title">
            <?php if ($isManagerRole): ?><?php echo $h($managerRegion ?: 'Region'); ?> overview
            <?php else: ?>Welcome back, <?php echo $h($user['name'] ?? $user['username']); ?>
            <?php endif; ?>
        </h1>
        <p class="page-sub"><?php echo date('l, j F Y'); ?></p>
    </div>
    <?php if (!$isRzmRole): ?>
    <div class="page-actions">
        <a href="pages/reports/list.php" class="btn btn-primary"><i class="fas fa-list" aria-hidden="true"></i> <?php echo $isManagerRole ? 'Region reports' : 'All reports'; ?></a>
    </div>
    <?php endif; ?>
</div>

<?php if (isset($user['active']) && !(int)$user['active']): ?>
<div class="alert alert-warning" role="status">
    <i class="fas fa-hourglass-half" aria-hidden="true"></i>
    <div><div class="alert-title">Account pending approval</div>Your account is waiting for admin approval. Some features may be limited until then.</div>
</div>
<?php endif; ?>

<?php if ($isRzmRole): ?>
<!-- ── RZM: this month's task ── -->
<section class="section" aria-labelledby="task-title">
    <div class="panel">
        <div class="panel-body">
            <?php if ($currentMonthSubmitted): ?>
            <div class="db-task">
                <div>
                    <span class="badge badge-success"><?php echo $h($monthLabel); ?> &middot; Submitted</span>
                    <h2 class="db-task-title" id="task-title">Your <?php echo $h(date('F')); ?> report is in</h2>
                    <p class="db-task-text">Nothing else is due this month. The next report is due by the end of <?php echo $h(date('F Y', strtotime('first day of next month'))); ?>.</p>
                </div>
                <?php if ($lastReportId): ?>
                <a href="pages/reports/view.php?id=<?php echo (int)$lastReportId; ?>" class="btn btn-secondary">View report</a>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <div class="db-task <?php echo $currentMonthDaysLate > 0 ? 'is-overdue' : ''; ?>">
                <div>
                    <?php if ($currentMonthDaysLate > 0): ?>
                    <span class="badge badge-danger"><?php echo $h($monthLabel); ?> &middot; <?php echo $plural($currentMonthDaysLate, 'day'); ?> overdue</span>
                    <?php else: ?>
                    <span class="badge badge-warning"><?php echo $h($monthLabel); ?> &middot; Not submitted</span>
                    <?php endif; ?>
                    <h2 class="db-task-title" id="task-title">Submit your <?php echo $h(date('F')); ?> report</h2>
                    <p class="db-task-text">
                        <?php echo $daysLeftInMonth > 0
                            ? $plural($daysLeftInMonth, 'day') . ' left this month.'
                            : 'Today is the last day of the month.'; ?>
                    </p>
                </div>
                <a href="pages/reports/create.php" class="btn btn-primary btn-lg"><i class="fas fa-plus" aria-hidden="true"></i> Submit report</a>
            </div>
            <?php endif; ?>
        </div>
        <?php if (!empty($pastOverdue)): ?>
        <div class="panel-foot" id="missing-months">
            <div class="meta" style="margin-bottom:var(--spacing-2);"><?php echo $plural(count($pastOverdue), 'earlier month'); ?> still missing</div>
            <ul class="db-rows">
                <?php foreach ($pastOverdue as $mm): ?>
                <li>
                    <span class="db-row-label"><?php echo $h($mm['label']); ?> <span class="badge badge-danger no-dot"><?php echo (int)$mm['days_late']; ?>d late</span></span>
                    <a href="pages/reports/create.php?month=<?php echo urlencode($mm['ym']); ?>" class="link">Submit</a>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
    </div>
</section>

<?php if ($overdueCount >= 3): ?>
<div class="alert alert-danger" role="alert">
    <i class="fas fa-exclamation-triangle" aria-hidden="true"></i>
    <div><div class="alert-title"><?php echo $plural($overdueCount, 'report'); ?> overdue</div>Submit the missing months above to bring your record up to date. If something is stopping you, contact your regional manager.</div>
</div>
<?php endif; ?>
<?php endif; ?>

<!-- ── KPIs ── -->
<section class="section" aria-labelledby="kpi-title">
    <div class="section-head">
        <h2 class="section-title" id="kpi-title"><?php echo $isRzmRole ? 'Your year so far' : ($isManagerRole ? 'Region totals' : 'All regions'); ?></h2>
        <span class="section-meta meta"><?php echo $h($currentYear); ?></span>
    </div>
    <div class="stat-grid db-kpis">
        <div class="stat">
            <span class="stat-label">Reports <?php echo $h($currentYear); ?></span>
            <span class="stat-value"><?php echo number_format($reportsCount); ?></span>
            <?php if ($isRzmRole && $totalMonthsExpected): ?><span class="stat-note"><?php echo (int)$reportsCount; ?> of <?php echo $plural((int)$totalMonthsExpected, 'month'); ?> this year</span><?php endif; ?>
        </div>
        <div class="stat"><span class="stat-label">Copies sponsored</span><span class="stat-value"><?php echo number_format($totalCopies); ?></span></div>
        <div class="stat"><span class="stat-label">Copies this month</span><span class="stat-value"><?php echo number_format($monthlyTotal); ?></span></div>
        <div class="stat"><span class="stat-label">Distributions</span><span class="stat-value"><?php echo number_format($totalDistributions); ?></span></div>
        <div class="stat"><span class="stat-label">Souls won</span><span class="stat-value"><?php echo number_format($totalSoulsWon); ?></span></div>
        <div class="stat"><span class="stat-label">New partners</span><span class="stat-value"><?php echo number_format($totalNewPartners); ?></span></div>
        <div class="stat"><span class="stat-label">Rhapsody cells</span><span class="stat-value"><?php echo number_format($totalRhapsodyCells); ?></span></div>
        <div class="stat"><span class="stat-label">New churches</span><span class="stat-value"><?php echo number_format($totalNewChurches); ?></span></div>
    </div>
</section>

<?php
// Recent reports table (shared by every role)
$showSubmitter = !$isRzmRole;
ob_start(); ?>
<div class="section-head">
    <h2 class="section-title" id="recent-title">Recent reports</h2>
    <a href="pages/reports/list.php" class="link section-meta">View all</a>
</div>
<?php if (empty($recentReports)): ?>
<div class="panel">
    <div class="empty-state">
        <div class="empty-state-icon"><i class="fas fa-file-alt" aria-hidden="true"></i></div>
        <div class="empty-state-title">No reports yet this year</div>
        <?php if ($isRzmRole): ?>
        <p class="empty-state-text">Reports you submit will appear here.</p>
        <?php else: ?>
        <p class="empty-state-text">Reports from <?php echo $isManagerRole ? 'your region' : 'RZMs'; ?> will appear here once submitted.</p>
        <?php endif; ?>
    </div>
</div>
<?php else: ?>
<div class="table-wrap">
    <table class="data-table db-recent">
        <thead>
            <tr>
                <th scope="col">Month</th>
                <?php if ($showSubmitter): ?><th scope="col">Submitted by</th><?php endif; ?>
                <th scope="col" class="num">Copies</th>
                <th scope="col" class="num"><span class="sr-only">Action</span></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($recentReports as $report): ?>
            <tr>
                <td><?php echo $h(date('M Y', strtotime($report['report_month'] ?? $report['created_at']))); ?></td>
                <?php if ($showSubmitter): ?>
                <td><?php echo $h($report['name']); ?><?php if (!empty($report['zone'])): ?><div class="muted" style="font-size:var(--text-xs);"><?php echo $h($report['zone']); ?></div><?php endif; ?></td>
                <?php endif; ?>
                <td class="num"><?php echo number_format((int)$report['total_copies']); ?></td>
                <td class="num"><a href="pages/reports/view.php?id=<?php echo (int)$report['id']; ?>" class="link">View</a></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif;
$recentHtml = ob_get_clean(); ?>

<?php if (!$isRzmRole): ?>
<!-- ── Admin / admin manager: recent + needs attention ── -->
<div class="db-split section">
    <section aria-labelledby="recent-title"><?php echo $recentHtml; ?></section>
    <aside aria-labelledby="attention-title">
        <div class="section-head"><h2 class="section-title" id="attention-title">Needs attention</h2></div>
        <div class="panel">
            <div class="panel-body">
                <ul class="db-rows">
                    <?php if ($isAdminRole): ?>
                    <li>
                        <span class="db-row-label"><i class="fas fa-user-clock" aria-hidden="true"></i> Pending approvals</span>
                        <?php if ($pendingApprovals > 0): ?>
                        <a href="admin/users.php?status=0" class="link num"><?php echo number_format($pendingApprovals); ?> &rarr;</a>
                        <?php else: ?><span class="muted num">0</span><?php endif; ?>
                    </li>
                    <?php endif; ?>
                    <li>
                        <span class="db-row-label"><i class="fas fa-calendar-times" aria-hidden="true"></i> RZMs yet to report for <?php echo $h(date('F')); ?></span>
                        <?php if ($rzmMissingMonth > 0): ?>
                        <a href="<?php echo $isAdminRole ? 'admin/zone_compliance.php' : 'pages/reports/list.php'; ?>" class="link num"><?php echo number_format($rzmMissingMonth); ?> &rarr;</a>
                        <?php else: ?><span class="muted num">0</span><?php endif; ?>
                    </li>
                    <?php if ($isAdminRole): ?>
                    <li>
                        <span class="db-row-label"><i class="fas fa-users" aria-hidden="true"></i> Total users</span>
                        <a href="admin/users.php" class="link num"><?php echo number_format($totalUsers); ?></a>
                    </li>
                    <?php else: ?>
                    <li>
                        <span class="db-row-label"><i class="fas fa-user-check" aria-hidden="true"></i> Active RZMs in region</span>
                        <span class="num"><?php echo number_format($rzmTotal); ?></span>
                    </li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </aside>
</div>
<?php else: ?>
<section class="section" aria-labelledby="recent-title"><?php echo $recentHtml; ?></section>
<?php endif; ?>

<?php if ($isRzmRole): ?>
<!-- ── RZM: progress (secondary) ── -->
<section class="section" aria-labelledby="progress-title">
    <div class="section-head">
        <h2 class="section-title" id="progress-title">Progress</h2>
        <a href="pages/analytics.php" class="link section-meta">Trends &amp; badges</a>
    </div>
    <div class="grid-2">
        <div class="panel">
            <div class="panel-body">
                <div class="meta">Submission streak</div>
                <div class="db-streak-value" style="margin:var(--spacing-3) 0 var(--spacing-2);"><?php echo $plural($consistencyStreak, 'month'); ?></div>
                <p class="muted" style="font-size:var(--text-sm);">
                    <?php if ($consistencyStreak === 0): ?>Submit every month to build a streak.
                    <?php elseif ($consistencyStreak < 3): ?><?php echo $plural(3 - $consistencyStreak, 'more month'); ?> to the On Fire badge.
                    <?php elseif ($consistencyStreak < 6): ?><?php echo $plural(6 - $consistencyStreak, 'more month'); ?> to Unstoppable.
                    <?php elseif ($consistencyStreak < 12): ?><?php echo $plural(12 - $consistencyStreak, 'more month'); ?> to Legendary.
                    <?php else: ?>Legendary streak. Keep it going.
                    <?php endif; ?>
                </p>
                <?php if ($bestMonthLabel || $allTimeTotal > 0): ?>
                <dl class="kv" style="margin-top:var(--spacing-4);">
                    <?php if ($bestMonthLabel): ?><dt>Best month</dt><dd><?php echo $h($bestMonthLabel); ?> &middot; <?php echo number_format($bestMonthValue); ?></dd><?php endif; ?>
                    <dt>All-time reports</dt><dd><?php echo number_format($allTimeTotal); ?></dd>
                    <dt>All-time copies</dt><dd><?php echo number_format($allTimeCopies); ?></dd>
                </dl>
                <?php endif; ?>
            </div>
        </div>
        <div class="panel">
            <div class="panel-body">
                <div class="meta" style="margin-bottom:var(--spacing-3);">Badges</div>
                <?php if (!empty($dbBadges)): ?>
                <div class="db-badges">
                    <?php foreach ($dbBadges as $badge): ?>
                    <span class="badge no-dot" title="<?php echo $h($badge['tip']); ?>"><i class="fas <?php echo $h($badge['icon']); ?>" aria-hidden="true"></i> <?php echo $h($badge['label']); ?></span>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <p class="muted" style="font-size:var(--text-sm);">Your first badge arrives with your first report.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {

    // ── First-login welcome ──
    <?php if (isset($_SESSION['first_login']) && $_SESSION['first_login']): ?>
    Swal.fire({
        title: 'Welcome to the Reporting Portal!',
        html: '<p class="muted">You can now submit and track your monthly reports. Start by submitting your first report.</p>',
        icon: 'success',
        confirmButtonColor: 'var(--ink)',
        confirmButtonText: 'Get Started'
    });
    <?php unset($_SESSION['first_login']); ?>
    <?php endif; ?>

    <?php if ($user['role'] === ROLE_RZM || !isset($user['role'])): ?>
    // ── Smart reminder popup ──
    (function() {
        var overdueCount  = <?php echo (int)$overdueCount; ?>;
        var daysLate      = <?php echo (int)$currentMonthDaysLate; ?>;
        var daysLeft      = <?php echo (int)$daysLeftInMonth; ?>;
        var submitted     = <?php echo $currentMonthSubmitted ? 'true' : 'false'; ?>;
        var currentMonth  = '<?php echo date('F Y'); ?>';
        var submitUrl     = '<?php echo BASE_URL; ?>/pages/reports/create.php';
        var historyUrl    = '<?php echo BASE_URL; ?>/pages/reports/list.php';

        // Skip popup entirely if all caught up
        if (submitted && overdueCount === 0) return;

        // Throttle: show once per session unless overdue >= 3 (always show escalation)
        var storageKey = 'db_reminder_shown_' + '<?php echo date('Y-m'); ?>';
        if (overdueCount < 3 && sessionStorage.getItem(storageKey)) return;
        sessionStorage.setItem(storageKey, '1');

        setTimeout(function() {
            var icon, title, bodyHtml, confirmText, confirmColor;

            if (overdueCount >= 3) {
                // Escalation level
                icon        = 'error';
                title       = 'Escalation notice';
                confirmText = 'Submit Reports Now';
                confirmColor = 'var(--danger-600)';
                bodyHtml = [
                    '<p style="margin-bottom:var(--spacing-3);">',
                    'You have <strong>' + overdueCount + ' overdue reports</strong>. ',
                    'Submit the missing months to bring your record up to date.',
                    '</p>',
                    '<div class="alert alert-danger" style="text-align:left;">',
                    '<i class="fas fa-exclamation-triangle" style="margin-right:0.4rem;"></i>',
                    '<div>If something is stopping you from reporting, contact your regional manager.</div>',
                    '</div>'
                ].join('');

            } else if (overdueCount > 0 && !submitted) {
                // Multiple missing including current
                icon        = 'warning';
                title       = 'Reports Overdue';
                confirmText = 'Submit Now';
                confirmColor = 'var(--ink)';
                bodyHtml = [
                    '<p style="margin-bottom:var(--spacing-3);">',
                    'You have <strong>' + overdueCount + ' overdue report' + (overdueCount !== 1 ? 's' : '') + '</strong> ',
                    'including <strong>' + currentMonth + '</strong>.',
                    '</p>',
                    '<div class="alert alert-warning" style="text-align:left;">',
                    '<i class="fas fa-clock" style="margin-right:0.35rem;"></i>',
                    daysLate > 0 ? (currentMonth + ' is ' + daysLate + ' day' + (daysLate !== 1 ? 's' : '') + ' overdue.') : (daysLeft + ' day' + (daysLeft !== 1 ? 's' : '') + ' left to submit ' + currentMonth + '.'),
                    '</div>'
                ].join('');

            } else if (overdueCount > 0 && submitted) {
                // Current done but past months missing
                icon        = 'warning';
                title       = 'Past Reports Missing';
                confirmText = 'Catch Up Now';
                confirmColor = 'var(--ink)';
                bodyHtml = [
                    '<p style="margin-bottom:var(--spacing-3);">',
                    'Great — your ' + currentMonth + ' report is in! But you still have ',
                    '<strong>' + overdueCount + ' overdue month' + (overdueCount !== 1 ? 's' : '') + '</strong> from earlier this year.',
                    '</p>',
                    '<div class="alert alert-warning" style="text-align:left;">',
                    '<i class="fas fa-history" style="margin-right:0.35rem;"></i> Catch up now to protect your compliance record.',
                    '</div>'
                ].join('');

            } else {
                // Only current month missing, not yet overdue
                icon = daysLeft <= 5 ? 'warning' : 'info';
                title = daysLeft <= 5 ? 'Last chance: ' + currentMonth : currentMonth + ' Report Due';
                confirmText = 'Submit Now';
                confirmColor = 'var(--ink)';
                bodyHtml = [
                    '<p style="margin-bottom:var(--spacing-3);">',
                    'You haven\'t submitted your report for <strong>' + currentMonth + '</strong> yet.',
                    '</p>',
                    '<div class="alert ' + (daysLeft <= 5 ? 'alert-warning' : 'alert-info') + '" style="text-align:left;">',
                    '<i class="fas fa-calendar" style="margin-right:0.35rem;"></i>',
                    daysLeft === 0 ? 'Today is the last day of the month — submit now!' : (daysLeft + ' day' + (daysLeft !== 1 ? 's' : '') + ' remaining this month.'),
                    '</div>'
                ].join('');
            }

            Swal.fire({
                title: title,
                html: bodyHtml,
                icon: icon,
                showCancelButton: true,
                confirmButtonText: '<i class="fas fa-plus" style="margin-right:0.35rem;"></i>' + confirmText,
                cancelButtonText: 'Remind Me Later',
                confirmButtonColor: confirmColor,
                cancelButtonColor: 'var(--neutral-500)',
                footer: '<a href="' + historyUrl + '" class="link" style="font-size:var(--text-xs);">View all submitted reports</a>'
            }).then(function(result) {
                if (result.isConfirmed) {
                    window.location.href = submitUrl;
                }
            });
        }, 900);
    })();
    <?php endif; ?>

    // ── Toast notifications ──
    <?php if (isset($_SESSION['success'])): ?>
        showToast('<?php echo addslashes($_SESSION['success']); ?>', 'success');
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>
    <?php if (isset($_SESSION['error'])): ?>
        showToast('<?php echo addslashes($_SESSION['error']); ?>', 'error');
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

});
</script>

</main>
</body>
</html>
