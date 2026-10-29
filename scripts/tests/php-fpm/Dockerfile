ARG PHP_FPM_BASE=php:8.5.12-fpm@sha256:8b4b4a764f7985137b13e4cb49dd59e967d9daa7946a731f9d06e31e748e39bb
FROM ${PHP_FPM_BASE}

RUN apt-get update \
    && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
        curl libcurl4-openssl-dev libfreetype6-dev libicu-dev libjpeg62-turbo-dev \
        libonig-dev libpng-dev libsqlite3-dev libwebp-dev libzip-dev nginx-light \
        iproute2 procps python3 \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" bcmath curl gd intl mbstring mysqli opcache pdo_mysql pdo_sqlite sqlite3 zip \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY app/ ./app/
COPY bootstrap/ ./bootstrap/
COPY config/ ./config/
COPY database/ ./database/
COPY lang/ ./lang/
COPY public/ ./public/
COPY resources/ ./resources/
COPY routes/ ./routes/
COPY storage/ ./storage/
COPY vendor/ ./vendor/
COPY artisan composer.json composer.lock ./
COPY scripts/tests/php-fpm/ /opt/php-fpm-test/

ENV PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin" \
    HOME=/tmp \
    APP_ENV=testing \
    CAREGRID_DEPLOYMENT_ENV=testing \
    MARKETING_RELEASE_PROFILE=marketing_only \
    MARKETING_RELEASE_ENFORCEMENT_MODE=testing \
    APP_DEBUG=false \
    APP_URL=http://127.0.0.1:8080 \
    DB_CONNECTION=sqlite \
    DB_DATABASE=/tmp/caregrid.sqlite \
    CACHE_STORE=array \
    SESSION_DRIVER=array \
    QUEUE_CONNECTION=database \
    DB_QUEUE=default \
    BACKUP_STORAGE= \
    RES_OPTIONS="attempts:1 timeout:1" \
    MAIL_MAILER=array \
    FILESYSTEM_DISK=local \
    STRIPE_ALLOW_LIVE_MODE=false \
    STRIPE_MODE=test \
    LOG_CHANNEL=stderr

USER www-data:www-data
ENTRYPOINT ["python3", "/opt/php-fpm-test/entrypoint.py"]
