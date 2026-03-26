-- Programmable objects fixture (PostgreSQL) — target database

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

-- View only in target → DropView
CREATE VIEW old_report AS
  SELECT id, name FROM products WHERE price < 5;

-- View in both but different definition → AlterView
CREATE VIEW expensive_products AS
  SELECT id, name FROM products WHERE price > 50;

-- Trigger + trigger function only in target → DropTrigger + DropRoutine
CREATE FUNCTION trg_old_audit_fn() RETURNS trigger
  LANGUAGE plpgsql
AS $$
BEGIN
  RETURN OLD;
END;
$$;

CREATE TRIGGER trg_old_audit AFTER DELETE ON products
  FOR EACH ROW EXECUTE FUNCTION trg_old_audit_fn();

-- Procedure only in target → DropRoutine
CREATE FUNCTION cleanup_products() RETURNS void
  LANGUAGE sql
AS $$
  DELETE FROM products WHERE price = 0;
$$;
