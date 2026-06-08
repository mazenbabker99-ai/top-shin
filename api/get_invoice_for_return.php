<?php
// ============================================================
// المسار: api/get_invoice_for_return.php
// الوظيفة: جلب بيانات فاتورة مع الكميات القابلة للإرجاع — AJAX endpoint للمرتجعات
// الاعتمادات: config/database.php | includes/auth.php | includes/middleware.php
// ============================================================

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/middleware.php';

// ——— تحقق من تسجيل الدخول ———
require_login();

// ——— رأس JSON ———
header('Content-Type: application/json; charset=utf-8');

// ——— Method: GET فقط ———
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ——— جلب رقم الفاتورة ———
$invoice_number = trim($_GET['invoice_number'] ?? '');

if ($invoice_number === '') {
    json_response(['success' => false, 'message' => 'رقم الفاتورة مطلوب.'], 400);
}

try {
    $pdo  = db();
    $role = Auth::getRole();
    $user_bid = Auth::getBranchId();

    // ============================================================
    // جلب الفاتورة الرئيسية
    // ============================================================
    $stmt = $pdo->prepare("
        SELECT
            i.id,
            i.invoice_number,
            i.branch_id,
            i.cashier_id,
            i.customer_id,
            i.total,
            i.payment_method,
            i.status,
            i.created_at,
            b.name    AS branch_name,
            b.code    AS branch_code,
            u.name    AS cashier_name,
            COALESCE(c.name,  '') AS customer_name,
            COALESCE(c.phone, '') AS customer_phone
        FROM invoices i
        LEFT JOIN branches  b ON b.id = i.branch_id
        LEFT JOIN users     u ON u.id = i.cashier_id
        LEFT JOIN customers c ON c.id = i.customer_id
        WHERE i.invoice_number = ?
        LIMIT 1
    ");
    $stmt->execute([$invoice_number]);
    $invoice = $stmt->fetch();

    if (!$invoice) {
        json_response(['success' => false, 'message' => 'الفاتورة غير موجودة.'], 404);
    }

    // ——— فحص حالة الفاتورة ———
    if ($invoice['status'] === 'cancelled') {
        json_response(['success' => false, 'message' => 'هذه الفاتورة ملغاة ولا يمكن إرجاعها.'], 422);
    }

    // ——— تحقق من صلاحية الوصول ———
    if ($role !== 'super_admin') {
        if ((int)$invoice['branch_id'] !== $user_bid) {
            json_response(['success' => false, 'message' => 'غير مصرح بالوصول لهذه الفاتورة.'], 403);
        }
    }

    // ============================================================
    // جلب بنود الفاتورة مع الكميات المُرجعة مسبقاً
    // ============================================================
    $items_stmt = $pdo->prepare("
        SELECT
            ii.id            AS invoice_item_id,
            ii.product_id,
            ii.product_name,
            ii.unit_price,
            ii.quantity      AS original_qty,
            ii.discount,
            ii.total,
            p.unit,
            p.barcode,
            p.name_ar,
            p.name_en,
            COALESCE(
                (
                    SELECT SUM(ri.quantity)
                    FROM return_items ri
                    INNER JOIN returns r ON r.id = ri.return_id
                    WHERE ri.invoice_item_id = ii.id
                      AND r.status IN ('pending', 'approved')
                ),
                0
            ) AS already_returned_qty
        FROM invoice_items ii
        LEFT JOIN products p ON p.id = ii.product_id
        WHERE ii.invoice_id = ?
        ORDER BY ii.id ASC
    ");
    $items_stmt->execute([(int)$invoice['id']]);
    $items = $items_stmt->fetchAll();

    // ——— تجهيز البنود مع الكمية القابلة للإرجاع ———
    $formatted_items = [];
    foreach ($items as $item) {
        $original_qty      = (float)$item['original_qty'];
        $already_returned  = (float)$item['already_returned_qty'];
        $returnable_qty    = max(0, $original_qty - $already_returned);

        // تخطي البنود المُرجَعة بالكامل
        if ($returnable_qty <= 0) {
            continue;
        }

        $formatted_items[] = [
            'invoice_item_id'    => (int)  $item['invoice_item_id'],
            'product_id'         => (int)  $item['product_id'],
            'product_name'       => $item['product_name'],
            'name_ar'            => $item['name_ar']    ?? $item['product_name'],
            'name_en'            => $item['name_en']    ?? $item['product_name'],
            'barcode'            => $item['barcode']    ?? '',
            'unit'               => $item['unit']       ?? '',
            'unit_price'         => (float)$item['unit_price'],
            'original_qty'       => $original_qty,
            'already_returned'   => $already_returned,
            'returnable_qty'     => $returnable_qty,
            'discount'           => (float)$item['discount'],
            'total'              => (float)$item['total'],
        ];
    }

    if (empty($formatted_items)) {
        json_response(['success' => false, 'message' => 'جميع بنود هذه الفاتورة تم إرجاعها مسبقاً.'], 422);
    }

    // ——— الرد الكامل ———
    echo json_encode([
        'success' => true,
        'invoice' => [
            'id'             => (int)$invoice['id'],
            'invoice_number' => $invoice['invoice_number'],
            'branch_id'      => (int)$invoice['branch_id'],
            'branch_name'    => $invoice['branch_name']    ?? '',
            'cashier_name'   => $invoice['cashier_name']   ?? '',
            'customer_name'  => $invoice['customer_name'],
            'customer_phone' => $invoice['customer_phone'],
            'total'          => (float)$invoice['total'],
            'payment_method' => $invoice['payment_method'],
            'status'         => $invoice['status'],
            'created_at'     => $invoice['created_at'],
            'items'          => $formatted_items,
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    error_log('[TopShine get_invoice_for_return] ' . $e->getMessage());
    json_response(['success' => false, 'message' => 'حدث خطأ في جلب بيانات الفاتورة.'], 500);
}
