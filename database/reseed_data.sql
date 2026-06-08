-- ============================================================
-- TOP SHINE POS — بيانات تجريبية واقعية (مع الترميز الصحيح)
-- المسار: database/reseed_data.sql
-- ============================================================

SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;
SET COLLATION_CONNECTION = utf8mb4_unicode_ci;

USE topshine_db;

-- حذف البيانات القديمة
DELETE FROM invoice_items;
DELETE FROM invoices;
DELETE FROM expenses;
DELETE FROM inventory;
DELETE FROM customers;
DELETE FROM suppliers;
DELETE FROM products;
DELETE FROM categories;
DELETE FROM users WHERE role != 'super_admin';
DELETE FROM branches WHERE id > 1;

-- ============================================================
-- 1. إضافة فروع (8 فروع)
-- ============================================================
INSERT IGNORE INTO branches (name, code, address, phone, status) VALUES
('فرع الخرطوم - وسط البلد', 'BR01', 'شارع الجمهورية، الخرطوم', '0241234567', 'active'),
('فرع أم درمان - السوق', 'BR02', 'سوق أم درمان الرئيسي', '0242345678', 'active'),
('فرع بحري - النيل', 'BR03', 'شارع النيل، بحري', '0243456789', 'active'),
('فرع الأبيض - المركز', 'BR04', 'المركز التجاري، الأبيض', '0244567890', 'active'),
('فرع مدني - الحي الأول', 'BR05', 'حي الأربعين، مدني', '0245678901', 'active'),
('فرع نيالا - السوق المركزي', 'BR06', 'سوق نيالا المركزي', '0246789012', 'active'),
('فرع كسلا - الشارع الرئيسي', 'BR07', 'الشارع الرئيسي، كسلا', '0247890123', 'active'),
('فرع بورتسودان - الميناء', 'BR08', 'منطقة الميناء، بورتسودان', '0248901234', 'active');

-- ============================================================
-- 2. إضافة مستخدمين
-- ============================================================
-- Branch Admins (واحد لكل فرع)
INSERT IGNORE INTO users (branch_id, name, username, password, role, lang, status, created_at) VALUES
(1, 'أحمد محمد', 'ahmed_br1', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'branch_admin', 'ar', 'active', NOW()),
(2, 'عمر عبدالله', 'omar_br2', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'branch_admin', 'ar', 'active', NOW()),
(3, 'فاطمة علي', 'fatima_br3', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'branch_admin', 'ar', 'active', NOW()),
(4, 'خالد حسن', 'khaled_br4', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'branch_admin', 'ar', 'active', NOW()),
(5, 'سارة أحمد', 'sara_br5', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'branch_admin', 'ar', 'active', NOW()),
(6, 'محمود إبراهيم', 'mahmoud_br6', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'branch_admin', 'ar', 'active', NOW()),
(7, 'نور الدين', 'noor_br7', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'branch_admin', 'ar', 'active', NOW()),
(8, 'ريم سالم', 'reem_br8', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'branch_admin', 'ar', 'active', NOW());

-- Cashiers (2 لكل فرع)
INSERT IGNORE INTO users (branch_id, name, username, password, role, lang, status, created_at) VALUES
(1, 'يوسف كريم', 'yusuf_c1', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'cashier', 'ar', 'active', NOW()),
(1, 'منى عبدالرحمن', 'mona_c1', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'cashier', 'ar', 'active', NOW()),
(2, 'عبدالرحيم نور', 'abdel_c2', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'cashier', 'ar', 'active', NOW()),
(2, 'هدي محمد', 'huda_c2', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'cashier', 'ar', 'active', NOW()),
(3, 'أسامة علي', 'osama_c3', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'cashier', 'ar', 'active', NOW()),
(3, 'زينب حسن', 'zeinab_c3', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'cashier', 'ar', 'active', NOW()),
(4, 'عمران يوسف', 'omran_c4', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'cashier', 'ar', 'active', NOW()),
(4, 'سعاد محمود', 'suad_c4', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'cashier', 'ar', 'active', NOW()),
(5, 'طارق عبدالله', 'tarek_c5', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'cashier', 'ar', 'active', NOW()),
(5, 'نادية كريم', 'nadia_c5', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'cashier', 'ar', 'active', NOW()),
(6, 'إبراهيم عثمان', 'ibrahim_c6', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'cashier', 'ar', 'active', NOW()),
(6, 'أمينة صالح', 'amina_c6', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'cashier', 'ar', 'active', NOW()),
(7, 'حسن نور', 'hassan_c7', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'cashier', 'ar', 'active', NOW()),
(7, 'مريم أحمد', 'mariam_c7', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'cashier', 'ar', 'active', NOW()),
(8, 'كمال الدين', 'kamal_c8', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'cashier', 'ar', 'active', NOW()),
(8, 'سعيدة محمد', 'saida_c8', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'cashier', 'ar', 'active', NOW());

