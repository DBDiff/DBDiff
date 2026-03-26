-- Programmable objects fixture (MySQL) — target database
-- Tests: DropView, AlterView (different definition), DropTrigger, DropRoutine

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

-- View only in target → DropView
CREATE VIEW `old_report` AS
  SELECT `id`, `name` FROM `products` WHERE `price` < 5;

-- View in both but different definition → AlterView (matches source by name)
CREATE VIEW `expensive_products` AS
  SELECT `id`, `name` FROM `products` WHERE `price` > 50;

-- Trigger only in target → DropTrigger
CREATE TRIGGER `trg_old_audit` AFTER DELETE ON `products`
  FOR EACH ROW SET @dummy = 1;

-- Procedure only in target → DropRoutine (simple form, no DELIMITER needed)
CREATE PROCEDURE `cleanup_products`()
  SQL SECURITY INVOKER
  DELETE FROM `products` WHERE `price` = 0;
