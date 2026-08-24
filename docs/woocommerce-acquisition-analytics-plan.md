# WooCommerce Acquisition Analytics Implementation Plan

## Goal and agreed decisions

Build a dedicated, non-PII attribution pipeline from the City Prepping WooCommerce plugin to `analytics.cityprepping.com`. Completed orders will be synchronized asynchronously, updated after refunds or later status changes, combined with existing short-link click data, and displayed in a new Acquisition dashboard with an aggregated CSV export.

Agreed product decisions:

- [x] Use a dedicated minimal plugin event instead of the full WooCommerce order webhook for acquisition reporting.
- [x] Count an order as a conversion only when it reaches `completed`.
- [x] Update previously completed conversions after refunds or later status changes.
- [x] Classify completed orders as new, returning, or unknown inside WordPress without transmitting customer identity.
- [x] Report both net merchandise revenue and net charged order total.
- [x] Include WooCommerce's non-PII native attribution fields alongside the custom City Prepping fields.
- [x] Provide an Acquisition dashboard and privacy-safe CSV export.
- [x] Backfill historical completed orders from data already stored by `analytics.cityprepping.com`; do not run the backfill against the live City Prepping WordPress database.
- [x] For historical periods missing from analytics, use a one-time minimal SQL extraction from a restored production backup or read replica, then import that sanitized dataset into analytics.
- [x] Prepare stable campaign keys for future spend, CAC, and ROAS reporting without adding spend support in this release.
- [x] Leave the existing broad WooCommerce webhook operational for the current product, membership, subscription, and revenue pipelines.

## 1. WordPress plugin: attribution capture

- [x] Preserve the checkout hook that copies tracking cookies into WooCommerce order metadata.
- [x] Preserve the wp-admin Attribution block and existing order-list columns.
- [x] Clear the complete previous attribution set when a new valid touch is received before writing new values.
- [x] Clear `cp_slid`, `cp_channel_variant`, and `cp_placement_label` when the new touch does not include them.
- [x] Continue clearing mutually exclusive Kit and YouTube identifiers when the source changes.
- [ ] Store the time of the last valid attributed touch in a 30-day cookie.
- [ ] Copy that timestamp to `_cp_attributed_at` when the order is created.
- [ ] Confirm cookie behavior under HTTPS, consent tooling, classic checkout, and block checkout.

## 2. WordPress plugin: analytics event construction

- [ ] Add a separate synchronization module; do not send analytics from the checkout-create hook or the wp-admin display block.
- [ ] Queue the initial event from `woocommerce_order_status_completed`.
- [ ] Mark the order as having reached completion so subsequent changes can be synchronized.
- [ ] Queue revised snapshots after a refund or later status change only when the order was previously synchronized as completed.
- [ ] Fetch the latest order state when a queued action executes so rapid changes collapse into the newest snapshot.
- [ ] Generate a unique event ID for each state snapshot and retain it across retries.
- [ ] Generate a stable order reference using HMAC-SHA256 over the store identifier and WooCommerce order ID.
- [ ] Never include the raw order ID or another reversible customer/order identifier in the payload.
- [ ] Compute customer classification locally:
  - `new`: no earlier qualifying order exists for the logged-in customer or normalized guest billing email.
  - `returning`: an earlier qualifying order exists.
  - `unknown`: no reliable local comparison is possible.
- [ ] Compute and send monetary values as decimal strings:
  - Original order total, including tax and shipping.
  - Refund total.
  - Net charged total after refunds.
  - Net merchandise revenue after discounts and item refunds, excluding tax and shipping.
- [ ] Read the following custom attribution fields from order metadata:
  - `_cp_from`
  - `_cp_slid`
  - `_cp_email_id`
  - `_cp_youtube_id`
  - `_cp_channel_variant`
  - `_cp_placement_label`
  - `_cp_attributed_at`
- [ ] Read the following WooCommerce native attribution fields:
  - Source type
  - UTM source
  - UTM medium
  - UTM campaign
  - Device type
