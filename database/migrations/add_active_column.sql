-- Add active column to users table
ALTER TABLE users
ADD COLUMN active BOOLEAN DEFAULT TRUE AFTER role;

-- Update existing users to be active
UPDATE users SET active = TRUE WHERE active IS NULL;
