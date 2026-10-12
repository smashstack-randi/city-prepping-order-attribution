# Guide submission milestones — plugin staging implementation

Version 1.8.0 adds a default-off Elementor capture path and private WordPress
outbox. Guide requests are intermediate milestones in purchase-ending customer
journeys. Events have guide/person/time context only: no attribution cookies,
first/latest snapshots, campaign dimensions, or confirmed-Kit-subscription claim.

## Build locations

| Component | Location |
| --- | --- |
| Flag, approved saved form identities, guide milestone capture | `includes/class-cp-guide-submission.php` in this plugin |
| Encrypted queue, delivery signatures, retries and retention | `includes/class-cp-guide-outbox.php` in this plugin |
| Endpoint authentication, nonce replay prevention, event idempotency, email HMAC/person resolution, private storage, paid-order joins and reporting | Analytics; implementation pending |
| Purchase and first/latest/conversion attribution | Existing WooCommerce/plugin and Analytics pipelines |
| Enrollment, consent, membership and confirmation | Existing native Elementor Kit action and Kit lifecycle ingestion |

## Configuration (server only)

Set transport constants in deployment configuration. No credential is rendered
in a form or included in JavaScript.

Since v1.9.0, **Settings → Order Attribution → Guide capture mode** controls
capture and delivery. It is the sole activation control; legacy
`CP_GUIDE_CAPTURE_ENABLED` constants are ignored.

- **Disabled** (default): no new captures or delivery; retention cleanup continues.
- **Test emails only**: only valid addresses at exactly `cityprepping.com` create
  events. Case-insensitive domain matching excludes subdomains and lookalikes.
  Other visitors retain normal form and Kit behavior without new outbox rows.
- **All approved form submissions**: normal capture on the server-approved forms.

Test-only events carry `is_test_capture: true` and are excluded from Analytics
guide-journey joins. This does not globally classify every company email as a
test person. For a test purchase that must also be excluded from revenue/orders,
use the existing `randi+<test>@cityprepping.com` convention. Ordinary company
addresses may still have independent Kit signup and purchase facts.

Switching All to Test-only also pauses delivery of existing ordinary queued
events; their retention clock still runs. Switching back to All resumes eligible
rows. Events captured in Test-only remain marked as tests after any mode change.

Since v1.8.1, set the failure policy under **Settings → Order Attribution → Guide
capture failure policy**. The default is **Continue normal form submission**.
The alternative shows a retryable error before native Kit enrollment if outbox
persistence fails. This setting does not enable guide capture. The saved admin
setting is the sole policy source; legacy wp-config.php policy constants are ignored.

```php
define('CP_GUIDE_EVENT_SCOPE', 'staging');
define('CP_GUIDE_ANALYTICS_URL', 'https://YOUR-ANALYTICS-HOST/api/wordpress/guide-submissions');
define('CP_GUIDE_ANALYTICS_SIGNING_KEY', 'REPLACE-WITH-A-DEDICATED-SECRET-OF-AT-LEAST-32-CHARACTERS');
```

The URL must be public HTTPS without query or fragment. Verify Analytics has
deployed the endpoint and test-marker support before selecting Test-only. Invalid
delivery configuration pauses sending; queued payloads still expire on schedule.
Turning capture off pauses new events and network delivery, while existing cron
continues retention cleanup. WordPress cron needs regular site traffic or a
system cron runner; monitor queue age before public activation.

The default map allows post `67820`, widgets `4bec153` and `ef38ef5`, required
email field `email`, and form name `Get started guide`, for
`getting-started-guide` / Kit form `7486364`. `CP_GUIDE_FORM_MAP` may replace this
array in trusted server configuration; `CP_GUIDE_FORM_MAP_VERSION` versions it.
Apartment Guide is not allowlisted until its installed form identity is verified.
The plugin checks saved form identity and required field definition in addition
to processed field type and valid email. Browser fields never select the guide.

