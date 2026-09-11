#!/usr/bin/env python3
"""Generate guarded providers from accepted UTF-8 sources, with explicit audited deltas."""
import hashlib
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
PINS = {
    'layers/phase5e/app/integrations/vmware/workspace-result.php': '408fed89e2b0583013325b08e2959813458c76fe',
    'layers/phase5f33/app/integrations/vmware/enriched-search-result.php': '2307929a9ce7b006d29aa80631b41f808a4ec7a2',
}
def read(rel):
    data = (ROOT / rel).read_bytes()
    if hashlib.sha1(b'blob ' + str(len(data)).encode() + b'\0' + data).hexdigest() != PINS[rel]:
        raise RuntimeError('provider_source_pin_mismatch: ' + rel)
    return data.decode('utf-8')
def between(text, begin, end):
    if text.count(begin) != 1: raise RuntimeError('provider_start_anchor')
    start = text.index(begin)
    return text[start:text.index(end, start)]
def replace(text, old, new):
    if text.count(old) != 1: raise RuntimeError('provider_delta_anchor: ' + old[:60])
    return text.replace(old, new, 1)
def generate():
    workspace = read(list(PINS)[0]); search = read(list(PINS)[1])
    renderer = between(workspace, 'function phase5e_result_h(', '$configId = isset($POST->config_id)')
    helpers = between(search, 'function phase5f33_ui_lower(', 'if (!$User->is_admin(false))')
    body = between(search, '    $RestTransport = new IntegrationVmwareVcenterDirectRestTransport(', "    phase5f33_ui_zero_secret($runtime['password']);")
    body = replace(body, "count($vmIds) === 0 || count($vmIds) > 1000", "count($vmIds) > 1000")
    facts_line = "    $facts = $FactsReader->read_facts($runtime['endpoint'], $runtime['username'], $runtime['password'], $vmIds, !empty($runtime['verify_tls']));"
    body = replace(body, facts_line, """    $facts = array('rows' => array(), 'soap_request_count' => 0, 'partial' => false);
    $factsStatus = 'ok';
    if ($vmIds) {
        try {
""" + facts_line + """
        } catch (Throwable $factError) {
            if (IntegrationVmwareSnapshotService::denied($factError)) throw new IntegrationVmwareSnapshotAccessDenied('search_fact_access_denied');
            $facts['partial'] = true; $factsStatus = 'unavailable';
        }
    }""")
    tag_line = "        $tagIndex = $TagReader->read_index($runtime['endpoint'], $runtime['username'], $runtime['password'], $vmIds, !empty($runtime['verify_tls']));"
    body = replace(body, tag_line, '        if ($vmIds) ' + tag_line.strip())
    body = replace(body, '$tagStatus = phase5f33_ui_category($tagError);', "$tagStatus = phase5f33_ui_category($tagError);\n        if (IntegrationVmwareSnapshotService::denied($tagError)) throw new IntegrationVmwareSnapshotAccessDenied('search_tag_access_denied');")
    # Optional enrichment must never remove an authoritative topology object.
    body = replace(body, "foreach ($facts['rows'] as $vmId => $fact) {", "foreach ($topVms as $vmId => $topVm) {\n        $fact = isset($facts['rows'][$vmId]) ? $facts['rows'][$vmId] : array();")
    body = replace(body, '$rows = array();', '$rows = array(); $placementChanged = false;')
    anchor = "        $memoryMb = isset($fact['memory_mb']) ? (int)$fact['memory_mb'] : 0;"
    body = replace(body, anchor, """        if (isset($fact['host_external_id'], $topVm['host_external_id']) && $fact['host_external_id'] !== $topVm['host_external_id']) {
            $clusterId = null; $dcId = null; $placementChanged = true;
        }
        $memoryMb = isset($fact['memory_mb']) ? (int)$fact['memory_mb'] : (int)($topVm['memory_size_mib'] ?? 0);""")
    body = replace(body, "'num_cpu' => isset($fact['num_cpu']) ? $fact['num_cpu'] : null,", "'num_cpu' => isset($fact['num_cpu']) ? $fact['num_cpu'] : ($topVm['cpu_count'] ?? null),")
    helpers = replace(helpers, '$parts = array();', "$parts = array();\n    phase5f33_ui_add($parts, $topVm['name'] ?? null);\n    phase5f33_ui_add($parts, $topVm['external_id'] ?? null);")
    start = search.index("    phase5f33_ui_json(array(\n        'ok' => true,")
    tail = search[start:search.index('\n} catch (Exception $e)', start)].replace('phase5f33_ui_json(array(', '$payload = array(', 1)
    if not tail.endswith('));'): raise RuntimeError('provider_return_anchor')
    tail = tail[:-3] + ');'
    tail = tail.replace('phase5f33_enriched_search_transient_index', 'phase5f34_shared_search_index')
    tail += "\n    $payload['facts_status'] = $factsStatus;\n    $payload['partial'] = $placementChanged || $tagStatus !== 'ok' || count($facts['rows']) !== count($vmIds) || !empty($facts['partial']);\n    return $payload;\n"
    guard = "<?php\n// Generated from source-pinned providers; no eval or live calls during generation.\nif (!defined('PHPIPAM_VMWARE_CACHE_GUARDED')) { http_response_code(403); return; }\n"
    return {
        'app/integrations/vmware/cache/renderers.php': (guard + renderer).encode(),
        'app/integrations/vmware/cache/search-provider.php': (guard + helpers + '\nfunction phase5f34_build_search(array $runtime, int $configId): array\n{\n' + body + tail + '}\n').encode(),
    }
if __name__ == '__main__':
    import sys
    if len(sys.argv) != 2: raise SystemExit('Usage: build-phase5f34-providers.py OUTPUT_DIRECTORY')
    root = Path(sys.argv[1])
    for rel, data in generate().items():
        p = root / rel; p.parent.mkdir(parents=True, exist_ok=True); p.write_bytes(data)
