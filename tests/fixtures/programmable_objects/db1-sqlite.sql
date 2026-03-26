-- Programmable objects fixture (SQLite) — source database
-- SQLite has no stored procedures/functions, so only testing views and triggers.

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

-- View only in source → CreateView
CREATE VIEW "product_names" AS
  SELECT "id", "name" FROM "products";

-- View in both but different definition → AlterView
CREATE VIEW "expensive_products" AS
  SELECT "id", "name", "price" FROM "products" WHERE "price" > 10;

-- Trigger only in source → CreateTrigger
CREATE TRIGGER "trg_products_updated" BEFORE UPDATE ON "products"
  FOR EACH ROW
  BEGIN
    UPDATE "products" SET "updated_at" = datetime('now') WHERE "id" = NEW."id";
  END;
