# Dashboard handoff — new reporters, heartbeat field, and metrics

Written for: whoever is building dashboard-side support for these changes (not merchant-facing — this is internal, not part of the module's own README).

Scope: everything added to the StackGauge Magento agent in this round of work. Each section says exactly what changed, the wire shape, and what (if anything) needs real dashboard code versus what should already work if the dashboard's existing generic rendering is in fact generic.

## 1. New heartbeat field: `storefront_probe`

Heartbeats (`type: "heartbeat"`) are sent every 5 minutes by `Cron\SendHeartbeat` and are a **separate, hand-built payload** — they do not go through the Field/Section reporter pipeline that the hourly/daily reports use, so nothing about them is self-describing. Anything new here needs explicit ingestion code.

`HeartbeatSender::SCHEMA_VERSION` bumped `1.0` → `1.1`.

```jsonc
{
  "type": "heartbeat",
  "schema_version": "1.1",
  "generated_at": "2026-10-01T13:45:00+00:00",
  "site": { "identifier": "..." },
  "maintenance_mode": false,
  // NEW — only present when the site has "Storefront Self-Probe" enabled (admin toggle,
  // default ON). Entirely absent from the payload when disabled, not sent as null/false.
  "storefront_probe": {
    "attempted": true,
    "reachable": true,
    "http_status": 200,
    "looks_blocked": false,
    "looks_like_error_page": false
  }
}
```

Field meanings:
- `attempted` — false only when the store has no base URL configured yet (never happens on a real install). Every other outcome, including a connection failure, has `attempted: true`.
- `reachable` — got any HTTP response at all (even a 500 counts as reachable — see `looks_like_error_page`).
- `http_status` — raw status code, `0` if the connection never completed (DNS failure, timeout, refused).
- `looks_blocked` — body matched a WAF/CDN challenge-page signature (Cloudflare "Just a moment", etc.). This means something in front of Magento stopped the request before it ever reached the app — **not** the same as the site being down.
- `looks_like_error_page` — body matched Magento's own genuine production fatal-error page text. This means Magento itself is broken.

Suggested dashboard treatment: these three booleans are mutually informative, not mutually exclusive flags to pick one of — a real outage usually looks like `reachable: true, http_status: 500, looks_like_error_page: true`, while a false-positive-prone external monitor would see `reachable: true, http_status: 403, looks_blocked: true` and should **not** page anyone for that case the way it would for a genuine error page.

## 2. New reporter blocks

These go through the normal `reporters` object in hourly/daily full reports, same shape as every existing reporter (Field/Section, self-describing `type`/`label`/`critical_when`/`severity` on every field). **If your dashboard already renders unknown reporter blocks generically from that metadata, these should require zero new rendering code.** Listed here for reference and so you know what to expect, plus anywhere the behavior has a wrinkle worth knowing about.

### `admin_accounts` (daily cadence, schema_version `1.1`)

```jsonc
{
  "general": {
    "total_admin_accounts": { "type": "number", "value": 2 },
    "locked_accounts": { "type": "number", "value": 0, "severity": "ok" },
    "accounts_with_recent_failed_logins_24h": { "type": "number", "value": 0, "severity": "ok" },
    "new_admin_accounts_24h": { "type": "number", "value": 0, "severity": "ok" },
    "two_factor_auth_enabled": { "type": "bool", "value": false },
    "two_factor_auth_bypass_detected": { "type": "bool", "value": true, "critical_when": true },
    "admin_accounts_without_2fa": {
      "type": "number", "value": 0, "severity": "ok",
      "metric_key": "admin_accounts.without_2fa", "aggregation": "latest"
    }
  }
}
```

Worth knowing: `two_factor_auth_enabled` is **not** a simple "is the TFA module installed" flag — Magento's own `TfaInterface::isEnabled()` is unreliable (hardcodes `true` whenever the module exists, regardless of real enforcement), so this field also factors in detection of a known dev-bypass tool (`MarkShust_DisableTwoFactorAuth`, common in local Docker dev setups). `two_factor_auth_bypass_detected` is the explicit "why" when `two_factor_auth_enabled` is `false` despite the module being present. Expect this to show `true` on a lot of client dev/staging sites — that's normal there, not a real finding, but genuinely bad if it ever shows up on a production site.

No PII: never usernames/emails, counts only.

### `config_hygiene` (daily cadence, schema_version `1.0`)

```jsonc
{
  "general": {
    "template_hints_enabled": { "type": "bool", "value": false, "critical_when": true },
    "css_minify_disabled": { "type": "bool", "value": false, "critical_when": true },
    "js_minify_disabled": { "type": "bool", "value": false, "critical_when": true },
    "static_content_signing_disabled": { "type": "bool", "value": false, "critical_when": true }
  }
}
```

Straightforward — all four are dev-settings left in a risky production state.

### `db_queue` (hourly cadence, schema_version `1.0`)

```jsonc
{
  "general": {
    "queue_count": { "type": "number", "value": 24 },
    "total_backlog": {
      "type": "number", "value": 0, "severity": "ok",
      "metric_key": "db_queue.total_backlog", "aggregation": "latest"
    },
    "total_errors": {
      "type": "number", "value": 0, "severity": "ok",
      "metric_key": "db_queue.total_errors", "aggregation": "latest"
    }
  },
  "queues": [
    { "type": "array", "value": { "name": {...}, "backlog": {...}, "errors": {...} } }
    // one row per registered DB-backed queue name (expect ~20-30 on a typical Magento install)
  ]
}
```

Covers Magento's own MySQL message-queue adapter ("db" connection) — a large set of queues (inventory reservations, media gallery sync, mass attribute updates, etc.) that exist independently of whatever's configured for RabbitMQ. Complements the existing `rabbitmq` block rather than replacing it.

### `uptime` (hourly cadence, schema_version `1.0`) — NEW REPORTER

```jsonc
{
  "general": {
    "attempted": { "type": "bool", "value": true },
    "reachable": {
      "type": "number", "value": 1, "severity": "ok",
      "metric_key": "uptime.reachable", "aggregation": "min"
    },
    "http_status": { "type": "number", "value": 200 },
    "looks_blocked": { "type": "bool", "value": false, "critical_when": true },
    "looks_like_error_page": { "type": "bool", "value": false, "critical_when": true }
  }
}
```

Same underlying probe as the heartbeat's `storefront_probe` (§1), run again hourly specifically so it can carry a trackable metric (metrics only attach to real reporters — see `Model\MetricCatalogPool`). **`reachable` is sent as `1`/`0`, not `true`/`false`**, because it needs to be numeric for the `min` aggregation to work (see §3's action item).

