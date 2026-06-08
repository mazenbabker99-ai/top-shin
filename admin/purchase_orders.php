<?php
// ============================================================
// المسار: admin/purchase_orders.php
// الوظيفة: إنشاء وإدارة أوامر الشراء — قائمة + إنشاء + تأكيد + إلغاء
// الصلاحية: super_admin | branch_admin
// ============================================================

declare(strict_types=1);

require_once __DIR__ . '/../includes/layout/header.php';
require_role(['super_admin', 'branch_admin']);

$pdo      = db();
$audit    = new AuditLogger($pdo);
$role     = Auth::getRole();
$user_bid = Auth::getBranchId();
$is_super = ($role === 'super_admin');

// ============================================================
// معالجة POST
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submitted_token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($submitted_token)) {
        flash('error', $lang['csrf_invalid']);
        redirect('purchase_orders.php');
    }

    $action = $_POST['action'] ?? '';

    // ——— إنشاء أمر شراء ———
    if ($action === 'create_po') {

        $supplier_id = sanitize_int($_POST['supplier_id'] ?? 0);
        $branch_id   = $is_super
            ? (sanitize_int($_POST['branch_id'] ?? 0) ?: null)
            : $user_bid;
        $status_req  = $_POST['po_status'] ?? 'draft';
        $status      = in_array($status_req, ['draft', 'confirmed'], true) ? $status_req : 'draft';
        $notes       = trim($_POST['notes'] ?? '');

        $items_raw   = $_POST['items'] ?? [];

        if ($supplier_id <= 0 || empty($items_raw)) {
            flash('error', $lang['required_field']);
            redirect('purchase_orders.php?action=new');
        }

        // بناء البنود + احتساب الإجمالي
        $items = [];
        $total = 0.0;
        foreach ($items_raw as $item) {
            $pid   = sanitize_int($item['product_id'] ?? 0);
            $qty   = (float) filter_var($item['quantity']   ?? 0, FILTER_VALIDATE_FLOAT);
            $cost  = (float) filter_var($item['unit_cost']  ?? 0, FILTER_VALIDATE_FLOAT);
            $pname = trim($item['product_name'] ?? '');

            if ($pid <= 0 || $qty <= 0 || $cost <= 0) continue;

            $item_total  = round($qty * $cost, 2);
            $total      += $item_total;
            $items[]     = compact('pid', 'pname', 'qty', 'cost', 'item_total');
        }

        if (empty($items)) {
            flash('error', $lang['required_field']);
            redirect('purchase_orders.php?action=new');
        }

        try {
            $pdo->beginTransaction();

            $po_number = generate_po_number($pdo);

            $ins = $pdo->prepare('
                INSERT INTO purchase_orders
                    (po_number, supplier_id, branch_id, total_amount, paid_amount, status, notes, created_by, created_at)
                VALUES (?, ?, ?, ?, 0, ?, ?, ?, NOW())
            ');
            $ins->execute([$po_number, $supplier_id, $branch_id, $total, $status, $notes, $_SESSION['user_id'] ?? 0]);
            $po_id = (int) $pdo->lastInsertId();

            $ins_item = $pdo->prepare('
                INSERT INTO purchase_order_items
                    (po_id, product_id, product_name, quantity, quantity_received, unit_cost, total)
                VALUES (?, ?, ?, ?, 0, ?, ?)
            ');
            foreach ($items as $it) {
                $ins_item->execute([$po_id, $it['pid'], $it['pname'], $it['qty'], $it['cost'], $it['item_total']]);
            }

            // تحديث رصيد المورد إذا كان مؤكداً
            if ($status === 'confirmed') {
                $pdo->prepare('UPDATE suppliers SET balance = balance + ? WHERE id=?')
                    ->execute([$total, $supplier_id]);
            }

            $pdo->commit();

            $audit->created('purchase_orders', $po_id, [
                'po_number'   => $po_number,
                'supplier_id' => $supplier_id,
                'branch_id'   => $branch_id,
                'total'       => $total,
                'status'      => $status,
            ]);

            flash('success', $lang['saved_successfully']);
            redirect('purchase_orders.php?view=' . $po_id);

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[TopShine] create_po: ' . $e->getMessage());
            flash('error', $lang['error_occurred']);
            redirect('purchase_orders.php?action=new');
        }
    }

    // ——— تأكيد أمر شراء ———
    if ($action === 'confirm_po') {
        $po_id = sanitize_int($_POST['po_id'] ?? 0);
        if ($po_id <= 0) { redirect('purchase_orders.php'); }

        try {
            $po = $pdo->prepare('SELECT * FROM purchase_orders WHERE id=? LIMIT 1');
            $po->execute([$po_id]);
            $po_row = $po->fetch();

            if (!$po_row || $po_row['status'] !== 'draft') {
                flash('error', $lang['error_occurred']);
                redirect('purchase_orders.php');
            }

            // تحقق من صلاحية الفرع
            if (!$is_super && $po_row['branch_id'] !== $user_bid) {
                render_403('branch');
            }

            $pdo->beginTransaction();

            $pdo->prepare('UPDATE purchase_orders SET status=\'confirmed\' WHERE id=?')
                ->execute([$po_id]);

            // إضافة إجمالي الـ PO لرصيد المورد
            $pdo->prepare('UPDATE suppliers SET balance = balance + ? WHERE id=?')
                ->execute([$po_row['total_amount'], $po_row['supplier_id']]);

            $pdo->commit();

            $audit->updated('purchase_orders', $po_id,
                ['status' => 'draft'],
                ['status' => 'confirmed']
            );
            flash('success', $lang['saved_successfully']);

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[TopShine] confirm_po: ' . $e->getMessage());
            flash('error', $lang['error_occurred']);
        }
        redirect('purchase_orders.php?view=' . $po_id);
    }

    // ——— إلغاء أمر شراء ———
    if ($action === 'cancel_po') {
        $po_id = sanitize_int($_POST['po_id'] ?? 0);
        if ($po_id <= 0) { redirect('purchase_orders.php'); }

        try {
            $po = $pdo->prepare('SELECT * FROM purchase_orders WHERE id=? LIMIT 1');
            $po->execute([$po_id]);
            $po_row = $po->fetch();

            if (!$po_row || !in_array($po_row['status'], ['draft', 'confirmed'], true)) {
                flash('error', $lang['error_occurred']);
                redirect('purchase_orders.php');
            }

            if (!$is_super && $po_row['branch_id'] !== $user_bid) {
                render_403('branch');
            }

            $pdo->beginTransaction();

            $pdo->prepare('UPDATE purchase_orders SET status=\'cancelled\' WHERE id=?')
                ->execute([$po_id]);

            // إذا كان مؤكداً نُزيل الرصيد من المورد
            if ($po_row['status'] === 'confirmed') {
                $pdo->prepare('UPDATE suppliers SET balance = GREATEST(0, balance - ?) WHERE id=?')
                    ->execute([$po_row['total_amount'], $po_row['supplier_id']]);
            }

            $pdo->commit();

            $audit->updated('purchase_orders', $po_id,
                ['status' => $po_row['status']],
                ['status' => 'cancelled']
            );
            flash('success', $lang['saved_successfully']);

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[TopShine] cancel_po: ' . $e->getMessage());
            flash('error', $lang['error_occurred']);
        }
        redirect('purchase_orders.php');
    }
}

// ============================================================
// تحديد وضع الصفحة
// ============================================================
$view_mode   = $_GET['action'] ?? '';   // 'new' = نموذج إنشاء
$view_po_id  = sanitize_int($_GET['view'] ?? 0);

// ============================================================
// جلب تفاصيل أمر شراء للعرض
// ============================================================
$po_detail       = null;
$po_detail_items = [];

if ($view_po_id > 0) {
    $d = $pdo->prepare('
        SELECT po.*,
               s.name   AS supplier_name,
               s.phone  AS supplier_phone,
               b.name   AS branch_name,
               u.name   AS created_by_name
        FROM purchase_orders po
        LEFT JOIN suppliers  s ON s.id = po.supplier_id
        LEFT JOIN branches   b ON b.id = po.branch_id
        LEFT JOIN users      u ON u.id = po.created_by
        WHERE po.id = ?
        LIMIT 1
    ');
    $d->execute([$view_po_id]);
    $po_detail = $d->fetch();

    if ($po_detail) {
        if (!$is_super && $po_detail['branch_id'] !== $user_bid) {
            render_403('branch');
        }

        $di = $pdo->prepare('
            SELECT poi.*,
                   p.name_ar, p.name_en, p.unit, p.barcode
            FROM purchase_order_items poi
            LEFT JOIN products p ON p.id = poi.product_id
            WHERE poi.po_id = ?
            ORDER BY poi.id ASC
        ');
        $di->execute([$view_po_id]);
        $po_detail_items = $di->fetchAll();
    } else {
        $view_po_id = 0;
    }
}

// ============================================================
// قائمة أوامر الشراء
// ============================================================
// فلاتر
$f_status     = $_GET['status']      ?? 'all';
$f_supplier   = sanitize_int($_GET['supplier'] ?? 0);
$f_date_from  = $_GET['date_from']   ?? '';
$f_date_to    = $_GET['date_to']     ?? '';
$f_branch     = $is_super ? sanitize_int($_GET['branch'] ?? 0) : (int) $user_bid;

$where_parts = ['1=1'];
$params      = [];

if ($f_status !== 'all') {
    $where_parts[] = 'po.status = ?';
    $params[]      = $f_status;
}
if ($f_supplier > 0) {
    $where_parts[] = 'po.supplier_id = ?';
    $params[]      = $f_supplier;
}
if (!$is_super && $user_bid !== null) {
    $where_parts[] = '(po.branch_id = ? OR po.branch_id IS NULL)';
    $params[]      = $user_bid;
} elseif ($is_super && $f_branch > 0) {
    $where_parts[] = 'po.branch_id = ?';
    $params[]      = $f_branch;
}
if ($f_date_from !== '') {
    $where_parts[] = 'DATE(po.created_at) >= ?';
    $params[]      = $f_date_from;
}
if ($f_date_to !== '') {
    $where_parts[] = 'DATE(po.created_at) <= ?';
    $params[]      = $f_date_to;
}

$where = implode(' AND ', $where_parts);

$per_page = 25;
$pg       = paginate($pdo, "SELECT COUNT(*) FROM purchase_orders po WHERE {$where}", $params, (int)($_GET['page'] ?? 1), $per_page);

$list_stmt = $pdo->prepare("
    SELECT po.*,
           s.name  AS supplier_name,
           b.name  AS branch_name,
           (SELECT COUNT(*) FROM purchase_order_items WHERE po_id = po.id) AS items_count
    FROM purchase_orders po
    LEFT JOIN suppliers s ON s.id = po.supplier_id
    LEFT JOIN branches  b ON b.id = po.branch_id
    WHERE {$where}
    ORDER BY po.created_at DESC
    LIMIT {$pg['per_page']} OFFSET {$pg['offset']}
");
$list_stmt->execute($params);
$po_list = $list_stmt->fetchAll();

// ——— بيانات مساعدة للـ filters ———
$all_suppliers = $pdo->query('SELECT id, name FROM suppliers WHERE status=\'active\' ORDER BY name ASC')->fetchAll();
$all_branches  = $is_super
    ? $pdo->query('SELECT id, name FROM branches WHERE status=\'active\' ORDER BY name ASC')->fetchAll()
    : [];

// ——— بيانات لنموذج الإنشاء ———
$form_suppliers = $all_suppliers;
$form_branches  = $all_branches;

// ============================================================
$page_title   = $lang['purchase_orders'];
$current_page = 'purchase_orders';
$csrf         = generate_csrf_token();
?>

<?php require_once __DIR__ . '/../includes/layout/sidebar.php'; ?>

<div class="ts-main-content">

    <?php if ($_flash_message): ?>
    <div class="alert alert-<?= $_flash_message['type'] === 'success' ? 'success' : 'danger' ?> alert-dismissible fade show">
        <?= sanitize($_flash_message['message']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- ======================================================
         رأس الصفحة
         ====================================================== -->
    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
        <h1 class="ts-section-title mb-0">
            <i class="bi bi-receipt me-2"></i><?= $lang['purchase_orders'] ?>
        </h1>
        <div class="d-flex gap-2">
            <?php if ($view_po_id > 0 || $view_mode === 'new'): ?>
                <a href="purchase_orders.php" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-right me-1"></i><?= $lang['back'] ?? 'رجوع' ?>
                </a>
            <?php else: ?>
                <a href="?action=new" class="btn btn-primary">
                    <i class="bi bi-plus-lg me-1"></i><?= $lang['new_po'] ?? 'أمر شراء جديد' ?>
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- ======================================================
         نموذج إنشاء أمر شراء جديد
         ====================================================== -->
    <?php if ($view_mode === 'new'): ?>
    <div class="card">
        <div class="card-header">
            <i class="bi bi-plus-circle me-2"></i><?= $lang['new_po'] ?? 'أمر شراء جديد' ?>
        </div>
        <div class="card-body">
            <form method="POST" id="formCreatePO">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="action"     value="create_po">

                <!-- معلومات الـ PO -->
                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <label class="form-label"><?= $lang['supplier'] ?> <span class="text-danger">*</span></label>
                        <select name="supplier_id" id="po_supplier" class="form-select" required>
                            <option value="">— <?= $lang['select'] ?? 'اختر' ?> —</option>
                            <?php foreach ($form_suppliers as $sup): ?>
                                <option value="<?= $sup['id'] ?>"><?= sanitize($sup['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <?php if ($is_super): ?>
                    <div class="col-md-4">
                        <label class="form-label"><?= $lang['branch'] ?? 'الفرع' ?> (<?= $lang['optional'] ?? 'اختياري' ?> — فارغ = مركزي)</label>
                        <select name="branch_id" class="form-select">
                            <option value=""><?= $lang['central'] ?? 'المخزن المركزي' ?></option>
                            <?php foreach ($form_branches as $br): ?>
                                <option value="<?= $br['id'] ?>"><?= sanitize($br['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>

                    <div class="col-md-4">
                        <label class="form-label"><?= $lang['status'] ?></label>
                        <select name="po_status" class="form-select">
                            <option value="draft"><?= $lang['draft'] ?></option>
                            <option value="confirmed"><?= $lang['confirmed'] ?></option>
                        </select>
                    </div>

                    <div class="col-12">
                        <label class="form-label"><?= $lang['notes'] ?></label>
                        <textarea name="notes" class="form-control" rows="2" maxlength="500"></textarea>
                    </div>
                </div>

                <!-- بحث المنتجات وإضافتها -->
                <div class="ts-section-title"><?= $lang['products'] ?></div>

                <div class="mb-3">
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                        <input type="text" id="productSearchInput" class="form-control"
                               placeholder="<?= $lang['search'] ?> <?= $lang['products'] ?>... (<?= $lang['barcode'] ?? 'باركود' ?> / <?= $lang['name'] ?? 'اسم' ?>)">
                    </div>
                    <!-- نتائج البحث -->
                    <div id="productSearchResults" class="list-group mt-1"
                         style="max-height:220px;overflow-y:auto;display:none;
                                background:var(--ts-bg-card);border:1px solid var(--ts-border);border-radius:6px;position:relative;z-index:100;">
                    </div>
                </div>

                <!-- جدول البنود -->
                <div class="table-responsive mb-4">
                    <table class="table table-bordered" id="poItemsTable">
                        <thead>
                            <tr>
                                <th><?= $lang['product'] ?? 'المنتج' ?></th>
                                <th><?= $lang['barcode'] ?? 'الباركود' ?></th>
                                <th style="width:130px"><?= $lang['quantity'] ?? 'الكمية' ?></th>
                                <th style="width:150px"><?= $lang['unit_cost'] ?></th>
                                <th style="width:140px"><?= $lang['total'] ?></th>
                                <th style="width:50px"></th>
                            </tr>
                        </thead>
                        <tbody id="poItemsBody">
                            <tr id="emptyRow">
                                <td colspan="6" class="text-center py-4" style="color:var(--ts-text-muted)">
                                    <i class="bi bi-inbox d-block fs-3 mb-2"></i>
                                    <?= $lang['search_to_add'] ?? 'ابحث عن منتج لإضافته' ?>
                                </td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="4" class="text-end fw-bold" style="color:var(--ts-gold)"><?= $lang['total'] ?></td>
                                <td class="fw-bold" id="po_grand_total" style="color:var(--ts-gold)">0.00 SDG</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div class="d-flex gap-2 justify-content-end">
                    <a href="purchase_orders.php" class="btn btn-outline-secondary"><?= $lang['cancel'] ?></a>
                    <button type="submit" class="btn btn-primary" id="btnSavePO">
                        <i class="bi bi-check-lg me-1"></i><?= $lang['save'] ?>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ======================================================
         تفاصيل أمر شراء
         ====================================================== -->
    <?php elseif ($po_detail): ?>
    <div class="card mb-4">
        <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
            <span>
                <i class="bi bi-file-text me-2"></i>
                <?= $lang['po_number'] ?>: <strong style="color:var(--ts-gold)"><?= sanitize($po_detail['po_number']) ?></strong>
            </span>
            <div class="d-flex gap-2 flex-wrap">
                <?php if ($po_detail['status'] === 'draft'): ?>
                    <!-- تأكيد -->
                    <form method="POST" class="d-inline"
                          onsubmit="return confirm('<?= $lang['confirm_action'] ?? 'تأكيد؟' ?>')">
                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                        <input type="hidden" name="action"    value="confirm_po">
                        <input type="hidden" name="po_id"     value="<?= $po_detail['id'] ?>">
                        <button class="btn btn-success btn-sm">
                            <i class="bi bi-check2-circle me-1"></i><?= $lang['confirm'] ?? 'تأكيد' ?>
                        </button>
                    </form>
                <?php endif; ?>

                <?php if (in_array($po_detail['status'], ['confirmed', 'partial'], true)): ?>
                    <!-- استلام -->
                    <a href="po_receive.php?po_id=<?= $po_detail['id'] ?>" class="btn btn-primary btn-sm">
                        <i class="bi bi-box-arrow-in-down me-1"></i><?= $lang['receive'] ?>
                    </a>
                <?php endif; ?>

                <?php if (in_array($po_detail['status'], ['draft', 'confirmed'], true)): ?>
                    <!-- إلغاء -->
                    <form method="POST" class="d-inline"
                          onsubmit="return confirm('<?= $lang['confirm_cancel'] ?? 'تأكيد الإلغاء؟' ?>')">
                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                        <input type="hidden" name="action"    value="cancel_po">
                        <input type="hidden" name="po_id"     value="<?= $po_detail['id'] ?>">
                        <button class="btn btn-danger btn-sm">
                            <i class="bi bi-x-circle me-1"></i><?= $lang['cancel'] ?>
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-body">

            <!-- معلومات PO -->
            <div class="row g-3 mb-4">
                <div class="col-sm-6 col-md-3">
                    <div class="ts-kpi-card">
                        <div class="kpi-label"><?= $lang['supplier'] ?></div>
                        <div class="fw-bold" style="color:var(--ts-text-primary)"><?= sanitize($po_detail['supplier_name'] ?? '—') ?></div>
                        <small class="text-muted"><?= sanitize($po_detail['supplier_phone'] ?? '') ?></small>
                    </div>
                </div>
                <div class="col-sm-6 col-md-3">
                    <div class="ts-kpi-card">
                        <div class="kpi-label"><?= $lang['branch'] ?? 'الفرع' ?></div>
                        <div class="fw-bold" style="color:var(--ts-text-primary)">
                            <?= $po_detail['branch_name'] ? sanitize($po_detail['branch_name']) : ($lang['central'] ?? 'المركزي') ?>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-md-3">
                    <div class="ts-kpi-card">
                        <div class="kpi-label"><?= $lang['total'] ?></div>
                        <div class="kpi-value"><?= format_money((float)$po_detail['total_amount']) ?></div>
                        <small class="text-muted">
                            <?= $lang['paid'] ?? 'مدفوع' ?>: <?= format_money((float)$po_detail['paid_amount']) ?> |
                            <?= $lang['remaining'] ?? 'متبقي' ?>:
                            <span class="<?= ((float)$po_detail['total_amount'] - (float)$po_detail['paid_amount']) > 0 ? 'ts-status-low' : '' ?>">
                                <?= format_money((float)$po_detail['total_amount'] - (float)$po_detail['paid_amount']) ?>
                            </span>
                        </small>
                    </div>
                </div>
                <div class="col-sm-6 col-md-3">
                    <div class="ts-kpi-card">
                        <div class="kpi-label"><?= $lang['status'] ?></div>
                        <?php
                        $status_colors = ['draft'=>'secondary','confirmed'=>'warning','received'=>'success','partial'=>'info','cancelled'=>'danger'];
                        $sc = $status_colors[$po_detail['status']] ?? 'secondary';
                        $sl = $lang[$po_detail['status']] ?? $po_detail['status'];
                        ?>
                        <span class="badge fs-6 bg-<?= $sc ?>"><?= $sl ?></span>
                        <div class="text-muted small mt-1"><?= format_datetime($po_detail['created_at']) ?></div>
                    </div>
                </div>
            </div>

            <?php if ($po_detail['notes']): ?>
            <div class="alert alert-info py-2 mb-3">
                <i class="bi bi-info-circle me-2"></i><?= sanitize($po_detail['notes']) ?>
            </div>
            <?php endif; ?>

            <!-- بنود الـ PO -->
            <div class="table-responsive">
                <table class="table table-bordered table-sm mb-0">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th><?= $lang['product'] ?? 'المنتج' ?></th>
                            <th><?= $lang['barcode'] ?? 'الباركود' ?></th>
                            <th class="text-end"><?= $lang['quantity'] ?? 'الكمية' ?></th>
                            <th class="text-end"><?= $lang['received_qty'] ?></th>
                            <th class="text-end"><?= $lang['unit_cost'] ?></th>
                            <th class="text-end"><?= $lang['total'] ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($po_detail_items as $i => $it): ?>
                        <?php
                        $remaining_qty = (float)$it['quantity'] - (float)$it['quantity_received'];
                        $prod_name = product_name($it, $lang_code);
                        if (!$prod_name) $prod_name = sanitize($it['product_name']);
                        ?>
                        <tr>
                            <td class="text-muted"><?= $i + 1 ?></td>
                            <td><?= sanitize($prod_name) ?></td>
                            <td><?= sanitize($it['barcode'] ?? '—') ?></td>
                            <td class="text-end"><?= format_qty((float)$it['quantity']) ?></td>
                            <td class="text-end">
                                <span class="<?= (float)$it['quantity_received'] < (float)$it['quantity'] ? 'ts-status-pending' : 'ts-status-active' ?>">
                                    <?= format_qty((float)$it['quantity_received']) ?>
                                </span>
                            </td>
                            <td class="text-end"><?= format_money((float)$it['unit_cost']) ?></td>
                            <td class="text-end"><?= format_money((float)$it['total']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="6" class="text-end fw-bold" style="color:var(--ts-gold)"><?= $lang['total'] ?></td>
                            <td class="text-end fw-bold" style="color:var(--ts-gold)">
                                <?= format_money((float)$po_detail['total_amount']) ?>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>

        </div>
    </div>

    <!-- ======================================================
         قائمة الـ POs
         ====================================================== -->
    <?php else: ?>

    <!-- فلاتر -->
    <div class="card mb-4">
        <div class="card-body py-2">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-sm-6 col-md-3">
                    <select name="status" class="form-select form-select-sm">
                        <option value="all"      <?= $f_status === 'all'       ? 'selected' : '' ?>><?= $lang['all'] ?? 'الكل' ?></option>
                        <option value="draft"    <?= $f_status === 'draft'     ? 'selected' : '' ?>><?= $lang['draft'] ?></option>
                        <option value="confirmed"<?= $f_status === 'confirmed' ? 'selected' : '' ?>><?= $lang['confirmed'] ?></option>
                        <option value="received" <?= $f_status === 'received'  ? 'selected' : '' ?>><?= $lang['received'] ?></option>
                        <option value="partial"  <?= $f_status === 'partial'   ? 'selected' : '' ?>><?= $lang['partial'] ?></option>
                        <option value="cancelled"<?= $f_status === 'cancelled' ? 'selected' : '' ?>><?= $lang['cancelled'] ?></option>
                    </select>
                </div>
                <div class="col-sm-6 col-md-3">
                    <select name="supplier" class="form-select form-select-sm">
                        <option value="0"><?= $lang['supplier'] ?> — <?= $lang['all'] ?? 'الكل' ?></option>
                        <?php foreach ($all_suppliers as $sup): ?>
                            <option value="<?= $sup['id'] ?>" <?= $f_supplier === (int)$sup['id'] ? 'selected' : '' ?>>
                                <?= sanitize($sup['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($is_super): ?>
                <div class="col-sm-6 col-md-2">
                    <select name="branch" class="form-select form-select-sm">
                        <option value="0"><?= $lang['all'] ?? 'الكل' ?></option>
                        <?php foreach ($all_branches as $br): ?>
                            <option value="<?= $br['id'] ?>" <?= $f_branch === (int)$br['id'] ? 'selected' : '' ?>>
                                <?= sanitize($br['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="col-sm-3 col-md-2">
                    <input type="date" name="date_from" class="form-control form-control-sm"
                           value="<?= sanitize($f_date_from) ?>" placeholder="من">
                </div>
                <div class="col-sm-3 col-md-2">
                    <input type="date" name="date_to" class="form-control form-control-sm"
                           value="<?= sanitize($f_date_to) ?>" placeholder="إلى">
                </div>
                <div class="col-auto">
                    <button class="btn btn-primary btn-sm"><i class="bi bi-search me-1"></i><?= $lang['search'] ?></button>
                    <a href="purchase_orders.php" class="btn btn-outline-secondary btn-sm ms-1"><?= $lang['reset'] ?? 'إعادة' ?></a>
                </div>
            </form>
        </div>
    </div>

    <!-- جدول الـ POs -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-list-ul me-2"></i><?= $lang['purchase_orders'] ?></span>
            <small class="text-muted"><?= $lang['total'] ?? 'الإجمالي' ?>: <?= $pg['total'] ?></small>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered mb-0 align-middle">
                    <thead>
                        <tr>
                            <th><?= $lang['po_number'] ?></th>
                            <th><?= $lang['supplier'] ?></th>
                            <th><?= $lang['branch'] ?? 'الفرع' ?></th>
                            <th class="text-center"><?= $lang['items'] ?? 'البنود' ?></th>
                            <th class="text-end"><?= $lang['total'] ?></th>
                            <th class="text-end"><?= $lang['paid'] ?? 'المدفوع' ?></th>
                            <th class="text-center"><?= $lang['status'] ?></th>
                            <th><?= $lang['date'] ?? 'التاريخ' ?></th>
                            <th class="text-center" style="width:120px"><?= $lang['actions'] ?? 'إجراءات' ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($po_list)): ?>
                        <tr>
                            <td colspan="9" class="text-center py-4" style="color:var(--ts-text-muted)">
                                <i class="bi bi-inbox fs-3 d-block mb-2"></i>
                                <?= $lang['no_data'] ?? 'لا توجد بيانات' ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($po_list as $po): ?>
                        <?php
                        $sc = $status_colors[$po['status']] ?? 'secondary';
                        $sl = $lang[$po['status']] ?? $po['status'];
                        ?>
                        <tr>
                            <td>
                                <a href="?view=<?= $po['id'] ?>" style="color:var(--ts-gold)">
                                    <?= sanitize($po['po_number']) ?>
                                </a>
                            </td>
                            <td><?= sanitize($po['supplier_name'] ?? '—') ?></td>
                            <td><?= sanitize($po['branch_name'] ?? ($lang['central'] ?? 'مركزي')) ?></td>
                            <td class="text-center">
                                <span class="badge bg-secondary"><?= (int)$po['items_count'] ?></span>
                            </td>
                            <td class="text-end"><?= format_money((float)$po['total_amount']) ?></td>
                            <td class="text-end"><?= format_money((float)$po['paid_amount']) ?></td>
                            <td class="text-center"><span class="badge bg-<?= $sc ?>"><?= $sl ?></span></td>
                            <td><?= format_datetime($po['created_at']) ?></td>
                            <td class="text-center">
                                <div class="btn-group btn-group-sm">
                                    <a href="?view=<?= $po['id'] ?>" class="btn btn-outline-secondary" title="<?= $lang['details'] ?? 'التفاصيل' ?>">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <?php if (in_array($po['status'], ['confirmed', 'partial'], true)): ?>
                                    <a href="po_receive.php?po_id=<?= $po['id'] ?>" class="btn btn-primary" title="<?= $lang['receive'] ?>">
                                        <i class="bi bi-box-arrow-in-down"></i>
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if ($pg['pages'] > 1): ?>
        <div class="card-footer d-flex justify-content-center">
            <nav><ul class="pagination pagination-sm mb-0">
                <?php for ($p = 1; $p <= $pg['pages']; $p++): ?>
                    <li class="page-item <?= $p === $pg['current'] ? 'active' : '' ?>">
                        <a class="page-link" href="?status=<?= $f_status ?>&supplier=<?= $f_supplier ?>&page=<?= $p ?>"><?= $p ?></a>
                    </li>
                <?php endfor; ?>
            </ul></nav>
        </div>
        <?php endif; ?>
    </div>

    <?php endif; // end mode checks ?>

</div><!-- /ts-main-content -->

<!-- ============================================================
     Scripts — نموذج الإنشاء
     ============================================================ -->
<?php if ($view_mode === 'new'): ?>
<script>
(function () {
    'use strict';

    const langCode      = '<?= $lang_code ?>';
    let   poItems       = [];           // [{product_id, product_name, barcode, unit, unit_cost, quantity, total}]
    let   searchTimer   = null;

    // ——— بحث المنتجات ———
    const searchInput   = document.getElementById('productSearchInput');
    const searchResults = document.getElementById('productSearchResults');
    const itemsBody     = document.getElementById('poItemsBody');
    const emptyRow      = document.getElementById('emptyRow');
    const grandTotal    = document.getElementById('po_grand_total');

    searchInput.addEventListener('input', () => {
        clearTimeout(searchTimer);
        const q = searchInput.value.trim();
        if (q.length < 1) { searchResults.style.display = 'none'; return; }
        searchTimer = setTimeout(() => fetchProducts(q), 300);
    });

    searchInput.addEventListener('keydown', e => {
        if (e.key === 'Escape') {
            searchResults.style.display = 'none';
            searchInput.value = '';
        }
    });

    document.addEventListener('click', e => {
        if (!searchResults.contains(e.target) && e.target !== searchInput) {
            searchResults.style.display = 'none';
        }
    });

    function fetchProducts(q) {
        const csrf = document.querySelector('[name="csrf_token"]').value;
        fetch('../api/search_products.php?q=' + encodeURIComponent(q), {
            headers: { 'X-CSRF-Token': csrf }
        })
        .then(r => r.json())
        .then(data => {
            if (!data.success || !data.products.length) {
                searchResults.innerHTML = '<div class="list-group-item" style="background:var(--ts-bg-card);color:var(--ts-text-muted)">لا نتائج</div>';
                searchResults.style.display = 'block';
                return;
            }
            searchResults.innerHTML = '';
            data.products.forEach(p => {
                const name = langCode === 'en' && p.name_en ? p.name_en : p.name_ar;
                const btn  = document.createElement('button');
                btn.type   = 'button';
                btn.className = 'list-group-item list-group-item-action d-flex justify-content-between align-items-center py-2 px-3';
                btn.style.background = 'var(--ts-bg-card)';
                btn.style.color      = 'var(--ts-text-primary)';
                btn.style.borderColor = 'var(--ts-border)';
                btn.innerHTML = `
                    <span>
                        <strong>${escHtml(name)}</strong>
                        ${p.barcode ? '<small class="ms-2 text-muted">' + escHtml(p.barcode) + '</small>' : ''}
                    </span>
                    <small style="color:var(--ts-gold)">${escHtml(p.unit || '')}</small>
                `;
                btn.addEventListener('click', () => {
                    addItem(p, name);
                    searchResults.style.display = 'none';
                    searchInput.value = '';
                    searchInput.focus();
                });
                searchResults.appendChild(btn);
            });
            searchResults.style.display = 'block';
        })
        .catch(() => { searchResults.style.display = 'none'; });
    }

    // ——— إضافة بند ———
    function addItem(p, name) {
        // إذا موجود، فقط أزد الكمية
        const existing = poItems.find(it => it.product_id === p.id);
        if (existing) {
            existing.quantity += 1;
            existing.total = parseFloat((existing.quantity * existing.unit_cost).toFixed(2));
            renderItems();
            return;
        }

        const cost = parseFloat(p.cost_price) || 0;
        poItems.push({
            product_id:   p.id,
            product_name: name,
            barcode:       p.barcode || '',
            unit:          p.unit    || '',
            unit_cost:     cost,
            quantity:      1,
            total:         cost,
        });
        renderItems();
    }

    // ——— عرض البنود ———
    function renderItems() {
        if (poItems.length === 0) {
            itemsBody.innerHTML = `<tr id="emptyRow">
                <td colspan="6" class="text-center py-4" style="color:var(--ts-text-muted)">
                    <i class="bi bi-inbox d-block fs-3 mb-2"></i>ابحث عن منتج لإضافته
                </td>
            </tr>`;
            grandTotal.textContent = '0.00 SDG';
            return;
        }

        let totalSum = 0;
        itemsBody.innerHTML = '';

        poItems.forEach((it, idx) => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>
                    ${escHtml(it.product_name)}
                    <input type="hidden" name="items[${idx}][product_id]"   value="${it.product_id}">
                    <input type="hidden" name="items[${idx}][product_name]" value="${escHtml(it.product_name)}">
                </td>
                <td class="text-muted small">${escHtml(it.barcode)}</td>
                <td>
                    <input type="number"
                           name="items[${idx}][quantity]"
                           class="form-control form-control-sm qty-input"
                           data-idx="${idx}"
                           value="${it.quantity}"
                           min="0.001" step="0.001" required>
                </td>
                <td>
                    <div class="input-group input-group-sm">
                        <input type="number"
                               name="items[${idx}][unit_cost]"
                               class="form-control cost-input"
                               data-idx="${idx}"
                               value="${it.unit_cost.toFixed(2)}"
                               min="0" step="0.01" required>
                        <span class="input-group-text">SDG</span>
                    </div>
                </td>
                <td class="row-total fw-bold" data-idx="${idx}" style="color:var(--ts-gold)">
                    ${it.total.toFixed(2)} SDG
                </td>
                <td class="text-center">
                    <button type="button" class="btn btn-sm btn-outline-danger remove-btn" data-idx="${idx}" title="حذف">
                        <i class="bi bi-trash3"></i>
                    </button>
                </td>
            `;
            itemsBody.appendChild(tr);
            totalSum += it.total;
        });

        grandTotal.textContent = totalSum.toFixed(2) + ' SDG';

        // ——— Event listeners للمدخلات ———
        itemsBody.querySelectorAll('.qty-input').forEach(inp => {
            inp.addEventListener('change', () => {
                const idx = parseInt(inp.dataset.idx);
                const qty = parseFloat(inp.value) || 0;
                if (qty <= 0) { inp.value = poItems[idx].quantity; return; }
                poItems[idx].quantity = qty;
                poItems[idx].total   = parseFloat((qty * poItems[idx].unit_cost).toFixed(2));
                renderItems();
            });
        });

        itemsBody.querySelectorAll('.cost-input').forEach(inp => {
            inp.addEventListener('change', () => {
                const idx  = parseInt(inp.dataset.idx);
                const cost = parseFloat(inp.value) || 0;
                poItems[idx].unit_cost = cost;
                poItems[idx].total     = parseFloat((poItems[idx].quantity * cost).toFixed(2));
                renderItems();
            });
        });

        itemsBody.querySelectorAll('.remove-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const idx = parseInt(btn.dataset.idx);
                poItems.splice(idx, 1);
                renderItems();
            });
        });
    }

    // ——— منع إرسال نموذج فارغ ———
    document.getElementById('formCreatePO').addEventListener('submit', e => {
        if (poItems.length === 0) {
            e.preventDefault();
            alert('أضف منتجاً واحداً على الأقل');
        }
    });

    // ——— مساعد escape HTML ———
    function escHtml(str) {
        return String(str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/layout/footer.php'; ?>
