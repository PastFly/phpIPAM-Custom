#!/usr/bin/env bash
set -euo pipefail

# phpIPAM Custom — Phase 5F.2.4 diagnostic launcher.
# The live CLI environment is known to inherit HTTP(S)_PROXY while php-fpm
# does not necessarily share that network path.  Run the diagnostic with all
# conventional proxy variables removed so it exercises the vCenter directly.

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
DIAG="${SCRIPT_DIR}/live-vmware-phase5f24-vmcard-diagnostic.php"

if [[ ! -f "$DIAG" ]]; then
    echo "ERROR: diagnostic script not found: $DIAG" >&2
    exit 2
fi

printf 'PHASE5F24_DIRECT_ENV_HTTP_PROXY=%s\n' "${HTTP_PROXY:+SET}${HTTP_PROXY:-UNSET}" | sed -E 's/SET.*/SET/' >&2
printf 'PHASE5F24_DIRECT_ENV_HTTPS_PROXY=%s\n' "${HTTPS_PROXY:+SET}${HTTPS_PROXY:-UNSET}" | sed -E 's/SET.*/SET/' >&2
printf 'PHASE5F24_DIRECT_ENV_ALL_PROXY=%s\n' "${ALL_PROXY:+SET}${ALL_PROXY:-UNSET}" | sed -E 's/SET.*/SET/' >&2
printf 'PHASE5F24_DIRECT_TRANSPORT=ENV_NO_PROXY\n' >&2
printf 'PHASE5F24_DIRECT_PERSISTENCE=0\n' >&2
printf 'PHASE5F24_DIRECT_VCENTER_MUTATIONS=0\n' >&2

exec env \
    -u HTTP_PROXY -u HTTPS_PROXY -u ALL_PROXY \
    -u http_proxy -u https_proxy -u all_proxy \
    php "$DIAG" "$@"
