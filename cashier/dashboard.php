<?php
// ============================================================
// المسار: cashier/dashboard.php
// الوظيفة: لوحة تحكم الكاشير — مبيعات اليوم + تنبيهات المخزون + آخر الفواتير
// الصلاحية: cashier فقط
// ============================================================

declare(strict_types=1);

$current_page = 'dashboard';
$page_title   = 'لوحة التحكم';

require_once __DIR__ . '/../includes/layout/header.php';
require_role(['cashier', 'branch_admin', 'super_admin']);

$pdo       = db();
$cashier_id = Auth::getUserId();
$branch_id  = Auth::getBranchId();
$today      = date('Y-m-d');

// ============================================================
// 1. إحصائيات مبيعات اليوم
// ============================================================
$stmt = $pdo->prepare("
    SELECT
        COUNT(*)                  AS invoice_count,
        COALESCE(SUM(total), 0)   AS total_sales,
        COALESCE(SUM(discount_amount), 0) AS total_discounts,
        COALESCE(AVG(total), 0)   AS avg_invoice
    FROM invoices
    WHERE cashier_id = ?
      AND branch_id  = ?
      AND DATE(created_at) = ?
      AND status = 'completed'
");
$stmt->execute([$cashier_id, $branch_id, $today]);
$today_stats = $stmt->fetch();

// ============================================================
// 2. المنتجات التي نزلت عن الحد الأدنى في فرع الكاشير
// ============================================================
$stmt = $pdo->prepare("
    SELECT
        p.id,
        p.name_ar,
        p.name_en,
        p.unit,
        i.quantity,
        i.min_quantity,
        CASE
            WHEN i.quantity <= 0               THEN 'out'
            WHEN i.quantity <= i.min_quantity  THEN 'low'
            ELSE 'ok'
        END AS stock_status
    FROM inventory i
    JOIN products p ON p.id = i.product_id
    WHERE i.branch_id  = ?
      AND p.status     = 'active'
      AND i.quantity   <= i.min_quantity
    ORDER BY i.quantity ASC
    LIMIT 10
");
$stmt->execute([$branch_id]);
$low_stock = $stmt->fetchAll();

// ============================================================
// 3. آخر 5 فواتير للكاشير الحالي
// ============================================================
$stmt = $pdo->prepare("
    SELECT
        i.id,
        i.invoice_number,
        i.total,
        i.payment_method,
        i.status,
        i.created_at,
        COALESCE(c.name, '') AS customer_name,
        COUNT(ii.id)         AS item_count
    FROM invoices i
    LEFT JOIN customers   c  ON c.id  = i.customer_id
    LEFT JOIN invoice_items ii ON ii.invoice_id = i.id
    WHERE i.cashier_id = ?
      AND i.branch_id  = ?
    GROUP BY i.id
    ORDER BY i.created_at DESC
    LIMIT 5
");
$stmt->execute([$cashier_id, $branch_id]);
$recent_invoices = $stmt->fetchAll();

// ——— مفاتيح اللغة ———
$page_title = $lang['dashboard'];
?>

<?php require_once __DIR__ . '/../includes/layout/sidebar.php'; ?>

<!-- ============================================================
     محتوى لوحة التحكم
     ============================================================ -->

<!-- عنوان الصفحة -->
<div class="d-flex align-items-center justify-content-between mb-4">
    <div>
        <h4 class="mb-0" style="color:var(--ts-gold); font-weight:700;">
            <i class="bi bi-speedometer2 me-2"></i>
            <?= $lang['dashboard'] ?>
        </h4>
        <small style="color:var(--ts-text-muted);">
            <?= ($lang_code === 'ar') ? 'اليوم — ' : 'Today — ' ?>
            <?= date('Y-m-d') ?>
        </small>
    </div>
    <a href="<?= str_repeat('../', $depth) ?>cashier/pos.php"
       class="btn btn-primary d-flex align-items-center gap-2">
        <i class="bi bi-cart3"></i>
        <?= $lang['pos'] ?>
    </a>
</div>

<!-- ============================================================
     KPI Cards — إحصائيات اليوم
     ============================================================ -->
<div class="row g-3 mb-4">

    <!-- إجمالي المبيعات -->
    <div class="col-6 col-lg-3">
        <div class="ts-kpi-card h-100">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="kpi-label mb-1"><?= $lang['total_sales'] ?></div>
                    <div class="kpi-value"><?= number_format((float)$today_stats['total_sales'], 0) ?></div>
                    <small style="color:var(--ts-text-muted);">SDG</small>
                </div>
                <i class="bi bi-cash-stack kpi-icon"></i>
            </div>
        </div>
    </div>

    <!-- عدد الفواتير -->
    <div class="col-6 col-lg-3">
        <div class="ts-kpi-card h-100">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="kpi-label mb-1"><?= $lang['total_invoices'] ?></div>
                    <div class="kpi-value"><?= (int)$today_stats['invoice_count'] ?></div>
                    <small style="color:var(--ts-text-muted);">
                        <?= ($lang_code === 'ar') ? 'فاتورة' : 'invoices' ?>
                    </small>
                </div>
                <i class="bi bi-file-earmark-check kpi-icon"></i>
            </div>
        </div>
    </div>

    <!-- متوسط الفاتورة -->
    <div class="col-6 col-lg-3">
        <div class="ts-kpi-card h-100">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="kpi-label mb-1"><?= $lang['avg_invoice'] ?></div>
                    <div class="kpi-value"><?= number_format((float)$today_stats['avg_invoice'], 0) ?></div>
                    <small style="color:var(--ts-text-muted);">SDG</small>
                </div>
                <i class="bi bi-graph-up-arrow kpi-icon"></i>
            </div>
        </div>
    </div>

    <!-- إجمالي الخصومات -->
    <div class="col-6 col-lg-3">
        <div class="ts-kpi-card h-100">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="kpi-label mb-1"><?= $lang['total_discounts'] ?></div>
                    <div class="kpi-value" style="color:var(--ts-danger);">
                        <?= number_format((float)$today_stats['total_discounts'], 0) ?>
                    </div>
                    <small style="color:var(--ts-text-muted);">SDG</small>
                </div>
                <i class="bi bi-tag kpi-icon"></i>
            </div>
        </div>
    </div>

</div>

<div class="row g-4">

    <!-- ============================================================
         آخر 5 فواتير
         ============================================================ -->
    <div class="col-12 col-xl-7">
        <div class="card h-100">
            <div class="card-header d-flex align-items-center justify-content-between">
                <span>
                    <i class="bi bi-receipt me-2"></i>
                    <?= $lang['my_invoices'] ?>
                </span>
                <small style="color:var(--ts-text-muted); font-weight:400;">
                    <?= ($lang_code === 'ar') ? 'آخر 5 فواتير' : 'Last 5 invoices' ?>
                </small>
            </div>
            <div class="card-body p-0">
                <?php if (empty($recent_invoices)): ?>
                    <div class="text-center py-5" style="color:var(--ts-text-muted);">
                        <i class="bi bi-inbox" style="font-size:2.5rem; display:block; margin-bottom:.75rem; opacity:.4;"></i>
                        <?= ($lang_code === 'ar') ? 'لا توجد فواتير اليوم بعد' : 'No invoices yet today' ?>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th><?= $lang['invoice_number'] ?></th>
                                    <th><?= $lang['customer'] ?></th>
                                    <th><?= $lang['total'] ?></th>
                                    <th><?= $lang['payment_method'] ?></th>
                                    <th><?= $lang['status'] ?></th>
                                    <th><?= $lang['time'] ?></th>
                                    <th class="no-print"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_invoices as $inv): ?>
                                    <tr>
                                        <td>
                                            <code style="color:var(--ts-gold); font-size:.8rem;">
                                                <?= htmlspecialchars($inv['invoice_number'], ENT_QUOTES, 'UTF-8') ?>
                                            </code>
                                        </td>
                                        <td>
                                            <?php if ($inv['customer_name']): ?>
                                                <i class="bi bi-person-fill me-1" style="color:var(--ts-silver-dark)"></i>
                                                <?= htmlspecialchars($inv['customer_name'], ENT_QUOTES, 'UTF-8') ?>
                                            <?php else: ?>
                                                <span style="color:var(--ts-text-muted);">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="font-weight:600; color:var(--ts-gold);">
                                            <?= number_format((float)$inv['total'], 2) ?>
                                            <small style="color:var(--ts-text-muted); font-weight:400;">SDG</small>
                                        </td>
                                        <td>
                                            <?php
                                            $pm_icons = [
                                                'cash'   => 'bi-cash',
                                                'bankak' => 'bi-phone',
                                                'ocash'  => 'bi-wallet2',
                                                'card'   => 'bi-credit-card',
                                                'other'  => 'bi-three-dots',
                                            ];
                                            $pm_icon = $pm_icons[$inv['payment_method']] ?? 'bi-cash';
                                            ?>
                                            <i class="bi <?= $pm_icon ?> me-1" style="color:var(--ts-silver)"></i>
                                            <?= $lang[$inv['payment_method']] ?? $inv['payment_method'] ?>
                                        </td>
                                        <td>
                                            <?php
                                            $st_color = match($inv['status']) {
                                                'completed' => 'var(--ts-success)',
                                                'refunded'  => 'var(--ts-warning)',
                                                'cancelled' => 'var(--ts-danger)',
                                                default     => 'var(--ts-silver)',
                                            };
                                            ?>
                                            <span class="badge rounded-pill"
                                                  style="background:<?= $st_color ?>22; color:<?= $st_color ?>; border:1px solid <?= $st_color ?>44;">
                                                <?= $lang[$inv['status']] ?? $inv['status'] ?>
                                            </span>
                                        </td>
                                        <td style="color:var(--ts-text-muted); font-size:.82rem;">
                                            <?= date('H:i', strtotime($inv['created_at'])) ?>
                                        </td>
                                        <td class="no-print">
                                            <a href="<?= str_repeat('../', $depth) ?>cashier/invoice_print.php?id=<?= (int)$inv['id'] ?>"
                                               class="btn btn-sm"
                                               style="background:transparent; border:1px solid var(--ts-border); color:var(--ts-silver); padding:.2rem .55rem;"
                                               title="<?= $lang['print'] ?>"
                                               target="_blank">
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

    <!-- ============================================================
         تنبيهات المخزون الناقص
         ============================================================ -->
    <div class="col-12 col-xl-5">
        <div class="card h-100">
            <div class="card-header d-flex align-items-center justify-content-between">
                <span>
                    <i class="bi bi-exclamation-triangle me-2" style="color:var(--ts-danger)"></i>
                    <?= $lang['low_stock_alerts'] ?>
                </span>
                <?php if (!empty($low_stock)): ?>
                    <span class="badge bg-danger rounded-pill"><?= count($low_stock) ?></span>
                <?php endif; ?>
            </div>
            <div class="card-body p-0">
                <?php if (empty($low_stock)): ?>
                    <div class="text-center py-5" style="color:var(--ts-text-muted);">
                        <i class="bi bi-check-circle" style="font-size:2.5rem; display:block; margin-bottom:.75rem; color:var(--ts-success); opacity:.6;"></i>
                        <?= ($lang_code === 'ar') ? 'المخزون بخير ✓' : 'Stock levels are good ✓' ?>
                    </div>
                <?php else: ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($low_stock as $item): ?>
                            <?php
                            $is_out  = ((float)$item['quantity'] <= 0);
                            $color   = $is_out ? 'var(--ts-danger)' : 'var(--ts-warning)';
                            $icon    = $is_out ? 'bi-x-circle-fill' : 'bi-exclamation-circle-fill';
                            $label   = $is_out ? $lang['stock_out'] : $lang['stock_low'];
                            $p_name  = ($lang_code === 'en' && $item['name_en'])
                                       ? $item['name_en'] : $item['name_ar'];
                            ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center"
                                style="background:transparent; border-color:var(--ts-border); padding:.65rem 1rem;">
                                <div class="d-flex align-items-center gap-2">
                                    <i class="bi <?= $icon ?>" style="color:<?= $color ?>; font-size:.95rem; flex-shrink:0;"></i>
                                    <div>
                                        <div style="font-size:.87rem; color:var(--ts-text-primary); font-weight:500;">
                                            <?= htmlspecialchars($p_name, ENT_QUOTES, 'UTF-8') ?>
                                        </div>
                                        <div style="font-size:.75rem; color:var(--ts-text-muted);">
                                            <?= $lang['min_quantity'] ?>:
                                            <?= rtrim(rtrim(number_format((float)$item['min_quantity'], 3), '0'), '.') ?>
                                            <?= htmlspecialchars($item['unit'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="text-end">
                                    <div style="font-weight:700; color:<?= $color ?>; font-size:.9rem;">
                                        <?= rtrim(rtrim(number_format((float)$item['quantity'], 3), '0'), '.') ?>
                                    </div>
                                    <span class="badge rounded-pill"
                                          style="font-size:.65rem; background:<?= $color ?>22; color:<?= $color ?>; border:1px solid <?= $color ?>44;">
                                        <?= $label ?>
                                    </span>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>

</div><!-- /.row -->

<!-- ============================================================
     زر الانتقال لنقطة البيع — ثابت أسفل الشاشة على الموبايل
     ============================================================ -->
<div class="d-md-none position-fixed bottom-0 start-0 end-0 p-3 no-print"
     style="z-index:1020; background:linear-gradient(transparent, var(--ts-bg-dark) 60%);">
    <a href="<?= str_repeat('../', $depth) ?>cashier/pos.php"
       class="btn btn-primary w-100 d-flex align-items-center justify-content-center gap-2"
       style="height:50px; font-size:1.05rem; font-weight:700;">
        <i class="bi bi-cart3"></i>
        <?= $lang['pos'] ?>
    </a>
</div>

<?php require_once __DIR__ . '/../includes/layout/footer.php'; ?>