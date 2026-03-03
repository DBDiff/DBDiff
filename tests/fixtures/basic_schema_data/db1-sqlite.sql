-- SQLite: basic_schema_data fixture - database 1

CREATE TABLE users (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  name       TEXT NOT NULL,
  email      TEXT NOT NULL UNIQUE,
  status     TEXT DEFAULT 'active',
  created_at TEXT DEFAULT '2024-01-01 00:00:00'
);

INSERT INTO users (id, name, email, status, created_at) VALUES
(1, 'John Doe',   'john@example.com', 'active',   '2024-01-01 00:00:00'),
(2, 'Jane Smith', 'jane@example.com', 'active',   '2024-01-01 00:00:00'),
(3, 'Bob Wilson', 'bob@example.com',  'inactive', '2024-01-01 00:00:00');

CREATE TABLE posts (
  id        INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id   INTEGER NOT NULL,
  title     TEXT NOT NULL,
  content   TEXT,
  published INTEGER DEFAULT 0
);

CREATE INDEX posts_user_id_idx ON posts (user_id);

INSERT INTO posts (id, user_id, title, content, published) VALUES
(1, 1, 'First Post',  'This is the first post content',  1),
(2, 1, 'Second Post', 'This is the second post content', 0),
(3, 2, 'Jane Post',   'This is Jane post content',       1);
