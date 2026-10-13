-- The field-reporter role is called RZM everywhere in the UI; store it that way too.
-- Idempotent: safe to re-run.
ALTER TABLE `users` MODIFY `role` ENUM('admin','admin-manager','user','rzm') DEFAULT 'rzm';
UPDATE `users` SET `role` = 'rzm' WHERE `role` = 'user';
ALTER TABLE `users` MODIFY `role` ENUM('admin','admin-manager','rzm') DEFAULT 'rzm';
