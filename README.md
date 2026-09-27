# qoliber/trident-symfony

Trident HTTP cache for **any Symfony application** (6.4 / 7.x, PHP 8.2+,
Doctrine ORM). The Sylius bundle (`qoliber/trident-cache-sylius`) builds on it.

What it does:

- **Shares pages safely.** For the routes you list, the response policy decides
  *after* rendering whether Trident may share the page:
  - shared (`public, max-age=0, s-maxage=3600, stale-while-revalidate=86400`,
    plus bounded `X-Cache-Tags`) only for a GET/HEAD 200 without
    `Set-Cookie`;
  - the render must not have read the visitor's session (the session was not
    started, or it is new and empty);
  - the request carries no bypass cookie, and no platform voter refuses.
  - Everything else on those routes is `private, no-store`.
- **Durable purges from Doctrine.** Each change's tags (from your
  `EntityTagResolver` services) are recorded as outbox rows **in the same
  transaction** as the change:
  - a rolled-back save leaves nothing;
  - a committed one can never lose its purge;
  - rows are delivered at the end of the request and removed only when Trident
    acknowledged them;
  - otherwise they are retried with backoff;
  - each purge is delivered **once more** `redeliver_after` seconds later
    (default 10, `0` = never). Trident already refuses to store a page whose
    fetch began before a purge of its tags; the second delivery also covers a
    render that read the old data from a replica or an application cache.
- **FOSHttpCache (optional):** the `TridentProxyClient` (tags, clear) uses the
  same outbox. With `fos_proxy_client: auto` (default) it becomes FOSHttpCache's
  client only when the application configured none — an existing Varnish or
  Symfony client is never replaced; `true` forces it, `false` never.
- **Several Trident instances**, each purged and retried on its own.
- **Admin screens' logic:** `qoliber/trident-php`'s `Admin\AdminService`
  (shared with Shopware): dashboard, purge, cached pages, tags, coverage,
  warmer, launch, reflect, denoisers, bans, backends, discovery, events. Pattern
  purges and bans are limited to the shop's own hosts. A platform renders the
  screens and provides a `ShopAdapter`.

Requires Doctrine ORM and DBAL (MySQL/MariaDB, PostgreSQL and SQLite are
tested). FOSHttpCacheBundle, DoctrineMigrationsBundle and symfony/scheduler are
optional; the bundle boots in a plain kernel without them.

## Configuration

Instances come from the environment first (they describe infrastructure, and
differ between staging and production while the database is copied):

```dotenv
TRIDENT_INSTANCES='{"edge-1":{"api_url":"http://10.0.0.11:9301","api_token":"…"},"edge-2":{"api_url":"http://10.0.0.12:9301"}}'
TRIDENT_API_TOKEN=…            # the token of an instance that names none
TRIDENT_ALLOWED_API_HOSTS=10.0.0.11,10.0.0.12   # optional allowlist (host or host:port, exact)
TRIDENT_PURGE_MODE=soft         # soft (default) or hard
TRIDENT_TAG_PREFIX=shop1_       # several shops on one Trident
TRIDENT_DEBUG_HEADERS=1         # X-Trident-Decision on responses
TRIDENT_TOKEN_KEY=…             # optional: key for the sealed admin token (default: kernel.secret)
```

Without any environment instance, the admin screen's single URL and token are
used.

```yaml
# config/packages/trident.yaml
trident:
    cacheable_routes: [app_home, app_product_show, app_category]
    bypass_cookies: [my_login_marker]
    tags:
        identity_prefixes: [product_]
        overflow_families: { '/^product_\d+$/': product_overflow }
```

Run the migration (`doctrine:migrations:migrate`): it creates
`trident_purge_outbox` and `trident_settings`. Without DoctrineMigrationsBundle,
`doctrine:schema:update` creates them from the entity mapping, which the bundle
adds to the default entity manager (the short `orm.mappings` form or
`entity_managers:`).

If the instance comes from the admin screen and the settings table cannot be
READ (the database is down, not "not migrated yet"), the failure is logged as
critical, purges are still recorded for the `default` instance (delivered once
the settings read again), and `trident:purge:status` reports it — a save never
fails because of Trident. Environment instances do not depend on that read.

```yaml
trident:
    redeliver_after: 10          # seconds; 0 = deliver each purge once
    fos_proxy_client: auto       # auto | true | false
```

Implement `Qoliber\TridentSymfony\Tags\EntityTagResolver` for your entities
(autoconfigured), and add tags to pages with `ResponseTags::add()` or
FOSHttpCache's response tagger.

## Delivery and monitoring

- `trident:purge:drain` — deliver due rows (cron every minute). With
  symfony/scheduler, `messenger:consume scheduler_trident` does the same.
  `--force` retries failed rows despite their backoff; `--now` delivers every
  row at once, whatever its due time (second deliveries included) — after an
  incident, or in tests.
- `trident:purge:status` — instances, pending rows, errors; **exit 1** when a
  purge has been pending over 15 minutes (`--stale-after=<seconds>` to
  change it), a configuration error exists, or rows are owed to an instance
  that is no longer configured. A purge owed for a few seconds is normal.
- `trident:purge:forget <instance>` — drop the rows of a removed instance.
- `trident:purge [tags…] [--all]` — purge by hand, durably.

## Security

- **The admin-stored token** is sealed to its URL (`Security\TokenVault`:
  XChaCha20-Poly1305, the normalised URL as associated data, HKDF of
  `TRIDENT_TOKEN_KEY` or the kernel secret).
  - It is never returned to the admin screen.
  - Pointing the URL elsewhere makes it unusable until re-entered.
- **Token routing:** the **environment token goes only to environment URLs.**
- **API URLs** must be `http(s)://host[:port][/path]`.
- **Refused targets:** link-local and cloud-metadata addresses are refused on the
  resolved address, which is pinned for the connection (`Http\NetworkGuard`).
- **Screens** show only answers from a verified Trident admin API, and error
  bodies are clipped.
- **A client's `Surrogate-Capability` header** is removed before the application
  sees it.

## Versioning

Versions follow Trident: this bundle 1.8.x works with Trident 1.8. MAJOR.MINOR moves
with the engine (every Trident X.Y.0 release is also a release of this package,
changed or not); the PATCH number is this package's own. The
admin screens warn when a connected Trident runs another release line.

## This repository is a mirror

`qoliber/trident-symfony` is developed in the Trident repository together with the
shared library [`qoliber/trident-php`](https://github.com/qoliber/trident-php)
and the live end-to-end test stacks, and published to
[github.com/qoliber/trident-symfony](https://github.com/qoliber/trident-symfony) automatically:
every commit there is a "Sync from trident-cache@…" snapshot. **Please open
issues there**; pull requests against the mirror cannot be merged, because the
next sync would overwrite them. Releases are the tags of that repository.
