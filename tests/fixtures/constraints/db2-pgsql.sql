-- PostgreSQL: constraints fixture - database 2
-- FK dropped, check constraint changed, new FK added, composite unique dropped

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
  status      VARCHAR(20) NOT NULL DEFAULT 'active',
  CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES categories(id),
  CONSTRAINT chk_price_positive CHECK (price >= 0),
  CONSTRAINT chk_status_valid CHECK (status IN ('draft', 'active', 'archived'))
);

CREATE TABLE order_items (
  id         SERIAL PRIMARY KEY,
  product_id INT NOT NULL,
  quantity   INT NOT NULL,
  unit_price NUMERIC(10,2) NOT NULL,
  discount   NUMERIC(5,2) NOT NULL DEFAULT 0.00,
  CONSTRAINT chk_quantity_positive CHECK (quantity > 0),
  CONSTRAINT chk_discount_range CHECK (discount >= 0 AND discount <= 100)
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
