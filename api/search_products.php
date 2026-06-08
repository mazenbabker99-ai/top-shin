<?php
// ============================================================
// المسار: api/search_products.php
// الوظيفة: بحث المنتجات عبر AJAX — يُرجع JSON
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

// ——— جلب المعاملات ———
$q         = trim($_GET['q'] ?? '');
$branch_id = Auth::getBranchId();
$lang_code = Auth::getLang();

// مصطلح البحث لا يقل عن حرفين
if (mb_strlen($q) < 1) {
    echo json_encode(['success' => true, 'products' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo    = db();
    $search = '%' . $q . '%';

    // ============================================================
    // استعلام البحث مع حساب الكمية المتاحة (الفرع + المركزي)
    // ============================================================
    $stmt = $pdo->prepare("
        SELECT
            p.id,
            p.name_ar,
            p.name_en,
            p.barcode,
            p.unit,
            p.selling_price,
            p.image,
            -- مخزون فرع الكاشير
            COALESCE((
                SELECT inv.quantity
                FROM inventory inv
                WHERE inv.product_id = p.id
                  AND inv.branch_id  = ?
                LIMIT 1
            ), 0) AS branch_qty,
            -- مخزون المركزي (branch_id IS NULL)
            COALESCE((
                SELECT inv.quantity
                FROM inventory inv
                WHERE inv.product_id = p.id
                  AND inv.branch_id  IS NULL
                LIMIT 1
            ), 0) AS central_qty
        FROM products p
        WHERE p.status = 'active'
          AND (
              p.name_ar LIKE ?
           OR p.name_en LIKE ?
           OR p.barcode  LIKE ?
          )
        ORDER BY
            -- الباركود المطابق تماماً في الأعلى
            CASE WHEN p.barcode = ? THEN 0 ELSE 1 END,
            p.name_ar ASC
        LIMIT 20
    ");

    $stmt->execute([$branch_id, $search, $search, $search, $q]);
    $rows = $stmt->fetchAll();

    // ——— تجهيز البيانات للإرجاع ———
    $products = [];
    foreach ($rows as $row) {
        $branch_qty  = max(0, (float)$row['branch_qty']);
        $central_qty = max(0, (float)$row['central_qty']);
        $available   = $branch_qty + $central_qty;

        // اسم المنتج حسب لغة المستخدم
        $display_name = ($lang_code === 'en' && !empty($row['name_en']))
            ? $row['name_en']
            : $row['name_ar'];

        $products[] = [
            'id'            => (int)  $row['id'],
            'name'          => $display_name,
            'name_ar'       => $row['name_ar'],
            'name_en'       => $row['name_en'] ?? '',
            'barcode'       => $row['barcode']  ?? '',
            'unit'          => $row['unit']     ?? '',
            'selling_price' => (float) $row['selling_price'],
            'available_qty' => $available,
            'branch_qty'    => $branch_qty,
            'central_qty'   => $central_qty,
            'in_stock'      => ($available > 0),
            'image'         => $row['image'] ?? '',
        ];
    }

    echo json_encode([
        'success'  => true,
        'products' => $products,
        'count'    => count($products),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    error_log('[TopShine search_products] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'حدث خطأ في البحث. أعد المحاولة.',
    ], JSON_UNESCAPED_UNICODE);
}
