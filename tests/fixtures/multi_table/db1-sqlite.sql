-- SQLite: multi_table fixture - database 1 (source)
-- Data differences mirror the MySQL/Postgres fixtures.

CREATE TABLE users (
  id    INTEGER PRIMARY KEY AUTOINCREMENT,
  name  TEXT NOT NULL,
  email TEXT NOT NULL
);

INSERT INTO users (id, name, email) VALUES
(1, 'User 1', 'user1@example.com'),
(2, 'User 2', 'user2@example.com');

CREATE TABLE posts (
  id      INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL,
  title   TEXT NOT NULL
);

INSERT INTO posts (id, user_id, title) VALUES
(1, 1, 'Post 1'),
(2, 2, 'Post 2');

CREATE TABLE categories (
  id   INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL
);

INSERT INTO categories (id, name) VALUES
(1, 'Category 1'),
(2, 'Category 2');

