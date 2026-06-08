<?php
// ============================================================
// المسار: superadmin/branches.php
// الوظيفة: إدارة الفروع — عرض + إضافة + تعديل + تعطيل
// الصلاحية: super_admin فقط
// ============================================================

declare(strict_types=1);

$current_page = 'branches';
require_once __DIR__ . '/../includes/layout/header.php';
require_role(['super_admin']);

$pdo   = db();
$audit = new AuditLogger($pdo);

// ============================================================
// معالجة POST
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submitted_token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($submitted_token)) {
        flash('error', $lang['csrf_invalid']);
        redirect('branches.php');
    }

    $action = $_POST['action'] ?? '';

    // ——— إضافة فرع ———
    if ($action === 'add_branch') {
        $name    = trim($_POST['name']    ?? '');
        $code    = strtoupper(trim($_POST['code'] ?? ''));
        $address = trim($_POST['address'] ?? '');
        $phone   = trim($_POST['phone']   ?? '');

        if ($name === '' || $code === '') {
            flash('error', $lang['required_field']);
            redirect('branches.php');
        }

        // تحقق من عدم تكرار الرمز
        $dup = $pdo->prepare("SELECT id FROM branches WHERE code = ? LIMIT 1");
        $dup->execute([$code]);
        if ($dup->fetch()) {
            flash('error', $lang['duplicate_code']);
            redirect('branches.php');
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO branches (name, code, address, phone, status, created_at)
                VALUES (?, ?, ?, ?, 'active', NOW())
            ");
            $stmt->execute([$name, $code, $address, $phone]);
            $new_id = (int)$pdo->lastInsertId();

            $audit->log('create', 'branches', $new_id, null, [
                'name' => $name, 'code' => $code,
            ]);
            regenerate_csrf_token();
            flash('success', $lang['saved']);
        } catch (Throwable $e) {
            error_log('[TopShine branches add] ' . $e->getMessage());
            flash('error', $lang['error']);
        }
        redirect('branches.php');
    }

    // ——— تعديل فرع ———
    if ($action === 'edit_branch') {
        $branch_id = sanitize_int($_POST['branch_id'] ?? 0);
        $name      = trim($_POST['name']    ?? '');
        $address   = trim($_POST['address'] ?? '');
        $phone     = trim($_POST['phone']   ?? '');

        if ($branch_id <= 0 || $name === '') {
            flash('error', $lang['required_field']);
            redirect('branches.php');
        }

        try {
            // جلب القديم للـ audit
            $old = $pdo->prepare("SELECT * FROM branches WHERE id = ? LIMIT 1");
            $old->execute([$branch_id]);
            $old_data = $old->fetch() ?: [];

            $stmt = $pdo->prepare("
                UPDATE branches SET name=?, address=?, phone=? WHERE id=?
            ");
            $stmt->execute([$name, $address, $phone, $branch_id]);

            $audit->log('update', 'branches', $branch_id,
                ['name' => $old_data['name'] ?? ''],
                ['name' => $name]
            );
            regenerate_csrf_token();
            flash('success', $lang['updated']);
        } catch (Throwable $e) {
            error_log('[TopShine branches edit] ' . $e->getMessage());
            flash('error', $lang['error']);
        }
        redirect('branches.php');
    }

    // ——— تعطيل / تفعيل فرع ———
    if ($action === 'toggle_branch') {
        $branch_id  = sanitize_int($_POST['branch_id'] ?? 0);
        $new_status = $_POST['new_status'] ?? 'inactive';
        $new_status = in_array($new_status, ['active', 'inactive'], true) ? $new_status : 'inactive';

        if ($branch_id <= 0) {
            flash('error', $lang['invalid_input']);
            redirect('branches.php');
        }

        // تحقق: إذا تعطيل — هل يوجد مستخدمون نشطون؟
        if ($new_status === 'inactive') {
            $active_users = $pdo->prepare(
                "SELECT COUNT(*) FROM users WHERE branch_id = ? AND status = 'active'"
            );
            $active_users->execute([$branch_id]);
            if ((int)$active_users->fetchColumn() > 0) {
                flash('error', $is_rtl
                    ? 'لا يمكن تعطيل الفرع — يوجد مستخدمون نشطون مرتبطون به.'
                    : 'Cannot deactivate branch — active users are assigned to it.');
                redirect('branches.php');
            }
        }

        try {
            $pdo->prepare("UPDATE branches SET status=? WHERE id=?")
                ->execute([$new_status, $branch_id]);

            $audit->log(
                $new_status === 'active' ? 'update' : 'delete',
                'branches', $branch_id,
                ['status' => $new_status === 'active' ? 'inactive' : 'active'],
                ['status' => $new_status]
            );
            regenerate_csrf_token();
            flash('success', $new_status === 'active' ? $lang['activated'] : $lang['deactivated']);
        } catch (Throwable $e) {
            error_log('[TopShine branches toggle] ' . $e->getMessage());
            flash('error', $lang['error']);
        }
        redirect('branches.php');
    }
}

