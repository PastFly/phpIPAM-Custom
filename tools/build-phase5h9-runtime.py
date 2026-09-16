#!/usr/bin/env python3
"""Add only the reviewed H7/H8/H9 local-reader classes to an already built Phase5G.5 package."""
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
RUNTIME_CLASSES = (
    'layers/phase5h2/functions/classes/class.IntegrationVmwareRosterReconciler.php',
    'layers/phase5h3/functions/classes/class.IntegrationVmwareFastStore.php',
    'layers/phase5h7/functions/classes/class.IntegrationVmwareFastReader.php',
    'layers/phase5h7/functions/classes/class.IntegrationVmwareFastMetricCompat.php',
    'layers/phase5h7/functions/classes/class.IntegrationVmwareFastOverlayReader.php',
    'layers/phase5h8/functions/classes/class.IntegrationVmwareInventoryQuerySelector.php',
    'layers/phase5h8/functions/classes/class.IntegrationVmwareFastReaderConfig.php',
    'layers/phase5h8/functions/classes/class.IntegrationVmwareFastOverlayFactory.php',
    'layers/phase5h9/functions/classes/class.IntegrationVmwareFastReaderConfigLoader.php',
)


def generate(package):
    """Pure package overlay; no filesystem mutation outside reading exact repository source."""
    app = dict(package['app'])
    release = dict(package['release'])
    for rel in RUNTIME_CLASSES:
        path = ROOT / rel
        if not path.is_file() or path.is_symlink():
            raise RuntimeError('phase5h9_runtime_source_unavailable:' + rel)
        value = path.read_bytes()
        target = 'functions/classes/' + path.name
        if target in app and app[target] != value:
            raise RuntimeError('phase5h9_runtime_target_collision:' + target)
        app[target] = value
        release[rel] = value

    controller = 'app/integrations/vmware/mirror/native.php'
    native = app.get(controller)
    if not isinstance(native, (bytes, bytearray)):
        raise RuntimeError('phase5h9_native_controller_missing')
    required = [
        b'IntegrationVmwareFastReaderConfigLoader::load()',
        b'IntegrationVmwareFastOverlayFactory($context, $fastConfig, (int)$id)',
        b'->selector($reader)',
    ]
    if any(token not in native for token in required):
        raise RuntimeError('phase5h9_native_controller_not_wired')
    if b'getenv(' in native or b'$_ENV' in native or b'vmware-fast-reader.json' in native:
        raise RuntimeError('phase5h9_native_controller_config_boundary')
    return dict(package, app=app, release=release)
