-- SQLite: multi_table fixture - database 2 (target)
-- Schema differences: users gains phone, posts gains content, categories gains description.
-- Data differences mirror the MySQL/Postgres fixtures.

CREATE TABLE users (
  id    INTEGER PRIMARY KEY AUTOINCREMENT,
  name  TEXT NOT NULL,
  email TEXT NOT NULL,
  phone TEXT DEFAULT NULL
);

INSERT INTO users (id, name, email, phone) VALUES
(1, 'User 1 Modified', 'user1@example.com', '555-1111'),
(3, 'User 3',          'user3@example.com', '555-3333');

CREATE TABLE posts (
  id      INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL,
  title   TEXT NOT NULL,
  content TEXT
);

INSERT INTO posts (id, user_id, title, content) VALUES
(1, 1, 'Post 1 Modified', 'Content 1'),
(3, 3, 'Post 3',          'Content 3');

CREATE TABLE categories (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  name        TEXT NOT NULL,
  description TEXT
);

INSERT INTO categories (id, name, description) VALUES
(1, 'Category 1 Modified', 'Description 1'),
(3, 'Category 3',          'Description 3');

