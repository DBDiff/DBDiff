-- Basic schema and data fixture - database 2
-- This has schema and data differences from db1

CREATE TABLE `users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,  -- New field
  `status` enum('active','inactive','pending') DEFAULT 'pending',  -- Modified enum
  `created_at` timestamp DEFAULT '2024-01-01 00:00:00',
  `updated_at` timestamp DEFAULT '2024-01-01 00:00:00',  -- Modified field
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  KEY `status` (`status`)  -- New index
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `users` (`id`, `name`, `email`, `phone`, `status`, `created_at`, `updated_at`) VALUES 
(1, 'John Doe', 'john@example.com', '555-1234', 'active', '2024-01-01 00:00:10', '2024-01-01 00:00:10'),
(2, 'Jane Smith', 'jane@example.com', '555-5678', 'pending', '2024-01-01 00:00:10', '2024-01-01 00:00:10'),
(4, 'Alice Brown', 'alice@example.com', '555-9999', 'active', '2024-01-01 00:00:10', '2024-01-01 00:00:10');

CREATE TABLE `posts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `title` varchar(255) NOT NULL,
  `content` text,
  `published` tinyint(1) DEFAULT 0,
  `published_at` datetime DEFAULT NULL,  -- New field
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `published` (`published`)  -- New index
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `posts` (`id`, `user_id`, `title`, `content`, `published`, `published_at`) VALUES 
(1, 1, 'First Post Updated', 'This is the updated first post content', 1, '2024-01-01 10:00:00'),  -- Modified
(2, 1, 'Second Post', 'This is the second post content', 1, '2024-01-02 11:00:00'),  -- Different published status
(4, 4, 'Alice Post', 'This is Alice post content', 1, '2024-01-03 12:00:00');  -- Jane's post missing, new post
