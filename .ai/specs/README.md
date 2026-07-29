# Specs index

Single glance at what is implemented and what is not. Every spec carries a
`> **Status:**` line right under its title; this table mirrors those lines.

**Lifecycle rule (see AGENTS.md):** the PR that implements a spec flips the
spec's Status header and this table row in the same PR. Statuses: `draft` →
`implemented — PR #N, date` (or `superseded — see <spec>`). Files are never
moved or deleted — paths are referenced from other specs, `.ai/runs/` plans,
and PRs.

| Spec | Status | Where |
| --- | --- | --- |
| [Native WooCommerce Brands Support](2026-07-18-native-woocommerce-brands-support.md) | ✅ implemented | PR #11 (issue #10) |
| [Playwright E2E Foundation](2026-07-18-playwright-e2e-foundation.md) | ✅ implemented | PR #14 |
| [Filter Bar Gutenberg Block & Placement Modes](2026-07-21-filter-bar-gutenberg-block-and-placement-modes.md) | 🚧 draft — blocked | needs alignment with Admin Settings IA first |
| [Admin Settings Information Architecture](2026-07-22-admin-settings-information-architecture.md) | ✅ implemented | PR #33 |
| [50k Demo Product Generator Scaling](2026-07-27-50k-demo-product-generator-scaling.md) | ✅ implemented | issue #18 |

