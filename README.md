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
make setup
```

This creates `.env` (the webhook token, and your UID: the services run as
your user, so the files they create belong to you), builds the images,
installs the dependencies, and starts Elasticsearch, the webhook and the worker, with a
full crawl limited to about 50 pages per site.

- Webhook: https://webhook-helfi-search.docker.so
- Elasticsearch has no public URL: `make search` shows the indexed documents.

The code is mounted into the containers, so changes apply without rebuilding
(`make restart-worker` for the looping worker).

`make help` lists all commands. The common ones:

```bash
make crawl              # Full crawl, about 50 pages per site (LIMIT=10 to change)
make crawl-all          # Full crawl of every page
make process-queue      # Apply the queued changes now
make composer ARGS="require vendor/package"
```

## Tests

```bash
make test               # Unit tests
make coverage           # Unit tests with coverage (PCOV)
make test-integration   # The real crawler and Elasticsearch, against a fake site
make phpcs              # Coding standard (PSR-12); make phpcbf fixes what it can
make phpstan            # Static analysis (PHPStan, level max)
make check              # All of the above, except coverage
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
make build-openshift                      # DOCKER_BUILD_TAG=dev for the dev base images
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
