-- MySQL: constraints fixture - database 1

CREATE TABLE categories (
  id   INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  slug VARCHAR(100) NOT NULL,
  UNIQUE KEY uq_name (name),
  UNIQUE KEY uq_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE products (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  category_id INT NOT NULL,
  name        VARCHAR(255) NOT NULL,
  sku         VARCHAR(20) NOT NULL,
  price       DECIMAL(10,2) NOT NULL,
  status      VARCHAR(20) NOT NULL DEFAULT 'draft',
  CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES categories(id),
  CONSTRAINT chk_price_positive CHECK (price > 0),
  UNIQUE KEY uq_category_sku (category_id, sku)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE order_items (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  product_id INT NOT NULL,
  quantity   INT NOT NULL,
  unit_price DECIMAL(10,2) NOT NULL,
  CONSTRAINT fk_order_items_product FOREIGN KEY (product_id) REFERENCES products(id),
  CONSTRAINT chk_quantity_positive CHECK (quantity > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
