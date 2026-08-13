-- The test suite runs against real PostgreSQL, not SQLite: the append-only triggers
-- and the enabled_modules CHECK are database-level guarantees that SQLite cannot
-- express, so testing on SQLite would exercise a schema missing its invariants.
CREATE DATABASE poslah_testing OWNER poslah;
