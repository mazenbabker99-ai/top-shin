<?php
// ============================================================
// المسار: superadmin/users.php
// الوظيفة: إدارة المستخدمين — عرض + إضافة + تعديل + تغيير كلمة المرور + تعطيل
// الصلاحية: super_admin فقط
// ============================================================

declare(strict_types=1);

$current_page = 'users';
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
        redirect('users.php');
    }

    $action = $_POST['action'] ?? '';

    // ——— إضافة مستخدم ———
    if ($action === 'add_user') {
        $name      = trim($_POST['name']      ?? '');
        $username  = trim($_POST['username']  ?? '');
        $password  = $_POST['password']       ?? '';
        $role      = $_POST['role']           ?? '';
        $branch_id = sanitize_int($_POST['branch_id'] ?? 0) ?: null;
        $lang_pref = in_array($_POST['lang_pref'] ?? 'ar', ['ar', 'en'], true)
                     ? $_POST['lang_pref'] : 'ar';

        // تحقق الأدوار الصالحة
        $valid_roles = ['super_admin', 'branch_admin', 'cashier'];
        if ($name === '' || $username === '' || $password === ''
            || !in_array($role, $valid_roles, true)) {
            flash('error', $lang['required_field']);
            redirect('users.php');
        }

        // branch_admin و cashier يجب أن يكون لهم فرع
        if (in_array($role, ['branch_admin', 'cashier'], true) && $branch_id === null) {
            flash('error', $is_rtl
                ? 'يجب تحديد فرع لهذا الدور.'
                : 'A branch must be assigned for this role.');
            redirect('users.php');
        }

        // super_admin لا يحتاج فرع
        if ($role === 'super_admin') {
            $branch_id = null;
        }

        // تحقق من تكرار اسم المستخدم
        $dup = $pdo->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
        $dup->execute([$username]);
        if ($dup->fetch()) {
            flash('error', $lang['duplicate_username']);
            redirect('users.php');
        }

        // تحقق من طول كلمة المرور
        if (strlen($password) < 6) {
            flash('error', $is_rtl
                ? 'كلمة المرور يجب أن تكون 6 أحرف على الأقل.'
                : 'Password must be at least 6 characters.');
            redirect('users.php');
        }

        try {
            $hashed = password_hash($password, PASSWORD_BCRYPT);
            $stmt   = $pdo->prepare("
                INSERT INTO users
                    (branch_id, name, username, password, role, lang, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, 'active', NOW())
            ");
            $stmt->execute([$branch_id, $name, $username, $hashed, $role, $lang_pref]);
            $new_id = (int)$pdo->lastInsertId();

            $audit->log('create', 'users', $new_id, null, [
                'name'     => $name,
                'username' => $username,
                'role'     => $role,
            ]);
            regenerate_csrf_token();
            flash('success', $lang['saved']);
        } catch (Throwable $e) {
            error_log('[TopShine users add] ' . $e->getMessage());
            flash('error', $lang['error']);
        }
        redirect('users.php');
    }

    // ——— تعديل مستخدم (بدون كلمة المرور) ———
    if ($action === 'edit_user') {
        $user_id   = sanitize_int($_POST['user_id']   ?? 0);
        $name      = trim($_POST['name']              ?? '');
        $role      = $_POST['role']                   ?? '';
        $branch_id = sanitize_int($_POST['branch_id'] ?? 0) ?: null;
        $lang_pref = in_array($_POST['lang_pref'] ?? 'ar', ['ar', 'en'], true)
                     ? $_POST['lang_pref'] : 'ar';

        $valid_roles = ['super_admin', 'branch_admin', 'cashier'];

        if ($user_id <= 0 || $name === '' || !in_array($role, $valid_roles, true)) {
            flash('error', $lang['required_field']);
            redirect('users.php');
        }

        if (in_array($role, ['branch_admin', 'cashier'], true) && $branch_id === null) {
            flash('error', $is_rtl
                ? 'يجب تحديد فرع لهذا الدور.'
                : 'A branch must be assigned for this role.');
            redirect('users.php');
        }

        if ($role === 'super_admin') {
            $branch_id = null;
        }

        try {
            $old_stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
            $old_stmt->execute([$user_id]);
            $old_data = $old_stmt->fetch() ?: [];

            $pdo->prepare("
                UPDATE users
                SET name=?, role=?, branch_id=?, lang=?
                WHERE id=?
            ")->execute([$name, $role, $branch_id, $lang_pref, $user_id]);

            $audit->log('update', 'users', $user_id,
                ['name' => $old_data['name'] ?? '', 'role' => $old_data['role'] ?? ''],
                ['name' => $name,                   'role' => $role]
            );
            regenerate_csrf_token();
            flash('success', $lang['updated']);
        } catch (Throwable $e) {
            error_log('[TopShine users edit] ' . $e->getMessage());
            flash('error', $lang['error']);
        }
        redirect('users.php');
    }

    // ——— تغيير كلمة المرور ———
    if ($action === 'change_password') {
        $user_id      = sanitize_int($_POST['user_id']      ?? 0);
        $new_password = $_POST['new_password']              ?? '';
        $confirm_pass = $_POST['confirm_password']          ?? '';

        if ($user_id <= 0 || $new_password === '') {
            flash('error', $lang['required_field']);
            redirect('users.php');
        }

        if ($new_password !== $confirm_pass) {
            flash('error', $is_rtl
                ? 'كلمتا المرور غير متطابقتين.'
                : 'Passwords do not match.');
            redirect('users.php');
        }

        if (strlen($new_password) < 6) {
            flash('error', $is_rtl
                ? 'كلمة المرور يجب أن تكون 6 أحرف على الأقل.'
                : 'Password must be at least 6 characters.');
            redirect('users.php');
        }

        try {
            $hashed = password_hash($new_password, PASSWORD_BCRYPT);
            $pdo->prepare("UPDATE users SET password=? WHERE id=?")
                ->execute([$hashed, $user_id]);

            $audit->log('update', 'users', $user_id,
                ['password' => '***'],
                ['password' => '***changed***']
            );
            regenerate_csrf_token();
            flash('success', $is_rtl ? 'تم تغيير كلمة المرور بنجاح.' : 'Password changed successfully.');
        } catch (Throwable $e) {
            error_log('[TopShine users password] ' . $e->getMessage());
            flash('error', $lang['error']);
        }
        redirect('users.php');
    }

    // ——— تعطيل / تفعيل مستخدم ———
    if ($action === 'toggle_user') {
        $user_id    = sanitize_int($_POST['user_id']    ?? 0);
        $new_status = $_POST['new_status']              ?? 'inactive';
        $new_status = in_array($new_status, ['active', 'inactive'], true)
                      ? $new_status : 'inactive';

        // لا يمكن تعطيل نفسك
        if ($user_id === Auth::getUserId()) {
            flash('error', $is_rtl
                ? 'لا يمكنك تعطيل حسابك الخاص.'
                : 'You cannot deactivate your own account.');
            redirect('users.php');
        }

        try {
            $pdo->prepare("UPDATE users SET status=? WHERE id=?")
                ->execute([$new_status, $user_id]);

            $audit->log(
                $new_status === 'active' ? 'update' : 'delete',
                'users', $user_id,
                ['status' => $new_status === 'active' ? 'inactive' : 'active'],
                ['status' => $new_status]
            );
            regenerate_csrf_token();
            flash('success', $new_status === 'active' ? $lang['activated'] : $lang['deactivated']);
        } catch (Throwable $e) {
            error_log('[TopShine users toggle] ' . $e->getMessage());
            flash('error', $lang['error']);
        }
        redirect('users.php');
    }
}

// ============================================================
// فلاتر القائمة
// ============================================================
$filter_role   = trim($_GET['role']      ?? '');
$filter_branch = sanitize_int($_GET['branch_id'] ?? 0);
$filter_status = trim($_GET['status']    ?? '');
$filter_search = trim($_GET['q']         ?? '');

$where_parts = [];
$params      = [];

if ($filter_role !== '' && in_array($filter_role, ['super_admin','branch_admin','cashier'], true)) {
    $where_parts[] = 'u.role = ?';
    $params[]      = $filter_role;
}
if ($filter_branch > 0) {
    $where_parts[] = 'u.branch_id = ?';
    $params[]      = $filter_branch;
}
if ($filter_status !== '' && in_array($filter_status, ['active','inactive'], true)) {
    $where_parts[] = 'u.status = ?';
    $params[]      = $filter_status;
}
if ($filter_search !== '') {
    $where_parts[] = '(u.name LIKE ? OR u.username LIKE ?)';
    $like          = '%' . $filter_search . '%';
    $params[]      = $like;
    $params[]      = $like;
}

$where_sql = $where_parts ? 'WHERE ' . implode(' AND ', $where_parts) : '';

// Pagination
$page_num  = max(1, (int)($_GET['p'] ?? 1));
$per_page  = 25;

$count_st = $pdo->prepare("SELECT COUNT(*) FROM users u $where_sql");
$count_st->execute($params);
$total_records = (int)$count_st->fetchColumn();
$total_pages   = max(1, (int)ceil($total_records / $per_page));
$offset        = ($page_num - 1) * $per_page;

$users_stmt = $pdo->prepare("
    SELECT
        u.id, u.name, u.username, u.role, u.lang, u.status,
        u.last_login, u.created_at, u.branch_id,
        b.name AS branch_name
    FROM users u
    LEFT JOIN branches b ON b.id = u.branch_id
    $where_sql
    ORDER BY u.status DESC, u.role ASC, u.name ASC
    LIMIT $per_page OFFSET $offset
");
$users_stmt->execute($params);
$users = $users_stmt->fetchAll();

// قائمة الفروع النشطة للـ select
$branches_list = $pdo->query(
    "SELECT id, name, code FROM branches WHERE status='active' ORDER BY name"
)->fetchAll();

$depth       = 1;
$assets_path = '../assets';

// تسمية الأدوار
$role_labels = [
    'super_admin'  => $lang['super_admin'],
    'branch_admin' => $lang['branch_admin'],
    'cashier'      => $lang['cashier_role'],
];
$role_colors = [
    'super_admin'  => 'var(--ts-gold)',
    'branch_admin' => 'var(--ts-silver)',
    'cashier'      => 'var(--ts-success)',
];
?>

<?php require_once __DIR__ . '/../includes/layout/sidebar.php'; ?>

<div class="main-content">

    <?php show_flash(); ?>

    <!-- رأس الصفحة -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <h4 class="mb-0" style="color:var(--ts-gold)">
            <i class="bi bi-people me-2"></i>
            <?= $lang['users'] ?>
        </h4>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addUserModal">
            <i class="bi bi-person-plus me-1"></i>
            <?= $is_rtl ? 'إضافة مستخدم' : 'Add User' ?>
        </button>
    </div>

    <div class="row g-4">

        <!-- ========== عمود الفلاتر ========== -->
        <div class="col-lg-3">
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-funnel me-1"></i><?= $lang['filter'] ?>
                </div>
                <div class="card-body">
                    <form method="GET">
                        <div class="mb-3">
                            <label class="form-label small"><?= $lang['search'] ?></label>
                            <input type="text" name="q" class="form-control form-control-sm ts-input"
                                   value="<?= htmlspecialchars($filter_search) ?>"
                                   placeholder="<?= $is_rtl ? 'الاسم أو اسم المستخدم...' : 'Name or username...' ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label small"><?= $lang['role'] ?></label>
                            <select name="role" class="form-select form-select-sm ts-select">
                                <option value=""><?= $is_rtl ? 'كل الأدوار' : 'All Roles' ?></option>
                                <?php foreach ($role_labels as $rv => $rl): ?>
                                <option value="<?= $rv ?>" <?= $filter_role === $rv ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($rl) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small"><?= $lang['branch'] ?></label>
                            <select name="branch_id" class="form-select form-select-sm ts-select">
                                <option value=""><?= $is_rtl ? 'كل الفروع' : 'All Branches' ?></option>
                                <?php foreach ($branches_list as $br): ?>
                                <option value="<?= $br['id'] ?>" <?= $filter_branch == $br['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($br['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small"><?= $lang['status'] ?></label>
                            <select name="status" class="form-select form-select-sm ts-select">
                                <option value=""><?= $is_rtl ? 'الكل' : 'All' ?></option>
                                <option value="active"   <?= $filter_status === 'active'   ? 'selected' : '' ?>><?= $lang['active'] ?></option>
                                <option value="inactive" <?= $filter_status === 'inactive' ? 'selected' : '' ?>><?= $lang['inactive'] ?></option>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm w-100">
                            <i class="bi bi-funnel me-1"></i><?= $lang['filter'] ?>
                        </button>
                        <a href="users.php" class="btn btn-outline-secondary btn-sm w-100 mt-2">
                            <?= $lang['reset'] ?>
                        </a>
                    </form>
                </div>
            </div>
        </div>

        <!-- ========== عمود الجدول ========== -->
        <div class="col-lg-9">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><?= $lang['users'] ?></span>
                    <small class="text-muted"><?= $total_records ?> <?= $lang['results'] ?></small>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($users)): ?>
                        <div class="text-center py-5 text-muted">
                            <i class="bi bi-people fs-1 d-block mb-2"></i>
                            <?= $lang['no_results'] ?>
                        </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th><?= $is_rtl ? 'المستخدم' : 'User' ?></th>
                                    <th><?= $lang['role'] ?></th>
                                    <th><?= $lang['branch'] ?></th>
                                    <th><?= $lang['language'] ?></th>
                                    <th><?= $lang['last_login'] ?></th>
                                    <th class="text-center"><?= $lang['status'] ?></th>
                                    <th class="text-center"><?= $lang['action'] ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $usr): ?>
                                <tr>
                                    <td>
                                        <!-- Avatar -->
                                        <div class="d-flex align-items-center gap-2">
                                            <div style="
                                                width:36px; height:36px; border-radius:50%;
                                                background:var(--ts-gold-dark); color:#0D0D0D;
                                                display:flex; align-items:center; justify-content:center;
                                                font-weight:700; font-size:.9rem; flex-shrink:0;">
                                                <?= mb_substr($usr['name'], 0, 1, 'UTF-8') ?>
                                            </div>
                                            <div>
                                                <div class="fw-semibold"><?= htmlspecialchars($usr['name']) ?></div>
                                                <small class="text-muted font-monospace">
                                                    @<?= htmlspecialchars($usr['username']) ?>
                                                </small>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge"
                                              style="background:var(--ts-bg-dark);
                                                     color:<?= $role_colors[$usr['role']] ?? 'var(--ts-silver)' ?>;
                                                     border:1px solid <?= $role_colors[$usr['role']] ?? 'var(--ts-border)' ?>">
                                            <?= htmlspecialchars($role_labels[$usr['role']] ?? $usr['role']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <small><?= htmlspecialchars($usr['branch_name'] ?? '—') ?></small>
                                    </td>
                                    <td>
                                        <small><?= $usr['lang'] === 'ar' ? '🇸🇦 عربي' : '🇬🇧 English' ?></small>
                                    </td>
                                    <td>
                                        <small class="text-muted font-monospace">
                                            <?= $usr['last_login']
                                                ? date('Y-m-d H:i', strtotime($usr['last_login']))
                                                : '—' ?>
                                        </small>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($usr['status'] === 'active'): ?>
                                            <span class="badge bg-success"><?= $lang['active'] ?></span>
                                        <?php else: ?>
                                            <span class="badge bg-danger"><?= $lang['inactive'] ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <div class="d-flex gap-1 justify-content-center flex-wrap">
                                            <!-- تعديل -->
                                            <button class="btn btn-sm btn-outline-secondary"
                                                    onclick="openEditUser(<?= htmlspecialchars(json_encode([
                                                        'id'        => $usr['id'],
                                                        'name'      => $usr['name'],
                                                        'username'  => $usr['username'],
                                                        'role'      => $usr['role'],
                                                        'branch_id' => $usr['branch_id'],
                                                        'lang'      => $usr['lang'],
                                                    ]), ENT_QUOTES) ?>)"
                                                    title="<?= $lang['edit'] ?>">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <!-- تغيير كلمة المرور -->
                                            <button class="btn btn-sm btn-outline-warning"
                                                    onclick="openPasswordModal(<?= $usr['id'] ?>)"
                                                    title="<?= $lang['change_password'] ?>">
                                                <i class="bi bi-key"></i>
                                            </button>
                                            <!-- تعطيل/تفعيل -->
                                            <?php if ($usr['id'] !== Auth::getUserId()): ?>
                                            <form method="POST" class="d-inline"
                                                  onsubmit="return confirm('<?= $is_rtl ? 'هل أنت متأكد؟' : 'Are you sure?' ?>')">
                                                <input type="hidden" name="action"     value="toggle_user">
                                                <input type="hidden" name="user_id"    value="<?= $usr['id'] ?>">
                                                <input type="hidden" name="new_status" value="<?= $usr['status'] === 'active' ? 'inactive' : 'active' ?>">
                                                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                                <button class="btn btn-sm <?= $usr['status'] === 'active' ? 'btn-outline-danger' : 'btn-outline-success' ?>"
                                                        title="<?= $usr['status'] === 'active' ? $lang['deactivate'] : $lang['activate'] ?>">
                                                    <i class="bi bi-<?= $usr['status'] === 'active' ? 'x-circle' : 'check-circle' ?>"></i>
                                                </button>
                                            </form>
                                            <?php else: ?>
                                                <span class="badge bg-secondary" title="<?= $is_rtl ? 'حسابك الحالي' : 'Current account' ?>">
                                                    <i class="bi bi-person-check"></i>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                    <div class="d-flex justify-content-between align-items-center p-3">
                        <small class="text-muted">
                            <?= $lang['showing'] ?> <?= $offset + 1 ?>–<?= min($offset + $per_page, $total_records) ?>
                            <?= $lang['of'] ?> <?= $total_records ?> <?= $lang['results'] ?>
                        </small>
                        <nav>
                            <ul class="pagination pagination-sm mb-0">
                                <?php for ($p = 1; $p <= $total_pages; $p++):
                                    $q = array_merge($_GET, ['p' => $p]);
                                ?>
                                <li class="page-item <?= $p === $page_num ? 'active' : '' ?>">
                                    <a class="page-link" href="?<?= http_build_query($q) ?>"><?= $p ?></a>
                                </li>
                                <?php endfor; ?>
                            </ul>
                        </nav>
                    </div>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div><!-- /.main-content -->

