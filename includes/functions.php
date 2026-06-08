<?php
// ============================================================
// المسار: includes/functions.php
// الوظيفة: دوال مساعدة مشتركة في كامل النظام
// ============================================================

declare(strict_types=1);

// ============================================================
// تحميل اللغة
// ============================================================

/**
 * يُحمّل مصفوفة نصوص اللغة الحالية
 *
 * @return array<string, string>
 */
function load_lang(): array
{
    $lang = $_SESSION['lang'] ?? 'ar';
    $lang = in_array($lang, ['ar', 'en'], true) ? $lang : 'ar';
    $path = __DIR__ . "/lang/{$lang}.php";

    if (!file_exists($path)) {
        $path = __DIR__ . '/lang/ar.php'; // fallback
    }

    return require $path;
}

// ============================================================
// تبديل اللغة
// ============================================================

/**
 * يُبدّل لغة المستخدم ويُحدّث قاعدة البيانات
 *
 * @param string $new_lang  'ar' | 'en'
 * @param PDO    $pdo
 */
function switch_lang(string $new_lang, PDO $pdo): void
{
    $new_lang = in_array($new_lang, ['ar', 'en'], true) ? $new_lang : 'ar';
    $_SESSION['lang'] = $new_lang;

    if (!empty($_SESSION['user_id'])) {
        $stmt = $pdo->prepare('UPDATE users SET lang = ? WHERE id = ?');
        $stmt->execute([$new_lang, $_SESSION['user_id']]);
    }
}

// ============================================================
// CSRF Token
// ============================================================

/**
 * يُنشئ CSRF token ويحفظه في الـ session
 *
 * @return string
 */
function generate_csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * يُجدّد CSRF token (استخدم بعد كل POST ناجح)
 *
 * @return string
 */
function regenerate_csrf_token(): string
{
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf_token'];
}

/**
 * يتحقق من CSRF token — يُوقف التنفيذ إن فشل
 *
 * @param string $token  التوكن المُرسل من الـ form / header
 * @return bool
 */