-- ============================================================
-- 3. إضافة فئات المنتجات
-- ============================================================
INSERT IGNORE INTO categories (name_ar, name_en) VALUES
('مستحضرات تجميل للوجه', 'Face Care Products'),
('عناية بالشعر', 'Hair Care'),
('عناية بالبشرة', 'Skin Care'),
('مكياج', 'Makeup'),
('عطور', 'Perfumes'),
('عناية بالجسم', 'Body Care'),
('منتجات الرجال', 'Men Products'),
('إكسسوارات', 'Accessories');

-- ============================================================
-- 4. إضافة منتجات (80 منتج)
-- ============================================================
INSERT IGNORE INTO products (category_id, name_ar, name_en, barcode, cost_price, selling_price, unit, status, created_at) VALUES
-- مستحضرات تجميل للوجه
(1, 'كريم ترطيب نهاري للوجه', 'Day Moisturizer', 'PROD001', 1500.00, 2500.00, 'قطعة', 'active', NOW()),
(1, 'كريم ليلي مضاد للتجاعيد', 'Anti-Aging Night Cream', 'PROD002', 2000.00, 3500.00, 'قطعة', 'active', NOW()),
(1, 'سيروم فيتامين C', 'Vitamin C Serum', 'PROD003', 2500.00, 4500.00, 'قطعة', 'active', NOW()),
(1, 'سيروم الهيالورونيك أسيد', 'Hyaluronic Acid Serum', 'PROD004', 2800.00, 5000.00, 'قطعة', 'active', NOW()),
(1, 'كريم واقي شمس SPF50', 'Sunscreen SPF50', 'PROD005', 1200.00, 2200.00, 'قطعة', 'active', NOW()),
(1, 'مقشر وجه حمضي', 'Acid Face Scrub', 'PROD006', 1800.00, 3000.00, 'قطعة', 'active', NOW()),
(1, 'ماسك طين للوجه', 'Clay Face Mask', 'PROD007', 1000.00, 1800.00, 'قطعة', 'active', NOW()),
(1, 'تونر للوجه', 'Face Toner', 'PROD008', 800.00, 1500.00, 'قطعة', 'active', NOW()),
(1, 'كريم للعيون', 'Eye Cream', 'PROD009', 2200.00, 4000.00, 'قطعة', 'active', NOW()),
(1, 'سيروم النياسيناميد', 'Niacinamide Serum', 'PROD010', 2600.00, 4800.00, 'قطعة', 'active', NOW()),
-- عناية بالشعر
(2, 'شامبو للشعر الجاف', 'Shampoo for Dry Hair', 'PROD011', 900.00, 1600.00, 'قطعة', 'active', NOW()),
(2, 'شامبو للشعر الدهني', 'Shampoo for Oily Hair', 'PROD012', 900.00, 1600.00, 'قطعة', 'active', NOW()),
(2, 'بلسم مرطب للشعر', 'Hair Conditioner', 'PROD013', 1100.00, 2000.00, 'قطعة', 'active', NOW()),
(2, 'زيت الأرغان للشعر', 'Argan Hair Oil', 'PROD014', 3500.00, 6000.00, 'قطعة', 'active', NOW()),
(2, 'زيت جوز الهند', 'Coconut Oil', 'PROD015', 1200.00, 2200.00, 'قطعة', 'active', NOW()),
(2, 'كريم للشعر المتقصف', 'Split End Cream', 'PROD016', 1500.00, 2800.00, 'قطعة', 'active', NOW()),
(2, 'ماسك للشعر', 'Hair Mask', 'PROD017', 1300.00, 2400.00, 'قطعة', 'active', NOW()),
(2, 'سبراي حماية من الحرارة', 'Heat Protection Spray', 'PROD018', 1000.00, 1800.00, 'قطعة', 'active', NOW()),
(2, 'جل تثبيت للشعر', 'Hair Gel', 'PROD019', 800.00, 1500.00, 'قطعة', 'active', NOW()),
(2, 'صبغة شعر بني', 'Brown Hair Dye', 'PROD020', 2000.00, 3500.00, 'قطعة', 'active', NOW()),
-- عناية بالبشرة
(3, 'صابون غليسيرين', 'Glycerin Soap', 'PROD021', 500.00, 1000.00, 'قطعة', 'active', NOW()),
(3, 'مقشر جسم بالسكر', 'Sugar Body Scrub', 'PROD022', 1200.00, 2200.00, 'قطعة', 'active', NOW()),
(3, 'ماسك طين للجسم', 'Clay Body Mask', 'PROD023', 1500.00, 2800.00, 'قطعة', 'active', NOW()),
(3, 'لوشن مرطب للجسم', 'Body Lotion', 'PROD024', 1800.00, 3200.00, 'قطعة', 'active', NOW()),
(3, 'زيت للجسم', 'Body Oil', 'PROD025', 2000.00, 3800.00, 'قطعة', 'active', NOW()),
(3, 'كريم يد', 'Hand Cream', 'PROD026', 700.00, 1300.00, 'قطعة', 'active', NOW()),
(3, 'كريم قدم', 'Foot Cream', 'PROD027', 800.00, 1500.00, 'قطعة', 'active', NOW()),
(3, 'غسول للوجه', 'Face Wash', 'PROD028', 1000.00, 1800.00, 'قطعة', 'active', NOW()),
(3, 'مناديل مبللة للوجه', 'Face Wipes', 'PROD029', 600.00, 1200.00, 'قطعة', 'active', NOW()),
(3, 'منشفة وجه', 'Face Towel', 'PROD030', 400.00, 800.00, 'قطعة', 'active', NOW()),
-- مكياج
(4, 'أحمر شفاه أحمر', 'Red Lipstick', 'PROD031', 1500.00, 2800.00, 'قطعة', 'active', NOW()),
(4, 'أحمر شفاه وردي', 'Pink Lipstick', 'PROD032', 1500.00, 2800.00, 'قطعة', 'active', NOW()),
(4, 'أحمر شفاه بيج', 'Beige Lipstick', 'PROD033', 1500.00, 2800.00, 'قطعة', 'active', NOW()),
(4, 'ظلال عيون بني', 'Brown Eyeshadow', 'PROD034', 1200.00, 2200.00, 'قطعة', 'active', NOW()),
(4, 'ظلال عيون ذهبي', 'Gold Eyeshadow', 'PROD035', 1200.00, 2200.00, 'قطعة', 'active', NOW()),
(4, 'ماسكارا سوداء', 'Black Mascara', 'PROD036', 1000.00, 1800.00, 'قطعة', 'active', NOW()),
(4, 'أيلاينر أسود', 'Black Eyeliner', 'PROD037', 800.00, 1500.00, 'قطعة', 'active', NOW()),
(4, 'بودرة وجه', 'Face Powder', 'PROD038', 1100.00, 2000.00, 'قطعة', 'active', NOW()),
(4, 'كريم أساس', 'Foundation Cream', 'PROD039', 1800.00, 3200.00, 'قطعة', 'active', NOW()),
(4, 'كونسيلر', 'Concealer', 'PROD040', 1400.00, 2500.00, 'قطعة', 'active', NOW()),
-- عطور
(5, 'عطر نسائي روز', 'Rose Perfume', 'PROD041', 4000.00, 7000.00, 'قطعة', 'active', NOW()),
(5, 'عطر نسائي فانيليا', 'Vanilla Perfume', 'PROD042', 4500.00, 8000.00, 'قطعة', 'active', NOW()),
(5, 'عطر رجالي أود', 'Oud Perfume', 'PROD043', 5000.00, 9000.00, 'قطعة', 'active', NOW()),
(5, 'عطر رجالي ليمون', 'Lemon Perfume', 'PROD044', 4800.00, 8500.00, 'قطعة', 'active', NOW()),
(5, 'عطر يونسيكس', 'Unisex Perfume', 'PROD045', 6000.00, 11000.00, 'قطعة', 'active', NOW()),
(5, 'عطر دوف', 'Dove Perfume', 'PROD046', 3500.00, 6000.00, 'قطعة', 'active', NOW()),
(5, 'عطر نينا ريتشي', 'Nina Ricci Perfume', 'PROD047', 5500.00, 10000.00, 'قطعة', 'active', NOW()),
(5, 'عطر كالفن كلاين', 'CK Perfume', 'PROD048', 7000.00, 13000.00, 'قطعة', 'active', NOW()),
(5, 'عطر شانيل', 'Chanel Perfume', 'PROD049', 12000.00, 22000.00, 'قطعة', 'active', NOW()),
(5, 'عطر ديور', 'Dior Perfume', 'PROD050', 10000.00, 18000.00, 'قطعة', 'active', NOW()),
-- عناية بالجسم
(6, 'لوشن جسم باللافندر', 'Lavender Body Lotion', 'PROD051', 1500.00, 2800.00, 'قطعة', 'active', NOW()),
(6, 'لوشن جسم بالورد', 'Rose Body Lotion', 'PROD052', 1500.00, 2800.00, 'قطعة', 'active', NOW()),
(6, 'زيت جسم بالياسمين', 'Jasmine Body Oil', 'PROD053', 2500.00, 4500.00, 'قطعة', 'active', NOW()),
(6, 'كريم جسم بالكاكاو', 'Cocoa Body Cream', 'PROD054', 2000.00, 3800.00, 'قطعة', 'active', NOW()),
(6, 'ملح استحمام', 'Bath Salt', 'PROD055', 1200.00, 2200.00, 'قطعة', 'active', NOW()),
(6, 'فواحات استحمام', 'Bath Bombs', 'PROD056', 1000.00, 1800.00, 'قطعة', 'active', NOW()),
(6, 'صابون يد سائل', 'Liquid Hand Soap', 'PROD057', 800.00, 1500.00, 'قطعة', 'active', NOW()),
(6, 'كريم بعد الحلاقة', 'After Shave Cream', 'PROD058', 1300.00, 2400.00, 'قطعة', 'active', NOW()),
(6, 'زونت للجسم', 'Body Spray', 'PROD059', 900.00, 1700.00, 'قطعة', 'active', NOW()),
(6, 'بودرة طفل', 'Baby Powder', 'PROD060', 700.00, 1300.00, 'قطعة', 'active', NOW()),
-- منتجات الرجال
(7, 'جل حلاقة', 'Shaving Gel', 'PROD061', 1000.00, 1800.00, 'قطعة', 'active', NOW()),
(7, 'شفرات حلاقة', 'Razor Blades', 'PROD062', 500.00, 1000.00, 'قطعة', 'active', NOW()),
(7, 'كريم للحية', 'Beard Cream', 'PROD063', 1200.00, 2200.00, 'قطعة', 'active', NOW()),
(7, 'زيت للحية', 'Beard Oil', 'PROD064', 1500.00, 2800.00, 'قطعة', 'active', NOW()),
(7, 'فرشاة للحية', 'Beard Brush', 'PROD065', 800.00, 1500.00, 'قطعة', 'active', NOW()),
(7, 'مقص للحية', 'Beard Scissors', 'PROD066', 600.00, 1200.00, 'قطعة', 'active', NOW()),
(7, 'عطر رجالي سبورت', 'Men Sport Perfume', 'PROD067', 3000.00, 5500.00, 'قطعة', 'active', NOW()),
(7, 'ديودورانت رجالي', 'Men Deodorant', 'PROD068', 900.00, 1700.00, 'قطعة', 'active', NOW()),
(7, 'شامبو للرجال', 'Men Shampoo', 'PROD069', 1100.00, 2000.00, 'قطعة', 'active', NOW()),
(7, 'جل استحمام رجالي', 'Men Body Wash', 'PROD070', 1000.00, 1800.00, 'قطعة', 'active', NOW()),
-- إكسسوارات
(8, 'فرشاة مكياج للوجه', 'Face Makeup Brush', 'PROD071', 800.00, 1500.00, 'قطعة', 'active', NOW()),
(8, 'فرشاة مكياج للعيون', 'Eye Makeup Brush', 'PROD072', 700.00, 1300.00, 'قطعة', 'active', NOW()),
(8, 'فرشاة مكياج للشفاه', 'Lip Makeup Brush', 'PROD073', 600.00, 1200.00, 'قطعة', 'active', NOW()),
(8, 'ممحاة مكياج', 'Makeup Remover', 'PROD074', 500.00, 1000.00, 'قطعة', 'active', NOW()),
(8, 'مرآة مكياج', 'Makeup Mirror', 'PROD075', 1500.00, 2800.00, 'قطعة', 'active', NOW()),
(8, 'حقيبة مكياج', 'Makeup Bag', 'PROD076', 2000.00, 3800.00, 'قطعة', 'active', NOW()),
(8, 'سبراي تثبيت المكياج', 'Makeup Setting Spray', 'PROD077', 1200.00, 2200.00, 'قطعة', 'active', NOW()),
(8, 'منشفة مكياج', 'Makeup Towel', 'PROD078', 400.00, 800.00, 'قطعة', 'active', NOW()),
(8, 'إسفنجة مكياج', 'Makeup Sponge', 'PROD079', 300.00, 600.00, 'قطعة', 'active', NOW()),
(8, 'مجموعة فرش مكياج', 'Makeup Brush Set', 'PROD080', 5000.00, 9000.00, 'قطعة', 'active', NOW());

