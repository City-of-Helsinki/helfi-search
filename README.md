# Hel.fi search

Indexes the content of hel.fi sites into Elasticsearch for semantic search.
The sites are crawled with [Elastic Open Crawler](https://github.com/elastic/crawler),
each page is split into chunks and embedded with OpenAI, and sites send a
webhook when content changes, so changed pages are reindexed within about a
minute.

![A hel.fi site sends POST /webhook to the webhook, which adds the change to the Redis queue; workers apply the queued changes to Elasticsearch, and the full crawl indexes into Elasticsearch too.](docs/architecture.svg)

- **webhook** ([FrankenPHP](https://frankenphp.dev)): accepts change
  notifications for the sites in `config/crawler.yml` and adds them to the
  queue in Redis.
- **worker**: runs all the time. Once a URL has had no new changes for 30
  seconds, recrawls the changed pages and deletes the removed ones.
- **full crawl**: crawls every site and removes pages that no longer exist.

Every part can run as several replicas at once: see
[Running several replicas](#running-several-replicas).

Both crawl the same way and process the pages with the same code
([`src/Indexer/`](src/Indexer/)):

![The crawler writes page HTML into hel-fi-pages-<crawl>; the pages are cleaned, converted to Markdown and split into chunks, and stored in hel-fi, one document per page with chunk vectors. Vectors come from hel-fi-embedding-cache, so only new chunks are embedded.](docs/indexing.svg)

| Index | Contents |
|---|---|
| `hel-fi-pages-<crawl>` | One per crawl, deleted when the crawl is processed: the crawler's output, each page's HTML. |
| `hel-fi` | The search index (an alias of `hel-fi-v1`): URL, title (`label`), language, content type (`entity_bundle`, from `<meta name="helfi_content_type">`), Markdown, and the chunks' snippets, section anchors and vectors (`embeddings_text_embedding_3_large`). The field names are those of the `helfi_search` Drupal module. |
| `hel-fi-embedding-cache` | A vector per chunk, by content hash. Unchanged pages aren't processed again, and a changed page re-embeds only its changed chunks. The full crawl prunes unused vectors once a day. |

The text processing (`src/Pipeline/`) is ported from `helfi_search`.

The webhook and the worker share only the queue in Redis
([`src/Queue/RedisQueue.php`](src/Queue/RedisQueue.php)). Both are one
[Symfony](https://symfony.com) application: the webhook is `public/index.php`,
the worker and the full crawl are the `app:worker` and `app:crawl` commands
(`bin/console`). Services are configured in
[`config/services.yaml`](config/services.yaml).

## Local development

Requires [Stonehenge](https://github.com/druidfi/stonehenge).

```bash
make setup
```

This creates `.env` (the webhook token, and your UID: the services run as
your user, so the files they create belong to you), builds the images,
installs the dependencies, and starts Elasticsearch, Redis, the webhook and two
workers, with a full crawl limited to about 50 pages per site. The services run
in Symfony's `dev` environment.

- Webhook: https://webhook-helfi-search.docker.so
- Elasticsearch has no public URL: `make search` shows the indexed documents.

The code is mounted into the containers, so changes apply without rebuilding
(`make restart-worker` for the workers, which keep running).

`make help` lists all commands. The common ones:

```bash
make crawl              # Full crawl, about 50 pages per site (LIMIT=10 to change)
make crawl-all          # Full crawl of every page
make process-queue      # Apply the queued changes now, without waiting 30 seconds
make queue              # The queue: waiting, being processed, failed
make composer ARGS="require vendor/package"
make console ARGS="debug:router"
```

## Tests

```bash
make test               # Unit tests (they use the compose project's Redis)
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

[`config/crawler.yml`](config/crawler.yml) holds the crawled sites, the crawl
settings and the local Elasticsearch connection.
[`config/services.yaml`](config/services.yaml) holds the index names and the
CSS classes of elements that aren't content.

`bin/console app:setup` creates the indexes that are missing (`make up` runs it
locally).

| Variable | Used by | Description |
|---|---|---|
| `WEBHOOK_TOKEN` | webhook | Shared secret for the API. Required. |
| `APP_ENV` | all | Symfony environment: `prod` in the images, `dev` locally. |
| `ELASTICSEARCH_HOST`, `ELASTICSEARCH_PORT` | worker, crawl | Override the connection in `crawler.yml`. |
| `ELASTICSEARCH_API_KEY` or `ELASTICSEARCH_USERNAME` and `ELASTICSEARCH_PASSWORD` | worker, crawl | Credentials. Never put them in `crawler.yml`. |
| `OPENAI_API_KEY` | worker, crawl | The embeddings API key. Without it, local development uses made-up vectors (fine for testing indexing, not search quality); production fails until it's set. |
| `OPENAI_BASE_URL` | worker, crawl | Default `https://api.openai.com/v1`. For Azure OpenAI: `https://<resource>.openai.azure.com/openai/deployments/{model}`. |
| `REDIS_URL` | all | One Redis server, e.g. `redis://redis:6379` (the default). Used when `REDIS_SENTINELS` is empty. |
| `REDIS_SENTINELS` | all | Sentinel setup: comma-separated sentinels, e.g. `tcp://sentinel-0:26379,tcp://sentinel-1:26379`. Every command goes to the master, and is retried for about 10 seconds during a failover. |
| `REDIS_SENTINEL_SERVICE` | all | The master's name in Sentinel. Required with `REDIS_SENTINELS`. |
| `REDIS_PASSWORD`, `REDIS_SENTINEL_PASSWORD` | all | The Redis password, and the sentinels' own if they have one. |
| `REDIS_DB` | all | The database number (default `0`). |
| `REDIS_PREFIX` | all | Prepended to every key (default `helfi-search:`), so a shared Redis is safe. |
| `CRAWL_LIMIT_PER_DOMAIN` | local crawl | Pages per site (default 50, `0` for all). |

To add a site, add it to `domains` in `config/crawler.yml` (copy the settings
of an existing one), deploy, and run a full crawl.

## Webhook API

All requests except `/health` need `Authorization: Bearer <WEBHOOK_TOKEN>`.

| Request | Response |
|---|---|
| `POST /webhook` with `{"url": "https://…", "action": "update"}` | `202` queued. `action` is `update` (default) or `delete`. `400` invalid request, `401` wrong token, `422` site not in `crawler.yml`. |
| `GET /status` | `{"pending": 3, "processing": 1, "batches": 1, "failed": 0, "workers": ["worker-1"]}`: changes waiting, URLs in batches being processed, failed changes (see `bin/console app:queue`), and the workers seen in the last 15 minutes. |
| `GET /health` | `200` when ready, `503` when Redis or `crawler.yml` isn't usable. A `POST /webhook` gets `503` too when Redis is down: retry later. |

What sites should send:

| Content event | Request |
|---|---|
| Published or edited | `update` for the page's public URL |
| Unpublished or deleted | `delete` for the page's public URL |
| URL changed | `delete` for the old URL, `update` for the new one |
| Translations | One request per language version |

Send the request without blocking the editor (after the response or with a
short timeout). Retrying is safe: only the latest request for a URL is kept. A
`202` means queued; a worker applies the change once the URL has had no new
requests for 30 seconds.

A change that fails is retried after 1, 2, 4 and 8 minutes, then marked as
failed. `bin/console app:queue` lists the failed changes with their errors, and
`bin/console app:queue --retry-failed` queues them again.

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
| Webhook Deployment | 2 or more replicas. Port 8080, probes on `GET /health`, a Route in front. `WEBHOOK_TOKEN` from a Secret, and the `REDIS_*` variables. |
| Worker Deployment | 2 replicas, sized for one crawl each. The image's default command, `app:worker`, keeps running. `terminationGracePeriodSeconds: 30`: on SIGTERM it stops its crawl and returns the batch to the queue. Liveness probe: `find /tmp/worker-alive -mmin -15 \| grep -q .` (the worker touches the file while it works or waits). `ELASTICSEARCH_*`, `OPENAI_*` and `REDIS_*` variables; credentials and the API key from a Secret. |
| Full crawl CronJob | Nightly, the worker image, as an indexed Job: `completionMode: Indexed`, `completions` the number of sites in `crawler.yml`, `parallelism` e.g. 3, and the command `php /app/bin/console app:crawl --site=$(JOB_COMPLETION_INDEX)`. Each pod crawls one site and removes that site's pages that are gone. Same variables as the worker. |
| Redis | The platform's Sentinel setup (`REDIS_SENTINELS`), or one server (`REDIS_URL`). The queue is small: one entry per changed URL. If it's lost, the next full crawl catches up. |
| Setup | `php /app/bin/console app:setup` in the worker image, on every deployment (e.g. a Job or an init container): creates missing indexes, leaves existing ones alone. |
| Elasticsearch | External. Vectors take space: about 12 kB per chunk in the search index and 16 kB in the cache. |

Both images run as any non-root user, as OpenShift requires. No volumes are
needed.

### Running several replicas

- **Webhook**: stateless; every request writes to Redis.
- **Workers**: each claims a batch of up to 200 URLs at a time
  (`--batch-size`). A URL is in at most one batch at a time, so changes to one
  URL are always applied in order. A worker renews its batch's lease while it
  works; if it dies, another worker takes the batch over when the lease
  expires (10 minutes).
- **Crawls**: each crawl writes into an index of its own, so a worker's crawl
  and full crawls of different sites never process each other's pages. A full
  crawl removes only its own sites' pages that are gone. A page deleted
  through the webhook after a crawl fetched it isn't added back: the worker
  records deletions in Redis for two days, and the indexer skips pages crawled
  before their deletion.
- **Clean-up**: after a complete crawl, the first crawl to finish in about a
  day removes unused vectors, old deletion records and indexes left by crawls
  that crashed.
