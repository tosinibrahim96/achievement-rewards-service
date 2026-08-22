# Achievement Rewards Service

A Laravel service for ecommerce achievements, badges, and cashback rewards.

The project currently includes its Docker-based infrastructure foundation, Sanctum authentication, trusted completed-purchase ingestion, purchase-driven achievement unlocking, exact achievement/badge events, badge progression, durable cashback reward entitlements, and verified/masked payout-account onboarding through a deterministic fake provider. Cashback payment execution, the real Paystack adapter, reliability/recovery, and the customer achievement endpoint remain later milestones.

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

Each request receives a server-generated request ID for logs and the `X-Request-ID` response header, but diagnostic identifiers are not repeated in the JSON body. Workflow correlation IDs remain internal log/context metadata; clients receive domain identifiers such as purchase references instead.

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
```

Supported payout-account fake scenarios are `success` and `rejected`. The fake derives a deterministic internal recipient identity without storing the full account number. Setting `PAYMENT_DRIVER=paystack` before the later Paystack adapter exists fails safely; it never falls back to fake or reinterprets an existing stored account. Changing the default alone changes no persisted destination—only a successful replacement stores a new provider.

Expected recipient rejection returns sanitized `422 payout_account_rejected`. A recipient identity already owned by another customer returns sanitized `409 payout_account_conflict`, and lock contention returns `409 payout_account_busy`. Provider unavailability, malformed responses, and timeouts map centrally to sanitized `503 payment_provider_unavailable`, `502 payment_provider_invalid_response`, and `504 payment_provider_timeout`. Exceeding the per-customer limit returns the standard `429 rate_limit_exceeded` response. Provider text, account details, secrets, and raw payloads are not copied into these responses.

## Purchase-driven achievements

Only completed purchases are stored. The service deliberately has no pending/failed purchase states and no product, cart, inventory, or checkout model: a trusted upstream checkout system sends a completed fact after its own payment flow succeeds. Purchases are currently restricted to NGN and monetary values use integer minor units (kobo), never floating point.

Two active progressions are seeded idempotently:

| Group | Achievement thresholds |
| --- | --- |
| Purchase count | First Purchase (1), 3 Purchases (3), 5 Purchases (5), 10 Purchases (10), 25 Purchases (25) |
| Lifetime NGN spend | NGN 5,000 (500,000 kobo), NGN 10,000 (1,000,000), NGN 25,000 (2,500,000), NGN 50,000 (5,000,000), NGN 100,000 (10,000,000) |

Four badge definitions are also seeded: Beginner at 1 achievement, Intermediate at 4, Advanced at 8, and Master at 10. Every newly crossed active badge is awarded in rank order and creates one durable cashback entitlement. The cashback rule is version controlled in `config/rewards.php` as `30000` kobo in NGN.

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
```

The evaluator may unlock several achievement and badge thresholds for one large purchase. Separate achievement and badge Redis locks prevent each queued stage from overlapping with another delivery of the same stage without blocking the downstream job created by the upstream listener. PostgreSQL user-row locks serialize durable progression, while unique `(user_id, achievement_id)`, `(user_id, badge_id)`, and `user_badge_id` reward constraints remain the final duplicate defenses. Redelivered events remain safe.

The assessment-facing event contracts are deliberately minimal and exact:

```text
AchievementUnlocked(achievement_name: string, user: User)
BadgeUnlocked(badge_name: string, user: User)
```

Both events implement after-commit dispatch. A transaction rollback therefore removes the new history/reward rows and emits no event. Existing unlocks return as idempotent no-ops and do not emit a duplicate event.

### Durable cashback entitlement

`cashback_rewards` records that the business owes one configured reward for one awarded badge. The row snapshots `amount_minor` and `currency`, carries the purchase workflow correlation ID, and receives a stable lowercase provider reference that later payment attempts must reuse. New rewards start in `awaiting_payout_account`. Customers can now register a verified destination, while the listener that discovers waiting rewards and starts payout execution belongs to the next milestone.

Creating the reward is not the same as paying it. This phase adds only the transfer-recipient gateway used during onboarding; it intentionally adds no payment job, cashback-transfer gateway, Paystack request, webhook, or reconciliation process. Those external and retryable effects will consume the already durable obligation in a later phase.

Achievement and badge unlocks are permanent in the current scope. Refund ingestion, revocation, payout execution, and clawbacks are not part of this milestone.

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

Use the mutating formatter only when intentionally fixing style:

```bash
docker compose run --rm app composer lint:fix
```

Inspect and verify the achievement-to-reward-entitlement wiring:

```bash
docker compose run --rm app php artisan route:list --path=api/internal/purchases
docker compose run --rm app php artisan route:list --path=api/me/payout-account
docker compose run --rm app php artisan event:list
docker compose run --rm app php artisan test tests/Feature/Purchases tests/Feature/Achievements tests/Feature/Badges tests/Feature/Cashback tests/Feature/Payouts tests/Feature/Concurrency
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
- The production image runs application processes as the unprivileged `app` user.
- Horizon is available locally and denied by default in non-local environments until an explicit authorization rule is added with authentication.
