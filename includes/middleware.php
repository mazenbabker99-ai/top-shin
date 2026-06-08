<?php
// ============================================================
// المسار: includes/middleware.php
// الوظيفة: حماية الصفحات — فحص الجلسة والصلاحيات والفروع
// الاعتمادات: includes/auth.php | includes/functions.php
// ============================================================

declare(strict_types=1);

// ============================================================
// require_login — يتحقق من تسجيل الدخول
// ============================================================

/**
 * يتحقق من أن المستخدم مسجل دخوله.
 * إذا لم يكن كذلك → يحفظ الـ URL الحالي ويُعيد التوجيه لصفحة الدخول.
 *
 * @return void
 */
function require_login(): void
{
    if (!Auth::check()) {
        // حفظ الـ URL الحالي للرجوع إليه بعد الدخول
        $current_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
            . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
            . ($_SERVER['REQUEST_URI'] ?? '/');

        $_SESSION['redirect_after_login'] = $current_url;

        // حساب عمق المسار للـ redirect الصحيح
        $script_dir = dirname($_SERVER['SCRIPT_NAME'] ?? '/');
        $depth      = substr_count(rtrim($script_dir, '/'), '/');
        $prefix     = str_repeat('../', $depth);

        redirect($prefix . 'auth/login.php');
    }
}

// ============================================================
// require_role — يتحقق من الدور
// ============================================================

/**
 * يتحقق من أن دور المستخدم الحالي مسموح به.
 * يستدعي require_login() أولاً تلقائياً.
 *
 * @param  array<string> $allowed_roles  الأدوار المسموح بها مثل: ['super_admin', 'branch_admin']
 * @return void
 */
function require_role(array $allowed_roles): void
{
    // 1. تحقق من تسجيل الدخول أولاً
    require_login();

    // 2. تحقق من الدور
    if (!Auth::hasRole($allowed_roles)) {
        render_403();
    }
}

// ============================================================
// require_branch_access — يتحقق من صلاحية الوصول لفرع محدد
// ============================================================

/**
 * يتحقق من أن المستخدم يملك صلاحية الوصول لفرع معين.
 * - super_admin: وصول كامل لكل الفروع دائماً.
 * - branch_admin / cashier: وصول لفرعهم فقط.
 *
 * @param  int $branch_id  معرف الفرع المطلوب الوصول إليه
 * @return void
 */
function require_branch_access(int $branch_id): void
{
    // 1. تحقق من تسجيل الدخول
    require_login();

    // 2. super_admin له صلاحية كاملة — لا قيود
    if (Auth::getRole() === 'super_admin') {
        return;
    }

    // 3. branch_admin / cashier: تحقق من تطابق الفرع
    $user_branch = Auth::getBranchId();

    if ($user_branch === null || $user_branch !== $branch_id) {
        render_403('branch');
    }
}

// ============================================================
// render_403 — صفحة الخطأ 403
// ============================================================

/**
 * يعرض صفحة خطأ 403 ويوقف التنفيذ.
 *
 * @param  string $type  'role' | 'branch' — لتخصيص الرسالة
 * @return never
 */
