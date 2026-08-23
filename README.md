# Achievement Rewards Service

A Laravel service for ecommerce achievements, badges, and cashback rewards.

The project currently includes its Docker-based infrastructure foundation, Sanctum authentication, trusted completed-purchase ingestion, purchase-driven achievement unlocking, exact achievement/badge events, badge progression, customer achievement visibility, durable cashback rewards, verified/masked payout-account onboarding, deterministic fake-backed payout execution, a Paystack test-mode adapter, signed Paystack transfer callbacks, first-transition support escalation, and customer cashback visibility. Automatic payout retry/reconciliation remains deferred beyond this assessment.

## Prerequisite

Install Docker Desktop, Docker Engine with the Compose plugin, or an equivalent Docker-compatible runtime. PHP, Composer, Node.js, PostgreSQL, and Redis are not required on the host.

Verify the prerequisite:

```bash
docker --version
docker compose version
```

## First-time setup

Build the development image, install PHP dependencies, create the local environment file, generate the application key, and migrate PostgreSQL:

```bash
docker compose build
docker compose --profile tools run --rm setup
docker compose up -d
```

The application is then available at <http://localhost:8000>. A successful response looks like:

```json
{
  "name": "Achievement Rewards Service",
  "status": "ok"
}
```

## Customer authentication

Laravel Sanctum issues bearer tokens for the JSON API. The public auth routes create and authenticate customer accounts only:

| Method | Route | Purpose |
| --- | --- | --- |
| `POST` | `/api/auth/register` | Register a customer and issue a token |
| `POST` | `/api/auth/login` | Authenticate a customer and issue a token |
| `POST` | `/api/auth/logout` | Revoke the bearer token used for the request |
| `GET` | `/api/me` | Return the authenticated user |

Register a customer:

```bash
curl --request POST http://localhost:8000/api/auth/register \
  --header 'Accept: application/json' \
  --header 'Content-Type: application/json' \
  --data '{
    "name": "Example Customer",
    "email": "customer@example.com",
    "password": "secure-password",
    "password_confirmation": "secure-password",
    "device_name": "local-demo"
  }'
```

Registration returns `201 Created`; login returns `200 OK`. Both use the same root-level response contract (ordinary API Resources do not add a `data` wrapper):

```json
{
  "user": {
    "id": 1,
    "name": "Example Customer",
    "email": "customer@example.com",
    "account_type": "customer"
  },
  "token": "1|ars_...",
  "token_type": "Bearer",
  "abilities": [
    "achievements:read",
    "payout-accounts:write",
    "cashback-rewards:read"
  ]
}
```

Send the `token` value only in the `Authorization` header. It is shown once and the database stores only its SHA-256 hash:

```bash
curl http://localhost:8000/api/me \
  --header 'Accept: application/json' \
  --header 'Authorization: Bearer <token>'
```

Customer tokens receive only these abilities:

- `achievements:read`
- `payout-accounts:write`
- `cashback-rewards:read`

The reserved `purchases:write` ability is never issued by public registration or login. It belongs to trusted system identities provisioned administratively; a repeatable internal demo/setup workflow is a later milestone.

The Sanctum guard is explicitly restricted to the `users` provider. This still accepts both customer and system `User` identities while preventing an unrelated tokenable model from becoming valid accidentally. Logout is bearer-only: it revokes only the persisted personal access token used for the request, returns an empty `204 No Content`, and leaves other tokens valid. For logout, missing, revoked, or session/transient authentication receives `401`.

The auth delivery flow is deliberately narrow:

```text
FormRequest -> immutable input DTO -> one top-level Action -> typed result -> API Resource
```

Controllers only receive, delegate, and respond. The register/login Actions coordinate token issuance; `/api/me` wraps the authenticated `User` directly because it has no application work to perform.

API failures use one compact `application/json` contract. The HTTP status is carried only by the response status line; the body contains a stable machine-readable `code`, a human-readable `message`, and optional validation `errors`. Protocol headers such as `WWW-Authenticate`, `Allow`, and `Retry-After` are preserved.

Each request receives a server-generated request ID in Laravel Context and the `X-Request-ID` response header, but diagnostic identifiers are not repeated in the JSON body. Workflow correlation IDs are durable database metadata on the purchase-to-reward flow and are copied into selected milestone-log context; Laravel does not infer a database correlation ID for every log statement. Clients receive domain identifiers such as purchase references instead.

