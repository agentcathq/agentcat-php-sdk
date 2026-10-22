#!/usr/bin/env bash
# Runs PHPStan and the adapter suites against another mcp/sdk in a throwaway copy, so vendor/ stays on 0.8.1.
# Usage: tests/bin/mcp-sdk-leg.sh [constraint]   (default 0.9.0; for example "dev-main as 0.9.99" for the nightly)
# Without network access to GitHub, set MCP_SDK_REPO to a local clone (read-only), for example /home/ubuntu/misc/mcp-php-sdk.
set -euo pipefail
constraint="${1:-0.9.0}"
repo="$(cd "$(dirname "$0")/../.." && pwd)"
copy="$repo/.cache/mcp-sdk-leg"
rm -rf "$copy" && mkdir -p "$copy"
# Copy the tracked files and the untracked ones that git does not ignore. A tracked file deleted in the working tree
# is skipped.
cd "$repo"
git ls-files -z -co --exclude-standard | while IFS= read -r -d '' file; do
  if [ -e "$file" ] || [ -L "$file" ]; then
    printf '%s\0' "$file"
  fi
done | tar --null -T - -cf - | tar -C "$copy" -xf -
cd "$copy"
if [ -n "${MCP_SDK_REPO:-}" ]; then
  composer config repositories.mcp-sdk-leg vcs "$MCP_SDK_REPO"
fi
# Removes symfony/mcp-bundle, which requires mcp/sdk ^0.8.1: the Symfony tests skip without it.
tests/bin/require-library.sh mcp/sdk "$constraint"
vendor/bin/phpstan analyse --no-progress --memory-limit=1G --configuration=phpstan.no-bundle.neon
vendor/bin/phpunit --testsuite "${SUITES:-Contract,Integration,E2E}"