- [ ] Do not send names, email addresses, customer IDs, addresses, postcodes, IP addresses, user agents, notes, transaction IDs, raw referrer URLs, arbitrary metadata, or customer-level line-item data.

### Version 1 event contract

```json
{
  "schema_version": 1,
  "event_id": "uuid",
  "event_type": "order.completed",
  "event_occurred_at": "2026-08-11T18:00:00Z",
  "store_id": "cityprepping",
  "order_ref": "hmac-sha256-reference",
  "order": {
    "completed_at": "2026-08-11T17:58:00Z",
    "current_status": "completed",
    "currency": "USD",
    "order_total": "124.95",
    "refund_total": "0.00",
    "net_order_total": "124.95",
    "net_merchandise_revenue": "109.95",
    "customer_type": "new"
  },
  "attribution": {
    "custom_source": "youtube",
    "short_link_id": "survival-01",
    "email_id": null,
    "youtube_id": "video-123",
    "attributed_at": "2026-08-06T14:30:00Z",
    "native_source_type": "utm",
    "utm_source": "youtube",
    "utm_medium": "video",
    "utm_campaign": "august-launch",
    "device_type": "mobile",
    "channel_variant": "...",
    "placement_label": "..."
  }
}
```

- [ ] Represent refund and post-completion changes as new events containing a complete current snapshot.
- [ ] Use `order.updated` for later status/refund snapshots and preserve the original completion timestamp.
- [ ] Validate and length-limit every outbound string even though it originated locally.

## 3. WordPress plugin: delivery, retries, and operations

- [ ] Add these deployment constants to `wp-config.php` rather than plugin files or WordPress options:
  - Analytics ingestion endpoint
  - Store ID
  - Shared ingestion secret
  - Synchronization enabled/disabled flag
- [ ] Require a fixed HTTPS endpoint.
- [ ] Sign `timestamp.rawBody` using HMAC-SHA256.
- [ ] Send the timestamp, signature, event ID, content type, and schema version in request headers.
- [ ] Queue delivery through WooCommerce Action Scheduler so order completion is never blocked by the analytics service.
- [ ] Retry temporary failures after 1 minute, 5 minutes, 30 minutes, 2 hours, and 12 hours.
- [ ] Retry connection failures, timeouts, HTTP 429 responses, and HTTP 5xx responses.
- [ ] Treat successful duplicate responses as delivered.
- [ ] Do not retry permanent schema or authentication failures indefinitely.
- [ ] Store only non-sensitive synchronization state on the order:
  - Pending, synchronized, or failed.
  - Last successful event ID.
  - Last attempt time.
  - Last sanitized error code/message.
- [ ] Add analytics synchronization status to the existing wp-admin Attribution block.
- [ ] Bump the plugin version from 1.6.0 to 1.7.0.
- [ ] Document plugin configuration, privacy boundaries, event hooks, and retry behavior.

## 4. Analytics application: secure ingestion endpoint

- [ ] Add `POST /api/woocommerce/attribution` as a Node.js Next.js route handler.
- [ ] Keep the route publicly reachable through middleware but authenticated exclusively by its HMAC signature.
- [ ] Add the matching ingestion secret and permitted store ID to the analytics deployment environment.
- [ ] Require JSON content type and enforce a small maximum request size.
- [ ] Validate the body with a strict Zod schema and reject unknown fields.
- [ ] Validate event IDs, store IDs, ISO timestamps, enums, ISO currency codes, decimal strings, and bounded attribution strings.
- [ ] Verify the HMAC signature using a timing-safe comparison.
- [ ] Reject timestamps outside a five-minute replay window.
- [ ] Return deterministic responses:
  - Success for new events.
  - Success with a duplicate indicator for an already accepted event ID.
  - HTTP 400 for malformed requests.
  - HTTP 401 for invalid authentication.
  - HTTP 409 for invalid state conflicts.
  - HTTP 429 or 5xx only for retryable conditions.
- [ ] Never log request bodies, signatures, secrets, or order references in production error logs.

## 5. Analytics application: persistence and attribution model

