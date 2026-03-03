-- PostgreSQL fixture: source database state (diff1pgsql)
-- Mirrors the MySQL db1-up.sql structure but uses standard SQL / Postgres syntax.

CREATE TABLE aa (
    id integer NOT NULL,
    name varchar(255) NOT NULL,
    pass varchar(255) DEFAULT NULL,
    "as" integer NOT NULL,
    qw integer NOT NULL,
    PRIMARY KEY (id),
    UNIQUE (name)
);

INSERT INTO aa (id, name, pass, "as", qw) VALUES
    (1, 'aa', 'zz', 1, 0),
    (2, 'bb', 'vv', 2, 0),
    (3, 'cc', 'zz', 1, 0);

CREATE TABLE bb (
    id integer NOT NULL,
    jj integer NOT NULL,
    PRIMARY KEY (id)
);

INSERT INTO bb (id, jj) VALUES (1, 0), (2, 0), (3, 0);

CREATE TABLE cc (
    id integer NOT NULL,
    PRIMARY KEY (id)
);

INSERT INTO cc (id) VALUES (11);

ALTER TABLE aa ADD CONSTRAINT fk_aa_bb FOREIGN KEY ("as") REFERENCES bb (id);
