<?php
// ============================================================
// المسار: reports/purchases.php
// الوظيفة: تقرير المشتريات — إجمالي + تفصيل بالمورد + Aging
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
$filter_date_from = trim($_GET['date_from']   ?? date('Y-m-01'));
$filter_date_to   = trim($_GET['date_to']     ?? date('Y-m-d'));
$filter_supplier  = sanitize_int($_GET['supplier_id'] ?? 0);
$filter_status    = trim($_GET['po_status']   ?? '');
$filter_branch    = $is_super
    ? sanitize_int($_GET['branch_id'] ?? 0)
    : (int)$user_bid;

$valid_statuses = ['draft', 'confirmed', 'received', 'partial', 'cancelled'];

// ============================================================
// بناء شرط WHERE المشترك
// ============================================================
$where_parts = [];
$params      = [];

if (!$is_super) {
    $where_parts[] = '(po.branch_id = ? OR po.branch_id IS NULL)';
    $params[]      = $user_bid;
} elseif ($filter_branch > 0) {
    $where_parts[] = '(po.branch_id = ? OR po.branch_id IS NULL)';
    $params[]      = $filter_branch;
}

if ($filter_supplier > 0) {
    $where_parts[] = 'po.supplier_id = ?';
    $params[]      = $filter_supplier;
}

if ($filter_status !== '' && in_array($filter_status, $valid_statuses, true)) {
    $where_parts[] = 'po.status = ?';
    $params[]      = $filter_status;
}

if ($filter_date_from !== '') {
    $where_parts[] = 'DATE(po.created_at) >= ?';
    $params[]      = $filter_date_from;
}

if ($filter_date_to !== '') {
    $where_parts[] = 'DATE(po.created_at) <= ?';
    $params[]      = $filter_date_to;
}

$where_sql = $where_parts ? ('WHERE ' . implode(' AND ', $where_parts)) : '';