- [ ] Add an immutable minimal attribution-event table keyed by event ID.
- [ ] Add a current order-attribution snapshot keyed by store ID and pseudonymous order reference.
- [ ] Store only the fields in the approved event contract; do not copy broad WooCommerce payloads into the dedicated tables.
- [ ] Apply event insertion and snapshot upsert in one database transaction.
- [ ] Ignore an older snapshot when a newer event has already been applied.
- [ ] Add indexes for:
  - Completion and attribution-touch dates.
  - Custom source.
  - Short Link ID.
  - Kit email ID.
  - YouTube ID.
  - Native source and UTM campaign.
  - Current status and currency.
- [ ] Update the Prisma schema and committed database/bootstrap migrations together.
- [ ] Join acquisition snapshots to `shortlinks.short_links` through `cp_slid`.
- [ ] Derive platform using this precedence:
  1. Custom `cp_from`.
  2. Native UTM source.
  3. Native source type.
  4. `unknown`.
- [ ] Derive campaign using this precedence:
  1. Registered short-link campaign.
  2. Kit or YouTube content identifier.
  3. Native UTM campaign.
- [ ] Preserve the raw custom and native dimensions separately for auditing.
- [ ] Use non-bot short-link clicks for traffic and completion-rate metrics.
- [ ] Align forward orders with the captured last-touch timestamp.
- [ ] Treat historical rows without a touch timestamp as completion-date based and expose that limitation in report copy.
- [ ] Update existing short-link order counts from the dedicated attribution snapshots rather than the broad webhook-derived order table.
- [ ] Keep each currency separate; never aggregate unlike currencies into a single revenue number.

## 6. Analytics application: historical backfill

- [ ] Implement the backfill as an analytics-side script, exposed as `pnpm attribution:backfill`; it must not query the live WordPress database or call the live WooCommerce REST API.
- [ ] Use `core.orders`, `core.order_items`, `core.refunds`, `shortlinks.short_links`, and existing `woocommerce.webhook_event` records as the historical source.
- [ ] Add a dry-run mode that reports source coverage, eligible completed orders, missing attribution, unmatched Short Link IDs, currencies, and date bounds without writing data.
- [ ] Backfill completed orders containing at least one custom attribution value.
- [ ] Generate the same stable HMAC order reference and normalized snapshot shape used by forward plugin events.
- [ ] Calculate original order total, refund total, net charged total, and net merchandise revenue from the existing analytics-side order, item, and refund records.
- [ ] Classify registered historical customers as new or returning from existing order chronology; classify guest orders as `unknown` unless a non-PII stable customer key already exists in analytics.
- [ ] Extract available native WooCommerce attribution fields from the latest stored raw order event without copying unrelated payload fields into the dedicated attribution tables.
- [ ] Use completion time as the attribution time when historical `_cp_attributed_at` metadata is unavailable.
- [ ] Detect historical source/Short Link channel conflicts caused by the old stale-`cp_slid` behavior; report and exclude ambiguous rows from campaign-level metrics until reviewed.
- [ ] Write directly through the same transactional ingestion service used by the HTTP endpoint rather than posting events over the public route.
- [ ] Process in bounded, restartable batches and make reruns idempotent.
- [ ] Support optional date boundaries and a small validation limit before the full run.
- [ ] Produce processed, skipped, ambiguous, duplicate, and failed totals without logging customer identifiers.
- [ ] If analytics-side source tables do not cover the required history, stop and report the missing date range. Recover that gap only from an existing database backup/replica or an offline sanitized extract—not by running a bulk query against the live user-facing site.
- [ ] Restore the relevant City Prepping database backup into an isolated temporary MySQL environment when a historical gap exists.
- [ ] Create a versioned SQL extraction for both HPOS and legacy order storage that emits only completed-order attribution, timestamps, statuses, currency, required totals/refunds, and temporary fields needed to calculate new/returning status.
- [ ] Compute new/returning/unknown classification inside the isolated extraction environment, then remove customer IDs, emails, addresses, IP data, transaction IDs, and other PII from the exported artifact.
- [ ] Validate the sanitized export with row counts, date bounds, currency totals, attribution-null counts, duplicate checks, and a checksum before importing it into analytics.
- [ ] Import the sanitized artifact through the analytics-side backfill service, reconcile it, and securely delete the temporary database copy and export file after acceptance.

