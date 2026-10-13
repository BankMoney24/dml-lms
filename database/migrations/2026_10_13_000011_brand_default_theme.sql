-- DML Technologies brand: new schools start with the "DML blue" theme.
-- Existing schools keep the colour they chose.
ALTER TABLE tenants ALTER COLUMN theme SET DEFAULT 'dml';
