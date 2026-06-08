SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;
SET COLLATION_CONNECTION = utf8mb4_unicode_ci;

USE topshine_db;

-- حذف جميع الفئات
DELETE FROM categories;

-- إعادة إدخال الفئات بشكل صحيح
INSERT INTO categories (name_ar, name_en) VALUES
('مستحضرات تجميل للوجه', 'Face Care Products'),
('عناية بالشعر', 'Hair Care'),
('عناية بالبشرة', 'Skin Care'),
('مكياج', 'Makeup'),
('عطور', 'Perfumes'),
('عناية بالجسم', 'Body Care'),
('منتجات الرجال', 'Men Products'),
('إكسسوارات', 'Accessories');
