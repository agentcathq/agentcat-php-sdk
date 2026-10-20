#!/usr/bin/env bash
# The package probe: installs agentcat/sdk from its dist in a fresh project, as a customer does, and runs one
# tools/call through a tracked mcp/sdk stdio server. The dist is `git archive HEAD`, so it honors export-ignore like
# Packagist's zip; commit before you run it.
# Usage: tests/bin/package-probe.sh <mcp-sdk-constraint>   (for example 0.8.1, 0.9.0 or "dev-main as 0.9.99")
# Everything stays under .cache/package-probe/, and the probe reaches no host but 127.0.0.1.
set -euo pipefail
constraint="${1:?usage: package-probe.sh <mcp-sdk-constraint>}"
repo="$(cd "$(dirname "$0")/../.." && pwd)"
root="$repo/.cache/package-probe"
dist="$root/dist"
project="$root/project"
home="$root/home"
collector_pid=''
cleanup() {
  if [ -n "$collector_pid" ]; then kill "$collector_pid" 2>/dev/null || true; fi
}
trap cleanup EXIT

rm -rf "$root" && mkdir -p "$dist" "$project" "$home"

# 1. The dist.
cd "$repo"
git archive --format=tar HEAD | tar -x -C "$dist"

# 2. The dist file list.
fail=0
require() { if [ ! -e "$dist/$1" ]; then echo "dist: missing $1" >&2; fail=1; fi; }
forbid() { if compgen -G "$dist/$1" >/dev/null; then echo "dist: must not contain $1" >&2; fail=1; fi; }
for path in src config composer.json README.md LICENSE; do require "$path"; done
# TODO(Task 13): CHANGELOG.md and resources/boost/guidelines/core.blade.php are expected only while git tracks them.
# Task 13 makes both strict: delete the git ls-files conditions below, or set PROBE_STRICT_DIST=1 to try it now.
for path in CHANGELOG.md resources/boost/guidelines/core.blade.php; do
  if [ "${PROBE_STRICT_DIST:-}" = 1 ] || [ -n "$(git ls-files -- "$path")" ]; then require "$path"; fi
done
for path in tests docs recipe .github 'phpstan*.neon*' rector.php infection.json5 phpunit.xml.dist composer-dependency-analyser.php; do
  forbid "$path"
done
[ "$fail" = 0 ] || exit 1

# 3. A fresh project. The probe files are copied in: /tests is export-ignored, so the dist does not hold them.
sed -e "s#__DIST__#$dist#" -e "s#__MCP_SDK__#$constraint#" tests/Fixtures/package-probe/composer.json.tpl >"$project/composer.json"
cp tests/Fixtures/package-probe/probe.php "$project/probe.php"
(cd "$project" && composer install --no-interaction --no-progress)

# 4. A local collector: php -S with a router that appends each request to a file.
cat >"$root/router.php" <<'PHP'
<?php

file_put_contents(
    getenv('AGENTCAT_PROBE_COLLECTOR_LOG') ?: '/dev/null',
    json_encode(['method' => $_SERVER['REQUEST_METHOD'], 'uri' => $_SERVER['REQUEST_URI'], 'body' => file_get_contents('php://input')]) . "\n",
    FILE_APPEND | LOCK_EX,
);
http_response_code(202);
header('Content-Type: application/json');
echo '{"status":"accepted"}';
PHP
free_port() { php -r '$s = stream_socket_server("tcp://127.0.0.1:0"); echo parse_url(stream_socket_get_name($s, false), PHP_URL_PORT);'; }
log="$root/collector.log"
: >"$log"
port="$(free_port)"
AGENTCAT_PROBE_COLLECTOR_LOG="$log" php -S "127.0.0.1:$port" "$root/router.php" >"$root/collector.out" 2>&1 &
collector_pid=$!
for _ in $(seq 1 50); do
  if (exec 3<>"/dev/tcp/127.0.0.1/$port") 2>/dev/null; then break; fi
  sleep 0.1
done
closed_port="$(free_port)" # nothing listens on it

# 5. The probe, with every URL local and a fresh HOME.
cd "$project"
HOME="$home" \
AGENTCAT_API_URL="http://127.0.0.1:$port" \
MCPCAT_API_URL="http://127.0.0.1:$port" \
AGENTCAT_DISABLE_DIAGNOSTICS=true \
AGENTCAT_DIAGNOSTICS_ENDPOINT="http://127.0.0.1:$closed_port" \
AGENTCAT_PROBE_COLLECTOR_LOG="$log" \
  timeout 60 php probe.php
