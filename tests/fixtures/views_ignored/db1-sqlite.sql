-- Bug #6 regression fixture (SQLite) — source database

CREATE TABLE IF NOT EXISTS "products" (
  "id"     INTEGER NOT NULL,
  "name"   TEXT    NOT NULL,
  "active" INTEGER NOT NULL DEFAULT 1,
  PRIMARY KEY ("id")
);

INSERT INTO "products" ("id", "name", "active") VALUES
  (1, 'Widget', 1),
  (2, 'Gadget', 1);

-- SQLite already filters on type='table', so this view must not appear in diffs.
CREATE VIEW "active_products" AS
  SELECT "id", "name" FROM "products" WHERE "active" = 1;
