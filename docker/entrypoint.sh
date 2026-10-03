#!/bin/sh
set -eu
mkdir -p database/data storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
touch database/data/database.sqlite
exec "$@"
