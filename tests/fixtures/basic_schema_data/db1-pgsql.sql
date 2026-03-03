-- PostgreSQL: basic_schema_data fixture - database 1
-- This fixture has differences in both schema and data compared to db2

CREATE TABLE users (
  id         SERIAL PRIMARY KEY,
  name       VARCHAR(255) NOT NULL,
  email      VARCHAR(255) NOT NULL,
  status     VARCHAR(20) DEFAULT 'active',
  created_at TIMESTAMP DEFAULT '2024-01-01 00:00:00',
  CONSTRAINT users_email_unique UNIQUE (email)
);

INSERT INTO users (id, name, email, status, created_at) VALUES
(1, 'John Doe',   'john@example.com', 'active',   '2024-01-01 00:00:00'),
(2, 'Jane Smith', 'jane@example.com', 'active',   '2024-01-01 00:00:00'),
(3, 'Bob Wilson', 'bob@example.com',  'inactive', '2024-01-01 00:00:00');

CREATE TABLE posts (
  id        SERIAL PRIMARY KEY,
  user_id   INT NOT NULL,
  title     VARCHAR(255) NOT NULL,
  content   TEXT,
  published BOOLEAN DEFAULT FALSE
);

CREATE INDEX posts_user_id_idx ON posts (user_id);

INSERT INTO posts (id, user_id, title, content, published) VALUES
(1, 1, 'First Post',  'This is the first post content',  TRUE),
(2, 1, 'Second Post', 'This is the second post content', FALSE),
(3, 2, 'Jane Post',   'This is Jane post content',       TRUE);
