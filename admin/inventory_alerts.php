<?php
// ============================================================
// المسار: admin/inventory_alerts.php
// الوظيفة: تنبيهات المخزون الناقص / المنعدم مجمّعة بالفرع
//          + آخر تاريخ بيع + زر طلب تحويل مباشر
// الصلاحية: super_admin | branch_admin
// ============================================================

declare(strict_types=1);

require_once __DIR__ . '/../includes/layout/header.php';
require_role(['super_admin', 'branch_admin']);

$pdo      = db();
$role     = Auth::getRole();
$user_bid = Auth::getBranchId();
$is_super = ($role === 'super_admin');

// ================================================================
// جلب الفروع النشطة
// ================================================================
$branches_stmt = $pdo->query("SELECT id, name, code FROM branches WHERE status = 'active' ORDER BY name");
$branches      = $branches_stmt->fetchAll();

// ================================================================
// فلتر الفرع
// ================================================================
if ($is_super) {
    $filter_bid = sanitize_int($_GET['branch_id'] ?? 0) ?: null;
} else {
    $filter_bid = $user_bid;
}

// ================================================================
// استعلام المنتجات الناقصة — مجمّعة بالفرع
// ================================================================
$alert_params = [];
$branch_cond  = '1=1';

if ($filter_bid !== null) {
    $branch_cond    = 'i.branch_id = ?';
    $alert_params[] = $filter_bid;
} elseif (!$is_super) {
    $branch_cond    = 'i.branch_id = ?';
    $alert_params[] = $user_bid;
}
// super_admin بدون فلتر → يرى كل الفروع

