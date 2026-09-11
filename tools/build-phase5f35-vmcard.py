#!/usr/bin/env python3
"""Generate the additive optimized endpoint from the exact installed card source."""
import hashlib
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
PINS = {
    'layers/phase5e2/app/integrations/vmware/vm-card-result.php': 'd1a6f3fba93d35054af7d6e05455b557ee5dbd18',
    'tools/phase5f24-patch-vmcard.php': '526267e7f46508a1580d0a8890b0c51a46700271',
}

def blob(value):
    return hashlib.sha1(b'blob ' + str(len(value)).encode() + b'\0' + value).hexdigest()

def read(path):
    value = (ROOT / path).read_bytes()
    if blob(value) != PINS[path]: raise RuntimeError('vmcard_source_pin_mismatch: ' + path)
    return value.decode('utf-8')

def replace(text, old, new):
    if text.count(old) != 1: raise RuntimeError('vmcard_anchor_mismatch: ' + old[:60])
    return text.replace(old, new, 1)

def generate():
    text = read(next(iter(PINS)))
    patcher = read('tools/phase5f24-patch-vmcard.php')
    def heredoc(name):
        marker = '$' + name + " = <<<'PHP'\n"
        return patcher.split(marker, 1)[1].split('\nPHP;', 1)[0]
    text = replace(text, heredoc('needle'), heredoc('replacement'))
    if blob(text.encode()) != '175265cf2698d17b5f7d0d396a8440cd91ed1bd1':
        raise RuntimeError('installed_vmcard_composition_mismatch')
    text = replace(text, ' * Uses the Phase 5D read-only collectors; no persistence or mutation.',
        ' * Phase 5F.3.5: shared encrypted core context; individual VM reads stay live.')
    text = replace(text, 'disable_php_errors();', '''disable_php_errors();
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, private, max-age=0');
header('X-Content-Type-Options: nosniff');''')
    anchor = "$csrf = isset($POST->csrf_cookie) ? (string)$POST->csrf_cookie : '';"
    text = replace(text, anchor, '''// PHPIPAM_CUSTOM_VMWARE_PHASE5F35_CONTEXT_V1
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405); print '<div class="alert alert-danger">POST required.</div>'; exit;
}
foreach (array('config_id', 'csrf_cookie', 'vm_id', 'include_tags', 'force_refresh') as $field) {
    if (isset($POST->$field) && !is_string($POST->$field) && !is_int($POST->$field)) {
        http_response_code(400); print '<div class="alert alert-danger">Invalid request.</div>'; exit;
    }
}
''' + anchor)
    text = replace(text, r"if ($vmId === '' || strlen($vmId) > 1024 || preg_match('/[\x00-\x20\x7F\/?#]/', $vmId)) {",
        "if (!preg_match('/^vm-[0-9]{1,20}$/D', $vmId)) {")
    text = replace(text, "$includeTags = phase5e_vmcard_post_bool($POST, 'include_tags');",
        "$includeTags = phase5e_vmcard_post_bool($POST, 'include_tags');\n    $forceContext = phase5e_vmcard_post_bool($POST, 'force_refresh');")
    text = replace(text, '$runtime = null;\nob_start();', '''$runtime = null; $Service = null;
$phase5f35Start = hrtime(true); $phase5f35Timings = array();
ob_start();''')
    text = replace(text, '    $runtime = $Store->load_runtime_config($configId, true);', '''    $runtime = $Store->load_runtime_config($configId, true);
    // Release the PHP session before slow I/O; authorization has already completed.
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    foreach (array('IntegrationVmwareSnapshotCache', 'IntegrationVmwareSnapshotService',
        'IntegrationVmwareVcenterDirectRestTransport', 'IntegrationVmwareVmCardContext') as $class) {
        require_once(dirname(__FILE__) . '/../../../functions/classes/class.' . $class . '.php');
    }
    $Service = IntegrationVmwareSnapshotService::fromRuntime(dirname(__DIR__, 3), $configId, $runtime);''')
    begin = '    $Topology = new IntegrationVmwareVcenterTopology();'
    end = '    $context = $Explorer->vm_context($projection, $vmId);'
    old = text[text.index(begin):text.index(end) + len(end)]
    text = replace(text, old, '''    $phase5f35Tick = hrtime(true);
    $shared = IntegrationVmwareVmCardContext::read($Service, $vmId, $forceContext);
    $context = $shared['context']; $datastoreInventory = $shared['datastores'];
    $phase5f35Timings['core'] = (hrtime(true) - $phase5f35Tick) / 1000000;
    $cacheState = (string)($shared['cache']['state'] ?? 'unknown');
    $cacheAge = isset($shared['cache']['age_seconds']) ? max(0, (int)$shared['cache']['age_seconds']) : null;
    header('X-Phpipam-VM-Context-Cache: ' . (preg_match('/^[a-z_]{1,32}$/D', $cacheState) ? $cacheState : 'unknown'));
    if ($cacheAge !== null) header('X-Phpipam-VM-Context-Age: ' . $cacheAge);
    header('X-Phpipam-VM-Context-Snapshot-Written: ' . (!empty($shared['cache']['cache_snapshot_written']) ? '1' : '0'));''')
    for name, begin, end in (
        ('detail', '    $VmDetail = new IntegrationVmwareVcenterVmDetail();', "    $detail = $Resolver->resolve_vm_detail($detail, $datastoreInventory['datastores'], $context['datacenter_external_id']);"),
        ('guest', '    // PHPIPAM_CUSTOM_VMWARE_PHASE5F24_OPTIONAL_GUEST_NETWORK_V1', "    $metadata = array('tag_count' => 0, 'category_count' => 0, 'tags' => array());"),
        ('tags', "    $metadata = array('tag_count' => 0, 'category_count' => 0, 'tags' => array());", '    $AddressLinkResolver = null;\n    try {'),
    ):
        text = replace(text, begin, '    $phase5f35Tick = hrtime(true);\n' + begin)
        if name == 'detail':
            text = replace(text, end, end + "\n    $phase5f35Timings['detail'] = (hrtime(true) - $phase5f35Tick) / 1000000;")
        else:
            text = replace(text, end, "    $phase5f35Timings['" + name + "'] = (hrtime(true) - $phase5f35Tick) / 1000000;\n\n" + end)
    # Guest optional errors remain fail-open, but authorization failures must abort the card.
    text = replace(text, '    } catch (Exception $guestNetworkError) {', '''    } catch (Exception $guestNetworkError) {
        if (IntegrationVmwareSnapshotService::denied($guestNetworkError)) throw new RuntimeException('snapshot_access_denied');''')
    marker = "    print '<div class=\"phase5e-detail-section\"><h5><strong>Placement / Guest</strong>"
    pos = text.index(marker)
    text = text[:pos] + '''    print '<div class="small text-muted phase5f35-context" data-cache-state="' . phase5e_vmcard_h($cacheState) . '">';
    print 'Расположение и сопоставление хранилищ: ' . ($cacheState === 'disabled' ? 'получены напрямую' :
        'снимок сервера, возраст ' . phase5e_vmcard_h($cacheAge === null ? 'неизвестен' : $cacheAge . ' с'));
    print '. Детали ВМ запрошены отдельно; графики и дополнительные панели загружаются отдельно.</div>';

''' + text[pos:]
    text = replace(text, 'persistent_inventory_written=false · phpipam_objects_modified=false · vcenter_objects_modified=false',
        "inventory_db_written=false · cache_snapshot_written=' . (!empty($shared['cache']['cache_snapshot_written']) ? 'true' : 'false') . ' · phpipam_objects_modified=false · vcenter_objects_modified=false")
    text = replace(text, '} catch (Exception $e) {\n    http_response_code(500);', '''} catch (Throwable $e) {
    // Never forward provider text, headers, credentials or partial sensitive output.
    ob_clean();
    $known = array('snapshot_refresh_busy', 'snapshot_backoff', 'snapshot_access_denied', 'snapshot_vm_not_found', 'snapshot_context_incomplete');
    $category = in_array($e->getMessage(), $known, true) ? $e->getMessage() : 'vm_card_unavailable';
    if (class_exists('IntegrationVmwareSnapshotService', false) && IntegrationVmwareSnapshotService::denied($e)) $category = 'snapshot_access_denied';
    http_response_code($category === 'snapshot_access_denied' ? 403 : ($category === 'snapshot_vm_not_found' ? 404 : 503));
    header('X-Phpipam-VM-Context-Error: ' . $category);
    if ($category === 'snapshot_refresh_busy') header('Retry-After: 1');''')
    text = replace(text, 'phase5e_vmcard_h(phase5e_vmcard_error_category($e))', 'phase5e_vmcard_h($category)')
    text = replace(text, "    if (is_array($runtime) && isset($runtime['password'])) phase5e_vmcard_zero_secret($runtime['password']);", '''    unset($Service);
    if (is_array($runtime) && isset($runtime['password'])) phase5e_vmcard_zero_secret($runtime['password']);
    $phase5f35Timings['total'] = (hrtime(true) - $phase5f35Start) / 1000000;
    $phase5f35Header = array();
    foreach ($phase5f35Timings as $stage => $ms) $phase5f35Header[] = $stage . ';dur=' . number_format($ms, 2, '.', '');
    header('Server-Timing: ' . implode(', ', $phase5f35Header));''')
    return {'app/integrations/vmware/phase5f35-vm-card-result.php': text.encode()}

if __name__ == '__main__':
    import sys
    if len(sys.argv) != 2: raise SystemExit('Usage: build-phase5f35-vmcard.py OUTPUT_DIRECTORY')
    for rel, data in generate().items():
        out = Path(sys.argv[1]) / rel; out.parent.mkdir(parents=True, exist_ok=True); out.write_bytes(data)
