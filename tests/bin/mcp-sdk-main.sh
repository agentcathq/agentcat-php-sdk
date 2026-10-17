#!/usr/bin/env bash
# Runs PHPStan and the adapter suites against mcp/sdk main (82e19d1) in a throwaway copy, so vendor/ stays on 0.8.1.
# Without network access to GitHub, set MCP_SDK_REPO to a local clone (read-only), for example /home/ubuntu/misc/mcp-php-sdk.
set -euo pipefail
repo="$(cd "$(dirname "$0")/../.." && pwd)"
copy="$repo/.cache/mcp-sdk-main"
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
  composer config repositories.mcp-sdk-main vcs "$MCP_SDK_REPO"
fi
composer require --dev --no-interaction --update-with-all-dependencies "mcp/sdk:dev-main#82e19d114e95671e05e7df7909195f7ace03d90e as 0.9.0"
vendor/bin/phpstan analyse --no-progress --memory-limit=1G
vendor/bin/phpunit --testsuite "${SUITES:-Contract,Integration}"
