SET NAMES utf8mb4;
USE topshine_db;

SELECT 
    b.name AS branch_name,
    COUNT(c.id) as customer_count
FROM customers c
LEFT JOIN branches b ON b.id = c.registered_branch_id
WHERE c.status = 'active'
GROUP BY b.id, b.name
ORDER BY customer_count DESC;
