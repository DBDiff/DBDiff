-- Bug #7 regression fixture (SQLite) — source database
-- SQLite uses IS NOT for NULL-safe comparison, so this is a cross-driver
-- sanity check rather than a direct regression test for the MySQL IFNULL fix.

CREATE TABLE IF NOT EXISTS "items" (
  "id"          INTEGER NOT NULL,
  "name"        TEXT    NOT NULL,
  "description" TEXT    DEFAULT NULL,
  PRIMARY KEY ("id")
);

INSERT INTO "items" ("id", "name", "description") VALUES
  (1, 'Widget', NULL),
  (2, 'Gadget', 'A gadget'),
  (3, 'Donut',  NULL);
