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

// Available years
$yearsStmt = $conn->query("SELECT DISTINCT YEAR(report_month) as y FROM reports ORDER BY y DESC");
$availableYears = $yearsStmt->fetchAll(PDO::FETCH_COLUMN);
if (!$availableYears) $availableYears = [(int)date('Y')];

// Available regions / zones for filters
$regionsStmt = $conn->query("SELECT DISTINCT region FROM users WHERE region IS NOT NULL AND region != '' ORDER BY region");
$allRegions  = $regionsStmt->fetchAll(PDO::FETCH_COLUMN);

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

    if ($totalReports >= 1)  $badges[] = ['icon'=>'fa-star',        'color'=>'#f59e0b','label'=>'First Report',       'desc'=>'You submitted your first report'];
    if ($totalReports >= 3)  $badges[] = ['icon'=>'fa-seedling',     'color'=>'#10b981','label'=>'Getting Started',    'desc'=>'3 reports submitted'];
    if ($totalReports >= 6)  $badges[] = ['icon'=>'fa-medal',        'color'=>'#0ea5e9','label'=>'Half Year',          'desc'=>'6 months of reporting'];
    if ($totalReports >= 12) $badges[] = ['icon'=>'fa-trophy',       'color'=>'#f59e0b','label'=>'Full Year',          'desc'=>'12 months of reporting'];
    if ($streakCount  >= 3)  $badges[] = ['icon'=>'fa-fire',         'color'=>'#ef4444','label'=>'On Fire',            'desc'=>'3-month streak'];
    if ($streakCount  >= 6)  $badges[] = ['icon'=>'fa-fire-alt',     'color'=>'#dc2626','label'=>'Unstoppable',        'desc'=>'6-month streak'];
    if ($streakCount  >= 12) $badges[] = ['icon'=>'fa-crown',        'color'=>'#7c3aed','label'=>'Legendary',         'desc'=>'12-month streak'];
    if ((int)($allTimeStats['souls'] ?? 0) >= 100)     $badges[] = ['icon'=>'fa-heart',        'color'=>'#ec4899','label'=>'Soul Winner',        'desc'=>'100+ souls won'];
    if ((int)($allTimeStats['souls'] ?? 0) >= 500)     $badges[] = ['icon'=>'fa-hands-helping','color'=>'#8b5cf6','label'=>'Soul Champion',      'desc'=>'500+ souls won'];
    if ((int)($allTimeStats['churches'] ?? 0) >= 5)    $badges[] = ['icon'=>'fa-church',       'color'=>'#6366f1','label'=>'Church Planter',     'desc'=>'5+ new churches'];
    if ((int)($allTimeStats['partners'] ?? 0) >= 50)   $badges[] = ['icon'=>'fa-handshake',    'color'=>'#0d9488','label'=>'Partner Builder',    'desc'=>'50+ new partners'];
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

<style>
.an-page { max-width:1100px; margin:0 auto; padding:1.5rem 1rem 3rem; }
.an-breadcrumb { display:flex; align-items:center; gap:0.35rem; font-size:0.78rem; color:var(--neutral-400); margin-bottom:1.25rem; flex-wrap:wrap; }
.an-breadcrumb a { color:var(--neutral-500); text-decoration:none; }
.an-breadcrumb a:hover { color:var(--primary-600); }
.an-breadcrumb .sep { color:var(--neutral-300); }
.an-head { display:flex; align-items:flex-end; justify-content:space-between; flex-wrap:wrap; gap:0.75rem; margin-bottom:1.5rem; }
.an-head-left h1 { font-size:1.2rem; font-weight:700; color:var(--neutral-900); margin:0 0 0.2rem; }
.an-head-left p  { font-size:0.8rem; color:var(--neutral-500); margin:0; }

