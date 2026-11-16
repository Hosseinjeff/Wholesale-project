#!/bin/sh
set -eu

# Railway mounts volumes as root-owned directories. Give the service account
# write access before Supervisor drops the web and worker processes privileges.
mkdir -p /data
chown app:app /data
exec /usr/local/bin/gunicorn app:app --bind "0.0.0.0:${PORT:-8080}" --workers 1 --threads 2 --user app --group app --timeout 900 --graceful-timeout 20
