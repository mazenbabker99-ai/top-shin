<?php
// ============================================================
// المسار: admin/po_receive.php
// الوظيفة: استلام بضاعة أمر الشراء — كلي أو جزئي
//          Transaction: inventory + movements + PO status + supplier balance
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
// جلب أمر الشراء
// ============================================================
$po_id = sanitize_int($_GET['po_id'] ?? $_POST['po_id'] ?? 0);

if ($po_id <= 0) {
    flash('error', $lang['required_field']);
    redirect('purchase_orders.php');
}

$po_stmt = $pdo->prepare('
    SELECT po.*,
           s.name   AS supplier_name,
           s.id     AS supplier_id,
           b.name   AS branch_name,
           b.code   AS branch_code
    FROM purchase_orders po
    LEFT JOIN suppliers s ON s.id = po.supplier_id
    LEFT JOIN branches  b ON b.id = po.branch_id
    WHERE po.id = ?
    LIMIT 1
');
$po_stmt->execute([$po_id]);
$po = $po_stmt->fetch();

if (!$po) {
    flash('error', $lang['not_found'] ?? 'السجل غير موجود');
    redirect('purchase_orders.php');
}

// تحقق الصلاحية — branch_admin فرعه فقط
if (!$is_super && $po['branch_id'] !== null && $po['branch_id'] !== $user_bid) {
    render_403('branch');
}

// يجب أن يكون PO بحالة confirmed أو partial
if (!in_array($po['status'], ['confirmed', 'partial'], true)) {
    flash('error', $lang['error_occurred']);
    redirect('purchase_orders.php?view=' . $po_id);
}

// ——— جلب بنود الـ PO ———
$items_stmt = $pdo->prepare('
    SELECT poi.*,
           p.name_ar, p.name_en, p.unit, p.barcode,
           p.cost_price AS current_cost
    FROM purchase_order_items poi
    LEFT JOIN products p ON p.id = poi.product_id
    WHERE poi.po_id = ?
    ORDER BY poi.id ASC
');
$items_stmt->execute([$po_id]);
$po_items = $items_stmt->fetchAll();

// ============================================================
// معالجة POST — الاستلام
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'receive') {

    $submitted_token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($submitted_token)) {
        flash('error', $lang['csrf_invalid']);
        redirect('po_receive.php?po_id=' . $po_id);
    }

    // الكميات المُستلمة من الـ form
    $received_map = $_POST['received'] ?? [];  // [item_id => quantity]
    $notes        = trim($_POST['notes'] ?? '');

    // ——— التحقق: لا يجوز استلام صفر في كل البنود ———
    $any_received = false;
    $items_to_process = [];

    foreach ($po_items as $item) {
        $item_id  = (int) $item['id'];
        $raw_qty  = $received_map[$item_id] ?? '0';
        $recv_qty = (float) filter_var($raw_qty, FILTER_VALIDATE_FLOAT);

        $max_recv = (float)$item['quantity'] - (float)$item['quantity_received'];

        if ($recv_qty < 0)         $recv_qty = 0.0;
        if ($recv_qty > $max_recv) $recv_qty = $max_recv; // لا تتجاوز المطلوب

        if ($recv_qty > 0) {
            $any_received = true;
        }

        $items_to_process[] = [
            'item_id'       => $item_id,
            'product_id'    => (int) $item['product_id'],
            'recv_qty'      => $recv_qty,
            'qty_ordered'   => (float) $item['quantity'],
            'qty_prev_recv' => (float) $item['quantity_received'],
            'unit_cost'     => (float) $item['unit_cost'],
        ];
    }

    if (!$any_received) {
        flash('error', $lang['required_field']);
        redirect('po_receive.php?po_id=' . $po_id);
    }

    // ——— Transaction ———
    try {
        $pdo->beginTransaction();

        // الفرع الوجهة (من الـ PO)
        $dest_branch_id = $po['branch_id'] !== null ? (int)$po['branch_id'] : null;

        $total_cost_received = 0.0;

        foreach ($items_to_process as $it) {
            if ($it['recv_qty'] <= 0) continue;

            $product_id  = $it['product_id'];
            $recv_qty    = $it['recv_qty'];
            $unit_cost   = $it['unit_cost'];

            // ——— UPDATE quantity_received في PO ———
            $pdo->prepare('
                UPDATE purchase_order_items
                SET quantity_received = quantity_received + ?
                WHERE id = ?
            ')->execute([$recv_qty, $it['item_id']]);

            // ——— تحديث المخزون (INSERT or UPDATE) ———
            if ($dest_branch_id === null) {
                // مخزون مركزي
                $inv_row = $pdo->prepare('
                    SELECT id, quantity FROM inventory
                    WHERE product_id = ? AND branch_id IS NULL
                    LIMIT 1
                ');
                $inv_row->execute([$product_id]);
            } else {
                $inv_row = $pdo->prepare('
                    SELECT id, quantity FROM inventory
                    WHERE product_id = ? AND branch_id = ?
                    LIMIT 1
                ');
                $inv_row->execute([$product_id, $dest_branch_id]);
            }
            $inv = $inv_row->fetch();

            $qty_before = (float) ($inv['quantity'] ?? 0);
            $qty_after  = $qty_before + $recv_qty;

            if ($inv) {
                if ($dest_branch_id === null) {
                    $pdo->prepare('
                        UPDATE inventory SET quantity = ?, updated_at = NOW()
                        WHERE product_id = ? AND branch_id IS NULL
                    ')->execute([$qty_after, $product_id]);
                } else {
                    $pdo->prepare('
                        UPDATE inventory SET quantity = ?, updated_at = NOW()
                        WHERE product_id = ? AND branch_id = ?
                    ')->execute([$qty_after, $product_id, $dest_branch_id]);
                }
            } else {
                // لا يوجد سجل مخزون — أنشئه
                $pdo->prepare('
                    INSERT INTO inventory (product_id, branch_id, quantity, min_quantity, updated_at)
                    VALUES (?, ?, ?, 5, NOW())
                ')->execute([$product_id, $dest_branch_id, $qty_after]);
            }

            // ——— سجّل حركة المخزون ———
            log_inventory_movement(
                $pdo,
                $product_id,
                $dest_branch_id,
                'purchase',
                $recv_qty,
                $qty_before,
                $qty_after,
                $po_id,
                'purchase_order',
                $notes ?: "استلام من PO: {$po['po_number']}"
            );

            $total_cost_received += round($recv_qty * $unit_cost, 2);
        }

        // ——— تحديث حالة الـ PO (received / partial) ———
        // إعادة قراءة البنود بعد التحديث
        $check = $pdo->prepare('
            SELECT
                SUM(quantity)          AS total_ordered,
                SUM(quantity_received) AS total_received
            FROM purchase_order_items
            WHERE po_id = ?
        ');
        $check->execute([$po_id]);
        $check_row  = $check->fetch();
        $fully_done = (float)$check_row['total_received'] >= (float)$check_row['total_ordered'];
        $new_status = $fully_done ? 'received' : 'partial';
        $received_at = $fully_done ? 'NOW()' : 'NULL';

        if ($fully_done) {
            $pdo->prepare('
                UPDATE purchase_orders
                SET status = \'received\', received_at = NOW()
                WHERE id = ?
            ')->execute([$po_id]);
        } else {
            $pdo->prepare('
                UPDATE purchase_orders
                SET status = \'partial\'
                WHERE id = ?
            ')->execute([$po_id]);
        }

        // ——— تحديث paid_amount إذا دُفع جزء (لا نُعدّله هنا — يتم من صفحة الموردين) ———
        // نُخفّض رصيد المورد بمقدار ما تم استلامه فعلياً (تحديد الدين الحقيقي)
        // الرصيد يُحدَّث فقط عند تسجيل الدفعة — هذا ليس دفعة

        $pdo->commit();

        $audit->log(
            'update',
            'purchase_orders',
            $po_id,
            ['status' => $po['status']],
            ['status' => $new_status, 'cost_received' => $total_cost_received, 'notes' => $notes]
        );

        flash('success', $lang['saved_successfully']);
        redirect('purchase_orders.php?view=' . $po_id);

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[TopShine] po_receive: ' . $e->getMessage());
        flash('error', $lang['error_occurred'] . ' — ' . $e->getMessage());
        redirect('po_receive.php?po_id=' . $po_id);
    }
}

// ============================================================
// إعداد عرض الصفحة
// ============================================================
$page_title   = $lang['receive_order'];
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
            <i class="bi bi-box-arrow-in-down me-2"></i><?= $lang['receive_order'] ?>
        </h1>
        <a href="purchase_orders.php?view=<?= $po_id ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-right me-1"></i><?= $lang['back'] ?? 'رجوع' ?>
        </a>
    </div>

    <!-- ======================================================
         معلومات الـ PO
         ====================================================== -->
    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-md-3">
            <div class="ts-kpi-card">
                <div class="kpi-label"><?= $lang['po_number'] ?></div>
                <div class="fw-bold" style="color:var(--ts-gold)"><?= sanitize($po['po_number']) ?></div>
            </div>
        </div>
        <div class="col-sm-6 col-md-3">
            <div class="ts-kpi-card">
                <div class="kpi-label"><?= $lang['supplier'] ?></div>
                <div class="fw-bold"><?= sanitize($po['supplier_name'] ?? '—') ?></div>
            </div>
        </div>
        <div class="col-sm-6 col-md-3">
            <div class="ts-kpi-card">
                <div class="kpi-label"><?= $lang['branch'] ?? 'الفرع' ?> (<?= $lang['destination'] ?? 'الوجهة' ?>)</div>
                <div class="fw-bold">
                    <?= $po['branch_name'] ? sanitize($po['branch_name']) : ($lang['central'] ?? 'المخزن المركزي') ?>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-md-3">
            <div class="ts-kpi-card">
                <div class="kpi-label"><?= $lang['status'] ?></div>
                <?php
                $status_colors = ['confirmed' => 'warning', 'partial' => 'info'];
                $sc = $status_colors[$po['status']] ?? 'secondary';
                $sl = $lang[$po['status']] ?? $po['status'];
                ?>
                <span class="badge fs-6 bg-<?= $sc ?>"><?= $sl ?></span>
            </div>
        </div>
    </div>

    <!-- ======================================================
         نموذج الاستلام
         ====================================================== -->
    <form method="POST" id="formReceive">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="action"     value="receive">
        <input type="hidden" name="po_id"      value="<?= $po_id ?>">

        <div class="card">
            <div class="card-header">
                <i class="bi bi-list-check me-2"></i><?= $lang['items'] ?? 'البنود' ?> — <?= $lang['receive'] ?>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-bordered mb-0 align-middle">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th><?= $lang['product'] ?? 'المنتج' ?></th>
                                <th><?= $lang['barcode'] ?? 'الباركود' ?></th>
                                <th class="text-end"><?= $lang['quantity'] ?? 'الكمية' ?> (<?= $lang['ordered'] ?? 'المطلوبة' ?>)</th>
                                <th class="text-end"><?= $lang['received_qty'] ?> (<?= $lang['previous'] ?? 'سابقاً' ?>)</th>
                                <th class="text-end" style="min-width:80px"><?= $lang['remaining'] ?? 'المتبقي' ?></th>
                                <th style="min-width:160px"><?= $lang['receive_now'] ?? 'يُستلم الآن' ?></th>
                                <th class="text-end"><?= $lang['unit_cost'] ?></th>
                                <th class="text-end"><?= $lang['subtotal'] ?? 'الإجمالي' ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($po_items as $i => $item): ?>
                            <?php
                            $max_recv   = (float)$item['quantity'] - (float)$item['quantity_received'];
                            $fully_recv = ($max_recv <= 0);
                            $prod_name  = product_name($item, $lang_code);
                            if (!$prod_name) $prod_name = sanitize($item['product_name']);
                            ?>
                            <tr class="<?= $fully_recv ? 'opacity-50' : '' ?>">
                                <td class="text-muted"><?= $i + 1 ?></td>
                                <td>
                                    <strong><?= sanitize($prod_name) ?></strong>
                                    <?php if ($item['unit']): ?>
                                        <br><small class="text-muted"><?= sanitize($item['unit']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td class="text-muted small"><?= sanitize($item['barcode'] ?? '—') ?></td>
                                <td class="text-end"><?= format_qty((float)$item['quantity']) ?></td>
                                <td class="text-end">
                                    <?php if ((float)$item['quantity_received'] > 0): ?>
                                        <span class="text-success"><?= format_qty((float)$item['quantity_received']) ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end <?= $max_recv > 0 ? 'ts-status-pending' : 'ts-status-active' ?>">
                                    <?= format_qty(max(0, $max_recv)) ?>
                                </td>
                                <td>
                                    <?php if ($fully_recv): ?>
                                        <span class="badge bg-success"><?= $lang['received'] ?> ✓</span>
                                        <input type="hidden" name="received[<?= $item['id'] ?>]" value="0">
                                    <?php else: ?>
                                        <div class="input-group input-group-sm">
                                            <input type="number"
                                                   name="received[<?= $item['id'] ?>]"
                                                   class="form-control recv-input"
                                                   data-max="<?= $max_recv ?>"
                                                   data-cost="<?= $item['unit_cost'] ?>"
                                                   data-row="<?= $i ?>"
                                                   value="<?= $max_recv ?>"
                                                   min="0"
                                                   max="<?= $max_recv ?>"
                                                   step="0.001">
                                            <button type="button" class="btn btn-outline-secondary btn-set-max"
                                                    data-row="<?= $i ?>"
                                                    title="<?= $lang['full'] ?? 'كامل' ?>">
                                                <i class="bi bi-check-all"></i>
                                            </button>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end"><?= format_money((float)$item['unit_cost']) ?></td>
                                <td class="text-end row-subtotal" data-row="<?= $i ?>"
                                    style="color:var(--ts-gold)">
                                    <?= $fully_recv ? '—' : format_money(max(0, $max_recv) * (float)$item['unit_cost']) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="8" class="text-end fw-bold" style="color:var(--ts-gold)">
                                    <?= $lang['total_to_receive'] ?? 'إجمالي ما يُستلم الآن' ?>
                                </td>
                                <td class="text-end fw-bold" id="grand_receive_total" style="color:var(--ts-gold)">
                                    <?= format_money(
                                        array_sum(array_map(
                                            fn($it) => max(0, (float)$it['quantity'] - (float)$it['quantity_received']) * (float)$it['unit_cost'],
                                            $po_items
                                        ))
                                    ) ?>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <!-- ملاحظات + إجراءات -->
            <div class="card-footer">
                <div class="row g-3 align-items-end">
                    <div class="col-md-8">
                        <label class="form-label"><?= $lang['notes'] ?></label>
                        <input type="text" name="notes" class="form-control"
                               placeholder="<?= $lang['notes'] ?>..."
                               maxlength="300">
                    </div>
                    <div class="col-md-4 d-flex gap-2 justify-content-end">
                        <!-- زر التحقق من الكل -->
                        <button type="button" id="btnSelectAll" class="btn btn-outline-secondary">
                            <i class="bi bi-check2-square me-1"></i><?= $lang['select_all'] ?? 'استلام الكل' ?>
                        </button>
                        <!-- زر الإرسال -->
                        <button type="submit" class="btn btn-primary" id="btnReceive">
                            <i class="bi bi-box-arrow-in-down me-1"></i><?= $lang['save_receive'] ?? 'تأكيد الاستلام' ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>

    </form>

    <!-- ======================================================
         إرشادات
         ====================================================== -->
    <div class="alert alert-info mt-3 py-2">
        <i class="bi bi-info-circle me-2"></i>
        <?php if ($lang_code === 'ar'): ?>
            يمكنك تعديل الكمية المُستلمة في كل بند — الاستلام الجزئي مدعوم.
            البضاعة ستُضاف لمخزون
            <strong><?= $po['branch_name'] ? sanitize($po['branch_name']) : ($lang['central'] ?? 'المخزن المركزي') ?></strong>.
        <?php else: ?>
            You can adjust the received quantity per item — partial receiving is supported.
            Goods will be added to
            <strong><?= $po['branch_name'] ? sanitize($po['branch_name']) : 'Central Warehouse' ?></strong> inventory.
        <?php endif; ?>
    </div>

</div><!-- /ts-main-content -->

<!-- ============================================================
     Scripts
     ============================================================ -->
<script>
(function () {
    'use strict';

    // ——— تحديث الإجمالي عند تغيير الكميات ———
    const inputs     = document.querySelectorAll('.recv-input');
    const grandTotal = document.getElementById('grand_receive_total');

    function recalc() {
        let total = 0;
        inputs.forEach(inp => {
            const row   = inp.dataset.row;
            const cost  = parseFloat(inp.dataset.cost) || 0;
            const qty   = parseFloat(inp.value) || 0;
            const max   = parseFloat(inp.dataset.max) || 0;
            const valid = Math.min(Math.max(0, qty), max);
            const sub   = parseFloat((valid * cost).toFixed(2));

            const cell = document.querySelector('.row-subtotal[data-row="' + row + '"]');
            if (cell) cell.textContent = sub.toFixed(2) + ' SDG';

            total += sub;
        });
        grandTotal.textContent = total.toFixed(2) + ' SDG';
    }

    inputs.forEach(inp => {
        inp.addEventListener('input', recalc);
        inp.addEventListener('change', () => {
            const max = parseFloat(inp.dataset.max) || 0;
            let   qty = parseFloat(inp.value) || 0;
            if (qty < 0)   qty = 0;
            if (qty > max) qty = max;
            inp.value = qty;
            recalc();
        });
    });

    // ——— زر ضبط للحد الأقصى لبند واحد ———
    document.querySelectorAll('.btn-set-max').forEach(btn => {
        btn.addEventListener('click', () => {
            const row = btn.dataset.row;
            const inp = document.querySelector('.recv-input[data-row="' + row + '"]');
            if (inp) {
                inp.value = inp.dataset.max;
                recalc();
            }
        });
    });

    // ——— زر استلام الكل ———
    document.getElementById('btnSelectAll').addEventListener('click', () => {
        inputs.forEach(inp => {
            inp.value = inp.dataset.max;
        });
        recalc();
    });

    // ——— منع الإرسال إن كل الكميات = 0 ———
    document.getElementById('formReceive').addEventListener('submit', e => {
        const anyPositive = Array.from(inputs).some(inp => parseFloat(inp.value) > 0);
        if (!anyPositive) {
            e.preventDefault();
            alert('<?= $lang_code === 'ar' ? 'أدخل كمية مُستلمة لبند واحد على الأقل' : 'Enter at least one received quantity' ?>');
        }
    });

    // حساب أولي
    recalc();

})();
</script>

<?php require_once __DIR__ . '/../includes/layout/footer.php'; ?>
