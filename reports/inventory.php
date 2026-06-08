<?php
// ============================================================
// المسار: reports/inventory.php
// الوظيفة: تقرير المخزون — الكمية الحالية + قيمة المخزون +
//          المنتجات الناقصة + حركات المخزون خلال فترة
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
$filter_branch   = $is_super ? sanitize_int($_GET['branch_id'] ?? 0) : (int)$user_bid;
$filter_category = sanitize_int($_GET['category_id'] ?? 0);
$filter_status   = $_GET['stock_status'] ?? 'all';   // all | low | out | ok
$filter_date_from = trim($_GET['date_from'] ?? date('Y-m-01'));
$filter_date_to   = trim($_GET['date_to']   ?? date('Y-m-d'));

// ============================================================
// شرط الفرع للمخزون
// ============================================================
$branch_cond = '';
$branch_params = [];

if (!$is_super) {
    $branch_cond    = 'AND (i.branch_id = ? OR i.branch_id IS NULL)';
    $branch_params[] = $user_bid;
} elseif ($filter_branch > 0) {
    $branch_cond    = 'AND (i.branch_id = ? OR i.branch_id IS NULL)';
    $branch_params[] = $filter_branch;
}

// ============================================================
// تصدير CSV
// ============================================================
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $csv_stmt = $pdo->prepare("
        SELECT
            p.name_ar, p.name_en, p.barcode, p.unit,
            c.name_ar AS category_ar,
            COALESCE(SUM(CASE WHEN i.branch_id IS NULL THEN i.quantity ELSE 0 END), 0) AS central_qty,
            COALESCE(SUM(CASE WHEN i.branch_id = ? THEN i.quantity ELSE 0 END), 0)     AS branch_qty,
            COALESCE(SUM(i.quantity), 0)   AS total_qty,
            p.cost_price,
            COALESCE(SUM(i.quantity), 0) * p.cost_price AS stock_value,
            COALESCE(MIN(i.min_quantity), 0) AS min_qty
        FROM products p
        LEFT JOIN inventory i ON i.product_id = p.id
        LEFT JOIN categories c ON c.id = p.category_id
        WHERE p.status = 'active'
        GROUP BY p.id
        ORDER BY p.name_ar
    ");
    $csv_bid = $filter_branch > 0 ? $filter_branch : ($user_bid ?? 0);
    $csv_stmt->execute([$csv_bid]);
    $csv_rows = $csv_stmt->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="inventory_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($out, [
        $is_rtl ? 'المنتج' : 'Product',
        $is_rtl ? 'الباركود' : 'Barcode',
        $is_rtl ? 'التصنيف' : 'Category',
        $is_rtl ? 'الوحدة' : 'Unit',
        $is_rtl ? 'مخزون مركزي' : 'Central Stock',
        $is_rtl ? 'مخزون الفرع' : 'Branch Stock',
        $is_rtl ? 'الإجمالي' : 'Total',
        $is_rtl ? 'الحد الأدنى' : 'Min Qty',
        $is_rtl ? 'تكلفة الوحدة' : 'Unit Cost',
        $is_rtl ? 'قيمة المخزون' : 'Stock Value',
    ]);
    foreach ($csv_rows as $row) {
        $name = ($lang_code === 'en' && $row['name_en']) ? $row['name_en'] : $row['name_ar'];
        fputcsv($out, [
            $name,
            $row['barcode'] ?? '—',
            $row['category_ar'] ?? '—',
            $row['unit'] ?? '—',
            $row['central_qty'],
            $row['branch_qty'],
            $row['total_qty'],
            $row['min_qty'],
            $row['cost_price'],
            number_format((float)$row['stock_value'], 2),
        ]);
    }
    fclose($out);
    exit;
}

// ============================================================
// بناء الاستعلام الرئيسي للمخزون
// ============================================================
$where_parts = ['p.status = \'active\''];
$params      = [];

// فلتر التصنيف
if ($filter_category > 0) {
    $where_parts[] = 'p.category_id = ?';
    $params[]      = $filter_category;
}

$where_sql = 'WHERE ' . implode(' AND ', $where_parts);

// الفرع المستخدم في الحسابات
$active_bid = $filter_branch > 0 ? $filter_branch : ($user_bid ?? 0);

