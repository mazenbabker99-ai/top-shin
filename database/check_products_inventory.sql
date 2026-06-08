SET NAMES utf8mb4;
USE topshine_db;

SELECT 
    p.id,
    p.name_ar,
    p.category_id,
    COALESCE(inv.quantity, 0) as inventory_qty
FROM products p
LEFT JOIN inventory inv ON inv.product_id = p.id AND inv.branch_id = 1
WHERE p.status = 'active'
LIMIT 10;
