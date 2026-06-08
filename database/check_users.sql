SET NAMES utf8mb4;
USE topshine_db;
SELECT id, name, username, role, branch_id FROM users WHERE role = 'super_admin' OR role = 'branch_admin';
