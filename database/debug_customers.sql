SET NAMES utf8mb4;
USE topshine_db;

-- التحقق من الاستعلام الكامل مع الفلاتر
SELECT COUNT(*) as count FROM customers c WHERE c.status = 'active';

-- التحقق من الاستعلام مع JOIN
SELECT COUNT(*) as count 
FROM customers c
LEFT JOIN branches b ON b.id = c.registered_branch_id
WHERE c.status = 'active';

-- التحقق من الاستعلام مع ORDER BY
SELECT COUNT(*) as count 
FROM customers c
LEFT JOIN branches b ON b.id = c.registered_branch_id
WHERE c.status = 'active'
ORDER BY c.total_purchases DESC, c.name ASC;