```json
{
  "code": "validation_failed",
  "message": "One or more fields are invalid.",
  "errors": {
    "email": ["The email field must be a valid email address."]
  }
}
```

Malformed or missing login fields return `422 validation_failed` with field-level `errors`. A syntactically valid login with an unknown email, wrong password, or system identity returns the same `401 invalid_credentials` body without an `errors` object, so clients are not told that the email field itself is invalid and account existence is not disclosed. `WWW-Authenticate: Bearer` is reserved for protected endpoints that actually require an existing bearer token.

The setup command is safe to rerun. It installs the locked dependencies and applies only outstanding migrations.

## Payout account onboarding

An authenticated customer can create or replace their own transfer destination:

```text
PUT /api/me/payout-account
```

The route requires a valid Sanctum bearer token with `payout-accounts:write`. It also requires a `customer` identity and applies the payout-account ownership policy; a `system` identity is rejected even if its token contains that ability. The route never accepts a customer ID, so one customer cannot select another customer's destination. Provider-backed updates are limited to five attempts per minute for each authenticated customer.

The request accepts exactly two string fields. Keeping the account number as a string preserves leading zeros:

```bash
curl --request PUT http://localhost:8000/api/me/payout-account \
  --header 'Accept: application/json' \
  --header 'Content-Type: application/json' \
  --header 'Authorization: Bearer <customer-token>' \
  --data '{
    "account_number": "0000000000",
    "bank_code": "057"
  }'
```

The first verified destination returns `201 Created`; a successful replacement updates the same current row and returns `200 OK`. Both statuses use the same root-level response:

```json
{
  "id": 42,
  "provider": "fake",
  "account_name": "Demo Customer",
  "bank_name": "Demo Bank",
  "bank_code": "057",
  "masked_account_number": "******0000",
  "currency": "NGN",
  "verified_at": "2026-08-22T18:30:00.000000Z"
}
```

`account_name` is canonical provider output, not accepted customer input. The service uses the full account number only while creating the provider recipient, then discards it. The database stores the provider recipient code and last four digits; the API never returns the full number, raw last-four source field, user ID, recipient code, provider payload, or diagnostics.

Replacement is fail-safe. A bounded per-customer Redis lock serializes ordinary competing requests. The new provider recipient is created before a short PostgreSQL transaction locks the customer and replaces the current row. Provider rejection or local rollback leaves the previous verified destination unchanged, while database uniqueness remains the durable one-account rule. `PayoutAccountVerified` is dispatched only after a successful commit and carries only the persisted payout-account model.

The default and CI-safe adapter is selected with:

```dotenv
PAYMENT_DRIVER=fake
FAKE_PAYOUT_ACCOUNT_SCENARIO=success
FAKE_TRANSFER_SCENARIO=success
```

Supported payout-account fake scenarios are `success` and `rejected`. The fake derives a deterministic internal recipient identity without storing the full account number. Both fake and Paystack adapters remain registered. `PAYMENT_DRIVER` selects only the adapter used for a new or replacement payout-account operation; changing the default never reinterprets an existing account or claimed reward. A failed Paystack operation leaves the existing verified destination unchanged and never falls back to fake.

Expected recipient rejection returns sanitized `422 payout_account_rejected`. A recipient identity already owned by another customer returns sanitized `409 payout_account_conflict`, and lock contention returns `409 payout_account_busy`. Provider unavailability, malformed responses, and timeouts map centrally to sanitized `503 payment_provider_unavailable`, `502 payment_provider_invalid_response`, and `504 payment_provider_timeout`. Exceeding the per-customer limit returns the standard `429 rate_limit_exceeded` response. Provider text, account details, secrets, and raw payloads are not copied into these responses.

### Paystack test-mode adapter

The real adapter is deliberately restricted to Paystack test mode. Keep the fake as the default for local demonstrations and automated tests. To select Paystack for a manual sandbox operation, set these only in the local, Git-ignored `.env` file:

```dotenv
PAYMENT_DRIVER=paystack
PAYSTACK_SECRET_KEY=<Paystack test secret>
PAYSTACK_BASE_URL=https://api.paystack.co
CASHBACK_SUPPORT_EMAIL=support@example.test
```

