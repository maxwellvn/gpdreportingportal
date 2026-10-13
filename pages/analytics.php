<?php
require_once '../config/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/statistics.php';

Auth::requireLogin();

$db   = new Database();
$conn = $db->getConnection();
$auth = new Auth();
$currentUser = $auth->getUser($_SESSION['user_id']);
$_SESSION['user_data'] = $currentUser;
$isAdmin        = Auth::isAdmin();
$isAdminManager = Auth::isAdminManager();
$isPrivileged   = $isAdmin || $isAdminManager;

// Non-privileged users see only their own data
$userId = (int)$_SESSION['user_id'];

// ── Filters ──────────────────────────────────────────────────────────────
$filterYear   = isset($_GET['year'])   ? (int)$_GET['year']   : (int)date('Y');
$filterRegion = isset($_GET['region']) ? trim($_GET['region']) : '';
$filterZone   = isset($_GET['zone'])   ? trim($_GET['zone'])   : '';
// Admin managers only ever see their own region, whatever the query string says
if ($isAdminManager && !$isAdmin) {
    $filterRegion = html_entity_decode((string)Auth::getScopedRegion(), ENT_QUOTES, 'UTF-8') ?: '__no_region__';
}

// Available years
$yearsStmt = $conn->query("SELECT DISTINCT YEAR(report_month) as y FROM reports ORDER BY y DESC");
$availableYears = $yearsStmt->fetchAll(PDO::FETCH_COLUMN);
if (!$availableYears) $availableYears = [(int)date('Y')];

// Available regions / zones for filters
$regionsStmt = $conn->query("SELECT DISTINCT region FROM users WHERE region IS NOT NULL AND region != '' ORDER BY region");
$allRegions  = $regionsStmt->fetchAll(PDO::FETCH_COLUMN);
if ($isAdminManager && !$isAdmin) { $allRegions = [$filterRegion]; }

$zonesQuery = "SELECT DISTINCT zone FROM users WHERE zone IS NOT NULL AND zone != ''";
$zonesParams = [];
if ($filterRegion) { $zonesQuery .= " AND region = :region"; $zonesParams[':region'] = $filterRegion; }
$zonesQuery .= " ORDER BY zone";
$zonesStmt = $conn->prepare($zonesQuery);
$zonesStmt->execute($zonesParams);
$allZones = $zonesStmt->fetchAll(PDO::FETCH_COLUMN);

// ── Base WHERE builder ────────────────────────────────────────────────────
function anBuildWhere($filterYear, $filterRegion, $filterZone, $isPrivileged, $userId, &$params, $alias = 'r', $uAlias = 'u') {
    $w = "WHERE YEAR({$alias}.report_month) = :year";
    $params[':year'] = $filterYear;
    if (!$isPrivileged) {
        $w .= " AND {$alias}.user_id = :uid";
        $params[':uid'] = $userId;
    } else {
        if ($filterRegion) { $w .= " AND {$uAlias}.region = :region"; $params[':region'] = $filterRegion; }
        if ($filterZone)   { $w .= " AND {$uAlias}.zone = :zone";     $params[':zone']   = $filterZone; }
    }
    return $w;
}

