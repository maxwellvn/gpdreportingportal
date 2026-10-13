<?php
require_once '../config/config.php';
require_once '../includes/auth.php';
require_once '../includes/audit.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/login.php'); exit;
}
$auth = new Auth();
$currentUser = $auth->getUser($_SESSION['user_id']);
if (!isset($currentUser['role']) || $currentUser['role'] !== 'admin') {
    header('Location: ' . BASE_URL . '/dashboard.php'); exit;
}

$filterAction     = trim((string)($_GET['action'] ?? ''));
$filterTargetType = trim((string)($_GET['target_type'] ?? ''));
$filterActor      = trim((string)($_GET['actor'] ?? ''));
$limit            = 100;

$db   = new Database();
$conn = $db->getConnection();

// Ensure table exists
try { $conn->exec("
    CREATE TABLE IF NOT EXISTS `audit_trail` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `actor_id` int(11) DEFAULT NULL,
      `actor_name` varchar(120) DEFAULT NULL,
      `action` varchar(60) NOT NULL,
      `target_type` varchar(40) DEFAULT NULL,
      `target_id` int(11) DEFAULT NULL,
      `description` text DEFAULT NULL,
      `ip_address` varchar(45) DEFAULT NULL,
      `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
"); } catch (Exception $e) {}

// Fetch distinct actions for filter
$actions = [];
try {
    $actions = $conn->query("SELECT DISTINCT action FROM audit_trail ORDER BY action")->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (Exception $e) {}

// Build query
$where  = [];
$params = [];

if ($filterAction !== '') {
    $where[] = 'action = :action';
    $params[':action'] = $filterAction;
}
if ($filterTargetType !== '') {
    $where[] = 'target_type = :tt';
    $params[':tt'] = $filterTargetType;
}
if ($filterActor !== '') {
    $where[] = 'actor_name LIKE :actor';
    $params[':actor'] = '%' . $filterActor . '%';
}

$sql = 'SELECT * FROM audit_trail';
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' ORDER BY created_at DESC LIMIT :lim';

$entries = [];
try {
    $stmt = $conn->prepare($sql);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $entries = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {}

$actionLabels = [
    'approve_user'     => ['label' => 'Approve user',     'badge' => 'badge-success'],
    'deactivate_user'  => ['label' => 'Move to pending',  'badge' => 'badge-warning'],
    'bulk_approve'     => ['label' => 'Bulk approve',     'badge' => 'badge-success'],
    'bulk_deactivate'  => ['label' => 'Bulk move to pending', 'badge' => 'badge-warning'],
    'delete_report'    => ['label' => 'Delete report',    'badge' => 'badge-danger'],
    'edit_report'      => ['label' => 'Edit report',      'badge' => 'badge-info'],
    'delete_user'      => ['label' => 'Delete user',      'badge' => 'badge-danger'],
    'send_reminder'    => ['label' => 'Send reminder',    'badge' => 'badge-info'],
];

require_once '../layouts/header.php';
?>
<?php $hasFilters = $filterAction !== '' || $filterTargetType !== '' || $filterActor !== ''; ?>

<nav class="breadcrumb" aria-label="Breadcrumb"><a href="users.php">People</a><span class="sep" aria-hidden="true">›</span><span aria-current="page">Audit log</span></nav>
<div class="page-head">
    <div>
        <h1 class="page-title">Audit log</h1>
        <p class="page-sub">Admin actions: approvals, deletions, edits and reminders.</p>
    </div>
</div>

<form method="GET" class="toolbar" role="search">
    <div class="field">
        <label for="al-actor" class="field-label">User</label>
        <input type="search" id="al-actor" name="actor" value="<?php echo htmlspecialchars($filterActor); ?>" class="input" placeholder="Actor name">
    </div>
    <div class="field">
        <label for="al-action" class="field-label">Action</label>
        <select id="al-action" name="action" class="input">
            <option value="">All actions</option>
            <?php foreach ($actions as $a): ?>
            <option value="<?php echo htmlspecialchars($a); ?>" <?php echo $filterAction === $a ? 'selected' : ''; ?>><?php echo htmlspecialchars($actionLabels[$a]['label'] ?? ucwords(str_replace('_', ' ', $a))); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field">
        <label for="al-target" class="field-label">Target</label>
        <select id="al-target" name="target_type" class="input">
            <option value="">All targets</option>
            <option value="user"   <?php echo $filterTargetType === 'user'   ? 'selected' : ''; ?>>User</option>
            <option value="report" <?php echo $filterTargetType === 'report' ? 'selected' : ''; ?>>Report</option>
        </select>
    </div>
    <div class="toolbar-actions">
        <button type="submit" class="btn btn-primary">Apply</button>
        <?php if ($hasFilters): ?><a href="audit_log.php" class="btn btn-ghost">Clear</a><?php endif; ?>
    </div>
</form>

<?php if (empty($entries)): ?>
<div class="panel">
    <div class="empty-state">
        <div class="empty-state-icon"><i class="fas fa-clock-rotate-left" aria-hidden="true"></i></div>
        <div class="empty-state-title"><?php echo $hasFilters ? 'No entries match these filters' : 'No audit entries yet'; ?></div>
        <div class="empty-state-text">Approvals, edits, deletions and reminders will be listed here as they happen.</div>
        <?php if ($hasFilters): ?><a href="audit_log.php" class="btn btn-secondary">Clear filters</a><?php endif; ?>
    </div>
</div>
<?php else: ?>
<div class="section-head">
    <span class="section-meta">Latest <?php echo count($entries); ?> entries (max <?php echo $limit; ?>)</span>
</div>
<div class="table-wrap">
    <table class="data-table">
        <thead>
            <tr>
                <th scope="col">When</th>
                <th scope="col">User</th>
                <th scope="col">Action</th>
                <th scope="col">Target</th>
                <th scope="col">Description</th>
                <th scope="col">IP</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($entries as $e):
                $meta = $actionLabels[$e['action']] ?? ['label' => ucwords(str_replace('_', ' ', $e['action'])), 'badge' => ''];
                $tid = (int)($e['target_id'] ?? 0);
            ?>
            <tr>
                <td class="meta" style="white-space:nowrap;"><time datetime="<?php echo htmlspecialchars(date('c', strtotime($e['created_at']))); ?>"><?php echo date('M j, Y', strtotime($e['created_at'])); ?> · <?php echo date('H:i:s', strtotime($e['created_at'])); ?></time></td>
                <td style="font-weight:500;color:var(--neutral-900);white-space:nowrap;"><?php echo htmlspecialchars($e['actor_name'] ?? '—'); ?></td>
                <td><span class="badge no-dot <?php echo $meta['badge']; ?>"><?php echo htmlspecialchars($meta['label']); ?></span></td>
                <td style="white-space:nowrap;">
                    <?php if ($e['target_type']): ?>
                    <span class="muted"><?php echo htmlspecialchars(ucfirst($e['target_type'])); ?></span>
                    <?php if ($tid): ?>
                        <?php if ($e['target_type'] === 'user'): ?>
                        <a href="edit-user.php?id=<?php echo $tid; ?>" class="link num">#<?php echo $tid; ?></a>
                        <?php elseif ($e['target_type'] === 'report'): ?>
                        <a href="<?php echo BASE_URL; ?>/pages/reports/view.php?id=<?php echo $tid; ?>" class="link num">#<?php echo $tid; ?></a>
                        <?php else: ?>
                        <span class="num">#<?php echo $tid; ?></span>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php else: ?>
                    <span class="muted">—</span>
                    <?php endif; ?>
                </td>
                <td class="muted" style="min-width:16rem;max-width:28rem;"><?php echo htmlspecialchars($e['description'] ?? ''); ?></td>
                <td class="meta"><?php echo htmlspecialchars($e['ip_address'] ?? '—'); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php require_once '../layouts/footer.php'; ?>
