<p align="center"><a href="https://dbdiff.github.io/DBDiff/" target="_blank" rel="noopener noreferrer"><img width="100" src="https://avatars3.githubusercontent.com/u/12562465?s=200&v=4" alt="DBDiff logo"></a></p>

<p align="center">
	<a href="https://github.com/DBDiff/DBDiff/actions/workflows/tests.yml"><img src="https://github.com/DBDiff/DBDiff/actions/workflows/tests.yml/badge.svg" alt="Build Status"></a>
	<a href="https://packagist.org/packages/dbdiff/dbdiff"><img src="https://poser.pugx.org/dbdiff/dbdiff/downloads" alt="Total Downloads"></a>
	<a href="https://packagist.org/packages/dbdiff/dbdiff"><img src="https://poser.pugx.org/dbdiff/dbdiff/d/monthly" alt="Monthly Downloads"></a>
	<a href="https://github.com/dbdiff/dbdiff/graphs/contributors"><img src="https://img.shields.io/github/contributors/dbdiff/dbdiff.svg" /></a>
	<a href="https://packagist.org/packages/dbdiff/dbdiff"><img src="https://poser.pugx.org/dbdiff/dbdiff/license" alt="License"></a>
</p>

<p align="center">
	<strong>DBDiff</strong> is an automated database schema and data diff tool. It compares two databases, local or remote, and produces a migration file of the differences automatically.
</p>

<p align="center">
	When used alongside a <a href="#compatible-migration-tools">compatible database migration tool</a>, it can help enable database version control within your team or enterprise.
</p>


## Features

