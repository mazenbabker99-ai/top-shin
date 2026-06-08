<?php
// ============================================================
// المسار: admin/categories.php
// الوظيفة: إدارة التصنيفات — عرض كشجرة + إضافة + تعديل + تعطيل
// الصلاحية: super_admin | branch_admin
// ============================================================

declare(strict_types=1);

require_once __DIR__ . '/../includes/layout/header.php';
require_role(['super_admin', 'branch_admin']);

$pdo   = db();
$audit = new AuditLogger($pdo);
$flash = get_flash();

// ================================================================
// معالجة POST
// ================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_or_die();

    $action = $_POST['action'] ?? '';

    // ——— إضافة تصنيف ———
    if ($action === 'add') {
        $name_ar   = sanitize($_POST['name_ar']   ?? '');
        $name_en   = sanitize($_POST['name_en']   ?? '');
        $parent_id = sanitize_int($_POST['parent_id'] ?? 0) ?: null;

        if ($name_ar === '') {
            flash('error', $lang['required_field'] . ' — ' . ($lang_code === 'ar' ? 'الاسم بالعربية' : 'Arabic name'));
        } else {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO categories (name_ar, name_en, parent_id, status)
                    VALUES (?, ?, ?, 'active')
                ");
                $stmt->execute([$name_ar, $name_en, $parent_id]);
                $new_id = (int)$pdo->lastInsertId();
                $audit->created('categories', $new_id, [
                    'name_ar' => $name_ar, 'name_en' => $name_en, 'parent_id' => $parent_id,
                ]);
                flash('success', $lang['saved']);
            } catch (\PDOException $e) {
                error_log('[categories add] ' . $e->getMessage());
                flash('error', $lang['error']);
            }
        }
        redirect('categories.php');
    }

    // ——— تعديل تصنيف ———
    if ($action === 'edit') {
        $id        = sanitize_int($_POST['id'] ?? 0);
        $name_ar   = sanitize($_POST['name_ar'] ?? '');
        $name_en   = sanitize($_POST['name_en'] ?? '');
        $parent_id = sanitize_int($_POST['parent_id'] ?? 0) ?: null;

        if ($id <= 0 || $name_ar === '') {
            flash('error', $lang['required_field']);
        } else {
            // منع جعل التصنيف أباً لنفسه
            if ($parent_id === $id) {
                flash('error', $is_rtl ? 'لا يمكن أن يكون التصنيف أباً لنفسه.' : 'Category cannot be its own parent.');
                redirect('categories.php');
            }

            $old_stmt = $pdo->prepare("SELECT * FROM categories WHERE id = ? LIMIT 1");
            $old_stmt->execute([$id]);
            $old = $old_stmt->fetch();

            if ($old) {
                $upd = $pdo->prepare("
                    UPDATE categories
                    SET name_ar = ?, name_en = ?, parent_id = ?
                    WHERE id = ?
                ");
                $upd->execute([$name_ar, $name_en, $parent_id, $id]);
                $audit->updated('categories', $id,
                    ['name_ar' => $old['name_ar'], 'name_en' => $old['name_en'], 'parent_id' => $old['parent_id']],
                    ['name_ar' => $name_ar,        'name_en' => $name_en,        'parent_id' => $parent_id]
                );
                flash('success', $lang['updated']);
            } else {
                flash('error', $lang['not_found']);
            }
        }
        redirect('categories.php');
    }

    // ——— تغيير الحالة (تفعيل / تعطيل) ———
    if ($action === 'toggle_status') {
        $id = sanitize_int($_POST['id'] ?? 0);
        if ($id > 0) {
            // تحقق من المنتجات المرتبطة قبل التعطيل
            $check = $pdo->prepare("SELECT COUNT(*) FROM products WHERE category_id = ? AND status = 'active'");
            $check->execute([$id]);
            $prod_count = (int)$check->fetchColumn();

            $cat_stmt = $pdo->prepare("SELECT status, name_ar FROM categories WHERE id = ? LIMIT 1");
            $cat_stmt->execute([$id]);
            $cat = $cat_stmt->fetch();

            if ($cat) {
                if ($cat['status'] === 'active' && $prod_count > 0) {
                    flash('error',
                        ($is_rtl
                            ? "لا يمكن تعطيل التصنيف — يحتوي على {$prod_count} منتج نشط."
                            : "Cannot deactivate — {$prod_count} active products linked.")
                    );
                } else {
                    $new_status = $cat['status'] === 'active' ? 'inactive' : 'active';
                    $pdo->prepare("UPDATE categories SET status = ? WHERE id = ?")->execute([$new_status, $id]);
                    $audit->updated('categories', $id,
                        ['status' => $cat['status']],
                        ['status' => $new_status]
                    );
                    flash('success', $new_status === 'active' ? $lang['activated'] : $lang['deactivated']);
                }
            }
        }
        redirect('categories.php');
    }
}

