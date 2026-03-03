-- PostgreSQL: basic_schema_data fixture - database 2
-- Has schema and data differences from db1

CREATE TABLE users (
  id         SERIAL PRIMARY KEY,
  name       VARCHAR(255) NOT NULL,
  email      VARCHAR(255) NOT NULL,
  phone      VARCHAR(20) DEFAULT NULL,
  status     VARCHAR(20) DEFAULT 'pending',
  created_at TIMESTAMP DEFAULT '2024-01-01 00:00:00',
  updated_at TIMESTAMP DEFAULT '2024-01-01 00:00:00',
  CONSTRAINT users_email_unique UNIQUE (email)
);

CREATE INDEX users_status_idx ON users (status);

INSERT INTO users (id, name, email, phone, status, created_at, updated_at) VALUES
(1, 'John Doe',    'john@example.com',  '555-1234', 'active',  '2024-01-01 00:00:10', '2024-01-01 00:00:10'),
(2, 'Jane Smith',  'jane@example.com',  '555-5678', 'pending', '2024-01-01 00:00:10', '2024-01-01 00:00:10'),
(4, 'Alice Brown', 'alice@example.com', '555-9999', 'active',  '2024-01-01 00:00:10', '2024-01-01 00:00:10');

CREATE TABLE posts (
  id           SERIAL PRIMARY KEY,
  user_id      INT NOT NULL,
  title        VARCHAR(255) NOT NULL,
  content      TEXT,
  published    BOOLEAN DEFAULT FALSE,
  published_at TIMESTAMP DEFAULT NULL
);

CREATE INDEX posts_user_id_idx  ON posts (user_id);
CREATE INDEX posts_published_idx ON posts (published);

INSERT INTO posts (id, user_id, title, content, published, published_at) VALUES
(1, 1, 'First Post Updated', 'This is the updated first post content', TRUE,  '2024-01-01 10:00:00'),
(2, 1, 'Second Post',        'This is the second post content',        TRUE,  '2024-01-02 11:00:00'),
(4, 4, 'Alice Post',         'This is Alice post content',             TRUE,  '2024-01-03 12:00:00');
