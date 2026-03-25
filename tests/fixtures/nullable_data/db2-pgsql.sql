-- Bug #7 regression fixture (PostgreSQL) — target database

CREATE TABLE items (
  id          INT          NOT NULL,
  name        VARCHAR(100) NOT NULL,
  description VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (id)
);

INSERT INTO items (id, name, description) VALUES
  (1, 'Widget', 'A widget description'),
  (2, 'Gadget', NULL),
  (3, 'Donut',  NULL);
