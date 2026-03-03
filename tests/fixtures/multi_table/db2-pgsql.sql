-- PostgreSQL: multi_table fixture - database 2
-- posts and categories tables will be ignored in tests

CREATE TABLE users (
  id    SERIAL PRIMARY KEY,
  name  VARCHAR(255) NOT NULL,
  email VARCHAR(255) NOT NULL,
  phone VARCHAR(20) DEFAULT NULL
);

INSERT INTO users (id, name, email, phone) VALUES
(1, 'User 1 Modified', 'user1@example.com', '555-1111'),
(3, 'User 3',          'user3@example.com', '555-3333');

CREATE TABLE posts (
  id      SERIAL PRIMARY KEY,
  user_id INT NOT NULL,
  title   VARCHAR(255) NOT NULL,
  content TEXT
);

INSERT INTO posts (id, user_id, title, content) VALUES
(1, 1, 'Post 1 Modified', 'Content 1'),
(3, 3, 'Post 3',          'Content 3');

CREATE TABLE categories (
  id          SERIAL PRIMARY KEY,
  name        VARCHAR(255) NOT NULL,
  description TEXT
);

INSERT INTO categories (id, name, description) VALUES
(1, 'Category 1 Modified', 'Description 1'),
(3, 'Category 3',          'Description 3');
