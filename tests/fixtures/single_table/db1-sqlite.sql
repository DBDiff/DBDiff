-- SQLite: single_table fixture - database 1 (source)
-- Data is identical in both db1 and db2; only schema differs.

CREATE TABLE test_table (
  id                    INTEGER PRIMARY KEY AUTOINCREMENT,
  name                  TEXT NOT NULL,
  description           TEXT,
  ignored_field         TEXT DEFAULT 'should_be_ignored',
  another_ignored_field INTEGER DEFAULT 999,
  important_field       TEXT NOT NULL
);

INSERT INTO test_table (id, name, description, ignored_field, another_ignored_field, important_field) VALUES
(1, 'Test 1', 'Description 1', 'ignore_this',     111, 'important_value_1'),
(2, 'Test 2', 'Description 2', 'ignore_this_too', 222, 'important_value_2');