-- ============================================================
-- 5. إضافة مخزون للفروع (أول 20 منتج فقط)
-- ============================================================
INSERT IGNORE INTO inventory (branch_id, product_id, quantity, min_quantity, updated_at) VALUES
-- فرع 1 - الخرطوم
(1, 1, 50, 10, NOW()), (1, 2, 40, 8, NOW()), (1, 3, 30, 5, NOW()), (1, 4, 25, 5, NOW()),
(1, 5, 60, 15, NOW()), (1, 6, 35, 7, NOW()), (1, 7, 45, 10, NOW()), (1, 8, 55, 12, NOW()),
(1, 9, 30, 6, NOW()), (1, 10, 25, 5, NOW()), (1, 11, 70, 15, NOW()), (1, 12, 65, 14, NOW()),
(1, 13, 50, 10, NOW()), (1, 14, 20, 4, NOW()), (1, 15, 40, 8, NOW()), (1, 16, 35, 7, NOW()),
(1, 17, 45, 9, NOW()), (1, 18, 55, 11, NOW()), (1, 19, 60, 12, NOW()), (1, 20, 30, 6, NOW()),
-- فرع 2 - أم درمان
(2, 1, 45, 10, NOW()), (2, 2, 35, 8, NOW()), (2, 3, 28, 5, NOW()), (2, 4, 22, 5, NOW()),
(2, 5, 55, 15, NOW()), (2, 6, 32, 7, NOW()), (2, 7, 42, 10, NOW()), (2, 8, 50, 12, NOW()),
(2, 9, 28, 6, NOW()), (2, 10, 23, 5, NOW()), (2, 11, 65, 15, NOW()), (2, 12, 60, 14, NOW()),
(2, 13, 48, 10, NOW()), (2, 14, 18, 4, NOW()), (2, 15, 38, 8, NOW()), (2, 16, 33, 7, NOW()),
(2, 17, 42, 9, NOW()), (2, 18, 52, 11, NOW()), (2, 19, 58, 12, NOW()), (2, 20, 28, 6, NOW());

