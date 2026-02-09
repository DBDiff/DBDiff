-- Single table fixture - database 1
-- For testing single table diffs and field ignoring

CREATE TABLE `test_table` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `description` text,
  `ignored_field` varchar(100) DEFAULT 'should_be_ignored',
  `another_ignored_field` int DEFAULT 999,
  `important_field` varchar(255) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `test_table` (`id`, `name`, `description`, `ignored_field`, `another_ignored_field`, `important_field`) VALUES 
(1, 'Test 1', 'Description 1', 'ignore_this', 111, 'important_value_1'),
(2, 'Test 2', 'Description 2', 'ignore_this_too', 222, 'important_value_2');
