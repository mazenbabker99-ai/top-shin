<?php
// ============================================================
// المسار: auth/logout.php
// الوظيفة: تسجيل الخروج — يمسح الـ session ويُعيد التوجيه
// ============================================================

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

// تسجيل الخروج (يتضمن audit + session_destroy + redirect)
$auth = new Auth(db());
$auth->logout();
