-- SQLite: constraints fixture - database 2

CREATE TABLE categories (
  id   INTEGER PRIMARY KEY AUTOINCREMENT,
  name VARCHAR(100) NOT NULL UNIQUE,
  slug VARCHAR(100) NOT NULL UNIQUE
);

CREATE TABLE products (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  category_id INTEGER NOT NULL,
  name        VARCHAR(255) NOT NULL,
  sku         VARCHAR(20) NOT NULL,
  price       NUMERIC(10,2) NOT NULL,
  status      VARCHAR(20) NOT NULL DEFAULT 'active',
  FOREIGN KEY (category_id) REFERENCES categories(id),
  CHECK (price >= 0),
  CHECK (status IN ('draft', 'active', 'archived'))
);

CREATE TABLE order_items (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  product_id INTEGER NOT NULL,
  quantity   INTEGER NOT NULL,
  unit_price NUMERIC(10,2) NOT NULL,
  discount   NUMERIC(5,2) NOT NULL DEFAULT 0.00,
  CHECK (quantity > 0),
  CHECK (discount >= 0 AND discount <= 100)
);

INSERT INTO categories (id, name, slug) VALUES
(1, 'Electronics', 'electronics'),
(2, 'Clothing',    'clothing'),
(3, 'Books',       'books');

INSERT INTO products (id, category_id, name, sku, price, status) VALUES
(1, 1, 'Laptop',  'ELC-001', 999.99, 'active'),
(2, 1, 'Phone',   'ELC-002', 599.99, 'active'),
(3, 2, 'T-Shirt', 'CLT-001', 19.99,  'active');

INSERT INTO order_items (id, product_id, quantity, unit_price, discount) VALUES
(1, 1, 2, 999.99, 0.00),
(2, 2, 1, 599.99, 10.00);
