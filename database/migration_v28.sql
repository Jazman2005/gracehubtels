-- Per-install feature flags — lets the vendor enable/disable individual
-- services (airtime, betting, virtual cards, etc.) per client install
-- without any code changes. See user/core/db.php::feature_enabled() and
-- grace-hub-tels/vendor-access.php.
--
-- Absence of a row for a given feature_key means ENABLED (fail open) — this
-- table only needs a row inserted for a feature you want to actively
-- DISABLE. feature_enabled() also self-creates this table on first use, so
-- running this migration is optional/documentation, not required.
CREATE TABLE IF NOT EXISTS feature_flags (
    feature_key VARCHAR(50) NOT NULL PRIMARY KEY,
    is_enabled TINYINT(1) NOT NULL DEFAULT 1,
    label VARCHAR(100) DEFAULT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
