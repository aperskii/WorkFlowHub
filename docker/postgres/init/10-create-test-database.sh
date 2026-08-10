#!/bin/bash
#
# Creates the dedicated WorkFlowHub test database alongside the development
# database, so the automated test suite can never touch development data.
#
# Executed by the postgres entrypoint only on first initialization of an empty
# data volume. To create the test database on an existing volume, run:
#
#   docker compose exec postgres \
#       createdb -U "$DB_USERNAME" -O "$DB_USERNAME" workflowhub_testing
#
# No `set -e` here: the postgres entrypoint may source this file rather than
# execute it, and shell options would leak into the entrypoint. The entrypoint
# already runs with `set -Eeo pipefail`, and ON_ERROR_STOP makes psql fail loudly.
test_database="${DB_TEST_DATABASE:-${POSTGRES_DB}_testing}"

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<-EOSQL
    CREATE DATABASE "${test_database}" OWNER "${POSTGRES_USER}";
EOSQL

echo "Created test database: ${test_database}"
