<?php
// ============================================================
// المسار: auth/login.php
// الوظيفة: صفحة تسجيل الدخول — نظام توب شاين
// ============================================================

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

// ——— إعداد اللغة (قبل الدخول تكون ar افتراضياً) ———
$lang_code = $_SESSION['lang'] ?? 'ar';
$lang      = load_lang();
$is_rtl    = ($lang_code === 'ar');
$dir       = $is_rtl ? 'rtl' : 'ltr';

// ——— إذا المستخدم مسجل دخوله → حوّله مباشرة ———
if (Auth::check()) {
    redirect(Auth::get_redirect_url(Auth::getRole()));
}

// ——— التعامل مع تبديل اللغة ———
if (!empty($_GET['switch_lang'])) {
    $new_lang          = in_array($_GET['switch_lang'], ['ar', 'en']) ? $_GET['switch_lang'] : 'ar';
    $_SESSION['lang']  = $new_lang;
    redirect('login.php');
}

$pdo = db();
$auth = new Auth($pdo);

$error_msg  = '';
$block_msg  = '';
$csrf_token = generate_csrf_token();

// ============================================================
// معالجة الـ POST
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // تحقق من CSRF
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error_msg = $lang['csrf_invalid'];
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($username) || empty($password)) {
            $error_msg = $lang['required_field'];
        } else {
            $result = $auth->login($username, $password);

            if ($result['success']) {
                // حوّله حسب الدور
                redirect(Auth::get_redirect_url(Auth::getRole()));

            } elseif ($result['message'] === 'blocked') {
                $block_msg = sprintf($lang['login_blocked'], $result['minutes_left'] ?? self::BLOCK_MINUTES);

            } elseif ($result['message'] === 'inactive') {
                $error_msg = $lang['login_inactive'];

            } else {
                $error_msg = $lang['login_failed'];
            }
        }
    }

    // جدّد CSRF بعد كل POST
    $csrf_token = regenerate_csrf_token();
}

