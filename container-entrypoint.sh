#!/bin/sh
set -eu

# Railway mounts volumes as root-owned directories. Give the service account
# write access before Supervisor drops the web and worker processes privileges.
mkdir -p /data
chown app:app /data
exec /usr/local/bin/supervisord -n -c /app/supervisord.conf
