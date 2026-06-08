<?php
// ============================================================
// المسار: admin/suppliers.php
// الوظيفة: إدارة الموردين — إضافة / تعديل / تعطيل + تسجيل الدفعات + سجل المشتريات
// الصلاحية: super_admin | branch_admin
// ============================================================

declare(strict_types=1);

require_once __DIR__ . '/../includes/layout/header.php';
require_role(['super_admin', 'branch_admin']);

$pdo   = db();
$audit = new AuditLogger($pdo);
$role  = Auth::getRole();

// ============================================================
// معالجة POST
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submitted_token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($submitted_token)) {
        flash('error', $lang['csrf_invalid']);
        redirect('suppliers.php');
    }

    $action = $_POST['action'] ?? '';

    // ——— إضافة مورد ———
    if ($action === 'add_supplier') {
        $name    = trim($_POST['name']    ?? '');
        $phone   = trim($_POST['phone']   ?? '');
        $email   = trim($_POST['email']   ?? '');
        $address = trim($_POST['address'] ?? '');
        $notes   = trim($_POST['notes']   ?? '');

        if ($name === '') {
            flash('error', $lang['required_field']);
            redirect('suppliers.php');
        }

        try {
            $stmt = $pdo->prepare('
                INSERT INTO suppliers (name, phone, email, address, notes, status, created_at)
                VALUES (?, ?, ?, ?, ?, \'active\', NOW())
            ');
            $stmt->execute([$name, $phone, $email, $address, $notes]);
            $new_id = (int) $pdo->lastInsertId();
            $audit->created('suppliers', $new_id, compact('name', 'phone', 'email', 'address'));
            flash('success', $lang['saved_successfully']);
        } catch (Throwable $e) {
            error_log('[TopShine] suppliers add: ' . $e->getMessage());
            flash('error', $lang['error_occurred']);
        }
        redirect('suppliers.php');
    }

    // ——— تعديل مورد ———
    if ($action === 'edit_supplier') {
        $id      = sanitize_int($_POST['supplier_id'] ?? 0);
        $name    = trim($_POST['name']    ?? '');
        $phone   = trim($_POST['phone']   ?? '');
        $email   = trim($_POST['email']   ?? '');
        $address = trim($_POST['address'] ?? '');
        $notes   = trim($_POST['notes']   ?? '');

        if ($id <= 0 || $name === '') {
            flash('error', $lang['required_field']);
            redirect('suppliers.php');
        }

        try {
            $old = $pdo->prepare('SELECT * FROM suppliers WHERE id = ? LIMIT 1');
            $old->execute([$id]);
            $old_data = $old->fetch() ?: [];

            $stmt = $pdo->prepare('
                UPDATE suppliers
                SET name=?, phone=?, email=?, address=?, notes=?
                WHERE id=?
            ');
            $stmt->execute([$name, $phone, $email, $address, $notes, $id]);
            $audit->updated('suppliers', $id, $old_data, compact('name', 'phone', 'email', 'address'));
            flash('success', $lang['saved_successfully']);
        } catch (Throwable $e) {
            error_log('[TopShine] suppliers edit: ' . $e->getMessage());
            flash('error', $lang['error_occurred']);
        }
        redirect('suppliers.php');
    }

    // ——— تعطيل مورد (soft delete) ———
    if ($action === 'toggle_status') {
        $id = sanitize_int($_POST['supplier_id'] ?? 0);
        if ($id <= 0) { redirect('suppliers.php'); }

        try {
            $row = $pdo->prepare('SELECT status FROM suppliers WHERE id=? LIMIT 1');
            $row->execute([$id]);
            $current = $row->fetchColumn();
            $new_status = ($current === 'active') ? 'inactive' : 'active';

            $pdo->prepare('UPDATE suppliers SET status=? WHERE id=?')
                ->execute([$new_status, $id]);

            $audit->log(
                $new_status === 'inactive' ? 'delete' : 'update',
                'suppliers', $id,
                ['status' => $current],
                ['status' => $new_status]
            );
            flash('success', $lang['saved_successfully']);
        } catch (Throwable $e) {
            error_log('[TopShine] suppliers toggle: ' . $e->getMessage());
            flash('error', $lang['error_occurred']);
        }
        redirect('suppliers.php');
    }

    // ——— تسجيل دفعة للمورد ———
    if ($action === 'add_payment') {
        $supplier_id     = sanitize_int($_POST['supplier_id']     ?? 0);
        $amount          = (float) filter_var($_POST['amount'] ?? 0, FILTER_VALIDATE_FLOAT);
        $payment_method  = $_POST['payment_method'] ?? 'cash';
        $payment_date    = $_POST['payment_date']   ?? date('Y-m-d');
        $po_id           = sanitize_int($_POST['po_id'] ?? 0);
        $notes           = trim($_POST['notes'] ?? '');

        $allowed_methods = ['cash', 'bank_transfer', 'check', 'other'];
        if (!in_array($payment_method, $allowed_methods, true)) {
            $payment_method = 'cash';
        }
        if ($supplier_id <= 0 || $amount <= 0) {
            flash('error', $lang['required_field']);
            redirect('suppliers.php');
        }

        try {
            $pdo->beginTransaction();

            // INSERT في supplier_payments
            $ins = $pdo->prepare('
                INSERT INTO supplier_payments
                    (supplier_id, po_id, amount, payment_method, payment_date, notes, created_by, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ');
            $ins->execute([
                $supplier_id,
                $po_id > 0 ? $po_id : null,
                $amount,
                $payment_method,
                $payment_date,
                $notes,
                $_SESSION['user_id'] ?? 0,
            ]);
            $pay_id = (int) $pdo->lastInsertId();

            // تخفيض رصيد المورد
            $pdo->prepare('UPDATE suppliers SET balance = GREATEST(0, balance - ?) WHERE id=?')
                ->execute([$amount, $supplier_id]);

            $pdo->commit();

            $audit->created('supplier_payments', $pay_id, [
                'supplier_id'    => $supplier_id,
                'amount'         => $amount,
                'payment_method' => $payment_method,
                'payment_date'   => $payment_date,
            ]);
            flash('success', $lang['saved_successfully']);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[TopShine] supplier payment: ' . $e->getMessage());
            flash('error', $lang['error_occurred']);
        }
        redirect('suppliers.php');
    }
}

// ============================================================
// جلب بيانات الصفحة
// ============================================================

// فلترة البحث
$search     = trim($_GET['q']      ?? '');
$status_f   = $_GET['status']      ?? 'all';
$view_id    = sanitize_int($_GET['view'] ?? 0); // عرض تاريخ مورد معين

// إجمالي الرصيد المستحق
$total_balance = (float) $pdo->query('SELECT COALESCE(SUM(balance),0) FROM suppliers WHERE status=\'active\'')->fetchColumn();

// ——— قائمة الموردين مع Pagination ———
$where_parts = ['1=1'];
$params      = [];

if ($search !== '') {
    $where_parts[] = '(s.name LIKE ? OR s.phone LIKE ? OR s.email LIKE ?)';
    $params = array_merge($params, ["%{$search}%", "%{$search}%", "%{$search}%"]);
}
if ($status_f !== 'all') {
    $where_parts[] = 's.status = ?';
    $params[]      = $status_f;
}

$where = implode(' AND ', $where_parts);

$per_page = 20;
$pg       = paginate($pdo, "SELECT COUNT(*) FROM suppliers s WHERE {$where}", $params, (int)($_GET['page'] ?? 1), $per_page);

$stmt = $pdo->prepare("
    SELECT s.*,
           (SELECT COUNT(*) FROM purchase_orders po WHERE po.supplier_id = s.id) AS po_count
    FROM suppliers s
    WHERE {$where}
    ORDER BY s.status ASC, s.name ASC
    LIMIT {$pg['per_page']} OFFSET {$pg['offset']}
");
$stmt->execute($params);
$suppliers = $stmt->fetchAll();

// ——— سجل مشتريات مورد محدد ———
$supplier_detail  = null;
$supplier_pos     = [];
$supplier_payments_list = [];

if ($view_id > 0) {
    $d = $pdo->prepare('SELECT * FROM suppliers WHERE id=? LIMIT 1');
    $d->execute([$view_id]);
    $supplier_detail = $d->fetch();

    if ($supplier_detail) {
        // آخر 20 أمر شراء
        $po_stmt = $pdo->prepare('
            SELECT po.*,
                   b.name AS branch_name
            FROM purchase_orders po
            LEFT JOIN branches b ON b.id = po.branch_id
            WHERE po.supplier_id = ?
            ORDER BY po.created_at DESC
            LIMIT 20
        ');
        $po_stmt->execute([$view_id]);
        $supplier_pos = $po_stmt->fetchAll();

        // آخر 20 دفعة
        $pay_stmt = $pdo->prepare('
            SELECT sp.*,
                   po.po_number
            FROM supplier_payments sp
            LEFT JOIN purchase_orders po ON po.id = sp.po_id
            WHERE sp.supplier_id = ?
            ORDER BY sp.created_at DESC
            LIMIT 20
        ');
        $pay_stmt->execute([$view_id]);
        $supplier_payments_list = $pay_stmt->fetchAll();
    }
}

// ——— قائمة الموردين للـ select في modal الدفعة ———
$all_suppliers_active = $pdo->query('SELECT id, name, balance FROM suppliers WHERE status=\'active\' ORDER BY name ASC')->fetchAll();

// أوامر شراء مفتوحة لكل مورد (للربط بالدفعة)
$open_pos_by_supplier = [];
if (!empty($view_id) && $supplier_detail) {
    $op = $pdo->prepare('
        SELECT id, po_number, total_amount, paid_amount
        FROM purchase_orders
        WHERE supplier_id=? AND status IN (\'confirmed\',\'received\',\'partial\')
        ORDER BY created_at DESC
    ');
    $op->execute([$view_id]);
    $open_pos_by_supplier = $op->fetchAll();
}

// ============================================================
$page_title   = $lang['suppliers'];
$current_page = 'suppliers';
$csrf         = generate_csrf_token();
?>

<?php require_once __DIR__ . '/../includes/layout/sidebar.php'; ?>

<div class="ts-main-content">

    <?php if ($_flash_message): ?>
    <div class="alert alert-<?= $_flash_message['type'] === 'success' ? 'success' : 'danger' ?> alert-dismissible fade show" role="alert">
        <?= sanitize($_flash_message['message']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- ======================================================
         الترويسة
         ====================================================== -->
    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
        <h1 class="ts-section-title mb-0">
            <i class="bi bi-truck me-2"></i><?= $lang['suppliers'] ?>
        </h1>
        <div class="d-flex gap-2 flex-wrap">
            <!-- KPI: إجمالي الرصيد المستحق -->
            <span class="badge fs-6 px-3 py-2"
                  style="background:rgba(220,53,69,.15);border:1px solid var(--ts-danger);color:#f08090;">
                <i class="bi bi-exclamation-circle me-1"></i>
                <?= $lang['supplier_balance'] ?>: <?= format_money($total_balance) ?>
            </span>
            <!-- زر إضافة -->
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalAddSupplier">
                <i class="bi bi-plus-lg me-1"></i><?= $lang['add'] ?? 'إضافة' ?> <?= $lang['supplier'] ?>
            </button>
        </div>
    </div>

    <!-- ======================================================
         فلاتر البحث
         ====================================================== -->
    <div class="card mb-4">
        <div class="card-body py-2">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-sm-6 col-md-5">
                    <input type="text" name="q" class="form-control form-control-sm"
                           placeholder="<?= $lang['search'] ?>... (<?= $lang['supplier_name'] ?> / <?= $lang['phone'] ?? 'هاتف' ?>)"
                           value="<?= sanitize($search) ?>">
                </div>
                <div class="col-sm-4 col-md-3">
                    <select name="status" class="form-select form-select-sm">
                        <option value="all"     <?= $status_f === 'all'     ? 'selected' : '' ?>><?= $lang['all']    ?? 'الكل' ?></option>
                        <option value="active"  <?= $status_f === 'active'  ? 'selected' : '' ?>><?= $lang['active']  ?? 'نشط' ?></option>
                        <option value="inactive"<?= $status_f === 'inactive'? 'selected' : '' ?>><?= $lang['inactive']?? 'معطل' ?></option>
                    </select>
                </div>
                <div class="col-auto">
                    <button class="btn btn-primary btn-sm"><i class="bi bi-search me-1"></i><?= $lang['search'] ?></button>
                    <a href="suppliers.php" class="btn btn-outline-secondary btn-sm ms-1"><?= $lang['reset'] ?? 'إعادة' ?></a>
                </div>
            </form>
        </div>
    </div>

    <!-- ======================================================
         جدول الموردين
         ====================================================== -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-list-ul me-2"></i><?= $lang['suppliers'] ?></span>
            <small class="text-muted"><?= $lang['total'] ?? 'الإجمالي' ?>: <?= $pg['total'] ?></small>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered mb-0 align-middle">
                    <thead>
                        <tr>
                            <th style="width:40px">#</th>
                            <th><?= $lang['supplier_name'] ?></th>
                            <th><?= $lang['phone'] ?? 'الهاتف' ?></th>
                            <th><?= $lang['supplier_email'] ?></th>
                            <th class="text-center"><?= $lang['purchase_orders'] ?></th>
                            <th class="text-end"><?= $lang['supplier_balance'] ?></th>
                            <th class="text-center"><?= $lang['status'] ?></th>
                            <th class="text-center" style="width:180px"><?= $lang['actions'] ?? 'إجراءات' ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($suppliers)): ?>
                        <tr>
                            <td colspan="8" class="text-center py-4" style="color:var(--ts-text-muted)">
                                <i class="bi bi-inbox fs-3 d-block mb-2"></i>
                                <?= $lang['no_data'] ?? 'لا توجد بيانات' ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($suppliers as $i => $s): ?>
                        <tr>
                            <td class="text-muted small"><?= $pg['offset'] + $i + 1 ?></td>
                            <td>
                                <strong><?= sanitize($s['name']) ?></strong>
                                <?php if ($s['address']): ?>
                                    <br><small class="text-muted"><i class="bi bi-geo-alt me-1"></i><?= sanitize($s['address']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td><?= sanitize($s['phone'] ?? '—') ?></td>
                            <td><?= sanitize($s['email'] ?? '—') ?></td>
                            <td class="text-center">
                                <span class="badge bg-secondary"><?= (int)$s['po_count'] ?></span>
                            </td>
                            <td class="text-end <?= (float)$s['balance'] > 0 ? 'ts-status-low' : '' ?>">
                                <?= format_money((float)$s['balance']) ?>
                            </td>
                            <td class="text-center">
                                <?php if ($s['status'] === 'active'): ?>
                                    <span class="badge bg-success"><?= $lang['active'] ?? 'نشط' ?></span>
                                <?php else: ?>
                                    <span class="badge bg-secondary"><?= $lang['inactive'] ?? 'معطل' ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <div class="btn-group btn-group-sm">
                                    <!-- عرض التاريخ -->
                                    <a href="?view=<?= $s['id'] ?>" class="btn btn-outline-secondary" title="<?= $lang['history'] ?? 'التاريخ' ?>">
                                        <i class="bi bi-clock-history"></i>
                                    </a>
                                    <!-- تسجيل دفعة -->
                                    <?php if ($s['status'] === 'active'): ?>
                                    <button class="btn btn-outline-secondary"
                                            title="<?= $lang['pay_supplier'] ?>"
                                            onclick="openPayModal(<?= $s['id'] ?>, '<?= sanitize($s['name']) ?>', <?= $s['balance'] ?>)">
                                        <i class="bi bi-cash-coin"></i>
                                    </button>
                                    <?php endif; ?>
                                    <!-- تعديل -->
                                    <button class="btn btn-outline-secondary"
                                            title="<?= $lang['edit'] ?? 'تعديل' ?>"
                                            onclick="openEditModal(<?= htmlspecialchars(json_encode($s), ENT_QUOTES) ?>)">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <!-- تعطيل / تفعيل -->
                                    <form method="POST" class="d-inline"
                                          onsubmit="return confirm('<?= $s['status'] === 'active' ? ($lang['confirm_deactivate'] ?? 'تأكيد التعطيل؟') : ($lang['confirm_activate'] ?? 'تأكيد التفعيل؟') ?>')">
                                        <input type="hidden" name="csrf_token"   value="<?= $csrf ?>">
                                        <input type="hidden" name="action"       value="toggle_status">
                                        <input type="hidden" name="supplier_id"  value="<?= $s['id'] ?>">
                                        <button class="btn <?= $s['status'] === 'active' ? 'btn-outline-danger' : 'btn-outline-success' ?>"
                                                title="<?= $s['status'] === 'active' ? ($lang['deactivate'] ?? 'تعطيل') : ($lang['activate'] ?? 'تفعيل') ?>">
                                            <i class="bi bi-<?= $s['status'] === 'active' ? 'slash-circle' : 'check-circle' ?>"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Pagination -->
        <?php if ($pg['pages'] > 1): ?>
        <div class="card-footer d-flex justify-content-center">
            <nav><ul class="pagination pagination-sm mb-0">
                <?php for ($p = 1; $p <= $pg['pages']; $p++): ?>
                    <li class="page-item <?= $p === $pg['current'] ? 'active' : '' ?>">
                        <a class="page-link" href="?q=<?= urlencode($search) ?>&status=<?= $status_f ?>&page=<?= $p ?>"><?= $p ?></a>
                    </li>
                <?php endfor; ?>
            </ul></nav>
        </div>
        <?php endif; ?>
    </div>

    <!-- ======================================================
         تاريخ مورد محدد
         ====================================================== -->
    <?php if ($supplier_detail): ?>
    <div class="card mt-4" id="supplier-detail">
        <div class="card-header d-flex align-items-center justify-content-between">
            <span>
                <i class="bi bi-clock-history me-2"></i>
                <?= $lang['history'] ?? 'تاريخ' ?> — <?= sanitize($supplier_detail['name']) ?>
            </span>
            <a href="suppliers.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-x-lg"></i>
            </a>
        </div>
        <div class="card-body">

            <!-- KPIs -->
            <div class="row g-3 mb-4">
                <div class="col-sm-4">
                    <div class="ts-kpi-card">
                        <div class="kpi-label"><?= $lang['supplier_balance'] ?></div>
                        <div class="kpi-value text-danger"><?= format_money((float)$supplier_detail['balance']) ?></div>
                    </div>
                </div>
                <div class="col-sm-4">
                    <div class="ts-kpi-card">
                        <div class="kpi-label"><?= $lang['purchase_orders'] ?></div>
                        <div class="kpi-value"><?= count($supplier_pos) ?></div>
                    </div>
                </div>
                <div class="col-sm-4">
                    <div class="ts-kpi-card">
                        <div class="kpi-label"><?= $lang['pay_supplier'] ?></div>
                        <div class="kpi-value"><?= count($supplier_payments_list) ?></div>
                    </div>
                </div>
            </div>

            <!-- Tabs -->
            <ul class="nav nav-tabs mb-3" id="supplierTabs">
                <li class="nav-item">
                    <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabPOs">
                        <i class="bi bi-receipt me-1"></i><?= $lang['purchase_orders'] ?>
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabPayments">
                        <i class="bi bi-cash-coin me-1"></i><?= $lang['pay_supplier'] ?>
                    </button>
                </li>
            </ul>

            <div class="tab-content">

                <!-- أوامر الشراء -->
                <div class="tab-pane fade show active" id="tabPOs">
                    <div class="table-responsive">
                        <table class="table table-bordered table-sm mb-0">
                            <thead>
                                <tr>
                                    <th><?= $lang['po_number'] ?></th>
                                    <th><?= $lang['branch'] ?? 'الفرع' ?></th>
                                    <th class="text-end"><?= $lang['total'] ?></th>
                                    <th class="text-end"><?= $lang['paid'] ?? 'المدفوع' ?></th>
                                    <th class="text-end"><?= $lang['remaining'] ?? 'المتبقي' ?></th>
                                    <th class="text-center"><?= $lang['status'] ?></th>
                                    <th><?= $lang['date'] ?? 'التاريخ' ?></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($supplier_pos as $po): ?>
                                <?php $remaining = (float)$po['total_amount'] - (float)$po['paid_amount']; ?>
                                <tr>
                                    <td>
                                        <a href="purchase_orders.php?view=<?= $po['id'] ?>"
                                           style="color:var(--ts-gold)"><?= sanitize($po['po_number']) ?></a>
                                    </td>
                                    <td><?= sanitize($po['branch_name'] ?? '—') ?></td>
                                    <td class="text-end"><?= format_money((float)$po['total_amount']) ?></td>
                                    <td class="text-end"><?= format_money((float)$po['paid_amount']) ?></td>
                                    <td class="text-end <?= $remaining > 0 ? 'ts-status-low' : '' ?>">
                                        <?= format_money($remaining) ?>
                                    </td>
                                    <td class="text-center">
                                        <?php
                                        $po_status_colors = [
                                            'draft'     => 'secondary',
                                            'confirmed' => 'warning',
                                            'received'  => 'success',
                                            'partial'   => 'info',
                                            'cancelled' => 'danger',
                                        ];
                                        $sc = $po_status_colors[$po['status']] ?? 'secondary';
                                        $sl = $lang[$po['status']] ?? $po['status'];
                                        ?>
                                        <span class="badge bg-<?= $sc ?>"><?= $sl ?></span>
                                    </td>
                                    <td><?= format_datetime($po['created_at']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($supplier_pos)): ?>
                                <tr><td colspan="7" class="text-center py-3" style="color:var(--ts-text-muted)"><?= $lang['no_data'] ?? 'لا بيانات' ?></td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- الدفعات -->
                <div class="tab-pane fade" id="tabPayments">
                    <div class="d-flex justify-content-end mb-2">
                        <button class="btn btn-primary btn-sm"
                                onclick="openPayModal(<?= $supplier_detail['id'] ?>, '<?= sanitize($supplier_detail['name']) ?>', <?= $supplier_detail['balance'] ?>)">
                            <i class="bi bi-plus-lg me-1"></i><?= $lang['pay_supplier'] ?>
                        </button>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-bordered table-sm mb-0">
                            <thead>
                                <tr>
                                    <th><?= $lang['payment_date'] ?></th>
                                    <th class="text-end"><?= $lang['amount'] ?? 'المبلغ' ?></th>
                                    <th><?= $lang['payment_method'] ?></th>
                                    <th><?= $lang['po_number'] ?></th>
                                    <th><?= $lang['notes'] ?></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($supplier_payments_list as $pay): ?>
                                <tr>
                                    <td><?= sanitize($pay['payment_date']) ?></td>
                                    <td class="text-end"><?= format_money((float)$pay['amount']) ?></td>
                                    <td><?= $lang[$pay['payment_method']] ?? sanitize($pay['payment_method']) ?></td>
                                    <td><?= $pay['po_number'] ? sanitize($pay['po_number']) : '—' ?></td>
                                    <td><?= sanitize($pay['notes'] ?? '—') ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($supplier_payments_list)): ?>
                                <tr><td colspan="5" class="text-center py-3" style="color:var(--ts-text-muted)"><?= $lang['no_data'] ?? 'لا بيانات' ?></td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div><!-- /tab-content -->
        </div>
    </div>
    <?php endif; ?>

</div><!-- /ts-main-content -->

<!-- ============================================================
     Modal — إضافة مورد
     ============================================================ -->
<div class="modal fade" id="modalAddSupplier" tabindex="-1" aria-labelledby="modalAddSupplierLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="action"     value="add_supplier">

                <div class="modal-header">
                    <h5 class="modal-title" id="modalAddSupplierLabel">
                        <i class="bi bi-plus-circle me-2"></i><?= $lang['add'] ?? 'إضافة' ?> <?= $lang['supplier'] ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label"><?= $lang['supplier_name'] ?> <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" required maxlength="200">
                    </div>
                    <div class="row g-3">
                        <div class="col-sm-6">
                            <label class="form-label"><?= $lang['phone'] ?? 'الهاتف' ?></label>
                            <input type="text" name="phone" class="form-control" maxlength="20">
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label"><?= $lang['supplier_email'] ?></label>
                            <input type="email" name="email" class="form-control" maxlength="150">
                        </div>
                    </div>
                    <div class="mb-3 mt-3">
                        <label class="form-label"><?= $lang['address'] ?? 'العنوان' ?></label>
                        <input type="text" name="address" class="form-control" maxlength="300">
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?= $lang['notes'] ?></label>
                        <textarea name="notes" class="form-control" rows="2" maxlength="500"></textarea>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= $lang['cancel'] ?></button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-1"></i><?= $lang['save'] ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============================================================
     Modal — تعديل مورد
     ============================================================ -->
<div class="modal fade" id="modalEditSupplier" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="csrf_token"  value="<?= $csrf ?>">
                <input type="hidden" name="action"      value="edit_supplier">
                <input type="hidden" name="supplier_id" id="edit_supplier_id">

                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-pencil me-2"></i><?= $lang['edit'] ?? 'تعديل' ?> <?= $lang['supplier'] ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label"><?= $lang['supplier_name'] ?> <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="edit_name" class="form-control" required maxlength="200">
                    </div>
                    <div class="row g-3">
                        <div class="col-sm-6">
                            <label class="form-label"><?= $lang['phone'] ?? 'الهاتف' ?></label>
                            <input type="text" name="phone" id="edit_phone" class="form-control" maxlength="20">
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label"><?= $lang['supplier_email'] ?></label>
                            <input type="email" name="email" id="edit_email" class="form-control" maxlength="150">
                        </div>
                    </div>
                    <div class="mb-3 mt-3">
                        <label class="form-label"><?= $lang['address'] ?? 'العنوان' ?></label>
                        <input type="text" name="address" id="edit_address" class="form-control" maxlength="300">
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?= $lang['notes'] ?></label>
                        <textarea name="notes" id="edit_notes" class="form-control" rows="2" maxlength="500"></textarea>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= $lang['cancel'] ?></button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-1"></i><?= $lang['save'] ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============================================================
     Modal — تسجيل دفعة
     ============================================================ -->
<div class="modal fade" id="modalPaySupplier" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="csrf_token"  value="<?= $csrf ?>">
                <input type="hidden" name="action"      value="add_payment">
                <input type="hidden" name="supplier_id" id="pay_supplier_id">

                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-cash-coin me-2"></i><?= $lang['pay_supplier'] ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <!-- اسم المورد + رصيده -->
                    <div class="alert alert-warning py-2 mb-3">
                        <strong id="pay_supplier_name"></strong>
                        <span class="ms-2"><?= $lang['supplier_balance'] ?>:
                            <strong id="pay_supplier_balance"></strong>
                        </span>
                    </div>

                    <div class="mb-3">
                        <label class="form-label"><?= $lang['amount'] ?? 'المبلغ' ?> <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="number" name="amount" id="pay_amount" class="form-control"
                                   step="0.01" min="0.01" required>
                            <span class="input-group-text">SDG</span>
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-sm-6">
                            <label class="form-label"><?= $lang['payment_method'] ?></label>
                            <select name="payment_method" class="form-select">
                                <option value="cash"><?= $lang['cash'] ?></option>
                                <option value="bank_transfer"><?= $lang['bank_transfer'] ?></option>
                                <option value="check"><?= $lang['check'] ?></option>
                                <option value="other"><?= $lang['other'] ?? 'أخرى' ?></option>
                            </select>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label"><?= $lang['payment_date'] ?></label>
                            <input type="date" name="payment_date" class="form-control" value="<?= date('Y-m-d') ?>">
                        </div>
                    </div>

                    <div class="mb-3 mt-3">
                        <label class="form-label"><?= $lang['po_number'] ?> (<?= $lang['optional'] ?? 'اختياري' ?>)</label>
                        <select name="po_id" id="pay_po_select" class="form-select">
                            <option value="">— <?= $lang['general_payment'] ?? 'دفعة عامة' ?> —</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label"><?= $lang['notes'] ?></label>
                        <textarea name="notes" class="form-control" rows="2" maxlength="300"></textarea>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= $lang['cancel'] ?></button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-1"></i><?= $lang['save'] ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============================================================
     Scripts
     ============================================================ -->
<script>
// ——— بيانات أوامر الشراء المفتوحة لكل مورد ———
const openPOsBySupplier = <?= json_encode(
    // نجمّع الـ POs المفتوحة مفهرسة بـ supplier_id
    (function() use ($pdo): array {
        $rows = $pdo->query("
            SELECT po.id, po.po_number, po.supplier_id,
                   po.total_amount, po.paid_amount
            FROM purchase_orders po
            WHERE po.status IN ('confirmed','received','partial')
            ORDER BY po.created_at DESC
        ")->fetchAll();
        $indexed = [];
        foreach ($rows as $r) {
            $indexed[$r['supplier_id']][] = $r;
        }
        return $indexed;
    })(),
    JSON_UNESCAPED_UNICODE
) ?>;

// ——— فتح Modal تعديل مورد ———
function openEditModal(supplier) {
    document.getElementById('edit_supplier_id').value = supplier.id;
    document.getElementById('edit_name').value        = supplier.name    || '';
    document.getElementById('edit_phone').value       = supplier.phone   || '';
    document.getElementById('edit_email').value       = supplier.email   || '';
    document.getElementById('edit_address').value     = supplier.address || '';
    document.getElementById('edit_notes').value       = supplier.notes   || '';
    new bootstrap.Modal(document.getElementById('modalEditSupplier')).show();
}

// ——— فتح Modal تسجيل دفعة ———
function openPayModal(supplierId, supplierName, balance) {
    document.getElementById('pay_supplier_id').value     = supplierId;
    document.getElementById('pay_supplier_name').textContent    = supplierName;
    document.getElementById('pay_supplier_balance').textContent = parseFloat(balance).toFixed(2) + ' SDG';
    document.getElementById('pay_amount').value          = balance > 0 ? parseFloat(balance).toFixed(2) : '';

    // ملء قائمة أوامر الشراء المفتوحة
    const select = document.getElementById('pay_po_select');
    select.innerHTML = '<option value="">— <?= $lang['general_payment'] ?? 'دفعة عامة' ?> —</option>';
    const pos = openPOsBySupplier[supplierId] || [];
    pos.forEach(po => {
        const remaining = (parseFloat(po.total_amount) - parseFloat(po.paid_amount)).toFixed(2);
        const opt = document.createElement('option');
        opt.value = po.id;
        opt.textContent = po.po_number + ' (متبقي: ' + remaining + ' SDG)';
        select.appendChild(opt);
    });

    new bootstrap.Modal(document.getElementById('modalPaySupplier')).show();
}
</script>

<?php require_once __DIR__ . '/../includes/layout/footer.php'; ?>
