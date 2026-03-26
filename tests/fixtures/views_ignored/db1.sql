-- Bug #6 regression fixture — source database
-- Contains a table plus a VIEW on that table.
-- After the fix, the view must NOT appear as a table in the diff output.

CREATE TABLE `products` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `products` (`id`, `name`, `active`) VALUES
  (1, 'Widget', 1),
  (2, 'Gadget', 1);

-- This VIEW must be silently ignored during the diff, not treated as a DROP TABLE target.
CREATE VIEW `active_products` AS
  SELECT `id`, `name` FROM `products` WHERE `active` = 1;
