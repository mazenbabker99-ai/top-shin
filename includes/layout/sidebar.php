<?php
// ============================================================
// المسار: includes/layout/sidebar.php
// الوظيفة: القائمة الجانبية — تتغير بالكامل حسب دور المستخدم
// الاعتمادات: يُضمَّن بعد header.php مباشرةً
// المتغيرات المتاحة من header.php:
//   $lang, $lang_code, $is_rtl, $depth, $assets_path,
//   $user_role, $user_name, $branch_name, $dashboard_link
// ملاحظة: $current_page يُعيَّن في كل صفحة قبل include header
//   مثال: $current_page = 'products';
// ============================================================

// ——— الصفحة الحالية لتمييز الرابط النشط ———
$current_page = $current_page ?? '';

// ——— دالة مساعدة لتوليد رابط الـ sidebar ———
// تُرجع class="nav-link active" أو class="nav-link"
$nav_link = function(string $page) use ($current_page): string {
    $is_active = ($current_page === $page);
    return 'nav-link' . ($is_active ? ' active' : '');
};

// ——— مسار الروابط حسب العمق ———
// استخدام مسار نسبي صحيح من المجلد الحالي إلى جذر المشروع
$base = $base_path ?? str_repeat('../', $depth);

// ——— روابط القائمة مجمّعة حسب الدور ———

// super_admin — روابط القائمة الكاملة
$menu_super_admin = [
    'group_main' => [
        'label' => null, // بدون عنوان مجموعة
        'items' => [
            ['page' => 'dashboard',   'icon' => 'bi-speedometer2',  'label' => $lang['dashboard'],   'url' => $base . 'superadmin/dashboard.php'],
        ],
    ],
    'group_operations' => [
        'label' => ($lang_code === 'ar') ? 'العمليات' : 'Operations',
        'items' => [
            ['page' => 'branches',    'icon' => 'bi-diagram-3',     'label' => $lang['branches'],     'url' => $base . 'superadmin/branches.php'],
            ['page' => 'users',       'icon' => 'bi-people',        'label' => $lang['users'],        'url' => $base . 'superadmin/users.php'],
        ],
    ],
    'group_catalog' => [
        'label' => ($lang_code === 'ar') ? 'المنتجات والمخزون' : 'Products & Inventory',
        'items' => [
            ['page' => 'categories',  'icon' => 'bi-tags',          'label' => $lang['categories'],   'url' => $base . 'admin/categories.php'],
            ['page' => 'products',    'icon' => 'bi-box-seam',      'label' => $lang['products'],     'url' => $base . 'admin/products.php'],
            ['page' => 'inventory',   'icon' => 'bi-archive',       'label' => $lang['inventory'],    'url' => $base . 'admin/inventory.php'],
            ['page' => 'stock_transfers', 'icon' => 'bi-arrow-left-right', 'label' => $lang['stock_transfers'], 'url' => $base . 'admin/stock_transfers.php'],
            ['page' => 'inventory_alerts', 'icon' => 'bi-exclamation-triangle', 'label' => $lang['inventory_alerts'], 'url' => $base . 'admin/inventory_alerts.php'],
        ],
    ],
    'group_purchases' => [
        'label' => ($lang_code === 'ar') ? 'المشتريات' : 'Purchases',
        'items' => [
            ['page' => 'suppliers',       'icon' => 'bi-truck',           'label' => $lang['suppliers'],       'url' => $base . 'admin/suppliers.php'],
            ['page' => 'purchase_orders', 'icon' => 'bi-receipt',         'label' => $lang['purchase_orders'], 'url' => $base . 'admin/purchase_orders.php'],
        ],
    ],
    'group_sales' => [
        'label' => ($lang_code === 'ar') ? 'المبيعات' : 'Sales',
        'items' => [
            ['page' => 'returns',   'icon' => 'bi-arrow-return-left', 'label' => $lang['returns'],   'url' => $base . 'admin/returns.php'],
            ['page' => 'customers', 'icon' => 'bi-person-lines-fill', 'label' => $lang['customers'], 'url' => $base . 'admin/customers.php'],
            ['page' => 'expenses',  'icon' => 'bi-wallet2',           'label' => $lang['expenses'],  'url' => $base . 'admin/expenses.php'],
        ],
    ],
    'group_reports' => [
        'label' => ($lang_code === 'ar') ? 'التقارير' : 'Reports',
        'items' => [
            ['page' => 'report_sales',      'icon' => 'bi-graph-up',        'label' => $lang['sales_report'],      'url' => $base . 'reports/sales.php'],
            ['page' => 'report_branches',   'icon' => 'bi-bar-chart-line',  'label' => $lang['branches_report'],   'url' => $base . 'reports/branches.php'],
            ['page' => 'report_profit',     'icon' => 'bi-currency-dollar', 'label' => $lang['profit_report'],     'url' => $base . 'reports/profit.php'],
            ['page' => 'report_inventory',  'icon' => 'bi-clipboard-data',  'label' => $lang['inventory_report'],  'url' => $base . 'reports/inventory.php'],
            ['page' => 'report_purchases',  'icon' => 'bi-cart-check',      'label' => $lang['purchases_report'],  'url' => $base . 'reports/purchases.php'],
            ['page' => 'report_returns',    'icon' => 'bi-arrow-counterclockwise', 'label' => $lang['returns_report'], 'url' => $base . 'reports/returns.php'],
            ['page' => 'report_expenses',   'icon' => 'bi-journal-text',    'label' => $lang['expenses_report'],   'url' => $base . 'reports/expenses.php'],
        ],
    ],
    'group_system' => [
        'label' => ($lang_code === 'ar') ? 'النظام' : 'System',
        'items' => [
            ['page' => 'audit_log', 'icon' => 'bi-shield-check', 'label' => $lang['audit_log'], 'url' => $base . 'superadmin/audit_logs.php'],
        ],
    ],
];

