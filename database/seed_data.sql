-- ============================================================
-- TOP SHINE POS — بيانات تجريبية واقعية
-- المسار: database/seed_data.sql
-- الوظيفة: إضافة بيانات تجريبية للنظام (فروع، مستخدمين، منتجات، فواتير)
-- ============================================================

USE topshine_db;

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
-- Super Admin
INSERT IGNORE INTO users (branch_id, name, username, password, role, lang, status, created_at) VALUES
(NULL, 'مدير النظام', 'admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'super_admin', 'ar', 'active', NOW());

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
-- 5. إضافة مخزون للفروع
-- ============================================================
-- فرع 1 - الخرطوم
INSERT IGNORE INTO inventory (branch_id, product_id, quantity, min_quantity, updated_at) VALUES
(1, 1, 50, 10, NOW()), (1, 2, 40, 8, NOW()), (1, 3, 30, 5, NOW()), (1, 4, 25, 5, NOW()),
(1, 5, 60, 15, NOW()), (1, 6, 35, 7, NOW()), (1, 7, 45, 10, NOW()), (1, 8, 55, 12, NOW()),
(1, 9, 30, 6, NOW()), (1, 10, 25, 5, NOW()), (1, 11, 70, 15, NOW()), (1, 12, 65, 14, NOW()),
(1, 13, 50, 10, NOW()), (1, 14, 20, 4, NOW()), (1, 15, 40, 8, NOW()), (1, 16, 35, 7, NOW()),
(1, 17, 45, 9, NOW()), (1, 18, 55, 11, NOW()), (1, 19, 60, 12, NOW()), (1, 20, 30, 6, NOW());

-- فرع 2 - أم درمان
INSERT IGNORE INTO inventory (branch_id, product_id, quantity, min_quantity, updated_at) VALUES
(2, 1, 45, 10, NOW()), (2, 2, 35, 8, NOW()), (2, 3, 28, 5, NOW()), (2, 4, 22, 5, NOW()),
(2, 5, 55, 15, NOW()), (2, 6, 32, 7, NOW()), (2, 7, 42, 10, NOW()), (2, 8, 50, 12, NOW()),
(2, 9, 28, 6, NOW()), (2, 10, 23, 5, NOW()), (2, 11, 65, 15, NOW()), (2, 12, 60, 14, NOW()),
(2, 13, 48, 10, NOW()), (2, 14, 18, 4, NOW()), (2, 15, 38, 8, NOW()), (2, 16, 33, 7, NOW()),
(2, 17, 42, 9, NOW()), (2, 18, 52, 11, NOW()), (2, 19, 58, 12, NOW()), (2, 20, 28, 6, NOW());

-- فرع 3 - بحري
INSERT IGNORE INTO inventory (branch_id, product_id, quantity, min_quantity, updated_at) VALUES
(3, 1, 40, 10, NOW()), (3, 2, 30, 8, NOW()), (3, 3, 25, 5, NOW()), (3, 4, 20, 5, NOW()),
(3, 5, 50, 15, NOW()), (3, 6, 30, 7, NOW()), (3, 7, 40, 10, NOW()), (3, 8, 45, 12, NOW()),
(3, 9, 25, 6, NOW()), (3, 10, 20, 5, NOW()), (3, 11, 60, 15, NOW()), (3, 12, 55, 14, NOW()),
(3, 13, 45, 10, NOW()), (3, 14, 15, 4, NOW()), (3, 15, 35, 8, NOW()), (3, 16, 30, 7, NOW()),
(3, 17, 40, 9, NOW()), (3, 18, 50, 11, NOW()), (3, 19, 55, 12, NOW()), (3, 20, 25, 6, NOW());

-- فرع 4 - الأبيض
INSERT IGNORE INTO inventory (branch_id, product_id, quantity, min_quantity, updated_at) VALUES
(4, 1, 35, 10, NOW()), (4, 2, 25, 8, NOW()), (4, 3, 22, 5, NOW()), (4, 4, 18, 5, NOW()),
(4, 5, 45, 15, NOW()), (4, 6, 28, 7, NOW()), (4, 7, 38, 10, NOW()), (4, 8, 40, 12, NOW()),
(4, 9, 22, 6, NOW()), (4, 10, 18, 5, NOW()), (4, 11, 55, 15, NOW()), (4, 12, 50, 14, NOW()),
(4, 13, 42, 10, NOW()), (4, 14, 12, 4, NOW()), (4, 15, 32, 8, NOW()), (4, 16, 28, 7, NOW()),
(4, 17, 38, 9, NOW()), (4, 18, 48, 11, NOW()), (4, 19, 52, 12, NOW()), (4, 20, 22, 6, NOW());

-- فرع 5 - مدني
INSERT IGNORE INTO inventory (branch_id, product_id, quantity, min_quantity, updated_at) VALUES
(5, 1, 30, 10, NOW()), (5, 2, 20, 8, NOW()), (5, 3, 18, 5, NOW()), (5, 4, 15, 5, NOW()),
(5, 5, 40, 15, NOW()), (5, 6, 25, 7, NOW()), (5, 7, 35, 10, NOW()), (5, 8, 35, 12, NOW()),
(5, 9, 20, 6, NOW()), (5, 10, 15, 5, NOW()), (5, 11, 50, 15, NOW()), (5, 12, 45, 14, NOW()),
(5, 13, 40, 10, NOW()), (5, 14, 10, 4, NOW()), (5, 15, 30, 8, NOW()), (5, 16, 25, 7, NOW()),
(5, 17, 35, 9, NOW()), (5, 18, 45, 11, NOW()), (5, 19, 50, 12, NOW()), (5, 20, 20, 6, NOW());

-- فرع 6 - نيالا
INSERT IGNORE INTO inventory (branch_id, product_id, quantity, min_quantity, updated_at) VALUES
(6, 1, 25, 10, NOW()), (6, 2, 18, 8, NOW()), (6, 3, 15, 5, NOW()), (6, 4, 12, 5, NOW()),
(6, 5, 35, 15, NOW()), (6, 6, 22, 7, NOW()), (6, 7, 32, 10, NOW()), (6, 8, 30, 12, NOW()),
(6, 9, 18, 6, NOW()), (6, 10, 12, 5, NOW()), (6, 11, 45, 15, NOW()), (6, 12, 40, 14, NOW()),
(6, 13, 35, 10, NOW()), (6, 14, 8, 4, NOW()), (6, 15, 28, 8, NOW()), (6, 16, 22, 7, NOW()),
(6, 17, 32, 9, NOW()), (6, 18, 40, 11, NOW()), (6, 19, 45, 12, NOW()), (6, 20, 18, 6, NOW());

-- فرع 7 - كسلا
INSERT IGNORE INTO inventory (branch_id, product_id, quantity, min_quantity, updated_at) VALUES
(7, 1, 20, 10, NOW()), (7, 2, 15, 8, NOW()), (7, 3, 12, 5, NOW()), (7, 4, 10, 5, NOW()),
(7, 5, 30, 15, NOW()), (7, 6, 20, 7, NOW()), (7, 7, 28, 10, NOW()), (7, 8, 25, 12, NOW()),
(7, 9, 15, 6, NOW()), (7, 10, 10, 5, NOW()), (7, 11, 40, 15, NOW()), (7, 12, 35, 14, NOW()),
(7, 13, 30, 10, NOW()), (7, 14, 6, 4, NOW()), (7, 15, 25, 8, NOW()), (7, 16, 20, 7, NOW()),
(7, 17, 28, 9, NOW()), (7, 18, 35, 11, NOW()), (7, 19, 40, 12, NOW()), (7, 20, 15, 6, NOW());

-- فرع 8 - بورتسودان
INSERT IGNORE INTO inventory (branch_id, product_id, quantity, min_quantity, updated_at) VALUES
(8, 1, 15, 10, NOW()), (8, 2, 12, 8, NOW()), (8, 3, 10, 5, NOW()), (8, 4, 8, 5, NOW()),
(8, 5, 25, 15, NOW()), (8, 6, 18, 7, NOW()), (8, 7, 25, 10, NOW()), (8, 8, 20, 12, NOW()),
(8, 9, 12, 6, NOW()), (8, 10, 8, 5, NOW()), (8, 11, 35, 15, NOW()), (8, 12, 30, 14, NOW()),
(8, 13, 25, 10, NOW()), (8, 14, 5, 4, NOW()), (8, 15, 22, 8, NOW()), (8, 16, 18, 7, NOW()),
(8, 17, 25, 9, NOW()), (8, 18, 30, 11, NOW()), (8, 19, 35, 12, NOW()), (8, 20, 12, 6, NOW());

-- ============================================================
-- 6. إضافة عملاء (25 عميل)
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
('هدي أحمد', '0912345687', 4, NULL, 'active', 110000.00, NOW()),
('طارق عبدالرحمن', '0912345688', 4, NULL, 'active', 60000.00, NOW()),
('نادية صالح', '0912345689', 4, NULL, 'active', 80000.00, NOW()),
('إبراهيم نور', '0912345690', 5, NULL, 'active', 145000.00, NOW()),
('أمينة عثمان', '0912345691', 5, NULL, 'active', 70000.00, NOW()),
('حسن محمود', '0912345692', 5, NULL, 'active', 90000.00, NOW()),
('سعيدة كريم', '0912345693', 6, NULL, 'active', 50000.00, NOW()),
('كمال الدين', '0912345694', 6, NULL, 'active', 125000.00, NOW()),
('ريم سالم', '0912345695', 6, NULL, 'active', 85000.00, NOW()),
('أسامة علي', '0912345696', 7, NULL, 'active', 65000.00, NOW()),
('منى حسن', '0912345697', 7, NULL, 'active', 115000.00, NOW()),
('عبدالرحيم يوسف', '0912345698', 7, NULL, 'active', 75000.00, NOW()),
('زكريا أحمد', '0912345699', 8, NULL, 'active', 100000.00, NOW()),
('ليلى محمد', '0912345700', 8, NULL, 'active', 55000.00, NOW()),
('محمود عبدالله', '0912345701', 8, NULL, 'active', 130000.00, NOW()),
('سعاد نور', '0912345702', 1, 'عميلة جديدة', 'active', 35000.00, NOW());

-- ============================================================
-- 7. إضافة موردين (10 مورد)
-- ============================================================
INSERT IGNORE INTO suppliers (name, phone, email, address, status, created_at) VALUES
('شركة التجميل السودانية', '0241111111', 'info@sudancosmetics.com', 'الخرطوم، شارع الجمهورية', 'active', NOW()),
('مستوردرات الجمال', '0242222222', 'sales@beautysupplies.sd', 'أم درمان، السوق', 'active', NOW()),
('العطور العالمية', '0243333333', 'orders@worldperfumes.com', 'بحري، شارع النيل', 'active', NOW()),
('منتجات العناية الطبيعية', '0244444444', 'info@naturalcare.sd', 'الأبيض، المركز التجاري', 'active', NOW()),
('إكسسوارات التجميل', '0245555555', 'contact@beautyaccessories.com', 'مدني، حي الأربعين', 'active', NOW()),
('مستحضرات الوجه المتقدمة', '0246666666', 'sales@advancedface.sd', 'نيالا، السوق المركزي', 'active', NOW()),
('منتجات الشعر الاحترافية', '0247777777', 'info@prohair.sd', 'كسلا، الشارع الرئيسي', 'active', NOW()),
('عناية الجسم والبشرة', '0248888888', 'orders@bodycare.sd', 'بورتسودان، الميناء', 'active', NOW()),
('مكياج وأدوات تجميل', '0249999999', 'sales@makeuptools.com', 'الخرطوم، وسط البلد', 'active', NOW()),
('منتجات الرجال الفاخرة', '0240000000', 'info@mensluxury.sd', 'أم درمان، السوق الجديد', 'active', NOW());

-- ============================================================
-- 8. إضافة فواتير تجريبية (50 فاتورة)
-- ============================================================
-- فرع 1 - الخرطوم
INSERT IGNORE INTO invoices (invoice_number, branch_id, cashier_id, customer_id, subtotal, discount_type, discount_value, discount_amount, total, payment_method, amount_paid, change_amount, status, created_at) VALUES
('INV-BR01-001', 1, 18, 1, 7500.00, 'fixed', 0.00, 0.00, 7500.00, 'cash', 7500.00, 0.00, 'completed', DATE_SUB(NOW(), INTERVAL 30 DAY)),
('INV-BR01-002', 1, 18, 2, 4200.00, 'fixed', 200.00, 200.00, 4000.00, 'cash', 4000.00, 0.00, 'completed', DATE_SUB(NOW(), INTERVAL 28 DAY)),
('INV-BR01-003', 1, 19, 3, 5800.00, 'fixed', 0.00, 0.00, 5800.00, 'bankak', 5800.00, 0.00, 'completed', DATE_SUB(NOW(), INTERVAL 25 DAY)),
('INV-BR01-004', 1, 18, 1, 12000.00, 'fixed', 500.00, 500.00, 11500.00, 'cash', 12000.00, 500.00, 'completed', DATE_SUB(NOW(), INTERVAL 22 DAY)),
('INV-BR01-005', 1, 19, 4, 8500.00, 'fixed', 0.00, 0.00, 8500.00, 'card', 8500.00, 0.00, 'completed', DATE_SUB(NOW(), INTERVAL 20 DAY)),
('INV-BR01-006', 1, 18, 5, 6300.00, 'fixed', 300.00, 300.00, 6000.00, 'cash', 6000.00, 0.00, 'completed', DATE_SUB(NOW(), INTERVAL 18 DAY)),
('INV-BR01-007', 1, 19, 2, 9200.00, 'fixed', 0.00, 0.00, 9200.00, 'cash', 9200.00, 0.00, 'completed', DATE_SUB(NOW(), INTERVAL 15 DAY)),
('INV-BR01-008', 1, 18, 6, 4500.00, 'fixed', 0.00, 0.00, 4500.00, 'bankak', 4500.00, 0.00, 'completed', DATE_SUB(NOW(), INTERVAL 12 DAY)),
('INV-BR01-009', 1, 19, 1, 15000.00, 'fixed', 1000.00, 1000.00, 14000.00, 'cash', 15000.00, 1000.00, 'completed', DATE_SUB(NOW(), INTERVAL 10 DAY)),
('INV-BR01-010', 1, 18, 7, 7800.00, 'fixed', 0.00, 0.00, 7800.00, 'card', 7800.00, 0.00, 'completed', DATE_SUB(NOW(), INTERVAL 8 DAY));

-- فرع 2 - أم درمان
INSERT IGNORE INTO invoices (invoice_number, branch_id, cashier_id, customer_id, subtotal, discount_type, discount_value, discount_amount, total, payment_method, amount_paid, change_amount, status, created_at) VALUES
('INV-BR02-001', 2, 20, 4, 6800.00, 'fixed', 0.00, 0.00, 6800.00, 'cash', 6800.00, 0.00, 'completed', DATE_SUB(NOW(), INTERVAL 29 DAY)),
('INV-BR02-002', 2, 21, 5, 5200.00, 'fixed', 200.00, 200.00, 5000.00, 'cash', 5000.00, 0.00, 'completed', DATE_SUB(NOW(), INTERVAL 26 DAY)),
('INV-BR02-003', 2, 20, 6, 9500.00, 'fixed', 0.00, 0.00, 9500.00, 'bankak', 9500.00, 0.00, 'completed', DATE_SUB(NOW(), INTERVAL 24 DAY)),
('INV-BR02-004', 2, 21, 4, 11000.00, 'fixed', 500.00, 500.00, 10500.00, 'cash', 11000.00, 500.00, 'completed', DATE_SUB(NOW(), INTERVAL 21 DAY)),
('INV-BR02-005', 2, 20, 8, 7200.00, 'fixed', 0.00, 0.00, 7200.00, 'card', 7200.00, 0.00, 'completed', DATE_SUB(NOW(), INTERVAL 19 DAY)),
('INV-BR02-006', 2, 21, 5, 5800.00, 'fixed', 300.00, 300.00, 5500.00, 'cash', 5500.00, 0.00, 'completed', DATE_SUB(NOW(), INTERVAL 17 DAY)),
('INV-BR02-007', 2, 20, 6, 8300.00, 'fixed', 0.00, 0.00, 8300.00, 'cash', 8300.00, 0.00, 'completed', DATE_SUB(NOW(), INTERVAL 14 DAY)),
('INV-BR02-008', 2, 21, 9, 4800.00, 'fixed', 0.00, 0.00, 4800.00, 'bankak', 4800.00, 0.00, 'completed', DATE_SUB(NOW(), INTERVAL 11 DAY)),
('INV-BR02-009', 2, 20, 4, 13500.00, 'fixed', 1000.00, 1000.00, 12500.00, 'cash', 13500.00, 1000.00, 'completed', DATE_SUB(NOW(), INTERVAL 9 DAY)),
('INV-BR02-010', 2, 21, 10, 6900.00, 'fixed', 0.00, 0.00, 6900.00, 'card', 6900.00, 0.00, 'completed', DATE_SUB(NOW(), INTERVAL 7 DAY));

-- فرع 3 - بحري
INSERT IGNORE INTO invoices (invoice_number, branch_id, cashier_id, customer_id, subtotal, discount_type, discount_value, discount_amount, total, payment_method, amount_paid, change_amount, status, created_at) VALUES
('INV-BR03-001', 3, 22, 7, 5900.00, 'fixed', 0.00, 0.00, 5900.00, 'cash', 5900.00, 0.00, 'completed', DATE_SUB(NOW(), INTERVAL 28 DAY)),
('INV-BR03-002', 3, 23, 8, 4700.00, 'fixed', 200.00, 200.00, 4500.00, 'cash', 4500.00, 0.00, 'completed', DATE_SUB(NOW(), INTERVAL 25 DAY)),
('INV-BR03-003', 3, 22, 9, 8200.00, 'fixed', 0.00, 0.00, 8200.00, 'bankak', 8200.00, 0.00, 'completed', DATE_SUB(NOW(), INTERVAL 23 DAY)),
('INV-BR03-004', 3, 23, 7, 10500.00, 'fixed', 500.00, 500.00, 10000.00, 'cash', 10500.00, 500.00, 'completed', DATE_SUB(NOW(), INTERVAL 20 DAY)),
('INV-BR03-005', 3, 22, 11, 6700.00, 'fixed', 0.00, 0.00, 6700.00, 'card', 6700.00, 0.00, 'completed', DATE_SUB(NOW(), INTERVAL 18 DAY)),
('INV-BR03-006', 3, 23, 8, 5300.00, 'fixed', 300.00, 300.00, 5000.00, 'cash', 5000.00, 0.00, 'completed', DATE_SUB(NOW(), INTERVAL 16 DAY)),
('INV-BR03-007', 3, 22, 9, 7800.00, 'fixed', 0.00, 0.00, 7800.00, 'cash', 7800.00, 0.00, 'completed', DATE_SUB(NOW(), INTERVAL 13 DAY)),
('INV-BR03-008', 3, 23, 12, 4200.00, 'fixed', 0.00, 0.00, 4200.00, 'bankak', 4200.00, 0.00, 'completed', DATE_SUB(NOW(), INTERVAL 10 DAY)),
('INV-BR03-009', 3, 22, 7, 12500.00, 'fixed', 1000.00, 1000.00, 11500.00, 'cash', 12500.00, 1000.00, 'completed', DATE_SUB(NOW(), INTERVAL 8 DAY)),
('INV-BR03-010', 3, 23, 13, 6100.00, 'fixed', 0.00, 0.00, 6100.00, 'card', 6100.00, 0.00, 'completed', DATE_SUB(NOW(), INTERVAL 6 DAY));

-- فرع 4 - الأبيض
INSERT IGNORE INTO invoices (invoice_number, branch_id, cashier_id, customer_id, subtotal, discount_type, discount_value, discount_amount, total, payment_method, amount_paid, change_amount, status, created_at) VALUES
('INV-BR04-001', 4, 24, 10, 6200.00, 'fixed', 0.00, 0.00, 6200.00, 'cash', 6200.00, 0.00, 'completed', DATE_SUB(NOW(), INTERVAL 27 DAY)),
('INV-BR04-002', 4, 25, 11, 4800.00, 'fixed', 200.00, 200.00, 4600.00, 'cash', 4600.00, 0.00, 'completed', DATE_SUB(NOW(), INTERVAL 24 DAY)),
('INV-BR04-003', 4, 24, 12, 8800.00, 'fixed', 0.00, 0.00, 8800.00, 'bankak', 8800.00, 0.00, 'completed', DATE_SUB(NOW(), INTERVAL 22 DAY)),
('INV-BR04-004', 4, 25, 10, 11200.00, 'fixed', 500.00, 500.00, 10700.00, 'cash', 11200.00, 500.00, 'completed', DATE_SUB(NOW(), INTERVAL 19 DAY)),
('INV-BR04-005', 4, 24, 14, 7100.00, 'fixed', 0.00, 0.00, 7100.00, 'card', 7100.00, 0.00, 'completed', DATE_SUB(NOW(), INTERVAL 17 DAY)),
('INV-BR04-006', 4, 25, 11, 5600.00, 'fixed', 300.00, 300.00, 5300.00, 'cash', 5300.00, 0.00, 'completed', DATE_SUB(NOW(), INTERVAL 15 DAY)),
('INV-BR04-007', 4, 24, 12, 8100.00, 'fixed', 0.00, 0.00, 8100.00, 'cash', 8100.00, 0.00, 'completed', DATE_SUB(NOW(), INTERVAL 12 DAY)),
('INV-BR04-008', 4, 25, 15, 4600.00, 'fixed', 0.00, 0.00, 4600.00, 'bankak', 4600.00, 0.00, 'completed', DATE_SUB(NOW(), INTERVAL 9 DAY)),
('INV-BR04-009', 4, 24, 10, 13000.00, 'fixed', 1000.00, 1000.00, 12000.00, 'cash', 13000.00, 1000.00, 'completed', DATE_SUB(NOW(), INTERVAL 7 DAY)),
('INV-BR04-010', 4, 25, 16, 6400.00, 'fixed', 0.00, 0.00, 6400.00, 'card', 6400.00, 0.00, 'completed', DATE_SUB(NOW(), INTERVAL 5 DAY));

-- فرع 5 - مدني
INSERT IGNORE INTO invoices (invoice_number, branch_id, cashier_id, customer_id, subtotal, discount_type, discount_value, discount_amount, total, payment_method, amount_paid, change_amount, status, created_at) VALUES
('INV-BR05-001', 5, 26, 13, 5500.00, 'fixed', 0.00, 0.00, 5500.00, 'cash', 5500.00, 0.00, 'completed', DATE_SUB(NOW(), INTERVAL 26 DAY)),
('INV-BR05-002', 5, 27, 14, 4300.00, 'fixed', 200.00, 200.00, 4100.00, 'cash', 4100.00, 0.00, 'completed', DATE_SUB(NOW(), INTERVAL 23 DAY)),
('INV-BR05-003', 5, 26, 15, 7900.00, 'fixed', 0.00, 0.00, 7900.00, 'bankak', 7900.00, 0.00, 'completed', DATE_SUB(NOW(), INTERVAL 21 DAY)),
('INV-BR05-004', 5, 27, 13, 10800.00, 'fixed', 500.00, 500.00, 10300.00, 'cash', 10800.00, 500.00, 'completed', DATE_SUB(NOW(), INTERVAL 18 DAY)),
('INV-BR05-005', 5, 26, 17, 6600.00, 'fixed', 0.00, 0.00, 6600.00, 'card', 6600.00, 0.00, 'completed', DATE_SUB(NOW(), INTERVAL 16 DAY)),
('INV-BR05-006', 5, 27, 14, 5100.00, 'fixed', 300.00, 300.00, 4800.00, 'cash', 4800.00, 0.00, 'completed', DATE_SUB(NOW(), INTERVAL 14 DAY)),
('INV-BR05-007', 5, 26, 15, 7600.00, 'fixed', 0.00, 0.00, 7600.00, 'cash', 7600.00, 0.00, 'completed', DATE_SUB(NOW(), INTERVAL 11 DAY)),
('INV-BR05-008', 5, 27, 18, 4400.00, 'fixed', 0.00, 0.00, 4400.00, 'bankak', 4400.00, 0.00, 'completed', DATE_SUB(NOW(), INTERVAL 8 DAY)),
('INV-BR05-009', 5, 26, 13, 12800.00, 'fixed', 1000.00, 1000.00, 11800.00, 'cash', 12800.00, 1000.00, 'completed', DATE_SUB(NOW(), INTERVAL 6 DAY)),
('INV-BR05-010', 5, 27, 19, 5900.00, 'fixed', 0.00, 0.00, 5900.00, 'card', 5900.00, 0.00, 'completed', DATE_SUB(NOW(), INTERVAL 4 DAY));

-- فرع 6 - نيالا
INSERT IGNORE INTO invoices (invoice_number, branch_id, cashier_id, customer_id, subtotal, discount_type, discount_value, discount_amount, total, payment_method, amount_paid, change_amount, status, created_at) VALUES
('INV-BR06-001', 6, 28, 16, 5800.00, 'fixed', 0.00, 0.00, 5800.00, 'cash', 5800.00, 0.00, 'completed', DATE_SUB(NOW(), INTERVAL 25 DAY)),
('INV-BR06-002', 6, 29, 17, 4600.00, 'fixed', 200.00, 200.00, 4400.00, 'cash', 4400.00, 0.00, 'completed', DATE_SUB(NOW(), INTERVAL 22 DAY)),
('INV-BR06-003', 6, 28, 18, 8100.00, 'fixed', 0.00, 0.00, 8100.00, 'bankak', 8100.00, 0.00, 'completed', DATE_SUB(NOW(), INTERVAL 20 DAY)),
('INV-BR06-004', 6, 29, 16, 10900.00, 'fixed', 500.00, 500.00, 10400.00, 'cash', 10900.00, 500.00, 'completed', DATE_SUB(NOW(), INTERVAL 17 DAY)),
('INV-BR06-005', 6, 28, 20, 6900.00, 'fixed', 0.00, 0.00, 6900.00, 'card', 6900.00, 0.00, 'completed', DATE_SUB(NOW(), INTERVAL 15 DAY)),
('INV-BR06-006', 6, 29, 17, 5400.00, 'fixed', 300.00, 300.00, 5100.00, 'cash', 5100.00, 0.00, 'completed', DATE_SUB(NOW(), INTERVAL 13 DAY)),
('INV-BR06-007', 6, 28, 18, 7900.00, 'fixed', 0.00, 0.00, 7900.00, 'cash', 7900.00, 0.00, 'completed', DATE_SUB(NOW(), INTERVAL 10 DAY)),
('INV-BR06-008', 6, 29, 21, 4700.00, 'fixed', 0.00, 0.00, 4700.00, 'bankak', 4700.00, 0.00, 'completed', DATE_SUB(NOW(), INTERVAL 7 DAY)),
('INV-BR06-009', 6, 28, 16, 13200.00, 'fixed', 1000.00, 1000.00, 12200.00, 'cash', 13200.00, 1000.00, 'completed', DATE_SUB(NOW(), INTERVAL 5 DAY)),
('INV-BR06-010', 6, 29, 22, 6200.00, 'fixed', 0.00, 0.00, 6200.00, 'card', 6200.00, 0.00, 'completed', DATE_SUB(NOW(), INTERVAL 3 DAY));

-- فرع 7 - كسلا
INSERT IGNORE INTO invoices (invoice_number, branch_id, cashier_id, customer_id, subtotal, discount_type, discount_value, discount_amount, total, payment_method, amount_paid, change_amount, status, created_at) VALUES
('INV-BR07-001', 7, 30, 19, 5300.00, 'fixed', 0.00, 0.00, 5300.00, 'cash', 5300.00, 0.00, 'completed', DATE_SUB(NOW(), INTERVAL 24 DAY)),
('INV-BR07-002', 7, 31, 20, 4100.00, 'fixed', 200.00, 200.00, 3900.00, 'cash', 3900.00, 0.00, 'completed', DATE_SUB(NOW(), INTERVAL 21 DAY)),
('INV-BR07-003', 7, 30, 21, 7600.00, 'fixed', 0.00, 0.00, 7600.00, 'bankak', 7600.00, 0.00, 'completed', DATE_SUB(NOW(), INTERVAL 19 DAY)),
('INV-BR07-004', 7, 31, 19, 10400.00, 'fixed', 500.00, 500.00, 9900.00, 'cash', 10400.00, 500.00, 'completed', DATE_SUB(NOW(), INTERVAL 16 DAY)),
('INV-BR07-005', 7, 30, 23, 6400.00, 'fixed', 0.00, 0.00, 6400.00, 'card', 6400.00, 0.00, 'completed', DATE_SUB(NOW(), INTERVAL 14 DAY)),
('INV-BR07-006', 7, 31, 20, 4900.00, 'fixed', 300.00, 300.00, 4600.00, 'cash', 4600.00, 0.00, 'completed', DATE_SUB(NOW(), INTERVAL 12 DAY)),
('INV-BR07-007', 7, 30, 21, 7400.00, 'fixed', 0.00, 0.00, 7400.00, 'cash', 7400.00, 0.00, 'completed', DATE_SUB(NOW(), INTERVAL 9 DAY)),
('INV-BR07-008', 7, 31, 24, 4200.00, 'fixed', 0.00, 0.00, 4200.00, 'bankak', 4200.00, 0.00, 'completed', DATE_SUB(NOW(), INTERVAL 6 DAY)),
('INV-BR07-009', 7, 30, 19, 12600.00, 'fixed', 1000.00, 1000.00, 11600.00, 'cash', 12600.00, 1000.00, 'completed', DATE_SUB(NOW(), INTERVAL 4 DAY)),
('INV-BR07-010', 7, 31, 25, 5700.00, 'fixed', 0.00, 0.00, 5700.00, 'card', 5700.00, 0.00, 'completed', DATE_SUB(NOW(), INTERVAL 2 DAY));

-- فرع 8 - بورتسودان
INSERT IGNORE INTO invoices (invoice_number, branch_id, cashier_id, customer_id, subtotal, discount_type, discount_value, discount_amount, total, payment_method, amount_paid, change_amount, status, created_at) VALUES
('INV-BR08-001', 8, 32, 22, 5600.00, 'fixed', 0.00, 0.00, 5600.00, 'cash', 5600.00, 0.00, 'completed', DATE_SUB(NOW(), INTERVAL 23 DAY)),
('INV-BR08-002', 8, 33, 23, 4400.00, 'fixed', 200.00, 200.00, 4200.00, 'cash', 4200.00, 0.00, 'completed', DATE_SUB(NOW(), INTERVAL 20 DAY)),
('INV-BR08-003', 8, 32, 24, 7900.00, 'fixed', 0.00, 0.00, 7900.00, 'bankak', 7900.00, 0.00, 'completed', DATE_SUB(NOW(), INTERVAL 18 DAY)),
('INV-BR08-004', 8, 33, 22, 11100.00, 'fixed', 500.00, 500.00, 10600.00, 'cash', 11100.00, 500.00, 'completed', DATE_SUB(NOW(), INTERVAL 15 DAY)),
('INV-BR08-005', 8, 32, 26, 7100.00, 'fixed', 0.00, 0.00, 7100.00, 'card', 7100.00, 0.00, 'completed', DATE_SUB(NOW(), INTERVAL 13 DAY)),
('INV-BR08-006', 8, 33, 23, 5600.00, 'fixed', 300.00, 300.00, 5300.00, 'cash', 5300.00, 0.00, 'completed', DATE_SUB(NOW(), INTERVAL 11 DAY)),
('INV-BR08-007', 8, 32, 24, 8100.00, 'fixed', 0.00, 0.00, 8100.00, 'cash', 8100.00, 0.00, 'completed', DATE_SUB(NOW(), INTERVAL 8 DAY)),
('INV-BR08-008', 8, 33, 27, 4900.00, 'fixed', 0.00, 0.00, 4900.00, 'bankak', 4900.00, 0.00, 'completed', DATE_SUB(NOW(), INTERVAL 5 DAY)),
('INV-BR08-009', 8, 32, 22, 13400.00, 'fixed', 1000.00, 1000.00, 12400.00, 'cash', 13400.00, 1000.00, 'completed', DATE_SUB(NOW(), INTERVAL 3 DAY)),
('INV-BR08-010', 8, 33, 28, 6600.00, 'fixed', 0.00, 0.00, 6600.00, 'card', 6600.00, 0.00, 'completed', DATE_SUB(NOW(), INTERVAL 1 DAY));

-- ============================================================
-- 9. إضافة عناصر الفواتير (invoice_items)
-- ============================================================
-- عناصر الفواتير للفرع 1
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
-- 10. إضافة مصروفات (40 سجل)
-- ============================================================
INSERT IGNORE INTO expenses (branch_id, category, amount, description, expense_date, created_by, created_at) VALUES
(1, 'إيجار', 150000.00, 'إيجار شهر يناير', '2024-01-01', 2, DATE_SUB(NOW(), INTERVAL 30 DAY)),
(1, 'رواتب', 200000.00, 'رواتب الموظفين شهر يناير', '2024-01-05', 2, DATE_SUB(NOW(), INTERVAL 29 DAY)),
(1, 'كهرباء وماء', 25000.00, 'فاتورة كهرباء يناير', '2024-01-10', 2, DATE_SUB(NOW(), INTERVAL 28 DAY)),
(1, 'مواصلات', 15000.00, 'وقود السيارات', '2024-01-15', 2, DATE_SUB(NOW(), INTERVAL 27 DAY)),
(1, 'مستلزمات مكتبية', 5000.00, 'ورق وأقلام', '2024-01-20', 2, DATE_SUB(NOW(), INTERVAL 26 DAY)),
(2, 'إيجار', 120000.00, 'إيجار شهر يناير', '2024-01-01', 4, DATE_SUB(NOW(), INTERVAL 30 DAY)),
(2, 'رواتب', 180000.00, 'رواتب الموظفين شهر يناير', '2024-01-05', 4, DATE_SUB(NOW(), INTERVAL 29 DAY)),
(2, 'كهرباء وماء', 20000.00, 'فاتورة كهرباء يناير', '2024-01-10', 4, DATE_SUB(NOW(), INTERVAL 28 DAY)),
(2, 'مواصلات', 12000.00, 'وقود السيارات', '2024-01-15', 4, DATE_SUB(NOW(), INTERVAL 27 DAY)),
(2, 'صيانة', 8000.00, 'صيانة المكيفات', '2024-01-20', 4, DATE_SUB(NOW(), INTERVAL 26 DAY)),
(3, 'إيجار', 100000.00, 'إيجار شهر يناير', '2024-01-01', 6, DATE_SUB(NOW(), INTERVAL 30 DAY)),
(3, 'رواتب', 160000.00, 'رواتب الموظفين شهر يناير', '2024-01-05', 6, DATE_SUB(NOW(), INTERVAL 29 DAY)),
(3, 'كهرباء وماء', 18000.00, 'فاتورة كهرباء يناير', '2024-01-10', 6, DATE_SUB(NOW(), INTERVAL 28 DAY)),
(3, 'مواصلات', 10000.00, 'وقود السيارات', '2024-01-15', 6, DATE_SUB(NOW(), INTERVAL 27 DAY)),
(3, 'تسويق وإعلان', 15000.00, 'إعلان فيسبوك', '2024-01-20', 6, DATE_SUB(NOW(), INTERVAL 26 DAY)),
(4, 'إيجار', 90000.00, 'إيجار شهر يناير', '2024-01-01', 8, DATE_SUB(NOW(), INTERVAL 30 DAY)),
(4, 'رواتب', 150000.00, 'رواتب الموظفين شهر يناير', '2024-01-05', 8, DATE_SUB(NOW(), INTERVAL 29 DAY)),
(4, 'كهرباء وماء', 15000.00, 'فاتورة كهرباء يناير', '2024-01-10', 8, DATE_SUB(NOW(), INTERVAL 28 DAY)),
(4, 'مواصلات', 8000.00, 'وقود السيارات', '2024-01-15', 8, DATE_SUB(NOW(), INTERVAL 27 DAY)),
(4, 'مصروفات متنوعة', 5000.00, 'أخرى', '2024-01-20', 8, DATE_SUB(NOW(), INTERVAL 26 DAY)),
(5, 'إيجار', 80000.00, 'إيجار شهر يناير', '2024-01-01', 10, DATE_SUB(NOW(), INTERVAL 30 DAY)),
(5, 'رواتب', 140000.00, 'رواتب الموظفين شهر يناير', '2024-01-05', 10, DATE_SUB(NOW(), INTERVAL 29 DAY)),
(5, 'كهرباء وماء', 12000.00, 'فاتورة كهرباء يناير', '2024-01-10', 10, DATE_SUB(NOW(), INTERVAL 28 DAY)),
(5, 'مواصلات', 7000.00, 'وقود السيارات', '2024-01-15', 10, DATE_SUB(NOW(), INTERVAL 27 DAY)),
(5, 'صيانة', 6000.00, 'صيانة الحاسوب', '2024-01-20', 10, DATE_SUB(NOW(), INTERVAL 26 DAY)),
(6, 'إيجار', 70000.00, 'إيجار شهر يناير', '2024-01-01', 12, DATE_SUB(NOW(), INTERVAL 30 DAY)),
(6, 'رواتب', 130000.00, 'رواتب الموظفين شهر يناير', '2024-01-05', 12, DATE_SUB(NOW(), INTERVAL 29 DAY)),
(6, 'كهرباء وماء', 10000.00, 'فاتورة كهرباء يناير', '2024-01-10', 12, DATE_SUB(NOW(), INTERVAL 28 DAY)),
(6, 'مواصلات', 6000.00, 'وقود السيارات', '2024-01-15', 12, DATE_SUB(NOW(), INTERVAL 27 DAY)),
(6, 'تسويق وإعلان', 10000.00, 'إعلان راديو', '2024-01-20', 12, DATE_SUB(NOW(), INTERVAL 26 DAY)),
(7, 'إيجار', 60000.00, 'إيجار شهر يناير', '2024-01-01', 14, DATE_SUB(NOW(), INTERVAL 30 DAY)),
(7, 'رواتب', 120000.00, 'رواتب الموظفين شهر يناير', '2024-01-05', 14, DATE_SUB(NOW(), INTERVAL 29 DAY)),
(7, 'كهرباء وماء', 8000.00, 'فاتورة كهرباء يناير', '2024-01-10', 14, DATE_SUB(NOW(), INTERVAL 28 DAY)),
(7, 'مواصلات', 5000.00, 'وقود السيارات', '2024-01-15', 14, DATE_SUB(NOW(), INTERVAL 27 DAY)),
(7, 'مصروفات متنوعة', 4000.00, 'أخرى', '2024-01-20', 14, DATE_SUB(NOW(), INTERVAL 26 DAY)),
(8, 'إيجار', 50000.00, 'إيجار شهر يناير', '2024-01-01', 16, DATE_SUB(NOW(), INTERVAL 30 DAY)),
(8, 'رواتب', 110000.00, 'رواتب الموظفين شهر يناير', '2024-01-05', 16, DATE_SUB(NOW(), INTERVAL 29 DAY)),
(8, 'كهرباء وماء', 7000.00, 'فاتورة كهرباء يناير', '2024-01-10', 16, DATE_SUB(NOW(), INTERVAL 28 DAY)),
(8, 'مواصلات', 4000.00, 'وقود السيارات', '2024-01-15', 16, DATE_SUB(NOW(), INTERVAL 27 DAY)),
(8, 'صيانة', 3000.00, 'صيانة الطابعة', '2024-01-20', 16, DATE_SUB(NOW(), INTERVAL 26 DAY));

-- ============================================================
-- 11. إضافة إعدادات المتجر الافتراضية
-- ============================================================
INSERT IGNORE INTO settings (setting_key, setting_value, branch_id) VALUES
('store_name_ar', 'توب شاين - جمالكِ يبدأ من هنا', NULL),
('store_name_en', 'Top Shine - Your Beauty Starts Here', NULL),
('invoice_footer_ar', 'شكراً لتعاملكم معنا — توب شاين', NULL),
('invoice_footer_en', 'Thank you for shopping with Top Shine', NULL),
('currency', 'SDG', NULL),
('thermal_header_ar', 'توب شاين — جمالكِ يبدأ من هنا', NULL),
('thermal_header_en', 'Top Shine — Your Beauty Starts Here', NULL),
('thermal_footer_ar', 'شكراً لزيارتكم', NULL),
('thermal_footer_en', 'Thank you for your visit', NULL);

-- ============================================================
-- تم إضافة البيانات التجريبية بنجاح
-- ============================================================
