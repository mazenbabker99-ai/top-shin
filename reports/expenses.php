<?php
// ============================================================
// المسار: reports/expenses.php
// الوظيفة: تقرير المصروفات — إجمالي مجمّع بالفئة + تفصيل + CSV
// الصلاحية: super_admin | branch_admin
// ============================================================

declare(strict_types=1);

$current_page = 'reports';
require_once __DIR__ . '/../includes/layout/header.php';
require_role(['super_admin', 'branch_admin']);

$pdo      = db();
$role     = Auth::getRole();
$is_super = ($role === 'super_admin');
$user_bid = Auth::getBranchId();

// ============================================================
// فلاتر
// ============================================================
$filter_date_from = trim($_GET['date_from']    ?? date('Y-m-01'));
$filter_date_to   = trim($_GET['date_to']      ?? date('Y-m-d'));
$filter_branch    = $is_super
    ? sanitize_int($_GET['branch_id']  ?? 0)
    : (int)$user_bid;
$filter_category  = trim($_GET['category']     ?? '');

// ============================================================
// بناء شرط WHERE
// ============================================================
$where_parts = [];
$params      = [];

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

$where_sql = $where_parts ? ('WHERE ' . implode(' AND ', $where_parts)) : '';

// ============================================================
// تصدير CSV
// ============================================================
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $csv_stmt = $pdo->prepare("
        SELECT
            e.expense_date,
            e.category,
            e.amount,
            e.description,
            b.name  AS branch_name,
            u.name  AS created_by_name
        FROM expenses e
        LEFT JOIN branches b ON b.id = e.branch_id
        LEFT JOIN users    u ON u.id = e.created_by
        $where_sql
        ORDER BY e.expense_date DESC, e.id DESC
    ");
    $csv_stmt->execute($params);
    $csv_rows = $csv_stmt->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="expenses_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($out, [
        $lang['expense_date'],
        $lang['expense_category'],
        $lang['expense_amount'],
        $lang['expense_desc'],
        $lang['branch'],
        $is_rtl ? 'أدخله' : 'Created By',
    ]);
    foreach ($csv_rows as $row) {
        fputcsv($out, [
            $row['expense_date'],
            $row['category'],
            $row['amount'],
            $row['description'] ?? '',
            $row['branch_name'] ?? '—',
            $row['created_by_name'] ?? '—',
        ]);
    }
    fclose($out);
    exit;
}

