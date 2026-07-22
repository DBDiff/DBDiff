-- MySQL: column_type_changes fixture - database 1

CREATE TABLE products (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(100) NOT NULL,
  description VARCHAR(255),
  price       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  quantity    INT NOT NULL DEFAULT 0,
  sku         VARCHAR(20) NOT NULL,
  is_active   TINYINT(1) NOT NULL DEFAULT 1,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO products (id, name, description, price, quantity, sku, is_active) VALUES
(1, 'Widget',    'A small widget',  9.99,  100, 'WDG-001', 1),
(2, 'Gadget',    'A fancy gadget',  29.99, 50,  'GDG-001', 1),
(3, 'Doohickey', NULL,              4.99,  200, 'DHK-001', 0);
