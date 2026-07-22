-- SQLite: constraints fixture - database 1

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
  status      VARCHAR(20) NOT NULL DEFAULT 'draft',
  FOREIGN KEY (category_id) REFERENCES categories(id),
  CHECK (price > 0),
  UNIQUE (category_id, sku)
);

CREATE TABLE order_items (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  product_id INTEGER NOT NULL,
  quantity   INTEGER NOT NULL,
  unit_price NUMERIC(10,2) NOT NULL,
  FOREIGN KEY (product_id) REFERENCES products(id),
  CHECK (quantity > 0)
);

INSERT INTO categories (id, name, slug) VALUES
(1, 'Electronics', 'electronics'),
(2, 'Clothing',    'clothing');

INSERT INTO products (id, category_id, name, sku, price, status) VALUES
(1, 1, 'Laptop',  'ELC-001', 999.99, 'active'),
(2, 1, 'Phone',   'ELC-002', 499.99, 'active'),
(3, 2, 'T-Shirt', 'CLT-001', 19.99,  'draft');

INSERT INTO order_items (id, product_id, quantity, unit_price) VALUES
(1, 1, 2, 999.99),
(2, 2, 1, 499.99);
