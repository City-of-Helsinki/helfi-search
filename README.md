# Hel.fi search

Indexes the content of hel.fi sites into Elasticsearch for semantic search.
The sites are crawled with [Elastic Open Crawler](https://github.com/elastic/crawler),
each page is split into chunks and embedded with OpenAI, and sites send a
webhook when content changes, so changed pages are reindexed within about 30
minutes.

```
hel.fi site ──POST /webhook──▶ webhook ──▶ queue folder ──▶ worker ──▶ Elasticsearch
                                                              ▲
                               full crawl (app:crawl) ────────┘
```

- **webhook** ([FrankenPHP](https://frankenphp.dev)): accepts change
  notifications for the sites in `config/crawler.yml` and writes them into a
  shared queue folder.
- **worker**: every 30 minutes, recrawls the changed pages and deletes the
  removed ones.
- **full crawl**: crawls every site and removes pages that no longer exist.

Both crawl the same way and process the pages with the same code
([`src/Indexer/`](src/Indexer/)):

```
crawler ──▶ hel-fi-pages ──▶ clean → Markdown → chunks ──▶ hel-fi
            (page HTML)                  ▲         (one document per page,
                                         │          with chunk vectors)
                       hel-fi-embedding-cache
                       (only new chunks are embedded)
```

| Index | Contents |
|---|---|
| `hel-fi-pages` | The crawler's output: each page's HTML. Read only by the worker. |
| `hel-fi` | The search index (an alias of `hel-fi-v1`): URL, title (`label`), language, content type (`entity_bundle`, from `<meta name="helfi_content_type">`), Markdown, and the chunks' snippets, section anchors and vectors (`embeddings_text_embedding_3_large`). The field names are those of the `helfi_search` Drupal module. |
| `hel-fi-embedding-cache` | A vector per chunk, by content hash. Unchanged pages aren't processed again, and a changed page re-embeds only its changed chunks. The full crawl prunes unused vectors. |

The text processing (`src/Pipeline/`) is ported from `helfi_search`.

The webhook and the worker share only the queue folder (`var/queue`). Both are
one [Symfony](https://symfony.com) application: the webhook is
`public/index.php`, the worker and the full crawl are the `app:worker` and
`app:crawl` commands (`bin/console`). Services are configured in
[`config/services.yaml`](config/services.yaml).

## Local development

Requires [Stonehenge](https://github.com/druidfi/stonehenge).

```bash
make setup
```

This creates `.env` (the webhook token, and your UID: the services run as
your user, so the files they create belong to you), builds the images,
installs the dependencies, and starts Elasticsearch, the webhook and the
worker, with a full crawl limited to about 50 pages per site. The services run
in Symfony's `dev` environment.

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
make console ARGS="debug:router"
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

[`config/crawler.yml`](config/crawler.yml) holds the crawled sites, the
crawler's index (`hel-fi-pages`) and the local Elasticsearch connection.
[`config/services.yaml`](config/services.yaml) holds the other index names and
the CSS classes of elements that aren't content.

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
| Worker CronJob | `*/30 * * * *`, `concurrencyPolicy: Forbid`. `ELASTICSEARCH_*` and `OPENAI_*` variables, credentials and the API key from a Secret. |
| Full crawl CronJob | The worker image with `php /app/bin/console app:crawl`, e.g. nightly. Same variables. |
| Queue volume | `ReadWriteMany` (e.g. `azure-file`), mounted at `/app/var/queue` in the webhook and the worker. Writable by group 0. Not at `/app/var`: Symfony's cache (`/app/var/cache`) is built into the images. |
| Setup | `php /app/bin/console app:setup` in the worker image, on every deployment (e.g. a Job or an init container): creates missing indexes, leaves existing ones alone. |
| Elasticsearch | External. Vectors take space: about 12 kB per chunk in the search index and 16 kB in the cache. |

Both images run as any non-root user, as OpenShift requires.

## Limitations

- Changes are applied every 30 minutes, not immediately.
- Only the notified page is reindexed; listings and other pages showing the
  same content wait for the next full crawl. Run one regularly.
- `update` for a page that no longer exists keeps its document: send `delete`.
  The next full crawl removes it otherwise.
- Pages must be public; the crawler fetches them anonymously.
- Only HTML pages are indexed, not attachments (PDFs etc.).
- Sites without `<meta name="helfi_content_type">` get no `entity_bundle`.
- The content cleaning is tuned for hel.fi platform pages
  (`<main class="layout-main-wrapper">`, its CSS classes); on other layouts,
  menus and other page chrome can end up in the content.
- Changing a page's intro re-embeds all its chunks: every chunk includes its
  parent section's snippet as context.
- One token for all sites, and no rate limiting.
