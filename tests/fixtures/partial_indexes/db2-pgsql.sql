-- PostgreSQL: partial_indexes fixture - database 2
-- Partial index changed, index dropped, new partial index added, expression index added

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

-- Changed: partial index condition changed from is_public=TRUE to status='scheduled'
CREATE INDEX idx_events_upcoming ON events (start_date) WHERE status = 'scheduled';
-- Dropped: idx_events_venue
-- Changed: idx_events_status_date dropped, replaced with different column combo
CREATE INDEX idx_events_status_public ON events (status, is_public);
-- New: expression index on lower(venue)
CREATE INDEX idx_events_venue_lower ON events (LOWER(venue));
-- New: partial index with capacity filter
CREATE INDEX idx_events_large ON events (venue, capacity) WHERE capacity > 100;

INSERT INTO events (id, title, start_date, end_date, venue, is_public, status, capacity) VALUES
(1, 'Conference 2024', '2024-06-01 09:00:00', '2024-06-03 17:00:00', 'Main Hall',    TRUE,  'completed',  500),
(2, 'Private Meeting', '2024-07-15 14:00:00', '2024-07-15 16:00:00', 'Room 101',     FALSE, 'cancelled',  20),
(3, 'Workshop',        '2024-08-10 10:00:00', '2024-08-10 17:00:00', 'Lab A',        TRUE,  'scheduled',  30),
(4, 'Summit 2025',     '2025-01-20 09:00:00', NULL,                   'Grand Arena',  TRUE,  'scheduled', 1000);
