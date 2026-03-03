-- SQLite: single_table fixture - database 2 (target)
-- Schema differences: ignored fields have different defaults, new_important_field added.
-- Data is identical to db1 to avoid MySQL-specific cross-DB join SQL.

CREATE TABLE test_table (
  id                    INTEGER PRIMARY KEY AUTOINCREMENT,
  name                  TEXT NOT NULL,
  description           TEXT,
  ignored_field         TEXT DEFAULT 'this_change_should_be_ignored',
  another_ignored_field INTEGER DEFAULT 777,
  important_field       TEXT NOT NULL,
  new_important_field   TEXT DEFAULT 'new_value'
);

INSERT INTO test_table (id, name, description, ignored_field, another_ignored_field, important_field) VALUES
(1, 'Test 1', 'Description 1', 'ignore_this',     111, 'important_value_1'),
(2, 'Test 2', 'Description 2', 'ignore_this_too', 222, 'important_value_2');

