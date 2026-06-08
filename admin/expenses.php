<?php
// ============================================================
// المسار: admin/expenses.php
// الوظيفة: تسجيل وإدارة مصروفات الفروع — قائمة + إضافة + فلترة
// الصلاحية: super_admin | branch_admin
// ============================================================

declare(strict_types=1);

$current_page = 'expenses';
require_once __DIR__ . '/../includes/layout/header.php';
require_role(['super_admin', 'branch_admin']);

$pdo      = db();
$audit    = new AuditLogger($pdo);
$role     = Auth::getRole();
$user_id  = Auth::getUserId();
$user_bid = Auth::getBranchId();
$is_super = ($role === 'super_admin');

// ============================================================
// تصنيفات المصروفات
// ============================================================
$expense_categories = $is_rtl ? [
    'إيجار',
    'رواتب',
    'مواصلات',
    'كهرباء وماء',
    'مستلزمات مكتبية',
    'صيانة',
    'تسويق وإعلان',
    'مصروفات متنوعة',
] : [
    'Rent',
    'Salaries',
    'Transportation',
    'Utilities',
    'Office Supplies',
    'Maintenance',
    'Marketing & Ads',
    'Miscellaneous',
];

// ============================================================
// معالجة POST
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submitted_token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($submitted_token)) {
        flash('error', $lang['csrf_invalid']);
        redirect('expenses.php');
    }

    $action = $_POST['action'] ?? '';

    // ——— إضافة مصروف ———
    if ($action === 'add_expense') {
        $category = trim($_POST['category'] ?? '');
        $amount   = (float)filter_var($_POST['amount'] ?? 0, FILTER_VALIDATE_FLOAT);
        $desc     = trim($_POST['description']   ?? '');
        $exp_date = trim($_POST['expense_date']  ?? date('Y-m-d'));
        $branch_id = $is_super
            ? (sanitize_int($_POST['branch_id'] ?? 0) ?: null)
            : $user_bid;

        if ($category === '' || $amount <= 0) {
            flash('error', $lang['required_field']);
            redirect('expenses.php');
        }

        // التحقق من صحة التاريخ
        $exp_date_obj = DateTime::createFromFormat('Y-m-d', $exp_date);
        if (!$exp_date_obj) {
            $exp_date = date('Y-m-d');
        }

        try {
            $stmt = $pdo->prepare('
                INSERT INTO expenses
                    (branch_id, category, amount, description, expense_date, created_by, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ');
            $stmt->execute([$branch_id, $category, $amount, $desc, $exp_date, $user_id]);
            $new_id = (int)$pdo->lastInsertId();

            $audit->log('create', 'expenses', $new_id, null, [
                'category' => $category,
                'amount'   => $amount,
                'date'     => $exp_date,
            ]);
            regenerate_csrf_token();
            flash('success', $lang['saved']);
        } catch (Throwable $e) {
            error_log('[TopShine expenses add] ' . $e->getMessage());
            flash('error', $lang['error']);
        }
        redirect('expenses.php');
    }

    // ——— أرشفة مصروف (Soft Delete — نُضيف عمود archived=1 بدل الحذف الفعلي) ———
    // ملاحظة: جدول expenses لا يحتوي عمود status في الـ schema الأصلي
    // لذا نستخدم نهج الأرشفة عبر عمود description مع بادئة [ARCHIVED] كحل آمن
    // أو ALTER TABLE لإضافة عمود archived — نختار الحل الأكثر أماناً بدون تغيير الـ schema:
    // نعلّم المصروف بإضافة بادئة [ARCHIVED] في الـ description ونخفيه من الجداول
    if ($action === 'archive_expense') {
        $exp_id = sanitize_int($_POST['expense_id'] ?? 0);
        if ($exp_id > 0) {
            try {
                $chk = $pdo->prepare(
                    "SELECT id, branch_id, created_by, category, amount, description
                     FROM expenses WHERE id = ? LIMIT 1"
                );
                $chk->execute([$exp_id]);
                $exp_row = $chk->fetch();

                if (!$exp_row) {
                    flash('error', $lang['not_found']);
                    redirect('expenses.php');
                }

                // تحقق الصلاحية: super_admin أو المُسجِّل من نفس الفرع
                $can_archive = $is_super
                    || ((int)$exp_row['branch_id'] === (int)$user_bid
                        && (int)$exp_row['created_by'] === (int)$user_id);

                if (!$can_archive) {
                    flash('error', $lang['unauthorized']);
                    redirect('expenses.php');
                }

                // Soft archive: نُضيف بادئة [ARCHIVED] في الـ description
                $new_desc = '[ARCHIVED] ' . ($exp_row['description'] ?? '');
                $pdo->prepare(
                    "UPDATE expenses SET description = ? WHERE id = ? LIMIT 1"
                )->execute([$new_desc, $exp_id]);

                $audit->log(
                    'delete',
                    'expenses',
                    $exp_id,
                    ['category' => $exp_row['category'], 'amount' => $exp_row['amount'], 'archived' => false],
                    ['category' => $exp_row['category'], 'amount' => $exp_row['amount'], 'archived' => true]
                );
                regenerate_csrf_token();
                flash('success', $lang['deactivated']);

            } catch (Throwable $e) {
                error_log('[TopShine expenses archive] ' . $e->getMessage());
                flash('error', $lang['error']);
            }
        }
        redirect('expenses.php');
    }
}

// ============================================================
// فلاتر القائمة
// ============================================================
$filter_category   = trim($_GET['category']    ?? '');
$filter_date_from  = trim($_GET['date_from']   ?? date('Y-m-01')); // أول الشهر افتراضياً
$filter_date_to    = trim($_GET['date_to']     ?? date('Y-m-d'));
$filter_branch     = $is_super ? sanitize_int($_GET['branch_id'] ?? 0) : $user_bid;

$where_parts = [];
$params      = [];

// قيد الفرع
if (!$is_super) {
    $where_parts[] = 'e.branch_id = ?';
    $params[]      = $user_bid;
} elseif ($filter_branch > 0) {
    $where_parts[] = 'e.branch_id = ?';
    $params[]      = $filter_branch;
}

if ($filter_category !== '') {
    $where_parts[] = 'e.category = ?';
    $params[]      = $filter_category;
}

if ($filter_date_from !== '') {
    $where_parts[] = 'e.expense_date >= ?';
    $params[]      = $filter_date_from;
}

if ($filter_date_to !== '') {
    $where_parts[] = 'e.expense_date <= ?';
    $params[]      = $filter_date_to;
}

// إخفاء المصروفات المؤرشفة (Soft Delete)
$where_parts[] = "e.description NOT LIKE '[ARCHIVED]%'";

$where_sql = $where_parts ? 'WHERE ' . implode(' AND ', $where_parts) : '';

// إجمالي المصروفات للفترة
$total_stmt = $pdo->prepare("SELECT COALESCE(SUM(e.amount),0) FROM expenses e $where_sql");
$total_stmt->execute($params);
$total_expenses = (float)$total_stmt->fetchColumn();

// تجميع حسب التصنيف
$cat_params = $params;
$cat_stmt   = $pdo->prepare("
    SELECT e.category, COUNT(*) AS cnt, SUM(e.amount) AS total
    FROM expenses e
    $where_sql
    GROUP BY e.category
    ORDER BY total DESC
");
$cat_stmt->execute($cat_params);
$by_category = $cat_stmt->fetchAll();

// Pagination
$page_num  = max(1, (int)($_GET['p'] ?? 1));
$per_page  = 25;

$count_st = $pdo->prepare("SELECT COUNT(*) FROM expenses e $where_sql");
$count_st->execute($params);
$total_records = (int)$count_st->fetchColumn();
$total_pages   = max(1, (int)ceil($total_records / $per_page));
$offset        = ($page_num - 1) * $per_page;

$list_stmt = $pdo->prepare("
    SELECT
        e.id, e.category, e.amount, e.description,
        e.expense_date, e.created_at, e.created_by,
        b.name AS branch_name,
        u.name AS created_by_name
    FROM expenses e
    LEFT JOIN branches b ON b.id = e.branch_id
    LEFT JOIN users    u ON u.id = e.created_by
    $where_sql
    ORDER BY e.expense_date DESC, e.created_at DESC
    LIMIT $per_page OFFSET $offset
");
$list_stmt->execute($params);
$expenses = $list_stmt->fetchAll();

// قائمة الفروع
$branches_list = [];
if ($is_super) {
    $br_stmt = $pdo->query("SELECT id, name FROM branches WHERE status='active' ORDER BY name");
    $branches_list = $br_stmt->fetchAll();
}

$depth       = 1;
$assets_path = '../assets/';
?>

<?php require_once __DIR__ . '/../includes/layout/sidebar.php'; ?>

<div class="main-content">

    <?php show_flash(); ?>

    <!-- ================================================== -->
    <!-- رأس الصفحة                                          -->
    <!-- ================================================== -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <h4 class="mb-0" style="color:var(--ts-gold)">
            <i class="bi bi-cash-stack me-2"></i>
            <?= $lang['expenses'] ?>
        </h4>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addExpenseModal">
            <i class="bi bi-plus-lg me-1"></i>
            <?= $is_rtl ? 'تسجيل مصروف' : 'Add Expense' ?>
        </button>
    </div>

    <!-- ================================================== -->
    <!-- KPI Cards                                            -->
    <!-- ================================================== -->
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card">
                <div class="card-body text-center">
                    <div class="small text-muted mb-1">
                        <?= $is_rtl ? 'إجمالي المصروفات' : 'Total Expenses' ?>
                    </div>
                    <div class="fs-4 fw-bold" style="color:var(--ts-danger)">
                        <?= format_money($total_expenses) ?>
                    </div>
                    <small class="text-muted">
                        <?= $filter_date_from ?: '—' ?> → <?= $filter_date_to ?: '—' ?>
                    </small>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card">
                <div class="card-body text-center">
                    <div class="small text-muted mb-1">
                        <?= $is_rtl ? 'عدد السجلات' : 'Records Count' ?>
                    </div>
                    <div class="fs-4 fw-bold" style="color:var(--ts-gold)">
                        <?= number_format($total_records) ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card">
                <div class="card-body text-center">
                    <div class="small text-muted mb-1">
                        <?= $is_rtl ? 'متوسط المصروف' : 'Average Expense' ?>
                    </div>
                    <div class="fs-4 fw-bold" style="color:var(--ts-silver)">
                        <?= $total_records > 0 ? format_money($total_expenses / $total_records) : '0.00 SDG' ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <!-- ========== عمود الفلاتر + التوزيع ========== -->
        <div class="col-lg-3">

            <!-- فلاتر -->
            <div class="card mb-3">
                <div class="card-header">
                    <i class="bi bi-funnel me-1"></i><?= $lang['filter'] ?>
                </div>
                <div class="card-body">
                    <form method="GET">
                        <?php if ($is_super): ?>
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
                        <?php endif; ?>
                        <div class="mb-3">
                            <label class="form-label small"><?= $lang['expense_category'] ?></label>
                            <select name="category" class="form-select form-select-sm ts-select">
                                <option value=""><?= $is_rtl ? 'الكل' : 'All' ?></option>
                                <?php foreach ($expense_categories as $cat): ?>
                                <option value="<?= htmlspecialchars($cat) ?>"
                                        <?= $filter_category === $cat ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cat) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
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
                        <button type="submit" class="btn btn-primary btn-sm w-100">
                            <i class="bi bi-funnel me-1"></i><?= $lang['filter'] ?>
                        </button>
                        <a href="expenses.php" class="btn btn-outline-secondary btn-sm w-100 mt-2">
                            <?= $lang['reset'] ?>
                        </a>
                    </form>
                </div>
            </div>

            <!-- توزيع حسب التصنيف -->
            <?php if (!empty($by_category)): ?>
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-pie-chart me-1"></i>
                    <?= $is_rtl ? 'حسب التصنيف' : 'By Category' ?>
                </div>
                <div class="card-body p-0">
                    <ul class="list-group list-group-flush">
                        <?php foreach ($by_category as $cat_row):
                            $pct = $total_expenses > 0
                                ? round((float)$cat_row['total'] / $total_expenses * 100, 1)
                                : 0;
                        ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center py-2"
                            style="background:transparent;border-color:var(--ts-border);color:var(--ts-text-primary)">
                            <div>
                                <small class="fw-semibold"><?= htmlspecialchars($cat_row['category']) ?></small>
                                <div class="progress mt-1" style="height:4px;width:100px;background:var(--ts-border)">
                                    <div class="progress-bar" style="width:<?= $pct ?>%;background:var(--ts-gold)"></div>
                                </div>
                            </div>
                            <div class="text-end">
                                <div class="small" style="color:var(--ts-gold)"><?= format_money((float)$cat_row['total']) ?></div>
                                <div class="smaller text-muted"><?= $pct ?>%</div>
                            </div>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- ========== عمود الجدول ========== -->
        <div class="col-lg-9">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><?= $lang['expenses'] ?></span>
                    <small class="text-muted">
                        <?= $total_records ?> <?= $lang['results'] ?>
                    </small>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($expenses)): ?>
                    <div class="text-center py-5 text-muted">
                        <i class="bi bi-cash-stack fs-1 d-block mb-2"></i>
                        <?= $lang['no_results'] ?>
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th><?= $lang['expense_date'] ?></th>
                                    <th><?= $lang['expense_category'] ?></th>
                                    <th><?= $lang['expense_amount'] ?></th>
                                    <th><?= $lang['expense_desc'] ?></th>
                                    <?php if ($is_super): ?><th><?= $lang['branch'] ?></th><?php endif; ?>
                                    <th><?= $is_rtl ? 'مُسجَّل بواسطة' : 'Recorded By' ?></th>
                                    <th class="text-center"><?= $lang['action'] ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($expenses as $exp): ?>
                                <tr>
                                    <td>
                                        <span class="font-monospace small">
                                            <?= htmlspecialchars($exp['expense_date']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge"
                                              style="background:var(--ts-bg-dark);color:var(--ts-gold);border:1px solid var(--ts-border-gold)">
                                            <?= htmlspecialchars($exp['category']) ?>
                                        </span>
                                    </td>
                                    <td class="fw-bold" style="color:var(--ts-danger)">
                                        <?= format_money((float)$exp['amount']) ?>
                                    </td>
                                    <td>
                                        <small class="text-muted">
                                            <?= htmlspecialchars($exp['description'] ?: '—') ?>
                                        </small>
                                    </td>
                                    <?php if ($is_super): ?>
                                    <td><small><?= htmlspecialchars($exp['branch_name'] ?? '—') ?></small></td>
                                    <?php endif; ?>
                                    <td><small><?= htmlspecialchars($exp['created_by_name'] ?? '—') ?></small></td>
                                    <td class="text-center">
                                        <!-- أرشفة (Soft Delete): فقط المُسجِّل أو super_admin -->
                                        <?php
                                        $can_archive = $is_super || (int)$exp['created_by'] === (int)$user_id;
                                        $is_archived = str_starts_with($exp['description'] ?? '', '[ARCHIVED]');
                                        ?>
                                        <?php if ($can_archive && !$is_archived): ?>
                                        <form method="POST" class="d-inline"
                                              onsubmit="return confirm('<?= $is_rtl ? 'أرشفة هذا المصروف وإخفاؤه؟' : 'Archive this expense?' ?>')">
                                            <input type="hidden" name="action"     value="archive_expense">
                                            <input type="hidden" name="expense_id" value="<?= $exp['id'] ?>">
                                            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                            <button class="btn btn-sm btn-outline-danger" title="<?= $lang['deactivate'] ?>">
                                                <i class="bi bi-archive"></i>
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr style="background:var(--ts-bg-dark)">
                                    <td colspan="<?= $is_super ? 2 : 2 ?>" class="fw-bold" style="color:var(--ts-gold)">
                                        <?= $is_rtl ? 'الإجمالي' : 'Total' ?>
                                    </td>
                                    <td class="fw-bold" style="color:var(--ts-danger)">
                                        <?= format_money($total_expenses) ?>
                                    </td>
                                    <td colspan="<?= $is_super ? 4 : 3 ?>"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                    <div class="d-flex justify-content-between align-items-center p-3">
                        <small class="text-muted">
                            <?= $lang['showing'] ?> <?= $offset + 1 ?> - <?= min($offset + $per_page, $total_records) ?>
                            <?= $lang['of'] ?> <?= $total_records ?> <?= $lang['results'] ?>
                        </small>
                        <nav>
                            <ul class="pagination pagination-sm mb-0">
                                <?php for ($p = 1; $p <= $total_pages; $p++):
                                    $q_arr = array_merge($_GET, ['p' => $p]);
                                ?>
                                <li class="page-item <?= $p === $page_num ? 'active' : '' ?>">
                                    <a class="page-link" href="?<?= http_build_query($q_arr) ?>"><?= $p ?></a>
                                </li>
                                <?php endfor; ?>
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
<!-- Modal: إضافة مصروف                                  -->
<!-- ================================================== -->
<div class="modal fade" id="addExpenseModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content ts-modal">
            <div class="modal-header">
                <h5 class="modal-title" style="color:var(--ts-gold)">
                    <i class="bi bi-cash-stack me-2"></i>
                    <?= $is_rtl ? 'تسجيل مصروف جديد' : 'Add New Expense' ?>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action"     value="add_expense">
                    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">

                    <?php if ($is_super): ?>
                    <div class="mb-3">
                        <label class="form-label"><?= $lang['branch'] ?></label>
                        <select name="branch_id" class="form-select ts-select">
                            <option value=""><?= $is_rtl ? 'مصروف عام' : 'General Expense' ?></option>
                            <?php foreach ($branches_list as $br): ?>
                            <option value="<?= $br['id'] ?>"><?= htmlspecialchars($br['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>

                    <div class="mb-3">
                        <label class="form-label"><?= $lang['expense_category'] ?> <span class="text-danger">*</span></label>
                        <select name="category" class="form-select ts-select" required>
                            <option value=""><?= $is_rtl ? 'اختر التصنيف...' : 'Choose category...' ?></option>
                            <?php foreach ($expense_categories as $cat): ?>
                            <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label"><?= $lang['expense_amount'] ?> <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="number" name="amount" class="form-control ts-input"
                                   step="0.01" min="0.01" required
                                   placeholder="0.00">
                            <span class="input-group-text" style="background:var(--ts-bg-dark);color:var(--ts-gold);border-color:var(--ts-border)">
                                SDG
                            </span>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label"><?= $lang['expense_date'] ?></label>
                        <input type="date" name="expense_date" class="form-control ts-input"
                               value="<?= date('Y-m-d') ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label"><?= $lang['expense_desc'] ?></label>
                        <textarea name="description" class="form-control ts-input" rows="2"
                                  placeholder="<?= $is_rtl ? 'وصف تفصيلي اختياري...' : 'Optional description...' ?>"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= $lang['cancel'] ?></button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-1"></i><?= $lang['save'] ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/layout/footer.php'; ?>
