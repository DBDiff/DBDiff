-- SQLite: basic_schema_data fixture - database 2

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

INSERT INTO users (id, name, email, phone, status, created_at, updated_at) VALUES
(1, 'John Doe',    'john@example.com',  '555-1234', 'active',  '2024-01-01 00:00:10', '2024-01-01 00:00:10'),
(2, 'Jane Smith',  'jane@example.com',  '555-5678', 'pending', '2024-01-01 00:00:10', '2024-01-01 00:00:10'),
(4, 'Alice Brown', 'alice@example.com', '555-9999', 'active',  '2024-01-01 00:00:10', '2024-01-01 00:00:10');

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

INSERT INTO posts (id, user_id, title, content, published, published_at) VALUES
(1, 1, 'First Post Updated', 'This is the updated first post content', 1, '2024-01-01 10:00:00'),
(2, 1, 'Second Post',        'This is the second post content',        1, '2024-01-02 11:00:00'),
(4, 4, 'Alice Post',         'This is Alice post content',             1, '2024-01-03 12:00:00');
