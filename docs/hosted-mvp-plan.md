# Hosted Service MVP Plan

## Goal

Validate whether stores will pay for a reliable managed RediSearch connection without first building a full self-service platform.

## Pilot architecture

- One small VPS may host the first pilot, but search, control API, and public edge must be separate processes and security boundaries.
- Each pilot store receives a dedicated Redis Stack container, persistent volume, memory limit, CPU limit, password, and network identity.
- Redis ports are private. Only the API gateway or an approved server-to-server tunnel can reach them.
- `search.shift64.com` terminates TLS and routes authenticated plugin traffic to the appropriate tenant service.
- The browser never receives Redis credentials and never connects directly to Redis.
- Provisioning is manual for the first cohort: create the customer record, container, volume, secret, tenant route, backup policy, and service key using an internal checklist.

Separate containers cost more memory than shared tenancy but make early isolation, debugging, limits, and deletion much safer. Do not promise 50 stores on one VPS from the `100 MB per store` estimate. That number ignores Redis process overhead, RediSearch index expansion, allocator fragmentation, rebuild peaks, backups, the gateway, monitoring, and burst concurrency. Capacity must be derived from measured high-water marks with spare memory and CPU.

## Minimum gateway responsibilities

- Authenticate the installation and map it to one tenant.
- Enforce request size, rate, and timeout limits.
- Restrict commands to the operations the plugin needs.
- Record latency, errors, usage, and tenant-level capacity signals without logging secrets.
- Support credential rotation and immediate revocation.

The first gateway can be a small service on the same VPS. It should still expose a stable HTTPS contract so Redis topology can change later without a plugin update.

## Manual pilot operations

For each store, record:

- customer and WordPress installation identity;
- plan and product-count limit;
- container, volume, memory, and CPU limits;
- backup and restore status;
- service-key creation and rotation dates;
- last health check, index size, peak memory, and rebuild duration.

Start with 5–8 stores, not 50. Rebuilds should be scheduled or queued so they cannot all run at once. Test restore and tenant migration before charging customers.

## What is outside the first MVP

- public self-service provisioning;
- shared multi-tenant Redis databases;
- automatic placement across many nodes;
- direct browser-to-service search;
- contractual production SLA;
- elaborate usage-based billing.

## Before paid launch

- Terms of Service and Privacy Policy;
- documented data categories and retention;
- secret storage, rotation, and revocation;
- backups with a verified restore procedure;
- monitoring and alerts for memory, CPU, disk, latency, and errors;
- incident and degradation behavior;
- Stripe checkout, cancellation, and a full refund of the first charge within 30 days.
