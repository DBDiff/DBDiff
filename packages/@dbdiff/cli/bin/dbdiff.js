#!/usr/bin/env node
'use strict';

// This is the only JavaScript in the entire @dbdiff/cli distribution.
// It detects the current platform, resolves the matching pre-built binary
// from the appropriate optional-dependency package, and executes it.
//
// Pattern mirrors @biomejs/biome and @tailwindcss/oxide — no PHP required.

const { execFileSync } = require('child_process');
const path = require('path');

const PLATFORM_PACKAGES = {
  'linux-x64':        '@dbdiff/cli-linux-x64',
  'linux-arm64':      '@dbdiff/cli-linux-arm64',
  'linux-x64-musl':   '@dbdiff/cli-linux-x64-musl',
  'linux-arm64-musl': '@dbdiff/cli-linux-arm64-musl',
  'darwin-x64':       '@dbdiff/cli-darwin-x64',
  'darwin-arm64':     '@dbdiff/cli-darwin-arm64',
  'win32-x64':        '@dbdiff/cli-win32-x64',
  'win32-arm64':      '@dbdiff/cli-win32-arm64',
};

/**
 * Whether this Linux uses musl (Alpine) rather than glibc.
 *
 * Node reports the glibc it runs on, and nothing on musl — the check
 * detect-libc relies on. This used to read /proc/version, which in a
 * container describes the host's kernel, and `ldd --version`, which on
 * Alpine exits non-zero: execSync threw, the answer was "glibc", and every
 * Alpine install — every node:alpine image — fell back to PHP.
 */
function isMusl() {
  const fs = require('fs');
  try {
    if (process.report) {
      process.report.excludeNetwork = true; // skip the slow network lookups
      const header = process.report.getReport().header;
      if (header) return !header.glibcVersionRuntime;
    }
  } catch {
    // fall through to the filesystem
  }
  try {
    if (fs.existsSync('/etc/alpine-release')) return true;
    return fs.readdirSync('/lib').some((name) => name.startsWith('ld-musl-'));
  } catch {
    return false;
  }
}

function getPlatformKeys() {
  const platform = process.platform; // 'linux', 'darwin', 'win32'
  const arch = process.arch;         // 'x64', 'arm64'
  if (platform !== 'linux') return [`${platform}-${arch}`];

  // Both Linux builds are fully static, so either runs anywhere: prefer the
  // one built for this libc, and take the other if only it was installed —
  // npm installs by the libc it detects, which need not agree with ours.
  const glibc = `linux-${arch}`;
  const musl = `linux-${arch}-musl`;
  return isMusl() ? [musl, glibc] : [glibc, musl];
}

function getBinaryPath(pkg) {
  const fs = require('fs');
  const binaryName = process.platform === 'win32' ? 'dbdiff.exe' : 'dbdiff';
  try {
    const pkgDir = path.dirname(require.resolve(`${pkg}/package.json`));
    const binPath = path.join(pkgDir, binaryName);
    // Return null when the platform package is installed but contains no binary
    // (can happen if a platform build failed during release).
    if (!fs.existsSync(binPath)) return null;
    // An archive tool that drops permissions (some unzip implementations,
    // bundles copied across filesystems) leaves the binary unrunnable.
    if (process.platform !== 'win32') {
      try {
        fs.accessSync(binPath, fs.constants.X_OK);
      } catch {
        fs.chmodSync(binPath, 0o755);
      }
    }
    return binPath;
  } catch {
    return null;
  }
}

const keys = getPlatformKeys();
const candidates = keys.map((key) => PLATFORM_PACKAGES[key]).filter(Boolean);
const pkg = candidates[0] ?? null;

if (!pkg) {
  process.stderr.write(
    `@dbdiff/cli: Unsupported platform: ${process.platform}-${process.arch}\n` +
    `Please open an issue at https://github.com/DBDiff/DBDiff/issues\n`
  );
  process.exit(1);
}

const binaryPath = candidates.map(getBinaryPath).find(Boolean) ?? null;

// If no native binary is available, fall back to the PHAR bundled in this
// package and run it with system PHP.  DBDiff targets PHP developers so php
// is expected to be in PATH on any machine where it isn't available natively
// (e.g. win32-x64 while the Windows static build is being stabilised).
if (!binaryPath) {
  const fs = require('fs');
  const pharPath = path.join(__dirname, '..', 'dbdiff.phar');

  if (fs.existsSync(pharPath)) {
    const phpExe = process.platform === 'win32' ? 'php.exe' : 'php';
    try {
      execFileSync(phpExe, [pharPath, ...process.argv.slice(2)], { stdio: 'inherit' });
    } catch (err) {
      if (err.code === 'ENOENT') {
        process.stderr.write(
          `@dbdiff/cli: No native binary is available for ${process.platform}-${process.arch}.\n` +
          `Attempted PHAR fallback but 'php' was not found in PATH.\n` +
          `Install PHP 8.1+ from https://www.php.net/downloads and add it to your PATH,\n` +
          `or open an issue at https://github.com/DBDiff/DBDiff/issues\n`
        );
        process.exit(1);
      }
      process.exit(err.status ?? 1);
    }
  } else {
    process.stderr.write(
      `@dbdiff/cli: Could not locate the binary for ${process.platform}-${process.arch}.\n` +
      `The platform-specific package '${pkg}' may not have been installed.\n` +
      `Try reinstalling: npm install -g @dbdiff/cli\n`
    );
    process.exit(1);
  }
} else {
  try {
    execFileSync(binaryPath, process.argv.slice(2), { stdio: 'inherit' });
  } catch (err) {
    process.exit(err.status ?? 1);
  }
}
