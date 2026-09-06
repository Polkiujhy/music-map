#!/bin/sh
set -eu

umask 027

. /usr/local/libexec/music-map-bootstrap-runtime
music_map_bootstrap_runtime

php /usr/local/libexec/music-map-wait-for-postgres

exec "$@"