Only a secret whose prefix identifies a test key is accepted; a missing, public, whitespace-padded, malformed, or live key fails before network I/O and cannot authenticate a callback. Paystack uses the same integration secret for API authentication and webhook HMAC—not a separate application-owned webhook secret. Supporting live keys later requires a separately reviewed, mode-aware policy and operational controls; renaming the validation method would not make this service live-ready. Never commit or paste a real secret into documentation, fixtures, logs, screenshots, or issue text. Long-lived Horizon workers must be restarted after changing driver or credential configuration:

```bash
docker compose restart horizon
```

Paystack payout-account onboarding keeps the application contract provider-neutral. Internally, the adapter calls `GET /bank/resolve` with the string account number and bank code, uses the returned canonical name in `POST /transferrecipient`, and returns only the recipient code plus masked bank metadata. The full account number and provider payload are discarded rather than persisted.

Cashback transfers use `POST /transfer` with source `balance`, the snapshotted amount and recipient, and the reward's existing stable reference. Paystack's test guide documents immediate `success`, while the general OTP-disabled API flow may return `pending`; the adapter maps the actual `data.status` instead of inferring payment from HTTP `200` or response text. A timeout, malformed response, contradictory envelope, or unknown provider state stays ambiguous and is not automatically re-posted. `GET /balance` and `GET /transfer/verify/{reference}` are available through the adapter for readiness and later reconciliation, but a balance read is advisory rather than a reservation.

The default and CI path remains credential-free: `phpunit.xml` pins the fake driver, Paystack's official API base URL, and a blank Paystack key, while Laravel HTTP fakes prove the exact Paystack URLs, Bearer header, JSON/query payloads, configured timeout options, synthetic timeout classifications, response mappings, and one-request behavior without contacting Paystack. Paystack test versus live mode is selected by the credential, not by a different base URL.

#### Optional sandbox smoke test

This check is optional and never gates CI or the required reviewer flow. Immediately before running it:

1. Confirm the Paystack Dashboard is in test mode and that the secret belongs to that same integration.
2. Recheck that **Confirm transfers before sending** is unchecked; this is mutable Dashboard state, not an application guarantee.
3. Read the available NGN balance through the Dashboard or `GET /balance`. Ensure it can cover NGN 300 plus Paystack's applicable fee.
4. If needed, prepare test funds outside this service with the Dashboard's test top-up flow or a manually initialized test checkout, verify that test transaction, and read the balance again. Do not add customer collection or automatic funding to this service.
5. Put the test key in local `.env`, select `PAYMENT_DRIVER=paystack`, restart Horizon, and create or replace the customer's destination through `PUT /api/me/payout-account`. Paystack documents Zenith Bank, account `0000000000`, bank code `057`, and NGN for its Nigerian test recipient.
6. Trigger the normal fixed NGN 300 reward flow. Accept the actual `success` or `pending` result and verify the same stored reference; never generate a second reference to make the smoke test pass.
7. If Paystack returns raw `otp`, stop. The service records `otp_required`/`requires_attention` and intentionally calls none of the finalize, resend, enable-OTP, or disable-OTP endpoints. Correct the Dashboard setting before another separately reviewed attempt.
8. Restore `PAYMENT_DRIVER=fake` after the smoke test and restart Horizon.

Disabling per-transfer confirmation increases the impact of a stolen secret because a test transfer no longer pauses for human approval. Backend-only test keys, redacted exception boundaries, and the fake default are therefore part of the safety model. Paystack URL-based Transfers Approval may be useful production hardening, but live activation, real money, that approval protocol, automatic retries, and reconciliation are outside this phase.

### Signed Paystack transfer callbacks

Paystack may complete a transfer after the initiation response, so the service exposes one public server-to-server route:

| Method | Route | Authentication |
| --- | --- | --- |
| `POST` | `/api/webhooks/paystack` | `x-paystack-signature`: HMAC SHA-512 of the exact raw body with the configured Paystack test secret |

The route intentionally has no Sanctum bearer middleware: Paystack is not a customer. It authenticates the exact request bytes before JSON parsing, accepts at most 65,536 bytes, requires a canonical lowercase 128-character hexadecimal signature, and compares it with `hash_equals()`. Missing or invalid signatures return `401 invalid_webhook_signature`; oversized bodies return `413 webhook_payload_too_large`; missing, malformed, non-string, or live secret configuration returns `503 webhook_verification_unavailable`. None of those failures creates a receipt or changes a payout.

