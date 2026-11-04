# Hel.fi search

The Search project indexes the content of hel.fi sites into Elasticsearch, so
they can be searched. Pages are indexed by crawling the sites, and sites can
send a webhook when content changes, so changed pages are reindexed within
about 30 minutes, without waiting for the next full crawl.

Repository: [city-of-helsinki/helfi-search](https://github.com/city-of-helsinki/helfi-search)

## Tech stack

- [Elastic Open Crawler](https://github.com/elastic/crawler) (latest release)
  fetches the pages and indexes them into Elasticsearch.
- Elasticsearch 9 stores the index (`hel-fi`).
- PHP 8.4 services (Symfony components, Guzzle) receive change notifications
  and run targeted crawls. The webhook runs on
  [FrankenPHP](https://frankenphp.dev) (PHP inside the Caddy web server).
- Docker Compose with [Stonehenge](https://github.com/druidfi/stonehenge)
  locally; OpenShift in production.

## How it works

```
                        full crawl (on demand / nightly)
crawler.yml ─────────────────────────────────────────────────┐
                                                             ▼
hel.fi site ──POST /webhook──▶ webhook ──▶ queue folder ──▶ worker ──▶ Elasticsearch
                              (PHP)       (shared volume)   (PHP + crawler, every 30 min)
```

| Part | What it does | Image |
|---|---|---|
| `crawler` | Full crawl of every site in `config/crawler.yml`. Removes pages that no longer exist. Locally limited to about 50 pages per site. | `worker` (`bin/crawl`) |
| `webhook` | Accepts change notifications for the sites in `crawler.yml` and writes them into the queue folder. Never talks to the crawler or Elasticsearch. | `webhook` (PHP 8.4) |
| `worker` | Applies the queued changes: recrawls updated pages, deletes removed ones. | `worker` (crawler image + PHP 8.4) |
| `crawler-elastic`, `pipeline` | Local Elasticsearch and the `hel-fi` ingest pipeline. Local development only. | Elasticsearch 9.5.3 |

The webhook and the worker are decoupled: they run as separate services, and
the queue folder is the only thing they share. See
[Queue folder format](#queue-folder-format).

## Code layout

One PHP codebase builds both images, on base images from
[City-of-Helsinki/drupal-docker-images](https://github.com/City-of-Helsinki/drupal-docker-images)
(see [Images](#images)).

```
src/
  Queue/      The queue folder format, shared by both: Request, QueueFolder,
              QueueWriter (webhook side), Spool (worker side)
  Webhook/    HTTP handling. Uses only Queue.
  Worker/     Batches, the crawler and Elasticsearch
  CrawlerConfig.php  Reads crawler.yml (shared: the webhook uses the site list)
  Log.php, Site.php
public/index.php    Webhook front controller (webhook image)
docker/openshift/webhook/Dockerfile  Production webhook image
docker/openshift/worker/Dockerfile   Production worker image
bin/worker          Applies the queue (worker image)
bin/crawl           Full crawl (worker image)
```

Code under `Webhook/` must not use anything from `Worker/`: the webhook image
doesn't contain the crawler, and the two services must stay decoupled.

## Quick start

The local setup runs behind [Stonehenge](https://github.com/druidfi/stonehenge)
(Traefik), like the other hel.fi projects. Start Stonehenge first; it creates
the `stonehenge-network` this project joins.

`.env` holds the webhook token (`WEBHOOK_TOKEN=...`). Create it if it's
missing:

```bash
printf 'WEBHOOK_TOKEN=%s\n' "$(openssl rand -hex 24)" > .env
```

Build the local images and install the PHP dependencies into the project
folder (the containers run the code from there, see
[Development](#development)):

```bash
docker compose build --pull
docker compose run --rm --no-deps --entrypoint composer webhook install
```

Start everything:

```bash
docker compose up -d
```

This starts Elasticsearch, creates the ingest pipeline, runs one full crawl
limited to about 50 pages per site (the `crawler` service exits when it's
done; see [Full crawl](#full-crawl)), and starts the webhook and the worker.

The webhook is at https://webhook-helfi-search.docker.so (`/webhook`,
`/status`):

```bash
curl -H "Authorization: Bearer $(grep -oP '(?<=WEBHOOK_TOKEN=).*' .env)" https://webhook-helfi-search.docker.so/status
```

Only the webhook joins `stonehenge-network`, which other projects share.
Elasticsearch has no public URL: only the crawler and the worker use it, over
the project's own network. To query it, run `curl` inside its container:

```bash
docker compose exec crawler-elastic curl -s "localhost:9200/hel-fi/_search?pretty"
```

The index is kept in the `elastic_data` volume (`docker compose down -v`
removes it), and the queue in `var/queue` in the project folder (ignored by
git).

## Development

Locally, the webhook, worker and crawler run the code from the project folder,
mounted over `/app` (`.:/app:delegated`), so changes apply without
rebuilding:

- **Webhook:** on the next request.
- **Worker and full crawl:** on the next run; each run starts a new PHP
  process. Restart the looping worker to pick up changes in it:
  `docker compose restart worker`.
- **Images:** compose builds them with `docker/local/Dockerfile` on the `dev`
  base images. `docker compose build --pull` and `docker compose up -d` pick
  up new base image versions.

Both local images include Composer:

```bash
docker compose exec webhook composer install
docker compose exec webhook composer require vendor/package
```

`vendor/` is created in the project folder and ignored by git. If the webhook
can't load its dependencies, it answers `500` and isn't healthy, so Stonehenge
shows "Service not found!".

The services run as UID 1000 (a typical first user on Linux) in group 0, so
the files they create in the project folder belong to you. If your UID isn't
1000, add `user: "<your UID>:0"` to the services in `compose.yaml`.

## Images

| Image | Built with | Base image |
|---|---|---|
| Webhook, OpenShift | `docker/openshift/webhook/Dockerfile` | `ghcr.io/city-of-helsinki/helfi-search-webhook:latest` |
| Worker, OpenShift | `docker/openshift/worker/Dockerfile` | `ghcr.io/city-of-helsinki/helfi-search-elasticsearch-crawler:latest` |
| Webhook, local | `docker/local/Dockerfile`, `--target webhook` (compose) | `ghcr.io/city-of-helsinki/helfi-search-webhook:dev` |
| Worker and crawler, local | `docker/local/Dockerfile`, `--target elasticsearch-crawler` (compose) | `ghcr.io/city-of-helsinki/helfi-search-elasticsearch-crawler:dev` |

The base images are built and tested in
[City-of-Helsinki/drupal-docker-images](https://github.com/City-of-Helsinki/drupal-docker-images)
(`helfi-search/`), with two tags each: `latest` for OpenShift and `dev` for
local development and for testing changes before they're released. They
contain no project code:

- **`helfi-search-webhook`:** FrankenPHP on port 8080, routing every request
  to `public/index.php`; PHP's production settings; non-root with no
  capabilities; umask `0002`; a health check on `/health`.
- **`helfi-search-elasticsearch-crawler`:** Elastic Open Crawler with PHP 8.4,
  `tini`, and the crawler's output folder writable for random UIDs.

The production Dockerfiles add this project's code, `vendor/` (built without
development dependencies), the queue folder and the worker's default command.
There's one Dockerfile per image, because an OpenShift build config builds a
Dockerfile without choosing a target. Build them from the repository root:

```bash
docker build -f docker/openshift/webhook/Dockerfile .
docker build -f docker/openshift/worker/Dockerfile .
```

The build argument `DOCKER_BUILD_TAG` selects the base image's tag. It
defaults to `latest`, also when it's empty; the release pipeline sets it to
`dev` in the testing and staging environments, so changes to the base images
are tested there before they're released as `latest`:

```bash
docker build -f docker/openshift/webhook/Dockerfile --build-arg DOCKER_BUILD_TAG=dev .
```

`docker/local/Dockerfile` adds Composer, makes PHP check for changed files on
every request, and runs as UID 1000; the code is mounted, not copied.

Changes to the base images (a new crawler or PHP version, the FrankenPHP
configuration) are made in drupal-docker-images and published to the `dev`
tags first. To try them before publishing, run `make load` in its
`helfi-search/` folder: it builds the `dev` images into the local Docker.
Then build the local images with Docker's own builder, which can see them:

```bash
BUILDX_BUILDER=default docker compose build
```

(A separate buildx builder, if you've selected one, can only pull published
images.)

## Full crawl

```bash
docker compose run --rm crawler
```

Crawls every site in `config/crawler.yml` with `bin/crawl`.

**Locally, the crawl is limited** to about 50 URLs per site, so it finishes in
under a minute (about 30 seconds for the two current sites). Change the limit
with `CRAWL_LIMIT_PER_DOMAIN`, or set it to `0` for a complete crawl:

```bash
CRAWL_LIMIT_PER_DOMAIN=10 docker compose run --rm crawler   # about 10 per site
CRAWL_LIMIT_PER_DOMAIN=0 docker compose run --rm crawler    # everything
```

`bin/crawl` options:

- **No options:** one crawl with `crawler.yml` as is. Afterwards, documents for
  pages that weren't found are removed (the crawler's purge step), so a
  complete crawl also cleans up anything the webhooks missed.
- **`--limit-per-domain=N`:** one crawl per site, each stopping after about `N`
  URLs. The crawler only has a limit for the whole crawl, so this is how each
  site gets its share.

Limits of the limited crawl:

- **At most `N` documents per site, usually fewer.** Redirects, `robots.txt`
  and non-HTML files count toward the limit, and pages that declare another
  page as their canonical URL share one document.
- **No purge.** A purge deletes every document the crawl didn't visit, which
  would include the other sites and every page past the limit. Documents from
  earlier crawls stay; delete the index to start clean:
  `docker compose exec crawler-elastic curl -s -XDELETE localhost:9200/hel-fi`.
- **Which pages you get isn't fixed.** The crawler starts from the front page
  and follows links with several threads, so the set can differ between runs.

## What gets indexed

Each page becomes one document in the `hel-fi` index. The document ID is a
SHA-1 hash of the page's normalized URL, so recrawling a page updates its
document.

| Field | Source |
|---|---|
| `title` | `<title>` |
| `body` | Page text, without `header`, `nav`, `aside` and `footer` elements (`exclude_tags` in `crawler.yml`) |
| `meta_description`, `meta_keywords` | `<meta name="description">`, `<meta name="keywords">` |
| `og_image` | `<meta property="og:image">` (an extraction rule in `crawler.yml`) |
| `url`, `url_host`, `url_path`, `url_path_dir1`-`3`, `url_port`, `url_scheme` | The page URL. If the page declares a different canonical URL, that's used and the crawled URL goes in `additional_urls`. |
| `last_crawled_at` | Crawl time |

The crawler also produces `links` and `headings` from the whole page, and adds
its pipeline parameters (`_reduce_whitespace`, `_run_ml_inference`,
`_extract_binary_content`) to every document. The `hel-fi` ingest pipeline
(`config/pipeline.json`) removes all of them before indexing.

The pipeline doesn't use Elastic's `ent-search-generic-ingestion` pipeline:
Elasticsearch 9 no longer installs it, and documents sent to a missing pipeline
are rejected.

If Elasticsearch rejects documents, the crawler saves them under
`/home/app/output/failed_payloads` in its container, and the worker and
`bin/crawl` treat the run as failed (the crawler itself still reports
success).

## Webhook API

All requests need the token:

```
Authorization: Bearer <WEBHOOK_TOKEN>
```

### `POST /webhook`

Queues a change for one page.

```json
{"url": "https://avustukset.hel.fi/fi/ohjeita-hakijalle", "action": "update"}
```

| Field | Required | Values |
|---|---|---|
| `url` | yes | Absolute `http(s)` URL of the page, on a site listed in `config/crawler.yml`. A `#fragment` is removed. |
| `action` | no | `update` (default): recrawl the page. `delete`: remove the page from the index. |

| Status | Meaning |
|---|---|
| `202 Accepted` | Queued. Body: `{"queued": {"url": "...", "action": "..."}}` |
| `400 Bad Request` | The body isn't JSON, or `url` or `action` is invalid. |
| `401 Unauthorized` | Missing or wrong token. |
| `405 Method Not Allowed` | Not a `POST`. |
| `422 Unprocessable Entity` | The URL's site isn't in `config/crawler.yml`. |
| `500 Internal Server Error` | The queue folder isn't writable. Safe to retry. |
| `503 Service Unavailable` | `WEBHOOK_TOKEN` isn't set on the server. |

Errors have the body `{"error": "<message>"}`.

### `GET /status`

Returns the number of queued requests waiting for the worker:

```json
{"pending": 3}
```

The count is of requests, not pages: a page sent twice counts twice until the
worker picks them up.

### `GET /health`

Health check for Docker and OpenShift probes. Doesn't need the token.

| Status | Body |
|---|---|
| `200 OK` | `{"status": "ok"}` |
| `503 Service Unavailable` | `{"status": "queue folder not writable"}` |
| `503 Service Unavailable` | `{"error": "The crawler configuration is not readable."}` (every endpoint answers this while `crawler.yml` is missing or invalid) |

The image's Docker health check uses it. Traefik (Stonehenge) only routes to
the webhook once it's healthy, so the URL answers "Service not found!" for a
few seconds after the container starts.

## Sending notifications (client)

A site sends one request per changed page. There's no client library; any
HTTP client works.

### curl

```bash
curl -X POST "https://<webhook host>/webhook" \
  -H "Authorization: Bearer $WEBHOOK_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"url": "https://avustukset.hel.fi/fi/ohjeita-hakijalle", "action": "update"}'
```

### When to send what

| Content event | Send |
|---|---|
| Published, or a published page is edited | `update` for the page's public URL |
| Unpublished or deleted | `delete` for the page's public URL |
| URL alias changed | `delete` for the old URL and `update` for the new one |
| Translation added, changed or removed | One request per language version, each with its own URL |

### Client guidelines

- **Send the public URL** a visitor would open, e.g.
  `https://avustukset.hel.fi/fi/ohjeita-hakijalle`, not an internal path like
  `/node/20` or an admin URL.
- **Don't block saving content.** Send the request after the response, from a
  queue, or with a short timeout, and log failures instead of showing them to
  the editor.
- **Retrying is safe.** Sending the same change twice has no extra effect; the
  latest request for a URL wins.

### Client limitations

- **Not immediate.** Changes are applied in batches, every 30 minutes by
  default. A `202` means queued, not indexed.
- **No feedback.** The client isn't told whether the page was crawled or
  deleted successfully. Check the worker log if something doesn't show up.
- **One URL per request.** There's no bulk endpoint; send several requests.
- **Only listed sites.** URLs on sites missing from `config/crawler.yml` get `422`.
  Adding a site needs a change on the crawler side (see
  [Adding a site](#adding-a-site)).
- **Only the given page is updated.** Pages that show the changed content
  (front page lifts, listings, other languages) aren't recrawled unless they
  get their own notification. The next full crawl updates them.
- **`update` doesn't remove pages.** If a page that no longer exists is sent as
  `update`, the crawler gets an error page and the old document stays. Send
  `delete` instead.
- **Canonical URLs.** If a page declares a different canonical URL, the
  document is stored under the canonical URL. `delete` still finds it by the
  URL you sent, as long as that page was crawled from that URL.
- **Pages must be public.** The crawler fetches pages anonymously, so pages
  behind a login can't be indexed.

## Worker

The worker applies the queued changes in batches:

1. Takes every queued request (requests that arrive during a batch wait for
   the next one).
2. Keeps only the latest request per URL.
3. Deletes the `delete` URLs from Elasticsearch.
4. Crawls the `update` URLs with the settings from `crawler.yml`, limited to
   exactly those pages: it doesn't follow links, doesn't read sitemaps and
   doesn't purge other documents.
5. Removes the batch. If anything failed, the whole batch goes back into the
   queue and is retried on the next run.

`crawler.yml` is read again on every run, so changes apply without a restart.

```
worker [--every=SECONDS]
```

- **No arguments:** process the queue once and exit. For schedulers like an
  OpenShift CronJob.
- **`--every=SECONDS`:** keep running and process the queue every `SECONDS`.
  Docker Compose has no scheduler, so `compose.yaml` uses this with
  `--every=1800` (30 minutes).

Only one worker may run at a time. Each run takes a lock on `worker.lock` in
the queue folder; a second run exits right away with code 0. The holder of the
lock first returns any batch left by a run that was killed, then processes the
queue. On filesystems without working locks (see
[OpenShift](#openshift)) the worker logs a warning and continues, so the
scheduler must prevent overlapping runs.

Process the queue now instead of waiting:

```bash
docker compose stop worker
docker compose run --rm --entrypoint php worker /app/bin/worker
docker compose start worker
```

Stop the looping `worker` service first: while it runs, it holds the lock and
the single run exits with "Another worker is running".

Exit codes: `0` success (or another worker is running), `1` the batch failed
and was returned to the queue, `2` invalid arguments.

## Queue folder format

The folder is the interface between the webhook and the worker. Anything that
writes files in this format can queue changes. In the code, the format is in
`src/Queue/` (`QueueFolder` and `Request`).

```
<queue>/
  incoming/     Waiting requests, one file each
  processing/   One subfolder per batch being processed
  failed/       Unreadable request files, kept for inspection
  worker.lock   The worker's lock
```

A request file is `incoming/<microseconds>-<random hex>.json`:

```json
{"url": "https://avustukset.hel.fi/fi/ohjeita-hakijalle", "action": "update", "received_at": "2026-11-04T05:53:02+00:00"}
```

- The name starts with the arrival time in microseconds (16 digits), so
  sorting the names gives arrival order. When a URL is queued more than once,
  the last one wins.
- Write the file under a temporary name that doesn't end in `.json` and rename
  it into place, so the worker never reads a partial file.
- Make files and folders group-writable (umask `0002`). The webhook and the
  worker run as different users and share the folder through its group.

## Configuration

### `config/crawler.yml`

The crawled sites, what's extracted from each page, the index (`output_index`)
and the Elasticsearch connection. Used by the webhook (for the list of sites),
the worker and the full crawl, all of which read it from
`/app/config/crawler.yml`: from the mounted project locally, and copied into
the images for OpenShift. See the comments in the file and the
[crawler documentation](https://github.com/elastic/crawler/tree/main/docs).

The Elasticsearch connection in the file is the local one. Other environments
override it with the `ELASTICSEARCH_*` variables below; credentials belong
there (in a Secret), never in the file.

### Environment variables

| Variable | Service | Default | Description |
|---|---|---|---|
| `WEBHOOK_TOKEN` | webhook | (none) | Shared secret for the API. The webhook refuses every request while it's empty. Locally read from `.env`. |
| `ELASTICSEARCH_HOST` | worker, crawler | `elasticsearch.host` in `crawler.yml` | Elasticsearch URL without the port, e.g. `https://elastic.example`. |
| `ELASTICSEARCH_PORT` | worker, crawler | `elasticsearch.port` in `crawler.yml` | Elasticsearch port. |
| `ELASTICSEARCH_API_KEY` | worker, crawler | (none) | API key for Elasticsearch. Takes precedence over the username and password. |
| `ELASTICSEARCH_USERNAME`, `ELASTICSEARCH_PASSWORD` | worker, crawler | (none) | Basic authentication for Elasticsearch. |
| `CRAWL_LIMIT_PER_DOMAIN` | crawler (compose only) | `50` | Maximum URLs per site in the local full crawl, passed as `--limit-per-domain`. `0` crawls everything. |

### Adding a site

1. Add a domain to `domains` in `config/crawler.yml`. Copy the settings of an
   existing one (`exclude_tags`, `extraction_rulesets`) and check that they fit
   the site's HTML.
2. Deploy, and run a full crawl to index the site.

Locally, the webhook reads the file on every request, so it accepts the new
site as soon as the file is saved. If a site is removed while its requests are
still queued, the worker skips them and logs a warning.

## OpenShift

Both images run as any non-root user, as OpenShift's restricted security
policy requires (a random UID in group 0). Locally, `compose.yaml` runs them
as UID 1000 in group 0 (so they can write to the mounted project folder), with
all Linux capabilities dropped and privilege escalation blocked, like the
restricted policy.

| Resource | Notes |
|---|---|
| Webhook Deployment | `docker/openshift/webhook/Dockerfile`. Port 8080 (plain HTTP; the Route terminates TLS). `WEBHOOK_TOKEN` from a Secret. Mount the queue volume at `/app/var`. Readiness and liveness probes: `GET /health` on port 8080. Expose with a Route. |
| Worker CronJob | `docker/openshift/worker/Dockerfile`, default command. `schedule: "*/30 * * * *"`, `concurrencyPolicy: Forbid`, and `activeDeadlineSeconds` longer than the longest batch. Mount the queue volume at `/app/var`. `ELASTICSEARCH_*` variables for the cluster, credentials from a Secret. |
| Full crawl CronJob (optional) | The worker image with the command `php /app/bin/crawl` (a complete crawl), e.g. nightly, with the same `ELASTICSEARCH_*` variables. Don't set `--limit-per-domain` here. |
| Queue PVC | Mounted at `/app/var` in the webhook and the worker; the queue is `var/queue` in it. `ReadWriteMany` (the webhook and the CronJob pods are separate pods), e.g. `storageClassName: azure-file`, 1Gi. Use a separate PVC, or a `subPath`, rather than sharing a folder with another app. |
| Elasticsearch | Not part of this project on OpenShift. Point the worker and the full crawl at the real cluster with the `ELASTICSEARCH_*` variables, and create the `hel-fi` pipeline there once (`config/pipeline.json`). |

With Azure Files (`azure-file`):

- Permissions come from the StorageClass mount options, not from the
  containers. The random UIDs can write if they include
  `dir_mode=0777,file_mode=0777`, or `gid=0` with `0775`/`0664`. Check with
  `oc get storageclass azure-file -o jsonpath='{.mountOptions}'`.
- File locks may work only within one pod (e.g. when mounted with `nobrl`).
  `concurrencyPolicy: Forbid` is what prevents overlapping runs; the lock file
  is a safety net.
- File information may be cached for up to 30 seconds (`actimeo=30`), so a new
  request can take that long to appear in `/status`.

The queue has not been tested on Azure Files yet.

## Limitations

- **Delay.** Webhook changes are applied every 30 minutes by default. The
  worker starts a new crawler process (a JVM) for each batch, which adds a few
  seconds of startup to every batch.
- **Missed notifications.** A webhook that's never sent, or a page affected
  indirectly, is only fixed by the next full crawl. Run one regularly.
- **One worker.** Running more than one worker at a time isn't supported.
- **Single token.** All sites share one token, and there's no rate limiting.
  Anyone with the token can queue recrawls of the allowed sites.
- **Body extraction.** `exclude_tags` accepts only plain tag names, not CSS
  selectors, so `body` can't be limited to `<main>`. Breadcrumbs and other
  page chrome outside the excluded tags stay in `body`.
- **Local Elasticsearch.** The `crawler-elastic` service runs without security and
  with a 512 MB heap. It's for development only.
