-- Adds the "reporting as a Network" option (separate from Zone/Group/Church/Cell),
-- plus Country/City on the distributor.
ALTER TABLE distributions
    ADD COLUMN network VARCHAR(255) NOT NULL DEFAULT '' AFTER zone,
    ADD COLUMN network_other VARCHAR(255) NOT NULL DEFAULT '' AFTER network,
    ADD COLUMN country VARCHAR(100) NOT NULL DEFAULT '' AFTER distributor_name,
    ADD COLUMN city VARCHAR(100) NOT NULL DEFAULT '' AFTER country,
    ADD COLUMN testimony_text TEXT NULL AFTER testimonies_file;
