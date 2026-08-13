-- Electricity: separate postpaid charge.
--
-- electricity_discos previously had one flat `charge` applied to every
-- purchase on that disco regardless of meter type, even though postpaid bill
-- payments commonly carry a different service fee than prepaid top-ups.
-- NULL means "no override" — falls back to the existing `charge` for
-- postpaid too, so this is a no-op until an admin explicitly sets it.
-- See grace-hub-tels/service-electricity.php and user/core/electricity.php::process().
ALTER TABLE electricity_discos
    ADD COLUMN postpaid_charge DECIMAL(10,2) DEFAULT NULL AFTER charge;
