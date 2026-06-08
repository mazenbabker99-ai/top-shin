<?php
// ============================================================
// المسار: reports/returns.php
// الوظيفة: تقرير المرتجعات — إجمالي + تفصيل + نسبة من المبيعات
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
$filter_date_from  = trim($_GET['date_from']      ?? date('Y-m-01'));
$filter_date_to    = trim($_GET['date_to']        ?? date('Y-m-d'));
$filter_branch     = $is_super
    ? sanitize_int($_GET['branch_id']    ?? 0)
    : (int)$user_bid;
$filter_refund_method = trim($_GET['refund_method'] ?? '');
$filter_status        = trim($_GET['ret_status']    ?? 'approved');

$valid_methods  = ['cash', 'wallet', 'exchange'];
$valid_statuses = ['pending', 'approved', 'rejected', 'all'];

// ============================================================
// بناء شرط WHERE
// ============================================================
$where_parts = [];
$params      = [];

if ($filter_status !== 'all' && in_array($filter_status, ['pending', 'approved', 'rejected'], true)) {
    $where_parts[] = 'r.status = ?';
    $params[]      = $filter_status;
}

if (!$is_super) {
    $where_parts[] = 'r.branch_id = ?';
    $params[]      = $user_bid;
} elseif ($filter_branch > 0) {
    $where_parts[] = 'r.branch_id = ?';
    $params[]      = $filter_branch;
}

if ($filter_refund_method !== '' && in_array($filter_refund_method, $valid_methods, true)) {
    $where_parts[] = 'r.refund_method = ?';
    $params[]      = $filter_refund_method;
}

if ($filter_date_from !== '') {
    $where_parts[] = 'DATE(r.created_at) >= ?';
    $params[]      = $filter_date_from;
}

if ($filter_date_to !== '') {
    $where_parts[] = 'DATE(r.created_at) <= ?';
    $params[]      = $filter_date_to;
}

$where_sql = $where_parts ? ('WHERE ' . implode(' AND ', $where_parts)) : '';

// ============================================================
// تصدير CSV
// ============================================================
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $csv_stmt = $pdo->prepare("
        SELECT
            r.return_number,
            DATE(r.created_at)          AS return_date,
            i.invoice_number,
            b.name                      AS branch_name,
            r.total_refund,
            r.refund_method,
            r.reason,
            r.status,
            u.name                      AS processed_by_name
        FROM returns r
        LEFT JOIN invoices i ON i.id = r.invoice_id
        LEFT JOIN branches b ON b.id = r.branch_id
        LEFT JOIN users    u ON u.id = r.processed_by
        $where_sql
        ORDER BY r.created_at DESC
    ");
    $csv_stmt->execute($params);
    $csv_rows = $csv_stmt->fetchAll();

    $status_map = [
        'pending'  => $is_rtl ? 'معلق'        : 'Pending',
        'approved' => $is_rtl ? 'مُوافق عليه' : 'Approved',
        'rejected' => $is_rtl ? 'مرفوض'       : 'Rejected',
    ];
    $method_map = [
        'cash'     => $is_rtl ? 'كاش'      : 'Cash',
        'wallet'   => $is_rtl ? 'محفظة'    : 'Wallet',
        'exchange' => $is_rtl ? 'استبدال'  : 'Exchange',
    ];

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="returns_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($out, [
        $lang['return_number'],
        $lang['date'],
        $lang['invoice_number'],
        $lang['branch'],
        $lang['total_refund'],
        $lang['refund_method'],
        $lang['return_reason'],
        $lang['status'],
        $is_rtl ? 'مُعالَج بواسطة' : 'Processed By',
    ]);
    foreach ($csv_rows as $row) {
        fputcsv($out, [
            $row['return_number'],
            $row['return_date'],
            $row['invoice_number'] ?? '—',
            $row['branch_name']    ?? '—',
            $row['total_refund'],
            $method_map[$row['refund_method']] ?? $row['refund_method'],
            $row['reason'] ?? '—',
            $status_map[$row['status']] ?? $row['status'],
            $row['processed_by_name'] ?? '—',
        ]);
    }
    fclose($out);
    exit;
}