// branch_admin — قائمة إدارة الفرع
$menu_branch_admin = [
    'group_main' => [
        'label' => null,
        'items' => [
            ['page' => 'dashboard', 'icon' => 'bi-speedometer2', 'label' => $lang['dashboard'], 'url' => $base . 'admin/dashboard.php'],
        ],
    ],
    'group_catalog' => [
        'label' => ($lang_code === 'ar') ? 'المنتجات والمخزون' : 'Products & Inventory',
        'items' => [
            ['page' => 'categories',       'icon' => 'bi-tags',                'label' => $lang['categories'],       'url' => $base . 'admin/categories.php'],
            ['page' => 'products',         'icon' => 'bi-box-seam',            'label' => $lang['products'],         'url' => $base . 'admin/products.php'],
            ['page' => 'inventory',        'icon' => 'bi-archive',             'label' => $lang['inventory'],        'url' => $base . 'admin/inventory.php'],
            ['page' => 'stock_transfers',  'icon' => 'bi-arrow-left-right',    'label' => $lang['stock_transfers'],  'url' => $base . 'admin/stock_transfers.php'],
            ['page' => 'inventory_alerts', 'icon' => 'bi-exclamation-triangle','label' => $lang['inventory_alerts'], 'url' => $base . 'admin/inventory_alerts.php'],
        ],
    ],
    'group_purchases' => [
        'label' => ($lang_code === 'ar') ? 'المشتريات' : 'Purchases',
        'items' => [
            ['page' => 'suppliers',       'icon' => 'bi-truck',   'label' => $lang['suppliers'],       'url' => $base . 'admin/suppliers.php'],
            ['page' => 'purchase_orders', 'icon' => 'bi-receipt', 'label' => $lang['purchase_orders'], 'url' => $base . 'admin/purchase_orders.php'],
        ],
    ],
    'group_sales' => [
        'label' => ($lang_code === 'ar') ? 'المبيعات' : 'Sales',
        'items' => [
            ['page' => 'returns',   'icon' => 'bi-arrow-return-left', 'label' => $lang['returns'],   'url' => $base . 'admin/returns.php'],
            ['page' => 'customers', 'icon' => 'bi-person-lines-fill', 'label' => $lang['customers'], 'url' => $base . 'admin/customers.php'],
            ['page' => 'expenses',  'icon' => 'bi-wallet2',           'label' => $lang['expenses'],  'url' => $base . 'admin/expenses.php'],
        ],
    ],
    'group_reports' => [
        'label' => ($lang_code === 'ar') ? 'التقارير' : 'Reports',
        'items' => [
            ['page' => 'report_sales',     'icon' => 'bi-graph-up',       'label' => $lang['sales_report'],     'url' => $base . 'reports/sales.php'],
            ['page' => 'report_profit',    'icon' => 'bi-currency-dollar','label' => $lang['profit_report'],    'url' => $base . 'reports/profit.php'],
            ['page' => 'report_inventory', 'icon' => 'bi-clipboard-data', 'label' => $lang['inventory_report'], 'url' => $base . 'reports/inventory.php'],
            ['page' => 'report_purchases', 'icon' => 'bi-cart-check',     'label' => $lang['purchases_report'], 'url' => $base . 'reports/purchases.php'],
            ['page' => 'report_returns',   'icon' => 'bi-arrow-counterclockwise', 'label' => $lang['returns_report'], 'url' => $base . 'reports/returns.php'],
            ['page' => 'report_expenses',  'icon' => 'bi-journal-text',   'label' => $lang['expenses_report'],  'url' => $base . 'reports/expenses.php'],
        ],
    ],
    'group_settings' => [
        'label' => ($lang_code === 'ar') ? 'الإعدادات' : 'Settings',
        'items' => [
            ['page' => 'branch_settings', 'icon' => 'bi-gear', 'label' => $lang['branch_settings'], 'url' => $base . 'admin/branch_settings.php'],
        ],
    ],
];

