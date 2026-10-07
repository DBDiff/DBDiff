# @dbdiff/cli

> Compare MySQL, Postgres or SQLite databases and automatically create schema & data change migrations — no PHP required.

DBDiff reads two databases and writes the migration between them: schema and
data, up and down, as plain SQL or for Flyway, Liquibase, Laravel or a custom
template. On
PostgreSQL it covers tables, partitions, views, materialized views, functions,
triggers, types, domains, sequences, row level security, extensions and
comments, across one schema or all of them. Every migration is built to apply
as written and leave the target identical to the source.

## Install

```bash
# Global install
npm install -g @dbdiff/cli

# One-off via npx
npx @dbdiff/cli --help

# Project dev dependency
npm install --save-dev @dbdiff/cli

# Install from GitHub Packages (mirror registry)
npm install -g @dbdiff/cli --registry=https://npm.pkg.github.com
```

## Usage

```bash
# Schema diff between two MySQL databases
dbdiff server1.db1:server2.db2

# Two PostgreSQL databases by URL, schema and data, with the DOWN too
dbdiff diff --server1-url='postgres://user:pass@host1:5432/app' \
            --server2-url='postgres://user:pass@host2:5432/app' \
            --type=all --include=both --output=migration.sql

# Every schema but Supabase's own
dbdiff diff --supabase --server1-url=... --server2-url=... \
            --ignore-schemas='auth,storage,realtime,vault,extensions,graphql*,supabase_*'

# Flyway-style files in a directory
dbdiff --format=flyway --description=add_users --output=./sql/ server1.db1:server2.db2
```

Run `dbdiff --help` for every flag, or see the
[full documentation](https://github.com/DBDiff/DBDiff#command-line-api).

## How it works

`@dbdiff/cli` distributes a **platform-native self-contained binary** — a
static PHP interpreter with all required extensions baked in, combined with
the DBDiff PHAR. There is no PHP installation, no Composer, and no runtime
dependencies required on the end-user machine.

npm downloads only the binary for your platform:

| Platform | Package |
|---|---|
| Linux x64 (glibc) | `@dbdiff/cli-linux-x64` |
| Linux arm64 (glibc) | `@dbdiff/cli-linux-arm64` |
| Linux x64 (musl/Alpine) | `@dbdiff/cli-linux-x64-musl` |
| Linux arm64 (musl/Alpine) | `@dbdiff/cli-linux-arm64-musl` |
| macOS Intel | `@dbdiff/cli-darwin-x64` |
| macOS Apple Silicon | `@dbdiff/cli-darwin-arm64` |
| Windows x64 | `@dbdiff/cli-win32-x64` |
| Windows arm64 | `@dbdiff/cli-win32-arm64` |

## Links

- [Full documentation](https://github.com/DBDiff/DBDiff)
- [Issue tracker](https://github.com/DBDiff/DBDiff/issues)
- [Changelog](https://github.com/DBDiff/DBDiff/releases)

## License

MIT
