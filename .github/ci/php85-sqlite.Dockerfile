# CI image for the Server SQLite regression gate (PHP 8.5). Every base image is pinned by digest.
# Built on the job runner; the layer cache persists on the self-hosted runner, so only the first build compiles the extensions.
FROM node:22.21.1-bookworm-slim@sha256:25b3eb23a00590b7499f2a2ce939322727fcce1b15fdd69754fcd09536a3ae2c AS node
FROM composer:2.9@sha256:b09bccd91a78fe8a9ab4b33d707b862e8fe54fec17782e32683ad2a69c46867d AS composer
FROM php:8.5.12-cli@sha256:50956f094e90a716d43bea28087d447555ad80510e386ab8c9c10d8c70d781a9

ENV DEBIAN_FRONTEND=noninteractive
# Required PHP extensions (same list as the former setup-php step): mbstring, intl, gd, zip, sodium, pdo_sqlite, sqlite3, fileinfo,
# openssl, curl, posix, pcntl, bcmath. mbstring, sodium, pdo_sqlite, sqlite3, fileinfo, openssl, curl and posix are part of the base image.
RUN apt-get update \
 && apt-get install -y --no-install-recommends ca-certificates git unzip libicu-dev libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype-dev libwebp-dev \
 && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
 && docker-php-ext-install -j"$(nproc)" intl zip gd pcntl bcmath \
 && rm -rf /var/lib/apt/lists/*

COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
 && ln -s ../lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
