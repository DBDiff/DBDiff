-- Multi-table fixture - database 2
-- Posts and categories tables will be ignored in tests

CREATE TABLE `users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,  -- New field in users (should appear in diff)
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `users` (`id`, `name`, `email`, `phone`) VALUES 
(1, 'User 1 Modified', 'user1@example.com', '555-1111'),  -- Modified data
(3, 'User 3', 'user3@example.com', '555-3333');  -- User 2 missing, User 3 added

CREATE TABLE `posts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `title` varchar(255) NOT NULL,
  `content` text,  -- New field (should be ignored)
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `posts` (`id`, `user_id`, `title`, `content`) VALUES 
(1, 1, 'Post 1 Modified', 'Content 1'),  -- Modified (should be ignored)
(3, 3, 'Post 3', 'Content 3');  -- New post (should be ignored)

CREATE TABLE `categories` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `description` text,  -- New field (should be ignored)
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `categories` (`id`, `name`, `description`) VALUES 
(1, 'Category 1 Modified', 'Description 1'),  -- Modified (should be ignored)
(3, 'Category 3', 'Description 3');  -- New category (should be ignored)
