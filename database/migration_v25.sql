ALTER TABLE virtual_cards ADD COLUMN IF NOT EXISTS `card_number` VARCHAR(30) DEFAULT NULL COMMENT 'Full card number (encrypted/stored for reveal)' AFTER `masked_number`;
ALTER TABLE virtual_cards ADD COLUMN IF NOT EXISTS `cvv` VARCHAR(10) DEFAULT NULL COMMENT 'Card CVV' AFTER `expiry`;
