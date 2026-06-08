<?php
// ============================================================
// المسار: admin/dashboard.php
// الوظيفة: لوحة تحكم مدير الفرع — مبيعات اليوم والشهر +
//          تنبيهات المخزون + طلبات التحويل + آخر الفواتير +
//          charts + sparklines + heatmap + مقارنة بالفترة السابقة
// الصلاحية: branch_admin | super_admin
// ============================================================

declare(strict_types=1);

$current_page = 'dashboard';
require_once __DIR__ . '/../includes/layout/header.php';
require_role(['branch_admin', 'super_admin']);

$pdo      = db();
$role     = Auth::getRole();
$user_bid = Auth::getBranchId();
$is_super = ($role === 'super_admin');
$today    = date('Y-m-d');

// super_admin يختار الفرع عبر GET — branch_admin فرعه فقط
$view_bid = $is_super
    ? (sanitize_int($_GET['branch_id'] ?? 0) ?: null)
    : $user_bid;

// chart_mode: weekly | monthly
$chart_mode = in_array($_GET['chart_mode'] ?? '', ['weekly','monthly'], true)
    ? $_GET['chart_mode']
    : 'weekly';

$branch_cond_inv = ($view_bid !== null)
    ? 'AND i.branch_id = ' . (int)$view_bid
    : '';

// ============================================================
// 1. إحصائيات مبيعات اليوم
// ============================================================
$today_stmt = $pdo->prepare("
    SELECT
        COUNT(*)                           AS invoice_count,
        COALESCE(SUM(i.total), 0)          AS total_sales,
        COALESCE(SUM(i.discount_amount),0) AS total_discounts,
        COALESCE(AVG(i.total), 0)          AS avg_invoice
    FROM invoices i
    WHERE i.status = 'completed'
      AND DATE(i.created_at) = ?
      $branch_cond_inv
");
$today_stmt->execute([$today]);
$today_stats = $today_stmt->fetch();

// ============================================================
// 2. مبيعات الشهر الحالي
// ============================================================
$month_stmt = $pdo->prepare("
    SELECT
        COUNT(*)                           AS invoice_count,
        COALESCE(SUM(i.total), 0)          AS total_sales,
        COALESCE(SUM(
            (SELECT SUM(ii.cost_price * ii.quantity)
             FROM invoice_items ii WHERE ii.invoice_id = i.id)
        ), 0)                              AS total_cost
    FROM invoices i
    WHERE i.status = 'completed'
      AND YEAR(i.created_at)  = YEAR(CURDATE())
      AND MONTH(i.created_at) = MONTH(CURDATE())
      $branch_cond_inv
");
$month_stmt->execute([]);
$month_stats  = $month_stmt->fetch();
$month_profit = (float)$month_stats['total_sales'] - (float)$month_stats['total_cost'];

// ============================================================
// 3. مقارنة اليوم بالأمس + الشهر بالشهر السابق
// ============================================================
$yesterday = date('Y-m-d', strtotime('-1 day'));
$yday_stmt  = $pdo->prepare("
    SELECT COALESCE(SUM(i.total),0) AS total_sales, COUNT(*) AS invoice_count
    FROM invoices i
    WHERE i.status='completed' AND DATE(i.created_at)=? $branch_cond_inv
");
$yday_stmt->execute([$yesterday]);
$yday_stats = $yday_stmt->fetch();

$prev_month_stmt = $pdo->prepare("
    SELECT COALESCE(SUM(i.total),0) AS total_sales, COUNT(*) AS invoice_count
    FROM invoices i
    WHERE i.status='completed'
      AND YEAR(i.created_at)  = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))
      AND MONTH(i.created_at) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))
      $branch_cond_inv
");
$prev_month_stmt->execute([]);
$prev_month_stats = $prev_month_stmt->fetch();

// حساب نسب التغيير
function pct_change(float $new, float $old): ?float {
    if ($old <= 0) return null;
    return round((($new - $old) / $old) * 100, 1);
}
$today_vs_yday   = pct_change((float)$today_stats['total_sales'],  (float)$yday_stats['total_sales']);
$month_vs_prev   = pct_change((float)$month_stats['total_sales'],  (float)$prev_month_stats['total_sales']);
$inv_vs_yday     = pct_change((float)$today_stats['invoice_count'],(float)$yday_stats['invoice_count']);

