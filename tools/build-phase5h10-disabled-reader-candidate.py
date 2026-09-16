#!/usr/bin/env python3
"""Build a pinned, disabled Phase5H reader overlay bundle without mutating runtime state."""
from __future__ import annotations

import hashlib
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
ACCEPTED_H9_HEAD = 'db218eb391b7399a4c51b95a86f18a3c2778d44d'
INTENDED_CONFIG_TARGET = '/etc/phpipam-custom/vmware-fast-reader.json'
RECOVERY_CONFIG = '/etc/phpipam-custom/vmware-inventory-recovery5g5.json'
RECOVERY_DATA = '/var/lib/phpipam-custom/vmware-inventory-recovery5g5'
SHADOW_ROOT = '/var/lib/phpipam-custom/vmware-fast-shadow'

# Exact accepted H9-stack source blobs. H10 must fail on source drift rather than silently package it.
SOURCE_BLOBS = {
    'layers/phase5g/functions/classes/class.IntegrationVmwareInventoryContext.php': 'fb4371237e36046c93bfe6098df1c5a55cc4f99c',
    'layers/phase5g2/functions/classes/class.IntegrationVmwareInventoryNative.php': '5f15f736d2b6bf39200f99506c74611d63a4096d',
    'layers/phase5g2/app/integrations/vmware/mirror/native.php': '018e5bd23c5849bf2d8933c43277028a5ebc2a35',
    'layers/phase5h2/functions/classes/class.IntegrationVmwareRosterReconciler.php': 'f463dcc679f70a44ddb26ae4f2d9f32f5c9f6f8a',
    'layers/phase5h3/functions/classes/class.IntegrationVmwareFastStore.php': '380de222f7d437406ea8aa14807b09bbafa581b1',
    'layers/phase5h7/functions/classes/class.IntegrationVmwareFastReader.php': 'b2d8581bc16bd385a095b6b3da59ba7932184c90',
    'layers/phase5h7/functions/classes/class.IntegrationVmwareFastMetricCompat.php': '5a4ab35f298b9d48e17d948cf10a6eb38f11a36b',
    'layers/phase5h7/functions/classes/class.IntegrationVmwareFastOverlayReader.php': '5544e9dbf5d590d5939cda77c9f85defc8b09938',
    'layers/phase5h8/functions/classes/class.IntegrationVmwareInventoryQuerySelector.php': '961f9056bbb49b7533e80aacc241f608aa2950fe',
    'layers/phase5h8/functions/classes/class.IntegrationVmwareFastReaderConfig.php': 'a03dc90e507528ee411f595a86e1e70cc621f6a5',
    'layers/phase5h8/functions/classes/class.IntegrationVmwareFastOverlayFactory.php': '540626def3731897131a301f6bd76f202fe0b43d',
    'layers/phase5h9/functions/classes/class.IntegrationVmwareFastReaderConfigLoader.php': 'df0f69f926d11d272a1c268caaceaf91f3ccf3e4',
}

TARGETS = {
    'functions/classes/class.IntegrationVmwareInventoryContext.php': 'layers/phase5g/functions/classes/class.IntegrationVmwareInventoryContext.php',
    'functions/classes/class.IntegrationVmwareInventoryNative.php': 'layers/phase5g2/functions/classes/class.IntegrationVmwareInventoryNative.php',
    'app/integrations/vmware/mirror/native.php': 'layers/phase5g2/app/integrations/vmware/mirror/native.php',
    'functions/classes/class.IntegrationVmwareRosterReconciler.php': 'layers/phase5h2/functions/classes/class.IntegrationVmwareRosterReconciler.php',
    'functions/classes/class.IntegrationVmwareFastStore.php': 'layers/phase5h3/functions/classes/class.IntegrationVmwareFastStore.php',
    'functions/classes/class.IntegrationVmwareFastReader.php': 'layers/phase5h7/functions/classes/class.IntegrationVmwareFastReader.php',
    'functions/classes/class.IntegrationVmwareFastMetricCompat.php': 'layers/phase5h7/functions/classes/class.IntegrationVmwareFastMetricCompat.php',
    'functions/classes/class.IntegrationVmwareFastOverlayReader.php': 'layers/phase5h7/functions/classes/class.IntegrationVmwareFastOverlayReader.php',
    'functions/classes/class.IntegrationVmwareInventoryQuerySelector.php': 'layers/phase5h8/functions/classes/class.IntegrationVmwareInventoryQuerySelector.php',
    'functions/classes/class.IntegrationVmwareFastReaderConfig.php': 'layers/phase5h8/functions/classes/class.IntegrationVmwareFastReaderConfig.php',
    'functions/classes/class.IntegrationVmwareFastOverlayFactory.php': 'layers/phase5h8/functions/classes/class.IntegrationVmwareFastOverlayFactory.php',
    'functions/classes/class.IntegrationVmwareFastReaderConfigLoader.php': 'layers/phase5h9/functions/classes/class.IntegrationVmwareFastReaderConfigLoader.php',
}