// ============================================================
// KPI — إجمالي المرتجعات
// ============================================================
$kpi_stmt = $pdo->prepare("
    SELECT
        COUNT(r.id)                        AS total_returns,
        COALESCE(SUM(r.total_refund), 0)   AS total_refund_amount,
        COALESCE(SUM(CASE WHEN r.refund_method = 'cash'     THEN r.total_refund ELSE 0 END), 0) AS cash_refund,
        COALESCE(SUM(CASE WHEN r.refund_method = 'exchange' THEN r.total_refund ELSE 0 END), 0) AS exchange_refund,
        COALESCE(SUM(CASE WHEN r.refund_method = 'wallet'   THEN r.total_refund ELSE 0 END), 0) AS wallet_refund
    FROM returns r
    $where_sql
");
$kpi_stmt->execute($params);
$kpi = $kpi_stmt->fetch();

// ============================================================
// نسبة المرتجعات من المبيعات خلال نفس الفترة
// ============================================================
$sales_where_parts = ["i2.status = 'completed'"];
$sales_params      = [];

if (!$is_super) {
    $sales_where_parts[] = 'i2.branch_id = ?';
    $sales_params[]      = $user_bid;
} elseif ($filter_branch > 0) {
    $sales_where_parts[] = 'i2.branch_id = ?';
    $sales_params[]      = $filter_branch;
}
if ($filter_date_from !== '') {
    $sales_where_parts[] = 'DATE(i2.created_at) >= ?';
    $sales_params[]      = $filter_date_from;
}
if ($filter_date_to !== '') {
    $sales_where_parts[] = 'DATE(i2.created_at) <= ?';
    $sales_params[]      = $filter_date_to;
}
$sales_where_sql = 'WHERE ' . implode(' AND ', $sales_where_parts);

$sales_total_stmt = $pdo->prepare("
    SELECT COALESCE(SUM(i2.total), 0) AS total_sales
    FROM invoices i2
    $sales_where_sql
");
$sales_total_stmt->execute($sales_params);
$total_sales_row  = $sales_total_stmt->fetch();
$total_sales      = (float)$total_sales_row['total_sales'];
$total_refund_amt = (float)$kpi['total_refund_amount'];
$return_rate      = $total_sales > 0
    ? round(($total_refund_amt / $total_sales) * 100, 2)
    : 0.0;

// ============================================================
// توزيع المرتجعات يومياً (للـ Chart)
// ============================================================
$chart_stmt = $pdo->prepare("
    SELECT
        DATE(r.created_at)             AS day,
        COUNT(r.id)                    AS return_count,
        COALESCE(SUM(r.total_refund),0) AS day_refund
    FROM returns r
    $where_sql
    GROUP BY DATE(r.created_at)
    ORDER BY day ASC
");
$chart_stmt->execute($params);
$chart_rows    = $chart_stmt->fetchAll();
$chart_labels  = array_column($chart_rows, 'day');
$chart_amounts = array_map(fn($r) => (float)$r['day_refund'],   $chart_rows);
$chart_counts  = array_map(fn($r) => (int)$r['return_count'],   $chart_rows);

// ============================================================
// توزيع حسب طريقة الاسترداد
// ============================================================
$method_dist_stmt = $pdo->prepare("
    SELECT
        r.refund_method,
        COUNT(r.id)                     AS count,
        COALESCE(SUM(r.total_refund),0) AS total
    FROM returns r
    $where_sql
    GROUP BY r.refund_method
    ORDER BY total DESC
");
$method_dist_stmt->execute($params);
$method_dist = $method_dist_stmt->fetchAll();

// ============================================================
// أكثر المنتجات إرجاعاً
// ============================================================
$top_products_where = $where_parts
    ? str_replace('r.', 'r2.', $where_sql)
    : '';
// بناء الـ where للمنتجات بشكل صحيح
$tp_parts  = [];
$tp_params = [];

if ($filter_status !== 'all' && in_array($filter_status, ['pending', 'approved', 'rejected'], true)) {
    $tp_parts[] = 'r2.status = ?'; $tp_params[] = $filter_status;
}
if (!$is_super) {
    $tp_parts[] = 'r2.branch_id = ?'; $tp_params[] = $user_bid;
} elseif ($filter_branch > 0) {
    $tp_parts[] = 'r2.branch_id = ?'; $tp_params[] = $filter_branch;
}
if ($filter_date_from !== '') { $tp_parts[] = 'DATE(r2.created_at) >= ?'; $tp_params[] = $filter_date_from; }
if ($filter_date_to   !== '') { $tp_parts[] = 'DATE(r2.created_at) <= ?'; $tp_params[] = $filter_date_to; }

$tp_where = $tp_parts ? ('WHERE ' . implode(' AND ', $tp_parts)) : '';

$top_products_stmt = $pdo->prepare("
    SELECT
        p.name_ar,
        p.name_en,
        p.barcode,
        SUM(ri.quantity)              AS total_returned_qty,
        SUM(ri.total)                 AS total_returned_value,
        COUNT(DISTINCT r2.id)         AS return_count
    FROM return_items ri
    JOIN returns  r2 ON r2.id = ri.return_id
    JOIN products p  ON p.id  = ri.product_id
    $tp_where
    GROUP BY p.id, p.name_ar, p.name_en, p.barcode
    ORDER BY total_returned_qty DESC
    LIMIT 10
");
$top_products_stmt->execute($tp_params);
$top_products = $top_products_stmt->fetchAll();

// ============================================================
// جدول المرتجعات التفصيلي — Pagination
// ============================================================
$page_num = max(1, (int)($_GET['p'] ?? 1));
$per_page = 25;

$count_st = $pdo->prepare("SELECT COUNT(*) FROM returns r $where_sql");
$count_st->execute($params);
$total_records = (int)$count_st->fetchColumn();
$total_pages   = max(1, (int)ceil($total_records / $per_page));
$offset        = ($page_num - 1) * $per_page;

$list_stmt = $pdo->prepare("
    SELECT
        r.id, r.return_number, r.created_at,
        r.total_refund, r.refund_method, r.reason, r.status,
        i.invoice_number,
        b.name  AS branch_name,
        u.name  AS processed_by_name,
        (SELECT COUNT(*) FROM return_items ri WHERE ri.return_id = r.id) AS items_count
    FROM returns r
    LEFT JOIN invoices i ON i.id = r.invoice_id
    LEFT JOIN branches b ON b.id = r.branch_id
    LEFT JOIN users    u ON u.id = r.processed_by
    $where_sql
    ORDER BY r.created_at DESC
    LIMIT $per_page OFFSET $offset
");
$list_stmt->execute($params);
$returns = $list_stmt->fetchAll();

// ============================================================
// بيانات الفلاتر
// ============================================================
$branches_list = [];
if ($is_super) {
    $branches_list = $pdo->query(
        "SELECT id, name FROM branches WHERE status='active' ORDER BY name"
    )->fetchAll();
}

// تسميات
$status_labels = [
    'pending'  => ['label' => $is_rtl ? 'معلق'        : 'Pending',  'color' => 'warning'],
    'approved' => ['label' => $is_rtl ? 'مُوافق عليه' : 'Approved', 'color' => 'success'],
    'rejected' => ['label' => $is_rtl ? 'مرفوض'       : 'Rejected', 'color' => 'danger'],
];
$method_labels = [
    'cash'     => $is_rtl ? 'كاش'     : 'Cash',
    'wallet'   => $is_rtl ? 'محفظة'   : 'Wallet',
    'exchange' => $is_rtl ? 'استبدال' : 'Exchange',
];
?>

<?php require_once __DIR__ . '/../includes/layout/sidebar.php'; ?>

<div class="main-content">

    <?php show_flash(); ?>

    <!-- رأس الصفحة -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <h4 class="mb-0" style="color:var(--ts-gold)">
            <i class="bi bi-arrow-return-left me-2"></i>
            <?= $lang['returns_report'] ?>
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
                <div class="col-md-2">
                    <label class="form-label small"><?= $lang['refund_method'] ?></label>
                    <select name="refund_method" class="form-select form-select-sm ts-select">
                        <option value=""><?= $is_rtl ? 'كل الطرق' : 'All Methods' ?></option>
                        <?php foreach ($method_labels as $mk => $ml): ?>
                        <option value="<?= $mk ?>" <?= $filter_refund_method === $mk ? 'selected' : '' ?>>
                            <?= htmlspecialchars($ml) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small"><?= $lang['status'] ?></label>
                    <select name="ret_status" class="form-select form-select-sm ts-select">
                        <option value="all"   <?= $filter_status === 'all'      ? 'selected' : '' ?>>
                            <?= $is_rtl ? 'الكل' : 'All' ?>
                        </option>
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
                    <a href="returns.php" class="btn btn-outline-secondary btn-sm">
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
                        <?= $lang['total_refunds'] ?>
                    </div>
                    <div class="fs-5 fw-bold" style="color:var(--ts-danger)">
                        <?= format_money($total_refund_amt) ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card text-center">
                <div class="card-body py-3">
                    <div class="small text-muted mb-1">
                        <?= $is_rtl ? 'عدد المرتجعات' : 'Returns Count' ?>
                    </div>
                    <div class="fs-5 fw-bold" style="color:var(--ts-gold)">
                        <?= number_format((int)$kpi['total_returns']) ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card text-center">
                <div class="card-body py-3">
                    <div class="small text-muted mb-1">
                        <?= $lang['refund_rate'] ?>
                    </div>
                    <div class="fs-5 fw-bold" style="color:<?= $return_rate > 5 ? 'var(--ts-danger)' : 'var(--ts-success)' ?>">
                        <?= $return_rate ?>%
                    </div>
                    <small class="text-muted d-block" style="font-size:0.7rem">
                        <?= $is_rtl ? 'من المبيعات' : 'of sales' ?>
                    </small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card text-center">
                <div class="card-body py-3">
                    <div class="small text-muted mb-1">
                        <?= $is_rtl ? 'كاش / استبدال / محفظة' : 'Cash / Exchange / Wallet' ?>
                    </div>
                    <div class="fs-6 fw-bold mt-1">
                        <span style="color:var(--ts-success)"><?= format_money((float)$kpi['cash_refund']) ?></span>
                        <span class="text-muted mx-1">/</span>
                        <span style="color:var(--ts-info)"><?= format_money((float)$kpi['exchange_refund']) ?></span>
                        <span class="text-muted mx-1">/</span>
                        <span style="color:var(--ts-silver)"><?= format_money((float)$kpi['wallet_refund']) ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ================================================== -->
    <!-- Chart + توزيع طرق الاسترداد                        -->
    <!-- ================================================== -->
    <div class="row g-3 mb-4">
        <!-- Chart المرتجعات اليومية -->
        <?php if (!empty($chart_rows)): ?>
        <div class="col-md-8 no-print">
            <div class="card h-100">
                <div class="card-header">
                    <i class="bi bi-bar-chart me-1"></i>
                    <?= $is_rtl ? 'المرتجعات اليومية' : 'Daily Returns' ?>
                </div>
                <div class="card-body" style="height:260px">
                    <canvas id="returnsChart"></canvas>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- توزيع طرق الاسترداد -->
        <?php if (!empty($method_dist)): ?>
        <div class="col-md-<?= empty($chart_rows) ? '12' : '4' ?>">
            <div class="card h-100">
                <div class="card-header">
                    <i class="bi bi-pie-chart me-1"></i>
                    <?= $is_rtl ? 'توزيع طرق الاسترداد' : 'Refund Methods' ?>
                </div>
                <div class="card-body">
                    <?php foreach ($method_dist as $md): ?>
                    <?php
                    $pct = $total_refund_amt > 0
                        ? round(((float)$md['total'] / $total_refund_amt) * 100, 1)
                        : 0;
                    $bar_color = match($md['refund_method']) {
                        'cash'     => 'var(--ts-success)',
                        'exchange' => 'var(--ts-info)',
                        'wallet'   => 'var(--ts-silver)',
                        default    => 'var(--ts-gold)',
                    };
                    ?>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between small mb-1">
                            <span><?= htmlspecialchars($method_labels[$md['refund_method']] ?? $md['refund_method']) ?></span>
                            <span class="fw-bold"><?= format_money((float)$md['total']) ?></span>
                        </div>
                        <div class="progress" style="height:8px;background:var(--ts-bg-dark)">
                            <div class="progress-bar" style="width:<?= $pct ?>%;background:<?= $bar_color ?>"></div>
                        </div>
                        <small class="text-muted"><?= $pct ?>% — <?= (int)$md['count'] ?> <?= $is_rtl ? 'مرتجع' : 'returns' ?></small>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- ================================================== -->
    <!-- أكثر المنتجات إرجاعاً                               -->
    <!-- ================================================== -->
    <?php if (!empty($top_products)): ?>
    <div class="card mb-4">
        <div class="card-header">
            <i class="bi bi-trophy me-1"></i>
            <?= $is_rtl ? 'أكثر المنتجات إرجاعاً (Top 10)' : 'Most Returned Products (Top 10)' ?>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th><?= $lang['product'] ?></th>
                            <th><?= $lang['barcode'] ?></th>
                            <th class="text-center"><?= $is_rtl ? 'الكمية المرتجعة' : 'Returned Qty' ?></th>
                            <th class="text-center"><?= $is_rtl ? 'عدد الطلبات' : 'Orders' ?></th>
                            <th><?= $is_rtl ? 'إجمالي قيمة الإرجاع' : 'Total Refund Value' ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($top_products as $idx => $tp): ?>
                        <tr>
                            <td>
                                <span class="badge" style="background:var(--ts-bg-dark);color:var(--ts-gold);border:1px solid var(--ts-border)">
                                    <?= $idx + 1 ?>
                                </span>
                            </td>
                            <td class="fw-semibold">
                                <?= htmlspecialchars($is_rtl ? $tp['name_ar'] : ($tp['name_en'] ?: $tp['name_ar'])) ?>
                            </td>
                            <td><small class="text-muted font-monospace"><?= htmlspecialchars($tp['barcode'] ?? '—') ?></small></td>
                            <td class="text-center fw-bold" style="color:var(--ts-danger)">
                                <?= number_format((float)$tp['total_returned_qty'], 2) ?>
                            </td>
                            <td class="text-center">
                                <span class="badge" style="background:var(--ts-bg-dark);color:var(--ts-silver);border:1px solid var(--ts-border)">
                                    <?= (int)$tp['return_count'] ?>
                                </span>
                            </td>
                            <td style="color:var(--ts-warning)">
                                <?= format_money((float)$tp['total_returned_value']) ?>
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
    <!-- جدول المرتجعات التفصيلي                             -->
    <!-- ================================================== -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>
                <i class="bi bi-list-ul me-1"></i>
                <?= $is_rtl ? 'تفصيل المرتجعات' : 'Returns Detail' ?>
            </span>
            <small class="text-muted"><?= $total_records ?> <?= $lang['results'] ?></small>
        </div>
        <div class="card-body p-0">
            <?php if (empty($returns)): ?>
                <div class="text-center py-5 text-muted">
                    <i class="bi bi-arrow-return-left fs-1 d-block mb-2"></i>
                    <?= $lang['no_results'] ?>
                </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th><?= $lang['return_number'] ?></th>
                            <th><?= $lang['date'] ?></th>
                            <th><?= $lang['invoice_number'] ?></th>
                            <?php if ($is_super): ?>
                            <th><?= $lang['branch'] ?></th>
                            <?php endif; ?>
                            <th class="text-center"><?= $is_rtl ? 'المنتجات' : 'Items' ?></th>
                            <th><?= $lang['total_refund'] ?></th>
                            <th><?= $lang['refund_method'] ?></th>
                            <th><?= $lang['return_reason'] ?></th>
                            <th><?= $lang['status'] ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($returns as $ret): ?>
                        <?php $st = $status_labels[$ret['status']] ?? ['label' => $ret['status'], 'color' => 'secondary']; ?>
                        <tr>
                            <td>
                                <span class="font-monospace small" style="color:var(--ts-gold)">
                                    <?= htmlspecialchars($ret['return_number']) ?>
                                </span>
                            </td>
                            <td>
                                <small class="font-monospace">
                                    <?= date('Y-m-d', strtotime($ret['created_at'])) ?>
                                </small>
                            </td>
                            <td>
                                <small class="text-muted font-monospace">
                                    <?= htmlspecialchars($ret['invoice_number'] ?? '—') ?>
                                </small>
                            </td>
                            <?php if ($is_super): ?>
                            <td><small><?= htmlspecialchars($ret['branch_name'] ?? '—') ?></small></td>
                            <?php endif; ?>
                            <td class="text-center">
                                <span class="badge" style="background:var(--ts-bg-dark);color:var(--ts-silver);border:1px solid var(--ts-border)">
                                    <?= (int)$ret['items_count'] ?>
                                </span>
                            </td>
                            <td class="fw-bold" style="color:var(--ts-danger)">
                                <?= format_money((float)$ret['total_refund']) ?>
                            </td>
                            <td>
                                <span class="badge" style="background:var(--ts-bg-dark);color:var(--ts-silver);border:1px solid var(--ts-border)">
                                    <?= htmlspecialchars($method_labels[$ret['refund_method']] ?? $ret['refund_method']) ?>
                                </span>
                            </td>
                            <td>
                                <small class="text-muted">
                                    <?= htmlspecialchars($ret['reason'] ? mb_substr($ret['reason'], 0, 40) . (mb_strlen($ret['reason']) > 40 ? '…' : '') : '—') ?>
                                </small>
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
                            <td colspan="<?= $is_super ? 5 : 4 ?>" class="fw-bold" style="color:var(--ts-gold)">
                                <?= $is_rtl ? 'الإجمالي الكلي' : 'Grand Total' ?>
                            </td>
                            <td class="fw-bold" style="color:var(--ts-danger)">
                                <?= format_money($total_refund_amt) ?>
                            </td>
                            <td colspan="3"></td>
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
    var ctx = document.getElementById('returnsChart');
    if (!ctx) return;

    var labels  = <?= json_encode($chart_labels)  ?>;
    var amounts = <?= json_encode($chart_amounts) ?>;
    var counts  = <?= json_encode($chart_counts)  ?>;

    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [
                {
                    label: '<?= $is_rtl ? 'قيمة المرتجعات (SDG)' : 'Returns Value (SDG)' ?>',
                    data: amounts,
                    backgroundColor: 'rgba(220,53,69,0.65)',
                    borderColor: '#DC3545',
                    borderWidth: 1,
                    yAxisID: 'y',
                },
                {
                    label: '<?= $is_rtl ? 'عدد المرتجعات' : 'Returns Count' ?>',
                    data: counts,
                    type: 'line',
                    borderColor: '#C9A84C',
                    backgroundColor: 'rgba(201,168,76,0.1)',
                    borderWidth: 2,
                    pointRadius: 4,
                    yAxisID: 'y1',
                    tension: 0.3,
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { labels: { color: '#F5F5F5', font: { family: 'Tajawal' } } },
                tooltip: {
                    callbacks: {
                        label: function (ctx) {
                            if (ctx.datasetIndex === 0)
                                return ' ' + Number(ctx.raw).toLocaleString() + ' SDG';
                            return ' ' + ctx.raw + ' <?= $is_rtl ? 'مرتجع' : 'returns' ?>';
                        }
                    }
                }
            },
            scales: {
                x: { ticks: { color: '#B0B3B8' }, grid: { color: '#3A3A3A' } },
                y: {
                    type: 'linear',
                    position: '<?= $is_rtl ? 'right' : 'left' ?>',
                    ticks: { color: '#DC3545', callback: v => v.toLocaleString() + ' SDG' },
                    grid: { color: '#3A3A3A' }
                },
                y1: {
                    type: 'linear',
                    position: '<?= $is_rtl ? 'left' : 'right' ?>',
                    ticks: { color: '#C9A84C' },
                    grid: { drawOnChartArea: false }
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
