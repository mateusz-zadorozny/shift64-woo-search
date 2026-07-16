# Product Roadmap

## Product strategy

Shift64 Woo Search should validate search quality and installation demand before the managed Redis service is built. The public plugin and hosted infrastructure are separate products joined by one connection layer.

## Stage 1: independent BYOR plugin

Target: `0.1.0` through the WordPress.org review candidate.

- Complete the independent product identity and release process.
- Support any compatible Redis deployment with RediSearch.
- Keep credentials server-side and validate the connection from WordPress.
- Expose every material ranking behavior as an explicit, documented control.
- Add a search block for block themes and a shortcode for classic themes.
- Recruit a small group of real stores and measure relevance, latency, indexing cost, support load, and memory use.
- Publish the GPL plugin source on GitHub.

BYOR is intentionally the first distribution mode because it lets WordPress.org reviewers and technical users run the plugin without a paid service. It also validates the core plugin before hosted-service complexity is introduced.

## Stage 2: WordPress.org publication

- Submit the complete, useful BYOR plugin without requiring an account or external service.
- Keep hosted-service code dormant until the user explicitly chooses it.
- Clearly disclose any external service, data sent, terms, privacy policy, and pricing before enabling it.
- Treat the WordPress.org package as the free product, not as a non-functional lead form.

## Stage 3: managed Redis option

- Add a service-key onboarding path alongside BYOR.
- The plugin exchanges the key with the Shift64 control API and receives short-lived or rotatable connection configuration.
- Do not expose Redis directly to the browser.
- Keep the search algorithm and visible controls equivalent between BYOR and managed modes.
- Offer either a free trial or paid activation with a clear 30-day, no-questions-asked refund for the first charge.
- Use Stripe Checkout and the customer portal for payment methods, invoices, cancellation, and refunds where practical.

## Stage 4: self-service portal

- Account, store, subscription, and service-key management.
- Usage, capacity, status, and incident visibility.
- Credential rotation, rebuild requests, cancellation, and deletion.
- Automated provisioning through a private control plane and job queue.

## Version policy

- `0.x`: compatibility may change while the product and hosting model are validated.
- `1.0.0`: stable public configuration, storage keys, hooks, CLI commands, blocks, shortcode API, and migration policy.
- Semantic Versioning governs releases after `1.0.0`; Conventional Commits drive automated release notes.

No compatibility aliases for pre-product identifiers are carried into `0.1.0`.