-- ============================================================
-- 6. إضافة عملاء (10 عملاء)
-- ============================================================
INSERT IGNORE INTO customers (name, phone, registered_branch_id, notes, status, total_purchases, created_at) VALUES
('أميرة محمد', '0912345678', 1, 'عميلة VIP', 'active', 150000.00, NOW()),
('سارة أحمد', '0912345679', 1, NULL, 'active', 85000.00, NOW()),
('نور الدين', '0912345680', 1, NULL, 'active', 45000.00, NOW()),
('فاطمة علي', '0912345681', 2, NULL, 'active', 120000.00, NOW()),
('خالد حسن', '0912345682', 2, NULL, 'active', 65000.00, NOW()),
('مريم عبدالله', '0912345683', 2, NULL, 'active', 95000.00, NOW()),
('عمر إبراهيم', '0912345684', 3, NULL, 'active', 55000.00, NOW()),
('زينب محمد', '0912345685', 3, NULL, 'active', 135000.00, NOW()),
('يوسف كريم', '0912345686', 3, NULL, 'active', 75000.00, NOW()),
('هدي أحمد', '0912345687', 4, NULL, 'active', 110000.00, NOW());

-- ============================================================
-- 7. إضافة موردين (5 مورد)
-- ============================================================
INSERT IGNORE INTO suppliers (name, phone, email, address, status, created_at) VALUES
('شركة التجميل السودانية', '0241111111', 'info@sudancosmetics.com', 'الخرطوم، شارع الجمهورية', 'active', NOW()),
('مستوردرات الجمال', '0242222222', 'sales@beautysupplies.sd', 'أم درمان، السوق', 'active', NOW()),
('العطور العالمية', '0243333333', 'orders@worldperfumes.com', 'بحري، شارع النيل', 'active', NOW()),
('منتجات العناية الطبيعية', '0244444444', 'info@naturalcare.sd', 'الأبيض، المركز التجاري', 'active', NOW()),
('إكسسوارات التجميل', '0245555555', 'contact@beautyaccessories.com', 'مدني، حي الأربعين', 'active', NOW());

