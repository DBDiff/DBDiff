CREATE TABLE IF NOT EXISTS `aa` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `pass` varchar(255) DEFAULT NULL,
  `as` int(11) NOT NULL,
  `qw` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

INSERT INTO `aa` (`id`, `name`, `pass`, `as`, `qw`) VALUES (1, 'aa', 'zz', 1, 0),(2, 'bb', 'vv', 2, 0),(3, 'cc', 'zz', 1, 0);

CREATE TABLE IF NOT EXISTS `asas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `firstname` varchar(255) COLLATE latin1_spanish_ci NOT NULL,
  `lastname` varchar(255) COLLATE latin1_spanish_ci NOT NULL,
  PRIMARY KEY (`id`,`firstname`)
) ENGINE=InnoDB  DEFAULT CHARSET=latin1 COLLATE=latin1_spanish_ci AUTO_INCREMENT=5 ;

INSERT INTO `asas` (`id`, `firstname`, `lastname`) VALUES (1, 'a', 'bb'),(2, 'c', 'd'),(3, 'x', 'y'),(4, 'v', 'w');

CREATE TABLE IF NOT EXISTS `bb` (
  `id` int(11) NOT NULL,
  `jj` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

INSERT INTO `bb` (`id`, `jj`) VALUES (1, 0),(2, 0),(3, 0);

CREATE TABLE IF NOT EXISTS `cc` (
  `id` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

INSERT INTO `cc` (`id`) VALUES (11);

ALTER TABLE `aa` ADD CONSTRAINT `as` FOREIGN KEY (`as`) REFERENCES `bb` (`id`);
