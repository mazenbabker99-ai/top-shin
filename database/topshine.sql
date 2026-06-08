-- ============================================================
-- TOP SHINE POS — قاعدة البيانات الكاملة
-- المسار: database/topshine.sql
-- الإصدار: 1.0.0
-- الترميز: utf8mb4_unicode_ci
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';
SET time_zone = '+03:00';

-- ============================================================
-- إنشاء قاعدة البيانات
-- ============================================================
CREATE DATABASE IF NOT EXISTS topshine_db
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE topshine_db;

-- ============================================================
-- 1. الفروع / Branches
-- ============================================================
CREATE TABLE branches (
    id      INT PRIMARY KEY AUTO_INCREMENT,
    name    VARCHAR(100) NOT NULL,
    code    VARCHAR(10)  UNIQUE NOT NULL,
    address TEXT,
    phone   VARCHAR(20),
    status  ENUM('active','inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 2. المستخدمون / Users
-- ============================================================
CREATE TABLE users (
    id        INT PRIMARY KEY AUTO_INCREMENT,
    branch_id INT NULL,
    name      VARCHAR(100) NOT NULL,
    username  VARCHAR(50)  UNIQUE NOT NULL,
    password  VARCHAR(255) NOT NULL,
    role      ENUM('super_admin','branch_admin','cashier') NOT NULL,
    lang      ENUM('ar','en') DEFAULT 'ar',
    status    ENUM('active','inactive') DEFAULT 'active',
    last_login TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 3. التصنيفات / Categories
-- ============================================================
CREATE TABLE categories (
    id        INT PRIMARY KEY AUTO_INCREMENT,
    name_ar   VARCHAR(100) NOT NULL,
    name_en   VARCHAR(100),
    parent_id INT NULL,
    status    ENUM('active','inactive') DEFAULT 'active',
    FOREIGN KEY (parent_id) REFERENCES categories(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 4. الموردون / Suppliers
-- ============================================================
CREATE TABLE suppliers (
    id         INT PRIMARY KEY AUTO_INCREMENT,
    name       VARCHAR(150) NOT NULL,
    phone      VARCHAR(30),
    email      VARCHAR(150),
    address    TEXT,
    balance    DECIMAL(12,2) DEFAULT 0.00 COMMENT 'المبلغ المستحق للمورد (موجب = مدين)',
    notes      TEXT,
    status     ENUM('active','inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 5. المنتجات / Products
-- ============================================================
CREATE TABLE products (
    id            INT PRIMARY KEY AUTO_INCREMENT,
    category_id   INT NULL,
    name_ar       VARCHAR(200) NOT NULL,
    name_en       VARCHAR(200),
    barcode       VARCHAR(100) UNIQUE,
    unit          VARCHAR(50) COMMENT 'قطعة / مل / غرام / كيلو',
    cost_price    DECIMAL(12,2) DEFAULT 0.00,
    selling_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    image         VARCHAR(255),
    status        ENUM('active','inactive') DEFAULT 'active',
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 6. المخزون / Inventory
-- branch_id = NULL  →  مخزون مركزي
-- branch_id = X     →  مخزون فرع X
-- ============================================================
CREATE TABLE inventory (
    id           INT PRIMARY KEY AUTO_INCREMENT,
    product_id   INT NOT NULL,
    branch_id    INT NULL COMMENT 'NULL = مركزي',
    quantity     DECIMAL(12,3) DEFAULT 0.000,
    min_quantity DECIMAL(12,3) DEFAULT 0.000 COMMENT 'الحد الأدنى للتنبيه',
    updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_product_branch (product_id, branch_id),
    FOREIGN KEY (product_id) REFERENCES products(id) ON UPDATE CASCADE,
    FOREIGN KEY (branch_id)  REFERENCES branches(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 7. حركات المخزون / Inventory Movements
-- ============================================================
CREATE TABLE inventory_movements (
    id              INT PRIMARY KEY AUTO_INCREMENT,
    product_id      INT NOT NULL,
    branch_id       INT NULL,
    movement_type   ENUM(
                        'purchase',
                        'sale',
                        'transfer_in',
                        'transfer_out',
                        'return_in',
                        'adjustment'
                    ) NOT NULL,
    quantity        DECIMAL(12,3) NOT NULL COMMENT 'موجب = دخول / سالب = خروج',
    quantity_before DECIMAL(12,3) NOT NULL DEFAULT 0.000,
    quantity_after  DECIMAL(12,3) NOT NULL DEFAULT 0.000,
    reference_id    INT NULL    COMMENT 'invoice_id / po_id / transfer_id ...',
    reference_type  VARCHAR(50) NULL COMMENT 'invoice / purchase_order / transfer ...',
    notes           TEXT,
    created_by      INT NOT NULL COMMENT 'user_id',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id) ON UPDATE CASCADE,
    FOREIGN KEY (branch_id)  REFERENCES branches(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 8. أوامر الشراء / Purchase Orders
-- ============================================================
CREATE TABLE purchase_orders (
    id           INT PRIMARY KEY AUTO_INCREMENT,
    po_number    VARCHAR(30) UNIQUE NOT NULL,
    supplier_id  INT NOT NULL,
    branch_id    INT NULL COMMENT 'وجهة الاستلام — NULL = مركزي',
    total_amount DECIMAL(12,2) DEFAULT 0.00,
    paid_amount  DECIMAL(12,2) DEFAULT 0.00,
    status       ENUM('draft','confirmed','received','partial','cancelled') DEFAULT 'draft',
    notes        TEXT,
    created_by   INT NOT NULL,
    received_at  TIMESTAMP NULL,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON UPDATE CASCADE,
    FOREIGN KEY (branch_id)   REFERENCES branches(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 9. بنود أوامر الشراء / Purchase Order Items
-- ============================================================
CREATE TABLE purchase_order_items (
    id                INT PRIMARY KEY AUTO_INCREMENT,
    po_id             INT NOT NULL,
    product_id        INT NOT NULL,
    product_name      VARCHAR(200) NOT NULL COMMENT 'نسخة مجمّدة من الاسم وقت الشراء',
    quantity          DECIMAL(12,3) NOT NULL DEFAULT 0.000,
    quantity_received DECIMAL(12,3) NOT NULL DEFAULT 0.000,
    unit_cost         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total             DECIMAL(12,2) GENERATED ALWAYS AS (quantity * unit_cost) STORED,
    FOREIGN KEY (po_id)       REFERENCES purchase_orders(id) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (product_id)  REFERENCES products(id) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 10. مدفوعات الموردين / Supplier Payments
-- ============================================================
CREATE TABLE supplier_payments (
    id             INT PRIMARY KEY AUTO_INCREMENT,
    supplier_id    INT NOT NULL,
    po_id          INT NULL COMMENT 'مرتبط بأمر شراء — اختياري',
    amount         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    payment_method ENUM('cash','bank_transfer','check','other') DEFAULT 'cash',
    payment_date   DATE NOT NULL,
    notes          TEXT,
    created_by     INT NOT NULL,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON UPDATE CASCADE,
    FOREIGN KEY (po_id)       REFERENCES purchase_orders(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 11. العملاء / Customers
-- registered_branch_id: مرجعي فقط لمعرفة فرع التسجيل الأول
-- ============================================================
CREATE TABLE customers (
    id                   INT PRIMARY KEY AUTO_INCREMENT,
    name                 VARCHAR(150) NOT NULL,
    phone                VARCHAR(30),
    registered_branch_id INT NULL COMMENT 'فرع التسجيل الأول — مرجعي فقط',
    total_purchases      DECIMAL(12,2) DEFAULT 0.00,
    notes                TEXT,
    status               ENUM('active','inactive') DEFAULT 'active',
    created_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (registered_branch_id) REFERENCES branches(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 12. الفواتير / Invoices
-- ============================================================
CREATE TABLE invoices (
    id              INT PRIMARY KEY AUTO_INCREMENT,
    invoice_number  VARCHAR(40) UNIQUE NOT NULL,
    branch_id       INT NOT NULL,
    cashier_id      INT NOT NULL,
    customer_id     INT NULL,
    subtotal        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    discount_type   ENUM('fixed','percent') DEFAULT 'fixed',
    discount_value  DECIMAL(12,2) DEFAULT 0.00,
    discount_amount DECIMAL(12,2) DEFAULT 0.00,
    total           DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    payment_method  ENUM('cash','bankak','ocash','card','other') DEFAULT 'cash',
    amount_paid     DECIMAL(12,2) DEFAULT 0.00,
    change_amount   DECIMAL(12,2) DEFAULT 0.00,
    notes           TEXT,
    status          ENUM('completed','refunded','cancelled') DEFAULT 'completed',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (branch_id)   REFERENCES branches(id)   ON UPDATE CASCADE,
    FOREIGN KEY (cashier_id)  REFERENCES users(id)      ON UPDATE CASCADE,
    FOREIGN KEY (customer_id) REFERENCES customers(id)  ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 13. بنود الفواتير / Invoice Items
-- ============================================================
CREATE TABLE invoice_items (
    id           INT PRIMARY KEY AUTO_INCREMENT,
    invoice_id   INT NOT NULL,
    product_id   INT NOT NULL,
    product_name VARCHAR(200) NOT NULL COMMENT 'نسخة مجمّدة من الاسم وقت البيع',
    unit_price   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    cost_price   DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'لحساب هامش الربح',
    quantity     DECIMAL(12,3) NOT NULL DEFAULT 1.000,
    discount     DECIMAL(12,2) DEFAULT 0.00 COMMENT 'خصم البند بالمبلغ',
    total        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    FOREIGN KEY (invoice_id) REFERENCES invoices(id)  ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id)  ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 14. المرتجعات / Returns
-- ============================================================
CREATE TABLE returns (
    id            INT PRIMARY KEY AUTO_INCREMENT,
    return_number VARCHAR(30) UNIQUE NOT NULL,
    invoice_id    INT NOT NULL,
    branch_id     INT NOT NULL,
    processed_by  INT NOT NULL,
    total_refund  DECIMAL(12,2) DEFAULT 0.00,
    refund_method ENUM('cash','wallet','exchange') DEFAULT 'cash',
    reason        TEXT,
    status        ENUM('pending','approved','rejected') DEFAULT 'pending',
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (invoice_id)   REFERENCES invoices(id)  ON UPDATE CASCADE,
    FOREIGN KEY (branch_id)    REFERENCES branches(id)  ON UPDATE CASCADE,
    FOREIGN KEY (processed_by) REFERENCES users(id)     ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 15. بنود المرتجعات / Return Items
-- ============================================================
CREATE TABLE return_items (
    id              INT PRIMARY KEY AUTO_INCREMENT,
    return_id       INT NOT NULL,
    invoice_item_id INT NOT NULL,
    product_id      INT NOT NULL,
    quantity        DECIMAL(12,3) NOT NULL DEFAULT 0.000,
    unit_price      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total           DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    restock         TINYINT(1) DEFAULT 1 COMMENT '1 = يُعاد للمخزون',
    FOREIGN KEY (return_id)       REFERENCES returns(id)       ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (invoice_item_id) REFERENCES invoice_items(id) ON UPDATE CASCADE,
    FOREIGN KEY (product_id)      REFERENCES products(id)      ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 16. تحويلات المخزون / Stock Transfers
-- from_branch_id = NULL → التحويل من المركزي
-- ============================================================
CREATE TABLE stock_transfers (
    id              INT PRIMARY KEY AUTO_INCREMENT,
    transfer_number VARCHAR(30) UNIQUE NOT NULL,
    from_branch_id  INT NULL COMMENT 'NULL = من المركزي',
    to_branch_id    INT NOT NULL,
    status          ENUM('pending','approved','rejected') DEFAULT 'pending',
    notes           TEXT,
    created_by      INT NOT NULL,
    approved_by     INT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    approved_at     TIMESTAMP NULL,
    FOREIGN KEY (from_branch_id) REFERENCES branches(id) ON DELETE SET NULL ON UPDATE CASCADE,
    FOREIGN KEY (to_branch_id)   REFERENCES branches(id) ON UPDATE CASCADE,
    FOREIGN KEY (created_by)     REFERENCES users(id)    ON UPDATE CASCADE,
    FOREIGN KEY (approved_by)    REFERENCES users(id)    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 17. بنود التحويلات / Stock Transfer Items
-- ============================================================
CREATE TABLE stock_transfer_items (
    id          INT PRIMARY KEY AUTO_INCREMENT,
    transfer_id INT NOT NULL,
    product_id  INT NOT NULL,
    quantity    DECIMAL(12,3) NOT NULL DEFAULT 0.000,
    FOREIGN KEY (transfer_id) REFERENCES stock_transfers(id) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (product_id)  REFERENCES products(id)        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 18. المصروفات / Expenses
-- ============================================================
CREATE TABLE expenses (
    id           INT PRIMARY KEY AUTO_INCREMENT,
    branch_id    INT NOT NULL,
    category     VARCHAR(100) NOT NULL,
    amount       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    description  TEXT,
    expense_date DATE NOT NULL,
    created_by   INT NOT NULL,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (branch_id)  REFERENCES branches(id) ON UPDATE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id)    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 19. سجل المراجعة / Audit Logs
-- ============================================================
CREATE TABLE audit_logs (
    id         INT PRIMARY KEY AUTO_INCREMENT,
    user_id    INT NULL,
    user_name  VARCHAR(100),
    branch_id  INT NULL,
    action     VARCHAR(50) NOT NULL COMMENT 'create/update/delete/login/logout/login_failed/login_blocked',
    table_name VARCHAR(100),
    record_id  INT NULL,
    old_data   JSON NULL,
    new_data   JSON NULL,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_id   (user_id),
    INDEX idx_action    (action),
    INDEX idx_table     (table_name),
    INDEX idx_created   (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 20. الإعدادات / Settings
-- branch_id = NULL → إعداد عام للمتجر
-- branch_id = X   → إعداد خاص بالفرع (يُلغي العام)
-- ============================================================
CREATE TABLE settings (
    id            INT PRIMARY KEY AUTO_INCREMENT,
    setting_key   VARCHAR(100) NOT NULL,
    setting_value TEXT,
    branch_id     INT NULL COMMENT 'NULL = عام / X = فرعي',
    UNIQUE KEY unique_key_branch (setting_key, branch_id),
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- البيانات الأولية / Initial Data
-- ============================================================

-- الفرع الرئيسي الافتراضي
INSERT INTO branches (name, code, address, phone, status) VALUES
('الفرع الرئيسي', 'BR1', 'الخرطوم، السودان', '', 'active');

-- Super Admin الافتراضي
-- كلمة المرور: password  →  مشفّرة بـ bcrypt cost=12
INSERT INTO users (branch_id, name, username, password, role, lang, status) VALUES
(NULL, 'مدير النظام', 'admin',
 '$2y$12$EixZaYVK1fsbw1ZfbX3OXePaWxn96p36WQoeG6Lruj3vjPGga31lW',
 'super_admin', 'ar', 'active');

-- الإعدادات العامة
INSERT INTO settings (setting_key, setting_value, branch_id) VALUES
('store_name_ar',      'توب شاين',                          NULL),
('store_name_en',      'Top Shine',                          NULL),
('store_logo',         'assets/images/logo.png',             NULL),
('currency',           'SDG',                                NULL),
('store_address_ar',   'الخرطوم، السودان',                  NULL),
('store_address_en',   'Khartoum, Sudan',                    NULL),
('store_phone',        '',                                   NULL),
('thermal_header_ar',  'توب شاين — جمالكِ يبدأ من هنا',    NULL),
('thermal_header_en',  'Top Shine — Your Beauty Starts Here',NULL),
('thermal_footer_ar',  'شكراً لزيارتكم',                   NULL),
('thermal_footer_en',  'Thank you for your visit',           NULL),
('tax_enabled',        '0',                                  NULL),
('tax_percent',        '0',                                  NULL),
('invoice_notes_ar',   '',                                   NULL),
('invoice_notes_en',   '',                                   NULL);

-- تصنيفات أولية لمنتجات التجميل
INSERT INTO categories (name_ar, name_en, parent_id, status) VALUES
('العناية بالبشرة',   'Skin Care',       NULL, 'active'),
('العناية بالشعر',   'Hair Care',       NULL, 'active'),
('المكياج',          'Makeup',          NULL, 'active'),
('العطور',           'Perfumes',        NULL, 'active'),
('أدوات التجميل',   'Beauty Tools',    NULL, 'active'),
('كريمات الجسم',    'Body Creams',     1,    'active'),
('غسولات الوجه',    'Face Wash',       1,    'active'),
('شامبو وبلسم',     'Shampoo & Conditioner', 2, 'active'),
('أصباغ الشعر',     'Hair Colors',     2,    'active'),
('أحمر الشفاه',     'Lipstick',        3,    'active');

-- ============================================================
-- Indexes إضافية لتحسين الأداء
-- ============================================================
CREATE INDEX idx_inv_product_branch  ON inventory          (product_id, branch_id);
CREATE INDEX idx_invmov_product      ON inventory_movements(product_id);
CREATE INDEX idx_invmov_created      ON inventory_movements(created_at);
CREATE INDEX idx_invoices_branch     ON invoices           (branch_id);
CREATE INDEX idx_invoices_cashier    ON invoices           (cashier_id);
CREATE INDEX idx_invoices_created    ON invoices           (created_at);
CREATE INDEX idx_invoices_status     ON invoices           (status);
CREATE INDEX idx_invitems_invoice    ON invoice_items      (invoice_id);
CREATE INDEX idx_invitems_product    ON invoice_items      (product_id);
CREATE INDEX idx_po_supplier         ON purchase_orders    (supplier_id);
CREATE INDEX idx_po_status           ON purchase_orders    (status);
CREATE INDEX idx_products_barcode    ON products           (barcode);
CREATE INDEX idx_products_status     ON products           (status);
CREATE INDEX idx_customers_phone     ON customers          (phone);

-- ============================================================
-- نهاية الملف
-- topshine.sql — TOP SHINE POS v1.0
-- ============================================================
