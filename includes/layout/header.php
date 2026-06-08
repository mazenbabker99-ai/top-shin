<?php
// ============================================================
// المسار: includes/layout/header.php
// الوظيفة: رأس الصفحة المشترك — HTML + CSS + Navbar
// الاعتمادات: config/database.php | includes/auth.php
//             includes/functions.php | includes/middleware.php
// الاستخدام: require_once __DIR__ . '/../../includes/layout/header.php';
// ============================================================

declare(strict_types=1);

// ——— Output Buffering — لمنع مشاكل headers already sent ———
ob_start();

// ——— تأمين البدء ———
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/middleware.php';

// ——— تبديل اللغة (يُعالَج هنا قبل أي HTML) ———
if (!empty($_GET['switch_lang'])) {
    $new_lang = in_array($_GET['switch_lang'], ['ar', 'en'], true)
        ? $_GET['switch_lang']
        : 'ar';

    $_SESSION['lang'] = $new_lang;

    // تحديث حقل lang في قاعدة البيانات إذا المستخدم مسجل
    if (Auth::check()) {
        $pdo_lang = db();
        $stmt     = $pdo_lang->prepare('UPDATE users SET lang = ? WHERE id = ?');
        $stmt->execute([$new_lang, Auth::getUserId()]);
    }

    // إعادة التوجيه للصفحة ذاتها بدون البارامتر
    $current_url = strtok($_SERVER['REQUEST_URI'], '?');
    redirect($current_url);
}

// ——— إعداد المتغيرات الأساسية ———
$lang_code = $_SESSION['lang'] ?? 'ar';
$lang      = load_lang();
$is_rtl    = ($lang_code === 'ar');
$dir       = $is_rtl ? 'rtl' : 'ltr';

// ——— بيانات المستخدم الحالي ———
$user_name   = sanitize($_SESSION['user_name']   ?? '');
$user_role   = $_SESSION['user_role']   ?? '';
$branch_name = sanitize($_SESSION['branch_name'] ?? '');
$branch_id   = Auth::getBranchId();

// ——— الـ CSS المناسب للاتجاه ———
$bs_css = $is_rtl
    ? 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css'
    : 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css';

// ——— أيقونات Bootstrap ———
$bs_icons_css = 'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css';

// ——— حساب مسار assets حسب عمق الصفحة ———
$script_dir  = dirname($_SERVER['SCRIPT_NAME'] ?? '/');
// حساب العمق من جذر المشروع (باستبعاد اسم المجلد الفرعي)
$path_parts  = explode('/', trim($script_dir, '/'));
$project_idx = array_search('topshine_complete', $path_parts);
if ($project_idx !== false) {
    $depth = count($path_parts) - $project_idx - 1;
} else {
    $depth = substr_count(rtrim($script_dir, '/'), '/');
}
$base_path   = str_repeat('../', $depth);
$assets_path = $base_path . 'assets';

// ——— عداد تنبيهات المخزون ———
$low_stock_count = 0;
if (Auth::check()) {
    try {
        $pdo_alert       = db();
        $low_stock_count = count_low_stock_alerts($branch_id, $pdo_alert);
    } catch (Throwable) {
        $low_stock_count = 0;
    }
}

// ——— اسم الدور المُعرَّب ———
$role_label = match($user_role) {
    'super_admin'  => $lang['super_admin'],
    'branch_admin' => $lang['branch_admin'],
    'cashier'      => $lang['cashier_role'],
    default        => $user_role,
};

// ——— رابط تبديل اللغة ———
$switch_lang_code = $is_rtl ? 'en' : 'ar';
$switch_lang_label = $is_rtl ? 'English' : 'عربي';

// ——— CSRF Token ———
$csrf_token = generate_csrf_token();

// ——— اسم الصفحة الحالية (للـ active state في الـ sidebar) ———
// يُحدَّد بـ $page_title من كل صفحة قبل include header
$page_title = $page_title ?? 'Top Shine';

// ——— رابط لوحة التحكم حسب الدور ———
$dashboard_link = match($user_role) {
    'super_admin'  => str_repeat('../', $depth) . 'superadmin/dashboard.php',
    'branch_admin' => str_repeat('../', $depth) . 'admin/dashboard.php',
    'cashier'      => str_repeat('../', $depth) . 'cashier/dashboard.php',
    default        => str_repeat('../', $depth) . 'auth/login.php',
};

