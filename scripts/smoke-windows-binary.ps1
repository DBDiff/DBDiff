# Smoke-test a Windows dbdiff.exe: it starts, has its database drivers, and
# produces a correct SQLite diff from both an absolute (drive letter) and a
# relative sqlite:// URL.
#
#   pwsh scripts/smoke-windows-binary.ps1 -Exe .\dbdiff.exe
#
# Needs Python on PATH to build the SQLite fixture databases. Used by the
# Windows x64 job and by the Windows ARM64 job, which runs the x64 build under
# Windows 11's x64 emulation.
param(
    [Parameter(Mandatory = $true)][string]$Exe,
    [string]$Fixture = (Join-Path $PSScriptRoot '..\tests\fixtures\basic_schema_data')
)

$ErrorActionPreference = 'Stop'
$Exe = (Resolve-Path $Exe).Path
$Fixture = (Resolve-Path $Fixture).Path

# Write-Host, not Write-Output: inside a function Write-Output becomes part of
# the return value, and a failure message would vanish into a variable.
function Fail([string]$message, [string]$detail = '') {
    Write-Host "::error::$message"
    if ($detail) { Write-Host $detail }
    exit 1
}

Write-Output "Machine: $env:PROCESSOR_ARCHITECTURE  Binary: $Exe"

# ── Starts ───────────────────────────────────────────────────────────────────
$version = (& $Exe --version 2>&1 | Out-String)
if ($LASTEXITCODE -ne 0 -or $version -notmatch 'DBDiff') {
    Fail "dbdiff --version failed (exit $LASTEXITCODE)" $version
}
Write-Output $version.Trim()
& $Exe --help *> $null
if ($LASTEXITCODE -ne 0) { Fail "dbdiff --help failed (exit $LASTEXITCODE)" }

# ── Drivers ──────────────────────────────────────────────────────────────────
# --version passes whether or not a single database driver made it in. PDO
# reports a missing driver as exactly "could not find driver" and an
# unreachable server as a connection error, so a closed port tells the two
# apart without needing a database.
foreach ($scheme in @('postgresql', 'mysql')) {
    $out = (& $Exe diff `
        --server1-url "${scheme}://u:p@127.0.0.1:1/x" `
        --server2-url "${scheme}://u:p@127.0.0.1:1/y" 2>&1 | Out-String)
    if ($out -match 'could not find driver') { Fail "$scheme driver missing from the binary" $out }
    Write-Output "$scheme driver present"
}

# ── A real diff ──────────────────────────────────────────────────────────────
$work = Join-Path ([System.IO.Path]::GetTempPath()) ("dbdiff-smoke-" + [guid]::NewGuid())
New-Item -ItemType Directory -Path $work | Out-Null

$build = @'
import sqlite3, sys
src, dst = sys.argv[1], sys.argv[2]
db = sqlite3.connect(dst)
db.executescript(open(src, encoding="utf-8").read())
db.commit()
db.close()
'@
foreach ($n in 1, 2) {
    python -c $build (Join-Path $Fixture "db$n-sqlite.sql") (Join-Path $work "db$n.db")
    if ($LASTEXITCODE -ne 0) { Fail "could not build the db$n SQLite fixture" }
}

$expected = @(
    'ALTER TABLE "users" DROP COLUMN "phone";',
    'UPDATE "posts" SET "published" = ''0'' WHERE "id" = ''2'';',
    'INSERT INTO "users" ("id","name","email","status","created_at") VALUES(''3'',''Bob Wilson'',''bob@example.com'',''inactive'',''2024-01-01 00:00:00'');'
)

function Invoke-Diff([string]$label, [string]$url1, [string]$url2) {
    $sql = Join-Path $work "$label.sql"
    $out = (& $Exe diff --server1-url $url1 --server2-url $url2 `
        --type=all --include=all --allow-destructive --nocomments --output=$sql 2>&1 | Out-String)
    if ($LASTEXITCODE -ne 0) { Fail "SQLite diff ($label) exited $LASTEXITCODE" $out }
    if (-not (Test-Path $sql)) { Fail "SQLite diff ($label) wrote no output" $out }
    $text = Get-Content -Raw $sql
    foreach ($line in $expected) {
        if (-not $text.Contains($line)) { Fail "SQLite diff ($label) is missing: $line" $text }
    }
    Write-Host "SQLite diff ($label) correct"
    # Version and timestamp lines differ run to run.
    return (Get-Content $sql | Where-Object { $_ -notmatch '^-- (Version|Generated):' }) -join "`n"
}

# Absolute: sqlite:///C:/…/db1.db — the drive letter must survive the URL.
$abs1 = 'sqlite:///' + ((Join-Path $work 'db1.db') -replace '\\', '/')
$abs2 = 'sqlite:///' + ((Join-Path $work 'db2.db') -replace '\\', '/')
$absolute = Invoke-Diff 'absolute' $abs1 $abs2

Push-Location $work
try {
    $relative = Invoke-Diff 'relative' 'sqlite://./db1.db' 'sqlite://./db2.db'
} finally {
    Pop-Location
}

if ($absolute -ne $relative) { Fail 'absolute and relative SQLite URLs produced different diffs' }

Remove-Item -Recurse -Force $work
Write-Output 'Windows binary smoke test passed'
exit 0
