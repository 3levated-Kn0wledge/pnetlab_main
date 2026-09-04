-- Minimum control rows for an offline login.
--
-- Source: docs/REFERENCE-ENVIRONMENT.md. Applied on every install run: these
-- rows are the switch that turns the appliance on (docs/OFFLINE-FIRST.md; offline
-- is the only mode since Phase 05, so ctrl_online_mode and ctrl_default_mode went)
-- and drift here is a bug, not a local preference.
--
-- Applied against pnetlab_db. Requires the `control` table, which ships in the
-- appliance image and is not in this repository.

REPLACE INTO control (control_name, control_value) VALUES
  ('ctrl_offline_mode', '1'),
  ('ctrl_version',      '5.3.13');
