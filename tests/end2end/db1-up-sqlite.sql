-- SQLite fixture: source database state (db1)
-- Uses SQLite-compatible types (INTEGER, TEXT, REAL, BLOB).

CREATE TABLE aa (
    id INTEGER NOT NULL,
    name TEXT NOT NULL,
    pass TEXT DEFAULT NULL,
    "as" INTEGER NOT NULL,
    qw INTEGER NOT NULL,
    PRIMARY KEY (id),
    UNIQUE (name)
);

INSERT INTO aa (id, name, pass, "as", qw) VALUES
    (1, 'aa', 'zz', 1, 0),
    (2, 'bb', 'vv', 2, 0),
    (3, 'cc', 'zz', 1, 0);

CREATE TABLE bb (
    id INTEGER NOT NULL,
    jj INTEGER NOT NULL,
    PRIMARY KEY (id)
);

INSERT INTO bb (id, jj) VALUES (1, 0), (2, 0), (3, 0);

CREATE TABLE cc (
    id INTEGER NOT NULL,
    PRIMARY KEY (id)
);

INSERT INTO cc (id) VALUES (11);
