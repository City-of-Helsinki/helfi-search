# The worker's OpenShift image: applies the queued changes (bin/worker, the
# default command) and runs full crawls (bin/crawl). Build from the repository
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

USER root
COPY --from=vendor /app /app
COPY bin /app/bin
COPY config /app/config
# var/ holds the queue folder, as in the webhook image.
RUN mkdir -p /app/var && chgrp 0 /app/var && chmod 2775 /app/var
USER java

CMD ["php", "/app/bin/worker"]