// ============================================================
// 4. بيانات الـ Sparkline (آخر 7 أيام لكل KPI)
// ============================================================
$sparkline_sales   = [];
$sparkline_invoices = [];
for ($d = 6; $d >= 0; $d--) {
    $dt   = date('Y-m-d', strtotime("-$d days"));
    $s    = $pdo->prepare("
        SELECT COALESCE(SUM(total),0) AS s, COUNT(*) AS c
        FROM invoices WHERE status='completed' AND DATE(created_at)=? $branch_cond_inv
    ");
    $s->execute([$dt]);
    $row = $s->fetch();
    $sparkline_sales[]    = (float)$row['s'];
    $sparkline_invoices[] = (int)$row['c'];
}

// ============================================================
// 5. بيانات الـ Chart الرئيسي (أسبوعي أو شهري)
// ============================================================
$chart_labels = [];
$chart_sales  = [];
$chart_prev   = [];

if ($chart_mode === 'weekly') {
    // آخر 8 أسابيع
    for ($w = 7; $w >= 0; $w--) {
        $wstart = date('Y-m-d', strtotime("monday -$w weeks"));
        $wend   = date('Y-m-d', strtotime("sunday -$w weeks"));
        $label  = date('d/m', strtotime($wstart));
        $stmt   = $pdo->prepare("
            SELECT COALESCE(SUM(total),0) AS s
            FROM invoices WHERE status='completed'
              AND DATE(created_at) BETWEEN ? AND ? $branch_cond_inv
        ");
        $stmt->execute([$wstart, $wend]);
        $chart_labels[] = $label;
        $chart_sales[]  = (float)$stmt->fetchColumn();
    }
    // الفترة السابقة (8 أسابيع قبلها)
    for ($w = 15; $w >= 8; $w--) {
        $wstart = date('Y-m-d', strtotime("monday -$w weeks"));
        $wend   = date('Y-m-d', strtotime("sunday -$w weeks"));
        $stmt2  = $pdo->prepare("
            SELECT COALESCE(SUM(total),0) AS s
            FROM invoices WHERE status='completed'
              AND DATE(created_at) BETWEEN ? AND ? $branch_cond_inv
        ");
        $stmt2->execute([$wstart, $wend]);
        $chart_prev[] = (float)$stmt2->fetchColumn();
    }
} else {
    // آخر 12 شهر
    for ($m = 11; $m >= 0; $m--) {
        $y   = (int)date('Y', strtotime("-$m months"));
        $mo  = (int)date('m', strtotime("-$m months"));
        $lbl = date('M Y', strtotime("-$m months"));
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(total),0) AS s
            FROM invoices WHERE status='completed'
              AND YEAR(created_at)=? AND MONTH(created_at)=? $branch_cond_inv
        ");
        $stmt->execute([$y, $mo]);
        $chart_labels[] = $lbl;
        $chart_sales[]  = (float)$stmt->fetchColumn();
        // السنة السابقة
        $py  = $y - 1;
        $stmt3 = $pdo->prepare("
            SELECT COALESCE(SUM(total),0) AS s
            FROM invoices WHERE status='completed'
              AND YEAR(created_at)=? AND MONTH(created_at)=? $branch_cond_inv
        ");
        $stmt3->execute([$py, $mo]);
        $chart_prev[] = (float)$stmt3->fetchColumn();
    }
}

// ============================================================
// 6. بيانات الـ Heatmap (آخر 4 أسابيع × 24 ساعة مجمعة)
// ============================================================
// نجمع حسب يوم الأسبوع (0=أحد..6=سبت) وساعة اليوم
$heatmap_raw = $pdo->prepare("
    SELECT
        DAYOFWEEK(created_at)-1 AS dow,
        HOUR(created_at)        AS hr,
        COUNT(*)                AS cnt,
        COALESCE(SUM(total),0)  AS rev
    FROM invoices
    WHERE status='completed'
      AND created_at >= DATE_SUB(NOW(), INTERVAL 4 WEEK)
      $branch_cond_inv
    GROUP BY dow, hr
");
$heatmap_raw->execute([]);
$heatmap_data = array_fill(0, 7, array_fill(0, 24, 0));
$hmap_max = 0;
foreach ($heatmap_raw->fetchAll() as $row) {
    $val = (int)$row['cnt'];
    $heatmap_data[(int)$row['dow']][(int)$row['hr']] = $val;
    if ($val > $hmap_max) $hmap_max = $val;
}

// ============================================================
// 7. تنبيهات المخزون الناقص
// ============================================================
if ($view_bid !== null) {
    $low_stmt = $pdo->prepare("
        SELECT
            p.id, p.name_ar, p.name_en, p.unit,
            i.quantity, i.min_quantity,
            CASE
                WHEN i.quantity <= 0              THEN 'out'
                WHEN i.quantity <= i.min_quantity THEN 'low'
                ELSE 'ok'
            END AS stock_status
        FROM inventory i
        JOIN products p ON p.id = i.product_id
        WHERE i.branch_id = ?
          AND p.status    = 'active'
          AND i.quantity  <= i.min_quantity
        ORDER BY i.quantity ASC
        LIMIT 10
    ");
    $low_stmt->execute([$view_bid]);
} else {
    $low_stmt = $pdo->prepare("
        SELECT
            p.id, p.name_ar, p.name_en, p.unit,
            i.quantity, i.min_quantity,
            b.name AS branch_name,
            CASE
                WHEN i.quantity <= 0              THEN 'out'
                WHEN i.quantity <= i.min_quantity THEN 'low'
                ELSE 'ok'
            END AS stock_status
        FROM inventory i
        JOIN products p ON p.id = i.product_id
        LEFT JOIN branches b ON b.id = i.branch_id
        WHERE p.status   = 'active'
          AND i.quantity <= i.min_quantity
        ORDER BY i.quantity ASC
        LIMIT 10
    ");
    $low_stmt->execute([]);
}
$low_stock     = $low_stmt->fetchAll();
$out_of_stock  = array_filter($low_stock, fn($r) => (float)$r['quantity'] <= 0);
$low_only      = array_filter($low_stock, fn($r) => (float)$r['quantity'] > 0);

// ============================================================
// 8. طلبات التحويل المعلقة
// ============================================================
if ($view_bid !== null) {
    $trans_stmt = $pdo->prepare("
        SELECT COUNT(*) FROM stock_transfers
        WHERE status = 'pending'
          AND (from_branch_id = ? OR to_branch_id = ?)
    ");
    $trans_stmt->execute([$view_bid, $view_bid]);
} else {
    $trans_stmt = $pdo->query("SELECT COUNT(*) FROM stock_transfers WHERE status='pending'");
}
$pending_transfers = (int)$trans_stmt->fetchColumn();

// ============================================================
// 9. أوامر الشراء المؤكدة في انتظار الاستلام
// ============================================================
$po_cond   = ($view_bid !== null) ? 'AND po.branch_id = ' . (int)$view_bid : '';
$pending_pos = (int)$pdo->query("
    SELECT COUNT(*) FROM purchase_orders po
    WHERE po.status IN ('confirmed','partial') $po_cond
")->fetchColumn();

// ============================================================
// 10. آخر 8 فواتير
// ============================================================
$inv_stmt = $pdo->prepare("
    SELECT
        i.id, i.invoice_number, i.total,
        i.payment_method, i.status, i.created_at,
        u.name AS cashier_name,
        COALESCE(c.name,'') AS customer_name,
        COUNT(ii.id) AS item_count
    FROM invoices i
    LEFT JOIN users         u  ON u.id  = i.cashier_id
    LEFT JOIN customers     c  ON c.id  = i.customer_id
    LEFT JOIN invoice_items ii ON ii.invoice_id = i.id
    WHERE i.status IN ('completed','refunded')
      $branch_cond_inv
    GROUP BY i.id
    ORDER BY i.created_at DESC
    LIMIT 8
");
$inv_stmt->execute([]);
$recent_invoices = $inv_stmt->fetchAll();

// ============================================================
// 11. أكثر 5 منتجات مبيعاً
// ============================================================
$top_stmt = $pdo->prepare("
    SELECT
        ii.product_name,
        SUM(ii.quantity) AS total_qty,
        SUM(ii.total)    AS total_revenue
    FROM invoice_items ii
    JOIN invoices i ON i.id = ii.invoice_id
    WHERE i.status = 'completed'
      AND YEAR(i.created_at)  = YEAR(CURDATE())
      AND MONTH(i.created_at) = MONTH(CURDATE())
      $branch_cond_inv
    GROUP BY ii.product_name
    ORDER BY total_qty DESC
    LIMIT 5
");
$top_stmt->execute([]);
$top_products = $top_stmt->fetchAll();

// ============================================================
// 12. قائمة الفروع للـ super_admin
// ============================================================
$branches_list = [];
if ($is_super) {
    $branches_list = $pdo->query(
        "SELECT id, name FROM branches WHERE status='active' ORDER BY name"
    )->fetchAll();
}
$branch_name_display = '';
if ($view_bid !== null) {
    $bn = $pdo->prepare("SELECT name FROM branches WHERE id=? LIMIT 1");
    $bn->execute([$view_bid]);
    $branch_name_display = $bn->fetchColumn() ?: '';
}

$page_title = $lang['dashboard'];

// ============================================================
// helper: نسبة التغيير HTML badge
// ============================================================
function pct_badge(?float $pct, bool $is_rtl): string {
    if ($pct === null) return '';
    $color = $pct >= 0 ? '#28A745' : '#DC3545';
    $icon  = $pct >= 0 ? '▲' : '▼';
    $label = $is_rtl ? ($pct >= 0 ? 'عن أمس' : 'عن أمس') : ($pct >= 0 ? 'vs prev' : 'vs prev');
    return "<span style=\"font-size:.72rem;color:$color;font-weight:600;\">$icon " . abs($pct) . "% $label</span>";
}
?>

<?php include __DIR__ . '/../includes/layout/sidebar.php'; ?>

<div class="main-content">

    <?php show_flash(); ?>

    <!-- ============================================================
         شريط تنبيهات المخزون (بنر علوي)
         ============================================================ -->
    <?php if (!empty($low_stock)): ?>
    <div id="stock-alert-banner" class="d-flex align-items-center gap-3 px-4 py-2 mb-3 no-print"
         style="background:linear-gradient(90deg,rgba(220,53,69,.18),rgba(220,53,69,.06));
                border:1px solid rgba(220,53,69,.35);border-radius:10px;flex-wrap:wrap;position:relative;">
        <div class="d-flex align-items-center gap-2" style="flex-shrink:0">
            <i class="bi bi-exclamation-triangle-fill" style="color:var(--ts-danger);font-size:1.1rem;animation:pulse-alert 1.4s infinite"></i>
            <strong style="color:var(--ts-danger);font-size:.88rem;">
                <?= $is_rtl ? 'تنبيه مخزون' : 'Stock Alert' ?>
            </strong>
        </div>
        <div class="d-flex gap-2 flex-wrap align-items-center" style="flex:1;min-width:0">
            <?php foreach (array_slice($low_stock, 0, 6) as $item):
                $is_out = ((float)$item['quantity'] <= 0);
                $bg     = $is_out ? 'rgba(220,53,69,.25)' : 'rgba(201,168,76,.15)';
                $clr    = $is_out ? '#DC3545' : '#C9A84C';
                $p_name = ($lang_code === 'en' && $item['name_en']) ? $item['name_en'] : $item['name_ar'];
            ?>
            <span style="background:<?=$bg?>;border:1px solid <?=$clr?>44;color:<?=$clr?>;
                         font-size:.75rem;padding:.18rem .55rem;border-radius:20px;white-space:nowrap;">
                <?= $is_out ? '🔴' : '🟡' ?>
                <?= htmlspecialchars($p_name) ?>
                — <?= rtrim(rtrim(number_format((float)$item['quantity'],3),'0'),'.') ?>
                <?= htmlspecialchars($item['unit'] ?? '') ?>
            </span>
            <?php endforeach; ?>
            <?php if (count($low_stock) > 6): ?>
            <a href="../admin/inventory_alerts.php"
               style="font-size:.75rem;color:var(--ts-silver);text-decoration:none;">
                +<?= count($low_stock)-6 ?> <?= $is_rtl ? 'أخرى' : 'more' ?>
            </a>
            <?php endif; ?>
        </div>
        <a href="../admin/inventory_alerts.php"
           class="btn btn-sm"
           style="background:rgba(220,53,69,.2);border:1px solid rgba(220,53,69,.4);
                  color:var(--ts-danger);font-size:.78rem;white-space:nowrap;flex-shrink:0">
            <i class="bi bi-box-arrow-up-right me-1"></i>
            <?= $is_rtl ? 'إدارة المخزون' : 'Manage Stock' ?>
        </a>
        <button onclick="document.getElementById('stock-alert-banner').style.display='none'"
                style="position:absolute;top:6px;<?=$is_rtl?'left':'right'?>:10px;
                       background:none;border:none;color:var(--ts-text-muted);cursor:pointer;font-size:1rem;line-height:1"
                title="<?=$is_rtl?'إغلاق':'Close'?>">×</button>
    </div>
    <?php endif; ?>

    <!-- رأس الصفحة -->
    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
        <div>
            <h4 class="mb-0" style="color:var(--ts-gold); font-weight:700;">
                <i class="bi bi-speedometer2 me-2"></i>
                <?= $lang['dashboard'] ?>
                <?php if ($branch_name_display): ?>
                    <span class="badge ms-2"
                          style="background:var(--ts-bg-dark);color:var(--ts-gold);
                                 border:1px solid var(--ts-border-gold);font-size:.7rem;font-weight:500;">
                        <?= htmlspecialchars($branch_name_display) ?>
                    </span>
                <?php endif; ?>
            </h4>
            <small style="color:var(--ts-text-muted);">
                <?= $is_rtl ? 'اليوم — ' : 'Today — ' ?><?= $today ?>
            </small>
        </div>

        <div class="d-flex gap-2 flex-wrap align-items-center">
            <?php if ($is_super && count($branches_list) > 0): ?>
            <form method="GET" class="d-flex align-items-center gap-2">
                <input type="hidden" name="chart_mode" value="<?= htmlspecialchars($chart_mode) ?>">
                <select name="branch_id" class="form-select form-select-sm"
                        style="width:180px;background:var(--ts-bg-input);border-color:var(--ts-border);color:var(--ts-text-primary);"
                        onchange="this.form.submit()">
                    <option value=""><?= $is_rtl ? 'كل الفروع' : 'All Branches' ?></option>
                    <?php foreach ($branches_list as $br): ?>
                    <option value="<?= $br['id'] ?>" <?= $view_bid == $br['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($br['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </form>
            <?php endif; ?>
            <a href="../reports/sales.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-graph-up me-1"></i><?= $lang['reports'] ?>
            </a>
            <a href="../cashier/pos.php" class="btn btn-primary btn-sm">
                <i class="bi bi-cart3 me-1"></i><?= $lang['pos'] ?>
            </a>
        </div>
    </div>

    <!-- ============================================================
         KPI Cards مع Sparklines
         ============================================================ -->
    <div class="row g-3 mb-4">

        <!-- مبيعات اليوم -->
        <div class="col-6 col-md-3">
            <div class="ts-kpi-card h-100" style="position:relative;overflow:hidden;">
                <div class="d-flex justify-content-between align-items-start">
                    <div style="z-index:1;position:relative">
                        <div class="kpi-label"><?= $lang['total_sales'] ?></div>
                        <div class="kpi-value"><?= number_format((float)$today_stats['total_sales'], 0) ?></div>
                        <small style="color:var(--ts-text-muted)">SDG — <?= $is_rtl ? 'اليوم' : 'Today' ?></small>
                        <div class="mt-1"><?= pct_badge($today_vs_yday, $is_rtl) ?></div>
                    </div>
                    <i class="bi bi-cash-stack kpi-icon"></i>
                </div>
                <div class="mt-2" style="height:36px;">
                    <canvas id="spark-sales" height="36"></canvas>
                </div>
            </div>
        </div>

        <!-- عدد الفواتير اليوم -->
        <div class="col-6 col-md-3">
            <div class="ts-kpi-card h-100" style="position:relative;overflow:hidden;">
                <div class="d-flex justify-content-between align-items-start">
                    <div style="z-index:1;position:relative">
                        <div class="kpi-label"><?= $lang['total_invoices'] ?></div>
                        <div class="kpi-value"><?= (int)$today_stats['invoice_count'] ?></div>
                        <small style="color:var(--ts-text-muted)"><?= $is_rtl ? 'فاتورة اليوم' : "Today's invoices" ?></small>
                        <div class="mt-1"><?= pct_badge($inv_vs_yday, $is_rtl) ?></div>
                    </div>
                    <i class="bi bi-file-earmark-check kpi-icon"></i>
                </div>
                <div class="mt-2" style="height:36px;">
                    <canvas id="spark-inv" height="36"></canvas>
                </div>
            </div>
        </div>

        <!-- مبيعات الشهر -->
        <div class="col-6 col-md-3">
            <div class="ts-kpi-card h-100" style="position:relative;overflow:hidden;">
                <div class="d-flex justify-content-between align-items-start">
                    <div style="z-index:1;position:relative">
                        <div class="kpi-label"><?= $is_rtl ? 'مبيعات الشهر' : 'Month Sales' ?></div>
                        <div class="kpi-value"><?= number_format((float)$month_stats['total_sales'], 0) ?></div>
                        <small style="color:var(--ts-text-muted)">SDG — <?= date('F Y') ?></small>
                        <div class="mt-1"><?= pct_badge($month_vs_prev, $is_rtl) ?></div>
                    </div>
                    <i class="bi bi-calendar2-check kpi-icon"></i>
                </div>
                <div class="mt-2" style="height:36px;">
                    <canvas id="spark-month" height="36"></canvas>
                </div>
            </div>
        </div>

        <!-- الربح الإجمالي -->
        <div class="col-6 col-md-3">
            <div class="ts-kpi-card h-100"
                 style="border-top-color:<?= $month_profit >= 0 ? 'var(--ts-success)' : 'var(--ts-danger)' ?>;position:relative;overflow:hidden;">
                <div class="d-flex justify-content-between align-items-start">
                    <div style="z-index:1;position:relative">
                        <div class="kpi-label"><?= $lang['gross_profit'] ?></div>
                        <div class="kpi-value"
                             style="color:<?= $month_profit >= 0 ? 'var(--ts-success)' : 'var(--ts-danger)' ?>">
                            <?= number_format($month_profit, 0) ?>
                        </div>
                        <small style="color:var(--ts-text-muted)">SDG — <?= $is_rtl ? 'الشهر' : 'Month' ?></small>
                        <?php
                        $profit_pct = ($month_stats['total_sales'] > 0)
                            ? round($month_profit / (float)$month_stats['total_sales'] * 100, 1)
                            : 0;
                        ?>
                        <div class="mt-1">
                            <span style="font-size:.72rem;color:<?=$month_profit>=0?'#28A745':'#DC3545'?>;font-weight:600;">
                                <?= $is_rtl ? 'هامش' : 'Margin' ?>: <?= $profit_pct ?>%
                            </span>
                        </div>
                    </div>
                    <i class="bi bi-graph-up-arrow kpi-icon"></i>
                </div>
                <div class="mt-2" style="height:36px;">
                    <canvas id="spark-profit" height="36"></canvas>
                </div>
            </div>
        </div>

    </div>

    <!-- ============================================================
         مخطط المبيعات الرئيسي
         ============================================================ -->
    <div class="card mb-4">
        <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
            <span>
                <i class="bi bi-bar-chart-line me-2" style="color:var(--ts-gold)"></i>
                <?= $is_rtl ? 'مخطط المبيعات مقارنةً بالفترة السابقة' : 'Sales vs Previous Period' ?>
            </span>
            <div class="d-flex gap-2">
                <a href="?<?= http_build_query(array_merge($_GET, ['chart_mode'=>'weekly'])) ?>"
                   class="btn btn-sm <?= $chart_mode==='weekly' ? 'btn-primary' : 'btn-outline-secondary' ?>">
                   <i class="bi bi-calendar-week me-1"></i><?= $is_rtl ? 'أسبوعي' : 'Weekly' ?>
                </a>
                <a href="?<?= http_build_query(array_merge($_GET, ['chart_mode'=>'monthly'])) ?>"
                   class="btn btn-sm <?= $chart_mode==='monthly' ? 'btn-primary' : 'btn-outline-secondary' ?>">
                   <i class="bi bi-calendar3 me-1"></i><?= $is_rtl ? 'شهري' : 'Monthly' ?>
                </a>
            </div>
        </div>
        <div class="card-body" style="padding:1.25rem;">
            <div style="position:relative;height:240px;">
                <canvas id="mainSalesChart"></canvas>
            </div>
            <!-- ملخص المقارنة -->
            <?php
            $cur_total  = array_sum($chart_sales);
            $prev_total = array_sum($chart_prev);
            $diff_pct   = pct_change($cur_total, $prev_total);
            ?>
            <div class="d-flex gap-4 mt-3 flex-wrap" style="font-size:.82rem;">
                <div class="d-flex align-items-center gap-2">
                    <span style="width:28px;height:3px;background:#C9A84C;border-radius:2px;display:inline-block"></span>
                    <span style="color:var(--ts-text-muted)"><?= $is_rtl ? 'الفترة الحالية' : 'Current' ?>:</span>
                    <strong style="color:var(--ts-gold)"><?= number_format($cur_total, 0) ?> SDG</strong>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <span style="width:28px;height:3px;background:#6C757D;border-radius:2px;display:inline-block"></span>
                    <span style="color:var(--ts-text-muted)"><?= $is_rtl ? 'الفترة السابقة' : 'Previous' ?>:</span>
                    <strong style="color:var(--ts-silver)"><?= number_format($prev_total, 0) ?> SDG</strong>
                </div>
                <?php if ($diff_pct !== null): ?>
                <div class="d-flex align-items-center gap-1">
                    <?php $up = $diff_pct >= 0; ?>
                    <i class="bi bi-<?= $up ? 'arrow-up' : 'arrow-down' ?>-circle-fill"
                       style="color:<?= $up ? 'var(--ts-success)' : 'var(--ts-danger)' ?>"></i>
                    <strong style="color:<?= $up ? 'var(--ts-success)' : 'var(--ts-danger)' ?>">
                        <?= abs($diff_pct) ?>%
                    </strong>
                    <span style="color:var(--ts-text-muted)"><?= $is_rtl ? 'تغيير' : 'change' ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ============================================================
         الجدولان: آخر الفواتير + أكثر المنتجات + الإجراءات
         ============================================================ -->
    <div class="row g-4 mb-4">

        <!-- آخر 8 فواتير -->
        <div class="col-12 col-xl-7">
            <div class="card h-100">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <span>
                        <i class="bi bi-receipt me-2"></i>
                        <?= $lang['invoices'] ?>
                    </span>
                    <a href="../reports/sales.php" class="btn btn-sm btn-outline-secondary">
                        <?= $lang['view'] ?> <?= $lang['all'] ?? 'الكل' ?>
                    </a>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($recent_invoices)): ?>
                        <div class="text-center py-5" style="color:var(--ts-text-muted)">
                            <i class="bi bi-inbox" style="font-size:2.5rem;display:block;margin-bottom:.75rem;opacity:.4"></i>
                            <?= $lang['no_results'] ?>
                        </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th><?= $lang['invoice_number'] ?></th>
                                    <th><?= $lang['cashier'] ?></th>
                                    <th><?= $lang['total'] ?></th>
                                    <th><?= $lang['payment_method'] ?></th>
                                    <th><?= $lang['status'] ?></th>
                                    <th><?= $lang['time'] ?></th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($recent_invoices as $inv):
                                $st_color = match($inv['status']) {
                                    'completed' => 'var(--ts-success)',
                                    'refunded'  => 'var(--ts-warning)',
                                    'cancelled' => 'var(--ts-danger)',
                                    default     => 'var(--ts-silver)',
                                };
                                $pm_icons = [
                                    'cash'=>'bi-cash','bankak'=>'bi-phone',
                                    'ocash'=>'bi-wallet2','card'=>'bi-credit-card','other'=>'bi-three-dots',
                                ];
                                $pm_icon = $pm_icons[$inv['payment_method']] ?? 'bi-cash';
                            ?>
                            <tr>
                                <td>
                                    <code style="color:var(--ts-gold);font-size:.8rem">
                                        <?= htmlspecialchars($inv['invoice_number']) ?>
                                    </code>
                                </td>
                                <td><small><?= htmlspecialchars($inv['cashier_name'] ?? '—') ?></small></td>
                                <td style="font-weight:600;color:var(--ts-gold)">
                                    <?= number_format((float)$inv['total'], 2) ?>
                                    <small style="color:var(--ts-text-muted);font-weight:400">SDG</small>
                                </td>
                                <td>
                                    <i class="bi <?= $pm_icon ?> me-1" style="color:var(--ts-silver)"></i>
                                    <small><?= $lang[$inv['payment_method']] ?? $inv['payment_method'] ?></small>
                                </td>
                                <td>
                                    <span class="badge rounded-pill"
                                          style="background:<?= $st_color ?>22;color:<?= $st_color ?>;border:1px solid <?= $st_color ?>44">
                                        <?= $lang[$inv['status']] ?? $inv['status'] ?>
                                    </span>
                                </td>
                                <td style="color:var(--ts-text-muted);font-size:.82rem">
                                    <?= date('H:i', strtotime($inv['created_at'])) ?>
                                </td>
                                <td>
                                    <a href="../cashier/invoice_print.php?id=<?= $inv['id'] ?>"
                                       class="btn btn-sm"
                                       style="background:transparent;border:1px solid var(--ts-border);color:var(--ts-silver);padding:.2rem .5rem"
                                       target="_blank" title="<?= $lang['print'] ?>">
                                        <i class="bi bi-printer"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- العمود الأيمن: المنتجات + الإجراءات -->
        <div class="col-12 col-xl-5">
            <div class="row g-3 h-100">

                <!-- أكثر 5 منتجات -->
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <i class="bi bi-trophy me-2" style="color:var(--ts-gold)"></i>
                            <?= $is_rtl ? 'أكثر المنتجات مبيعاً — الشهر' : 'Top Products — Month' ?>
                        </div>
                        <div class="card-body p-0">
                            <?php if (empty($top_products)): ?>
                                <div class="text-center py-4" style="color:var(--ts-text-muted)">
                                    <i class="bi bi-box-seam d-block fs-3 mb-2 opacity-50"></i>
                                    <?= $lang['no_results'] ?>
                                </div>
                            <?php else: ?>
                            <ul class="list-group list-group-flush">
                                <?php foreach ($top_products as $idx => $prod):
                                    $medals = ['🥇','🥈','🥉'];
                                    $medal  = $medals[$idx] ?? ($idx + 1);
                                    $max_qty = (float)($top_products[0]['total_qty'] ?: 1);
                                    $pct     = round((float)$prod['total_qty'] / $max_qty * 100);
                                ?>
                                <li class="list-group-item"
                                    style="background:transparent;border-color:var(--ts-border);padding:.6rem 1rem">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <span style="font-size:.87rem;color:var(--ts-text-primary)">
                                            <?= $medal ?> <?= htmlspecialchars($prod['product_name']) ?>
                                        </span>
                                        <span style="font-size:.82rem;color:var(--ts-gold);font-weight:600">
                                            <?= number_format((float)$prod['total_qty'], 0) ?>
                                            <small style="color:var(--ts-text-muted);font-weight:400">
                                                <?= $is_rtl ? 'وحدة' : 'units' ?>
                                            </small>
                                        </span>
                                    </div>
                                    <div class="progress" style="height:4px;background:var(--ts-border)">
                                        <div class="progress-bar"
                                             style="width:<?= $pct ?>%;background:var(--ts-gold)"></div>
                                    </div>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- إجراءات سريعة -->
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <i class="bi bi-lightning me-2" style="color:var(--ts-gold)"></i>
                            <?= $is_rtl ? 'إجراءات سريعة' : 'Quick Actions' ?>
                        </div>
                        <div class="card-body">
                            <div class="row g-2">
                                <div class="col-4">
                                    <a href="../cashier/pos.php" class="btn btn-primary w-100 d-flex flex-column align-items-center py-2 gap-1">
                                        <i class="bi bi-cart3 fs-5"></i>
                                        <small style="font-size:.72rem"><?= $lang['pos'] ?></small>
                                    </a>
                                </div>
                                <div class="col-4">
                                    <a href="../admin/products.php" class="btn btn-outline-secondary w-100 d-flex flex-column align-items-center py-2 gap-1">
                                        <i class="bi bi-box-seam fs-5"></i>
                                        <small style="font-size:.72rem"><?= $lang['products'] ?></small>
                                    </a>
                                </div>
                                <div class="col-4">
                                    <a href="../admin/inventory.php" class="btn btn-outline-secondary w-100 d-flex flex-column align-items-center py-2 gap-1">
                                        <i class="bi bi-archive fs-5"></i>
                                        <small style="font-size:.72rem"><?= $lang['inventory'] ?></small>
                                    </a>
                                </div>
                                <div class="col-4">
                                    <a href="../admin/purchase_orders.php" class="btn btn-outline-secondary w-100 d-flex flex-column align-items-center py-2 gap-1"
                                       style="<?= $pending_pos > 0 ? 'border-color:var(--ts-gold);color:var(--ts-gold)' : '' ?>">
                                        <i class="bi bi-receipt fs-5"></i>
                                        <small style="font-size:.72rem">
                                            <?= $lang['purchase_orders'] ?>
                                            <?php if ($pending_pos > 0): ?>
                                            <span class="badge rounded-pill" style="background:var(--ts-gold);color:#000;font-size:.65rem">
                                                <?= $pending_pos ?>
                                            </span>
                                            <?php endif; ?>
                                        </small>
                                    </a>
                                </div>
                                <div class="col-4">
                                    <a href="../admin/stock_transfers.php" class="btn btn-outline-secondary w-100 d-flex flex-column align-items-center py-2 gap-1"
                                       style="<?= $pending_transfers > 0 ? 'border-color:var(--ts-warning);color:var(--ts-warning)' : '' ?>">
                                        <i class="bi bi-arrow-left-right fs-5"></i>
                                        <small style="font-size:.72rem">
                                            <?= $is_rtl ? 'تحويل' : 'Transfer' ?>
                                            <?php if ($pending_transfers > 0): ?>
                                            <span class="badge rounded-pill" style="background:var(--ts-warning);color:#000;font-size:.65rem">
                                                <?= $pending_transfers ?>
                                            </span>
                                            <?php endif; ?>
                                        </small>
                                    </a>
                                </div>
                                <div class="col-4">
                                    <a href="../admin/returns.php" class="btn btn-outline-secondary w-100 d-flex flex-column align-items-center py-2 gap-1">
                                        <i class="bi bi-arrow-return-left fs-5"></i>
                                        <small style="font-size:.72rem"><?= $lang['returns'] ?></small>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>

    </div>

    <!-- ============================================================
         خريطة الحرارة — أوقات الذروة
         ============================================================ -->
    <div class="card mb-4">
        <div class="card-header d-flex align-items-center gap-2">
            <i class="bi bi-fire" style="color:#ff6b35"></i>
            <span><?= $is_rtl ? 'خريطة حرارة المبيعات — أوقات الذروة (آخر 4 أسابيع)' : 'Sales Heatmap — Peak Hours (Last 4 Weeks)' ?></span>
        </div>
        <div class="card-body" style="overflow-x:auto;padding:1rem 1.25rem">
            <?php
            $days_ar = ['أحد','اثنين','ثلاثاء','أربعاء','خميس','جمعة','سبت'];
            $days_en = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
            $days_labels = $is_rtl ? $days_ar : $days_en;
            $peak_hours = [8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23]; // ساعات العمل المعتادة
            ?>
            <div style="display:grid;grid-template-columns:52px repeat(<?= count($peak_hours) ?>,1fr);gap:3px;min-width:520px;">
                <!-- رأس الأعمدة: الساعات -->
                <div></div>
                <?php foreach ($peak_hours as $hr): ?>
                <div style="text-align:center;font-size:.68rem;color:var(--ts-text-muted);padding-bottom:3px">
                    <?= $hr ?>:00
                </div>
                <?php endforeach; ?>

                <!-- صفوف الأيام -->
                <?php foreach ($days_labels as $di => $dname): ?>
                <div style="font-size:.75rem;color:var(--ts-text-muted);display:flex;align-items:center;justify-content:flex-end;padding-right:6px;white-space:nowrap">
                    <?= $dname ?>
                </div>
                <?php foreach ($peak_hours as $hr):
                    $val = $heatmap_data[$di][$hr] ?? 0;
                    $intensity = ($hmap_max > 0) ? ($val / $hmap_max) : 0;
                    // لون من شفاف للذهبي للبرتقالي للأحمر
                    if ($intensity <= 0) {
                        $bg = 'rgba(255,255,255,0.04)';
                    } elseif ($intensity < 0.33) {
                        $alpha = round($intensity * 3, 2);
                        $bg = "rgba(201,168,76,$alpha)";
                    } elseif ($intensity < 0.66) {
                        $alpha = round(0.4 + ($intensity - 0.33) * 1.2, 2);
                        $bg = "rgba(220,120,30,$alpha)";
                    } else {
                        $alpha = round(0.6 + ($intensity - 0.66) * 1.2, 2);
                        $bg = "rgba(220,53,69,$alpha)";
                    }
                    $title = "$dname {$hr}:00 — $val " . ($is_rtl ? 'فاتورة' : 'invoices');
                ?>
                <div title="<?= htmlspecialchars($title) ?>"
                     style="background:<?= $bg ?>;border-radius:4px;height:26px;cursor:default;
                            transition:transform .15s;border:1px solid rgba(255,255,255,.04)"
                     onmouseenter="this.style.transform='scale(1.25)';this.style.zIndex='2'"
                     onmouseleave="this.style.transform='scale(1)';this.style.zIndex='1'">
                </div>
                <?php endforeach; ?>
                <?php endforeach; ?>
            </div>
            <!-- مفتاح الألوان -->
            <div class="d-flex align-items-center gap-2 mt-3" style="font-size:.72rem;color:var(--ts-text-muted)">
                <span><?= $is_rtl ? 'منخفض' : 'Low' ?></span>
                <div style="display:flex;gap:2px">
                    <?php
                    $grad_steps = [
                        'rgba(255,255,255,.04)',
                        'rgba(201,168,76,.3)',
                        'rgba(201,168,76,.7)',
                        'rgba(220,120,30,.7)',
                        'rgba(220,53,69,.8)',
                        'rgba(220,53,69,1)',
                    ];
                    foreach ($grad_steps as $gc): ?>
                    <div style="width:22px;height:12px;background:<?= $gc ?>;border-radius:2px"></div>
                    <?php endforeach; ?>
                </div>
                <span><?= $is_rtl ? 'ذروة' : 'Peak' ?></span>
            </div>
        </div>
    </div>

</div><!-- /.main-content -->

<!-- ============================================================
     CSS
     ============================================================ -->
<style>
.main-content { padding: 1.5rem; }

@keyframes pulse-alert {
    0%, 100% { opacity: 1; }
    50%       { opacity: .4; }
}

/* KPI Cards */
.ts-kpi-card {
    background: var(--ts-bg-card);
    border: 1px solid var(--ts-border);
    border-top: 3px solid var(--ts-gold);
    border-radius: 10px;
    padding: 1rem 1.1rem .75rem;
}
.kpi-label {
    font-size: .78rem;
    color: var(--ts-text-muted);
    margin-bottom: .2rem;
    text-transform: uppercase;
    letter-spacing: .03em;
}
.kpi-value {
    font-size: 1.7rem;
    font-weight: 800;
    color: var(--ts-gold);
    line-height: 1.1;
}
.kpi-icon {
    font-size: 1.8rem;
    color: var(--ts-gold);
    opacity: .2;
}

/* Cards */
.card {
    background: var(--ts-bg-card);
    border: 1px solid var(--ts-border);
    border-radius: 10px;
    color: var(--ts-text-primary);
}
.card-header {
    background: rgba(0,0,0,.15);
    border-bottom: 1px solid var(--ts-border);
    padding: .75rem 1rem;
    font-size: .88rem;
    font-weight: 600;
    color: var(--ts-text-primary);
}

/* Tables */
.table { color: var(--ts-text-primary); }
.table thead th {
    background: rgba(0,0,0,.2);
    border-color: var(--ts-border);
    font-size: .78rem;
    font-weight: 600;
    color: var(--ts-text-muted);
    text-transform: uppercase;
    letter-spacing: .04em;
    padding: .55rem 1rem;
}
.table tbody td {
    border-color: var(--ts-border);
    padding: .55rem 1rem;
    vertical-align: middle;
}
.table-hover tbody tr:hover { background: rgba(201,168,76,.06); }

@media print {
    .no-print, nav, .sidebar { display: none !important; }
    .main-content { padding: 0 !important; }
}
</style>

<!-- ============================================================
     Chart.js + الرسوم البيانية
     ============================================================ -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
// ——— بيانات PHP → JS ———
const chartLabels   = <?= json_encode($chart_labels, JSON_UNESCAPED_UNICODE) ?>;
const chartSales    = <?= json_encode($chart_sales) ?>;
const chartPrev     = <?= json_encode($chart_prev) ?>;
const sparkSales    = <?= json_encode($sparkline_sales) ?>;
const sparkInv      = <?= json_encode($sparkline_invoices) ?>;
const tsGold        = '#C9A84C';
const tsGoldLight   = 'rgba(201,168,76,0.15)';
const tsSilver      = '#6C757D';
const tsSilverLight = 'rgba(108,117,125,0.1)';

Chart.defaults.color          = '#6C757D';
Chart.defaults.borderColor    = '#3A3A3A';
Chart.defaults.font.family    = "'Tajawal', sans-serif";

// ——— Helper: Sparkline ———
function mkSparkline(id, data, color) {
    const ctx = document.getElementById(id);
    if (!ctx) return;
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: data.map((_,i) => i),
            datasets: [{
                data,
                borderColor: color,
                borderWidth: 2,
                fill: true,
                backgroundColor: color.replace(')', ',0.12)').replace('rgb','rgba'),
                tension: 0.4,
                pointRadius: 0,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false }, tooltip: { enabled: false } },
            scales: { x: { display: false }, y: { display: false } },
            animation: { duration: 600 },
        }
    });
}

mkSparkline('spark-sales',   sparkSales, tsGold);
mkSparkline('spark-inv',     sparkInv,   '#4ABFFF');
mkSparkline('spark-month',   sparkSales, '#28A745');
mkSparkline('spark-profit',  sparkSales, '#C9A84C');

// ——— المخطط الرئيسي ———
(function() {
    const ctx = document.getElementById('mainSalesChart');
    if (!ctx) return;

    const isRTL = document.documentElement.dir === 'rtl';

    new Chart(ctx, {
        type: 'line',
        data: {
            labels: chartLabels,
            datasets: [
                {
                    label: isRTL ? 'الفترة الحالية' : 'Current Period',
                    data: chartSales,
                    borderColor: tsGold,
                    backgroundColor: 'rgba(201,168,76,0.12)',
                    borderWidth: 2.5,
                    fill: true,
                    tension: 0.4,
                    pointRadius: 4,
                    pointHoverRadius: 7,
                    pointBackgroundColor: tsGold,
                    pointBorderColor: '#1A1A1A',
                    pointBorderWidth: 2,
                },
                {
                    label: isRTL ? 'الفترة السابقة' : 'Previous Period',
                    data: chartPrev,
                    borderColor: tsSilver,
                    backgroundColor: tsSilverLight,
                    borderWidth: 1.5,
                    fill: false,
                    tension: 0.4,
                    pointRadius: 3,
                    pointHoverRadius: 5,
                    borderDash: [5, 3],
                    pointBackgroundColor: tsSilver,
                    pointBorderColor: '#1A1A1A',
                    pointBorderWidth: 1,
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: {
                    display: false, // عرضنا custom legend يدوياً في الـ HTML
                },
                tooltip: {
                    backgroundColor: '#1A1A1A',
                    borderColor: '#3A3A3A',
                    borderWidth: 1,
                    titleColor: '#C9A84C',
                    bodyColor: '#B0B3B8',
                    padding: 12,
                    callbacks: {
                        label: ctx => {
                            const v = ctx.parsed.y;
                            return ` ${ctx.dataset.label}: ${v.toLocaleString()} SDG`;
                        }
                    }
                }
            },
            scales: {
                x: {
                    grid: { color: 'rgba(58,58,58,0.5)' },
                    ticks: { color: '#6C757D', font: { size: 11 } }
                },
                y: {
                    grid: { color: 'rgba(58,58,58,0.5)' },
                    ticks: {
                        color: '#6C757D',
                        font: { size: 11 },
                        callback: v => v >= 1000
                            ? (v/1000).toFixed(1) + 'K'
                            : v
                    },
                    beginAtZero: true,
                }
            }
        }
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/layout/footer.php'; ?>
