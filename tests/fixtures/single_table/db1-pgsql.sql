-- PostgreSQL: single_table fixture - database 1
-- For testing single table diffs and field ignoring

CREATE TABLE test_table (
  id                   SERIAL PRIMARY KEY,
  name                 VARCHAR(255) NOT NULL,
  description          TEXT,
  ignored_field        VARCHAR(100) DEFAULT 'should_be_ignored',
  another_ignored_field INT DEFAULT 999,
  important_field      VARCHAR(255) NOT NULL
);

INSERT INTO test_table (id, name, description, ignored_field, another_ignored_field, important_field) VALUES
(1, 'Test 1', 'Description 1', 'ignore_this',     111, 'important_value_1'),
(2, 'Test 2', 'Description 2', 'ignore_this_too', 222, 'important_value_2');
