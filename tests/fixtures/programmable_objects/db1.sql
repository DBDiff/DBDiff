-- Programmable objects fixture (MySQL) — source database
-- Tests: CreateView, AlterView, CreateTrigger, CreateRoutine

CREATE TABLE `products` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `products` (`id`, `name`, `price`) VALUES
  (1, 'Widget', 9.99),
  (2, 'Gadget', 19.99);

-- View only in source → CreateView
CREATE VIEW `product_names` AS
  SELECT `id`, `name` FROM `products`;

-- View in both but different definition → AlterView
CREATE VIEW `expensive_products` AS
  SELECT `id`, `name`, `price` FROM `products` WHERE `price` > 10;

-- Trigger only in source → CreateTrigger
CREATE TRIGGER `trg_products_updated` BEFORE UPDATE ON `products`
  FOR EACH ROW SET NEW.`updated_at` = NOW();

-- Function only in source → CreateRoutine (simple form, no DELIMITER needed)
CREATE FUNCTION `get_product_count`() RETURNS int
  READS SQL DATA
  DETERMINISTIC
  RETURN (SELECT COUNT(*) FROM `products`);
