SET NAMES utf8mb4;
USE topshine_db;

-- حذف العملاء المكررة بناءً على رقم الهاتف
DELETE c1 FROM customers c1
INNER JOIN customers c2
WHERE c1.id > c2.id
AND c1.phone = c2.phone
AND c1.phone IS NOT NULL
AND c1.phone != '';
