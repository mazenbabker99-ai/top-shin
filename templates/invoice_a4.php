<?php
// ============================================================
// المسار: templates/invoice_a4.php
// الوظيفة: قالب طباعة فاتورة A4 — يُطبع تلقائياً عند التحميل
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
        b.code    AS branch_code,
        b.address AS branch_address,
        b.phone   AS branch_phone,
        u.name    AS cashier_name,
        COALESCE(c.name,  '') AS customer_name,
        COALESCE(c.phone, '') AS customer_phone
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
        ii.id, ii.product_name, ii.unit_price,
        ii.quantity, ii.discount, ii.total,
        COALESCE(p.unit, '') AS unit
    FROM invoice_items ii
    LEFT JOIN products p ON p.id = ii.product_id
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

// ——— إعدادات المتجر ———
$store_name    = get_setting('store_name_'    . $lang_code, $pdo) ?: 'Top Shine';
$store_address = get_setting('store_address_' . $lang_code, $pdo) ?: '';
$store_logo    = get_setting('store_logo',   $pdo) ?: 'assets/images/logo.png';
$tax_enabled   = (bool)(int)get_setting('tax_enabled', $pdo);
$tax_percent   = (float)get_setting('tax_percent', $pdo);

// ——— تواريخ الفاتورة ———
$inv_date = date('Y-m-d',       strtotime($invoice['created_at']));
$inv_time = date('H:i',         strtotime($invoice['created_at']));

// ——— خريطة طرق الدفع ———
$pm_labels = [
    'cash'   => $lang['cash'],
    'bankak' => $lang['bankak'],
    'ocash'  => $lang['ocash'],
    'card'   => $lang['card'],
    'other'  => $lang['other'],
];
$pm_label = $pm_labels[$invoice['payment_method']] ?? $invoice['payment_method'];

