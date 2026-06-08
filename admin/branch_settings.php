<?php
// ============================================================
// المسار: admin/branch_settings.php
// الوظيفة: إعدادات الفرع — branch_admin يُعدّل فرعه فقط
//          super_admin يستطيع تعديل إعدادات أي فرع
// الصلاحية: branch_admin | super_admin
// الاعتمادات: includes/layout/header.php | includes/functions.php
//             includes/auth.php | includes/audit.php
// ============================================================

declare(strict_types=1);

$current_page = 'branch_settings';
require_once __DIR__ . '/../includes/layout/header.php';
require_role(['branch_admin', 'super_admin']);

$pdo      = db();
$audit    = new AuditLogger($pdo);
$role     = Auth::getRole();
$user_id  = Auth::getUserId();
$user_bid = Auth::getBranchId();
$is_super = ($role === 'super_admin');

// ============================================================
// تحديد الفرع المستهدف
// super_admin: يختار عبر GET — branch_admin: فرعه فقط
// ============================================================
$target_branch_id = $is_super
    ? (sanitize_int($_GET['branch_id'] ?? 0) ?: $user_bid)
    : $user_bid;

// branch_admin بدون فرع → خطأ
if ($target_branch_id === null && !$is_super) {
    flash('error', $lang['unauthorized']);
    redirect('dashboard.php');
}

// branch_admin يحاول تعديل فرع آخر → خطأ
if (!$is_super && (int)$target_branch_id !== (int)$user_bid) {
    flash('error', $lang['unauthorized']);
    redirect('branch_settings.php');
}

// ============================================================
// جلب بيانات الفرع المستهدف
// ============================================================
$branch_stmt = $pdo->prepare('SELECT * FROM branches WHERE id = ? LIMIT 1');
$branch_stmt->execute([$target_branch_id]);
$branch = $branch_stmt->fetch();

if (!$branch) {
    flash('error', $lang['not_found']);
    redirect($is_super ? '../superadmin/branches.php' : 'dashboard.php');
}

// ============================================================
// مفاتيح الإعدادات المسموح بتعديلها
// branch_admin: هاتف + تذييل الفاتورة (عربي/إنجليزي) + رأس/تذييل Thermal
// super_admin:  نفس الأعلاه (اسم المتجر والشعار في صفحة منفصلة)
// ============================================================
$settings_keys = [
    'branch_phone'      => [
        'label_ar' => 'هاتف الفرع',
        'label_en' => 'Branch Phone',
        'type'     => 'text',
        'maxlen'   => 20,
        'dir'      => 'ltr',
        'section'  => 'contact',
    ],
    'invoice_footer_ar' => [
        'label_ar' => 'تذييل الفاتورة A4 (عربي)',
        'label_en' => 'A4 Invoice Footer (Arabic)',
        'type'     => 'textarea',
        'dir'      => 'rtl',
        'section'  => 'invoice',
    ],
    'invoice_footer_en' => [
        'label_ar' => 'تذييل الفاتورة A4 (إنجليزي)',
        'label_en' => 'A4 Invoice Footer (English)',
        'type'     => 'textarea',
        'dir'      => 'ltr',
        'section'  => 'invoice',
    ],
    'thermal_header_ar' => [
        'label_ar' => 'رأس الفاتورة الحرارية (عربي)',
        'label_en' => 'Thermal Header (Arabic)',
        'type'     => 'text',
        'maxlen'   => 100,
        'dir'      => 'rtl',
        'section'  => 'thermal',
    ],
    'thermal_header_en' => [
        'label_ar' => 'رأس الفاتورة الحرارية (إنجليزي)',
        'label_en' => 'Thermal Header (English)',
        'type'     => 'text',
        'maxlen'   => 100,
        'dir'      => 'ltr',
        'section'  => 'thermal',
    ],
    'thermal_footer_ar' => [
        'label_ar' => 'تذييل الفاتورة الحرارية (عربي)',
        'label_en' => 'Thermal Footer (Arabic)',
        'type'     => 'text',
        'maxlen'   => 100,
        'dir'      => 'rtl',
        'section'  => 'thermal',
    ],
    'thermal_footer_en' => [
        'label_ar' => 'تذييل الفاتورة الحرارية (إنجليزي)',
        'label_en' => 'Thermal Footer (English)',
        'type'     => 'text',
        'maxlen'   => 100,
        'dir'      => 'ltr',
        'section'  => 'thermal',
    ],
];

