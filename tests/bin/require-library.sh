#!/usr/bin/env bash
# Installs one version of an MCP library over the installed dependencies, for the `released` jobs of ci.yml and
# nightly.yml (D72) and for tests/bin/mcp-sdk-leg.sh. PHPStan then reads phpstan.no-bundle.neon after an mcp/sdk
# install, which removes symfony/mcp-bundle, and phpstan.neon.dist after the others.
# Usage: tests/bin/require-library.sh <mcp/sdk|laravel/mcp|symfony/mcp-bundle> <constraint>   (for example 0.9.0)
set -euo pipefail
library="${1:?usage: require-library.sh <mcp/sdk|laravel/mcp|symfony/mcp-bundle> <constraint>}"
constraint="${2:?usage: require-library.sh <mcp/sdk|laravel/mcp|symfony/mcp-bundle> <constraint>}"
case "$library" in
  mcp/sdk)
    # symfony/mcp-bundle requires mcp/sdk ^0.8.1, which 0.9 and main do not satisfy: the Symfony tests skip without
    # it, and the bundle's own rows test it.
    composer remove --dev --no-update symfony/mcp-bundle
    composer require --dev --no-update "mcp/sdk:${constraint}"
    ;;
  laravel/mcp)
    composer require --dev --no-update "laravel/mcp:${constraint}"
    ;;
  symfony/mcp-bundle)
    # The bundle chooses its mcp/sdk, so a release that needs a newer mcp/sdk than require-dev allows still installs.
    composer require --dev --no-update "symfony/mcp-bundle:${constraint}" "mcp/sdk:*"
    ;;
  *)
    echo "require-library.sh: unknown library ${library}" >&2
    exit 2
    ;;
esac
# Every root package can move, so a release that needs a newer shared dependency still installs. When this update
# fails, require-dev does not allow that release yet (for example a new Laravel or Symfony major).
composer update --no-interaction
