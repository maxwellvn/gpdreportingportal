ALTER TABLE users
MODIFY COLUMN role ENUM('admin','admin-manager','user') DEFAULT 'user';
