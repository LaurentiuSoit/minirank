FROM php:8-cli

# pdo_sqlite is not in the base image. The official PHP Debian image compiles
# the driver against the SYSTEM sqlite3 library (via pkg-config), not a bundled
# amalgamation, so we must install libsqlite3-dev first.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends libsqlite3-dev; \
    docker-php-ext-install pdo_sqlite; \
    rm -rf /var/lib/apt/lists/*

WORKDIR /app

# Package the app into the image. .dockerignore keeps build context lean.
COPY . /app

EXPOSE 8000

# Standalone run uses the same command compose uses; compose may override it.
CMD ["php", "-S", "0.0.0.0:8000", "-t", "public", "public/router.php"]
