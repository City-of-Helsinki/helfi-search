# Local development images, built by compose.yaml on the dev tags of the base
# images (City-of-Helsinki/drupal-docker-images, helfi-search/). The project
# folder is mounted over /app, so the code isn't copied in.


# The webhook.
FROM ghcr.io/city-of-helsinki/helfi-search-webhook:dev AS webhook

USER root
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
# Check changed files on every request instead of every 2 seconds.
RUN echo 'opcache.revalidate_freq=0' > "$PHP_INI_DIR/conf.d/zz-local.ini"

ENV COMPOSER_HOME=/tmp/composer
# The usual first user on Linux, so files created in the mounted project
# folder belong to the developer. Group 0 as on OpenShift.
USER 1000:0


# The worker and the full crawl.
FROM ghcr.io/city-of-helsinki/helfi-search-elasticsearch-crawler:dev AS elasticsearch-crawler

USER root
# phar and openssl are needed by Composer.
RUN apk add --no-cache php-8.4-phar php-8.4-openssl
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

ENV COMPOSER_HOME=/tmp/composer
USER 1000:0
