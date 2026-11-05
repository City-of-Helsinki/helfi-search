# Hel.fi search

Indexes the content of hel.fi sites into Elasticsearch. Pages are indexed by
crawling the sites with [Elastic Open Crawler](https://github.com/elastic/crawler),
and sites send a webhook when content changes, so changed pages are reindexed
within about 30 minutes.

```
hel.fi site ──POST /webhook──▶ webhook ──▶ queue folder ──▶ worker ──▶ Elasticsearch
                                                              ▲
                               full crawl (bin/crawl) ────────┘
```

- **webhook** ([FrankenPHP](https://frankenphp.dev)): accepts change
  notifications for the sites in `config/crawler.yml` and writes them into a
  shared queue folder.
- **worker**: every 30 minutes, recrawls the changed pages and deletes the
  removed ones.
- **full crawl**: crawls every site and removes pages that no longer exist.

The webhook and the worker share only the queue folder (`var/queue`).

## Local development

Requires [Stonehenge](https://github.com/druidfi/stonehenge).

```bash
printf 'WEBHOOK_TOKEN=%s\n' "$(openssl rand -hex 24)" > .env
docker compose build --pull
docker compose run --rm --no-deps --entrypoint composer webhook install
docker compose up -d
```

This starts Elasticsearch, the webhook and the worker, and runs a full crawl
limited to about 50 pages per site.

- Webhook: https://webhook-helfi-search.docker.so
- Elasticsearch (no public URL):
  `docker compose exec crawler-elastic curl -s "localhost:9200/hel-fi/_search?pretty"`

The code is mounted into the containers, so changes apply without rebuilding
(restart the looping worker with `docker compose restart worker`). The
services run as UID 1000; if yours differs, set `user: "<UID>:0"` in
`compose.yaml`.

Common commands:

```bash
# Full crawl, about 50 pages per site; 0 crawls everything
docker compose run --rm crawler
CRAWL_LIMIT_PER_DOMAIN=0 docker compose run --rm crawler

# Process the queue now (stop the looping worker first: it holds the lock)
docker compose stop worker
docker compose run --rm worker php /app/bin/worker
docker compose start worker

# Composer
docker compose exec webhook composer require vendor/package
```

## Tests

```bash
# Unit tests
docker compose exec webhook vendor/bin/phpunit
# With coverage (PCOV, off by default)
docker compose exec webhook php -d pcov.enabled=1 vendor/bin/phpunit --coverage-text
# Integration tests: the real crawler and Elasticsearch, against a fake site
docker compose run --rm worker vendor/bin/phpunit --testsuite integration

# Coding standard (PSR-12) and static analysis (PHPStan, level max)
docker compose exec webhook vendor/bin/phpcs
docker compose exec webhook vendor/bin/phpcbf   # fixes what it can
docker compose exec webhook vendor/bin/phpstan analyse
```

GitHub Actions ([test.yml](.github/workflows/test.yml)) runs the same setup
with Stonehenge: `composer audit`, PHPCS, PHPStan, the unit tests with
coverage, the integration tests, and a smoke test through the webhook and the
worker.

## Configuration

[`config/crawler.yml`](config/crawler.yml) holds the crawled sites, what's
extracted from each page, the index (`hel-fi`) and the local Elasticsearch
connection. [`config/pipeline.json`](config/pipeline.json) is the `hel-fi`
ingest pipeline, which removes fields that shouldn't be indexed.

| Variable | Used by | Description |
|---|---|---|
| `WEBHOOK_TOKEN` | webhook | Shared secret for the API. Required. |
| `ELASTICSEARCH_HOST`, `ELASTICSEARCH_PORT` | worker, crawl | Override the connection in `crawler.yml`. |
| `ELASTICSEARCH_API_KEY` or `ELASTICSEARCH_USERNAME` and `ELASTICSEARCH_PASSWORD` | worker, crawl | Credentials. Never put them in `crawler.yml`. |
| `CRAWL_LIMIT_PER_DOMAIN` | local crawl | Pages per site (default 50, `0` for all). |

To add a site, add it to `domains` in `config/crawler.yml` (copy the settings
of an existing one), deploy, and run a full crawl.

## Webhook API

All requests except `/health` need `Authorization: Bearer <WEBHOOK_TOKEN>`.

| Request | Response |
|---|---|
| `POST /webhook` with `{"url": "https://…", "action": "update"}` | `202` queued. `action` is `update` (default) or `delete`. `400` invalid request, `401` wrong token, `422` site not in `crawler.yml`. |
| `GET /status` | `{"pending": 3}`: requests waiting for the worker. |
| `GET /health` | `200` when ready, `503` when the queue or `crawler.yml` isn't usable. |

What sites should send:

| Content event | Request |
|---|---|
| Published or edited | `update` for the page's public URL |
| Unpublished or deleted | `delete` for the page's public URL |
| URL changed | `delete` for the old URL, `update` for the new one |
| Translations | One request per language version |

Send the request without blocking the editor (after the response or with a
short timeout). Retrying is safe: the latest request for a URL wins. A `202`
means queued; the change is applied by the next worker run.

## Deployment (OpenShift)

The images build on base images from
[City-of-Helsinki/drupal-docker-images](https://github.com/City-of-Helsinki/drupal-docker-images)
(`helfi-search/`): `helfi-search-webhook` and
`helfi-search-elasticsearch-crawler`. `DOCKER_BUILD_TAG` selects their tag:
`latest` by default, `dev` in the testing and staging environments.

```bash
docker build -f docker/openshift/webhook/Dockerfile .
docker build -f docker/openshift/worker/Dockerfile .
```

| Resource | Notes |
|---|---|
| Webhook Deployment | Port 8080, probes on `GET /health`, a Route in front. `WEBHOOK_TOKEN` from a Secret. |
| Worker CronJob | `*/30 * * * *`, `concurrencyPolicy: Forbid`. `ELASTICSEARCH_*` variables, credentials from a Secret. |
| Full crawl CronJob | The worker image with `php /app/bin/crawl`, e.g. nightly. Same variables. |
| Queue volume | `ReadWriteMany` (e.g. `azure-file`), mounted at `/app/var` in the webhook and the worker. Writable by group 0. |
| Elasticsearch | External. Create the `hel-fi` pipeline from `config/pipeline.json` once. |

Both images run as any non-root user, as OpenShift requires.

## Limitations

- Changes are applied every 30 minutes, not immediately.
- Only the notified page is reindexed; listings and other pages showing the
  same content wait for the next full crawl. Run one regularly.
- `update` for a page that no longer exists keeps its document: send `delete`.
- Pages must be public; the crawler fetches them anonymously.
- `body` excludes `header`, `nav`, `aside` and `footer`, but can't be limited
  to `<main>`, so other page chrome stays in it.
- One token for all sites, and no rate limiting.
