<?php
require_once 'config/config.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

Auth::requireLogin();

$auth = new Auth();
$user = $auth->getUser($_SESSION['user_id']);
$_SESSION['user_data'] = $user;
$isAdmin        = Auth::isAdmin();
$isAdminManager = Auth::isAdminManager();
$scopedRegion   = ($isAdminManager && !empty($user['region'])) ? $user['region'] : '';

$q = trim((string)($_GET['q'] ?? ''));

$db   = new Database();
$conn = $db->getConnection();

$reportResults = [];
$userResults   = [];
$zoneResults   = [];
$detectedIntent = [];

// ── Month name map ──
$monthMap = [
    'january'=>1,'february'=>2,'march'=>3,'april'=>4,'may'=>5,'june'=>6,
    'july'=>7,'august'=>8,'september'=>9,'october'=>10,'november'=>11,'december'=>12,
    'jan'=>1,'feb'=>2,'mar'=>3,'apr'=>4,'jun'=>6,'jul'=>7,'aug'=>8,
    'sep'=>9,'sept'=>9,'oct'=>10,'nov'=>11,'dec'=>12,
];

/**
 * Highlight all tokens from $tokens inside $text (case-insensitive).
 * Returns HTML-safe string with <mark> around matched segments.
 */
function highlightTokens(string $text, array $tokens): string {
    $safe = htmlspecialchars($text);
    $parts = [];
    foreach ($tokens as $tok) {
        $tok = trim($tok);
        // Skip single-char tokens — too noisy (match inside every word)
        if (mb_strlen($tok) < 2) continue;
        $escaped = preg_quote(htmlspecialchars($tok), '/');
        // For short 2-3 char tokens require a word boundary to avoid
        // false matches inside longer words
        if (mb_strlen($tok) <= 3) {
            $parts[] = '\\b' . $escaped . '\\b';
        } else {
            $parts[] = $escaped;
        }
    }
    if (empty($parts)) return $safe;
    // Longest pattern first so more specific tokens take priority
    usort($parts, fn($a, $b) => strlen($b) <=> strlen($a));
    $pattern = '/(' . implode('|', $parts) . ')/iu';
    return preg_replace_callback(
        $pattern,
        fn($m) => '<mark class="sr-hl">' . $m[1] . '</mark>',
        $safe
    );
}

/**
 * Score a candidate string against query tokens.
 * Returns integer relevance score (higher = better).
 */
function scoreMatch(string $candidate, array $tokens, string $fullQuery): int {
    $score = 0;
    $c = mb_strtolower($candidate);
    $fq = mb_strtolower($fullQuery);
    // Exact full match = highest
    if ($c === $fq) return 100;
    // Full query as substring
    if (strpos($c, $fq) !== false) $score += 50;
    foreach ($tokens as $tok) {
        if ($tok === '') continue;
        $t = mb_strtolower($tok);
        // Exact word boundary match
        if (preg_match('/\b' . preg_quote($t, '/') . '\b/u', $c)) {
            $score += 20;
        } elseif (strpos($c, $t) === 0) {
            // Starts with token
            $score += 15;
        } elseif (strpos($c, $t) !== false) {
            $score += 8;
        }
    }
    return $score;
}

