ALTER TABLE distributions
    ADD COLUMN phone VARCHAR(50) NOT NULL DEFAULT '' AFTER distributor_name,
    ADD COLUMN email VARCHAR(255) NOT NULL DEFAULT '' AFTER phone,
    ADD COLUMN kingschat_username VARCHAR(100) NOT NULL DEFAULT '' AFTER email;
