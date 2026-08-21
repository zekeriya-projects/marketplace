#!/bin/sh
set -eu

psql --set ON_ERROR_STOP=1 \
    --username "$POSTGRES_USER" \
    --dbname "$POSTGRES_DB" \
    --command 'CREATE DATABASE marketplace_saas_testing'
