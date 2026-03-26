-- Bug #7 regression fixture (PostgreSQL) — source database

CREATE TABLE items (
  id          INT          NOT NULL,
  name        VARCHAR(100) NOT NULL,
  description VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (id)
);

INSERT INTO items (id, name, description) VALUES
  (1, 'Widget', NULL),
  (2, 'Gadget', 'A gadget'),
  (3, 'Donut',  NULL);
