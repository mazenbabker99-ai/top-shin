<?php
// ============================================================
// المسار: admin/products.php
// الوظيفة: إدارة المنتجات — جدول + فلترة + Pagination + Modal إضافة/تعديل
// الصلاحية: super_admin | branch_admin
// ============================================================

declare(strict_types=1);

require_once __DIR__ . '/../includes/layout/header.php';
require_role(['super_admin', 'branch_admin']);

$pdo   = db();
$audit = new AuditLogger($pdo);
$flash = get_flash();

// ================================================================
// معالجة POST — إضافة أو تعديل منتج
// ================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_or_die();

    $action = $_POST['action'] ?? '';

    // ——— إضافة منتج ———
    if ($action === 'add') {
        $name_ar       = sanitize($_POST['name_ar']       ?? '');
        $name_en       = sanitize($_POST['name_en']       ?? '');
        $barcode       = sanitize($_POST['barcode']       ?? '');
        $category_id   = sanitize_int($_POST['category_id']   ?? 0) ?: null;
        $unit          = sanitize($_POST['unit']          ?? 'piece');
        $cost_price    = sanitize_float($_POST['cost_price']    ?? 0);
        $selling_price = sanitize_float($_POST['selling_price'] ?? 0);
        $status        = in_array($_POST['status'] ?? '', ['active','inactive'], true) ? $_POST['status'] : 'active';

        if ($name_ar === '' || $selling_price <= 0) {
            flash('error', $lang['required_field']);
            redirect('products.php');
        }

        // تحقق من تكرار الباركود
        if ($barcode !== '') {
            $bcheck = $pdo->prepare("SELECT id FROM products WHERE barcode = ? LIMIT 1");
            $bcheck->execute([$barcode]);
            if ($bcheck->fetch()) {
                flash('error', $is_rtl ? 'الباركود مستخدم مسبقاً.' : 'Barcode already exists.');
                redirect('products.php');
            }
        }

        try {
            $ins = $pdo->prepare("
                INSERT INTO products
                    (category_id, name_ar, name_en, barcode, unit,
                     cost_price, selling_price, image, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, '', ?, NOW())
            ");
            $ins->execute([
                $category_id, $name_ar, $name_en,
                $barcode ?: null,
                $unit, $cost_price, $selling_price, $status,
            ]);
            $new_id = (int)$pdo->lastInsertId();

            $audit->created('products', $new_id, [
                'name_ar'       => $name_ar,
                'selling_price' => $selling_price,
                'cost_price'    => $cost_price,
                'barcode'       => $barcode,
            ]);
            flash('success', $lang['saved']);
        } catch (\PDOException $e) {
            error_log('[products add] ' . $e->getMessage());
            flash('error', $lang['error']);
        }
        redirect('products.php');
    }

    // ——— تعديل منتج ———
    if ($action === 'edit') {
        $id            = sanitize_int($_POST['id']            ?? 0);
        $name_ar       = sanitize($_POST['name_ar']           ?? '');
        $name_en       = sanitize($_POST['name_en']           ?? '');
        $barcode       = sanitize($_POST['barcode']           ?? '');
        $category_id   = sanitize_int($_POST['category_id']   ?? 0) ?: null;
        $unit          = sanitize($_POST['unit']              ?? 'piece');
        $cost_price    = sanitize_float($_POST['cost_price']    ?? 0);
        $selling_price = sanitize_float($_POST['selling_price'] ?? 0);
        $status        = in_array($_POST['status'] ?? '', ['active','inactive'], true) ? $_POST['status'] : 'active';

        if ($id <= 0 || $name_ar === '' || $selling_price <= 0) {
            flash('error', $lang['required_field']);
            redirect('products.php');
        }

        // تحقق من تكرار الباركود لمنتج آخر
        if ($barcode !== '') {
            $bcheck = $pdo->prepare("SELECT id FROM products WHERE barcode = ? AND id != ? LIMIT 1");
            $bcheck->execute([$barcode, $id]);
            if ($bcheck->fetch()) {
                flash('error', $is_rtl ? 'الباركود مستخدم لمنتج آخر.' : 'Barcode used by another product.');
                redirect('products.php');
            }
        }

        // جلب البيانات القديمة لـ audit
        $old_stmt = $pdo->prepare("SELECT * FROM products WHERE id = ? LIMIT 1");
        $old_stmt->execute([$id]);
        $old = $old_stmt->fetch();

        if (!$old) {
            flash('error', $lang['not_found']);
            redirect('products.php');
        }

        try {
            $upd = $pdo->prepare("
                UPDATE products SET
                    category_id   = ?,
                    name_ar       = ?,
                    name_en       = ?,
                    barcode       = ?,
                    unit          = ?,
                    cost_price    = ?,
                    selling_price = ?,
                    status        = ?
                WHERE id = ?
            ");
            $upd->execute([
                $category_id, $name_ar, $name_en,
                $barcode ?: null,
                $unit, $cost_price, $selling_price, $status,
                $id,
            ]);

            // Audit — تسجيل منفصل لتغيير السعر لأهميته
            $old_data = [
                'name_ar'       => $old['name_ar'],
                'name_en'       => $old['name_en'],
                'barcode'       => $old['barcode'],
                'cost_price'    => (float)$old['cost_price'],
                'selling_price' => (float)$old['selling_price'],
                'status'        => $old['status'],
            ];
            $new_data = [
                'name_ar'       => $name_ar,
                'name_en'       => $name_en,
                'barcode'       => $barcode,
                'cost_price'    => $cost_price,
                'selling_price' => $selling_price,
                'status'        => $status,
            ];
            $audit->updated('products', $id, $old_data, $new_data);
            flash('success', $lang['updated']);
        } catch (\PDOException $e) {
            error_log('[products edit] ' . $e->getMessage());
            flash('error', $lang['error']);
        }
        redirect('products.php');
    }

    // ——— تغيير الحالة (soft delete) ———
    if ($action === 'toggle_status') {
        $id = sanitize_int($_POST['id'] ?? 0);
        if ($id > 0) {
            $p = $pdo->prepare("SELECT status, name_ar FROM products WHERE id = ? LIMIT 1");
            $p->execute([$id]);
            $prod = $p->fetch();
            if ($prod) {
                $new_status = $prod['status'] === 'active' ? 'inactive' : 'active';
                $pdo->prepare("UPDATE products SET status = ? WHERE id = ?")->execute([$new_status, $id]);
                $audit->updated('products', $id,
                    ['status' => $prod['status']],
                    ['status' => $new_status]
                );
                flash('success', $new_status === 'active' ? $lang['activated'] : $lang['deactivated']);
            }
        }
        redirect('products.php');
    }
}

// ================================================================
// جلب البيانات مع الفلترة والـ Pagination
// ================================================================
$search      = sanitize($_GET['search']      ?? '');
$cat_filter  = sanitize_int($_GET['category'] ?? 0);
$stat_filter = sanitize($_GET['status']       ?? '');
$cur_page    = max(1, sanitize_int($_GET['page'] ?? 1));
$per_page    = 25;

$where  = ['1=1'];
$params = [];

if ($search !== '') {
    $where[]  = '(p.name_ar LIKE ? OR p.name_en LIKE ? OR p.barcode LIKE ?)';
    $s        = '%' . $search . '%';
    $params[] = $s; $params[] = $s; $params[] = $s;
}
if ($cat_filter > 0) {
    $where[]  = 'p.category_id = ?';
    $params[] = $cat_filter;
}
if (in_array($stat_filter, ['active', 'inactive'], true)) {
    $where[]  = 'p.status = ?';
    $params[] = $stat_filter;
}

$where_sql = implode(' AND ', $where);

// عدد الكل
$count_sql  = "SELECT COUNT(*) FROM products p WHERE {$where_sql}";
$count_stmt = $pdo->prepare($count_sql);
$count_stmt->execute($params);
$total_count = (int)$count_stmt->fetchColumn();
$total_pages = max(1, (int)ceil($total_count / $per_page));
$cur_page    = min($cur_page, $total_pages);
$offset      = ($cur_page - 1) * $per_page;

// جلب المنتجات
$prod_stmt = $pdo->prepare("
    SELECT
        p.id, p.name_ar, p.name_en, p.barcode, p.unit,
        p.cost_price, p.selling_price, p.image, p.status,
        p.created_at, p.category_id,
        c.name_ar AS cat_name_ar,
        c.name_en AS cat_name_en,
        COALESCE(SUM(inv.quantity), 0) AS total_stock
    FROM products p
    LEFT JOIN categories c   ON c.id = p.category_id
    LEFT JOIN inventory  inv ON inv.product_id = p.id
    WHERE {$where_sql}
    GROUP BY p.id
    ORDER BY p.created_at DESC
    LIMIT {$per_page} OFFSET {$offset}
");
$prod_stmt->execute($params);
$products = $prod_stmt->fetchAll();

// جلب التصنيفات النشطة للفلتر والـ Modal
$cats_stmt = $pdo->query("SELECT id, name_ar, name_en FROM categories WHERE status='active' ORDER BY parent_id IS NOT NULL, name_ar");
$categories = $cats_stmt->fetchAll();

$csrf_token = generate_csrf_token();

// بناء query string للـ pagination مع الحفاظ على الفلاتر
function build_page_url(int $page, string $search, int $cat, string $status): string {
    $q = http_build_query(array_filter([
        'page'     => $page,
        'search'   => $search,
        'category' => $cat ?: null,
        'status'   => $status,
    ]));
    return 'products.php?' . $q;
}
?>

<?php include __DIR__ . '/../includes/layout/sidebar.php'; ?>

<div class="main-content">

    <?php show_flash(); ?>

    <!-- رأس الصفحة -->
    <div class="page-header d-flex align-items-center justify-content-between mb-3">
        <div>
            <h4 class="page-title mb-0">
                <i class="bi bi-box-seam-fill me-2" style="color:var(--ts-gold);"></i>
                <?= $lang['products'] ?>
            </h4>
            <small class="text-muted"><?= $total_count ?> <?= $is_rtl ? 'منتج' : 'products' ?></small>
        </div>
        <button class="btn btn-primary btn-sm"
                data-bs-toggle="modal" data-bs-target="#modalAddProduct">
            <i class="bi bi-plus-lg me-1"></i><?= $lang['add'] ?>
        </button>
    </div>

    <!-- ================================================================
         شريط الفلترة
         ================================================================ -->
    <div class="card ts-card mb-3">
        <div class="card-body py-2">
            <form method="GET" class="row g-2 align-items-center">
                <div class="col-12 col-md-4">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text ts-ig-text"><i class="bi bi-search"></i></span>
                        <input type="text" name="search" class="form-control ts-input"
                               value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>"
                               placeholder="<?= $lang['search_product'] ?>">
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <select name="category" class="form-select form-select-sm ts-input">
                        <option value="0"><?= $lang['category'] ?></option>
                        <?php foreach ($categories as $c): ?>
                            <option value="<?= (int)$c['id'] ?>" <?= $cat_filter === (int)$c['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($lang_code === 'ar' ? $c['name_ar'] : ($c['name_en'] ?: $c['name_ar']), ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <select name="status" class="form-select form-select-sm ts-input">
                        <option value=""><?= $lang['status'] ?></option>
                        <option value="active"   <?= $stat_filter === 'active'   ? 'selected' : '' ?>><?= $lang['active'] ?></option>
                        <option value="inactive" <?= $stat_filter === 'inactive' ? 'selected' : '' ?>><?= $lang['inactive'] ?></option>
                    </select>
                </div>
                <div class="col-12 col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-primary btn-sm flex-fill"><?= $lang['filter'] ?></button>
                    <a href="products.php" class="btn btn-outline-secondary btn-sm"><?= $is_rtl ? 'مسح' : 'Clear' ?></a>
                </div>
            </form>
        </div>
    </div>

    <!-- ================================================================
         جدول المنتجات
         ================================================================ -->
    <div class="card ts-card">
        <div class="card-body p-0">
            <?php if (empty($products)): ?>
                <div class="text-center py-5 text-muted">
                    <i class="bi bi-box" style="font-size:2.5rem; opacity:.3; display:block; margin-bottom:.75rem;"></i>
                    <?= $lang['no_results'] ?>
                </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table ts-table mb-0">
                    <thead>
                        <tr>
                            <th style="width:60px;"><?= $is_rtl ? 'صورة' : 'Img' ?></th>
                            <th><?= $lang['product_name'] ?></th>
                            <th><?= $lang['barcode'] ?></th>
                            <th><?= $lang['category'] ?></th>
                            <th><?= $lang['selling_price'] ?></th>
                            <th><?= $lang['cost_price'] ?></th>
                            <th><?= $lang['unit'] ?></th>
                            <th><?= $is_rtl ? 'المخزون' : 'Stock' ?></th>
                            <th><?= $lang['status'] ?></th>
                            <th style="width:110px;"><?= $lang['edit'] ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($products as $prod): ?>
                        <?php
                            $cat_name = $lang_code === 'ar'
                                ? ($prod['cat_name_ar'] ?? '—')
                                : ($prod['cat_name_en'] ?: ($prod['cat_name_ar'] ?? '—'));
                            $display_name = $lang_code === 'ar'
                                ? $prod['name_ar']
                                : ($prod['name_en'] ?: $prod['name_ar']);
                            $stock_val  = (float)$prod['total_stock'];
                            $stock_cls  = $stock_val <= 0 ? 'text-danger' : ($stock_val <= 5 ? 'text-warning' : 'text-success');
                        ?>
                        <tr class="<?= $prod['status'] !== 'active' ? 'opacity-50' : '' ?>">
                            <td>
                                <?php if ($prod['image']): ?>
                                    <img src="../<?= htmlspecialchars($prod['image'], ENT_QUOTES, 'UTF-8') ?>"
                                         width="40" height="40"
                                         style="object-fit:cover; border-radius:6px; border:1px solid var(--ts-border);"
                                         onerror="this.src='../assets/images/no-product.png'">
                                <?php else: ?>
                                    <div style="width:40px; height:40px; background:var(--ts-bg-dark); border-radius:6px;
                                                display:flex; align-items:center; justify-content:center; color:var(--ts-text-muted);">
                                        <i class="bi bi-box"></i>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong><?= htmlspecialchars($display_name, ENT_QUOTES, 'UTF-8') ?></strong>
                                <?php if ($prod['name_en'] && $lang_code === 'ar'): ?>
                                    <div style="font-size:.72rem; color:var(--ts-text-muted);"><?= htmlspecialchars($prod['name_en'], ENT_QUOTES, 'UTF-8') ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <code style="color:var(--ts-silver); font-size:.78rem;">
                                    <?= htmlspecialchars($prod['barcode'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                                </code>
                            </td>
                            <td><span style="font-size:.82rem;"><?= htmlspecialchars($cat_name, ENT_QUOTES, 'UTF-8') ?></span></td>
                            <td><strong style="color:var(--ts-gold);"><?= number_format((float)$prod['selling_price'], 2) ?> SDG</strong></td>
                            <td style="color:var(--ts-text-muted); font-size:.85rem;"><?= number_format((float)$prod['cost_price'], 2) ?> SDG</td>
                            <td><span style="font-size:.8rem;"><?= htmlspecialchars($prod['unit'], ENT_QUOTES, 'UTF-8') ?></span></td>
                            <td>
                                <span class="<?= $stock_cls ?>" style="font-size:.85rem; font-weight:600;">
                                    <?= number_format($stock_val, $stock_val == floor($stock_val) ? 0 : 2) ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($prod['status'] === 'active'): ?>
                                    <span class="badge bg-success"><?= $lang['active'] ?></span>
                                <?php else: ?>
                                    <span class="badge bg-secondary"><?= $lang['inactive'] ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="d-flex gap-1">
                                    <!-- تعديل -->
                                    <button class="btn btn-outline-secondary btn-xs btn-edit-prod"
                                            data-id="<?= (int)$prod['id'] ?>"
                                            data-name_ar="<?= htmlspecialchars($prod['name_ar'], ENT_QUOTES, 'UTF-8') ?>"
                                            data-name_en="<?= htmlspecialchars($prod['name_en'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                            data-barcode="<?= htmlspecialchars($prod['barcode'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                            data-category_id="<?= (int)$prod['category_id'] ?>"
                                            data-unit="<?= htmlspecialchars($prod['unit'], ENT_QUOTES, 'UTF-8') ?>"
                                            data-cost_price="<?= (float)$prod['cost_price'] ?>"
                                            data-selling_price="<?= (float)$prod['selling_price'] ?>"
                                            data-status="<?= htmlspecialchars($prod['status'], ENT_QUOTES, 'UTF-8') ?>"
                                            title="<?= $lang['edit'] ?>">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <!-- صورة -->
                                    <button class="btn btn-outline-secondary btn-xs btn-upload-img"
                                            data-id="<?= (int)$prod['id'] ?>"
                                            data-name="<?= htmlspecialchars($display_name, ENT_QUOTES, 'UTF-8') ?>"
                                            title="<?= $lang['upload_image'] ?>">
                                        <i class="bi bi-camera"></i>
                                    </button>
                                    <!-- تفعيل/تعطيل -->
                                    <form method="POST" style="display:inline;"
                                          onsubmit="return confirm('<?= htmlspecialchars($lang['confirm_action'], ENT_QUOTES, 'UTF-8') ?>')">
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="id" value="<?= (int)$prod['id'] ?>">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                                        <button type="submit"
                                                class="btn btn-xs <?= $prod['status'] === 'active' ? 'btn-outline-danger' : 'btn-outline-success' ?>"
                                                title="<?= $prod['status'] === 'active' ? $lang['deactivate'] : $lang['activate'] ?>">
                                            <i class="bi <?= $prod['status'] === 'active' ? 'bi-slash-circle' : 'bi-check-circle' ?>"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <div class="d-flex align-items-center justify-content-between px-3 py-2"
                 style="border-top:1px solid var(--ts-border);">
                <small class="text-muted">
                    <?= $lang['page'] ?> <?= $cur_page ?> <?= $lang['of'] ?> <?= $total_pages ?>
                    — <?= $total_count ?> <?= $is_rtl ? 'منتج' : 'products' ?>
                </small>
                <nav>
                    <ul class="pagination pagination-sm mb-0">
                        <?php if ($cur_page > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="<?= build_page_url($cur_page - 1, $search, $cat_filter, $stat_filter) ?>">
                                    <?= $lang['previous'] ?>
                                </a>
                            </li>
                        <?php endif; ?>
                        <?php
                        $start = max(1, $cur_page - 2);
                        $end   = min($total_pages, $cur_page + 2);
                        for ($p = $start; $p <= $end; $p++):
                        ?>
                            <li class="page-item <?= $p === $cur_page ? 'active' : '' ?>">
                                <a class="page-link" href="<?= build_page_url($p, $search, $cat_filter, $stat_filter) ?>"><?= $p ?></a>
                            </li>
                        <?php endfor; ?>
                        <?php if ($cur_page < $total_pages): ?>
                            <li class="page-item">
                                <a class="page-link" href="<?= build_page_url($cur_page + 1, $search, $cat_filter, $stat_filter) ?>">
                                    <?= $lang['next'] ?>
                                </a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </nav>
            </div>
            <?php endif; ?>

            <?php endif; ?>
        </div>
    </div>

</div><!-- /.main-content -->

<!-- ================================================================
     Modal — إضافة منتج
     ================================================================ -->
<div class="modal fade" id="modalAddProduct" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content ts-modal">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-plus-circle me-2" style="color:var(--ts-gold);"></i>
                    <?= $is_rtl ? 'إضافة منتج جديد' : 'Add New Product' ?>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="formAddProduct">
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label"><?= $is_rtl ? 'الاسم بالعربية' : 'Arabic Name' ?> <span class="text-danger">*</span></label>
                            <input type="text" name="name_ar" class="form-control ts-input" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= $is_rtl ? 'الاسم بالإنجليزية' : 'English Name' ?></label>
                            <input type="text" name="name_en" class="form-control ts-input">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= $lang['barcode'] ?></label>
                            <div class="input-group">
                                <input type="text" name="barcode" id="addBarcode" class="form-control ts-input"
                                       placeholder="<?= $is_rtl ? 'أو اتركه فارغاً للتوليد' : 'Leave empty to generate' ?>">
                                <button type="button" class="btn btn-outline-secondary btn-sm" id="btnGenerateBarcode">
                                    <i class="bi bi-upc-scan"></i>
                                </button>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= $lang['category'] ?></label>
                            <select name="category_id" class="form-select ts-input">
                                <option value="0"><?= $is_rtl ? '— بدون تصنيف —' : '— No category —' ?></option>
                                <?php foreach ($categories as $c): ?>
                                    <option value="<?= (int)$c['id'] ?>">
                                        <?= htmlspecialchars($lang_code === 'ar' ? $c['name_ar'] : ($c['name_en'] ?: $c['name_ar']), ENT_QUOTES, 'UTF-8') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label"><?= $lang['unit'] ?></label>
                            <select name="unit" class="form-select ts-input">
                                <option value="piece"><?= $lang['unit_piece'] ?></option>
                                <option value="ml"><?= $lang['unit_ml'] ?></option>
                                <option value="gram"><?= $lang['unit_gram'] ?></option>
                                <option value="kg"><?= $lang['unit_kg'] ?></option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label"><?= $lang['cost_price'] ?> (SDG)</label>
                            <input type="number" name="cost_price" class="form-control ts-input"
                                   value="0" min="0" step="0.01">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label"><?= $lang['selling_price'] ?> (SDG) <span class="text-danger">*</span></label>
                            <input type="number" name="selling_price" class="form-control ts-input"
                                   value="0" min="0.01" step="0.01" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label"><?= $lang['status'] ?></label>
                            <select name="status" class="form-select ts-input">
                                <option value="active"><?= $lang['active'] ?></option>
                                <option value="inactive"><?= $lang['inactive'] ?></option>
                            </select>
                        </div>
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

<!-- ================================================================
     Modal — تعديل منتج
     ================================================================ -->
<div class="modal fade" id="modalEditProduct" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content ts-modal">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-pencil-square me-2" style="color:var(--ts-gold);"></i>
                    <?= $is_rtl ? 'تعديل منتج' : 'Edit Product' ?>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="id" id="editProdId">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label"><?= $is_rtl ? 'الاسم بالعربية' : 'Arabic Name' ?> <span class="text-danger">*</span></label>
                            <input type="text" name="name_ar" id="editProdNameAr" class="form-control ts-input" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= $is_rtl ? 'الاسم بالإنجليزية' : 'English Name' ?></label>
                            <input type="text" name="name_en" id="editProdNameEn" class="form-control ts-input">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= $lang['barcode'] ?></label>
                            <input type="text" name="barcode" id="editProdBarcode" class="form-control ts-input">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= $lang['category'] ?></label>
                            <select name="category_id" id="editProdCategoryId" class="form-select ts-input">
                                <option value="0"><?= $is_rtl ? '— بدون تصنيف —' : '— No category —' ?></option>
                                <?php foreach ($categories as $c): ?>
                                    <option value="<?= (int)$c['id'] ?>">
                                        <?= htmlspecialchars($lang_code === 'ar' ? $c['name_ar'] : ($c['name_en'] ?: $c['name_ar']), ENT_QUOTES, 'UTF-8') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label"><?= $lang['unit'] ?></label>
                            <select name="unit" id="editProdUnit" class="form-select ts-input">
                                <option value="piece"><?= $lang['unit_piece'] ?></option>
                                <option value="ml"><?= $lang['unit_ml'] ?></option>
                                <option value="gram"><?= $lang['unit_gram'] ?></option>
                                <option value="kg"><?= $lang['unit_kg'] ?></option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label"><?= $lang['cost_price'] ?> (SDG)</label>
                            <input type="number" name="cost_price" id="editProdCostPrice"
                                   class="form-control ts-input" min="0" step="0.01">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label"><?= $lang['selling_price'] ?> (SDG) <span class="text-danger">*</span></label>
                            <input type="number" name="selling_price" id="editProdSellingPrice"
                                   class="form-control ts-input" min="0.01" step="0.01" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label"><?= $lang['status'] ?></label>
                            <select name="status" id="editProdStatus" class="form-select ts-input">
                                <option value="active"><?= $lang['active'] ?></option>
                                <option value="inactive"><?= $lang['inactive'] ?></option>
                            </select>
                        </div>
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

<!-- ================================================================
     Modal — رفع صورة
     ================================================================ -->
<div class="modal fade" id="modalUploadImage" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content ts-modal">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-camera me-2" style="color:var(--ts-gold);"></i>
                    <?= $lang['upload_image'] ?>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted" style="font-size:.82rem;" id="uploadProductName"></p>
                <!-- Preview -->
                <div id="imgPreviewWrap" style="display:none; margin-bottom:.75rem; text-align:center;">
                    <img id="imgPreview" style="max-width:100%; max-height:200px; border-radius:8px; border:1px solid var(--ts-border);">
                </div>
                <input type="file" id="imageFileInput" class="form-control ts-input" accept="image/jpeg,image/png,image/webp">
                <small class="text-muted d-block mt-1">
                    <?= $is_rtl
                        ? 'الأنواع: jpg, png, webp — الحد الأقصى: 2MB'
                        : 'Types: jpg, png, webp — Max: 2MB' ?>
                </small>
                <div id="uploadProgress" style="display:none; margin-top:.75rem;">
                    <div class="progress" style="height:4px; background:var(--ts-border);">
                        <div class="progress-bar" style="width:0%; background:var(--ts-gold);"></div>
                    </div>
                </div>
                <div id="uploadMsg" class="mt-2" style="font-size:.82rem;"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal"><?= $lang['cancel'] ?></button>
                <button type="button" class="btn btn-primary btn-sm" id="btnDoUpload" disabled>
                    <i class="bi bi-cloud-upload me-1"></i><?= $is_rtl ? 'رفع' : 'Upload' ?>
                </button>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/layout/footer.php'; ?>

<script>
(function() {
    'use strict';

    const CSRF    = <?= json_encode($csrf_token) ?>;
    const BASE    = '../';
    const LANG    = <?= json_encode($lang_code) ?>;

    // ——— توليد باركود ———
    document.getElementById('btnGenerateBarcode')?.addEventListener('click', function() {
        var ts   = Date.now().toString().slice(-8);
        var rand = Math.floor(Math.random() * 9000 + 1000);
        document.getElementById('addBarcode').value = ts + rand;
    });

    // ——— تعبئة Modal التعديل ———
    document.querySelectorAll('.btn-edit-prod').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var d = this.dataset;
            document.getElementById('editProdId').value          = d.id;
            document.getElementById('editProdNameAr').value      = d.name_ar;
            document.getElementById('editProdNameEn').value      = d.name_en;
            document.getElementById('editProdBarcode').value     = d.barcode;
            document.getElementById('editProdCostPrice').value   = d.cost_price;
            document.getElementById('editProdSellingPrice').value = d.selling_price;

            // التصنيف
            var catSel = document.getElementById('editProdCategoryId');
            for (var i = 0; i < catSel.options.length; i++) {
                catSel.options[i].selected = (catSel.options[i].value === d.category_id);
            }
            // الوحدة
            var unitSel = document.getElementById('editProdUnit');
            for (var j = 0; j < unitSel.options.length; j++) {
                unitSel.options[j].selected = (unitSel.options[j].value === d.unit);
            }
            // الحالة
            var statSel = document.getElementById('editProdStatus');
            for (var k = 0; k < statSel.options.length; k++) {
                statSel.options[k].selected = (statSel.options[k].value === d.status);
            }

            new bootstrap.Modal(document.getElementById('modalEditProduct')).show();
        });
    });

    // ——— Modal رفع الصورة ———
    var uploadProductId = null;

    document.querySelectorAll('.btn-upload-img').forEach(function(btn) {
        btn.addEventListener('click', function() {
            uploadProductId = this.dataset.id;
            document.getElementById('uploadProductName').textContent = this.dataset.name;
            document.getElementById('imgPreviewWrap').style.display = 'none';
            document.getElementById('imageFileInput').value = '';
            document.getElementById('uploadMsg').textContent = '';
            document.getElementById('btnDoUpload').disabled = true;
            new bootstrap.Modal(document.getElementById('modalUploadImage')).show();
        });
    });

    // preview الصورة قبل الرفع
    document.getElementById('imageFileInput')?.addEventListener('change', function() {
        var file = this.files[0];
        var btn  = document.getElementById('btnDoUpload');
        var msg  = document.getElementById('uploadMsg');
        msg.textContent = '';

        if (!file) { btn.disabled = true; return; }

        if (file.size > 2 * 1024 * 1024) {
            msg.textContent = LANG === 'ar' ? '❌ حجم الصورة يتجاوز 2MB.' : '❌ Image exceeds 2MB.';
            msg.style.color = '#dc3545';
            btn.disabled = true;
            return;
        }

        var reader = new FileReader();
        reader.onload = function(e) {
            document.getElementById('imgPreview').src = e.target.result;
            document.getElementById('imgPreviewWrap').style.display = 'block';
        };
        reader.readAsDataURL(file);
        btn.disabled = false;
    });

    // رفع الصورة
    document.getElementById('btnDoUpload')?.addEventListener('click', function() {
        var file = document.getElementById('imageFileInput').files[0];
        if (!file || !uploadProductId) return;

        var msg    = document.getElementById('uploadMsg');
        var prog   = document.getElementById('uploadProgress');
        var bar    = prog.querySelector('.progress-bar');
        var btn    = this;

        btn.disabled = true;
        prog.style.display = 'block';
        bar.style.width = '30%';
        msg.textContent = '';

        var fd = new FormData();
        fd.append('product_id', uploadProductId);
        fd.append('image',      file);
        fd.append('csrf_token', CSRF);

        fetch(BASE + 'api/upload_image.php', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                bar.style.width = '100%';
                if (data.success) {
                    msg.textContent = LANG === 'ar' ? '✅ تم رفع الصورة بنجاح.' : '✅ Image uploaded.';
                    msg.style.color = '#28a745';
                    setTimeout(function() { location.reload(); }, 1200);
                } else {
                    msg.textContent = '❌ ' + (data.message || (LANG === 'ar' ? 'فشل الرفع' : 'Upload failed'));
                    msg.style.color = '#dc3545';
                    btn.disabled = false;
                }
            })
            .catch(function() {
                msg.textContent = LANG === 'ar' ? '❌ خطأ في الاتصال.' : '❌ Connection error.';
                msg.style.color = '#dc3545';
                btn.disabled = false;
            })
            .finally(function() {
                setTimeout(function() { prog.style.display = 'none'; bar.style.width = '0%'; }, 1500);
            });
    });

})();
</script>
