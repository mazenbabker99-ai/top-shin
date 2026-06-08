<?php
// ============================================================
// المسار: cashier/pos.php
// الوظيفة: واجهة نقطة البيع الكاملة — full width بدون sidebar
// الصلاحية: cashier + branch_admin + super_admin
// ============================================================

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/middleware.php';

require_role(['cashier', 'branch_admin', 'super_admin']);

$pdo        = db();
$lang_code  = Auth::getLang();
$lang       = load_lang();
$is_rtl     = ($lang_code === 'ar');
$dir        = $is_rtl ? 'rtl' : 'ltr';
$csrf_token = generate_csrf_token();

$cashier_name = $_SESSION['user_name']   ?? '';
$branch_name  = $_SESSION['branch_name'] ?? '';
$branch_id    = Auth::getBranchId();

// جلب اسم المتجر من الإعدادات
$store_name = get_setting('store_name_' . $lang_code, $pdo);
if (!$store_name) $store_name = 'Top Shine';

// Bootstrap RTL/LTR
$bs_css = $is_rtl
    ? 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css'
    : 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css';

// عمق المسار للـ assets
$depth = 1; // cashier/ = عمق 1
$assets = str_repeat('../', $depth) . 'assets';
?>
<!DOCTYPE html>
<html lang="<?= $lang_code ?>" dir="<?= $dir ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $lang['pos'] ?> — <?= htmlspecialchars($store_name, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="<?= $bs_css ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700&display=swap" rel="stylesheet">

    <style>
        /* ——— CSS Variables ——— */
        :root {
            --ts-gold:          #C9A84C;
            --ts-gold-light:    #E2C97E;
            --ts-gold-dark:     #A0812A;
            --ts-silver:        #B0B3B8;
            --ts-silver-light:  #D8D9DC;
            --ts-silver-dark:   #7A7D82;
            --ts-bg-dark:       #0D0D0D;
            --ts-bg-mid:        #1A1A1A;
            --ts-bg-card:       #242424;
            --ts-bg-input:      #2E2E2E;
            --ts-text-primary:  #F5F5F5;
            --ts-text-secondary:#B0B3B8;
            --ts-text-muted:    #6C757D;
            --ts-success:       #28A745;
            --ts-warning:       #C9A84C;
            --ts-danger:        #DC3545;
            --ts-border:        #3A3A3A;
            --ts-border-gold:   #C9A84C;
        }

        * { font-family: 'Tajawal', sans-serif; box-sizing: border-box; }

        body {
            background: var(--ts-bg-mid);
            color: var(--ts-text-primary);
            height: 100vh;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        /* ——— Navbar ——— */
        .pos-navbar {
            background: var(--ts-bg-dark);
            border-bottom: 1px solid var(--ts-gold-dark);
            padding: .5rem 1rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-shrink: 0;
            z-index: 100;
        }
        .pos-navbar .brand {
            color: var(--ts-gold);
            font-weight: 700;
            font-size: 1.05rem;
            display: flex;
            align-items: center;
            gap: .4rem;
        }
        .pos-navbar .nav-info {
            color: var(--ts-text-muted);
            font-size: .82rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .pos-navbar .nav-info span {
            display: flex;
            align-items: center;
            gap: .3rem;
        }

        /* ——— Layout ——— */
        .pos-body {
            display: flex;
            flex: 1;
            overflow: hidden;
        }

        /* ——— منطقة البحث (يسار) ——— */
        .pos-search-panel {
            width: 55%;
            display: flex;
            flex-direction: column;
            border-inline-end: 1px solid var(--ts-border);
            overflow: hidden;
        }

        .search-box-wrapper {
            padding: .75rem;
            background: var(--ts-bg-dark);
            border-bottom: 1px solid var(--ts-border);
        }
        .search-input-group {
            position: relative;
        }
        .search-input-group .search-icon {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            color: var(--ts-gold);
            font-size: 1rem;
            inset-inline-start: .85rem;
            pointer-events: none;
        }
        #searchInput {
            background: var(--ts-bg-input);
            border: 1px solid var(--ts-border);
            color: var(--ts-text-primary);
            border-radius: 8px;
            padding: .65rem .75rem;
            padding-inline-start: 2.4rem;
            width: 100%;
            font-size: .95rem;
            font-family: 'Tajawal', sans-serif;
            transition: border-color .2s;
        }
        #searchInput:focus {
            outline: none;
            border-color: var(--ts-gold);
            background: var(--ts-bg-input);
            color: var(--ts-text-primary);
        }
        #searchInput::placeholder { color: var(--ts-text-muted); }

        /* ——— نتائج البحث ——— */
        .search-results {
            flex: 1;
            overflow-y: auto;
            padding: .5rem;
        }
        .search-results::-webkit-scrollbar { width: 5px; }
        .search-results::-webkit-scrollbar-track { background: var(--ts-bg-mid); }
        .search-results::-webkit-scrollbar-thumb { background: var(--ts-border); border-radius: 3px; }

        .product-card {
            background: var(--ts-bg-card);
            border: 1px solid var(--ts-border);
            border-radius: 8px;
            padding: .65rem .8rem;
            margin-bottom: .4rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: .75rem;
            transition: border-color .15s, background .15s;
            user-select: none;
        }
        .product-card:hover,
        .product-card:focus {
            border-color: var(--ts-gold);
            background: #2a2a1a;
            outline: none;
        }
        .product-card.out-of-stock {
            opacity: .55;
            cursor: not-allowed;
        }
        .product-card.out-of-stock:hover { border-color: var(--ts-danger); }

        .product-img {
            width: 48px;
            height: 48px;
            object-fit: cover;
            border-radius: 6px;
            border: 1px solid var(--ts-border);
            flex-shrink: 0;
            background: var(--ts-bg-mid);
        }
        .product-img-placeholder {
            width: 48px;
            height: 48px;
            border-radius: 6px;
            border: 1px solid var(--ts-border);
            background: var(--ts-bg-mid);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--ts-text-muted);
            font-size: 1.2rem;
            flex-shrink: 0;
        }
        .product-info { flex: 1; min-width: 0; }
        .product-name {
            font-weight: 600;
            font-size: .88rem;
            color: var(--ts-text-primary);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .product-barcode {
            font-size: .72rem;
            color: var(--ts-text-muted);
            font-family: monospace;
        }
        .product-price-col { text-align: end; flex-shrink: 0; }
        .product-price {
            font-weight: 700;
            color: var(--ts-gold);
            font-size: .92rem;
        }
        .product-stock {
            font-size: .72rem;
            margin-top: .1rem;
        }
        .stock-ok    { color: var(--ts-success); }
        .stock-low   { color: var(--ts-warning); }
        .stock-empty { color: var(--ts-danger); }

        .search-placeholder {
            text-align: center;
            color: var(--ts-text-muted);
            padding: 3rem 1rem;
        }
        .search-placeholder i { font-size: 2.5rem; opacity: .3; display: block; margin-bottom: .75rem; }

        /* ——— السلة (يمين) ——— */
        .pos-cart-panel {
            width: 45%;
            display: flex;
            flex-direction: column;
            background: var(--ts-bg-card);
        }

        .cart-header {
            background: var(--ts-bg-dark);
            border-bottom: 1px solid var(--ts-border);
            padding: .65rem .9rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .cart-header-title {
            color: var(--ts-gold);
            font-weight: 700;
            font-size: .95rem;
            display: flex;
            align-items: center;
            gap: .4rem;
        }
        .cart-count-badge {
            background: var(--ts-gold);
            color: #0D0D0D;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            font-size: .7rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* ——— قائمة السلة ——— */
        .cart-items {
            flex: 1;
            overflow-y: auto;
            padding: .4rem;
        }
        .cart-items::-webkit-scrollbar { width: 4px; }
        .cart-items::-webkit-scrollbar-thumb { background: var(--ts-border); border-radius: 2px; }

        .cart-empty {
            text-align: center;
            color: var(--ts-text-muted);
            padding: 2.5rem 1rem;
        }
        .cart-empty i { font-size: 2.2rem; opacity: .3; display: block; margin-bottom: .5rem; }

        .cart-item {
            background: var(--ts-bg-mid);
            border: 1px solid var(--ts-border);
            border-radius: 7px;
            padding: .5rem .65rem;
            margin-bottom: .35rem;
            display: grid;
            grid-template-columns: 1fr auto;
            gap: .25rem;
        }
        .cart-item-name {
            font-size: .84rem;
            font-weight: 600;
            color: var(--ts-text-primary);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .cart-item-controls {
            display: flex;
            align-items: center;
            gap: .35rem;
            margin-top: .3rem;
        }
        .cart-item-controls label {
            font-size: .72rem;
            color: var(--ts-text-muted);
            flex-shrink: 0;
        }
        .cart-num-input {
            background: var(--ts-bg-input);
            border: 1px solid var(--ts-border);
            color: var(--ts-text-primary);
            border-radius: 5px;
            padding: .18rem .35rem;
            font-size: .8rem;
            width: 65px;
            text-align: center;
            font-family: 'Tajawal', sans-serif;
        }
        .cart-num-input:focus {
            border-color: var(--ts-gold);
            outline: none;
        }
        .cart-item-total {
            font-weight: 700;
            color: var(--ts-gold);
            font-size: .85rem;
            text-align: end;
            align-self: center;
        }
        .btn-remove-item {
            background: transparent;
            border: none;
            color: var(--ts-danger);
            cursor: pointer;
            padding: .1rem .3rem;
            font-size: .9rem;
            line-height: 1;
            border-radius: 4px;
            align-self: start;
        }
        .btn-remove-item:hover { background: #dc354522; }

        /* ——— تفاصيل الحساب ——— */
        .cart-summary {
            border-top: 1px solid var(--ts-border);
            padding: .6rem .9rem;
            background: var(--ts-bg-dark);
        }
        .summary-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: .18rem 0;
            font-size: .85rem;
        }
        .summary-row.total-row {
            font-size: 1.05rem;
            font-weight: 700;
            color: var(--ts-gold);
            border-top: 1px solid var(--ts-border);
            padding-top: .5rem;
            margin-top: .25rem;
        }
        .summary-label { color: var(--ts-text-secondary); }

        /* ——— منطقة الدفع ——— */
        .cart-payment {
            border-top: 1px solid var(--ts-border);
            padding: .6rem .9rem;
            background: var(--ts-bg-dark);
        }

        .payment-methods {
            display: flex;
            gap: .3rem;
            flex-wrap: wrap;
            margin-bottom: .5rem;
        }
        .pm-btn {
            flex: 1;
            min-width: 60px;
            background: var(--ts-bg-input);
            border: 1px solid var(--ts-border);
            color: var(--ts-text-secondary);
            border-radius: 6px;
            padding: .3rem .4rem;
            font-size: .75rem;
            cursor: pointer;
            text-align: center;
            transition: all .15s;
            font-family: 'Tajawal', sans-serif;
        }
        .pm-btn.active {
            background: var(--ts-gold);
            border-color: var(--ts-gold);
            color: #0D0D0D;
            font-weight: 700;
        }
        .pm-btn:hover:not(.active) {
            border-color: var(--ts-silver);
            color: var(--ts-text-primary);
        }
        .pm-btn i { display: block; font-size: .95rem; margin-bottom: .1rem; }

        .payment-inputs {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: .4rem;
            margin-bottom: .5rem;
        }
        .pay-input-wrap label {
            font-size: .72rem;
            color: var(--ts-text-muted);
            display: block;
            margin-bottom: .2rem;
        }
        .pay-input {
            background: var(--ts-bg-input);
            border: 1px solid var(--ts-border);
            color: var(--ts-text-primary);
            border-radius: 6px;
            padding: .35rem .6rem;
            font-size: .88rem;
            width: 100%;
            font-family: 'Tajawal', sans-serif;
            transition: border-color .15s;
        }
        .pay-input:focus { border-color: var(--ts-gold); outline: none; }

        .change-display {
            background: var(--ts-bg-mid);
            border: 1px solid var(--ts-border);
            border-radius: 6px;
            padding: .3rem .6rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: .5rem;
            font-size: .85rem;
        }
        .change-display .change-label { color: var(--ts-text-muted); }
        .change-display .change-val { font-weight: 700; color: var(--ts-success); }

        /* خيار العميل */
        .customer-row {
            display: flex;
            gap: .4rem;
            margin-bottom: .5rem;
            align-items: center;
        }
        .customer-select {
            flex: 1;
            background: var(--ts-bg-input);
            border: 1px solid var(--ts-border);
            color: var(--ts-text-primary);
            border-radius: 6px;
            padding: .32rem .6rem;
            font-size: .8rem;
            font-family: 'Tajawal', sans-serif;
        }
        .customer-select:focus { border-color: var(--ts-gold); outline: none; }

        /* خصم الفاتورة */
        .discount-row {
            display: flex;
            gap: .4rem;
            margin-bottom: .5rem;
            align-items: center;
        }
        .discount-type-select {
            background: var(--ts-bg-input);
            border: 1px solid var(--ts-border);
            color: var(--ts-text-primary);
            border-radius: 6px;
            padding: .32rem .6rem;
            font-size: .78rem;
            font-family: 'Tajawal', sans-serif;
            width: 130px;
            flex-shrink: 0;
        }
        .discount-type-select:focus { border-color: var(--ts-gold); outline: none; }

        /* ——— أزرار الحفظ ——— */
        .cart-actions {
            display: flex;
            gap: .5rem;
            border-top: 1px solid var(--ts-border);
            padding: .6rem .9rem;
            background: var(--ts-bg-dark);
        }
        .btn-save-print {
            flex: 1;
            background: var(--ts-gold);
            color: #0D0D0D;
            border: none;
            border-radius: 8px;
            padding: .6rem;
            font-size: .9rem;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: .4rem;
            font-family: 'Tajawal', sans-serif;
            transition: background .15s;
        }
        .btn-save-print:hover:not(:disabled) { background: var(--ts-gold-light); }
        .btn-save-print:disabled { opacity: .5; cursor: not-allowed; }
        .btn-save-only {
            background: transparent;
            border: 1px solid var(--ts-silver);
            color: var(--ts-silver);
            border-radius: 8px;
            padding: .6rem .9rem;
            font-size: .85rem;
            cursor: pointer;
            font-family: 'Tajawal', sans-serif;
            transition: all .15s;
        }
        .btn-save-only:hover:not(:disabled) { background: var(--ts-bg-card); color: var(--ts-text-primary); }
        .btn-save-only:disabled { opacity: .5; cursor: not-allowed; }
        .btn-reset {
            background: transparent;
            border: 1px solid #dc354555;
            color: var(--ts-danger);
            border-radius: 8px;
            padding: .6rem .7rem;
            font-size: .85rem;
            cursor: pointer;
            font-family: 'Tajawal', sans-serif;
            transition: all .15s;
        }
        .btn-reset:hover { background: #dc354515; }

        /* ——— Toast ——— */
        .pos-toast {
            position: fixed;
            bottom: 1.5rem;
            inset-inline-start: 50%;
            transform: translateX(-50%);
            background: var(--ts-bg-card);
            border: 1px solid var(--ts-border);
            color: var(--ts-text-primary);
            padding: .6rem 1.2rem;
            border-radius: 8px;
            font-size: .88rem;
            z-index: 9999;
            display: none;
            align-items: center;
            gap: .5rem;
            box-shadow: 0 4px 20px #00000066;
            max-width: 380px;
            text-align: center;
        }
        .pos-toast.show { display: flex; }
        .pos-toast.success { border-color: var(--ts-success); color: var(--ts-success); }
        .pos-toast.error   { border-color: var(--ts-danger);  color: var(--ts-danger); }

        /* ——— Print hide ——— */
        @media print { body { display: none !important; } }

        /* ——— Responsive ——— */
        @media (max-width: 768px) {
            body { overflow: auto; }
            .pos-body { flex-direction: column; height: auto; }
            .pos-search-panel,
            .pos-cart-panel { width: 100%; }
        }

        /* ——— Loading spinner ——— */
        .spinner-sm {
            width: 16px; height: 16px;
            border: 2px solid #0D0D0D44;
            border-top-color: #0D0D0D;
            border-radius: 50%;
            animation: spin .6s linear infinite;
            display: none;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* select options dark */
        select option { background: var(--ts-bg-input); color: var(--ts-text-primary); }
    </style>
</head>
<body>

<!-- ============================================================
     Navbar
     ============================================================ -->
<nav class="pos-navbar no-print">
    <div class="brand">
        <img src="<?= $assets ?>/images/logo.png"
             alt="Logo" height="32"
             onerror="this.style.display='none'"
             style="border-radius:4px;">
        <?= htmlspecialchars($store_name, ENT_QUOTES, 'UTF-8') ?>
    </div>
    <div class="nav-info">
        <span><i class="bi bi-building"></i> <?= htmlspecialchars($branch_name, ENT_QUOTES, 'UTF-8') ?></span>
        <span><i class="bi bi-person-badge"></i> <?= htmlspecialchars($cashier_name, ENT_QUOTES, 'UTF-8') ?></span>
        <span id="posTime" style="color:var(--ts-gold-light);"><i class="bi bi-clock"></i> --:--</span>
    </div>
    <div class="d-flex align-items-center gap-2">
        <a href="dashboard.php"
           class="btn btn-sm"
           style="border:1px solid var(--ts-border); color:var(--ts-text-secondary); background:transparent; font-size:.78rem;">
            <i class="bi bi-speedometer2 me-1"></i><?= $lang['dashboard'] ?>
        </a>
        <a href="<?= str_repeat('../', $depth) ?>auth/logout.php"
           class="btn btn-sm"
           style="border:1px solid #dc354544; color:var(--ts-danger); background:transparent; font-size:.78rem;">
            <i class="bi bi-box-arrow-right me-1"></i><?= $lang['logout'] ?>
        </a>
    </div>
</nav>

<!-- ============================================================
     POS Body
     ============================================================ -->
<div class="pos-body">

    <!-- ===== منطقة البحث ===== -->
    <div class="pos-search-panel">

        <!-- حقل البحث -->
        <div class="search-box-wrapper">
            <div class="search-input-group">
                <i class="bi bi-search search-icon"></i>
                <input type="text"
                       id="searchInput"
                       autocomplete="off"
                       autofocus
                       placeholder="<?= htmlspecialchars($lang['search_product'], ENT_QUOTES, 'UTF-8') ?>"
                       tabindex="1">
            </div>
        </div>

        <!-- نتائج البحث -->
        <div class="search-results" id="searchResults">
            <div class="search-placeholder">
                <i class="bi bi-upc-scan"></i>
                <div style="font-size:.88rem;">
                    <?= ($lang_code === 'ar')
                        ? 'ابدأ الكتابة أو امسح الباركود لإضافة منتج'
                        : 'Start typing or scan barcode to add a product' ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ===== السلة ===== -->
    <div class="pos-cart-panel">

        <!-- رأس السلة -->
        <div class="cart-header">
            <div class="cart-header-title">
                <i class="bi bi-cart3"></i>
                <?= $lang['cart'] ?>
                <span class="cart-count-badge" id="cartCountBadge">0</span>
            </div>
            <!-- خيار العميل -->
            <div style="display:flex; align-items:center; gap:.5rem;">
                <i class="bi bi-person" style="color:var(--ts-silver-dark); font-size:.9rem;"></i>
                <select id="customerSelect" class="customer-select" style="max-width:160px;" tabindex="10">
                    <option value=""><?= ($lang_code === 'ar') ? 'بدون عميل' : 'No customer' ?></option>
                    <?php
                    // جلب العملاء النشطين
                    $cust_stmt = $pdo->prepare("SELECT id, name, phone FROM customers WHERE status='active' ORDER BY name ASC");
                    $cust_stmt->execute();
                    foreach ($cust_stmt->fetchAll() as $cust):
                    ?>
                        <option value="<?= (int)$cust['id'] ?>">
                            <?= htmlspecialchars($cust['name'], ENT_QUOTES, 'UTF-8') ?>
                            <?= $cust['phone'] ? '— ' . htmlspecialchars($cust['phone'], ENT_QUOTES, 'UTF-8') : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <!-- بنود السلة -->
        <div class="cart-items" id="cartItems">
            <div class="cart-empty" id="cartEmpty">
                <i class="bi bi-cart-x"></i>
                <?= $lang['cart_empty'] ?>
            </div>
        </div>

        <!-- ملخص الحساب -->
        <div class="cart-summary">
            <div class="summary-row">
                <span class="summary-label"><?= $lang['subtotal'] ?></span>
                <span id="summarySubtotal">0.00 SDG</span>
            </div>

            <!-- خصم الفاتورة -->
            <div class="discount-row">
                <select id="discountType" class="discount-type-select" tabindex="20">
                    <option value="fixed"><?= $lang['discount_fixed'] ?></option>
                    <option value="percent"><?= $lang['discount_percent'] ?></option>
                </select>
                <input type="number"
                       id="discountValue"
                       class="pay-input"
                       value="0"
                       min="0"
                       step="0.01"
                       placeholder="0"
                       tabindex="21"
                       style="text-align:end;">
            </div>

            <div class="summary-row">
                <span class="summary-label"><?= $lang['discount'] ?></span>
                <span id="summaryDiscount" style="color:var(--ts-danger);">0.00 SDG</span>
            </div>

            <div class="summary-row total-row">
                <span><?= $lang['total'] ?></span>
                <span id="summaryTotal">0.00 SDG</span>
            </div>
        </div>

        <!-- الدفع -->
        <div class="cart-payment">

            <!-- طرق الدفع -->
            <div class="payment-methods">
                <button class="pm-btn active" data-method="cash" tabindex="30">
                    <i class="bi bi-cash"></i><?= $lang['cash'] ?>
                </button>
                <button class="pm-btn" data-method="bankak" tabindex="31">
                    <i class="bi bi-phone"></i><?= $lang['bankak'] ?>
                </button>
                <button class="pm-btn" data-method="ocash" tabindex="32">
                    <i class="bi bi-wallet2"></i><?= $lang['ocash'] ?>
                </button>
                <button class="pm-btn" data-method="card" tabindex="33">
                    <i class="bi bi-credit-card"></i><?= $lang['card'] ?>
                </button>
                <button class="pm-btn" data-method="other" tabindex="34">
                    <i class="bi bi-three-dots"></i><?= $lang['other'] ?>
                </button>
            </div>

            <!-- المدفوع والباقي -->
            <div class="payment-inputs">
                <div class="pay-input-wrap">
                    <label><?= $lang['amount_paid'] ?? 'المدفوع' ?> (SDG)</label>
                    <input type="number" id="amountPaid" class="pay-input"
                           value="0" min="0" step="0.01" tabindex="40">
                </div>
                <div class="pay-input-wrap">
                    <label><?= $lang['change_amount'] ?> (SDG)</label>
                    <input type="text" id="changeAmount" class="pay-input"
                           value="0.00" readonly tabindex="-1"
                           style="color:var(--ts-success); font-weight:700;">
                </div>
            </div>
        </div>

        <!-- أزرار الحفظ -->
        <div class="cart-actions">
            <button class="btn-save-print" id="btnSavePrint" tabindex="50" disabled>
                <span class="spinner-sm" id="saveSpinner"></span>
                <i class="bi bi-printer-fill" id="savePrintIcon"></i>
                <?= $lang['save_and_print'] ?>
                <small style="font-size:.7rem; opacity:.8;">[F10]</small>
            </button>
            <button class="btn-save-only" id="btnSaveOnly" tabindex="51" disabled>
                <i class="bi bi-check-lg"></i>
                <?= $lang['save'] ?>
            </button>
            <button class="btn-reset" id="btnReset" tabindex="52">
                <i class="bi bi-arrow-counterclockwise"></i>
            </button>
        </div>

    </div><!-- /.pos-cart-panel -->

</div><!-- /.pos-body -->

<!-- Toast -->
<div class="pos-toast" id="posToast"></div>

<!-- CSRF Token مخفي -->
<input type="hidden" id="csrfToken" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
<input type="hidden" id="langCode"  value="<?= $lang_code ?>">
<input type="hidden" id="branchId"  value="<?= (int)$branch_id ?>">
<input type="hidden" id="baseUrl"   value="<?= str_repeat('../', $depth) ?>">

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ============================================================
// pos.js — منطق نقطة البيع
// ============================================================
(function () {
    'use strict';

    // ——— State ———
    let cart         = [];          // [{product_id, name, unit, price, qty, discount, total}]
    let paymentMethod = 'cash';
    const CART_KEY   = 'ts_pos_cart_<?= (int)$branch_id ?>_<?= $cashier_id ?>';
    const BASE_URL   = document.getElementById('baseUrl').value;
    const LANG       = document.getElementById('langCode').value;

    // ——— Elements ———
    const searchInput    = document.getElementById('searchInput');
    const searchResults  = document.getElementById('searchResults');
    const cartItems      = document.getElementById('cartItems');
    const cartEmpty      = document.getElementById('cartEmpty');
    const cartCountBadge = document.getElementById('cartCountBadge');
    const summarySubtotal = document.getElementById('summarySubtotal');
    const summaryDiscount = document.getElementById('summaryDiscount');
    const summaryTotal    = document.getElementById('summaryTotal');
    const discountType    = document.getElementById('discountType');
    const discountValue   = document.getElementById('discountValue');
    const amountPaid      = document.getElementById('amountPaid');
    const changeAmount    = document.getElementById('changeAmount');
    const btnSavePrint    = document.getElementById('btnSavePrint');
    const btnSaveOnly     = document.getElementById('btnSaveOnly');
    const btnReset        = document.getElementById('btnReset');
    const customerSelect  = document.getElementById('customerSelect');
    const csrfToken       = document.getElementById('csrfToken').value;
    const posToast        = document.getElementById('posToast');
    const saveSpinner     = document.getElementById('saveSpinner');
    const savePrintIcon   = document.getElementById('savePrintIcon');

    // ——— الساعة ———
    function updateClock() {
        const el = document.getElementById('posTime');
        if (el) el.innerHTML = '<i class="bi bi-clock"></i> ' + new Date().toLocaleTimeString(LANG === 'ar' ? 'ar-SD' : 'en-US', {hour:'2-digit', minute:'2-digit'});
    }
    updateClock();
    setInterval(updateClock, 1000);

    // ============================================================
    // Toast
    // ============================================================
    let toastTimer;
    function showToast(msg, type = 'info', duration = 3000) {
        posToast.className = 'pos-toast show ' + type;
        posToast.innerHTML = msg;
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => posToast.classList.remove('show'), duration);
    }

    // ============================================================
    // حساب الإجماليات
    // ============================================================
    function calcTotals() {
        let subtotal = 0;
        cart.forEach(item => {
            item.total = Math.max(0, item.qty * item.price - item.discount);
            subtotal += item.total;
        });

        const dtype  = discountType.value;
        const dval   = Math.max(0, parseFloat(discountValue.value) || 0);
        let disc_amt = 0;

        if (dtype === 'percent') {
            disc_amt = subtotal * Math.min(100, dval) / 100;
        } else {
            disc_amt = Math.min(subtotal, dval);
        }

        const total  = Math.max(0, subtotal - disc_amt);
        const paid   = parseFloat(amountPaid.value) || 0;
        const change = Math.max(0, paid - total);

        summarySubtotal.textContent = fmtMoney(subtotal);
        summaryDiscount.textContent = fmtMoney(disc_amt);
        summaryTotal.textContent    = fmtMoney(total);
        changeAmount.value          = fmtNum(change);

        const hasItems = cart.length > 0;
        btnSavePrint.disabled = !hasItems;
        btnSaveOnly.disabled  = !hasItems;

        return { subtotal, disc_amt, total, paid, change };
    }

    function fmtMoney(n) {
        return n.toLocaleString(LANG === 'ar' ? 'ar-SD' : 'en-US', {minimumFractionDigits:2, maximumFractionDigits:2}) + ' SDG';
    }
    function fmtNum(n) {
        return parseFloat(n).toFixed(2);
    }

    // ============================================================
    // تحديث عرض السلة
    // ============================================================
    function renderCart() {
        cartCountBadge.textContent = cart.reduce((s, i) => s + i.qty, 0);

        if (cart.length === 0) {
            cartItems.innerHTML = '<div class="cart-empty"><i class="bi bi-cart-x"></i>' +
                (LANG === 'ar' ? 'السلة فارغة' : 'Cart is empty') + '</div>';
            calcTotals();
            saveToLocalStorage();
            return;
        }

        let html = '';
        cart.forEach((item, idx) => {
            const itemTotal = Math.max(0, item.qty * item.price - item.discount);
            html += `
            <div class="cart-item" data-idx="${idx}">
                <div>
                    <div class="cart-item-name" title="${esc(item.name)}">${esc(item.name)}</div>
                    <div class="cart-item-controls">
                        <label>${LANG==='ar'?'الكمية':'Qty'}</label>
                        <input type="number" class="cart-num-input item-qty"
                               value="${fmtNum(item.qty)}" min="0.001" step="0.001"
                               data-idx="${idx}" tabindex="${60 + idx * 2}">
                        <label style="margin-inline-start:.5rem;">${LANG==='ar'?'خصم':'Disc'}</label>
                        <input type="number" class="cart-num-input item-disc"
                               value="${fmtNum(item.discount)}" min="0" step="0.01"
                               data-idx="${idx}" tabindex="${61 + idx * 2}">
                        <span style="font-size:.72rem; color:var(--ts-text-muted);">${esc(item.unit)}</span>
                    </div>
                </div>
                <div style="display:flex; flex-direction:column; align-items:flex-end; gap:.25rem;">
                    <button class="btn-remove-item" data-idx="${idx}" tabindex="-1" title="${LANG==='ar'?'إزالة':'Remove'}">
                        <i class="bi bi-x-lg"></i>
                    </button>
                    <div class="cart-item-total">${fmtMoney(itemTotal)}</div>
                    <div style="font-size:.7rem; color:var(--ts-text-muted);">
                        ${fmtNum(item.price)} × ${fmtNum(item.qty)}
                    </div>
                </div>
            </div>`;
        });

        cartItems.innerHTML = html;

        // ربط الأحداث
        cartItems.querySelectorAll('.item-qty').forEach(inp => {
            inp.addEventListener('change', e => {
                const i = parseInt(e.target.dataset.idx);
                const v = parseFloat(e.target.value);
                if (!isNaN(v) && v > 0) {
                    cart[i].qty = v;
                    renderCart();
                } else {
                    e.target.value = fmtNum(cart[i].qty);
                }
            });
        });

        cartItems.querySelectorAll('.item-disc').forEach(inp => {
            inp.addEventListener('change', e => {
                const i = parseInt(e.target.dataset.idx);
                const v = parseFloat(e.target.value);
                cart[i].discount = isNaN(v) ? 0 : Math.max(0, v);
                renderCart();
            });
        });

        cartItems.querySelectorAll('.btn-remove-item').forEach(btn => {
            btn.addEventListener('click', e => {
                const i = parseInt(btn.dataset.idx);
                cart.splice(i, 1);
                renderCart();
            });
        });

        calcTotals();
        saveToLocalStorage();
    }

    function esc(str) {
        const d = document.createElement('div');
        d.textContent = str;
        return d.innerHTML;
    }

    // ============================================================
    // إضافة منتج للسلة
    // ============================================================
    function addToCart(product) {
        if (!product.in_stock) {
            showToast((LANG==='ar'?'❌ نفد المخزون: ':'❌ Out of stock: ') + product.name, 'error');
            return;
        }

        const existing = cart.find(i => i.product_id === product.id);
        if (existing) {
            existing.qty += 1;
        } else {
            cart.push({
                product_id: product.id,
                name:       product.name,
                unit:       product.unit  || '',
                price:      product.selling_price,
                qty:        1,
                discount:   0,
                total:      product.selling_price,
                available:  product.available_qty,
            });
        }

        renderCart();
        showToast('<i class="bi bi-check-circle-fill"></i> ' + (LANG==='ar'?'تمت الإضافة: ':'Added: ') + product.name, 'success', 1500);

        // أعد التركيز لحقل البحث
        searchInput.focus();
        searchInput.select();
    }

    // ============================================================
    // البحث — AJAX مع Debounce
    // ============================================================
    let searchTimer;
    let lastQuery = '';

    function renderSearchResults(products, query) {
        if (products.length === 0) {
            searchResults.innerHTML = `
            <div class="search-placeholder">
                <i class="bi bi-search" style="opacity:.3;"></i>
                <div>${LANG==='ar'?'لا توجد نتائج لـ: ':'No results for: '}<strong>${esc(query)}</strong></div>
            </div>`;
            return;
        }

        let html = '';
        products.forEach(p => {
            const outOfStock = !p.in_stock;
            const stockClass = p.available_qty <= 0 ? 'stock-empty' : (p.available_qty <= 3 ? 'stock-low' : 'stock-ok');
            const stockIcon  = p.available_qty <= 0 ? 'bi-x-circle-fill' : (p.available_qty <= 3 ? 'bi-exclamation-triangle-fill' : 'bi-check-circle-fill');

            html += `
            <div class="product-card${outOfStock?' out-of-stock':''}"
                 tabindex="0"
                 data-product='${JSON.stringify(p).replace(/'/g,"&#39;")}'>
                <div class="product-img-placeholder">
                    ${p.image
                        ? `<img src="${esc(p.image)}" class="product-img" onerror="this.parentElement.innerHTML='<i class=\\'bi bi-box\\'></i>'">`
                        : '<i class="bi bi-box"></i>'
                    }
                </div>
                <div class="product-info">
                    <div class="product-name">${esc(p.name)}</div>
                    <div class="product-barcode">${p.barcode ? esc(p.barcode) : '—'} · ${esc(p.unit)}</div>
                </div>
                <div class="product-price-col">
                    <div class="product-price">${p.selling_price.toLocaleString()} SDG</div>
                    <div class="product-stock ${stockClass}">
                        <i class="bi ${stockIcon}"></i>
                        ${parseFloat(p.available_qty).toFixed(p.available_qty % 1 === 0 ? 0 : 2)}
                    </div>
                </div>
            </div>`;
        });

        searchResults.innerHTML = html;

        // ربط أحداث النقر
        searchResults.querySelectorAll('.product-card').forEach(card => {
            const addProduct = () => {
                try {
                    const raw = card.dataset.product.replace(/&#39;/g, "'");
                    const product = JSON.parse(raw);
                    addToCart(product);
                } catch(e) { console.error(e); }
            };
            card.addEventListener('click', addProduct);
            card.addEventListener('keydown', e => {
                if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); addProduct(); }
            });
        });
    }

    function doSearch(q) {
        if (q === lastQuery) return;
        lastQuery = q;

        if (q.length < 1) {
            searchResults.innerHTML = `
            <div class="search-placeholder">
                <i class="bi bi-upc-scan"></i>
                <div>${LANG==='ar'?'ابدأ الكتابة أو امسح الباركود لإضافة منتج':'Start typing or scan barcode to add a product'}</div>
            </div>`;
            return;
        }

        searchResults.innerHTML = `
        <div class="search-placeholder">
            <i class="bi bi-hourglass-split" style="animation:spin .8s linear infinite;"></i>
            <div>${LANG==='ar'?'جارٍ البحث...':'Searching...'}</div>
        </div>`;

        fetch(BASE_URL + 'api/search_products.php?q=' + encodeURIComponent(q), {
            headers: { 'X-CSRF-Token': csrfToken }
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                renderSearchResults(data.products, q);
            } else {
                showToast(data.message || 'خطأ في البحث', 'error');
            }
        })
        .catch(() => showToast(LANG==='ar'?'خطأ في الاتصال':'Connection error', 'error'));
    }

    searchInput.addEventListener('input', e => {
        clearTimeout(searchTimer);
        const q = e.target.value.trim();
        searchTimer = setTimeout(() => doSearch(q), 300);
    });

    // ============================================================
    // طرق الدفع
    // ============================================================
    document.querySelectorAll('.pm-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.pm-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            paymentMethod = btn.dataset.method;
        });
    });

    // حساب تلقائي عند تغيير المدفوع / الخصم
    amountPaid.addEventListener('input', calcTotals);
    discountType.addEventListener('change', calcTotals);
    discountValue.addEventListener('input', calcTotals);

    // ============================================================
    // حفظ في localStorage (لاسترداد عند انقطاع الكهرباء)
    // ============================================================
    function saveToLocalStorage() {
        try {
            localStorage.setItem(CART_KEY, JSON.stringify(cart));
        } catch(e) {}
    }

    function loadFromLocalStorage() {
        try {
            const saved = localStorage.getItem(CART_KEY);
            if (saved) {
                const parsed = JSON.parse(saved);
                if (Array.isArray(parsed) && parsed.length > 0) {
                    cart = parsed;
                    renderCart();
                    showToast(LANG==='ar'?'📦 تمت استعادة السلة السابقة':'📦 Previous cart restored', 'info', 4000);
                }
            }
        } catch(e) {}
    }

    // ============================================================
    // إعادة تعيين السلة
    // ============================================================
    function resetCart() {
        cart = [];
        paymentMethod = 'cash';
        discountType.value  = 'fixed';
        discountValue.value = '0';
        amountPaid.value    = '0';
        changeAmount.value  = '0.00';
        searchInput.value   = '';
        lastQuery = '';
        document.querySelectorAll('.pm-btn').forEach(b => b.classList.remove('active'));
        document.querySelector('.pm-btn[data-method="cash"]')?.classList.add('active');
        customerSelect.value = '';

        searchResults.innerHTML = `
        <div class="search-placeholder">
            <i class="bi bi-upc-scan"></i>
            <div>${LANG==='ar'?'ابدأ الكتابة أو امسح الباركود لإضافة منتج':'Start typing or scan barcode to add a product'}</div>
        </div>`;

        renderCart();
        try { localStorage.removeItem(CART_KEY); } catch(e) {}
        searchInput.focus();
    }

    btnReset.addEventListener('click', () => {
        if (cart.length === 0) { searchInput.focus(); return; }
        if (confirm(LANG==='ar'?'هل تريد مسح السلة والبدء من جديد؟':'Clear the cart and start over?')) {
            resetCart();
        }
    });

    // ============================================================
    // حفظ الفاتورة
    // ============================================================
    async function saveInvoice(withPrint = false) {
        if (cart.length === 0) return;

        const totals = calcTotals();

        // تجميع البيانات
        const payload = {
            customer_id:    customerSelect.value ? parseInt(customerSelect.value) : null,
            items:          cart.map(item => ({
                product_id: item.product_id,
                quantity:   item.qty,
                unit_price: item.price,
                discount:   item.discount,
            })),
            discount_type:  discountType.value,
            discount_value: parseFloat(discountValue.value) || 0,
            payment_method: paymentMethod,
            amount_paid:    parseFloat(amountPaid.value) || totals.total,
            notes:          '',
        };

        // إظهار spinner
        saveSpinner.style.display = 'inline-block';
        savePrintIcon.style.display = 'none';
        btnSavePrint.disabled = true;
        btnSaveOnly.disabled  = true;

        try {
            const res = await fetch(BASE_URL + 'api/save_invoice.php', {
                method:  'POST',
                headers: {
                    'Content-Type':  'application/json',
                    'X-CSRF-Token':  csrfToken,
                },
                body: JSON.stringify(payload),
            });

            const data = await res.json();

            if (data.success) {
                showToast('✅ ' + (LANG==='ar'?'تم حفظ الفاتورة: ':'Invoice saved: ') + data.invoice_number, 'success', 3000);

                if (withPrint) {
                    // فتح صفحة اختيار الطباعة
                    window.open(BASE_URL + 'cashier/invoice_print.php?id=' + data.invoice_id, '_blank');
                }

                // تصفير السلة بعد الحفظ
                setTimeout(resetCart, 400);
            } else {
                showToast('❌ ' + (data.message || 'فشل الحفظ'), 'error', 5000);
            }

        } catch(err) {
            showToast('❌ ' + (LANG==='ar'?'خطأ في الاتصال بالخادم':'Server connection error'), 'error', 5000);
        } finally {
            saveSpinner.style.display = 'none';
            savePrintIcon.style.display = 'inline-block';
            btnSavePrint.disabled = cart.length === 0;
            btnSaveOnly.disabled  = cart.length === 0;
        }
    }

    btnSavePrint.addEventListener('click', () => saveInvoice(true));
    btnSaveOnly.addEventListener('click',  () => saveInvoice(false));

    // ============================================================
    // اختصارات لوحة المفاتيح
    // ============================================================
    document.addEventListener('keydown', e => {
        // F10 = حفظ وطباعة
        if (e.key === 'F10') {
            e.preventDefault();
            if (!btnSavePrint.disabled) saveInvoice(true);
        }
        // Escape = إعادة تعيين
        if (e.key === 'Escape') {
            if (cart.length > 0) btnReset.click();
        }
        // أي حرف/رقم يُعيد التركيز لحقل البحث
        if (!e.ctrlKey && !e.altKey && !e.metaKey) {
            const tag = document.activeElement?.tagName;
            if (tag !== 'INPUT' && tag !== 'SELECT' && tag !== 'TEXTAREA') {
                if (e.key.length === 1) {
                    searchInput.focus();
                }
            }
        }
    });

    // ============================================================
    // تهيئة — تحميل السلة المحفوظة
    // ============================================================
    loadFromLocalStorage();
    searchInput.focus();

})();
</script>
</body>
</html>