Every authentic, non-duplicate delivery is handled synchronously in one short PostgreSQL transaction. The service records a privacy-minimized `provider_webhook_receipts` row with internally assigned Paystack provenance, the SHA-256 hash of the exact body, a safe bounded event label/reference when available, an optional restricted link to the fully matched payout attempt, one final handling result, and `received_at`. It does **not** retain the raw body, signature, provider transfer/recipient code, amount, currency, reason, customer data, request/correlation ID, or generic timestamps.

Receipt results answer how this service handled the delivery, not whether the transfer succeeded:

| Receipt result | Meaning |
| --- | --- |
| `applied` | A supported, fully matched callback changed the payout state. |
| `unchanged` | It matched, but the state transition was already applied, stale, contradictory with durable lifecycle state, or no longer allowed. |
| `invalid` | The authentic JSON, object/type/value shape, or event/status pair was invalid. |
| `unsupported` | The bounded event name was retained, but this service has no transition rule for it. |
| `not_found` | No local reward/attempt exists for the valid reference. |
| `mismatch` | The reference located a candidate, but stored provider, recipient, amount, currency, or known transfer code disagreed. |

Only `transfer.success/success`, `transfer.failed/failed`, and `transfer.reversed/reversed` are supported. Root, `data`, and `recipient` must be JSON objects; amount must be a positive JSON integer; currency and source must be exact `NGN` and `balance`; identity strings must be printable ASCII without edge whitespace. Unknown extra fields are ignored. A reference only locates a candidate: provider, reward/attempt reference, recipient, reward/attempt amount and currency, and any already-known transfer code must still match exactly before the receipt links to an attempt or state changes.

The callback locks the reward first and payout attempt second, matching initiation completion. `started`, `ambiguous`, `pending`, or `otp_required` may become succeeded, failed, or reversed; a succeeded attempt accepts only a later reversal; failed, reversed, and pre-creation conclusions remain unchanged. Success records `paid`; failure/reversal leave the customer owed and set `requires_attention`. The original initiation completion updates only a still-`processing` reward with a still-`started` attempt, so a callback that wins the race cannot be overwritten by a stale HTTP response.

Exact `(provider, body_hash)` redelivery creates no second receipt, transition, log, or alert. Semantically identical JSON with different bytes may create an `unchanged` receipt, while the locked transition remains idempotent. A local transaction failure persists neither receipt nor payout update and returns `500`, allowing Paystack to redeliver the callback; that redelivery is a notification, not another money transfer. Authentic deliveries with a final receipt return empty `200 OK`, including invalid, unsupported, missing, mismatched, and unchanged facts that redelivery cannot repair.

### Payout support escalation

Set a real deployment-only destination with `CASHBACK_SUPPORT_EMAIL`; `.env.example` deliberately uses the non-deliverable `support@example.test`. The first unresolved transition stamps `payout_attempts.support_alert_requested_at` while the attempt is locked, then requests one queued mail notification after commit:

| Attempt fact | Safe issue category | Suggested action |
| --- | --- | --- |
| `insufficient_funds` | `funding_required` | Fund and review before another transfer. |
| `ambiguous` | `status_uncertain` | Verify the existing transfer before considering another. |
| `retryable_rejection` | `temporary_rejection` | Review provider availability before a manual retry decision. |
| `permanent_rejection`, `otp_required`, `failed`, `reversed` | `human_review` | Inspect the stored attempt and resolve the outstanding reward. |

`started`, `pending`, and `succeeded` do not alert. The notification contains only local reward/attempt IDs, category, a service-owned reason, and next action—never account/customer data, provider identifiers/text, request payload, signature, or secret. `support_alert_requested_at` proves intent, not queue acceptance or mailbox delivery. A queue-push failure is reported after the financial state commits and is not made atomic by this MVP. With the local default `MAIL_MAILER=log`, the safe mail is written to the Laravel log rather than delivered; production must configure a real mail transport as well as the support address. Horizon and `failed_jobs` remain the delivery diagnostics.

### Business workflow logs

The service tries to write one small structured log after each important business step finishes. Queued jobs and repeated work can create the same log again or make a later step appear first. An empty achievement or badge list means the check ran but found nothing new; a missing log cannot prove that the check ran. Each new log waits for the outermost database transaction to commit. The payout-account log also waits until the per-user cache lock has been released.

