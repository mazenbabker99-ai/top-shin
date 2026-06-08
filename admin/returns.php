<?php
// ============================================================
// المسار: admin/returns.php
// الوظيفة: إنشاء ومعالجة المرتجعات + قائمة المرتجعات + الموافقة
// الصلاحية: super_admin | branch_admin | cashier (إنشاء فقط)
// ============================================================

declare(strict_types=1);

$current_page = 'returns';
require_once __DIR__ . '/../includes/layout/header.php';
require_role(['super_admin', 'branch_admin', 'cashier']);

$pdo      = db();
$audit    = new AuditLogger($pdo);
$role     = Auth::getRole();
$user_id  = Auth::getUserId();
$user_bid = Auth::getBranchId();
$is_super = ($role === 'super_admin');
$is_admin = in_array($role, ['super_admin', 'branch_admin'], true);

// ============================================================
// معالجة POST
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submitted_token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($submitted_token)) {
        flash('error', $lang['csrf_invalid']);
        redirect('returns.php');
    }

    $action = $_POST['action'] ?? '';

    // ——— إنشاء مرتجع جديد ———
    if ($action === 'create_return') {

        $invoice_id    = sanitize_int($_POST['invoice_id']    ?? 0);
        $branch_id     = sanitize_int($_POST['branch_id']     ?? 0);
        $reason        = trim($_POST['reason']        ?? '');
        $refund_method = trim($_POST['refund_method'] ?? 'cash');
        $items_raw     = $_POST['items'] ?? [];

        if (!in_array($refund_method, ['cash', 'wallet', 'exchange'], true)) {
            $refund_method = 'cash';
        }

        if ($invoice_id <= 0 || empty($items_raw)) {
            flash('error', $lang['required_field']);
            redirect('returns.php?action=new');
        }

        // تحقق أن الكاشير ينتمي لنفس الفرع
        if (!$is_super && $user_bid !== $branch_id) {
            flash('error', $lang['unauthorized']);
            redirect('returns.php');
        }

        // branch_admin → approved مباشرة | cashier → pending
        $status = ($role === 'cashier') ? 'pending' : 'approved';

        // تجهيز البنود مع التحقق من الكميات
        $items     = [];
        $total_ref = 0.0;

        foreach ($items_raw as $item) {
            $inv_item_id = sanitize_int($item['invoice_item_id'] ?? 0);
            $product_id  = sanitize_int($item['product_id']      ?? 0);
            $qty         = (float)filter_var($item['quantity'] ?? 0, FILTER_VALIDATE_FLOAT);
            $unit_price  = (float)filter_var($item['unit_price'] ?? 0, FILTER_VALIDATE_FLOAT);
            $restock     = isset($item['restock']) ? 1 : 0;

            if ($inv_item_id <= 0 || $product_id <= 0 || $qty <= 0) continue;

            $item_total  = round($qty * $unit_price, 2);
            $total_ref  += $item_total;

            $items[] = [
                'invoice_item_id' => $inv_item_id,
                'product_id'      => $product_id,
                'quantity'        => $qty,
                'unit_price'      => $unit_price,
                'total'           => $item_total,
                'restock'         => $restock,
            ];
        }

        if (empty($items)) {
            flash('error', $lang['required_field']);
            redirect('returns.php?action=new');
        }

        try {
            $pdo->beginTransaction();

            $return_number = generate_return_number($pdo);

            // INSERT في returns
            $ins_ret = $pdo->prepare('
                INSERT INTO returns
                    (return_number, invoice_id, branch_id, processed_by, total_refund,
                     refund_method, reason, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ');
            $ins_ret->execute([
                $return_number,
                $invoice_id,
                $branch_id,
                $user_id,
                round($total_ref, 2),
                $refund_method,
                $reason,
                $status,
            ]);
            $return_id = (int)$pdo->lastInsertId();

            // INSERT البنود + تحديث المخزون إذا approved
            foreach ($items as $item) {

                $ins_item = $pdo->prepare('
                    INSERT INTO return_items
                        (return_id, invoice_item_id, product_id, quantity, unit_price, total, restock)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ');
                $ins_item->execute([
                    $return_id,
                    $item['invoice_item_id'],
                    $item['product_id'],
                    $item['quantity'],
                    $item['unit_price'],
                    $item['total'],
                    $item['restock'],
                ]);

                // إعادة المخزون فقط إذا approved + restock=1
                if ($status === 'approved' && $item['restock'] === 1) {
                    _return_restock($pdo, $item['product_id'], $branch_id, $item['quantity'], $return_id);
                }
            }

            // تحديث حالة الفاتورة الأصلية إذا approved
            if ($status === 'approved') {
                $pdo->prepare("UPDATE invoices SET status = 'refunded' WHERE id = ? LIMIT 1")
                    ->execute([$invoice_id]);
            }

            $pdo->commit();

            $audit->log('create', 'returns', $return_id, null, [
                'return_number' => $return_number,
                'invoice_id'    => $invoice_id,
                'total_refund'  => round($total_ref, 2),
                'status'        => $status,
            ]);

            regenerate_csrf_token();
            $msg = $status === 'approved'
                ? ($is_rtl ? 'تم إنشاء المرتجع بنجاح.' : 'Return created successfully.')
                : ($is_rtl ? 'تم إرسال طلب المرتجع. بانتظار موافقة المدير.' : 'Return request sent. Awaiting manager approval.');
            flash('success', $msg);
            redirect('returns.php?print=' . $return_id);

        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('[TopShine returns create] ' . $e->getMessage());
            flash('error', $lang['transaction_failed']);
            redirect('returns.php?action=new');
        }
    }

    // ——— الموافقة على مرتجع معلق ———
    if ($action === 'approve_return' && $is_admin) {

        $return_id = sanitize_int($_POST['return_id'] ?? 0);
        if ($return_id <= 0) {
            flash('error', $lang['invalid_input']);
            redirect('returns.php');
        }

        try {
            $pdo->beginTransaction();

            // جلب بيانات المرتجع
            $ret_stmt = $pdo->prepare('SELECT * FROM returns WHERE id = ? AND status = ? LIMIT 1');
            $ret_stmt->execute([$return_id, 'pending']);
            $ret = $ret_stmt->fetch();

            if (!$ret) {
                $pdo->rollBack();
                flash('error', $lang['not_found']);
                redirect('returns.php');
            }

            // تحقق صلاحية الفرع
            if (!$is_super && $user_bid !== (int)$ret['branch_id']) {
                $pdo->rollBack();
                flash('error', $lang['unauthorized']);
                redirect('returns.php');
            }

            // جلب البنود
            $items_stmt = $pdo->prepare('SELECT * FROM return_items WHERE return_id = ?');
            $items_stmt->execute([$return_id]);
            $ret_items = $items_stmt->fetchAll();

            foreach ($ret_items as $item) {
                if ((int)$item['restock'] === 1) {
                    _return_restock($pdo, (int)$item['product_id'], (int)$ret['branch_id'], (float)$item['quantity'], $return_id);
                }
            }

            // تحديث الحالة
            $pdo->prepare("UPDATE returns SET status = 'approved' WHERE id = ? LIMIT 1")
                ->execute([$return_id]);

            $pdo->prepare("UPDATE invoices SET status = 'refunded' WHERE id = ? LIMIT 1")
                ->execute([(int)$ret['invoice_id']]);

            $pdo->commit();

            $audit->log('update', 'returns', $return_id, ['status' => 'pending'], ['status' => 'approved']);
            regenerate_csrf_token();
            flash('success', $is_rtl ? 'تمت الموافقة على المرتجع.' : 'Return approved.');

        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('[TopShine returns approve] ' . $e->getMessage());
            flash('error', $lang['transaction_failed']);
        }
        redirect('returns.php');
    }

    // ——— رفض مرتجع ———
    if ($action === 'reject_return' && $is_admin) {

        $return_id = sanitize_int($_POST['return_id'] ?? 0);
        if ($return_id > 0) {
            try {
                $ret_stmt = $pdo->prepare('SELECT branch_id FROM returns WHERE id = ? AND status = ? LIMIT 1');
                $ret_stmt->execute([$return_id, 'pending']);
                $ret = $ret_stmt->fetch();

                if ($ret && ($is_super || $user_bid === (int)$ret['branch_id'])) {
                    $pdo->prepare("UPDATE returns SET status = 'rejected' WHERE id = ? LIMIT 1")
                        ->execute([$return_id]);
                    $audit->log('update', 'returns', $return_id, ['status' => 'pending'], ['status' => 'rejected']);
                    regenerate_csrf_token();
                    flash('success', $is_rtl ? 'تم رفض المرتجع.' : 'Return rejected.');
                }
            } catch (Throwable $e) {
                error_log('[TopShine returns reject] ' . $e->getMessage());
                flash('error', $lang['error']);
            }
        }
        redirect('returns.php');
    }
}

// ============================================================
// دالة مساعدة: إعادة المخزون عند الموافقة
// ============================================================
function _return_restock(PDO $pdo, int $product_id, int $branch_id, float $qty, int $return_id): void
{
    // جلب الكمية الحالية
    $sel = $pdo->prepare('
        SELECT id, quantity FROM inventory
        WHERE product_id = ? AND branch_id = ?
        LIMIT 1
    ');
    $sel->execute([$product_id, $branch_id]);
    $row = $sel->fetch();

    $qty_before = (float)($row['quantity'] ?? 0);
    $qty_after  = $qty_before + $qty;

    if ($row) {
        $pdo->prepare('
            UPDATE inventory SET quantity = ?, updated_at = NOW()
            WHERE product_id = ? AND branch_id = ?
        ')->execute([$qty_after, $product_id, $branch_id]);
    } else {
        $pdo->prepare('
            INSERT INTO inventory (product_id, branch_id, quantity, min_quantity, updated_at)
            VALUES (?, ?, ?, 0, NOW())
        ')->execute([$product_id, $branch_id, $qty_after]);
    }

    // سجّل الحركة
    log_inventory_movement(
        $product_id,
        $branch_id,
        'return_in',
        $qty,
        $qty_before,
        $qty_after,
        $return_id,
        'returns',
        'إعادة من مرتجع',
        $pdo
    );
}

// ============================================================
// طلب طباعة إشعار مرتجع
// ============================================================
$print_id = sanitize_int($_GET['print'] ?? 0);

// ============================================================
// فلاتر القائمة
// ============================================================
$filter_status     = trim($_GET['status']      ?? '');
$filter_date_from  = trim($_GET['date_from']   ?? '');
$filter_date_to    = trim($_GET['date_to']     ?? '');
$filter_refund     = trim($_GET['refund_method'] ?? '');
$filter_branch     = $is_super ? sanitize_int($_GET['branch_id'] ?? 0) : $user_bid;

// بناء الـ WHERE
$where_parts = [];
$params      = [];

if (!$is_super) {
    $where_parts[] = 'r.branch_id = ?';
    $params[]      = $user_bid;
} elseif ($filter_branch > 0) {
    $where_parts[] = 'r.branch_id = ?';
    $params[]      = $filter_branch;
}

if (in_array($filter_status, ['pending', 'approved', 'rejected'], true)) {
    $where_parts[] = 'r.status = ?';
    $params[]      = $filter_status;
}

if ($filter_date_from !== '') {
    $where_parts[] = 'DATE(r.created_at) >= ?';
    $params[]      = $filter_date_from;
}

if ($filter_date_to !== '') {
    $where_parts[] = 'DATE(r.created_at) <= ?';
    $params[]      = $filter_date_to;
}

if (in_array($filter_refund, ['cash', 'wallet', 'exchange'], true)) {
    $where_parts[] = 'r.refund_method = ?';
    $params[]      = $filter_refund;
}

$where_sql = $where_parts ? 'WHERE ' . implode(' AND ', $where_parts) : '';

// Pagination
$current_page_num = max(1, (int)($_GET['p'] ?? 1));
$per_page         = 25;

$count_q  = "SELECT COUNT(*) FROM returns r $where_sql";
$count_st = $pdo->prepare($count_q);
$count_st->execute($params);
$total_records = (int)$count_st->fetchColumn();
$total_pages   = max(1, (int)ceil($total_records / $per_page));
$offset        = ($current_page_num - 1) * $per_page;

// جلب القائمة
$list_stmt = $pdo->prepare("
    SELECT
        r.id, r.return_number, r.total_refund, r.refund_method,
        r.reason, r.status, r.created_at,
        inv.invoice_number,
        b.name    AS branch_name,
        u.name    AS processed_by_name,
        COALESCE(c.name, '') AS customer_name
    FROM returns r
    LEFT JOIN invoices  inv ON inv.id = r.invoice_id
    LEFT JOIN branches  b   ON b.id   = r.branch_id
    LEFT JOIN users     u   ON u.id   = r.processed_by
    LEFT JOIN customers c   ON c.id   = inv.customer_id
    $where_sql
    ORDER BY r.created_at DESC
    LIMIT $per_page OFFSET $offset
");
$list_stmt->execute($params);
$returns_list = $list_stmt->fetchAll();

// قائمة الفروع للفلتر (super_admin)
$branches_list = [];
if ($is_super) {
    $br_stmt = $pdo->query("SELECT id, name FROM branches WHERE status='active' ORDER BY name");
    $branches_list = $br_stmt->fetchAll();
}

// ============================================================
// قسم الإنشاء الجديد
// ============================================================
$show_new_form = ($_GET['action'] ?? '') === 'new';

// إحصاء المعلقة للإشارة في الـ badge
$pending_count = 0;
if ($is_admin) {
    $pc_q = $is_super
        ? "SELECT COUNT(*) FROM returns WHERE status='pending'"
        : "SELECT COUNT(*) FROM returns WHERE status='pending' AND branch_id = ?";
    $pc_st = $pdo->prepare($pc_q);
    $is_super ? $pc_st->execute() : $pc_st->execute([$user_bid]);
    $pending_count = (int)$pc_st->fetchColumn();
}

$depth       = 1;
$assets_path = '../assets/';
?>

<?php require_once __DIR__ . '/../includes/layout/sidebar.php'; ?>

<div class="main-content">

    <?php show_flash(); ?>

    <!-- ================================================== -->
    <!-- طباعة إشعار المرتجع (يظهر مؤقتاً بعد الإنشاء)     -->
    <!-- ================================================== -->
    <?php if ($print_id > 0):
        $pr_stmt = $pdo->prepare('
            SELECT r.*, inv.invoice_number, b.name AS branch_name, u.name AS processed_by_name
            FROM returns r
            LEFT JOIN invoices inv ON inv.id = r.invoice_id
            LEFT JOIN branches b   ON b.id   = r.branch_id
            LEFT JOIN users    u   ON u.id   = r.processed_by
            WHERE r.id = ? LIMIT 1
        ');
        $pr_stmt->execute([$print_id]);
        $pr_ret = $pr_stmt->fetch();
        if ($pr_ret):
    ?>
    <div class="alert alert-success d-flex justify-content-between align-items-center mb-4">
        <span>
            <?= $is_rtl ? 'تم إنشاء مرتجع رقم:' : 'Return created:' ?>
            <strong><?= htmlspecialchars($pr_ret['return_number']) ?></strong>
        </span>
        <a href="../templates/return_receipt.php?id=<?= $print_id ?>" target="_blank"
           class="btn btn-sm" style="background:var(--ts-gold);color:#0D0D0D">
            <i class="bi bi-printer me-1"></i>
            <?= $lang['print'] ?>
        </a>
    </div>
    <?php endif; endif; ?>

    <!-- ================================================== -->
    <!-- رأس الصفحة                                          -->
    <!-- ================================================== -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <h4 class="mb-0" style="color:var(--ts-gold)">
            <i class="bi bi-arrow-return-left me-2"></i>
            <?= $lang['returns'] ?>
            <?php if ($pending_count > 0): ?>
                <span class="badge bg-warning text-dark ms-2"><?= $pending_count ?></span>
            <?php endif; ?>
        </h4>
        <?php if (!$show_new_form): ?>
        <a href="returns.php?action=new" class="btn btn-primary">
            <i class="bi bi-plus-lg me-1"></i>
            <?= $is_rtl ? 'إنشاء مرتجع جديد' : 'New Return' ?>
        </a>
        <?php else: ?>
        <a href="returns.php" class="btn btn-secondary">
            <i class="bi bi-arrow-right me-1"></i>
            <?= $is_rtl ? 'عودة للقائمة' : 'Back to List' ?>
        </a>
        <?php endif; ?>
    </div>

    <!-- ================================================== -->
    <!-- نموذج إنشاء مرتجع جديد                             -->
    <!-- ================================================== -->
    <?php if ($show_new_form): ?>
    <div class="card mb-4">
        <div class="card-header">
            <i class="bi bi-search me-2"></i>
            <?= $is_rtl ? 'البحث عن فاتورة' : 'Search Invoice' ?>
        </div>
        <div class="card-body">
            <!-- خطوة 1: البحث عن الفاتورة -->
            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <label class="form-label"><?= $lang['invoice_number'] ?></label>
                    <div class="input-group">
                        <input type="text" id="invoice_search_input" class="form-control"
                               placeholder="<?= $is_rtl ? 'TS-BR1-...' : 'TS-BR1-...' ?>"
                               style="background:var(--ts-bg-input);color:var(--ts-text-primary);border-color:var(--ts-border)">
                        <button class="btn btn-primary" id="btn_search_invoice" type="button">
                            <i class="bi bi-search"></i>
                            <?= $lang['search'] ?>
                        </button>
                    </div>
                </div>
            </div>

            <!-- نتائج الفاتورة -->
            <div id="invoice_result" class="d-none">
                <!-- معلومات الفاتورة -->
                <div id="invoice_info" class="alert mb-3" style="background:var(--ts-bg-dark);border:1px solid var(--ts-border-gold);color:var(--ts-text-primary)"></div>

                <!-- نموذج المرتجع -->
                <form method="POST" id="return_form">
                    <input type="hidden" name="action"     value="create_return">
                    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                    <input type="hidden" name="invoice_id" id="f_invoice_id" value="">
                    <input type="hidden" name="branch_id"  id="f_branch_id"  value="">

                    <!-- جدول البنود -->
                    <div class="table-responsive mb-3">
                        <table class="table table-hover align-middle">
                            <thead>
                                <tr>
                                    <th style="width:40px">
                                        <input type="checkbox" id="check_all" class="form-check-input">
                                    </th>
                                    <th><?= $lang['product_name'] ?></th>
                                    <th><?= $lang['unit_price'] ?></th>
                                    <th><?= $lang['returnable_qty'] ?></th>
                                    <th style="width:130px"><?= $lang['quantity'] ?></th>
                                    <th><?= $lang['total'] ?></th>
                                    <th><?= $lang['restock'] ?></th>
                                </tr>
                            </thead>
                            <tbody id="return_items_tbody"></tbody>
                        </table>
                    </div>

                    <!-- تفاصيل المرتجع -->
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label"><?= $lang['refund_method'] ?></label>
                            <select name="refund_method" class="form-select ts-select">
                                <option value="cash"><?= $lang['cash'] ?></option>
                                <option value="wallet"><?= $lang['wallet'] ?></option>
                                <option value="exchange"><?= $lang['exchange'] ?></option>
                            </select>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label"><?= $lang['return_reason'] ?></label>
                            <input type="text" name="reason" class="form-control ts-input"
                                   placeholder="<?= $is_rtl ? 'سبب الإرجاع...' : 'Return reason...' ?>">
                        </div>
                        <div class="col-12">
                            <div class="d-flex justify-content-between align-items-center">
                                <div class="fs-5 fw-bold" style="color:var(--ts-gold)">
                                    <?= $is_rtl ? 'إجمالي الاسترداد:' : 'Total Refund:' ?>
                                    <span id="total_refund_display">0.00 SDG</span>
                                </div>
                                <div>
                                    <?php if ($role === 'cashier'): ?>
                                        <div class="text-warning small mb-2">
                                            <i class="bi bi-info-circle me-1"></i>
                                            <?= $is_rtl ? 'سيُرسل الطلب للمدير للموافقة.' : 'Request will be sent to manager for approval.' ?>
                                        </div>
                                    <?php endif; ?>
                                    <button type="submit" class="btn btn-primary btn-lg" id="btn_save_return" disabled>
                                        <i class="bi bi-check-lg me-2"></i>
                                        <?= $role === 'cashier' ? ($is_rtl ? 'إرسال طلب المرتجع' : 'Send Return Request') : ($is_rtl ? 'تأكيد المرتجع' : 'Confirm Return') ?>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>

            <div id="invoice_error" class="alert alert-danger d-none"></div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ================================================== -->
    <!-- فلاتر القائمة                                       -->
    <!-- ================================================== -->
    <div class="card mb-4">
        <div class="card-body py-2">
            <form method="GET" class="row g-2 align-items-end">
                <?php if ($is_super): ?>
                <div class="col-md-2">
                    <select name="branch_id" class="form-select form-select-sm ts-select">
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
                    <select name="status" class="form-select form-select-sm ts-select">
                        <option value=""><?= $is_rtl ? 'كل الحالات' : 'All Status' ?></option>
                        <option value="pending"  <?= $filter_status === 'pending'  ? 'selected' : '' ?>><?= $lang['pending'] ?></option>
                        <option value="approved" <?= $filter_status === 'approved' ? 'selected' : '' ?>><?= $lang['approved'] ?></option>
                        <option value="rejected" <?= $filter_status === 'rejected' ? 'selected' : '' ?>><?= $lang['rejected'] ?></option>
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="refund_method" class="form-select form-select-sm ts-select">
                        <option value=""><?= $is_rtl ? 'طريقة الاسترداد' : 'Refund Method' ?></option>
                        <option value="cash"     <?= $filter_refund === 'cash'     ? 'selected' : '' ?>><?= $lang['cash'] ?></option>
                        <option value="wallet"   <?= $filter_refund === 'wallet'   ? 'selected' : '' ?>><?= $lang['wallet'] ?></option>
                        <option value="exchange" <?= $filter_refund === 'exchange' ? 'selected' : '' ?>><?= $lang['exchange'] ?></option>
                    </select>
                </div>
                <div class="col-md-2">
                    <input type="date" name="date_from" class="form-control form-control-sm ts-input"
                           value="<?= htmlspecialchars($filter_date_from) ?>" placeholder="<?= $lang['date_from'] ?>">
                </div>
                <div class="col-md-2">
                    <input type="date" name="date_to" class="form-control form-control-sm ts-input"
                           value="<?= htmlspecialchars($filter_date_to) ?>" placeholder="<?= $lang['date_to'] ?>">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-sm btn-primary w-100">
                        <i class="bi bi-funnel me-1"></i><?= $lang['filter'] ?>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ================================================== -->
    <!-- جدول المرتجعات                                      -->
    <!-- ================================================== -->
    <div class="card">
        <div class="card-body p-0">
            <?php if (empty($returns_list)): ?>
            <div class="text-center py-5 text-muted">
                <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                <?= $lang['no_results'] ?>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th><?= $lang['return_number'] ?></th>
                            <th><?= $lang['original_invoice'] ?></th>
                            <?php if ($is_super): ?><th><?= $lang['branch'] ?></th><?php endif; ?>
                            <th><?= $lang['customer'] ?></th>
                            <th><?= $lang['total_refund'] ?></th>
                            <th><?= $lang['refund_method'] ?></th>
                            <th><?= $lang['status'] ?></th>
                            <th><?= $lang['date'] ?></th>
                            <th class="text-center"><?= $lang['action'] ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($returns_list as $ret): ?>
                        <tr>
                            <td>
                                <span class="font-monospace small" style="color:var(--ts-gold)">
                                    <?= htmlspecialchars($ret['return_number']) ?>
                                </span>
                            </td>
                            <td>
                                <span class="small"><?= htmlspecialchars($ret['invoice_number'] ?? '—') ?></span>
                            </td>
                            <?php if ($is_super): ?>
                            <td><small><?= htmlspecialchars($ret['branch_name'] ?? '—') ?></small></td>
                            <?php endif; ?>
                            <td><?= htmlspecialchars($ret['customer_name'] ?: '—') ?></td>
                            <td style="color:var(--ts-gold)"><?= format_money((float)$ret['total_refund']) ?></td>
                            <td>
                                <?php
                                $rm_map = ['cash' => $lang['cash'], 'wallet' => $lang['wallet'], 'exchange' => $lang['exchange']];
                                echo htmlspecialchars($rm_map[$ret['refund_method']] ?? $ret['refund_method']);
                                ?>
                            </td>
                            <td>
                                <?php
                                $st_map = [
                                    'pending'  => ['warning', $lang['pending']],
                                    'approved' => ['success', $lang['approved']],
                                    'rejected' => ['danger',  $lang['rejected']],
                                ];
                                [$st_color, $st_label] = $st_map[$ret['status']] ?? ['secondary', $ret['status']];
                                ?>
                                <span class="badge bg-<?= $st_color ?>"><?= $st_label ?></span>
                            </td>
                            <td><small><?= format_datetime($ret['created_at']) ?></small></td>
                            <td class="text-center">
                                <div class="d-flex gap-1 justify-content-center flex-wrap">
                                    <!-- طباعة الإشعار -->
                                    <a href="../templates/return_receipt.php?id=<?= $ret['id'] ?>"
                                       target="_blank" class="btn btn-sm btn-outline-secondary" title="<?= $lang['print'] ?>">
                                        <i class="bi bi-printer"></i>
                                    </a>

                                    <!-- موافقة / رفض (admin فقط، pending فقط) -->
                                    <?php if ($is_admin && $ret['status'] === 'pending'): ?>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('<?= $is_rtl ? 'موافقة على هذا المرتجع؟' : 'Approve this return?' ?>')">
                                        <input type="hidden" name="action"     value="approve_return">
                                        <input type="hidden" name="return_id"  value="<?= $ret['id'] ?>">
                                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                        <button class="btn btn-sm btn-success" title="<?= $lang['approve'] ?>">
                                            <i class="bi bi-check-lg"></i>
                                        </button>
                                    </form>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('<?= $is_rtl ? 'رفض هذا المرتجع؟' : 'Reject this return?' ?>')">
                                        <input type="hidden" name="action"     value="reject_return">
                                        <input type="hidden" name="return_id"  value="<?= $ret['id'] ?>">
                                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                        <button class="btn btn-sm btn-danger" title="<?= $lang['reject'] ?>">
                                            <i class="bi bi-x-lg"></i>
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <div class="d-flex justify-content-between align-items-center p-3">
                <small class="text-muted">
                    <?= $lang['showing'] ?> <?= $offset + 1 ?> - <?= min($offset + $per_page, $total_records) ?>
                    <?= $lang['of'] ?> <?= $total_records ?> <?= $lang['results'] ?>
                </small>
                <nav>
                    <ul class="pagination pagination-sm mb-0">
                        <?php for ($p = 1; $p <= $total_pages; $p++):
                            $q = http_build_query(array_merge($_GET, ['p' => $p]));
                        ?>
                        <li class="page-item <?= $p === $current_page_num ? 'active' : '' ?>">
                            <a class="page-link" href="?<?= $q ?>"><?= $p ?></a>
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

<!-- ================================================== -->
<!-- JavaScript                                          -->
<!-- ================================================== -->
<script>
(function () {
    'use strict';

    const isRtl         = <?= $is_rtl ? 'true' : 'false' ?>;
    const btnSearch     = document.getElementById('btn_search_invoice');
    const searchInput   = document.getElementById('invoice_search_input');
    const resultDiv     = document.getElementById('invoice_result');
    const errorDiv      = document.getElementById('invoice_error');
    const infoDiv       = document.getElementById('invoice_info');
    const tbody         = document.getElementById('return_items_tbody');
    const fInvoiceId    = document.getElementById('f_invoice_id');
    const fBranchId     = document.getElementById('f_branch_id');
    const totalDisplay  = document.getElementById('total_refund_display');
    const btnSave       = document.getElementById('btn_save_return');
    const checkAll      = document.getElementById('check_all');

    if (!btnSearch) return; // صفحة القائمة فقط

    // ——— البحث عن الفاتورة ———
    function searchInvoice() {
        const num = searchInput.value.trim();
        if (!num) return;

        btnSearch.disabled = true;
        btnSearch.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

        fetch(`../api/get_invoice_for_return.php?invoice_number=${encodeURIComponent(num)}`)
            .then(r => r.json())
            .then(data => {
                btnSearch.disabled = false;
                btnSearch.innerHTML = `<i class="bi bi-search"></i> ${isRtl ? 'بحث' : 'Search'}`;

                if (!data.success) {
                    resultDiv.classList.add('d-none');
                    errorDiv.classList.remove('d-none');
                    errorDiv.textContent = data.message;
                    return;
                }

                errorDiv.classList.add('d-none');
                resultDiv.classList.remove('d-none');

                const inv = data.invoice;
                fInvoiceId.value = inv.id;
                fBranchId.value  = inv.branch_id;

                // معلومات الفاتورة
                infoDiv.innerHTML = `
                    <div class="row g-2">
                        <div class="col-sm-6">
                            <strong>${isRtl ? 'رقم الفاتورة:' : 'Invoice #:'}</strong>
                            <span class="font-monospace ms-1">${inv.invoice_number}</span>
                        </div>
                        <div class="col-sm-6">
                            <strong>${isRtl ? 'الفرع:' : 'Branch:'}</strong>
                            <span class="ms-1">${inv.branch_name}</span>
                        </div>
                        <div class="col-sm-6">
                            <strong>${isRtl ? 'الكاشير:' : 'Cashier:'}</strong>
                            <span class="ms-1">${inv.cashier_name}</span>
                        </div>
                        <div class="col-sm-6">
                            <strong>${isRtl ? 'العميل:' : 'Customer:'}</strong>
                            <span class="ms-1">${inv.customer_name || '—'}</span>
                        </div>
                        <div class="col-sm-6">
                            <strong>${isRtl ? 'الإجمالي:' : 'Total:'}</strong>
                            <span class="ms-1" style="color:var(--ts-gold)">${inv.total.toFixed(2)} SDG</span>
                        </div>
                        <div class="col-sm-6">
                            <strong>${isRtl ? 'التاريخ:' : 'Date:'}</strong>
                            <span class="ms-1">${inv.created_at}</span>
                        </div>
                    </div>`;

                // بناء صفوف البنود
                tbody.innerHTML = '';
                inv.items.forEach((item, idx) => {
                    const row = document.createElement('tr');
                    row.dataset.unitPrice    = item.unit_price;
                    row.dataset.returnableQty = item.returnable_qty;
                    row.innerHTML = `
                        <td>
                            <input type="checkbox" class="form-check-input item-check"
                                   name="items[${idx}][selected]" value="1">
                            <input type="hidden" name="items[${idx}][invoice_item_id]" value="${item.invoice_item_id}">
                            <input type="hidden" name="items[${idx}][product_id]"      value="${item.product_id}">
                            <input type="hidden" class="item-unit-price"
                                   name="items[${idx}][unit_price]" value="${item.unit_price}">
                        </td>
                        <td>
                            <div class="fw-semibold">${item.product_name}</div>
                            <small class="text-muted">${item.unit}</small>
                        </td>
                        <td>${item.unit_price.toFixed(2)} SDG</td>
                        <td>${item.returnable_qty}</td>
                        <td>
                            <input type="number" class="form-control form-control-sm ts-input item-qty"
                                   name="items[${idx}][quantity]"
                                   min="0.001" max="${item.returnable_qty}"
                                   step="0.001" value="${item.returnable_qty}"
                                   style="width:110px" disabled>
                        </td>
                        <td class="item-total" style="color:var(--ts-gold)">0.00 SDG</td>
                        <td>
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input item-restock"
                                       name="items[${idx}][restock]" value="1" checked>
                                <label class="form-check-label small">
                                    ${isRtl ? 'نعم' : 'Yes'}
                                </label>
                            </div>
                        </td>`;
                    tbody.appendChild(row);
                });

                bindItemEvents();
                recalcTotal();
            })
            .catch(() => {
                btnSearch.disabled = false;
                btnSearch.innerHTML = `<i class="bi bi-search"></i> ${isRtl ? 'بحث' : 'Search'}`;
                errorDiv.classList.remove('d-none');
                errorDiv.textContent = isRtl ? 'حدث خطأ في الاتصال.' : 'Connection error.';
            });
    }

    function bindItemEvents() {
        document.querySelectorAll('.item-check').forEach(chk => {
            chk.addEventListener('change', function () {
                const row = this.closest('tr');
                const qtyInput = row.querySelector('.item-qty');
                qtyInput.disabled = !this.checked;
                if (!this.checked) {
                    row.querySelector('.item-total').textContent = '0.00 SDG';
                } else {
                    updateRowTotal(row);
                }
                recalcTotal();
            });
        });

        document.querySelectorAll('.item-qty').forEach(inp => {
            inp.addEventListener('input', function () {
                const row = this.closest('tr');
                const max = parseFloat(row.dataset.returnableQty);
                if (parseFloat(this.value) > max) this.value = max;
                updateRowTotal(row);
                recalcTotal();
            });
        });
    }

    function updateRowTotal(row) {
        const chk   = row.querySelector('.item-check');
        if (!chk.checked) return;
        const qty   = parseFloat(row.querySelector('.item-qty').value) || 0;
        const price = parseFloat(row.querySelector('.item-unit-price').value) || 0;
        row.querySelector('.item-total').textContent = (qty * price).toFixed(2) + ' SDG';
    }

    function recalcTotal() {
        let total = 0;
        let hasChecked = false;
        document.querySelectorAll('.item-check').forEach(chk => {
            if (chk.checked) {
                hasChecked = true;
                const row   = chk.closest('tr');
                const qty   = parseFloat(row.querySelector('.item-qty').value) || 0;
                const price = parseFloat(row.querySelector('.item-unit-price').value) || 0;
                total += qty * price;
            }
        });
        if (totalDisplay) totalDisplay.textContent = total.toFixed(2) + ' SDG';
        if (btnSave) btnSave.disabled = !hasChecked;
    }

    // تحديد الكل
    if (checkAll) {
        checkAll.addEventListener('change', function () {
            document.querySelectorAll('.item-check').forEach(chk => {
                chk.checked = this.checked;
                const row = chk.closest('tr');
                row.querySelector('.item-qty').disabled = !this.checked;
                if (!this.checked) row.querySelector('.item-total').textContent = '0.00 SDG';
                else updateRowTotal(row);
            });
            recalcTotal();
        });
    }

    // ——— أحداث ———
    if (btnSearch)  btnSearch.addEventListener('click', searchInvoice);
    if (searchInput) {
        searchInput.addEventListener('keydown', e => {
            if (e.key === 'Enter') { e.preventDefault(); searchInvoice(); }
        });
    }

})();
</script>

<?php require_once __DIR__ . '/../includes/layout/footer.php'; ?>