// ============================================================
// جلب القيم الحالية من جدول settings لهذا الفرع
// ============================================================
$existing   = [];
$set_stmt   = $pdo->prepare('SELECT setting_key, setting_value FROM settings WHERE branch_id = ?');
$set_stmt->execute([$target_branch_id]);
foreach ($set_stmt->fetchAll() as $row) {
    $existing[$row['setting_key']] = $row['setting_value'];
}

// ============================================================
// معالجة POST — حفظ الإعدادات
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submitted_token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($submitted_token)) {
        flash('error', $lang['csrf_invalid']);
        redirect('branch_settings.php' . ($is_super && $target_branch_id ? '?branch_id=' . $target_branch_id : ''));
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'save_settings') {

        // تحقق إضافي: branch_admin لا يستطيع تعديل إعدادات فرع آخر عبر POST
        if (!$is_super) {
            $posted_bid = sanitize_int($_POST['target_branch_id'] ?? 0);
            if ($posted_bid !== (int)$user_bid) {
                flash('error', $lang['unauthorized']);
                redirect('branch_settings.php');
            }
        }

        try {
            $old_values = $existing;
            $new_values = [];

            foreach ($settings_keys as $key => $meta) {
                $raw_value = $_POST[$key] ?? '';
                $value     = trim((string)$raw_value);

                // UPSERT: إدراج أو تحديث إذا وُجد مفتاح مكرر
                $upsert = $pdo->prepare("
                    INSERT INTO settings (setting_key, setting_value, branch_id)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
                ");
                $upsert->execute([$key, $value, $target_branch_id]);
                $existing[$key]   = $value;
                $new_values[$key] = $value;
            }

            // تحديث هاتف الفرع في جدول branches أيضاً (مصدر موحد)
            if (array_key_exists('branch_phone', $_POST)) {
                $phone = trim($_POST['branch_phone'] ?? '');
                $pdo->prepare('UPDATE branches SET phone = ? WHERE id = ? LIMIT 1')
                    ->execute([$phone, $target_branch_id]);
                $branch['phone'] = $phone; // تحديث المتغير المحلي للعرض
            }

            $audit->log(
                'update',
                'settings',
                (int)$target_branch_id,
                ['branch_id' => $target_branch_id, 'values' => $old_values],
                ['branch_id' => $target_branch_id, 'values' => $new_values]
            );

            regenerate_csrf_token();
            flash('success', $lang['saved']);

        } catch (Throwable $e) {
            error_log('[TopShine branch_settings save] ' . $e->getMessage());
            flash('error', $lang['error']);
        }

        redirect('branch_settings.php' . ($is_super && $target_branch_id ? '?branch_id=' . $target_branch_id : ''));
    }
}

// ============================================================
// قائمة الفروع للـ super_admin (لتبديل الفرع)
// ============================================================
$branches_list = [];
if ($is_super) {
    $branches_list = $pdo->query(
        "SELECT id, name, code FROM branches WHERE status = 'active' ORDER BY name ASC"
    )->fetchAll();
}

// ============================================================
// Helper: قيمة الإعداد مع Fallback
// ============================================================
$setting_val = function (string $key) use ($existing, $branch): string {
    if (isset($existing[$key])) {
        return $existing[$key];
    }
    // fallback لهاتف الفرع من جدول branches
    if ($key === 'branch_phone') {
        return $branch['phone'] ?? '';
    }
    return '';
};

$depth       = 1;
$assets_path = '../assets/';
?>

<?php require_once __DIR__ . '/../includes/layout/sidebar.php'; ?>

