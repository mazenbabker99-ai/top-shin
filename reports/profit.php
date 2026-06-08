<?php
// ============================================================
// المسار: reports/profit.php
// الوظيفة: تقرير الأرباح والخسائر — مبيعات - تكلفة - مصروفات = صافي الربح
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
$filter_date_from = trim($_GET['date_from'] ?? date('Y-m-01'));
$filter_date_to   = trim($_GET['date_to']   ?? date('Y-m-d'));
$filter_branch    = $is_super
    ? sanitize_int($_GET['branch_id'] ?? 0)
    : (int)$user_bid;

// ============================================================
// شرط الفرع
// ============================================================
$branch_where_inv = $is_super && $filter_branch === 0
    ? ''
    : ('AND i.branch_id = ' . ($is_super ? (int)$filter_branch : (int)$user_bid));

$branch_where_exp = $is_super && $filter_branch === 0
    ? ''
    : ('AND e.branch_id = ' . ($is_super ? (int)$filter_branch : (int)$user_bid));

$branch_where_ret = $is_super && $filter_branch === 0
    ? ''
    : ('AND r.branch_id = ' . ($is_super ? (int)$filter_branch : (int)$user_bid));

// ============================================================
// تصدير CSV — أكثر المنتجات ربحاً + ملخص الأرباح
// ============================================================
if (isset($_GET['export']) && $_GET['export'] === 'csv') {

    // إعادة تنفيذ query المنتجات كاملاً للـ CSV (بدون LIMIT)
    $csv_top_stmt = $pdo->prepare("
        SELECT
            ii.product_name,
            SUM(ii.quantity)                                 AS total_qty,
            SUM(ii.total)                                    AS total_revenue,
            SUM(ii.cost_price * ii.quantity)                 AS total_cost_items,
            SUM(ii.total) - SUM(ii.cost_price * ii.quantity) AS gross_profit_item
        FROM invoice_items ii
        JOIN invoices i ON i.id = ii.invoice_id
        WHERE i.status = 'completed'
          $branch_where_inv
          AND DATE(i.created_at) BETWEEN ? AND ?
        GROUP BY ii.product_name
        ORDER BY gross_profit_item DESC
    ");
    $csv_top_stmt->execute([$filter_date_from, $filter_date_to]);
    $csv_products = $csv_top_stmt->fetchAll();

    // إعادة جلب مبيعات/تكلفة للـ summary
    $csv_sales_stmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(i.total), 0)   AS total_sales,
            COALESCE(SUM(
                (SELECT SUM(ii2.cost_price * ii2.quantity)
                 FROM invoice_items ii2
                 WHERE ii2.invoice_id = i.id)
            ), 0)                        AS total_cost,
            COALESCE(SUM(i.discount_amount), 0) AS total_discounts,
            COUNT(i.id)                  AS invoice_count
        FROM invoices i
        WHERE i.status = 'completed'
          $branch_where_inv
          AND DATE(i.created_at) BETWEEN ? AND ?
    ");
    $csv_sales_stmt->execute([$filter_date_from, $filter_date_to]);
    $csv_sales = $csv_sales_stmt->fetch();

    $csv_exp_stmt = $pdo->prepare("
        SELECT COALESCE(SUM(e.amount), 0)
        FROM expenses e
        WHERE DATE(e.expense_date) BETWEEN ? AND ?
        $branch_where_exp
    ");
    $csv_exp_stmt->execute([$filter_date_from, $filter_date_to]);
    $csv_total_exp = (float)$csv_exp_stmt->fetchColumn();

    $csv_ret_stmt = $pdo->prepare("
        SELECT COALESCE(SUM(r.total_refund), 0)
        FROM returns r
        WHERE r.status = 'approved'
          AND DATE(r.created_at) BETWEEN ? AND ?
        $branch_where_ret
    ");
    $csv_ret_stmt->execute([$filter_date_from, $filter_date_to]);
    $csv_total_ret = (float)$csv_ret_stmt->fetchColumn();

    $csv_ts    = (float)($csv_sales['total_sales'] ?? 0);
    $csv_tc    = (float)($csv_sales['total_cost']  ?? 0);
    $csv_gp    = $csv_ts - $csv_tc;
    $csv_np    = $csv_gp - $csv_total_exp - $csv_total_ret;

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="profit_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM for Excel Arabic

    // ——— ملخص الأرباح ———
    fputcsv($out, [$is_rtl ? '=== ملخص الأرباح ===' : '=== Profit Summary ===']);
    fputcsv($out, [
        $is_rtl ? 'البند' : 'Item',
        $is_rtl ? 'القيمة (SDG)' : 'Value (SDG)',
    ]);
    fputcsv($out, [$lang['total_sales'],    number_format($csv_ts,    2)]);
    fputcsv($out, [$lang['cost_of_goods'],  number_format($csv_tc,    2)]);
    fputcsv($out, [$lang['gross_profit'],   number_format($csv_gp,    2)]);
    fputcsv($out, [$lang['total_expenses'], number_format($csv_total_exp, 2)]);
    fputcsv($out, [$lang['total_refunds'],  number_format($csv_total_ret, 2)]);
    fputcsv($out, [$lang['net_profit'],     number_format($csv_np,    2)]);
    fputcsv($out, [
        $is_rtl ? 'عدد الفواتير' : 'Invoice Count',
        (int)($csv_sales['invoice_count'] ?? 0),
    ]);
    fputcsv($out, [
        $is_rtl ? 'الفترة' : 'Period',
        $filter_date_from . ' → ' . $filter_date_to,
    ]);
    fputcsv($out, []); // سطر فارغ

    // ——— تفاصيل المنتجات ———
    fputcsv($out, [$is_rtl ? '=== تفصيل المنتجات ===' : '=== Product Details ===']);
    fputcsv($out, [
        '#',
        $lang['product_name'],
        $lang['quantity'],
        $is_rtl ? 'الإيراد' : 'Revenue',
        $lang['cost_of_goods'],
        $lang['gross_profit'],
        $is_rtl ? 'نسبة الربح %' : 'Margin %',
    ]);
    foreach ($csv_products as $i => $row) {
        $rev   = (float)$row['total_revenue'];
        $cost  = (float)$row['total_cost_items'];
        $gp    = (float)$row['gross_profit_item'];
        $margin = $rev > 0 ? round($gp / $rev * 100, 1) : 0;
        fputcsv($out, [
            $i + 1,
            $row['product_name'],
            number_format((float)$row['total_qty'], 3),
            number_format($rev,  2),
            number_format($cost, 2),
            number_format($gp,   2),
            $margin . '%',
        ]);
    }
    fclose($out);
    exit;
}
$sales_stmt = $pdo->prepare("
    SELECT
        COALESCE(SUM(i.total), 0)                        AS total_sales,
        COALESCE(SUM(i.discount_amount), 0)              AS total_discounts,
        COALESCE(SUM(
            (SELECT SUM(ii.cost_price * ii.quantity)
             FROM invoice_items ii
             WHERE ii.invoice_id = i.id)
        ), 0)                                             AS total_cost,
        COUNT(i.id)                                       AS invoice_count
    FROM invoices i
    WHERE i.status = 'completed'
      $branch_where_inv
      AND DATE(i.created_at) BETWEEN ? AND ?
");
$sales_stmt->execute([$filter_date_from, $filter_date_to]);
$sales_data = $sales_stmt->fetch();

// ============================================================
// إجمالي المصروفات
// ============================================================
$exp_stmt = $pdo->prepare("
    SELECT COALESCE(SUM(e.amount), 0) AS total_expenses
    FROM expenses e
    WHERE DATE(e.expense_date) BETWEEN ? AND ?
    $branch_where_exp
");
$exp_stmt->execute([$filter_date_from, $filter_date_to]);
$total_expenses = (float)$exp_stmt->fetchColumn();

// ============================================================
// إجمالي المرتجعات
// ============================================================
$ret_stmt = $pdo->prepare("
    SELECT COALESCE(SUM(r.total_refund), 0) AS total_returns
    FROM returns r
    WHERE r.status = 'approved'
      AND DATE(r.created_at) BETWEEN ? AND ?
    $branch_where_ret
");
$ret_stmt->execute([$filter_date_from, $filter_date_to]);
$total_returns = (float)$ret_stmt->fetchColumn();

// ============================================================
// الحسابات الأساسية
// ============================================================
$total_sales    = (float)($sales_data['total_sales']    ?? 0);
$total_cost     = (float)($sales_data['total_cost']     ?? 0);
$total_discounts= (float)($sales_data['total_discounts']?? 0);
$invoice_count  = (int)  ($sales_data['invoice_count']  ?? 0);

$gross_profit   = $total_sales - $total_cost;
$gross_margin   = $total_sales > 0 ? round($gross_profit / $total_sales * 100, 2) : 0;
$net_profit     = $gross_profit - $total_expenses - $total_returns;
$net_margin     = $total_sales > 0 ? round($net_profit  / $total_sales * 100, 2) : 0;

// ============================================================
// أكثر 10 منتجات ربحاً
// ============================================================
$top_stmt = $pdo->prepare("
    SELECT
        ii.product_name,
        SUM(ii.quantity)                              AS total_qty,
        SUM(ii.total)                                 AS total_revenue,
        SUM(ii.cost_price * ii.quantity)              AS total_cost_items,
        SUM(ii.total) - SUM(ii.cost_price * ii.quantity) AS gross_profit_item
    FROM invoice_items ii
    JOIN invoices i ON i.id = ii.invoice_id
    WHERE i.status = 'completed'
      $branch_where_inv
      AND DATE(i.created_at) BETWEEN ? AND ?
    GROUP BY ii.product_name
    ORDER BY gross_profit_item DESC
    LIMIT 10
");
$top_stmt->execute([$filter_date_from, $filter_date_to]);
$top_products = $top_stmt->fetchAll();

// ============================================================
// بيانات Chart — مقارنة شهرية / يومية
// ============================================================
$chart_stmt = $pdo->prepare("
    SELECT
        DATE(i.created_at)                                AS day,
        COALESCE(SUM(i.total), 0)                         AS day_sales,
        COALESCE(SUM(
            (SELECT SUM(ii2.cost_price * ii2.quantity)
             FROM invoice_items ii2
             WHERE ii2.invoice_id = i.id)
        ), 0)                                             AS day_cost
    FROM invoices i
    WHERE i.status = 'completed'
      $branch_where_inv
      AND DATE(i.created_at) BETWEEN ? AND ?
    GROUP BY DATE(i.created_at)
    ORDER BY day ASC
");
$chart_stmt->execute([$filter_date_from, $filter_date_to]);
$chart_rows = $chart_stmt->fetchAll();

$chart_labels = array_column($chart_rows, 'day');
$chart_sales  = array_map(fn($r) => (float)$r['day_sales'], $chart_rows);
$chart_cost   = array_map(fn($r) => (float)$r['day_cost'],  $chart_rows);
$chart_profit = array_map(
    fn($r) => round((float)$r['day_sales'] - (float)$r['day_cost'], 2),
    $chart_rows
);

// ============================================================
// قائمة الفروع للـ super_admin
// ============================================================
$branches_list = [];
if ($is_super) {
    $branches_list = $pdo->query(
        "SELECT id, name FROM branches WHERE status='active' ORDER BY name"
    )->fetchAll();
}

$depth       = 1;
$assets_path = '../assets';
?>

<?php require_once __DIR__ . '/../includes/layout/sidebar.php'; ?>

<div class="main-content">

    <?php show_flash(); ?>

    <!-- رأس الصفحة -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <h4 class="mb-0" style="color:var(--ts-gold)">
            <i class="bi bi-graph-up-arrow me-2"></i>
            <?= $lang['profit_report'] ?>
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
                <?php if ($is_super): ?>
                <div class="col-md-3">
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
                <div class="col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-primary btn-sm flex-grow-1">
                        <i class="bi bi-funnel me-1"></i><?= $lang['filter'] ?>
                    </button>
                    <a href="profit.php" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-x"></i>
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- ================================================== -->
    <!-- KPI Cards الرئيسية                                  -->
    <!-- ================================================== -->
    <div class="row g-3 mb-4">

        <!-- إجمالي المبيعات -->
        <div class="col-6 col-md-4 col-lg-2">
            <div class="card text-center h-100">
                <div class="card-body p-2">
                    <div class="small text-muted mb-1"><?= $lang['total_sales'] ?></div>
                    <div class="fw-bold" style="color:var(--ts-success);font-size:.95rem">
                        <?= format_money($total_sales) ?>
                    </div>
                    <small class="text-muted"><?= $invoice_count ?> <?= $is_rtl?'فاتورة':'invoices' ?></small>
                </div>
            </div>
        </div>

        <!-- تكلفة البضاعة -->
        <div class="col-6 col-md-4 col-lg-2">
            <div class="card text-center h-100">
                <div class="card-body p-2">
                    <div class="small text-muted mb-1"><?= $is_rtl?'تكلفة البضاعة':'COGS' ?></div>
                    <div class="fw-bold" style="color:var(--ts-danger);font-size:.95rem">
                        <?= format_money($total_cost) ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- هامش الربح الإجمالي -->
        <div class="col-6 col-md-4 col-lg-2">
            <div class="card text-center h-100"
                 style="border-color:<?= $gross_profit>=0?'var(--ts-success)':'var(--ts-danger)' ?>">
                <div class="card-body p-2">
                    <div class="small text-muted mb-1"><?= $lang['gross_profit'] ?></div>
                    <div class="fw-bold"
                         style="color:<?= $gross_profit>=0?'var(--ts-success)':'var(--ts-danger)' ?>;font-size:.95rem">
                        <?= format_money($gross_profit) ?>
                    </div>
                    <small style="color:var(--ts-silver)"><?= $gross_margin ?>%</small>
                </div>
            </div>
        </div>

        <!-- المصروفات -->
        <div class="col-6 col-md-4 col-lg-2">
            <div class="card text-center h-100">
                <div class="card-body p-2">
                    <div class="small text-muted mb-1"><?= $lang['total_expenses'] ?></div>
                    <div class="fw-bold" style="color:var(--ts-danger);font-size:.95rem">
                        <?= format_money($total_expenses) ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- المرتجعات -->
        <div class="col-6 col-md-4 col-lg-2">
            <div class="card text-center h-100">
                <div class="card-body p-2">
                    <div class="small text-muted mb-1"><?= $lang['returns'] ?></div>
                    <div class="fw-bold" style="color:#ff6b35;font-size:.95rem">
                        <?= format_money($total_returns) ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- صافي الربح — البطاقة الكبيرة -->
        <div class="col-6 col-md-4 col-lg-2">
            <div class="card text-center h-100"
                 style="border:2px solid <?= $net_profit>=0?'var(--ts-success)':'var(--ts-danger)' ?>">
                <div class="card-body p-2">
                    <div class="small text-muted mb-1"><?= $lang['net_profit'] ?></div>
                    <div class="fw-bold"
                         style="color:<?= $net_profit>=0?'var(--ts-success)':'var(--ts-danger)' ?>;font-size:.95rem">
                        <?= format_money($net_profit) ?>
                    </div>
                    <small style="color:var(--ts-silver)"><?= $net_margin ?>%</small>
                </div>
            </div>
        </div>
    </div>

    <!-- ================================================== -->
    <!-- لوحة حساب الربح والخسارة                            -->
    <!-- ================================================== -->
    <div class="row g-4 mb-4">
        <div class="col-lg-5">
            <div class="card h-100">
                <div class="card-header">
                    <i class="bi bi-calculator me-1"></i>
                    <?= $is_rtl ? 'بيان الأرباح والخسائر' : 'Profit & Loss Statement' ?>
                </div>
                <div class="card-body">
                    <table class="table table-sm mb-0" style="color:var(--ts-text-primary)">
                        <tbody>
                            <tr>
                                <td><?= $lang['total_sales'] ?></td>
                                <td class="text-end fw-bold" style="color:var(--ts-success)">
                                    <?= format_money($total_sales) ?>
                                </td>
                            </tr>
                            <tr>
                                <td><?= $is_rtl ? 'الخصومات الممنوحة' : 'Discounts Given' ?></td>
                                <td class="text-end" style="color:var(--ts-danger)">
                                    (<?= format_money($total_discounts) ?>)
                                </td>
                            </tr>
                            <tr>
                                <td><?= $is_rtl ? 'تكلفة البضاعة المباعة' : 'Cost of Goods Sold' ?></td>
                                <td class="text-end" style="color:var(--ts-danger)">
                                    (<?= format_money($total_cost) ?>)
                                </td>
                            </tr>
                            <tr style="border-top:2px solid var(--ts-border-gold)">
                                <td class="fw-bold"><?= $lang['gross_profit'] ?></td>
                                <td class="text-end fw-bold"
                                    style="color:<?= $gross_profit>=0?'var(--ts-success)':'var(--ts-danger)' ?>">
                                    <?= format_money($gross_profit) ?>
                                    <small class="d-block" style="color:var(--ts-silver)">
                                        <?= $gross_margin ?>%
                                    </small>
                                </td>
                            </tr>
                            <tr>
                                <td><?= $lang['total_expenses'] ?></td>
                                <td class="text-end" style="color:var(--ts-danger)">
                                    (<?= format_money($total_expenses) ?>)
                                </td>
                            </tr>
                            <tr>
                                <td><?= $lang['returns'] ?></td>
                                <td class="text-end" style="color:#ff6b35">
                                    (<?= format_money($total_returns) ?>)
                                </td>
                            </tr>
                            <tr style="border-top:2px solid var(--ts-border-gold);background:var(--ts-bg-dark)">
                                <td class="fw-bold fs-6"><?= $lang['net_profit'] ?></td>
                                <td class="text-end fw-bold fs-6"
                                    style="color:<?= $net_profit>=0?'var(--ts-success)':'var(--ts-danger)' ?>">
                                    <?= format_money($net_profit) ?>
                                    <small class="d-block" style="color:var(--ts-silver)">
                                        <?= $net_margin ?>%
                                    </small>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Chart -->
        <div class="col-lg-7 no-print">
            <?php if (!empty($chart_rows)): ?>
            <div class="card h-100">
                <div class="card-header">
                    <i class="bi bi-bar-chart me-1"></i>
                    <?= $is_rtl ? 'المبيعات مقابل التكلفة' : 'Sales vs Cost' ?>
                </div>
                <div class="card-body" style="height:280px">
                    <canvas id="profitChart"></canvas>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ================================================== -->
    <!-- جدول أكثر المنتجات ربحاً                            -->
    <!-- ================================================== -->
    <?php if (!empty($top_products)): ?>
    <div class="card">
        <div class="card-header">
            <i class="bi bi-trophy me-1" style="color:var(--ts-gold)"></i>
            <?= $is_rtl ? 'أكثر 10 منتجات ربحاً' : 'Top 10 Most Profitable Products' ?>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th><?= $is_rtl ? 'المنتج' : 'Product' ?></th>
                            <th class="text-center"><?= $is_rtl ? 'الكمية المباعة' : 'Qty Sold' ?></th>
                            <th class="text-end"><?= $is_rtl ? 'إجمالي الإيراد' : 'Revenue' ?></th>
                            <th class="text-end"><?= $is_rtl ? 'إجمالي التكلفة' : 'Cost' ?></th>
                            <th class="text-end"><?= $lang['gross_profit'] ?></th>
                            <th class="text-center"><?= $is_rtl ? 'هامش الربح' : 'Margin' ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($top_products as $idx => $prod):
                            $margin = (float)$prod['total_revenue'] > 0
                                ? round((float)$prod['gross_profit_item'] / (float)$prod['total_revenue'] * 100, 1)
                                : 0;
                        ?>
                        <tr>
                            <td>
                                <?php
                                $medal = ['🥇','🥈','🥉'][$idx] ?? ($idx+1);
                                echo $medal;
                                ?>
                            </td>
                            <td class="fw-semibold">
                                <?= htmlspecialchars($prod['product_name']) ?>
                            </td>
                            <td class="text-center">
                                <span class="badge"
                                      style="background:var(--ts-bg-dark);color:var(--ts-silver);border:1px solid var(--ts-border)">
                                    <?= number_format((float)$prod['total_qty'], 0) ?>
                                </span>
                            </td>
                            <td class="text-end" style="color:var(--ts-success)">
                                <?= format_money((float)$prod['total_revenue']) ?>
                            </td>
                            <td class="text-end" style="color:var(--ts-danger)">
                                <?= format_money((float)$prod['total_cost_items']) ?>
                            </td>
                            <td class="text-end fw-bold"
                                style="color:<?= (float)$prod['gross_profit_item']>=0?'var(--ts-success)':'var(--ts-danger)' ?>">
                                <?= format_money((float)$prod['gross_profit_item']) ?>
                            </td>
                            <td class="text-center">
                                <div class="d-flex align-items-center gap-1 justify-content-center">
                                    <div class="progress"
                                         style="width:60px;height:6px;background:var(--ts-border)">
                                        <div class="progress-bar"
                                             style="width:<?= max(0,min(100,$margin)) ?>%;background:var(--ts-gold)">
                                        </div>
                                    </div>
                                    <small style="color:var(--ts-gold)"><?= $margin ?>%</small>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div><!-- /.main-content -->

<!-- Chart.js -->
<?php if (!empty($chart_rows)): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
(function(){
    var ctx = document.getElementById('profitChart');
    if (!ctx) return;

    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: <?= json_encode($chart_labels) ?>,
            datasets: [
                {
                    label: '<?= $is_rtl?'المبيعات':'Sales' ?>',
                    data: <?= json_encode($chart_sales) ?>,
                    backgroundColor: 'rgba(40,167,69,0.7)',
                    borderColor: '#28A745',
                    borderWidth: 1,
                    order: 2,
                },
                {
                    label: '<?= $is_rtl?'التكلفة':'Cost' ?>',
                    data: <?= json_encode($chart_cost) ?>,
                    backgroundColor: 'rgba(220,53,69,0.6)',
                    borderColor: '#DC3545',
                    borderWidth: 1,
                    order: 3,
                },
                {
                    label: '<?= $lang['gross_profit'] ?>',
                    data: <?= json_encode($chart_profit) ?>,
                    type: 'line',
                    borderColor: '#C9A84C',
                    backgroundColor: 'rgba(201,168,76,0.15)',
                    borderWidth: 2,
                    pointRadius: 3,
                    fill: true,
                    tension: 0.3,
                    order: 1,
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { labels:{ color:'#F5F5F5', font:{ family:'Tajawal' } } },
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
                        color:'#B0B3B8',
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
.ts-input  { background:var(--ts-bg-input) !important; border-color:var(--ts-border) !important; color:var(--ts-text-primary) !important; }
.ts-select { background:var(--ts-bg-input) !important; border-color:var(--ts-border) !important; color:var(--ts-text-primary) !important; }
.ts-input:focus, .ts-select:focus {
    border-color:var(--ts-gold) !important;
    box-shadow:0 0 0 .2rem rgba(201,168,76,.25) !important;
}

@media print {
    .no-print, nav, .sidebar { display:none !important; }
    .main-content { padding:0 !important; }
    .card { border:1px solid #ccc !important; background:#fff !important; color:#000 !important; break-inside:avoid; }
    .card-header  { background:#f5f5f5 !important; color:#000 !important; }
    table thead th{ background:#eee    !important; color:#000 !important; }
    .progress-bar { background:#666    !important; }
}
</style>

<?php require_once __DIR__ . '/../includes/layout/footer.php'; ?>