// ——— رابط تسجيل الخروج ———
$logout_link = str_repeat('../', $depth) . 'auth/logout.php';

// ——— رابط تنبيهات المخزون ———
$alerts_link = str_repeat('../', $depth) . 'admin/inventory_alerts.php';
?>
<!DOCTYPE html>
<html lang="<?= $lang_code ?>" dir="<?= $dir ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8') ?> — Top Shine</title>

    <!-- Bootstrap CSS (RTL/LTR) -->
    <link rel="stylesheet" href="<?= $bs_css ?>">

    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="<?= $bs_icons_css ?>">

    <!-- Google Fonts — Tajawal -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700;900&display=swap" rel="stylesheet">

    <!-- Top Shine — نظام الألوان والأنماط -->
    <style>
        /* ============================================================
           TOP SHINE — CSS Variables (نظام الألوان الرسمي)
           ============================================================ */
        :root {
            /* الألوان الأساسية */
            --ts-gold:          #C9A84C;
            --ts-gold-light:    #E2C97E;
            --ts-gold-dark:     #A0812A;
            --ts-silver:        #B0B3B8;
            --ts-silver-light:  #D8D9DC;
            --ts-silver-dark:   #7A7D82;

            /* الخلفيات */
            --ts-bg-dark:       #0D0D0D;
            --ts-bg-mid:        #1A1A1A;
            --ts-bg-card:       #242424;
            --ts-bg-input:      #2E2E2E;

            /* النصوص */
            --ts-text-primary:  #F5F5F5;
            --ts-text-secondary:#B0B3B8;
            --ts-text-muted:    #6C757D;

            /* الحالات الوظيفية */
            --ts-success:       #28A745;
            --ts-warning:       #C9A84C;
            --ts-danger:        #DC3545;
            --ts-info:          #B0B3B8;

            /* الحدود */
            --ts-border:        #3A3A3A;
            --ts-border-gold:   #C9A84C;

            /* Sidebar */
            --ts-sidebar-width: 260px;
            --ts-navbar-height: 60px;
        }

        /* ============================================================
           Reset & Base
           ============================================================ */
        *, *::before, *::after {
            box-sizing: border-box;
        }

        html, body {
            height: 100%;
        }

        body {
            font-family: 'Tajawal', sans-serif;
            background-color: var(--ts-bg-mid);
            color: var(--ts-text-primary);
            margin: 0;
            padding: 0;
            min-height: 100vh;
        }

        /* ============================================================
           Bootstrap Overrides — هوية توب شاين
           ============================================================ */

        /* أزرار أساسية */
        .btn-primary {
            background-color: var(--ts-gold);
            border-color: var(--ts-gold-dark);
            color: #0D0D0D;
            font-weight: 600;
        }
        .btn-primary:hover,
        .btn-primary:focus {
            background-color: var(--ts-gold-light);
            border-color: var(--ts-gold);
            color: #0D0D0D;
        }
        .btn-primary:active {
            background-color: var(--ts-gold-dark) !important;
            border-color: var(--ts-gold-dark) !important;
            color: #0D0D0D !important;
        }

        /* أزرار ثانوية */
        .btn-outline-secondary {
            border-color: var(--ts-silver);
            color: var(--ts-silver);
            background: transparent;
        }
        .btn-outline-secondary:hover {
            background-color: var(--ts-silver-dark);
            border-color: var(--ts-silver-dark);
            color: var(--ts-text-primary);
        }

        /* أزرار خطر */
        .btn-danger {
            background-color: var(--ts-danger);
            border-color: #b02a37;
        }

        /* حقول الإدخال */
        .form-control,
        .form-select {
            background-color: var(--ts-bg-input);
            border-color: var(--ts-border);
            color: var(--ts-text-primary);
        }
        .form-control:focus,
        .form-select:focus {
            background-color: var(--ts-bg-input);
            border-color: var(--ts-gold);
            color: var(--ts-text-primary);
            box-shadow: 0 0 0 0.2rem rgba(201, 168, 76, 0.25);
        }
        .form-control::placeholder {
            color: var(--ts-text-muted);
        }
        .form-control:disabled,
        .form-select:disabled {
            background-color: #1e1e1e;
            color: var(--ts-text-muted);
        }
        .form-label {
            color: var(--ts-text-secondary);
            font-weight: 500;
            margin-bottom: 0.3rem;
        }
        .input-group-text {
            background-color: var(--ts-bg-dark);
            border-color: var(--ts-border);
            color: var(--ts-silver);
        }

        /* الجداول */
        .table {
            color: var(--ts-text-primary);
            border-color: var(--ts-border);
        }
        .table thead th {
            background-color: var(--ts-bg-dark);
            color: var(--ts-gold);
            border-color: var(--ts-border-gold);
            font-weight: 600;
            white-space: nowrap;
        }
        .table tbody tr:nth-child(odd) {
            background-color: var(--ts-bg-card);
        }
        .table tbody tr:nth-child(even) {
            background-color: var(--ts-bg-mid);
        }
        .table tbody tr:hover {
            background-color: rgba(201, 168, 76, 0.07);
        }
        .table tbody td {
            border-color: var(--ts-border);
            vertical-align: middle;
        }
        .table-bordered {
            border-color: var(--ts-border);
        }

        /* Cards */
        .card {
            background-color: var(--ts-bg-card);
            border-color: var(--ts-border);
            color: var(--ts-text-primary);
        }
        .card-header {
            background-color: var(--ts-bg-dark);
            border-bottom: 1px solid var(--ts-border-gold);
            color: var(--ts-gold);
            font-weight: 600;
        }
        .card-footer {
            background-color: var(--ts-bg-dark);
            border-top: 1px solid var(--ts-border);
        }

        /* Modals */
        .modal-content {
            background-color: var(--ts-bg-card);
            border-color: var(--ts-border-gold);
            color: var(--ts-text-primary);
        }
        .modal-header {
            background-color: var(--ts-bg-dark);
            border-bottom: 1px solid var(--ts-border-gold);
        }
        .modal-header .modal-title {
            color: var(--ts-gold);
            font-weight: 600;
        }
        .modal-footer {
            background-color: var(--ts-bg-dark);
            border-top: 1px solid var(--ts-border);
        }
        .btn-close {
            filter: invert(1) brightness(0.7);
        }

        /* Dropdowns */
        .dropdown-menu {
            background-color: var(--ts-bg-card);
            border-color: var(--ts-border);
        }
        .dropdown-item {
            color: var(--ts-text-primary);
        }
        .dropdown-item:hover,
        .dropdown-item:focus {
            background-color: rgba(201, 168, 76, 0.1);
            color: var(--ts-gold);
        }
        .dropdown-divider {
            border-color: var(--ts-border);
        }

        /* Badges */
        .badge.bg-warning {
            background-color: var(--ts-gold) !important;
            color: #0D0D0D;
        }
        .badge.bg-success {
            background-color: var(--ts-success) !important;
        }
        .badge.bg-danger {
            background-color: var(--ts-danger) !important;
        }
        .badge.bg-secondary {
            background-color: var(--ts-silver-dark) !important;
        }

        /* Alerts */
        .alert-success {
            background-color: rgba(40, 167, 69, 0.15);
            border-color: var(--ts-success);
            color: #6fdb8a;
        }
        .alert-danger {
            background-color: rgba(220, 53, 69, 0.15);
            border-color: var(--ts-danger);
            color: #f08090;
        }
        .alert-warning {
            background-color: rgba(201, 168, 76, 0.15);
            border-color: var(--ts-gold);
            color: var(--ts-gold-light);
        }
        .alert-info {
            background-color: rgba(176, 179, 184, 0.15);
            border-color: var(--ts-silver);
            color: var(--ts-silver-light);
        }

        /* Pagination */
        .pagination .page-link {
            background-color: var(--ts-bg-card);
            border-color: var(--ts-border);
            color: var(--ts-silver);
        }
        .pagination .page-link:hover {
            background-color: rgba(201, 168, 76, 0.15);
            color: var(--ts-gold);
            border-color: var(--ts-gold-dark);
        }
        .pagination .page-item.active .page-link {
            background-color: var(--ts-gold);
            border-color: var(--ts-gold-dark);
            color: #0D0D0D;
            font-weight: 700;
        }
        .pagination .page-item.disabled .page-link {
            background-color: var(--ts-bg-dark);
            color: var(--ts-text-muted);
        }

        /* Nav Tabs */
        .nav-tabs {
            border-bottom-color: var(--ts-border);
        }
        .nav-tabs .nav-link {
            color: var(--ts-silver);
            border-color: transparent;
        }
        .nav-tabs .nav-link:hover {
            color: var(--ts-gold);
            border-color: transparent;
        }
        .nav-tabs .nav-link.active {
            background-color: var(--ts-bg-card);
            border-color: var(--ts-border-gold) var(--ts-border-gold) var(--ts-bg-card);
            color: var(--ts-gold);
        }

        /* Scrollbar */
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: var(--ts-bg-dark); }
        ::-webkit-scrollbar-thumb { background: var(--ts-border); border-radius: 3px; }
        ::-webkit-scrollbar-thumb:hover { background: var(--ts-silver-dark); }

        /* ============================================================
           Navbar — الشريط العلوي
           ============================================================ */
        .ts-navbar {
            position: fixed;
            top: 0;
            right: 0;
            left: 0;
            height: var(--ts-navbar-height);
            background-color: var(--ts-bg-dark);
            border-bottom: 2px solid var(--ts-gold-dark);
            display: flex;
            align-items: center;
            padding: 0 1.25rem;
            z-index: 1040;
            gap: 0.75rem;
        }

        /* زر القائمة للموبايل */
        .ts-sidebar-toggle {
            background: none;
            border: none;
            color: var(--ts-silver);
            font-size: 1.4rem;
            cursor: pointer;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            transition: color .2s;
            display: none;
        }
        .ts-sidebar-toggle:hover { color: var(--ts-gold); }

        /* اسم المتجر / الشعار في الـ navbar */
        .ts-navbar-brand {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            text-decoration: none;
            color: var(--ts-gold);
            font-weight: 700;
            font-size: 1.1rem;
            white-space: nowrap;
        }
        .ts-navbar-brand img {
            height: 36px;
            width: auto;
            object-fit: contain;
        }
        .ts-navbar-brand span {
            color: var(--ts-gold);
            letter-spacing: 0.02em;
        }

        /* Spacer */
        .ts-navbar-spacer { flex: 1; }

        /* اسم الفرع في الـ navbar */
        .ts-branch-badge {
            background: rgba(201, 168, 76, 0.12);
            border: 1px solid var(--ts-gold-dark);
            color: var(--ts-gold-light);
            font-size: 0.78rem;
            padding: 0.25rem 0.7rem;
            border-radius: 20px;
            white-space: nowrap;
        }

        /* زر تبديل اللغة */
        .ts-lang-btn {
            background: transparent;
            border: 1px solid var(--ts-silver-dark);
            color: var(--ts-silver);
            font-size: 0.82rem;
            font-weight: 600;
            padding: 0.3rem 0.75rem;
            border-radius: 20px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            transition: all .2s;
            white-space: nowrap;
        }
        .ts-lang-btn:hover {
            border-color: var(--ts-gold);
            color: var(--ts-gold);
        }

        /* زر تنبيهات المخزون */
        .ts-alert-btn {
            position: relative;
            background: none;
            border: none;
            color: var(--ts-silver);
            font-size: 1.3rem;
            cursor: pointer;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            text-decoration: none;
            transition: color .2s;
            display: inline-flex;
            align-items: center;
        }
        .ts-alert-btn:hover { color: var(--ts-gold); }
        .ts-alert-btn .badge {
            position: absolute;
            top: -2px;
            font-size: 0.6rem;
            min-width: 16px;
            height: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0 4px;
        }
        /* موضع البادج حسب الاتجاه */
        [dir="rtl"] .ts-alert-btn .badge { left: -4px; }
        [dir="ltr"] .ts-alert-btn .badge { right: -4px; }

        /* مستخدم Dropdown */
        .ts-user-dropdown .dropdown-toggle {
            background: transparent;
            border: none;
            color: var(--ts-text-primary);
            font-size: 0.9rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.3rem 0.5rem;
            border-radius: 6px;
            transition: background .2s;
        }
        .ts-user-dropdown .dropdown-toggle:hover {
            background: rgba(201, 168, 76, 0.08);
        }
        .ts-user-dropdown .dropdown-toggle::after { display: none; }
        .ts-user-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: var(--ts-gold-dark);
            color: #0D0D0D;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 0.85rem;
            flex-shrink: 0;
        }
        .ts-user-info { line-height: 1.2; text-align: start; }
        .ts-user-info .ts-user-name {
            font-weight: 600;
            font-size: 0.88rem;
            color: var(--ts-text-primary);
        }
        .ts-user-info .ts-user-role {
            font-size: 0.72rem;
            color: var(--ts-gold);
        }

        /* ============================================================
           Layout — Wrapper
           ============================================================ */
        .ts-wrapper {
            display: flex;
            min-height: 100vh;
            padding-top: var(--ts-navbar-height);
        }

        /* منطقة المحتوى الرئيسي */
        .ts-main-content {
            flex: 1;
            min-width: 0;
            padding: 1.5rem;
            transition: margin .3s ease;
        }

        /* Dashboard KPI cards */
        .ts-kpi-card {
            background: var(--ts-bg-card);
            border: 1px solid var(--ts-border);
            border-top: 3px solid var(--ts-gold);
            border-radius: 8px;
            padding: 1.25rem;
        }
        .ts-kpi-card .kpi-value {
            font-size: 1.6rem;
            font-weight: 700;
            color: var(--ts-gold);
        }
        .ts-kpi-card .kpi-label {
            font-size: 0.85rem;
            color: var(--ts-text-secondary);
        }
        .ts-kpi-card .kpi-icon {
            font-size: 2rem;
            color: var(--ts-gold-dark);
            opacity: 0.6;
        }

        /* Section Heading */
        .ts-section-title {
            color: var(--ts-gold);
            font-weight: 700;
            font-size: 1.15rem;
            border-bottom: 1px solid var(--ts-border);
            padding-bottom: 0.5rem;
            margin-bottom: 1.25rem;
        }

        /* Status badges */
        .ts-status-active   { color: var(--ts-success); }
        .ts-status-inactive { color: var(--ts-danger); }
        .ts-status-pending  { color: var(--ts-warning); }
        .ts-status-low      { color: var(--ts-danger); font-weight: 600; }

        /* Gold separator line */
        .ts-gold-line {
            border: 0;
            border-top: 1px solid var(--ts-border-gold);
            opacity: 0.4;
        }

        /* ============================================================
           Print — إخفاء عناصر الواجهة عند الطباعة
           ============================================================ */
        @media print {
            .ts-navbar,
            .ts-sidebar,
            .ts-sidebar-toggle,
            .no-print,
            .btn-close,
            .modal-backdrop { display: none !important; }

            .ts-main-content {
                margin: 0 !important;
                padding: 0 !important;
            }

            body {
                background: #fff !important;
                color: #000 !important;
            }

            .card {
                border: 1px solid #ccc !important;
                background: #fff !important;
                color: #000 !important;
            }
        }

        /* ============================================================
           Responsive
           ============================================================ */
        @media (max-width: 768px) {
            .ts-sidebar-toggle { display: flex !important; }
            .ts-branch-badge   { display: none; }
            .ts-user-info      { display: none; }
            .ts-main-content   { padding: 1rem 0.75rem; }
        }
    </style>

    <!-- ملف CSS مخصص إضافي (اختياري لكل صفحة) -->
    <?php if (!empty($extra_css)): ?>
        <style><?= $extra_css ?></style>
    <?php endif; ?>
