-- PostgreSQL: partial_indexes fixture - database 1
-- Tests partial (conditional) indexes, expression indexes, and multi-column indexes

CREATE TABLE events (
  id         SERIAL PRIMARY KEY,
  title      VARCHAR(255) NOT NULL,
  start_date TIMESTAMP NOT NULL,
  end_date   TIMESTAMP,
  venue      VARCHAR(255),
  is_public  BOOLEAN NOT NULL DEFAULT TRUE,
  status     VARCHAR(20) NOT NULL DEFAULT 'scheduled',
  capacity   INT
);

CREATE INDEX idx_events_public ON events (start_date) WHERE is_public = TRUE;
CREATE INDEX idx_events_venue ON events (venue);
CREATE INDEX idx_events_status_date ON events (status, start_date);

INSERT INTO events (id, title, start_date, end_date, venue, is_public, status, capacity) VALUES
(1, 'Conference 2024', '2024-06-01 09:00:00', '2024-06-03 17:00:00', 'Main Hall',    TRUE,  'completed', 500),
(2, 'Private Meeting', '2024-07-15 14:00:00', '2024-07-15 16:00:00', 'Room 101',     FALSE, 'scheduled', 20),
(3, 'Workshop',        '2024-08-10 10:00:00', NULL,                   'Lab A',        TRUE,  'scheduled', 30);
