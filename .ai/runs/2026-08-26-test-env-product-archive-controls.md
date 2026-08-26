# Test Environment Product Archive Controls — Execution Plan

## Goal

Make every newly provisioned block-theme test environment start with the
Shift64 Product Filters and Product Sort controls on WooCommerce's product
archive templates, while preserving idempotent setup and a safe fallback when
an older WooCommerce/plugin checkout lacks one of the blocks.

## Scope

- Transform WooCommerce's installed blockified templates during provisioning,
  without copying or hard-coding the upstream product-card markup.
- Cover `archive-product` (shop, product-category, and product-tag archives),
  `product-search-results`, and `taxonomy-product_attribute`.
- Add unit coverage for the template transformation and target-template list.
- Document the archive-template inventory and the fact that single-product
  related-product lists are intentionally out of scope.

## Non-goals

- Do not change the Shift64 Product Filters/Product Sort block implementation.
- Do not inject controls into classic themes, single-product templates, or
  arbitrary merchant templates.
- Do not add Playwright to the hermetic agentic validation gate.

## Implementation Plan

### Phase 1: Provision archive templates

1.1 Add a pure template transformer plus a WP-CLI provisioning runner that
    creates or updates the active theme's database template overrides.

1.2 Wire `bin/e2e-provision.sh` to run the template seeding after plugin and
    catalog setup, keeping reruns safe and allowing missing upstream template
    files to be reported without corrupting the environment.

### Phase 2: Verify and document

2.1 Add PHPUnit coverage for the three target templates, both custom controls,
    the legacy sort fallback, and idempotent transformation behavior.

2.2 Update test-environment documentation with the seeded template matrix and
    the manual QA URLs/expectations.

## Risks

- WooCommerce can change its blockified template markup. The transformer will
  use stable block markers and retain the upstream template as the source of
  truth; a missing marker will fail loudly in the provisioning runner.
- Database template overrides can persist across reruns. Updates will be
  scoped by active theme and template slug, so provisioning remains idempotent.

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles.

### Phase 1: Provision archive templates

- [ ] 1.1 Add a pure template transformer plus a WP-CLI provisioning runner that creates or updates the active theme's database template overrides.
- [ ] 1.2 Wire `bin/e2e-provision.sh` to run the template seeding after plugin and catalog setup, keeping reruns safe and allowing missing upstream template files to be reported without corrupting the environment.

### Phase 2: Verify and document

- [ ] 2.1 Add PHPUnit coverage for the three target templates, both custom controls, the legacy sort fallback, and idempotent transformation behavior.
- [ ] 2.2 Update test-environment documentation with the seeded template matrix and the manual QA URLs/expectations.