| Message | Level | Fields and meaning |
| --- | --- | --- |
| `purchase.processed` | `info` when created; `debug` for an exact duplicate | `purchase_id`, `user_id`, durable `correlation_id`, and `result` (`created` or `duplicate`) |
| `achievement.evaluation.completed` | `info` | `purchase_id`, `user_id`, durable `correlation_id`, `unlocked_count`, and ordered `unlocked_achievement_names`, including `0` and `[]` when nothing new is unlocked |
| `badge.evaluation.completed` | `info` | `user_id`, the latest user-achievement ID and its durable correlation ID (both `null` when the user has none), current `achievement_count`, ordered new `unlocked_badge_names`, and ordered new `cashback_reward_ids`, including empty lists |
| `payout_account.saved` | `info` | `user_id`, `payout_account_id`, safe provider name, and `result` (`created` or `replaced`); this user-level action does not invent a purchase correlation ID |
| `cashback.payout.processed` | `info` | Safe initiation result, including whether it changed stored state or lost the race to a callback |
| `paystack.webhook.recorded` | Result-dependent `info`, `debug`, or `warning` | One record per newly created receipt; an exact byte redelivery creates no second record |
| `cashback.payout.support_requested` | `warning` | The first committed unresolved-payout support intent, written before its notification queue attempt |

Each workflow log uses a fixed allowlist of local IDs, statuses, safe names/results, safe service errors/categories, and provider HTTP status/latency only where needed. It excludes purchase/provider references, amounts, balances, account details, account/bank/customer names, credentials, raw payloads, signatures, provider error text, complete models, requests, data-transfer objects, and exceptions. Laravel Context supplies the request ID. The saved workflow correlation ID is added only when the business record has one.

These logs help with searching and debugging, but they are not an audit ledger and do not prove that a queued job succeeded. A logging failure cannot reverse saved business data. A conflict, provider failure, or database rollback writes no false success log. Database rows—especially `cashback_rewards` and `payout_attempts`—remain the financial truth. This small feature adds no automatic retry, polling, scheduler, reconciliation worker, dashboard, trace system, or logging framework.

## Purchase-driven achievements

Only completed purchases are stored. The service deliberately has no pending/failed purchase states and no product, cart, inventory, or checkout model: a trusted upstream checkout system sends a completed fact after its own payment flow succeeds. Purchases are currently restricted to NGN and monetary values use integer minor units (kobo), never floating point.

Two active progressions are seeded idempotently:

| Group | Achievement thresholds |
| --- | --- |
| Purchase count | First Purchase (1), 3 Purchases (3), 5 Purchases (5), 10 Purchases (10), 25 Purchases (25) |
| Lifetime NGN spend | NGN 5,000 (500,000 kobo), NGN 10,000 (1,000,000), NGN 25,000 (2,500,000), NGN 50,000 (5,000,000), NGN 100,000 (10,000,000) |

Four badge definitions are also seeded: Beginner at 1 achievement, Intermediate at 4, Advanced at 8, and Master at 10. Every newly crossed active badge is awarded in rank order and creates one durable cashback entitlement. The cashback rule is version controlled in `config/rewards.php` as `30000` kobo in NGN.

### Customer achievement progress

The required progress route is declared in `routes/web.php` and intentionally has no `/api` prefix:

```text
GET /users/{user}/achievements
```

Use a customer Sanctum bearer token containing `achievements:read`. The `{user}` value must be that customer's own ID. Other customers and system accounts receive `403` even when their token has the ability.

```bash
curl http://localhost:8000/users/42/achievements \
  --header 'Accept: application/json' \
  --header 'Authorization: Bearer <customer-token>'
```

The successful response has exactly five top-level fields and no `data` wrapper:

```json
{
  "unlocked_achievements": [
    "First Purchase",
    "NGN 5,000 Spent"
  ],
  "next_available_achievements": [
    "3 Purchases",
    "NGN 10,000 Spent"
  ],
  "current_badge": "Beginner",
  "next_badge": "Intermediate",
  "remaining_to_unlock_next_badge": 2
}
```

Unlocked names come from saved unlock records and use this order: group `sort_order`, group `code`, achievement `sort_order`, then achievement `code`. For each active unfinished group, the next list shows only the first active achievement the user has not earned. Inactive groups and unearned inactive achievements are omitted from next steps. Previously earned achievements and badges remain visible even if their definitions are later deactivated.

