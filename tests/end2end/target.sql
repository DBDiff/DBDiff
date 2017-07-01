CREATE TABLE IF NOT EXISTS `aa` (
  `id` int(11) NOT NULL,
  `name` varchar(255) CHARACTER SET latin1 NOT NULL DEFAULT 'aa',
  `pass` varchar(255) CHARACTER SET latin1 NOT NULL,
  `zx` int(11) NOT NULL,
  PRIMARY KEY (`id`,`name`),
  UNIQUE KEY `pass` (`pass`),
  UNIQUE KEY `namekey` (`name`,`pass`,`zx`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_spanish_ci;

INSERT INTO `aa` (`id`, `name`, `pass`, `zx`) VALUES (1, 'aa', 'zz', 0),(2, 'bb', 'ww', 0),(4, 'dd', 'xx', 0);

CREATE TABLE IF NOT EXISTS `asas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `firstname` varchar(255) COLLATE latin1_bin NOT NULL,
  `lastname` varchar(255) COLLATE latin1_bin NOT NULL,
  PRIMARY KEY (`id`,`firstname`)
) ENGINE=InnoDB  DEFAULT CHARSET=latin1 COLLATE=latin1_bin AUTO_INCREMENT=8 ;

INSERT INTO `asas` (`id`, `firstname`, `lastname`) VALUES (1, 'a', 'b'),(2, 'x', 'd'),(6, 't', 'y'),(7, 'e', 'r');

CREATE TABLE IF NOT EXISTS `bb` (
  `id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_bin;

INSERT INTO `bb` (`id`) VALUES (1),(2);

CREATE TABLE IF NOT EXISTS `zz` (
  `id` int(11) NOT NULL,
  `name` varchar(13) CHARACTER SET latin1 COLLATE latin1_german1_ci NOT NULL DEFAULT 'lol',
  `time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `bool` tinyint(1) NOT NULL,
  PRIMARY KEY (`id`,`name`),
  UNIQUE KEY `name` (`name`,`time`),
  KEY `time` (`time`,`bool`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

INSERT INTO `zz` (`id`, `name`, `time`, `bool`) VALUES (1, 'name', '2015-05-10 17:54:05', 1);