function verify_csrf_token(string $token): bool
{
    if (empty($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * يتحقق من CSRF وإن فشل يُرسل خطأ JSON ويوقف التنفيذ
 * — مخصص لـ AJAX endpoints
 */
function csrf_check_or_die(): void
{
    $token = $_SERVER['HTTP_X_CSRF_TOKEN']
          ?? $_POST['csrf_token']
          ?? '';

    if (!verify_csrf_token($token)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'انتهت صلاحية الجلسة. أعد المحاولة.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// ============================================================
// التنسيق
// ============================================================

/**
 * تنسيق المبلغ المالي
 *
 * @param float $amount
 * @return string
 */
function format_money(float $amount): string
{
    return number_format($amount, 2) . ' SDG';
}

/**
 * تنسيق الكمية (يُزيل الأصفار الزائدة بعد الفاصلة)
 *
 * @param float $qty
 * @return string
 */
function format_qty(float $qty): string
{
    // 1.000 → 1  |  1.500 → 1.5  |  1.250 → 1.25
    return rtrim(rtrim(number_format($qty, 3, '.', ''), '0'), '.');
}

/**
 * تنسيق التاريخ والوقت
 *
 * @param string $datetime  قيمة TIMESTAMP من MySQL
 * @param bool   $time_only إذا true يُرجع الوقت فقط
 * @return string
 */
function format_datetime(string $datetime, bool $time_only = false): string
{
    if (empty($datetime)) return '—';

    try {
        $dt = new DateTimeImmutable($datetime);
        return $time_only
            ? $dt->format('H:i')
            : $dt->format('Y-m-d H:i');
    } catch (Exception) {
        return $datetime;
    }
}

// ============================================================
// توليد الأرقام المرجعية
// ============================================================

/**
 * توليد رقم فاتورة فريد
 * الصيغة: TS-{branch_code}-{YYYYMMDD}-{XXXX}
 *
 * @param string $branch_code رمز الفرع (مثل BR1)
 * @param PDO    $pdo
 * @return string
 */
function generate_invoice_number(string $branch_code, PDO $pdo): string
{
    $date   = date('Ymd');
    $prefix = "TS-{$branch_code}-{$date}-";

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM invoices
         WHERE invoice_number LIKE ?
         FOR UPDATE"
    );
    $stmt->execute([$prefix . '%']);
    $count = (int) $stmt->fetchColumn();

    return $prefix . str_pad((string)($count + 1), 4, '0', STR_PAD_LEFT);
}

/**
 * توليد رقم أمر شراء فريد
 * الصيغة: PO-{YYYYMMDD}-{XXXX}
 *
 * @param PDO $pdo
 * @return string
 */
function generate_po_number(PDO $pdo): string
{
    $date   = date('Ymd');
    $prefix = "PO-{$date}-";

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM purchase_orders
         WHERE po_number LIKE ?
         FOR UPDATE"
    );
    $stmt->execute([$prefix . '%']);
    $count = (int) $stmt->fetchColumn();

    return $prefix . str_pad((string)($count + 1), 4, '0', STR_PAD_LEFT);
}

/**
 * توليد رقم مرتجع فريد
 * الصيغة: RET-{YYYYMMDD}-{XXXX}
 *
 * @param PDO $pdo
 * @return string
 */
function generate_return_number(PDO $pdo): string
{
    $date   = date('Ymd');
    $prefix = "RET-{$date}-";

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM returns
         WHERE return_number LIKE ?
         FOR UPDATE"
    );
    $stmt->execute([$prefix . '%']);
    $count = (int) $stmt->fetchColumn();

    return $prefix . str_pad((string)($count + 1), 4, '0', STR_PAD_LEFT);
}

/**
 * توليد رقم تحويل مخزون فريد
 * الصيغة: TRF-{YYYYMMDD}-{XXXX}
 *
 * @param PDO $pdo
 * @return string
 */
function generate_transfer_number(PDO $pdo): string
{
    $date   = date('Ymd');
    $prefix = "TRF-{$date}-";

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM stock_transfers
         WHERE transfer_number LIKE ?
         FOR UPDATE"
    );
    $stmt->execute([$prefix . '%']);
    $count = (int) $stmt->fetchColumn();

    return $prefix . str_pad((string)($count + 1), 4, '0', STR_PAD_LEFT);
}

// ============================================================
// الإعدادات
// ============================================================

/**
 * يجلب إعداداً من جدول settings
 * الأولوية: إعداد الفرع → الإعداد العام
 *
 * @param string   $key       مفتاح الإعداد
 * @param PDO      $pdo
 * @param int|null $branch_id معرف الفرع (اختياري)
 * @return string             القيمة أو نص فارغ إن لم يوجد
 */
function get_setting(string $key, PDO $pdo, ?int $branch_id = null): string
{
    // حاول الإعداد الفرعي أولاً
    if ($branch_id !== null) {
        $stmt = $pdo->prepare(
            "SELECT setting_value FROM settings
             WHERE setting_key = ? AND branch_id = ?
             LIMIT 1"
        );
        $stmt->execute([$key, $branch_id]);
        $val = $stmt->fetchColumn();
        if ($val !== false) {
            return (string) $val;
        }
    }

    // ثم الإعداد العام
    $stmt = $pdo->prepare(
        "SELECT setting_value FROM settings
         WHERE setting_key = ? AND branch_id IS NULL
         LIMIT 1"
    );
    $stmt->execute([$key]);
    $val = $stmt->fetchColumn();

    return $val !== false ? (string) $val : '';
}

/**
 * يحفظ إعداداً في جدول settings (INSERT or UPDATE)
 *
 * @param string   $key
 * @param string   $value
 * @param PDO      $pdo
 * @param int|null $branch_id
 */
function save_setting(string $key, string $value, PDO $pdo, ?int $branch_id = null): void
{
    $stmt = $pdo->prepare(
        "INSERT INTO settings (setting_key, setting_value, branch_id)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
    );
    $stmt->execute([$key, $value, $branch_id]);
}

// ============================================================
// الأمان
// ============================================================

/**
 * تنظيف الإدخال للعرض الآمن في HTML
 *
 * @param mixed $input
 * @return string
 */
function sanitize(mixed $input): string
{
    return htmlspecialchars(
        trim((string) $input),
        ENT_QUOTES | ENT_HTML5,
        'UTF-8'
    );
}

/**
 * تنظيف قيمة عددية صحيحة
 *
 * @param mixed $input
 * @return int
 */
function sanitize_int(mixed $input): int
{
    return (int) filter_var($input, FILTER_SANITIZE_NUMBER_INT);
}

/**
 * تنظيف قيمة عشرية
 *
 * @param mixed $input
 * @return float
 */
function sanitize_float(mixed $input): float
{
    return (float) filter_var($input, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
}

/**
 * إعادة التوجيه
 *
 * @param string $url
 * @return never
 */
function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

/**
 * يُرجع استجابة JSON ويوقف التنفيذ
 *
 * @param array<mixed> $data
 * @param int          $status_code
 * @return never
 */
function json_response(array $data, int $status_code = 200): never
{
    http_response_code($status_code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ============================================================
// المخزون
// ============================================================

/**
 * يجلب إجمالي الكمية المتاحة لمنتج (فرع + مركزي)
 *
 * @param int      $product_id
 * @param int|null $branch_id   معرف الفرع (null = مركزي فقط)
 * @param PDO      $pdo
 * @return float
 */
function get_available_qty(int $product_id, ?int $branch_id, PDO $pdo): float
{
    // مخزون الفرع
    $branch_qty = 0.0;
    if ($branch_id !== null) {
        $stmt = $pdo->prepare(
            "SELECT quantity FROM inventory
             WHERE product_id = ? AND branch_id = ?
             LIMIT 1"
        );
        $stmt->execute([$product_id, $branch_id]);
        $branch_qty = (float)($stmt->fetchColumn() ?: 0);
    }

    // مخزون المركزي
    $stmt = $pdo->prepare(
        "SELECT quantity FROM inventory
         WHERE product_id = ? AND branch_id IS NULL
         LIMIT 1"
    );
    $stmt->execute([$product_id]);
    $central_qty = (float)($stmt->fetchColumn() ?: 0);

    return max(0, $branch_qty) + max(0, $central_qty);
}

/**
 * يُسجّل حركة مخزون
 *
 * @param PDO    $pdo
 * @param int    $product_id
 * @param int|null $branch_id
 * @param string $type            sale|purchase|transfer_in|transfer_out|return_in|adjustment
 * @param float  $quantity        موجب = دخول | سالب = خروج
 * @param float  $qty_before
 * @param float  $qty_after
 * @param int|null $reference_id
 * @param string|null $reference_type
 * @param string $notes
 */
function log_inventory_movement(
    PDO    $pdo,
    int    $product_id,
    ?int   $branch_id,
    string $type,
    float  $quantity,
    float  $qty_before,
    float  $qty_after,
    ?int   $reference_id   = null,
    ?string $reference_type = null,
    string $notes          = ''
): void {
    $stmt = $pdo->prepare(
        "INSERT INTO inventory_movements
            (product_id, branch_id, movement_type, quantity,
             quantity_before, quantity_after,
             reference_id, reference_type, notes, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([
        $product_id,
        $branch_id,
        $type,
        $quantity,
        $qty_before,
        $qty_after,
        $reference_id,
        $reference_type,
        $notes,
        $_SESSION['user_id'] ?? 0,
    ]);
}

// ============================================================
// عداد تنبيهات المخزون
// ============================================================

/**
 * يعود بعدد المنتجات الناقصة في فرع معين
 *
 * @param int|null $branch_id  null = كل الفروع (super_admin)
 * @param PDO      $pdo
 * @return int
 */
function count_low_stock_alerts(?int $branch_id, PDO $pdo): int
{
    if ($branch_id !== null) {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM inventory i
             JOIN products p ON p.id = i.product_id
             WHERE p.status = 'active'
               AND i.branch_id = ?
               AND i.quantity <= i.min_quantity"
        );
        $stmt->execute([$branch_id]);
    } else {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM inventory i
             JOIN products p ON p.id = i.product_id
             WHERE p.status = 'active'
               AND i.quantity <= i.min_quantity"
        );
        $stmt->execute();
    }

    return (int) $stmt->fetchColumn();
}

// ============================================================
// Pagination
// ============================================================

/**
 * يُرجع بيانات Pagination
 *
 * @param PDO    $pdo
 * @param string $count_query  استعلام COUNT(*)
 * @param array  $params       parameters للاستعلام
 * @param int    $current_page الصفحة الحالية
 * @param int    $per_page     عدد النتائج لكل صفحة
 * @return array{total: int, pages: int, current: int, offset: int, per_page: int}
 */
function paginate(PDO $pdo, string $count_query, array $params, int $current_page = 1, int $per_page = 25): array
{
    $stmt = $pdo->prepare($count_query);
    $stmt->execute($params);
    $total = (int) $stmt->fetchColumn();

    $pages   = max(1, (int) ceil($total / $per_page));
    $current = max(1, min($current_page, $pages));
    $offset  = ($current - 1) * $per_page;

    return [
        'total'    => $total,
        'pages'    => $pages,
        'current'  => $current,
        'offset'   => $offset,
        'per_page' => $per_page,
    ];
}

// ============================================================
// Flash Messages
// ============================================================

/**
 * يحفظ رسالة مؤقتة في الـ session
 *
 * @param string $type    success | error | warning | info
 * @param string $message
 */
function flash(string $type, string $message): void
{
    $_SESSION['flash'] = [
        'type'    => $type,
        'message' => $message,
    ];
}

/**
 * يجلب رسالة الـ flash ويمسحها
 *
 * @return array{type: string, message: string}|null
 */
function get_flash(): ?array
{
    if (!empty($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

/**
 * يعرض رسالة الـ flash كـ Bootstrap alert
 */
function show_flash(): void
{
    $flash = get_flash();
    if ($flash === null) return;

    $type_map = [
        'success' => 'success',
        'error'   => 'danger',
        'warning' => 'warning',
        'info'    => 'info',
    ];
    $bs_type = $type_map[$flash['type']] ?? 'info';
    $msg     = sanitize($flash['message']);

    echo <<<HTML
    <div class="alert alert-{$bs_type} alert-dismissible fade show" role="alert">
        {$msg}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    HTML;
}

// ============================================================
// أسماء المنتجات حسب اللغة
// ============================================================

/**
 * يُرجع اسم المنتج حسب لغة المستخدم
 *
 * @param array  $product   صف من جدول products
 * @param string $lang      'ar' | 'en'
 * @return string
 */
function product_name(array $product, string $lang = 'ar'): string
{
    if ($lang === 'en' && !empty($product['name_en'])) {
        return $product['name_en'];
    }
    return $product['name_ar'];
}

/**
 * يُرجع اسم التصنيف حسب لغة المستخدم
 *
 * @param array  $category
 * @param string $lang
 * @return string
 */
function category_name(array $category, string $lang = 'ar'): string
{
    if ($lang === 'en' && !empty($category['name_en'])) {
        return $category['name_en'];
    }
    return $category['name_ar'];
}
