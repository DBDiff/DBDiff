-- Basic schema and data fixture - database 1
-- This fixture has differences in both schema and data

CREATE TABLE `users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `users` (`id`, `name`, `email`, `status`) VALUES 
(1, 'John Doe', 'john@example.com', 'active'),
(2, 'Jane Smith', 'jane@example.com', 'active'),
(3, 'Bob Wilson', 'bob@example.com', 'inactive');

CREATE TABLE `posts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `title` varchar(255) NOT NULL,
  `content` text,
  `published` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `posts` (`id`, `user_id`, `title`, `content`, `published`) VALUES 
(1, 1, 'First Post', 'This is the first post content', 1),
(2, 1, 'Second Post', 'This is the second post content', 0),
(3, 2, 'Jane Post', 'This is Jane post content', 1);
