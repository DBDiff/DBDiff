#!/usr/bin/env bash
# Point npm's `latest` dist-tag of every @dbdiff package at an already
# published version — e.g. to promote a release candidate once it has been
# tested downstream:
#
#   scripts/npm-promote-latest.sh 3.0.0-rc.18
#
# The platform packages move first and the main package last, so `latest` of
# @dbdiff/cli never points at a version whose platform binaries are not also
# `latest`. Authenticates with $NPM_TOKEN when set, otherwise with whatever
# `npm login` left behind. Nothing is published; only tags move.
set -euo pipefail

version="${1:?usage: $0 <version>   e.g. 3.0.0-rc.18}"
tag="${2:-latest}"
root="$(cd "$(dirname "$0")/.." && pwd)"

npm_args=()
if [[ -n "${NPM_TOKEN:-}" ]]; then
  userconfig="$(mktemp)"
  trap 'rm -f "$userconfig"' EXIT
  printf '//registry.npmjs.org/:_authToken=%s\n' "$NPM_TOKEN" > "$userconfig"
  npm_args=(--userconfig "$userconfig")
fi

packages=()
for dir in "$root"/packages/@dbdiff/cli-*/; do
  packages+=("@dbdiff/$(basename "$dir")")
done
packages+=("@dbdiff/cli")

echo "Moving '$tag' to $version as $(npm whoami "${npm_args[@]}")"
for pkg in "${packages[@]}"; do
  if ! npm view "$pkg@$version" version >/dev/null 2>&1; then
    echo "  $pkg@$version is not published; stopping before anything else moves" >&2
    exit 1
  fi
done
for pkg in "${packages[@]}"; do
  npm dist-tag add "$pkg@$version" "$tag" "${npm_args[@]}"
done

echo "Registry now says:"
for pkg in "${packages[@]}"; do
  printf '  %-30s %s\n' "$pkg" "$(curl -fsS "https://registry.npmjs.org/-/package/$pkg/dist-tags")"
done