- Compares two databases (local or remote) and generates SQL migrations automatically
- Diffs tables, views, triggers, stored procedures/functions, and data — with deterministic, predictable output
- Up and down SQL generated in the same file
- Built-in migration runner: `migration:up`, `down`, `status`, `validate`, `repair`, `baseline`
- Supports MySQL, PostgreSQL, and SQLite via `--driver`
- Connect via DSN URLs (`--server1-url`, `--server2-url`, `--db-url`) — works with any connection string
- [Supabase](https://supabase.com)-ready via `--supabase` one-flag shorthand (not required when using DSN URLs)
- Works with [Flyway, Liquibase, Laravel Migrations, and more](#compatible-migration-tools)
- Ignore specific tables or fields via a YAML config file
- Unicode / UTF-8 aware
- Fast — tested on databases with millions of rows
- Runs on Windows, Linux and macOS (command-line / Terminal)


## Supported Databases

_Other versions may work but are not actively tested. PRs to add official support are welcome._

### MySQL

| Version | Status |
|---|---|
| MySQL 8.0.x | ✅ Supported |
| MySQL 8.4.x (LTS) | ✅ Supported |
| MySQL 9.3.x (Innovation) | ✅ Supported |
| MySQL 9.6.x (Innovation) | ✅ Supported |

### PostgreSQL

Use `--driver=pgsql` (or `driver: pgsql` in your `.dbdiff` config).

| Version | Status |
|---|---|
| PostgreSQL 14.x | ✅ Supported |
| PostgreSQL 15.x | ✅ Supported |
| PostgreSQL 16.x (LTS) | ✅ Supported |
| PostgreSQL 17.x | ✅ Supported |
| PostgreSQL 18.x | ✅ Supported |

### SQLite

Use `--driver=sqlite`. The file path is passed as the database name:

```bash
./dbdiff --driver=sqlite server1./path/to/source.db:server1./path/to/target.db
```

SQLite 3.x is supported (any version supported by the installed `pdo_sqlite` PHP extension).

### Supabase

`--supabase` sets `driver=pgsql` and enables SSL automatically:

```bash
./dbdiff --supabase --server1=user:pass@db.xxx.supabase.co:5432 server1.mydb:server1.mydb
```


## Compatible Database Variants

The databases below work with DBDiff's existing drivers with no code changes. **Unless otherwise noted, these have not been tested by the core team.** PRs to add official support are welcome.

### MySQL-compatible — `--driver=mysql` (default)

| Database | Notes |
|---|---|
| MariaDB 10.x / 11.x | MySQL wire protocol; minor DDL dialect differences |
| AWS Aurora MySQL | Standard MySQL protocol |
| PlanetScale | MySQL-compatible SaaS |
| Vitess / VTGate | MySQL wire protocol via VTGate |
| Percona XtraDB Cluster | MySQL-compatible; Galera replication metadata ignored |
| TiDB | MySQL-compatible; default port 4000 |
| [Dolt](https://github.com/dolthub/dolt) | MySQL-compatible, version-controlled; **CI-tested** |

### PostgreSQL-compatible — `--driver=pgsql`

| Database | Notes |
|---|---|
| AWS Aurora PostgreSQL | Standard pgsql connection |
| AWS RDS PostgreSQL | Standard pgsql connection |
| [Neon](https://neon.tech) | Standard pgsql; supports branch diffing (see below) |
| AlloyDB (Google Cloud) | Google's Postgres-compatible offering |
| CockroachDB | Postgres wire protocol; some DDL differences |
| YugabyteDB | Postgres-compatible YSQL layer |
| Multigres | Transparent Postgres proxy; no changes needed |
| TimescaleDB | Postgres extension; hypertable DDL diffs natively |
| pgvector | `vector(N)` columns and HNSW/IVFFlat indexes diff natively |

### Neon Branching

Neon's copy-on-write branching lets you diff any two branches directly:

```bash
./dbdiff \
  --server1-url postgres://user:pass@main-branch.hostname.neon.tech/mydb \
  --server2-url postgres://user:pass@feature-branch.hostname.neon.tech/mydb \
  --format=flyway --description=my_feature
```

### Dolt (Git for Databases)

[Dolt](https://github.com/dolthub/dolt) is a MySQL-compatible database with Git-style branching. Each branch is exposed as a separate database:

```bash
./dbdiff server1.main:server1.feature_add_users
```


## Installation

The quickest way to get started is to download a pre-built release directly from [**GitHub Releases**](https://github.com/DBDiff/DBDiff/releases/latest) — no PHP, Node, or Composer required:

| Method | Available on Releases? | Best for |
|---|---|---|
| [Pre-built binary](#pre-built-binaries) | ✅ Yes | Quickest start — zero dependencies |
| [PHAR](#phar) | ✅ Yes | Single portable file; requires PHP ≥ 8.1 |
| [npm](#npm) | ✅ Yes (via registry) | Node.js projects or CI pipelines |
| [Docker / Podman](#docker--podman) | — | Isolated environments, CI, or no local PHP |
| [Composer (source)](#composer-source-install) | — | Contributing to DBDiff or PHP integration |

> **PHP requirement:** Pre-built binaries, npm packages, and Docker/Podman images bundle PHP 8.3 — no system PHP needed. The PHAR and Composer installs require **PHP ≥ 8.1** on your system.


## Pre-built Binaries

Download from [**GitHub Releases**](https://github.com/DBDiff/DBDiff/releases/latest). No PHP, Node, or Composer required.

| Platform | Asset |
|---|---|
| Linux x64 (glibc) | `dbdiff-linux-x64` |
| Linux x64 (Alpine / musl) | `dbdiff-linux-x64-musl` |
| Linux arm64 (glibc) | `dbdiff-linux-arm64` |
| Linux arm64 (Alpine / musl) | `dbdiff-linux-arm64-musl` |
| macOS Apple Silicon | `dbdiff-darwin-arm64` |
| macOS Intel | `dbdiff-darwin-x64` |
| Windows x64 | `dbdiff-win32-x64.exe` |
| Windows arm64 | `dbdiff-win32-arm64.exe` |

After downloading, make it executable (Linux/macOS) and optionally move it to your `PATH`:

```bash
chmod +x dbdiff-linux-x64
sudo mv dbdiff-linux-x64 /usr/local/bin/dbdiff
dbdiff --version
```


## npm

```bash
npm install -g @dbdiff/cli
dbdiff --version
```

The correct platform binary is selected automatically at install time. Supported: Linux x64/arm64 (glibc + musl), macOS x64/arm64, Windows x64/arm64.

Packages are also published to **GitHub Packages** as a mirror. If npmjs.org is unavailable:

```bash
npm install -g @dbdiff/cli --registry=https://npm.pkg.github.com
```


## PHAR

Download `dbdiff.phar` from [**GitHub Releases**](https://github.com/DBDiff/DBDiff/releases/latest). Requires PHP ≥ 8.1.

```bash
chmod +x dbdiff.phar
sudo mv dbdiff.phar /usr/local/bin/dbdiff
dbdiff --version
```

To build a PHAR locally from source, see [Building a PHAR](#building-a-phar).


## Docker / Podman

Pre-built multi-arch images (linux/amd64 + linux/arm64) are published to GHCR on every release. Both Docker and [Podman](https://podman.io/) are fully supported — use whichever is available on your system. No local PHP installation is required.

### Pull and run (no build required)

**Docker:**
```bash
docker pull ghcr.io/dbdiff/dbdiff
docker run --rm ghcr.io/dbdiff/dbdiff --version
docker run --rm ghcr.io/dbdiff/dbdiff --driver=mysql \
  --server1=user:pass@host:3306 server1.mydb:server1.mydb
```

**Podman** (drop-in replacement — commands are identical):
```bash
podman pull ghcr.io/dbdiff/dbdiff
podman run --rm ghcr.io/dbdiff/dbdiff --version
podman run --rm ghcr.io/dbdiff/dbdiff --driver=mysql \
  --server1=user:pass@host:3306 server1.mydb:server1.mydb
```

> **Podman on Linux** runs rootless by default — no daemon required. Install via your package manager: `sudo apt install podman` (Debian/Ubuntu) or `brew install podman` (macOS).

### Image variants

| Tag pattern | Registry | Description |
|---|---|---|
| `latest`, `{version}`, `slim-{version}` | GHCR | **Slim** — PHAR + PHP Alpine (~120 MB). For production use / CI. |
| `full`, `full-{version}` | GHCR | **Full** — Composer source install (~600 MB). For development and cross-version testing. |

### Build locally

```bash
# Slim image (requires dist/dbdiff.phar — run `vendor/bin/box compile` first)
# Replace 'docker' with 'podman' if using Podman
docker build -f docker/Dockerfile.slim -t dbdiff:slim .
docker run --rm dbdiff:slim --version

# Full image (Composer install from source — no PHAR needed)
docker build -f docker/Dockerfile -t dbdiff:full .
```

See [DOCKER.md](DOCKER.md) for cross-version testing, Podman setup, and start.sh flags.


## Composer Source Install

```bash
git clone https://github.com/DBDiff/DBDiff.git
cd DBDiff
composer install --optimize-autoloader
```

Or as a project dependency:

```bash
composer require "dbdiff/dbdiff:@dev"
```

Or globally:

```bash
composer global require "dbdiff/dbdiff:@dev"
```

After installing from source, continue with [Setup](#setup).


## Setup

_For source installs (git clone / Composer) only. Binaries, PHAR, npm, and Docker do not require these steps._

1. Create a `.dbdiff` config file — see [File Examples](#file-examples)
2. Run: `./dbdiff server1.db1:server1.db2`

Expected output:

```
ℹ Now calculating schema diff for table `foo`
ℹ Now generating UP migration
ℹ Writing migration file to /path/to/dbdiff/migration.sql
✔ Completed
```


## Command-Line API

### `diff` (default command)

_Flags always override settings in `.dbdiff`._

| Flag | Description |
|---|---|
| `--server1=user:pass@host:port` | Source connection. Omit if using only one server. |
| `--server2=user:pass@host:port` | Target connection (if different from server1). |
| `--server1-url=<dsn>` | Full DSN URL for source (e.g. `postgres://user:pass@host:5432/db`). |
| `--server2-url=<dsn>` | Full DSN URL for target. Supported schemes: `mysql://`, `pgsql://`, `postgres://`, `postgresql://`, `sqlite://`. |
| `--driver=mysql\|pgsql\|sqlite` | Database driver. Defaults to `mysql`. |
| `--supabase` | Shorthand for `--driver=pgsql` + SSL. |
| `--format=native\|flyway\|liquibase-xml\|liquibase-yaml\|laravel` | Output format. Defaults to `native`. |
| `--description=<slug>` | Slug used in generated filenames. |
| `--template=<path>` | Custom output template. |
| `--type=schema\|data\|all` | What to diff. Defaults to `schema`. |
| `--include=up\|down\|both` | Directions to include. Defaults to `up`. (`all` is accepted as an alias for `both`.) |
| `--nocomments` | Strip comment headers from output. |
| `--config=<file>` | Config file path. Defaults to `.dbdiff`. |
| `--output=<path>` | Output file path. Defaults to `migration.sql`. |
| `--memory-limit=<value>` | PHP memory limit for this run (e.g. `512M`, `1G`, `2G`, `-1` for unlimited). Overrides the 1G default and any `memory_limit` setting in your config file. |
| `--debug` | Enable verbose error output. |
| `server1.db1:server2.db2` | Databases to compare. Or a single table: `server1.db1.table1:server2.db2.table1`. |

> **UP only by default:** The generated file includes only the UP (forward) migration by default. To also generate the DOWN (rollback) section, pass `--include=all`. Example: `dbdiff diff --include=all ...`

> **DSN URLs vs `--server` flags:** Use `--server1-url` / `--server2-url` when you have a connection string (common with Supabase, Neon, Railway, etc.). Use `--server1` / `--server2` when specifying credentials separately.

> **Passwords with special characters:** Embed the password percent-encoded in the URL. Use `dbdiff url:encode` to safely encode any password (see [`url:encode`](#urlencode--password-encoder) below). If dbdiff is not yet installed, `scripts/encode-password.sh` works without any dependencies.

> **Memory:** The CLI sets a default PHP memory limit of 1G. Diffing very large databases may need more — pass `--memory-limit=2G` on the command line or add `memory_limit: 2G` to your `.dbdiff` / `dbdiff.yml` config. The CLI flag always wins over the config file.

### `url:encode` — Password encoder

Percent-encodes a raw password for safe embedding in any `--server-url` connection string.

```bash
dbdiff url:encode '<raw password>'
```

Capture the result directly into a connection flag:

```bash
PASS=$(dbdiff url:encode 'my$ecret#pass@word%123')
dbdiff diff \
  --server1-url="postgres://user:${PASS}@db.xxxx.supabase.co:5432/postgres" \
  --server2-url="postgres://user:pass@db.yyyy.supabase.co:5432/postgres"
```

Accepts stdin too, for use in pipelines:

```bash
echo 'my$ecret#pass' | dbdiff url:encode
```

All characters except RFC 3986 unreserved characters (`A–Z a–z 0–9 - _ . ~`) are encoded. This is the safe, zero-guesswork approach for any password — including ones containing `@`, `#`, `?`, `/`, `+`, and literal `%`.

**If dbdiff is not yet installed** (e.g. you are setting up CI), use the included bash script instead — no Python, Node, or PHP required:

```bash
PASS=$(scripts/encode-password.sh 'my$ecret#pass@word%123')
```

### Migration Runner

DBDiff includes a built-in migration runner. All `migration:*` commands accept:

| Flag | Description |
|---|---|
| `--db-url=<dsn>` | Full DSN URL for the target database. |
| `--migrations-dir=<path>` | Override the migrations directory. |
| `--config=<file>` | Path to `dbdiff.yml`. |

| Command | Description | Extra flags |
|---|---|---|
| `migration:new <name>` | Scaffold a new migration file. | `--format=supabase` — plain `.sql` (no DOWN); auto-set inside Supabase projects |
| `migration:up` | Apply all pending migrations. | `--target=<version>` — stop after this version |
| `migration:down` | Roll back applied migration(s). | `--last=<n>` (default `1`), `--target=<version>` |
| `migration:status` | Show applied vs pending migrations. Adds a **Supa?** column inside Supabase projects. | — |
| `migration:validate` | Verify on-disk checksums match the history table. | — |
| `migration:repair` | Remove failed entries so they can be retried. | `--force` — skip confirmation |
| `migration:baseline` | Mark current DB state as the migration baseline. | `--baseline-version=<YYYYMMDDHHmmss>`, `--description=<text>`, `--force` |

#### Migration file formats

DBDiff supports two on-disk formats in the same directory:

| Format | File pattern | Best for |
|---|---|---|
| **Native** (default) | `{version}_{name}.up.sql` + optional `.down.sql` | New projects, rollback support |
| **Supabase** | `{version}_{name}.sql` (UP only) | Existing `supabase/migrations/` directories |

If both formats exist for the same version timestamp, the native `.up.sql` file takes precedence.

#### Supabase project auto-detection

When DBDiff is run inside (or below) a directory that contains `supabase/config.toml`, it automatically:

- Sets the migrations directory to `supabase/migrations/` (no `--migrations-dir` needed)
- Defaults `migration:new` to Supabase format — creating a plain `.sql` file
- Shows a **Supa?** column in `migration:status`, indicating which migrations Supabase's own `schema_migrations` table considers applied

Pass `--format=native` to `migration:new` to override the auto-detected format.


## Usage Examples

### MySQL (default)
```bash
./dbdiff server1.db1:server2.db2
```

### MySQL — data diff only
```bash
./dbdiff server1.dev.table1:server2.prod.table1 --nocomments --type=data
```

### MySQL — Flyway format with output path
```bash
./dbdiff --format=flyway --description=add_users --include=all \
  server1.db1:server2.db2 --output=./sql/
```

### PostgreSQL
```bash
./dbdiff --driver=pgsql --server1=user:pass@localhost:5432 server1.staging:server1.production
```

### Supabase
```bash
./dbdiff --supabase --server1=postgres:pass@db.xxxx.supabase.co:5432 \
  server1.staging:server1.production
```

### SQLite
```bash
./dbdiff --driver=sqlite server1./var/db/v1.db:server1./var/db/v2.db
```

### DSN URLs
```bash
./dbdiff diff \
  --server1-url='postgres://user:pass@db.xxxx.supabase.co:5432/postgres' \
  --server2-url='postgres://user:pass@db.yyyy.supabase.co:5432/postgres'
```

If your password contains special characters, use `dbdiff url:encode` (see [`url:encode`](#urlencode--password-encoder) in the Command-Line API section):

```bash
PASS=$(dbdiff url:encode 'my$ecret#pass@word%123')
./dbdiff diff \
  --server1-url="postgres://user:${PASS}@db.xxxx.supabase.co:5432/postgres" \
  --server2-url='postgres://user:pass@db.yyyy.supabase.co:5432/postgres'
```

### Migration runner
```bash
# Scaffold a new migration (DBDiff native format)
./dbdiff migration:new create_users_table

# Scaffold a Supabase-format migration (plain .sql, no DOWN file)
./dbdiff migration:new create_users_table --format=supabase

# Inside a Supabase project, format and directory are auto-detected:
cd my-supabase-project   # contains supabase/config.toml
./dbdiff migration:new create_users_table   # → supabase/migrations/{ts}_create_users_table.sql

# Apply all pending migrations
./dbdiff migration:up --db-url='postgres://user:pass@localhost:5432/mydb'

# Check status (adds a Supa? column inside Supabase projects)
./dbdiff migration:status --db-url='postgres://user:pass@localhost:5432/mydb'

# Roll back the last migration
./dbdiff migration:down --db-url='postgres://user:pass@localhost:5432/mydb'

# Validate checksums
./dbdiff migration:validate --db-url='postgres://user:pass@localhost:5432/mydb'
```

### Supabase — diff with local stack auto-fill

When inside a Supabase project (`supabase/config.toml` present) and the local stack is
running (`supabase start`), `--server1-url` is resolved automatically from
`supabase status`:

```bash
# Only supply the remote (production) URL — local stack fills in automatically
./dbdiff diff --server2-url='postgres://user:pass@db.yyyy.supabase.co:5432/postgres'
```


## File Examples

A single `dbdiff.yml` file in your project root configures both the diff command and the migration runner. Copy [`dbdiff.yml.example`](dbdiff.yml.example) to get started.

Auto-detected filenames, in priority order:

| Filename | Notes |
|---|---|
| `.dbdiff` | Legacy — still supported for backwards compatibility |
| `dbdiff.yml` | **Recommended** — YAML syntax highlighting, single file for everything |
| `.dbdiff.yml` | Hidden-file variant |
| `dbdiff.yaml` | `.yaml` extension variant |

You can also pass any filename explicitly: `./dbdiff --config=myconfig.yml server1.db:server2.db`

### `dbdiff.yml`

```yaml
# ── Diff command (./dbdiff server1.db:server2.db) ─────────────────────────
server1:
  user: user
  password: password
  port: 3306      # MySQL: 3306 | PostgreSQL: 5432
  host: localhost
server2:
  user: user
  password: password
  port: 3306
  host: host2
driver: mysql     # mysql | pgsql | sqlite
type: all
include: all
nocomments: true
tablesToIgnore:
  - table1
  - table2
fieldsToIgnore:
  table1:
    - field1
    - field2

# ── Migration runner (dbdiff migration:up) ────────────────────────────────
database:
  driver: mysql
  host: localhost
  port: 3306
  name: mydb
  user: root
  password: secret

migrations:
  dir: ./migrations
  history_table: _dbdiff_migrations
```


## How Does the Diff Work?

Comparisons run in this order:

### Overall
- Checks both databases exist and are accessible
- Compares database collation between source and target

### Schema
- Detects differences in column count, name, type, collation or attributes
- New columns in the source are added to the target

### Views
- Detects created, dropped, and altered views across source and target
- ALTER = DROP IF EXISTS + CREATE with the new definition

### Triggers
- Detects created, dropped, and altered triggers
- PostgreSQL DROP TRIGGER includes the required ON table clause

### Stored Procedures / Functions
- Detects created, dropped, and altered routines (MySQL and PostgreSQL)
- SQLite has no stored procedures — routines are skipped automatically
- MySQL definitions are normalized (DEFINER, ALGORITHM, SQL SECURITY stripped)

### Data
- Compares table storage engine, collation, and row count
- Records changed rows and missing rows per table


## Compatible Migration Tools

DBDiff supports multiple output formats via `--format`. Use `--description=<slug>` to customise generated filenames.

| `--format` | Tool | Language | Output | Notes |
|---|---|---|---|---|
| `native` (default) | Plain SQL | Any | `migration.sql` | Up, down, or both |
| `flyway` | [Flyway](https://flywaydb.org) | Java | `V{ts}__{desc}.sql` | Down adds `U{ts}__{desc}.sql` (Flyway Teams) |
| `liquibase-xml` | [Liquibase](https://liquibase.com) | Java | `changelog.xml` | Both directions in one file |
| `liquibase-yaml` | [Liquibase](https://liquibase.com) | Java | `changelog.yaml` | Both directions in one file |
| `laravel` | [Laravel Migrations](https://laravel.com/docs/migrations) | PHP | `YYYY_MM_DD_HHMMSS_{desc}.php` | `up()`/`down()` methods |
| _(template)_ | [Simple DB Migrate](https://github.com/guilhermechapiewski/simple-db-migrate) | Python | custom | Use `--template=templates/simple-db-migrate.tmpl` |

[Let us know](https://akalsoftware.com/) if you're using DBDiff with other tools so we can add them here.


## Building a PHAR

PHARs are built automatically and attached to every [GitHub Release](https://github.com/DBDiff/DBDiff/releases). To build locally from source:

```bash
composer install
vendor/bin/box compile
```

Output: `dist/dbdiff.phar` — rename and move to `/usr/local/bin/dbdiff` if desired.

> `box.json` is pre-configured with GZ compression and `check-requirements: false` so the PHAR works correctly when stitched with the static micro SAPI runtime used in the pre-built binaries.


## Releasing 🚀

### Automated (recommended)

1. Go to **GitHub Actions → Release DBDiff → Run workflow**
2. Enter the version number (e.g. `2.1.0` — no `v` prefix)
3. The workflow will:
   - Build the PHAR with Box
   - Build self-contained binaries for all 8 platforms via static-php-cli
   - Publish all `@dbdiff/cli-*` packages to npm (skips any already published)
   - Create or update the GitHub Release with all assets
   - Create the git tag (skipped if it already exists)

### Manual / local

```bash
# Build PHAR + tag
scripts/release.sh v2.1.0
git push origin v2.1.0

# Build Linux binaries locally (requires Podman or Docker)
SKIP_PHAR=1 scripts/release-binaries.sh 2.1.0

# Upload assets to an existing GitHub Release
gh release upload v2.1.0 --clobber \
  dist/dbdiff.phar \
  packages/@dbdiff/cli-linux-x64/dbdiff \
  packages/@dbdiff/cli-linux-x64-musl/dbdiff \
  packages/@dbdiff/cli-linux-arm64/dbdiff \
  packages/@dbdiff/cli-linux-arm64-musl/dbdiff

# Update the Homebrew tap formula
scripts/update-homebrew-formula.sh 2.1.0 ../homebrew-dbdiff
```


## Cross-Version Testing

Test DBDiff locally against any combination of PHP and MySQL:

```bash
# Single combination
./start.sh 8.3 8.0

# All 16 combinations in parallel
./start.sh all all --parallel
```

The CI matrix: **5 PHP × 4 MySQL = 20 jobs**, plus dedicated jobs for SQLite, PostgreSQL, DSN URLs, and Supabase.

See [DOCKER.md](DOCKER.md) for flags covering fast restarts, recording fixtures, and CI usage.


## Questions & Support 💡

- Open a [new issue](https://github.com/dbdiff/dbdiff/issues/new/choose) or check [existing ones](https://github.com/dbdiff/dbdiff/issues)
- For commercial support enquiries, [get in touch](https://akalsoftware.com/)


## Contributions 💖

Please read the [Contributing Guide](https://github.com/dbdiff/dbdiff/blob/master/.github/CONTRIBUTING.md) before submitting a PR.

<a href="https://github.com/dbdiff/dbdiff/graphs/contributors"><img src="https://img.shields.io/github/contributors/dbdiff/dbdiff.svg" /></a>


## Feedback 💬

Could you spare 2 minutes to share your feedback?

[https://forms.gle/gjdJxZxdVsz7BRxg7](https://forms.gle/gjdJxZxdVsz7BRxg7)


## License

[MIT](http://opensource.org/licenses/MIT)

<p align="center">Made with 💖 by<br>
<a href="https://akalsoftware.com/" target="_blank" rel="noopener noreferrer"><img width="150" src="images/akal-logo.svg" alt="Akal Logo"></a></p>
