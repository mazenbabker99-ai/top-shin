<?php
// ============================================================
// المسار: admin/branch_settings.php
// الوظيفة: إعدادات الفرع — branch_admin يُعدّل فرعه فقط
// الصلاحية: branch_admin | super_admin
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

// super_admin: يختار الفرع — branch_admin: فرعه فقط
$target_branch_id = $is_super
    ? (sanitize_int($_GET['branch_id'] ?? 0) ?: $user_bid)
    : $user_bid;

if ($target_branch_id === null && !$is_super) {
    flash('error', $lang['unauthorized']);
    redirect('dashboard.php');
}

// ============================================================
// جلب بيانات الفرع
// ============================================================
$branch_stmt = $pdo->prepare(
    "SELECT * FROM branches WHERE id = ? LIMIT 1"
);
$branch_stmt->execute([$target_branch_id]);
$branch = $branch_stmt->fetch();

if (!$branch) {
    flash('error', $lang['not_found']);
    redirect($is_super ? '../superadmin/branches.php' : 'dashboard.php');
}

// ============================================================
// حقول الإعدادات المسموح بتعديلها حسب الدور
// ============================================================
// branch_admin: هاتف الفرع + تذييل الفاتورة (عربي/إنجليزي)
// super_admin: الأعلاه + اسم الفرع في الإعدادات + الشعار (في صفحة أخرى)

$settings_keys = [
    'branch_phone'        => ['label_ar' => 'هاتف الفرع',                   'label_en' => 'Branch Phone',              'type' => 'text'],
    'invoice_footer_ar'   => ['label_ar' => 'تذييل الفاتورة (عربي)',         'label_en' => 'Invoice Footer (Arabic)',   'type' => 'textarea'],
    'invoice_footer_en'   => ['label_ar' => 'تذييل الفاتورة (إنجليزي)',      'label_en' => 'Invoice Footer (English)',  'type' => 'textarea'],
    'thermal_header_ar'   => ['label_ar' => 'رأس الفاتورة الحرارية (عربي)',  'label_en' => 'Thermal Header (Arabic)',   'type' => 'text'],
    'thermal_header_en'   => ['label_ar' => 'رأس الفاتورة الحرارية (إنجليزي)', 'label_en' => 'Thermal Header (English)', 'type' => 'text'],
    'thermal_footer_ar'   => ['label_ar' => 'تذييل الفاتورة الحرارية (عربي)', 'label_en' => 'Thermal Footer (Arabic)',  'type' => 'text'],
    'thermal_footer_en'   => ['label_ar' => 'تذييل الفاتورة الحرارية (إنجليزي)', 'label_en' => 'Thermal Footer (English)', 'type' => 'text'],
];

// جلب القيم الحالية من settings لهذا الفرع
$existing = [];
$set_stmt = $pdo->prepare(
    "SELECT setting_key, setting_value FROM settings WHERE branch_id = ?"
);
$set_stmt->execute([$target_branch_id]);
foreach ($set_stmt->fetchAll() as $row) {
    $existing[$row['setting_key']] = $row['setting_value'];
}

// ============================================================
// معالجة POST
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submitted_token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($submitted_token)) {
        flash('error', $lang['csrf_invalid']);
        redirect('branch_settings.php' . ($is_super ? '?branch_id=' . $target_branch_id : ''));
    }

    $action = $_POST['action'] ?? '';

    // ——— حفظ إعدادات الفرع ———
    if ($action === 'save_settings') {
        try {
            $old_values = $existing; // للـ audit

            foreach ($settings_keys as $key => $meta) {
                $value = trim($_POST[$key] ?? '');

                // UPSERT: تحديث إن وجد، إدراج إن لم يوجد
                $upsert = $pdo->prepare("
                    INSERT INTO settings (setting_key, setting_value, branch_id)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
                ");
                $upsert->execute([$key, $value, $target_branch_id]);
                $existing[$key] = $value;
            }

            // تحديث هاتف الفرع في جدول branches أيضاً
            if (isset($_POST['branch_phone'])) {
                $phone = trim($_POST['branch_phone'] ?? '');
                $pdo->prepare("UPDATE branches SET phone = ? WHERE id = ?")
                    ->execute([$phone, $target_branch_id]);
            }

            $audit->log('update', 'settings', $target_branch_id,
                ['branch_id' => $target_branch_id, 'old' => json_encode($old_values)],
                ['branch_id' => $target_branch_id, 'new' => json_encode($existing)]
            );
            regenerate_csrf_token();
            flash('success', $lang['saved']);
        } catch (Throwable $e) {
            error_log('[TopShine branch_settings] ' . $e->getMessage());
            flash('error', $lang['error']);
        }
        redirect('branch_settings.php' . ($is_super ? '?branch_id=' . $target_branch_id : ''));
    }
}

