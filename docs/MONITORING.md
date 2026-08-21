# Monitoring and Operations

## Health and availability

Probe the public `/up` endpoint every minute and alert after three consecutive failures. Separately monitor PostgreSQL connectivity, Redis connectivity, Horizon status, scheduler heartbeat, disk/object-storage errors, TLS expiry, CPU, memory and filesystem capacity.

## Queue and synchronization alerts

Alert on:

- failed-job count greater than zero for 10 minutes;
- oldest queued job above 5 minutes (`default`, `inventory-sync`, `price-sync`) or 15 minutes (`imports`, `orders`);
- scheduler heartbeat missing for 10 minutes;
- synchronization failure ratio above 10% over 15 minutes with at least 10 operations;
- any authentication failure affecting all recent operations for one channel account;
- order pull without a successful checkpoint for 20 minutes on an active account.
- the daily `sync-operation-archive` schedule fails or does not complete;
- unexpected growth in the logged `live_table_bytes` or `archive_table_bytes` values after archival.

Every synchronization status transition emits `sync_operation_status_changed` with operation, tenant/account/entity identifiers, attempt, normalized status and safe error codes. Credentials, authorization headers, raw provider payloads and customer snapshots must never be logged.

## Operator response

1. Open Synchronization History and identify whether failure is authentication, validation, rate limiting, network, or remote server related.
2. Authentication: replace credentials and rerun the connection test.
3. Validation/mapping: correct the catalog/listing data, then resubmit or retry.
4. Rate limit/network/remote server: allow bounded retries to finish; retry manually only after the active operation is terminal.
5. Order pull: verify the account is active, queue is healthy, and the checkpoint did not advance on failure.
6. Escalate repeated failures with sync operation IDs and timestamps—never credentials or full customer payloads.

Review daily during the pilot: failed jobs, failed sync operations, queue latency, last successful order pull per account, database backup age, and disk usage.

The scheduled `sync:archive` command runs daily at 02:30. Each successful run logs `archived_count`, `live_table_bytes`, and `archive_table_bytes`; failures emit a separate error entry. Alert when no successful completion is observed for 26 hours. PostgreSQL monthly partitioning is intentionally deferred until these measurements demonstrate a need.
