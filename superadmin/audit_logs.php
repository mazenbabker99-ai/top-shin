<?php
// ============================================================
// المسار: superadmin/audit_logs.php
// الوظيفة: عرض سجل المراجعة الكامل — للقراءة فقط
// الصلاحية: super_admin فقط
// ============================================================

declare(strict_types=1);

$current_page = 'audit_log';
require_once __DIR__ . '/../includes/layout/header.php';
require_role(['super_admin']);

$pdo = db();

// ============================================================
// فلاتر
// ============================================================
$filter_user      = sanitize_int($_GET['user_id']    ?? 0);
$filter_branch    = sanitize_int($_GET['branch_id']  ?? 0);
$filter_action    = trim($_GET['action_type']        ?? '');
$filter_table     = trim($_GET['table_name']         ?? '');
$filter_date_from = trim($_GET['date_from']          ?? date('Y-m-01'));
$filter_date_to   = trim($_GET['date_to']            ?? date('Y-m-d'));

$valid_actions = [
    'create', 'update', 'delete',
    'login', 'logout', 'login_failed', 'login_blocked',
    'adjustment',
];

$where_parts = [];
$params      = [];

if ($filter_user > 0) {
    $where_parts[] = 'al.user_id = ?';
    $params[]      = $filter_user;
}
if ($filter_branch > 0) {
    $where_parts[] = 'al.branch_id = ?';
    $params[]      = $filter_branch;
}
if ($filter_action !== '' && in_array($filter_action, $valid_actions, true)) {
    $where_parts[] = 'al.action = ?';
    $params[]      = $filter_action;
}
if ($filter_table !== '') {
    $where_parts[] = 'al.table_name = ?';
    $params[]      = $filter_table;
}
if ($filter_date_from !== '') {
    $where_parts[] = 'DATE(al.created_at) >= ?';
    $params[]      = $filter_date_from;
}
if ($filter_date_to !== '') {
    $where_parts[] = 'DATE(al.created_at) <= ?';
    $params[]      = $filter_date_to;
}

$where_sql = $where_parts ? 'WHERE ' . implode(' AND ', $where_parts) : '';

// ============================================================
// Pagination
// ============================================================
$page_num  = max(1, (int)($_GET['p'] ?? 1));
$per_page  = 50;

$count_st = $pdo->prepare("SELECT COUNT(*) FROM audit_logs al $where_sql");
$count_st->execute($params);
$total_records = (int)$count_st->fetchColumn();
$total_pages   = max(1, (int)ceil($total_records / $per_page));
$offset        = ($page_num - 1) * $per_page;