// قائمة الفروع للـ super_admin
$branches_list = [];
if ($is_super) {
    $branches_list = $pdo->query(
        "SELECT id, name, code FROM branches WHERE status='active' ORDER BY name"
    )->fetchAll();
}

$depth       = 1;
$assets_path = '../assets';
?>

<?php require_once __DIR__ . '/../includes/layout/sidebar.php'; ?>

<div class="main-content">

    <?php show_flash(); ?>

    <!-- رأس الصفحة -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <h4 class="mb-0" style="color:var(--ts-gold)">
            <i class="bi bi-gear me-2"></i>
            <?= $lang['branch_settings'] ?>
            <span class="badge ms-2"
                  style="background:var(--ts-bg-dark);color:var(--ts-gold);border:1px solid var(--ts-border-gold);font-size:.75rem">
                <?= htmlspecialchars($branch['name']) ?>
                (<?= htmlspecialchars($branch['code']) ?>)
            </span>
        </h4>

        <!-- Super Admin: تبديل الفرع -->
        <?php if ($is_super && count($branches_list) > 1): ?>
        <form method="GET" class="d-flex align-items-center gap-2">
            <select name="branch_id" class="form-select form-select-sm ts-select" style="width:200px"
                    onchange="this.form.submit()">
                <?php foreach ($branches_list as $br): ?>
                <option value="<?= $br['id'] ?>" <?= $target_branch_id == $br['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($br['name']) ?> (<?= $br['code'] ?>)
                </option>
                <?php endforeach; ?>
            </select>
        </form>
        <?php endif; ?>
    </div>

    <div class="row g-4">

        <!-- ========== بطاقة معلومات الفرع ========== -->
        <div class="col-lg-4">
            <div class="card mb-3">
                <div class="card-header">
                    <i class="bi bi-building me-1"></i>
                    <?= $is_rtl ? 'معلومات الفرع' : 'Branch Info' ?>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <div class="small text-muted mb-1"><?= $is_rtl ? 'الاسم' : 'Name' ?></div>
                        <div class="fw-semibold"><?= htmlspecialchars($branch['name']) ?></div>
                    </div>
                    <div class="mb-3">
                        <div class="small text-muted mb-1"><?= $is_rtl ? 'الرمز' : 'Code' ?></div>
                        <code style="color:var(--ts-gold);background:var(--ts-bg-dark);padding:.2rem .5rem;border-radius:3px">
                            <?= htmlspecialchars($branch['code']) ?>
                        </code>
                    </div>
                    <div class="mb-3">
                        <div class="small text-muted mb-1"><?= $is_rtl ? 'العنوان' : 'Address' ?></div>
                        <div><?= htmlspecialchars($branch['address'] ?: '—') ?></div>
                    </div>
                    <div class="mb-3">
                        <div class="small text-muted mb-1"><?= $is_rtl ? 'الهاتف' : 'Phone' ?></div>
                        <div><?= htmlspecialchars($branch['phone'] ?: '—') ?></div>
                    </div>
                    <div>
                        <div class="small text-muted mb-1"><?= $lang['status'] ?></div>
                        <?php if ($branch['status'] === 'active'): ?>
                            <span class="badge bg-success"><?= $lang['active'] ?></span>
                        <?php else: ?>
                            <span class="badge bg-danger"><?= $lang['inactive'] ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- ملاحظة الصلاحيات -->
            <div class="card" style="border-color:var(--ts-border-gold)">
                <div class="card-body">
                    <div class="d-flex gap-2">
                        <i class="bi bi-info-circle text-warning mt-1 flex-shrink-0"></i>
                        <small style="color:var(--ts-text-secondary)">
                            <?php if ($is_super): ?>
                                <?= $is_rtl
                                    ? 'بصفتك Super Admin يمكنك تعديل إعدادات أي فرع. لتعديل الاسم والشعار العام انتقل إلى إعدادات المتجر.'
                                    : 'As Super Admin you can edit any branch settings. To change the store name or logo go to Store Settings.' ?>
                            <?php else: ?>
                                <?= $is_rtl
                                    ? 'يمكنك تعديل إعدادات فرعك فقط. اسم المتجر والشعار يُديرهما Super Admin.'
                                    : 'You can only edit your branch settings. Store name and logo are managed by Super Admin.' ?>
                            <?php endif; ?>
                        </small>
                    </div>
                </div>
            </div>
        </div>

        <!-- ========== نموذج الإعدادات ========== -->
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-sliders me-1"></i>
                    <?= $is_rtl ? 'إعدادات الفاتورة والاتصال' : 'Invoice & Contact Settings' ?>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action"     value="save_settings">
                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">

                        <!-- هاتف الفرع -->
                        <div class="mb-4">
                            <h6 class="mb-3" style="color:var(--ts-gold)">
                                <i class="bi bi-telephone me-1"></i>
                                <?= $is_rtl ? 'بيانات الاتصال' : 'Contact Info' ?>
                            </h6>
                            <div class="mb-3">
                                <label class="form-label">
                                    <?= $is_rtl ? 'هاتف الفرع' : 'Branch Phone' ?>
                                </label>
                                <input type="text" name="branch_phone"
                                       class="form-control ts-input" maxlength="20"
                                       value="<?= htmlspecialchars($existing['branch_phone'] ?? $branch['phone'] ?? '') ?>"
                                       placeholder="09xxxxxxxx">
                            </div>
                        </div>

                        <hr style="border-color:var(--ts-border)">

                        <!-- تذييل الفاتورة A4 -->
                        <div class="mb-4">
                            <h6 class="mb-3" style="color:var(--ts-gold)">
                                <i class="bi bi-file-text me-1"></i>
                                <?= $is_rtl ? 'تذييل الفاتورة A4' : 'A4 Invoice Footer' ?>
                            </h6>
                            <div class="mb-3">
                                <label class="form-label small">
                                    <?= $is_rtl ? 'عربي' : 'Arabic' ?>
                                </label>
                                <textarea name="invoice_footer_ar"
                                          class="form-control ts-input" rows="2"
                                          placeholder="<?= $is_rtl ? 'مثال: شكراً لتعاملكم معنا — توب شاين' : 'e.g. Thank you for shopping with us' ?>"
                                          dir="rtl"><?= htmlspecialchars($existing['invoice_footer_ar'] ?? '') ?></textarea>
                            </div>
                            <div class="mb-3">
                                <label class="form-label small">
                                    <?= $is_rtl ? 'إنجليزي' : 'English' ?>
                                </label>
                                <textarea name="invoice_footer_en"
                                          class="form-control ts-input" rows="2"
                                          placeholder="e.g. Thank you for shopping with Top Shine"
                                          dir="ltr"><?= htmlspecialchars($existing['invoice_footer_en'] ?? '') ?></textarea>
                            </div>
                        </div>

                        <hr style="border-color:var(--ts-border)">

                        <!-- رأس وتذييل الفاتورة الحرارية -->
                        <div class="mb-4">
                            <h6 class="mb-3" style="color:var(--ts-gold)">
                                <i class="bi bi-printer me-1"></i>
                                <?= $is_rtl ? 'الفاتورة الحرارية (Thermal 80mm)' : 'Thermal Receipt (80mm)' ?>
                            </h6>

                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label small">
                                        <?= $is_rtl ? 'رأس الفاتورة (عربي)' : 'Header (Arabic)' ?>
                                    </label>
                                    <input type="text" name="thermal_header_ar"
                                           class="form-control ts-input" maxlength="100"
                                           value="<?= htmlspecialchars($existing['thermal_header_ar'] ?? '') ?>"
                                           dir="rtl"
                                           placeholder="<?= $is_rtl ? 'توب شاين — جمالكِ يبدأ من هنا' : 'Top Shine — Your Beauty Starts Here' ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small">
                                        <?= $is_rtl ? 'رأس الفاتورة (إنجليزي)' : 'Header (English)' ?>
                                    </label>
                                    <input type="text" name="thermal_header_en"
                                           class="form-control ts-input" maxlength="100"
                                           value="<?= htmlspecialchars($existing['thermal_header_en'] ?? '') ?>"
                                           dir="ltr"
                                           placeholder="Top Shine — Your Beauty Starts Here">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small">
                                        <?= $is_rtl ? 'تذييل الفاتورة (عربي)' : 'Footer (Arabic)' ?>
                                    </label>
                                    <input type="text" name="thermal_footer_ar"
                                           class="form-control ts-input" maxlength="100"
                                           value="<?= htmlspecialchars($existing['thermal_footer_ar'] ?? '') ?>"
                                           dir="rtl"
                                           placeholder="<?= $is_rtl ? 'شكراً لزيارتكم' : 'Thank you for your visit' ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small">
                                        <?= $is_rtl ? 'تذييل الفاتورة (إنجليزي)' : 'Footer (English)' ?>
                                    </label>
                                    <input type="text" name="thermal_footer_en"
                                           class="form-control ts-input" maxlength="100"
                                           value="<?= htmlspecialchars($existing['thermal_footer_en'] ?? '') ?>"
                                           dir="ltr"
                                           placeholder="Thank you for your visit">
                                </div>
                            </div>
                        </div>

                        <!-- معاينة الفاتورة الحرارية -->
                        <div class="mb-4">
                            <h6 class="mb-2" style="color:var(--ts-silver)">
                                <i class="bi bi-eye me-1"></i>
                                <?= $is_rtl ? 'معاينة الفاتورة الحرارية' : 'Thermal Preview' ?>
                            </h6>
                            <div id="thermal_preview"
                                 style="
                                    background:#fff; color:#000;
                                    width:230px; padding:8px;
                                    font-family:'Courier New',monospace; font-size:11px;
                                    border:1px dashed var(--ts-border); border-radius:4px;
                                    line-height:1.6; direction:rtl; text-align:center;">
                                <div id="prev_header" style="font-size:14px;font-weight:bold">
                                    <?= htmlspecialchars($existing['thermal_header_ar'] ?? 'توب شاين') ?>
                                </div>
                                <div>================================</div>
                                <div style="text-align:right;font-size:10px">
                                    الفرع: <?= htmlspecialchars($branch['name']) ?><br>
                                    التاريخ: <?= date('Y-m-d H:i') ?>
                                </div>
                                <div>================================</div>
                                <div style="text-align:right;font-size:10px">
                                    منتج تجريبي<br>
                                    &nbsp;&nbsp;2 × 150.00 = 300.00 SDG
                                </div>
                                <div>================================</div>
                                <div style="text-align:left;font-size:10px">
                                    الإجمالي: &nbsp;&nbsp;300.00 SDG<br>
                                    المدفوع: &nbsp;&nbsp;&nbsp;300.00 SDG
                                </div>
                                <div>================================</div>
                                <div id="prev_footer" style="font-size:10px">
                                    <?= htmlspecialchars($existing['thermal_footer_ar'] ?? 'شكراً لزيارتكم') ?>
                                </div>
                            </div>
                        </div>

                        <!-- أزرار الحفظ -->
                        <div class="d-flex gap-2 justify-content-end">
                            <a href="<?= $is_super
                                ? '../superadmin/branches.php'
                                : 'dashboard.php' ?>"
                               class="btn btn-secondary">
                                <?= $lang['cancel'] ?>
                            </a>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-lg me-1"></i><?= $lang['save'] ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div><!-- /.main-content -->

<script>
// تحديث المعاينة لحظياً عند الكتابة
(function () {
    var headerAr = document.querySelector('[name="thermal_header_ar"]');
    var footerAr = document.querySelector('[name="thermal_footer_ar"]');
    var prevH    = document.getElementById('prev_header');
    var prevF    = document.getElementById('prev_footer');

    function esc(s){ return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

    if (headerAr && prevH) {
        headerAr.addEventListener('input', function() {
            prevH.textContent = this.value || 'توب شاين';
        });
    }
    if (footerAr && prevF) {
        footerAr.addEventListener('input', function() {
            prevF.textContent = this.value || 'شكراً لزيارتكم';
        });
    }
})();
</script>

<style>
.main-content { padding: 1.5rem; }
.ts-input  { background:var(--ts-bg-input)  !important; border-color:var(--ts-border) !important; color:var(--ts-text-primary) !important; }
.ts-select { background:var(--ts-bg-input)  !important; border-color:var(--ts-border) !important; color:var(--ts-text-primary) !important; }
.ts-input:focus, .ts-select:focus {
    border-color:var(--ts-gold) !important;
    box-shadow:0 0 0 .2rem rgba(201,168,76,.25) !important;
}
</style>

<?php require_once __DIR__ . '/../includes/layout/footer.php'; ?>
