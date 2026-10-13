<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ini_set('error_log', 'error.log');

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

if (!isset($user['role']) || $user['role'] === 'user' || $user['role'] === 'admin-manager') {
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

if (!isset($user['role']) || $user['role'] === 'user') {
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

if (!isset($user['role']) || $user['role'] === 'user' || $user['role'] === 'admin-manager') {
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

// Include header
require_once 'layouts/header.php';
?>

<style>
/* ── Dashboard ── */
.db-page { max-width: 1060px; margin: 0 auto; padding: 2rem 1.25rem 3rem; }

/* Top bar */
.db-topbar { display:flex; align-items:flex-end; justify-content:space-between; flex-wrap:wrap; gap:0.75rem; margin-bottom:1.75rem; }
.db-topbar-left h1 { font-size:1.2rem; font-weight:700; color:var(--neutral-900); margin:0 0 0.15rem; }
.db-topbar-left p  { font-size:0.8rem; color:var(--neutral-500); margin:0; }
.db-topbar-actions { display:flex; gap:0.5rem; flex-wrap:wrap; }

/* ── Notification banners ── */
.db-banner { display:flex; align-items:center; gap:0.75rem; padding:0.75rem 1.1rem; border-radius:var(--radius-md); font-size:0.8125rem; margin-bottom:0.6rem; border:1px solid; }
.db-banner-danger  { background:var(--danger-50);  border-color:var(--danger-200);  color:var(--danger-800); }
.db-banner-warning { background:var(--warning-50); border-color:var(--warning-200); color:var(--warning-800); }
.db-banner-info    { background:var(--primary-50); border-color:var(--primary-200); color:var(--primary-800); }
.db-banner-success { background:var(--success-50); border-color:var(--success-200); color:var(--success-800); }
.db-banner-icon { font-size:0.85rem; flex-shrink:0; }
.db-banner-text { flex:1; }
.db-banner-text strong { font-weight:600; }
.db-banner-text span { opacity:0.8; }
.db-banner-action { font-size:0.78rem; font-weight:600; color:inherit; text-decoration:none; white-space:nowrap; border:1px solid currentColor; border-radius:var(--radius-sm); padding:0.2rem 0.6rem; opacity:0.85; }
.db-banner-action:hover { opacity:1; }

/* ── Control Center (What To Do Now) ── */
.db-control { border:1px solid var(--neutral-200); border-radius:var(--radius-lg); background:#fff; overflow:hidden; margin-bottom:1.5rem; }
.db-control-head { padding:0.85rem 1.25rem; border-bottom:1px solid var(--neutral-200); display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:0.5rem; }
.db-control-head h2 { font-size:0.8125rem; font-weight:700; color:var(--neutral-900); margin:0; text-transform:uppercase; letter-spacing:0.05em; }
.db-control-body { padding:1.1rem 1.25rem; display:flex; flex-direction:column; gap:0.6rem; }

/* Status row inside control center */
.db-status-row { display:flex; align-items:center; gap:0.875rem; padding:0.75rem 1rem; border-radius:var(--radius-md); border:1px solid var(--neutral-200); background:var(--neutral-50); }
.db-status-row.done   { background:var(--success-50);  border-color:var(--success-200); }
.db-status-row.overdue{ background:var(--danger-50);   border-color:var(--danger-200); }
.db-status-row.due    { background:var(--warning-50);  border-color:var(--warning-200); }
.db-status-dot { width:0.55rem; height:0.55rem; border-radius:50%; flex-shrink:0; }
.db-status-row.done    .db-status-dot { background:var(--success-500); }
.db-status-row.overdue .db-status-dot { background:var(--danger-500); }
.db-status-row.due     .db-status-dot { background:var(--warning-500); }
.db-status-row         .db-status-dot { background:var(--neutral-400); }
.db-status-main { flex:1; }
.db-status-main strong { font-size:0.85rem; font-weight:600; color:var(--neutral-900); display:block; }
.db-status-main span   { font-size:0.775rem; color:var(--neutral-500); }
.db-status-action { font-size:0.78rem; font-weight:600; white-space:nowrap; }

/* ── Quick links strip ── */
.db-quicklinks { display:grid; grid-template-columns:repeat(4,1fr); gap:0.75rem; margin-bottom:1.5rem; }
@media(max-width:768px){ .db-quicklinks { grid-template-columns:repeat(2,1fr); } }
@media(max-width:400px){ .db-quicklinks { grid-template-columns:1fr 1fr; } }
.db-ql { display:flex; flex-direction:column; align-items:flex-start; gap:0.3rem; padding:0.9rem 1rem; border:1px solid var(--neutral-200); border-radius:var(--radius-lg); background:#fff; text-decoration:none; transition:border-color 0.15s, box-shadow 0.15s; }
.db-ql:hover { border-color:var(--primary-300); box-shadow:0 0 0 3px var(--primary-50); }
.db-ql-icon { font-size:1rem; color:var(--primary-600); }
.db-ql-label { font-size:0.8125rem; font-weight:600; color:var(--neutral-900); }
.db-ql-sub   { font-size:0.72rem; color:var(--neutral-500); }

/* ── Two-column layout ── */
.db-grid { display:grid; grid-template-columns:1fr 300px; gap:1.25rem; margin-bottom:1.5rem; }
@media(max-width:860px){ .db-grid { grid-template-columns:1fr; } }

/* ── Section card ── */
.db-section { border:1px solid var(--neutral-200); border-radius:var(--radius-lg); background:#fff; overflow:hidden; margin-bottom:1.25rem; }
.db-section:last-child { margin-bottom:0; }
.db-section-head { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:0.5rem; padding:0.85rem 1.25rem; border-bottom:1px solid var(--neutral-200); }
.db-section-head h2 { font-size:0.8125rem; font-weight:700; color:var(--neutral-900); margin:0; text-transform:uppercase; letter-spacing:0.05em; }

/* Table tweaks */
.db-section .modern-table th { font-size:0.7rem; padding:0.6rem 1rem; }
.db-section .modern-table td { font-size:0.8125rem; padding:0.7rem 1rem; color:var(--neutral-700); }

/* ── Stat grid ── */
.db-stats { display:grid; grid-template-columns:repeat(2,1fr); gap:1px; background:var(--neutral-200); border:1px solid var(--neutral-200); border-radius:var(--radius-lg); overflow:hidden; margin-bottom:1.5rem; }
@media(min-width:640px)  { .db-stats { grid-template-columns:repeat(3,1fr); } }
@media(min-width:1024px) { .db-stats { grid-template-columns:repeat(4,1fr); } }
.db-stat { background:#fff; padding:1.1rem 1.25rem; display:flex; flex-direction:column; gap:0.2rem; }
.db-stat-label { font-size:0.7rem; font-weight:500; color:var(--neutral-400); text-transform:uppercase; letter-spacing:0.04em; }
.db-stat-value { font-size:1.4rem; font-weight:700; color:var(--neutral-900); line-height:1.2; }
.db-stat-link  { font-size:0.72rem; color:var(--primary-600); margin-top:0.1rem; }
.db-stat-link:hover { text-decoration:underline; }

/* ── Progress / sidebar ── */
.db-prog-item { display:flex; align-items:center; justify-content:space-between; padding:0.7rem 1.25rem; border-bottom:1px solid var(--neutral-100); font-size:0.82rem; }
.db-prog-item:last-child { border-bottom:none; }
.db-prog-label { color:var(--neutral-500); }
.db-prog-value { font-weight:700; color:var(--neutral-900); }
.db-prog-value.good    { color:var(--success-600); }
.db-prog-value.warn    { color:var(--warning-600); }
.db-prog-value.primary { color:var(--primary-600); }

/* ── Pending alert list ── */
.db-alert { border:1px solid var(--danger-100); border-left:3px solid var(--danger-500); border-radius:var(--radius-lg); background:#fff; overflow:hidden; margin-bottom:1.25rem; }
.db-alert-head { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:0.75rem; padding:0.85rem 1.25rem; border-bottom:1px solid var(--danger-100); background:var(--danger-50); }
.db-alert-head-left strong { font-size:0.875rem; font-weight:700; color:var(--danger-700); display:block; }
.db-alert-head-left span   { font-size:0.775rem; color:var(--neutral-500); }
.db-alert-row { display:flex; align-items:center; justify-content:space-between; padding:0.7rem 1.25rem; border-bottom:1px solid var(--neutral-100); font-size:0.8125rem; }
.db-alert-row:last-child { border-bottom:none; }
.db-alert-row-left { display:flex; align-items:center; gap:0.6rem; flex-wrap:wrap; }
.db-month-tag { font-size:0.72rem; font-weight:600; padding:0.2rem 0.5rem; border-radius:var(--radius-sm); background:var(--neutral-100); color:var(--neutral-700); border:1px solid var(--neutral-200); }
.db-month-tag.is-current { background:var(--primary-50); color:var(--primary-700); border-color:var(--primary-200); }
.db-late-tag { font-size:0.7rem; font-weight:600; color:var(--danger-700); display:inline-flex; align-items:center; gap:0.2rem; }
.db-due-tag  { font-size:0.7rem; font-weight:600; color:var(--warning-700); display:inline-flex; align-items:center; gap:0.2rem; }

@media(max-width:480px) {
    .db-page { padding:1.1rem 0.85rem 2.5rem; }
    .db-stat-value { font-size:1.2rem; }
    .db-topbar { flex-direction:column; align-items:flex-start; }
}

/* ── Streak hero ── */
.db-streak { display:flex; align-items:center; gap:1rem; padding:0.9rem 1.1rem; border-radius:var(--radius-lg); border:1px solid #fed7aa; background:linear-gradient(135deg,#fff7ed,#fef3c7); margin-bottom:1rem; }
.db-streak-icon { font-size:1.8rem; line-height:1; flex-shrink:0; }
.db-streak-body { flex:1; }
.db-streak-label { font-size:0.68rem; font-weight:700; color:var(--warning-700); text-transform:uppercase; letter-spacing:0.05em; }
.db-streak-value { font-size:1.5rem; font-weight:800; color:var(--warning-600); line-height:1.15; }
.db-streak-desc  { font-size:0.72rem; color:var(--neutral-500); margin-top:0.1rem; }
.db-streak.zero  { background:var(--neutral-50); border-color:var(--neutral-200); }
.db-streak.zero .db-streak-icon { opacity:0.25; }

/* ── Badges strip ── */
.db-badges { display:flex; flex-wrap:wrap; gap:0.5rem; padding:0.9rem 1.1rem; }
.db-badge { display:inline-flex; align-items:center; gap:0.4rem; padding:0.3rem 0.65rem 0.3rem 0.4rem; border-radius:9999px; border:1px solid; font-size:0.72rem; font-weight:600; white-space:nowrap; cursor:default; }
.db-badge-icon { width:1.25rem; height:1.25rem; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:0.6rem; flex-shrink:0; }
.db-badges-empty { padding:0.9rem 1.1rem; font-size:0.8rem; color:var(--neutral-400); }

/* ── All-time highlights strip ── */
.db-highlights { display:grid; grid-template-columns:repeat(4,1fr); gap:1px; background:var(--neutral-200); border-radius:var(--radius-lg); overflow:hidden; border:1px solid var(--neutral-200); margin-bottom:1rem; }
@media(max-width:500px){ .db-highlights { grid-template-columns:repeat(2,1fr); } }
.db-hl-cell { background:#fff; padding:0.65rem 0.9rem; }
.db-hl-lbl { font-size:0.65rem; font-weight:600; color:var(--neutral-400); text-transform:uppercase; letter-spacing:0.04em; }
.db-hl-val { font-size:1rem; font-weight:700; color:var(--neutral-900); line-height:1.3; }
</style>

<div class="db-page">

    <!-- ── Top bar ── -->
    <div class="db-topbar">
        <div class="db-topbar-left">
            <h1>Welcome back, <?php echo htmlspecialchars($user['name'] ?? $user['username']); ?></h1>
            <p>
                <?php echo htmlspecialchars($user['zone'] ?? 'No zone'); ?>
                <?php if (!empty($user['region'])): ?>&nbsp;&middot;&nbsp;<?php echo htmlspecialchars($user['region']); ?><?php endif; ?>
                &nbsp;&middot;&nbsp;<?php echo date('l, F j Y'); ?>
            </p>
        </div>
        <div class="db-topbar-actions">
            <?php if ($user['role'] !== 'admin-manager'): ?>
            <a href="pages/reports/create.php" class="modern-button modern-button-primary" style="font-size:0.8125rem;"><i class="fas fa-plus modern-button-icon"></i> Submit Report</a>
            <?php endif; ?>
            <a href="pages/reports/list.php" class="modern-button modern-button-secondary" style="font-size:0.8125rem;"><i class="fas fa-list modern-button-icon"></i> All Reports</a>
            <?php if ($user['role'] === 'admin'): ?>
            <a href="admin/dashboard.php" class="modern-button modern-button-secondary" style="font-size:0.8125rem;"><i class="fas fa-chart-bar modern-button-icon"></i> Analytics</a>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── Notification banners ── -->
    <?php if (isset($user['active']) && !(int)$user['active']): ?>
    <div class="db-banner db-banner-warning">
        <i class="fas fa-hourglass-half db-banner-icon"></i>
        <div class="db-banner-text"><strong>Account Pending Approval</strong> <span>&mdash; Your account is waiting for admin approval. Some features may be limited until then.</span></div>
    </div>
    <?php endif; ?>

    <?php if ($overdueCount >= 3 && ($user['role'] === 'user' || !isset($user['role']))): ?>
    <div class="db-banner db-banner-danger">
        <i class="fas fa-exclamation-triangle db-banner-icon"></i>
        <div class="db-banner-text"><strong><?php echo $overdueCount; ?> overdue report<?php echo $overdueCount !== 1 ? 's' : ''; ?></strong> <span>&mdash; Your regional manager has been notified. Please submit your missing reports immediately.</span></div>
        <a href="pages/reports/create.php" class="db-banner-action">Submit Now</a>
    </div>
    <?php elseif ($overdueCount > 0 && ($user['role'] === 'user' || !isset($user['role']))): ?>
    <div class="db-banner db-banner-danger">
        <i class="fas fa-clock db-banner-icon"></i>
        <div class="db-banner-text"><strong><?php echo $overdueCount; ?> overdue report<?php echo $overdueCount !== 1 ? 's' : ''; ?></strong> <span>&mdash; Please catch up on your missing submissions.</span></div>
        <a href="pages/reports/create.php" class="db-banner-action">Submit Now</a>
    </div>
    <?php endif; ?>

    <?php if (!$currentMonthSubmitted && $overdueCount === 0 && ($user['role'] === 'user' || !isset($user['role']))): ?>
    <div class="db-banner db-banner-info">
        <i class="fas fa-calendar-check db-banner-icon"></i>
        <div class="db-banner-text"><strong><?php echo date('F Y'); ?> report not yet submitted</strong> <span>&mdash; <?php echo $daysLeftInMonth > 0 ? $daysLeftInMonth . ' day' . ($daysLeftInMonth !== 1 ? 's' : '') . ' left this month.' : 'Last day of the month &mdash; submit today!'; ?></span></div>
        <a href="pages/reports/create.php" class="db-banner-action">Submit</a>
    </div>
    <?php endif; ?>

    <?php if ($currentMonthSubmitted && ($user['role'] === 'user' || !isset($user['role']))): ?>
    <div class="db-banner db-banner-success">
        <i class="fas fa-check-circle db-banner-icon"></i>
        <div class="db-banner-text"><strong><?php echo date('F Y'); ?> report submitted</strong> <span>&mdash; You&rsquo;re all caught up for this month. Great work!</span></div>
        <?php if ($lastReportId): ?><a href="pages/reports/view.php?id=<?php echo $lastReportId; ?>" class="db-banner-action">View Report</a><?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ── What To Do Now (user only) ── -->
    <?php if ($user['role'] === 'user' || !isset($user['role'])): ?>
    <div class="db-control">
        <div class="db-control-head">
            <h2><i class="fas fa-bolt" style="margin-right:0.4rem;color:var(--primary-500);"></i>What To Do Now</h2>
            <span style="font-size:0.75rem;color:var(--neutral-400);"><?php echo date('F Y'); ?></span>
        </div>
        <div class="db-control-body">
            <?php
            // Current month status row
            if ($currentMonthSubmitted):
            ?>
            <div class="db-status-row done">
                <div class="db-status-dot"></div>
                <div class="db-status-main">
                    <strong>This month&rsquo;s report is submitted</strong>
                    <span>Nothing pending for <?php echo date('F Y'); ?>. Check back next month.</span>
                </div>
                <?php if ($lastReportId): ?>
                <a href="pages/reports/view.php?id=<?php echo $lastReportId; ?>" class="modern-table-action db-status-action">View &rarr;</a>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <div class="db-status-row <?php echo $currentMonthDaysLate > 0 ? 'overdue' : 'due'; ?>">
                <div class="db-status-dot"></div>
                <div class="db-status-main">
                    <strong>
                        <?php if ($currentMonthDaysLate > 0): ?>
                            <?php echo date('F Y'); ?> report is <?php echo $currentMonthDaysLate; ?> day<?php echo $currentMonthDaysLate !== 1 ? 's' : ''; ?> overdue
                        <?php else: ?>
                            Submit your <?php echo date('F Y'); ?> report
                        <?php endif; ?>
                    </strong>
                    <span>
                        <?php if ($daysLeftInMonth > 0): ?>
                            <?php echo $daysLeftInMonth; ?> day<?php echo $daysLeftInMonth !== 1 ? 's' : ''; ?> remaining this month &mdash; don&rsquo;t wait until the last day.
                        <?php else: ?>
                            Today is the last day of the month &mdash; submit now.
                        <?php endif; ?>
                    </span>
                </div>
                <a href="pages/reports/create.php" class="modern-button modern-button-primary db-status-action" style="font-size:0.78rem;padding:0.3rem 0.75rem;">Submit Now</a>
            </div>
            <?php endif; ?>

            <?php
            // Overdue past months
            $pastOverdue = array_filter($missingMonths, fn($m) => !$m['is_current'] && $m['days_late'] > 0);
            if (!empty($pastOverdue)):
            ?>
            <div class="db-status-row overdue">
                <div class="db-status-dot"></div>
                <div class="db-status-main">
                    <strong><?php echo count($pastOverdue); ?> past month<?php echo count($pastOverdue) !== 1 ? 's' : ''; ?> still missing</strong>
                    <span>Overdue reports affect your consistency record. Submit them below.</span>
                </div>
                <a href="#missing-months" class="modern-table-action db-status-action" style="font-size:0.78rem;">View &darr;</a>
            </div>
            <?php endif; ?>

            <?php if ($consistencyStreak > 0): ?>
            <div class="db-status-row done">
                <div class="db-status-dot"></div>
                <div class="db-status-main">
                    <strong><?php echo $consistencyStreak; ?>-month consistency streak</strong>
                    <span>Keep it going &mdash; don&rsquo;t break the chain!</span>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── Quick Links ── -->
    <div class="db-quicklinks">
        <?php if ($user['role'] !== 'admin-manager'): ?>
        <a href="pages/reports/create.php" class="db-ql">
            <span class="db-ql-icon"><i class="fas fa-plus-circle"></i></span>
            <span class="db-ql-label">Submit Report</span>
            <span class="db-ql-sub">For <?php echo date('F Y'); ?></span>
        </a>
        <?php if ($lastReportId): ?>
        <a href="pages/reports/view.php?id=<?php echo $lastReportId; ?>" class="db-ql">
            <span class="db-ql-icon"><i class="fas fa-file-alt"></i></span>
            <span class="db-ql-label">Last Report</span>
            <span class="db-ql-sub">View your most recent</span>
        </a>
        <?php endif; ?>
        <?php endif; ?>
        <a href="pages/reports/list.php" class="db-ql">
            <span class="db-ql-icon"><i class="fas fa-history"></i></span>
            <span class="db-ql-label">Report History</span>
            <span class="db-ql-sub"><?php echo $reportsCount; ?> submitted this year</span>
        </a>
        <a href="pages/analytics.php" class="db-ql">
            <span class="db-ql-icon"><i class="fas fa-chart-area" style="color:#6366f1;"></i></span>
            <span class="db-ql-label">Analytics</span>
            <span class="db-ql-sub"><?php echo $user['role'] === 'admin' || $user['role'] === 'admin-manager' ? 'Trends &amp; leaderboard' : 'My trends &amp; badges'; ?></span>
        </a>
        <a href="profile.php" class="db-ql">
            <span class="db-ql-icon"><i class="fas fa-user-circle"></i></span>
            <span class="db-ql-label">My Profile</span>
            <span class="db-ql-sub">Account &amp; settings</span>
        </a>
        <?php if ($user['role'] === 'admin' || $user['role'] === 'admin-manager'): ?>
        <a href="admin/users.php" class="db-ql">
            <span class="db-ql-icon"><i class="fas fa-users"></i></span>
            <span class="db-ql-label">Manage Users</span>
            <span class="db-ql-sub">Approve &amp; edit accounts</span>
        </a>
        <a href="admin/zone_compliance.php" class="db-ql">
            <span class="db-ql-icon"><i class="fas fa-map-marked-alt"></i></span>
            <span class="db-ql-label">Zone Compliance</span>
            <span class="db-ql-sub">Check who has reported</span>
        </a>
        <a href="admin/dashboard.php" class="db-ql">
            <span class="db-ql-icon"><i class="fas fa-chart-bar"></i></span>
            <span class="db-ql-label">Admin Statistics</span>
            <span class="db-ql-sub">Full org-wide stats</span>
        </a>
        <?php endif; ?>
    </div>

    <!-- ── Main body grid ── -->
    <div class="db-grid">

        <!-- Left column -->
        <div>
            <!-- Stat tiles -->
            <div class="db-stats">
                <div class="db-stat">
                    <span class="db-stat-label">Reports <?php echo date('Y'); ?></span>
                    <span class="db-stat-value"><?php echo $reportsCount; ?></span>
                    <a href="pages/reports/list.php" class="db-stat-link">View all &rarr;</a>
                </div>
                <div class="db-stat">
                    <span class="db-stat-label">Copies Sponsored</span>
                    <span class="db-stat-value"><?php echo number_format($totalCopies); ?></span>
                </div>
                <div class="db-stat">
                    <span class="db-stat-label">This Month</span>
                    <span class="db-stat-value"><?php echo number_format($monthlyTotal); ?></span>
                </div>
                <div class="db-stat">
                    <span class="db-stat-label">Distributions</span>
                    <span class="db-stat-value"><?php echo number_format($totalDistributions); ?></span>
                </div>
                <div class="db-stat">
                    <span class="db-stat-label">Souls Won</span>
                    <span class="db-stat-value"><?php echo number_format($totalSoulsWon); ?></span>
                </div>
                <div class="db-stat">
                    <span class="db-stat-label">New Partners</span>
                    <span class="db-stat-value"><?php echo number_format($totalNewPartners); ?></span>
                </div>
                <div class="db-stat">
                    <span class="db-stat-label">Rhapsody Cells</span>
                    <span class="db-stat-value"><?php echo number_format($totalRhapsodyCells); ?></span>
                </div>
                <div class="db-stat">
                    <span class="db-stat-label">New Churches</span>
                    <span class="db-stat-value"><?php echo number_format($totalNewChurches); ?></span>
                </div>
                <?php if ($user['role'] === 'admin'): ?>
                <div class="db-stat">
                    <span class="db-stat-label">Total Users</span>
                    <span class="db-stat-value"><?php
                        try { $stmt = $conn->query("SELECT COUNT(*) as count FROM users"); echo $stmt->fetch()['count']; }
                        catch (PDOException $e) { echo '0'; }
                    ?></span>
                    <a href="admin/users.php" class="db-stat-link">Manage &rarr;</a>
                </div>
                <?php endif; ?>
            </div>

            <!-- Missing months / pending alert -->
            <?php if (!empty($missingMonths)): ?>
            <div class="db-alert" id="missing-months">
                <div class="db-alert-head">
                    <div class="db-alert-head-left">
                        <strong><i class="fas fa-calendar-times" style="margin-right:0.35rem;font-size:0.8rem;"></i>Pending Reports</strong>
                        <span><?php echo count($missingMonths); ?> month<?php echo count($missingMonths) > 1 ? 's' : ''; ?> without a submission</span>
                    </div>
                    <a href="pages/reports/create.php" class="modern-button modern-button-primary" style="font-size:0.78rem;"><i class="fas fa-plus modern-button-icon"></i>Submit Now</a>
                </div>
                <?php foreach ($missingMonths as $mm): ?>
                <div class="db-alert-row">
                    <div class="db-alert-row-left">
                        <span class="db-month-tag <?php echo $mm['is_current'] ? 'is-current' : ''; ?>">
                            <?php echo htmlspecialchars($mm['label']); ?><?php if ($mm['is_current']): ?> &middot; Current<?php endif; ?>
                        </span>
                        <?php if ($mm['days_late'] > 0): ?>
                            <span class="db-late-tag"><i class="fas fa-clock"></i><?php echo $mm['days_late']; ?>d late</span>
                        <?php elseif ($mm['is_current']): ?>
                            <span class="db-due-tag"><i class="fas fa-clock"></i><?php echo $daysLeftInMonth; ?>d left</span>
                        <?php endif; ?>
                    </div>
                    <a href="pages/reports/create.php?month=<?php echo urlencode($mm['ym']); ?>" class="modern-table-action" style="font-size:0.78rem;">Submit &rarr;</a>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Recent Reports -->
            <div class="db-section">
                <div class="db-section-head">
                    <h2>Recent Reports</h2>
                    <?php if ($user['role'] !== 'admin' && $user['role'] !== 'admin-manager'): ?>
                    <a href="pages/reports/create.php" class="modern-button modern-button-primary" style="font-size:0.78rem;"><i class="fas fa-plus modern-button-icon"></i>Submit</a>
                    <?php endif; ?>
                </div>
                <div class="modern-table-container">
                    <table class="modern-table">
                        <thead>
                            <tr>
                                <th>Month</th>
                                <th>Copies</th>
                                <?php if ($user['role'] === 'admin'): ?><th>Submitted By</th><?php endif; ?>
                                <th style="text-align:right;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recentReports)): ?>
                            <tr>
                                <td colspan="<?php echo $user['role'] === 'admin' ? '4' : '3'; ?>" style="text-align:center;padding:2rem;color:var(--neutral-400);font-size:0.8125rem;">
                                    No reports yet.
                                    <?php if ($user['role'] !== 'admin' && $user['role'] !== 'admin-manager'): ?>
                                    <a href="pages/reports/create.php" class="modern-table-action" style="margin-left:0.25rem;">Submit your first &rarr;</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($recentReports as $report): ?>
                            <tr>
                                <td><?php echo date('M Y', strtotime($report['report_month'] ?? $report['created_at'])); ?></td>
                                <td><?php echo number_format($report['total_copies']); ?></td>
                                <?php if ($user['role'] === 'admin'): ?>
                                <td>
                                    <span style="font-weight:500;"><?php echo htmlspecialchars($report['name']); ?></span>
                                    <span style="display:block;font-size:0.72rem;color:var(--neutral-400);"><?php echo htmlspecialchars($report['zone'] ?? ''); ?></span>
                                </td>
                                <?php endif; ?>
                                <td style="text-align:right;">
                                    <a href="pages/reports/view.php?id=<?php echo $report['id']; ?>" class="modern-table-action">View</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Right column: Personal Progress -->
        <?php if ($user['role'] === 'user' || !isset($user['role'])): ?>
        <div>

            <!-- ── Streak hero ── -->
            <div class="db-streak <?php echo $consistencyStreak === 0 ? 'zero' : ''; ?>" style="margin-bottom:0.85rem;">
                <div class="db-streak-icon"><?php echo $consistencyStreak >= 12 ? '👑' : ($consistencyStreak >= 6 ? '🔥' : ($consistencyStreak >= 3 ? '⚡' : '📅')); ?></div>
                <div class="db-streak-body">
                    <div class="db-streak-label">Submission Streak</div>
                    <div class="db-streak-value"><?php echo $consistencyStreak; ?> month<?php echo $consistencyStreak !== 1 ? 's' : ''; ?></div>
                    <div class="db-streak-desc">
                        <?php if ($consistencyStreak === 0): ?>Start submitting monthly to build a streak.
                        <?php elseif ($consistencyStreak < 3): ?>Keep going — <?php echo 3 - $consistencyStreak; ?> more month<?php echo (3-$consistencyStreak)!==1?'s':''; ?> to earn On Fire badge.
                        <?php elseif ($consistencyStreak < 6): ?>Great work! <?php echo 6 - $consistencyStreak; ?> more to Unstoppable.
                        <?php elseif ($consistencyStreak < 12): ?><?php echo 12 - $consistencyStreak; ?> more months to Legendary status!
                        <?php else: ?>Legendary <?php echo $consistencyStreak; ?>-month streak. Keep going!
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- ── All-time highlights ── -->
            <?php if ($allTimeTotal > 0): ?>
            <div class="db-highlights" style="margin-bottom:0.85rem;">
                <div class="db-hl-cell">
                    <div class="db-hl-lbl">All-time Reports</div>
                    <div class="db-hl-val"><?php echo number_format($allTimeTotal); ?></div>
                </div>
                <div class="db-hl-cell">
                    <div class="db-hl-lbl">Copies All-time</div>
                    <div class="db-hl-val"><?php echo $allTimeCopies >= 1000 ? round($allTimeCopies/1000,1).'k' : number_format($allTimeCopies); ?></div>
                </div>
                <div class="db-hl-cell">
                    <div class="db-hl-lbl">Souls Won</div>
                    <div class="db-hl-val" style="color:#ec4899;"><?php echo number_format($allTimeSouls); ?></div>
                </div>
                <div class="db-hl-cell">
                    <div class="db-hl-lbl">New Partners</div>
                    <div class="db-hl-val" style="color:#0d9488;"><?php echo number_format($allTimePartners); ?></div>
                </div>
            </div>
            <?php endif; ?>

            <!-- ── Badges ── -->
            <?php if (!empty($dbBadges)): ?>
            <div class="db-section" style="margin-bottom:0.85rem;">
                <div class="db-section-head">
                    <h2>My Badges</h2>
                    <a href="pages/analytics.php" style="font-size:0.72rem;color:var(--primary-600);text-decoration:none;">View all &rarr;</a>
                </div>
                <div class="db-badges">
                    <?php foreach ($dbBadges as $badge): ?>
                    <span class="db-badge" style="border-color:<?php echo $badge['color']; ?>30;color:<?php echo $badge['color']; ?>;background:<?php echo $badge['color']; ?>0d;" title="<?php echo htmlspecialchars($badge['tip']); ?>">
                        <span class="db-badge-icon" style="background:<?php echo $badge['color']; ?>20;color:<?php echo $badge['color']; ?>;"><i class="fas <?php echo $badge['icon']; ?>"></i></span>
                        <?php echo htmlspecialchars($badge['label']); ?>
                    </span>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- ── Progress rows ── -->
            <div class="db-section" style="margin-bottom:0.85rem;">
                <div class="db-section-head"><h2>This Year</h2></div>

                <div class="db-prog-item">
                    <span class="db-prog-label">Reports submitted</span>
                    <span class="db-prog-value primary"><?php echo $reportsCount; ?> / <?php echo $totalMonthsExpected ?: '—'; ?></span>
                </div>
                <div class="db-prog-item">
                    <span class="db-prog-label">Overdue months</span>
                    <span class="db-prog-value <?php echo $overdueCount > 0 ? 'warn' : 'good'; ?>">
                        <?php echo $overdueCount > 0 ? $overdueCount . ' overdue' : 'None ✓'; ?>
                    </span>
                </div>
                <div class="db-prog-item">
                    <span class="db-prog-label">Days left this month</span>
                    <span class="db-prog-value <?php echo $daysLeftInMonth <= 5 ? 'warn' : 'primary'; ?>">
                        <?php echo $daysLeftInMonth; ?> day<?php echo $daysLeftInMonth !== 1 ? 's' : ''; ?>
                    </span>
                </div>
                <?php if ($bestMonthLabel): ?>
                <div class="db-prog-item">
                    <span class="db-prog-label">Best month</span>
                    <span class="db-prog-value good" style="text-align:right;max-width:55%;">
                        <?php echo htmlspecialchars($bestMonthLabel); ?>
                        <span style="display:block;font-size:0.7rem;font-weight:400;color:var(--neutral-400);"><?php echo number_format($bestMonthValue); ?> copies</span>
                    </span>
                </div>
                <?php endif; ?>
                <div class="db-prog-item">
                    <span class="db-prog-label">This month</span>
                    <span class="db-prog-value <?php echo $currentMonthSubmitted ? 'good' : ($currentMonthDaysLate > 0 ? 'warn' : 'primary'); ?>">
                        <?php echo $currentMonthSubmitted ? 'Submitted ✓' : ($currentMonthDaysLate > 0 ? $currentMonthDaysLate . 'd late' : 'Pending'); ?>
                    </span>
                </div>
                <div style="padding:0.75rem 1.25rem;border-top:1px solid var(--neutral-100);">
                    <a href="pages/analytics.php" class="modern-button modern-button-secondary" style="font-size:0.78rem;width:100%;justify-content:center;"><i class="fas fa-chart-area modern-button-icon"></i>View Full Analytics</a>
                </div>
            </div>

            <!-- ── Accountability ── -->
            <div class="db-section">
                <div class="db-section-head"><h2>Accountability</h2></div>
                <div style="padding:1rem 1.25rem;font-size:0.8rem;color:var(--neutral-600);line-height:1.6;">
                    <?php if ($overdueCount >= 3): ?>
                    <p style="margin:0 0 0.5rem;color:var(--danger-700);font-weight:600;"><i class="fas fa-exclamation-triangle" style="margin-right:0.35rem;"></i>Escalation Notice</p>
                    <p style="margin:0 0 0.75rem;">You have <?php echo $overdueCount; ?> overdue reports. Your regional manager has been notified. Please submit your missing reports immediately.</p>
                    <?php elseif ($overdueCount > 0): ?>
                    <p style="margin:0 0 0.5rem;color:var(--warning-700);font-weight:600;"><i class="fas fa-bell" style="margin-right:0.35rem;"></i>Reminder</p>
                    <p style="margin:0 0 0.75rem;">You have <?php echo $overdueCount; ?> overdue report<?php echo $overdueCount !== 1 ? 's' : ''; ?>. Submit them to stay compliant and protect your streak.</p>
                    <?php elseif (!$currentMonthSubmitted): ?>
                    <p style="margin:0 0 0.5rem;color:var(--primary-700);font-weight:600;"><i class="fas fa-calendar-check" style="margin-right:0.35rem;"></i>Monthly Reminder</p>
                    <p style="margin:0 0 0.75rem;">Your <?php echo date('F'); ?> report is due. You have <?php echo $daysLeftInMonth; ?> day<?php echo $daysLeftInMonth !== 1 ? 's' : ''; ?> left &mdash; submit before the end of the month to keep your streak alive.</p>
                    <?php else: ?>
                    <p style="margin:0 0 0.5rem;color:var(--success-700);font-weight:600;"><i class="fas fa-check-circle" style="margin-right:0.35rem;"></i>You Are Up to Date</p>
                    <p style="margin:0 0 0.75rem;">No pending reports. Keep the momentum &mdash; your next report is due <?php echo date('F Y', strtotime('first day of next month')); ?>.</p>
                    <?php endif; ?>
                    <?php if ($consistencyStreak >= 3): ?>
                    <p style="margin:0;background:var(--success-50);border:1px solid var(--success-200);color:var(--success-800);padding:0.5rem 0.75rem;border-radius:var(--radius-sm);font-size:0.775rem;">
                        <i class="fas fa-fire" style="color:var(--warning-500);margin-right:0.3rem;"></i>
                        <?php if ($consistencyStreak >= 6): ?>You&rsquo;ve submitted <?php echo $consistencyStreak; ?> months in a row &mdash; incredible consistency!
                        <?php else: ?><?php echo $consistencyStreak; ?>-month streak! Don&rsquo;t break the chain.
                        <?php endif; ?>
                    </p>
                    <?php endif; ?>
                </div>
            </div>

        </div>
        <?php else: ?>
        <!-- Admin right column -->
        <div>
            <div class="db-section">
                <div class="db-section-head"><h2>At a Glance</h2></div>
                <div class="db-prog-item">
                    <span class="db-prog-label">Reports <?php echo date('Y'); ?></span>
                    <span class="db-prog-value primary"><?php echo $reportsCount; ?></span>
                </div>
                <div class="db-prog-item">
                    <span class="db-prog-label">Souls Won</span>
                    <span class="db-prog-value good"><?php echo number_format($totalSoulsWon); ?></span>
                </div>
                <div class="db-prog-item">
                    <span class="db-prog-label">New Partners</span>
                    <span class="db-prog-value primary"><?php echo number_format($totalNewPartners); ?></span>
                </div>
                <div class="db-prog-item">
                    <span class="db-prog-label">Total Users</span>
                    <span class="db-prog-value"><?php
                        try { $stmt = $conn->query("SELECT COUNT(*) as count FROM users"); echo (int)$stmt->fetch()['count']; }
                        catch (PDOException $e) { echo '0'; }
                    ?></span>
                </div>
                <div style="padding:0.85rem 1.25rem;border-top:1px solid var(--neutral-100);display:flex;flex-direction:column;gap:0.4rem;">
                    <a href="pages/analytics.php" class="modern-button modern-button-primary" style="font-size:0.8rem;width:100%;justify-content:center;"><i class="fas fa-chart-area modern-button-icon"></i>Analytics &amp; Trends</a>
                    <a href="admin/dashboard.php" class="modern-button modern-button-secondary" style="font-size:0.8rem;width:100%;justify-content:center;"><i class="fas fa-chart-bar modern-button-icon"></i>Admin Statistics</a>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </div><!-- /db-grid -->

</div>

<script>
document.addEventListener('DOMContentLoaded', function() {

    // ── First-login welcome ──
    <?php if (isset($_SESSION['first_login']) && $_SESSION['first_login']): ?>
    Swal.fire({
        title: 'Welcome to the Reporting Portal!',
        html: '<p style="font-size:0.9rem;color:#4b5563;">You can now submit and track your monthly reports. Start by submitting your first report.</p>',
        icon: 'success',
        confirmButtonColor: 'var(--primary-600)',
        confirmButtonText: 'Get Started'
    });
    <?php unset($_SESSION['first_login']); ?>
    <?php endif; ?>

    <?php if ($user['role'] === 'user' || !isset($user['role'])): ?>
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
                title       = '⚠️ Escalation Notice';
                confirmText = 'Submit Reports Now';
                confirmColor = '#dc2626';
                bodyHtml = [
                    '<p style="font-size:0.875rem;margin-bottom:0.75rem;">',
                    'You have <strong>' + overdueCount + ' overdue reports</strong>. ',
                    'Your regional manager has been notified.',
                    '</p>',
                    '<div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:0.75rem 1rem;font-size:0.8rem;color:#991b1b;">',
                    '<i class="fas fa-exclamation-triangle" style="margin-right:0.4rem;"></i>',
                    'Continued non-compliance may result in escalation to your zone leader.',
                    '</div>'
                ].join('');

            } else if (overdueCount > 0 && !submitted) {
                // Multiple missing including current
                icon        = 'warning';
                title       = 'Reports Overdue';
                confirmText = 'Submit Now';
                confirmColor = '#d97706';
                bodyHtml = [
                    '<p style="font-size:0.875rem;margin-bottom:0.75rem;">',
                    'You have <strong>' + overdueCount + ' overdue report' + (overdueCount !== 1 ? 's' : '') + '</strong> ',
                    'including <strong>' + currentMonth + '</strong>.',
                    '</p>',
                    '<div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:0.6rem 1rem;font-size:0.8rem;color:#92400e;">',
                    '<i class="fas fa-clock" style="margin-right:0.35rem;"></i>',
                    daysLate > 0 ? (currentMonth + ' is ' + daysLate + ' day' + (daysLate !== 1 ? 's' : '') + ' overdue.') : (daysLeft + ' day' + (daysLeft !== 1 ? 's' : '') + ' left to submit ' + currentMonth + '.'),
                    '</div>'
                ].join('');

            } else if (overdueCount > 0 && submitted) {
                // Current done but past months missing
                icon        = 'warning';
                title       = 'Past Reports Missing';
                confirmText = 'Catch Up Now';
                confirmColor = '#d97706';
                bodyHtml = [
                    '<p style="font-size:0.875rem;margin-bottom:0.75rem;">',
                    'Great — your ' + currentMonth + ' report is in! But you still have ',
                    '<strong>' + overdueCount + ' overdue month' + (overdueCount !== 1 ? 's' : '') + '</strong> from earlier this year.',
                    '</p>',
                    '<div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:0.6rem 1rem;font-size:0.8rem;color:#92400e;">',
                    '<i class="fas fa-history" style="margin-right:0.35rem;"></i> Catch up now to protect your compliance record.',
                    '</div>'
                ].join('');

            } else {
                // Only current month missing, not yet overdue
                icon = daysLeft <= 5 ? 'warning' : 'info';
                title = daysLeft <= 5 ? '⏰ Last Chance — ' + currentMonth : currentMonth + ' Report Due';
                confirmText = 'Submit Now';
                confirmColor = daysLeft <= 5 ? '#d97706' : '#0284c7';
                bodyHtml = [
                    '<p style="font-size:0.875rem;margin-bottom:0.75rem;">',
                    'You haven\'t submitted your report for <strong>' + currentMonth + '</strong> yet.',
                    '</p>',
                    '<div style="background:' + (daysLeft <= 5 ? '#fffbeb;border-color:#fde68a;color:#92400e' : '#eff6ff;border-color:#bfdbfe;color:#1e40af') + ';border:1px solid;border-radius:8px;padding:0.6rem 1rem;font-size:0.8rem;">',
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
                cancelButtonColor: '#6b7280',
                footer: '<a href="' + historyUrl + '" style="font-size:0.75rem;color:#6b7280;">View all submitted reports</a>'
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
