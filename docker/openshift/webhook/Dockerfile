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

USER root
COPY --from=vendor /app /app
COPY public /app/public
COPY config /app/config
# var/ holds the queue folder; OpenShift mounts the shared volume here. For
# when no volume is mounted: writable by group 0, new entries inherit the
# group.
RUN mkdir -p /app/var && chgrp 0 /app/var && chmod 2775 /app/var
USER 1001
