#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
export PYTHONDONTWRITEBYTECODE=1
exec python3 "$ROOT/tools/live-vmware-phase5f34-cache-preflight.py" "${1:-/var/www/html}"
