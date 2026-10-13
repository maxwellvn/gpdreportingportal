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
            $intentRole = ROLE_RZM;
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
/* Search: page-local pieces the shared components don't cover. */
.sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
.sr-form { display: flex; gap: var(--spacing-2); width: 100%; max-width: 44rem; margin-top: var(--spacing-5); }
.sr-field { position: relative; flex: 1; min-width: 0; }
.sr-field > i { position: absolute; left: 1rem; top: 50%; transform: translateY(-50%); color: var(--neutral-600); pointer-events: none; }
.sr-field .input { height: 3rem; padding-left: 2.75rem; font-size: var(--text-base); }
.sr-list { list-style: none; margin: 0; padding: 0; }
.sr-list li + li { border-top: 1px solid var(--hairline); }
.sr-row { display: flex; align-items: center; gap: var(--spacing-3); padding: var(--spacing-3) var(--spacing-5); color: inherit; }
.sr-row:focus-visible { outline: none; box-shadow: inset var(--focus-ring); }
.sr-main { flex: 1; min-width: 0; }
.sr-title { font-weight: 500; color: var(--ink); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.sr-sub { font-size: var(--text-xs); color: var(--neutral-600); margin-top: 0.125rem; overflow-wrap: anywhere; }
.sr-chevron { color: var(--neutral-400); font-size: var(--text-xs); flex-shrink: 0; }
.sr-chips { display: flex; flex-wrap: wrap; gap: var(--spacing-2); justify-content: center; }
.sr-intent { display: flex; flex-wrap: wrap; align-items: center; gap: var(--spacing-2); margin-bottom: var(--spacing-6); font-size: var(--text-sm); color: var(--slate); }
.sr-hl { background: var(--warning-100); color: var(--ink); border-radius: 2px; padding: 0 1px; }
@media (hover: hover) and (pointer: fine) { .sr-row { transition: background-color var(--dur-fast) var(--ease-out); } a.sr-row:hover { background: var(--mist); } }
@media (max-width: 479px) { .sr-form { flex-wrap: wrap; } .sr-field { flex-basis: 100%; } }
</style>

<div class="page-head">
    <div style="flex:1;min-width:0;">
        <div class="page-eyebrow">Search</div>
        <h1 class="page-title">Find reports<?php echo ($isAdmin || $isAdminManager) ? ', people and zones' : ''; ?></h1>
        <form method="GET" class="sr-form" role="search">
            <div class="sr-field">
                <i class="fas fa-search" aria-hidden="true"></i>
                <label for="sr-q" class="sr-only">Search</label>
                <input type="search" id="sr-q" name="q" value="<?php echo htmlspecialchars($q); ?>"
                       class="input" placeholder="<?php echo ($isAdmin || $isAdminManager) ? 'Name, zone, region or month' : 'Month or year, e.g. March 2025'; ?>"
                       autofocus autocomplete="off">
            </div>
            <button type="submit" class="btn btn-primary btn-lg">Search</button>
            <?php if ($q !== ''): ?>
            <a href="search.php" class="btn btn-ghost btn-lg">Clear</a>
            <?php endif; ?>
        </form>
        <?php if ($q !== ''): ?>
        <p class="page-sub" style="margin-top:var(--spacing-3);" aria-live="polite"><span class="num"><?php echo $totalResults; ?></span> result<?php echo $totalResults !== 1 ? 's' : ''; ?> for &ldquo;<?php echo htmlspecialchars($q); ?>&rdquo;</p>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($detectedIntent)): ?>
<div class="sr-intent">
    <span class="meta">Interpreted as</span>
    <?php foreach ($detectedIntent as $intent): ?>
    <span class="badge badge-info no-dot"><?php echo htmlspecialchars(trim($intent)); ?></span>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($q === ''): ?>
<div class="panel">
    <div class="empty-state">
        <div class="empty-state-icon"><i class="fas fa-search" aria-hidden="true"></i></div>
        <div class="empty-state-title">Search the portal</div>
        <p class="empty-state-text"><?php echo ($isAdmin || $isAdminManager) ? 'Enter a name, zone, region, or month.' : 'Enter a month or year to find your reports.'; ?> Try one of these:</p>
        <div class="sr-chips">
            <?php
            $suggestions = ($isAdmin || $isAdminManager) ? ['March 2025','pending users','admin','Lagos Zone'] : [date('F Y'), date('Y'), (string)((int)date('Y') - 1)];
            foreach ($suggestions as $s):
            ?>
            <a href="search.php?q=<?php echo urlencode($s); ?>" class="btn btn-secondary btn-sm"><?php echo htmlspecialchars($s); ?></a>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php elseif ($totalResults === 0): ?>
<div class="panel">
    <div class="empty-state">
        <div class="empty-state-icon"><i class="fas fa-inbox" aria-hidden="true"></i></div>
        <div class="empty-state-title">No results for &ldquo;<?php echo htmlspecialchars($q); ?>&rdquo;</div>
        <p class="empty-state-text">Try a different name, zone, month (e.g. &ldquo;March 2025&rdquo;), or role (e.g. &ldquo;admin&rdquo;).</p>
        <a href="search.php" class="btn btn-secondary">Clear search</a>
    </div>
</div>

<?php else: ?>