// assets path (templates/ = عمق 1)
$assets = '../assets';
?>
<!DOCTYPE html>
<html lang="<?= $lang_code ?>" dir="<?= $dir ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $lang['sales_invoice'] ?> — <?= htmlspecialchars($invoice['invoice_number'], ENT_QUOTES, 'UTF-8') ?></title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700&display=swap" rel="stylesheet">

    <style>
        /* ——— إعدادات الطباعة ——— */
        @page {
            size: A4 portrait;
            margin: 12mm 14mm;
        }

        @media print {
            nav, .sidebar, .no-print,
            .navbar, header, footer,
            .btn, button { display: none !important; }
            body { background: #fff !important; }
            .page-break { page-break-after: always; }
            .no-break { page-break-inside: avoid; }
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Tajawal', 'Arial', sans-serif;
        }

        body {
            background: #f0f0f0;
            direction: <?= $dir ?>;
            color: #1a1a1a;
            font-size: 13px;
            line-height: 1.5;
        }

        /* ——— حاوية الفاتورة ——— */
        .invoice-wrapper {
            max-width: 210mm;
            min-height: 297mm;
            margin: 20px auto;
            background: #fff;
            padding: 18mm 16mm 14mm;
            box-shadow: 0 2px 20px rgba(0,0,0,.12);
            display: flex;
            flex-direction: column;
        }

        /* ——— رأس الفاتورة ——— */
        .inv-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 2px solid #C9A84C;
            padding-bottom: 12px;
            margin-bottom: 14px;
        }

        .inv-header-right { display: flex; align-items: center; gap: 12px; }

        .inv-logo {
            width: 65px;
            height: 65px;
            object-fit: contain;
            border-radius: 6px;
        }

        .store-name {
            font-size: 20px;
            font-weight: 700;
            color: #A0812A;
            line-height: 1.2;
        }
        .store-sub {
            font-size: 11px;
            color: #666;
            margin-top: 2px;
        }

        .inv-header-left { text-align: <?= $is_rtl ? 'left' : 'right' ?>; }
        .inv-header-left .branch-name {
            font-size: 14px;
            font-weight: 700;
            color: #1a1a1a;
        }
        .inv-header-left .branch-info {
            font-size: 11px;
            color: #555;
            margin-top: 3px;
            line-height: 1.6;
        }

        /* ——— شريط عنوان الفاتورة ——— */
        .inv-title-bar {
            background: #0D0D0D;
            color: #C9A84C;
            text-align: center;
            padding: 7px 0;
            font-size: 15px;
            font-weight: 700;
            letter-spacing: .5px;
            border-radius: 4px;
            margin-bottom: 14px;
        }

        /* ——— معلومات الفاتورة ——— */
        .inv-meta {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 4px 20px;
            margin-bottom: 16px;
            font-size: 12px;
        }
        .inv-meta-row {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 3px 0;
            border-bottom: 1px dashed #e8e8e8;
        }
        .meta-label {
            font-weight: 600;
            color: #555;
            min-width: 90px;
            flex-shrink: 0;
        }
        .meta-value { color: #1a1a1a; }
        .meta-value.inv-num {
            color: #A0812A;
            font-weight: 700;
            font-size: 13px;
        }

        /* ——— جدول البنود ——— */
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 16px;
            font-size: 12px;
        }
        .items-table thead tr {
            background: #0D0D0D;
            color: #C9A84C;
        }
        .items-table thead th {
            padding: 7px 8px;
            font-weight: 700;
            font-size: 11.5px;
            white-space: nowrap;
        }
        .items-table tbody tr:nth-child(even) { background: #f8f8f5; }
        .items-table tbody tr:nth-child(odd)  { background: #fff; }
        .items-table tbody td {
            padding: 6px 8px;
            border-bottom: 1px solid #eee;
            vertical-align: middle;
        }
        .items-table tbody tr:last-child td { border-bottom: 2px solid #C9A84C; }

        .col-seq    { width: 30px;  text-align: center; }
        .col-name   { text-align: <?= $is_rtl ? 'right' : 'left' ?>; }
        .col-qty    { width: 65px;  text-align: center; }
        .col-price  { width: 85px;  text-align: center; }
        .col-disc   { width: 70px;  text-align: center; }
        .col-total  { width: 90px;  text-align: center; font-weight: 700; color: #A0812A; }

        /* ——— ملخص المبالغ ——— */
        .inv-summary {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 14px;
        }
        .summary-table {
            width: 240px;
            font-size: 12px;
        }
        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 4px 6px;
            border-bottom: 1px dashed #e8e8e8;
        }
        .summary-row.total-row {
            background: #0D0D0D;
            color: #C9A84C;
            font-size: 14px;
            font-weight: 700;
            border-radius: 4px;
            border: none;
            padding: 7px 8px;
            margin-top: 4px;
        }
        .summary-label { color: #555; }
        .summary-label.total-l { color: #C9A84C; }
        .summary-value { font-weight: 600; }

        /* ——— معلومات الدفع ——— */
        .inv-payment {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 8px;
            margin-bottom: 16px;
            font-size: 12px;
        }
        .pay-box {
            background: #f5f5f0;
            border: 1px solid #ddd;
            border-radius: 5px;
            padding: 7px 10px;
            text-align: center;
        }
        .pay-box-label { color: #777; font-size: 10.5px; margin-bottom: 2px; }
        .pay-box-value { font-weight: 700; color: #1a1a1a; font-size: 13px; }
        .pay-box.method .pay-box-value { color: #A0812A; }
        .pay-box.change .pay-box-value { color: #1a7a3a; }

        /* ——— ملاحظات ——— */
        .inv-notes {
            background: #fafaf7;
            border: 1px dashed #C9A84C;
            border-radius: 4px;
            padding: 8px 10px;
            font-size: 11.5px;
            color: #555;
            margin-bottom: 14px;
        }
        .inv-notes-label { font-weight: 700; color: #A0812A; margin-bottom: 2px; }

        /* ——— العميل ——— */
        .inv-customer {
            background: #fafaf7;
            border-inline-start: 3px solid #C9A84C;
            padding: 6px 10px;
            font-size: 12px;
            margin-bottom: 14px;
        }

        /* ——— تذييل الفاتورة ——— */
        .inv-footer {
            margin-top: auto;
            border-top: 2px solid #C9A84C;
            padding-top: 10px;
            text-align: center;
        }
        .inv-footer .thank-msg {
            font-size: 14px;
            font-weight: 700;
            color: #A0812A;
        }
        .inv-footer .footer-brand {
            font-size: 11px;
            color: #999;
            margin-top: 4px;
        }

        /* ——— حالة الفاتورة ——— */
        .status-badge {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }
        .status-completed { background: #d4edda; color: #155724; }
        .status-refunded  { background: #fff3cd; color: #856404; }
        .status-cancelled { background: #f8d7da; color: #721c24; }

        /* ——— زر الطباعة — يُخفى عند الطباعة ——— */
        .print-btn-bar {
            position: fixed;
            bottom: 20px;
            <?= $is_rtl ? 'left' : 'right' ?>: 20px;
            display: flex;
            gap: 10px;
            z-index: 999;
        }
        .btn-print {
            background: #C9A84C;
            color: #0D0D0D;
            border: none;
            padding: 10px 22px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            font-family: 'Tajawal', sans-serif;
        }
        .btn-print:hover { background: #E2C97E; }
        .btn-close-tab {
            background: #333;
            color: #f5f5f5;
            border: none;
            padding: 10px 16px;
            border-radius: 8px;
            font-size: 14px;
            cursor: pointer;
            font-family: 'Tajawal', sans-serif;
        }
        .btn-close-tab:hover { background: #555; }

        @media print {
            .print-btn-bar { display: none !important; }
            .invoice-wrapper { margin: 0; box-shadow: none; padding: 0; }
            body { background: #fff; }
        }
    </style>
</head>
<body>

<!-- زر الطباعة -->
<div class="print-btn-bar no-print">
    <button class="btn-close-tab" onclick="window.close()">✕ <?= $is_rtl ? 'إغلاق' : 'Close' ?></button>
    <button class="btn-print" onclick="window.print()">
        🖨 <?= $lang['print_a4'] ?>
    </button>
</div>

<!-- ============================================================
     الفاتورة
     ============================================================ -->
<div class="invoice-wrapper no-break">

    <!-- رأس الفاتورة -->
    <div class="inv-header">
        <div class="inv-header-right">
            <img src="<?= $assets ?>/images/logo.png"
                 alt="Logo"
                 class="inv-logo"
                 onerror="this.style.display='none'">
            <div>
                <div class="store-name"><?= htmlspecialchars($store_name, ENT_QUOTES, 'UTF-8') ?></div>
                <?php if ($store_address): ?>
                    <div class="store-sub"><?= htmlspecialchars($store_address, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
            </div>
        </div>
        <div class="inv-header-left">
            <div class="branch-name"><?= htmlspecialchars($invoice['branch_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
            <div class="branch-info">
                <?php if ($invoice['branch_address']): ?>
                    <?= htmlspecialchars($invoice['branch_address'], ENT_QUOTES, 'UTF-8') ?><br>
                <?php endif; ?>
                <?php if ($invoice['branch_phone']): ?>
                    <?= $is_rtl ? 'ت:' : 'Tel:' ?>
                    <?= htmlspecialchars($invoice['branch_phone'], ENT_QUOTES, 'UTF-8') ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- عنوان الفاتورة -->
    <div class="inv-title-bar">
        <?= $lang['sales_invoice'] ?>
        <?php if ($invoice['status'] !== 'completed'): ?>
            <span class="status-badge status-<?= htmlspecialchars($invoice['status'], ENT_QUOTES, 'UTF-8') ?>"
                  style="margin-inline-start:8px; font-size:11px;">
                <?= $lang[$invoice['status']] ?? $invoice['status'] ?>
            </span>
        <?php endif; ?>
    </div>

    <!-- معلومات الفاتورة -->
    <div class="inv-meta">
        <div class="inv-meta-row">
            <span class="meta-label"><?= $lang['invoice_number'] ?>:</span>
            <span class="meta-value inv-num"><?= htmlspecialchars($invoice['invoice_number'], ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <div class="inv-meta-row">
            <span class="meta-label"><?= $lang['branch'] ?>:</span>
            <span class="meta-value"><?= htmlspecialchars($invoice['branch_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <div class="inv-meta-row">
            <span class="meta-label"><?= $lang['invoice_date'] ?>:</span>
            <span class="meta-value"><?= $inv_date ?></span>
        </div>
        <div class="inv-meta-row">
            <span class="meta-label"><?= $lang['cashier'] ?>:</span>
            <span class="meta-value"><?= htmlspecialchars($invoice['cashier_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <div class="inv-meta-row">
            <span class="meta-label"><?= $lang['time'] ?>:</span>
            <span class="meta-value"><?= $inv_time ?></span>
        </div>
        <?php if ($invoice['customer_name']): ?>
        <div class="inv-meta-row">
            <span class="meta-label"><?= $lang['customer'] ?>:</span>
            <span class="meta-value"><?= htmlspecialchars($invoice['customer_name'], ENT_QUOTES, 'UTF-8') ?>
                <?php if ($invoice['customer_phone']): ?>
                    — <?= htmlspecialchars($invoice['customer_phone'], ENT_QUOTES, 'UTF-8') ?>
                <?php endif; ?>
            </span>
        </div>
        <?php endif; ?>
    </div>

    <!-- جدول البنود -->
    <table class="items-table no-break">
        <thead>
            <tr>
                <th class="col-seq">#</th>
                <th class="col-name"><?= $lang['product'] ?? ($is_rtl ? 'المنتج' : 'Product') ?></th>
                <th class="col-qty"><?= $lang['quantity'] ?></th>
                <th class="col-price"><?= $lang['unit_price'] ?></th>
                <th class="col-disc"><?= $lang['discount'] ?></th>
                <th class="col-total"><?= $lang['total'] ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $i => $item): ?>
            <tr>
                <td class="col-seq" style="color:#999;"><?= $i + 1 ?></td>
                <td class="col-name">
                    <?= htmlspecialchars($item['product_name'], ENT_QUOTES, 'UTF-8') ?>
                    <?php if ($item['unit']): ?>
                        <span style="color:#999; font-size:10.5px;">(<?= htmlspecialchars($item['unit'], ENT_QUOTES, 'UTF-8') ?>)</span>
                    <?php endif; ?>
                </td>
                <td class="col-qty"><?= rtrim(rtrim(number_format((float)$item['quantity'], 3, '.', ''), '0'), '.') ?></td>
                <td class="col-price"><?= number_format((float)$item['unit_price'], 2) ?></td>
                <td class="col-disc">
                    <?php if ((float)$item['discount'] > 0): ?>
                        <span style="color:#c0392b;"><?= number_format((float)$item['discount'], 2) ?></span>
                    <?php else: ?>
                        <span style="color:#ccc;">—</span>
                    <?php endif; ?>
                </td>
                <td class="col-total"><?= number_format((float)$item['total'], 2) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <!-- ملخص المبالغ -->
    <div class="inv-summary">
        <div class="summary-table">
            <div class="summary-row">
                <span class="summary-label"><?= $lang['subtotal'] ?>:</span>
                <span class="summary-value"><?= number_format((float)$invoice['subtotal'], 2) ?> SDG</span>
            </div>

            <?php if ((float)$invoice['discount_amount'] > 0): ?>
            <div class="summary-row">
                <span class="summary-label">
                    <?= $lang['discount'] ?>
                    <?php if ($invoice['discount_type'] === 'percent'): ?>
                        (<?= number_format((float)$invoice['discount_value'], 1) ?>%)
                    <?php endif; ?>:
                </span>
                <span class="summary-value" style="color:#c0392b;">
                    - <?= number_format((float)$invoice['discount_amount'], 2) ?> SDG
                </span>
            </div>
            <?php endif; ?>

            <?php if ($tax_enabled && $tax_percent > 0): ?>
            <div class="summary-row">
                <span class="summary-label"><?= $is_rtl ? 'ضريبة' : 'Tax' ?> (<?= $tax_percent ?>%):</span>
                <span class="summary-value"><?= number_format((float)$invoice['total'] * $tax_percent / 100, 2) ?> SDG</span>
            </div>
            <?php endif; ?>

            <div class="summary-row total-row">
                <span class="summary-label total-l"><?= $lang['total'] ?>:</span>
                <span class="summary-value"><?= number_format((float)$invoice['total'], 2) ?> SDG</span>
            </div>
        </div>
    </div>

    <!-- معلومات الدفع -->
    <div class="inv-payment">
        <div class="pay-box method">
            <div class="pay-box-label"><?= $lang['payment_method'] ?></div>
            <div class="pay-box-value"><?= htmlspecialchars($pm_label, ENT_QUOTES, 'UTF-8') ?></div>
        </div>
        <div class="pay-box">
            <div class="pay-box-label"><?= $lang['amount_paid'] ?></div>
            <div class="pay-box-value"><?= number_format((float)$invoice['amount_paid'], 2) ?> SDG</div>
        </div>
        <div class="pay-box change">
            <div class="pay-box-label"><?= $lang['change_amount'] ?></div>
            <div class="pay-box-value"><?= number_format((float)$invoice['change_amount'], 2) ?> SDG</div>
        </div>
    </div>

    <?php if (!empty($invoice['notes'])): ?>
    <!-- ملاحظات -->
    <div class="inv-notes">
        <div class="inv-notes-label"><?= $lang['notes'] ?>:</div>
        <?= htmlspecialchars($invoice['notes'], ENT_QUOTES, 'UTF-8') ?>
    </div>
    <?php endif; ?>

    <!-- تذييل الفاتورة -->
    <div class="inv-footer">
        <div class="thank-msg"><?= $lang['thank_you'] ?></div>
        <div class="footer-brand"><?= htmlspecialchars($store_name, ENT_QUOTES, 'UTF-8') ?> &mdash; <?= $inv_date ?></div>
    </div>

</div><!-- /.invoice-wrapper -->

<script>
    // طباعة تلقائية عند التحميل
    window.addEventListener('load', function () {
        setTimeout(function () { window.print(); }, 400);
    });
</script>
</body>
</html>
