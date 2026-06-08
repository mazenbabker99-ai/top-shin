<?php
// ============================================================
// المسار: superadmin/dashboard.php
// الوظيفة: لوحة تحكم Super Admin
// الصلاحية: super_admin فقط
// ============================================================

declare(strict_types=1);

require_once __DIR__ . '/../includes/layout/header.php';
require_role(['super_admin']);

$pdo = db();

// ================================================================
// إحصائيات سريعة
// ================================================================

// عدد الفروع
$branches_count = $pdo->query("SELECT COUNT(*) FROM branches WHERE status = 'active'")->fetchColumn();

// عدد المستخدمين
$users_count = $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'active'")->fetchColumn();

// عدد المنتجات
$products_count = $pdo->query("SELECT COUNT(*) FROM products WHERE status = 'active'")->fetchColumn();

// عدد الفواتير اليوم
$today_sales = $pdo->query("SELECT COUNT(*) FROM invoices WHERE DATE(created_at) = CURDATE()")->fetchColumn();

// إجمالي مبيعات اليوم
$today_revenue = $pdo->query("SELECT COALESCE(SUM(total), 0) FROM invoices WHERE DATE(created_at) = CURDATE()")->fetchColumn();

// إجمالي مبيعات الشهر
$month_revenue = $pdo->query("SELECT COALESCE(SUM(total), 0) FROM invoices WHERE YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE())")->fetchColumn();

