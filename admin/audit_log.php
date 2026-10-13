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
    'approve_user'     => ['label' => 'Approve User',     'icon' => 'fa-user-check',   'color' => 'var(--success-600)'],
    'deactivate_user'  => ['label' => 'Deactivate User',  'icon' => 'fa-user-times',   'color' => 'var(--warning-600)'],
    'bulk_approve'     => ['label' => 'Bulk Approve',     'icon' => 'fa-users',        'color' => 'var(--success-600)'],
    'bulk_deactivate'  => ['label' => 'Bulk Deactivate',  'icon' => 'fa-users',        'color' => 'var(--warning-600)'],
    'delete_report'    => ['label' => 'Delete Report',    'icon' => 'fa-trash',        'color' => 'var(--danger-600)'],
    'edit_report'      => ['label' => 'Edit Report',      'icon' => 'fa-edit',         'color' => 'var(--primary-600)'],
    'delete_user'      => ['label' => 'Delete User',      'icon' => 'fa-user-minus',   'color' => 'var(--danger-600)'],
    'send_reminder'    => ['label' => 'Send Reminder',    'icon' => 'fa-bell',         'color' => 'var(--primary-600)'],
];

require_once '../layouts/header.php';
?>
<style>
.al-page { max-width: 1100px; margin: 0 auto; padding: 2rem 1.25rem 3rem; }
.al-topbar { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:1rem; margin-bottom:1.5rem; }
.al-topbar h1 { font-size:1.125rem; font-weight:700; color:var(--neutral-900); margin:0 0 0.15rem; }
.al-topbar p { font-size:0.85rem; color:var(--neutral-500); margin:0; }
.al-card { border:1px solid var(--neutral-200); border-radius:var(--radius-lg); background:#fff; margin-bottom:1.25rem; overflow:hidden; }
.al-card-head { padding:0.85rem 1.25rem; border-bottom:1px solid var(--neutral-200); }
.al-card-head h2 { font-size:0.875rem; font-weight:600; color:var(--neutral-900); margin:0; text-transform:uppercase; letter-spacing:0.04em; }
.al-filter-grid { padding:1.25rem; display:grid; grid-template-columns:repeat(4,1fr); gap:0.875rem; }
@media(max-width:768px){ .al-filter-grid { grid-template-columns:repeat(2,1fr); } }
@media(max-width:480px){ .al-filter-grid { grid-template-columns:1fr; } }
.al-table { width:100%; border-collapse:collapse; font-size:0.845rem; }
.al-table th { padding:0.65rem 1rem; font-size:0.7rem; font-weight:600; text-transform:uppercase; letter-spacing:0.04em; color:var(--neutral-500); border-bottom:1px solid var(--neutral-200); text-align:left; }
.al-table td { padding:0.75rem 1rem; border-bottom:1px solid var(--neutral-100); color:var(--neutral-800); }
.al-table tr:last-child td { border-bottom:none; }
.al-table tbody tr:hover td { background:var(--neutral-50); }
.al-action-pill { display:inline-flex; align-items:center; gap:0.3rem; font-size:0.72rem; font-weight:600; padding:0.15rem 0.5rem; border-radius:9999px; background:var(--neutral-100); color:var(--neutral-700); }
.al-empty { padding:3rem 1.5rem; text-align:center; }
.al-empty i { font-size:2.5rem; color:var(--neutral-300); margin-bottom:0.75rem; display:block; }
.al-empty p { font-size:0.875rem; color:var(--neutral-500); margin:0; }
</style>

<div class="al-page">
    <div class="al-topbar">
        <div>
            <h1><i class="fas fa-history" style="color:var(--primary-500);margin-right:0.4rem;font-size:0.9rem;"></i>Audit Log</h1>
            <p>Track all admin actions — approvals, deletions, edits, reminders.</p>
        </div>
        <a href="users.php" class="modern-button modern-button-secondary" style="font-size:0.8125rem;"><i class="fas fa-arrow-left modern-button-icon"></i> Back to Users</a>
    </div>

    <div class="al-card">
        <div class="al-card-head"><h2>Filters</h2></div>
        <form method="GET" class="al-filter-grid">
            <div class="modern-form-group">
                <label class="modern-form-label">Action</label>
                <select name="action" class="modern-form-input">
                    <option value="">All Actions</option>
                    <?php foreach ($actions as $a): ?>
                    <option value="<?php echo htmlspecialchars($a); ?>" <?php echo $filterAction === $a ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($actionLabels[$a]['label'] ?? ucwords(str_replace('_', ' ', $a))); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="modern-form-group">
                <label class="modern-form-label">Target Type</label>
                <select name="target_type" class="modern-form-input">
                    <option value="">All Types</option>
                    <option value="user"   <?php echo $filterTargetType === 'user'   ? 'selected' : ''; ?>>User</option>
                    <option value="report" <?php echo $filterTargetType === 'report' ? 'selected' : ''; ?>>Report</option>
                </select>
            </div>
            <div class="modern-form-group">
                <label class="modern-form-label">Actor Name</label>
                <input type="text" name="actor" value="<?php echo htmlspecialchars($filterActor); ?>" class="modern-form-input" placeholder="Search actor…">
            </div>
            <div style="display:flex;align-items:flex-end;gap:0.5rem;">
                <button type="submit" class="modern-button modern-button-primary" style="font-size:0.8125rem;">Apply</button>
                <a href="audit_log.php" class="modern-button modern-button-secondary" style="font-size:0.8125rem;">Clear</a>
            </div>
        </form>
    </div>

    <div class="al-card">
        <div class="al-card-head">
            <h2>Recent Entries <?php if (!empty($entries)): ?><span style="font-weight:400;font-size:0.7rem;color:var(--neutral-400);text-transform:none;letter-spacing:0;">(showing up to <?php echo $limit; ?>)</span><?php endif; ?></h2>
        </div>
        <?php if (empty($entries)): ?>
        <div class="al-empty">
            <i class="fas fa-history"></i>
            <p>No audit entries yet<?php echo ($filterAction || $filterTargetType || $filterActor) ? ' for the selected filters' : ''; ?>.</p>
        </div>
        <?php else: ?>
        <div style="overflow-x:auto;">
            <table class="al-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>When</th>
                        <th>Actor</th>
                        <th>Action</th>
                        <th>Target</th>
                        <th>Description</th>
                        <th>IP</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($entries as $e):
                        $meta = $actionLabels[$e['action']] ?? ['label' => ucwords(str_replace('_', ' ', $e['action'])), 'icon' => 'fa-circle', 'color' => 'var(--neutral-500)'];
                    ?>
                    <tr>
                        <td style="color:var(--neutral-400);font-size:0.75rem;">#<?php echo $e['id']; ?></td>
                        <td style="white-space:nowrap;color:var(--neutral-500);font-size:0.78rem;">
                            <?php echo date('M j, Y', strtotime($e['created_at'])); ?><br>
                            <span style="font-size:0.7rem;"><?php echo date('H:i:s', strtotime($e['created_at'])); ?></span>
                        </td>
                        <td style="font-weight:500;"><?php echo htmlspecialchars($e['actor_name'] ?? '—'); ?></td>
                        <td>
                            <span class="al-action-pill" style="color:<?php echo $meta['color']; ?>;background:transparent;">
                                <i class="fas <?php echo $meta['icon']; ?>"></i>
                                <?php echo htmlspecialchars($meta['label']); ?>
                            </span>
                        </td>
                        <td style="color:var(--neutral-500);font-size:0.78rem;">
                            <?php if ($e['target_type']): ?>
                            <span><?php echo htmlspecialchars(ucfirst($e['target_type'])); ?></span>
                            <?php if ($e['target_id']): ?>
                            <?php if ($e['target_type'] === 'user'): ?>
                            <a href="edit-user.php?id=<?php echo $e['target_id']; ?>" style="font-size:0.72rem;color:var(--primary-500);">#<?php echo $e['target_id']; ?></a>
                            <?php elseif ($e['target_type'] === 'report'): ?>
                            <a href="<?php echo BASE_URL; ?>/pages/reports/view.php?id=<?php echo $e['target_id']; ?>" style="font-size:0.72rem;color:var(--primary-500);">#<?php echo $e['target_id']; ?></a>
                            <?php else: ?>
                            <span style="font-size:0.72rem;">#<?php echo $e['target_id']; ?></span>
                            <?php endif; ?>
                            <?php endif; ?>
                            <?php else: ?>
                            <span style="color:var(--neutral-300);">—</span>
                            <?php endif; ?>
                        </td>
                        <td style="max-width:280px;font-size:0.78rem;color:var(--neutral-600);"><?php echo htmlspecialchars($e['description'] ?? ''); ?></td>
                        <td style="font-size:0.72rem;color:var(--neutral-400);"><?php echo htmlspecialchars($e['ip_address'] ?? '—'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once '../layouts/footer.php'; ?>
