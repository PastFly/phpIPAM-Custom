#!/usr/bin/env python3
"""Dormancy, source-preservation and no-production-wiring contracts. DOM behavior runs separately."""
from pathlib import Path
import hashlib
import subprocess
ROOT = Path(__file__).resolve().parents[1]
pins = {
    'tools/phase5g4-rollout.py': '11a28455933a2ff2b2bddeb5854ba14f3fea2536',
    'tools/phase5g5-recover.py': '36b19f12e95b2f4884b4f625c755ce3df0db460a',
    'tools/build-phase5g5-runtime.py': '8d580ee281501a21f9f914d5ed35132b56e6630a',
    'layers/phase5g/functions/classes/class.IntegrationVmwareInventoryWorker.php': '915a44497afeabac9b1a1cff06a437341d84f4ad',
    'layers/phase5g3/functions/classes/class.IntegrationVmwareInventorySampler.php': '9cd43836d92e830e6b64414e8ab41d2ef5ba450f',
    'layers/phase5f/functions/classes/class.IntegrationVmwareVcenterPerformance.php': '426c8c32632037d2b2ceaac23ef11eb79a06edd2',
    'layers/phase5c/functions/classes/class.IntegrationVmwareVcenterGuestNetwork.php': 'f4db8a61a937424ce93ec9e5cd00a58ca77bdddd',
}
for path, expected in pins.items():
    raw = (ROOT / path).read_bytes()
    assert hashlib.sha1(b'blob ' + str(len(raw)).encode() + b'\0' + raw).hexdigest() == expected, path
files = sorted((ROOT / 'layers/phase5h/functions/classes').glob('*.php'))
assert len(files) == 3
for path in files:
    subprocess.run(['php', '-l', str(path)], check=True, stdout=subprocess.DEVNULL)
    text = path.read_text()
    for forbidden in ['$_POST', '$_GET', 'file_put_contents(', 'fopen(', 'mysqli', 'Database_PDO', 'systemctl', 'exec(', '->write(']:
        assert forbidden not in text, (path, forbidden)

# PR47 introduced exactly two reviewed, non-serving shadow tools. They may reference the batch
# classes, but their exact blobs are pinned here and their dedicated transaction tests prove that
# they do not wire Phase5H into /var/www/html or the active 5G.5 systemd units/data.
shadow_tools = {
    'tools/install-phase5h4-shadow.py': '249d591396ee88f9b594220c55c4eb672c62dc17',
    'tools/phase5h4-shadow-run.php': '7ecaa1af168933456069e057bd23de2d2a185dcb',
}
for relative, expected in shadow_tools.items():
    raw = (ROOT / relative).read_bytes()
    assert hashlib.sha1(b'blob ' + str(len(raw)).encode() + b'\0' + raw).hexdigest() == expected, relative
    assert 'IntegrationVmwareBatch' in raw.decode(), relative

# No production collector, website route, rollout/recovery engine or timer references the batch
# classes. Only the exact isolated one-shot shadow package above is exempted.
for folder in ['tools', 'deployment', 'layers/phase5g', 'layers/phase5g2', 'layers/phase5g3', 'layers/phase5g4', 'layers/phase5g5']:
    for path in (ROOT / folder).rglob('*'):
        if path.is_file() and path.suffix in ['.php', '.py', '.js', '.service', '.timer']:
            relative = path.relative_to(ROOT).as_posix()
            if relative in shadow_tools:
                continue
            assert 'IntegrationVmwareBatch' not in path.read_text(), path
print('PHASE5H_PRESERVED_PINS=7')
print('PHASE5H_REVIEWED_SHADOW_TOOL_PINS=2')
print('PHASE5H_NO_PRODUCTION_WIRING_OR_PERSISTENCE_OK')