// ============================================================
// تصدير CSV
// ============================================================
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $csv_stmt = $pdo->prepare("
        SELECT
            po.po_number,
            DATE(po.created_at)       AS po_date,
            s.name                    AS supplier_name,
            b.name                    AS branch_name,
            po.total_amount,
            po.paid_amount,
            (po.total_amount - po.paid_amount) AS remaining,
            po.status,
            u.name                    AS created_by_name,
            po.received_at,
            po.notes
        FROM purchase_orders po
        LEFT JOIN suppliers s ON s.id = po.supplier_id
        LEFT JOIN branches  b ON b.id = po.branch_id
        LEFT JOIN users     u ON u.id = po.created_by
        $where_sql
        ORDER BY po.created_at DESC
    ");
    $csv_stmt->execute($params);
    $csv_rows = $csv_stmt->fetchAll();

    $status_labels_csv = [
        'draft'     => $is_rtl ? 'مسودة'   : 'Draft',
        'confirmed' => $is_rtl ? 'مؤكد'    : 'Confirmed',
        'received'  => $is_rtl ? 'مستلم'   : 'Received',
        'partial'   => $is_rtl ? 'جزئي'    : 'Partial',
        'cancelled' => $is_rtl ? 'ملغي'    : 'Cancelled',
    ];

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="purchases_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM for Excel Arabic
    fputcsv($out, [
        $lang['po_number'],
        $lang['date'],
        $lang['supplier'],
        $lang['branch'],
        $is_rtl ? 'إجمالي الأمر' : 'PO Total',
        $lang['amount_paid'],
        $is_rtl ? 'المتبقي' : 'Remaining',
        $lang['status'],
        $is_rtl ? 'أنشأه' : 'Created By',
        $is_rtl ? 'تاريخ الاستلام' : 'Received At',
        $lang['notes'],
    ]);
    foreach ($csv_rows as $row) {
        fputcsv($out, [
            $row['po_number'],
            $row['po_date'],
            $row['supplier_name'] ?? '—',
            $row['branch_name']   ?? $lang['central_stock'],
            format_money((float)$row['total_amount']),
            format_money((float)$row['paid_amount']),
            format_money((float)$row['remaining']),
            $status_labels_csv[$row['status']] ?? $row['status'],
            $row['created_by_name'] ?? '—',
            $row['received_at'] ?? '—',
            $row['notes'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

// ============================================================
// KPI — إجمالي المشتريات خلال الفترة
// ============================================================
$kpi_stmt = $pdo->prepare("
    SELECT
        COUNT(po.id)                              AS total_orders,
        COALESCE(SUM(po.total_amount), 0)         AS total_amount,
        COALESCE(SUM(po.paid_amount), 0)          AS total_paid,
        COALESCE(SUM(po.total_amount - po.paid_amount), 0) AS total_remaining,
        COUNT(CASE WHEN po.status = 'received' THEN 1 END) AS received_count,
        COUNT(CASE WHEN po.status = 'partial'  THEN 1 END) AS partial_count
    FROM purchase_orders po
    $where_sql
");
$kpi_stmt->execute($params);
$kpi = $kpi_stmt->fetch();

// ============================================================
// ملخص بالمورد
// ============================================================
$supplier_summary_stmt = $pdo->prepare("
    SELECT
        s.id,
        s.name                                        AS supplier_name,
        s.phone,
        COUNT(po.id)                                  AS order_count,
        COALESCE(SUM(po.total_amount), 0)             AS total_amount,
        COALESCE(SUM(po.paid_amount), 0)              AS total_paid,
        COALESCE(SUM(po.total_amount - po.paid_amount), 0) AS total_remaining,
        s.balance                                     AS current_balance
    FROM purchase_orders po
    JOIN suppliers s ON s.id = po.supplier_id
    $where_sql
    GROUP BY s.id, s.name, s.phone, s.balance
    ORDER BY total_amount DESC
");
$supplier_summary_stmt->execute($params);
$supplier_summary = $supplier_summary_stmt->fetchAll();

// ============================================================
// مستحقات الموردين الحالية (Aging) — بغض النظر عن الفترة
// ============================================================
$aging_params = [];
$aging_branch = '';
if (!$is_super) {
    $aging_branch   = 'AND (po2.branch_id = ? OR po2.branch_id IS NULL)';
    $aging_params[] = $user_bid;
} elseif ($filter_branch > 0) {
    $aging_branch   = 'AND (po2.branch_id = ? OR po2.branch_id IS NULL)';
    $aging_params[] = $filter_branch;
}

$aging_stmt = $pdo->prepare("
    SELECT
        s.id,
        s.name                                                  AS supplier_name,
        s.phone,
        s.balance                                               AS current_balance,
        COUNT(CASE WHEN DATEDIFF(CURDATE(), po2.created_at) <= 30  THEN 1 END) AS orders_0_30,
        COUNT(CASE WHEN DATEDIFF(CURDATE(), po2.created_at) BETWEEN 31 AND 60 THEN 1 END) AS orders_31_60,
        COUNT(CASE WHEN DATEDIFF(CURDATE(), po2.created_at) > 60  THEN 1 END) AS orders_over_60,
        COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), po2.created_at) <= 30
            THEN po2.total_amount - po2.paid_amount ELSE 0 END), 0) AS amount_0_30,
        COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), po2.created_at) BETWEEN 31 AND 60
            THEN po2.total_amount - po2.paid_amount ELSE 0 END), 0) AS amount_31_60,
        COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), po2.created_at) > 60
            THEN po2.total_amount - po2.paid_amount ELSE 0 END), 0) AS amount_over_60
    FROM suppliers s
    JOIN purchase_orders po2
        ON po2.supplier_id = s.id
        AND po2.status IN ('confirmed', 'partial')
        AND (po2.total_amount - po2.paid_amount) > 0
        $aging_branch
    WHERE s.status = 'active'
    GROUP BY s.id, s.name, s.phone, s.balance
    HAVING current_balance > 0
    ORDER BY current_balance DESC
");
$aging_stmt->execute($aging_params);
$aging_rows = $aging_stmt->fetchAll();

// ============================================================
// جدول أوامر الشراء التفصيلي — Pagination
// ============================================================
$page_num = max(1, (int)($_GET['p'] ?? 1));
$per_page = 25;

$count_st = $pdo->prepare("SELECT COUNT(*) FROM purchase_orders po $where_sql");
$count_st->execute($params);
$total_records = (int)$count_st->fetchColumn();
$total_pages   = max(1, (int)ceil($total_records / $per_page));
$offset        = ($page_num - 1) * $per_page;