$alerts_stmt = $pdo->prepare("
    SELECT
        p.id        AS product_id,
        p.name_ar   AS product_name_ar,
        p.name_en   AS product_name_en,
        p.barcode,
        p.unit,
        p.cost_price,
        p.selling_price,
        i.quantity,
        i.min_quantity,
        i.branch_id,
        b.name      AS branch_name,
        (
            SELECT MAX(inv2.created_at)
            FROM inventory_movements inv2
            WHERE inv2.product_id = p.id
              AND inv2.branch_id  = i.branch_id
              AND inv2.movement_type = 'sale'
        ) AS last_sale_date
    FROM inventory i
    JOIN products  p ON p.id = i.product_id
    JOIN branches  b ON b.id = i.branch_id
    WHERE p.status = 'active'
      AND i.branch_id IS NOT NULL
      AND i.quantity <= i.min_quantity
      AND {$branch_cond}
    ORDER BY b.name, i.quantity ASC
");
$alerts_stmt->execute($alert_params);
$alerts_raw = $alerts_stmt->fetchAll();

// تجميع التنبيهات حسب الفرع
$grouped = [];
foreach ($alerts_raw as $row) {
    $bid   = (int) $row['branch_id'];
    $bname = $row['branch_name'];
    if (!isset($grouped[$bid])) {
        $grouped[$bid] = ['branch_name' => $bname, 'branch_id' => $bid, 'items' => []];
    }
    $grouped[$bid]['items'][] = $row;
}

$total_alerts = count($alerts_raw);
$out_of_stock = count(array_filter($alerts_raw, static fn ($r): bool => (float) $r['quantity'] <= 0));
$low_stock    = $total_alerts - $out_of_stock;

$csrf_token = generate_csrf_token();
?>

<?php include __DIR__ . '/../includes/layout/sidebar.php'; ?>

<div class="main-content">

    <?php show_flash(); ?>

    <!-- رأس الصفحة -->
    <div class="page-header d-flex align-items-center justify-content-between mb-3">
        <div>
            <h4 class="page-title mb-0">
                <i class="bi bi-bell-fill me-2" style="color:var(--ts-warning);"></i>
                <?= $lang['low_stock_alerts'] ?>
            </h4>
            <small style="color:var(--ts-text-muted);">
                <?= $is_rtl
                    ? "{$total_alerts} منتج يحتاج انتباهاً"
                    : "{$total_alerts} product(s) need attention" ?>
            </small>
        </div>
        <a href="inventory.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-boxes me-1"></i><?= $lang['inventory'] ?>
        </a>
    </div>

    <!-- ============================================================
         KPI Cards
         ============================================================ -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card ts-card text-center py-3">
                <div style="font-size:2rem; font-weight:700; color:var(--ts-warning);">
                    <?= $total_alerts ?>
                </div>
                <div style="font-size:.8rem; color:var(--ts-text-muted);">
                    <?= $is_rtl ? 'إجمالي التنبيهات' : 'Total Alerts' ?>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card ts-card text-center py-3">
                <div style="font-size:2rem; font-weight:700; color:var(--ts-danger);">
                    <?= $out_of_stock ?>
                </div>
                <div style="font-size:.8rem; color:var(--ts-text-muted);">
                    <?= $lang['stock_out'] ?>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card ts-card text-center py-3">
                <div style="font-size:2rem; font-weight:700; color:#ffc107;">
                    <?= $low_stock ?>
                </div>
                <div style="font-size:.8rem; color:var(--ts-text-muted);">
                    <?= $lang['stock_low'] ?>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card ts-card text-center py-3">
                <div style="font-size:2rem; font-weight:700; color:var(--ts-silver);">
                    <?= count($grouped) ?>
                </div>
                <div style="font-size:.8rem; color:var(--ts-text-muted);">
                    <?= $is_rtl ? 'فرع متأثر' : 'Branch(es) Affected' ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ============================================================
         فلتر الفرع (super_admin)
         ============================================================ -->
    <?php if ($is_super): ?>
    <div class="card ts-card mb-3">
        <div class="card-body py-2">
            <form method="GET" class="d-flex gap-2 align-items-center flex-wrap">
                <label style="font-size:.85rem; color:var(--ts-text-muted);">
                    <?= $is_rtl ? 'عرض فرع:' : 'Show branch:' ?>
                </label>
                <a href="inventory_alerts.php"
                   class="btn btn-sm <?= $filter_bid === null ? 'btn-primary' : 'btn-outline-secondary' ?>">
                    <?= $is_rtl ? 'كل الفروع' : 'All Branches' ?>
                </a>
                <?php foreach ($branches as $br): ?>
                    <a href="?branch_id=<?= (int) $br['id'] ?>"
                       class="btn btn-sm <?= $filter_bid === (int) $br['id'] ? 'btn-primary' : 'btn-outline-secondary' ?>">
                        <?= htmlspecialchars($br['name'], ENT_QUOTES, 'UTF-8') ?>
                    </a>
                <?php endforeach; ?>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- ============================================================
         التنبيهات مجمّعة حسب الفرع
         ============================================================ -->
    <?php if (empty($grouped)): ?>
        <div class="card ts-card">
            <div class="card-body text-center py-5">
                <i class="bi bi-check-circle-fill"
                   style="font-size:3rem; color:var(--ts-success); display:block; margin-bottom:1rem;"></i>
                <h5 style="color:var(--ts-success);">
                    <?= $is_rtl ? 'كل المخزون في وضع جيد!' : 'All stock levels are good!' ?>
                </h5>
                <p style="color:var(--ts-text-muted);">
                    <?= $is_rtl
                        ? 'لا توجد منتجات وصلت للحد الأدنى حالياً.'
                        : 'No products have reached minimum stock levels.' ?>
                </p>
            </div>
        </div>
    <?php else: ?>

    <?php foreach ($grouped as $group_bid => $group): ?>
    <div class="card ts-card mb-4">
        <div class="card-header d-flex align-items-center justify-content-between">
            <span style="color:var(--ts-gold); font-weight:700;">
                <i class="bi bi-building me-2"></i>
                <?= htmlspecialchars($group['branch_name'], ENT_QUOTES, 'UTF-8') ?>
                <span class="badge bg-danger ms-2"><?= count($group['items']) ?></span>
            </span>
            <?php if (!$is_super): ?>
            <button class="btn btn-primary btn-xs btn-quick-transfer"
                    data-branch_id="<?= (int) $group_bid ?>"
                    data-branch_name="<?= htmlspecialchars($group['branch_name'], ENT_QUOTES, 'UTF-8') ?>">
                <i class="bi bi-arrow-left-right me-1"></i>
                <?= $is_rtl ? 'طلب تحويل' : 'Request Transfer' ?>
            </button>
            <?php endif; ?>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table ts-table mb-0">
                    <thead>
                        <tr>
                            <th><?= $lang['product_name'] ?></th>
                            <th class="text-center"><?= $is_rtl ? 'الكمية الحالية' : 'Current Qty' ?></th>
                            <th class="text-center"><?= $lang['min_quantity'] ?></th>
                            <th class="text-center"><?= $lang['status'] ?></th>
                            <th><?= $is_rtl ? 'آخر بيع' : 'Last Sale' ?></th>
                            <th class="text-center"><?= $is_rtl ? 'قيمة الاحتياج' : 'Reorder Value' ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($group['items'] as $item):
                        $qty     = (float) $item['quantity'];
                        $minqty  = (float) $item['min_quantity'];
                        $name    = $lang_code === 'ar'
                                 ? $item['product_name_ar']
                                 : ($item['product_name_en'] ?: $item['product_name_ar']);

                        $is_out      = $qty <= 0;
                        $need_qty    = max(0, $minqty - $qty);
                        $reorder_val = $need_qty * (float) $item['cost_price'];
                    ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?></strong>
                                <?php if ($item['barcode']): ?>
                                    <div>
                                        <code style="font-size:.72rem; color:var(--ts-text-muted);">
                                            <?= htmlspecialchars($item['barcode'], ENT_QUOTES, 'UTF-8') ?>
                                        </code>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <strong style="font-size:1.1rem; color:<?= $is_out ? 'var(--ts-danger)' : '#ffc107' ?>;">
                                    <?= format_qty($qty) ?>
                                </strong>
                                <div style="font-size:.72rem; color:var(--ts-text-muted);">
                                    <?= htmlspecialchars($item['unit'], ENT_QUOTES, 'UTF-8') ?>
                                </div>
                            </td>
                            <td class="text-center" style="color:var(--ts-text-muted);">
                                <?= $minqty > 0 ? format_qty($minqty) : '—' ?>
                            </td>
                            <td class="text-center">
                                <?php if ($is_out): ?>
                                    <span class="badge bg-danger"><?= $lang['stock_out'] ?></span>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark"><?= $lang['stock_low'] ?></span>
                                <?php endif; ?>
                            </td>
                            <td style="color:var(--ts-text-muted); font-size:.82rem;">
                                <?php if ($item['last_sale_date']): ?>
                                    <?= htmlspecialchars(format_datetime($item['last_sale_date']), ENT_QUOTES, 'UTF-8') ?>
                                <?php else: ?>
                                    <span style="opacity:.5;">
                                        <?= $is_rtl ? 'لا توجد مبيعات' : 'No sales yet' ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center" style="font-size:.85rem; color:var(--ts-warning);">
                                <?= $need_qty > 0 ? format_money($reorder_val) : '—' ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <?php
                    $group_reorder = array_sum(array_map(
                        static fn ($i): float => max(0, (float)$i['min_quantity'] - (float)$i['quantity']) * (float)$i['cost_price'],
                        $group['items']
                    ));
                    ?>
                    <tfoot>
                        <tr style="background:var(--ts-bg-dark);">
                            <td colspan="5" class="text-end pe-3"
                                style="color:var(--ts-text-muted); font-size:.82rem;">
                                <?= $is_rtl
                                    ? 'إجمالي قيمة الاحتياج لهذا الفرع:'
                                    : 'Total reorder value for this branch:' ?>
                            </td>
                            <td class="text-center" style="color:var(--ts-warning); font-weight:700;">
                                <?= format_money($group_reorder) ?>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <!-- إجمالي كل الفروع -->
    <?php if (count($grouped) > 1): ?>
    <div class="card ts-card mb-3">
        <div class="card-body py-2 d-flex justify-content-between align-items-center">
            <span style="color:var(--ts-text-muted);">
                <?= $is_rtl
                    ? 'إجمالي قيمة الاحتياج لجميع الفروع:'
                    : 'Total reorder value across all branches:' ?>
            </span>
            <strong style="color:var(--ts-gold); font-size:1.1rem;">
                <?php
                $grand_total = array_sum(array_map(
                    static fn ($i): float => max(0, (float)$i['min_quantity'] - (float)$i['quantity']) * (float)$i['cost_price'],
                    $alerts_raw
                ));
                echo format_money($grand_total);
                ?>
            </strong>
        </div>
    </div>
    <?php endif; ?>

    <?php endif; ?>

</div><!-- /.main-content -->

<!-- ============================================================
     Modal — طلب تحويل سريع (branch_admin فقط)
     ============================================================ -->
<?php if (!$is_super): ?>
<div class="modal fade" id="modalQuickTransfer" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content ts-modal">
            <div class="modal-header">
                <h5 class="modal-title" style="color:var(--ts-gold);">
                    <i class="bi bi-arrow-left-right me-2"></i>
                    <?= $is_rtl ? 'طلب تحويل سريع' : 'Quick Transfer Request' ?>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p style="color:var(--ts-text-muted); font-size:.85rem;">
                    <?= $is_rtl
                        ? 'سيتم إنشاء طلب تحويل لجلب الكميات الناقصة من المخزون المركزي.'
                        : 'A transfer request will be created to bring missing quantities from central stock.' ?>
                </p>
                <p>
                    <a href="stock_transfers.php" class="btn btn-primary btn-sm">
                        <i class="bi bi-arrow-left-right me-1"></i>
                        <?= $is_rtl ? 'الانتقال لصفحة التحويلات' : 'Go to Transfers Page' ?>
                    </a>
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">
                    <?= $lang['close'] ?>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
document.querySelectorAll('.btn-quick-transfer').forEach(function (btn) {
    btn.addEventListener('click', function () {
        new bootstrap.Modal(document.getElementById('modalQuickTransfer')).show();
    });
});
</script>
<?php endif; ?>

<?php include __DIR__ . '/../includes/layout/footer.php'; ?>
