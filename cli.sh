#!/usr/bin/env sh
set -eu
host="${DUALDB_HOST:-localhost}"
port="${DUALDB_PORT:-3232}"
echo "DualDB Admin: http://${host}:${port}"
exec php -S "${host}:${port}" main.php
