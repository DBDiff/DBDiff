-- Single table fixture - database 2
-- Has differences but ignored fields should not appear in diff

CREATE TABLE `test_table` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `description` text,
  `ignored_field` varchar(100) DEFAULT 'this_change_should_be_ignored',  -- Different but should be ignored
  `another_ignored_field` int DEFAULT 777,  -- Different but should be ignored
  `important_field` varchar(255) NOT NULL,
  `new_important_field` varchar(255) DEFAULT 'new_value',  -- This should appear in diff
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `test_table` (`id`, `name`, `description`, `ignored_field`, `another_ignored_field`, `important_field`, `new_important_field`) VALUES 
(1, 'Test 1 Modified', 'Description 1 Modified', 'different_ignore', 333, 'important_value_1_modified', 'new_1'),
(3, 'Test 3', 'Description 3', 'new_ignore', 444, 'important_value_3', 'new_3');
