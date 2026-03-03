-- SQLite: single_table fixture - database 2
-- ignored fields differ but should not appear in the diff

CREATE TABLE test_table (
  id                    INTEGER PRIMARY KEY AUTOINCREMENT,
  name                  TEXT NOT NULL,
  description           TEXT,
  ignored_field         TEXT DEFAULT 'this_change_should_be_ignored',
  another_ignored_field INTEGER DEFAULT 777,
  important_field       TEXT NOT NULL,
  new_important_field   TEXT DEFAULT 'new_value'
);

INSERT INTO test_table (id, name, description, ignored_field, another_ignored_field, important_field, new_important_field) VALUES
(1, 'Test 1 Modified', 'Description 1 Modified', 'different_ignore', 333, 'important_value_1_modified', 'new_1'),
(3, 'Test 3',          'Description 3',          'new_ignore',       444, 'important_value_3',          'new_3');
