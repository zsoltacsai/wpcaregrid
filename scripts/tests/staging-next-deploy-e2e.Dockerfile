FROM node:22.23.3-bookworm-slim@sha256:3ed98443660ff48413b5af19ecefce3b9d50845a901a43420d47cc63a9fddbe9 AS node
WORKDIR /opt/wpcg-npm-seed
COPY package.json package-lock.json ./
RUN npm ci --ignore-scripts --no-audit --no-fund --cache /opt/wpcg-npm-cache

FROM composer:2@sha256:8de2184c6de224f4689e8f80ed5f5edaf0030b6b8b7839a22a2bcb6722ac87d0 AS composer

FROM mysql:8.4.11@sha256:6ea90827b1100f8f2ae306a539f86d2c264a26ed435a2a9f75551dd5c3aeb242 AS mysql-client

FROM php:8.5-cli-bookworm@sha256:050713ce008e2360ea9dc96e1f9a075690132388d917fc3552806f7a7be6f2a0

RUN apt-get update \
    && apt-get install -y --no-install-recommends python3 git passwd procps util-linux libncurses6 libmariadb-dev libcurl4-openssl-dev libicu-dev libzip-dev \
    && docker-php-ext-install curl intl pdo_mysql zip \
    && useradd --uid 1000 --create-home --shell /usr/sbin/nologin deploy-staging-next \
    && rm -rf /var/lib/apt/lists/*

COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules
COPY --from=node /opt/wpcg-npm-cache /opt/wpcg-npm-cache
RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s /usr/local/lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx \
    && chown -R 1000:1000 /opt/wpcg-npm-cache \
    && chmod -R go-rwx /opt/wpcg-npm-cache

COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY --from=mysql-client /usr/bin/mysql /usr/bin/mysql
COPY --from=mysql-client /usr/bin/mysqldump /usr/bin/mysqldump
WORKDIR /opt/wpcaregrid-source
COPY composer.json composer.lock ./
ENV COMPOSER_ALLOW_SUPERUSER=1
RUN php /usr/local/bin/composer install --no-dev --no-interaction --prefer-dist --no-progress --no-scripts --optimize-autoloader
COPY . /opt/wpcaregrid-source