// ============================================================
// KPI — إجمالي المصروفات
// ============================================================
$kpi_stmt = $pdo->prepare("
    SELECT
        COALESCE(SUM(e.amount), 0) AS total_expenses,
        COUNT(e.id)                AS total_count,
        COALESCE(AVG(e.amount), 0) AS avg_expense
    FROM expenses e
    $where_sql
");
$kpi_stmt->execute($params);
$kpi = $kpi_stmt->fetch();

// مقارنة بالمبيعات لنفس الفترة
$sales_parts  = ["i2.status = 'completed'"];
$sales_params = [];
if (!$is_super) {
    $sales_parts[]  = 'i2.branch_id = ?';
    $sales_params[] = $user_bid;
} elseif ($filter_branch > 0) {
    $sales_parts[]  = 'i2.branch_id = ?';
    $sales_params[] = $filter_branch;
}
if ($filter_date_from !== '') { $sales_parts[] = 'DATE(i2.created_at) >= ?'; $sales_params[] = $filter_date_from; }
if ($filter_date_to   !== '') { $sales_parts[] = 'DATE(i2.created_at) <= ?'; $sales_params[] = $filter_date_to; }
$sales_where = 'WHERE ' . implode(' AND ', $sales_parts);

$sales_stmt   = $pdo->prepare("SELECT COALESCE(SUM(i2.total), 0) AS total FROM invoices i2 $sales_where");
$sales_stmt->execute($sales_params);
$total_sales  = (float)$sales_stmt->fetchColumn();
$total_exp    = (float)$kpi['total_expenses'];
$expense_rate = $total_sales > 0 ? round(($total_exp / $total_sales) * 100, 2) : 0.0;

// ============================================================
// مجمّع بالفئة
// ============================================================
$by_category_stmt = $pdo->prepare("
    SELECT
        e.category,
        COUNT(e.id)                AS entry_count,
        COALESCE(SUM(e.amount), 0) AS category_total,
        COALESCE(AVG(e.amount), 0) AS category_avg,
        MIN(e.expense_date)        AS first_entry,
        MAX(e.expense_date)        AS last_entry
    FROM expenses e
    $where_sql
    GROUP BY e.category
    ORDER BY category_total DESC
");
$by_category_stmt->execute($params);
$by_category = $by_category_stmt->fetchAll();

// ============================================================
// مجمّع بالفرع (super_admin فقط)
// ============================================================
$by_branch = [];
if ($is_super && $filter_branch === 0) {
    $br_params = array_slice($params, 0); // نسخة
    // إعادة بناء شرط بدون فرع
    $br_parts = array_filter($where_parts, fn($p) => !str_contains($p, 'e.branch_id'));
    $br_params_filtered = [];
    // نعيد بناء الـ params المطابقة
    foreach ($where_parts as $i => $wp) {
        if (!str_contains($wp, 'e.branch_id')) {
            $br_params_filtered[] = $params[$i];
        }
    }
    $br_where = $br_parts ? ('WHERE ' . implode(' AND ', array_values($br_parts))) : '';

    $by_branch_stmt = $pdo->prepare("
        SELECT
            b.name                     AS branch_name,
            COALESCE(SUM(e.amount), 0) AS branch_total,
            COUNT(e.id)                AS entry_count
        FROM expenses e
        LEFT JOIN branches b ON b.id = e.branch_id
        $br_where
        GROUP BY e.branch_id, b.name
        ORDER BY branch_total DESC
    ");
    $by_branch_stmt->execute($br_params_filtered);
    $by_branch = $by_branch_stmt->fetchAll();
}

// ============================================================
// توزيع يومي (للـ Chart)
// ============================================================
$chart_stmt = $pdo->prepare("
    SELECT
        e.expense_date             AS day,
        COALESCE(SUM(e.amount), 0) AS day_total,
        COUNT(e.id)                AS day_count
    FROM expenses e
    $where_sql
    GROUP BY e.expense_date
    ORDER BY e.expense_date ASC
");
$chart_stmt->execute($params);
$chart_rows    = $chart_stmt->fetchAll();
$chart_labels  = array_column($chart_rows, 'day');
$chart_amounts = array_map(fn($r) => (float)$r['day_total'], $chart_rows);

// ============================================================
// قائمة الفئات الموجودة (للفلتر)
// ============================================================
$categories_for_filter = $pdo->query(
    "SELECT DISTINCT category FROM expenses WHERE category IS NOT NULL AND category != '' ORDER BY category"
)->fetchAll(PDO::FETCH_COLUMN);

// ============================================================
// جدول التفصيلي — Pagination
// ============================================================
$page_num = max(1, (int)($_GET['p'] ?? 1));
$per_page = 30;

$count_st = $pdo->prepare("SELECT COUNT(*) FROM expenses e $where_sql");
$count_st->execute($params);
$total_records = (int)$count_st->fetchColumn();
$total_pages   = max(1, (int)ceil($total_records / $per_page));
$offset        = ($page_num - 1) * $per_page;

$list_stmt = $pdo->prepare("
    SELECT
        e.id, e.expense_date, e.category,
        e.amount, e.description,
        b.name  AS branch_name,
        u.name  AS created_by_name
    FROM expenses e
    LEFT JOIN branches b ON b.id = e.branch_id
    LEFT JOIN users    u ON u.id = e.created_by
    $where_sql
    ORDER BY e.expense_date DESC, e.id DESC
    LIMIT $per_page OFFSET $offset
");
$list_stmt->execute($params);
$expense_rows = $list_stmt->fetchAll();

// ============================================================
// بيانات الفلاتر
// ============================================================
$branches_list = [];
if ($is_super) {
    $branches_list = $pdo->query(
        "SELECT id, name FROM branches WHERE status='active' ORDER BY name"
    )->fetchAll();
}

// ألوان الفئات
$cat_colors = [
    '#C9A84C', '#28A745', '#17A2B8', '#DC3545',
    '#6F42C1', '#FD7E14', '#B0B3B8', '#20C997',
];
?>

<?php require_once __DIR__ . '/../includes/layout/sidebar.php'; ?>

<div class="main-content">

    <?php show_flash(); ?>

    <!-- رأس الصفحة -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <h4 class="mb-0" style="color:var(--ts-gold)">
            <i class="bi bi-wallet2 me-2"></i>
            <?= $lang['expenses_report'] ?>
        </h4>
        <div class="d-flex gap-2">
            <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>"
               class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-filetype-csv me-1"></i>
                <?= $is_rtl ? 'تصدير CSV' : 'Export CSV' ?>
            </a>
            <button onclick="window.print()" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-printer me-1"></i>
                <?= $lang['print'] ?>
            </button>
        </div>
    </div>

    <!-- ================================================== -->
    <!-- فلاتر                                               -->
    <!-- ================================================== -->
    <div class="card mb-4 no-print">
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-2">
                    <label class="form-label small"><?= $lang['date_from'] ?></label>
                    <input type="date" name="date_from" class="form-control form-control-sm ts-input"
                           value="<?= htmlspecialchars($filter_date_from) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small"><?= $lang['date_to'] ?></label>
                    <input type="date" name="date_to" class="form-control form-control-sm ts-input"
                           value="<?= htmlspecialchars($filter_date_to) ?>">
                </div>
                <?php if ($is_super): ?>
                <div class="col-md-2">
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
                <div class="col-md-3">
                    <label class="form-label small"><?= $lang['expense_category'] ?></label>
                    <select name="category" class="form-select form-select-sm ts-select">
                        <option value=""><?= $is_rtl ? 'كل الفئات' : 'All Categories' ?></option>
                        <?php foreach ($categories_for_filter as $cat): ?>
                        <option value="<?= htmlspecialchars($cat) ?>"
                                <?= $filter_category === $cat ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cat) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-primary btn-sm flex-grow-1">
                        <i class="bi bi-funnel me-1"></i><?= $lang['filter'] ?>
                    </button>
                    <a href="expenses.php" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-x"></i>
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- ================================================== -->
    <!-- KPI Cards                                           -->
    <!-- ================================================== -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card text-center">
                <div class="card-body py-3">
                    <div class="small text-muted mb-1">
                        <?= $lang['total_expenses'] ?>
                    </div>
                    <div class="fs-5 fw-bold" style="color:var(--ts-danger)">
                        <?= format_money($total_exp) ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card text-center">
                <div class="card-body py-3">
                    <div class="small text-muted mb-1">
                        <?= $is_rtl ? 'عدد السجلات' : 'Records Count' ?>
                    </div>
                    <div class="fs-5 fw-bold" style="color:var(--ts-gold)">
                        <?= number_format((int)$kpi['total_count']) ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card text-center">
                <div class="card-body py-3">
                    <div class="small text-muted mb-1">
                        <?= $is_rtl ? 'متوسط المصروف' : 'Avg Expense' ?>
                    </div>
                    <div class="fs-5 fw-bold" style="color:var(--ts-silver)">
                        <?= format_money((float)$kpi['avg_expense']) ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card text-center">
                <div class="card-body py-3">
                    <div class="small text-muted mb-1">
                        <?= $is_rtl ? 'نسبة المصروفات من المبيعات' : 'Expenses / Sales Ratio' ?>
                    </div>
                    <div class="fs-5 fw-bold"
                         style="color:<?= $expense_rate > 20 ? 'var(--ts-danger)' : ($expense_rate > 10 ? 'var(--ts-warning)' : 'var(--ts-success)') ?>">
                        <?= $expense_rate ?>%
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ================================================== -->
    <!-- Chart + ملخص بالفرع                                -->
    <!-- ================================================== -->
    <div class="row g-3 mb-4">
        <!-- Chart التوزيع اليومي -->
        <?php if (!empty($chart_rows)): ?>
        <div class="col-md-<?= (!empty($by_branch)) ? '8' : '12' ?> no-print">
            <div class="card h-100">
                <div class="card-header">
                    <i class="bi bi-bar-chart me-1"></i>
                    <?= $is_rtl ? 'المصروفات اليومية' : 'Daily Expenses' ?>
                </div>
                <div class="card-body" style="height:260px">
                    <canvas id="expensesChart"></canvas>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ملخص بالفرع (super_admin فقط) -->
        <?php if (!empty($by_branch)): ?>
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-header">
                    <i class="bi bi-diagram-3 me-1"></i>
                    <?= $is_rtl ? 'المصروفات بالفرع' : 'Expenses by Branch' ?>
                </div>
                <div class="card-body">
                    <?php foreach ($by_branch as $bb): ?>
                    <?php
                    $pct_b = $total_exp > 0
                        ? round(((float)$bb['branch_total'] / $total_exp) * 100, 1)
                        : 0;
                    ?>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between small mb-1">
                            <span><?= htmlspecialchars($bb['branch_name'] ?? $lang['central_stock']) ?></span>
                            <span class="fw-bold"><?= format_money((float)$bb['branch_total']) ?></span>
                        </div>
                        <div class="progress" style="height:8px;background:var(--ts-bg-dark)">
                            <div class="progress-bar" style="width:<?= $pct_b ?>%;background:var(--ts-gold)"></div>
                        </div>
                        <small class="text-muted"><?= $pct_b ?>% — <?= (int)$bb['entry_count'] ?> <?= $is_rtl ? 'سجل' : 'records' ?></small>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- ================================================== -->
    <!-- مجمّع بالفئة                                        -->
    <!-- ================================================== -->
    <?php if (!empty($by_category)): ?>
    <div class="card mb-4">
        <div class="card-header">
            <i class="bi bi-grid me-1"></i>
            <?= $is_rtl ? 'المصروفات مجمّعة بالفئة' : 'Expenses by Category' ?>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th><?= $lang['expense_category'] ?></th>
                            <th class="text-center"><?= $is_rtl ? 'عدد السجلات' : 'Records' ?></th>
                            <th><?= $lang['expense_amount'] ?></th>
                            <th><?= $is_rtl ? 'متوسط المصروف' : 'Avg Expense' ?></th>
                            <th><?= $is_rtl ? 'نسبة من الإجمالي' : '% of Total' ?></th>
                            <th><?= $is_rtl ? 'آخر تاريخ' : 'Last Entry' ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($by_category as $ci => $cat): ?>
                        <?php
                        $pct = $total_exp > 0
                            ? round(((float)$cat['category_total'] / $total_exp) * 100, 1)
                            : 0;
                        $bar_color = $cat_colors[$ci % count($cat_colors)];
                        ?>
                        <tr>
                            <td>
                                <span class="d-inline-flex align-items-center gap-2">
                                    <span style="width:12px;height:12px;border-radius:50%;background:<?= $bar_color ?>;display:inline-block;flex-shrink:0"></span>
                                    <span class="fw-semibold"><?= htmlspecialchars($cat['category']) ?></span>
                                </span>
                            </td>
                            <td class="text-center">
                                <span class="badge" style="background:var(--ts-bg-dark);color:var(--ts-silver);border:1px solid var(--ts-border)">
                                    <?= (int)$cat['entry_count'] ?>
                                </span>
                            </td>
                            <td class="fw-bold" style="color:var(--ts-danger)">
                                <?= format_money((float)$cat['category_total']) ?>
                            </td>
                            <td class="text-muted small">
                                <?= format_money((float)$cat['category_avg']) ?>
                            </td>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="flex-grow-1" style="background:var(--ts-bg-dark);border-radius:4px;height:8px;min-width:60px">
                                        <div style="width:<?= $pct ?>%;height:100%;background:<?= $bar_color ?>;border-radius:4px"></div>
                                    </div>
                                    <small class="fw-bold" style="min-width:36px;color:<?= $bar_color ?>">
                                        <?= $pct ?>%
                                    </small>
                                </div>
                            </td>
                            <td>
                                <small class="text-muted font-monospace">
                                    <?= htmlspecialchars($cat['last_entry'] ?? '—') ?>
                                </small>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="background:var(--ts-bg-dark)">
                            <td class="fw-bold" style="color:var(--ts-gold)">
                                <?= $is_rtl ? 'الإجمالي' : 'Total' ?>
                            </td>
                            <td class="text-center fw-bold" style="color:var(--ts-silver)">
                                <?= number_format((int)$kpi['total_count']) ?>
                            </td>
                            <td class="fw-bold" style="color:var(--ts-danger)">
                                <?= format_money($total_exp) ?>
                            </td>
                            <td colspan="3"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ================================================== -->
    <!-- جدول التفصيلي                                       -->
    <!-- ================================================== -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>
                <i class="bi bi-list-ul me-1"></i>
                <?= $is_rtl ? 'سجلات المصروفات التفصيلية' : 'Expense Records Detail' ?>
            </span>
            <small class="text-muted"><?= $total_records ?> <?= $lang['results'] ?></small>
        </div>
        <div class="card-body p-0">
            <?php if (empty($expense_rows)): ?>
                <div class="text-center py-5 text-muted">
                    <i class="bi bi-wallet2 fs-1 d-block mb-2"></i>
                    <?= $lang['no_results'] ?>
                </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th><?= $lang['expense_date'] ?></th>
                            <?php if ($is_super): ?>
                            <th><?= $lang['branch'] ?></th>
                            <?php endif; ?>
                            <th><?= $lang['expense_category'] ?></th>
                            <th><?= $lang['expense_amount'] ?></th>
                            <th><?= $lang['expense_desc'] ?></th>
                            <th><?= $is_rtl ? 'أدخله' : 'By' ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($expense_rows as $er): ?>
                        <tr>
                            <td>
                                <small class="font-monospace">
                                    <?= htmlspecialchars($er['expense_date']) ?>
                                </small>
                            </td>
                            <?php if ($is_super): ?>
                            <td><small><?= htmlspecialchars($er['branch_name'] ?? $lang['central_stock']) ?></small></td>
                            <?php endif; ?>
                            <td>
                                <?php
                                $cat_idx = array_search($er['category'], array_column($by_category, 'category'));
                                $dot_color = $cat_idx !== false ? $cat_colors[$cat_idx % count($cat_colors)] : 'var(--ts-silver)';
                                ?>
                                <span class="d-inline-flex align-items-center gap-1">
                                    <span style="width:8px;height:8px;border-radius:50%;background:<?= $dot_color ?>;display:inline-block;flex-shrink:0"></span>
                                    <span><?= htmlspecialchars($er['category']) ?></span>
                                </span>
                            </td>
                            <td class="fw-bold" style="color:var(--ts-danger)">
                                <?= format_money((float)$er['amount']) ?>
                            </td>
                            <td>
                                <small class="text-muted">
                                    <?= htmlspecialchars($er['description']
                                        ? mb_substr($er['description'], 0, 50) . (mb_strlen($er['description']) > 50 ? '…' : '')
                                        : '—') ?>
                                </small>
                            </td>
                            <td>
                                <small class="text-muted"><?= htmlspecialchars($er['created_by_name'] ?? '—') ?></small>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="background:var(--ts-bg-dark)">
                            <td colspan="<?= $is_super ? 3 : 2 ?>" class="fw-bold" style="color:var(--ts-gold)">
                                <?= $is_rtl ? 'إجمالي الصفحة' : 'Page Total' ?>
                            </td>
                            <td class="fw-bold" style="color:var(--ts-danger)">
                                <?= format_money(array_sum(array_column($expense_rows, 'amount'))) ?>
                            </td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <div class="d-flex justify-content-between align-items-center p-3 no-print">
                <small class="text-muted">
                    <?= $lang['showing'] ?> <?= $offset + 1 ?>–<?= min($offset + $per_page, $total_records) ?>
                    <?= $lang['of'] ?> <?= $total_records ?> <?= $lang['results'] ?>
                </small>
                <nav>
                    <ul class="pagination pagination-sm mb-0">
                        <?php for ($p = 1; $p <= $total_pages; $p++): ?>
                        <li class="page-item <?= $p === $page_num ? 'active' : '' ?>">
                            <a class="page-link"
                               href="?<?= http_build_query(array_merge($_GET, ['p' => $p])) ?>">
                                <?= $p ?>
                            </a>
                        </li>
                        <?php endfor; ?>
                    </ul>
                </nav>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

</div><!-- /.main-content -->

<!-- Chart.js -->
<?php if (!empty($chart_rows)): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
(function () {
    var ctx = document.getElementById('expensesChart');
    if (!ctx) return;

    var labels  = <?= json_encode($chart_labels)  ?>;
    var amounts = <?= json_encode($chart_amounts) ?>;

    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: '<?= $is_rtl ? 'المصروفات (SDG)' : 'Expenses (SDG)' ?>',
                data: amounts,
                backgroundColor: 'rgba(220,53,69,0.6)',
                borderColor: '#DC3545',
                borderWidth: 1,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { labels: { color: '#F5F5F5', font: { family: 'Tajawal' } } },
                tooltip: {
                    callbacks: {
                        label: function (ctx) {
                            return ' ' + Number(ctx.raw).toLocaleString() + ' SDG';
                        }
                    }
                }
            },
            scales: {
                x: { ticks: { color: '#B0B3B8' }, grid: { color: '#3A3A3A' } },
                y: {
                    ticks: {
                        color: '#DC3545',
                        callback: function (v) { return v.toLocaleString() + ' SDG'; }
                    },
                    grid: { color: '#3A3A3A' }
                }
            }
        }
    });
})();
</script>
<?php endif; ?>

<style>
.main-content   { padding: 1.5rem; }
.font-monospace { font-family: monospace; }
.ts-input  { background:var(--ts-bg-input)  !important; border-color:var(--ts-border) !important; color:var(--ts-text-primary) !important; }
.ts-select { background:var(--ts-bg-input)  !important; border-color:var(--ts-border) !important; color:var(--ts-text-primary) !important; }
.ts-input:focus, .ts-select:focus {
    border-color: var(--ts-gold) !important;
    box-shadow: 0 0 0 .2rem rgba(201,168,76,.25) !important;
}
.progress { border-radius: 4px; }

@media print {
    .no-print, nav, .sidebar { display:none !important; }
    .main-content { padding: 0 !important; }
    .card { border: 1px solid #ccc !important; background: #fff !important; color: #000 !important; }
    .card-header { background: #f5f5f5 !important; color: #000 !important; }
    table thead th { background: #eee !important; color: #000 !important; }
}
</style>

<?php require_once __DIR__ . '/../includes/layout/footer.php'; ?>