`current_badge` is the highest badge actually awarded, not one guessed from the achievement count. `next_badge` is the lowest active rank above that saved badge, or the first active badge when current is `null`. Before the first award, current is `null`; after Master, next is `null` and remaining is `0`. If achievements are saved before the queued badge job catches up, the API uses `max(0, next badge requirement - earned achievement count)`. The remaining count therefore never goes below zero, and the API never claims an unsaved badge.

After authentication and user lookup, the progress Action runs four database queries. Adding groups or achievements does not add queries, although each query can process more rows. The Action never changes achievement, badge, or reward data, queues jobs, or uses a cache.

### Trusted ingestion contract

`POST /api/internal/purchases` requires all of the following:

- a valid Sanctum bearer token;
- the reserved `purchases:write` token ability;
- an authenticated `system` account rather than a customer account; and
- the per-system-identity limit of 120 requests per minute.

System identities and their narrowly scoped tokens are provisioned administratively; public registration and login never issue `purchases:write`.

```bash
curl --request POST http://localhost:8000/api/internal/purchases \
  --header 'Accept: application/json' \
  --header 'Content-Type: application/json' \
  --header 'Authorization: Bearer <system-token>' \
  --data '{
    "user_id": 42,
    "external_reference": "ORDER-10042",
    "amount_minor": 2500000,
    "currency": "NGN",
    "completed_at": "2026-08-21T14:30:00Z"
  }'
```

A new purchase returns `201 Created`:

```json
{
  "purchase": {
    "id": 73,
    "user_id": 42,
    "external_reference": "ORDER-10042",
    "amount_minor": 2500000,
    "currency": "NGN",
    "completed_at": "2026-08-21T14:30:00.000000Z"
  },
  "was_duplicate": false
}
```

The external reference is the idempotency key. An identical replay returns the same purchase with `200 OK` and `was_duplicate: true`; it creates no second event or unlock activity. Reusing the reference with a different customer, amount, or completion time returns `409 purchase_reference_conflict`. The internal workflow correlation ID is persisted but not exposed in the API response.

### Asynchronous progression flow

```text
trusted POST
    -> validate and normalize input
    -> transactionally insert one completed purchase
    -> dispatch PurchaseCompleted after commit
    -> queued listener, serialized per user by a short Redis lock
    -> PostgreSQL transaction locks that user's row
    -> purchase-count and lifetime-NGN-spend calculators run
    -> every newly crossed active threshold is inserted in order
    -> dispatch one AchievementUnlocked after commit per new achievement
    -> queued badge listener, serialized by a separate per-user Redis lock
    -> PostgreSQL transaction locks that user's row and counts durable unlocks
    -> every newly crossed active badge is persisted in rank order
    -> create one NGN 300 cashback reward in the same badge transaction
    -> dispatch one BadgeUnlocked after commit per new badge
    -> queued wake-up listener discovers all actionable rewards for that user
    -> queue one unique job carrying only each cashback reward ID
    -> transactionally snapshot provider, destination, money, and reference in an attempt
    -> call the snapshotted fake or Paystack transfer gateway after the claim transaction commits
    -> conditionally persist the factual attempt result and customer-visible reward state
```

The evaluator may unlock several achievement and badge thresholds for one large purchase. Separate achievement and badge Redis locks prevent each queued stage from overlapping with another delivery of the same stage without blocking the downstream job created by the upstream listener. PostgreSQL user-row locks serialize durable progression, while unique `(user_id, achievement_id)`, `(user_id, badge_id)`, and `user_badge_id` reward constraints remain the final duplicate defenses. Redelivered events remain safe.

The assessment-facing event contracts are deliberately minimal and exact:

```text
AchievementUnlocked(achievement_name: string, user: User)
BadgeUnlocked(badge_name: string, user: User)
```

Both events implement after-commit dispatch. A transaction rollback therefore removes the new history/reward rows and emits no event. Existing unlocks return as idempotent no-ops and do not emit a duplicate event.

### Durable cashback entitlement and payout execution

`cashback_rewards` records that the business owes one configured reward for one awarded badge. The row snapshots `amount_minor` and `currency`, carries the purchase workflow correlation ID, and receives a stable lowercase provider reference that later payment attempts must reuse. New rewards start in `awaiting_payout_account`; badge and verified-account wake-ups now discover when they are actionable.