</head>
<body>

<!-- ============================================================
     Navbar العلوي
     ============================================================ -->
<nav class="ts-navbar" role="navigation" aria-label="شريط التنقل الرئيسي">

    <!-- زر القائمة الجانبية (موبايل) -->
    <button class="ts-sidebar-toggle" id="sidebarToggleBtn"
            aria-label="فتح القائمة" aria-expanded="false">
        <i class="bi bi-list"></i>
    </button>

    <!-- شعار المتجر -->
    <a href="<?= htmlspecialchars($dashboard_link, ENT_QUOTES, 'UTF-8') ?>"
       class="ts-navbar-brand" title="Top Shine">
        <img src="<?= $assets_path ?>/images/logo.png"
             alt="Top Shine Logo"
             onerror="this.style.display='none'">
        <span>Top Shine</span>
    </a>

    <!-- Spacer -->
    <div class="ts-navbar-spacer"></div>

    <?php if (Auth::check()): ?>

        <!-- اسم الفرع -->
        <?php if ($branch_name): ?>
            <span class="ts-branch-badge">
                <i class="bi bi-shop me-1"></i>
                <?= $branch_name ?>
            </span>
        <?php elseif ($user_role === 'super_admin'): ?>
            <span class="ts-branch-badge">
                <i class="bi bi-globe me-1"></i>
                <?= $lang['super_admin'] ?>
            </span>
        <?php endif; ?>

        <!-- زر تنبيهات المخزون (للـ super_admin وbranch_admin فقط) -->
        <?php if (in_array($user_role, ['super_admin', 'branch_admin'], true)): ?>
            <a href="<?= htmlspecialchars($alerts_link, ENT_QUOTES, 'UTF-8') ?>"
               class="ts-alert-btn"
               title="<?= $lang['low_stock_alerts'] ?>">
                <i class="bi bi-bell<?= $low_stock_count > 0 ? '-fill' : '' ?>"></i>
                <?php if ($low_stock_count > 0): ?>
                    <span class="badge bg-danger rounded-pill">
                        <?= $low_stock_count > 99 ? '99+' : $low_stock_count ?>
                    </span>
                <?php endif; ?>
            </a>
        <?php endif; ?>

        <!-- زر تبديل اللغة -->
        <a href="?switch_lang=<?= $switch_lang_code ?>"
           class="ts-lang-btn"
           title="<?= $switch_lang_label ?>">
            <i class="bi bi-translate"></i>
            <span><?= $switch_lang_label ?></span>
        </a>

        <!-- Dropdown المستخدم -->
        <div class="ts-user-dropdown dropdown">
            <button class="dropdown-toggle"
                    data-bs-toggle="dropdown"
                    aria-expanded="false"
                    aria-label="قائمة المستخدم">
                <!-- Avatar — الحرف الأول من الاسم -->
                <div class="ts-user-avatar" aria-hidden="true">
                    <?= mb_substr($user_name, 0, 1, 'UTF-8') ?>
                </div>
                <div class="ts-user-info d-none d-md-block">
                    <div class="ts-user-name"><?= $user_name ?></div>
                    <div class="ts-user-role"><?= $role_label ?></div>
                </div>
                <i class="bi bi-chevron-down ms-1 d-none d-md-inline"
                   style="font-size:.7rem; color:var(--ts-silver-dark)"></i>
            </button>

            <ul class="dropdown-menu dropdown-menu-end shadow">
                <!-- معلومات المستخدم -->
                <li class="px-3 py-2">
                    <div style="font-weight:600; color:var(--ts-gold); font-size:.9rem">
                        <?= $user_name ?>
                    </div>
                    <div style="font-size:.78rem; color:var(--ts-text-muted)">
                        <?= $role_label ?>
                    </div>
                </li>
                <li><hr class="dropdown-divider"></li>

                <!-- إعدادات الفرع (للـ branch_admin) -->
                <?php if ($user_role === 'branch_admin'): ?>
                    <li>
                        <a class="dropdown-item" href="<?= str_repeat('../', $depth) ?>admin/branch_settings.php">
                            <i class="bi bi-gear me-2" style="color:var(--ts-silver)"></i>
                            <?= $lang['branch_settings'] ?>
                        </a>
                    </li>
                <?php endif; ?>

                <!-- إعدادات النظام (للـ super_admin) -->
                <?php if ($user_role === 'super_admin'): ?>
                    <li>
                        <a class="dropdown-item" href="<?= str_repeat('../', $depth) ?>superadmin/branches.php">
                            <i class="bi bi-diagram-3 me-2" style="color:var(--ts-silver)"></i>
                            <?= $lang['branches'] ?>
                        </a>
                    </li>
                <?php endif; ?>

                <li><hr class="dropdown-divider"></li>

                <!-- تسجيل الخروج -->
                <li>
                    <a class="dropdown-item text-danger" href="<?= htmlspecialchars($logout_link, ENT_QUOTES, 'UTF-8') ?>">
                        <i class="bi bi-box-arrow-right me-2"></i>
                        <?= $lang['logout'] ?>
                    </a>
                </li>
            </ul>
        </div>

    <?php endif; /* Auth::check() */ ?>
</nav>

<!-- ============================================================
     بداية الـ Wrapper الرئيسي (يُكمله sidebar.php والمحتوى)
     ============================================================ -->
<div class="ts-wrapper">

<?php
// ——— Flash Message (رسائل مؤقتة) ———
// سيتم عرضها داخل المحتوى بعد include sidebar
// نحفظ الـ flash لنعرضها في المكان المناسب
$_flash_message = get_flash();
?>