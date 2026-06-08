<?php
// ============================================================
// المسار: reports/sales.php
// الوظيفة: تقرير المبيعات التفصيلي — فلترة + Chart + CSV
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
$filter_branch     = $is_super ? sanitize_int($_GET['branch_id']  ?? 0) : (int)$user_bid;
$filter_cashier    = sanitize_int($_GET['cashier_id'] ?? 0);
$filter_payment    = trim($_GET['payment_method'] ?? '');

$valid_payments = ['cash','bankak','ocash','card','other'];

$where_parts = ["i.status = 'completed'"];
$params      = [];

if (!$is_super) {
    $where_parts[] = 'i.branch_id = ?';
    $params[]      = $user_bid;
} elseif ($filter_branch > 0) {
    $where_parts[] = 'i.branch_id = ?';
    $params[]      = $filter_branch;
}
if ($filter_cashier > 0) {
    $where_parts[] = 'i.cashier_id = ?';
    $params[]      = $filter_cashier;
}
if ($filter_payment !== '' && in_array($filter_payment, $valid_payments, true)) {
    $where_parts[] = 'i.payment_method = ?';
    $params[]      = $filter_payment;
}
if ($filter_date_from !== '') {
    $where_parts[] = 'DATE(i.created_at) >= ?';
    $params[]      = $filter_date_from;
}
if ($filter_date_to !== '') {
    $where_parts[] = 'DATE(i.created_at) <= ?';
    $params[]      = $filter_date_to;
}

$where_sql = 'WHERE ' . implode(' AND ', $where_parts);

// ============================================================
// تصدير CSV
// ============================================================
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $csv_stmt = $pdo->prepare("
        SELECT
            i.invoice_number, DATE(i.created_at) AS sale_date,
            TIME(i.created_at) AS sale_time,
            b.name AS branch_name,
            u.name AS cashier_name,
            c.name AS customer_name,
            i.subtotal, i.discount_amount, i.total,
            i.payment_method, i.amount_paid, i.change_amount
        FROM invoices i
        LEFT JOIN branches b ON b.id = i.branch_id
        LEFT JOIN users    u ON u.id = i.cashier_id
        LEFT JOIN customers c ON c.id = i.customer_id
        $where_sql
        ORDER BY i.created_at DESC
    ");
    $csv_stmt->execute($params);
    $csv_rows = $csv_stmt->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="sales_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM for Excel Arabic
    fputcsv($out, [
        $lang['invoice_number'], $lang['date'], $lang['time'],
        $lang['branch'], $lang['cashier_role'], $lang['customer'],
        $lang['subtotal'], $lang['discount'], $lang['total'],
        $lang['payment_method'], $lang['amount_paid'], $lang['change_amount'],
    ]);
    foreach ($csv_rows as $row) {
        fputcsv($out, [
            $row['invoice_number'], $row['sale_date'], $row['sale_time'],
            $row['branch_name'], $row['cashier_name'], $row['customer_name'] ?? '—',
            $row['subtotal'], $row['discount_amount'], $row['total'],
            $row['payment_method'], $row['amount_paid'], $row['change_amount'],
        ]);
    }
    fclose($out);
    exit;
}