$logs_stmt = $pdo->prepare("
    SELECT
        al.id, al.user_id, al.user_name, al.branch_id, al.action,
        al.table_name, al.record_id, al.old_data, al.new_data,
        al.ip_address, al.created_at,
        b.name AS branch_name
    FROM audit_logs al
    LEFT JOIN branches b ON b.id = al.branch_id
    $where_sql
    ORDER BY al.created_at DESC
    LIMIT $per_page OFFSET $offset
");
$logs_stmt->execute($params);
$logs = $logs_stmt->fetchAll();

// ============================================================
// بيانات الفلاتر
// ============================================================
$branches_list = $pdo->query(
    "SELECT id, name FROM branches ORDER BY name"
)->fetchAll();

$users_list = $pdo->query(
    "SELECT id, name, username FROM users ORDER BY name"
)->fetchAll();

// الجداول المتاحة للفلترة
$tables_list = $pdo->query(
    "SELECT DISTINCT table_name FROM audit_logs WHERE table_name != '' ORDER BY table_name"
)->fetchAll(PDO::FETCH_COLUMN);

// ============================================================
// دوال مساعدة للعرض
// ============================================================
$action_styles = [
    'create'        => ['color' => 'var(--ts-success)',  'icon' => 'plus-circle',         'label' => $lang['action_create']],
    'update'        => ['color' => 'var(--ts-gold)',     'icon' => 'pencil-square',        'label' => $lang['action_update']],
    'delete'        => ['color' => 'var(--ts-danger)',   'icon' => 'trash',                'label' => $lang['action_delete']],
    'login'         => ['color' => 'var(--ts-silver)',   'icon' => 'box-arrow-in-right',   'label' => $lang['action_login']],
    'logout'        => ['color' => 'var(--ts-text-muted)','icon'=> 'box-arrow-right',      'label' => $lang['action_logout']],
    'login_failed'  => ['color' => 'var(--ts-danger)',   'icon' => 'shield-x',             'label' => $lang['action_login_failed']],
    'login_blocked' => ['color' => '#ff6b35',            'icon' => 'slash-circle',         'label' => $lang['action_login_blocked']],
    'adjustment'    => ['color' => '#9b59b6',            'icon' => 'arrow-left-right',     'label' => $is_rtl ? 'تعديل مخزون' : 'Stock Adjustment'],
];

$depth       = 1;
$assets_path = '../assets';
?>

<?php require_once __DIR__ . '/../includes/layout/sidebar.php'; ?>

<div class="main-content">

    <?php show_flash(); ?>

    <!-- رأس الصفحة -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <h4 class="mb-0" style="color:var(--ts-gold)">
            <i class="bi bi-shield-check me-2"></i>
            <?= $lang['audit_log'] ?>
        </h4>
        <span class="badge" style="background:var(--ts-bg-dark);color:var(--ts-silver);border:1px solid var(--ts-border);font-size:.8rem;padding:.4rem .8rem">
            <i class="bi bi-eye-slash me-1"></i>
            <?= $is_rtl ? 'للقراءة فقط' : 'Read Only' ?>
        </span>
    </div>

    <div class="row g-4">

        <!-- ========== عمود الفلاتر ========== -->
        <div class="col-lg-3">
            <div class="card mb-3">
                <div class="card-header">
                    <i class="bi bi-funnel me-1"></i><?= $lang['filter'] ?>
                </div>
                <div class="card-body">
                    <form method="GET">
                        <div class="mb-3">
                            <label class="form-label small"><?= $lang['date_from'] ?></label>
                            <input type="date" name="date_from" class="form-control form-control-sm ts-input"
                                   value="<?= htmlspecialchars($filter_date_from) ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label small"><?= $lang['date_to'] ?></label>
                            <input type="date" name="date_to" class="form-control form-control-sm ts-input"
                                   value="<?= htmlspecialchars($filter_date_to) ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label small"><?= $is_rtl ? 'المستخدم' : 'User' ?></label>
                            <select name="user_id" class="form-select form-select-sm ts-select">
                                <option value=""><?= $is_rtl ? 'كل المستخدمين' : 'All Users' ?></option>
                                <?php foreach ($users_list as $u): ?>
                                <option value="<?= $u['id'] ?>" <?= $filter_user == $u['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($u['name']) ?> (@<?= htmlspecialchars($u['username']) ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small"><?= $lang['branch'] ?></label>
                            <select name="branch_id" class="form-select form-select-sm ts-select">
                                <option value=""><?= $is_rtl ? 'كل الفروع' : 'All Branches' ?></option>
                                <?php foreach ($branches_list as $br): ?>
                                <option value="<?= $br['id'] ?>" <?= $filter_branch == $br['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($br['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small"><?= $lang['action'] ?></label>
                            <select name="action_type" class="form-select form-select-sm ts-select">
                                <option value=""><?= $is_rtl ? 'كل الإجراءات' : 'All Actions' ?></option>
                                <?php foreach ($action_styles as $ak => $av): ?>
                                <option value="<?= $ak ?>" <?= $filter_action === $ak ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($av['label']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small"><?= $lang['table'] ?></label>
                            <select name="table_name" class="form-select form-select-sm ts-select">
                                <option value=""><?= $is_rtl ? 'كل الجداول' : 'All Tables' ?></option>
                                <?php foreach ($tables_list as $tbl): ?>
                                <option value="<?= htmlspecialchars($tbl) ?>"
                                        <?= $filter_table === $tbl ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($tbl) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm w-100">
                            <i class="bi bi-funnel me-1"></i><?= $lang['filter'] ?>
                        </button>
                        <a href="audit_logs.php" class="btn btn-outline-secondary btn-sm w-100 mt-2">
                            <?= $lang['reset'] ?>
                        </a>
                    </form>
                </div>
            </div>

            <!-- إحصائية سريعة -->
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-bar-chart me-1"></i>
                    <?= $is_rtl ? 'إجمالي النتائج' : 'Total Results' ?>
                </div>
                <div class="card-body text-center">
                    <div class="fs-3 fw-bold" style="color:var(--ts-gold)">
                        <?= number_format($total_records) ?>
                    </div>
                    <small class="text-muted"><?= $lang['results'] ?></small>
                </div>
            </div>
        </div>

        <!-- ========== عمود السجل ========== -->
        <div class="col-lg-9">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><?= $lang['audit_log'] ?></span>
                    <small class="text-muted">
                        <?= $lang['showing'] ?> <?= $offset + 1 ?>–<?= min($offset + $per_page, $total_records) ?>
                        <?= $lang['of'] ?> <?= $total_records ?>
                    </small>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($logs)): ?>
                        <div class="text-center py-5 text-muted">
                            <i class="bi bi-shield-check fs-1 d-block mb-2"></i>
                            <?= $lang['no_results'] ?>
                        </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0" id="auditTable">
                            <thead>
                                <tr>
                                    <th><?= $is_rtl ? 'التاريخ والوقت' : 'Date & Time' ?></th>
                                    <th><?= $is_rtl ? 'المستخدم' : 'User' ?></th>
                                    <th><?= $lang['branch'] ?></th>
                                    <th><?= $lang['action'] ?></th>
                                    <th><?= $lang['table'] ?></th>
                                    <th><?= $lang['record_id'] ?></th>
                                    <th><?= $lang['ip_address'] ?></th>
                                    <th class="text-center"><?= $lang['details'] ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($logs as $log):
                                    $style = $action_styles[$log['action']]
                                          ?? ['color' => 'var(--ts-silver)', 'icon' => 'circle', 'label' => $log['action']];
                                    $has_data = ($log['old_data'] !== null || $log['new_data'] !== null);
                                ?>
                                <tr class="log-row"
                                    data-id="<?= $log['id'] ?>"
                                    data-old="<?= htmlspecialchars($log['old_data'] ?? 'null', ENT_QUOTES) ?>"
                                    data-new="<?= htmlspecialchars($log['new_data'] ?? 'null', ENT_QUOTES) ?>"
                                    data-action="<?= htmlspecialchars($log['action']) ?>"
                                    data-table="<?= htmlspecialchars($log['table_name']) ?>"
                                    data-record="<?= $log['record_id'] ?? '' ?>"
                                    data-user="<?= htmlspecialchars($log['user_name'] ?? '') ?>">
                                    <td>
                                        <span class="font-monospace small">
                                            <?= date('Y-m-d', strtotime($log['created_at'])) ?>
                                        </span><br>
                                        <small class="text-muted font-monospace">
                                            <?= date('H:i:s', strtotime($log['created_at'])) ?>
                                        </small>
                                    </td>
                                    <td>
                                        <small class="fw-semibold">
                                            <?= htmlspecialchars($log['user_name'] ?? '—') ?>
                                        </small>
                                    </td>
                                    <td>
                                        <small class="text-muted">
                                            <?= htmlspecialchars($log['branch_name'] ?? '—') ?>
                                        </small>
                                    </td>
                                    <td>
                                        <span style="color:<?= $style['color'] ?>;font-size:.82rem;font-weight:600">
                                            <i class="bi bi-<?= $style['icon'] ?> me-1"></i>
                                            <?= htmlspecialchars($style['label']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($log['table_name']): ?>
                                        <code style="color:var(--ts-silver);font-size:.78rem;background:var(--ts-bg-dark);padding:.1rem .4rem;border-radius:3px">
                                            <?= htmlspecialchars($log['table_name']) ?>
                                        </code>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <small class="text-muted font-monospace">
                                            <?= $log['record_id'] ?? '—' ?>
                                        </small>
                                    </td>
                                    <td>
                                        <small class="text-muted font-monospace">
                                            <?= htmlspecialchars($log['ip_address'] ?? '—') ?>
                                        </small>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($has_data): ?>
                                        <button class="btn btn-sm btn-outline-secondary"
                                                onclick="showLogDetail(this.closest('tr'))"
                                                title="<?= $lang['details'] ?>">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                    <div class="d-flex justify-content-between align-items-center p-3 flex-wrap gap-2">
                        <small class="text-muted">
                            <?= $lang['showing'] ?> <?= $offset + 1 ?>–<?= min($offset + $per_page, $total_records) ?>
                            <?= $lang['of'] ?> <?= $total_records ?> <?= $lang['results'] ?>
                        </small>
                        <nav>
                            <ul class="pagination pagination-sm mb-0">
                                <?php
                                // عرض max 10 صفحات
                                $p_start = max(1, $page_num - 4);
                                $p_end   = min($total_pages, $p_start + 9);
                                if ($p_start > 1): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['p' => 1])) ?>">1</a>
                                    </li>
                                    <?php if ($p_start > 2): ?>
                                        <li class="page-item disabled"><span class="page-link">…</span></li>
                                    <?php endif; ?>
                                <?php endif; ?>

                                <?php for ($p = $p_start; $p <= $p_end; $p++): ?>
                                <li class="page-item <?= $p === $page_num ? 'active' : '' ?>">
                                    <a class="page-link"
                                       href="?<?= http_build_query(array_merge($_GET, ['p' => $p])) ?>">
                                        <?= $p ?>
                                    </a>
                                </li>
                                <?php endfor; ?>

                                <?php if ($p_end < $total_pages): ?>
                                    <?php if ($p_end < $total_pages - 1): ?>
                                        <li class="page-item disabled"><span class="page-link">…</span></li>
                                    <?php endif; ?>
                                    <li class="page-item">
                                        <a class="page-link"
                                           href="?<?= http_build_query(array_merge($_GET, ['p' => $total_pages])) ?>">
                                            <?= $total_pages ?>
                                        </a>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </nav>
                    </div>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div><!-- /.main-content -->

<!-- ================================================== -->
<!-- Modal: تفاصيل السجل — البيانات القديمة والجديدة    -->
<!-- ================================================== -->
<div class="modal fade" id="logDetailModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content ts-modal">
            <div class="modal-header">
                <h5 class="modal-title" style="color:var(--ts-gold)">
                    <i class="bi bi-journal-text me-2"></i>
                    <span id="modal_detail_title"><?= $lang['details'] ?></span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <!-- معلومات السجل -->
                <div class="row g-2 mb-3">
                    <div class="col-6 col-md-3">
                        <div class="small text-muted"><?= $lang['action'] ?></div>
                        <div id="detail_action" class="fw-semibold"></div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="small text-muted"><?= $lang['table'] ?></div>
                        <div id="detail_table" class="fw-semibold font-monospace"></div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="small text-muted"><?= $lang['record_id'] ?></div>
                        <div id="detail_record" class="fw-semibold"></div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="small text-muted"><?= $is_rtl ? 'المستخدم' : 'User' ?></div>
                        <div id="detail_user" class="fw-semibold"></div>
                    </div>
                </div>

                <hr style="border-color:var(--ts-border)">

                <!-- البيانات القديمة والجديدة -->
                <div class="row g-3">
                    <div class="col-md-6" id="old_data_col">
                        <div class="small fw-bold mb-2" style="color:var(--ts-danger)">
                            <i class="bi bi-dash-circle me-1"></i>
                            <?= $lang['old_data'] ?>
                        </div>
                        <div id="old_data_content" class="diff-panel"></div>
                    </div>
                    <div class="col-md-6" id="new_data_col">
                        <div class="small fw-bold mb-2" style="color:var(--ts-success)">
                            <i class="bi bi-plus-circle me-1"></i>
                            <?= $lang['new_data'] ?>
                        </div>
                        <div id="new_data_content" class="diff-panel"></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <?= $lang['close'] ?>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ================================================== -->
<!-- JavaScript                                          -->
<!-- ================================================== -->
<script>
function showLogDetail(row) {
    var oldRaw    = row.dataset.old;
    var newRaw    = row.dataset.new;
    var action    = row.dataset.action;
    var table     = row.dataset.table;
    var record    = row.dataset.record;
    var user      = row.dataset.user;

    // ملء معلومات السجل
    document.getElementById('detail_action').textContent = action;
    document.getElementById('detail_table').textContent  = table || '—';
    document.getElementById('detail_record').textContent = record || '—';
    document.getElementById('detail_user').textContent   = user  || '—';
    document.getElementById('modal_detail_title').textContent =
        (<?= json_encode($lang['details']) ?>) + ' — ' + table;

    // parse البيانات
    var oldData = null, newData = null;
    try { oldData = (oldRaw && oldRaw !== 'null') ? JSON.parse(oldRaw) : null; } catch(e) {}
    try { newData = (newRaw && newRaw !== 'null') ? JSON.parse(newRaw) : null; } catch(e) {}

    // بناء قائمة الحقول المتغيرة للمقارنة البصرية
    var allKeys = new Set();
    if (oldData && typeof oldData === 'object') Object.keys(oldData).forEach(k => allKeys.add(k));
    if (newData && typeof newData === 'object') Object.keys(newData).forEach(k => allKeys.add(k));

    function buildPanel(data, otherData, panelEl) {
        panelEl.innerHTML = '';
        if (!data) {
            panelEl.innerHTML = '<span class="text-muted small">—</span>';
            return;
        }
        if (typeof data !== 'object') {
            panelEl.innerHTML = '<pre class="diff-pre">' + escHtml(JSON.stringify(data, null, 2)) + '</pre>';
            return;
        }
        var table = document.createElement('table');
        table.className = 'diff-table';
        allKeys.forEach(function(key) {
            if (!(key in data)) return;
            var val      = data[key];
            var otherVal = (otherData && key in otherData) ? otherData[key] : undefined;
            var changed  = (otherData !== null && String(val) !== String(otherVal));
            var tr = document.createElement('tr');
            if (changed) tr.classList.add('diff-changed');
            tr.innerHTML =
                '<td class="diff-key">' + escHtml(key) + '</td>' +
                '<td class="diff-val">' + escHtml(val === null ? 'null' : String(val)) + '</td>';
            table.appendChild(tr);
        });
        panelEl.appendChild(table);
    }

    buildPanel(oldData, newData, document.getElementById('old_data_content'));
    buildPanel(newData, oldData, document.getElementById('new_data_content'));

    // إخفاء عمود إن لم تكن بيانات
    document.getElementById('old_data_col').style.display = oldData ? '' : 'none';
    document.getElementById('new_data_col').style.display = newData ? '' : 'none';

    new bootstrap.Modal(document.getElementById('logDetailModal')).show();
}

function escHtml(str) {
    return String(str)
        .replace(/&/g,'&amp;')
        .replace(/</g,'&lt;')
        .replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;');
}
</script>

<style>
/* ——— Layout ——— */
.main-content { padding: 1.5rem; }
.font-monospace { font-family: monospace; }

/* ——— Modal ——— */
.ts-modal         { background:var(--ts-bg-card); border-color:var(--ts-border-gold); color:var(--ts-text-primary); }
.ts-modal .modal-header { background:var(--ts-bg-dark); border-bottom-color:var(--ts-border-gold); }
.ts-modal .modal-footer { background:var(--ts-bg-dark); border-top-color:var(--ts-border); }

/* ——— Inputs ——— */
.ts-input  { background:var(--ts-bg-input)  !important; border-color:var(--ts-border) !important; color:var(--ts-text-primary) !important; }
.ts-select { background:var(--ts-bg-input)  !important; border-color:var(--ts-border) !important; color:var(--ts-text-primary) !important; }
.ts-input:focus, .ts-select:focus {
    border-color:var(--ts-gold) !important;
    box-shadow:0 0 0 .2rem rgba(201,168,76,.25) !important;
}

/* ——— Diff Panel ——— */
.diff-panel {
    background: var(--ts-bg-dark);
    border: 1px solid var(--ts-border);
    border-radius: 6px;
    padding: .75rem;
    min-height: 60px;
    max-height: 400px;
    overflow-y: auto;
}
.diff-table {
    width: 100%;
    border-collapse: collapse;
    font-size: .82rem;
}
.diff-table tr + tr { border-top: 1px solid var(--ts-border); }
.diff-key {
    padding: .25rem .5rem;
    color: var(--ts-silver);
    font-family: monospace;
    width: 40%;
    vertical-align: top;
    white-space: nowrap;
}
.diff-val {
    padding: .25rem .5rem;
    color: var(--ts-text-primary);
    font-family: monospace;
    word-break: break-all;
}
.diff-changed {
    background: rgba(201,168,76,.08);
}
.diff-changed .diff-key { color: var(--ts-gold); }
.diff-pre {
    margin: 0;
    font-size: .8rem;
    color: var(--ts-text-primary);
    white-space: pre-wrap;
    word-break: break-all;
}

/* ——— Log Row Hover ——— */
.log-row:hover { cursor: default; }
</style>

<?php require_once __DIR__ . '/../includes/layout/footer.php'; ?>
