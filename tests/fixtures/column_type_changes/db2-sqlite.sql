-- SQLite: column_type_changes fixture - database 2

CREATE TABLE products (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  name        VARCHAR(255) NOT NULL,
  description TEXT,
  price       NUMERIC(12,4) NOT NULL DEFAULT 0.0000,
  quantity    INTEGER NOT NULL DEFAULT 0,
  sku         VARCHAR(20),
  is_active   INTEGER NOT NULL DEFAULT 0,
  created_at  TEXT DEFAULT '2024-01-01 00:00:00'
);

INSERT INTO products (id, name, description, price, quantity, sku, is_active) VALUES
(1, 'Widget',    'A small widget (updated)', 9.9900,  100, 'WDG-001', 1),
(2, 'Gadget',    'A fancy gadget',           34.9900, 50,  'GDG-001', 1),
(3, 'Doohickey', 'Now with a description',   4.9900,  200, NULL,      1);
