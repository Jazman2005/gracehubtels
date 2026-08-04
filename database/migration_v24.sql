ALTER TABLE users ADD COLUMN IF NOT EXISTS `cashwyre_customer_code` VARCHAR(100) DEFAULT NULL COMMENT 'Cashwyre customer code for virtual cards' AFTER `wallet_balance`;
