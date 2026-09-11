#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
exec python3 -B "$ROOT/tools/phase5f35-vmcard-deploy.py" rollback "$@"