-- ============================================================
-- 8. إضافة فواتير تجريبية (10 فواتير)
-- ============================================================
INSERT IGNORE INTO invoices (invoice_number, branch_id, cashier_id, customer_id, subtotal, discount_type, discount_value, discount_amount, total, payment_method, amount_paid, change_amount, status, created_at) VALUES
('INV-BR01-001', 1, 18, 1, 7500.00, 'fixed', 0.00, 0.00, 7500.00, 'cash', 7500.00, 0.00, 'completed', DATE_SUB(NOW(), INTERVAL 30 DAY)),
('INV-BR01-002', 1, 18, 2, 4200.00, 'fixed', 200.00, 200.00, 4000.00, 'cash', 4000.00, 0.00, 'completed', DATE_SUB(NOW(), INTERVAL 28 DAY)),
('INV-BR01-003', 1, 19, 3, 5800.00, 'fixed', 0.00, 0.00, 5800.00, 'bankak', 5800.00, 0.00, 'completed', DATE_SUB(NOW(), INTERVAL 25 DAY)),
('INV-BR02-001', 2, 20, 4, 6800.00, 'fixed', 0.00, 0.00, 6800.00, 'cash', 6800.00, 0.00, 'completed', DATE_SUB(NOW(), INTERVAL 29 DAY)),
('INV-BR02-002', 2, 21, 5, 5200.00, 'fixed', 200.00, 200.00, 5000.00, 'cash', 5000.00, 0.00, 'completed', DATE_SUB(NOW(), INTERVAL 26 DAY)),
('INV-BR02-003', 2, 20, 6, 9500.00, 'fixed', 0.00, 0.00, 9500.00, 'bankak', 9500.00, 0.00, 'completed', DATE_SUB(NOW(), INTERVAL 24 DAY)),
('INV-BR03-001', 3, 22, 7, 5900.00, 'fixed', 0.00, 0.00, 5900.00, 'cash', 5900.00, 0.00, 'completed', DATE_SUB(NOW(), INTERVAL 28 DAY)),
('INV-BR03-002', 3, 23, 8, 4700.00, 'fixed', 200.00, 200.00, 4500.00, 'cash', 4500.00, 0.00, 'completed', DATE_SUB(NOW(), INTERVAL 25 DAY)),
('INV-BR03-003', 3, 22, 9, 8200.00, 'fixed', 0.00, 0.00, 8200.00, 'bankak', 8200.00, 0.00, 'completed', DATE_SUB(NOW(), INTERVAL 23 DAY)),
('INV-BR04-001', 4, 24, 10, 6200.00, 'fixed', 0.00, 0.00, 6200.00, 'cash', 6200.00, 0.00, 'completed', DATE_SUB(NOW(), INTERVAL 27 DAY));

