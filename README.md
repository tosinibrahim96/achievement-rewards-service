# Achievement Rewards Service

A Laravel service for ecommerce achievements, badges, and cashback rewards.

The project currently includes its Docker-based infrastructure foundation and customer authentication. Achievement, badge, and cashback domain features are implemented in later milestones.

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

The reserved `purchases:write` ability is never issued by public registration or login. It belongs to trusted system identities created through the later internal demo/setup workflow.

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
- The production image runs application processes as the unprivileged `app` user.
- Horizon is available locally and denied by default in non-local environments until an explicit authorization rule is added with authentication.
