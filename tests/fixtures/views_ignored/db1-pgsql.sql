-- Bug #6 regression fixture (PostgreSQL) — source database

CREATE TABLE products (
  id SERIAL NOT NULL,
  name VARCHAR(255) NOT NULL,
  active BOOLEAN NOT NULL DEFAULT TRUE,
  PRIMARY KEY (id)
);

INSERT INTO products (id, name, active) VALUES
  (1, 'Widget', true),
  (2, 'Gadget', true);

-- PostgresAdapter already uses pg_tables (excludes views), but the test still
-- validates the behaviour across all drivers.
CREATE VIEW active_products AS
  SELECT id, name FROM products WHERE active = true;