-- ============================================================
-- 9. إضافة عناصر الفواتير (invoice_items)
-- ============================================================
INSERT IGNORE INTO invoice_items (invoice_id, product_id, product_name, quantity, unit_price, cost_price, total) VALUES
(1, 1, 'كريم ترطيب نهاري للوجه', 2, 2500.00, 1500.00, 5000.00),
(1, 2, 'كريم ليلي مضاد للتجاعيد', 1, 2500.00, 2000.00, 2500.00),
(2, 3, 'سيروم فيتامين C', 1, 4500.00, 2500.00, 4500.00),
(3, 4, 'سيروم الهيالورونيك أسيد', 1, 5000.00, 2800.00, 5000.00),
(3, 5, 'كريم واقي شمس SPF50', 1, 2200.00, 1200.00, 2200.00),
(4, 6, 'مقشر وجه حمضي', 2, 3000.00, 1800.00, 6000.00),
(4, 7, 'ماسك طين للوجه', 2, 1800.00, 1000.00, 3600.00),
(4, 8, 'تونر للوجه', 1, 1500.00, 800.00, 1500.00),
(5, 9, 'كريم للعيون', 2, 4000.00, 2200.00, 8000.00),
(5, 10, 'سيروم النياسيناميد', 1, 4800.00, 2600.00, 4800.00);

