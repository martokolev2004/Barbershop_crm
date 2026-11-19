-- Rollback of 0001. DESTROYS ALL CRM DATA – the migrate tool refuses to run it without --force
-- and always takes a backup first.
DROP TABLE IF EXISTS audit_log;
DROP TABLE IF EXISTS settings;
DROP TABLE IF EXISTS demos;
DROP TABLE IF EXISTS offers;
DROP TABLE IF EXISTS business_events;
DROP TABLE IF EXISTS businesses;
DROP TABLE IF EXISTS areas;
DROP TABLE IF EXISTS login_attempts;
DROP TABLE IF EXISTS users;
