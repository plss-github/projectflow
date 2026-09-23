# Build information

- Plugin: Project Flow
- Version: 3.4.3
- Build date: 2026-09-23
- Target: GLPI 11.0.x / PHP 8.2+
- Assets: `public/css/projectflow-3.4.3.css`, `public/js/projectflow-3.4.3.js`

## Build checks

- PHP syntax check (`php -l`) on every PHP file.
- JavaScript syntax check on `public/js/projectflow-3.4.3.js`.
- `composer.json` JSON parse.
- Release package contains the top-level `projectflow/` folder.

A live GLPI/database instance is not embedded in this build environment. Run the smoke checklist in `docs/OPERATIONS.md` after installation in the target GLPI instance.
