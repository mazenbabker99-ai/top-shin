<?php
// ============================================================
// المسار: api/save_invoice.php
// الوظيفة: حفظ فاتورة جديدة — داخل MySQL Transaction كامل
// الاعتمادات: config/database.php | includes/auth.php | includes/functions.php | includes/audit.php
// ============================================================

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/middleware.php';

// ——— تحقق من تسجيل الدخول والدور ———
require_role(['cashier', 'branch_admin', 'super_admin']);

// ——— رأس JSON ———
header('Content-Type: application/json; charset=utf-8');

// ——— Method: POST فقط ———
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ——— تحقق من CSRF ———
csrf_check_or_die();

// ——— جلب جسم الطلب JSON ———
$raw   = file_get_contents('php://input');
$input = json_decode($raw, true);

if (!is_array($input)) {
    json_response(['success' => false, 'message' => 'بيانات غير صالحة.'], 400);
}

// ——— التحقق من البيانات الأساسية ———
$items          = $input['items']          ?? [];
$customer_id    = !empty($input['customer_id']) ? (int)$input['customer_id'] : null;
$discount_type  = $input['discount_type']  ?? 'fixed';
$discount_value = (float)($input['discount_value'] ?? 0);
$payment_method = $input['payment_method'] ?? 'cash';
$amount_paid    = (float)($input['amount_paid']    ?? 0);
$notes          = trim($input['notes']     ?? '');

// تحقق من الأصناف
if (empty($items) || !is_array($items)) {
    json_response(['success' => false, 'message' => 'الفاتورة لا تحتوي على منتجات.'], 422);
}

// تحقق من طريقة الدفع
$allowed_methods = ['cash', 'bankak', 'ocash', 'card', 'other'];
if (!in_array($payment_method, $allowed_methods, true)) {
    json_response(['success' => false, 'message' => 'طريقة دفع غير صالحة.'], 422);
}

// تحقق من نوع الخصم
if (!in_array($discount_type, ['fixed', 'percent'], true)) {
    json_response(['success' => false, 'message' => 'نوع خصم غير صالح.'], 422);
}

// ——— بيانات الكاشير ———
$cashier_id  = Auth::getUserId();
$branch_id   = Auth::getBranchId();
$branch_code = $_SESSION['branch_code'] ?? 'BR1';

if ($branch_id === null) {
    json_response(['success' => false, 'message' => 'الكاشير غير مرتبط بفرع.'], 403);
}

// ============================================================
// بدء العملية
// ============================================================
$pdo = db();
$db  = Database::getInstance();

