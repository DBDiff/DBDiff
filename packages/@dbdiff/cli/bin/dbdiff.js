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
 * Returns true when the current Linux system is musl-based (e.g. Alpine).
 * Checks /proc/version and ldd output — both are available on Alpine.
 */
function isMusl() {
  // Fast path: check /proc/version which contains "musl" on Alpine kernels
  try {
    const fs = require('fs');
    const procVersion = fs.readFileSync('/proc/version', 'utf8');
    if (procVersion.toLowerCase().includes('musl')) return true;
  } catch {
    // /proc/version may be absent in some minimal environments
  }

  // Fallback: ask ldd
  try {
    const { execSync } = require('child_process');
    const ldd = execSync('ldd --version 2>&1', { encoding: 'utf8', timeout: 2000 });
    return ldd.toLowerCase().includes('musl');
  } catch {
    return false;
  }
}

function getPlatformKey() {
  const platform = process.platform; // 'linux', 'darwin', 'win32'
  const arch = process.arch;         // 'x64', 'arm64'
  let key = `${platform}-${arch}`;

  // On Linux, distinguish musl (Alpine) from glibc (Ubuntu/Debian/RHEL)
  if (platform === 'linux' && isMusl()) {
    key = `linux-${arch}-musl`;
  }

  return key;
}

function getBinaryPath(pkg) {
  const binaryName = process.platform === 'win32' ? 'dbdiff.exe' : 'dbdiff';
  try {
    const pkgDir = path.dirname(require.resolve(`${pkg}/package.json`));
    const binPath = path.join(pkgDir, binaryName);
    // Return null when the platform package is installed but contains no binary
    // (can happen if a platform build failed during release).
    return require('fs').existsSync(binPath) ? binPath : null;
  } catch {
    return null;
  }
}

const key = getPlatformKey();
const pkg = PLATFORM_PACKAGES[key] ?? null;

if (!pkg) {
  process.stderr.write(
    `@dbdiff/cli: Unsupported platform: ${process.platform}-${process.arch}\n` +
    `Please open an issue at https://github.com/DBDiff/DBDiff/issues\n`
  );
  process.exit(1);
}

const binaryPath = getBinaryPath(pkg);

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
