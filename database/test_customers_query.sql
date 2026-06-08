SET NAMES utf8mb4;
USE topshine_db;

-- نفس الاستعلام الموجود في customers.php
SELECT
    c.id, c.name, c.phone, c.total_purchases,
    c.registered_branch_id, c.notes, c.status, c.created_at,
    b.name AS branch_name
FROM customers c
LEFT JOIN branches b ON b.id = c.registered_branch_id
WHERE c.status = 'active'
ORDER BY c.total_purchases DESC, c.name ASC
LIMIT 25 OFFSET 0;
