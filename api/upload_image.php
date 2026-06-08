<?php
// ============================================================
// المسار: api/upload_image.php
// الوظيفة: رفع وضغط صورة المنتج — GD → webp 600×600 جودة 80%
// الاعتمادات: config/database.php | includes/auth.php | includes/functions.php
// ============================================================

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/middleware.php';

header('Content-Type: application/json; charset=utf-8');

// ——— الصلاحية ———
require_role(['super_admin', 'branch_admin']);

// ——— Method: POST فقط ———
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'message' => 'Method not allowed'], 405);
}

// ——— CSRF ———
$csrf_input = $_POST['csrf_token'] ?? '';
if (!verify_csrf_token($csrf_input)) {
    json_response(['success' => false, 'message' => 'CSRF validation failed'], 403);
}

// ——— التحقق من product_id ———
$product_id = sanitize_int($_POST['product_id'] ?? 0);
if ($product_id <= 0) {
    json_response(['success' => false, 'message' => 'معرف المنتج غير صالح.'], 400);
}

// ——— التحقق من وجود الملف ———
if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    $err_map = [
        UPLOAD_ERR_INI_SIZE   => 'الملف أكبر من الحد المسموح (php.ini).',
        UPLOAD_ERR_FORM_SIZE  => 'الملف أكبر من الحد المسموح (form).',
        UPLOAD_ERR_PARTIAL    => 'تم رفع الملف جزئياً.',
        UPLOAD_ERR_NO_FILE    => 'لم يُرفق أي ملف.',
        UPLOAD_ERR_NO_TMP_DIR => 'مجلد الملفات المؤقتة غير موجود.',
        UPLOAD_ERR_CANT_WRITE => 'فشل الكتابة على القرص.',
    ];
    $err_code = $_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE;
    $err_msg  = $err_map[$err_code] ?? 'خطأ غير معروف في الرفع.';
    json_response(['success' => false, 'message' => $err_msg], 400);
}

$file = $_FILES['image'];

// ——— التحقق من الحجم (max 2MB) ———
$max_bytes = 2 * 1024 * 1024; // 2MB
if ($file['size'] > $max_bytes) {
    json_response(['success' => false, 'message' => 'حجم الصورة يتجاوز الحد الأقصى 2MB.'], 400);
}

// ——— التحقق من نوع الملف ———
$allowed_types = ['image/jpeg', 'image/png', 'image/webp'];
$allowed_exts  = ['jpg', 'jpeg', 'png', 'webp'];

// MIME من PHP (أكثر أماناً من امتداد الاسم)
$finfo     = new finfo(FILEINFO_MIME_TYPE);
$mime_type = $finfo->file($file['tmp_name']);

if (!in_array($mime_type, $allowed_types, true)) {
    json_response(['success' => false, 'message' => 'نوع الملف غير مسموح. المسموح: jpg, png, webp.'], 400);
}

$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, $allowed_exts, true)) {
    json_response(['success' => false, 'message' => 'امتداد الملف غير مسموح.'], 400);
}

// ——— التحقق من امتداد GD ———
if (!function_exists('imagecreatefromjpeg')) {
    json_response(['success' => false, 'message' => 'امتداد GD غير مثبت على الخادم.'], 500);
}

// ============================================================
// معالجة الصورة بـ GD
// ============================================================

// قراءة الصورة المصدر
$src_image = match ($mime_type) {
    'image/jpeg' => @imagecreatefromjpeg($file['tmp_name']),
    'image/png'  => @imagecreatefrompng($file['tmp_name']),
    'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($file['tmp_name']) : false,
    default      => false,
};

if (!$src_image) {
    json_response(['success' => false, 'message' => 'تعذّر قراءة الصورة. تأكد أن الملف صالح.'], 400);
}

// أبعاد الصورة الأصلية
$src_w = imagesx($src_image);
$src_h = imagesy($src_image);

// ——— حساب أبعاد القص مع الحفاظ على النسبة ———
$target = 600;

