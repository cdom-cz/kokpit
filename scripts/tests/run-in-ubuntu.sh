#!/usr/bin/env bash
# run-in-ubuntu.sh - run the quick test suite inside ubuntu:24.04 (mawk, GNU grep, bash 5.2) via Docker.
#
# Why: CI runs on Ubuntu where awk is mawk, whose regex engine rejects constructs that BWK awk (macOS) and
# gawk accept. This helper proves the scanner on that engine before CI does.
# The repository is mounted read-only, so the container can never write to the host checkout.
# Exit codes: the container's status, or 2 when the Docker daemon is not available.
set -eu

root=$(cd "$(dirname "$0")/../.." && pwd)

if ! docker info > /dev/null 2>&1; then
  echo 'run-in-ubuntu: Docker daemon not available (start OrbStack or Docker)' >&2
  exit 2
fi

exec docker run --rm -v "$root:/w:ro" -w /w ubuntu:24.04 bash -c '
  set -eu
  export DEBIAN_FRONTEND=noninteractive
  apt-get update -qq > /dev/null
  apt-get install -y -qq --no-install-recommends git ca-certificates > /dev/null
  git config --global --add safe.directory /w
  awk -W version 2>&1 | head -n 1
  bash scripts/tests/run.sh --quick
'