// cashier — قائمة مبسّطة
$menu_cashier = [
    'group_main' => [
        'label' => null,
        'items' => [
            ['page' => 'dashboard', 'icon' => 'bi-speedometer2',    'label' => $lang['dashboard'],    'url' => $base . 'cashier/dashboard.php'],
            ['page' => 'pos',       'icon' => 'bi-cart3',           'label' => $lang['pos'],          'url' => $base . 'cashier/pos.php'],
            ['page' => 'invoices',  'icon' => 'bi-file-earmark-text','label' => $lang['my_invoices'], 'url' => $base . 'cashier/dashboard.php#my-invoices'],
        ],
    ],
];

// ——— اختيار القائمة حسب الدور ———
$menu = match($user_role) {
    'super_admin'  => $menu_super_admin,
    'branch_admin' => $menu_branch_admin,
    'cashier'      => $menu_cashier,
    default        => [],
};
?>

<!-- ============================================================
     Sidebar CSS
     ============================================================ -->
<style>
    /* ——— Sidebar Container ——— */
    .ts-sidebar {
        width: var(--ts-sidebar-width);
        min-width: var(--ts-sidebar-width);
        background-color: var(--ts-bg-dark);
        border-inline-end: 1px solid var(--ts-border);
        display: flex;
        flex-direction: column;
        position: sticky;
        top: var(--ts-navbar-height);
        height: calc(100vh - var(--ts-navbar-height));
        overflow-y: auto;
        overflow-x: hidden;
        flex-shrink: 0;
        transition: transform .3s ease, width .3s ease;
        z-index: 1030;
    }

    /* Scrollbar داخل الـ sidebar */
    .ts-sidebar::-webkit-scrollbar { width: 4px; }
    .ts-sidebar::-webkit-scrollbar-track { background: transparent; }
    .ts-sidebar::-webkit-scrollbar-thumb { background: var(--ts-border); border-radius: 2px; }

    /* ——— Logo Block ——— */
    .ts-sidebar-logo {
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 1.25rem 1rem 1rem;
        border-bottom: 1px solid var(--ts-border);
        gap: 0.6rem;
        text-decoration: none;
    }
    .ts-sidebar-logo img {
        width: 140px;
        height: auto;
        object-fit: contain;
    }
    /* Fallback إذا لم يوجد الشعار */
    .ts-sidebar-logo-text {
        color: var(--ts-gold);
        font-weight: 900;
        font-size: 1.25rem;
        letter-spacing: 0.05em;
        display: none; /* يظهر فقط عند خطأ الصورة */
    }

    /* ——— Navigation ——— */
    .ts-sidebar-nav {
        flex: 1;
        padding: 0.75rem 0;
    }

    /* عنوان مجموعة */
    .ts-nav-group-label {
        font-size: 0.68rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        color: var(--ts-text-muted);
        padding: 0.9rem 1.1rem 0.3rem;
        margin: 0;
        user-select: none;
    }

    /* روابط التنقل */
    .ts-sidebar-nav .nav-link {
        display: flex;
        align-items: center;
        gap: 0.65rem;
        padding: 0.6rem 1.1rem;
        color: var(--ts-text-secondary);
        font-size: 0.88rem;
        font-weight: 400;
        border-radius: 0;
        text-decoration: none;
        white-space: nowrap;
        overflow: hidden;
        transition: color .18s, background .18s, border-color .18s;
        position: relative;
        /* حد داخلي للعنصر النشط — يختلف حسب الاتجاه */
        border-inline-start: 3px solid transparent;
    }

    .ts-sidebar-nav .nav-link i {
        font-size: 1rem;
        flex-shrink: 0;
        width: 1.1rem;
        text-align: center;
        color: var(--ts-silver-dark);
        transition: color .18s;
    }

    /* Hover */
    .ts-sidebar-nav .nav-link:hover {
        color: var(--ts-gold-light);
        background-color: rgba(201, 168, 76, 0.07);
        border-inline-start-color: var(--ts-gold-dark);
    }
    .ts-sidebar-nav .nav-link:hover i {
        color: var(--ts-gold-light);
    }

    /* Active — الرابط النشط */
    .ts-sidebar-nav .nav-link.active {
        color: var(--ts-gold);
        background-color: rgba(201, 168, 76, 0.12);
        border-inline-start-color: var(--ts-gold);
        font-weight: 600;
    }
    .ts-sidebar-nav .nav-link.active i {
        color: var(--ts-gold);
    }

    /* خط فاصل بين المجموعات */
    .ts-nav-divider {
        border: none;
        border-top: 1px solid var(--ts-border);
        margin: 0.4rem 0;
        opacity: 0.5;
    }

    /* ——— Footer الـ Sidebar ——— */
    .ts-sidebar-footer {
        padding: 0.75rem 1rem;
        border-top: 1px solid var(--ts-border);
    }
    .ts-sidebar-footer a {
        display: flex;
        align-items: center;
        gap: 0.6rem;
        color: var(--ts-danger);
        font-size: 0.85rem;
        text-decoration: none;
        padding: 0.5rem 0.1rem;
        border-radius: 4px;
        transition: color .18s, background .18s;
    }
    .ts-sidebar-footer a:hover {
        color: #ff6b6b;
        background: rgba(220, 53, 69, 0.08);
        padding-inline-start: 0.4rem;
    }
    .ts-sidebar-footer a i {
        font-size: 1rem;
    }

    /* ——— Overlay للموبايل ——— */
    .ts-sidebar-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0.65);
        z-index: 1029;
        backdrop-filter: blur(2px);
    }
    .ts-sidebar-overlay.show { display: block; }

    /* ——— Responsive — الموبايل ——— */
    @media (max-width: 768px) {
        .ts-sidebar {
            position: fixed;
            top: var(--ts-navbar-height);
            height: calc(100vh - var(--ts-navbar-height));
            z-index: 1035;
            /* إخفاء حسب الاتجاه */
        }

        /* RTL — الـ sidebar يأتي من اليمين */
        [dir="rtl"] .ts-sidebar {
            right: 0;
            left: auto;
            transform: translateX(100%);
        }
        [dir="rtl"] .ts-sidebar.show {
            transform: translateX(0);
        }

        /* LTR — الـ sidebar يأتي من اليسار */
        [dir="ltr"] .ts-sidebar {
            left: 0;
            right: auto;
            transform: translateX(-100%);
        }
        [dir="ltr"] .ts-sidebar.show {
            transform: translateX(0);
        }

        .ts-main-content {
            margin-inline-start: 0 !important;
        }
    }

    /* ——— Print ——— */
    @media print {
        .ts-sidebar,
        .ts-sidebar-overlay { display: none !important; }
    }