if ($q !== '') {
    // ── Tokenise query ──
    $rawTokens = array_values(array_filter(array_map('trim', preg_split('/\s+/', mb_strtolower($q)))));

    // ── Intent detection ──
    $intentYear   = null;
    $intentMonth  = null;
    $intentRole   = null;
    $intentStatus = null;
    $semanticTokens = []; // tokens that are NOT fully consumed by intent

    foreach ($rawTokens as $tok) {
        if (preg_match('/^(20\d{2})$/', $tok, $m)) {
            $intentYear = (int)$m[1];
        } elseif (isset($monthMap[$tok])) {
            $intentMonth = $monthMap[$tok];
        } elseif (in_array($tok, ['admin','administrator'])) {
            $intentRole = 'admin';
        } elseif (in_array($tok, ['manager','admin-manager'])) {
            $intentRole = 'admin-manager';
        } elseif (in_array($tok, ['user','rzm','sponsor'])) {
            $intentRole = 'user';
        } elseif (in_array($tok, ['active','approved'])) {
            $intentStatus = 1;
        } elseif (in_array($tok, ['pending','inactive','disabled'])) {
            $intentStatus = 0;
        } else {
            $semanticTokens[] = $tok;
        }
    }

    if ($intentYear || $intentMonth) {
        $detectedIntent[] = ($intentMonth ? date('F', mktime(0,0,0,$intentMonth,1)) . ' ' : '') . ($intentYear ?? '');
    }
    if ($intentRole)   $detectedIntent[] = 'Role: ' . $intentRole;
    if ($intentStatus !== null) $detectedIntent[] = $intentStatus ? 'Active users' : 'Pending users';

    // Build LIKE clauses for remaining text tokens
    $likes = array_map(fn($t) => '%' . $t . '%', $semanticTokens);
    // Also keep original full query as a fallback like
    $fullLike = '%' . $q . '%';

    // Helper: build WHERE fragment matching ANY token across multiple columns
    // Returns [sqlFragment, params]
    $buildTokenWhere = function(array $columns, array $toks, string $prefix = 't') use ($fullLike, $q): array {
        if (empty($toks)) return ['1=1', []];
        $clauses = [];
        $params  = [];
        foreach ($toks as $i => $tok) {
            $like = '%' . $tok . '%';
            $colClauses = [];
            foreach ($columns as $j => $col) {
                $key = ':' . $prefix . '_' . $i . '_' . $j;
                $colClauses[] = "$col LIKE $key";
                $params[$key] = $like;
            }
            $clauses[] = '(' . implode(' OR ', $colClauses) . ')';
        }
        // All tokens must match (AND) — semantic intersection
        return ['(' . implode(' AND ', $clauses) . ')', $params];
    };

    // ── Reports ──
    if ($isAdmin || $isAdminManager) {
        $rCols = ["u.name", "u.zone", "u.region", "DATE_FORMAT(r.report_month,'%M %Y')", "DATE_FORMAT(r.report_month,'%Y-%m')"];
        [$rWhere, $rParams] = $buildTokenWhere($rCols, $semanticTokens, 'r');

        // If only intent (no text tokens), match everything
        if (empty($semanticTokens)) { $rWhere = '1=1'; $rParams = []; }

        $rSql = "SELECT r.id, r.report_month, r.created_at, r.total_copies,
                        u.name, u.zone, u.region
                 FROM reports r
                 LEFT JOIN users u ON r.user_id = u.id
                 WHERE $rWhere";

        if ($intentYear) {
            $rSql .= " AND YEAR(r.report_month) = :iy";
            $rParams[':iy'] = $intentYear;
        }
        if ($intentMonth) {
            $rSql .= " AND MONTH(r.report_month) = :im";
            $rParams[':im'] = $intentMonth;
        }
        if ($isAdminManager) {
            $rSql .= " AND u.region = :reg";
            $rParams[':reg'] = $scopedRegion;
        }
        $rSql .= " ORDER BY r.report_month DESC, r.created_at DESC LIMIT 30";
        $rStmt = $conn->prepare($rSql);
        $rStmt->execute($rParams);
        $rows = $rStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Score & sort
        foreach ($rows as &$row) {
            $haystack = implode(' ', array_filter([
                $row['name'] ?? '', $row['zone'] ?? '', $row['region'] ?? '',
                date('F Y', strtotime($row['report_month']))
            ]));
            $row['_score'] = scoreMatch($haystack, $rawTokens, $q);
        }
        unset($row);
        usort($rows, fn($a,$b) => $b['_score'] <=> $a['_score']);
        $reportResults = array_slice($rows, 0, 20);

    } else {
        // Regular user — only their own reports, filtered by intent
        $rSql = "SELECT r.id, r.report_month, r.created_at, r.total_copies, u.name, u.zone, u.region
                 FROM reports r LEFT JOIN users u ON r.user_id = u.id
                 WHERE r.user_id = :uid";
        $rParams = [':uid' => $_SESSION['user_id']];
        if ($intentYear)  { $rSql .= " AND YEAR(r.report_month) = :iy";  $rParams[':iy'] = $intentYear; }
        if ($intentMonth) { $rSql .= " AND MONTH(r.report_month) = :im"; $rParams[':im'] = $intentMonth; }
        if (!empty($semanticTokens)) {
            $rSql .= " AND DATE_FORMAT(r.report_month,'%M %Y') LIKE :ql";
            $rParams[':ql'] = $fullLike;
        }
        $rSql .= " ORDER BY r.report_month DESC LIMIT 20";
        $rStmt = $conn->prepare($rSql);
        $rStmt->execute($rParams);
        $reportResults = $rStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    // ── Users (admin / manager) ──
    if ($isAdmin || $isAdminManager) {
        $uCols = ['username', 'name', 'region', 'zone'];
        [$uWhere, $uParams] = $buildTokenWhere($uCols, $semanticTokens, 'u');
        if (empty($semanticTokens)) { $uWhere = '1=1'; $uParams = []; }

        $uSql = "SELECT id, username, name, role, region, zone, active FROM users WHERE $uWhere";
        if ($isAdminManager) {
            $uSql .= " AND region = :reg";
            $uParams[':reg'] = $scopedRegion;
        }
        if ($intentRole !== null) {
            $uSql .= " AND role = :role";
            $uParams[':role'] = $intentRole;
        }
        if ($intentStatus !== null) {
            $uSql .= " AND active = :status";
            $uParams[':status'] = $intentStatus;
        }
        $uSql .= " ORDER BY name ASC LIMIT 30";
        $uStmt = $conn->prepare($uSql);
        $uStmt->execute($uParams);
        $uRows = $uStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Score
        foreach ($uRows as &$row) {
            $haystack = implode(' ', array_filter([$row['name'],$row['username'],$row['zone']??'',$row['region']??'']));
            $row['_score'] = scoreMatch($haystack, $rawTokens, $q);
        }
        unset($row);
        usort($uRows, fn($a,$b) => $b['_score'] <=> $a['_score']);
        $userResults = array_slice($uRows, 0, 20);
    }

    // ── Zones (admin / manager) ──
    if ($isAdmin || $isAdminManager) {
        [$zWhere, $zParams] = $buildTokenWhere(['u.zone','u.region'], $semanticTokens, 'z');
        if (empty($semanticTokens)) { $zWhere = '1=1'; $zParams = []; }

        $zSql = "SELECT u.zone, u.region,
                        COUNT(DISTINCT r.id) AS report_count,
                        MAX(r.created_at)    AS last_submission
                 FROM users u
                 LEFT JOIN reports r ON r.user_id = u.id
                 WHERE u.zone IS NOT NULL AND $zWhere";
        if ($isAdminManager) {
            $zSql .= " AND u.region = :reg";
            $zParams[':reg'] = $scopedRegion;
        }
        $zSql .= " GROUP BY u.zone, u.region ORDER BY u.zone ASC LIMIT 20";
        $zStmt = $conn->prepare($zSql);
        $zStmt->execute($zParams);
        $zRows = $zStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($zRows as &$row) {
            $haystack = ($row['zone'] ?? '') . ' ' . ($row['region'] ?? '');
            $row['_score'] = scoreMatch($haystack, $rawTokens, $q);
        }
        unset($row);
        usort($zRows, fn($a,$b) => $b['_score'] <=> $a['_score']);
        $zoneResults = array_slice($zRows, 0, 15);
    }
}

$totalResults = count($reportResults) + count($userResults) + count($zoneResults);

require_once 'layouts/header.php';
?>
<style>
.sr-page { max-width: 900px; margin: 0 auto; padding: 2rem 1.25rem 3rem; }
.sr-head { margin-bottom: 1.5rem; }
.sr-head h1 { font-size: 1.125rem; font-weight: 700; color: var(--neutral-900); margin: 0 0 0.85rem; }
.sr-form { display: flex; gap: 0.5rem; }
.sr-input { flex: 1; padding: 0.55rem 0.85rem; font-size: 0.9rem; border: 1px solid var(--neutral-300); border-radius: var(--radius-lg); outline: none; transition: border-color 0.15s, box-shadow 0.15s; }
.sr-input:focus { border-color: var(--primary-400); box-shadow: 0 0 0 3px rgba(14,165,233,0.1); }
.sr-meta { font-size: 0.8rem; color: var(--neutral-400); margin-top: 0.5rem; }
.sr-section { margin-bottom: 1.75rem; }
.sr-section-title { font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: var(--neutral-400); margin: 0 0 0.6rem; }
.sr-card { border: 1px solid var(--neutral-200); border-radius: var(--radius-lg); background: #fff; overflow: hidden; margin-bottom: 0.5rem; }
.sr-row { display: flex; align-items: center; gap: 0.75rem; padding: 0.75rem 1rem; text-decoration: none; transition: background 0.12s; }
.sr-row:hover { background: var(--neutral-50); }
.sr-row + .sr-row { border-top: 1px solid var(--neutral-100); }
.sr-icon { width: 2rem; height: 2rem; border-radius: var(--radius-md); display: flex; align-items: center; justify-content: center; font-size: 0.8rem; flex-shrink: 0; }
.sr-icon-report { background: var(--primary-50); color: var(--primary-600); }
.sr-icon-user   { background: var(--success-50); color: var(--success-700); }
.sr-icon-zone   { background: var(--warning-50); color: var(--warning-700); }
.sr-main { flex: 1; min-width: 0; }
.sr-title { font-size: 0.875rem; font-weight: 600; color: var(--neutral-900); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.sr-sub   { font-size: 0.75rem; color: var(--neutral-400); margin-top: 0.1rem; }
.sr-badge { font-size: 0.7rem; font-weight: 600; padding: 0.15rem 0.5rem; border-radius: 9999px; flex-shrink: 0; }
.sr-badge-success { background: var(--success-50); color: var(--success-700); }
.sr-badge-danger  { background: var(--danger-50);  color: var(--danger-700); }
.sr-badge-warning { background: var(--warning-50); color: var(--warning-700); }
.sr-empty { border: 1px solid var(--neutral-200); border-radius: var(--radius-lg); background: #fff; padding: 3rem 1.5rem; text-align: center; }
.sr-empty i { font-size: 2rem; color: var(--neutral-300); margin-bottom: 0.75rem; display: block; }
.sr-empty p { font-size: 0.875rem; color: var(--neutral-400); margin: 0; }
.sr-suggestion { display:inline-flex; align-items:center; font-size:0.78rem; color:var(--primary-600); background:var(--primary-50); border:1px solid var(--primary-100); border-radius:9999px; padding:0.2rem 0.7rem; text-decoration:none; transition:background 0.12s; }
.sr-suggestion:hover { background:var(--primary-100); }
.sr-intent-bar { display:flex; align-items:center; gap:0.5rem; flex-wrap:wrap; font-size:0.8rem; color:var(--neutral-500); background:var(--primary-50); border:1px solid var(--primary-100); border-radius:var(--radius-lg); padding:0.55rem 0.9rem; margin-bottom:1rem; }
.sr-intent-chip { display:inline-flex; align-items:center; background:var(--primary-100); color:var(--primary-700); font-size:0.75rem; font-weight:600; border-radius:9999px; padding:0.15rem 0.6rem; }
.sr-hl { background:#fef08a; color:#713f12; border-radius:2px; padding:0 1px; font-style:normal; }
</style>

<div class="sr-page">
    <div class="sr-head">
        <h1><i class="fas fa-search" style="color:var(--primary-500);margin-right:0.4rem;font-size:0.95rem;"></i>Search</h1>
        <form method="GET" class="sr-form">
            <input type="text" name="q" value="<?php echo htmlspecialchars($q); ?>"
                   class="sr-input" placeholder="Search reports, users, zones, months…"
                   autofocus autocomplete="off">
            <button type="submit" class="modern-button modern-button-primary" style="font-size:0.8125rem;">Search</button>
            <?php if ($q !== ''): ?>
            <a href="search.php" class="modern-button modern-button-secondary" style="font-size:0.8125rem;">Clear</a>
            <?php endif; ?>
        </form>
        <?php if ($q !== ''): ?>
        <p class="sr-meta"><?php echo $totalResults; ?> result<?php echo $totalResults !== 1 ? 's' : ''; ?> for &ldquo;<?php echo htmlspecialchars($q); ?>&rdquo;</p>
        <?php endif; ?>
    </div>

    <?php if (!empty($detectedIntent)): ?>
    <div class="sr-intent-bar">
        <i class="fas fa-magic" style="color:var(--primary-500);"></i>
        <span>Interpreted as:</span>
        <?php foreach ($detectedIntent as $intent): ?>
        <span class="sr-intent-chip"><?php echo htmlspecialchars(trim($intent)); ?></span>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($q === ''): ?>
    <div class="sr-empty">
        <i class="fas fa-search"></i>
        <p>Enter a name, zone, region, or month to search across the portal.</p>
        <div style="margin-top:1rem;display:flex;flex-wrap:wrap;gap:0.4rem;justify-content:center;">
            <?php
            $suggestions = ['March 2025','pending users','admin','Lagos Zone'];
            foreach ($suggestions as $s):
            ?>
            <a href="search.php?q=<?php echo urlencode($s); ?>" class="sr-suggestion"><?php echo htmlspecialchars($s); ?></a>
            <?php endforeach; ?>
        </div>
    </div>

    <?php elseif ($totalResults === 0): ?>
    <div class="sr-empty">
        <i class="fas fa-inbox"></i>
        <p>No results for &ldquo;<?php echo htmlspecialchars($q); ?>&rdquo;.</p>
        <p style="font-size:0.78rem;color:var(--neutral-400);margin-top:0.35rem;">Try a different name, zone, month (e.g. &ldquo;March 2025&rdquo;), or role (e.g. &ldquo;admin&rdquo;).</p>
    </div>

    <?php else: ?>

    <?php if (!empty($reportResults)): ?>
    <div class="sr-section">
        <p class="sr-section-title"><i class="fas fa-file-alt" style="margin-right:0.3rem;"></i>Reports (<?php echo count($reportResults); ?>)</p>
        <div class="sr-card">
            <?php foreach ($reportResults as $r): ?>
            <a href="<?php echo BASE_URL; ?>/pages/reports/view.php?id=<?php echo $r['id']; ?>" class="sr-row">
                <div class="sr-icon sr-icon-report"><i class="fas fa-file-alt"></i></div>
                <div class="sr-main">
                    <div class="sr-title">
                        Report #<?php echo $r['id']; ?> &mdash;
                        <?php echo highlightTokens(date('F Y', strtotime($r['report_month'])), $rawTokens ?? []); ?>
                        <?php if (!empty($r['zone'])): ?>
                        <span style="font-weight:400;color:var(--neutral-400);">&nbsp;&middot;&nbsp;<?php echo highlightTokens($r['zone'], $rawTokens ?? []); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="sr-sub">
                        <?php if (!empty($r['name'])): ?><?php echo highlightTokens($r['name'], $rawTokens ?? []); ?><?php endif; ?>
                        <?php if (!empty($r['region'])): ?> &middot; <?php echo highlightTokens($r['region'], $rawTokens ?? []); ?><?php endif; ?>
                        &middot; <?php echo date('M j, Y', strtotime($r['created_at'])); ?>
                        <?php if (!empty($r['total_copies'])): ?>
                        &middot; <?php echo number_format($r['total_copies']); ?> copies
                        <?php endif; ?>
                    </div>
                </div>
                <i class="fas fa-chevron-right" style="color:var(--neutral-300);font-size:0.7rem;"></i>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($userResults)): ?>
    <div class="sr-section">
        <p class="sr-section-title"><i class="fas fa-users" style="margin-right:0.3rem;"></i>Users (<?php echo count($userResults); ?>)</p>
        <div class="sr-card">
            <?php foreach ($userResults as $u): ?>
            <a href="<?php echo BASE_URL; ?>/admin/edit-user.php?id=<?php echo $u['id']; ?>" class="sr-row">
                <div class="sr-icon sr-icon-user"><i class="fas fa-user"></i></div>
                <div class="sr-main">
                    <div class="sr-title"><?php echo highlightTokens($u['name'] ?: $u['username'], $rawTokens ?? []); ?></div>
                    <div class="sr-sub">
                        @<?php echo highlightTokens($u['username'], $rawTokens ?? []); ?>
                        <?php if (!empty($u['zone'])): ?>&middot; <?php echo highlightTokens($u['zone'], $rawTokens ?? []); ?><?php endif; ?>
                        <?php if (!empty($u['region'])): ?>&middot; <?php echo highlightTokens($u['region'], $rawTokens ?? []); ?><?php endif; ?>
                        &middot; <?php echo htmlspecialchars($u['role']); ?>
                    </div>
                </div>
                <span class="sr-badge <?php echo $u['active'] ? 'sr-badge-success' : 'sr-badge-warning'; ?>">
                    <?php echo $u['active'] ? 'Active' : 'Pending'; ?>
                </span>
                <i class="fas fa-chevron-right" style="color:var(--neutral-300);font-size:0.7rem;"></i>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($zoneResults)): ?>
    <div class="sr-section">
        <p class="sr-section-title"><i class="fas fa-map-marker-alt" style="margin-right:0.3rem;"></i>Zones (<?php echo count($zoneResults); ?>)</p>
        <div class="sr-card">
            <?php foreach ($zoneResults as $z): ?>
            <a href="<?php echo BASE_URL; ?>/pages/reports/list.php?zone=<?php echo urlencode($z['zone']); ?>" class="sr-row">
                <div class="sr-icon sr-icon-zone"><i class="fas fa-map-marker-alt"></i></div>
                <div class="sr-main">
                    <div class="sr-title"><?php echo highlightTokens($z['zone'], $rawTokens ?? []); ?></div>
                    <div class="sr-sub">
                        <?php echo highlightTokens($z['region'], $rawTokens ?? []); ?>
                        &middot; <?php echo number_format($z['report_count']); ?> report<?php echo $z['report_count'] != 1 ? 's' : ''; ?>
                        <?php if ($z['last_submission']): ?>&middot; Last: <?php echo date('M Y', strtotime($z['last_submission'])); ?><?php endif; ?>
                    </div>
                </div>
                <span class="sr-badge <?php echo $z['report_count'] > 0 ? 'sr-badge-success' : 'sr-badge-danger'; ?>">
                    <?php echo $z['report_count'] > 0 ? 'Reporting' : 'No reports'; ?>
                </span>
                <i class="fas fa-chevron-right" style="color:var(--neutral-300);font-size:0.7rem;"></i>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php endif; ?>
</div>

<?php require_once 'layouts/footer.php'; ?>
