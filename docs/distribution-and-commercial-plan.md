# Distribution and Commercial Plan

## Sequence

1. Release and test a complete BYOR edition.
2. Prepare and submit the BYOR plugin to WordPress.org.
3. Add the optional managed Shift64 connection after the directory version is accepted and the core search experience is validated.
4. Sell managed plans from the Shift64 website using a service key for activation.

## WordPress.org boundary

The directory package must remain useful without payment: store owners can connect their own compatible Redis service, index products, search, tune relevance, and use the frontend components. Managed hosting is an optional external service. Its activation must be explicit, and the plugin must disclose what data is sent, why it is sent, and where the governing terms and privacy policy live.

## Commercial offer

Plans should initially be based on indexed catalog size because it is understandable and roughly correlated with memory and indexing cost. Before final pricing, measure document size, index expansion, rebuild peaks, query concurrency, and per-tenant operational overhead on real stores.

The purchase flow may offer a trial, but the baseline promise is a full refund of the first charge within 30 days, no questions asked. The terms should state the eligibility window, how the user requests the refund, and what happens to hosted data after cancellation.

Stripe Checkout is the preferred first implementation. Use hosted payment and customer-portal surfaces rather than storing payment data. Webhooks must be idempotent and drive subscription state in the Shift64 control plane.

## Licensing

The distributed WordPress plugin is GPL-2.0-or-later. Managed infrastructure, operations, and service access are sold separately. The commercial value should come from reliable hosting, automation, support, monitoring, and capacity—not from making the directory plugin unusable.

## Validation gates

- Search relevance is demonstrably better than the store's current baseline.
- Installation and setup can be completed without developer intervention.
- A small test cohort continues using the plugin after the novelty period.
- Memory and CPU assumptions are backed by production-like measurements.
- Support burden and failure modes are understood before public paid acquisition.
