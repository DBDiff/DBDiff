-- PostgreSQL fixture: target database state (diff2pgsql)
-- Mirrors the MySQL db2-up.sql structure but uses standard SQL / Postgres syntax.

CREATE TABLE aa (
    id integer NOT NULL,
    name varchar(255) NOT NULL DEFAULT 'aa',
    pass varchar(255) NOT NULL,
    zx integer NOT NULL,
    PRIMARY KEY (id, name),
    UNIQUE (pass),
    UNIQUE (name, pass, zx)
);

INSERT INTO aa (id, name, pass, zx) VALUES
    (1, 'aa', 'zz', 0),
    (2, 'bb', 'ww', 0),
    (4, 'dd', 'xx', 0);

CREATE TABLE bb (
    id integer NOT NULL
);

INSERT INTO bb (id) VALUES (1), (2);

CREATE TABLE zz (
    id integer NOT NULL,
    name varchar(13) NOT NULL DEFAULT 'lol',
    bool boolean NOT NULL,
    PRIMARY KEY (id, name),
    UNIQUE (name)
);

INSERT INTO zz (id, name, bool) VALUES (1, 'name', TRUE);
