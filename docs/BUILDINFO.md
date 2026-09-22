# Build information

- Plugin: Project Flow
- Version: 3.4.1
- Build date: 2026-09-22
- Target: GLPI 11.x / PHP 8.2+
- Base package: Project Flow 3.2.0

## Build checks

- PHP syntax check on every PHP file.
- JavaScript syntax check on `public/js/projectflow-3.4.js`.
- `composer.json` JSON parse.
- Twig block-balance smoke check.
- ZIP integrity test.

A live GLPI/database instance is not embedded in this build environment. Run the smoke checklist in `docs/OPERATIONS.md` after installation in the target GLPI instance.