## 3. New trackable metrics — action items

| metric_key | aggregation | default operator | default threshold | default window (min) | Status |
|---|---|---|---|---|---|
| `admin_accounts.without_2fa` | `latest` | `gt` | `0` | 1500 | Same aggregation as existing metrics — should work with no new dashboard code |
| `db_queue.total_backlog` | `latest` | `gt` | `50` | 15 | Same as above |
| `db_queue.total_errors` | `latest` | `gt` | `0` | 15 | Same as above |
| `uptime.reachable` | **`min`** | `lt` | `1` | 120 | **Action item — see below** |

**Action item**: `uptime.reachable` is the first metric anywhere in this module to use `MetricDefinition::AGGREGATION_MIN`. If your alert-evaluation engine has a hardcoded switch over known aggregation types (`sum`/`avg`/`latest`/`max`/`delta`, whatever you've implemented so far), it needs a `min` case added: *the lowest sample value within the window*. The intent is "was the site unreachable (`0`) at any point in the last 2 hours" — not an average, not just the latest sample. A single blip should trip this; a site that's been solidly up the whole window should not.

These metric definitions arrive automatically via the existing config-sync mechanism (`Model\PayloadBuilder::buildConfigSync()` / `Model\MetricCatalogPool`) the same way every other metric always has — no new sync code should be needed, only the `min` evaluator.

## 4. Schema version bumps — what changed, not just the number

- **`logs`**: `1.0` → `1.1`. `recent_exceptions` and every `tail_*` section now show lines appended *since the last collection*, not a fresh rescan of the whole file each time. They can legitimately be empty on a quiet run — that's not a regression or a sign collection stopped, it means nothing new happened. `exception_count` is the one field that still behaves as before (a stable, ever-growing total).
- **`modules`**: `1.0` → `1.1`. `version_source` can now be `composer_json`, `version_json`, or `registration_php` in addition to the existing `composer_lock`/`module_xml`/`unknown` — purely additive, no existing value's meaning changed.
- **`admin_accounts`**: `1.0` → `1.1` (first appearance of this reporter was already `1.0`; see §2 above for the real behavior change around `two_factor_auth_enabled`/`two_factor_auth_bypass_detected`).

## Summary checklist for the dashboard

- [ ] Add `storefront_probe` parsing to whatever ingests heartbeat payloads (not automatic — heartbeat isn't self-describing)
- [ ] Implement `AGGREGATION_MIN` in the alert-evaluation engine (blocks `uptime.reachable` from alerting correctly until done)
- [ ] Confirm generic reporter-block rendering actually handles unknown reporter names/fields (if it does, `admin_accounts`/`config_hygiene`/`db_queue`/`uptime` need no further work)
- [ ] Nothing required for the `logs`/`modules` schema bumps unless the dashboard has version-specific rendering logic for those two blocks specifically
