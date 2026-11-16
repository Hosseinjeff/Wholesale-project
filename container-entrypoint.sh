#!/bin/sh
set -eu

# Railway mounts volumes as root-owned directories. Give the service account
# write access before Gunicorn drops the worker's privileges.
mkdir -p /data
chown app:app /data
exec /usr/local/bin/gunicorn --config /app/gunicorn.conf.py app:app --bind "0.0.0.0:${PORT:-8080}" --workers 1 --threads 2 --user app --group app --timeout 900 --graceful-timeout 20
