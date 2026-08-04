-- Give transaction-PIN resets their own token columns, separate from the
-- account password-reset flow (users.reset_token / reset_token_expiry).
-- Previously both flows shared the same columns: a weak 6-digit PIN-reset
-- OTP could be replayed against the unauthenticated password-reset endpoint
-- (api/v1/forgot-password.php) to take over the account password, since that
-- endpoint validates only `reset_token=?` with no purpose/scope check.
ALTER TABLE users ADD COLUMN IF NOT EXISTS `pin_reset_token` VARCHAR(10) DEFAULT NULL AFTER `reset_token_expiry`;
ALTER TABLE users ADD COLUMN IF NOT EXISTS `pin_reset_token_expiry` DATETIME DEFAULT NULL AFTER `pin_reset_token`;
