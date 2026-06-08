<?php
// ============================================================
// المسار: index.php
// الوظيفة: نقطة الدخول الرئيسية — توجيه حسب الدور
// ============================================================
session_start();

if (empty($_SESSION['user_id'])) {
    header('Location: auth/login.php');
    exit;
}

$role = $_SESSION['user_role'] ?? '';
$redirect = match($role) {
    'super_admin'  => 'superadmin/dashboard.php',
    'branch_admin' => 'admin/dashboard.php',
    'cashier'      => 'cashier/dashboard.php',
    default        => 'auth/login.php',
};

header("Location: {$redirect}");
exit;