## 7. Analytics application: Acquisition dashboard

- [ ] Add an authenticated `/acquisition` page and navigation entry.
- [ ] Preserve the established City Prepping dashboard visual language and responsive behavior.
- [ ] Use the application's global date-range filter.
- [ ] Add currency filtering when more than one currency is present.
- [ ] Add KPI cards for:
  - Completed orders.
  - Retained orders.
  - New customers.
  - Refunded orders.
  - Net merchandise revenue.
  - Net charged revenue.
  - Average order value.
- [ ] Define retained orders as completed orders that have not subsequently been fully refunded or cancelled.
- [ ] Keep fully refunded/cancelled-after-completion orders visible as historical conversions while excluding them from retained-order and net-revenue totals.
- [ ] Add a platform comparison showing clicks, orders, retained orders, new-customer share, revenue, AOV, and directional completion rate.
- [ ] Add a campaign/short-link table showing platform, campaign, Short Link ID, content ID, clicks, completed orders, refunds, revenue, and AOV.
- [ ] Add an acquisition time series.
- [ ] Link Short Link IDs to the existing short-link detail pages.
- [ ] Add clear empty, partial-history, unknown-attribution, unmatched-link, and stale-data states.
- [ ] Label completion rate as directional because clicks and orders are aggregate attribution data rather than person-level funnel tracking.

## 8. Privacy-safe CSV export

- [ ] Add a Clerk-protected CSV endpoint for the Acquisition dashboard.
- [ ] Export only aggregate rows matching the active date, currency, platform, and campaign filters.
- [ ] Include the same platform/campaign/link metrics displayed in the dashboard.
- [ ] Do not export event IDs, pseudonymous order references, raw order rows, or customer-level records.
- [ ] Protect text cells against spreadsheet formula injection.
- [ ] Return `Cache-Control: no-store` and a deterministic filename containing the selected date range.
- [ ] Confirm that generated CSV files are streamed to the requester and not retained on the application server.

## 9. Monitoring and future campaign spend

- [ ] Add an Acquisition integration card showing last accepted event time and data freshness.
- [ ] Add safe operational counts for accepted, duplicate, invalid, and failed events without displaying payload contents.
- [ ] Document how to inspect failed Action Scheduler jobs in WooCommerce.
- [ ] Reconcile analytics-side source order counts with dedicated attribution snapshots without querying the live WordPress database.
- [ ] Establish stable platform, campaign, and Short Link keys suitable for future campaign-cost joins.
- [ ] Do not add spend entry, CAC, or ROAS UI in this version.
- [ ] When spend becomes available later, add it as a separate cost fact source rather than embedding it in order events.

## 10. Test plan

### Plugin tests

- [ ] A valid new touch replaces the source and clears a missing/stale Short Link ID.
- [ ] Switching between Kit and YouTube clears the previous source-specific identifier.
- [ ] Attribution cookies and metadata are sanitized and use the expected expiry.
- [ ] Pending, processing, failed, and cancelled-before-completion orders are not transmitted.
- [ ] A completed order queues exactly one current snapshot.
- [ ] Duplicate completion hooks do not create duplicate conversions.
- [ ] Partial refunds, full refunds, and post-completion cancellation queue revised snapshots.
- [ ] New, returning, and unknown classification behaves correctly for registered customers and guests.
- [ ] Both revenue calculations are correct for discounts, tax, shipping, and refunds.
- [ ] The payload contains every allowed field and none of the prohibited PII fields.
- [ ] HMAC order references and request signatures are deterministic and correctly encoded.
- [ ] Retryable and permanent HTTP failures follow the specified retry policy.
- [ ] HPOS and legacy order-storage modes both work.

