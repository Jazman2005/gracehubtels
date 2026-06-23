CREATE TABLE IF NOT EXISTS `bulk_sms` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `transaction_ref` varchar(60) NOT NULL,
  `sender_id` varchar(20) NOT NULL,
  `recipients` text NOT NULL,
  `message` text NOT NULL,
  `pages` tinyint(2) NOT NULL DEFAULT 1,
  `amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `status` enum('pending','success','failed') NOT NULL DEFAULT 'pending',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
