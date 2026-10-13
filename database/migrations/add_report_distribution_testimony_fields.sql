ALTER TABLE reports
ADD COLUMN IF NOT EXISTS distribution_digital INT NOT NULL DEFAULT 0 AFTER total_distribution,
ADD COLUMN IF NOT EXISTS distribution_physical INT NOT NULL DEFAULT 0 AFTER distribution_digital,
ADD COLUMN IF NOT EXISTS crusades INT NOT NULL DEFAULT 0 AFTER external_ministers,
ADD COLUMN IF NOT EXISTS testimonies_received INT NOT NULL DEFAULT 0 AFTER crusades;
