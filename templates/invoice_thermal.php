<?php
// ============================================================
// المسار: templates/invoice_thermal.php
// الوظيفة: قالب طباعة فاتورة Thermal 80mm — يُطبع تلقائياً
// الاعتمادات: config/database.php | includes/auth.php | includes/functions.php
// ============================================================

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/middleware.php';

require_login();

// ——— جلب معرف الفاتورة ———
$invoice_id = (int)($_GET['id'] ?? 0);
if ($invoice_id <= 0) {
    http_response_code(400);
    die('<p style="font-family:sans-serif;color:red;">معرف الفاتورة غير صالح.</p>');
}

$pdo = db();

// ============================================================
// جلب الفاتورة الرئيسية
// ============================================================
$stmt = $pdo->prepare("
    SELECT
        i.id, i.invoice_number, i.branch_id, i.cashier_id, i.customer_id,
        i.subtotal, i.discount_type, i.discount_value, i.discount_amount,
        i.total, i.payment_method, i.amount_paid, i.change_amount,
        i.notes, i.status, i.created_at,
        b.name    AS branch_name,
        b.phone   AS branch_phone,
        u.name    AS cashier_name,
        COALESCE(c.name, '') AS customer_name
    FROM invoices i
    LEFT JOIN branches  b ON b.id = i.branch_id
    LEFT JOIN users     u ON u.id = i.cashier_id
    LEFT JOIN customers c ON c.id = i.customer_id
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

// ============================================================
// جلب بنود الفاتورة
// ============================================================
$items_stmt = $pdo->prepare("
    SELECT
        ii.product_name,
        ii.unit_price,
        ii.quantity,
        ii.discount,
        ii.total
    FROM invoice_items ii
    WHERE ii.invoice_id = ?
    ORDER BY ii.id ASC
");
$items_stmt->execute([$invoice_id]);
$items = $items_stmt->fetchAll();

// ——— لغة الفاتورة = لغة المستخدم الحالي ———
$lang_code = Auth::getLang();
$lang      = load_lang();
$is_rtl    = ($lang_code === 'ar');
$dir       = $is_rtl ? 'rtl' : 'ltr';

// ——— إعدادات المتجر والفاتورة الحرارية ———
$store_name      = get_setting('store_name_'       . $lang_code, $pdo) ?: 'Top Shine';
$thermal_header  = get_setting('thermal_header_'   . $lang_code, $pdo);
$thermal_footer  = get_setting('thermal_footer_'   . $lang_code, $pdo);

// إذا لم توجد إعدادات فرعية، استخدم العامة
if (!$thermal_header) $thermal_header = $is_rtl
    ? 'توب شاين — جمالكِ يبدأ من هنا'
    : 'Top Shine — Your Beauty Starts Here';
if (!$thermal_footer) $thermal_footer = $lang['thank_you'];

// ——— تاريخ ووقت الفاتورة ———
$inv_date = date('Y-m-d', strtotime($invoice['created_at']));
$inv_time = date('H:i',   strtotime($invoice['created_at']));

// ——— خريطة طرق الدفع ———
$pm_labels = [
    'cash'   => $lang['cash'],
    'bankak' => $lang['bankak'],
    'ocash'  => $lang['ocash'],
    'card'   => $lang['card'],
    'other'  => $lang['other'],
];
$pm_label = $pm_labels[$invoice['payment_method']] ?? $invoice['payment_method'];

// ——— دالة مساعدة لتنسيق الكمية ———
function fmt_qty_t(float $q): string {
    return rtrim(rtrim(number_format($q, 3, '.', ''), '0'), '.');
}
?>
<!DOCTYPE html>
<html lang="<?= $lang_code ?>" dir="<?= $dir ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $lang['sales_invoice'] ?> — <?= htmlspecialchars($invoice['invoice_number'], ENT_QUOTES, 'UTF-8') ?></title>

    <!-- خط Cairo للطباعة الحرارية -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">

    <style>
        /* ——— إعدادات الطباعة الحرارية ——— */
        @page {
            size: 80mm auto;
            margin: 2mm;
        }

        @media print {
            nav, .no-print, button { display: none !important; }
            body { background: #fff !important; }
            .thermal-receipt { box-shadow: none !important; margin: 0 !important; }
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Cairo', 'Courier New', monospace;
            font-size: 12px;
            background: #e8e8e8;
            direction: <?= $dir ?>;
            color: #000;
            line-height: 1.5;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        /* ——— حاوية الإيصال ——— */
        .thermal-receipt {
            width: 76mm;
            margin: 10px auto;
            background: #fff;
            padding: 3mm;
            box-shadow: 0 0 10px rgba(0,0,0,.15);
        }

        /* ——— المحاذاة ——— */
        .tc  { text-align: center; }
        .tr  { text-align: <?= $is_rtl ? 'left' : 'right' ?>; }
        .tl  { text-align: <?= $is_rtl ? 'right' : 'left' ?>; }

        /* ——— فاصل ——— */
        .sep {
            border: none;
            border-top: 1px dashed #000;
            margin: 4px 0;
        }
        .sep-solid {
            border: none;
            border-top: 1px solid #000;
            margin: 4px 0;
        }

        /* ——— اسم المتجر ——— */
        .store-name {
            font-size: 17px;
            font-weight: 700;
            text-align: center;
            line-height: 1.2;
        }
        .store-header-msg {
            font-size: 11px;
            text-align: center;
            color: #333;
            margin-top: 2px;
        }

        /* ——— معلومات الفاتورة ——— */
        .info-row {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            font-size: 11.5px;
            padding: 1.5px 0;
        }
        .info-label { color: #444; font-size: 10.5px; flex-shrink: 0; }
        .info-value { font-weight: 600; font-size: 11.5px; }
        .inv-num-val {
            font-size: 11px;
            font-weight: 700;
            text-align: center;
            letter-spacing: .3px;
            color: #000;
            margin: 3px 0;
        }

        /* ——— جدول البنود (بدون حدود — نص فقط) ——— */
        .item-block {
            padding: 3px 0;
            border-bottom: 1px dashed #ccc;
        }
        .item-name {
            font-size: 12px;
            font-weight: 600;
            line-height: 1.3;
        }
        .item-calc {
            display: flex;
            justify-content: space-between;
            font-size: 11.5px;
            color: #222;
        }
        .item-calc .calc-math { color: #555; font-size: 11px; }
        .item-calc .calc-total { font-weight: 700; }
        .item-disc {
            font-size: 10.5px;
            color: #c0392b;
        }

        /* ——— ملخص المبالغ ——— */
        .summary-row {
            display: flex;
            justify-content: space-between;
            font-size: 12px;
            padding: 2px 0;
        }
        .summary-row .s-label { color: #444; }
        .summary-row .s-value { font-weight: 600; }
        .total-row {
            font-size: 15px;
            font-weight: 700;
            padding: 4px 0 2px;
            border-top: 1px solid #000;
            margin-top: 2px;
        }
        .payment-row {
            font-size: 12px;
            padding: 2px 0;
        }
        .change-row {
            font-size: 12.5px;
            font-weight: 700;
            padding: 2px 0;
        }

        /* ——— التذييل ——— */
        .footer-msg {
            font-size: 12px;
            font-weight: 700;
            text-align: center;
            margin: 4px 0 2px;
        }
        .footer-date {
            font-size: 10px;
            text-align: center;
            color: #666;
        }

        /* ——— زر الطباعة ——— */
        .print-btn-bar {
            text-align: center;
            margin: 12px 0;
        }
        .btn-print {
            background: #C9A84C;
            color: #0D0D0D;
            border: none;
            padding: 8px 20px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            font-family: 'Cairo', sans-serif;
            margin: 0 4px;
        }
        .btn-close-tab {
            background: #555;
            color: #fff;
            border: none;
            padding: 8px 14px;
            border-radius: 6px;
            font-size: 13px;
            cursor: pointer;
            font-family: 'Cairo', sans-serif;
        }
    </style>
</head>
<body>

<!-- زر الطباعة -->
<div class="print-btn-bar no-print">
    <button class="btn-close-tab" onclick="window.close()">✕</button>
    <button class="btn-print" onclick="window.print()">🖨 <?= $lang['print_thermal'] ?></button>
</div>

<!-- ============================================================
     الإيصال الحراري
     ============================================================ -->
<div class="thermal-receipt">

    <!-- اسم المتجر -->
    <div class="store-name"><?= htmlspecialchars($store_name, ENT_QUOTES, 'UTF-8') ?></div>
    <div class="store-header-msg"><?= htmlspecialchars($thermal_header, ENT_QUOTES, 'UTF-8') ?></div>

    <?php if ($invoice['branch_name']): ?>
        <div style="text-align:center; font-size:10.5px; color:#555; margin-top:2px;">
            <?= htmlspecialchars($invoice['branch_name'], ENT_QUOTES, 'UTF-8') ?>
            <?php if ($invoice['branch_phone']): ?>
                — <?= htmlspecialchars($invoice['branch_phone'], ENT_QUOTES, 'UTF-8') ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <hr class="sep-solid">

    <!-- معلومات الفاتورة -->
    <div class="info-row">
        <span class="info-label"><?= $is_rtl ? 'الكاشير:' : 'Cashier:' ?></span>
        <span class="info-value"><?= htmlspecialchars($invoice['cashier_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
    </div>
    <div class="info-row">
        <span class="info-label"><?= $lang['date'] ?>:</span>
        <span class="info-value"><?= $inv_date ?></span>
    </div>
    <div class="info-row">
        <span class="info-label"><?= $lang['time'] ?>:</span>
        <span class="info-value"><?= $inv_time ?></span>
    </div>
    <?php if ($invoice['customer_name']): ?>
    <div class="info-row">
        <span class="info-label"><?= $lang['customer'] ?>:</span>
        <span class="info-value"><?= htmlspecialchars($invoice['customer_name'], ENT_QUOTES, 'UTF-8') ?></span>
    </div>
    <?php endif; ?>

    <div class="inv-num-val"># <?= htmlspecialchars($invoice['invoice_number'], ENT_QUOTES, 'UTF-8') ?></div>

    <hr class="sep-solid">

    <!-- بنود الفاتورة -->
    <?php foreach ($items as $item): ?>
    <div class="item-block">
        <div class="item-name"><?= htmlspecialchars($item['product_name'], ENT_QUOTES, 'UTF-8') ?></div>
        <div class="item-calc">
            <span class="calc-math">
                <?= fmt_qty_t((float)$item['quantity']) ?>
                &times;
                <?= number_format((float)$item['unit_price'], 2) ?>
            </span>
            <span class="calc-total"><?= number_format((float)$item['total'], 2) ?> SDG</span>
        </div>
        <?php if ((float)$item['discount'] > 0): ?>
        <div class="item-disc">
            <?= $is_rtl ? 'خصم:' : 'Disc:' ?>
            - <?= number_format((float)$item['discount'], 2) ?> SDG
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>

    <hr class="sep-solid">

    <!-- الملخص -->
    <?php if ((float)$invoice['discount_amount'] > 0): ?>
    <div class="summary-row">
        <span class="s-label"><?= $lang['subtotal'] ?>:</span>
        <span class="s-value"><?= number_format((float)$invoice['subtotal'], 2) ?> SDG</span>
    </div>
    <div class="summary-row">
        <span class="s-label">
            <?= $lang['discount'] ?>
            <?php if ($invoice['discount_type'] === 'percent'): ?>
                (<?= number_format((float)$invoice['discount_value'], 1) ?>%)
            <?php endif; ?>:
        </span>
        <span class="s-value" style="color:#c0392b;">
            - <?= number_format((float)$invoice['discount_amount'], 2) ?> SDG
        </span>
    </div>
    <?php endif; ?>

    <!-- الإجمالي -->
    <div class="summary-row total-row">
        <span><?= $lang['total'] ?>:</span>
        <span><?= number_format((float)$invoice['total'], 2) ?> SDG</span>
    </div>

    <hr class="sep">

    <!-- معلومات الدفع -->
    <div class="summary-row payment-row">
        <span class="s-label"><?= $lang['payment_method'] ?>:</span>
        <span class="s-value"><?= htmlspecialchars($pm_label, ENT_QUOTES, 'UTF-8') ?></span>
    </div>
    <div class="summary-row payment-row">
        <span class="s-label"><?= $lang['amount_paid'] ?>:</span>
        <span class="s-value"><?= number_format((float)$invoice['amount_paid'], 2) ?> SDG</span>
    </div>
    <div class="summary-row change-row">
        <span><?= $lang['change_amount'] ?>:</span>
        <span><?= number_format((float)$invoice['change_amount'], 2) ?> SDG</span>
    </div>

    <?php if (!empty($invoice['notes'])): ?>
    <hr class="sep">
    <div style="font-size:10.5px; color:#555; text-align:center;">
        <?= htmlspecialchars($invoice['notes'], ENT_QUOTES, 'UTF-8') ?>
    </div>
    <?php endif; ?>

    <hr class="sep-solid">

    <!-- التذييل -->
    <div class="footer-msg"><?= htmlspecialchars($thermal_footer, ENT_QUOTES, 'UTF-8') ?></div>
    <div class="footer-date"><?= htmlspecialchars($store_name, ENT_QUOTES, 'UTF-8') ?> &mdash; <?= $inv_date ?></div>

    <!-- مسافة في النهاية للطابعة الحرارية -->
    <div style="height:8mm;"></div>

</div><!-- /.thermal-receipt -->

<script>
    // طباعة تلقائية عند التحميل
    window.addEventListener('load', function () {
        setTimeout(function () { window.print(); }, 400);
    });
</script>
</body>
</html>
