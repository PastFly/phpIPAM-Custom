#!/usr/bin/env python3
"""Build pure native renderers and dormant route replacements from exact reviewed sources."""
import hashlib
import json
import subprocess
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
SOURCES = {
    'workspace': 'layers/phase5e/app/integrations/vmware/workspace-result.php',
    'vm': 'layers/phase5e2/app/integrations/vmware/vm-card-result.php',
    'host': 'layers/phase5f/app/integrations/vmware/host-card-result.php',
    'annotation': 'layers/phase5f2/app/integrations/vmware/vm-annotation-result.php',
    'host_network': 'layers/phase5f3/app/integrations/vmware/host-network-result.php',
    'network_path': 'layers/phase5f31/app/integrations/vmware/vm-network-path-result.php',
    'performance': 'layers/phase5f/app/integrations/vmware/performance-result.php',
    'storage_performance': 'layers/phase5f1/app/integrations/vmware/storage-performance-result.php',
}
ROUTES = {
    'workspace-result.php': 'workspace', 'folder-result.php': 'host_folders',
    'object-folders-result.php': 'object_folders', 'enriched-search-result.php': 'search',
    'vm-card-result.php': 'vm', 'host-card-result.php': 'host',
    'vm-annotation-result.php': 'annotation', 'host-network-result.php': 'host_network',
    'vm-network-path-result.php': 'network_path', 'vm-guest-facts-result.php': 'guest_facts',
    'performance-result.php': 'performance', 'storage-performance-result.php': 'storage_performance',
    'cache/workspace-result.php': 'workspace', 'cache/folder-result.php': 'host_folders',
    'cache/object-folders-result.php': 'object_folders', 'cache/enriched-search-result.php': 'search',
    'cache/refresh.php': 'status', 'cache/status.php': 'status',
}

def span(text, start, stop):
    if text.count(start) != 1: raise RuntimeError('native_start_anchor: ' + start)
    a = text.index(start)
    b = text.index(stop, a)
    return text[a:b]

def generate():
    pins = json.loads((ROOT / 'design/phase5g2-native-pins.json').read_text())
    if set(pins) != set(SOURCES.values()): raise RuntimeError('native_pin_set')
    texts = {}
    for name, path in SOURCES.items():
        raw = (ROOT / path).read_bytes()
        actual = hashlib.sha1(b'blob ' + str(len(raw)).encode() + b'\0' + raw).hexdigest()
        if actual != pins[path]: raise RuntimeError('native_source_drift: ' + path)
        texts[name] = raw.decode('utf-8')
    out = '<?php\nnamespace PhpIpamMirrorViews;\nuse \\IntegrationVmwareVcenterExplorer;\nuse \\Exception;\nuse \\InvalidArgumentException;\n'
    out += "if (!defined('PHPIPAM_VMWARE_MIRROR_GUARDED')) { http_response_code(403); return; }\n"
    for name, text in texts.items():
        parser = r"""$tokens = token_get_all(stream_get_contents(STDIN));
        $out=[]; $current=''; $depth=0; $started=false;
        foreach ($tokens as $token) {
            if (!$started) { if (!is_array($token) || $token[0] !== T_FUNCTION) continue; $started=true; }
            $current .= is_array($token) ? $token[1] : $token;
            if ($token === '{') $depth++;
            if ($token === '}' && --$depth === 0) { $out[]=$current; $current=''; $started=false; }
        }
        echo json_encode($out, JSON_THROW_ON_ERROR);"""
        functions = json.loads(subprocess.run(['php', '-r', parser], input=text.encode(),
            stdout=subprocess.PIPE, check=True).stdout)
        out += '\n'.join(functions) + '\n'
    specs = {
        'vm': ('$detail, $context, $guestVm, $metadata, $vmId, $includeTags, $AddressLinkResolver', '    $vm = $detail[\'vm\'];'),
        'host': ('$summary, $performance, $hostId', "    $hardware = isset($summary['hardware'])"),
        'annotation': ('$result', "    $annotation = isset($result['annotation'])"),
        'host_network': ('$result', "    $hints = isset($result['hints'])"),
        'network_path': ('$result', "    $nics = isset($result['nics'])"),
        'performance': ('$performance', "    $samples = isset($performance['samples'])"),
        'storage_performance': ('$result', "    if (empty($result['supported']))"),
    }
    for name, (args, start) in specs.items():
        text = texts[name]
        body = span(text, start, '\n} catch (Exception $e) {')
        if name == 'host':
            a = body.index("    if (empty($runtime['verify_tls'])) {")
            b = body.index("    $power =", a)
            body = body[:a] + body[b:]
        body = body.replace('Real-time performance', 'Сохранённые показатели')
        body = body.replace('Текущая загрузка', 'Загрузка на момент сбора')
        body = body.replace('Данные не сохраняются.', 'Сохранённые измерения, не live.')
        body = body.replace(' · не сохраняется.', ' · сохранённый снимок.')
        body = body.replace('persistent_inventory_written=false', 'local_inventory_read=true · vcenter_requests=0')
        # No dynamically evaluated or network-enabled source is emitted.
        out += '\nfunction render_' + name + '(' + args + '): string\n{\n'
        out += '    $ipLinkCache = array();\n    ob_start();\n    try {\n' + body
        out += '\n        return (string)ob_get_clean();\n    } catch (\\Throwable $error) { ob_end_clean(); throw $error; }\n}\n'
    out = out.replace('>сейчас</text>', '>последнее</text>').replace('real-time samples', 'сохранённых измерений')
    # Never advance a saved uptime using the web request time.
    old_uptime = span(out, '    if ($seconds === null && $bootTime !== null)', "    if ($seconds === null) return '—';")
    out = out.replace(old_uptime, '', 1)
    if any(s in out for s in ['->read_', 'curl_exec(', 'load_runtime_config(', '::from_keyring_file(', 'eval(']):
        raise RuntimeError('network_in_renderer')
    result = {'app/integrations/vmware/mirror/native-views.php': out.encode()}
    paths = {'app/integrations/vmware/' + p: op for p, op in ROUTES.items()}
    paths.update({'app/tools/search/vmware-global-result.php': 'search', 'app/tools/search/cache/vmware-global-result.php': 'search'})
    for path, op in paths.items():
        levels = len(Path(path).parent.parts)
        result[path] = ("<?php\n// Source-generated local-only route; no live fallback.\n"
            "define('PHPIPAM_VMWARE_MIRROR_OPERATION', '" + op + "');\n"
            "require dirname(__DIR__, " + str(levels) + ") . '/app/integrations/vmware/mirror/native.php';\n").encode()
    return result

if __name__ == '__main__':
    import sys
    if len(sys.argv) != 2: raise SystemExit('Usage: build-phase5g2-native.py OUTPUT_DIRECTORY')
    for rel, data in generate().items():
        path = Path(sys.argv[1]) / rel
        path.parent.mkdir(parents=True, exist_ok=True); path.write_bytes(data)