/* Filters */
.an-filters { display:flex; align-items:center; gap:0.5rem; flex-wrap:wrap; background:#fff; border:1px solid var(--neutral-200); border-radius:var(--radius-lg); padding:0.75rem 1rem; margin-bottom:1.5rem; }
.an-filters label { font-size:0.75rem; font-weight:600; color:var(--neutral-500); margin-right:0.2rem; }
.an-filters select { font-size:0.8rem; padding:0.3rem 0.6rem; border:1px solid var(--neutral-200); border-radius:var(--radius-md); color:var(--neutral-700); background:#fff; }
.an-filters button { font-size:0.8rem; }

/* Section card */
.an-section { border:1px solid var(--neutral-200); border-radius:var(--radius-lg); background:#fff; overflow:hidden; margin-bottom:1.25rem; }
.an-section-head { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:0.5rem; padding:0.8rem 1.1rem; background:var(--neutral-50); border-bottom:1px solid var(--neutral-100); }
.an-section-head h2 { font-size:0.8125rem; font-weight:700; color:var(--neutral-800); margin:0; text-transform:uppercase; letter-spacing:0.05em; display:flex; align-items:center; gap:0.5rem; }
.an-section-body { padding:1.1rem; }

/* Stat strip */
.an-stats { display:grid; grid-template-columns:repeat(4,1fr); gap:1px; background:var(--neutral-200); border:1px solid var(--neutral-200); border-radius:var(--radius-lg); overflow:hidden; margin-bottom:1.25rem; }
@media(max-width:680px){ .an-stats { grid-template-columns:repeat(2,1fr); } }
.an-stat { background:#fff; padding:1rem 1.25rem; }
.an-stat-lbl { font-size:0.68rem; font-weight:600; color:var(--neutral-400); text-transform:uppercase; letter-spacing:0.05em; }
.an-stat-val { font-size:1.35rem; font-weight:700; color:var(--neutral-900); line-height:1.2; margin:0.15rem 0; }
.an-stat-sub { font-size:0.7rem; color:var(--neutral-400); }

/* Chart containers */
.an-chart-wrap { position:relative; height:260px; }
.an-chart-wrap-sm { position:relative; height:220px; }

/* Heatmap */
.an-heatmap { display:grid; grid-template-columns:repeat(6,1fr); gap:0.5rem; }
@media(max-width:500px){ .an-heatmap { grid-template-columns:repeat(4,1fr); } }
.an-hm-cell { border-radius:var(--radius-md); padding:0.7rem 0.5rem; text-align:center; background:var(--neutral-100); border:1px solid var(--neutral-200); transition:transform 0.1s; cursor:default; }
.an-hm-cell:hover { transform:scale(1.04); }
.an-hm-cell.hm-0  { background:#f1f5f9; border-color:#e2e8f0; }
.an-hm-cell.hm-1  { background:#dbeafe; border-color:#bfdbfe; }
.an-hm-cell.hm-2  { background:#bfdbfe; border-color:#93c5fd; }
.an-hm-cell.hm-3  { background:#60a5fa; border-color:#3b82f6; color:#fff; }
.an-hm-cell.hm-4  { background:#2563eb; border-color:#1d4ed8; color:#fff; }
.an-hm-cell-lbl { font-size:0.65rem; font-weight:600; color:inherit; text-transform:uppercase; }
.an-hm-cell-val { font-size:1.1rem; font-weight:700; color:inherit; }

/* Leaderboard table */
.an-lb { width:100%; border-collapse:collapse; font-size:0.8rem; }
.an-lb th { font-size:0.68rem; font-weight:700; color:var(--neutral-500); text-transform:uppercase; letter-spacing:0.04em; padding:0.5rem 0.75rem; text-align:left; border-bottom:1px solid var(--neutral-200); white-space:nowrap; }
.an-lb td { padding:0.6rem 0.75rem; border-bottom:1px solid var(--neutral-100); color:var(--neutral-700); vertical-align:middle; }
.an-lb tr:last-child td { border-bottom:none; }
.an-lb tr:hover td { background:var(--neutral-50); }
.an-rank { display:inline-flex; align-items:center; justify-content:center; width:1.5rem; height:1.5rem; border-radius:50%; font-size:0.72rem; font-weight:700; background:var(--neutral-100); color:var(--neutral-600); }
.an-rank.r1 { background:#fef08a; color:#713f12; }
.an-rank.r2 { background:#e2e8f0; color:#374151; }
.an-rank.r3 { background:#fde8d0; color:#7c2d12; }
.an-rate-bar { display:flex; align-items:center; gap:0.5rem; }
.an-rate-track { flex:1; height:6px; background:var(--neutral-100); border-radius:3px; overflow:hidden; }
.an-rate-fill { height:100%; border-radius:3px; transition:width 0.6s ease; }

/* Badges */
.an-badges { display:flex; flex-wrap:wrap; gap:0.75rem; }
.an-badge { display:flex; align-items:center; gap:0.5rem; padding:0.5rem 0.9rem; border-radius:var(--radius-lg); border:1px solid; background:#fff; }
.an-badge-icon { width:1.75rem; height:1.75rem; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:0.8rem; flex-shrink:0; }
.an-badge-text strong { font-size:0.78rem; font-weight:700; display:block; color:var(--neutral-900); }
.an-badge-text span   { font-size:0.68rem; color:var(--neutral-500); }
.an-badge-locked { opacity:0.35; filter:grayscale(1); }

/* Streak hero */
.an-streak { display:flex; align-items:center; gap:1.25rem; padding:1rem 1.25rem; background:linear-gradient(135deg,#fff7ed,#fef3c7); border:1px solid #fed7aa; border-radius:var(--radius-lg); margin-bottom:1rem; }
.an-streak-icon { font-size:2.2rem; }
.an-streak-label { font-size:0.78rem; color:var(--warning-700); font-weight:600; text-transform:uppercase; letter-spacing:0.05em; }
.an-streak-value { font-size:2rem; font-weight:800; color:var(--warning-600); line-height:1; }
.an-streak-desc { font-size:0.78rem; color:var(--neutral-500); margin-top:0.15rem; }
.an-streak-zero { background:var(--neutral-50); border-color:var(--neutral-200); }
.an-streak-zero .an-streak-icon { opacity:0.3; }

/* Timeline */
.an-timeline { display:flex; flex-direction:column; gap:0; }
.an-tl-item { display:flex; align-items:flex-start; gap:0.75rem; padding:0.65rem 0; border-bottom:1px solid var(--neutral-100); }
.an-tl-item:last-child { border-bottom:none; }
.an-tl-dot-col { display:flex; flex-direction:column; align-items:center; gap:0; padding-top:0.15rem; }
.an-tl-dot { width:0.65rem; height:0.65rem; border-radius:50%; background:var(--primary-400); flex-shrink:0; }
.an-tl-dot.latest { background:var(--success-500); box-shadow:0 0 0 3px rgba(16,185,129,0.15); }
.an-tl-month { font-size:0.78rem; font-weight:700; color:var(--neutral-900); }
.an-tl-stats { font-size:0.72rem; color:var(--neutral-500); display:flex; gap:0.75rem; flex-wrap:wrap; margin-top:0.1rem; }
.an-tl-stat  { display:flex; align-items:center; gap:0.2rem; }

/* Region comparison */
.an-region-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(220px,1fr)); gap:0.75rem; }
.an-region-card { border:1px solid var(--neutral-200); border-radius:var(--radius-lg); padding:0.85rem 1rem; background:#fff; }
.an-region-card h4 { font-size:0.8rem; font-weight:700; color:var(--neutral-800); margin:0 0 0.5rem; }
.an-region-stat { display:flex; justify-content:space-between; font-size:0.76rem; color:var(--neutral-600); margin-bottom:0.2rem; }
.an-region-stat strong { color:var(--neutral-900); }

/* Export bar */
.an-export { display:flex; align-items:center; gap:0.5rem; flex-wrap:wrap; }

/* two col */
.an-two { display:grid; grid-template-columns:1fr 1fr; gap:1.25rem; margin-bottom:1.25rem; }
@media(max-width:800px){ .an-two { grid-template-columns:1fr; } }

/* success state */
.an-success-state { text-align:center; padding:2rem 1rem; }
.an-success-state .an-ss-icon { font-size:2.5rem; color:var(--success-500); margin-bottom:0.5rem; }
.an-success-state h3 { font-size:1rem; font-weight:700; color:var(--neutral-900); margin:0 0 0.25rem; }
.an-success-state p  { font-size:0.8rem; color:var(--neutral-500); margin:0; }
</style>

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

<div class="an-page">

    <div class="an-breadcrumb">
        <a href="<?php echo BASE_URL; ?>/dashboard.php"><i class="fas fa-home" style="font-size:0.7rem;"></i> Dashboard</a>
        <span class="sep">/</span>
        <span>Analytics</span>
    </div>

    <div class="an-head">
        <div class="an-head-left">
            <h1><i class="fas fa-chart-area" style="color:var(--primary-500);margin-right:0.4rem;font-size:1rem;"></i>Analytics &amp; Reporting</h1>
            <p><?php echo $isPrivileged ? 'Organisation-wide performance trends and compliance insights' : 'Your personal performance trends and submission history'; ?></p>
        </div>
        <div class="an-export">
            <?php if ($isPrivileged): ?>
            <a href="<?php echo BASE_URL; ?>/admin/export_summary_report.php?year=<?php echo $filterYear; ?><?php echo $filterRegion ? '&region='.urlencode($filterRegion) : ''; ?>" class="modern-button modern-button-secondary" style="font-size:0.78rem;"><i class="fas fa-file-csv modern-button-icon"></i>Export CSV</a>
            <a href="<?php echo BASE_URL; ?>/admin/export_compliance_report.php?year=<?php echo $filterYear; ?><?php echo $filterRegion ? '&region='.urlencode($filterRegion) : ''; ?>" class="modern-button modern-button-secondary" style="font-size:0.78rem;"><i class="fas fa-file-alt modern-button-icon"></i>Compliance Report</a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Filters -->
    <form method="GET" class="an-filters">
        <label for="an-year">Year</label>
        <select name="year" id="an-year">
            <?php foreach ($availableYears as $y): ?>
            <option value="<?php echo $y; ?>" <?php echo $y==$filterYear?'selected':''; ?>><?php echo $y; ?></option>
            <?php endforeach; ?>
        </select>
        <?php if ($isPrivileged): ?>
        <label for="an-region" style="margin-left:0.5rem;">Region</label>
        <select name="region" id="an-region">
            <option value="">All Regions</option>
            <?php foreach ($allRegions as $r): ?>
            <option value="<?php echo htmlspecialchars($r); ?>" <?php echo $r===$filterRegion?'selected':''; ?>><?php echo htmlspecialchars($r); ?></option>
            <?php endforeach; ?>
        </select>
        <label for="an-zone" style="margin-left:0.5rem;">Zone</label>
        <select name="zone" id="an-zone">
            <option value="">All Zones</option>
            <?php foreach ($allZones as $z): ?>
            <option value="<?php echo htmlspecialchars($z); ?>" <?php echo $z===$filterZone?'selected':''; ?>><?php echo htmlspecialchars($z); ?></option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <button type="submit" class="modern-button modern-button-primary" style="font-size:0.78rem;padding:0.35rem 0.85rem;"><i class="fas fa-filter modern-button-icon"></i>Apply</button>
        <?php if ($filterYear != date('Y') || $filterRegion || $filterZone): ?>
        <a href="analytics.php" class="modern-button modern-button-secondary" style="font-size:0.78rem;padding:0.35rem 0.75rem;">Reset</a>
        <?php endif; ?>
    </form>

    <!-- Stat strip -->
    <div class="an-stats">
        <div class="an-stat">
            <div class="an-stat-lbl">Total Submissions</div>
            <div class="an-stat-val"><?php echo number_format($agg['reports'] ?? 0); ?></div>
            <div class="an-stat-sub"><?php echo $filterYear; ?><?php echo $filterRegion ? ' · '.$filterRegion : ''; ?></div>
        </div>
        <div class="an-stat">
            <div class="an-stat-lbl">Copies Sponsored</div>
            <div class="an-stat-val"><?php echo number_format($agg['copies'] ?? 0); ?></div>
            <div class="an-stat-sub">Total for period</div>
        </div>
        <div class="an-stat">
            <div class="an-stat-lbl">Souls Won</div>
            <div class="an-stat-val"><?php echo number_format($agg['souls'] ?? 0); ?></div>
            <div class="an-stat-sub">Total for period</div>
        </div>
        <div class="an-stat">
            <div class="an-stat-lbl"><?php echo $isPrivileged ? 'Active RZMs' : 'New Partners'; ?></div>
            <div class="an-stat-val"><?php echo $isPrivileged ? number_format($agg['users'] ?? 0) : number_format($agg['partners'] ?? 0); ?></div>
            <div class="an-stat-sub">Total for period</div>
        </div>
    </div>

    <?php if (!$isPrivileged): ?>
    <!-- ══ Non-admin: streak hero ══ -->
    <div class="an-streak <?php echo $streakCount === 0 ? 'an-streak-zero' : ''; ?>" style="margin-bottom:1.25rem;">
        <div class="an-streak-icon"><?php echo $streakCount >= 6 ? '🔥' : ($streakCount >= 3 ? '⚡' : '📅'); ?></div>
        <div>
            <div class="an-streak-label">Submission Streak</div>
            <div class="an-streak-value"><?php echo $streakCount; ?> month<?php echo $streakCount !== 1 ? 's' : ''; ?></div>
            <div class="an-streak-desc">
                <?php if ($streakCount === 0): ?>You haven't built a streak yet — submit to start one!
                <?php elseif ($streakCount < 3): ?>Good start! Keep submitting every month to build your streak.
                <?php elseif ($streakCount < 6): ?>You're on fire! <?php echo 6-$streakCount; ?> more months to earn the Unstoppable badge.
                <?php elseif ($streakCount < 12): ?>Incredible — <?php echo 12-$streakCount; ?> more months to Legendary status.
                <?php else: ?>Legendary! You've maintained a <?php echo $streakCount; ?>-month streak.
                <?php endif; ?>
            </div>
        </div>
        <?php if ($streakCount === 0): ?>
        <a href="<?php echo BASE_URL; ?>/pages/reports/create.php" class="modern-button modern-button-primary" style="font-size:0.78rem;margin-left:auto;">Start Streak &rarr;</a>
        <?php endif; ?>
    </div>

    <!-- ══ Badges ══ -->
    <div class="an-section" style="margin-bottom:1.25rem;">
        <div class="an-section-head">
            <h2><i class="fas fa-award" style="color:#f59e0b;"></i> Achievements &amp; Badges</h2>
            <span style="font-size:0.75rem;color:var(--neutral-400);"><?php echo count($badges); ?> earned</span>
        </div>
        <div class="an-section-body">
            <?php
            $allBadgeDefs = [
                ['icon'=>'fa-star',        'color'=>'#f59e0b','label'=>'First Report',    'desc'=>'Submit your first report'],
                ['icon'=>'fa-seedling',     'color'=>'#10b981','label'=>'Getting Started', 'desc'=>'3 reports submitted'],
                ['icon'=>'fa-medal',        'color'=>'#0ea5e9','label'=>'Half Year',       'desc'=>'6 months of reporting'],
                ['icon'=>'fa-trophy',       'color'=>'#f59e0b','label'=>'Full Year',       'desc'=>'12 months of reporting'],
                ['icon'=>'fa-fire',         'color'=>'#ef4444','label'=>'On Fire',         'desc'=>'3-month streak'],
                ['icon'=>'fa-fire-alt',     'color'=>'#dc2626','label'=>'Unstoppable',     'desc'=>'6-month streak'],
                ['icon'=>'fa-crown',        'color'=>'#7c3aed','label'=>'Legendary',       'desc'=>'12-month streak'],
                ['icon'=>'fa-heart',        'color'=>'#ec4899','label'=>'Soul Winner',     'desc'=>'100+ souls won'],
                ['icon'=>'fa-hands-helping','color'=>'#8b5cf6','label'=>'Soul Champion',  'desc'=>'500+ souls won'],
                ['icon'=>'fa-church',       'color'=>'#6366f1','label'=>'Church Planter',  'desc'=>'5+ new churches'],
                ['icon'=>'fa-handshake',    'color'=>'#0d9488','label'=>'Partner Builder', 'desc'=>'50+ new partners'],
            ];
            $earnedLabels = array_column($badges, 'label');
            ?>
            <div class="an-badges">
                <?php foreach ($allBadgeDefs as $bd):
                    $earned = in_array($bd['label'], $earnedLabels);
                ?>
                <div class="an-badge <?php echo !$earned ? 'an-badge-locked' : ''; ?>" style="border-color:<?php echo $earned ? $bd['color'].'40' : 'var(--neutral-200)'; ?>;" title="<?php echo htmlspecialchars($bd['desc']); ?>">
                    <div class="an-badge-icon" style="background:<?php echo $bd['color'].'18'; ?>;color:<?php echo $bd['color']; ?>;">
                        <i class="fas <?php echo $bd['icon']; ?>"></i>
                    </div>
                    <div class="an-badge-text">
                        <strong><?php echo $bd['label']; ?></strong>
                        <span><?php echo $bd['desc']; ?></span>
                    </div>
                    <?php if ($earned): ?>
                    <i class="fas fa-check-circle" style="color:var(--success-500);font-size:0.75rem;margin-left:0.25rem;"></i>
                    <?php else: ?>
                    <i class="fas fa-lock" style="color:var(--neutral-300);font-size:0.75rem;margin-left:0.25rem;"></i>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ══ Trend Charts ══ -->
    <div class="an-section">
        <div class="an-section-head">
            <h2><i class="fas fa-chart-line" style="color:var(--primary-500);"></i> Historical Trends — Last 12 Months</h2>
            <div style="display:flex;gap:0.4rem;flex-wrap:wrap;">
                <button onclick="switchChart('copies')"  class="modern-button modern-button-secondary an-chart-btn active" data-metric="copies"   style="font-size:0.72rem;padding:0.25rem 0.6rem;">Copies</button>
                <button onclick="switchChart('souls')"   class="modern-button modern-button-secondary an-chart-btn"        data-metric="souls"    style="font-size:0.72rem;padding:0.25rem 0.6rem;">Souls</button>
                <button onclick="switchChart('partners')"class="modern-button modern-button-secondary an-chart-btn"        data-metric="partners" style="font-size:0.72rem;padding:0.25rem 0.6rem;">Partners</button>
                <button onclick="switchChart('churches')"class="modern-button modern-button-secondary an-chart-btn"        data-metric="churches" style="font-size:0.72rem;padding:0.25rem 0.6rem;">Churches</button>
            </div>
        </div>
        <div class="an-section-body">
            <div class="an-chart-wrap">
                <canvas id="trendChart"></canvas>
            </div>
        </div>
    </div>

    <!-- ══ Submission Heatmap ══ -->
    <div class="an-section">
        <div class="an-section-head">
            <h2><i class="fas fa-th" style="color:#6366f1;"></i> Submission Heatmap — <?php echo $filterYear; ?></h2>
        </div>
        <div class="an-section-body">
            <?php
            $maxHm = max(1, max(array_values($hmRaw) ?: [1]));
            ?>
            <div class="an-heatmap">
                <?php for ($m = 1; $m <= 12; $m++):
                    $mn  = str_pad($m, 2, '0', STR_PAD_LEFT);
                    $cnt = (int)($hmRaw[$mn] ?? 0);
                    $intensity = $cnt === 0 ? 0 : min(4, (int)ceil(($cnt / $maxHm) * 4));
                    $isFuture = ($filterYear == (int)date('Y') && $m > (int)date('n'));
                ?>
                <div class="an-hm-cell hm-<?php echo $intensity; ?>" title="<?php echo $monthNames[$mn]; ?> <?php echo $filterYear; ?>: <?php echo $cnt; ?> submission<?php echo $cnt!==1?'s':''; ?>">
                    <div class="an-hm-cell-lbl"><?php echo $monthNames[$mn]; ?></div>
                    <div class="an-hm-cell-val"><?php echo $isFuture ? '—' : $cnt; ?></div>
                </div>
                <?php endfor; ?>
            </div>
            <p style="font-size:0.72rem;color:var(--neutral-400);margin:0.75rem 0 0;text-align:right;">
                Colour intensity = submission volume &nbsp;·&nbsp; Hover for details
            </p>
        </div>
    </div>

    <?php if (!$isPrivileged && !empty($timelineRows)): ?>
    <!-- ══ Personal Timeline ══ -->
    <div class="an-section">
        <div class="an-section-head">
            <h2><i class="fas fa-stream" style="color:var(--primary-400);"></i> My Submission Timeline</h2>
            <span style="font-size:0.75rem;color:var(--neutral-400);"><?php echo count($timelineRows); ?> reports all time</span>
        </div>
        <div class="an-section-body">
            <div class="an-chart-wrap-sm" style="margin-bottom:1rem;">
                <canvas id="personalChart"></canvas>
            </div>
            <div class="an-timeline" style="max-height:260px;overflow-y:auto;">
                <?php $timelineRev = array_reverse($timelineRows); ?>
                <?php foreach ($timelineRev as $i => $tl): ?>
                <div class="an-tl-item">
                    <div class="an-tl-dot-col">
                        <div class="an-tl-dot <?php echo $i===0?'latest':''; ?>"></div>
                    </div>
                    <div style="flex:1;">
                        <div class="an-tl-month">
                            <?php echo htmlspecialchars($tl['label']); ?>
                            <?php if ($i===0): ?><span style="font-size:0.65rem;background:var(--success-100);color:var(--success-700);padding:0.1rem 0.4rem;border-radius:9999px;margin-left:0.4rem;font-weight:600;">Latest</span><?php endif; ?>
                        </div>
                        <div class="an-tl-stats">
                            <span class="an-tl-stat"><i class="fas fa-book" style="color:var(--primary-400);"></i> <?php echo number_format($tl['total_copies']); ?> copies</span>
                            <span class="an-tl-stat"><i class="fas fa-heart" style="color:#ec4899;"></i> <?php echo number_format($tl['souls_won']); ?> souls</span>
                            <span class="an-tl-stat"><i class="fas fa-handshake" style="color:#0d9488;"></i> <?php echo number_format($tl['new_partners']); ?> partners</span>
                            <span class="an-tl-stat"><i class="fas fa-church" style="color:#6366f1;"></i> <?php echo number_format($tl['new_churches']); ?> churches</span>
                        </div>
                    </div>
                    <a href="<?php echo BASE_URL; ?>/pages/reports/view.php?id=<?php echo $tl['id']; ?>" style="font-size:0.72rem;color:var(--primary-600);text-decoration:none;white-space:nowrap;">View &rarr;</a>
                </div>
                <?php endforeach; ?>
            </div>
            <?php if (!empty($allTimeStats)): ?>
            <div style="display:flex;gap:1rem;flex-wrap:wrap;border-top:1px solid var(--neutral-100);margin-top:0.75rem;padding-top:0.75rem;">
                <div style="font-size:0.78rem;color:var(--neutral-500);">All-time total:</div>
                <div style="font-size:0.78rem;font-weight:700;color:var(--neutral-800);"><?php echo number_format($allTimeStats['copies']??0); ?> copies</div>
                <div style="font-size:0.78rem;font-weight:700;color:#ec4899;"><?php echo number_format($allTimeStats['souls']??0); ?> souls</div>
                <div style="font-size:0.78rem;font-weight:700;color:#0d9488;"><?php echo number_format($allTimeStats['partners']??0); ?> partners</div>
                <div style="font-size:0.78rem;font-weight:700;color:#6366f1;"><?php echo number_format($allTimeStats['churches']??0); ?> churches</div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php elseif (!$isPrivileged && empty($timelineRows)): ?>
    <div class="an-section">
        <div class="an-section-body">
            <div class="an-success-state">
                <div class="an-ss-icon"><i class="fas fa-file-alt" style="color:var(--neutral-300);"></i></div>
                <h3>No Reports Yet</h3>
                <p>Submit your first monthly report to start building your performance timeline.</p>
                <a href="<?php echo BASE_URL; ?>/pages/reports/create.php" class="modern-button modern-button-primary" style="font-size:0.8rem;margin-top:0.75rem;"><i class="fas fa-plus modern-button-icon"></i>Submit First Report</a>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($isPrivileged): ?>
    <!-- ══ Compliance Leaderboard ══ -->
    <div class="an-section">
        <div class="an-section-head">
            <h2><i class="fas fa-trophy" style="color:#f59e0b;"></i> Compliance Leaderboard — <?php echo $filterYear; ?></h2>
            <span style="font-size:0.75rem;color:var(--neutral-400);"><?php echo count($leaderboard); ?> zones · <?php echo $filterYear==(int)date('Y')?(int)date('n'):12; ?> months expected</span>
        </div>
        <div class="an-section-body" style="padding:0;">
            <?php if (empty($leaderboard)): ?>
            <div style="padding:2rem;text-align:center;color:var(--neutral-400);font-size:0.8rem;">No data for the selected filters.</div>
            <?php else: ?>
            <div style="overflow-x:auto;">
            <table class="an-lb">
                <thead>
                    <tr>
                        <th style="width:2.5rem;">#</th>
                        <th>Zone</th>
                        <th>Region</th>
                        <th>Months</th>
                        <th style="min-width:130px;">Compliance</th>
                        <th>Copies</th>
                        <th>Souls</th>
                        <th>Last Submit</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($leaderboard as $i => $lb):
                    $rateColor = $lb['rate'] >= 80 ? '#10b981' : ($lb['rate'] >= 50 ? '#f59e0b' : '#ef4444');
                    $rankClass = $i===0?'r1':($i===1?'r2':($i===2?'r3':''));
                ?>
                <tr>
                    <td><span class="an-rank <?php echo $rankClass; ?>"><?php echo $i+1; ?></span></td>
                    <td style="font-weight:600;color:var(--neutral-900);"><?php echo htmlspecialchars($lb['zone']); ?></td>
                    <td style="font-size:0.72rem;color:var(--neutral-500);"><?php echo htmlspecialchars($lb['region'] ?? '—'); ?></td>
                    <td style="font-weight:600;"><?php echo (int)$lb['months_submitted']; ?> / <?php echo (int)$lb['expected']; ?></td>
                    <td>
                        <div class="an-rate-bar">
                            <div class="an-rate-track"><div class="an-rate-fill" style="width:<?php echo $lb['rate']; ?>%;background:<?php echo $rateColor; ?>;"></div></div>
                            <span style="font-size:0.72rem;font-weight:700;color:<?php echo $rateColor; ?>;white-space:nowrap;"><?php echo $lb['rate']; ?>%</span>
                        </div>
                    </td>
                    <td><?php echo number_format($lb['copies']); ?></td>
                    <td><?php echo number_format($lb['souls']); ?></td>
                    <td style="font-size:0.72rem;color:var(--neutral-500);"><?php echo $lb['last_submission'] ? date('d M Y', strtotime($lb['last_submission'])) : '—'; ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ══ Region Comparison + Top Users ══ -->
    <div class="an-two">
        <!-- Region comparison -->
        <div class="an-section" style="margin-bottom:0;">
            <div class="an-section-head">
                <h2><i class="fas fa-map-marked-alt" style="color:#6366f1;"></i> Region Comparison</h2>
            </div>
            <div class="an-section-body">
                <?php if (empty($regionRows)): ?>
                <p style="font-size:0.8rem;color:var(--neutral-400);text-align:center;padding:1rem 0;">No data for selected filters.</p>
                <?php else: ?>
                <div class="an-chart-wrap-sm" style="margin-bottom:1rem;">
                    <canvas id="regionChart"></canvas>
                </div>
                <div class="an-region-grid">
                    <?php foreach ($regionRows as $rr): ?>
                    <div class="an-region-card">
                        <h4><?php echo htmlspecialchars($rr['region']); ?></h4>
                        <div class="an-region-stat"><span>Reports</span><strong><?php echo number_format($rr['reports']); ?></strong></div>
                        <div class="an-region-stat"><span>Copies</span><strong><?php echo number_format($rr['copies']); ?></strong></div>
                        <div class="an-region-stat"><span>Souls Won</span><strong><?php echo number_format($rr['souls']); ?></strong></div>
                        <div class="an-region-stat"><span>New Partners</span><strong><?php echo number_format($rr['partners']); ?></strong></div>
                        <div class="an-region-stat"><span>New Churches</span><strong><?php echo number_format($rr['churches']); ?></strong></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Top users -->
        <div class="an-section" style="margin-bottom:0;">
            <div class="an-section-head">
                <h2><i class="fas fa-users" style="color:#0ea5e9;"></i> Top Performers</h2>
            </div>
            <div class="an-section-body" style="padding:0;">
                <?php if (empty($topUsers)): ?>
                <p style="font-size:0.8rem;color:var(--neutral-400);text-align:center;padding:2rem;">No data for selected filters.</p>
                <?php else: ?>
                <table class="an-lb">
                    <thead><tr><th>#</th><th>Name</th><th>Zone</th><th>Copies</th><th>Souls</th></tr></thead>
                    <tbody>
                    <?php foreach ($topUsers as $ti => $tu):
                        $rc = $ti===0?'r1':($ti===1?'r2':($ti===2?'r3':''));
                    ?>
                    <tr>
                        <td><span class="an-rank <?php echo $rc; ?>"><?php echo $ti+1; ?></span></td>
                        <td style="font-weight:600;font-size:0.8rem;"><?php echo htmlspecialchars($tu['name'] ?? '—'); ?></td>
                        <td style="font-size:0.72rem;color:var(--neutral-500);"><?php echo htmlspecialchars($tu['zone'] ?? '—'); ?></td>
                        <td><?php echo number_format($tu['copies']); ?></td>
                        <td><?php echo number_format($tu['souls']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($filterRegion && !empty($zoneCompare)): ?>
    <!-- ══ Zone comparison within region ══ -->
    <div class="an-section">
        <div class="an-section-head">
            <h2><i class="fas fa-layer-group" style="color:#0d9488;"></i> Zone Breakdown — <?php echo htmlspecialchars($filterRegion); ?></h2>
        </div>
        <div class="an-section-body">
            <div class="an-chart-wrap">
                <canvas id="zoneChart"></canvas>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; /* isPrivileged */ ?>

</div><!-- /an-page -->

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function() {
var trendLabels   = <?php echo json_encode($trendLabels); ?>;
var trendCopies   = <?php echo json_encode($trendCopies); ?>;
var trendSouls    = <?php echo json_encode($trendSouls); ?>;
var trendPartners = <?php echo json_encode($trendPartners); ?>;
var trendChurches = <?php echo json_encode($trendChurches); ?>;

var chartColors = {
    copies:   {border:'#0ea5e9', bg:'rgba(14,165,233,0.10)'},
    souls:    {border:'#ec4899', bg:'rgba(236,72,153,0.10)'},
    partners: {border:'#0d9488', bg:'rgba(13,148,136,0.10)'},
    churches: {border:'#6366f1', bg:'rgba(99,102,241,0.10)'}
};
var metricData = {
    copies:   {label:'Total Copies Sponsored',  data:trendCopies},
    souls:    {label:'Souls Won',                data:trendSouls},
    partners: {label:'New Partners',             data:trendPartners},
    churches: {label:'New Churches',             data:trendChurches}
};

var activeMetric = 'copies';
var trendCtx = document.getElementById('trendChart');
var trendChart = null;
if (trendCtx) {
    trendChart = new Chart(trendCtx, {
        type: 'line',
        data: {
            labels: trendLabels,
            datasets: [{
                label: 'Total Copies Sponsored',
                data:  trendCopies,
                borderColor: chartColors.copies.border,
                backgroundColor: chartColors.copies.bg,
                tension: 0.35,
                fill: true,
                pointRadius: 4,
                pointHoverRadius: 6,
                borderWidth: 2.5
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: false }, tooltip: { callbacks: {
                label: function(ctx) { return ' ' + ctx.dataset.label + ': ' + ctx.parsed.y.toLocaleString(); }
            }}},
            scales: {
                x: { grid: { display:false }, ticks: { font:{size:11} } },
                y: { beginAtZero:true, grid: { color:'rgba(0,0,0,0.04)' }, ticks: { font:{size:11}, callback: function(v){ return v>=1000?(v/1000).toFixed(1)+'k':v; }}}
            }
        }
    });
}

window.switchChart = function(metric) {
    if (!trendChart) return;
    activeMetric = metric;
    var m = metricData[metric];
    var c = chartColors[metric];
    trendChart.data.datasets[0].label = m.label;
    trendChart.data.datasets[0].data  = m.data;
    trendChart.data.datasets[0].borderColor = c.border;
    trendChart.data.datasets[0].backgroundColor = c.bg;
    trendChart.update('active');
    document.querySelectorAll('.an-chart-btn').forEach(function(b) {
        b.classList.toggle('active', b.dataset.metric === metric);
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
        data: {
            labels: <?php echo json_encode($tlLabels); ?>,
            datasets: [
                {
                    label: 'Copies Sponsored',
                    data:  <?php echo json_encode($tlCopies); ?>,
                    backgroundColor: 'rgba(14,165,233,0.6)',
                    borderColor: '#0ea5e9',
                    borderWidth: 1,
                    borderRadius: 4,
                    yAxisID: 'y'
                },
                {
                    label: 'Souls Won',
                    data:  <?php echo json_encode($tlSouls); ?>,
                    backgroundColor: 'rgba(236,72,153,0.6)',
                    borderColor: '#ec4899',
                    borderWidth: 1,
                    borderRadius: 4,
                    yAxisID: 'y1'
                }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { position:'top', labels:{font:{size:11}} } },
            scales: {
                x: { grid:{display:false}, ticks:{font:{size:10}} },
                y:  { position:'left',  beginAtZero:true, grid:{color:'rgba(0,0,0,0.04)'}, ticks:{font:{size:10}} },
                y1: { position:'right', beginAtZero:true, grid:{display:false},             ticks:{font:{size:10}} }
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
        data: {
            labels: <?php echo json_encode($rcLabels); ?>,
            datasets: [
                { label:'Copies Sponsored', data:<?php echo json_encode($rcCopies); ?>, backgroundColor:'rgba(14,165,233,0.65)', borderColor:'#0ea5e9', borderWidth:1, borderRadius:4 },
                { label:'Souls Won',        data:<?php echo json_encode($rcSouls); ?>,  backgroundColor:'rgba(236,72,153,0.65)', borderColor:'#ec4899', borderWidth:1, borderRadius:4 }
            ]
        },
        options: {
            responsive:true, maintainAspectRatio:false,
            plugins:{ legend:{ position:'top', labels:{font:{size:11}} } },
            scales: {
                x:{ grid:{display:false}, ticks:{font:{size:10}} },
                y:{ beginAtZero:true, grid:{color:'rgba(0,0,0,0.04)'}, ticks:{font:{size:10}, callback:function(v){return v>=1000?(v/1000).toFixed(1)+'k':v;}} }
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
        data: {
            labels: <?php echo json_encode($zcLabels); ?>,
            datasets: [
                { label:'Copies Sponsored', data:<?php echo json_encode($zcCopies); ?>, backgroundColor:'rgba(99,102,241,0.65)', borderColor:'#6366f1', borderWidth:1, borderRadius:4 },
                { label:'Souls Won',        data:<?php echo json_encode($zcSouls); ?>,  backgroundColor:'rgba(13,148,136,0.65)', borderColor:'#0d9488', borderWidth:1, borderRadius:4 }
            ]
        },
        options: {
            responsive:true, maintainAspectRatio:false,
            plugins:{ legend:{position:'top', labels:{font:{size:11}}} },
            scales: {
                x:{ grid:{display:false}, ticks:{font:{size:9},maxRotation:35} },
                y:{ beginAtZero:true, grid:{color:'rgba(0,0,0,0.04)'}, ticks:{font:{size:10}, callback:function(v){return v>=1000?(v/1000).toFixed(1)+'k':v;}} }
            }
        }
    });
}
<?php endif; ?>

})();
</script>

<?php require_once '../layouts/footer.php'; ?>
