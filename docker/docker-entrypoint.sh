#!/usr/bin/env bash

function waitForMySQL {
  # Wait for the docker MySQL db host & port to become available
  # Use DB_HOST environment variable if set, otherwise default to 'db'
  DB_HOST_NAME=${DB_HOST:-db}
  ./scripts/wait-for-it.sh $DB_HOST_NAME:3306 --timeout=300
}

# Defaults to running the DBDiff command with any arguments that were passed in
# Otherwise if phpunit is the first argument, runs the phpunit test suite
RUN_CMD=${1:-false}

if [[ $RUN_CMD == 'phpunit' ]]; then
  waitForMySQL
  ./vendor/bin/phpunit
else
  waitForMySQL
  ./dbdiff "$@"
fi