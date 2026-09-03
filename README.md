# StackNuts StackGauge

[![Latest Version](https://img.shields.io/packagist/v/stacknuts/magento-stackgauge.svg)](https://packagist.org/packages/stacknuts/magento-stackgauge) [![License](https://img.shields.io/packagist/l/stacknuts/magento-stackgauge.svg)](https://github.com/StackNuts/magento-stackgauge/blob/main/LICENSE) [![PHP Version](https://img.shields.io/packagist/php-v/stacknuts/magento-stackgauge.svg)](https://packagist.org/packages/stacknuts/magento-stackgauge)

A lightweight, read-only agent that reports a Magento store's version, patch, and
operational health data to a central StackGauge fleet dashboard - so an agency managing many
client sites can see everyone's update/security posture at a glance, instead of checking
each store by hand.

## Why

External scanners only see what a store exposes publicly - they can't read `composer.json`,
true per-module versions, or DB schema drift. This module runs *inside* Magento and reports
what only the inside can see, on a schedule, to a dashboard your agency controls.

## What it is

- **Push only.** This module calls out over HTTPS; it exposes no inbound endpoint and no new
  attack surface. The dashboard is the server; this module is purely a client.
- **Read-only.** Every built-in reporter only reads store/platform state. Nothing here
  modifies catalog, sales, or customer data.
- **Boring on purpose.** Collect and send, nothing else. Every interesting decision (alerting,
  severity rollups, retention) belongs on the dashboard side, not here.
- **Open core.** The reporter interface is public and documented below specifically so this
  doesn't have to be a closed checklist - your own modules, or another agency's, can
  contribute their own status data without ever touching this module's code.

## Installation

```bash
composer require stacknuts/magento-stackgauge
bin/magento module:enable StackNuts_StackGauge
bin/magento setup:upgrade
```

## Configuration

Go to **Stores > Configuration > Advanced > StackGauge**.

| Field | Notes |
|---|---|
| Enabled | Master switch. Off, no cron job or plugin sends anything - **Test Ping** still works while off, so a site can be fully configured and verified before switching this on. |
| Dashboard Endpoint URL | Full ingest URL issued by the dashboard. |
| Site Identifier | Issued by the dashboard when the site is registered; sent in every report. |
| API Key | Bearer token issued by the dashboard for this site. Stored encrypted. |
| HMAC Secret | Shared secret used to sign every report. Stored encrypted. |
| Enabled Reporters | Which built-in reporters run. Third-party reporters (see below) aren't listed here and always run. |
| Log Level | Minimum severity written to `var/log/stacknuts_stackgauge.log`. |

These settings apply to the whole Magento instance (default scope only) - one installation
reports as one site, not one report per store view.

Click **Test Ping** after saving to verify the endpoint URL and API key work end to end; a
successful test sends one real full report immediately.

## What gets collected

| Reporter | Payload key | Data |
|---|---|---|
| `CoreReporter` | `core` | Edition, Magento version, PHP version, deployment mode, whether static content is pre-deployed |
| `ModuleReporter` | `modules` | Every registered module (enabled or not), with its code version |
| `ComposerReporter` | `composer` | `composer.lock` hash + a small watch-list of key package versions |
| `DbSchemaReporter` | `db_schema` | Per-module code vs. DB `setup_version` drift |
| `PatchReporter` | `patches` | Output of `vendor/bin/patch-status` (Adobe's Quality Patches Tool CLI) when present; `detectable: false` otherwise |
| `IndexerReporter` | `indexers` | Per-indexer status and mode (schedule vs. update-on-save) |
| `CronReporter` | `cron` | Whether cron looks alive, plus last successful run per job code |
| `CacheReporter` | `cache` | Per-cache-type enabled/disabled status, plus which Full Page Cache type is active |
| `SecurityReporter` | `security` | Whether the admin path is still the default, maintenance-mode flag, sample-data modules present |
| `RedisReporter` | `redis` | Reachability + version of every Redis-backed cache frontend and the session backend, checked separately |
| `SearchReporter` | `search` | Configured search engine, a `pingable` flag (false for engines like MySQL search with nothing to ping), whether it's actually reachable, and the product index's document count once reachable (catches an empty/missing index, not just a down cluster) |
| `RabbitMqReporter` | `rabbitmq` | Per-queue message/consumer count for every queue routed through the "amqp" connection, if RabbitMQ is configured at all |
| `DiskSpaceReporter` | `disk` | Free/total bytes for var/log, var/cache, and media, checked independently since they're not guaranteed to share a mount |
| `DatabaseReporter` | `database` | MySQL/MariaDB version and reachability |
| `SalesReporter` | `sales` | Lifetime order count and lifetime count of quotes that ever had an item added, tracked as ever-increasing counters (see the class docblock for why "since midnight" was rejected) |
| `CatalogReporter` | `catalog` | Enabled product count, checked daily rather than hourly since catalog size doesn't need tighter monitoring |

`DiskSpaceReporter` reports `null` (not `0`) for a volume it can't measure - on this project's own dev environment, `var/cache` doesn't exist on disk at all (caching is entirely Redis-backed here), and `disk_free_space()` on a nonexistent path fails cleanly rather than lying with a fake number.

Deliberately **not yet built**: SSL cert expiry and rolling error-signal counts. Held back
because they need genuinely new machinery this module doesn't have yet - SSL needs an
outbound TLS handshake to a *public* hostname rather than an internal service; log signal
needs the first persistent per-reporter offset tracking, more state than any reporter here
currently keeps.

`SecurityReporter` reports `is_default_admin_path` as a **boolean**, never the real admin
path string - sending every client's actual (deliberately obscured) admin URL to a
third-party dashboard would concentrate exactly the secret that obscurity protects.

`RedisReporter`, `SearchReporter`, and `RabbitMqReporter` are the only reporters that make
network calls during collection, not just at send time - all three catch everything, so an
unreachable backend reports `reachable: false` rather than stalling the report or crashing
the cron job. `RedisReporter` uses `Credis_Client` (`colinmollenhour/credis`) rather than
requiring the phpredis PHP extension directly - it's already a transitive dependency of
`magento/framework` itself (via `colinmollenhour/php-redis-session-abstract`), so it's
present on every real Magento install regardless of whether phpredis is compiled in, and
prefers the native extension when it is. `SearchReporter` goes through Magento's own
`Magento\AdvancedSearch\Model\Client\ClientResolver` instead of talking to Elasticsearch/
OpenSearch's REST API directly - `ClientResolver::create()->testConnection()` is engine-
agnostic (same code path for Elasticsearch 5/7/8 or OpenSearch) and is a real ping against
the cluster, not just "is a hostname configured."

`RabbitMqReporter` checks `queue/amqp/host` first and reports `{"configured": false}`
immediately if RabbitMQ was never set up - most Community Edition sites fall into this
bucket (queues default to the "db" connection instead), and this avoids attempting a doomed
connection every cycle. When it is configured, per-queue depth comes from the AMQP
protocol's own passive `queue_declare` (returns message/consumer counts for a named queue
without side effects) via `Magento\Framework\Amqp\Config::getChannel()` - the exact same
connection and credentials the application already uses to publish/consume, reused rather
than duplicated. Deliberately **not** RabbitMQ's Management HTTP API, which would need a
third credential set (a different port, and often different auth) on top of the AMQP broker
credentials already configured. Queue names come from
`Magento\Framework\MessageQueue\Topology\ConfigInterface::getQueues()`, filtered to
`connection === 'amqp'` - Magento's own declared topology, not a guess at what might exist.
A queue that Magento's topology declares but that was never actually created on the broker
(no consumer has run yet) reports `exists: false` rather than an error, since that's a
routine state, not a fault.

## Payloads

Full report (hourly by default, also triggered by the CLI and Test Ping):

```json
{
  "type": "full",
  "schema_version": "1.0",
  "module_version": "1.0.0",
  "generated_at": "2026-08-29T12:00:00+00:00",
  "site": { "identifier": "site-123" },
  "reporters": {
    "core": { "schema_version": "1.0", "edition": "Community", "version": "2.4.7", "php_version": "8.3.1", "deployment_mode": "production", "static_content_deployed": true },
    "modules": { "schema_version": "1.0", "modules": [{ "name": "Magento_Catalog", "version": "103.0.5", "version_source": "composer_lock", "enabled": true }] },
    "redis": { "schema_version": "1.0", "backends": [{ "purpose": "cache_default", "reachable": true, "version": "7.2.4" }, { "purpose": "session", "reachable": true, "version": "7.2.4" }] },
    "search": { "schema_version": "2.1", "engine": "opensearch", "pingable": true, "reachable": true, "index_document_count": 4213 },
    "rabbitmq": { "schema_version": "1.0", "configured": true, "reachable": true, "queues": [{ "name": "async.operations.all", "exists": true, "messages": 0, "consumers": 1 }] }
  }
}
```

Heartbeat (every 5 minutes, and instantly on a maintenance-mode change):

```json
{ "type": "heartbeat", "schema_version": "1.0", "generated_at": "...", "site": { "identifier": "site-123" }, "maintenance_mode": false }
```

Both post to the same configured endpoint URL, distinguished by `type`.

## Transport / auth

- `POST` to the configured endpoint URL.
- `Authorization: Bearer <api_key>`
- `X-StackGauge-Signature`: hex HMAC-SHA256 of the raw JSON body, using the configured HMAC
  secret.
- `X-StackGauge-Site-Id`: duplicates `site.identifier`, so the dashboard can route/validate
  before parsing JSON.
- 5s connect / 10s total timeout - a slow or unreachable dashboard can never hang a site's
  cron.

## Cron

Runs in its own `stackgauge` cron group (independently schedulable/observable from Magento's
`default` group):

- `stacknuts_stackgauge_send_report` - full collection, hourly by default.
- `stacknuts_stackgauge_send_heartbeat` - lightweight ping, every 5 minutes by default.

Both cron jobs, and the maintenance-mode plugin, catch every exception internally - a
dashboard outage or misconfiguration can never fail a cron run or block
`bin/magento maintenance:enable|disable`.

A single site-wide `bin/magento cron:run` (with no `--group`) - the standard single system
crontab entry (`* * * * * bin/magento cron:run`) virtually every real Magento install
already has - runs every registered cron group, including custom ones like `stackgauge`.
Confirmed by reading Magento's own `ProcessCronQueueObserver`: the group filter only
*excludes* groups when `--group` is explicitly passed, so no group-specific crontab entry
or `cron:install` re-run is needed for this module's jobs to actually fire.

**A real bug caught by testing this live, not just reading the code**: `etc/cron_groups.xml`
originally shipped with `schedule_generate_every=1` / `schedule_ahead_for=2` (minutes),
copied loosely from a generic example without checking it against this group's own jobs.
With only a 2-minute ahead-for window, Magento's schedule generator only ever creates a
pending row for the hourly `send_report` job during the 2 minutes immediately before each
hour boundary - miss that narrow window (near-certain on real system cron, since it isn't
aligned to your server's exact hour boundary) and the job silently never gets scheduled at
all. Fixed to `schedule_generate_every=5` / `schedule_ahead_for=10` (5x the heartbeat's own
period), and `history_success_lifetime` bumped from the default group's 60 minutes to 180 -
`CronReporter` reads "last successful run" from this same history, and a 60-minute window is
narrower than the hourly job's own period, so it could occasionally show a false gap right
before each new run. Verified end-to-end against a real `cron_schedule` table: schedule
generation, a forced-due row actually executing via `cron:run`, and a real signed POST
landing successfully against a public echo endpoint with the HMAC header intact - not just
each piece in isolation.

## CLI

```bash
bin/magento stackgauge:send --dry-run   # print the assembled payload, don't send
bin/magento stackgauge:send             # send now (skipped if "Enabled" is off)
bin/magento stackgauge:send --force     # send now regardless of "Enabled"
```

### Useful before a dashboard exists

Every real send attempt (the hourly cron, `stackgauge:send` without `--dry-run`, and Test
Ping) logs the full payload at Info level before attempting delivery - not just on failure.
Set **Log Level** to "Info" and turn **Enabled** on, and `var/log/stacknuts_stackgauge.log`
fills up with a complete, real snapshot every hour, greppable/`jq`-able, with or without an
endpoint URL configured yet. That makes the hourly cron a useful local fleet-history log in
its own right from day one, not just a debugging aid once a dashboard exists to send to.

## Pluggable reporters

Third-party modules can contribute their own named block to the payload without this module
knowing about them in advance. Implement `StackNuts\StackGauge\Api\ReporterInterface`:

```php
namespace StackNuts\StackGauge\Api;

interface ReporterInterface
{
    public function getName(): string;          // payload key, must be unique, e.g. "cloudflare"
    public function getSchemaVersion(): string;  // your own schema version, opaque to StackGauge
    public function getStatus(): array;          // array<string, Section\SectionInterface> - see below
}
```

Register it against the shared `reporters` array on `ReporterPool` from your own `di.xml` -
Magento merges array-type arguments across every module's `di.xml`, so nothing in
StackNuts_StackGauge needs to change. This is a real, live example, not a hypothetical one -
[`stacknuts/magento-cloudflare-cache-stackgauge`](https://github.com/StackNuts/magento-cloudflare-cache-stackgauge)
implements it in `Model/StackGaugeReporter.php` and registers it in its own `etc/di.xml`:

```xml
<type name="StackNuts\StackGauge\Model\ReporterPool">
    <arguments>
        <argument name="reporters" xsi:type="array">
            <item name="cloudflare" xsi:type="object">StackNuts\CloudflareCacheStackGauge\Model\StackGaugeReporter</item>
        </argument>
    </arguments>
</type>
```

```php
class StackGaugeReporter implements \StackNuts\StackGauge\Api\ReporterInterface
{
    public function getName(): string { return 'cloudflare'; }
    public function getSchemaVersion(): string { return '3.0'; }
    public function getStatus(): array
    {
        return [
            'general' => Section::facts('general', 'General', $this->getDescription(), [
                'enabled' => Field::bool('Enabled', $this->config->isActive()),
                'purge_queue_backlog' => Field::number('Purge Queue Backlog', $this->purgeQueue->getPendingCount()),
            ]),
        ];
    }
}
```

Deliberately restricted to a bool and a number for this first cross-module reporter, rather
than richer detail (e.g. a last-purge timestamp) - the simplest useful shape, in keeping with
the "keep it small" rule below.

**Compatibility module, not a direct dependency**: this integration doesn't live in
`StackNuts_CloudflareCache` itself, and `StackNuts_CloudflareCache` has no dependency on
StackGauge at all - it lives in its own small `stacknuts/magento-cloudflare-cache-stackgauge`
module that requires both. This is the same pattern Magento core uses for optional
cross-module features (e.g. `Magento_CatalogInventoryGraphQl`, `Magento_PaypalGraphQl`): a
module named after the pair it bridges, containing only glue code. Install it, and both are
wired together; don't, and both parent modules work exactly as if it didn't exist - no
partial functionality, and no risk to either parent's own `setup:di:compile` from a class
that `implements` an interface belonging to a module it doesn't otherwise depend on. If you
add a reporter for your own module, this is the recommended shape to copy: a third,
dedicated compatibility module, not a direct edit to either existing one.

**Shape rules for `getStatus()`:**

- Return `array<string, SectionInterface>` - every value built via
  `StackNuts\StackGauge\Api\Section\Section::facts()` or `::table()`, each field inside a
  section built via `StackNuts\StackGauge\Api\Field\Field::bool()`/`varchar()`/`number()`/
  `datetime()`/`trackableNumber()`. No raw scalars, objects, resources, or closures - each
  Section/Field type validates and cleans its own value at construction time.
- `Section::facts($key, $label, $description, $fields)` for a flat key -> scalar fact list
  (no array-shaped fields allowed - split those into their own table section instead).
  `Section::table($key, $label, $description, $rows)` for a homogeneous list of records, each
  row built with `Field::array($rowLabel, ['col' => Field::...])` - every row must share the
  same set of column keys.
- Don't use the keys `schema_version`, `label`, or `description` in the array returned by
  `getStatus()` - `ReporterPool` injects those itself, so they're reserved.
- A field's own key must stay unique across your reporter's sections - the dashboard's
  trackable `metric_key` convention (`"<reporter_name>.<field_key>"`) only sees the field
  key, not which section it lives in.
- Nothing to report yet? Return a section with an empty `fields`/`rows` array rather than
  throwing - a thrown exception is treated as a genuine failure: it's caught, logged, and your
  block becomes `{"error": "..."}` for that cycle instead of your real data.
- Keep it small. This rides an hourly/5-minute report, not a bulk export - no raw file
  contents, no PII, no binary blobs.

The admin **Enabled Reporters** toggle only covers this module's own built-in codes - a
third-party reporter isn't individually toggleable from this screen. To suppress one, remove
its `di.xml` registration, or have that module add its own on/off setting and return an
empty array when off.

## Uninstall

```bash
bin/magento module:disable StackNuts_StackGauge
composer remove stacknuts/magento-stackgauge
bin/magento setup:upgrade
```

## Test coverage

Unit tests cover the logic-bearing, I/O-free pieces: `PayloadSigner` (HMAC correctness),
`PayloadBuilder` (envelope shape), and `ReporterPool` (failure isolation, the enabled-
reporters filter, and that third-party reporter names always run). The Magento-core-heavy
reporters (Indexer/Cron/Cache/Module/Composer/Security/Patch) would need heavy framework
mocking for limited value at this stage and are left for a follow-up integration-test pass.

## License

MIT. See [LICENSE](LICENSE).