// ================================================================
// جلب التصنيفات كشجرة
// ================================================================

// جلب كل التصنيفات مع عدد المنتجات
$all_cats_stmt = $pdo->prepare("
    SELECT
        c.id, c.name_ar, c.name_en, c.parent_id, c.status,
        COUNT(p.id) AS product_count
    FROM categories c
    LEFT JOIN products p ON p.category_id = c.id AND p.status = 'active'
    GROUP BY c.id
    ORDER BY c.parent_id IS NOT NULL, c.parent_id, c.name_ar
");
$all_cats_stmt->execute();
$all_cats_raw = $all_cats_stmt->fetchAll();

// بناء الشجرة
$cats_by_id = [];
$roots      = [];
foreach ($all_cats_raw as $cat) {
    $cats_by_id[$cat['id']] = $cat;
    $cats_by_id[$cat['id']]['children'] = [];
}
foreach ($cats_by_id as $id => $cat) {
    if ($cat['parent_id'] && isset($cats_by_id[$cat['parent_id']])) {
        $cats_by_id[$cat['parent_id']]['children'][] = &$cats_by_id[$id];
    } else {
        $roots[] = &$cats_by_id[$id];
    }
}

// التصنيفات الرئيسية للـ select
$parent_options = array_filter($all_cats_raw, fn($c) => is_null($c['parent_id']));

$csrf_token = generate_csrf_token();
?>

<?php include __DIR__ . '/../includes/layout/sidebar.php'; ?>

<!-- ================================================================
     المحتوى الرئيسي
     ================================================================ -->
<div class="main-content">

    <!-- Flash -->
    <?php show_flash(); ?>

    <!-- رأس الصفحة -->
    <div class="page-header d-flex align-items-center justify-content-between mb-4">
        <div>
            <h4 class="page-title mb-0">
                <i class="bi bi-tags-fill me-2" style="color:var(--ts-gold);"></i>
                <?= $lang['categories'] ?>
            </h4>
            <small class="text-muted">
                <?= count($all_cats_raw) ?> <?= $is_rtl ? 'تصنيف' : 'categories' ?>
            </small>
        </div>
        <button class="btn btn-primary btn-sm"
                data-bs-toggle="modal" data-bs-target="#modalAddCategory">
            <i class="bi bi-plus-lg me-1"></i><?= $lang['add'] ?>
        </button>
    </div>

    <!-- ================================================================
         شجرة التصنيفات
         ================================================================ -->
    <div class="card ts-card">
        <div class="card-body p-0">
            <?php if (empty($roots)): ?>
                <div class="text-center py-5 text-muted">
                    <i class="bi bi-tags" style="font-size:2.5rem; opacity:.3; display:block; margin-bottom:.75rem;"></i>
                    <?= $is_rtl ? 'لا توجد تصنيفات بعد.' : 'No categories yet.' ?>
                </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table ts-table mb-0">
                    <thead>
                        <tr>
                            <th style="width:40px;">#</th>
                            <th><?= $is_rtl ? 'الاسم بالعربية' : 'Arabic Name' ?></th>
                            <th><?= $is_rtl ? 'الاسم بالإنجليزية' : 'English Name' ?></th>
                            <th><?= $is_rtl ? 'النوع' : 'Type' ?></th>
                            <th><?= $is_rtl ? 'المنتجات' : 'Products' ?></th>
                            <th><?= $lang['status'] ?></th>
                            <th><?= $lang['edit'] ?> / <?= $lang['deactivate'] ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        // دالة تعرض الشجرة بشكل متكرر
                        function render_category_row(array $cat, int $depth, string $lang_code, array $lang, string $csrf, bool $is_rtl): void {
                            $indent  = str_repeat('&nbsp;&nbsp;&nbsp;&nbsp;', $depth);
                            $icon    = $depth === 0 ? '📁' : '↳';
                            $name    = $lang_code === 'ar' ? $cat['name_ar'] : ($cat['name_en'] ?: $cat['name_ar']);
                            $badge   = $cat['status'] === 'active'
                                ? '<span class="badge bg-success">' . $lang['active'] . '</span>'
                                : '<span class="badge bg-secondary">' . $lang['inactive'] . '</span>';
                            $type    = $depth === 0
                                ? '<span class="badge" style="background:var(--ts-bg-dark);color:var(--ts-gold);border:1px solid var(--ts-gold);">' . ($is_rtl ? 'رئيسي' : 'Main') . '</span>'
                                : '<span class="badge bg-secondary" style="opacity:.75;">' . ($is_rtl ? 'فرعي' : 'Sub') . '</span>';
                            $toggle_label = $cat['status'] === 'active' ? $lang['deactivate'] : $lang['activate'];
                            $toggle_class = $cat['status'] === 'active' ? 'btn-outline-danger' : 'btn-outline-success';

                            echo "<tr class='cat-row depth-{$depth}" . ($cat['status'] !== 'active' ? " opacity-50" : "") . "'>";
                            echo "<td style='color:var(--ts-text-muted); font-size:.8rem;'>{$cat['id']}</td>";
                            echo "<td>{$indent}{$icon} <strong>" . htmlspecialchars($cat['name_ar'], ENT_QUOTES, 'UTF-8') . "</strong></td>";
                            echo "<td>" . htmlspecialchars($cat['name_en'] ?? '', ENT_QUOTES, 'UTF-8') . "</td>";
                            echo "<td>{$type}</td>";
                            echo "<td><span class='badge' style='background:var(--ts-bg-dark); color:var(--ts-silver);'>{$cat['product_count']}</span></td>";
                            echo "<td>{$badge}</td>";
                            echo "<td>
                                    <div class='d-flex gap-1'>
                                        <button class='btn btn-outline-secondary btn-xs btn-edit-cat'
                                                data-id='{$cat['id']}'
                                                data-name_ar='" . htmlspecialchars($cat['name_ar'], ENT_QUOTES, 'UTF-8') . "'
                                                data-name_en='" . htmlspecialchars($cat['name_en'] ?? '', ENT_QUOTES, 'UTF-8') . "'
                                                data-parent_id='" . ($cat['parent_id'] ?? '') . "'>
                                            <i class='bi bi-pencil'></i>
                                        </button>
                                        <form method='POST' style='display:inline;' onsubmit=\"return confirm('{$lang['confirm_action']}')\">
                                            <input type='hidden' name='action' value='toggle_status'>
                                            <input type='hidden' name='id' value='{$cat['id']}'>
                                            <input type='hidden' name='csrf_token' value='{$csrf}'>
                                            <button type='submit' class='btn {$toggle_class} btn-xs'>
                                                " . ($cat['status'] === 'active' ? '<i class="bi bi-slash-circle"></i>' : '<i class="bi bi-check-circle"></i>') . "
                                            </button>
                                        </form>
                                    </div>
                                  </td>";
                            echo "</tr>";

                            foreach ($cat['children'] as $child) {
                                render_category_row($child, $depth + 1, $lang_code, $lang, $csrf, $is_rtl);
                            }
                        }

                        foreach ($roots as $root) {
                            render_category_row($root, 0, $lang_code, $lang, $csrf_token, $is_rtl);
                        }
                        ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

</div><!-- /.main-content -->

<!-- ================================================================
     Modal — إضافة تصنيف
     ================================================================ -->
<div class="modal fade" id="modalAddCategory" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content ts-modal">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-plus-circle me-2" style="color:var(--ts-gold);"></i>
                    <?= $is_rtl ? 'إضافة تصنيف جديد' : 'Add New Category' ?>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">
                            <?= $is_rtl ? 'الاسم بالعربية' : 'Arabic Name' ?>
                            <span class="text-danger">*</span>
                        </label>
                        <input type="text" name="name_ar" class="form-control ts-input"
                               required placeholder="<?= $is_rtl ? 'مثال: مستحضرات العناية بالبشرة' : 'e.g. Skin Care' ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?= $is_rtl ? 'الاسم بالإنجليزية' : 'English Name' ?></label>
                        <input type="text" name="name_en" class="form-control ts-input"
                               placeholder="<?= $is_rtl ? 'اختياري' : 'Optional' ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?= $lang['parent_category'] ?></label>
                        <select name="parent_id" class="form-select ts-input">
                            <option value="0"><?= $is_rtl ? '— تصنيف رئيسي —' : '— Main Category —' ?></option>
                            <?php foreach ($parent_options as $po): ?>
                                <option value="<?= (int)$po['id'] ?>">
                                    <?= htmlspecialchars($lang_code === 'ar' ? $po['name_ar'] : ($po['name_en'] ?: $po['name_ar']), ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
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
     Modal — تعديل تصنيف
     ================================================================ -->
<div class="modal fade" id="modalEditCategory" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content ts-modal">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-pencil-square me-2" style="color:var(--ts-gold);"></i>
                    <?= $is_rtl ? 'تعديل التصنيف' : 'Edit Category' ?>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="id" id="editCatId">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">
                            <?= $is_rtl ? 'الاسم بالعربية' : 'Arabic Name' ?>
                            <span class="text-danger">*</span>
                        </label>
                        <input type="text" name="name_ar" id="editCatNameAr"
                               class="form-control ts-input" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?= $is_rtl ? 'الاسم بالإنجليزية' : 'English Name' ?></label>
                        <input type="text" name="name_en" id="editCatNameEn" class="form-control ts-input">
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?= $lang['parent_category'] ?></label>
                        <select name="parent_id" id="editCatParentId" class="form-select ts-input">
                            <option value="0"><?= $is_rtl ? '— تصنيف رئيسي —' : '— Main Category —' ?></option>
                            <?php foreach ($parent_options as $po): ?>
                                <option value="<?= (int)$po['id'] ?>">
                                    <?= htmlspecialchars($lang_code === 'ar' ? $po['name_ar'] : ($po['name_en'] ?: $po['name_ar']), ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
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

<?php include __DIR__ . '/../includes/layout/footer.php'; ?>

<script>
// ——— فتح Modal التعديل وتعبئة البيانات ———
document.querySelectorAll('.btn-edit-cat').forEach(function(btn) {
    btn.addEventListener('click', function() {
        document.getElementById('editCatId').value       = this.dataset.id;
        document.getElementById('editCatNameAr').value   = this.dataset.name_ar;
        document.getElementById('editCatNameEn').value   = this.dataset.name_en;
        var parentSel = document.getElementById('editCatParentId');
        var pid       = this.dataset.parent_id || '0';
        for (var i = 0; i < parentSel.options.length; i++) {
            parentSel.options[i].selected = (parentSel.options[i].value === pid);
        }
        var modal = new bootstrap.Modal(document.getElementById('modalEditCategory'));
        modal.show();
    });
});
</script>
