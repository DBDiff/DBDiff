# @dbdiff/cli

> Compare MySQL, Postgres or SQLite databases and automatically create schema & data change migrations — no PHP required.

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

# Full diff (schema + data) with Supabase Postgres
dbdiff --supabase --server1=user:pass@host:5432 db1:db2

# Generate migration files
dbdiff --type=both --output=migrations/ server1.db1:server2.db2
```

## How it works

`@dbdiff/cli` distributes a **platform-native self-contained binary** — a
static PHP interpreter with all required extensions baked in, combined with
the DBDiff PHAR. There is no PHP installation, no Composer, and no runtime
dependencies required on the end-user machine.

npm automatically downloads only the binary for your platform (~10–15 MB):

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
