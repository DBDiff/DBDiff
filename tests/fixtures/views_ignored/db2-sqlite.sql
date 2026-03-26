-- Bug #6 regression fixture (SQLite) — target database

CREATE TABLE IF NOT EXISTS "products" (
  "id"    INTEGER NOT NULL,
  "name"  TEXT    NOT NULL,
  "price" REAL    DEFAULT NULL,
  "active" INTEGER NOT NULL DEFAULT 1,
  PRIMARY KEY ("id")
);

INSERT INTO "products" ("id", "name", "price", "active") VALUES
  (1, 'Widget', 9.99, 1),
  (2, 'Gadget', 19.99, 1);