// ============================================================
// KPI Cards
// ============================================================
$kpi_stmt = $pdo->prepare("
    SELECT
        COUNT(*)                     AS total_invoices,
        COALESCE(SUM(i.total),0)     AS total_sales,
        COALESCE(AVG(i.total),0)     AS avg_invoice,
        COALESCE(SUM(i.discount_amount),0) AS total_discounts
    FROM invoices i
    $where_sql
");
$kpi_stmt->execute($params);
$kpi = $kpi_stmt->fetch();

// ============================================================
// بيانات الرسم البياني — مبيعات يومية
// ============================================================
$chart_stmt = $pdo->prepare("
    SELECT
        DATE(i.created_at)       AS day,
        COUNT(*)                 AS invoice_count,
        COALESCE(SUM(i.total),0) AS day_total
    FROM invoices i
    $where_sql
    GROUP BY DATE(i.created_at)
    ORDER BY day ASC
");
$chart_stmt->execute($params);
$chart_rows = $chart_stmt->fetchAll();

$chart_labels = array_column($chart_rows, 'day');
$chart_sales  = array_map(fn($r) => (float)$r['day_total'],    $chart_rows);
$chart_counts = array_map(fn($r) => (int)$r['invoice_count'],  $chart_rows);

// ============================================================
// الجدول التفصيلي — Pagination
// ============================================================
$page_num = max(1, (int)($_GET['p'] ?? 1));
$per_page = 25;

$count_st = $pdo->prepare("SELECT COUNT(*) FROM invoices i $where_sql");
$count_st->execute($params);
$total_records = (int)$count_st->fetchColumn();
$total_pages   = max(1, (int)ceil($total_records / $per_page));
$offset        = ($page_num - 1) * $per_page;

$list_stmt = $pdo->prepare("
    SELECT
        i.id, i.invoice_number, i.created_at,
        i.subtotal, i.discount_amount, i.total,
        i.payment_method, i.amount_paid, i.change_amount,
        b.name  AS branch_name,
        u.name  AS cashier_name,
        c.name  AS customer_name
    FROM invoices i
    LEFT JOIN branches  b ON b.id = i.branch_id
    LEFT JOIN users     u ON u.id = i.cashier_id
    LEFT JOIN customers c ON c.id = i.customer_id
    $where_sql
    ORDER BY i.created_at DESC
    LIMIT $per_page OFFSET $offset
");
$list_stmt->execute($params);
$invoices = $list_stmt->fetchAll();

// ============================================================
// بيانات الفلاتر
// ============================================================
$branches_list = [];
if ($is_super) {
    $branches_list = $pdo->query(
        "SELECT id, name FROM branches WHERE status='active' ORDER BY name"
    )->fetchAll();
}

// قائمة الكاشيرين حسب الفرع
$cashier_where = $is_super
    ? ($filter_branch > 0 ? "branch_id = $filter_branch AND role='cashier'" : "role='cashier'")
    : "branch_id = $user_bid AND role='cashier'";
$cashiers_list = $pdo->query(
    "SELECT id, name FROM users WHERE $cashier_where AND status='active' ORDER BY name"
)->fetchAll();

$payment_labels = [
    'cash'   => $is_rtl ? 'كاش'     : 'Cash',
    'bankak' => 'Bankak',
    'ocash'  => 'OCash',
    'card'   => $is_rtl ? 'بطاقة'   : 'Card',
    'other'  => $is_rtl ? 'أخرى'    : 'Other',
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
            <i class="bi bi-graph-up me-2"></i>
            <?= $lang['sales_report'] ?>
        </h4>
        <div class="d-flex gap-2">
            <a href="?<?= http_build_query(array_merge($_GET, ['export'=>'csv'])) ?>"
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
                        <option value="<?= $br['id'] ?>" <?= $filter_branch == $br['id'] ? 'selected':'' ?>>
                            <?= htmlspecialchars($br['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="col-md-2">
                    <label class="form-label small"><?= $is_rtl ? 'الكاشير' : 'Cashier' ?></label>
                    <select name="cashier_id" class="form-select form-select-sm ts-select">
                        <option value=""><?= $is_rtl ? 'الكل' : 'All' ?></option>
                        <?php foreach ($cashiers_list as $cs): ?>
                        <option value="<?= $cs['id'] ?>" <?= $filter_cashier == $cs['id'] ? 'selected':'' ?>>
                            <?= htmlspecialchars($cs['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small"><?= $lang['payment_method'] ?></label>
                    <select name="payment_method" class="form-select form-select-sm ts-select">
                        <option value=""><?= $is_rtl ? 'الكل' : 'All' ?></option>
                        <?php foreach ($payment_labels as $pk => $pl): ?>
                        <option value="<?= $pk ?>" <?= $filter_payment === $pk ? 'selected':'' ?>>
                            <?= htmlspecialchars($pl) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-primary btn-sm flex-grow-1">
                        <i class="bi bi-funnel me-1"></i><?= $lang['filter'] ?>
                    </button>
                    <a href="sales.php" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-x"></i>
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- ================================================== -->
    <!-- KPI Cards                                            -->
    <!-- ================================================== -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <div class="small text-muted mb-1"><?= $lang['total_sales'] ?></div>
                    <div class="fs-4 fw-bold" style="color:var(--ts-success)">
                        <?= format_money((float)$kpi['total_sales']) ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <div class="small text-muted mb-1"><?= $is_rtl ? 'عدد الفواتير' : 'Invoices Count' ?></div>
                    <div class="fs-4 fw-bold" style="color:var(--ts-gold)">
                        <?= number_format((int)$kpi['total_invoices']) ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <div class="small text-muted mb-1"><?= $is_rtl ? 'متوسط الفاتورة' : 'Avg Invoice' ?></div>
                    <div class="fs-4 fw-bold" style="color:var(--ts-silver)">
                        <?= format_money((float)$kpi['avg_invoice']) ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <div class="small text-muted mb-1"><?= $is_rtl ? 'إجمالي الخصومات' : 'Total Discounts' ?></div>
                    <div class="fs-4 fw-bold" style="color:var(--ts-danger)">
                        <?= format_money((float)$kpi['total_discounts']) ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ================================================== -->
    <!-- Chart: مبيعات يومية                                 -->
    <!-- ================================================== -->
    <?php if (!empty($chart_rows)): ?>
    <div class="card mb-4 no-print">
        <div class="card-header">
            <i class="bi bi-bar-chart me-1"></i>
            <?= $is_rtl ? 'المبيعات اليومية' : 'Daily Sales' ?>
        </div>
        <div class="card-body" style="height:280px">
            <canvas id="salesChart"></canvas>
        </div>
    </div>
    <?php endif; ?>

    <!-- ================================================== -->
    <!-- جدول الفواتير                                        -->
    <!-- ================================================== -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><?= $lang['sales_report'] ?></span>
            <small class="text-muted"><?= $total_records ?> <?= $lang['results'] ?></small>
        </div>
        <div class="card-body p-0">
            <?php if (empty($invoices)): ?>
                <div class="text-center py-5 text-muted">
                    <i class="bi bi-receipt fs-1 d-block mb-2"></i>
                    <?= $lang['no_results'] ?>
                </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th><?= $lang['invoice_number'] ?></th>
                            <th><?= $lang['date'] ?></th>
                            <?php if ($is_super): ?><th><?= $lang['branch'] ?></th><?php endif; ?>
                            <th><?= $is_rtl ? 'الكاشير' : 'Cashier' ?></th>
                            <th><?= $lang['customer'] ?></th>
                            <th><?= $lang['subtotal'] ?></th>
                            <th><?= $lang['discount'] ?></th>
                            <th><?= $lang['total'] ?></th>
                            <th><?= $lang['payment_method'] ?></th>
                            <th class="text-center"><?= $lang['action'] ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($invoices as $inv): ?>
                        <tr>
                            <td>
                                <span class="font-monospace small" style="color:var(--ts-gold)">
                                    <?= htmlspecialchars($inv['invoice_number']) ?>
                                </span>
                            </td>
                            <td>
                                <small class="font-monospace">
                                    <?= date('Y-m-d', strtotime($inv['created_at'])) ?><br>
                                    <span class="text-muted"><?= date('H:i', strtotime($inv['created_at'])) ?></span>
                                </small>
                            </td>
                            <?php if ($is_super): ?>
                            <td><small><?= htmlspecialchars($inv['branch_name'] ?? '—') ?></small></td>
                            <?php endif; ?>
                            <td><small><?= htmlspecialchars($inv['cashier_name'] ?? '—') ?></small></td>
                            <td><small class="text-muted"><?= htmlspecialchars($inv['customer_name'] ?? '—') ?></small></td>
                            <td><small><?= format_money((float)$inv['subtotal']) ?></small></td>
                            <td>
                                <?php if ((float)$inv['discount_amount'] > 0): ?>
                                    <small style="color:var(--ts-danger)">
                                        -<?= format_money((float)$inv['discount_amount']) ?>
                                    </small>
                                <?php else: ?>
                                    <small class="text-muted">—</small>
                                <?php endif; ?>
                            </td>
                            <td class="fw-bold" style="color:var(--ts-success)">
                                <?= format_money((float)$inv['total']) ?>
                            </td>
                            <td>
                                <span class="badge"
                                      style="background:var(--ts-bg-dark);color:var(--ts-silver);border:1px solid var(--ts-border)">
                                    <?= htmlspecialchars($payment_labels[$inv['payment_method']] ?? $inv['payment_method']) ?>
                                </span>
                            </td>
                            <td class="text-center">
                                <a href="../cashier/invoice_print.php?id=<?= $inv['id'] ?>"
                                   target="_blank"
                                   class="btn btn-sm btn-outline-secondary"
                                   title="<?= $lang['print'] ?>">
                                    <i class="bi bi-printer"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="background:var(--ts-bg-dark)">
                            <td colspan="<?= $is_super ? 7 : 6 ?>" class="fw-bold" style="color:var(--ts-gold)">
                                <?= $is_rtl ? 'الإجمالي' : 'Total' ?>
                            </td>
                            <td class="fw-bold" style="color:var(--ts-success)">
                                <?= format_money((float)$kpi['total_sales']) ?>
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
                    <?= $lang['showing'] ?> <?= $offset+1 ?>–<?= min($offset+$per_page, $total_records) ?>
                    <?= $lang['of'] ?> <?= $total_records ?> <?= $lang['results'] ?>
                </small>
                <nav>
                    <ul class="pagination pagination-sm mb-0">
                        <?php for ($p=1; $p<=$total_pages; $p++): ?>
                        <li class="page-item <?= $p===$page_num?'active':'' ?>">
                            <a class="page-link"
                               href="?<?= http_build_query(array_merge($_GET,['p'=>$p])) ?>">
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
(function(){
    var ctx = document.getElementById('salesChart');
    if (!ctx) return;

    var labels = <?= json_encode($chart_labels) ?>;
    var sales  = <?= json_encode($chart_sales)  ?>;
    var counts = <?= json_encode($chart_counts) ?>;

    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [
                {
                    label: '<?= $is_rtl ? 'المبيعات (SDG)' : 'Sales (SDG)' ?>',
                    data: sales,
                    backgroundColor: 'rgba(201,168,76,0.7)',
                    borderColor: '#C9A84C',
                    borderWidth: 1,
                    yAxisID: 'y',
                },
                {
                    label: '<?= $is_rtl ? 'عدد الفواتير' : 'Invoices' ?>',
                    data: counts,
                    type: 'line',
                    borderColor: '#B0B3B8',
                    backgroundColor: 'rgba(176,179,184,0.1)',
                    borderWidth: 2,
                    pointRadius: 3,
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
                        label: function(ctx) {
                            if (ctx.datasetIndex === 0)
                                return ' ' + Number(ctx.raw).toLocaleString() + ' SDG';
                            return ' ' + ctx.raw + ' <?= $is_rtl ? 'فاتورة' : 'invoices' ?>';
                        }
                    }
                }
            },
            scales: {
                x: { ticks:{ color:'#B0B3B8' }, grid:{ color:'#3A3A3A' } },
                y: {
                    type: 'linear', position: '<?= $is_rtl ? 'right' : 'left' ?>',
                    ticks: { color:'#C9A84C', callback: v => v.toLocaleString() + ' SDG' },
                    grid: { color:'#3A3A3A' }
                },
                y1: {
                    type: 'linear', position: '<?= $is_rtl ? 'left' : 'right' ?>',
                    ticks: { color:'#B0B3B8' },
                    grid: { drawOnChartArea: false }
                }
            }
        }
    });
})();
</script>
<?php endif; ?>

<style>
.main-content { padding: 1.5rem; }
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
    .badge { border: 1px solid #ccc !important; color: #000 !important; background: #f9f9f9 !important; }
}
</style>

<?php require_once __DIR__ . '/../includes/layout/footer.php'; ?>
