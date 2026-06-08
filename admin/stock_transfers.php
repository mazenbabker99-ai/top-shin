<?php
// ============================================================
// المسار: admin/stock_transfers.php
// الوظيفة: طلب تحويل المخزون بين الفروع (branch_admin)
//          + الموافقة / الرفض (super_admin)
// الصلاحية: super_admin | branch_admin
// ============================================================

declare(strict_types=1);

require_once __DIR__ . '/../includes/layout/header.php';
require_role(['super_admin', 'branch_admin']);

$pdo      = db();
$audit    = new AuditLogger($pdo);
$role     = Auth::getRole();
$user_id  = (int) ($_SESSION['user_id']  ?? 0);
$user_bid = Auth::getBranchId();          // int|null  (null = central)
$is_super = ($role === 'super_admin');

// ================================================================
// معالجة POST
// ================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submitted_token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($submitted_token)) {
        flash('error', $lang['csrf_invalid']);
        redirect('stock_transfers.php');
    }

    $action = $_POST['action'] ?? '';

    // ----------------------------------------------------------------
    // إنشاء طلب تحويل جديد (branch_admin)
    // ----------------------------------------------------------------
    if ($action === 'create_transfer') {

        if ($is_super) {
            flash('error', $lang['unauthorized']);
            redirect('stock_transfers.php');
        }

        $to_branch_id = sanitize_int($_POST['to_branch_id'] ?? 0);
        $notes        = trim($_POST['notes'] ?? '');
        $items_raw    = $_POST['items'] ?? [];

        if ($to_branch_id <= 0 || empty($items_raw)) {
            flash('error', $lang['required_field']);
            redirect('stock_transfers.php');
        }

        // تحقق أن الوجهة ليست نفس فرع المستخدم
        if ($to_branch_id === $user_bid) {
            flash('error', $is_rtl ? 'لا يمكن التحويل لنفس الفرع.' : 'Cannot transfer to the same branch.');
            redirect('stock_transfers.php');
        }

        // FIX #8: تحقق أن الفرع الوجهة موجود ونشط
        $br_check = $pdo->prepare("SELECT id FROM branches WHERE id = ? AND status = 'active' LIMIT 1");
        $br_check->execute([$to_branch_id]);
        if (!$br_check->fetch()) {
            flash('error', $lang['not_found']);
            redirect('stock_transfers.php');
        }

        // تحقق من صحة البنود + FIX #5: إزالة التكرار
        $items     = [];
        $seen_pids = [];
        foreach ($items_raw as $item) {
            $pid = sanitize_int($item['product_id'] ?? 0);
            $qty = (float) filter_var($item['quantity'] ?? 0, FILTER_VALIDATE_FLOAT);
            if ($pid > 0 && $qty > 0 && !isset($seen_pids[$pid])) {
                $items[]          = ['product_id' => $pid, 'quantity' => $qty];
                $seen_pids[$pid]  = true;
            }
        }

        if (empty($items)) {
            flash('error', $lang['required_field']);
            redirect('stock_transfers.php');
        }

        try {
            $pdo->beginTransaction();

            // FIX #2: توليد رقم التحويل داخل المعاملة
            $transfer_num = generate_transfer_number($pdo);

            $ins = $pdo->prepare('
                INSERT INTO stock_transfers
                    (transfer_number, from_branch_id, to_branch_id, status, notes, created_by)
                VALUES (?, ?, ?, \'pending\', ?, ?)
            ');
            $ins->execute([$transfer_num, $user_bid, $to_branch_id, $notes, $user_id]);
            $transfer_id = (int) $pdo->lastInsertId();

            $ins_item = $pdo->prepare('
                INSERT INTO stock_transfer_items (transfer_id, product_id, quantity)
                VALUES (?, ?, ?)
            ');
            foreach ($items as $item) {
                $ins_item->execute([$transfer_id, $item['product_id'], $item['quantity']]);
            }

            $audit->created('stock_transfers', $transfer_id, [
                'transfer_number' => $transfer_num,
                'to_branch_id'    => $to_branch_id,
                'items_count'     => count($items),
            ]);

            $pdo->commit();
            flash('success', ($is_rtl
                ? "تم إنشاء طلب التحويل #{$transfer_num} بنجاح."
                : "Transfer request #{$transfer_num} created successfully."));

        } catch (\Throwable $e) {
            $pdo->rollBack();
            error_log('[stock_transfers.create] ' . $e->getMessage());
            flash('error', $lang['transaction_failed']);
        }

        redirect('stock_transfers.php');
    }

    // ----------------------------------------------------------------
    // موافقة (super_admin فقط)
    // ----------------------------------------------------------------
    if ($action === 'approve_transfer') {

        if (!$is_super) {
            flash('error', $lang['unauthorized']);
            redirect('stock_transfers.php');
        }

        $transfer_id = sanitize_int($_POST['transfer_id'] ?? 0);
        if ($transfer_id <= 0) {
            flash('error', $lang['invalid_input']);
            redirect('stock_transfers.php');
        }

        try {
            $pdo->beginTransaction();

            $tf = $pdo->prepare("
                SELECT * FROM stock_transfers WHERE id = ? AND status = 'pending'
            ");
            $tf->execute([$transfer_id]);
            $transfer = $tf->fetch();

            if (!$transfer) {
                $pdo->rollBack();
                flash('error', $lang['not_found']);
                redirect('stock_transfers.php');
            }

            // FIX #4: تمييز صريح بين NULL (مركزي) وعدد صحيح
            $from_bid_raw = $transfer['from_branch_id'];
            $from_bid     = ($from_bid_raw === null) ? null : (int) $from_bid_raw;
            $to_bid       = (int) $transfer['to_branch_id'];

            $items_stmt = $pdo->prepare('
                SELECT sti.product_id, sti.quantity, p.name_ar
                FROM stock_transfer_items sti
                JOIN products p ON p.id = sti.product_id
                WHERE sti.transfer_id = ?
            ');
            $items_stmt->execute([$transfer_id]);
            $items = $items_stmt->fetchAll();

            foreach ($items as $item) {
                $pid = (int) $item['product_id'];
                $qty = (float) $item['quantity'];

                // FIX #4: استخدام IS NULL صريح بدلاً من مقارنة القيمة
                if ($from_bid === null) {
                    $sel = $pdo->prepare('
                        SELECT quantity FROM inventory
                        WHERE product_id = ? AND branch_id IS NULL
                        LIMIT 1
                    ');
                    $sel->execute([$pid]);
                } else {
                    $sel = $pdo->prepare('
                        SELECT quantity FROM inventory
                        WHERE product_id = ? AND branch_id = ?
                        LIMIT 1
                    ');
                    $sel->execute([$pid, $from_bid]);
                }
                $from_qty = (float) ($sel->fetchColumn() ?: 0);

                if ($from_qty < $qty) {
                    $pdo->rollBack();
                    $pname = htmlspecialchars($item['name_ar'], ENT_QUOTES, 'UTF-8');
                    flash('error', sprintf(
                        $lang['stock_insufficient'],
                        $pname . " ({$from_qty} " . ($is_rtl ? 'متاح' : 'available') . ")"
                    ));
                    redirect('stock_transfers.php');
                }

                $new_from = $from_qty - $qty;

                if ($from_bid === null) {
                    $upd_from = $pdo->prepare('
                        UPDATE inventory SET quantity = ?, updated_at = NOW()
                        WHERE product_id = ? AND branch_id IS NULL
                    ');
                    $upd_from->execute([$new_from, $pid]);
                } else {
                    $upd_from = $pdo->prepare('
                        UPDATE inventory SET quantity = ?, updated_at = NOW()
                        WHERE product_id = ? AND branch_id = ?
                    ');
                    $upd_from->execute([$new_from, $pid, $from_bid]);
                }

                log_inventory_movement(
                    $pdo, $pid, $from_bid,
                    'transfer_out', -$qty, $from_qty, $new_from,
                    $transfer_id, 'stock_transfer',
                    $is_rtl
                        ? "تحويل صادر #{$transfer['transfer_number']}"
                        : "Transfer out #{$transfer['transfer_number']}"
                );

                $sel_to = $pdo->prepare('
                    SELECT quantity FROM inventory
                    WHERE product_id = ? AND branch_id = ?
                    LIMIT 1
                ');
                $sel_to->execute([$pid, $to_bid]);
                $to_qty_row    = $sel_to->fetch();
                $to_qty_before = (float) ($to_qty_row['quantity'] ?? 0);
                $to_qty_after  = $to_qty_before + $qty;

                if ($to_qty_row) {
                    $upd_to = $pdo->prepare('
                        UPDATE inventory SET quantity = ?, updated_at = NOW()
                        WHERE product_id = ? AND branch_id = ?
                    ');
                    $upd_to->execute([$to_qty_after, $pid, $to_bid]);
                } else {
                    $ins_to = $pdo->prepare('
                        INSERT INTO inventory (product_id, branch_id, quantity, min_quantity, updated_at)
                        VALUES (?, ?, ?, 0, NOW())
                    ');
                    $ins_to->execute([$pid, $to_bid, $to_qty_after]);
                }

                log_inventory_movement(
                    $pdo, $pid, $to_bid,
                    'transfer_in', $qty, $to_qty_before, $to_qty_after,
                    $transfer_id, 'stock_transfer',
                    $is_rtl
                        ? "تحويل وارد #{$transfer['transfer_number']}"
                        : "Transfer in #{$transfer['transfer_number']}"
                );
            }

            $upd_tf = $pdo->prepare("
                UPDATE stock_transfers
                SET status = 'approved', approved_by = ?, approved_at = NOW()
                WHERE id = ?
            ");
            $upd_tf->execute([$user_id, $transfer_id]);

            $audit->updated('stock_transfers', $transfer_id,
                ['status' => 'pending'],
                ['status' => 'approved', 'approved_by' => $user_id]
            );

            $pdo->commit();
            flash('success', $is_rtl
                ? 'تمت الموافقة على التحويل وتحديث المخزون.'
                : 'Transfer approved and inventory updated.');

        } catch (\Throwable $e) {
            $pdo->rollBack();
            error_log('[stock_transfers.approve] ' . $e->getMessage());
            flash('error', $lang['transaction_failed']);
        }

        redirect('stock_transfers.php');
    }

    // ----------------------------------------------------------------
    // رفض (super_admin فقط)
    // ----------------------------------------------------------------
    if ($action === 'reject_transfer') {

        if (!$is_super) {
            flash('error', $lang['unauthorized']);
            redirect('stock_transfers.php');
        }

        $transfer_id = sanitize_int($_POST['transfer_id'] ?? 0);
        if ($transfer_id <= 0) {
            flash('error', $lang['invalid_input']);
            redirect('stock_transfers.php');
        }

        try {
            // FIX #7: استخدام rejected_at بدلاً من approved_at عند الرفض
            $upd = $pdo->prepare("
                UPDATE stock_transfers
                SET status = 'rejected', approved_by = ?, rejected_at = NOW()
                WHERE id = ? AND status = 'pending'
            ");
            $upd->execute([$user_id, $transfer_id]);

            $audit->updated('stock_transfers', $transfer_id,
                ['status' => 'pending'],
                ['status' => 'rejected']
            );

            flash('success', $is_rtl ? 'تم رفض طلب التحويل.' : 'Transfer request rejected.');

        } catch (\Throwable $e) {
            error_log('[stock_transfers.reject] ' . $e->getMessage());
            flash('error', $lang['error']);
        }

        redirect('stock_transfers.php');
    }
}

// ================================================================
// جلب البيانات للعرض
// ================================================================

$all_branches_stmt = $pdo->query("SELECT id, name FROM branches WHERE status = 'active' ORDER BY name");
$all_branches      = $all_branches_stmt->fetchAll();

$prods_stmt = $pdo->query("
    SELECT p.id, p.name_ar, p.name_en, p.unit,
           COALESCE((
               SELECT inv.quantity FROM inventory inv
               WHERE inv.product_id = p.id AND inv.branch_id IS NULL
               LIMIT 1
           ), 0) AS central_qty
    FROM products p
    WHERE p.status = 'active'
    ORDER BY p.name_ar
");
$all_products = $prods_stmt->fetchAll();

// ——— جلب التحويلات ———
$tf_where  = ['1=1'];
$tf_params = [];

if (!$is_super) {
    $tf_where[]  = '(st.from_branch_id = ? OR st.to_branch_id = ?)';
    $tf_params[] = $user_bid;
    $tf_params[] = $user_bid;
}

$status_filter = in_array($_GET['status'] ?? '', ['pending', 'approved', 'rejected'], true)
               ? $_GET['status']
               : '';
if ($status_filter !== '') {
    $tf_where[]  = 'st.status = ?';
    $tf_params[] = $status_filter;
}

// FIX #6: رفع الحد إلى 500 وعرض تحذير عند وجود طلبات معلقة مخفية
$transfers_stmt = $pdo->prepare('
    SELECT
        st.id, st.transfer_number, st.status, st.notes, st.created_at,
        st.approved_at,
        bf.name AS from_branch_name,
        bt.name AS to_branch_name,
        uc.name AS created_by_name,
        ua.name AS approved_by_name,
        (SELECT COUNT(*) FROM stock_transfer_items sti WHERE sti.transfer_id = st.id) AS items_count
    FROM stock_transfers st
    LEFT JOIN branches bf ON bf.id = st.from_branch_id
    LEFT JOIN branches bt ON bt.id = st.to_branch_id
    LEFT JOIN users    uc ON uc.id = st.created_by
    LEFT JOIN users    ua ON ua.id = st.approved_by
    WHERE ' . implode(' AND ', $tf_where) . '
    ORDER BY st.id DESC
    LIMIT 500
');
$transfers_stmt->execute($tf_params);
$transfers = $transfers_stmt->fetchAll();

// عدد الطلبات المعلقة الحقيقي من قاعدة البيانات (لا من القائمة المحدودة)
$pending_real_stmt = $pdo->prepare('
    SELECT COUNT(*) FROM stock_transfers st
    WHERE st.status = \'pending\'
    ' . (!$is_super ? 'AND (st.from_branch_id = ? OR st.to_branch_id = ?)' : '')
);
if (!$is_super) {
    $pending_real_stmt->execute([$user_bid, $user_bid]);
} else {
    $pending_real_stmt->execute([]);
}
$pending_count = (int) $pending_real_stmt->fetchColumn();

$csrf_token = generate_csrf_token();

$status_map = [
    'pending'  => ['label' => $lang['pending'],  'class' => 'bg-warning text-dark'],
    'approved' => ['label' => $lang['approved'],  'class' => 'bg-success'],
    'rejected' => ['label' => $lang['rejected'],  'class' => 'bg-danger'],
];

// FIX #3: جلب بنود التحويلات المرئية للمستخدم الحالي فقط
$visible_ids = array_column($transfers, 'id');
$transfer_items_map = [];
if (!empty($visible_ids)) {
    $placeholders = implode(',', array_fill(0, count($visible_ids), '?'));
    $items_map_stmt = $pdo->prepare("
        SELECT sti.transfer_id, sti.quantity,
               p.name_ar, p.name_en, p.unit
        FROM stock_transfer_items sti
        JOIN products p ON p.id = sti.product_id
        WHERE sti.transfer_id IN ($placeholders)
        ORDER BY sti.transfer_id, p.name_ar
    ");
    $items_map_stmt->execute($visible_ids);
    foreach ($items_map_stmt->fetchAll() as $r) {
        $transfer_items_map[(int)$r['transfer_id']][] = [
            'name' => $lang_code === 'ar' ? $r['name_ar'] : ($r['name_en'] ?: $r['name_ar']),
            'qty'  => (float) $r['quantity'],
            'unit' => $r['unit'],
        ];
    }
}
?>

<?php include __DIR__ . '/../includes/layout/sidebar.php'; ?>

<div class="main-content">

    <?php show_flash(); ?>

    <!-- رأس الصفحة -->
    <div class="page-header d-flex align-items-center justify-content-between mb-3">
        <div>
            <h4 class="page-title mb-0">
                <i class="bi bi-arrow-left-right me-2" style="color:var(--ts-gold);"></i>
                <?= $lang['stock_transfers'] ?>
            </h4>
            <?php if ($pending_count > 0): ?>
            <small style="color:var(--ts-warning);">
                <?= $pending_count ?> <?= $is_rtl ? 'طلب معلق' : 'pending request(s)' ?>
            </small>
            <?php endif; ?>
        </div>
        <?php if (!$is_super): ?>
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalCreate">
            <i class="bi bi-plus-lg me-1"></i>
            <?= $is_rtl ? 'طلب تحويل جديد' : 'New Transfer Request' ?>
        </button>
        <?php endif; ?>
    </div>

    <!-- فلتر الحالة -->
    <div class="card ts-card mb-3">
        <div class="card-body py-2">
            <form method="GET" class="d-flex gap-2 align-items-center flex-wrap">
                <label style="font-size:.85rem; color:var(--ts-text-muted);">
                    <?= $is_rtl ? 'فلترة بالحالة:' : 'Filter by status:' ?>
                </label>
                <?php
                $statuses = [
                    ''         => ($is_rtl ? 'الكل' : 'All'),
                    'pending'  => $lang['pending'],
                    'approved' => $lang['approved'],
                    'rejected' => $lang['rejected'],
                ];
                foreach ($statuses as $val => $lbl):
                ?>
                    <a href="?status=<?= urlencode($val) ?>"
                       class="btn btn-sm <?= $status_filter === $val ? 'btn-primary' : 'btn-outline-secondary' ?>">
                        <?= $lbl ?>
                        <?php if ($val === 'pending' && $pending_count > 0): ?>
                            <span class="badge bg-danger ms-1"><?= $pending_count ?></span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </form>
        </div>
    </div>

    <!-- ============================================================
         جدول التحويلات
         ============================================================ -->
    <div class="card ts-card">
        <div class="card-header">
            <span style="color:var(--ts-gold); font-weight:700;">
                <i class="bi bi-list-ul me-1"></i>
                <?= $is_rtl ? 'سجل التحويلات' : 'Transfer Records' ?>
            </span>
        </div>
        <div class="card-body p-0">
            <?php if (empty($transfers)): ?>
                <div class="text-center py-5" style="color:var(--ts-text-muted);">
                    <i class="bi bi-arrow-left-right"
                       style="font-size:2.5rem; opacity:.3; display:block; margin-bottom:.75rem;"></i>
                    <?= $lang['no_results'] ?>
                </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table ts-table mb-0">
                    <thead>
                        <tr>
                            <th><?= $is_rtl ? 'رقم التحويل' : 'Transfer #' ?></th>
                            <th><?= $lang['transfer_from'] ?></th>
                            <th><?= $lang['transfer_to'] ?></th>
                            <th class="text-center"><?= $is_rtl ? 'عدد البنود' : 'Items' ?></th>
                            <th><?= $lang['status'] ?></th>
                            <th><?= $is_rtl ? 'منشئ الطلب' : 'Created By' ?></th>
                            <th><?= $lang['date'] ?></th>
                            <?php if ($is_super): ?>
                            <th class="text-center"><?= $is_rtl ? 'الإجراء' : 'Action' ?></th>
                            <?php endif; ?>
                            <th class="text-center"><?= $lang['details'] ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($transfers as $tf):
                        $st = $status_map[$tf['status']] ?? ['label' => $tf['status'], 'class' => 'bg-secondary'];
                    ?>
                        <tr>
                            <td>
                                <code style="color:var(--ts-gold); font-size:.85rem;">
                                    <?= htmlspecialchars($tf['transfer_number'], ENT_QUOTES, 'UTF-8') ?>
                                </code>
                            </td>
                            <td><?= htmlspecialchars(
                                $tf['from_branch_name'] ?? ($is_rtl ? 'مركزي' : 'Central'),
                                ENT_QUOTES, 'UTF-8'
                            ) ?></td>
                            <td><?= htmlspecialchars($tf['to_branch_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="text-center">
                                <span class="badge" style="background:var(--ts-bg-dark); color:var(--ts-gold);">
                                    <?= (int) $tf['items_count'] ?>
                                </span>
                            </td>
                            <td><span class="badge <?= $st['class'] ?>"><?= $st['label'] ?></span></td>
                            <td style="color:var(--ts-text-secondary); font-size:.85rem;">
                                <?= htmlspecialchars($tf['created_by_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <td style="color:var(--ts-text-muted); font-size:.82rem; white-space:nowrap;">
                                <?= htmlspecialchars(format_datetime($tf['created_at']), ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <?php if ($is_super): ?>
                            <td class="text-center">
                                <?php if ($tf['status'] === 'pending'): ?>
                                <div class="d-flex gap-1 justify-content-center">
                                    <!-- FIX #1: نقل confirm إلى JS منفصل بدلاً من inline string -->
                                    <form method="POST" class="d-inline form-approve-reject"
                                          data-confirm="<?= $is_rtl ? 'موافقة على التحويل؟' : 'Approve this transfer?' ?>">
                                        <input type="hidden" name="action"      value="approve_transfer">
                                        <input type="hidden" name="csrf_token"  value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="transfer_id" value="<?= (int) $tf['id'] ?>">
                                        <button type="submit" class="btn btn-success btn-xs">
                                            <i class="bi bi-check-lg me-1"></i><?= $lang['approve'] ?>
                                        </button>
                                    </form>
                                    <form method="POST" class="d-inline form-approve-reject"
                                          data-confirm="<?= $is_rtl ? 'رفض هذا الطلب؟' : 'Reject this transfer?' ?>">
                                        <input type="hidden" name="action"      value="reject_transfer">
                                        <input type="hidden" name="csrf_token"  value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="transfer_id" value="<?= (int) $tf['id'] ?>">
                                        <button type="submit" class="btn btn-danger btn-xs">
                                            <i class="bi bi-x-lg me-1"></i><?= $lang['reject'] ?>
                                        </button>
                                    </form>
                                </div>
                                <?php elseif ($tf['status'] === 'approved'): ?>
                                    <small style="color:var(--ts-text-muted);">
                                        <?= htmlspecialchars($tf['approved_by_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                                    </small>
                                <?php else: ?>
                                    <span style="color:var(--ts-text-muted);">—</span>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                            <td class="text-center">
                                <button class="btn btn-outline-secondary btn-xs btn-view-transfer"
                                        data-id="<?= (int) $tf['id'] ?>"
                                        data-num="<?= htmlspecialchars($tf['transfer_number'], ENT_QUOTES, 'UTF-8') ?>">
                                    <i class="bi bi-eye"></i>
                                </button>
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
     Modal — إنشاء طلب تحويل (branch_admin فقط)
     ============================================================ -->
<?php if (!$is_super): ?>
<div class="modal fade" id="modalCreate" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content ts-modal">
            <div class="modal-header">
                <h5 class="modal-title" style="color:var(--ts-gold);">
                    <i class="bi bi-arrow-left-right me-2"></i>
                    <?= $is_rtl ? 'إنشاء طلب تحويل مخزون' : 'Create Stock Transfer Request' ?>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="formCreate">
                <input type="hidden" name="action"     value="create_transfer">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">

                <div class="modal-body">

                    <div class="mb-3">
                        <label class="form-label">
                            <?= $lang['transfer_to'] ?> <span class="text-danger">*</span>
                        </label>
                        <select name="to_branch_id" class="form-select ts-input" required id="selToBranch">
                            <option value=""><?= $is_rtl ? 'اختر الفرع الوجهة...' : 'Select destination branch...' ?></option>
                            <?php foreach ($all_branches as $br): ?>
                                <?php if ((int) $br['id'] !== $user_bid): ?>
                                <option value="<?= (int) $br['id'] ?>">
                                    <?= htmlspecialchars($br['name'], ENT_QUOTES, 'UTF-8') ?>
                                </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <label class="form-label mb-0">
                                <?= $is_rtl ? 'البنود المطلوب تحويلها' : 'Items to Transfer' ?>
                                <span class="text-danger">*</span>
                            </label>
                            <button type="button" class="btn btn-outline-secondary btn-xs" id="btnAddRow">
                                <i class="bi bi-plus-lg"></i> <?= $is_rtl ? 'إضافة بند' : 'Add Item' ?>
                            </button>
                        </div>

                        <div class="table-responsive">
                            <table class="table ts-table" id="tblItems">
                                <thead>
                                    <tr>
                                        <th><?= $lang['product_name'] ?></th>
                                        <th class="text-center"><?= $is_rtl ? 'المخزون المركزي' : 'Central Stock' ?></th>
                                        <th class="text-center" style="width:130px;"><?= $lang['quantity'] ?></th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody id="itemsBody"></tbody>
                            </table>
                        </div>
                        <div id="noItemsMsg" class="text-center py-3"
                             style="color:var(--ts-text-muted); font-size:.85rem;">
                            <?= $is_rtl ? 'اضغط "إضافة بند" لإضافة منتج للتحويل.' : 'Click "Add Item" to add a product.' ?>
                        </div>
                    </div>

                    <div class="mb-1">
                        <label class="form-label"><?= $lang['notes'] ?></label>
                        <textarea name="notes" class="form-control ts-input" rows="2"
                                  placeholder="<?= $is_rtl ? 'ملاحظات اختيارية...' : 'Optional notes...' ?>"></textarea>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">
                        <?= $lang['cancel'] ?>
                    </button>
                    <button type="submit" class="btn btn-primary btn-sm" id="btnSubmitTransfer" disabled>
                        <i class="bi bi-send me-1"></i>
                        <?= $is_rtl ? 'إرسال الطلب' : 'Submit Request' ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ============================================================
     Modal — تفاصيل التحويل
     ============================================================ -->
<div class="modal fade" id="modalDetails" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content ts-modal">
            <div class="modal-header">
                <h5 class="modal-title" id="detailsTitle" style="color:var(--ts-gold);">
                    <i class="bi bi-info-circle me-2"></i>
                    <?= $is_rtl ? 'تفاصيل التحويل' : 'Transfer Details' ?>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="detailsBody">
                <div class="text-center py-4" style="color:var(--ts-text-muted);">
                    <div class="spinner-border spinner-border-sm me-2"></div>
                    <?= $lang['loading'] ?>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">
                    <?= $lang['close'] ?>
                </button>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/layout/footer.php'; ?>

<script>
(function () {
    'use strict';

    // ——— بيانات المنتجات ———
    var allProducts = <?= json_encode(
        array_map(static fn ($p) => [
            'id'      => (int) $p['id'],
            'name'    => $lang_code === 'ar' ? $p['name_ar'] : ($p['name_en'] ?: $p['name_ar']),
            'unit'    => $p['unit'],
            'central' => (float) $p['central_qty'],
        ], $all_products),
        JSON_UNESCAPED_UNICODE
    ) ?>;

    // FIX #3: فقط بنود التحويلات المرئية للمستخدم الحالي
    var transferItems = <?= json_encode($transfer_items_map, JSON_UNESCAPED_UNICODE) ?>;

    var rowIndex = 0;

    function updateSubmitBtn() {
        var rows    = document.querySelectorAll('#itemsBody tr');
        var hasItems = rows.length > 0;
        document.getElementById('btnSubmitTransfer').disabled = !hasItems;
        document.getElementById('noItemsMsg').style.display   = hasItems ? 'none' : 'block';
    }

    function addRow() {
        rowIndex++;
        var idx   = rowIndex;
        var tbody = document.getElementById('itemsBody');

        var opts = '<option value=""><?= $is_rtl ? "اختر منتجاً..." : "Select product..." ?></option>';
        allProducts.forEach(function (p) {
            opts += '<option value="' + p.id +
                    '" data-central="' + p.central +
                    '" data-unit="'    + p.unit    + '">' +
                    p.name + '</option>';
        });

        var tr = document.createElement('tr');
        tr.setAttribute('data-row', idx);
        tr.innerHTML =
            '<td>' +
                '<select name="items[' + idx + '][product_id]" ' +
                        'class="form-select form-select-sm ts-input sel-product" required>' +
                    opts +
                '</select>' +
            '</td>' +
            '<td class="text-center td-central" style="color:var(--ts-silver);">—</td>' +
            '<td>' +
                '<input type="number" name="items[' + idx + '][quantity]" ' +
                       'class="form-control form-control-sm ts-input inp-qty" ' +
                       'min="0.001" step="0.001" required placeholder="0">' +
            '</td>' +
            '<td class="text-center">' +
                '<button type="button" class="btn btn-outline-danger btn-xs btn-remove-row">' +
                    '<i class="bi bi-trash"></i>' +
                '</button>' +
            '</td>';

        tbody.appendChild(tr);

        tr.querySelector('.sel-product').addEventListener('change', function () {
            var opt    = this.options[this.selectedIndex];
            var cent   = opt.dataset.central || '0';
            var tdCent = tr.querySelector('.td-central');
            tdCent.textContent = cent + ' ' + (opt.dataset.unit || '');
        });

        tr.querySelector('.btn-remove-row').addEventListener('click', function () {
            tr.remove();
            updateSubmitBtn();
        });

        updateSubmitBtn();
    }

    var btnAdd = document.getElementById('btnAddRow');
    if (btnAdd) btnAdd.addEventListener('click', addRow);

    // FIX #1: confirm عبر data-attribute بدلاً من inline onsubmit
    document.querySelectorAll('.form-approve-reject').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            var msg = this.dataset.confirm || 'Confirm?';
            if (!window.confirm(msg)) {
                e.preventDefault();
            }
        });
    });

    // ——— عرض تفاصيل تحويل ———
    document.querySelectorAll('.btn-view-transfer').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id   = this.dataset.id;
            var num  = this.dataset.num;
            var body = document.getElementById('detailsBody');

            document.getElementById('detailsTitle').textContent =
                '<?= $is_rtl ? "تفاصيل التحويل" : "Transfer Details" ?> — ' + num;

            var items = transferItems[id] || [];
            if (!items.length) {
                body.innerHTML =
                    '<p class="text-center py-3" style="color:var(--ts-text-muted);">' +
                    '<?= $lang['no_results'] ?></p>';
            } else {
                var html =
                    '<div class="table-responsive">' +
                    '<table class="table ts-table mb-0"><thead><tr>' +
                    '<th><?= $lang['product_name'] ?></th>' +
                    '<th class="text-center"><?= $lang['quantity'] ?></th>' +
                    '<th><?= $lang['unit'] ?></th>' +
                    '</tr></thead><tbody>';
                items.forEach(function (it) {
                    html +=
                        '<tr><td>' + it.name + '</td>' +
                        '<td class="text-center"><strong style="color:var(--ts-gold);">' +
                        it.qty + '</strong></td>' +
                        '<td>' + it.unit + '</td></tr>';
                });
                html += '</tbody></table></div>';
                body.innerHTML = html;
            }

            new bootstrap.Modal(document.getElementById('modalDetails')).show();
        });
    });

})();
</script>
