# Production Runbook

## Deployment model

Run the modular monolith as separate application, Horizon, scheduler, PostgreSQL, Redis, and TLS-terminating proxy processes. Build one immutable application image; do not use development bind mounts or publish PostgreSQL/Redis publicly.

Required production settings:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://panel.example.com
LOG_CHANNEL=stderr
LOG_LEVEL=info
SESSION_SECURE_COOKIE=true
DB_CONNECTION=pgsql
QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=redis
FILESYSTEM_DISK=s3
```

Generate a unique `APP_KEY` through the secret manager. Do not rotate it without a credential re-encryption plan because marketplace credentials use Laravel encryption. Store database, Redis, object-storage and mail secrets outside the image and repository.

## Release procedure

1. Back up PostgreSQL and verify the latest restore test.
2. Build assets and the application image from the reviewed revision.
3. Run `php artisan migrate --force` as a one-off release task.
4. Restart application workers, then run `php artisan horizon:terminate` so the process manager starts workers on the new image.
5. Run `php artisan optimize`, verify `/up`, `horizon:status`, and `schedule:list`.
6. Test login, one channel connection, a queued sync, and the sync-history screen.

Rollback application code to the previous image. Database rollback must be an explicit reviewed decision; prefer forward-fix migrations. Never run `migrate:fresh` outside the isolated test database.

## Pilot onboarding

1. Create the merchant owner account and organization.
2. Create an active default warehouse and enter starting stock.
3. Connect WooCommerce, test it, and import the catalog.
4. Review SKU/barcode mappings and inventory before activating propagation.
5. Connect Trendyol, publish a test variant, and confirm its batch result.
6. Confirm scheduled order jobs and review synchronization history.

Routine authentication, mapping, validation and provider failures are shown with safe messages. Operators can retest connections, restart imports/pulls, resubmit corrected listings, and retry failed inventory/price operations without database access.

## Security checklist

- TLS and HSTS are enabled at the public proxy.
- `APP_DEBUG=false`; error pages do not expose traces.
- PostgreSQL and Redis are private and authenticated at the network layer.
- S3 buckets are private; access uses least-privilege credentials.
- Horizon dashboard is not publicly exposed without authorization.
- `.env`, logs, backups and database snapshots are access-controlled.
- Owner/admin/operator/viewer access is reviewed during onboarding and offboarding.
- Clock synchronization is enabled on all hosts.