<div class="main-content">

    <?php show_flash(); ?>

    <!-- ========================================================= -->
    <!-- رأس الصفحة                                                 -->
    <!-- ========================================================= -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h4 class="mb-0" style="color:var(--ts-gold)">
                <i class="bi bi-gear me-2"></i>
                <?= $lang['branch_settings'] ?>
            </h4>
            <small class="text-muted">
                <?= htmlspecialchars($branch['name']) ?>
                <span class="font-monospace ms-1">(<?= htmlspecialchars($branch['code']) ?>)</span>
            </small>
        </div>

        <!-- Super Admin: تبديل الفرع -->
        <?php if ($is_super && count($branches_list) > 1): ?>
        <form method="GET" class="d-flex align-items-center gap-2">
            <label class="form-label mb-0 small text-muted">
                <?= $is_rtl ? 'الفرع:' : 'Branch:' ?>
            </label>
            <select name="branch_id" class="form-select form-select-sm ts-select"
                    style="width:220px" onchange="this.form.submit()">
                <?php foreach ($branches_list as $br): ?>
                <option value="<?= $br['id'] ?>"
                        <?= (int)$target_branch_id === (int)$br['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($br['name']) ?>
                    (<?= htmlspecialchars($br['code']) ?>)
                </option>
                <?php endforeach; ?>
            </select>
        </form>
        <?php endif; ?>
    </div>

    <div class="row g-4">

        <!-- ===================================================== -->
        <!-- العمود الأيسر: معلومات الفرع + تنبيه الصلاحيات        -->
        <!-- ===================================================== -->
        <div class="col-lg-4">

            <!-- بطاقة معلومات الفرع -->
            <div class="card mb-3">
                <div class="card-header">
                    <i class="bi bi-building me-1"></i>
                    <?= $is_rtl ? 'معلومات الفرع' : 'Branch Info' ?>
                </div>
                <div class="card-body">
                    <dl class="row small mb-0">
                        <dt class="col-5" style="color:var(--ts-text-secondary)">
                            <?= $is_rtl ? 'الاسم' : 'Name' ?>
                        </dt>
                        <dd class="col-7 fw-semibold">
                            <?= htmlspecialchars($branch['name']) ?>
                        </dd>

                        <dt class="col-5" style="color:var(--ts-text-secondary)">
                            <?= $is_rtl ? 'الرمز' : 'Code' ?>
                        </dt>
                        <dd class="col-7">
                            <code style="color:var(--ts-gold);background:var(--ts-bg-dark);
                                         padding:.15rem .4rem;border-radius:3px;font-size:.85rem">
                                <?= htmlspecialchars($branch['code']) ?>
                            </code>
                        </dd>

                        <?php if ($branch['address']): ?>
                        <dt class="col-5" style="color:var(--ts-text-secondary)">
                            <?= $is_rtl ? 'العنوان' : 'Address' ?>
                        </dt>
                        <dd class="col-7">
                            <?= htmlspecialchars($branch['address']) ?>
                        </dd>
                        <?php endif; ?>

                        <dt class="col-5" style="color:var(--ts-text-secondary)">
                            <?= $is_rtl ? 'الهاتف' : 'Phone' ?>
                        </dt>
                        <dd class="col-7 font-monospace">
                            <?= htmlspecialchars($branch['phone'] ?: '—') ?>
                        </dd>

                        <dt class="col-5" style="color:var(--ts-text-secondary)">
                            <?= $lang['status'] ?>
                        </dt>
                        <dd class="col-7">
                            <span class="badge bg-<?= $branch['status'] === 'active' ? 'success' : 'secondary' ?>">
                                <?= $branch['status'] === 'active' ? $lang['active'] : $lang['inactive'] ?>
                            </span>
                        </dd>
                    </dl>
                </div>
            </div>

            <!-- تنبيه الصلاحيات -->
            <div class="card" style="border-color:var(--ts-border-gold)">
                <div class="card-body py-3">
                    <div class="d-flex gap-2">
                        <i class="bi bi-shield-check mt-1 flex-shrink-0"
                           style="color:var(--ts-gold)"></i>
                        <small style="color:var(--ts-text-secondary);line-height:1.6">
                            <?php if ($is_super): ?>
                                <?= $is_rtl
                                    ? 'بصفتك Super Admin يمكنك تعديل إعدادات أي فرع. اسم المتجر والشعار العام يُديَران من إعدادات النظام.'
                                    : 'As Super Admin you can edit any branch settings. Store name and global logo are managed from System Settings.' ?>
                            <?php else: ?>
                                <?= $is_rtl
                                    ? 'يمكنك تعديل إعدادات فرعك فقط: رقم الهاتف ونصوص الفاتورة. اسم المتجر والشعار يُديرهما مدير النظام.'
                                    : 'You can only edit your own branch settings: phone number and invoice texts. Store name and logo are managed by the System Admin.' ?>
                            <?php endif; ?>
                        </small>
                    </div>
                </div>
            </div>

            <!-- معاينة الفاتورة الحرارية -->
            <div class="card mt-3">
                <div class="card-header">
                    <i class="bi bi-eye me-1"></i>
                    <?= $is_rtl ? 'معاينة حرارية' : 'Thermal Preview' ?>
                </div>
                <div class="card-body d-flex justify-content-center">
                    <div id="thermal_preview"
                         style="
                             background:#fff; color:#000;
                             width:230px; padding:8px 6px;
                             font-family:'Courier New',monospace; font-size:11px;
                             border:1px dashed #ccc; border-radius:4px;
                             line-height:1.7; text-align:center;">
                        <div id="prev_header_ar"
                             style="font-size:14px;font-weight:bold;direction:rtl">
                            <?= htmlspecialchars($setting_val('thermal_header_ar') ?: 'توب شاين') ?>
                        </div>
                        <div style="letter-spacing:1px">================================</div>
                        <div style="text-align:right;font-size:10px;direction:rtl">
                            <?= $is_rtl ? 'الفرع:' : 'Branch:' ?>
                            <?= htmlspecialchars($branch['name']) ?><br>
                            <?= $is_rtl ? 'التاريخ:' : 'Date:' ?>
                            <?= date('Y-m-d H:i') ?>
                        </div>
                        <div style="letter-spacing:1px">================================</div>
                        <div style="text-align:right;font-size:10px;direction:rtl">
                            <?= $is_rtl ? 'منتج تجريبي' : 'Sample Product' ?><br>
                            &nbsp;&nbsp;2 × 150.00 = 300.00 SDG
                        </div>
                        <div style="letter-spacing:1px">================================</div>
                        <div style="text-align:<?= $is_rtl ? 'right' : 'left' ?>;font-size:10px;direction:rtl">
                            <?= $is_rtl ? 'الإجمالي:' : 'Total:' ?> &nbsp;300.00 SDG<br>
                            <?= $is_rtl ? 'المدفوع:' : 'Paid:' ?> &nbsp;&nbsp;300.00 SDG
                        </div>
                        <div style="letter-spacing:1px">================================</div>
                        <div id="prev_footer_ar"
                             style="font-size:10px;direction:rtl">
                            <?= htmlspecialchars($setting_val('thermal_footer_ar') ?: 'شكراً لزيارتكم') ?>
                        </div>
                    </div>
                </div>
            </div>

        </div>

        <!-- ===================================================== -->
        <!-- العمود الأيمن: نموذج الإعدادات                        -->
        <!-- ===================================================== -->
        <div class="col-lg-8">
            <form method="POST" id="settings_form">
                <input type="hidden" name="action"           value="save_settings">
                <input type="hidden" name="csrf_token"       value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="target_branch_id" value="<?= (int)$target_branch_id ?>">

                <!-- ——— قسم بيانات الاتصال ——— -->
                <div class="card mb-4">
                    <div class="card-header">
                        <i class="bi bi-telephone me-1"></i>
                        <?= $is_rtl ? 'بيانات الاتصال' : 'Contact Info' ?>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label">
                                <?= $is_rtl ? 'هاتف الفرع' : 'Branch Phone' ?>
                            </label>
                            <input type="tel" name="branch_phone"
                                   class="form-control ts-input" maxlength="20" dir="ltr"
                                   value="<?= htmlspecialchars($setting_val('branch_phone')) ?>"
                                   placeholder="09xxxxxxxxx">
                            <div class="form-text text-muted">
                                <?= $is_rtl
                                    ? 'يظهر على الفواتير المطبوعة.'
                                    : 'Appears on printed invoices.' ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ——— قسم تذييل الفاتورة A4 ——— -->
                <div class="card mb-4">
                    <div class="card-header">
                        <i class="bi bi-file-text me-1"></i>
                        <?= $is_rtl ? 'تذييل الفاتورة A4' : 'A4 Invoice Footer' ?>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label">
                                <?= $is_rtl ? 'النص العربي' : 'Arabic Text' ?>
                            </label>
                            <textarea name="invoice_footer_ar"
                                      class="form-control ts-input" rows="2" dir="rtl"
                                      placeholder="<?= $is_rtl
                                          ? 'مثال: شكراً لتعاملكم — توب شاين للتجميل'
                                          : 'e.g. Thank you for your purchase — Top Shine' ?>"><?= htmlspecialchars($setting_val('invoice_footer_ar')) ?></textarea>
                        </div>
                        <div class="mb-0">
                            <label class="form-label">
                                <?= $is_rtl ? 'النص الإنجليزي' : 'English Text' ?>
                            </label>
                            <textarea name="invoice_footer_en"
                                      class="form-control ts-input" rows="2" dir="ltr"
                                      placeholder="e.g. Thank you for shopping with Top Shine Beauty"><?= htmlspecialchars($setting_val('invoice_footer_en')) ?></textarea>
                        </div>
                    </div>
                </div>

                <!-- ——— قسم الفاتورة الحرارية Thermal ——— -->
                <div class="card mb-4">
                    <div class="card-header">
                        <i class="bi bi-printer me-1"></i>
                        <?= $is_rtl ? 'الفاتورة الحرارية (Thermal 80mm)' : 'Thermal Receipt (80mm)' ?>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">

                            <!-- رأس الفاتورة -->
                            <div class="col-12">
                                <div class="small fw-semibold mb-2" style="color:var(--ts-silver)">
                                    <i class="bi bi-chevron-up me-1"></i>
                                    <?= $is_rtl ? 'رأس الفاتورة' : 'Receipt Header' ?>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small">
                                    <?= $is_rtl ? 'عربي' : 'Arabic' ?>
                                </label>
                                <input type="text" name="thermal_header_ar"
                                       id="inp_thermal_header_ar"
                                       class="form-control ts-input" maxlength="100" dir="rtl"
                                       value="<?= htmlspecialchars($setting_val('thermal_header_ar')) ?>"
                                       placeholder="<?= $is_rtl
                                           ? 'توب شاين — جمالكِ يبدأ من هنا'
                                           : 'Top Shine — Your Beauty Starts Here' ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small">
                                    <?= $is_rtl ? 'إنجليزي' : 'English' ?>
                                </label>
                                <input type="text" name="thermal_header_en"
                                       class="form-control ts-input" maxlength="100" dir="ltr"
                                       value="<?= htmlspecialchars($setting_val('thermal_header_en')) ?>"
                                       placeholder="Top Shine — Your Beauty Starts Here">
                            </div>

                            <div class="col-12">
                                <hr style="border-color:var(--ts-border);margin:.5rem 0">
                                <div class="small fw-semibold mb-2" style="color:var(--ts-silver)">
                                    <i class="bi bi-chevron-down me-1"></i>
                                    <?= $is_rtl ? 'تذييل الفاتورة' : 'Receipt Footer' ?>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small">
                                    <?= $is_rtl ? 'عربي' : 'Arabic' ?>
                                </label>
                                <input type="text" name="thermal_footer_ar"
                                       id="inp_thermal_footer_ar"
                                       class="form-control ts-input" maxlength="100" dir="rtl"
                                       value="<?= htmlspecialchars($setting_val('thermal_footer_ar')) ?>"
                                       placeholder="<?= $is_rtl ? 'شكراً لزيارتكم' : 'Thank you for your visit' ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small">
                                    <?= $is_rtl ? 'إنجليزي' : 'English' ?>
                                </label>
                                <input type="text" name="thermal_footer_en"
                                       class="form-control ts-input" maxlength="100" dir="ltr"
                                       value="<?= htmlspecialchars($setting_val('thermal_footer_en')) ?>"
                                       placeholder="Thank you for your visit">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ——— أزرار الحفظ ——— -->
                <div class="d-flex justify-content-between align-items-center">
                    <a href="<?= $is_super
                        ? '../superadmin/branches.php'
                        : 'dashboard.php' ?>"
                       class="btn btn-secondary">
                        <i class="bi bi-arrow-<?= $is_rtl ? 'right' : 'left' ?> me-1"></i>
                        <?= $lang['cancel'] ?>
                    </a>
                    <button type="submit" class="btn btn-primary btn-lg">
                        <i class="bi bi-check-lg me-2"></i>
                        <?= $lang['save'] ?>
                    </button>
                </div>

            </form>
        </div>
    </div>
</div><!-- /.main-content -->

<!-- ============================================================ -->
<!-- JavaScript: تحديث المعاينة الحرارية لحظياً                   -->
<!-- ============================================================ -->
<script>
(function () {
    'use strict';

    var fields = {
        thermal_header_ar : 'prev_header_ar',
        thermal_footer_ar : 'prev_footer_ar',
    };

    Object.keys(fields).forEach(function (name) {
        var inp  = document.querySelector('[name="' + name + '"]');
        var prev = document.getElementById(fields[name]);
        if (!inp || !prev) return;

        inp.addEventListener('input', function () {
            var fallback = name.indexOf('header') !== -1 ? 'توب شاين' : 'شكراً لزيارتكم';
            prev.textContent = this.value.trim() || fallback;
        });
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/layout/footer.php'; ?>