// التنبيهات (منتجات ناقصة)
$low_stock_alerts = $pdo->query("
    SELECT COUNT(*) 
    FROM inventory i
    JOIN products p ON p.id = i.product_id
    WHERE p.status = 'active' AND i.quantity <= i.min_quantity
")->fetchColumn();

// طلبات التحويل المعلقة
$pending_transfers = $pdo->query("SELECT COUNT(*) FROM stock_transfers WHERE status = 'pending'")->fetchColumn();

?>

<?php include __DIR__ . '/../includes/layout/sidebar.php'; ?>

<div class="main-content">

    <?php show_flash(); ?>

    <!-- رأس الصفحة -->
    <div class="page-header d-flex align-items-center justify-content-between mb-4">
        <div>
            <h4 class="page-title mb-0">
                <i class="bi bi-speedometer2 me-2" style="color:var(--ts-gold);"></i>
                <?= $is_rtl ? 'لوحة التحكم' : 'Dashboard' ?>
            </h4>
            <small style="color:var(--ts-text-muted);">
                <?= $is_rtl ? 'مرحباً، ' . htmlspecialchars($_SESSION['user_name'] ?? 'Admin', ENT_QUOTES, 'UTF-8') : 'Welcome, ' . htmlspecialchars($_SESSION['user_name'] ?? 'Admin', ENT_QUOTES, 'UTF-8') ?>
            </small>
        </div>
        <div>
            <span class="badge bg-success">
                <i class="bi bi-shield-check me-1"></i>
                <?= $is_rtl ? 'مسؤول النظام' : 'Super Admin' ?>
            </span>
        </div>
    </div>

    <!-- ============================================================
         KPI Cards
         ============================================================ -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card ts-card text-center py-3">
                <div style="font-size:2rem; font-weight:700; color:var(--ts-gold);">
                    <?= $branches_count ?>
                </div>
                <div style="font-size:.8rem; color:var(--ts-text-muted);">
                    <?= $is_rtl ? 'فرع نشط' : 'Active Branches' ?>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card ts-card text-center py-3">
                <div style="font-size:2rem; font-weight:700; color:var(--ts-silver);">
                    <?= $users_count ?>
                </div>
                <div style="font-size:.8rem; color:var(--ts-text-muted);">
                    <?= $is_rtl ? 'مستخدم نشط' : 'Active Users' ?>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card ts-card text-center py-3">
                <div style="font-size:2rem; font-weight:700; color:var(--ts-success);">
                    <?= $products_count ?>
                </div>
                <div style="font-size:.8rem; color:var(--ts-text-muted);">
                    <?= $is_rtl ? 'منتج' : 'Products' ?>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card ts-card text-center py-3">
                <div style="font-size:2rem; font-weight:700; color:#ffc107;">
                    <?= $today_sales ?>
                </div>
                <div style="font-size:.8rem; color:var(--ts-text-muted);">
                    <?= $is_rtl ? 'فاتورة اليوم' : 'Today Sales' ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ============================================================
         Revenue Cards
         ============================================================ -->
    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <div class="card ts-card">
                <div class="card-body py-3">
                    <div style="color:var(--ts-text-muted); font-size:.85rem;">
                        <?= $is_rtl ? 'إيرادات اليوم' : 'Today Revenue' ?>
                    </div>
                    <div style="font-size:1.8rem; font-weight:700; color:var(--ts-success);">
                        <?= format_money((float)$today_revenue) ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card ts-card">
                <div class="card-body py-3">
                    <div style="color:var(--ts-text-muted); font-size:.85rem;">
                        <?= $is_rtl ? 'إيرادات الشهر' : 'Month Revenue' ?>
                    </div>
                    <div style="font-size:1.8rem; font-weight:700; color:var(--ts-gold);">
                        <?= format_money((float)$month_revenue) ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ============================================================
         Alerts Section
         ============================================================ -->
    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <div class="card ts-card">
                <div class="card-body py-3 d-flex align-items-center justify-content-between">
                    <div>
                        <div style="color:var(--ts-text-muted); font-size:.85rem;">
                            <?= $is_rtl ? 'تنبيهات المخزون' : 'Stock Alerts' ?>
                        </div>
                        <div style="font-size:1.5rem; font-weight:700; color:var(--ts-danger);">
                            <?= $low_stock_alerts ?>
                        </div>
                    </div>
                    <a href="../admin/inventory_alerts.php" class="btn btn-outline-warning btn-sm">
                        <i class="bi bi-eye me-1"></i>
                        <?= $lang['view'] ?>
                    </a>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card ts-card">
                <div class="card-body py-3 d-flex align-items-center justify-content-between">
                    <div>
                        <div style="color:var(--ts-text-muted); font-size:.85rem;">
                            <?= $is_rtl ? 'طلبات تحويل معلقة' : 'Pending Transfers' ?>
                        </div>
                        <div style="font-size:1.5rem; font-weight:700; color:var(--ts-warning);">
                            <?= $pending_transfers ?>
                        </div>
                    </div>
                    <a href="../admin/stock_transfers.php" class="btn btn-outline-warning btn-sm">
                        <i class="bi bi-eye me-1"></i>
                        <?= $lang['view'] ?>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- ============================================================
         Quick Actions
         ============================================================ -->
    <div class="card ts-card mb-4">
        <div class="card-header">
            <span style="color:var(--ts-gold); font-weight:700;">
                <i class="bi bi-lightning me-1"></i>
                <?= $is_rtl ? 'إجراءات سريعة' : 'Quick Actions' ?>
            </span>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-6 col-md-3">
                    <a href="../admin/products.php" class="btn btn-outline-secondary w-100">
                        <i class="bi bi-box-seam me-1"></i>
                        <?= $lang['products'] ?>
                    </a>
                </div>
                <div class="col-6 col-md-3">
                    <a href="../admin/inventory.php" class="btn btn-outline-secondary w-100">
                        <i class="bi bi-boxes me-1"></i>
                        <?= $lang['inventory'] ?>
                    </a>
                </div>
                <div class="col-6 col-md-3">
                    <a href="../admin/branches.php" class="btn btn-outline-secondary w-100">
                        <i class="bi bi-building me-1"></i>
                        <?= $is_rtl ? 'الفروع' : 'Branches' ?>
                    </a>
                </div>
                <div class="col-6 col-md-3">
                    <a href="../admin/users.php" class="btn btn-outline-secondary w-100">
                        <i class="bi bi-people me-1"></i>
                        <?= $is_rtl ? 'المستخدمين' : 'Users' ?>
                    </a>
                </div>
            </div>
        </div>
    </div>

</div><!-- /.main-content -->

<?php include __DIR__ . '/../includes/layout/footer.php'; ?>
