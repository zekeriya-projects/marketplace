# Marketplace SaaS

A pilot-ready modular Laravel monolith for centrally managing WooCommerce and Trendyol catalog, inventory, listings, orders, and synchronization.

## WSL2 prerequisite

Clone this repository into the WSL2 Linux filesystem for normal development. Bind mounts from `/mnt/c` or other Windows drives are substantially slower.

```bash
mkdir -p ~/projects
cd ~/projects
git clone <repository-url> marketplace-saas
cd marketplace-saas
```

Docker Desktop must have WSL2 integration enabled. No host PHP, Composer, Node, PostgreSQL, or Redis installation is required.

## Start the stack

```bash
cp .env.example .env
docker compose build
docker compose run --rm app php artisan key:generate
docker compose up -d
docker compose exec app php artisan migrate
```

Open <http://localhost:8080>. Change `APP_PORT` in `.env` if port 8080 is occupied.

The local services are `nginx`, `app`, `postgres`, `redis`, `horizon`, and `scheduler`.

Register at <http://localhost:8080/register>. Registration creates the first organization and assigns the new user the `owner` role. Organization settings allow owners and admins to add existing registered users and manage permitted roles.

## Common commands

```bash
docker compose exec app php artisan migrate
docker compose exec app php artisan test
docker compose exec app composer check
docker compose exec app npm run typecheck
docker compose exec app npm run build
docker compose exec app php artisan horizon:status
docker compose ps
```

The PostgreSQL container creates a separate `marketplace_saas_testing` database on first initialization. The Pest suite is forced to use that database so test refreshes never touch local development data.

## Horizon queue smoke check

Dispatch a uniquely named Redis job, wait briefly for Horizon, then check its Redis marker:

```bash
docker compose exec app php artisan queue:smoke phase0-check
docker compose exec app php artisan queue:smoke-status phase0-check
docker compose exec app php artisan horizon:status
```

The status command exits successfully only after Horizon has handled the job. Use a new token for repeated checks.

## Reset local data

`docker compose down` stops the environment while retaining PostgreSQL and Redis data. `docker compose down --volumes` also removes local database, Redis, dependency, and built-asset volumes.

## Production operations

See [Production](docs/PRODUCTION.md), [Backup and Recovery](docs/BACKUP_AND_RECOVERY.md), [Monitoring](docs/MONITORING.md), and [Security Audit](docs/SECURITY_AUDIT.md) before onboarding a pilot merchant. The included Compose file is optimized for local development, not a complete production deployment manifest.