if ($src_w > $src_h) {
    // عرضية: نضغط على الارتفاع
    $scale   = $target / $src_h;
    $new_w   = (int)round($src_w * $scale);
    $new_h   = $target;
    $crop_x  = (int)round(($new_w - $target) / 2);
    $crop_y  = 0;
} elseif ($src_h > $src_w) {
    // طولية: نضغط على العرض
    $scale   = $target / $src_w;
    $new_w   = $target;
    $new_h   = (int)round($src_h * $scale);
    $crop_x  = 0;
    $crop_y  = (int)round(($new_h - $target) / 2);
} else {
    // مربعة
    $new_w  = $target;
    $new_h  = $target;
    $crop_x = 0;
    $crop_y = 0;
}

// ——— إنشاء صورة وسيطة مُحجَّمة ———
$scaled = imagecreatetruecolor($new_w, $new_h);

// دعم الشفافية للـ PNG
if ($mime_type === 'image/png') {
    imagealphablending($scaled, false);
    imagesavealpha($scaled, true);
    $transparent = imagecolorallocatealpha($scaled, 0, 0, 0, 127);
    imagefilledrectangle($scaled, 0, 0, $new_w, $new_h, $transparent);
}

imagecopyresampled($scaled, $src_image, 0, 0, 0, 0, $new_w, $new_h, $src_w, $src_h);

// ——— قص المنتصف 600×600 ———
$canvas = imagecreatetruecolor($target, $target);

// خلفية بيضاء للمنتجات (أفضل من الشفافية لـ webp)
$white = imagecolorallocate($canvas, 255, 255, 255);
imagefilledrectangle($canvas, 0, 0, $target, $target, $white);

imagecopy($canvas, $scaled, 0, 0, $crop_x, $crop_y, $target, $target);

imagedestroy($scaled);
imagedestroy($src_image);

// ——— مسار الحفظ ———
// المسار يُحسب من جذر المشروع (api/ = عمق 1)
$upload_dir = __DIR__ . '/../assets/images/products/';

// إنشاء المجلد إن لم يكن موجوداً
if (!is_dir($upload_dir)) {
    if (!mkdir($upload_dir, 0755, true)) {
        imagedestroy($canvas);
        json_response(['success' => false, 'message' => 'تعذّر إنشاء مجلد الصور.'], 500);
    }
}

$filename    = $product_id . '.webp';
$output_path = $upload_dir . $filename;
$public_path = 'assets/images/products/' . $filename;

// ——— حفظ كـ webp جودة 80% ———
if (!function_exists('imagewebp')) {
    // fallback: حفظ كـ JPEG إن لم يدعم الخادم webp
    $filename    = $product_id . '.jpg';
    $output_path = $upload_dir . $filename;
    $public_path = 'assets/images/products/' . $filename;
    $saved       = imagejpeg($canvas, $output_path, 85);
} else {
    $saved = imagewebp($canvas, $output_path, 80);
}

imagedestroy($canvas);

if (!$saved) {
    json_response(['success' => false, 'message' => 'تعذّر حفظ الصورة على الخادم.'], 500);
}

// ——— تحديث حقل image في قاعدة البيانات ———
try {
    $pdo = db();

    // جلب الصورة القديمة للـ audit
    $old_stmt = $pdo->prepare("SELECT image FROM products WHERE id = ? LIMIT 1");
    $old_stmt->execute([$product_id]);
    $old_image = $old_stmt->fetchColumn();

    $upd = $pdo->prepare("UPDATE products SET image = ? WHERE id = ?");
    $upd->execute([$public_path, $product_id]);

    // Audit Log
    $audit = new AuditLogger($pdo);
    $audit->updated('products', $product_id,
        ['image' => $old_image ?: ''],
        ['image' => $public_path]
    );

    json_response([
        'success'  => true,
        'path'     => $public_path,
        'message'  => 'تم رفع الصورة وضغطها بنجاح.',
    ]);

} catch (\Throwable $e) {
    error_log('[upload_image DB] ' . $e->getMessage());
    // الصورة رُفعت لكن لم تُحدَّث قاعدة البيانات
    json_response([
        'success' => false,
        'message' => 'رُفعت الصورة لكن فشل تحديث قاعدة البيانات. أعد المحاولة.',
    ], 500);
}
