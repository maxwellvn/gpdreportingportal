-- Add name column to users table
ALTER TABLE users
ADD COLUMN name VARCHAR(255) DEFAULT NULL AFTER username;

-- Update existing users to have their username as name if name is NULL
UPDATE users 
SET name = username 
WHERE name IS NULL; 