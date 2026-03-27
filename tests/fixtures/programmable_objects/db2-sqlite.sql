-- Programmable objects fixture (SQLite) — target database

CREATE TABLE IF NOT EXISTS "products" (
  "id"         INTEGER NOT NULL,
  "name"       TEXT    NOT NULL,
  "price"      REAL    NOT NULL DEFAULT 0.00,
  "updated_at" TEXT    DEFAULT NULL,
  PRIMARY KEY ("id")
);

INSERT INTO "products" ("id", "name", "price") VALUES
  (1, 'Widget', 9.99),
  (2, 'Gadget', 19.99);

-- View only in target → DropView
CREATE VIEW "old_report" AS
  SELECT "id", "name" FROM "products" WHERE "price" < 5;

-- View in both but different definition → AlterView
CREATE VIEW "expensive_products" AS
  SELECT "id", "name" FROM "products" WHERE "price" > 50;

-- Trigger only in target → DropTrigger
CREATE TRIGGER "trg_old_audit" AFTER DELETE ON "products"
  FOR EACH ROW
  BEGIN
    SELECT 1;
  END;
