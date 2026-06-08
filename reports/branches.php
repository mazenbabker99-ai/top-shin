<?php
// ============================================================
// المسار: reports/branches.php
// الوظيفة: تقرير مقارنة الفروع — super_admin فقط
// الصلاحية: super_admin
// ============================================================

declare(strict_types=1);

$current_page = 'reports';
require_once __DIR__ . '/../includes/layout/header.php';
require_role(['super_admin']);

$pdo = db();

// ============================================================
// فلاتر
// ============================================================
$filter_date_from = trim($_GET['date_from'] ?? date('Y-m-01'));
$filter_date_to   = trim($_GET['date_to']   ?? date('Y-m-d'));

$date_params = [$filter_date_from, $filter_date_to];

// ============================================================
// تصدير CSV
// ============================================================
if (isset($_GET['export']) && $_GET['export'] === 'csv') {

    // جلب البيانات كاملاً للتصدير
    $csv_stmt = $pdo->prepare("
        SELECT
            b.name   AS branch_name,
            b.code   AS branch_code,
            COALESCE(SUM(CASE WHEN i.status='completed' THEN i.total ELSE 0 END), 0)
                AS total_sales,
            COUNT(CASE WHEN i.status='completed' THEN 1 END)
                AS invoice_count,
            COALESCE(AVG(CASE WHEN i.status='completed' THEN i.total END), 0)
                AS avg_invoice,
            COALESCE(SUM(CASE WHEN i.status='completed' THEN i.discount_amount ELSE 0 END), 0)
                AS total_discounts,
            COALESCE((
                SELECT SUM(r.total_refund) FROM returns r
                WHERE r.branch_id = b.id AND r.status = 'approved'
                  AND DATE(r.created_at) BETWEEN ? AND ?
            ), 0) AS total_returns,
            COALESCE((
                SELECT SUM(e.amount) FROM expenses e
                WHERE e.branch_id = b.id
                  AND DATE(e.expense_date) BETWEEN ? AND ?
            ), 0) AS total_expenses,
            (SELECT COUNT(*) FROM users u
             WHERE u.branch_id = b.id AND u.role='cashier' AND u.status='active')
                AS active_cashiers
        FROM branches b
        LEFT JOIN invoices i
            ON i.branch_id = b.id
            AND DATE(i.created_at) BETWEEN ? AND ?
        WHERE b.status = 'active'
        GROUP BY b.id, b.name, b.code
        ORDER BY total_sales DESC
    ");
    $csv_stmt->execute([
        $filter_date_from, $filter_date_to,
        $filter_date_from, $filter_date_to,
        $filter_date_from, $filter_date_to,
    ]);
    $csv_rows = $csv_stmt->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="branches_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM for Excel Arabic

    fputcsv($out, [
        $is_rtl ? 'الفرع'           : 'Branch',
        $is_rtl ? 'الرمز'           : 'Code',
        $lang['total_sales'],
        $is_rtl ? 'عدد الفواتير'    : 'Invoices',
        $is_rtl ? 'متوسط الفاتورة'  : 'Avg Invoice',
        $lang['total_discounts'],
        $lang['total_expenses'],
        $lang['total_refunds'],
        $is_rtl ? 'صافي الفرع'      : 'Branch Net',
        $is_rtl ? 'الكاشيرون'       : 'Cashiers',
        $is_rtl ? 'الفترة'          : 'Period',
    ]);

    foreach ($csv_rows as $row) {
        $net = (float)$row['total_sales']
             - (float)$row['total_expenses']
             - (float)$row['total_returns'];
        fputcsv($out, [
            $row['branch_name'],
            $row['branch_code'],
            number_format((float)$row['total_sales'],    2),
            (int)$row['invoice_count'],
            number_format((float)$row['avg_invoice'],    2),
            number_format((float)$row['total_discounts'],2),
            number_format((float)$row['total_expenses'], 2),
            number_format((float)$row['total_returns'],  2),
            number_format($net, 2),
            (int)$row['active_cashiers'],
            $filter_date_from . ' → ' . $filter_date_to,
        ]);
    }
    fclose($out);
    exit;
}
$branches_stmt = $pdo->prepare("
    SELECT
        b.id,
        b.name   AS branch_name,
        b.code   AS branch_code,
        b.status AS branch_status,

        -- إجمالي المبيعات
        COALESCE(SUM(CASE WHEN i.status='completed' THEN i.total ELSE 0 END), 0)
            AS total_sales,

        -- عدد الفواتير
        COUNT(CASE WHEN i.status='completed' THEN 1 END)
            AS invoice_count,

        -- متوسط الفاتورة
        COALESCE(AVG(CASE WHEN i.status='completed' THEN i.total END), 0)
            AS avg_invoice,

        -- إجمالي الخصومات
        COALESCE(SUM(CASE WHEN i.status='completed' THEN i.discount_amount ELSE 0 END), 0)
            AS total_discounts,

        -- إجمالي المرتجعات
        COALESCE((
            SELECT SUM(r.total_refund)
            FROM returns r
            WHERE r.branch_id = b.id
              AND r.status = 'approved'
              AND DATE(r.created_at) BETWEEN ? AND ?
        ), 0) AS total_returns,

        -- إجمالي المصروفات
        COALESCE((
            SELECT SUM(e.amount)
            FROM expenses e
            WHERE e.branch_id = b.id
              AND DATE(e.expense_date) BETWEEN ? AND ?
        ), 0) AS total_expenses,

        -- عدد الكاشيرين النشطين
        (SELECT COUNT(*) FROM users u
         WHERE u.branch_id = b.id AND u.role='cashier' AND u.status='active')
            AS active_cashiers

    FROM branches b
    LEFT JOIN invoices i
        ON i.branch_id = b.id
        AND DATE(i.created_at) BETWEEN ? AND ?
    WHERE b.status = 'active'
    GROUP BY b.id, b.name, b.code, b.status
    ORDER BY total_sales DESC
");

$branches_stmt->execute([
    $filter_date_from, $filter_date_to,  // returns subquery
    $filter_date_from, $filter_date_to,  // expenses subquery
    $filter_date_from, $filter_date_to,  // main LEFT JOIN
]);
$branches_data = $branches_stmt->fetchAll();

// ============================================================
// أكثر منتج مبيعاً لكل فرع
// ============================================================
$top_products = [];
foreach ($branches_data as $br) {
    $tp_stmt = $pdo->prepare("
        SELECT ii.product_name, SUM(ii.quantity) AS total_qty
        FROM invoice_items ii
        JOIN invoices i ON i.id = ii.invoice_id
        WHERE i.branch_id = ?
          AND i.status = 'completed'
          AND DATE(i.created_at) BETWEEN ? AND ?
        GROUP BY ii.product_name
        ORDER BY total_qty DESC
        LIMIT 1
    ");
    $tp_stmt->execute([$br['id'], $filter_date_from, $filter_date_to]);
    $top = $tp_stmt->fetch();
    $top_products[$br['id']] = $top ?: null;
}

// ============================================================
// إجماليات كل الفروع
// ============================================================
$grand_sales    = array_sum(array_column($branches_data, 'total_sales'));
$grand_invoices = array_sum(array_column($branches_data, 'invoice_count'));
$grand_expenses = array_sum(array_column($branches_data, 'total_expenses'));
$grand_returns  = array_sum(array_column($branches_data, 'total_returns'));

// بيانات الرسم البياني
$chart_names  = array_column($branches_data, 'branch_name');
$chart_sales  = array_map(fn($r) => (float)$r['total_sales'],    $branches_data);
$chart_exp    = array_map(fn($r) => (float)$r['total_expenses'],  $branches_data);
$chart_ret    = array_map(fn($r) => (float)$r['total_returns'],   $branches_data);

$depth       = 1;
$assets_path = '../assets';
?>

<?php require_once __DIR__ . '/../includes/layout/sidebar.php'; ?>

<div class="main-content">

    <?php show_flash(); ?>

    <!-- رأس الصفحة -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <h4 class="mb-0" style="color:var(--ts-gold)">
            <i class="bi bi-diagram-3 me-2"></i>
            <?= $lang['branches_report'] ?>
        </h4>
        <div class="d-flex gap-2 flex-wrap">
            <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>"
               class="btn btn-outline-secondary btn-sm no-print">
                <i class="bi bi-filetype-csv me-1"></i>
                <?= $is_rtl ? 'تصدير CSV' : 'Export CSV' ?>
            </a>
            <button onclick="window.print()" class="btn btn-outline-secondary btn-sm no-print">
                <i class="bi bi-printer me-1"></i><?= $lang['print'] ?>
            </button>
        </div>
    </div>

    <!-- ================================================== -->
    <!-- فلاتر                                               -->
    <!-- ================================================== -->
    <div class="card mb-4 no-print">
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small"><?= $lang['date_from'] ?></label>
                    <input type="date" name="date_from" class="form-control form-control-sm ts-input"
                           value="<?= htmlspecialchars($filter_date_from) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small"><?= $lang['date_to'] ?></label>
                    <input type="date" name="date_to" class="form-control form-control-sm ts-input"
                           value="<?= htmlspecialchars($filter_date_to) ?>">
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-primary btn-sm flex-grow-1">
                        <i class="bi bi-funnel me-1"></i><?= $lang['filter'] ?>
                    </button>
                    <a href="branches.php" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-x"></i>
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- ================================================== -->
    <!-- KPI Cards — إجماليات كل الفروع                     -->
    <!-- ================================================== -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <div class="small text-muted mb-1"><?= $lang['total_sales'] ?></div>
                    <div class="fs-4 fw-bold" style="color:var(--ts-success)">
                        <?= format_money($grand_sales) ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <div class="small text-muted mb-1"><?= $is_rtl ? 'إجمالي الفواتير' : 'Total Invoices' ?></div>
                    <div class="fs-4 fw-bold" style="color:var(--ts-gold)">
                        <?= number_format($grand_invoices) ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <div class="small text-muted mb-1"><?= $lang['total_expenses'] ?></div>
                    <div class="fs-4 fw-bold" style="color:var(--ts-danger)">
                        <?= format_money($grand_expenses) ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <div class="small text-muted mb-1"><?= $lang['returns'] ?></div>
                    <div class="fs-4 fw-bold" style="color:#ff6b35">
                        <?= format_money($grand_returns) ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ================================================== -->
    <!-- Chart: مقارنة الفروع                                -->
    <!-- ================================================== -->
    <?php if (!empty($branches_data)): ?>
    <div class="card mb-4 no-print">
        <div class="card-header">
            <i class="bi bi-bar-chart-grouped me-1"></i>
            <?= $is_rtl ? 'مقارنة الفروع' : 'Branch Comparison' ?>
        </div>
        <div class="card-body" style="height:300px">
            <canvas id="branchChart"></canvas>
        </div>
    </div>
    <?php endif; ?>

    <!-- ================================================== -->
    <!-- بطاقات الفروع التفصيلية                             -->
    <!-- ================================================== -->
    <div class="row g-4 mb-4">
        <?php foreach ($branches_data as $idx => $br):
            $pct = $grand_sales > 0
                ? round((float)$br['total_sales'] / $grand_sales * 100, 1)
                : 0;
            $net = (float)$br['total_sales'] - (float)$br['total_expenses'] - (float)$br['total_returns'];
            $rank_colors = ['#C9A84C', '#B0B3B8', '#CD7F32'];
            $rank_color  = $rank_colors[$idx] ?? 'var(--ts-border)';
        ?>
        <div class="col-md-6 col-xl-4">
            <div class="card h-100"
                 style="border-top: 3px solid <?= $rank_color ?>">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div>
                        <!-- ترتيب الفرع -->
                        <span class="fw-bold me-2"
                              style="color:<?= $rank_color ?>;font-size:1.1rem">
                            #<?= $idx + 1 ?>
                        </span>
                        <span class="fw-semibold"><?= htmlspecialchars($br['branch_name']) ?></span>
                        <code class="ms-1"
                              style="color:var(--ts-silver);font-size:.75rem;background:var(--ts-bg-dark);padding:.1rem .3rem;border-radius:3px">
                            <?= htmlspecialchars($br['branch_code']) ?>
                        </code>
                    </div>
                    <span class="badge bg-success"><?= $lang['active'] ?></span>
                </div>
                <div class="card-body">
                    <!-- نسبة المبيعات -->
                    <div class="mb-3">
                        <div class="d-flex justify-content-between small mb-1">
                            <span class="text-muted"><?= $lang['total_sales'] ?></span>
                            <span style="color:var(--ts-gold)"><?= $pct ?>%</span>
                        </div>
                        <div class="progress" style="height:6px;background:var(--ts-border)">
                            <div class="progress-bar"
                                 style="width:<?= $pct ?>%;background:<?= $rank_color ?>">
                            </div>
                        </div>
                    </div>

                    <!-- أرقام -->
                    <div class="row g-2 text-center mb-3">
                        <div class="col-6">
                            <div class="small text-muted"><?= $lang['total_sales'] ?></div>
                            <div class="fw-bold" style="color:var(--ts-success);font-size:.95rem">
                                <?= format_money((float)$br['total_sales']) ?>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="small text-muted"><?= $is_rtl ? 'الفواتير' : 'Invoices' ?></div>
                            <div class="fw-bold" style="color:var(--ts-gold);font-size:.95rem">
                                <?= number_format((int)$br['invoice_count']) ?>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="small text-muted"><?= $lang['total_expenses'] ?></div>
                            <div class="fw-bold" style="color:var(--ts-danger);font-size:.95rem">
                                <?= format_money((float)$br['total_expenses']) ?>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="small text-muted"><?= $lang['returns'] ?></div>
                            <div class="fw-bold" style="color:#ff6b35;font-size:.95rem">
                                <?= format_money((float)$br['total_returns']) ?>
                            </div>
                        </div>
                    </div>

                    <hr style="border-color:var(--ts-border)">

                    <!-- صافي -->
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="small text-muted"><?= $is_rtl ? 'صافي الفرع' : 'Branch Net' ?></span>
                        <span class="fw-bold"
                              style="color:<?= $net >= 0 ? 'var(--ts-success)' : 'var(--ts-danger)' ?>">
                            <?= format_money($net) ?>
                        </span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="small text-muted"><?= $is_rtl ? 'متوسط الفاتورة' : 'Avg Invoice' ?></span>
                        <span class="fw-semibold" style="color:var(--ts-silver)">
                            <?= format_money((float)$br['avg_invoice']) ?>
                        </span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="small text-muted"><?= $is_rtl ? 'الكاشيرون النشطون' : 'Active Cashiers' ?></span>
                        <span class="badge bg-secondary"><?= $br['active_cashiers'] ?></span>
                    </div>

                    <!-- أكثر منتج مبيعاً -->
                    <?php $tp = $top_products[$br['id']] ?? null; ?>
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="small text-muted">
                            <i class="bi bi-trophy me-1" style="color:var(--ts-gold)"></i>
                            <?= $is_rtl ? 'الأكثر مبيعاً' : 'Top Product' ?>
                        </span>
                        <span class="small fw-semibold" style="color:var(--ts-text-primary)">
                            <?php if ($tp): ?>
                                <?= htmlspecialchars($tp['product_name']) ?>
                                <span class="text-muted">(<?= number_format((float)$tp['total_qty'],0) ?>)</span>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- ================================================== -->
    <!-- جدول ملخص مقارن                                     -->
    <!-- ================================================== -->
    <div class="card">
        <div class="card-header">
            <i class="bi bi-table me-1"></i>
            <?= $is_rtl ? 'جدول المقارنة' : 'Comparison Table' ?>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th><?= $lang['branch'] ?></th>
                            <th class="text-center"><?= $is_rtl ? 'الفواتير' : 'Invoices' ?></th>
                            <th class="text-end"><?= $lang['total_sales'] ?></th>
                            <th class="text-end"><?= $lang['total_expenses'] ?></th>
                            <th class="text-end"><?= $lang['returns'] ?></th>
                            <th class="text-end"><?= $is_rtl ? 'صافي الفرع' : 'Net' ?></th>
                            <th class="text-center"><?= $is_rtl ? 'نسبة المبيعات' : 'Sales Share' ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($branches_data as $br):
                            $pct = $grand_sales > 0
                                ? round((float)$br['total_sales'] / $grand_sales * 100, 1)
                                : 0;
                            $net = (float)$br['total_sales']
                                 - (float)$br['total_expenses']
                                 - (float)$br['total_returns'];
                        ?>
                        <tr>
                            <td>
                                <div class="fw-semibold"><?= htmlspecialchars($br['branch_name']) ?></div>
                                <small class="text-muted font-monospace"><?= $br['branch_code'] ?></small>
                            </td>
                            <td class="text-center">
                                <span class="badge" style="background:var(--ts-bg-dark);color:var(--ts-gold);border:1px solid var(--ts-border-gold)">
                                    <?= number_format((int)$br['invoice_count']) ?>
                                </span>
                            </td>
                            <td class="text-end fw-bold" style="color:var(--ts-success)">
                                <?= format_money((float)$br['total_sales']) ?>
                            </td>
                            <td class="text-end" style="color:var(--ts-danger)">
                                <?= format_money((float)$br['total_expenses']) ?>
                            </td>
                            <td class="text-end" style="color:#ff6b35">
                                <?= format_money((float)$br['total_returns']) ?>
                            </td>
                            <td class="text-end fw-bold"
                                style="color:<?= $net >= 0 ? 'var(--ts-success)' : 'var(--ts-danger)' ?>">
                                <?= format_money($net) ?>
                            </td>
                            <td class="text-center">
                                <div class="d-flex align-items-center gap-2 justify-content-center">
                                    <div class="progress flex-grow-1"
                                         style="height:6px;max-width:80px;background:var(--ts-border)">
                                        <div class="progress-bar"
                                             style="width:<?= $pct ?>%;background:var(--ts-gold)">
                                        </div>
                                    </div>
                                    <small style="color:var(--ts-gold);width:40px"><?= $pct ?>%</small>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="background:var(--ts-bg-dark)">
                            <td class="fw-bold" style="color:var(--ts-gold)">
                                <?= $is_rtl ? 'الإجمالي' : 'Total' ?>
                            </td>
                            <td class="text-center fw-bold" style="color:var(--ts-gold)">
                                <?= number_format($grand_invoices) ?>
                            </td>
                            <td class="text-end fw-bold" style="color:var(--ts-success)">
                                <?= format_money($grand_sales) ?>
                            </td>
                            <td class="text-end fw-bold" style="color:var(--ts-danger)">
                                <?= format_money($grand_expenses) ?>
                            </td>
                            <td class="text-end fw-bold" style="color:#ff6b35">
                                <?= format_money($grand_returns) ?>
                            </td>
                            <td class="text-end fw-bold"
                                style="color:<?= ($grand_sales-$grand_expenses-$grand_returns)>=0?'var(--ts-success)':'var(--ts-danger)' ?>">
                                <?= format_money($grand_sales - $grand_expenses - $grand_returns) ?>
                            </td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

</div><!-- /.main-content -->

<!-- Chart.js -->
<?php if (!empty($branches_data)): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
(function(){
    var ctx = document.getElementById('branchChart');
    if (!ctx) return;

    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: <?= json_encode($chart_names) ?>,
            datasets: [
                {
                    label: '<?= $is_rtl ? 'المبيعات' : 'Sales' ?>',
                    data: <?= json_encode($chart_sales) ?>,
                    backgroundColor: 'rgba(201,168,76,0.8)',
                    borderColor: '#C9A84C',
                    borderWidth: 1,
                },
                {
                    label: '<?= $lang['total_expenses'] ?>',
                    data: <?= json_encode($chart_exp) ?>,
                    backgroundColor: 'rgba(220,53,69,0.7)',
                    borderColor: '#DC3545',
                    borderWidth: 1,
                },
                {
                    label: '<?= $lang['returns'] ?>',
                    data: <?= json_encode($chart_ret) ?>,
                    backgroundColor: 'rgba(255,107,53,0.6)',
                    borderColor: '#ff6b35',
                    borderWidth: 1,
                },
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    labels: { color:'#F5F5F5', font:{ family:'Tajawal' } }
                },
                tooltip: {
                    callbacks: {
                        label: ctx => ' ' + Number(ctx.raw).toLocaleString() + ' SDG'
                    }
                }
            },
            scales: {
                x: { ticks:{ color:'#B0B3B8' }, grid:{ color:'#3A3A3A' } },
                y: {
                    ticks: {
                        color: '#C9A84C',
                        callback: v => v.toLocaleString() + ' SDG'
                    },
                    grid: { color:'#3A3A3A' }
                }
            }
        }
    });
})();
</script>
<?php endif; ?>

<style>
.main-content  { padding: 1.5rem; }
.font-monospace{ font-family: monospace; }
.ts-input  { background:var(--ts-bg-input) !important; border-color:var(--ts-border) !important; color:var(--ts-text-primary) !important; }
.ts-input:focus { border-color:var(--ts-gold) !important; box-shadow:0 0 0 .2rem rgba(201,168,76,.25) !important; }

@media print {
    .no-print, nav, .sidebar { display:none !important; }
    .main-content { padding:0 !important; }
    .card { border:1px solid #ccc !important; background:#fff !important; color:#000 !important; break-inside:avoid; }
    .card-header { background:#f5f5f5 !important; color:#000 !important; }
    table thead th { background:#eee !important; color:#000 !important; }
    .progress-bar { background:#999 !important; }
}
</style>

<?php require_once __DIR__ . '/../includes/layout/footer.php'; ?>
