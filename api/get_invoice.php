<?php
// ============================================================
// المسار: api/get_invoice.php
// الوظيفة: جلب بيانات فاتورة كاملة — للطباعة أو العرض
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

// ——— جلب معرف الفاتورة ———
$invoice_id = (int)($_GET['id'] ?? 0);

if ($invoice_id <= 0) {
    json_response(['success' => false, 'message' => 'معرف الفاتورة غير صالح.'], 400);
}

try {
    $pdo = db();

    // ============================================================
    // جلب الفاتورة الرئيسية مع بيانات الفرع + الكاشير + العميل
    // ============================================================
    $stmt = $pdo->prepare("
        SELECT
            i.id,
            i.invoice_number,
            i.branch_id,
            i.cashier_id,
            i.customer_id,
            i.subtotal,
            i.discount_type,
            i.discount_value,
            i.discount_amount,
            i.total,
            i.payment_method,
            i.amount_paid,
            i.change_amount,
            i.notes,
            i.status,
            i.created_at,
            -- بيانات الفرع
            b.name    AS branch_name,
            b.code    AS branch_code,
            b.address AS branch_address,
            b.phone   AS branch_phone,
            -- بيانات الكاشير
            u.name    AS cashier_name,
            -- بيانات العميل
            COALESCE(c.name,  '') AS customer_name,
            COALESCE(c.phone, '') AS customer_phone
        FROM invoices i
        LEFT JOIN branches  b ON b.id = i.branch_id
        LEFT JOIN users     u ON u.id = i.cashier_id
        LEFT JOIN customers c ON c.id = i.customer_id
        WHERE i.id = ?
        LIMIT 1
    ");
    $stmt->execute([$invoice_id]);
    $invoice = $stmt->fetch();

    if (!$invoice) {
        json_response(['success' => false, 'message' => 'الفاتورة غير موجودة.'], 404);
    }

    // ——— تحقق من صلاحية الوصول ———
    // super_admin يرى كل الفواتير
    // branch_admin / cashier يرون فواتير فرعهم فقط
    if (Auth::getRole() !== 'super_admin') {
        if ((int)$invoice['branch_id'] !== Auth::getBranchId()) {
            json_response(['success' => false, 'message' => 'غير مصرح بالوصول لهذه الفاتورة.'], 403);
        }
    }

    // ============================================================
    // جلب بنود الفاتورة
    // ============================================================
    $items_stmt = $pdo->prepare("
        SELECT
            ii.id,
            ii.product_id,
            ii.product_name,
            ii.unit_price,
            ii.cost_price,
            ii.quantity,
            ii.discount,
            ii.total,
            p.unit,
            p.barcode
        FROM invoice_items ii
        LEFT JOIN products p ON p.id = ii.product_id
        WHERE ii.invoice_id = ?
        ORDER BY ii.id ASC
    ");
    $items_stmt->execute([$invoice_id]);
    $items = $items_stmt->fetchAll();

    // ——— تجهيز بنود الفاتورة ———
    $formatted_items = [];
    foreach ($items as $item) {
        $formatted_items[] = [
            'id'           => (int)  $item['id'],
            'product_id'   => (int)  $item['product_id'],
            'product_name' => $item['product_name'],
            'unit_price'   => (float)$item['unit_price'],
            'cost_price'   => (float)$item['cost_price'],
            'quantity'     => (float)$item['quantity'],
            'discount'     => (float)$item['discount'],
            'total'        => (float)$item['total'],
            'unit'         => $item['unit']    ?? '',
            'barcode'      => $item['barcode'] ?? '',
        ];
    }

    // ——— تجهيز الفاتورة الكاملة ———
    $response = [
        'success' => true,
        'invoice' => [
            'id'             => (int)  $invoice['id'],
            'invoice_number' => $invoice['invoice_number'],
            'branch_id'      => (int)  $invoice['branch_id'],
            'branch_name'    => $invoice['branch_name']    ?? '',
            'branch_code'    => $invoice['branch_code']    ?? '',
            'branch_address' => $invoice['branch_address'] ?? '',
            'branch_phone'   => $invoice['branch_phone']   ?? '',
            'cashier_id'     => (int)  $invoice['cashier_id'],
            'cashier_name'   => $invoice['cashier_name']   ?? '',
            'customer_id'    => $invoice['customer_id'] ? (int)$invoice['customer_id'] : null,
            'customer_name'  => $invoice['customer_name'],
            'customer_phone' => $invoice['customer_phone'],
            'subtotal'       => (float)$invoice['subtotal'],
            'discount_type'  => $invoice['discount_type'],
            'discount_value' => (float)$invoice['discount_value'],
            'discount_amount'=> (float)$invoice['discount_amount'],
            'total'          => (float)$invoice['total'],
            'payment_method' => $invoice['payment_method'],
            'amount_paid'    => (float)$invoice['amount_paid'],
            'change_amount'  => (float)$invoice['change_amount'],
            'notes'          => $invoice['notes']  ?? '',
            'status'         => $invoice['status'],
            'created_at'     => $invoice['created_at'],
            'items'          => $formatted_items,
            'items_count'    => count($formatted_items),
        ],
    ];

    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    error_log('[TopShine get_invoice] ' . $e->getMessage());
    json_response(['success' => false, 'message' => 'حدث خطأ في جلب الفاتورة.'], 500);
}
