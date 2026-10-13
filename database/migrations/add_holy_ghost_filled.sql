ALTER TABLE distributions
    ADD COLUMN holy_ghost_filled INT NOT NULL DEFAULT 0 AFTER souls_saved;
