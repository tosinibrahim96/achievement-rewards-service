# Achievement Rewards Service

[![Quality](https://github.com/tosinibrahim96/achievement-rewards-service/actions/workflows/quality.yml/badge.svg)](https://github.com/tosinibrahim96/achievement-rewards-service/actions/workflows/quality.yml)
![Coverage gate: at least 90%](https://img.shields.io/badge/coverage%20gate-%E2%89%A590%25-brightgreen)

A Laravel service that turns completed purchases into achievements, badges, and durable NGN cashback rewards. It includes a real Paystack test-mode adapter, but the complete reviewer journey uses the deterministic Fake provider: no Paystack account, SMTP server, external mailbox, or live money is required.

The core story is:

```text
trusted system records a purchase
  -> Horizon evaluates achievements
  -> Horizon evaluates badges
  -> a badge creates an NGN 300 cashback reward
  -> the reward waits for a payout account or queues a payout
  -> Fake or Paystack records one durable payout row and state
```

## Table of contents

- [Start from a clean clone](#start-from-a-clean-clone)
- [Run the guided demo in Scalar](#run-the-guided-demo-in-scalar)
- [Try another seeded scenario](#try-another-seeded-scenario)
- [Configure Fake payments and logged mail](#configure-fake-payments-and-logged-mail)
- [Review the API contract and authorization](#review-the-api-contract-and-authorization)
- [Run tests and quality checks](#run-tests-and-quality-checks)
- [Add an achievement and a badge](#add-an-achievement-and-a-badge)
- [Understand the design and limits](#understand-the-design-and-limits)
- [Troubleshoot, inspect, or reset](#troubleshoot-inspect-or-reset)

## Start from a clean clone

### 1. Check the prerequisites

You need Git, Docker with the Compose plugin, and a browser that can reach jsDelivr to load Scalar's pinned JavaScript bundle. On Windows, use a WSL shell with Docker Desktop integration for these copy-paste commands. You do not need host PHP, Composer, PostgreSQL, or Redis.

```bash
git --version
docker --version
docker compose version
docker info
```

If `docker info` cannot reach the daemon, start Docker Desktop or the Docker service before continuing.

### 2. Clone and build

```bash
git clone https://github.com/tosinibrahim96/achievement-rewards-service.git
cd achievement-rewards-service
docker compose build
```

Run every later Docker Compose command from this repository directory. If a new terminal opens in `~` or another directory, `cd` back here first; otherwise Compose cannot find `compose.yaml`.

### 3. Install, configure, migrate, and seed

```bash
docker compose --profile tools run --rm setup
docker compose up -d
```

The setup container:

- copies `.env.example` to an uncommitted `.env` when needed;
- records your host user and group IDs for writable bind-mounted files;
- installs the exact dependencies from `composer.lock`;
- generates `APP_KEY` when it is empty;
- clears stale Laravel caches; and
- runs outstanding migrations and the idempotent seeders.

The default environment is already the safe reviewer configuration:

```dotenv
APP_ENV=local
QUEUE_CONNECTION=redis
PAYMENT_DRIVER=fake
FAKE_PAYOUT_ACCOUNT_SCENARIO=success
FAKE_TRANSFER_SCENARIO=success
MAIL_MAILER=log
CASHBACK_SUPPORT_EMAIL=support@example.test
```

Do not add a Paystack key or mail credential for the guided demo.

### 4. Confirm the stack

```bash
docker compose ps
```

The application, Nginx, PostgreSQL, Redis, Horizon, and scheduler should be running. The first two requests in the Scalar journey confirm the public service and health responses.

## Run the guided demo in Scalar

Scalar is both the interactive API client and the rendered reference for this repository's single [OpenAPI 3.1 contract](openapi.yaml). Open <http://localhost:8000/docs>; every request below is sent to the running application and its formatted status, headers, and JSON response stay visible beside the operation.

The documented setup uses Laravel's `local` environment, where Scalar is enabled. Its interface loads the exact pinned browser bundle from jsDelivr, so the browser needs internet access when opening the page. The application, OpenAPI contract, and automated checks run locally and do not depend on that CDN.

This journey starts with a customer who has no purchases and no payout account. One purchase earns `First Purchase`, the Beginner badge, an NGN 300 reward, and a logged customer notification. Adding a Fake payout account then pays that same reward.

Setup seeds nine customers and exactly one system identity, `demo.purchase-system@example.test`. All demo identities use the local/testing-only password `password`. No bearer token is seeded: both identities must prove their credentials through the documented login endpoints.

### 1. Open two independent Scalar tabs

Open <http://localhost:8000/docs> in two fresh browser tabs and keep both open. Avoid the browser's **Duplicate Tab** command because browsers differ in what page state they clone; opening the URL twice removes that ambiguity.

| Tab | Identity | Purpose |
| --- | --- | --- |
| **SYSTEM** | `demo.purchase-system@example.test` | Authenticate with `purchases:write` and record purchases |
| **CUSTOMER** | `demo.fresh@example.test` | Read progress and rewards, then save a payout account |

Scalar is deliberately configured with `persistAuth: false`. Each Scalar page keeps its bearer token only in that tab's in-memory store, so the customer and system tokens remain separate. Reloading clears only Scalar's local copy; it does not revoke the server-side Sanctum token. Keep both tabs open through the logout step.

For each named operation, open it, choose **Test Request**, enter the values shown here, and send the request. Start with **Show the service identity** and **Check application health** in either tab. Expect `200` with `status: ok` and `status: up`, respectively.

To see the asynchronous workflow and logged email beside the browser journey, optionally keep this command running in a terminal:

```bash
docker compose exec app php artisan pail -vv
```

Stop it later with `Ctrl+C`. Because `MAIL_MAILER=log`, Laravel writes the rendered recipient, subject, and message here; nothing is sent to an inbox.

### 2. Authorize the SYSTEM tab

In the SYSTEM tab, run **Log in a system identity** with:

```json
{
  "email": "demo.purchase-system@example.test",
  "password": "password",
  "device_name": "Scalar system demo"
}
```

Expect `200`. The response identifies a `system` account and contains exactly one ability, `purchases:write`. Copy the generated `token`, open Scalar's authentication panel, select `bearerAuth`, and paste only the token value. Scalar adds the `Bearer` scheme.

Customer login cannot authenticate this identity. The system-login request cannot select an account type or request more abilities.

### 3. Authorize the CUSTOMER tab

In the CUSTOMER tab, run **Log in a customer** with:

```json
{
  "email": "demo.fresh@example.test",
  "password": "password",
  "device_name": "Scalar customer demo"
}
```

Expect `200`. Copy the returned `user.id` for later path and request fields; do not assume that a displayed example ID matches your database. The token contains exactly:

- `achievements:read`;
- `payout-accounts:write`; and
- `cashback-rewards:read`.

Copy the generated `token` into this tab's `bearerAuth` field, again without typing `Bearer` yourself.

### 4. Prove that the tabs use different identities

Run **Show the authenticated identity** in both tabs:

- CUSTOMER must return `demo.fresh@example.test` with `account_type: customer`.
- SYSTEM must return `demo.purchase-system@example.test` with `account_type: system`.

This quick check prevents a purchase attempt with the customer token or an owner-scoped read with the system token.

### 5. Read the customer's starting state

In the CUSTOMER tab, run **Show a customer's achievement and badge progress**. Set the `user` path field to the customer ID returned by login. The response contains all five assessment fields:

```json
{
  "unlocked_achievements": [],
  "next_available_achievements": [
    "First Purchase",
    "NGN 5,000 Spent"
  ],
  "current_badge": null,
  "next_badge": "Beginner",
  "remaining_to_unlock_next_badge": 1
}
```

Then run **List the current customer's cashback rewards**. Its `data` array is empty and `meta.total` is `0`.

### 6. Record the purchase and prove replay safety

In the SYSTEM tab, run **Record a completed purchase**. Replace `user_id` with the ID returned by customer login:

```json
{
  "user_id": 1,
  "external_reference": "README-FRESH-FIRST-001",
  "amount_minor": 100000,
  "currency": "NGN",
  "completed_at": "2026-08-24T18:00:00Z"
}
```

`amount_minor` is kobo, so `100000` means NGN 1,000. On a fresh demo database, the first response is `201` with `was_duplicate: false`.

Send the identical request once more. It returns `200`, the same purchase ID, and `was_duplicate: true`; it does not dispatch the purchase event again. Reusing the reference with different purchase facts returns `409 Conflict`.

### 7. Observe the achievement, badge, reward, and notification

Give Horizon a few seconds, then return to the CUSTOMER tab and send the two reads from step 5 again. The progress response becomes:

```json
{
  "unlocked_achievements": ["First Purchase"],
  "next_available_achievements": [
    "3 Purchases",
    "NGN 5,000 Spent"
  ],
  "current_badge": "Beginner",
  "next_badge": "Intermediate",
  "remaining_to_unlock_next_badge": 3
}
```

The rewards response contains one item with these stable facts; IDs and timestamps are generated:

```json
{
  "badge_name": "Beginner",
  "amount_minor": 30000,
  "currency": "NGN",
  "status": "awaiting_payout_account",
  "paid_at": null
}
```

In Pail, expect recipient `demo.fresh@example.test` and subject `Add a payout account for your cashback reward`. This proves Laravel rendered the customer notification locally; it does not prove SMTP or inbox delivery.

If the GET responses have not changed, wait another moment and send those GET requests again. Do not create a new purchase reference as a polling mechanism.

### 8. Add a Fake payout destination

In the CUSTOMER tab, run **Create or replace the current customer's payout account** with:

```json
{
  "account_number": "0000001234",
  "bank_code": "057"
}
```

These are deterministic Fake-provider inputs, not a real customer's bank details. The Fake provider validates their shape without contacting a bank.

The first save returns `201` and never returns the full account number. Its numeric ID and timestamp are generated; the stable response facts are:

```json
{
  "id": 3,
  "provider": "fake",
  "account_name": "Demo Customer",
  "bank_name": "Demo Bank",
  "bank_code": "057",
  "masked_account_number": "******1234",
  "currency": "NGN",
  "verified_at": "<generated UTC timestamp>"
}
```

Give Horizon a few seconds, then send **List the current customer's cashback rewards** again. The same reward now has `status: paid` and a non-null `paid_at`. This is the customer-facing application result: the service marked its NGN 300 obligation fulfilled after the selected provider returned success.

The Fake provider never moves money, so this does **not** prove that a bank account received NGN 300. There is intentionally no “cashback paid” email in this assessment. An email would communicate the same application state; it would not be independent proof of bank credit.

### 9. Revoke both generated tokens

In the CUSTOMER tab, run **Revoke the current bearer token** and expect `204` with no body. Repeat the same operation in the SYSTEM tab and expect another `204`. Each request revokes only the token used for that request.

For an optional visible proof, send **Show the authenticated identity** once more in each tab while the revoked token remains selected; each response is `401`. Then clear any temporary token and customer-ID notes.

## Try another seeded scenario

To demonstrate a specific boundary, open two fresh Scalar tabs and repeat both login/authorization steps: the seeded purchase system in the SYSTEM tab and one unused customer from this table in the CUSTOMER tab. Then run the two starting reads, system purchase ingestion, and the two reads after Horizon catches up. Each row assumes a fresh seed and the default Fake success configuration.

| Customer email | Exact starting fact | `external_reference` | `amount_minor` | Expected result after one purchase |
| --- | --- | --- | ---: | --- |
| `demo.fresh@example.test` | 0 purchases, no account | `README-FRESH-FIRST-001` | 100000 | First Purchase, Beginner, waiting reward, customer log message |
| `demo.one-purchase@example.test` | 1 purchase, NGN 1,000 spent | `README-SPEND-5000-001` | 400000 | NGN 5,000 Spent only |
| `demo.two-purchases@example.test` | 2 purchases | `README-THREE-PURCHASES-001` | 100000 | 3 Purchases only |
| `demo.intermediate-next@example.test` | 4 purchases, 3 achievements | `README-INTERMEDIATE-001` | 100000 | 5 Purchases, Intermediate, second waiting reward, customer log message |
| `demo.advanced-next@example.test` | 9 purchases, 7 achievements | `README-ADVANCED-001` | 100000 | 10 Purchases, Advanced, third waiting reward, customer log message |
| `demo.master-next@example.test` | 24 purchases, 9 achievements | `README-MASTER-001` | 100000 | 25 Purchases, Master, fourth waiting reward, customer log message |
| `demo.complete@example.test` | All 10 achievements and 4 badges | `README-COMPLETE-NOOP-001` | 100000 | Purchase accepted; no new achievement, badge, or reward |
| `demo.payout-success@example.test` | 0 purchases, verified Fake account | `README-PAYOUT-SUCCESS-001` | 100000 | First Purchase, Beginner, reward `paid` |
| `demo.payout-insufficient@example.test` | 0 purchases, verified Fake account | `README-PAYOUT-INSUFFICIENT-001` | 100000 | With insufficient-funds configuration: reward `awaiting_funds` and support log message |

For example, log in as `demo.intermediate-next@example.test`, record the table's purchase from the SYSTEM tab, then repeat the two CUSTOMER reads. Expect `5 Purchases`, current badge `Intermediate`, and a second waiting reward.

Demo personas exist only in Laravel's `local` and `testing` environments. Rerunning the seeder adds missing fixture facts but does not rewind a reward or payout result you already changed. Use the full reset near the end of this README when you need the original snapshots again.

## Configure Fake payments and logged mail

### Fake payout-account verification

`FAKE_PAYOUT_ACCOUNT_SCENARIO` is read by the web application when a customer calls `PUT /api/me/payout-account`.

| Value | API result |
| --- | --- |
| `success` | Verifies and stores a deterministic masked Fake account |
| `rejected` | Returns `422 payout_account_rejected` |
| Any unsupported value | Returns `503 payment_provider_unavailable` |

### Fake cashback transfer

`FAKE_TRANSFER_SCENARIO` is read by Horizon when it processes the payout job.

| Value | Reward state | Payout state | Support message |
| --- | --- | --- | --- |
| `success` | `paid` | `succeeded` | No |
| `pending` | `pending` | `pending` | No |
| `insufficient_funds` | `awaiting_funds` | `insufficient_funds` | Yes |
| `permanent_failure` | `requires_attention` | `rejected` | Yes |

To demonstrate insufficient funds:

1. Set this exact value in your uncommitted `.env`:

   ```dotenv
   FAKE_TRANSFER_SCENARIO=insufficient_funds
   ```

2. Clear cached configuration and reload the long-running worker:

   ```bash
   docker compose exec -T app php artisan config:clear
   docker compose restart horizon
   ```

3. Open two fresh Scalar tabs and repeat the login and authorization steps. Use `demo.payout-insufficient@example.test` in the CUSTOMER tab and the seeded purchase-system identity in the SYSTEM tab. Record this purchase from the SYSTEM tab, replacing `user_id` with the value returned by customer login:

   ```json
   {
     "user_id": 9,
     "external_reference": "README-PAYOUT-INSUFFICIENT-001",
     "amount_minor": 100000,
     "currency": "NGN",
     "completed_at": "2026-08-24T18:00:00Z"
   }
   ```

   Give Horizon a few seconds, then run **List the current customer's cashback rewards** in the CUSTOMER tab. The reward should be `awaiting_funds`. Use the returned ID rather than assuming the example `9` matches your database.

4. In Pail, expect a message to the configured `CASHBACK_SUPPORT_EMAIL` with subject `Cashback payout requires attention`.

Set the scenario before creating the reward. One reward owns one durable payout and its first result is sticky; changing `.env` later does not create a public retry operation or rewrite that payout.

Restore `FAKE_TRANSFER_SCENARIO=success`, then reload the worker before returning to the golden path:

```bash
docker compose exec -T app php artisan config:clear
docker compose restart horizon
```

### Logged mail

The default is:

```dotenv
MAIL_MAILER=log
CASHBACK_SUPPORT_EMAIL=support@example.test
```

The service currently emits exactly two queued mail notifications:

- a customer message to the customer's registered email when a badge reward needs a payout account; and
- a support message to `CASHBACK_SUPPORT_EMAIL` when a payout requires human attention.

There is no SMTP step in this reviewer journey. A real-looking email address still receives nothing while the log mailer is selected. The log proves the application chose a recipient and rendered a message; it does not prove transport or inbox delivery.

Inspect live mail and workflow logs:

```bash
docker compose exec app php artisan pail -vv
```

Or inspect recent persisted log output:

```bash
docker compose exec app tail -n 200 storage/logs/laravel.log
```

### When an environment change needs a restart

The repository is bind-mounted, so ordinary `.env` edits do not require an image rebuild. Laravel configuration is loaded into long-running PHP-FPM and Horizon processes, however. The calm, predictable command after changing a Laravel value is:

```bash
docker compose exec -T app php artisan config:clear
docker compose restart app horizon
```

| Changed value | Process that must reload |
| --- | --- |
| `FAKE_PAYOUT_ACCOUNT_SCENARIO` | `app` |
| `FAKE_TRANSFER_SCENARIO` | `horizon` |
| `MAIL_*` | `horizon`; both current notifications are queued |
| `CASHBACK_SUPPORT_EMAIL` | `app` and `horizon`; synchronous webhook support routing uses PHP-FPM, while Fake payout support routing uses Horizon |
| `PAYMENT_DRIVER` or Paystack settings | `app` and `horizon` |
| `APP_PORT` | Recreate `nginx`; Compose owns the host port mapping |
| `POSTGRES_HOST_PORT` | Recreate `postgres`; Compose owns the host port mapping |

For an application value, restarting without clearing a previously cached configuration can retain stale values. Clearing without restarting Horizon can leave the already-running worker on its old in-memory configuration. Use both commands above. Restarting `app` also ends any Pail process attached to that container, so run the Pail command again afterward.

### Optional Paystack test mode

The Fake provider is the supported default demo. If you independently choose to exercise Paystack test mode, keep the credential only in your uncommitted `.env`:

```dotenv
PAYMENT_DRIVER=paystack
PAYSTACK_SECRET_KEY=sk_test_your_test_key
PAYSTACK_BASE_URL=https://api.paystack.co
```

Then clear configuration and restart `app` and `horizon`. The adapter rejects live `sk_live_` keys. Never commit, paste into documentation, or screenshot a real secret. A credentialed Paystack action is not required by setup, CI, coverage, Scalar, or any guided scenario here.

## Review the API contract and authorization

The machine-readable contract is available at <http://localhost:8000/openapi.yaml>. Scalar renders that same file and can show a generated curl command for any operation; the repository does not maintain a second API description or a second guided client journey.

Both documentation routes are restricted to Laravel's `local` and `testing` environments. Scalar does not save plaintext bearer credentials in browser storage. The signed Paystack webhook is the one operation not suited to the interactive client: changing its JSON body changes the HMAC-covered bytes, and Scalar cannot recompute a signature from the private test key. Its focused automated tests provide that proof instead.

### Endpoint map

| Method and path | Caller | Purpose |
| --- | --- | --- |
| `GET /` | Public | Service identity and status |
| `GET /up` | Public | Framework health response |
| `POST /api/auth/register` | Public | Create a customer and customer token |
| `POST /api/auth/login` | Public customer | Create another customer token |
| `POST /api/auth/system/login` | Provisioned system identity | Create a token containing only `purchases:write` |
| `POST /api/auth/logout` | Sanctum personal access token | Revoke only the current token |
| `GET /api/me` | Authenticated customer or system identity | Read the authenticated identity |
| `PUT /api/me/payout-account` | Customer with `payout-accounts:write` | Verify and save one payout destination |
| `GET /api/me/cashback-rewards` | Customer with `cashback-rewards:read` | Read owner-scoped rewards |
| `POST /api/internal/purchases` | System with `purchases:write` | Idempotently ingest a completed purchase |
| `POST /api/webhooks/paystack` | Valid Paystack HMAC | Apply or safely acknowledge a transfer callback |
| `GET /users/{user}/achievements` | Owning customer with `achievements:read` | Read achievement and badge progress |

Customer registration and login issue only:

- `achievements:read`;
- `payout-accounts:write`; and
- `cashback-rewards:read`.

The purchase ability is reserved for administratively provisioned system identities. The local/testing seeder provisions one predictable `.test` identity; production does not. The dedicated login validates that identity's credentials and account type, then issues only `purchases:write`. The achievement route also accepts an already-authenticated first-party Laravel session because it is in the `web` middleware group and Sanctum checks the `web` guard, but this service exposes no public session-login route; the documented and Scalar paths use bearer tokens.

Every JSON API error uses the same safe shape: a stable `code`, a human-readable `message`, and an `errors` object only for validation failures. Normal responses and standardized JSON errors receive an `X-Request-ID`. Laravel's browser-style HTML exception page is the documented exception because it can be rendered after request middleware has unwound. The OpenAPI contract defines the exact operation-specific codes, status responses, nullability, headers, and examples.

Money is always an integer `amount_minor` in kobo. Do not send floating-point NGN values.

### Signed Paystack callbacks

The webhook handler authenticates a lowercase SHA-512 HMAC over the exact raw body with the configured test secret. It rejects bodies larger than 65,536 bytes before parsing. Authentic duplicate, unsupported, malformed, unmatched, or unchanged deliveries are acknowledged safely; a matching success, failure, or reversal is applied synchronously with its receipt record.

Reformatting JSON changes the signed bytes. The default Fake path neither needs nor pretends to exercise this boundary.

## Run tests and quality checks

All commands run inside Docker.

The test runner is Pest on PHPUnit. Xdebug supplies line coverage; Laravel Pint checks formatting; Larastan runs PHPStan at level 10; `php-openapi` validates the OpenAPI 3.1 document; Composer audits the locked dependency graph; and GitHub Actions repeats the complete gate with the Docker images used by this repository.

| Need | Command | What a failure means |
| --- | --- | --- |
| Complete test suite | `docker compose run --rm app composer test` | A unit, feature, concurrency, or architecture behavior failed |
| Coverage | `docker compose run --rm app composer test:coverage` | A test failed or application line coverage fell below 90% |
| Code style | `docker compose run --rm app composer lint` | Laravel Pint found formatting drift |
| Static analysis | `docker compose run --rm app composer analyse` | Larastan found a type or control-flow problem |
| OpenAPI | `docker compose run --rm app composer openapi:validate` | The OpenAPI 3.1 document is structurally invalid |
| Dependency advisories | `docker compose run --rm app composer audit --locked --no-interaction` | A locked dependency has a known security advisory |
| Every gate | `docker compose run --rm app composer quality` | Any manifest, contract, style, analysis, audit, test, or coverage gate failed |

`composer quality` runs, in order:

1. strict Composer manifest and lock validation;
2. OpenAPI validation;
3. Pint in check-only mode;
4. Larastan static analysis;
5. locked dependency audit; and
6. the full test suite with a minimum 90% application coverage gate.

The badge at the top reports the GitHub Actions Docker quality workflow. The coverage badge reports the enforced threshold, not a published live percentage; this repository does not use an external coverage-reporting service.

Run one file when you need a fast, specific answer:

```bash
docker compose run --rm app php artisan test tests/Feature/DemoPersonaSeederTest.php
docker compose run --rm app php artisan test tests/Feature/Achievements/AchievementProgressionTest.php
docker compose run --rm app php artisan test tests/Feature/Badges/BadgeProgressionTest.php
docker compose run --rm app php artisan test tests/Feature/Cashback/ProcessCashbackPayoutTest.php
```

Run the connected assessment slice:

```bash
docker compose run --rm app php artisan test \
  tests/Feature/Purchases/PurchaseIngestionTest.php \
  tests/Feature/Achievements/AchievementUnlockedEventTest.php \
  tests/Feature/Achievements/AchievementProgressionTest.php \
  tests/Feature/Badges/BadgeUnlockedEventTest.php \
  tests/Feature/Badges/BadgeProgressionTest.php \
  tests/Feature/Cashback/QueueCashbackPayoutsTest.php \
  tests/Feature/Achievements/AchievementProgressApiTest.php \
  tests/Feature/Cashback/CashbackRewardApiTest.php
```

The runtime journey and focused tests cover different kinds of evidence:

| Assessment requirement | Runtime demonstration | Direct automated proof |
| --- | --- | --- |
| Unlock achievements from purchases | Purchase POST, then progress GET before/after | `AchievementProgressionTest.php` |
| Fire `AchievementUnlocked` with `achievement_name` and `user` | Internal event; not exposed as a public response | `AchievementUnlockedEventTest.php` |
| Fire `BadgeUnlocked`, then create NGN 300 cashback | Beginner badge and reward appear after one purchase | `BadgeUnlockedEventTest.php`, `QueueCashbackPayoutsTest.php` |
| Return the required five-field achievement response | Exact before/after responses shown in the Scalar journey | `AchievementProgressApiTest.php` |
| Protect trusted purchase ingestion | Separate system login and `purchases:write` token | `SystemLoginTest.php`, `PurchaseIngestionTest.php` |

CI builds the same development and Nginx images, runs setup, starts the complete stack, checks HTTP health, and executes `composer quality`.

## Add an achievement and a badge

This optional exercise proves that the catalogue can grow without changing the achievement, badge, event, reward, or HTTP orchestration code. Work on a throwaway branch. Append the two new definitions; do not replace the existing definitions or edit database rows by hand.

The short version is: add the two catalogue rows, make every listed test and description edit, rebuild the disposable Compose data with `setup`, prove the result through the copied endpoint journey, then run the focused and complete checks. Do not run `db:seed` separately and do not seed on top of a demo database you have already changed; the fresh reset below is what makes every copied response predictable.

The example deliberately adds `NGN 150,000 Spent` instead of inserting `2 Purchases`. Inserting a threshold at two purchases would change several seeded customers' earned achievement counts and badge awards. The appended spend threshold leaves every seeded earned fact unchanged while adding one new next achievement and badge for the complete customer:

```text
demo.complete@example.test starts at NGN 100,000 spent
  -> one NGN 50,000 purchase
  -> NGN 150,000 Spent becomes achievement 11
  -> achievement 11 unlocks Legend
  -> Legend creates the customer's fifth NGN 300 reward
```

### 1. Append the two catalogue definitions

In `database/seeders/AchievementCatalogueSeeder.php`, append this row after `one-hundred-thousand-spent` in the `lifetime-spend` achievement list:

```php
[
    'code' => 'one-hundred-fifty-thousand-spent',
    'name' => 'NGN 150,000 Spent',
    'threshold' => 15_000_000,
    'sort_order' => 6,
],
```

Money thresholds use minor units: `15_000_000` kobo is NGN 150,000. The new `sort_order` places the definition after the existing spend achievements.

In `database/seeders/BadgeCatalogueSeeder.php`, append:

```php
[
    'code' => 'legend',
    'name' => 'Legend',
    'required_achievement_count' => 11,
    'rank' => 5,
],
```

Appending rank 5 avoids moving the existing unique ranks 1–4. No migration, enum, Action, listener, event, model, controller, route, Resource, provider, or `DemoPersonaSeeder` change is needed; those layers already read active catalogue rows.

### 2. Update the exact tests and descriptions

Make these edits before running the checks.

In `tests/Feature/DomainCatalogueTest.php`, append the achievement to the exact `$achievements` expectation and Legend to the exact `$badges` expectation:

```php
['code' => 'one-hundred-fifty-thousand-spent', 'name' => 'NGN 150,000 Spent', 'threshold' => 15_000_000, 'sort_order' => 6, 'is_active' => true],
```

```php
['code' => 'legend', 'name' => 'Legend', 'required_achievement_count' => 11, 'rank' => 5, 'is_active' => true],
```

In `tests/Feature/Achievements/AchievementProgressionTest.php`, add both sides of the new threshold:

```php
it('does not unlock the appended spend achievement below its threshold', function (): void {
    $user = User::factory()->create();

    recordQualifyingPurchase($user, 'ORDER-SPEND-ONE-HUNDRED-FIFTY-THOUSAND-BELOW', 14_999_999);

    expect(unlockedCodesFor($user))->not->toContain('one-hundred-fifty-thousand-spent');
});

it('unlocks the appended spend achievement at its exact threshold', function (): void {
    $user = User::factory()->create();

    recordQualifyingPurchase($user, 'ORDER-SPEND-ONE-HUNDRED-FIFTY-THOUSAND', 15_000_000);

    expect(unlockedCodesFor($user))->toBe([
        'first-purchase',
        'five-thousand-spent',
        'ten-thousand-spent',
        'twenty-five-thousand-spent',
        'fifty-thousand-spent',
        'one-hundred-thousand-spent',
        'one-hundred-fifty-thousand-spent',
    ]);
});
```

In the boundary dataset in `tests/Feature/Achievements/GetUserAchievementProgressTest.php`, append:

```php
'Master at ten achievements' => [10, 'master', 'Master', 'Legend', 1],
```

Replace `returns no next badge after Master` with:

```php
it('returns no next badge after Legend', function (): void {
    $user = User::factory()->create();
    BadgeTestData::giveAchievements($user, 11);
    grantProgressBadge($user, 'legend');

    $progress = app(GetUserAchievementProgress::class)->handle($user);

    expect($progress->unlockedAchievements)->toHaveCount(11)
        ->and($progress->nextAvailableAchievements)->toBe([])
        ->and($progress->currentBadge)->toBe('Legend')
        ->and($progress->nextBadge)->toBeNull()
        ->and($progress->remainingToUnlockNextBadge)->toBe(0);
});
```

That test file also creates synthetic future badges. Change only their generated required count so the first one uses 12 instead of colliding with Legend's unique count of 11:

```php
'required_achievement_count' => 11 + $sequence,
```

Leave its existing generated rank expression unchanged.

In the dataset in `tests/Feature/Badges/BadgeProgressionTest.php`, append:

```php
'legend boundary' => [11, ['beginner', 'intermediate', 'advanced', 'master', 'legend']],
```

In the `$scenarios` array in `tests/Feature/DemoPersonaSeederTest.php`, replace only the complete customer's next-action row:

```php
'demo.complete@example.test' => [
    5_000_000,
    ['one-hundred-fifty-thousand-spent'],
    ['legend'],
],
```

Do not change that file's seeded snapshot expectation. Before the new purchase, the customer still truthfully has 10 achievements, Master, four rewards, and NGN 100,000 lifetime spend.

In `openapi.yaml`, update only the `BadgeName` description:

```yaml
description: Catalogue-defined badge name. The seed catalogue is Beginner, Intermediate, Advanced, Master, and Legend, but the database may contain additional definitions.
```

The achievement schema is deliberately an open string rather than an enum, so no achievement schema change is required. The existing `3 Purchases` example also remains valid.

Finally, if this feature will remain in the repository, replace the `demo.complete@example.test` row in the [seeded scenario table](#try-another-seeded-scenario) with:

```markdown
| `demo.complete@example.test` | 10 of 11 achievements, Master, no account | `README-NEW-LEGEND-001` | 5000000 | NGN 150,000 Spent, Legend, fifth waiting reward, customer log message |
```

### 3. Rebuild the deterministic demo data

The edited PHP files define the catalogue, but the running database still has the old rows. For a predictable demonstration, rebuild the disposable local data instead of adding the rows to an already-used database.

First restore these safe reviewer values in your uncommitted `.env`; deleting volumes does not change that file:

```dotenv
APP_ENV=local
QUEUE_CONNECTION=redis
PAYMENT_DRIVER=fake
FAKE_PAYOUT_ACCOUNT_SCENARIO=success
FAKE_TRANSFER_SCENARIO=success
MAIL_MAILER=log
CASHBACK_SUPPORT_EMAIL=support@example.test
```

The following reset permanently deletes only this Compose project's local PostgreSQL and Redis volumes. It stops the workers before clearing the bind-mounted Laravel log, so an old queued notification cannot be written after the clear:

```bash
docker compose down --volumes --remove-orphans
docker compose --profile tools run --rm setup
docker compose run --rm --no-deps app php -r \
  'file_put_contents("storage/logs/laravel.log", "");'
docker compose up -d
docker compose ps
docker compose exec app php artisan horizon:status
```

`setup` runs the migrations and `DatabaseSeeder`, which runs both catalogue seeders and the demo-persona seeder. Do not run a separate `artisan db:seed` after it. Seeding alone can add the definitions, but it does not rewind used personas, tokens, rewards, queue state, or Fake transfer effects; the fresh reset is what makes this proof repeatable.

Optionally watch the new notification and event-driven work:

```bash
docker compose exec app php artisan pail -vv
```

Leave Pail running while the Scalar requests execute.

### 4. Open Scalar and authorize both tabs

Open two fresh <http://localhost:8000/docs> tabs. In the SYSTEM tab, run **Log in a system identity** with the seeded system email, password `password`, and device name `Scalar extension system`. Copy its token into that tab's `bearerAuth` field.

In the CUSTOMER tab, run **Log in a customer** with:

```json
{
  "email": "demo.complete@example.test",
  "password": "password",
  "device_name": "Scalar extension customer"
}
```

Copy the returned customer ID, then put its token in that tab's `bearerAuth` field. Run **Show the authenticated identity** in both tabs to verify the customer/system split before continuing.

### 5. Prove the starting boundary

In the CUSTOMER tab, run **Show a customer's achievement and badge progress** with the returned customer ID.

Expect exactly one next achievement and one remaining achievement before Legend:

```json
{
  "unlocked_achievements": [
    "First Purchase",
    "3 Purchases",
    "5 Purchases",
    "10 Purchases",
    "25 Purchases",
    "NGN 5,000 Spent",
    "NGN 10,000 Spent",
    "NGN 25,000 Spent",
    "NGN 50,000 Spent",
    "NGN 100,000 Spent"
  ],
  "next_available_achievements": ["NGN 150,000 Spent"],
  "current_badge": "Master",
  "next_badge": "Legend",
  "remaining_to_unlock_next_badge": 1
}
```

Run **List the current customer's cashback rewards**. The response starts with `meta.total: 4`.

### 6. Send the one qualifying purchase

In the SYSTEM tab, run **Record a completed purchase**. Replace `user_id` with the ID returned by customer login:

```json
{
  "user_id": 1,
  "external_reference": "README-NEW-LEGEND-001",
  "amount_minor": 5000000,
  "currency": "NGN",
  "completed_at": "2026-08-24T18:20:00Z"
}
```

`5000000` minor units is NGN 50,000. It raises this customer's seeded NGN 100,000 lifetime spend to exactly NGN 150,000. The purchase response is `201` with `was_duplicate: false`.

### 7. Prove the achievement, badge, event-driven reward, and replay behavior

Give Horizon a few seconds, then repeat the progress and rewards operations in the CUSTOMER tab.

The progress response becomes:

```json
{
  "unlocked_achievements": [
    "First Purchase",
    "3 Purchases",
    "5 Purchases",
    "10 Purchases",
    "25 Purchases",
    "NGN 5,000 Spent",
    "NGN 10,000 Spent",
    "NGN 25,000 Spent",
    "NGN 50,000 Spent",
    "NGN 100,000 Spent",
    "NGN 150,000 Spent"
  ],
  "next_available_achievements": [],
  "current_badge": "Legend",
  "next_badge": null,
  "remaining_to_unlock_next_badge": 0
}
```

The rewards page now has `meta.total: 5`. Its newest item contains:

```json
{
  "badge_name": "Legend",
  "amount_minor": 30000,
  "currency": "NGN",
  "status": "awaiting_payout_account",
  "paid_at": null
}
```

The waiting state is expected because this persona has no payout account. With `MAIL_MAILER=log`, Pail also shows recipient `demo.complete@example.test` and subject `Add a payout account for your cashback reward`.

Send the identical purchase from the SYSTEM tab once more. It returns `200` with `was_duplicate: true`; the totals remain 11 achievements, five badges, and five rewards.

### 8. Run the focused and complete checks

```bash
docker compose run --rm app php artisan test \
  tests/Feature/ApiDocumentationTest.php \
  tests/Feature/DomainCatalogueTest.php \
  tests/Feature/Achievements/AchievementUnlockedEventTest.php \
  tests/Feature/Achievements/AchievementProgressionTest.php \
  tests/Feature/Achievements/AchievementProgressApiTest.php \
  tests/Feature/Achievements/GetUserAchievementProgressTest.php \
  tests/Feature/Badges/BadgeUnlockedEventTest.php \
  tests/Feature/Badges/BadgeProgressionTest.php \
  tests/Feature/Cashback/CashbackRewardTest.php \
  tests/Feature/Concurrency/PurchaseAchievementConcurrencyTest.php \
  tests/Feature/DemoPersonaSeederTest.php

docker compose run --rm app composer quality
```

The focused group proves the definitions, both spend-boundary sides, both event contracts, badge progression, reward creation, the exact five-field endpoint, concurrency safety, and the seeded starting state. The Scalar requests above prove the same extension through the public HTTP boundaries. `composer quality` then validates OpenAPI, formatting, level-10 static analysis, locked dependencies, the complete test suite, and the 90% coverage floor.

### 9. Revoke the two extension-demo tokens

Run **Revoke the current bearer token** in each authorized Scalar tab. Each request returns `204` and revokes only its current token. Clear temporary copied values and stop Pail with `Ctrl+C` if it is running.

## Understand the design and limits

### Why the workflow is asynchronous

The purchase endpoint commits the trusted purchase before dispatching work. Horizon then evaluates achievements, dispatches achievement events, evaluates badges, creates the unique reward, and queues a payout only when a verified account exists. After-commit dispatch prevents a worker from observing database rows that later roll back.

The HTTP purchase caller receives a quick durable ingestion result; customer progression catches up through the queue. That is why the demo reads again after a short wait.

### Why purchases and rewards are idempotent

`external_reference` is unique. A replay with the same customer, normalized reference, amount, and completion time returns the existing purchase; contradictory facts fail closed. User-achievement, user-badge, badge-reward, and reward-payout uniqueness constraints stop normal event replays from duplicating durable outcomes.

### Why the system token is separate

Customers may read their own progress and rewards and manage their own payout destination. They cannot claim that a purchase happened. Only a provisioned system identity with `purchases:write` may ingest one. `POST /api/auth/system/login` checks that identity's credentials and system account type, then issues exactly that one ability; the request cannot select its role or abilities. The predictable `.test` identity exists only in local/testing seeds.

### Why the Fake provider is the default

Fake recipient verification and transfer outcomes implement the same provider contracts used by Paystack. They keep setup, CI, demos, and failure-state testing deterministic without credentials or live money. Transfer request and result facts are stored on the single payout; verified destination facts remain on the payout account. The customer-facing reward exposes only safe business state.

### Transaction and provider boundary

Database work can roll back database changes; it cannot roll back a network transfer. The payout flow first creates durable local intent, performs provider I/O outside the row-locking transaction, then records the first result in a follow-up transaction. A timeout or lost response can therefore be ambiguous. The service preserves that uncertainty rather than pretending a retry is automatically safe.

### Honest limits

This assessment intentionally does not include:

- automatic payout retries or a public “retry transfer” button;
- stale-state, funding, or payout-account reconciliation;
- provider-balance monitoring or treasury automation;
- asynchronous webhook recovery;
- guaranteed exactly-once external email delivery;
- a production docs portal, generated SDK, or second client-specific contract; or
- deployment or live Paystack operations.

Inspect a failed payment job before doing anything. A queue failure is evidence, not authorization to send money again.

## Troubleshoot, inspect, or reset

### Useful checks

```bash
docker compose ps
docker compose exec app php artisan horizon:status
docker compose exec app php artisan queue:failed
docker compose exec app php artisan migrate:status
docker compose exec app php artisan db:show --counts
docker compose logs --tail=100 horizon
```

Open the local Horizon dashboard at <http://localhost:8000/horizon>.

The durable PostgreSQL reward and payout rows are financial truth. Logs provide request IDs, workflow correlation context, and seven bounded business milestones; they do not replace state or authorize a retry.

### Start and stop without deleting data

```bash
docker compose up -d
docker compose stop
```

`docker compose down` removes containers and the network but preserves named volumes unless `--volumes` is supplied.

### Restore the exact demo state

This is the pressure-proof reset button. It permanently deletes this Compose project's local PostgreSQL and Redis volumes, including customers, tokens, purchases, rewards, queue state, and failed jobs. It also clears the local Laravel log so an earlier notification cannot be mistaken for the new demonstration.

First restore the safe reviewer values in your uncommitted `.env`; deleting volumes does not change that file:

```dotenv
APP_ENV=local
QUEUE_CONNECTION=redis
PAYMENT_DRIVER=fake
FAKE_PAYOUT_ACCOUNT_SCENARIO=success
FAKE_TRANSFER_SCENARIO=success
MAIL_MAILER=log
CASHBACK_SUPPORT_EMAIL=support@example.test
```

Then rebuild the exact data snapshot:

```bash
docker compose down --volumes --remove-orphans
docker compose --profile tools run --rm setup
docker compose run --rm --no-deps app php -r \
  'file_put_contents("storage/logs/laravel.log", "");'
docker compose up -d
```

Use it before a repeatable demonstration so old queue jobs, references, and sticky payout results cannot affect the story.

### Revoke generated demo tokens

Each successful login creates a persisted Sanctum token; the current configuration does not expire it automatically. While a token is still copied or selected in Scalar, use **Revoke the current bearer token** as shown in [the guided demo](#9-revoke-both-generated-tokens). If the token value is lost in this disposable local environment, the full reset deletes every seeded user's tokens with the database volume.

### If a read has not caught up

Check:

```bash
docker compose exec app php artisan horizon:status
docker compose exec app php artisan queue:failed
docker compose logs --tail=100 horizon
```

If Horizon is running and no job failed, wait and repeat the GET request. Do not send a new purchase reference as a polling mechanism.

### If an edited environment value appears stale

```bash
docker compose exec -T app php artisan config:clear
docker compose restart app horizon
```

Then trigger a fresh, unused persona. An already-created payout keeps its durable first result.
