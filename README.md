# Achievement Rewards Service

A Laravel service for ecommerce achievements, badges, and cashback rewards.

The project is currently at the infrastructure foundation milestone. Domain features and authentication are intentionally not implemented yet.

## Prerequisite

Install Docker Desktop, Docker Engine with the Compose plugin, or an equivalent Docker-compatible runtime. PHP, Composer, Node.js, PostgreSQL, and Redis are not required on the host.

Verify the prerequisite:

```bash
docker --version
docker compose version
```

## First-time setup

Build the development image, create the local environment file, generate the application key, and migrate PostgreSQL:

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

The setup command is safe to rerun. It installs the locked Composer dependencies and applies only outstanding migrations. It does not run migrations from every long-running container at startup.

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

Stop the services while retaining PostgreSQL, Redis, and Composer dependency volumes:

```bash
docker compose down
```

To change the host HTTP port, set `APP_PORT` for the Compose command:

```bash
APP_PORT=8080 docker compose up -d
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

This removes local PostgreSQL, Redis, and dependency volumes. It is destructive to local development data:

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
