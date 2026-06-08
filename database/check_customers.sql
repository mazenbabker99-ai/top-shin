SET NAMES utf8mb4;
USE topshine_db;
SELECT COUNT(*) as total_customers, 
       SUM(CASE WHEN status='active' THEN 1 ELSE 0 END) as active_customers,
       SUM(CASE WHEN status='inactive' THEN 1 ELSE 0 END) as inactive_customers
FROM customers;