def git_blob(raw: bytes) -> str:
    return hashlib.sha1(b'blob ' + str(len(raw)).encode() + b'\0' + raw).hexdigest()


def sha256(raw: bytes) -> str:
    return hashlib.sha256(raw).hexdigest()


def source(rel: str) -> bytes:
    path = ROOT / rel
    if not path.is_file() or path.is_symlink():
        raise RuntimeError('phase5h10_source_unavailable:' + rel)
    raw = path.read_bytes()
    if git_blob(raw) != SOURCE_BLOBS.get(rel):
        raise RuntimeError('phase5h10_source_blob_drift:' + rel)
    return raw


def recovery_context(raw: bytes) -> bytes:
    text = raw.decode('utf-8')
    old_config = '/etc/phpipam-custom/vmware-inventory.json'
    old_data = '/var/lib/phpipam-custom/vmware-inventory'
    if text.count(old_config) != 1 or text.count(old_data) != 1:
        raise RuntimeError('phase5h10_context_recovery_anchor_drift')
    text = text.replace(old_config, RECOVERY_CONFIG, 1).replace(old_data, RECOVERY_DATA, 1)
    if 'public function fastStore(string $root): IntegrationVmwareFastStore' not in text or SHADOW_ROOT not in text:
        raise RuntimeError('phase5h10_context_faststore_boundary_missing')
    return text.encode('utf-8')


def disabled_config() -> bytes:
    config = {
        'schema': 1,
        'enabled': False,
        'connector_id': 1,
        'root': SHADOW_ROOT,
        'max_property_age': 300,
        'max_metric_age': 300,
        'max_roster_age': 300,
    }
    raw = (json.dumps(config, sort_keys=True, separators=(',', ':')) + '\n').encode('utf-8')
    if b'"enabled":false' not in raw or b'"enabled":true' in raw:
        raise RuntimeError('phase5h10_config_not_disabled')
    return raw


def generate() -> dict:
    if len(TARGETS) != 12 or set(TARGETS.values()) != set(SOURCE_BLOBS):
        raise RuntimeError('phase5h10_source_target_manifest_drift')

    overlay = {}
    records = {}
    for target, rel in TARGETS.items():
        raw = source(rel)
        if target.endswith('class.IntegrationVmwareInventoryContext.php'):
            raw = recovery_context(raw)
        overlay[target] = raw
        records[target] = {
            'source': rel,
            'source_blob': SOURCE_BLOBS[rel],
            'sha256': sha256(raw),
            'bytes': len(raw),
        }

    native = overlay['functions/classes/class.IntegrationVmwareInventoryNative.php']
    route = overlay['app/integrations/vmware/mirror/native.php']
    if b"is_callable([$reader, 'query'])" not in native:
        raise RuntimeError('phase5h10_native_query_boundary_missing')
    for token in [
        b'IntegrationVmwareFastReaderConfigLoader::load()',
        b'IntegrationVmwareFastOverlayFactory($context, $fastConfig, (int)$id)',
        b'->selector($reader)',
    ]:
        if token not in route:
            raise RuntimeError('phase5h10_route_boundary_missing')
    for token in [b'getenv(', b'$_ENV', b'IntegrationSecretCipher', b'curl_exec(', b'SoapClient']:
        if token in route:
            raise RuntimeError('phase5h10_route_forbidden_dependency')

    config = disabled_config()
    manifest = {
        'schema': 1,
        'source_head': ACCEPTED_H9_HEAD,
        'create_only': True,
        'serving_cutover': False,
        'website_mutation': False,
        'systemd_mutation': False,
        'database_mutation': False,
        'connector_tls_mutation': False,
        'provider_calls': False,
        'intended_config_target': INTENDED_CONFIG_TARGET,
        'config_sha256': sha256(config),
        'config_enabled': False,
        'overlay': records,
    }
    manifest_raw = (json.dumps(manifest, sort_keys=True, separators=(',', ':')) + '\n').encode('utf-8')
    files = {'overlay/' + target: raw for target, raw in overlay.items()}
    files['config/vmware-fast-reader.json'] = config
    files['manifest.json'] = manifest_raw
    return {'files': files, 'manifest': manifest}


if __name__ == '__main__':
    import sys
    if len(sys.argv) != 2:
        raise SystemExit('Usage: build-phase5h10-disabled-reader-candidate.py OUTPUT_DIRECTORY')
    out = Path(sys.argv[1])
    if out.exists():
        raise SystemExit('output already exists')
    bundle = generate()
    for rel, raw in bundle['files'].items():
        path = out / rel
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(raw)
