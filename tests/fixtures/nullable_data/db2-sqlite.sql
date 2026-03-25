-- Bug #7 regression fixture (SQLite) — target database

CREATE TABLE IF NOT EXISTS "items" (
  "id"          INTEGER NOT NULL,
  "name"        TEXT    NOT NULL,
  "description" TEXT    DEFAULT NULL,
  PRIMARY KEY ("id")
);

INSERT INTO "items" ("id", "name", "description") VALUES
  (1, 'Widget', 'A widget description'),
  (2, 'Gadget', NULL),
  (3, 'Donut',  NULL);
