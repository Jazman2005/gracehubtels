-- Wallet hardening: dedupe_signature column on transactions.
--
-- Lets debit_wallet() reject a duplicate purchase attempt while an earlier
-- one with the same user+category+parameters is still 'pending'/'processing'
-- — closing the gap where claim_purchase_lock()'s fixed 5-second window
-- expires before a slow provider call finishes, letting a retry through as
-- a "new" purchase. See user/core/db.php::debit_wallet().
--
-- Deliberately NOT unique — the same signature must be reusable once the
-- prior transaction reaches a terminal status (success/failed/refunded), so
-- a genuine repeat purchase (e.g. buying the same airtime amount again next
-- week) isn't permanently blocked the way reusing the UNIQUE idempotency_key
-- column for this would have been.
ALTER TABLE transactions
    ADD COLUMN dedupe_signature VARCHAR(80) DEFAULT NULL,
    ADD INDEX idx_dedupe (user_id, status, dedupe_signature);
