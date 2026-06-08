<?php
// ============================================================
// المسار: admin/customers.php
// الوظيفة: إدارة العملاء — إضافة / تعديل / تعطيل + عرض آخر الفواتير
// الصلاحية: super_admin | branch_admin
// ============================================================

declare(strict_types=1);

$current_page = 'customers';
require_once __DIR__ . '/../includes/layout/header.php';
require_role(['super_admin', 'branch_admin']);

$pdo      = db();
$audit    = new AuditLogger($pdo);
$role     = Auth::getRole();
$user_id  = Auth::getUserId();
$user_bid = Auth::getBranchId();
$is_super = ($role === 'super_admin');

// ============================================================
// معالجة POST
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submitted_token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($submitted_token)) {
        flash('error', $lang['csrf_invalid']);
        redirect('customers.php');
    }

    $action = $_POST['action'] ?? '';

    // ——— إضافة عميل ———
    if ($action === 'add_customer') {
        $name      = trim($_POST['name']  ?? '');
        $phone     = trim($_POST['phone'] ?? '');
        $notes     = trim($_POST['notes'] ?? '');
        $reg_bid   = $is_super
            ? (sanitize_int($_POST['registered_branch_id'] ?? 0) ?: null)
            : $user_bid;

        if ($name === '') {
            flash('error', $lang['required_field']);
            redirect('customers.php');
        }

        // تحقق من عدم تكرار الهاتف إذا مُدخَل
        if ($phone !== '') {
            $dup = $pdo->prepare("SELECT id FROM customers WHERE phone = ? AND status = 'active' LIMIT 1");
            $dup->execute([$phone]);
            if ($dup->fetch()) {
                flash('error', $is_rtl ? 'رقم الهاتف مستخدم من قبل.' : 'Phone number already exists.');
                redirect('customers.php');
            }
        }

        try {
            $stmt = $pdo->prepare('
                INSERT INTO customers
                    (name, phone, registered_branch_id, notes, status, created_at)
                VALUES (?, ?, ?, ?, \'active\', NOW())
            ');
            $stmt->execute([$name, $phone, $reg_bid, $notes]);
            $new_id = (int)$pdo->lastInsertId();

            $audit->log('create', 'customers', $new_id, null, compact('name', 'phone'));
            regenerate_csrf_token();
            flash('success', $lang['saved']);
        } catch (Throwable $e) {
            error_log('[TopShine customers add] ' . $e->getMessage());
            flash('error', $lang['error']);
        }
        redirect('customers.php');
    }

    // ——— تعديل عميل ———
    if ($action === 'edit_customer') {
        $cust_id = sanitize_int($_POST['customer_id'] ?? 0);
        $name    = trim($_POST['name']  ?? '');
        $phone   = trim($_POST['phone'] ?? '');
        $notes   = trim($_POST['notes'] ?? '');
        $reg_bid = $is_super
            ? (sanitize_int($_POST['registered_branch_id'] ?? 0) ?: null)
            : $user_bid;

        if ($cust_id <= 0 || $name === '') {
            flash('error', $lang['required_field']);
            redirect('customers.php');
        }

        // تحقق من عدم تكرار الهاتف (باستثناء السجل الحالي)
        if ($phone !== '') {
            $dup = $pdo->prepare("SELECT id FROM customers WHERE phone = ? AND id != ? AND status = 'active' LIMIT 1");
            $dup->execute([$phone, $cust_id]);
            if ($dup->fetch()) {
                flash('error', $is_rtl ? 'رقم الهاتف مستخدم من قبل.' : 'Phone number already exists.');
                redirect('customers.php');
            }
        }

        try {
            // جلب البيانات القديمة للـ audit
            $old_stmt = $pdo->prepare('SELECT name, phone FROM customers WHERE id = ? LIMIT 1');
            $old_stmt->execute([$cust_id]);
            $old = $old_stmt->fetch();

            $upd = $pdo->prepare('
                UPDATE customers
                SET name = ?, phone = ?, registered_branch_id = ?, notes = ?
                WHERE id = ?
                LIMIT 1
            ');
            $upd->execute([$name, $phone, $reg_bid, $notes, $cust_id]);

            $audit->log('update', 'customers', $cust_id, $old, compact('name', 'phone'));
            regenerate_csrf_token();
            flash('success', $lang['updated']);
        } catch (Throwable $e) {
            error_log('[TopShine customers edit] ' . $e->getMessage());
            flash('error', $lang['error']);
        }
        redirect('customers.php');
    }

    // ——— تعطيل عميل ———
    if ($action === 'deactivate_customer') {
        $cust_id = sanitize_int($_POST['customer_id'] ?? 0);
        if ($cust_id > 0) {
            try {
                $pdo->prepare("UPDATE customers SET status = 'inactive' WHERE id = ? LIMIT 1")
                    ->execute([$cust_id]);
                $audit->log('delete', 'customers', $cust_id, ['status' => 'active'], ['status' => 'inactive']);
                regenerate_csrf_token();
                flash('success', $lang['deactivated']);
            } catch (Throwable $e) {
                error_log('[TopShine customers deactivate] ' . $e->getMessage());
                flash('error', $lang['error']);
            }
        }
        redirect('customers.php');
    }

    // ——— تفعيل عميل ———
    if ($action === 'activate_customer') {
        $cust_id = sanitize_int($_POST['customer_id'] ?? 0);
        if ($cust_id > 0) {
            try {
                $pdo->prepare("UPDATE customers SET status = 'active' WHERE id = ? LIMIT 1")
                    ->execute([$cust_id]);
                $audit->log('update', 'customers', $cust_id, ['status' => 'inactive'], ['status' => 'active']);
                regenerate_csrf_token();
                flash('success', $lang['activated']);
            } catch (Throwable $e) {
                flash('error', $lang['error']);
            }
        }
        redirect('customers.php');
    }
}

// ============================================================
// فلاتر القائمة
// ============================================================
$filter_q       = trim($_GET['q']       ?? '');
$filter_status  = trim($_GET['status']  ?? '');
$filter_branch  = $is_super ? sanitize_int($_GET['branch_id'] ?? 0) : 0;

$where_parts = [];
$params      = [];

if ($filter_q !== '') {
    $where_parts[] = '(c.name LIKE ? OR c.phone LIKE ?)';
    $params[]      = '%' . $filter_q . '%';
    $params[]      = '%' . $filter_q . '%';
}

if (in_array($filter_status, ['active', 'inactive'], true)) {
    $where_parts[] = 'c.status = ?';
    $params[]      = $filter_status;
}

if ($is_super && $filter_branch > 0) {
    $where_parts[] = 'c.registered_branch_id = ?';
    $params[]      = $filter_branch;
}

$where_sql = $where_parts ? 'WHERE ' . implode(' AND ', $where_parts) : '';

// Pagination
$page_num  = max(1, (int)($_GET['p'] ?? 1));
$per_page  = 25;

$count_st = $pdo->prepare("SELECT COUNT(*) FROM customers c $where_sql");
$count_st->execute($params);
$total_records = (int)$count_st->fetchColumn();
$total_pages   = max(1, (int)ceil($total_records / $per_page));
$offset        = ($page_num - 1) * $per_page;

$list_stmt = $pdo->prepare("
    SELECT
        c.id, c.name, c.phone, c.total_purchases,
        c.registered_branch_id, c.notes, c.status, c.created_at,
        b.name AS branch_name
    FROM customers c
    LEFT JOIN branches b ON b.id = c.registered_branch_id
    $where_sql
    ORDER BY c.total_purchases DESC, c.name ASC
    LIMIT $per_page OFFSET $offset
");
$list_stmt->execute($params);
$customers = $list_stmt->fetchAll();

// قائمة الفروع
$branches_list = [];
if ($is_super) {
    $br_stmt = $pdo->query("SELECT id, name FROM branches WHERE status='active' ORDER BY name");
    $branches_list = $br_stmt->fetchAll();
}

// تحديد عميل للعرض التفصيلي
$view_id = sanitize_int($_GET['view'] ?? 0);
$view_customer = null;
$customer_invoices = [];

if ($view_id > 0) {
    $vc_stmt = $pdo->prepare('
        SELECT c.*, b.name AS branch_name
        FROM customers c
        LEFT JOIN branches b ON b.id = c.registered_branch_id
        WHERE c.id = ? LIMIT 1
    ');
    $vc_stmt->execute([$view_id]);
    $view_customer = $vc_stmt->fetch();

    if ($view_customer) {
        $inv_stmt = $pdo->prepare('
            SELECT i.id, i.invoice_number, i.total, i.payment_method,
                   i.status, i.created_at, b.name AS branch_name
            FROM invoices i
            LEFT JOIN branches b ON b.id = i.branch_id
            WHERE i.customer_id = ?
            ORDER BY i.created_at DESC
            LIMIT 10
        ');
        $inv_stmt->execute([$view_id]);
        $customer_invoices = $inv_stmt->fetchAll();
    }
}

$depth       = 1;
$assets_path = '../assets/';
?>

<?php require_once __DIR__ . '/../includes/layout/sidebar.php'; ?>

<div class="main-content">

    <?php show_flash(); ?>

    <!-- ================================================== -->
    <!-- رأس الصفحة                                          -->
    <!-- ================================================== -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <h4 class="mb-0" style="color:var(--ts-gold)">
            <i class="bi bi-people me-2"></i>
            <?= $lang['customers'] ?>
            <span class="badge ms-2" style="background:var(--ts-gold);color:#0D0D0D;font-size:.7rem">
                <?= number_format($total_records) ?>
            </span>
        </h4>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCustomerModal">
            <i class="bi bi-plus-lg me-1"></i>
            <?= $is_rtl ? 'إضافة عميل' : 'Add Customer' ?>
        </button>
    </div>

    <div class="row g-4">
        <!-- ========== عمود القائمة ========== -->
        <div class="col-lg-<?= $view_customer ? '7' : '12' ?>">

            <!-- فلاتر -->
            <div class="card mb-3">
                <div class="card-body py-2">
                    <form method="GET" class="row g-2 align-items-end">
                        <div class="col-md-4">
                            <input type="text" name="q" class="form-control form-control-sm ts-input"
                                   placeholder="<?= $is_rtl ? 'بحث بالاسم أو الهاتف...' : 'Search by name or phone...' ?>"
                                   value="<?= htmlspecialchars($filter_q) ?>">
                        </div>
                        <?php if ($is_super): ?>
                        <div class="col-md-3">
                            <select name="branch_id" class="form-select form-select-sm ts-select">
                                <option value=""><?= $is_rtl ? 'كل الفروع' : 'All Branches' ?></option>
                                <?php foreach ($branches_list as $br): ?>
                                <option value="<?= $br['id'] ?>" <?= $filter_branch == $br['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($br['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        <div class="col-md-2">
                            <select name="status" class="form-select form-select-sm ts-select">
                                <option value=""><?= $is_rtl ? 'الكل' : 'All' ?></option>
                                <option value="active"   <?= $filter_status === 'active'   ? 'selected' : '' ?>><?= $lang['active'] ?></option>
                                <option value="inactive" <?= $filter_status === 'inactive' ? 'selected' : '' ?>><?= $lang['inactive'] ?></option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-sm btn-primary w-100">
                                <i class="bi bi-search me-1"></i><?= $lang['search'] ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- الجدول -->
            <div class="card">
                <div class="card-body p-0">
                    <?php if (empty($customers)): ?>
                    <div class="text-center py-5 text-muted">
                        <i class="bi bi-people fs-1 d-block mb-2"></i>
                        <?= $lang['no_results'] ?>
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th><?= $lang['customer_name'] ?></th>
                                    <th><?= $lang['customer_phone'] ?></th>
                                    <?php if ($is_super): ?><th><?= $lang['branch'] ?></th><?php endif; ?>
                                    <th><?= $lang['total_purchases'] ?></th>
                                    <th><?= $lang['status'] ?></th>
                                    <th><?= $lang['created_at'] ?></th>
                                    <th class="text-center"><?= $lang['action'] ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($customers as $i => $cust): ?>
                                <tr class="<?= $view_id == $cust['id'] ? 'table-active' : '' ?>">
                                    <td class="text-muted small"><?= $offset + $i + 1 ?></td>
                                    <td>
                                        <div class="fw-semibold"><?= htmlspecialchars($cust['name']) ?></div>
                                    </td>
                                    <td>
                                        <span class="font-monospace small">
                                            <?= htmlspecialchars($cust['phone'] ?: '—') ?>
                                        </span>
                                    </td>
                                    <?php if ($is_super): ?>
                                    <td><small><?= htmlspecialchars($cust['branch_name'] ?? '—') ?></small></td>
                                    <?php endif; ?>
                                    <td style="color:var(--ts-gold)">
                                        <?= format_money((float)$cust['total_purchases']) ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?= $cust['status'] === 'active' ? 'success' : 'secondary' ?>">
                                            <?= $cust['status'] === 'active' ? $lang['active'] : $lang['inactive'] ?>
                                        </span>
                                    </td>
                                    <td><small><?= format_datetime($cust['created_at']) ?></small></td>
                                    <td class="text-center">
                                        <div class="d-flex gap-1 justify-content-center">
                                            <!-- عرض تفاصيل -->
                                            <a href="?view=<?= $cust['id'] ?><?= $filter_q ? '&q='.urlencode($filter_q) : '' ?>"
                                               class="btn btn-sm btn-outline-secondary" title="<?= $lang['view'] ?>">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                            <!-- تعديل -->
                                            <button class="btn btn-sm btn-outline-secondary btn-edit-customer"
                                                    data-id="<?= $cust['id'] ?>"
                                                    data-name="<?= htmlspecialchars($cust['name'], ENT_QUOTES) ?>"
                                                    data-phone="<?= htmlspecialchars($cust['phone'], ENT_QUOTES) ?>"
                                                    data-branch="<?= $cust['registered_branch_id'] ?>"
                                                    data-notes="<?= htmlspecialchars($cust['notes'], ENT_QUOTES) ?>"
                                                    title="<?= $lang['edit'] ?>">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <!-- تعطيل / تفعيل -->
                                            <?php if ($cust['status'] === 'active'): ?>
                                            <form method="POST" class="d-inline"
                                                  onsubmit="return confirm('<?= $lang['confirm_deactivate'] ?>')">
                                                <input type="hidden" name="action"      value="deactivate_customer">
                                                <input type="hidden" name="customer_id" value="<?= $cust['id'] ?>">
                                                <input type="hidden" name="csrf_token"  value="<?= generate_csrf_token() ?>">
                                                <button class="btn btn-sm btn-outline-danger" title="<?= $lang['deactivate'] ?>">
                                                    <i class="bi bi-slash-circle"></i>
                                                </button>
                                            </form>
                                            <?php else: ?>
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="action"      value="activate_customer">
                                                <input type="hidden" name="customer_id" value="<?= $cust['id'] ?>">
                                                <input type="hidden" name="csrf_token"  value="<?= generate_csrf_token() ?>">
                                                <button class="btn btn-sm btn-outline-success" title="<?= $lang['activate'] ?>">
                                                    <i class="bi bi-check-circle"></i>
                                                </button>
                                            </form>
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
                            <?= $lang['showing'] ?> <?= $offset + 1 ?> - <?= min($offset + $per_page, $total_records) ?>
                            <?= $lang['of'] ?> <?= $total_records ?> <?= $lang['results'] ?>
                        </small>
                        <nav>
                            <ul class="pagination pagination-sm mb-0">
                                <?php for ($p = 1; $p <= $total_pages; $p++):
                                    $q_arr = array_merge($_GET, ['p' => $p]);
                                ?>
                                <li class="page-item <?= $p === $page_num ? 'active' : '' ?>">
                                    <a class="page-link" href="?<?= http_build_query($q_arr) ?>"><?= $p ?></a>
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

        <!-- ========== عمود التفاصيل ========== -->
        <?php if ($view_customer): ?>
        <div class="col-lg-5">
            <div class="card sticky-top" style="top:80px">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span style="color:var(--ts-gold)">
                        <i class="bi bi-person-circle me-2"></i>
                        <?= htmlspecialchars($view_customer['name']) ?>
                    </span>
                    <a href="customers.php" class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-x-lg"></i>
                    </a>
                </div>
                <div class="card-body">
                    <dl class="row mb-0 small">
                        <dt class="col-5"><?= $lang['customer_phone'] ?></dt>
                        <dd class="col-7 font-monospace"><?= htmlspecialchars($view_customer['phone'] ?: '—') ?></dd>

                        <dt class="col-5"><?= $lang['branch'] ?></dt>
                        <dd class="col-7"><?= htmlspecialchars($view_customer['branch_name'] ?? '—') ?></dd>

                        <dt class="col-5"><?= $lang['total_purchases'] ?></dt>
                        <dd class="col-7" style="color:var(--ts-gold)">
                            <?= format_money((float)$view_customer['total_purchases']) ?>
                        </dd>

                        <dt class="col-5"><?= $lang['status'] ?></dt>
                        <dd class="col-7">
                            <span class="badge bg-<?= $view_customer['status'] === 'active' ? 'success' : 'secondary' ?>">
                                <?= $view_customer['status'] === 'active' ? $lang['active'] : $lang['inactive'] ?>
                            </span>
                        </dd>

                        <dt class="col-5"><?= $lang['created_at'] ?></dt>
                        <dd class="col-7"><?= format_datetime($view_customer['created_at']) ?></dd>

                        <?php if ($view_customer['notes']): ?>
                        <dt class="col-5"><?= $lang['notes'] ?></dt>
                        <dd class="col-7"><?= htmlspecialchars($view_customer['notes']) ?></dd>
                        <?php endif; ?>
                    </dl>

                    <!-- آخر 10 فواتير -->
                    <hr style="border-color:var(--ts-border)">
                    <h6 style="color:var(--ts-gold)">
                        <i class="bi bi-receipt me-1"></i>
                        <?= $is_rtl ? 'آخر الفواتير' : 'Recent Invoices' ?>
                    </h6>

                    <?php if (empty($customer_invoices)): ?>
                    <p class="text-muted small"><?= $lang['no_results'] ?></p>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th><?= $lang['invoice_number'] ?></th>
                                    <th><?= $lang['total'] ?></th>
                                    <th><?= $lang['status'] ?></th>
                                    <th><?= $lang['date'] ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($customer_invoices as $inv): ?>
                                <tr>
                                    <td>
                                        <a href="../templates/invoice_a4.php?id=<?= $inv['id'] ?>"
                                           target="_blank" class="small font-monospace" style="color:var(--ts-gold)">
                                            <?= htmlspecialchars($inv['invoice_number']) ?>
                                        </a>
                                    </td>
                                    <td class="small"><?= format_money((float)$inv['total']) ?></td>
                                    <td>
                                        <?php
                                        $si_map = [
                                            'completed' => 'success',
                                            'refunded'  => 'warning',
                                            'cancelled' => 'danger',
                                        ];
                                        ?>
                                        <span class="badge bg-<?= $si_map[$inv['status']] ?? 'secondary' ?>">
                                            <?= $lang[$inv['status']] ?? $inv['status'] ?>
                                        </span>
                                    </td>
                                    <td><small><?= format_datetime($inv['created_at']) ?></small></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div><!-- /.main-content -->

<!-- ================================================== -->
<!-- Modal: إضافة عميل                                   -->
<!-- ================================================== -->
<div class="modal fade" id="addCustomerModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content ts-modal">
            <div class="modal-header">
                <h5 class="modal-title" style="color:var(--ts-gold)">
                    <i class="bi bi-person-plus me-2"></i>
                    <?= $is_rtl ? 'إضافة عميل جديد' : 'Add New Customer' ?>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action"     value="add_customer">
                    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">

                    <div class="mb-3">
                        <label class="form-label"><?= $lang['customer_name'] ?> <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control ts-input" required
                               placeholder="<?= $is_rtl ? 'اسم العميل...' : 'Customer name...' ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?= $lang['customer_phone'] ?></label>
                        <input type="tel" name="phone" class="form-control ts-input"
                               placeholder="<?= $is_rtl ? '09XXXXXXXX' : '09XXXXXXXX' ?>">
                    </div>
                    <?php if ($is_super): ?>
                    <div class="mb-3">
                        <label class="form-label"><?= $lang['branch'] ?></label>
                        <select name="registered_branch_id" class="form-select ts-select">
                            <option value=""><?= $is_rtl ? 'غير محدد' : 'Not specified' ?></option>
                            <?php foreach ($branches_list as $br): ?>
                            <option value="<?= $br['id'] ?>"><?= htmlspecialchars($br['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <div class="mb-3">
                        <label class="form-label"><?= $lang['notes'] ?></label>
                        <textarea name="notes" class="form-control ts-input" rows="2"
                                  placeholder="<?= $is_rtl ? 'ملاحظات اختيارية...' : 'Optional notes...' ?>"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= $lang['cancel'] ?></button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-1"></i><?= $lang['save'] ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: تعديل عميل -->
<div class="modal fade" id="editCustomerModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content ts-modal">
            <div class="modal-header">
                <h5 class="modal-title" style="color:var(--ts-gold)">
                    <i class="bi bi-person-gear me-2"></i>
                    <?= $is_rtl ? 'تعديل بيانات العميل' : 'Edit Customer' ?>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action"      value="edit_customer">
                    <input type="hidden" name="csrf_token"  value="<?= generate_csrf_token() ?>">
                    <input type="hidden" name="customer_id" id="edit_customer_id">

                    <div class="mb-3">
                        <label class="form-label"><?= $lang['customer_name'] ?> <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="edit_name" class="form-control ts-input" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?= $lang['customer_phone'] ?></label>
                        <input type="tel" name="phone" id="edit_phone" class="form-control ts-input">
                    </div>
                    <?php if ($is_super): ?>
                    <div class="mb-3">
                        <label class="form-label"><?= $lang['branch'] ?></label>
                        <select name="registered_branch_id" id="edit_branch" class="form-select ts-select">
                            <option value=""><?= $is_rtl ? 'غير محدد' : 'Not specified' ?></option>
                            <?php foreach ($branches_list as $br): ?>
                            <option value="<?= $br['id'] ?>"><?= htmlspecialchars($br['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <div class="mb-3">
                        <label class="form-label"><?= $lang['notes'] ?></label>
                        <textarea name="notes" id="edit_notes" class="form-control ts-input" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= $lang['cancel'] ?></button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-1"></i><?= $lang['save'] ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.querySelectorAll('.btn-edit-customer').forEach(btn => {
    btn.addEventListener('click', function () {
        document.getElementById('edit_customer_id').value = this.dataset.id;
        document.getElementById('edit_name').value        = this.dataset.name;
        document.getElementById('edit_phone').value       = this.dataset.phone;
        document.getElementById('edit_notes').value       = this.dataset.notes;
        const branchSel = document.getElementById('edit_branch');
        if (branchSel) branchSel.value = this.dataset.branch || '';
        new bootstrap.Modal(document.getElementById('editCustomerModal')).show();
    });
});
</script>

<?php require_once __DIR__ . '/../includes/layout/footer.php'; ?>
