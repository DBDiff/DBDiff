-- Programmable objects fixture (PostgreSQL) — source database

CREATE TABLE products (
  id SERIAL NOT NULL,
  name VARCHAR(255) NOT NULL,
  price NUMERIC(10,2) NOT NULL DEFAULT 0.00,
  updated_at TIMESTAMP DEFAULT NULL,
  PRIMARY KEY (id)
);

INSERT INTO products (id, name, price) VALUES
  (1, 'Widget', 9.99),
  (2, 'Gadget', 19.99);

-- View only in source → CreateView
CREATE VIEW product_names AS
  SELECT id, name FROM products;

-- View in both but different definition → AlterView
CREATE VIEW expensive_products AS
  SELECT id, name, price FROM products WHERE price > 10;

-- Trigger + trigger function only in source → CreateTrigger + CreateRoutine
CREATE FUNCTION trg_products_updated_fn() RETURNS trigger
  LANGUAGE plpgsql
AS $$
BEGIN
  NEW.updated_at = NOW();
  RETURN NEW;
END;
$$;

CREATE TRIGGER trg_products_updated BEFORE UPDATE ON products
  FOR EACH ROW EXECUTE FUNCTION trg_products_updated_fn();

-- Function only in source → CreateRoutine
CREATE FUNCTION get_product_count() RETURNS integer
  LANGUAGE sql
  STABLE
AS $$
  SELECT count(*)::integer FROM products;
$$;