Creating the reward is not the same as paying it. `BadgeUnlocked` and `PayoutAccountVerified` are wake-up signals: their queued listeners re-query all unattempted rewards that the event user can now receive and dispatch one unique job per reward ID. The processor remains the correctness boundary. In a short PostgreSQL transaction it locks and claims the reward, copies the current verified account's provider and destination into a durable `payout_attempts` snapshot, and commits before calling the gateway. A second conditional transaction records the result without letting an older response overwrite newer durable state.

The provider and destination become sticky on first claim. Changing `PAYMENT_DRIVER`, replacing the customer's account, or changing the fake scenario later cannot redirect an existing attempt. The reward's stable reference is reused by both providers; the fake stores accepted effects atomically in Redis, while Paystack receives the same reference for initiation and verification. Queue uniqueness and overlap locks reduce repeated work, while the PostgreSQL claim and attempt uniqueness are the durable defenses.

The four server-controlled fake transfer scenarios map as follows:

| `FAKE_TRANSFER_SCENARIO` | Attempt fact | Reward state | Fake effect created |
| --- | --- | --- | --- |
| `success` | `succeeded` | `paid` | yes |
| `pending` | `pending` | `pending` | yes |
| `insufficient_funds` | `insufficient_funds` | `awaiting_funds` | no |
| `permanent_failure` | `permanent_rejection` | `requires_attention` | no |

A created fake effect deliberately has no TTL, so a later scenario change cannot erase or replace its transfer identity. The insufficient-funds outcome records its observed zero balance, but the processor does not mistake an advisory balance read for a reservation. The signed Paystack callback above may finalize a matching real-adapter attempt, and the first unresolved result requests support. There is still no automatic retry or scheduled reconciliation: `pending`, `processing`, `awaiting_funds`, and `requires_attention` remain durable operational facts.

After deploying these listeners, run the bounded activation scan once so eligible rewards created before deployment are not left dormant:

```bash
docker compose run --rm app php artisan cashback:dispatch-actionable
```

The command only discovers candidates and dispatches the same reward-ID jobs; it never calls a provider itself and is safe to rerun. The reported number is the number of actionable candidates requested, while queue uniqueness may suppress an already queued duplicate. Only the four documented `FAKE_TRANSFER_SCENARIO` values are accepted; an unsupported value fails before a reward is claimed. Changing the server-side scenario requires the long-lived Horizon workers to reload configuration:

```bash
docker compose restart horizon
```

There is no public endpoint for selecting a fake result.

### Customer cashback rewards

An authenticated customer can inspect only their own rewards:

```bash
curl 'http://localhost:8000/api/me/cashback-rewards?page=1' \
  --header 'Accept: application/json' \
  --header 'Authorization: Bearer <customer-token>'
```

The route requires `cashback-rewards:read` and a customer identity. It returns a fixed 20-item page ordered by newest creation time and then ID:

```json
{
  "data": [
    {
      "id": 81,
      "badge_name": "Beginner",
      "amount_minor": 30000,
      "currency": "NGN",
      "status": "paid",
      "created_at": "2026-08-23T01:10:00.000000Z",
      "updated_at": "2026-08-23T01:10:01.000000Z",
      "paid_at": "2026-08-23T01:10:01.000000Z"
    }
  ],
  "links": {
    "first": "http://localhost:8000/api/me/cashback-rewards?page=1",
    "last": "http://localhost:8000/api/me/cashback-rewards?page=1",
    "prev": null,
    "next": null
  },
  "meta": {
    "current_page": 1,
    "per_page": 20,
    "last_page": 1,
    "total": 1
  }
}
```

Provider ownership, stable references, recipient codes, attempt rows, balance observations, and diagnostics are intentionally absent from this customer response.

Achievement and badge unlocks are permanent in the current scope. Refund ingestion, revocation, cashback clawbacks, and automatic payout recovery are not part of this milestone.

## Daily operation

Start the existing environment:

```bash
docker compose up -d
```

Inspect service health:

```bash
docker compose ps
docker compose exec nginx wget --quiet --spider http://127.0.0.1/up
```

Stop the services while retaining PostgreSQL and Redis volumes. The local `vendor/` directory also remains available for the editor:

```bash
docker compose down
```

To change the host HTTP port, set `APP_PORT` for the Compose command:

```bash
APP_PORT=8080 docker compose up -d
```

## Database tools

PostgreSQL is available to database clients on the host loopback interface only. For TablePlus or another database client, use:

