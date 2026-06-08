SET NAMES utf8mb4;
USE topshine_db;

SELECT 
    p.id,
    p.name_ar,
    p.category_id,
    p.status
FROM products p
WHERE p.category_id >= 83
LIMIT 10;