<!-- ================================================== -->
<!-- Modal: إضافة مستخدم                                 -->
<!-- ================================================== -->
<div class="modal fade" id="addUserModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content ts-modal">
            <div class="modal-header">
                <h5 class="modal-title" style="color:var(--ts-gold)">
                    <i class="bi bi-person-plus me-2"></i>
                    <?= $is_rtl ? 'إضافة مستخدم جديد' : 'Add New User' ?>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action"     value="add_user">
                    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">
                                <?= $is_rtl ? 'الاسم الكامل' : 'Full Name' ?>
                                <span class="text-danger">*</span>
                            </label>
                            <input type="text" name="name" class="form-control ts-input"
                                   required maxlength="100">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">
                                <?= $lang['username'] ?>
                                <span class="text-danger">*</span>
                            </label>
                            <input type="text" name="username" class="form-control ts-input"
                                   required maxlength="50"
                                   placeholder="<?= $is_rtl ? 'بدون مسافات أو رموز' : 'No spaces or symbols' ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">
                                <?= $lang['password'] ?>
                                <span class="text-danger">*</span>
                            </label>
                            <input type="password" name="password" class="form-control ts-input"
                                   required minlength="6">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">
                                <?= $lang['role'] ?>
                                <span class="text-danger">*</span>
                            </label>
                            <select name="role" id="add_role" class="form-select ts-select"
                                    required onchange="toggleBranchSelect('add_branch_wrap', this.value)">
                                <option value=""><?= $is_rtl ? 'اختر الدور...' : 'Choose role...' ?></option>
                                <?php foreach ($role_labels as $rv => $rl): ?>
                                <option value="<?= $rv ?>"><?= htmlspecialchars($rl) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6" id="add_branch_wrap" style="display:none">
                            <label class="form-label">
                                <?= $lang['branch'] ?>
                                <span class="text-danger">*</span>
                            </label>
                            <select name="branch_id" id="add_branch_id" class="form-select ts-select">
                                <option value=""><?= $is_rtl ? 'اختر الفرع...' : 'Choose branch...' ?></option>
                                <?php foreach ($branches_list as $br): ?>
                                <option value="<?= $br['id'] ?>">
                                    <?= htmlspecialchars($br['name']) ?> (<?= $br['code'] ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= $lang['language'] ?></label>
                            <select name="lang_pref" class="form-select ts-select">
                                <option value="ar"><?= $lang['arabic'] ?></option>
                                <option value="en"><?= $lang['english'] ?></option>
                            </select>
                        </div>
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
<!-- Modal: تعديل مستخدم                                 -->
<!-- ================================================== -->
<div class="modal fade" id="editUserModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content ts-modal">
            <div class="modal-header">
                <h5 class="modal-title" style="color:var(--ts-gold)">
                    <i class="bi bi-pencil-square me-2"></i>
                    <?= $is_rtl ? 'تعديل المستخدم' : 'Edit User' ?>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action"     value="edit_user">
                    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                    <input type="hidden" name="user_id"    id="edit_user_id">

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">
                                <?= $is_rtl ? 'اسم المستخدم' : 'Username' ?>
                            </label>
                            <input type="text" id="edit_username_display"
                                   class="form-control ts-input" disabled style="opacity:.6">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">
                                <?= $is_rtl ? 'الاسم الكامل' : 'Full Name' ?>
                                <span class="text-danger">*</span>
                            </label>
                            <input type="text" name="name" id="edit_name"
                                   class="form-control ts-input" required maxlength="100">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">
                                <?= $lang['role'] ?>
                                <span class="text-danger">*</span>
                            </label>
                            <select name="role" id="edit_role" class="form-select ts-select"
                                    required onchange="toggleBranchSelect('edit_branch_wrap', this.value)">
                                <?php foreach ($role_labels as $rv => $rl): ?>
                                <option value="<?= $rv ?>"><?= htmlspecialchars($rl) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6" id="edit_branch_wrap">
                            <label class="form-label"><?= $lang['branch'] ?></label>
                            <select name="branch_id" id="edit_branch_id" class="form-select ts-select">
                                <option value=""><?= $is_rtl ? 'بدون فرع' : 'No Branch' ?></option>
                                <?php foreach ($branches_list as $br): ?>
                                <option value="<?= $br['id'] ?>">
                                    <?= htmlspecialchars($br['name']) ?> (<?= $br['code'] ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= $lang['language'] ?></label>
                            <select name="lang_pref" id="edit_lang" class="form-select ts-select">
                                <option value="ar"><?= $lang['arabic'] ?></option>
                                <option value="en"><?= $lang['english'] ?></option>
                            </select>
                        </div>
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
<!-- Modal: تغيير كلمة المرور                            -->
<!-- ================================================== -->
<div class="modal fade" id="passwordModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content ts-modal">
            <div class="modal-header">
                <h5 class="modal-title" style="color:var(--ts-gold)">
                    <i class="bi bi-key me-2"></i>
                    <?= $lang['change_password'] ?>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action"     value="change_password">
                    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                    <input type="hidden" name="user_id"    id="pw_user_id">

                    <div class="mb-3">
                        <label class="form-label">
                            <?= $lang['new_password'] ?>
                            <span class="text-danger">*</span>
                        </label>
                        <input type="password" name="new_password" class="form-control ts-input"
                               required minlength="6">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">
                            <?= $lang['confirm_password'] ?>
                            <span class="text-danger">*</span>
                        </label>
                        <input type="password" name="confirm_password" class="form-control ts-input"
                               required minlength="6">
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
<!-- JavaScript                                          -->
<!-- ================================================== -->
<script>
// إظهار/إخفاء حقل الفرع حسب الدور
function toggleBranchSelect(wrapId, role) {
    var wrap = document.getElementById(wrapId);
    if (!wrap) return;
    if (role === 'super_admin' || role === '') {
        wrap.style.display = 'none';
    } else {
        wrap.style.display = '';
    }
}

// فتح modal التعديل مع ملء البيانات
function openEditUser(user) {
    document.getElementById('edit_user_id').value         = user.id;
    document.getElementById('edit_username_display').value = user.username;
    document.getElementById('edit_name').value            = user.name;
    document.getElementById('edit_lang').value            = user.lang || 'ar';

    var roleSelect = document.getElementById('edit_role');
    roleSelect.value = user.role;
    toggleBranchSelect('edit_branch_wrap', user.role);

    var branchSelect = document.getElementById('edit_branch_id');
    branchSelect.value = user.branch_id || '';

    var modal = new bootstrap.Modal(document.getElementById('editUserModal'));
    modal.show();
}

// فتح modal كلمة المرور
function openPasswordModal(userId) {
    document.getElementById('pw_user_id').value = userId;
    var modal = new bootstrap.Modal(document.getElementById('passwordModal'));
    modal.show();
}
</script>

<style>
.ts-modal         { background:var(--ts-bg-card);  border-color:var(--ts-border-gold);  color:var(--ts-text-primary); }
.ts-modal .modal-header { background:var(--ts-bg-dark); border-bottom-color:var(--ts-border-gold); }
.ts-modal .modal-footer { background:var(--ts-bg-dark); border-top-color:var(--ts-border); }
.ts-input  { background:var(--ts-bg-input)  !important; border-color:var(--ts-border) !important; color:var(--ts-text-primary) !important; }
.ts-select { background:var(--ts-bg-input)  !important; border-color:var(--ts-border) !important; color:var(--ts-text-primary) !important; }
.ts-input:focus, .ts-select:focus {
    border-color:var(--ts-gold) !important;
    box-shadow:0 0 0 .2rem rgba(201,168,76,.25) !important;
}
.main-content { padding: 1.5rem; }
.font-monospace { font-family: monospace; }
</style>

<?php require_once __DIR__ . '/../includes/layout/footer.php'; ?>
