# Shift64 Woo Search documentation

This directory contains the Astro Starlight documentation site. It is kept separate from the WordPress plugin runtime and is excluded from release archives.

## Initial local setup

Use a current Node.js LTS release, then install and run the site from this directory:

```bash
npm install
npm run dev
```

The local site is served at the URL printed by Astro (normally `http://localhost:4321`).

## Documentation conventions

- Maintained pages live in `src/content/docs/` as `.mdx` files.
- Add or update the relevant documentation in the same commit as a product change.
- Do not commit `node_modules`, `dist`, `.astro`, or `.env*` files. An `.env.example` may be committed when configuration is introduced.
- The documentation site must never be included in the plugin ZIP.

## Current documentation baseline

The initial published content is the introduction and the runtime requirements page. Existing planning notes remain at the top level of this directory until they are reviewed and migrated into the maintained documentation structure.
