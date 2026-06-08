<?php
// ============================================================
// المسار: admin/inventory.php
// الوظيفة: عرض المخزون + تعديل يدوي + سجل الحركات
// الصلاحية: super_admin | branch_admin
// ============================================================

declare(strict_types=1);

require_once __DIR__ . '/../includes/layout/header.php';
require_role(['super_admin', 'branch_admin']);

$pdo      = db();
$audit    = new AuditLogger($pdo);
$role     = Auth::getRole();
$user_bid = Auth::getBranchId();   // null = super_admin
$is_super = ($role === 'super_admin');

// ================================================================
// معالجة POST — تعديل يدوي للمخزون
// ================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submitted_token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($submitted_token)) {
        flash('error', $lang['csrf_invalid']);
        redirect('inventory.php');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'adjust') {

        $product_id = sanitize_int($_POST['product_id'] ?? 0);
        $new_qty    = (float) filter_var($_POST['new_quantity'] ?? -1, FILTER_VALIDATE_FLOAT);
        $reason     = trim($_POST['reason'] ?? '');

        // الفرع: super_admin يختار، branch_admin محدد بفرعه
        if ($is_super) {
            $raw_bid = $_POST['branch_id'] ?? '';
            $adj_bid = ($raw_bid === '' || $raw_bid === 'null') ? null : sanitize_int($raw_bid);
        } else {
            $adj_bid = $user_bid;
        }

        if ($product_id <= 0 || $new_qty < 0) {
            flash('error', $lang['required_field']);
            redirect('inventory.php');
        }

        try {
            // ——— جلب الكمية الحالية ———
            if ($adj_bid === null) {
                $sel = $pdo->prepare('
                    SELECT id, quantity FROM inventory
                    WHERE product_id = ? AND branch_id IS NULL
                    LIMIT 1
                ');
                $sel->execute([$product_id]);
            } else {
                $sel = $pdo->prepare('
                    SELECT id, quantity FROM inventory
                    WHERE product_id = ? AND branch_id = ?
                    LIMIT 1
                ');
                $sel->execute([$product_id, $adj_bid]);
            }
            $row        = $sel->fetch();
            $qty_before = (float) ($row['quantity'] ?? 0);
            $diff       = $new_qty - $qty_before;

            // ——— UPDATE أو INSERT ———
            if ($row) {
                if ($adj_bid === null) {
                    $upd = $pdo->prepare('
                        UPDATE inventory
                        SET quantity = ?, updated_at = NOW()
                        WHERE product_id = ? AND branch_id IS NULL
                    ');
                    $upd->execute([$new_qty, $product_id]);
                } else {
                    $upd = $pdo->prepare('
                        UPDATE inventory
                        SET quantity = ?, updated_at = NOW()
                        WHERE product_id = ? AND branch_id = ?
                    ');
                    $upd->execute([$new_qty, $product_id, $adj_bid]);
                }
            } else {
                $ins = $pdo->prepare('
                    INSERT INTO inventory
                        (product_id, branch_id, quantity, min_quantity, updated_at)
                    VALUES (?, ?, ?, 0, NOW())
                ');
                $ins->execute([$product_id, $adj_bid, $new_qty]);
            }

            // ——— سجّل حركة المخزون ———
            $reason_text = $reason ?: ($is_rtl ? 'تعديل يدوي' : 'Manual adjustment');
            log_inventory_movement(
                $pdo, $product_id, $adj_bid,
                'adjustment', $diff, $qty_before, $new_qty,
                null, null, $reason_text
            );

            // ——— Audit ———
            $audit->stock_adjusted($product_id, $adj_bid, $qty_before, $new_qty, $reason_text);

            flash('success', $lang['updated']);

        } catch (\Throwable $e) {
            error_log('[inventory.adjust] ' . $e->getMessage());
            flash('error', $lang['error']);
        }

        redirect('inventory.php' . ($_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : ''));
    }
}

// ================================================================
// فلاتر العرض
// ================================================================
$search       = trim($_GET['search']   ?? '');
$cat_filter   = sanitize_int($_GET['category'] ?? 0);
$stock_filter = in_array($_GET['stock'] ?? '', ['low', 'out', 'ok'], true)
              ? $_GET['stock']
              : '';

// الفرع المعروض: super_admin يختار، branch_admin محدد بفرعه
if ($is_super) {
    $raw_vb      = $_GET['branch_id'] ?? '';
    $view_branch = ($raw_vb === '' || $raw_vb === 'null') ? null : sanitize_int($raw_vb);
} else {
    $view_branch = $user_bid;
}

// ——— جلب الفروع (super_admin فقط) ———
$branches = [];
if ($is_super) {
    $br_stmt  = $pdo->query("SELECT id, name, code FROM branches WHERE status = 'active' ORDER BY name");
    $branches = $br_stmt->fetchAll();
}

// ——— جلب التصنيفات ———
$cats_stmt = $pdo->query("SELECT id, name_ar, name_en FROM categories WHERE status = 'active' ORDER BY name_ar");
$cats      = $cats_stmt->fetchAll();

// ================================================================
// استعلام المخزون — آمن بالكامل (prepared statements فقط)
// ================================================================
$where  = ["p.status = 'active'"];
$params = [];

if ($search !== '') {
    $where[] = '(p.name_ar LIKE ? OR p.name_en LIKE ? OR p.barcode LIKE ?)';
    $s       = '%' . $search . '%';
    array_push($params, $s, $s, $s);
}
if ($cat_filter > 0) {
    $where[]  = 'p.category_id = ?';
    $params[] = $cat_filter;
}

$where_sql = implode(' AND ', $where);

if ($view_branch !== null) {
    $inv_sql = "
        SELECT
            p.id, p.name_ar, p.name_en, p.barcode, p.unit,
            p.cost_price, p.selling_price,
            c.name_ar AS cat_name_ar,
            COALESCE((
                SELECT inv.quantity
                FROM inventory inv
                WHERE inv.product_id = p.id AND inv.branch_id = ?
                LIMIT 1
            ), 0) AS branch_qty,
            COALESCE((
                SELECT inv.min_quantity
                FROM inventory inv
                WHERE inv.product_id = p.id AND inv.branch_id = ?
                LIMIT 1
            ), 0) AS min_qty,
            COALESCE((
                SELECT inv.quantity
                FROM inventory inv
                WHERE inv.product_id = p.id AND inv.branch_id IS NULL
                LIMIT 1
            ), 0) AS central_qty
        FROM products p
        LEFT JOIN categories c ON c.id = p.category_id
        WHERE {$where_sql}
        ORDER BY p.name_ar
    ";
    $inv_params = array_merge([$view_branch, $view_branch], $params);
} else {
    // عرض المركزي (branch_id IS NULL)
    $inv_sql = "
        SELECT
            p.id, p.name_ar, p.name_en, p.barcode, p.unit,
            p.cost_price, p.selling_price,
            c.name_ar AS cat_name_ar,
            COALESCE((
                SELECT inv.quantity
                FROM inventory inv
                WHERE inv.product_id = p.id AND inv.branch_id IS NULL
                LIMIT 1
            ), 0) AS branch_qty,
            COALESCE((
                SELECT inv.min_quantity
                FROM inventory inv
                WHERE inv.product_id = p.id AND inv.branch_id IS NULL
                LIMIT 1
            ), 0) AS min_qty,
            0.000 AS central_qty
        FROM products p
        LEFT JOIN categories c ON c.id = p.category_id
        WHERE {$where_sql}
        ORDER BY p.name_ar
    ";
    $inv_params = $params;
}

$inv_stmt     = $pdo->prepare($inv_sql);
$inv_stmt->execute($inv_params);
$products_raw = $inv_stmt->fetchAll();

// تطبيق فلتر حالة المخزون بعد الجلب
$products = array_values(array_filter($products_raw, static function (array $p) use ($stock_filter): bool {
    if ($stock_filter === '') return true;
    $qty    = (float) $p['branch_qty'];
    $minqty = (float) $p['min_qty'];
    return match ($stock_filter) {
        'out'   => $qty <= 0,
        'low'   => $qty > 0 && $minqty > 0 && $qty <= $minqty,
        'ok'    => $minqty <= 0 || $qty > $minqty,
        default => true,
    };
}));

// إجمالي قيمة المخزون المعروض
$total_stock_value = array_sum(array_map(
    static fn (array $p): float => (float) $p['branch_qty'] * (float) $p['cost_price'],
    $products
));

// ================================================================
// آخر 50 حركة مخزون
// ================================================================
$mov_where  = ['1=1'];
$mov_params = [];

if (!$is_super) {
    $mov_where[]  = '(m.branch_id = ? OR m.branch_id IS NULL)';
    $mov_params[] = $user_bid;
} elseif ($view_branch !== null) {
    $mov_where[]  = '(m.branch_id = ? OR m.branch_id IS NULL)';
    $mov_params[] = $view_branch;
}

$mov_stmt = $pdo->prepare('
    SELECT
        m.movement_type, m.quantity, m.quantity_before, m.quantity_after,
        m.reference_type, m.notes, m.created_at,
        p.name_ar AS product_name,
        b.name    AS branch_name,
        u.name    AS created_by_name
    FROM inventory_movements m
    LEFT JOIN products p ON p.id = m.product_id
    LEFT JOIN branches b ON b.id = m.branch_id
    LEFT JOIN users    u ON u.id = m.created_by
    WHERE ' . implode(' AND ', $mov_where) . '
    ORDER BY m.id DESC
    LIMIT 50
');
$mov_stmt->execute($mov_params);
$movements = $mov_stmt->fetchAll();

// خريطة أنواع الحركات
$mov_labels = [
    'purchase'     => ['label' => $is_rtl ? 'شراء'       : 'Purchase',     'class' => 'text-success'],
    'sale'         => ['label' => $is_rtl ? 'بيع'         : 'Sale',         'class' => 'text-danger'],
    'transfer_in'  => ['label' => $is_rtl ? 'تحويل وارد' : 'Transfer In',  'class' => 'text-info'],
    'transfer_out' => ['label' => $is_rtl ? 'تحويل صادر' : 'Transfer Out', 'class' => 'text-warning'],
    'return_in'    => ['label' => $is_rtl ? 'مرتجع'       : 'Return',       'class' => 'text-primary'],
    'adjustment'   => ['label' => $is_rtl ? 'تعديل يدوي' : 'Adjustment',   'class' => 'text-secondary'],
];

$csrf_token = generate_csrf_token();

// اسم الفرع المعروض حالياً (للـ header)
$viewed_branch_name = '';
if ($view_branch !== null && $is_super) {
    foreach ($branches as $br) {
        if ((int) $br['id'] === $view_branch) {
            $viewed_branch_name = $br['name'];
            break;
        }
    }
} elseif ($view_branch !== null && !$is_super) {
    $br_name_stmt = $pdo->prepare('SELECT name FROM branches WHERE id = ? LIMIT 1');
    $br_name_stmt->execute([$view_branch]);
    $viewed_branch_name = (string) ($br_name_stmt->fetchColumn() ?: '');
}
?>

<?php include __DIR__ . '/../includes/layout/sidebar.php'; ?>

<div class="main-content">

    <?php show_flash(); ?>

    <!-- ============================================================
         رأس الصفحة
         ============================================================ -->
    <div class="page-header d-flex align-items-center justify-content-between mb-3">
        <div>
            <h4 class="page-title mb-0">
                <i class="bi bi-boxes me-2" style="color:var(--ts-gold);"></i>
                <?= $lang['inventory'] ?>
                <?php if ($viewed_branch_name !== ''): ?>
                    <span style="color:var(--ts-silver); font-size:.85rem; font-weight:400; margin-inline-start:.5rem;">
                        — <?= htmlspecialchars($viewed_branch_name, ENT_QUOTES, 'UTF-8') ?>
                    </span>
                <?php elseif ($view_branch === null && $is_super): ?>
                    <span style="color:var(--ts-silver); font-size:.85rem; font-weight:400; margin-inline-start:.5rem;">
                        — <?= $is_rtl ? 'المركزي' : 'Central' ?>
                    </span>
                <?php endif; ?>
            </h4>
            <small style="color:var(--ts-text-muted);">
                <?= count($products) ?> <?= $is_rtl ? 'منتج' : 'products' ?>
                &nbsp;|&nbsp;
                <?= $is_rtl ? 'قيمة المخزون:' : 'Stock Value:' ?>
                <strong style="color:var(--ts-gold);"><?= format_money($total_stock_value) ?></strong>
            </small>
        </div>
        <a href="inventory_alerts.php" class="btn btn-sm btn-outline-warning">
            <i class="bi bi-bell-fill me-1"></i>
            <?= $lang['low_stock_alerts'] ?>
        </a>
    </div>

    <!-- ============================================================
         شريط الفلترة
         ============================================================ -->
    <div class="card ts-card mb-3">
        <div class="card-body py-2">
            <form method="GET" class="row g-2 align-items-center">

                <div class="col-12 col-md-3">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text ts-ig-text">
                            <i class="bi bi-search"></i>
                        </span>
                        <input type="text" name="search" class="form-control ts-input"
                               value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>"
                               placeholder="<?= $lang['search'] ?>...">
                    </div>
                </div>

                <div class="col-6 col-md-2">
                    <select name="category" class="form-select form-select-sm ts-input">
                        <option value="0"><?= $lang['category'] ?></option>
                        <?php foreach ($cats as $c): ?>
                            <option value="<?= (int) $c['id'] ?>"
                                <?= $cat_filter === (int) $c['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars(
                                    $lang_code === 'ar' ? $c['name_ar'] : ($c['name_en'] ?: $c['name_ar']),
                                    ENT_QUOTES, 'UTF-8'
                                ) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-6 col-md-2">
                    <select name="stock" class="form-select form-select-sm ts-input">
                        <option value=""><?= $is_rtl ? 'حالة المخزون' : 'Stock Status' ?></option>
                        <option value="out" <?= $stock_filter === 'out' ? 'selected' : '' ?>><?= $lang['stock_out'] ?></option>
                        <option value="low" <?= $stock_filter === 'low' ? 'selected' : '' ?>><?= $lang['stock_low'] ?></option>
                        <option value="ok"  <?= $stock_filter === 'ok'  ? 'selected' : '' ?>><?= $lang['stock_ok'] ?></option>
                    </select>
                </div>

                <?php if ($is_super): ?>
                <div class="col-6 col-md-2">
                    <select name="branch_id" class="form-select form-select-sm ts-input">
                        <option value="null"><?= $is_rtl ? 'المركزي' : 'Central' ?></option>
                        <?php foreach ($branches as $br): ?>
                            <option value="<?= (int) $br['id'] ?>"
                                <?= $view_branch === (int) $br['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($br['name'], ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <div class="col-12 col-md-<?= $is_super ? '3' : '5' ?> d-flex gap-2">
                    <button type="submit" class="btn btn-primary btn-sm flex-fill">
                        <i class="bi bi-funnel me-1"></i><?= $lang['filter'] ?>
                    </button>
                    <a href="inventory.php" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-x-lg"></i>
                    </a>
                </div>

            </form>
        </div>
    </div>

    <!-- ============================================================
         جدول المخزون
         ============================================================ -->
    <div class="card ts-card mb-4">
        <div class="card-header d-flex align-items-center justify-content-between">
            <span style="color:var(--ts-gold); font-weight:700;">
                <i class="bi bi-table me-1"></i>
                <?= $is_rtl ? 'المخزون الحالي' : 'Current Stock' ?>
            </span>
            <div class="d-flex gap-3" style="font-size:.78rem;">
                <span><span class="badge bg-success">&nbsp;</span> <?= $lang['stock_ok'] ?></span>
                <span><span class="badge bg-warning text-dark">&nbsp;</span> <?= $lang['stock_low'] ?></span>
                <span><span class="badge bg-danger">&nbsp;</span> <?= $lang['stock_out'] ?></span>
            </div>
        </div>
        <div class="card-body p-0">
            <?php if (empty($products)): ?>
                <div class="text-center py-5" style="color:var(--ts-text-muted);">
                    <i class="bi bi-boxes"
                       style="font-size:2.5rem; opacity:.3; display:block; margin-bottom:.75rem;"></i>
                    <?= $lang['no_results'] ?>
                </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table ts-table mb-0">
                    <thead>
                        <tr>
                            <th><?= $lang['product_name'] ?></th>
                            <th><?= $lang['category'] ?></th>
                            <th><?= $lang['unit'] ?></th>
                            <th class="text-center"><?= $lang['branch_stock'] ?></th>
                            <?php if ($is_super && $view_branch !== null): ?>
                            <th class="text-center"><?= $lang['central_stock'] ?></th>
                            <?php endif; ?>
                            <th class="text-center"><?= $lang['min_quantity'] ?></th>
                            <th class="text-center"><?= $is_rtl ? 'الحالة' : 'Status' ?></th>
                            <th class="text-center"><?= $is_rtl ? 'قيمة المخزون' : 'Stock Value' ?></th>
                            <th class="text-center"><?= $is_rtl ? 'تعديل' : 'Adjust' ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($products as $prod):
                        $bqty  = (float) $prod['branch_qty'];
                        $cqty  = (float) $prod['central_qty'];
                        $miqty = (float) $prod['min_qty'];
                        $name  = $lang_code === 'ar'
                               ? $prod['name_ar']
                               : ($prod['name_en'] ?: $prod['name_ar']);

                        if ($bqty <= 0) {
                            $badge     = '<span class="badge bg-danger">' . $lang['stock_out'] . '</span>';
                            $qty_color = 'var(--ts-danger)';
                        } elseif ($miqty > 0 && $bqty <= $miqty) {
                            $badge     = '<span class="badge bg-warning text-dark">' . $lang['stock_low'] . '</span>';
                            $qty_color = '#ffc107';
                        } else {
                            $badge     = '<span class="badge bg-success">' . $lang['stock_ok'] . '</span>';
                            $qty_color = 'var(--ts-success)';
                        }

                        $stock_val = $bqty * (float) $prod['cost_price'];
                    ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?></strong>
                                <?php if ($prod['barcode']): ?>
                                    <div>
                                        <code style="font-size:.72rem; color:var(--ts-text-muted);">
                                            <?= htmlspecialchars($prod['barcode'], ENT_QUOTES, 'UTF-8') ?>
                                        </code>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td style="color:var(--ts-text-secondary); font-size:.85rem;">
                                <?= htmlspecialchars($prod['cat_name_ar'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <td style="color:var(--ts-text-muted);">
                                <?= htmlspecialchars($prod['unit'], ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <td class="text-center">
                                <strong style="color:<?= $qty_color ?>; font-size:1.05rem;">
                                    <?= format_qty($bqty) ?>
                                </strong>
                            </td>
                            <?php if ($is_super && $view_branch !== null): ?>
                            <td class="text-center" style="color:var(--ts-silver);">
                                <?= format_qty($cqty) ?>
                            </td>
                            <?php endif; ?>
                            <td class="text-center" style="color:var(--ts-text-muted); font-size:.85rem;">
                                <?= $miqty > 0 ? format_qty($miqty) : '—' ?>
                            </td>
                            <td class="text-center"><?= $badge ?></td>
                            <td class="text-center" style="font-size:.85rem; color:var(--ts-text-secondary);">
                                <?= format_money($stock_val) ?>
                            </td>
                            <td class="text-center">
                                <button class="btn btn-outline-secondary btn-xs btn-adjust"
                                        title="<?= $is_rtl ? 'تعديل يدوي' : 'Manual Adjust' ?>"
                                        data-product_id="<?= (int) $prod['id'] ?>"
                                        data-product_name="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>"
                                        data-current_qty="<?= $bqty ?>"
                                        data-branch_id="<?= $view_branch ?? 'null' ?>">
                                    <i class="bi bi-pencil-square"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="background:var(--ts-bg-dark);">
                            <td colspan="<?= ($is_super && $view_branch !== null) ? 7 : 6 ?>"
                                class="text-end pe-3"
                                style="color:var(--ts-gold); font-weight:600;">
                                <?= $is_rtl ? 'إجمالي قيمة المخزون المعروض:' : 'Total Displayed Stock Value:' ?>
                            </td>
                            <td class="text-center" style="color:var(--ts-gold); font-weight:700;">
                                <?= format_money($total_stock_value) ?>
                            </td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ============================================================
         سجل الحركات — آخر 50
         ============================================================ -->
    <div class="card ts-card">
        <div class="card-header">
            <span style="color:var(--ts-gold); font-weight:700;">
                <i class="bi bi-clock-history me-1"></i>
                <?= $is_rtl ? 'سجل حركات المخزون — آخر 50 حركة' : 'Stock Movement Log — Last 50' ?>
            </span>
        </div>
        <div class="card-body p-0">
            <?php if (empty($movements)): ?>
                <div class="text-center py-4" style="color:var(--ts-text-muted);">
                    <?= $lang['no_results'] ?>
                </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table ts-table mb-0" style="font-size:.82rem;">
                    <thead>
                        <tr>
                            <th><?= $lang['date'] ?></th>
                            <th><?= $lang['product_name'] ?></th>
                            <th><?= $is_rtl ? 'الفرع' : 'Branch' ?></th>
                            <th><?= $lang['movement_type'] ?></th>
                            <th class="text-center"><?= $lang['quantity'] ?></th>
                            <th class="text-center"><?= $lang['qty_before'] ?></th>
                            <th class="text-center"><?= $lang['qty_after'] ?></th>
                            <th><?= $is_rtl ? 'المنفّذ / ملاحظات' : 'By / Notes' ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($movements as $mov):
                        $mtype   = $mov_labels[$mov['movement_type']] ?? ['label' => $mov['movement_type'], 'class' => 'text-muted'];
                        $qty_val = (float) $mov['quantity'];
                        $qty_sign = $qty_val >= 0 ? '+' : '';
                    ?>
                        <tr>
                            <td style="white-space:nowrap; color:var(--ts-text-muted);">
                                <?= htmlspecialchars(format_datetime($mov['created_at']), ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <td><?= htmlspecialchars($mov['product_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                            <td style="color:var(--ts-text-secondary);">
                                <?= htmlspecialchars(
                                    $mov['branch_name'] ?? ($is_rtl ? 'مركزي' : 'Central'),
                                    ENT_QUOTES, 'UTF-8'
                                ) ?>
                            </td>
                            <td>
                                <span class="<?= $mtype['class'] ?>"><?= $mtype['label'] ?></span>
                            </td>
                            <td class="text-center">
                                <strong class="<?= $qty_val >= 0 ? 'text-success' : 'text-danger' ?>">
                                    <?= $qty_sign . format_qty($qty_val) ?>
                                </strong>
                            </td>
                            <td class="text-center" style="color:var(--ts-text-muted);">
                                <?= format_qty((float) $mov['quantity_before']) ?>
                            </td>
                            <td class="text-center" style="color:var(--ts-text-muted);">
                                <?= format_qty((float) $mov['quantity_after']) ?>
                            </td>
                            <td style="color:var(--ts-text-muted); max-width:180px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                                <?php if ($mov['created_by_name']): ?>
                                    <span style="color:var(--ts-text-secondary);">
                                        <?= htmlspecialchars($mov['created_by_name'], ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                    <?php if ($mov['notes']): ?>
                                        &nbsp;·&nbsp;
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?= htmlspecialchars($mov['notes'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

</div><!-- /.main-content -->

<!-- ============================================================
     Modal — التعديل اليدوي للمخزون
     ============================================================ -->
<div class="modal fade" id="modalAdjust" tabindex="-1" aria-labelledby="modalAdjustLabel" aria-hidden="true">
    <div class="modal-dialog modal-sm">
        <div class="modal-content ts-modal">
            <div class="modal-header">
                <h5 class="modal-title" id="modalAdjustLabel">
                    <i class="bi bi-sliders me-2" style="color:var(--ts-gold);"></i>
                    <?= $is_rtl ? 'تعديل المخزون' : 'Adjust Stock' ?>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" id="formAdjust">
                <input type="hidden" name="action"     value="adjust">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="product_id" id="adjProductId">
                <input type="hidden" name="branch_id"  id="adjBranchId">

                <div class="modal-body">
                    <p class="mb-1" style="font-size:.8rem; color:var(--ts-text-muted);">
                        <?= $is_rtl ? 'المنتج' : 'Product' ?>
                    </p>
                    <p class="mb-3" style="color:var(--ts-gold); font-weight:600; font-size:1rem;"
                       id="adjProductName"></p>

                    <div class="mb-3">
                        <label class="form-label" style="font-size:.8rem; color:var(--ts-text-muted);">
                            <?= $is_rtl ? 'الكمية الحالية' : 'Current Quantity' ?>
                        </label>
                        <div class="form-control ts-input" id="adjCurrentQty"
                             style="color:var(--ts-gold); font-weight:700; pointer-events:none; cursor:default;">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="adjNewQty">
                            <?= $is_rtl ? 'الكمية الجديدة' : 'New Quantity' ?>
                            <span class="text-danger">*</span>
                        </label>
                        <input type="number" name="new_quantity" id="adjNewQty"
                               class="form-control ts-input"
                               min="0" step="0.001" required>
                        <div id="adjDiff" class="form-text mt-1" style="font-weight:600;"></div>
                    </div>

                    <div class="mb-1">
                        <label class="form-label" for="adjReason">
                            <?= $lang['adjustment_reason'] ?>
                        </label>
                        <input type="text" name="reason" id="adjReason"
                               class="form-control ts-input"
                               placeholder="<?= $is_rtl ? 'مثال: جرد، كسر، فقدان...' : 'e.g. inventory count, damage...' ?>">
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">
                        <?= $lang['cancel'] ?>
                    </button>
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-check-lg me-1"></i><?= $lang['save'] ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/layout/footer.php'; ?>

<script>
(function () {
    'use strict';

    // فتح Modal التعديل
    document.querySelectorAll('.btn-adjust').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var currentQty = parseFloat(this.dataset.current_qty);

            document.getElementById('adjProductId').value        = this.dataset.product_id;
            document.getElementById('adjBranchId').value         = this.dataset.branch_id === 'null' ? '' : this.dataset.branch_id;
            document.getElementById('adjProductName').textContent = this.dataset.product_name;
            document.getElementById('adjCurrentQty').textContent  = currentQty;
            document.getElementById('adjNewQty').value            = currentQty;
            document.getElementById('adjDiff').textContent        = '';
            document.getElementById('adjReason').value            = '';

            new bootstrap.Modal(document.getElementById('modalAdjust')).show();

            setTimeout(function () {
                var el = document.getElementById('adjNewQty');
                el.focus();
                el.select();
            }, 400);
        });
    });

    // عرض الفرق تلقائياً
    document.getElementById('adjNewQty').addEventListener('input', function () {
        var currentEl = document.getElementById('adjCurrentQty');
        var diffEl    = document.getElementById('adjDiff');
        var current   = parseFloat(currentEl.textContent) || 0;
        var newVal    = parseFloat(this.value) || 0;
        var diff      = newVal - current;

        if (diff === 0) {
            diffEl.textContent = '';
            diffEl.style.color = '';
        } else if (diff > 0) {
            diffEl.textContent = '+ ' + diff.toFixed(3).replace(/\.?0+$/, '') +
                                  ' <?= $is_rtl ? "(زيادة)" : "(increase)" ?>';
            diffEl.style.color = 'var(--ts-success)';
        } else {
            diffEl.textContent = diff.toFixed(3).replace(/\.?0+$/, '') +
                                  ' <?= $is_rtl ? "(نقص)" : "(decrease)" ?>';
            diffEl.style.color = 'var(--ts-danger)';
        }
    });

    // حفظ query string في الـ form action
    var qs = window.location.search;
    if (qs) {
        document.getElementById('formAdjust').action = 'inventory.php' + qs;
    }
})();
</script>