<?php if (!empty($reportResults)): ?>
<section class="section" aria-labelledby="sr-reports">
    <div class="section-head">
        <h2 class="section-title" id="sr-reports">Reports</h2>
        <span class="section-meta num"><?php echo count($reportResults); ?></span>
    </div>
    <div class="panel">
        <ul class="sr-list">
            <?php foreach ($reportResults as $r): ?>
            <li>
                <a href="<?php echo BASE_URL; ?>/pages/reports/view.php?id=<?php echo (int)$r['id']; ?>" class="sr-row">
                    <div class="sr-main">
                        <div class="sr-title">
                            <?php echo highlightTokens(date('F Y', strtotime($r['report_month'])), $rawTokens ?? []); ?>
                            <?php if (!empty($r['zone'])): ?><span class="muted" style="font-weight:400;"> &middot; <?php echo highlightTokens($r['zone'], $rawTokens ?? []); ?></span><?php endif; ?>
                        </div>
                        <div class="sr-sub">
                            <span class="meta">#<?php echo (int)$r['id']; ?></span>
                            <?php if (!empty($r['name'])): ?> &middot; <?php echo highlightTokens($r['name'], $rawTokens ?? []); ?><?php endif; ?>
                            <?php if (!empty($r['region'])): ?> &middot; <?php echo highlightTokens($r['region'], $rawTokens ?? []); ?><?php endif; ?>
                            &middot; <?php echo date('M j, Y', strtotime($r['created_at'])); ?>
                            <?php if (!empty($r['total_copies'])): ?> &middot; <span class="num"><?php echo number_format($r['total_copies']); ?></span> copies<?php endif; ?>
                        </div>
                    </div>
                    <i class="fas fa-chevron-right sr-chevron" aria-hidden="true"></i>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
</section>
<?php endif; ?>

<?php if (!empty($userResults)): ?>
<section class="section" aria-labelledby="sr-users">
    <div class="section-head">
        <h2 class="section-title" id="sr-users">People</h2>
        <span class="section-meta num"><?php echo count($userResults); ?></span>
    </div>
    <div class="panel">
        <ul class="sr-list">
            <?php foreach ($userResults as $u): ?>
            <li>
                <?php if ($isAdmin): ?>
                <a href="<?php echo BASE_URL; ?>/admin/edit-user.php?id=<?php echo (int)$u['id']; ?>" class="sr-row">
                <?php else: /* admin managers cannot open admin/edit-user.php */ ?>
                <div class="sr-row">
                <?php endif; ?>
                    <div class="sr-main">
                        <div class="sr-title"><?php echo highlightTokens($u['name'] ?: $u['username'], $rawTokens ?? []); ?></div>
                        <div class="sr-sub">
                            @<?php echo highlightTokens($u['username'], $rawTokens ?? []); ?>
                            <?php if (!empty($u['zone'])): ?> &middot; <?php echo highlightTokens($u['zone'], $rawTokens ?? []); ?><?php endif; ?>
                            <?php if (!empty($u['region'])): ?> &middot; <?php echo highlightTokens($u['region'], $rawTokens ?? []); ?><?php endif; ?>
                            &middot; <?php echo htmlspecialchars($u['role'] === ROLE_RZM ? 'RZM' : ($u['role'] === 'admin-manager' ? 'Admin Manager' : ucfirst($u['role']))); ?>
                        </div>
                    </div>
                    <span class="badge <?php echo $u['active'] ? 'badge-success' : 'badge-warning'; ?>"><?php echo $u['active'] ? 'Active' : 'Pending'; ?></span>
                <?php if ($isAdmin): ?>
                    <i class="fas fa-chevron-right sr-chevron" aria-hidden="true"></i>
                </a>
                <?php else: ?>
                </div>
                <?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
</section>
<?php endif; ?>

<?php if (!empty($zoneResults)): ?>
<section class="section" aria-labelledby="sr-zones">
    <div class="section-head">
        <h2 class="section-title" id="sr-zones">Zones</h2>
        <span class="section-meta num"><?php echo count($zoneResults); ?></span>
    </div>
    <div class="panel">
        <ul class="sr-list">
            <?php foreach ($zoneResults as $z): ?>
            <li>
                <a href="<?php echo BASE_URL; ?>/pages/reports/list.php?zone=<?php echo urlencode($z['zone']); ?>" class="sr-row">
                    <div class="sr-main">
                        <div class="sr-title"><?php echo highlightTokens($z['zone'], $rawTokens ?? []); ?></div>
                        <div class="sr-sub">
                            <?php echo highlightTokens((string)$z['region'], $rawTokens ?? []); ?>
                            &middot; <span class="num"><?php echo number_format($z['report_count']); ?></span> report<?php echo $z['report_count'] != 1 ? 's' : ''; ?>
                            <?php if ($z['last_submission']): ?> &middot; Last: <?php echo date('M Y', strtotime($z['last_submission'])); ?><?php endif; ?>
                        </div>
                    </div>
                    <span class="badge <?php echo $z['report_count'] > 0 ? 'badge-success' : 'badge-danger'; ?>"><?php echo $z['report_count'] > 0 ? 'Reporting' : 'No reports'; ?></span>
                    <i class="fas fa-chevron-right sr-chevron" aria-hidden="true"></i>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
</section>
<?php endif; ?>

<?php endif; ?>

<?php require_once 'layouts/footer.php'; ?>
