-- Bug #6 regression fixture (PostgreSQL) — target database

CREATE TABLE products (
  id SERIAL NOT NULL,
  name VARCHAR(255) NOT NULL,
  price DECIMAL(10,2) DEFAULT NULL,
  active BOOLEAN NOT NULL DEFAULT TRUE,
  PRIMARY KEY (id)
);

INSERT INTO products (id, name, price, active) VALUES
  (1, 'Widget', 9.99, true),
  (2, 'Gadget', 19.99, true);
