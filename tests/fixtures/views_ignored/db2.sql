-- Bug #6 regression fixture — target database
-- Same table as source but with an extra column (schema diff is intentional so
-- the diff runs and produces real output — confirms we can assert against it).
-- No view present here.

CREATE TABLE `products` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `price` decimal(10,2) DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `products` (`id`, `name`, `price`, `active`) VALUES
  (1, 'Widget', 9.99, 1),
  (2, 'Gadget', 19.99, 1);
