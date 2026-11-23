# Red Pharma POS Modernization

Workspace for the audit and the rebuild of the Red Pharma POS: a Nuxt POS on a centralized Laravel API.

**Current phase: audit complete. Implementation is waiting for approval.**

## Audit documents (`docs/`)

| Document | Contents |
|---|---|
| [legacy-system-audit.md](docs/legacy-system-audit.md) | Architecture, codebases, versions, auth, integrations, tech debt, access limits |
| [pos-navigation-inventory.md](docs/pos-navigation-inventory.md) | Every sidebar item, submenu, route, action, form, permission, and the verification checklist |
| [pos-feature-parity-matrix.md](docs/pos-feature-parity-matrix.md) | One row per feature, with implementation and verification status |
| [pos-business-workflows.md](docs/pos-business-workflows.md) | Business workflows as they exist today, traced through the code |
| [api-architecture.md](docs/api-architecture.md) | Current API, consumers, and the target centralized API and versioning |
| [database-dependencies.md](docs/database-dependencies.md) | Schema, stock and money model, integrity findings, migration rules |
| [known-issues.md](docs/known-issues.md) | Deduplicated issue register, including immediate production actions |
| [migration-plan.md](docs/migration-plan.md) | Phased plan, cutover and rollback, and the decisions needed |
| `docs/workpapers/` | Detailed evidence (wp1–wp5) with `file:line` citations |

Sources audited (read-only) are in `../backend - ocr pos`, `../backend`, `../web`, `../web-v2`, `../app`, the production SQL dump, and non-destructive HTTP checks against production.
