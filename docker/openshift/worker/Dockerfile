# The worker's OpenShift image: applies the queued changes (app:worker, the
# default command) and runs full crawls (app:crawl). Build from the repository
# root:
#
#   docker build -f docker/openshift/worker/Dockerfile .
#
# The base image is built in City-of-Helsinki/drupal-docker-images
# (helfi-search/). DOCKER_BUILD_TAG selects its tag: the release pipeline sets
# it to "dev" in the testing and staging environments. Empty or unset means
# "latest".

ARG DOCKER_BUILD_TAG=latest

FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --no-scripts --ignore-platform-reqs --no-autoloader
COPY src src
RUN composer dump-autoload --no-dev --classmap-authoritative


FROM ghcr.io/city-of-helsinki/helfi-search-elasticsearch-crawler:${DOCKER_BUILD_TAG:-latest}

ENV APP_ENV=prod

USER root
COPY --from=vendor /app /app
COPY bin /app/bin
COPY config /app/config
# Symfony's cache and the queue folder, as in the webhook image.
RUN php /app/bin/console cache:warmup \
    && mkdir -p /app/var/queue \
    && chgrp -R 0 /app/var \
    && chmod -R g=u /app/var/cache \
    && chmod 2775 /app/var/queue
USER java

CMD ["php", "/app/bin/console", "app:worker"]