try {
    $db->beginTransaction();

    // ——————————————————————————————————————
    // 1. التحقق من كل منتج وحساب الإجماليات
    // ——————————————————————————————————————
    $subtotal     = 0.0;
    $valid_items  = [];

    foreach ($items as $idx => $item) {
        $product_id  = (int)  ($item['product_id']  ?? 0);
        $quantity    = (float)($item['quantity']     ?? 0);
        $unit_price  = (float)($item['unit_price']   ?? 0);
        $item_disc   = (float)($item['discount']     ?? 0);

        if ($product_id <= 0 || $quantity <= 0 || $unit_price < 0) {
            $db->rollback();
            json_response(['success' => false, 'message' => "بيانات المنتج رقم " . ($idx + 1) . " غير صالحة."], 422);
        }

        // جلب تكلفة المنتج من قاعدة البيانات (لا نثق بقيمة الـ client)
        $pstmt = $pdo->prepare("SELECT id, name_ar, name_en, cost_price, selling_price, status FROM products WHERE id = ? LIMIT 1");
        $pstmt->execute([$product_id]);
        $product = $pstmt->fetch();

        if (!$product || $product['status'] !== 'active') {
            $db->rollback();
            json_response(['success' => false, 'message' => "المنتج رقم {$product_id} غير موجود أو غير نشط."], 422);
        }

        $item_total   = ($unit_price * $quantity) - $item_disc;
        $item_total   = max(0, $item_total);
        $subtotal    += $item_total;

        $valid_items[] = [
            'product_id'   => $product_id,
            'product_name' => $product['name_ar'],
            'unit_price'   => $unit_price,
            'cost_price'   => (float)$product['cost_price'],
            'quantity'     => $quantity,
            'discount'     => $item_disc,
            'total'        => $item_total,
        ];
    }

    // ——————————————————————————————————————
    // 2. حساب الخصم الإجمالي والكلي
    // ——————————————————————————————————————
    if ($discount_type === 'percent') {
        $discount_pct    = min(100, max(0, $discount_value));
        $discount_amount = round($subtotal * $discount_pct / 100, 2);
    } else {
        $discount_amount = min($subtotal, max(0, $discount_value));
    }

    $total         = max(0, $subtotal - $discount_amount);
    $change_amount = max(0, $amount_paid - $total);

    // ——————————————————————————————————————
    // 3. توليد رقم الفاتورة
    // ——————————————————————————————————————
    $invoice_number = generate_invoice_number($branch_code, $pdo);

    // ——————————————————————————————————————
    // 4. INSERT الفاتورة الرئيسية
    // ——————————————————————————————————————
    $ins = $pdo->prepare("
        INSERT INTO invoices
            (invoice_number, branch_id, cashier_id, customer_id,
             subtotal, discount_type, discount_value, discount_amount,
             total, payment_method, amount_paid, change_amount,
             notes, status, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'completed', NOW())
    ");
    $ins->execute([
        $invoice_number,
        $branch_id,
        $cashier_id,
        $customer_id,
        round($subtotal, 2),
        $discount_type,
        $discount_value,
        round($discount_amount, 2),
        round($total, 2),
        $payment_method,
        round($amount_paid, 2),
        round($change_amount, 2),
        $notes,
    ]);
    $invoice_id = (int)$db->lastInsertId();

    // ——————————————————————————————————————
    // 5. INSERT بنود الفاتورة + سحب المخزون
    // ——————————————————————————————————————
    foreach ($valid_items as $item) {
        // 5a. INSERT invoice_item
        $iins = $pdo->prepare("
            INSERT INTO invoice_items
                (invoice_id, product_id, product_name, unit_price, cost_price,
                 quantity, discount, total)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $iins->execute([
            $invoice_id,
            $item['product_id'],
            $item['product_name'],
            $item['unit_price'],
            $item['cost_price'],
            $item['quantity'],
            $item['discount'],
            $item['total'],
        ]);

        // 5b. سحب المخزون: فرع الكاشير أولاً ← المركزي تلقائياً إن نفد
        $qty_needed = $item['quantity'];

        // ——— مخزون الفرع ———
        $inv_stmt = $pdo->prepare("
            SELECT quantity FROM inventory
            WHERE product_id = ? AND branch_id = ?
            FOR UPDATE
        ");
        $inv_stmt->execute([$item['product_id'], $branch_id]);
        $branch_inv = $inv_stmt->fetch();
        $branch_qty = (float)($branch_inv['quantity'] ?? 0);

        $deduct_from_branch  = 0.0;
        $deduct_from_central = 0.0;

        if ($branch_qty >= $qty_needed) {
            // يكفي من الفرع وحده
            $deduct_from_branch = $qty_needed;
        } elseif ($branch_qty > 0) {
            // يسحب ما تبقى من الفرع + الباقي من المركزي
            $deduct_from_branch  = $branch_qty;
            $deduct_from_central = $qty_needed - $branch_qty;
        } else {
            // الفرع فارغ — يسحب كله من المركزي
            $deduct_from_central = $qty_needed;
        }

        // ——— سحب من الفرع ———
        if ($deduct_from_branch > 0) {
            $qty_before_branch = $branch_qty;
            $qty_after_branch  = $branch_qty - $deduct_from_branch;

            if ($branch_inv) {
                $upd = $pdo->prepare("
                    UPDATE inventory SET quantity = quantity - ?, updated_at = NOW()
                    WHERE product_id = ? AND branch_id = ?
                ");
                $upd->execute([$deduct_from_branch, $item['product_id'], $branch_id]);
            } else {
                // لا سجل — أنشئه بكمية سالبة (لن يحدث لكن للسلامة)
                $upd = $pdo->prepare("
                    INSERT INTO inventory (product_id, branch_id, quantity, min_quantity, updated_at)
                    VALUES (?, ?, ?, 0, NOW())
                ");
                $upd->execute([$item['product_id'], $branch_id, -$deduct_from_branch]);
                $qty_before_branch = 0;
                $qty_after_branch  = -$deduct_from_branch;
            }

            // تحقق من عدم السالبية
            $check = $pdo->prepare("SELECT quantity FROM inventory WHERE product_id = ? AND branch_id = ?");
            $check->execute([$item['product_id'], $branch_id]);
            if ((float)$check->fetchColumn() < -0.001) {
                $db->rollback();
                json_response(['success' => false, 'message' => "مخزون غير كافٍ للمنتج: {$item['product_name']}"], 422);
            }

            // سجّل حركة الفرع
            log_inventory_movement(
                $pdo,
                $item['product_id'],
                $branch_id,
                'sale',
                -$deduct_from_branch,
                $qty_before_branch,
                $qty_after_branch,
                $invoice_id,
                'invoice',
                "فاتورة {$invoice_number}"
            );
        }

        // ——— سحب من المركزي ———
        if ($deduct_from_central > 0) {
            $cent_stmt = $pdo->prepare("
                SELECT quantity FROM inventory
                WHERE product_id = ? AND branch_id IS NULL
                FOR UPDATE
            ");
            $cent_stmt->execute([$item['product_id']]);
            $cent_inv = $cent_stmt->fetch();
            $cent_qty = (float)($cent_inv['quantity'] ?? 0);

            if ($cent_qty < $deduct_from_central - 0.001) {
                $db->rollback();
                json_response([
                    'success' => false,
                    'message' => "مخزون غير كافٍ للمنتج: {$item['product_name']} (المتاح: " . round($cent_qty, 3) . ")",
                ], 422);
            }

            $qty_after_central = $cent_qty - $deduct_from_central;

            if ($cent_inv) {
                $upd = $pdo->prepare("
                    UPDATE inventory SET quantity = quantity - ?, updated_at = NOW()
                    WHERE product_id = ? AND branch_id IS NULL
                ");
                $upd->execute([$deduct_from_central, $item['product_id']]);
            } else {
                $db->rollback();
                json_response(['success' => false, 'message' => "لا يوجد مخزون مركزي للمنتج: {$item['product_name']}"], 422);
            }

            // سجّل حركة المركزي
            log_inventory_movement(
                $pdo,
                $item['product_id'],
                null,
                'sale',
                -$deduct_from_central,
                $cent_qty,
                $qty_after_central,
                $invoice_id,
                'invoice',
                "فاتورة {$invoice_number} — سحب من المركزي"
            );
        }
    }

    // ——————————————————————————————————————
    // 6. تحديث إجمالي مشتريات العميل
    // ——————————————————————————————————————
    if ($customer_id !== null) {
        $cu = $pdo->prepare("
            UPDATE customers
            SET total_purchases = total_purchases + ?
            WHERE id = ?
        ");
        $cu->execute([round($total, 2), $customer_id]);
    }

    // ——————————————————————————————————————
    // 7. Audit Log
    // ——————————————————————————————————————
    $audit = new AuditLogger($pdo);
    $audit->created('invoices', $invoice_id, [
        'invoice_number' => $invoice_number,
        'total'          => $total,
        'items_count'    => count($valid_items),
        'payment_method' => $payment_method,
        'branch_id'      => $branch_id,
    ]);

    // ——————————————————————————————————————
    // 8. COMMIT
    // ——————————————————————————————————————
    $db->commit();

    json_response([
        'success'        => true,
        'invoice_id'     => $invoice_id,
        'invoice_number' => $invoice_number,
        'total'          => round($total, 2),
        'change_amount'  => round($change_amount, 2),
        'message'        => 'تم حفظ الفاتورة بنجاح.',
    ]);

} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollback();
    }
    error_log('[TopShine save_invoice] ' . $e->getMessage() . ' | Line: ' . $e->getLine());
    json_response(['success' => false, 'message' => 'حدث خطأ أثناء حفظ الفاتورة. أعد المحاولة.'], 500);
}