// ======================================================
// HTML — صفحة تسجيل الدخول
// ======================================================
?>
<!DOCTYPE html>
<html lang="<?= $lang_code ?>" dir="<?= $dir ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $lang['login'] ?> — Top Shine</title>

    <!-- Google Fonts: Tajawal -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700&display=swap" rel="stylesheet">

    <!-- Bootstrap 5 RTL / LTR -->
    <?php if ($is_rtl): ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css">
    <?php else: ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <?php endif; ?>

    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <style>
        /* ——— CSS Variables ——— */
        :root {
            --ts-gold:          #C9A84C;
            --ts-gold-light:    #E2C97E;
            --ts-gold-dark:     #A0812A;
            --ts-silver:        #B0B3B8;
            --ts-silver-light:  #D8D9DC;
            --ts-bg-dark:       #0D0D0D;
            --ts-bg-mid:        #1A1A1A;
            --ts-bg-card:       #242424;
            --ts-bg-input:      #2E2E2E;
            --ts-text-primary:  #F5F5F5;
            --ts-text-secondary:#B0B3B8;
            --ts-text-muted:    #6C757D;
            --ts-border:        #3A3A3A;
            --ts-border-gold:   #C9A84C;
            --ts-danger:        #DC3545;
            --ts-success:       #28A745;
        }

        * { box-sizing: border-box; }

        body {
            font-family: 'Tajawal', sans-serif;
            background: var(--ts-bg-mid);
            color: var(--ts-text-primary);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
            padding: 20px;
            background-image:
                radial-gradient(ellipse at 20% 50%, rgba(201,168,76,0.05) 0%, transparent 50%),
                radial-gradient(ellipse at 80% 20%, rgba(201,168,76,0.03) 0%, transparent 40%);
        }

        /* ——— Login Card ——— */
        .login-wrapper {
            width: 100%;
            max-width: 440px;
        }

        .login-card {
            background: var(--ts-bg-card);
            border: 1px solid var(--ts-border);
            border-top: 3px solid var(--ts-gold);
            border-radius: 16px;
            padding: 40px 36px 36px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.5);
        }

        /* ——— Logo & Branding ——— */
        .brand-section {
            text-align: center;
            margin-bottom: 32px;
        }

        .brand-logo {
            width: 100px;
            height: 100px;
            object-fit: contain;
            margin-bottom: 12px;
        }

        .brand-logo-placeholder {
            width: 90px;
            height: 90px;
            background: var(--ts-bg-dark);
            border: 2px solid var(--ts-gold-dark);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 12px;
            font-size: 36px;
            color: var(--ts-gold);
        }

        .brand-name {
            font-size: 28px;
            font-weight: 700;
            color: var(--ts-gold);
            letter-spacing: 1px;
            margin: 0;
        }

        .brand-tagline {
            font-size: 13px;
            color: var(--ts-text-muted);
            margin: 4px 0 0;
        }

        /* ——— Form Elements ——— */
        .form-label {
            color: var(--ts-text-secondary);
            font-size: 14px;
            font-weight: 500;
            margin-bottom: 6px;
        }

        .form-control {
            background: var(--ts-bg-input);
            border: 1px solid var(--ts-border);
            color: var(--ts-text-primary);
            border-radius: 8px;
            padding: 11px 14px;
            font-family: 'Tajawal', sans-serif;
            font-size: 15px;
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        .form-control:focus {
            background: var(--ts-bg-input);
            border-color: var(--ts-gold);
            box-shadow: 0 0 0 3px rgba(201,168,76,0.18);
            color: var(--ts-text-primary);
            outline: none;
        }

        .form-control::placeholder {
            color: var(--ts-text-muted);
        }

        /* ——— Password toggle ——— */
        .input-group .form-control {
            border-radius: <?= $is_rtl ? '8px 0 0 8px' : '0 8px 8px 0' ?>;
        }
        .input-group .btn-outline-secondary {
            border-color: var(--ts-border);
            color: var(--ts-text-muted);
            background: var(--ts-bg-input);
            border-radius: <?= $is_rtl ? '0 8px 8px 0' : '8px 0 0 8px' ?>;
        }
        .input-group .btn-outline-secondary:hover {
            background: var(--ts-border);
            color: var(--ts-text-primary);
        }

        /* ——— Submit Button ——— */
        .btn-login {
            background: var(--ts-gold);
            border: none;
            color: #0D0D0D;
            font-family: 'Tajawal', sans-serif;
            font-weight: 700;
            font-size: 16px;
            padding: 12px;
            border-radius: 8px;
            width: 100%;
            cursor: pointer;
            transition: background 0.2s, transform 0.1s;
            margin-top: 8px;
        }

        .btn-login:hover {
            background: var(--ts-gold-light);
        }

        .btn-login:active {
            transform: scale(0.98);
        }

        /* ——— Alerts ——— */
        .alert-login {
            border-radius: 8px;
            font-size: 14px;
            padding: 12px 16px;
            border: none;
        }

        .alert-danger-custom {
            background: rgba(220, 53, 69, 0.12);
            color: #f5a0a8;
            border-left: 3px solid var(--ts-danger);
        }

        .alert-warning-custom {
            background: rgba(201, 168, 76, 0.12);
            color: var(--ts-gold-light);
            border-left: 3px solid var(--ts-gold);
        }

        /* ——— Language Toggle ——— */
        .lang-toggle {
            text-align: center;
            margin-top: 24px;
        }

        .lang-toggle a {
            color: var(--ts-text-muted);
            text-decoration: none;
            font-size: 13px;
            transition: color 0.2s;
        }

        .lang-toggle a:hover {
            color: var(--ts-gold);
        }

        /* ——— Footer ——— */
        .login-footer {
            text-align: center;
            margin-top: 20px;
            color: var(--ts-text-muted);
            font-size: 12px;
        }

        /* ——— Field Icon ——— */
        .field-icon {
            color: var(--ts-gold);
            margin-<?= $is_rtl ? 'left' : 'right' ?>: 6px;
        }

        /* ——— Loading spinner ——— */
        .btn-login .spinner-border {
            width: 1rem;
            height: 1rem;
            border-width: 2px;
        }
    </style>
</head>
<body>

<div class="login-wrapper">
    <div class="login-card">

        <!-- ——— الشعار ——— -->
        <div class="brand-section">
            <?php
            $logo_path = __DIR__ . '/../assets/images/logo.png';
            if (file_exists($logo_path)): ?>
                <img src="../assets/images/logo.png" alt="Top Shine" class="brand-logo">
            <?php else: ?>
                <div class="brand-logo-placeholder">
                    <i class="bi bi-stars"></i>
                </div>
            <?php endif; ?>
            <h1 class="brand-name">Top Shine</h1>
            <p class="brand-tagline">
                <?= $is_rtl ? 'نظام إدارة المبيعات' : 'Sales Management System' ?>
            </p>
        </div>

        <!-- ——— رسائل الخطأ / التحذير ——— -->
        <?php if (!empty($error_msg)): ?>
        <div class="alert-login alert-danger-custom mb-3" role="alert">
            <i class="bi bi-exclamation-circle me-1"></i>
            <?= sanitize($error_msg) ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($block_msg)): ?>
        <div class="alert-login alert-warning-custom mb-3" role="alert">
            <i class="bi bi-shield-lock me-1"></i>
            <?= sanitize($block_msg) ?>
        </div>
        <?php endif; ?>

        <!-- ——— رسالة انتهاء الجلسة ——— -->
        <?php if (!empty($_GET['expired'])): ?>
        <div class="alert-login alert-warning-custom mb-3" role="alert">
            <i class="bi bi-clock me-1"></i>
            <?= $lang['session_expired'] ?>
        </div>
        <?php endif; ?>

        <!-- ——— Form ——— -->
        <form method="POST" action="login.php" id="loginForm" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= sanitize($csrf_token) ?>">

            <!-- اسم المستخدم -->
            <div class="mb-3">
                <label for="username" class="form-label">
                    <i class="bi bi-person field-icon"></i>
                    <?= $lang['username'] ?>
                </label>
                <input
                    type="text"
                    class="form-control"
                    id="username"
                    name="username"
                    placeholder="<?= $is_rtl ? 'أدخل اسم المستخدم' : 'Enter username' ?>"
                    value="<?= sanitize($_POST['username'] ?? '') ?>"
                    autocomplete="username"
                    required
                    autofocus
                >
            </div>

            <!-- كلمة المرور -->
            <div class="mb-3">
                <label for="password" class="form-label">
                    <i class="bi bi-lock field-icon"></i>
                    <?= $lang['password'] ?>
                </label>
                <div class="input-group">
                    <?php if (!$is_rtl): ?>
                    <button type="button" class="btn btn-outline-secondary" id="togglePass" tabindex="-1">
                        <i class="bi bi-eye" id="togglePassIcon"></i>
                    </button>
                    <?php endif; ?>
                    <input
                        type="password"
                        class="form-control"
                        id="password"
                        name="password"
                        placeholder="<?= $is_rtl ? 'أدخل كلمة المرور' : 'Enter password' ?>"
                        autocomplete="current-password"
                        required
                    >
                    <?php if ($is_rtl): ?>
                    <button type="button" class="btn btn-outline-secondary" id="togglePass" tabindex="-1">
                        <i class="bi bi-eye" id="togglePassIcon"></i>
                    </button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- زر الدخول -->
            <button type="submit" class="btn-login" id="loginBtn">
                <span id="btnText">
                    <i class="bi bi-box-arrow-in-right me-1"></i>
                    <?= $lang['login_btn'] ?>
                </span>
                <span id="btnLoading" class="d-none">
                    <span class="spinner-border" role="status"></span>
                    <?= $is_rtl ? 'جارٍ الدخول...' : 'Signing in...' ?>
                </span>
            </button>
        </form>

        <!-- ——— تبديل اللغة ——— -->
        <div class="lang-toggle">
            <?php if ($lang_code === 'ar'): ?>
                <a href="login.php?switch_lang=en">
                    <i class="bi bi-translate me-1"></i>Switch to English
                </a>
            <?php else: ?>
                <a href="login.php?switch_lang=ar">
                    <i class="bi bi-translate me-1"></i>التبديل للعربية
                </a>
            <?php endif; ?>
        </div>

    </div><!-- /.login-card -->

    <div class="login-footer">
        Top Shine POS &copy; <?= date('Y') ?>
        &nbsp;|&nbsp;
        <?= $is_rtl ? 'الإصدار 1.0' : 'Version 1.0' ?>
    </div>
</div>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
// ——— إظهار / إخفاء كلمة المرور ———
const toggleBtn  = document.getElementById('togglePass');
const passInput  = document.getElementById('password');
const toggleIcon = document.getElementById('togglePassIcon');

if (toggleBtn) {
    toggleBtn.addEventListener('click', () => {
        const isHidden = passInput.type === 'password';
        passInput.type = isHidden ? 'text' : 'password';
        toggleIcon.className = isHidden ? 'bi bi-eye-slash' : 'bi bi-eye';
    });
}

// ——— Loading state عند الإرسال ———
document.getElementById('loginForm').addEventListener('submit', function () {
    const btn     = document.getElementById('loginBtn');
    const btnText = document.getElementById('btnText');
    const btnLoad = document.getElementById('btnLoading');
    btn.disabled  = true;
    btnText.classList.add('d-none');
    btnLoad.classList.remove('d-none');
});
</script>

</body>
</html>
