-- The default administrator, for an install with no users at all.
--
-- Source: docs/REFERENCE-ENVIRONMENT.md.
--
-- Credentials: admin / pnet. CHANGE THEM. This exists so that a fresh install
-- can be logged into at all, not because these are acceptable in service.
--
-- SHA2(...,256) reproduces what includes/ currently computes: an unsalted
-- SHA-256 digest. That is a defect under repair, not a pattern to copy into
-- new code.
--
-- The installer applies this ONLY when no admin user exists, or when
-- --reset-admin is given, so that re-running it does not reset the password on
-- a system in use.

REPLACE INTO users
  (pod, username, password, role, offline, user_status, folder, html5, online_time)
VALUES
  (2, 'admin', SHA2('pnet', 256), 'admin', 1, 1, '/', 0, UNIX_TIMESTAMP());
