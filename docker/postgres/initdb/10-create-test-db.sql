-- Runs once on fresh Postgres init (docker-entrypoint-initdb.d). Creates the
-- dedicated test database so integration tests (make test / fresh clones) have
-- an isolated DB and never touch the dev `keyforge` database.
-- POSTGRES_USER (keyforge) is the cluster superuser, so it owns it.
CREATE DATABASE keyforge_test OWNER keyforge;
