-- Bug #7 regression fixture — source database (MySQL)
-- Rows with NULL values in a nullable column must be detected during data diff.
-- Before the fix, CONCAT(...NULL...) = NULL so all nulled rows hashed identically
-- and differences were silently missed.

CREATE TABLE `items` (
  `id`          int          NOT NULL,
  `name`        varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `items` (`id`, `name`, `description`) VALUES
  (1, 'Widget', NULL),          -- description is NULL here, 'A widget description' in target
  (2, 'Gadget', 'A gadget'),    -- description is 'A gadget' here, NULL in target
  (3, 'Donut',  NULL);          -- NULL in both: must NOT appear in the diff
