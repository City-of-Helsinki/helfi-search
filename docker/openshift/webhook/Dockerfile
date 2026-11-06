# The webhook's OpenShift image: receives change notifications and writes them
# into the queue folder. Build from the repository root:
#
#   docker build -f docker/openshift/webhook/Dockerfile .
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


FROM ghcr.io/city-of-helsinki/helfi-search-webhook:${DOCKER_BUILD_TAG:-latest}

ENV APP_ENV=prod

USER root
COPY --from=vendor /app /app
COPY public /app/public
COPY bin /app/bin
COPY config /app/config
# Symfony's cache (var/cache) is built here, so it ships with the image;
# group 0 may update it. var/queue is the queue folder, where OpenShift mounts
# the shared volume. For when no volume is mounted: writable by group 0, new
# entries inherit the group.
RUN php /app/bin/console cache:warmup \
    && mkdir -p /app/var/queue \
    && chgrp -R 0 /app/var \
    && chmod -R g=u /app/var/cache \
    && chmod 2775 /app/var/queue
USER 1001