// ============================================================
// جلب الفروع مع إحصاءات مختصرة
// ============================================================
$branches = $pdo->query("
    SELECT
        b.*,
        (SELECT COUNT(*) FROM users u
         WHERE u.branch_id = b.id AND u.status = 'active') AS active_users,
        (SELECT COUNT(*) FROM invoices i
         WHERE i.branch_id = b.id AND DATE(i.created_at) = CURDATE()) AS today_invoices,
        (SELECT COALESCE(SUM(i.total),0) FROM invoices i
         WHERE i.branch_id = b.id AND DATE(i.created_at) = CURDATE()) AS today_revenue
    FROM branches b
    ORDER BY b.status DESC, b.created_at ASC
")->fetchAll();

$depth       = 1;
$assets_path = '../assets';
?>

<?php require_once __DIR__ . '/../includes/layout/sidebar.php'; ?>

<div class="main-content">

    <?php show_flash(); ?>

    <!-- رأس الصفحة -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <h4 class="mb-0" style="color:var(--ts-gold)">
            <i class="bi bi-diagram-3 me-2"></i>
            <?= $lang['branches'] ?>
        </h4>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addBranchModal">
            <i class="bi bi-plus-lg me-1"></i>
            <?= $is_rtl ? 'إضافة فرع' : 'Add Branch' ?>
        </button>
    </div>

    <!-- ================================================== -->
    <!-- جدول الفروع                                         -->
    <!-- ================================================== -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>
                <i class="bi bi-list-ul me-1"></i>
                <?= $is_rtl ? 'قائمة الفروع' : 'Branches List' ?>
            </span>
            <small class="text-muted"><?= count($branches) ?> <?= $lang['results'] ?></small>
        </div>
        <div class="card-body p-0">
            <?php if (empty($branches)): ?>
                <div class="text-center py-5 text-muted">
                    <i class="bi bi-diagram-3 fs-1 d-block mb-2"></i>
                    <?= $lang['no_results'] ?>
                </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th><?= $is_rtl ? 'الفرع' : 'Branch' ?></th>
                            <th><?= $is_rtl ? 'الرمز' : 'Code' ?></th>
                            <th><?= $is_rtl ? 'العنوان' : 'Address' ?></th>
                            <th><?= $is_rtl ? 'الهاتف' : 'Phone' ?></th>
                            <th class="text-center"><?= $is_rtl ? 'مستخدمون' : 'Users' ?></th>
                            <th class="text-center"><?= $is_rtl ? 'فواتير اليوم' : 'Today Invoices' ?></th>
                            <th class="text-center"><?= $is_rtl ? 'مبيعات اليوم' : 'Today Revenue' ?></th>
                            <th class="text-center"><?= $lang['status'] ?></th>
                            <th class="text-center"><?= $lang['action'] ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($branches as $br): ?>
                        <tr>
                            <td>
                                <div class="fw-semibold" style="color:var(--ts-text-primary)">
                                    <?= htmlspecialchars($br['name']) ?>
                                </div>
                                <small class="text-muted">
                                    <?= $is_rtl ? 'أُنشئ:' : 'Created:' ?>
                                    <?= date('Y-m-d', strtotime($br['created_at'])) ?>
                                </small>
                            </td>
                            <td>
                                <span class="badge"
                                      style="background:var(--ts-bg-dark);color:var(--ts-gold);border:1px solid var(--ts-border-gold);font-family:monospace;font-size:.85rem">
                                    <?= htmlspecialchars($br['code']) ?>
                                </span>
                            </td>
                            <td><small class="text-muted"><?= htmlspecialchars($br['address'] ?: '—') ?></small></td>
                            <td><small><?= htmlspecialchars($br['phone'] ?: '—') ?></small></td>
                            <td class="text-center">
                                <span class="badge bg-secondary"><?= $br['active_users'] ?></span>
                            </td>
                            <td class="text-center">
                                <span class="fw-bold" style="color:var(--ts-gold)">
                                    <?= number_format((int)$br['today_invoices']) ?>
                                </span>
                            </td>
                            <td class="text-center">
                                <span class="fw-bold" style="color:var(--ts-success)">
                                    <?= format_money((float)$br['today_revenue']) ?>
                                </span>
                            </td>
                            <td class="text-center">
                                <?php if ($br['status'] === 'active'): ?>
                                    <span class="badge bg-success"><?= $lang['active'] ?></span>
                                <?php else: ?>
                                    <span class="badge bg-danger"><?= $lang['inactive'] ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <div class="d-flex gap-1 justify-content-center">
                                    <!-- زر تعديل -->
                                    <button class="btn btn-sm btn-outline-secondary"
                                            onclick="openEditModal(<?= htmlspecialchars(json_encode($br), ENT_QUOTES) ?>)"
                                            title="<?= $lang['edit'] ?>">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <!-- زر تعطيل/تفعيل -->
                                    <form method="POST" class="d-inline"
                                          onsubmit="return confirm('<?= $is_rtl ? 'هل أنت متأكد؟' : 'Are you sure?' ?>')">
                                        <input type="hidden" name="action"     value="toggle_branch">
                                        <input type="hidden" name="branch_id"  value="<?= $br['id'] ?>">
                                        <input type="hidden" name="new_status" value="<?= $br['status'] === 'active' ? 'inactive' : 'active' ?>">
                                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                        <button class="btn btn-sm <?= $br['status'] === 'active' ? 'btn-outline-danger' : 'btn-outline-success' ?>"
                                                title="<?= $br['status'] === 'active' ? $lang['deactivate'] : $lang['activate'] ?>">
                                            <i class="bi bi-<?= $br['status'] === 'active' ? 'x-circle' : 'check-circle' ?>"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div><!-- /.main-content -->

<!-- ================================================== -->
<!-- Modal: إضافة فرع                                    -->
<!-- ================================================== -->
<div class="modal fade" id="addBranchModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content ts-modal">
            <div class="modal-header">
                <h5 class="modal-title" style="color:var(--ts-gold)">
                    <i class="bi bi-plus-circle me-2"></i>
                    <?= $is_rtl ? 'إضافة فرع جديد' : 'Add New Branch' ?>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action"     value="add_branch">
                    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">

                    <div class="mb-3">
                        <label class="form-label">
                            <?= $is_rtl ? 'اسم الفرع' : 'Branch Name' ?>
                            <span class="text-danger">*</span>
                        </label>
                        <input type="text" name="name" class="form-control ts-input"
                               required maxlength="100"
                               placeholder="<?= $is_rtl ? 'مثال: فرع بحري' : 'e.g. North Branch' ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">
                            <?= $is_rtl ? 'رمز الفرع' : 'Branch Code' ?>
                            <span class="text-danger">*</span>
                        </label>
                        <input type="text" name="code" class="form-control ts-input text-uppercase"
                               required maxlength="10"
                               placeholder="<?= $is_rtl ? 'مثال: BR2' : 'e.g. BR2' ?>"
                               oninput="this.value=this.value.toUpperCase()">
                        <div class="form-text" style="color:var(--ts-text-muted)">
                            <?= $is_rtl ? 'رمز فريد يُستخدم في أرقام الفواتير (حروف وأرقام فقط)' : 'Unique code used in invoice numbers (letters & numbers only)' ?>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label"><?= $is_rtl ? 'العنوان' : 'Address' ?></label>
                        <input type="text" name="address" class="form-control ts-input" maxlength="255"
                               placeholder="<?= $is_rtl ? 'عنوان الفرع' : 'Branch address' ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label"><?= $is_rtl ? 'رقم الهاتف' : 'Phone' ?></label>
                        <input type="text" name="phone" class="form-control ts-input" maxlength="20"
                               placeholder="<?= $is_rtl ? '09xxxxxxxx' : '09xxxxxxxx' ?>">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <?= $lang['cancel'] ?>
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-1"></i><?= $lang['save'] ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ================================================== -->
<!-- Modal: تعديل فرع                                    -->
<!-- ================================================== -->
<div class="modal fade" id="editBranchModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content ts-modal">
            <div class="modal-header">
                <h5 class="modal-title" style="color:var(--ts-gold)">
                    <i class="bi bi-pencil-square me-2"></i>
                    <?= $is_rtl ? 'تعديل الفرع' : 'Edit Branch' ?>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action"     value="edit_branch">
                    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                    <input type="hidden" name="branch_id"  id="edit_branch_id">

                    <div class="mb-3">
                        <label class="form-label">
                            <?= $is_rtl ? 'رمز الفرع' : 'Branch Code' ?>
                        </label>
                        <input type="text" id="edit_branch_code"
                               class="form-control ts-input" disabled
                               style="opacity:.6">
                        <div class="form-text" style="color:var(--ts-text-muted)">
                            <?= $is_rtl ? 'الرمز لا يمكن تعديله بعد الإنشاء' : 'Code cannot be changed after creation' ?>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">
                            <?= $is_rtl ? 'اسم الفرع' : 'Branch Name' ?>
                            <span class="text-danger">*</span>
                        </label>
                        <input type="text" name="name" id="edit_branch_name"
                               class="form-control ts-input" required maxlength="100">
                    </div>

                    <div class="mb-3">
                        <label class="form-label"><?= $is_rtl ? 'العنوان' : 'Address' ?></label>
                        <input type="text" name="address" id="edit_branch_address"
                               class="form-control ts-input" maxlength="255">
                    </div>

                    <div class="mb-3">
                        <label class="form-label"><?= $is_rtl ? 'رقم الهاتف' : 'Phone' ?></label>
                        <input type="text" name="phone" id="edit_branch_phone"
                               class="form-control ts-input" maxlength="20">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <?= $lang['cancel'] ?>
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-1"></i><?= $lang['save'] ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openEditModal(branch) {
    document.getElementById('edit_branch_id').value      = branch.id;
    document.getElementById('edit_branch_code').value    = branch.code;
    document.getElementById('edit_branch_name').value    = branch.name;
    document.getElementById('edit_branch_address').value = branch.address || '';
    document.getElementById('edit_branch_phone').value   = branch.phone   || '';
    var modal = new bootstrap.Modal(document.getElementById('editBranchModal'));
    modal.show();
}
</script>

<style>
.ts-modal { background:var(--ts-bg-card); border-color:var(--ts-border-gold); color:var(--ts-text-primary); }
.ts-modal .modal-header { background:var(--ts-bg-dark); border-bottom-color:var(--ts-border-gold); }
.ts-modal .modal-footer { background:var(--ts-bg-dark); border-top-color:var(--ts-border); }
.ts-input { background:var(--ts-bg-input) !important; border-color:var(--ts-border) !important; color:var(--ts-text-primary) !important; }
.ts-input:focus { border-color:var(--ts-gold) !important; box-shadow:0 0 0 .2rem rgba(201,168,76,.25) !important; }
.main-content { padding: 1.5rem; }
</style>

<?php require_once __DIR__ . '/../includes/layout/footer.php'; ?>
