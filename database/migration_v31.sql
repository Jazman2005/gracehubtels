-- Electricity: separate provider Plan ID per meter type.
--
-- plan_api_bindings previously had one product_code per (service, plan_id,
-- api_registry_id) — fine for data/cabletv/data_card, but electricity
-- providers commonly catalog prepaid and postpaid as entirely different
-- products with different IDs for the same disco (e.g. SageCloud's own
-- compound slugs already encode this: "ikeja_electric_prepaid" vs
-- "ikeja_electric_postpaid"). This adds a meter_type dimension so a disco
-- can have a distinct Plan ID mapped for each.
--
-- meter_type defaults to '' (blank) and stays '' forever for every
-- non-electricity service — get_plan_bindings() passes '' for those calls,
-- so this is a no-op for data/data_card/cabletv.
--
-- Existing electricity mappings (created before this column existed) are
-- backfilled to 'prepaid' rather than left blank/orphaned, so a Plan ID an
-- admin already configured keeps working for prepaid purchases exactly as
-- before. Postpaid starts unmapped on those discos (falls back to the
-- disco's legacy single product_code, or the raw disco_id, same as
-- pre-migration behavior) until an admin fills it in via Plan ID Mapping.
ALTER TABLE plan_api_bindings
    ADD COLUMN meter_type VARCHAR(20) NOT NULL DEFAULT '' AFTER plan_id;

UPDATE plan_api_bindings SET meter_type = 'prepaid' WHERE service = 'electricity' AND meter_type = '';

ALTER TABLE plan_api_bindings
    DROP INDEX service_plan_api,
    ADD UNIQUE KEY service_plan_api (service, plan_id, api_registry_id, meter_type);
