-- SQLite: column_type_changes fixture - database 1

CREATE TABLE products (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  name        VARCHAR(100) NOT NULL,
  description VARCHAR(255),
  price       NUMERIC(10,2) NOT NULL DEFAULT 0.00,
  quantity    INTEGER NOT NULL DEFAULT 0,
  sku         VARCHAR(20) NOT NULL,
  is_active   INTEGER NOT NULL DEFAULT 1,
  created_at  TEXT DEFAULT (datetime('now'))
);

INSERT INTO products (id, name, description, price, quantity, sku, is_active) VALUES
(1, 'Widget',    'A small widget',  9.99,  100, 'WDG-001', 1),
(2, 'Gadget',    'A fancy gadget',  29.99, 50,  'GDG-001', 1),
(3, 'Doohickey', NULL,              4.99,  200, 'DHK-001', 0);
