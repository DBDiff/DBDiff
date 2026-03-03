-- SQLite: basic_schema_data fixture - database 2 (target)
-- db2 has a wider schema: extra columns and indexes.
-- Data is deliberately identical to db1 so that data-diff produces
-- empty output (SQLite's CONVERT/cross-DB join is MySQL-only).

CREATE TABLE users (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  name       TEXT NOT NULL,
  email      TEXT NOT NULL UNIQUE,
  phone      TEXT DEFAULT NULL,
  status     TEXT DEFAULT 'pending',
  created_at TEXT DEFAULT '2024-01-01 00:00:00',
  updated_at TEXT DEFAULT '2024-01-01 00:00:00'
);

CREATE INDEX users_status_idx ON users (status);

INSERT INTO users (id, name, email, status, created_at) VALUES
(1, 'John Doe',   'john@example.com', 'active', '2024-01-01 00:00:00'),
(2, 'Jane Smith', 'jane@example.com', 'active', '2024-01-01 00:00:00'),
(3, 'Bob Wilson', 'bob@example.com',  'active', '2024-01-01 00:00:00');

CREATE TABLE posts (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id      INTEGER NOT NULL,
  title        TEXT NOT NULL,
  content      TEXT,
  published    INTEGER DEFAULT 0,
  published_at TEXT DEFAULT NULL
);

CREATE INDEX posts_user_id_idx   ON posts (user_id);
CREATE INDEX posts_published_idx ON posts (published);

INSERT INTO posts (id, user_id, title, content, published) VALUES
(1, 1, 'First Post',  'This is the first post content',  1),
(2, 1, 'Second Post', 'This is the second post content', 0),
(3, 2, 'Jane Post',   'This is Jane post content',       1);
