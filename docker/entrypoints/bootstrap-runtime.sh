#!/bin/sh

music_map_bootstrap_runtime() {
    cache=/var/www/html/bootstrap/cache
    manifest=/usr/local/share/music-map/package-manifest.tar

    for directory in \
        /var/www/html/storage \
        /var/www/html/storage/app \
        /var/www/html/storage/framework/cache/data \
        /var/www/html/storage/framework/sessions \
        /var/www/html/storage/framework/views \
        /var/www/html/storage/logs; do
        if [ ! -d "$directory" ] || [ ! -w "$directory" ]; then
            echo "required runtime storage directory is not writable" >&2
            return 1
        fi
    done
    if [ ! -d "$cache" ] || [ ! -w "$cache" ]; then
        echo "ephemeral bootstrap cache is not writable" >&2
        return 1
    fi
    if [ ! -f "$manifest" ] || [ -L "$manifest" ]; then
        echo "immutable package manifest is unavailable" >&2
        return 1
    fi

    find "$cache" -mindepth 1 -maxdepth 1 -exec rm -f -- {} \;
    tar -xf "$manifest" -C "$cache"
    for required in packages.php services.php; do
        if [ ! -f "$cache/$required" ] || [ -L "$cache/$required" ]; then
            echo "restored package manifest is invalid" >&2
            return 1
        fi
    done
    if ! php artisan config:cache --no-ansi >/dev/null 2>&1; then
        echo "runtime configuration cache generation failed" >&2
        return 1
    fi
    if [ ! -f "$cache/config.php" ] || [ -L "$cache/config.php" ]; then
        echo "runtime configuration cache is invalid" >&2
        return 1
    fi
}