-- ============================================================
-- 10. إضافة مصروفات (10 سجل)
-- ============================================================
INSERT IGNORE INTO expenses (branch_id, category, amount, description, expense_date, created_by, created_at) VALUES
(1, 'إيجار', 150000.00, 'إيجار شهر يناير', '2024-01-01', 2, DATE_SUB(NOW(), INTERVAL 30 DAY)),
(1, 'رواتب', 200000.00, 'رواتب الموظفين شهر يناير', '2024-01-05', 2, DATE_SUB(NOW(), INTERVAL 29 DAY)),
(1, 'كهرباء وماء', 25000.00, 'فاتورة كهرباء يناير', '2024-01-10', 2, DATE_SUB(NOW(), INTERVAL 28 DAY)),
(2, 'إيجار', 120000.00, 'إيجار شهر يناير', '2024-01-01', 4, DATE_SUB(NOW(), INTERVAL 30 DAY)),
(2, 'رواتب', 180000.00, 'رواتب الموظفين شهر يناير', '2024-01-05', 4, DATE_SUB(NOW(), INTERVAL 29 DAY)),
(2, 'كهرباء وماء', 20000.00, 'فاتورة كهرباء يناير', '2024-01-10', 4, DATE_SUB(NOW(), INTERVAL 28 DAY)),
(3, 'إيجار', 100000.00, 'إيجار شهر يناير', '2024-01-01', 6, DATE_SUB(NOW(), INTERVAL 30 DAY)),
(3, 'رواتب', 160000.00, 'رواتب الموظفين شهر يناير', '2024-01-05', 6, DATE_SUB(NOW(), INTERVAL 29 DAY)),
(3, 'كهرباء وماء', 18000.00, 'فاتورة كهرباء يناير', '2024-01-10', 6, DATE_SUB(NOW(), INTERVAL 28 DAY)),
(4, 'إيجار', 90000.00, 'إيجار شهر يناير', '2024-01-01', 8, DATE_SUB(NOW(), INTERVAL 30 DAY));
