-- PostgreSQL: constraints fixture - database 1
-- Tests foreign keys, check constraints, composite unique constraints

CREATE TABLE categories (
  id   SERIAL PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE,
  slug VARCHAR(100) NOT NULL UNIQUE
);

CREATE TABLE products (
  id          SERIAL PRIMARY KEY,
  category_id INT NOT NULL,
  name        VARCHAR(255) NOT NULL,
  sku         VARCHAR(20) NOT NULL,
  price       NUMERIC(10,2) NOT NULL,
  status      VARCHAR(20) NOT NULL DEFAULT 'draft',
  CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES categories(id),
  CONSTRAINT chk_price_positive CHECK (price > 0),
  CONSTRAINT uq_category_sku UNIQUE (category_id, sku)
);

CREATE TABLE order_items (
  id         SERIAL PRIMARY KEY,
  product_id INT NOT NULL,
  quantity   INT NOT NULL,
  unit_price NUMERIC(10,2) NOT NULL,
  CONSTRAINT fk_order_items_product FOREIGN KEY (product_id) REFERENCES products(id),
  CONSTRAINT chk_quantity_positive CHECK (quantity > 0)
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