| Setting | Value |
| --- | --- |
| Host | `127.0.0.1` |
| Port | `5433` |
| User | `achievement_rewards` |
| Password | `local_password` |
| Database | `achievement_rewards` |
| SSL | disabled |

Laravel containers continue to connect through `postgres:5432` on the private Compose network. The host port exists only for local development tools and is not reachable through the Mac's LAN address.

If host port `5433` is already occupied, change `POSTGRES_HOST_PORT` in `.env` and recreate the PostgreSQL container:

```bash
docker compose up -d --force-recreate postgres
```

## Tests and quality

All project commands run inside the development image:

```bash
docker compose run --rm app composer test
docker compose run --rm app composer test:coverage
docker compose run --rm app composer lint
docker compose run --rm app composer analyse
docker compose run --rm app composer audit
docker compose run --rm app composer quality
```

`composer quality` validates `composer.json`, checks formatting, runs Larastan at level 10, enforces the 90% line-coverage floor, and audits locked dependencies.

The automated suite never needs a Paystack account, real credential, funded balance, mailbox, or network connection. Paystack API coverage uses `Http::fake()`; callback coverage signs synthetic exact bodies with a reserved test-only fixture secret, and notification coverage fakes or intentionally fails the dispatcher. The optional credentialed sandbox procedure above is separate manual evidence.

Use the mutating formatter only when intentionally fixing style:

```bash
docker compose run --rm app composer lint:fix
```

Inspect and verify the achievement-to-reward-entitlement wiring:

```bash
docker compose run --rm app php artisan route:list --path=api/internal/purchases
docker compose run --rm app php artisan route:list --path=users -v
docker compose run --rm app php artisan route:list --path=api/me/payout-account
docker compose run --rm app php artisan route:list --path=api/me/cashback-rewards
docker compose run --rm app php artisan route:list --path=api/webhooks/paystack
docker compose run --rm app php artisan help cashback:dispatch-actionable
docker compose run --rm app php artisan event:list
docker compose run --rm app php artisan test tests/Feature/Purchases tests/Feature/Achievements tests/Feature/Badges tests/Feature/Cashback tests/Feature/Payouts tests/Feature/Payments tests/Feature/Webhooks tests/Feature/Concurrency
docker compose run --rm app composer quality
```

For a disposable local database, the following command proves that the schema and seeders work from an empty state and remain safe to rerun. It deletes all data in the configured local database:

```bash
docker compose run --rm app php artisan migrate:fresh --seed --force
docker compose run --rm app php artisan db:seed --force
```

## Services

| Service | Responsibility |
| --- | --- |
| `nginx` | HTTP entry point on port 8000 |
| `app` | PHP 8.4 FPM application runtime |
| `postgres` | PostgreSQL 17 source of truth |
| `redis` | Queues, cache, locks, and Horizon state |
| `horizon` | Redis queue workers |
| `scheduler` | Laravel scheduled tasks |
| `setup` | Explicit, one-time/repeatable setup command |

The app, Horizon, scheduler, tests, and quality tools use the same development image. The Dockerfile also provides a separate production target without development dependencies or Xdebug and with OPcache enabled.

## Useful diagnostics

```bash
docker compose logs -f nginx app
docker compose logs -f horizon scheduler
docker compose exec app php artisan about
docker compose exec app php artisan migrate:status
```

## Resetting local infrastructure

This removes the local PostgreSQL and Redis volumes. It is destructive to local development data, but it does not delete the Git-ignored `vendor/` directory:

```bash
docker compose down --volumes
```

Run the first-time setup commands again afterward.

## Security notes

- `.env` and production environment files are excluded from Git and the Docker build context.
- The credentials in `.env.example` are local-development defaults only.
- Production secrets must be injected by the deployment environment.
- Full payout account numbers are accepted only over the protected request boundary for recipient creation and are never persisted or returned.
- Paystack integration accepts test keys only; the fake remains the default and no live-money path is supported.
- Paystack callback authentication uses the same test integration secret over exact raw bytes. The receipt retains only a bounded event/reference plus local handling metadata, and structured logs retain only their documented safe allowlists; neither they nor support mail retain secrets, signatures, raw payloads, provider reason text, or account/customer data.
- The production image runs application processes as the unprivileged `app` user.
- Horizon is available locally and denied by default in non-local environments until an explicit authorization rule is added with authentication.