// ── 1. Monthly trend (12 months rolling for charts) ──────────────────────
$trendParams = [];
$trendWhere  = "WHERE r.report_month >= DATE_SUB(LAST_DAY(CURDATE()), INTERVAL 11 MONTH)";
if (!$isPrivileged) {
    $trendWhere .= " AND r.user_id = :uid"; $trendParams[':uid'] = $userId;
} else {
    if ($filterRegion) { $trendWhere .= " AND u.region = :region"; $trendParams[':region'] = $filterRegion; }
    if ($filterZone)   { $trendWhere .= " AND u.zone = :zone";     $trendParams[':zone']   = $filterZone; }
}
$trendStmt = $conn->prepare("
    SELECT DATE_FORMAT(r.report_month,'%Y-%m') as ym,
           DATE_FORMAT(r.report_month,'%b %Y') as label,
           SUM(r.total_copies)      as copies,
           SUM(r.souls_won)         as souls,
           SUM(r.new_partners)      as partners,
           SUM(r.new_churches)      as churches,
           SUM(r.total_distribution) as distributed,
           COUNT(DISTINCT r.id)     as submissions
    FROM reports r
    LEFT JOIN users u ON r.user_id = u.id
    $trendWhere
    GROUP BY DATE_FORMAT(r.report_month,'%Y-%m')
    ORDER BY r.report_month ASC
");
$trendStmt->execute($trendParams);
$trendRaw = $trendStmt->fetchAll(PDO::FETCH_ASSOC);

// Fill in all 12 months (including zeros for missing)
$trendMonths = $trendLabels = $trendCopies = $trendSouls = $trendPartners = $trendChurches = $trendSubmissions = [];
$dt = new DateTime('first day of -11 month');
for ($i = 0; $i < 12; $i++) {
    $ym    = $dt->format('Y-m');
    $label = $dt->format('M Y');
    $found = null;
    foreach ($trendRaw as $row) { if ($row['ym'] === $ym) { $found = $row; break; } }
    $trendMonths[]      = $ym;
    $trendLabels[]      = $label;
    $trendCopies[]      = $found ? (int)$found['copies']      : 0;
    $trendSouls[]       = $found ? (int)$found['souls']       : 0;
    $trendPartners[]    = $found ? (int)$found['partners']    : 0;
    $trendChurches[]    = $found ? (int)$found['churches']    : 0;
    $trendSubmissions[] = $found ? (int)$found['submissions'] : 0;
    $dt->modify('+1 month');
}

// ── 2. Region comparison (admin only) ────────────────────────────────────
$regionRows = [];
if ($isPrivileged) {
    $rcParams = [':year' => $filterYear];
    $rcWhere = "WHERE YEAR(r.report_month) = :year AND u.region IS NOT NULL AND u.region != ''";
    if ($filterRegion) { $rcWhere .= " AND u.region = :region"; $rcParams[':region'] = $filterRegion; }
    $rcStmt = $conn->prepare("
        SELECT u.region,
               COUNT(DISTINCT r.id)     as reports,
               COUNT(DISTINCT r.user_id) as users,
               SUM(r.total_copies)       as copies,
               SUM(r.souls_won)          as souls,
               SUM(r.new_partners)       as partners,
               SUM(r.new_churches)       as churches
        FROM reports r
        LEFT JOIN users u ON r.user_id = u.id
        $rcWhere
        GROUP BY u.region
        ORDER BY copies DESC
    ");
    $rcStmt->execute($rcParams);
    $regionRows = $rcStmt->fetchAll(PDO::FETCH_ASSOC);
}

// ── 3. Compliance leaderboard (zones by submission rate) ─────────────────
$leaderboard = [];
if ($isPrivileged) {
    $zonesData = $REGIONS_ZONES;
    $lbUserWhere = "u.zone IS NOT NULL AND u.zone != ''";
    $lbExec      = [':year' => $filterYear];
    if ($filterRegion) {
        $lbUserWhere .= " AND u.region = :region";
        $lbExec[':region'] = $filterRegion;
    }
    $lbStmt = $conn->prepare("
        SELECT u.zone, u.region,
               COUNT(DISTINCT DATE_FORMAT(r.report_month,'%Y-%m')) as months_submitted,
               COALESCE(SUM(r.total_copies), 0) as copies,
               COALESCE(SUM(r.souls_won), 0)    as souls,
               MAX(r.created_at)                as last_submission
        FROM users u
        LEFT JOIN reports r ON r.user_id = u.id AND YEAR(r.report_month) = :year
        WHERE $lbUserWhere
        GROUP BY u.zone, u.region
        ORDER BY months_submitted DESC, copies DESC
    ");
    $lbStmt->execute($lbExec);
    $lbRaw = $lbStmt->fetchAll(PDO::FETCH_ASSOC);

    // Expected months = months elapsed this year (or all 12 if prior year)
    $expectedMonths = ($filterYear == (int)date('Y')) ? (int)date('n') : 12;
    foreach ($lbRaw as $row) {
        $rate = $expectedMonths > 0 ? min(100, round(($row['months_submitted'] / $expectedMonths) * 100)) : 0;
        $leaderboard[] = array_merge($row, ['rate' => $rate, 'expected' => $expectedMonths]);
    }
}

// ── 4. Submission heatmap — monthly grid for year (non-privileged = own; admin = all) ──
$hmParams = [':year' => $filterYear];
$hmWhere  = "WHERE YEAR(r.report_month) = :year";
if (!$isPrivileged) {
    $hmWhere .= " AND r.user_id = :uid"; $hmParams[':uid'] = $userId;
} else {
    if ($filterRegion) { $hmWhere .= " AND u.region = :region"; $hmParams[':region'] = $filterRegion; }
    if ($filterZone)   { $hmWhere .= " AND u.zone = :zone";     $hmParams[':zone']   = $filterZone; }
}
$hmStmt = $conn->prepare("
    SELECT DATE_FORMAT(r.report_month,'%m') as month_num,
           COUNT(DISTINCT r.id)             as count
    FROM reports r
    LEFT JOIN users u ON r.user_id = u.id
    $hmWhere
    GROUP BY DATE_FORMAT(r.report_month,'%m')
");
$hmStmt->execute($hmParams);
$hmRaw = $hmStmt->fetchAll(PDO::FETCH_KEY_PAIR);

// ── 5. User personal timeline (non-admin) ────────────────────────────────
$timelineRows = [];
if (!$isPrivileged) {
    $tlStmt = $conn->prepare("
        SELECT DATE_FORMAT(report_month,'%Y-%m') as ym,
               DATE_FORMAT(report_month,'%b %Y') as label,
               total_copies, souls_won, new_partners, new_churches, id
        FROM reports
        WHERE user_id = :uid
        ORDER BY report_month ASC
    ");
    $tlStmt->execute([':uid' => $userId]);
    $timelineRows = $tlStmt->fetchAll(PDO::FETCH_ASSOC);
}

// ── 6. Streak & badge calculation (non-admin) ────────────────────────────
$streakCount   = 0;
$totalReports  = 0;
$badges        = [];
$allTimeStats  = [];
if (!$isPrivileged) {
    // All-time stats
    $atsStmt = $conn->prepare("
        SELECT COUNT(*) as total,
               COALESCE(SUM(total_copies),0) as copies,
               COALESCE(SUM(souls_won),0) as souls,
               COALESCE(SUM(new_partners),0) as partners,
               COALESCE(SUM(new_churches),0) as churches,
               MIN(report_month) as first_report
        FROM reports WHERE user_id = :uid
    ");
    $atsStmt->execute([':uid' => $userId]);
    $allTimeStats = $atsStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $totalReports = (int)($allTimeStats['total'] ?? 0);

    // Streak (consecutive months going back from today)
    $check = new DateTime('first day of this month');
    // Check if current month submitted
    $cmStmt = $conn->prepare("SELECT id FROM reports WHERE user_id=:uid AND DATE_FORMAT(report_month,'%Y-%m')=:ym");
    $cmStmt->execute([':uid'=>$userId,':ym'=>date('Y-m')]);
    if (!$cmStmt->fetch()) $check->modify('first day of last month');
    for ($s = 0; $s < 60; $s++) {
        $ym = $check->format('Y-m');
        $chStmt = $conn->prepare("SELECT id FROM reports WHERE user_id=:uid AND DATE_FORMAT(report_month,'%Y-%m')=:ym");
        $chStmt->execute([':uid'=>$userId,':ym'=>$ym]);
        if ($chStmt->fetch()) { $streakCount++; $check->modify('first day of previous month'); }
        else break;
    }

    // Badges
    $monthsSinceFirst = 0;
    if (!empty($allTimeStats['first_report'])) {
        $firstDt = new DateTime($allTimeStats['first_report']);
        $nowDt   = new DateTime();
        $monthsSinceFirst = ($nowDt->format('Y') - $firstDt->format('Y')) * 12
                          + $nowDt->format('n') - $firstDt->format('n') + 1;
    }

    if ($totalReports >= 1)  $badges[] = ['icon'=>'fa-star',        'label'=>'First Report',       'desc'=>'You submitted your first report'];
    if ($totalReports >= 3)  $badges[] = ['icon'=>'fa-seedling',     'label'=>'Getting Started',    'desc'=>'3 reports submitted'];
    if ($totalReports >= 6)  $badges[] = ['icon'=>'fa-medal',        'label'=>'Half Year',          'desc'=>'6 months of reporting'];
    if ($totalReports >= 12) $badges[] = ['icon'=>'fa-trophy',       'label'=>'Full Year',          'desc'=>'12 months of reporting'];
    if ($streakCount  >= 3)  $badges[] = ['icon'=>'fa-fire',         'label'=>'On Fire',            'desc'=>'3-month streak'];
    if ($streakCount  >= 6)  $badges[] = ['icon'=>'fa-fire-alt',     'label'=>'Unstoppable',        'desc'=>'6-month streak'];
    if ($streakCount  >= 12) $badges[] = ['icon'=>'fa-crown',        'label'=>'Legendary',         'desc'=>'12-month streak'];
    if ((int)($allTimeStats['souls'] ?? 0) >= 100)     $badges[] = ['icon'=>'fa-heart',        'label'=>'Soul Winner',        'desc'=>'100+ souls won'];
    if ((int)($allTimeStats['souls'] ?? 0) >= 500)     $badges[] = ['icon'=>'fa-hands-helping','label'=>'Soul Champion',      'desc'=>'500+ souls won'];
    if ((int)($allTimeStats['churches'] ?? 0) >= 5)    $badges[] = ['icon'=>'fa-church',       'label'=>'Church Planter',     'desc'=>'5+ new churches'];
    if ((int)($allTimeStats['partners'] ?? 0) >= 50)   $badges[] = ['icon'=>'fa-handshake',    'label'=>'Partner Builder',    'desc'=>'50+ new partners'];
}

// ── 7. Zone performance comparison (admin) ───────────────────────────────
$zoneCompare = [];
if ($isPrivileged && $filterRegion) {
    $zcParams = [':year' => $filterYear, ':region' => $filterRegion];
    $zcStmt = $conn->prepare("
        SELECT u.zone,
               COUNT(DISTINCT DATE_FORMAT(r.report_month,'%Y-%m')) as months_submitted,
               COALESCE(SUM(r.total_copies),0)    as copies,
               COALESCE(SUM(r.souls_won),0)        as souls,
               COALESCE(SUM(r.new_churches),0)     as churches,
               COALESCE(SUM(r.new_partners),0)     as partners
        FROM users u
        LEFT JOIN reports r ON r.user_id = u.id AND YEAR(r.report_month) = :year
        WHERE u.region = :region AND u.zone IS NOT NULL
        GROUP BY u.zone
        ORDER BY copies DESC
    ");
    $zcStmt->execute($zcParams);
    $zoneCompare = $zcStmt->fetchAll(PDO::FETCH_ASSOC);
}

// ── 8. Top individual performers (admin) ─────────────────────────────────
$topUsers = [];
if ($isPrivileged) {
    $tuParams = [':year' => $filterYear];
    $tuWhere  = "WHERE YEAR(r.report_month) = :year";
    if ($filterRegion) { $tuWhere .= " AND u.region = :region"; $tuParams[':region'] = $filterRegion; }
    if ($filterZone)   { $tuWhere .= " AND u.zone = :zone";     $tuParams[':zone']   = $filterZone; }
    $tuStmt = $conn->prepare("
        SELECT u.name, u.zone, u.region,
               COUNT(DISTINCT r.id)    as reports,
               SUM(r.total_copies)     as copies,
               SUM(r.souls_won)        as souls
        FROM reports r
        LEFT JOIN users u ON r.user_id = u.id
        $tuWhere
        GROUP BY r.user_id, u.name, u.zone, u.region
        ORDER BY copies DESC
        LIMIT 10
    ");
    $tuStmt->execute($tuParams);
    $topUsers = $tuStmt->fetchAll(PDO::FETCH_ASSOC);
}

require_once '../layouts/header.php';
?>

<?php
// Compute aggregate stats for the stat strip
$aggParams = [':year' => $filterYear];
$aggWhere  = "WHERE YEAR(r.report_month) = :year";
if (!$isPrivileged) { $aggWhere .= " AND r.user_id = :uid"; $aggParams[':uid'] = $userId; }
else {
    if ($filterRegion) { $aggWhere .= " AND u.region = :region"; $aggParams[':region'] = $filterRegion; }
    if ($filterZone)   { $aggWhere .= " AND u.zone = :zone";     $aggParams[':zone']   = $filterZone; }
}
$aggStmt = $conn->prepare("
    SELECT COALESCE(SUM(r.total_copies),0)       as copies,
           COALESCE(SUM(r.souls_won),0)           as souls,
           COALESCE(SUM(r.new_partners),0)        as partners,
           COALESCE(SUM(r.new_churches),0)        as churches,
           COUNT(DISTINCT r.id)                   as reports,
           COUNT(DISTINCT r.user_id)              as users
    FROM reports r LEFT JOIN users u ON r.user_id = u.id $aggWhere
");
$aggStmt->execute($aggParams);
$agg = $aggStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$monthNames = ['01'=>'Jan','02'=>'Feb','03'=>'Mar','04'=>'Apr','05'=>'May','06'=>'Jun',
               '07'=>'Jul','08'=>'Aug','09'=>'Sep','10'=>'Oct','11'=>'Nov','12'=>'Dec'];
?>

<style>
/* Page-local analytics pieces (tokens only) */
.an-chart { position: relative; height: 280px; }
.an-chart-sm { position: relative; height: 220px; }
.an-panel-head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: var(--spacing-3); }
.an-panel-head .tabs { margin-bottom: 0; max-width: 100%; overflow-x: auto; }
.an-kpis { grid-template-columns: repeat(auto-fit, minmax(9.5rem, 1fr)); margin-bottom: var(--spacing-6); }
.an-stack .grid-2 { align-items: start; }
.an-page-tables .data-table td, .an-stack .data-table td { white-space: nowrap; }
@media (max-width: 500px) { .an-kpis .stat-value { font-size: var(--text-lg); } }
.an-stack { display: grid; gap: var(--spacing-6); margin-bottom: var(--spacing-6); }

/* Heatmap: one sequential ramp from signal blue */
.an-heatmap { display: grid; grid-template-columns: repeat(6, 1fr); gap: var(--spacing-2); }
@media (max-width: 500px) { .an-heatmap { grid-template-columns: repeat(4, 1fr); } }
.an-hm-cell { border-radius: var(--radius-md); padding: var(--spacing-3) var(--spacing-2); text-align: center; background: var(--neutral-100); color: var(--neutral-800); }
.an-hm-cell.hm-1 { background: var(--primary-100); }
.an-hm-cell.hm-2 { background: var(--primary-200); }
.an-hm-cell.hm-3 { background: var(--primary-300); color: var(--ink); }
.an-hm-cell.hm-4 { background: var(--primary-700); color: var(--neutral-50); }
.an-hm-cell .meta { color: inherit; opacity: 0.8; }
.an-hm-val { font-size: var(--text-lg); font-weight: 500; font-variant-numeric: tabular-nums; }
.an-legend { display: flex; align-items: center; justify-content: flex-end; gap: var(--spacing-1); margin-top: var(--spacing-3); }
.an-legend i { width: 0.75rem; height: 0.75rem; border-radius: 3px; background: var(--neutral-100); display: inline-block; }
.an-legend i.hm-1 { background: var(--primary-100); } .an-legend i.hm-2 { background: var(--primary-200); }
.an-legend i.hm-3 { background: var(--primary-300); } .an-legend i.hm-4 { background: var(--primary-700); }

/* Compliance rate bar */
.an-rate { display: flex; align-items: center; gap: var(--spacing-2); min-width: 8rem; }
.an-rate-track { flex: 1; height: 6px; background: var(--neutral-100); border-radius: var(--radius-full); overflow: hidden; }
.an-rate-fill { height: 100%; border-radius: inherit; background: var(--success-600); }
.an-rate.mid .an-rate-fill { background: var(--warning-500); }
.an-rate.low .an-rate-fill { background: var(--ember); }
.an-rate-val { font-size: var(--text-xs); font-variant-numeric: tabular-nums; color: var(--neutral-700); min-width: 2.5rem; text-align: right; }

/* Timeline list */
.an-tl { list-style: none; margin: 0; padding: 0; max-height: 260px; overflow-y: auto; border-top: 1px solid var(--neutral-200); }
.an-tl li { display: flex; align-items: baseline; gap: var(--spacing-3); padding: var(--spacing-3) 0; border-bottom: 1px solid var(--neutral-200); }
.an-tl li:last-child { border-bottom: 0; }
.an-tl-main { flex: 1; min-width: 0; }
.an-tl-month { font-weight: 500; color: var(--neutral-900); }
.an-tl-stats { font-size: var(--text-xs); color: var(--neutral-600); display: flex; flex-wrap: wrap; gap: var(--spacing-3); font-variant-numeric: tabular-nums; }

/* Quieter achievements block */
.an-achieve { display: grid; gap: var(--spacing-4); }
.an-streak { display: flex; align-items: baseline; flex-wrap: wrap; gap: var(--spacing-2) var(--spacing-4); }
.an-streak-value { font-size: var(--text-lg); font-weight: 500; font-variant-numeric: tabular-nums; color: var(--neutral-900); }
.an-badges { display: grid; grid-template-columns: repeat(auto-fill, minmax(13rem, 1fr)); gap: var(--spacing-2); list-style: none; margin: 0; padding: 0; }
.an-badge { display: flex; align-items: center; gap: var(--spacing-3); padding: var(--spacing-2) var(--spacing-3); border: 1px solid var(--neutral-200); border-radius: var(--radius-md); background: var(--surface, #fff); font-size: var(--text-sm); }
.an-badge i { width: 1rem; text-align: center; color: var(--neutral-600); font-size: var(--text-sm); }
.an-badge small { display: block; font-size: var(--text-xs); color: var(--neutral-500); }
.an-badge.is-locked { background: transparent; color: var(--neutral-500); }
.an-badge.is-locked i { color: var(--neutral-400); }
.an-badge.is-earned .an-badge-state { color: var(--success-700); }
.an-badge-state { margin-left: auto; }
.sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0,0,0,0); white-space: nowrap; border: 0; }
</style>

<?php
$hasRegionFilter = $isPrivileged && $filterRegion !== '';
$exportQuery = 'year=' . (int)$filterYear . ($filterRegion ? '&region=' . urlencode($filterRegion) : '');
$periodLabel = (string)(int)$filterYear . ($isPrivileged && $filterRegion ? ' · ' . $filterRegion : '') . ($isPrivileged && $filterZone ? ' · ' . $filterZone : '');
?>

<nav class="breadcrumb" aria-label="Breadcrumb">
    <a href="<?php echo BASE_URL; ?>/dashboard.php">Dashboard</a>
    <span class="sep">/</span>
    <span>Analytics</span>
</nav>

<header class="page-head">
    <div>
        <p class="page-eyebrow">Analytics &middot; <?php echo htmlspecialchars($periodLabel); ?></p>
        <h1 class="page-title"><?php echo $isPrivileged ? 'Performance analytics' : 'Your performance'; ?></h1>
        <p class="page-sub"><?php echo $isPrivileged ? 'Trends, regional comparison and submission compliance.' : 'Your monthly trends and submission history.'; ?></p>
    </div>
    <?php if ($isPrivileged): ?>
    <div class="page-actions">
        <a href="<?php echo BASE_URL; ?>/admin/export_summary_report.php?<?php echo htmlspecialchars($exportQuery); ?>" class="btn btn-secondary"><i class="fas fa-file-csv" aria-hidden="true"></i> Export CSV</a>
        <a href="<?php echo BASE_URL; ?>/admin/export_compliance_report.php?<?php echo htmlspecialchars($exportQuery); ?>" class="btn btn-secondary"><i class="fas fa-file-alt" aria-hidden="true"></i> Compliance report</a>
    </div>
    <?php endif; ?>
</header>

<!-- Period selector -->
<form method="GET" class="toolbar" aria-label="Analytics period">
    <div class="field">
        <label for="an-year" class="field-label">Year</label>
        <select name="year" id="an-year" class="input">
            <?php foreach ($availableYears as $y): ?>
            <option value="<?php echo (int)$y; ?>" <?php echo $y==$filterYear?'selected':''; ?>><?php echo (int)$y; ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php if ($isPrivileged): ?>
    <div class="field">
        <label for="an-region" class="field-label">Region</label>
        <select name="region" id="an-region" class="input">
            <?php if ($isAdmin): ?><option value="">All regions</option><?php endif; ?>
            <?php foreach ($allRegions as $r): ?>
            <option value="<?php echo htmlspecialchars($r); ?>" <?php echo $r===$filterRegion?'selected':''; ?>><?php echo htmlspecialchars($r); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field">
        <label for="an-zone" class="field-label">Zone</label>
        <select name="zone" id="an-zone" class="input">
            <option value="">All zones</option>
            <?php foreach ($allZones as $z): ?>
            <option value="<?php echo htmlspecialchars($z); ?>" <?php echo $z===$filterZone?'selected':''; ?>><?php echo htmlspecialchars($z); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>
    <div class="toolbar-actions">
        <button type="submit" class="btn btn-primary">Apply</button>
        <?php if ($filterYear != date('Y') || $filterRegion || $filterZone): ?>
        <a href="analytics.php" class="btn btn-secondary">Reset</a>
        <?php endif; ?>
    </div>
</form>

<!-- KPIs -->
<div class="stat-grid an-kpis">
    <div class="stat">
        <span class="stat-label">Submissions</span>
        <span class="stat-value"><?php echo number_format($agg['reports'] ?? 0); ?></span>
        <span class="stat-note"><?php echo htmlspecialchars($periodLabel); ?></span>
    </div>
    <div class="stat">
        <span class="stat-label">Copies sponsored</span>
        <span class="stat-value"><?php echo number_format($agg['copies'] ?? 0); ?></span>
        <span class="stat-note">Total for period</span>
    </div>
    <div class="stat">
        <span class="stat-label">Souls won</span>
        <span class="stat-value"><?php echo number_format($agg['souls'] ?? 0); ?></span>
        <span class="stat-note">Total for period</span>
    </div>
    <div class="stat">
        <span class="stat-label"><?php echo $isPrivileged ? 'Active RZMs' : 'New partners'; ?></span>
        <span class="stat-value"><?php echo $isPrivileged ? number_format($agg['users'] ?? 0) : number_format($agg['partners'] ?? 0); ?></span>
        <span class="stat-note"><?php echo $isPrivileged ? 'Submitted at least once' : 'Total for period'; ?></span>
    </div>
</div>

<div class="an-stack">

<!-- Main trend -->
<section class="panel" aria-labelledby="an-trend-title">
    <div class="panel-head an-panel-head">
        <div>
            <h2 id="an-trend-title" class="section-title">Monthly trend</h2>
            <p class="meta">Last 12 months</p>
        </div>
        <div class="tabs" role="group" aria-label="Metric">
            <button type="button" onclick="switchChart('copies')" class="an-chart-btn active" data-metric="copies" aria-pressed="true">Copies</button>
            <button type="button" onclick="switchChart('souls')" class="an-chart-btn" data-metric="souls" aria-pressed="false">Souls</button>
            <button type="button" onclick="switchChart('partners')" class="an-chart-btn" data-metric="partners" aria-pressed="false">Partners</button>
            <button type="button" onclick="switchChart('churches')" class="an-chart-btn" data-metric="churches" aria-pressed="false">Churches</button>
        </div>
    </div>
    <div class="panel-body">
        <div class="an-chart"><canvas id="trendChart" role="img" aria-label="Monthly trend chart for the last 12 months"></canvas></div>
    </div>
</section>

<div class="grid-2">
    <!-- Heatmap -->
    <section class="panel" aria-labelledby="an-hm-title">
        <div class="panel-head">
            <h2 id="an-hm-title" class="section-title">Submissions by month</h2>
            <p class="meta"><?php echo (int)$filterYear; ?></p>
        </div>
        <div class="panel-body">
            <?php $maxHm = max(1, max(array_values($hmRaw) ?: [1])); ?>
            <div class="an-heatmap">
                <?php for ($m = 1; $m <= 12; $m++):
                    $mn  = str_pad($m, 2, '0', STR_PAD_LEFT);
                    $cnt = (int)($hmRaw[$mn] ?? 0);
                    $intensity = $cnt === 0 ? 0 : min(4, (int)ceil(($cnt / $maxHm) * 4));
                    $isFuture = ($filterYear == (int)date('Y') && $m > (int)date('n'));
                ?>
                <div class="an-hm-cell hm-<?php echo $intensity; ?>" title="<?php echo $monthNames[$mn]; ?> <?php echo (int)$filterYear; ?>: <?php echo $cnt; ?> submission<?php echo $cnt!==1?'s':''; ?>">
                    <div class="meta"><?php echo $monthNames[$mn]; ?></div>
                    <div class="an-hm-val"><?php echo $isFuture ? '&mdash;' : $cnt; ?></div>
                </div>
                <?php endfor; ?>
            </div>
            <div class="an-legend meta" aria-hidden="true">Fewer <i></i><i class="hm-1"></i><i class="hm-2"></i><i class="hm-3"></i><i class="hm-4"></i> More</div>
        </div>
    </section>

    <?php if ($isPrivileged): ?>
    <!-- Region comparison -->
    <section class="panel" aria-labelledby="an-region-title">
        <div class="panel-head">
            <h2 id="an-region-title" class="section-title">Region comparison</h2>
            <p class="meta"><?php echo (int)$filterYear; ?></p>
        </div>
        <?php if (empty($regionRows)): ?>
        <div class="empty-state">
            <p class="empty-state-title">No data for these filters</p>
            <p class="empty-state-text">Try another year or clear the region filter.</p>
        </div>
        <?php else: ?>
        <div class="panel-body">
            <div class="an-chart-sm"><canvas id="regionChart" role="img" aria-label="Copies sponsored and souls won by region"></canvas></div>
        </div>
        <div class="table-wrap" style="border-radius:0;border-width:1px 0 0;">
            <table class="data-table">
                <thead><tr><th scope="col">Region</th><th scope="col" class="num">Reports</th><th scope="col" class="num">Copies</th><th scope="col" class="num">Souls</th><th scope="col" class="num">Partners</th><th scope="col" class="num">Churches</th></tr></thead>
                <tbody>
                <?php foreach ($regionRows as $rr): ?>
                <tr>
                    <td><?php echo htmlspecialchars($rr['region']); ?></td>
                    <td class="num"><?php echo number_format($rr['reports']); ?></td>
                    <td class="num"><?php echo number_format($rr['copies']); ?></td>
                    <td class="num"><?php echo number_format($rr['souls']); ?></td>
                    <td class="num"><?php echo number_format($rr['partners']); ?></td>
                    <td class="num"><?php echo number_format($rr['churches']); ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </section>
    <?php elseif (!empty($timelineRows)): ?>
    <!-- Personal timeline -->
    <section class="panel" aria-labelledby="an-tl-title">
        <div class="panel-head">
            <h2 id="an-tl-title" class="section-title">Your submissions</h2>
            <p class="meta"><?php echo count($timelineRows); ?> reports all time</p>
        </div>
        <div class="panel-body">
            <div class="an-chart-sm" style="margin-bottom:var(--spacing-4);"><canvas id="personalChart" role="img" aria-label="Copies sponsored and souls won per report"></canvas></div>
            <ul class="an-tl">
                <?php foreach (array_reverse($timelineRows) as $i => $tl): ?>
                <li>
                    <div class="an-tl-main">
                        <div class="an-tl-month">
                            <?php echo htmlspecialchars($tl['label']); ?>
                            <?php if ($i === 0): ?><span class="badge badge-success no-dot">Latest</span><?php endif; ?>
                        </div>
                        <div class="an-tl-stats">
                            <span><?php echo number_format($tl['total_copies']); ?> copies</span>
                            <span><?php echo number_format($tl['souls_won']); ?> souls</span>
                            <span><?php echo number_format($tl['new_partners']); ?> partners</span>
                            <span><?php echo number_format($tl['new_churches']); ?> churches</span>
                        </div>
                    </div>
                    <a class="link" href="<?php echo BASE_URL; ?>/pages/reports/view.php?id=<?php echo (int)$tl['id']; ?>">View</a>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php if (!empty($allTimeStats)): ?>
        <div class="panel-foot an-tl-stats">
            <span>All time:</span>
            <strong><?php echo number_format($allTimeStats['copies'] ?? 0); ?> copies</strong>
            <strong><?php echo number_format($allTimeStats['souls'] ?? 0); ?> souls</strong>
            <strong><?php echo number_format($allTimeStats['partners'] ?? 0); ?> partners</strong>
            <strong><?php echo number_format($allTimeStats['churches'] ?? 0); ?> churches</strong>
        </div>
        <?php endif; ?>
    </section>
    <?php else: ?>
    <section class="panel">
        <div class="empty-state">
            <div class="empty-state-icon"><i class="fas fa-file-alt" aria-hidden="true"></i></div>
            <p class="empty-state-title">No reports yet</p>
            <p class="empty-state-text">Submit your first monthly report to start building your timeline.</p>
            <a href="<?php echo BASE_URL; ?>/pages/reports/create.php" class="btn btn-primary"><i class="fas fa-plus" aria-hidden="true"></i> Submit your first report</a>
        </div>
    </section>
    <?php endif; ?>
</div>

<?php if ($hasRegionFilter && !empty($zoneCompare)): ?>
<!-- Zone breakdown within region -->
<section class="panel" aria-labelledby="an-zone-title">
    <div class="panel-head">
        <h2 id="an-zone-title" class="section-title">Zones in <?php echo htmlspecialchars($filterRegion); ?></h2>
        <p class="meta"><?php echo (int)$filterYear; ?></p>
    </div>
    <div class="panel-body">
        <div class="an-chart"><canvas id="zoneChart" role="img" aria-label="Copies sponsored and souls won by zone"></canvas></div>
    </div>
</section>
<?php endif; ?>

</div><!-- /.an-stack -->

<?php if ($isPrivileged): ?>
<!-- Leaderboards (quieter, last) -->
<section class="section an-page-tables" aria-labelledby="an-lb-title">
    <div class="section-head">
        <h2 id="an-lb-title" class="section-title">Zone compliance</h2>
        <span class="section-meta"><?php echo count($leaderboard); ?> zones &middot; <?php echo $filterYear==(int)date('Y')?(int)date('n'):12; ?> months expected</span>
    </div>
    <?php if (empty($leaderboard)): ?>
    <div class="panel panel-muted"><div class="empty-state"><p class="empty-state-title">No data for these filters</p></div></div>
    <?php else: ?>
    <div class="table-wrap" style="max-height:28rem;">
        <table class="data-table">
            <thead>
                <tr>
                    <th scope="col" class="num">#</th>
                    <th scope="col">Zone</th>
                    <th scope="col">Region</th>
                    <th scope="col" class="num">Months</th>
                    <th scope="col">Compliance</th>
                    <th scope="col" class="num">Copies</th>
                    <th scope="col" class="num">Souls</th>
                    <th scope="col">Last submitted</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($leaderboard as $i => $lb):
                $rateClass = $lb['rate'] >= 80 ? '' : ($lb['rate'] >= 50 ? 'mid' : 'low');
            ?>
            <tr>
                <td class="num muted"><?php echo $i + 1; ?></td>
                <td><?php echo htmlspecialchars($lb['zone']); ?></td>
                <td class="muted"><?php echo htmlspecialchars($lb['region'] ?? '—'); ?></td>
                <td class="num"><?php echo (int)$lb['months_submitted']; ?> / <?php echo (int)$lb['expected']; ?></td>
                <td>
                    <div class="an-rate <?php echo $rateClass; ?>">
                        <div class="an-rate-track"><div class="an-rate-fill" style="width:<?php echo (int)$lb['rate']; ?>%;"></div></div>
                        <span class="an-rate-val"><?php echo (int)$lb['rate']; ?>%</span>
                    </div>
                </td>
                <td class="num"><?php echo number_format($lb['copies']); ?></td>
                <td class="num"><?php echo number_format($lb['souls']); ?></td>
                <td><span class="meta"><?php echo $lb['last_submission'] ? htmlspecialchars(date('d M Y', strtotime($lb['last_submission']))) : '—'; ?></span></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>

<section class="section an-page-tables" aria-labelledby="an-top-title">
    <div class="section-head">
        <h2 id="an-top-title" class="section-title">Top performers</h2>
        <span class="section-meta">By copies sponsored &middot; top 10</span>
    </div>
    <?php if (empty($topUsers)): ?>
    <div class="panel panel-muted"><div class="empty-state"><p class="empty-state-title">No data for these filters</p></div></div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th scope="col" class="num">#</th><th scope="col">Name</th><th scope="col">Zone</th><th scope="col" class="num">Copies</th><th scope="col" class="num">Souls</th></tr></thead>
            <tbody>
            <?php foreach ($topUsers as $ti => $tu): ?>
            <tr>
                <td class="num muted"><?php echo $ti + 1; ?></td>
                <td><?php echo htmlspecialchars($tu['name'] ?? '—'); ?></td>
                <td class="muted"><?php echo htmlspecialchars($tu['zone'] ?? '—'); ?></td>
                <td class="num"><?php echo number_format($tu['copies']); ?></td>
                <td class="num"><?php echo number_format($tu['souls']); ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>

<?php else: ?>
<!-- Streak + achievements (quieter, last) -->
<?php
$allBadgeDefs = [
    ['icon'=>'fa-star',         'label'=>'First Report',    'desc'=>'Submit your first report'],
    ['icon'=>'fa-seedling',     'label'=>'Getting Started', 'desc'=>'3 reports submitted'],
    ['icon'=>'fa-medal',        'label'=>'Half Year',       'desc'=>'6 months of reporting'],
    ['icon'=>'fa-trophy',       'label'=>'Full Year',       'desc'=>'12 months of reporting'],
    ['icon'=>'fa-fire',         'label'=>'On Fire',         'desc'=>'3-month streak'],
    ['icon'=>'fa-fire-alt',     'label'=>'Unstoppable',     'desc'=>'6-month streak'],
    ['icon'=>'fa-crown',        'label'=>'Legendary',       'desc'=>'12-month streak'],
    ['icon'=>'fa-heart',        'label'=>'Soul Winner',     'desc'=>'100+ souls won'],
    ['icon'=>'fa-hands-helping','label'=>'Soul Champion',   'desc'=>'500+ souls won'],
    ['icon'=>'fa-church',       'label'=>'Church Planter',  'desc'=>'5+ new churches'],
    ['icon'=>'fa-handshake',    'label'=>'Partner Builder', 'desc'=>'50+ new partners'],
];
$earnedLabels = array_column($badges, 'label');
?>
<section class="section" aria-labelledby="an-ach-title">
    <div class="section-head">
        <h2 id="an-ach-title" class="section-title">Streak &amp; achievements</h2>
        <span class="section-meta"><?php echo count($badges); ?> of <?php echo count($allBadgeDefs); ?> earned</span>
    </div>
    <div class="panel panel-muted">
        <div class="panel-body an-achieve">
            <div class="an-streak">
                <span class="meta">Submission streak</span>
                <span class="an-streak-value"><?php echo (int)$streakCount; ?> month<?php echo $streakCount !== 1 ? 's' : ''; ?></span>
                <span class="muted" style="font-size:var(--text-sm);">
                    <?php if ($streakCount === 0): ?>Submit this month's report to start a streak.
                    <?php elseif ($streakCount < 3): ?>Keep submitting every month to build your streak.
                    <?php elseif ($streakCount < 6): ?><?php echo 6 - $streakCount; ?> more months to earn Unstoppable.
                    <?php elseif ($streakCount < 12): ?><?php echo 12 - $streakCount; ?> more months to Legendary.
                    <?php else: ?>You've kept a <?php echo (int)$streakCount; ?>-month streak.
                    <?php endif; ?>
                </span>
                <?php if ($streakCount === 0): ?>
                <a href="<?php echo BASE_URL; ?>/pages/reports/create.php" class="link">Submit report</a>
                <?php endif; ?>
            </div>
            <ul class="an-badges">
                <?php foreach ($allBadgeDefs as $bd): $earned = in_array($bd['label'], $earnedLabels, true); ?>
                <li class="an-badge <?php echo $earned ? 'is-earned' : 'is-locked'; ?>">
                    <i class="fas <?php echo htmlspecialchars($bd['icon']); ?>" aria-hidden="true"></i>
                    <div>
                        <?php echo htmlspecialchars($bd['label']); ?>
                        <small><?php echo htmlspecialchars($bd['desc']); ?></small>
                    </div>
                    <span class="an-badge-state">
                        <i class="fas <?php echo $earned ? 'fa-check' : 'fa-lock'; ?>" aria-hidden="true"></i>
                        <span class="sr-only"><?php echo $earned ? 'Earned' : 'Locked'; ?></span>
                    </span>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</section>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function() {
if (typeof Chart === 'undefined') return;

// Palette from design tokens
var css = getComputedStyle(document.documentElement);
function tok(name, fallback) { return (css.getPropertyValue(name) || '').trim() || fallback || ''; }
function alpha(hex, a) {
    var h = hex.replace('#', '');
    if (h.length === 3) h = h.split('').map(function(c) { return c + c; }).join('');
    var n = parseInt(h, 16);
    return 'rgba(' + ((n >> 16) & 255) + ',' + ((n >> 8) & 255) + ',' + (n & 255) + ',' + a + ')';
}
var C = {
    ink: tok('--ink'),
    slate: tok('--slate'),
    signal: tok('--signal'),
    success: tok('--success-600'),
    warning: tok('--warning-500'),
    grid: tok('--neutral-200'),
    tick: tok('--neutral-600')
};
var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
Chart.defaults.font.family = tok('--font-sans', 'Inter, system-ui, sans-serif');
Chart.defaults.font.size = 11;
Chart.defaults.color = C.tick;
Chart.defaults.borderColor = C.grid;
if (reduceMotion) Chart.defaults.animation = false;
Chart.defaults.plugins.tooltip.backgroundColor = C.ink;
Chart.defaults.plugins.legend.labels.boxWidth = 10;
Chart.defaults.plugins.legend.labels.boxHeight = 10;
function kfmt(v) { var a = Math.abs(v); return a >= 1e6 ? +(v / 1e6).toFixed(1) + 'M' : a >= 1e3 ? +(v / 1e3).toFixed(1) + 'k' : v; }
function barSet(label, data, color, axis) {
    return { label: label, data: data, backgroundColor: color, borderWidth: 0, borderRadius: 3, maxBarThickness: 28, yAxisID: axis || 'y' };
}

var trendLabels   = <?php echo json_encode($trendLabels, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
var metricData = {
    copies:   {label:'Copies sponsored', data:<?php echo json_encode($trendCopies); ?>,   color:C.signal},
    souls:    {label:'Souls won',        data:<?php echo json_encode($trendSouls); ?>,    color:C.ink},
    partners: {label:'New partners',     data:<?php echo json_encode($trendPartners); ?>, color:C.success},
    churches: {label:'New churches',     data:<?php echo json_encode($trendChurches); ?>, color:C.warning}
};

var trendCtx = document.getElementById('trendChart');
var trendChart = null;
if (trendCtx) {
    trendChart = new Chart(trendCtx, {
        type: 'line',
        data: { labels: trendLabels, datasets: [{
            label: metricData.copies.label, data: metricData.copies.data,
            borderColor: C.signal, backgroundColor: alpha(C.signal, 0.08),
            tension: 0.3, fill: true, pointRadius: 3, pointHoverRadius: 5, pointBackgroundColor: C.signal, borderWidth: 2
        }]},
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: false }, tooltip: { callbacks: {
                label: function(ctx) { return ' ' + ctx.dataset.label + ': ' + ctx.parsed.y.toLocaleString(); }
            }}},
            scales: {
                x: { grid: { display: false } },
                y: { beginAtZero: true, border: { display: false }, ticks: { callback: kfmt } }
            }
        }
    });
}

window.switchChart = function(metric) {
    if (!trendChart || !metricData[metric]) return;
    var m = metricData[metric], ds = trendChart.data.datasets[0];
    ds.label = m.label; ds.data = m.data;
    ds.borderColor = m.color; ds.pointBackgroundColor = m.color; ds.backgroundColor = alpha(m.color, 0.08);
    trendChart.update();
    document.querySelectorAll('.an-chart-btn').forEach(function(b) {
        var on = b.dataset.metric === metric;
        b.classList.toggle('active', on);
        b.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
};

<?php if (!$isPrivileged && !empty($timelineRows)):
    $tlLabels   = array_column($timelineRows, 'label');
    $tlCopies   = array_map(fn($r)=>(int)$r['total_copies'],  $timelineRows);
    $tlSouls    = array_map(fn($r)=>(int)$r['souls_won'],      $timelineRows);
?>
var personalCtx = document.getElementById('personalChart');
if (personalCtx) {
    new Chart(personalCtx, {
        type: 'bar',
        data: { labels: <?php echo json_encode($tlLabels, JSON_HEX_TAG | JSON_HEX_AMP); ?>, datasets: [
            barSet('Copies sponsored', <?php echo json_encode($tlCopies); ?>, C.signal, 'y'),
            barSet('Souls won', <?php echo json_encode($tlSouls); ?>, C.slate, 'y1')
        ]},
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { position: 'top', align: 'end' } },
            scales: {
                x:  { grid: { display: false } },
                y:  { position: 'left',  beginAtZero: true, border: { display: false }, ticks: { callback: kfmt } },
                y1: { position: 'right', beginAtZero: true, grid: { display: false }, border: { display: false }, ticks: { callback: kfmt } }
            }
        }
    });
}
<?php endif; ?>

<?php if ($isPrivileged && !empty($regionRows)):
    $rcLabels = array_column($regionRows, 'region');
    $rcCopies = array_map(fn($r)=>(int)$r['copies'], $regionRows);
    $rcSouls  = array_map(fn($r)=>(int)$r['souls'],  $regionRows);
?>
var regionCtx = document.getElementById('regionChart');
if (regionCtx) {
    new Chart(regionCtx, {
        type: 'bar',
        data: { labels: <?php echo json_encode($rcLabels, JSON_HEX_TAG | JSON_HEX_AMP); ?>, datasets: [
            barSet('Copies sponsored', <?php echo json_encode($rcCopies); ?>, C.signal),
            barSet('Souls won', <?php echo json_encode($rcSouls); ?>, C.slate)
        ]},
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { position: 'top', align: 'end' } },
            scales: {
                x: { grid: { display: false } },
                y: { beginAtZero: true, border: { display: false }, ticks: { callback: kfmt } }
            }
        }
    });
}
<?php endif; ?>

<?php if ($isPrivileged && $filterRegion && !empty($zoneCompare)):
    $zcLabels = array_column($zoneCompare, 'zone');
    $zcCopies = array_map(fn($r)=>(int)$r['copies'], $zoneCompare);
    $zcSouls  = array_map(fn($r)=>(int)$r['souls'],  $zoneCompare);
?>
var zoneCtx = document.getElementById('zoneChart');
if (zoneCtx) {
    new Chart(zoneCtx, {
        type: 'bar',
        data: { labels: <?php echo json_encode($zcLabels, JSON_HEX_TAG | JSON_HEX_AMP); ?>, datasets: [
            barSet('Copies sponsored', <?php echo json_encode($zcCopies); ?>, C.signal),
            barSet('Souls won', <?php echo json_encode($zcSouls); ?>, C.slate)
        ]},
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { position: 'top', align: 'end' } },
            scales: {
                x: { grid: { display: false }, ticks: { font: { size: 10 }, maxRotation: 35 } },
                y: { beginAtZero: true, border: { display: false }, ticks: { callback: kfmt } }
            }
        }
    });
}
<?php endif; ?>
})();
</script>

<?php require_once '../layouts/footer.php'; ?>
