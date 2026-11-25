#!/bin/sh
set -eu
cd "$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
if [ ! -f .env.production ]; then
    echo '請先依 docs/DEPLOYMENT.md 建立 .env.production。' >&2
    exit 1
fi
exec docker compose --env-file .env.production -p warehouse-production -f compose.production.yaml "$@"
