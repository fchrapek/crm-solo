# Leads

Two funnels, one per brand the practice sells under (`pipeline`: `kiwwwi`,
`filipchrapek`), on one board at `/leads`. A lead is a prospect before
anything is a Client.

**`config/leadgen.php` is the contract**: sources, stages, scoring weights,
tiers, routing, follow-up SLAs. `tests/Unit/LeadgenConfigTest.php` pins its
structure. Never hardcode this vocabulary in code or UI; read it from config.
Custom sources and label overrides saved in Settings (settings row
`scope=leadgen`) are merged over the config at boot.

## Rules

- **`source` and `pipeline` are immutable after capture**, enforced in
  `Lead::booted` (an update that changes either throws). The source is the
  channel attribution the funnel is measured by; editing it would rewrite a
  channel's history.
- **`visitor` is funnel math, not a row stage.** `analytics_only_stages` keeps
  it off every row: visitors are anonymous, so visitor to lead is measured in
  analytics, never here. Both pipelines' row stages are `new`,
  `conversation`, `offer`, `won`; `Lead::rowStages()` and
  `unifiedRowStages()` drive validation and the single mixed board (brand is a
  filter and a chip).
- **Capture is a creation, not a transition.** The first `LeadStageEvent` has
  `from_stage` null and `to_stage` the entry stage (`new`; on `filipchrapek`
  a `social` lead enters at `conversation` through `entry_stage_overrides`). A
  synthetic hop would forge a 100% conversion at the one boundary the CRM does
  not measure.
- **`lead_stage_events` is the funnel measurement.** Stage moves go through
  `Lead::transitionTo()`; the rates assumed in config are meant to be replaced
  by rates computed from these rows.
- **Scoring is derived, never stored.** `score_factors` holds only the ticked
  factor slugs; `scoreTotal()`, `tier()` and `tierRouting()` compute from
  config on read. Tier slugs are `gold`, `oak`, `rowan`, labelled Hot (at
  least `tiers.gold_min`, 7), Warm (from `oak_min`, 4) and Cold. Labels resolve
  config, then i18n, then a humanised slug (`useLeadLabel()` in
  `pages/leads/lib/labels.ts`), so custom vocabulary never renders raw. A
  factor from the wrong funnel is rejected; a retired factor stops counting.
- The tier filter resolves in PHP because the tier is not a column. If that is
  ever too slow, add a materialised column rather than a JSON expression in
  `WHERE`.
- **`PATCH /leads/{lead}/stage` answers JSON, not a redirect.** The board uses
  `fetch()`, which follows a 302 with the same method into a GET-only route
  (405) and rolls the card back although the move committed. The same holds for
  `PATCH /tasks/{task}/agent-lane`.
- **Convert to client** only from `Lead::wonStage()`. The lead keeps
  `client_id`, so "which channel produced this client" stays answerable.
- Leads soft-delete; `PUT /leads/{lead}/restore` brings one back.

## Marketing consent and click ids

A pulled lead keeps an ad click id only when the visitor granted the service
that issued it (decided 2026-09-29). The kiwwwi lead API sends each
submission's ConsentLite state as `consent` (snapshot v2, read from the
consent cookie at submit).

- `App\Services\Leads\MarketingConsent::parse()` is a strict schema check:
  exact version, exact slugs (no trimming or lower-casing, so `mark eting` is
  malformed, never `marketing`), list shapes, `necessary` present, and a
  revision equal to `services.kiwwwi.leads.consent_revision` (env
  `KIWWWI_CONSENT_REVISION`, default 0). Anything off is unknown.
- A service is granted only when `marketing` is accepted and the service id
  is in `services.marketing`. An empty list is denied and a missing one
  unknown, because the widget never builds a marketing category without
  services. No cookie at submit is unknown (NULL), not denied.
- `leads.marketing_consent` records the `google_ads` state: `granted`,
  `denied` or NULL for unknown. The notes gain a `Marketing consent:` line.
- Retention per id: gclid, gbraid, wbraid and dclid need `google_ads`, fbclid
  needs `meta_pixel`, msclkid never stays (the widget has no Microsoft
  service).
- `App\Services\Leads\ClickIdScrubber` has one key parser (`canonicalKey`:
  case-insensitive, percent-decoded, `[]` suffix dropped) shared by detection
  and scrubbing. It covers query, fragment, `;`, `&amp;`, encoded nested URLs,
  bare pairs and `gclid: value` prose, and at import runs on the source URL,
  the tracking pairs and the assembled notes, visitor message included.
- `source` is derived first with the same key parser, so `ads` attribution
  never depends on consent. Any new import path that stores landing URLs or
  free text must go through the same gate. A staged submission keeps the raw
  payload until it imports.
- Backfill: `php artisan kiwwwi:scrub-click-ids` (dry run; `--apply` writes,
  trashed rows included, idempotent) detects by comparing scrubbed and
  original notes; granted leads keep Google ids only. Scrubbed notes cannot be
  restored, so back up the database before `--apply`.

Routes: `resource('leads')` without `show`, plus `PATCH /leads/{lead}/stage`,
`POST /leads/{lead}/convert`, `PUT /leads/{lead}/restore`.

## Capture paths

- The form, `crm lead-capture` and the MCP `lead_capture` tool (full model
  validation). The verb and the tool go through `App\Services\Leads\LeadCapture`.
- The closed-mode splash (`/czesc`, source `waitlist`) through the same
  service, deduped on email per account. Setup: `setup.md`, Closed mode.
- `php artisan kiwwwi:sync-leads`, every 15 minutes when
  `KIWWWI_LEADS_*` is configured: pulls form submissions from a WordPress
  endpoint on each configured site, stages every submission before importing
  any, and runs one pull at a time. Sites are endpoints in
  `services.kiwwwi.leads.endpoints`: each has its own cursor and its own
  `external_ref` prefix (`ff-` for blog 1, kept from the single-site era so
  old refs stay stable; `ff-en-` for blog 2), because FluentForm ids are
  per-blog sequences. A lead's notes carry a `Site:` line; `source` stays the
  channel (ads or www-form), never the language. One site failing never
  blocks the other, and a payload naming the wrong blog id is refused.