$list_stmt = $pdo->prepare("
    SELECT
        po.id, po.po_number, po.created_at, po.received_at,
        po.total_amount, po.paid_amount,
        (po.total_amount - po.paid_amount) AS remaining,
        po.status, po.notes,
        s.name  AS supplier_name,
        b.name  AS branch_name,
        u.name  AS created_by_name
    FROM purchase_orders po
    LEFT JOIN suppliers s ON s.id = po.supplier_id
    LEFT JOIN branches  b ON b.id = po.branch_id
    LEFT JOIN users     u ON u.id = po.created_by
    $where_sql
    ORDER BY po.created_at DESC
    LIMIT $per_page OFFSET $offset
");
$list_stmt->execute($params);
$orders = $list_stmt->fetchAll();

// ============================================================
// بيانات الفلاتر
// ============================================================
$suppliers_list = $pdo->query(
    "SELECT id, name FROM suppliers WHERE status='active' ORDER BY name"
)->fetchAll();

$branches_list = [];
if ($is_super) {
    $branches_list = $pdo->query(
        "SELECT id, name FROM branches WHERE status='active' ORDER BY name"
    )->fetchAll();
}

// تسميات الحالة
$status_labels = [
    'draft'     => ['label' => $is_rtl ? 'مسودة'   : 'Draft',     'color' => 'secondary'],
    'confirmed' => ['label' => $is_rtl ? 'مؤكد'    : 'Confirmed', 'color' => 'info'],
    'received'  => ['label' => $is_rtl ? 'مستلم'   : 'Received',  'color' => 'success'],
    'partial'   => ['label' => $is_rtl ? 'جزئي'    : 'Partial',   'color' => 'warning'],
    'cancelled' => ['label' => $is_rtl ? 'ملغي'    : 'Cancelled', 'color' => 'danger'],
];
?>

<?php require_once __DIR__ . '/../includes/layout/sidebar.php'; ?>

<div class="main-content">

    <?php show_flash(); ?>

    <!-- رأس الصفحة -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <h4 class="mb-0" style="color:var(--ts-gold)">
            <i class="bi bi-cart3 me-2"></i>
            <?= $lang['purchases_report'] ?>
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
                <div class="col-md-2">
                    <label class="form-label small"><?= $lang['supplier'] ?></label>
                    <select name="supplier_id" class="form-select form-select-sm ts-select">
                        <option value=""><?= $is_rtl ? 'كل الموردين' : 'All Suppliers' ?></option>
                        <?php foreach ($suppliers_list as $sup): ?>
                        <option value="<?= $sup['id'] ?>" <?= $filter_supplier == $sup['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($sup['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
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
                <div class="col-md-2">
                    <label class="form-label small"><?= $lang['status'] ?></label>
                    <select name="po_status" class="form-select form-select-sm ts-select">
                        <option value=""><?= $is_rtl ? 'كل الحالات' : 'All Statuses' ?></option>
                        <?php foreach ($status_labels as $sk => $sv): ?>
                        <option value="<?= $sk ?>" <?= $filter_status === $sk ? 'selected' : '' ?>>
                            <?= htmlspecialchars($sv['label']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-primary btn-sm flex-grow-1">
                        <i class="bi bi-funnel me-1"></i><?= $lang['filter'] ?>
                    </button>
                    <a href="purchases.php" class="btn btn-outline-secondary btn-sm">
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
                        <?= $is_rtl ? 'إجمالي المشتريات' : 'Total Purchases' ?>
                    </div>
                    <div class="fs-5 fw-bold" style="color:var(--ts-gold)">
                        <?= format_money((float)$kpi['total_amount']) ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card text-center">
                <div class="card-body py-3">
                    <div class="small text-muted mb-1">
                        <?= $is_rtl ? 'إجمالي المدفوع' : 'Total Paid' ?>
                    </div>
                    <div class="fs-5 fw-bold" style="color:var(--ts-success)">
                        <?= format_money((float)$kpi['total_paid']) ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card text-center">
                <div class="card-body py-3">
                    <div class="small text-muted mb-1">
                        <?= $is_rtl ? 'المستحق للموردين' : 'Due to Suppliers' ?>
                    </div>
                    <div class="fs-5 fw-bold" style="color:var(--ts-danger)">
                        <?= format_money((float)$kpi['total_remaining']) ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card text-center">
                <div class="card-body py-3">
                    <div class="small text-muted mb-1">
                        <?= $is_rtl ? 'عدد الأوامر' : 'Orders Count' ?>
                    </div>
                    <div class="fs-5 fw-bold" style="color:var(--ts-silver)">
                        <?= number_format((int)$kpi['total_orders']) ?>
                        <small class="d-block fs-6 mt-1">
                            <span style="color:var(--ts-success)"><?= (int)$kpi['received_count'] ?> <?= $is_rtl ? 'مستلم' : 'received' ?></span>
                            &nbsp;|&nbsp;
                            <span style="color:var(--ts-warning)"><?= (int)$kpi['partial_count'] ?> <?= $is_rtl ? 'جزئي' : 'partial' ?></span>
                        </small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ================================================== -->
    <!-- ملخص بالمورد                                        -->
    <!-- ================================================== -->
    <?php if (!empty($supplier_summary)): ?>
    <div class="card mb-4">
        <div class="card-header d-flex align-items-center gap-2">
            <i class="bi bi-people"></i>
            <?= $is_rtl ? 'ملخص بالمورد' : 'Summary by Supplier' ?>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th><?= $lang['supplier'] ?></th>
                            <th><?= $lang['supplier_phone'] ?></th>
                            <th class="text-center"><?= $is_rtl ? 'عدد الأوامر' : 'Orders' ?></th>
                            <th><?= $is_rtl ? 'إجمالي المشتريات' : 'Total' ?></th>
                            <th><?= $is_rtl ? 'المدفوع' : 'Paid' ?></th>
                            <th><?= $is_rtl ? 'المتبقي (فترة الفلتر)' : 'Remaining (Period)' ?></th>
                            <th><?= $lang['supplier_balance'] ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($supplier_summary as $row): ?>
                        <tr>
                            <td class="fw-semibold"><?= htmlspecialchars($row['supplier_name']) ?></td>
                            <td><small class="text-muted"><?= htmlspecialchars($row['phone'] ?? '—') ?></small></td>
                            <td class="text-center">
                                <span class="badge" style="background:var(--ts-bg-dark);color:var(--ts-silver);border:1px solid var(--ts-border)">
                                    <?= (int)$row['order_count'] ?>
                                </span>
                            </td>
                            <td style="color:var(--ts-gold)"><?= format_money((float)$row['total_amount']) ?></td>
                            <td style="color:var(--ts-success)"><?= format_money((float)$row['total_paid']) ?></td>
                            <td>
                                <?php if ((float)$row['total_remaining'] > 0): ?>
                                    <span style="color:var(--ts-warning)"><?= format_money((float)$row['total_remaining']) ?></span>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ((float)$row['current_balance'] > 0): ?>
                                    <span class="fw-bold" style="color:var(--ts-danger)">
                                        <?= format_money((float)$row['current_balance']) ?>
                                    </span>
                                <?php else: ?>
                                    <span style="color:var(--ts-success)">✓</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="background:var(--ts-bg-dark)">
                            <td colspan="3" class="fw-bold" style="color:var(--ts-gold)">
                                <?= $is_rtl ? 'الإجمالي' : 'Total' ?>
                            </td>
                            <td class="fw-bold" style="color:var(--ts-gold)">
                                <?= format_money((float)$kpi['total_amount']) ?>
                            </td>
                            <td class="fw-bold" style="color:var(--ts-success)">
                                <?= format_money((float)$kpi['total_paid']) ?>
                            </td>
                            <td class="fw-bold" style="color:var(--ts-danger)">
                                <?= format_money((float)$kpi['total_remaining']) ?>
                            </td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ================================================== -->
    <!-- مستحقات الموردين — Aging Report                     -->
    <!-- ================================================== -->
    <?php if (!empty($aging_rows)): ?>
    <div class="card mb-4 no-print">
        <div class="card-header d-flex align-items-center gap-2" style="color:var(--ts-danger)">
            <i class="bi bi-exclamation-triangle"></i>
            <?= $is_rtl ? 'مستحقات الموردين الحالية (Aging)' : 'Supplier Aging Report' ?>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th><?= $lang['supplier'] ?></th>
                            <th><?= $lang['supplier_phone'] ?></th>
                            <th class="text-center">
                                <?= $is_rtl ? '0–30 يوم' : '0–30 Days' ?>
                            </th>
                            <th class="text-center">
                                <?= $is_rtl ? '31–60 يوم' : '31–60 Days' ?>
                            </th>
                            <th class="text-center">
                                <?= $is_rtl ? '+60 يوم' : '60+ Days' ?>
                            </th>
                            <th>
                                <?= $is_rtl ? 'إجمالي المستحق' : 'Total Due' ?>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($aging_rows as $ag): ?>
                        <tr>
                            <td class="fw-semibold"><?= htmlspecialchars($ag['supplier_name']) ?></td>
                            <td><small class="text-muted"><?= htmlspecialchars($ag['phone'] ?? '—') ?></small></td>
                            <td class="text-center">
                                <?php if ((float)$ag['amount_0_30'] > 0): ?>
                                    <span style="color:var(--ts-warning)"><?= format_money((float)$ag['amount_0_30']) ?></span>
                                <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if ((float)$ag['amount_31_60'] > 0): ?>
                                    <span style="color:var(--ts-warning)"><?= format_money((float)$ag['amount_31_60']) ?></span>
                                <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if ((float)$ag['amount_over_60'] > 0): ?>
                                    <span class="fw-bold" style="color:var(--ts-danger)"><?= format_money((float)$ag['amount_over_60']) ?></span>
                                <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                            </td>
                            <td class="fw-bold" style="color:var(--ts-danger)">
                                <?= format_money((float)$ag['current_balance']) ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ================================================== -->
    <!-- جدول أوامر الشراء التفصيلي                          -->
    <!-- ================================================== -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>
                <i class="bi bi-list-ul me-1"></i>
                <?= $is_rtl ? 'تفصيل أوامر الشراء' : 'Purchase Orders Detail' ?>
            </span>
            <small class="text-muted"><?= $total_records ?> <?= $lang['results'] ?></small>
        </div>
        <div class="card-body p-0">
            <?php if (empty($orders)): ?>
                <div class="text-center py-5 text-muted">
                    <i class="bi bi-cart-x fs-1 d-block mb-2"></i>
                    <?= $lang['no_results'] ?>
                </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th><?= $lang['po_number'] ?></th>
                            <th><?= $lang['date'] ?></th>
                            <th><?= $lang['supplier'] ?></th>
                            <?php if ($is_super): ?>
                            <th><?= $lang['branch'] ?></th>
                            <?php endif; ?>
                            <th><?= $is_rtl ? 'إجمالي الأمر' : 'PO Total' ?></th>
                            <th><?= $lang['amount_paid'] ?></th>
                            <th><?= $is_rtl ? 'المتبقي' : 'Remaining' ?></th>
                            <th><?= $lang['status'] ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orders as $po): ?>
                        <?php $st = $status_labels[$po['status']] ?? ['label' => $po['status'], 'color' => 'secondary']; ?>
                        <tr>
                            <td>
                                <span class="font-monospace small" style="color:var(--ts-gold)">
                                    <?= htmlspecialchars($po['po_number']) ?>
                                </span>
                            </td>
                            <td>
                                <small class="font-monospace">
                                    <?= date('Y-m-d', strtotime($po['created_at'])) ?>
                                </small>
                            </td>
                            <td><?= htmlspecialchars($po['supplier_name'] ?? '—') ?></td>
                            <?php if ($is_super): ?>
                            <td><small><?= htmlspecialchars($po['branch_name'] ?? $lang['central_stock']) ?></small></td>
                            <?php endif; ?>
                            <td style="color:var(--ts-gold)">
                                <?= format_money((float)$po['total_amount']) ?>
                            </td>
                            <td style="color:var(--ts-success)">
                                <?= format_money((float)$po['paid_amount']) ?>
                            </td>
                            <td>
                                <?php if ((float)$po['remaining'] > 0): ?>
                                    <span style="color:var(--ts-danger)">
                                        <?= format_money((float)$po['remaining']) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge bg-<?= $st['color'] ?>">
                                    <?= $st['label'] ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="background:var(--ts-bg-dark)">
                            <td colspan="<?= $is_super ? 4 : 3 ?>" class="fw-bold" style="color:var(--ts-gold)">
                                <?= $is_rtl ? 'إجمالي الصفحة' : 'Page Total' ?>
                            </td>
                            <td class="fw-bold" style="color:var(--ts-gold)">
                                <?= format_money(array_sum(array_column($orders, 'total_amount'))) ?>
                            </td>
                            <td class="fw-bold" style="color:var(--ts-success)">
                                <?= format_money(array_sum(array_column($orders, 'paid_amount'))) ?>
                            </td>
                            <td class="fw-bold" style="color:var(--ts-danger)">
                                <?= format_money(array_sum(array_column($orders, 'remaining'))) ?>
                            </td>
                            <td></td>
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

<style>
.main-content  { padding: 1.5rem; }
.font-monospace { font-family: monospace; }
.ts-input  { background:var(--ts-bg-input)  !important; border-color:var(--ts-border) !important; color:var(--ts-text-primary) !important; }
.ts-select { background:var(--ts-bg-input)  !important; border-color:var(--ts-border) !important; color:var(--ts-text-primary) !important; }
.ts-input:focus, .ts-select:focus {
    border-color:var(--ts-gold) !important;
    box-shadow:0 0 0 .2rem rgba(201,168,76,.25) !important;
}

@media print {
    .no-print, nav, .sidebar { display:none !important; }
    .main-content { padding: 0 !important; }
    .card { border: 1px solid #ccc !important; background: #fff !important; color: #000 !important; }
    .card-header { background: #f5f5f5 !important; color: #000 !important; }
    table thead th { background: #eee !important; color: #000 !important; }
    .badge { border: 1px solid #ccc !important; }
}
</style>

<?php require_once __DIR__ . '/../includes/layout/footer.php'; ?>
