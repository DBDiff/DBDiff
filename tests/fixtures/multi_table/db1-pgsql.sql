-- PostgreSQL: multi_table fixture - database 1
-- For testing table ignore functionality

CREATE TABLE users (
  id    SERIAL PRIMARY KEY,
  name  VARCHAR(255) NOT NULL,
  email VARCHAR(255) NOT NULL
);

INSERT INTO users (id, name, email) VALUES
(1, 'User 1', 'user1@example.com'),
(2, 'User 2', 'user2@example.com');

CREATE TABLE posts (
  id      SERIAL PRIMARY KEY,
  user_id INT NOT NULL,
  title   VARCHAR(255) NOT NULL
);

INSERT INTO posts (id, user_id, title) VALUES
(1, 1, 'Post 1'),
(2, 2, 'Post 2');

CREATE TABLE categories (
  id   SERIAL PRIMARY KEY,
  name VARCHAR(255) NOT NULL
);

INSERT INTO categories (id, name) VALUES
(1, 'Category 1'),
(2, 'Category 2');