// استعلام المخزون مع مخزون الفرع والمركزي منفصلَين
$inv_stmt = $pdo->prepare("
    SELECT
        p.id, p.name_ar, p.name_en, p.barcode, p.unit,
        p.cost_price, p.selling_price,
        c.name_ar AS category_ar, c.name_en AS category_en,
        COALESCE(central.quantity, 0)  AS central_qty,
        COALESCE(central.min_quantity,0) AS central_min,
        COALESCE(branch_inv.quantity, 0) AS branch_qty,
        COALESCE(branch_inv.min_quantity,0) AS branch_min,
        (COALESCE(central.quantity,0) + COALESCE(branch_inv.quantity,0)) AS total_qty,
        (COALESCE(central.quantity,0) + COALESCE(branch_inv.quantity,0)) * p.cost_price AS stock_value
    FROM products p
    LEFT JOIN categories c ON c.id = p.category_id
    LEFT JOIN inventory central    ON central.product_id   = p.id AND central.branch_id IS NULL
    LEFT JOIN inventory branch_inv ON branch_inv.product_id = p.id AND branch_inv.branch_id = ?
    $where_sql
    ORDER BY p.name_ar ASC
");
$params_full = array_merge([$active_bid], $params);
$inv_stmt->execute($params_full);
$all_products = $inv_stmt->fetchAll();

// ============================================================
// تطبيق فلتر حالة المخزون
// ============================================================
$filtered_products = array_filter($all_products, function($row) use ($filter_status) {
    $total = (float)$row['total_qty'];
    $min   = max((float)$row['branch_min'], (float)$row['central_min']);
    return match($filter_status) {
        'out' => $total <= 0,
        'low' => $total > 0 && $min > 0 && $total <= $min,
        'ok'  => $total > 0 && ($min <= 0 || $total > $min),
        default => true,
    };
});
$filtered_products = array_values($filtered_products);

// ============================================================
// KPIs
// ============================================================
$total_products   = count($all_products);
$total_stock_val  = array_sum(array_column($all_products, 'stock_value'));
$out_of_stock     = count(array_filter($all_products, fn($r) => (float)$r['total_qty'] <= 0));
$low_stock_count  = count(array_filter($all_products, function($r) {
    $min = max((float)$r['branch_min'], (float)$r['central_min']);
    return (float)$r['total_qty'] > 0 && $min > 0 && (float)$r['total_qty'] <= $min;
}));

// ============================================================
// حركات المخزون خلال الفترة
// ============================================================
$mov_branch_cond  = '';
$mov_params       = [$filter_date_from, $filter_date_to];

if (!$is_super) {
    $mov_branch_cond  = 'AND (m.branch_id = ? OR m.branch_id IS NULL)';
    $mov_params[]     = $user_bid;
} elseif ($filter_branch > 0) {
    $mov_branch_cond  = 'AND (m.branch_id = ? OR m.branch_id IS NULL)';
    $mov_params[]     = $filter_branch;
}

$mov_stmt = $pdo->prepare("
    SELECT
        m.id, m.movement_type, m.quantity,
        m.quantity_before, m.quantity_after,
        m.notes, m.created_at,
        p.name_ar, p.name_en, p.unit,
        b.name AS branch_name,
        u.name AS created_by_name
    FROM inventory_movements m
    JOIN products p ON p.id = m.product_id
    LEFT JOIN branches b ON b.id = m.branch_id
    LEFT JOIN users    u ON u.id = m.created_by
    WHERE DATE(m.created_at) BETWEEN ? AND ?
      $mov_branch_cond
    ORDER BY m.created_at DESC
    LIMIT 50
");
$mov_stmt->execute($mov_params);
$movements = $mov_stmt->fetchAll();

// ============================================================
// بيانات الفلاتر
// ============================================================
$branches_list  = $is_super
    ? $pdo->query("SELECT id, name FROM branches WHERE status='active' ORDER BY name")->fetchAll()
    : [];
$categories_list = $pdo->query("SELECT id, name_ar, name_en FROM categories WHERE status='active' ORDER BY name_ar")->fetchAll();

$movement_labels = [
    'purchase'      => $is_rtl ? 'شراء'        : 'Purchase',
    'sale'          => $is_rtl ? 'بيع'          : 'Sale',
    'transfer_in'   => $is_rtl ? 'تحويل وارد'   : 'Transfer In',
    'transfer_out'  => $is_rtl ? 'تحويل صادر'   : 'Transfer Out',
    'return_in'     => $is_rtl ? 'مرتجع'        : 'Return In',
    'adjustment'    => $is_rtl ? 'تعديل يدوي'   : 'Adjustment',
];
$movement_colors = [
    'purchase'    => 'var(--ts-success)',
    'sale'        => 'var(--ts-danger)',
    'transfer_in' => '#17a2b8',
    'transfer_out'=> '#fd7e14',
    'return_in'   => 'var(--ts-warning)',
    'adjustment'  => 'var(--ts-silver)',
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
            <i class="bi bi-clipboard-data me-2"></i>
            <?= $lang['inventory_report'] ?>
        </h4>
        <div class="d-flex gap-2 flex-wrap">
            <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>"
               class="btn btn-outline-secondary btn-sm">
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
            <form method="GET" class="row g-2 align-items-end">
                <?php if ($is_super): ?>
                <div class="col-md-2">
                    <label class="form-label small"><?= $lang['branch'] ?></label>
                    <select name="branch_id" class="form-select form-select-sm ts-input">
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
                    <label class="form-label small"><?= $lang['category'] ?></label>
                    <select name="category_id" class="form-select form-select-sm ts-input">
                        <option value=""><?= $is_rtl ? 'كل التصنيفات' : 'All Categories' ?></option>
                        <?php foreach ($categories_list as $cat):
                            $cat_name = ($lang_code === 'en' && $cat['name_en']) ? $cat['name_en'] : $cat['name_ar'];
                        ?>
                        <option value="<?= $cat['id'] ?>" <?= $filter_category == $cat['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cat_name) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-2">
                    <label class="form-label small"><?= $is_rtl ? 'حالة المخزون' : 'Stock Status' ?></label>
                    <select name="stock_status" class="form-select form-select-sm ts-input">
                        <option value="all"  <?= $filter_status === 'all' ? 'selected' : '' ?>><?= $is_rtl ? 'الكل' : 'All' ?></option>
                        <option value="ok"   <?= $filter_status === 'ok'  ? 'selected' : '' ?>><?= $lang['stock_ok'] ?></option>
                        <option value="low"  <?= $filter_status === 'low' ? 'selected' : '' ?>><?= $lang['stock_low'] ?></option>
                        <option value="out"  <?= $filter_status === 'out' ? 'selected' : '' ?>><?= $lang['stock_out'] ?></option>
                    </select>
                </div>

                <!-- فلتر التاريخ لسجل الحركات -->
                <div class="col-md-2">
                    <label class="form-label small"><?= $lang['date_from'] ?> (<?= $is_rtl ? 'الحركات' : 'Movements' ?>)</label>
                    <input type="date" name="date_from" class="form-control form-control-sm ts-input"
                           value="<?= htmlspecialchars($filter_date_from) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small"><?= $lang['date_to'] ?></label>
                    <input type="date" name="date_to" class="form-control form-control-sm ts-input"
                           value="<?= htmlspecialchars($filter_date_to) ?>">
                </div>

                <div class="col-auto d-flex gap-1">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-funnel me-1"></i><?= $lang['filter'] ?>
                    </button>
                    <a href="inventory.php" class="btn btn-outline-secondary btn-sm">
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
                    <div class="small text-muted mb-1"><?= $is_rtl ? 'إجمالي المنتجات' : 'Total Products' ?></div>
                    <div class="fs-4 fw-bold" style="color:var(--ts-gold)"><?= $total_products ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <div class="small text-muted mb-1"><?= $lang['stock_value'] ?></div>
                    <div class="fs-5 fw-bold" style="color:var(--ts-success)"><?= format_money($total_stock_val) ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <div class="small text-muted mb-1"><?= $lang['stock_low'] ?></div>
                    <div class="fs-4 fw-bold" style="color:var(--ts-warning)"><?= $low_stock_count ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <div class="small text-muted mb-1"><?= $lang['stock_out'] ?></div>
                    <div class="fs-4 fw-bold" style="color:var(--ts-danger)"><?= $out_of_stock ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- ================================================== -->
    <!-- جدول المخزون                                         -->
    <!-- ================================================== -->
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-boxes me-1"></i><?= $lang['inventory_report'] ?></span>
            <small class="text-muted"><?= count($filtered_products) ?> <?= $lang['results'] ?></small>
        </div>
        <div class="card-body p-0">
            <?php if (empty($filtered_products)): ?>
                <div class="text-center py-5 text-muted">
                    <i class="bi bi-inbox fs-1 d-block mb-2"></i><?= $lang['no_results'] ?>
                </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th><?= $lang['product_name'] ?></th>
                            <th><?= $lang['category'] ?></th>
                            <th><?= $lang['unit'] ?></th>
                            <th class="text-end"><?= $lang['central_stock'] ?></th>
                            <th class="text-end"><?= $lang['branch_stock'] ?></th>
                            <th class="text-end"><?= $is_rtl ? 'الإجمالي' : 'Total' ?></th>
                            <th class="text-end"><?= $lang['min_quantity'] ?></th>
                            <th class="text-end"><?= $lang['cost_price'] ?></th>
                            <th class="text-end"><?= $lang['stock_value'] ?></th>
                            <th class="text-center"><?= $lang['status'] ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($filtered_products as $idx => $row):
                        $total_qty = (float)$row['total_qty'];
                        $min_qty   = max((float)$row['branch_min'], (float)$row['central_min']);
                        $is_out    = $total_qty <= 0;
                        $is_low    = !$is_out && $min_qty > 0 && $total_qty <= $min_qty;
                        $st_color  = $is_out ? 'var(--ts-danger)' : ($is_low ? 'var(--ts-warning)' : 'var(--ts-success)');
                        $st_label  = $is_out ? $lang['stock_out'] : ($is_low ? $lang['stock_low'] : $lang['stock_ok']);
                        $p_name    = ($lang_code === 'en' && $row['name_en']) ? $row['name_en'] : $row['name_ar'];
                        $cat_name  = ($lang_code === 'en' && $row['category_en']) ? $row['category_en'] : ($row['category_ar'] ?? '—');
                    ?>
                    <tr style="<?= $is_out ? 'opacity:.65' : '' ?>">
                        <td class="text-muted small"><?= $idx + 1 ?></td>
                        <td>
                            <strong style="color:<?= $is_out ? 'var(--ts-danger)' : 'var(--ts-text-primary)' ?>">
                                <?= htmlspecialchars($p_name) ?>
                            </strong>
                            <?php if ($row['barcode']): ?>
                                <br><small class="text-muted font-monospace"><?= htmlspecialchars($row['barcode']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td><small class="text-muted"><?= htmlspecialchars($cat_name) ?></small></td>
                        <td><small><?= htmlspecialchars($row['unit'] ?? '—') ?></small></td>
                        <td class="text-end">
                            <?= rtrim(rtrim(number_format((float)$row['central_qty'], 3), '0'), '.') ?>
                        </td>
                        <td class="text-end">
                            <?= rtrim(rtrim(number_format((float)$row['branch_qty'], 3), '0'), '.') ?>
                        </td>
                        <td class="text-end fw-bold" style="color:<?= $st_color ?>">
                            <?= rtrim(rtrim(number_format($total_qty, 3), '0'), '.') ?>
                        </td>
                        <td class="text-end text-muted small">
                            <?= $min_qty > 0 ? rtrim(rtrim(number_format($min_qty, 3), '0'), '.') : '—' ?>
                        </td>
                        <td class="text-end small"><?= format_money((float)$row['cost_price']) ?></td>
                        <td class="text-end fw-bold" style="color:var(--ts-gold)">
                            <?= format_money((float)$row['stock_value']) ?>
                        </td>
                        <td class="text-center">
                            <span class="badge rounded-pill"
                                  style="background:<?= $st_color ?>22;color:<?= $st_color ?>;border:1px solid <?= $st_color ?>44">
                                <?= $st_label ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="background:var(--ts-bg-dark)">
                            <td colspan="9" class="fw-bold text-end" style="color:var(--ts-gold)">
                                <?= $is_rtl ? 'إجمالي قيمة المخزون' : 'Total Stock Value' ?>
                            </td>
                            <td class="text-end fw-bold" style="color:var(--ts-success)">
                                <?= format_money(array_sum(array_column($filtered_products, 'stock_value'))) ?>
                            </td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ================================================== -->
    <!-- سجل حركات المخزون                                   -->
    <!-- ================================================== -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>
                <i class="bi bi-arrow-left-right me-1"></i>
                <?= $is_rtl ? 'سجل حركات المخزون' : 'Inventory Movements' ?>
                <small class="text-muted ms-2">(<?= $filter_date_from ?> → <?= $filter_date_to ?>)</small>
            </span>
            <small class="text-muted"><?= count($movements) ?> <?= $is_rtl ? 'حركة' : 'movements' ?></small>
        </div>
        <div class="card-body p-0">
            <?php if (empty($movements)): ?>
                <div class="text-center py-5 text-muted">
                    <i class="bi bi-inbox fs-1 d-block mb-2"></i><?= $lang['no_results'] ?>
                </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th><?= $lang['date'] ?></th>
                            <th><?= $lang['product_name'] ?></th>
                            <th><?= $lang['branch'] ?></th>
                            <th class="text-center"><?= $lang['movement_type'] ?></th>
                            <th class="text-end"><?= $lang['quantity'] ?></th>
                            <th class="text-end"><?= $lang['qty_before'] ?></th>
                            <th class="text-end"><?= $lang['qty_after'] ?></th>
                            <th><?= $lang['notes'] ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($movements as $mov):
                        $m_label = $movement_labels[$mov['movement_type']] ?? $mov['movement_type'];
                        $m_color = $movement_colors[$mov['movement_type']] ?? 'var(--ts-silver)';
                        $qty_sign = in_array($mov['movement_type'], ['sale','transfer_out'], true) ? '-' : '+';
                        $qty_color = ($qty_sign === '-') ? 'var(--ts-danger)' : 'var(--ts-success)';
                        $m_name = ($lang_code === 'en' && $mov['name_en']) ? $mov['name_en'] : $mov['name_ar'];
                    ?>
                    <tr>
                        <td>
                            <small class="font-monospace">
                                <?= date('Y-m-d', strtotime($mov['created_at'])) ?><br>
                                <span class="text-muted"><?= date('H:i', strtotime($mov['created_at'])) ?></span>
                            </small>
                        </td>
                        <td>
                            <span style="font-size:.88rem"><?= htmlspecialchars($m_name) ?></span>
                            <small class="text-muted d-block"><?= htmlspecialchars($mov['unit'] ?? '') ?></small>
                        </td>
                        <td><small class="text-muted"><?= htmlspecialchars($mov['branch_name'] ?? ($is_rtl ? 'مركزي' : 'Central')) ?></small></td>
                        <td class="text-center">
                            <span class="badge"
                                  style="background:<?= $m_color ?>22;color:<?= $m_color ?>;border:1px solid <?= $m_color ?>44">
                                <?= $m_label ?>
                            </span>
                        </td>
                        <td class="text-end fw-bold" style="color:<?= $qty_color ?>">
                            <?= $qty_sign ?><?= rtrim(rtrim(number_format(abs((float)$mov['quantity']), 3), '0'), '.') ?>
                        </td>
                        <td class="text-end text-muted small">
                            <?= rtrim(rtrim(number_format((float)$mov['quantity_before'], 3), '0'), '.') ?>
                        </td>
                        <td class="text-end" style="color:var(--ts-gold)">
                            <?= rtrim(rtrim(number_format((float)$mov['quantity_after'], 3), '0'), '.') ?>
                        </td>
                        <td><small class="text-muted"><?= htmlspecialchars($mov['notes'] ?? '—') ?></small></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

</div><!-- /.main-content -->

<style>
.main-content  { padding: 1.5rem; }
.font-monospace { font-family: monospace; }
.ts-input { background:var(--ts-bg-input) !important; border-color:var(--ts-border) !important; color:var(--ts-text-primary) !important; }
.ts-input:focus { border-color:var(--ts-gold) !important; box-shadow:0 0 0 .2rem rgba(201,168,76,.25) !important; }
@media print {
    .no-print, nav, .sidebar { display:none !important; }
    .main-content { padding:0 !important; }
    .card { border:1px solid #ccc !important; background:#fff !important; color:#000 !important; break-inside:avoid; }
    .card-header { background:#f5f5f5 !important; color:#000 !important; }
    table thead th { background:#eee !important; color:#000 !important; }
    .badge { border:1px solid #ccc !important; color:#000 !important; background:#f5f5f5 !important; }
}
</style>

<?php require_once __DIR__ . '/../includes/layout/footer.php'; ?>
