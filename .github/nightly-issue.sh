#!/usr/bin/env bash
# Opens one issue per failing nightly job, or comments on the open one. A person closes it after the fix.
# Usage: nightly-issue.sh <job name> [report file]. The text of the report file, if it exists, goes into the issue or comment.
set -euo pipefail
job="$1"
title="Nightly: ${job} failed"
run_url="${GITHUB_SERVER_URL}/${GITHUB_REPOSITORY}/actions/runs/${GITHUB_RUN_ID}"
gh label create nightly --color B60205 --description "A scheduled upstream or release check failed" 2>/dev/null || true
number="$(gh issue list --label nightly --state open --limit 100 --json number,title --jq ".[] | select(.title == \"${title}\") | .number" | head -n 1)"
body="The nightly job \`${job}\` failed: ${run_url}"
report="${2:-}"
if [ -n "${report}" ] && [ -s "${report}" ]; then
  # An issue body holds at most 65536 characters.
  body+=$'\n\n'"$(head -c 60000 "${report}")"
fi
if [ -n "${number}" ]; then
  gh issue comment "${number}" --body "${body}"
else
  gh issue create --title "${title}" --label nightly --body "${body}"
fi