### Analytics tests

- [ ] Accept a correctly signed event.
- [ ] Reject missing, invalid, or expired signatures.
- [ ] Reject malformed fields and unexpected/PII fields.
- [ ] Accept a repeated event ID without duplicating the snapshot.
- [ ] Prevent an older delivery from overwriting newer state.
- [ ] Apply partial/full refund and cancellation revisions correctly.
- [ ] Apply platform and campaign precedence correctly.
- [ ] Handle unknown attribution and unmatched Short Link IDs.
- [ ] Keep currency totals separate.
- [ ] Calculate dashboard aggregates and filters correctly.
- [ ] Export only aggregate, non-PII CSV fields.
- [ ] Protect CSV values against formula injection.
- [ ] Require Clerk authentication for the dashboard and export.

### End-to-end staging scenarios

- [ ] YouTube short link → checkout → completion → dashboard and CSV.
- [ ] Kit short link → checkout → completion → dashboard and CSV.
- [ ] Native UTM order without custom attribution.
- [ ] Unattributed/direct completed order for forward-data coverage.
- [ ] Repeat delivery without duplication.
- [ ] Partial refund followed by full refund.
- [ ] Analytics endpoint unavailable during completion, followed by successful retry.
- [ ] Historical backfill overlapping with an already synchronized live order.
- [ ] Analytics-side backfill dry run, limited validation run, interruption/resume, and idempotent rerun.
- [ ] Missing historical coverage is reported without falling back to the live WooCommerce database or REST API.
- [ ] A sanitized SQL extract from an isolated backup imports successfully without customer identifiers or duplicate conversions.

## 11. Deployment and rollout

- [ ] Preserve unrelated uncommitted changes currently present in the analytics repository.
- [ ] Apply the analytics database migration first.
- [ ] Deploy the ingestion endpoint before enabling the plugin sender.
- [ ] Deploy the Acquisition dashboard and CSV export.
- [ ] Configure the same shared secret and store ID in Vercel and WordPress.
- [ ] Deploy plugin 1.7.0 with synchronization disabled.
- [ ] Send and validate a signed staging event.
- [ ] Complete a controlled production test order and verify all calculated fields.
- [ ] Enable live synchronization.
- [ ] Run the analytics-side historical backfill dry run and review its coverage/ambiguity report.
- [ ] If coverage is incomplete, restore an existing production backup/read replica and generate the one-time sanitized SQL export outside the live storefront.
- [ ] Run a limited analytics-side validation batch, reconcile it against existing analytics source tables, then run the remaining backfill.
- [ ] Reconcile completed orders and both revenue measures by date and source.
- [ ] Validate campaign joins and investigate unmatched Short Link IDs.
- [ ] Confirm dashboard, CSV, retry logs, and integration freshness indicators.
- [ ] Monitor delivery failures and data freshness during the initial rollout period.

## Rollback

- [ ] Document the synchronization feature flag as the immediate rollback control.
- [ ] If necessary, disable outbound synchronization without removing attribution metadata or queued events.
- [ ] Keep ingestion idempotent so queued live events and analytics-side backfill jobs can be safely resumed.
- [ ] Do not disable the existing broad WooCommerce webhook as part of this feature rollback.

## Explicit boundaries

- The existing broad WooCommerce webhook remains unchanged because current product, membership, subscription, and revenue pipelines depend on it.
- Reducing or deleting PII already retained by that broader pipeline is a separate privacy/data-architecture project.
- Historical backfill uses only data already present on the analytics infrastructure and covers completed orders with existing custom attribution. It must not read from the live WordPress database or WooCommerce REST API.
- If the analytics database lacks part of the historical period, that gap cannot be reconstructed without an existing backup, replica, or offline sanitized extract from the source system.
- Any required manual SQL extraction must run against that isolated backup/replica, not the live `cityprepping.com` database.
- Ongoing plugin synchronization covers every newly completed order so native and direct attribution coverage grows from launch onward.
- Campaign spend integrations, CAC, and ROAS are deferred until spend data is available.
