# Private BYOR Test Infrastructure

## Purpose

Provide remote Redis environments for development and invited testers without coupling the plugin to the future paid Shift64 service.

## Recommended setup

- Use a dedicated test host such as `test.shift64.com`.
- Run one Redis Stack container per test store with a persistent volume, explicit resource limits, authentication, and a private container network.
- Do not publish Redis directly through an HTTP reverse proxy. Redis is not HTTP, and a URL path such as `/tenant-a` cannot safely proxy the Redis protocol with ordinary Nginx HTTP routing.
- Prefer a private network or tunnel between WordPress and Redis. If public TCP access is unavoidable for a short test, use TLS, an allowlist, strong credentials, a dedicated port, and strict firewall rules.
- A hostname plus port, such as `test.shift64.com:16666`, can be used by the plugin as BYOR connection data. The hostname represents test infrastructure, not managed-service activation.

## Optional test gateway

An HTTPS gateway can be introduced early to exercise the future service contract. In that mode, the plugin talks to the gateway rather than raw Redis. The gateway authenticates a test installation, selects the tenant, and runs only approved search and indexing operations.

Keep raw BYOR and gateway mode distinct in configuration. A test gateway should not masquerade as a normal Redis endpoint.

## Test checklist

- Verify isolation by attempting cross-tenant reads.
- Verify memory and CPU limits during a full rebuild.
- Rotate a tenant credential and confirm the old credential stops working.
- Restart the container and host, then confirm persistence.
- Restore a backup into a fresh container.
- Confirm logs contain neither passwords nor full service keys.
- Remove a tenant and verify its route, container, volume, and secret are all deleted.