</style>

<!-- Overlay للموبايل -->
<div class="ts-sidebar-overlay" id="sidebarOverlay" aria-hidden="true"></div>

<!-- ============================================================
     الـ Sidebar
     ============================================================ -->
<aside class="ts-sidebar" id="mainSidebar" role="navigation" aria-label="القائمة الجانبية">

    <!-- شعار المتجر داخل الـ Sidebar -->
    <a href="<?= htmlspecialchars($dashboard_link, ENT_QUOTES, 'UTF-8') ?>"
       class="ts-sidebar-logo"
       title="Top Shine — <?= $lang['dashboard'] ?>">
        <img
            src="<?= $assets_path ?>/images/logo.png"
            alt="Top Shine Logo"
            onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
        <span class="ts-sidebar-logo-text">✦ Top Shine</span>
    </a>

    <!-- ——— روابط التنقل ——— -->
    <nav class="ts-sidebar-nav">
        <?php foreach ($menu as $group_key => $group): ?>

            <?php
            // عنوان المجموعة
            if (!empty($group['label'])): ?>
                <?php if ($group_key !== array_key_first($menu)): ?>
                    <hr class="ts-nav-divider">
                <?php endif; ?>
                <p class="ts-nav-group-label"><?= htmlspecialchars($group['label'], ENT_QUOTES, 'UTF-8') ?></p>
            <?php endif; ?>

            <?php foreach ($group['items'] as $item): ?>
                <a href="<?= htmlspecialchars($item['url'], ENT_QUOTES, 'UTF-8') ?>"
                   class="<?= $nav_link($item['page']) ?>"
                   <?= ($current_page === $item['page']) ? 'aria-current="page"' : '' ?>>
                    <i class="bi <?= htmlspecialchars($item['icon'], ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></i>
                    <span><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?></span>
                </a>
            <?php endforeach; ?>

        <?php endforeach; ?>
    </nav>

    <!-- ——— Footer الـ Sidebar — تسجيل الخروج ——— -->
    <div class="ts-sidebar-footer">
        <a href="<?= htmlspecialchars($base . 'auth/logout.php', ENT_QUOTES, 'UTF-8') ?>"
           onclick="return confirm('<?= ($lang_code === 'ar') ? 'هل تريد تسجيل الخروج؟' : 'Are you sure you want to logout?' ?>')">
            <i class="bi bi-box-arrow-right" aria-hidden="true"></i>
            <span><?= $lang['logout'] ?></span>
        </a>
    </div>