## Event and transport contract

Events use schema version 1: `signup_event_id` (server UUID), `source_system`
(`wordpress`), `scope` (`local`, `development`, `staging`, or `production`),
UTC `submitted_at`, `event_type` (`guide_submission`), `form` (`post_id`,
`widget_id`, `form_key`, `guide_key`, `kit_form_id`, `map_version`), and transient
`email`, and boolean `is_test_capture`. Analytics must normalize and HMAC the email without retaining it in
identity/reporting tables or ordinary logs, and validate the form/catalog/scope.

The outbox is persisted before any network call. Delivery runs only from cron,
never the form hook, and never invokes Elementor or Kit again. Attempts retain
the logical UUID/body and use a fresh nonce and timestamp. Request headers:

- `X-CP-Guide-Version: 1`
- `X-CP-Guide-Timestamp`: Unix seconds
- `X-CP-Guide-Nonce`: per-attempt UUID
- `X-CP-Guide-Signature`: lowercase hex HMAC-SHA256 using the dedicated key

Canonical signature input (UTF-8; newline separators, no final newline):

```text
cp-guide-submission-v1
TIMESTAMP
NONCE
LOWERCASE_HEX_SHA256_OF_EXACT_REQUEST_BODY
```

Analytics must compare signatures in constant time, enforce a bounded timestamp
window, prevent nonce replays, and enforce event UUID idempotency. A successful
response must be 2xx JSON with matching `signup_event_id` and `status` equal to
`accepted` or `duplicate`; any other response is retried within bounds. Use a
dedicated signing key distinct from campaign signing and identity HMAC secrets.

## Queue, privacy and recovery

The private table is `{$wpdb->prefix}cp_guide_outbox`, created only when capture
is enabled. Schema v2 adds a non-PII `is_test_capture` column to restrict worker
selection; the encrypted event also carries the immutable marker. Whole event payloads are AES-256-GCM encrypted with a domain-separated
key derived from WordPress auth salts. Operators and database administrators can
access this operational table; it is not an Analytics reporting table. No
plaintext email is stored in its columns. WordPress salt rotation invalidates
pending ciphertext, so drain the queue before rotating salts.

Workers claim rows atomically with a two-minute lease and token. Retries start
at 60 seconds, double up to six hours, and stop at ten attempts or seven days.
Success, exhausted attempts, corrupt payloads and expiry scrub ciphertext.
Only safe error categories are retained; HTTP bodies and exception text are
never copied to diagnostics. Metadata is removed 30 days after payload expiry.
Expired/failed rows cannot replay after ciphertext is removed. A lost response
retries the same UUID; Analytics must return idempotent success after committing.

Storage failures increment only `cp_guide_capture_failure_count`. Under
`retry_error`, `Ajax_Handler::send()` is required because Elementor does not
recheck errors immediately after `actions_before`; it exits before native
actions. Choose and verify that policy before activating the integration.

## Validation and activation gates

```sh
php tests/signature-verification.php
php tests/attribution-state.php
php tests/order-attribution.php
php tests/rejected-attribution.php
php tests/guide-submission.php
php tests/guide-submission.php retry_error
php tests/guide-policy-settings.php
php tests/guide-outbox.php
php tests/guide-feature-off.php
```

On 2026-10-12, all seven scripts (including both persistence-failure policies)
passed in the local PHP 8.2 container, along with syntax checks for every runtime
and test PHP file. Existing purchase attribution regressions also passed.

Contract tests do not establish actual WordPress table creation, concurrent DB
behavior, cron scheduling, Turnstile enforcement, or real Kit enrollment.
Before enabling: complete Analytics ingestion, approve isolated scope/test
identity and Kit delivery behavior, choose persistence-failure policy, verify
Turnstile validation precedes capture, exercise real staging schema/cron/retry
and rollback, and pass purchase regressions. Production activation remains a
separate approval. This repository change does not enable capture or migrate DBs.
