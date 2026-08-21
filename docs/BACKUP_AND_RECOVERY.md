# Backup and Recovery

## Policy

- PostgreSQL: encrypted daily full backup plus continuous WAL archiving when the hosting platform supports point-in-time recovery.
- Retention: 14 daily, 8 weekly, and 12 monthly recovery points for the pilot; revise for contractual/legal requirements.
- S3-compatible product media: bucket versioning and lifecycle retention. Development local files are not production backups.
- Redis is operational state, not the source of truth. Persist it for queue durability, but recover business state from PostgreSQL and redispatch failed/pending work deliberately.
- Store backups in a separate account/project and failure domain from production.

## Verification

Run a restore drill at least monthly into an isolated environment. Record backup ID, start/end time, restored row counts, migration status, application smoke results, and achieved RPO/RTO. A backup is not considered valid until restored successfully.

Pilot targets:

- RPO: 24 hours without WAL archiving; 15 minutes with point-in-time recovery.
- RTO: 4 hours.

## Recovery procedure

1. Declare the incident and stop writes if continued operation can worsen data loss.
2. Select the newest verified recovery point before the incident.
3. Restore PostgreSQL into a new instance; never overwrite the only production copy first.
4. Restore/version product media when affected.
5. Run migrations only after confirming the restored schema version.
6. Validate tenant counts, orders, inventory snapshots versus movement totals, channel mappings, and recent sync operations.
7. Point a staging application at the restored services and run smoke tests.
8. Cut over, restart Horizon/scheduler, and review pending/failed synchronization work before retrying it.
9. Document actual RPO/RTO and remediation actions.

Marketplace credentials remain encrypted with `APP_KEY`; the matching key must be recoverable from the secret manager. Never place it inside the database backup archive.

