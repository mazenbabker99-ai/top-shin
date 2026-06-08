SET NAMES utf8mb4;
USE topshine_db;
SELECT COUNT(*) as product_count FROM products WHERE status = 'active';
