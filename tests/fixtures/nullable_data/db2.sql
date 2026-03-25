-- Bug #7 regression fixture — target database (MySQL)

CREATE TABLE `items` (
  `id`          int          NOT NULL,
  `name`        varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `items` (`id`, `name`, `description`) VALUES
  (1, 'Widget', 'A widget description'),  -- was NULL in source
  (2, 'Gadget', NULL),                    -- was 'A gadget' in source
  (3, 'Donut',  NULL);                    -- NULL in both: must stay out of diff
