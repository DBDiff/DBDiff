# DBDiff — Project Guidelines

## What This Is

Automated database schema & data diff tool that generates SQL migration files. Compares two databases and produces UP + DOWN SQL. Built-in migration runner with versioned history tracking.

Supports MySQL 8.0–9.6, PostgreSQL 14–18, SQLite 3, plus MySQL-compatible variants (MariaDB, Aurora, PlanetScale, Vitess, TiDB, Dolt) and Supabase.

## Architecture

```
src/
  DB/          — Adapters (MySQL, Postgres, SQLite), schema introspection, data diffing
  Diff/        — 27 diff object models (AddTable, AlterTableChangeColumn, InsertData, CreateView, CreateTrigger, CreateRoutine, etc.)
  SQLGen/      — SQL generation: Dialect/ (MySQL, Postgres, SQLite), DiffToSQL/ (27 generators)
  Migration/   — Commands (Symfony Console), Runner, Config, Format/ (Native, Flyway, Liquibase, Laravel)
  Params/      — CLI parameter parsing (CLI flags → config file → defaults)
  Exceptions/  — Exception hierarchy
```

Key flow: `DiffCommand` → `DBDiff` orchestrator → `DiffCalculator` (schema + data) → `SQLGenerator` → `MigrationGenerator` → output file.

**Design patterns**: Factory (AdapterFactory, DialectRegistry, FormatRegistry), Strategy (adapters, dialects, formats), Command (Symfony Console), Registry.

**Namespace**: `DBDiff\{Module}` with PSR-4 autoloading.

## Build & Test

```bash
composer install                              # Install dependencies
composer run build:phar                       # Compile PHAR binary (uses box.json)

# Tests — use the wrapper script:
./scripts/run-tests.sh                        # Full test run
./scripts/run-tests.sh --unit                 # Unit tests only
./scripts/run-tests.sh --postgres             # PostgreSQL E2E
./scripts/run-tests.sh --sqlite              # SQLite E2E
./scripts/run-tests.sh --specific <method>    # Single test method
./scripts/run-tests.sh --record              # Record new baselines
```

**Local runs use Podman** (not Docker). The wrapper script handles this. For direct PHPUnit invocations:
```bash
podman run --rm -v "$(pwd):/app:Z" -w /app php:8.4-cli vendor/bin/phpunit ...
```

**CI matrix**: 5 PHP versions (8.1–8.5) × 4 MySQL versions, Dolt, PostgreSQL 14–18, SQLite. See `.github/workflows/tests.yml`.

## Conventions

- **PHP 8.1+** minimum. No PHP attributes used yet — stick to docblocks.
- **One class per file**, matching classname.
- **Test baselines**: `tests/expected/` contains golden output files. Run `--record` to regenerate after intentional output changes.
- **Test fixtures**: `tests/fixtures/` for schema/data setups, `tests/end2end/` for scenario-based E2E tests.
- **Config resolution order**: CLI flags → `dbdiff.yml` → `.dbdiff.yml` → `dbdiff.yaml` → `.dbdiff`
- **DSN URLs**: Full database URLs supported (`postgres://user:pass@host:5432/db`), parsed by `DsnParser`.
- **Commit format**: `type(scope): subject` — Angular convention. Types: feat, fix, docs, style, refactor, perf, test, chore.

## Key Gotchas

- Each `DiffToSQL/` generator implements both `getUp()` and `getDown()` — always update both when modifying SQL generation.
- SQLite has limited ALTER TABLE — the adapter uses table-rebuild strategy. Test SQLite separately.
- `InsertDataSQL` and `DeleteDataSQL` emit explicit column-name lists (not `INSERT INTO t VALUES`).
- The `_dbdiff_migrations` table tracks migration history — never diff this table.
- Supabase mode (`--supabase`) sets `driver=pgsql`, `sslMode=require`, and enables dual-write to `supabase_migrations.schema_migrations`.
- MySQL adapter's `normalizeCreateStatement()` strips DEFINER, ALGORITHM, SQL SECURITY, and trailing semicolons from view/trigger/routine definitions.
- PostgreSQL `DROP TRIGGER` requires `ON table` — handled by `PostgresDialect::dropTrigger()`.
- SQLite has no stored procedures/functions — `getRoutines()` returns `[]`.
- DiffSorter places DROP view/trigger/routine BEFORE table ops, CREATE/ALTER AFTER data ops.

## Docs

- [README.md](../README.md) — Usage, supported databases, download
- [DOCKER.md](../DOCKER.md) — Docker setup
- [docs/SUPABASE.md](../docs/SUPABASE.md) — Supabase integration
- [docs/CROSS_DB_TRANSLATION.md](../docs/CROSS_DB_TRANSLATION.md) — Cross-DB SQL strategies
- [docs/diff-algorithms.md](../docs/diff-algorithms.md) — Algorithm details
- [docs/DB-ecosystem-compatibility.md](../docs/DB-ecosystem-compatibility.md) — Variant support matrix
- [.github/CONTRIBUTING.md](CONTRIBUTING.md) — PR guidelines, commit format

## NPM Distribution

`packages/@dbdiff/cli/` contains a TypeScript/Node wrapper that bundles pre-compiled PHAR binaries for 8 platforms.

```bash
cd packages/@dbdiff/cli
npm install
npm test                   # Vitest unit tests
npm run test:integration   # Integration tests
npm run build              # Compile TypeScript
```

See `packages/@dbdiff/cli/README.md` for full details.

## Definition of Done

Every branch or piece of independent work MUST satisfy all of the following before it is considered complete:

- **Minimal dependencies**: Make the simplest fix or feature possible. Do NOT add external production dependencies unless unavoidable. Dev dependencies are fine only if: actively maintained open source, MIT or Apache 2.0 license, regular releases, and many contributors.
- **DRY code**: Don't Repeat Yourself. Before implementing any functionality, check existing utils, helpers, and config in the codebase. Extract repeated logic into reusable methods or classes.
- **No magic values**: Key config, numbers, and settings must not be hardcoded in source files. Extract them to named constants or config.
- **Low complexity**: No single file should carry too much responsibility. Extract reusable classes and utilities with their own dedicated unit tests.
- **Tests**: Add or update unit tests AND e2e tests. Register new test suites in the GHA workflow (`.github/workflows/tests.yml`) so they run in CI.
- **Local verification**: Run all relevant unit and e2e tests locally via Podman (or Docker if installed) and confirm they pass before treating the work as done.
- **Docs updated**: Update `README.md`, inline docblocks, and any relevant *existing* file under `docs/` to reflect the change. Include a usage example for any user-facing change. Do NOT create new documentation files unless explicitly instructed to do so.
- **Clean commits**: Never force-add files or folders excluded by `.gitignore`. Do not mention gitignored paths or any part of their file contents in commit messages or PR descriptions.