function render_403(string $type = 'role'): never
{
    http_response_code(403);

    $lang_code = $_SESSION['lang'] ?? 'ar';
    $is_rtl    = ($lang_code === 'ar');
    $dir       = $is_rtl ? 'rtl' : 'ltr';

    // حساب مسار الـ Bootstrap حسب عمق الصفحة
    $script_dir  = dirname($_SERVER['SCRIPT_NAME'] ?? '/');
    $depth       = substr_count(rtrim($script_dir, '/'), '/');
    $assets_path = str_repeat('../', $depth) . 'assets';

    // نصوص الخطأ حسب اللغة ونوع الخطأ
    if ($lang_code === 'en') {
        $title      = 'Access Denied';
        $heading    = '403 — Access Denied';
        $msg_role   = 'You do not have permission to access this page.';
        $msg_branch = 'You are not allowed to access data from another branch.';
        $btn_back   = 'Go Back';
        $btn_home   = 'Dashboard';
    } else {
        $title      = 'غير مصرح بالوصول';
        $heading    = '403 — ممنوع الوصول';
        $msg_role   = 'ليس لديك صلاحية للوصول إلى هذه الصفحة.';
        $msg_branch = 'غير مسموح لك بالوصول إلى بيانات فرع آخر.';
        $btn_back   = 'رجوع';
        $btn_home   = 'لوحة التحكم';
    }

    $message = ($type === 'branch') ? $msg_branch : $msg_role;

    // تحديد رابط لوحة التحكم حسب الدور
    $role          = Auth::getRole();
    $dashboard_url = match($role) {
        'super_admin'  => str_repeat('../', $depth) . 'superadmin/dashboard.php',
        'branch_admin' => str_repeat('../', $depth) . 'admin/dashboard.php',
        'cashier'      => str_repeat('../', $depth) . 'cashier/dashboard.php',
        default        => str_repeat('../', $depth) . 'auth/login.php',
    };

    // رابط Bootstrap (RTL أو LTR) من CDN
    $bs_css = $is_rtl
        ? 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css'
        : 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css';

    echo <<<HTML
    <!DOCTYPE html>
    <html lang="{$lang_code}" dir="{$dir}">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>{$title} — Top Shine</title>
        <link rel="stylesheet" href="{$bs_css}">
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700&display=swap" rel="stylesheet">
        <style>
            :root {
                --ts-gold:       #C9A84C;
                --ts-gold-light: #E2C97E;
                --ts-gold-dark:  #A0812A;
                --ts-bg-dark:    #0D0D0D;
                --ts-bg-mid:     #1A1A1A;
                --ts-bg-card:    #242424;
                --ts-text-primary: #F5F5F5;
                --ts-border:     #3A3A3A;
            }
            * { font-family: 'Tajawal', sans-serif; }
            body {
                background-color: var(--ts-bg-mid);
                color: var(--ts-text-primary);
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            .error-card {
                background: var(--ts-bg-card);
                border: 1px solid var(--ts-border);
                border-top: 3px solid var(--ts-gold);
                border-radius: 12px;
                padding: 3rem 2.5rem;
                text-align: center;
                max-width: 480px;
                width: 100%;
            }
            .error-icon {
                font-size: 4rem;
                color: var(--ts-gold);
                margin-bottom: 1rem;
            }
            .error-code {
                font-size: 5rem;
                font-weight: 700;
                color: var(--ts-gold);
                line-height: 1;
                margin-bottom: 0.5rem;
            }
            .error-title {
                font-size: 1.4rem;
                font-weight: 600;
                margin-bottom: 1rem;
            }
            .error-msg {
                color: #B0B3B8;
                margin-bottom: 2rem;
                line-height: 1.7;
            }
            .btn-gold {
                background-color: var(--ts-gold);
                color: #0D0D0D;
                font-weight: 600;
                border: none;
                padding: 0.6rem 1.8rem;
                border-radius: 6px;
                text-decoration: none;
                display: inline-block;
                transition: background .2s;
            }
            .btn-gold:hover {
                background-color: var(--ts-gold-light);
                color: #0D0D0D;
            }
            .btn-outline-silver {
                background: transparent;
                color: #B0B3B8;
                border: 1px solid #B0B3B8;
                padding: 0.6rem 1.8rem;
                border-radius: 6px;
                text-decoration: none;
                display: inline-block;
                transition: all .2s;
                margin-inline-end: 0.5rem;
            }
            .btn-outline-silver:hover {
                background: #7A7D82;
                color: #F5F5F5;
            }
            .brand {
                color: var(--ts-gold);
                font-weight: 700;
                font-size: 1.1rem;
                margin-top: 2rem;
                display: block;
            }
        </style>
    </head>
    <body>
        <div class="error-card">
            <div class="error-icon">🔒</div>
            <div class="error-code">403</div>
            <div class="error-title">{$heading}</div>
            <p class="error-msg">{$message}</p>
            <div class="d-flex justify-content-center flex-wrap gap-2">
                <a href="javascript:history.back()" class="btn-outline-silver">{$btn_back}</a>
                <a href="{$dashboard_url}" class="btn-gold">{$btn_home}</a>
            </div>
            <span class="brand">Top Shine ✦ توب شاين</span>
        </div>
    </body>
    </html>
    HTML;

    exit;
}

// ============================================================
// get_user_branch_filter — مساعد للاستعلامات المُقيَّدة بالفرع
// ============================================================

/**
 * يُرجع شرط SQL وقيمة الـ branch_id المناسبَين لدور المستخدم الحالي.
 *
 * الاستخدام:
 *   [$cond, $val] = get_user_branch_filter('i.branch_id');
 *   $sql .= " AND {$cond}";
 *   if ($val !== null) $params[] = $val;
 *
 * @param  string $column  اسم عمود الـ branch_id في الاستعلام (مثل 'i.branch_id')
 * @return array{0: string, 1: int|null}
 *         [0] = شرط SQL — [1] = قيمة الـ branch_id أو null (للـ super_admin)
 */
function get_user_branch_filter(string $column = 'branch_id'): array
{
    if (Auth::getRole() === 'super_admin') {
        // super_admin يرى كل الفروع — لا قيد
        return ['1=1', null];
    }

    $branch_id = Auth::getBranchId();
    return ["{$column} = ?", $branch_id];
}