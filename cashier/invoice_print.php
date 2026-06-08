<?php
// ============================================================
// المسار: cashier/invoice_print.php
// الوظيفة: صفحة اختيار نوع الطباعة — A4 أو Thermal 80mm
// الاعتمادات: config/database.php | includes/auth.php | includes/functions.php
// ============================================================

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/middleware.php';

require_role(['cashier', 'branch_admin', 'super_admin']);

$invoice_id = (int)($_GET['id'] ?? 0);
if ($invoice_id <= 0) {
    http_response_code(400);
    die('<p style="font-family:sans-serif;color:red;">معرف الفاتورة غير صالح.</p>');
}

$pdo = db();

// ——— جلب بيانات الفاتورة المختصرة ———
$stmt = $pdo->prepare("
    SELECT i.id, i.invoice_number, i.total, i.created_at,
           i.status, i.branch_id,
           b.name AS branch_name
    FROM invoices i
    LEFT JOIN branches b ON b.id = i.branch_id
    WHERE i.id = ?
    LIMIT 1
");
$stmt->execute([$invoice_id]);
$invoice = $stmt->fetch();

if (!$invoice) {
    http_response_code(404);
    die('<p style="font-family:sans-serif;color:red;">الفاتورة غير موجودة.</p>');
}

// ——— تحقق من الصلاحية ———
if (Auth::getRole() !== 'super_admin') {
    if ((int)$invoice['branch_id'] !== Auth::getBranchId()) {
        http_response_code(403);
        die('<p style="font-family:sans-serif;color:red;">غير مصرح.</p>');
    }
}

$lang_code = Auth::getLang();
$lang      = load_lang();
$is_rtl    = ($lang_code === 'ar');
$dir       = $is_rtl ? 'rtl' : 'ltr';

$pdo       = db();
$store_name = get_setting('store_name_' . $lang_code, $pdo) ?: 'Top Shine';

// روابط القوالب
$base      = '../';
$url_a4    = $base . 'templates/invoice_a4.php?id='      . $invoice_id;
$url_therm = $base . 'templates/invoice_thermal.php?id=' . $invoice_id;

$inv_date  = date('Y-m-d H:i', strtotime($invoice['created_at']));

$bs_css = $is_rtl
    ? 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css'
    : 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css';
?>
<!DOCTYPE html>
<html lang="<?= $lang_code ?>" dir="<?= $dir ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $lang['print'] ?> — <?= htmlspecialchars($invoice['invoice_number'], ENT_QUOTES, 'UTF-8') ?></title>

    <link rel="stylesheet" href="<?= $bs_css ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700&display=swap" rel="stylesheet">

    <style>
        :root {
            --ts-gold:        #C9A84C;
            --ts-gold-light:  #E2C97E;
            --ts-gold-dark:   #A0812A;
            --ts-silver:      #B0B3B8;
            --ts-bg-dark:     #0D0D0D;
            --ts-bg-mid:      #1A1A1A;
            --ts-bg-card:     #242424;
            --ts-bg-input:    #2E2E2E;
            --ts-text-primary:#F5F5F5;
            --ts-text-muted:  #6C757D;
            --ts-border:      #3A3A3A;
            --ts-success:     #28A745;
        }

        * { font-family: 'Tajawal', sans-serif; box-sizing: border-box; }

        body {
            background: var(--ts-bg-mid);
            color: var(--ts-text-primary);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
        }

        /* ——— بطاقة الاختيار ——— */
        .print-card {
            background: var(--ts-bg-card);
            border: 1px solid var(--ts-border);
            border-radius: 16px;
            padding: 2rem 2.2rem;
            max-width: 480px;
            width: 100%;
            box-shadow: 0 8px 40px rgba(0,0,0,.4);
        }

        /* ——— رأس البطاقة ——— */
        .print-card-header {
            text-align: center;
            margin-bottom: 1.6rem;
            padding-bottom: 1.2rem;
            border-bottom: 1px solid var(--ts-border);
        }
        .print-card-header .icon-wrap {
            width: 56px;
            height: 56px;
            background: linear-gradient(135deg, var(--ts-gold-dark), var(--ts-gold));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto .8rem;
            font-size: 1.5rem;
            color: #0D0D0D;
        }
        .print-card-header h5 {
            font-size: 1.15rem;
            font-weight: 700;
            color: var(--ts-text-primary);
            margin-bottom: .3rem;
        }
        .print-card-header .inv-badge {
            display: inline-block;
            background: var(--ts-bg-dark);
            border: 1px solid var(--ts-border);
            border-radius: 20px;
            padding: .2rem .9rem;
            font-size: .8rem;
            color: var(--ts-gold);
            font-weight: 600;
            letter-spacing: .3px;
        }

        /* ——— معلومات الفاتورة ——— */
        .inv-info-strip {
            background: var(--ts-bg-dark);
            border: 1px solid var(--ts-border);
            border-radius: 8px;
            padding: .75rem 1rem;
            margin-bottom: 1.5rem;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: .4rem .8rem;
            font-size: .82rem;
        }
        .inv-info-item {
            display: flex;
            flex-direction: column;
            gap: .15rem;
        }
        .inv-info-label { color: var(--ts-text-muted); font-size: .72rem; }
        .inv-info-value { color: var(--ts-text-primary); font-weight: 600; }
        .inv-info-value.gold { color: var(--ts-gold); }

        /* ——— عنوان القسم ——— */
        .section-label {
            font-size: .78rem;
            color: var(--ts-text-muted);
            text-transform: uppercase;
            letter-spacing: .5px;
            margin-bottom: .7rem;
            font-weight: 600;
        }

        /* ——— أزرار الطباعة ——— */
        .print-options {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: .8rem;
            margin-bottom: 1.2rem;
        }

        .print-opt-btn {
            background: var(--ts-bg-dark);
            border: 1.5px solid var(--ts-border);
            border-radius: 12px;
            padding: 1.2rem .8rem;
            cursor: pointer;
            text-align: center;
            transition: border-color .18s, background .18s, transform .12s;
            color: var(--ts-text-primary);
            text-decoration: none;
            display: block;
            user-select: none;
        }
        .print-opt-btn:hover {
            border-color: var(--ts-gold);
            background: #1e1a0a;
            color: var(--ts-text-primary);
            transform: translateY(-2px);
            text-decoration: none;
        }
        .print-opt-btn:active { transform: translateY(0); }

        .print-opt-btn .opt-icon {
            font-size: 2rem;
            color: var(--ts-gold);
            display: block;
            margin-bottom: .5rem;
            line-height: 1;
        }
        .print-opt-btn .opt-title {
            font-size: .9rem;
            font-weight: 700;
            display: block;
            margin-bottom: .25rem;
        }
        .print-opt-btn .opt-desc {
            font-size: .72rem;
            color: var(--ts-text-muted);
            line-height: 1.4;
        }

        .print-opt-btn.thermal-btn:hover { border-color: var(--ts-silver); background: #1a1a1e; }
        .print-opt-btn.thermal-btn .opt-icon { color: var(--ts-silver); }

        /* ——— أزرار الإجراءات الثانوية ——— */
        .secondary-actions {
            display: flex;
            gap: .6rem;
        }
        .btn-secondary-action {
            flex: 1;
            background: transparent;
            border: 1px solid var(--ts-border);
            border-radius: 8px;
            color: var(--ts-text-muted);
            font-size: .82rem;
            padding: .5rem .6rem;
            cursor: pointer;
            text-align: center;
            transition: all .15s;
            font-family: 'Tajawal', sans-serif;
            text-decoration: none;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: .3rem;
        }
        .btn-secondary-action:hover {
            border-color: var(--ts-silver);
            color: var(--ts-text-primary);
            text-decoration: none;
        }
        .btn-secondary-action.danger:hover {
            border-color: #dc354566;
            color: #dc3545;
        }

        @media print { body { display: none !important; } }
        @media (max-width: 400px) {
            .print-options { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<div class="print-card">

    <!-- رأس البطاقة -->
    <div class="print-card-header">
        <div class="icon-wrap"><i class="bi bi-printer-fill"></i></div>
        <h5><?= $is_rtl ? 'اختر طريقة الطباعة' : 'Choose Print Type' ?></h5>
        <span class="inv-badge">
            <i class="bi bi-receipt" style="margin-inline-end:.3rem;"></i>
            <?= htmlspecialchars($invoice['invoice_number'], ENT_QUOTES, 'UTF-8') ?>
        </span>
    </div>

    <!-- معلومات الفاتورة -->
    <div class="inv-info-strip">
        <div class="inv-info-item">
            <span class="inv-info-label"><?= $lang['branch'] ?></span>
            <span class="inv-info-value"><?= htmlspecialchars($invoice['branch_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <div class="inv-info-item">
            <span class="inv-info-label"><?= $lang['total'] ?></span>
            <span class="inv-info-value gold"><?= number_format((float)$invoice['total'], 2) ?> SDG</span>
        </div>
        <div class="inv-info-item">
            <span class="inv-info-label"><?= $lang['date'] ?></span>
            <span class="inv-info-value"><?= htmlspecialchars($inv_date, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <div class="inv-info-item">
            <span class="inv-info-label"><?= $lang['status'] ?></span>
            <span class="inv-info-value"><?= $lang[$invoice['status']] ?? htmlspecialchars($invoice['status'], ENT_QUOTES, 'UTF-8') ?></span>
        </div>
    </div>

    <!-- عنوان القسم -->
    <div class="section-label">
        <i class="bi bi-printer" style="margin-inline-end:.3rem;"></i>
        <?= $is_rtl ? 'خيارات الطباعة' : 'Print Options' ?>
    </div>

    <!-- أزرار الطباعة -->
    <div class="print-options">

        <!-- A4 -->
        <a href="<?= htmlspecialchars($url_a4, ENT_QUOTES, 'UTF-8') ?>"
           target="_blank"
           class="print-opt-btn"
           onclick="trackPrint('a4')">
            <i class="bi bi-file-earmark-text opt-icon"></i>
            <span class="opt-title"><?= $lang['print_a4'] ?></span>
            <span class="opt-desc">
                <?= $is_rtl
                    ? 'فاتورة رسمية كاملة — مناسبة للأرشيف والعملاء'
                    : 'Full official invoice — suitable for archiving' ?>
            </span>
        </a>

        <!-- Thermal -->
        <a href="<?= htmlspecialchars($url_therm, ENT_QUOTES, 'UTF-8') ?>"
           target="_blank"
           class="print-opt-btn thermal-btn"
           onclick="trackPrint('thermal')">
            <i class="bi bi-receipt opt-icon" style="color:var(--ts-silver);"></i>
            <span class="opt-title"><?= $lang['print_thermal'] ?></span>
            <span class="opt-desc">
                <?= $is_rtl
                    ? 'إيصال حراري 80mm — سريع للكاشير'
                    : '80mm thermal receipt — fast for cashier' ?>
            </span>
        </a>

    </div>

    <!-- إجراءات ثانوية -->
    <div class="secondary-actions">
        <a href="pos.php" class="btn-secondary-action">
            <i class="bi bi-plus-circle"></i>
            <?= $is_rtl ? 'فاتورة جديدة' : 'New Invoice' ?>
        </a>
        <a href="dashboard.php" class="btn-secondary-action">
            <i class="bi bi-speedometer2"></i>
            <?= $lang['dashboard'] ?>
        </a>
        <button class="btn-secondary-action danger" onclick="window.close()">
            <i class="bi bi-x-lg"></i>
            <?= $is_rtl ? 'إغلاق' : 'Close' ?>
        </button>
    </div>

</div><!-- /.print-card -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // تتبع مبسط لاختيار الطباعة (يُفيد في التصحيح)
    function trackPrint(type) {
        console.log('[TopShine] Print selected:', type, 'Invoice ID:', <?= $invoice_id ?>);
    }

    // إذا فُتحت هذه الصفحة من pos.php بعد الحفظ — أغلق تلقائياً بعد الطباعة
    // الكاشير يختار ثم تُغلق النافذة بعد 8 ثوانٍ من النقر
    document.querySelectorAll('.print-opt-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            setTimeout(function() {
                // لا تغلق تلقائياً — اترك القرار للمستخدم
            }, 8000);
        });
    });
</script>
</body>
</html>