</aside>

<!-- ============================================================
     بداية منطقة المحتوى الرئيسي
     ============================================================ -->
<main class="ts-main-content" id="mainContent" role="main">

    <!-- Flash Message — إن وجدت -->
    <?php if (!empty($_flash_message)): ?>
        <?php
        $flash_type = match($_flash_message['type']) {
            'success' => 'success',
            'error'   => 'danger',
            'warning' => 'warning',
            default   => 'info',
        };
        ?>
        <div class="alert alert-<?= $flash_type ?> alert-dismissible fade show mb-3" role="alert">
            <i class="bi bi-<?= match($flash_type) {
                'success' => 'check-circle',
                'danger'  => 'x-circle',
                'warning' => 'exclamation-triangle',
                default   => 'info-circle'
            } ?> me-2" aria-hidden="true"></i>
            <?= htmlspecialchars($_flash_message['message'], ENT_QUOTES, 'UTF-8') ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="<?= $lang['close'] ?>"></button>
        </div>
    <?php endif; ?>

<!-- ============================================================
     JavaScript — تفاعل الـ Sidebar
     ============================================================ -->
<script>
(function () {
    'use strict';

    const sidebar  = document.getElementById('mainSidebar');
    const overlay  = document.getElementById('sidebarOverlay');
    const toggleBtn = document.getElementById('sidebarToggleBtn');
    const isRTL    = document.documentElement.dir === 'rtl';

    if (!sidebar || !overlay || !toggleBtn) return;

    /** فتح الـ sidebar */
    function openSidebar() {
        sidebar.classList.add('show');
        overlay.classList.add('show');
        toggleBtn.setAttribute('aria-expanded', 'true');
        toggleBtn.innerHTML = '<i class="bi bi-x-lg"></i>';
        document.body.style.overflow = 'hidden';
    }

    /** إغلاق الـ sidebar */
    function closeSidebar() {
        sidebar.classList.remove('show');
        overlay.classList.remove('show');
        toggleBtn.setAttribute('aria-expanded', 'false');
        toggleBtn.innerHTML = '<i class="bi bi-list"></i>';
        document.body.style.overflow = '';
    }

    /** تبديل الحالة */
    function toggleSidebar() {
        sidebar.classList.contains('show') ? closeSidebar() : openSidebar();
    }

    // أحداث
    toggleBtn.addEventListener('click', toggleSidebar);
    overlay.addEventListener('click', closeSidebar);

    // إغلاق بـ Escape
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && sidebar.classList.contains('show')) {
            closeSidebar();
        }
    });

    // إغلاق تلقائي عند تغيير حجم الشاشة لـ desktop
    window.addEventListener('resize', function () {
        if (window.innerWidth > 768) {
            sidebar.classList.remove('show');
            overlay.classList.remove('show');
            document.body.style.overflow = '';
            toggleBtn.setAttribute('aria-expanded', 'false');
            toggleBtn.innerHTML = '<i class="bi bi-list"></i>';
        }
    });

    // إضافة حركة Swipe للموبايل
    let touchStartX = 0;
    let touchStartY = 0;

    document.addEventListener('touchstart', function (e) {
        touchStartX = e.changedTouches[0].screenX;
        touchStartY = e.changedTouches[0].screenY;
    }, { passive: true });

    document.addEventListener('touchend', function (e) {
        const dx = e.changedTouches[0].screenX - touchStartX;
        const dy = e.changedTouches[0].screenY - touchStartY;

        // تجاهل السحب الرأسي
        if (Math.abs(dy) > Math.abs(dx)) return;
        if (Math.abs(dx) < 50) return; // حد أدنى للسحب

        if (isRTL) {
            // RTL: سحب لليسار يفتح، سحب لليمين يغلق
            if (dx < 0 && !sidebar.classList.contains('show') && touchStartX > window.innerWidth - 30) openSidebar();
            if (dx > 0 &&  sidebar.classList.contains('show')) closeSidebar();
        } else {
            // LTR: سحب لليمين يفتح، سحب لليسار يغلق
            if (dx > 0 && !sidebar.classList.contains('show') && touchStartX < 30) openSidebar();
            if (dx < 0 &&  sidebar.classList.contains('show')) closeSidebar();
        }
    }, { passive: true });

})();
</script>