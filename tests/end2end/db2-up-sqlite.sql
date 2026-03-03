-- SQLite fixture: target database state (db2)
-- Uses SQLite-compatible types (INTEGER, TEXT, REAL, BLOB).

CREATE TABLE aa (
    id INTEGER NOT NULL,
    name TEXT NOT NULL DEFAULT 'aa',
    pass TEXT NOT NULL,
    zx INTEGER NOT NULL,
    PRIMARY KEY (id, name),
    UNIQUE (pass),
    UNIQUE (name, pass, zx)
);

INSERT INTO aa (id, name, pass, zx) VALUES
    (1, 'aa', 'zz', 0),
    (2, 'bb', 'ww', 0),
    (4, 'dd', 'xx', 0);

CREATE TABLE bb (
    id INTEGER NOT NULL
);

INSERT INTO bb (id) VALUES (1), (2);

CREATE TABLE zz (
    id INTEGER NOT NULL,
    name TEXT NOT NULL DEFAULT 'lol',
    bool INTEGER NOT NULL,
    PRIMARY KEY (id, name),
    UNIQUE (name)
);

INSERT INTO zz (id, name, bool) VALUES (1, 'name', 1);
